<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- Real archives in the system temp directory, WordPress is not loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Archive\ArchiveReader;
use OpenVault\Backup\Archive\TarWriter;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RestoreDoubles.php';

/**
 * @covers \OpenVault\Backup\Archive\ArchiveReader
 * @covers \OpenVault\Backup\Archive\TarWriter
 */
class ArchiveReaderTest extends TestCase {

	/** @var string[] */
	private $dirs = array();

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			CleanupStep::remove_tree( $dir );
		}
		parent::tearDown();
	}

	private function dir() {
		$dir = sys_get_temp_dir() . '/ovb-ar-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * One tar entry (header + body + padding).
	 */
	private static function entry( $name, $body, $type = '0' ) {
		$header = TarWriter::entry_header( $name, strlen( $body ), 1700000000 );
		if ( '0' !== $type ) {
			$header = self::retype( $header, $type );
		}
		return $header . $body . str_repeat( "\0", TarWriter::padding( strlen( $body ) ) );
	}

	/**
	 * Change the type flag of the last header block and fix its checksum.
	 */
	private static function retype( $blocks, $type ) {
		$last      = substr( $blocks, -512 );
		$rest      = substr( $blocks, 0, -512 );
		$last[156] = $type;
		return $rest . self::checksum( $last );
	}

	private static function checksum( $block ) {
		$sum = array_sum( unpack( 'C*', substr( $block, 0, 148 ) . '        ' . substr( $block, 156 ) ) );
		return substr( $block, 0, 148 ) . sprintf( '%06o', $sum ) . "\0 " . substr( $block, 156 );
	}

	/**
	 * Write a tar.gz of the given tar chunks, one gzip member per chunk (like the backup writes it), and the end blocks.
	 *
	 * @param string[] $chunks Tar byte chunks.
	 */
	private function tar_gz( array $chunks, $end = true ) {
		$data = '';
		foreach ( $chunks as $chunk ) {
			$data .= gzencode( $chunk, 6 );
		}
		if ( $end ) {
			$data .= gzencode( TarWriter::end_blocks(), 6 );
		}
		$path = $this->dir() . '/db.part01.tar.gz';
		file_put_contents( $path, $data );
		return $path;
	}

	private function sql( $size = 700000 ) {
		$out    = "-- ovb-dump: 1\n";
		$length = strlen( $out );
		while ( $length < $size ) {
			$line    = 'INSERT INTO `wp_t` VALUES (' . mt_rand() . ",'" . bin2hex( random_bytes( 20 ) ) . "');\n";
			$out    .= $line;
			$length += strlen( $line );
		}
		return $out;
	}

	private function slices( $kind, $part, $dest, $reads ) {
		$offset = 0;
		for ( $i = 0; $i < 200; $i++ ) {
			$result = ArchiveReader::extract( $kind, $part, ArchiveReader::ENTRY_DB, $dest, $offset, StepDriver::budget( $reads ) );
			$this->assertGreaterThanOrEqual( $offset, $result['offset'] );
			$offset = $result['offset'];
			if ( $result['done'] ) {
				return $i + 1;
			}
		}
		$this->fail( 'extraction did not finish' );
	}

	public function test_kind_for_and_names() {
		$this->assertSame( 'zip', ArchiveReader::kind_for( 'db.part01.zip' ) );
		$this->assertSame( 'zip', ArchiveReader::kind_for( 'DB.PART01.ZIP' ) );
		$this->assertSame( 'tar.gz', ArchiveReader::kind_for( 'db.part01.tar.gz' ) );
		$this->assertSame( 'tar.gz', ArchiveReader::kind_for( 'db.part01.tgz' ) );
		$this->assertSame( '', ArchiveReader::kind_for( 'db.part01.tar.bz2' ) );
		$this->assertSame( '', ArchiveReader::kind_for( 'db.sql' ) );

		foreach ( array( 'db/db.sql', 'abspath/wp-config.php', 'content/uploads/a b.png', 'a' ) as $name ) {
			$this->assertTrue( ArchiveReader::is_safe_name( $name ), $name );
		}
		foreach ( array( '', '/etc/passwd', '../x', 'a/../b', 'a/./b', 'a//b', 'a/', 'db\\db.sql', "db/db.sql\0", "a\nb", 'C:/x', 'c:x', "a\x7f", str_repeat( 'a', 5000 ), null, 5, array() ) as $name ) {
			$this->assertFalse( ArchiveReader::is_safe_name( $name ), is_string( $name ) ? addcslashes( substr( $name, 0, 20 ), "\0..\37" ) : gettype( $name ) );
		}
		$this->assertTrue( ArchiveReader::is_allowed( 'db/db.sql' ) );
		$this->assertFalse( ArchiveReader::is_allowed( 'db/other.sql' ) );
		$this->assertFalse( ArchiveReader::is_allowed( 'abspath/wp-config.php' ) );
		$this->assertTrue( ArchiveReader::is_allowed( 'abspath/wp-config.php', array( 'abspath/wp-config.php' ) ) );
		$this->assertFalse( ArchiveReader::is_allowed( '../db/db.sql', array( '../db/db.sql' ) ), 'an allow-list never overrides the safety check' );
	}

	public function test_tar_gz_with_other_entries_in_front_and_many_gzip_members() {
		$sql     = $this->sql();
		$tar     = self::entry( 'abspath/readme.html', str_repeat( 'r', 1000 ) ) . self::entry( "content/uploads/caf\xC3\xA9 " . str_repeat( 'long', 40 ) . '.txt', 'x' ) . self::entry( 'db/db.sql', $sql ) . self::entry( 'content/after.txt', 'zzz' );
		$members = str_split( $tar, 100000 );
		$part    = $this->tar_gz( $members );

		$dest = $this->dir() . '/db.sql';
		$this->assertSame( strlen( $sql ), ArchiveReader::entry_size( 'tar.gz', $part ) );
		$slices = $this->slices( 'tar.gz', $part, $dest, 100000 );
		$this->assertSame( 1, $slices );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest ) );
	}

	public function test_tar_gz_resumes_by_offset_and_discards_garbage_written_after_the_saved_offset() {
		$sql  = $this->sql( 900000 );
		$part = $this->tar_gz( array( self::entry( 'abspath/a.txt', str_repeat( 'a', 5000 ) ) . self::entry( 'db/db.sql', $sql ) ) );
		$dest = $this->dir() . '/db.sql';

		$first = ArchiveReader::extract( 'tar.gz', $part, 'db/db.sql', $dest, 0, StepDriver::budget( 2 ) );
		$this->assertFalse( $first['done'] );
		$this->assertGreaterThan( 0, $first['offset'] );
		$this->assertLessThan( strlen( $sql ), $first['offset'] );
		clearstatcache();
		$this->assertSame( $first['offset'], filesize( $dest ) );

		// A run that was killed after the saved offset: garbage behind it.
		file_put_contents( $dest, 'GARBAGE-GARBAGE', FILE_APPEND );
		$offset = $first['offset'];
		$slices = 1;
		do {
			$result = ArchiveReader::extract( 'tar.gz', $part, 'db/db.sql', $dest, $offset, StepDriver::budget( 2 ) );
			$offset = $result['offset'];
			++$slices;
		} while ( ! $result['done'] && $slices < 50 );
		$this->assertGreaterThanOrEqual( 2, $slices );
		clearstatcache();
		$this->assertSame( strlen( $sql ), filesize( $dest ) );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest ) );
	}

	public function test_an_offset_beyond_the_file_starts_over() {
		$sql  = $this->sql( 50000 );
		$part = $this->tar_gz( array( self::entry( 'db/db.sql', $sql ) ) );
		$dest = $this->dir() . '/db.sql';
		file_put_contents( $dest, 'short' );
		$result = ArchiveReader::extract( 'tar.gz', $part, 'db/db.sql', $dest, 999999, StepDriver::budget( 100 ) );
		$this->assertTrue( $result['done'] );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest ) );

		file_put_contents( $dest, 'short' );
		$result = ArchiveReader::extract( 'tar.gz', $part, 'db/db.sql', $dest, 100, StepDriver::budget( 100 ) );
		$this->assertTrue( $result['done'], 'an offset ahead of the file (5 bytes) starts again from 0' );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest ) );
	}

	public function test_pax_long_names_and_a_pax_size_in_front_of_the_entry() {
		$sql  = $this->sql( 20000 );
		$long = 'content/' . str_repeat( 'sub-folder-with-a-long-name/', 12 ) . "caf\xC3\xA9.txt";
		$part = $this->tar_gz( array( self::entry( $long, 'x' ) . self::entry( 'db/db.sql', $sql ) ) );
		$dest = $this->dir() . '/db.sql';
		$this->assertTrue( ArchiveReader::extract( 'tar.gz', $part, 'db/db.sql', $dest, 0, StepDriver::budget( 100 ) )['done'] );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest ) );

		// The entry itself carries a PAX path: the ustar name is only a stand-in.
		$pax_record = " path=db/db.sql\n";
		$pax_len    = strlen( $pax_record ) + strlen( (string) ( strlen( $pax_record ) + 2 ) );
		$pax_body   = $pax_len . $pax_record;
		$pax_header = self::retype( TarWriter::entry_header( 'PaxHeaders/x', strlen( $pax_body ), 1 ), 'x' );
		$standin    = self::entry( 'standin-name', $sql );
		$part2      = $this->tar_gz( array( $pax_header . $pax_body . str_repeat( "\0", TarWriter::padding( strlen( $pax_body ) ) ) . $standin ) );
		$dest2      = $this->dir() . '/db.sql';
		$this->assertTrue( ArchiveReader::extract( 'tar.gz', $part2, 'db/db.sql', $dest2, 0, StepDriver::budget( 100 ) )['done'], 'the PAX path names the entry' );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest2 ) );
	}

	public function test_a_base_256_size_in_front_of_the_entry_is_read() {
		$sql    = $this->sql( 20000 );
		$decoy  = str_repeat( 'd', 1500 );
		$header = TarWriter::entry_header( 'decoy.bin', 0, 1 );
		$header = substr( $header, 0, 124 ) . "\x80\0\0\0\0\0\0\0" . pack( 'N', strlen( $decoy ) ) . substr( $header, 136 );
		$header = self::checksum( $header );
		$part   = $this->tar_gz( array( $header . $decoy . str_repeat( "\0", TarWriter::padding( strlen( $decoy ) ) ) . self::entry( 'db/db.sql', $sql ) ) );
		$dest   = $this->dir() . '/db.sql';
		$this->assertTrue( ArchiveReader::extract( 'tar.gz', $part, 'db/db.sql', $dest, 0, StepDriver::budget( 100 ) )['done'] );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest ) );
	}

	public function test_a_huge_size_in_the_header_makes_the_archive_look_cut_off() {
		$header = TarWriter::entry_header( 'decoy.bin', 0, 1 );
		$header = substr( $header, 0, 124 ) . "\x80\0\0\0\x7f\xff\xff\xff\xff\xff\xff\xff" . substr( $header, 136 );
		$part   = $this->tar_gz( array( self::checksum( $header ) . self::entry( 'db/db.sql', 'x' ) ) );
		$this->expectException( ArchiveException::class );
		ArchiveReader::extract( 'tar.gz', $part, 'db/db.sql', $this->dir() . '/db.sql', 0, StepDriver::budget( 100 ) );
	}

	public function test_only_a_regular_file_with_the_exact_name_counts() {
		$decoys = self::entry( 'db/db.sql', '', '2' ) . self::entry( 'db/db.sql', '', '5' ) . self::entry( 'db/db.sql.bak', 'nope' ) . self::entry( 'x/db/db.sql', 'nope' ) . self::entry( 'DB/DB.SQL', 'nope' );
		$part   = $this->tar_gz( array( $decoys ) );
		try {
			ArchiveReader::extract( 'tar.gz', $part, 'db/db.sql', $this->dir() . '/db.sql', 0, StepDriver::budget( 100 ) );
			$this->fail( 'extracted a link, a folder or a look-alike' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'does not contain', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->dirs[ count( $this->dirs ) - 1 ] . '/db.sql' );
	}

	public function test_names_that_are_not_allowed_are_refused_before_anything_is_opened() {
		$part = $this->tar_gz( array( self::entry( 'db/db.sql', 'x' ), self::entry( 'abspath/wp-config.php', 'secret' ) ) );
		foreach ( array( 'abspath/wp-config.php', '../db/db.sql', '/db/db.sql', 'db\\db.sql', "db/db.sql\0", 'db/../db/db.sql', 'db/db.sql/', '', 'db/*', 'db/db.sql ' ) as $name ) {
			$dest = $this->dir() . '/out.sql';
			try {
				ArchiveReader::extract( 'tar.gz', $part, $name, $dest, 0, StepDriver::budget( 100 ) );
				$this->fail( 'extracted ' . addcslashes( $name, "\0..\37" ) );
			} catch ( ArchiveException $e ) {
				$this->assertStringContainsString( 'may not be extracted', $e->getMessage() );
			}
			$this->assertFileDoesNotExist( $dest );
		}
	}

	public function test_a_missing_or_truncated_or_damaged_part_is_an_archive_exception() {
		$sql  = $this->sql( 100000 );
		$part = $this->tar_gz( array( self::entry( 'db/db.sql', $sql ) ) );
		$dest = $this->dir() . '/db.sql';

		foreach ( array(
			'missing'   => $part . '.nope',
			'directory' => $this->dir(),
		) as $label => $path ) {
			try {
				ArchiveReader::extract( 'tar.gz', $path, 'db/db.sql', $dest, 0, null );
				$this->fail( $label );
			} catch ( ArchiveException $e ) {
				$this->assertSame( 'The archive part is missing.', $e->getMessage() );
			}
		}

		$whole = file_get_contents( $part );
		foreach ( array( 30, 2000, (int) ( strlen( $whole ) / 2 ) ) as $cut ) {
			$cut_part = $this->dir() . '/cut.tar.gz';
			file_put_contents( $cut_part, substr( $whole, 0, $cut ) );
			try {
				ArchiveReader::extract( 'tar.gz', $cut_part, 'db/db.sql', $dest, 0, null );
				$this->fail( "cut at $cut" );
			} catch ( ArchiveException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}

		$garbage = $this->dir() . '/garbage.tar.gz';
		file_put_contents( $garbage, random_bytes( 3000 ) );
		$this->expectException( ArchiveException::class );
		ArchiveReader::extract( 'tar.gz', $garbage, 'db/db.sql', $dest, 0, null );
	}

	public function test_a_tar_whose_data_ends_before_the_header_size_is_cut_off() {
		$header = TarWriter::entry_header( 'db/db.sql', 100000, 1 );
		$part   = $this->tar_gz( array( $header . str_repeat( 'a', 5000 ) ), false );
		$this->expectException( ArchiveException::class );
		ArchiveReader::extract( 'tar.gz', $part, 'db/db.sql', $this->dir() . '/db.sql', 0, null );
	}

	public function test_an_unknown_kind_is_refused() {
		$part = $this->tar_gz( array( self::entry( 'db/db.sql', 'x' ) ) );
		$this->expectException( ArchiveException::class );
		ArchiveReader::extract( 'tar.bz2', $part, 'db/db.sql', $this->dir() . '/db.sql', 0, null );
	}

	public function test_zip_entry_extraction() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available (this test runs in the matrix).' );
		}
		$sql  = $this->sql( 900000 );
		$path = $this->dir() . '/db.part01.zip';
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( 'abspath/wp-config.php', 'secret' );
		$zip->addFromString( 'db/db.sql', $sql );
		$zip->addFromString( 'db/db.sql.bak', 'nope' );
		$zip->close();

		$dest = $this->dir() . '/db.sql';
		$this->assertSame( strlen( $sql ), ArchiveReader::entry_size( 'zip', $path ) );
		$slices = $this->slices( 'zip', $path, $dest, 2 );
		$this->assertGreaterThan( 1, $slices );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest ) );
		$this->assertSame( array( '.', '..', 'db.sql' ), scandir( dirname( $dest ) ), 'only the one file was written' );
	}

	public function test_zip_resume_discards_garbage_and_wrong_entries_are_refused() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available (this test runs in the matrix).' );
		}
		$sql  = $this->sql( 900000 );
		$path = $this->dir() . '/db.part01.zip';
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( 'db/other.sql', 'x' );
		$zip->addFromString( 'db/db.sql', $sql );
		$zip->setCompressionName( 'db/db.sql', \ZipArchive::CM_STORE );
		$zip->close();

		$dest  = $this->dir() . '/db.sql';
		$first = ArchiveReader::extract( 'zip', $path, 'db/db.sql', $dest, 0, StepDriver::budget( 2 ) );
		$this->assertFalse( $first['done'] );
		file_put_contents( $dest, 'GARBAGE', FILE_APPEND );
		$offset = $first['offset'];
		for ( $i = 0; $i < 500; $i++ ) {
			$result = ArchiveReader::extract( 'zip', $path, 'db/db.sql', $dest, $offset, StepDriver::budget( 2 ) );
			$offset = $result['offset'];
			if ( $result['done'] ) {
				break;
			}
		}
		$this->assertTrue( $result['done'] );
		$this->assertSame( hash( 'sha256', $sql ), hash_file( 'sha256', $dest ) );

		$empty = $this->dir() . '/empty.zip';
		$zip   = new \ZipArchive();
		$zip->open( $empty, \ZipArchive::CREATE );
		$zip->addFromString( 'db/other.sql', 'x' );
		$zip->close();
		try {
			ArchiveReader::extract( 'zip', $empty, 'db/db.sql', $this->dir() . '/o.sql', 0, null );
			$this->fail( 'extracted a missing entry' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'does not contain', $e->getMessage() );
		}

		$bad = $this->dir() . '/bad.zip';
		file_put_contents( $bad, random_bytes( 2000 ) );
		$this->expectException( ArchiveException::class );
		ArchiveReader::extract( 'zip', $bad, 'db/db.sql', $this->dir() . '/o2.sql', 0, null );
	}

	public function test_tar_writer_reads_base_256_sizes() {
		$block = TarWriter::entry_header( 'x', 0, 1 );
		$block = self::checksum( substr( $block, 0, 124 ) . "\x80\0\0\0\0\0\0\0\x00\x10\x00\x00" . substr( $block, 136 ) );
		$this->assertSame( 1048576, TarWriter::parse_header( $block )['size'] );
		$block = self::checksum( substr( $block, 0, 124 ) . "\x80\0\0\0\x02\x00\x00\x00\x00\x00\x00\x00" . substr( $block, 136 ) );
		$this->assertSame( 2 * 256 ** 7, TarWriter::parse_header( $block )['size'] );
		$block = self::checksum( substr( $block, 0, 124 ) . "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF" . substr( $block, 136 ) );
		$this->assertSame( PHP_INT_MAX, TarWriter::parse_header( $block )['size'], 'a size that does not fit is clamped' );
		$ordinary = TarWriter::entry_header( 'x', 12345, 1 );
		$this->assertSame( 12345, TarWriter::parse_header( $ordinary )['size'] );
	}
}
