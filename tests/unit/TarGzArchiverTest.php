<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real files in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Archive\TarGzArchiver;
use OpenVault\Backup\Archive\TarWriter;
use OpenVault\Backup\Engine\Budget;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Archive\TarGzArchiver
 */
class TarGzArchiverTest extends TestCase {

	/**
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'deflate_init' ) ) {
			$this->markTestSkipped( 'The zlib extension is not loaded.' );
		}
		$this->dir = sys_get_temp_dir() . '/ovb-targz-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		if ( $this->dir && is_dir( $this->dir ) ) {
			foreach ( (array) scandir( $this->dir ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					unlink( $this->dir . '/' . $name );
				}
			}
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	private function size_of( $path ) {
		clearstatcache( true, $path );
		return filesize( $path );
	}

	private function source( $name, $content ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );
		return $path;
	}

	/**
	 * Entries of a .tar.gz read through zlib (all gzip members) and our own tar parser.
	 */
	private function read_entries( $path ) {
		$tar = file_get_contents( 'compress.zlib://' . $path );
		$this->assertIsString( $tar );
		$entries = array();
		$offset  = 0;
		$pax     = array();
		$zeros   = 0;
		$total   = strlen( $tar );
		while ( $offset + 512 <= $total ) {
			$block   = substr( $tar, $offset, 512 );
			$offset += 512;
			if ( 512 === strspn( $block, "\0" ) ) {
				++$zeros;
				continue;
			}
			$this->assertSame( 0, $zeros, 'no data after the end blocks start' );
			$header = TarWriter::parse_header( $block );
			$this->assertNotNull( $header, 'valid header at ' . ( $offset - 512 ) );
			$size    = isset( $pax['size'] ) ? (int) $pax['size'] : $header['size'];
			$body    = substr( $tar, $offset, $size );
			$offset += $size + TarWriter::padding( $size );
			if ( 'x' === $header['type'] ) {
				$pax = TarWriter::parse_pax( $body );
				continue;
			}
			$name             = isset( $pax['path'] ) ? $pax['path'] : $header['name'];
			$entries[ $name ] = $body;
			$pax              = array();
		}
		$this->assertSame( 2, $zeros, 'the stream ends with two zero blocks' );
		$this->assertSame( strlen( $tar ), $offset, 'nothing after the end blocks' );
		return $entries;
	}

	public function test_basic_part_round_trips_and_verifies() {
		$files    = array(
			'db/db.sql'                  => "CREATE TABLE x;\n",
			'content/uploads/empty.txt'  => '',
			'content/uploads/caf' . "\xc3\xa9" . ' ' . "\xf0\x9f\x98\x80" . '.txt' => 'unicode',
			'content/uploads/' . str_repeat( 'n', 150 ) . '.txt' => 'long',
			'content/uploads/exact.bin'  => str_repeat( 'x', 512 ),
			'content/uploads/random.bin' => random_bytes( 200000 ),
		);
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/files.part01.tar.gz';
		$archiver->begin_part( $part );
		foreach ( $files as $entry => $body ) {
			$source = $this->source( 'src' . md5( $entry ), $body );
			$this->assertSame( strlen( $body ), $archiver->add_file( $source, $entry, strlen( $body ), 1700000000 ) );
		}
		$done = $archiver->finish_part();

		$this->assertSame( $part, $done['path'] );
		$this->assertSame( $this->size_of( $part ), $done['size'] );
		$this->assertSame( hash_file( 'sha256', $part ), $done['sha256'] );
		$this->assertSame( $files, $this->read_entries( $part ) );
		$this->assertSame( '', $archiver->verify_part( $part, count( $files ) ) );
		$this->assertStringContainsString( 'expected', $archiver->verify_part( $part, count( $files ) + 1 ), 'wrong entry count' );
		$this->assertSame( array(), $archiver->take_warnings() );
	}

	public function test_every_slice_is_one_gzip_member() {
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/p.tar.gz';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'a', 'hello' ), 'a.txt', 5, 0 );
		$archiver->end_slice();
		$archiver->begin_part( $part, $this->size_of( $part ) );
		$archiver->add_file( $this->source( 'b', 'world' ), 'b.txt', 5, 0 );
		$archiver->finish_part();
		$this->assertSame( "\x1f\x8b", substr( file_get_contents( $part ), 0, 2 ) );
		$this->assertSame( 2, substr_count( file_get_contents( $part ), "\x1f\x8b\x08" ) >= 2 ? 2 : 0, 'two gzip members' );
	}

	public function test_slices_with_a_crash_after_each_slice_resume_to_a_valid_archive() {
		$files = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$files[ 'content/f' . $i . '.bin' ] = random_bytes( 1000 * ( $i + 1 ) );
		}
		$part     = $this->dir . '/files.part01.tar.gz';
		$archiver = new TarGzArchiver();
		$saved    = 0;
		$added    = 0;
		$names    = array_keys( $files );
		$chunks   = array_chunk( $names, 3 );
		foreach ( $chunks as $index => $chunk ) {
			$archiver->begin_part( $part, $saved );
			foreach ( $chunk as $entry ) {
				$archiver->add_file( $this->source( 'src' . $added, $files[ $entry ] ), $entry, strlen( $files[ $entry ] ), 0 );
				++$added;
			}
			if ( $index < count( $chunks ) - 1 ) {
				$saved = $archiver->end_slice();
				$this->assertSame( $this->size_of( $part ), $saved );
				// A killed run: bytes written after the last saved slice (a half member, garbage).
				file_put_contents( $part, "GARBAGE\x1f\x8b\x08partial" . random_bytes( 300 ), FILE_APPEND );
			}
		}
		$archiver->finish_part();
		$this->assertSame( $files, $this->read_entries( $part ) );
		$this->assertSame( '', $archiver->verify_part( $part, 12 ) );
	}

	public function test_a_file_can_be_split_over_slices_and_stays_byte_exact() {
		$body     = random_bytes( 700000 );
		$source   = $this->source( 'big', $body );
		$part     = $this->dir . '/files.part01.tar.gz';
		$archiver = new TarGzArchiver();
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'first', 'before' ), 'content/before.txt', 6, 0 );

		$stopped  = new Budget( 0, PHP_INT_MAX );
		$position = 0;
		$length   = strlen( $body );
		$slices   = 0;
		$saved    = 0;
		do {
			if ( $slices > 0 ) {
				$archiver->begin_part( $part, $saved );
			}
			$position = $archiver->add_file( $source, 'content/big.bin', strlen( $body ), 0, $position, $stopped );
			$saved    = $archiver->end_slice();
			++$slices;
			$this->assertLessThan( 100, $slices );
		} while ( $position < $length );

		$this->assertGreaterThan( 5, $slices, 'one 64 KB chunk per slice' );
		$archiver->begin_part( $part, $saved );
		$archiver->add_file( $this->source( 'last', 'after' ), 'content/after.txt', 5, 0 );
		$archiver->finish_part();

		$entries = $this->read_entries( $part );
		$this->assertSame( array( 'content/before.txt', 'content/big.bin', 'content/after.txt' ), array_keys( $entries ) );
		$this->assertTrue( $body === $entries['content/big.bin'] );
		$this->assertSame( '', $archiver->verify_part( $part, 3 ) );
	}

	public function test_a_file_that_vanished_before_it_is_added_is_skipped() {
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/p.tar.gz';
		$archiver->begin_part( $part );
		$this->assertSame( -1, $archiver->add_file( $this->dir . '/missing', 'content/missing.txt', 10, 0 ) );
		$archiver->add_file( $this->source( 'ok', 'fine' ), 'content/ok.txt', 4, 0 );
		$archiver->finish_part();
		$warnings = $archiver->take_warnings();
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'content/missing.txt', $warnings[0] );
		$this->assertSame( array( 'content/ok.txt' => 'fine' ), $this->read_entries( $part ) );
		$this->assertSame( '', $archiver->verify_part( $part, 1 ) );
		$this->assertSame( array(), $archiver->take_warnings(), 'warnings are handed out once' );
	}

	public function test_a_file_that_shrank_is_padded_and_one_that_grew_is_cut() {
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/p.tar.gz';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'shrunk', 'abc' ), 'content/shrunk.txt', 10, 0 );
		$archiver->add_file( $this->source( 'grown', '0123456789ABCDEF' ), 'content/grown.txt', 10, 0 );
		$archiver->finish_part();

		$entries = $this->read_entries( $part );
		$this->assertSame( "abc\0\0\0\0\0\0\0", $entries['content/shrunk.txt'] );
		$this->assertSame( '0123456789', $entries['content/grown.txt'] );
		$this->assertCount( 2, $archiver->take_warnings() );
		$this->assertSame( '', $archiver->verify_part( $part, 2 ) );
	}

	public function test_a_file_that_vanishes_in_the_middle_is_filled_with_zeros() {
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/p.tar.gz';
		$archiver->begin_part( $part );
		$source = $this->source( 'gone', str_repeat( 'q', 200000 ) );
		$pos    = $archiver->add_file( $source, 'content/gone.bin', 200000, 0, 0, new Budget( 0, PHP_INT_MAX ) );
		$this->assertSame( 65536, $pos );
		$saved = $archiver->end_slice();
		unlink( $source );
		$archiver->begin_part( $part, $saved );
		$this->assertSame( 200000, $archiver->add_file( $source, 'content/gone.bin', 200000, 0, $pos ) );
		$archiver->finish_part();
		$entries = $this->read_entries( $part );
		$this->assertSame( 200000, strlen( $entries['content/gone.bin'] ) );
		$this->assertSame( str_repeat( 'q', 65536 ), substr( $entries['content/gone.bin'], 0, 65536 ) );
		$this->assertSame( str_repeat( "\0", 200000 - 65536 ), substr( $entries['content/gone.bin'], 65536 ) );
		$this->assertCount( 1, $archiver->take_warnings() );
	}

	public function test_a_part_shorter_than_its_saved_size_is_refused() {
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/p.tar.gz';
		file_put_contents( $part, 'short' );
		$this->expectException( ArchiveException::class );
		$archiver->begin_part( $part, 100 );
	}

	public function test_begin_part_with_zero_starts_over() {
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/p.tar.gz';
		file_put_contents( $part, 'old content that must go away' );
		$archiver->begin_part( $part, 0 );
		$archiver->add_file( $this->source( 'a', 'new' ), 'a.txt', 3, 0 );
		$archiver->finish_part();
		$this->assertSame( array( 'a.txt' => 'new' ), $this->read_entries( $part ) );
	}

	public function test_size_so_far_is_the_compressed_size() {
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/p.tar.gz';
		$archiver->begin_part( $part );
		$this->assertSame( 0, $archiver->size_so_far() );
		$source = $this->source( 'zeros', str_repeat( "\0", 3000000 ) );
		$archiver->add_file( $source, 'z.bin', 3000000, 0 );
		$archiver->end_slice();
		$this->assertSame( $this->size_of( $part ), $archiver->size_so_far() );
		$this->assertLessThan( 50000, $archiver->size_so_far(), 'highly compressible data is small on disk' );
	}

	public function test_verify_detects_damage() {
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/p.tar.gz';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'a', random_bytes( 5000 ) ), 'a.bin', 5000, 0 );
		$done = $archiver->finish_part();
		$this->assertSame( '', $archiver->verify_part( $part, 1 ) );

		$bytes = file_get_contents( $part );
		file_put_contents( $part, substr( $bytes, 0, (int) ( $done['size'] / 2 ) ) );
		$this->assertNotSame( '', $archiver->verify_part( $part, 1 ), 'truncated part' );

		$flipped                                   = $bytes;
		$flipped[ (int) ( strlen( $bytes ) / 2 ) ] = chr( ord( $flipped[ (int) ( strlen( $bytes ) / 2 ) ] ) ^ 0xff );
		file_put_contents( $part, $flipped );
		$this->assertNotSame( '', $archiver->verify_part( $part, 1 ), 'flipped bytes' );

		file_put_contents( $part, 'not gzip at all' );
		$this->assertNotSame( '', $archiver->verify_part( $part, 1 ) );
		$this->assertNotSame( '', $archiver->verify_part( $this->dir . '/missing.tar.gz', 1 ) );
	}

	public function test_interface_facts() {
		$archiver = new TarGzArchiver();
		$this->assertSame( 'tar.gz', $archiver->id() );
		$this->assertSame( 'tar.gz', $archiver->extension() );
		$this->assertTrue( $archiver->supports_partial() );
	}

	/**
	 * A one-slice archive is a plain .tar.gz that PharData reads. (PharData cannot read the several gzip members of a
	 * multi-slice archive, "corrupted tar file (truncated)": that is why the restore uses gzopen and not PharData.)
	 */
	public function test_phardata_reads_a_single_member_archive() {
		if ( ! class_exists( 'PharData' ) ) {
			$this->markTestSkipped( 'The phar extension is not loaded.' );
		}
		$archiver = new TarGzArchiver();
		$part     = $this->dir . '/files.part01.tar.gz';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'a', 'one' ), 'content/a.txt', 3, 0 );
		$archiver->add_file( $this->source( 'b', 'two' ), 'content/b.txt', 3, 0 );
		$archiver->finish_part();

		$phar = new \PharData( $part );
		$seen = array();
		$base = 'phar://' . $part . '/';
		foreach ( new \RecursiveIteratorIterator( $phar ) as $item ) {
			$seen[ substr( $item->getPathname(), strlen( $base ) ) ] = file_get_contents( $item->getPathname() );
		}
		ksort( $seen );
		$this->assertSame(
			array(
				'content/a.txt' => 'one',
				'content/b.txt' => 'two',
			),
			$seen
		);
	}
}
