<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real files in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Archive\ZipArchiver;
use PHPUnit\Framework\TestCase;

/**
 * Needs the zip extension; skipped where it is missing (the Docker matrix runs it).
 *
 * @covers \OpenVault\Backup\Archive\ZipArchiver
 */
class ZipArchiverTest extends TestCase {

	/**
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'The zip extension is not loaded.' );
		}
		$this->dir = sys_get_temp_dir() . '/ovb-zip-' . bin2hex( random_bytes( 4 ) );
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

	private function source( $name, $content ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );
		return $path;
	}

	private function size_of( $path ) {
		clearstatcache( true, $path );
		return filesize( $path );
	}

	/**
	 * @return array<string,string> Entry name => content.
	 */
	private function read_entries( $path, &$methods = array() ) {
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $path, \ZipArchive::CHECKCONS ) );
		$entries = array();
		$count   = $zip->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$stat                     = $zip->statIndex( $i );
			$entries[ $stat['name'] ] = $zip->getFromIndex( $i );
			$methods[ $stat['name'] ] = $stat['comp_method'];
		}
		$zip->close();
		return $entries;
	}

	public function test_interface_facts() {
		$this->assertSame( 'zip', ( new ZipArchiver( false ) )->id() );
		$this->assertSame( 'zip-store', ( new ZipArchiver( true ) )->id() );
		$this->assertSame( 'zip', ( new ZipArchiver( true ) )->extension() );
		$this->assertFalse( ( new ZipArchiver() )->supports_partial() );
	}

	public function test_deflate_round_trip() {
		$files    = array(
			'db/db.sql'                 => str_repeat( "INSERT INTO t VALUES (1,'abc');\n", 2000 ),
			'content/uploads/empty.txt' => '',
			'content/uploads/caf' . "\xc3\xa9" . ' ' . "\xf0\x9f\x98\x80" . '.txt' => 'unicode',
			'content/uploads/' . str_repeat( 'n', 200 ) . '.txt' => 'long',
			'content/random.bin'        => random_bytes( 100000 ),
		);
		$archiver = new ZipArchiver( false );
		$part     = $this->dir . '/files.part01.zip';
		$archiver->begin_part( $part );
		foreach ( $files as $entry => $body ) {
			$this->assertSame( strlen( $body ), $archiver->add_file( $this->source( 'src' . md5( $entry ), $body ), $entry, strlen( $body ), 0 ) );
		}
		$done = $archiver->finish_part();

		$methods = array();
		$this->assertSame( $files, $this->read_entries( $part, $methods ) );
		$this->assertSame( \ZipArchive::CM_DEFLATE, $methods['db/db.sql'], 'compressible data is deflated' );
		$this->assertSame( $this->size_of( $part ), $done['size'] );
		$this->assertSame( hash_file( 'sha256', $part ), $done['sha256'] );
		$this->assertSame( '', $archiver->verify_part( $part, count( $files ) ) );
		$this->assertNotSame( '', $archiver->verify_part( $part, count( $files ) + 1 ) );
		$this->assertSame( strlen( $files['db/db.sql'] ) + 100000 + 7 + 4, $archiver->size_so_far() );
	}

	public function test_store_mode_does_not_compress() {
		$body     = str_repeat( 'a', 100000 );
		$archiver = new ZipArchiver( true );
		$part     = $this->dir . '/p.zip';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'a', $body ), 'content/a.txt', strlen( $body ), 0 );
		$done    = $archiver->finish_part();
		$methods = array();
		$this->assertSame( array( 'content/a.txt' => $body ), $this->read_entries( $part, $methods ) );
		$this->assertSame( \ZipArchive::CM_STORE, $methods['content/a.txt'] );
		$this->assertGreaterThan( 100000, $done['size'] );
	}

	public function test_more_files_than_one_flush_and_many_slices() {
		$archiver = new ZipArchiver( false );
		$part     = $this->dir . '/p.zip';
		$expected = array();
		$saved    = 0;
		$load     = 0;
		for ( $slice = 0; $slice < 4; $slice++ ) {
			$archiver->begin_part( $part, $saved, $load, $slice * 130 );
			for ( $i = 0; $i < 130; $i++ ) {
				$entry              = 'content/s' . $slice . '/f' . $i . '.txt';
				$expected[ $entry ] = 'file ' . $slice . '-' . $i;
				$archiver->add_file( $this->source( 'x' . ( $slice * 1000 + $i ), $expected[ $entry ] ), $entry, strlen( $expected[ $entry ] ), 0 );
			}
			if ( $slice < 3 ) {
				$saved = $archiver->end_slice();
				$load  = $archiver->size_so_far();
				$this->assertSame( $this->size_of( $part ), $saved );
				$this->assertGreaterThan( 0, $saved );
			}
		}
		$archiver->finish_part();
		$this->assertSame( $expected, $this->read_entries( $part ) );
		$this->assertSame( '', $archiver->verify_part( $part, 520 ) );
	}

	public function test_garbage_after_the_saved_slice_is_cut_off() {
		$archiver = new ZipArchiver( false );
		$part     = $this->dir . '/p.zip';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'a', 'one' ), 'a.txt', 3, 0 );
		$saved = $archiver->end_slice();

		file_put_contents( $part, "PK\x03\x04garbage" . random_bytes( 500 ), FILE_APPEND );
		file_put_contents( $part . '.aB3dE9', 'a temporary file libzip left behind' );

		$archiver->begin_part( $part, $saved, 3, 1 );
		$this->assertFileDoesNotExist( $part . '.aB3dE9', 'stray temporary files of a killed run are removed' );
		$archiver->add_file( $this->source( 'b', 'two' ), 'b.txt', 3, 0 );
		$archiver->finish_part();
		$this->assertSame(
			array(
				'a.txt' => 'one',
				'b.txt' => 'two',
			),
			$this->read_entries( $part )
		);
	}

	public function test_a_part_that_is_newer_than_the_saved_cursor_is_cut_back_by_entries() {
		$archiver = new ZipArchiver( false );
		$part     = $this->dir . '/p.zip';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'a', str_repeat( 'a', 5000 ) ), 'a.txt', 5000, 0 );
		$saved = $archiver->end_slice();
		$load  = $archiver->size_so_far();

		// The next slice closed the archive, but the run died before it saved its cursor.
		$archiver->begin_part( $part, $saved, $load, 1 );
		$archiver->add_file( $this->source( 'b', random_bytes( 3000 ) ), 'b.bin', 3000, 0 );
		$archiver->add_file( $this->source( 'c', random_bytes( 3000 ) ), 'c.bin', 3000, 0 );
		$newer = $archiver->end_slice();
		$this->assertGreaterThan( $saved, $newer );

		// The old cursor comes back: size = $saved, one entry. A plain truncate would corrupt the archive.
		$replay = new ZipArchiver( false );
		$replay->begin_part( $part, $saved, $load, 1 );
		$replay->add_file( $this->source( 'd', 'second' ), 'd.txt', 6, 0 );
		$replay->finish_part();
		$this->assertSame(
			array(
				'a.txt' => str_repeat( 'a', 5000 ),
				'd.txt' => 'second',
			),
			$this->read_entries( $part )
		);
		$this->assertSame( '', $replay->verify_part( $part, 2 ) );
	}

	public function test_a_part_with_fewer_entries_than_saved_is_refused() {
		$archiver = new ZipArchiver( false );
		$part     = $this->dir . '/p.zip';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'a', 'one' ), 'a.txt', 3, 0 );
		$saved = $archiver->end_slice();
		file_put_contents( $part, 'x', FILE_APPEND );
		$this->expectException( ArchiveException::class );
		$archiver->begin_part( $part, $saved, 3, 5 );
	}

	public function test_a_part_shorter_than_its_saved_size_is_refused() {
		$archiver = new ZipArchiver( false );
		$part     = $this->dir . '/p.zip';
		file_put_contents( $part, 'short' );
		$this->expectException( ArchiveException::class );
		$archiver->begin_part( $part, 100 );
	}

	public function test_a_file_that_vanished_before_it_is_added_is_skipped() {
		$archiver = new ZipArchiver( false );
		$part     = $this->dir . '/p.zip';
		$archiver->begin_part( $part );
		$this->assertSame( -1, $archiver->add_file( $this->dir . '/missing', 'content/missing.txt', 10, 0 ) );
		$archiver->add_file( $this->source( 'ok', 'fine' ), 'content/ok.txt', 4, 0 );
		$archiver->finish_part();
		$this->assertCount( 1, $archiver->take_warnings() );
		$this->assertSame( array( 'content/ok.txt' => 'fine' ), $this->read_entries( $part ) );
	}

	/**
	 * Depending on the libzip version close() either fails (the queued files are then added one by one and only the
	 * bad one is skipped) or stores the vanished file as an empty entry. Either way the archive stays valid and the
	 * other files are intact, and the loss is reported.
	 */
	public function test_a_file_that_vanishes_after_it_was_queued_never_breaks_the_archive() {
		$archiver = new ZipArchiver( false );
		$part     = $this->dir . '/p.zip';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'a', 'one' ), 'a.txt', 3, 0 );
		$doomed = $this->source( 'doomed', 'two' );
		$archiver->add_file( $doomed, 'doomed.txt', 3, 0 );
		$archiver->add_file( $this->source( 'c', 'three' ), 'c.txt', 5, 0 );
		unlink( $doomed );
		$archiver->end_slice();
		$archiver->finish_part();

		$entries = $this->read_entries( $part );
		$this->assertSame( 'one', $entries['a.txt'] );
		$this->assertSame( 'three', $entries['c.txt'] );
		$this->assertContains( $entries['doomed.txt'] ?? null, array( null, '' ), 'skipped or stored empty, never with wrong content' );
		$warnings = $archiver->take_warnings();
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'doomed.txt', $warnings[0] );
		$this->assertSame( '', $archiver->verify_part( $part, count( $entries ) ) );
	}

	public function test_verify_detects_damage() {
		$archiver = new ZipArchiver( false );
		$part     = $this->dir . '/p.zip';
		$archiver->begin_part( $part );
		$archiver->add_file( $this->source( 'a', random_bytes( 5000 ) ), 'a.bin', 5000, 0 );
		$done = $archiver->finish_part();
		$this->assertSame( '', $archiver->verify_part( $part, 1 ) );

		file_put_contents( $part, substr( file_get_contents( $part ), 0, (int) ( $done['size'] / 2 ) ) );
		$this->assertNotSame( '', $archiver->verify_part( $part, 1 ) );
		$this->assertNotSame( '', $archiver->verify_part( $this->dir . '/missing.zip', 1 ) );
	}

	public function test_an_empty_part_cannot_be_finished() {
		$archiver = new ZipArchiver( false );
		$archiver->begin_part( $this->dir . '/p.zip' );
		$this->expectException( ArchiveException::class );
		$archiver->finish_part();
	}
}
