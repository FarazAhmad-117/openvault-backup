<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged,Generic.CodeAnalysis.AssignmentInCondition -- These tests exercise real files and links in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Restore\ExtractionPolicy;
use OpenVault\Backup\Restore\FileRestoreException;
use OpenVault\Backup\Restore\SafeWriter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FileFixtures.php';

/**
 * The file system side of a restore: destination walk, symlink refusal, temp file and rename, saved originals.
 *
 * @covers \OpenVault\Backup\Restore\SafeWriter
 * @covers \OpenVault\Backup\Restore\FsProbe
 */
class SafeWriterTest extends TestCase {

	/**
	 * Temp folder.
	 *
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = FileFixtures::tmp();
		mkdir( $this->dir . '/root' );
		mkdir( $this->dir . '/outside' );
	}

	protected function tearDown(): void {
		FileFixtures::rrmdir( $this->dir );
		parent::tearDown();
	}

	private function symlink_or_skip( $target, $link ) {
		if ( ! function_exists( 'symlink' ) || ! @symlink( $target, $link ) ) {
			$this->markTestSkipped( 'Symlinks are not available.' );
		}
	}

	public function test_missing_folders_are_created_one_by_one_after_the_journal_hook() {
		$writer  = new SafeWriter();
		$created = array();
		$result  = $writer->prepare_dir(
			$this->dir . '/root',
			'content/uploads',
			array( '2026', '05', 'x y' ),
			static function ( $entry ) use ( &$created ) {
				$created[] = $entry;
				return true;
			}
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $this->dir . '/root/2026/05/x y', $result['dir'] );
		$this->assertDirectoryExists( $result['dir'] );
		$this->assertSame( array( 'content/uploads/2026', 'content/uploads/2026/05', 'content/uploads/2026/05/x y' ), $created );

		$created = array();
		$again   = $writer->prepare_dir(
			$this->dir . '/root',
			'content/uploads',
			array( '2026', '05' ),
			static function ( $entry ) use ( &$created ) {
				$created[] = $entry;
				return true;
			}
		);
		$this->assertTrue( $again['ok'] );
		$this->assertSame( array(), $created, 'folders that exist are not journaled again' );
	}

	public function test_a_missing_root_is_created_and_journaled() {
		$created = array();
		$result  = ( new SafeWriter() )->prepare_dir(
			$this->dir . '/root/uploads',
			'content/uploads',
			array( 'a' ),
			static function ( $entry ) use ( &$created ) {
				$created[] = $entry;
				return true;
			}
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'content/uploads', 'content/uploads/a' ), $created );
	}

	public function test_a_journal_that_cannot_be_written_stops_before_a_folder_is_created() {
		try {
			( new SafeWriter() )->prepare_dir(
				$this->dir . '/root',
				'content/uploads',
				array( 'new' ),
				static function () {
					return false;
				}
			);
			$this->fail( 'must throw' );
		} catch ( FileRestoreException $e ) {
			$this->assertSame( 'journal', $e->reason() );
		}
		$this->assertDirectoryDoesNotExist( $this->dir . '/root/new' );
	}

	public function test_a_real_symlink_below_the_root_is_refused_and_nothing_is_created_behind_it() {
		$this->symlink_or_skip( $this->dir . '/outside', $this->dir . '/root/linked' );
		$result = ( new SafeWriter() )->prepare_dir( $this->dir . '/root', 'content/uploads', array( 'linked', 'deeper' ), null );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( ExtractionPolicy::R_DEST_SYMLINK, $result['reason'] );
		$this->assertSame( array(), glob( $this->dir . '/outside/*' ) );
	}

	public function test_a_symlink_in_the_middle_of_the_path_is_refused() {
		mkdir( $this->dir . '/root/a' );
		$this->symlink_or_skip( $this->dir . '/outside', $this->dir . '/root/a/b' );
		$result = ( new SafeWriter() )->prepare_dir( $this->dir . '/root', 'content/uploads', array( 'a', 'b', 'c' ), null );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( ExtractionPolicy::R_DEST_SYMLINK, $result['reason'] );
	}

	public function test_a_pretended_symlink_is_refused_through_the_probe() {
		mkdir( $this->dir . '/root/plain' );
		$writer = new SafeWriter( FileFixtures::probe_with_links( array( $this->dir . '/root/plain' ) ) );
		$result = $writer->prepare_dir( $this->dir . '/root', 'content/uploads', array( 'plain', 'x' ), null );
		$this->assertSame( ExtractionPolicy::R_DEST_SYMLINK, $result['reason'] );
		$this->assertDirectoryDoesNotExist( $this->dir . '/root/plain/x' );
	}

	public function test_a_symlink_that_the_probe_missed_is_caught_by_the_real_path_check() {
		$this->symlink_or_skip( $this->dir . '/outside', $this->dir . '/root/sneaky' );
		// A probe that always says "not a link" (a race): the real path of the folder leaves the root, so it is refused all the same.
		$blind  = new class() extends \OpenVault\Backup\Restore\FsProbe {
			/**
			 * Never a link.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function is_link( $path ) {
				unset( $path );
				return false;
			}
		};
		$result = ( new SafeWriter( $blind ) )->prepare_dir( $this->dir . '/root', 'content/uploads', array( 'sneaky' ), null );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( ExtractionPolicy::R_DEST_SYMLINK, $result['reason'] );
	}

	public function test_a_file_where_a_folder_is_needed_is_refused() {
		file_put_contents( $this->dir . '/root/file', 'x' );
		$result = ( new SafeWriter() )->prepare_dir( $this->dir . '/root', 'content/uploads', array( 'file', 'sub' ), null );
		$this->assertSame( ExtractionPolicy::R_DEST_NOT_DIR, $result['reason'] );
		$this->assertSame( 'x', file_get_contents( $this->dir . '/root/file' ) );
	}

	public function test_a_root_that_is_a_symlink_is_trusted() {
		$this->symlink_or_skip( $this->dir . '/outside', $this->dir . '/rootlink' );
		$result = ( new SafeWriter() )->prepare_dir( $this->dir . '/rootlink', 'content/uploads', array( 'sub' ), null );
		$this->assertTrue( $result['ok'], 'the site owner chose to link this folder' );
		$this->assertDirectoryExists( $this->dir . '/outside/sub' );
	}

	public function test_walk_existing_creates_nothing_and_refuses_links() {
		mkdir( $this->dir . '/root/a' );
		$this->symlink_or_skip( $this->dir . '/outside', $this->dir . '/root/lnk' );
		$writer = new SafeWriter();
		$this->assertSame( array( true, true ), array( $writer->walk_existing( $this->dir . '/root', array( 'a' ) )['ok'], $writer->walk_existing( $this->dir . '/root', array( 'a' ) )['exists'] ) );
		$missing = $writer->walk_existing( $this->dir . '/root', array( 'nope', 'deeper' ) );
		$this->assertTrue( $missing['ok'] );
		$this->assertFalse( $missing['exists'] );
		$this->assertDirectoryDoesNotExist( $this->dir . '/root/nope' );
		$this->assertSame( ExtractionPolicy::R_DEST_SYMLINK, $writer->walk_existing( $this->dir . '/root', array( 'lnk' ) )['reason'] );
	}

	public function test_target_state() {
		$writer = new SafeWriter();
		file_put_contents( $this->dir . '/root/f', 'x' );
		mkdir( $this->dir . '/root/d' );
		$this->assertSame( 'file', $writer->target_state( $this->dir . '/root/f' ) );
		$this->assertSame( 'dir', $writer->target_state( $this->dir . '/root/d' ) );
		$this->assertSame( 'missing', $writer->target_state( $this->dir . '/root/none' ) );
		if ( function_exists( 'symlink' ) && @symlink( $this->dir . '/root/f', $this->dir . '/root/l' ) ) {
			$this->assertSame( 'link', $writer->target_state( $this->dir . '/root/l' ) );
		}
		if ( function_exists( 'symlink' ) && @symlink( $this->dir . '/root/gone', $this->dir . '/root/dangling' ) ) {
			$this->assertSame( 'link', $writer->target_state( $this->dir . '/root/dangling' ), 'a dangling link is still a link' );
		}
	}

	public function test_temp_names_are_next_to_the_target_and_long_names_are_hashed() {
		$this->assertSame( '/d/a.txt.ovbtmpabcd1234', SafeWriter::temp_path( '/d/', 'a.txt', 'abcd1234' ) );
		$long = SafeWriter::temp_path( '/d', str_repeat( 'n', 240 ), 'abcd1234' );
		$this->assertLessThan( 255, strlen( basename( $long ) ) );
		$this->assertStringEndsWith( '.ovbtmpabcd1234', $long );
	}

	public function test_a_file_is_written_in_chunks_and_moved_into_place() {
		$writer                 = new SafeWriter();
		$temp                   = SafeWriter::temp_path( $this->dir . '/root', 'a.txt', 'aa11' );
		$data                   = random_bytes( 700000 );
		list( $handle, $start ) = $writer->open_temp( $temp, 0 );
		$this->assertSame( 0, $start );

		$pos                    = 0;
		$reader                 = static function ( $max ) use ( $data, &$pos ) {
			$chunk = substr( $data, $pos, $max );
			$pos  += strlen( $chunk );
			return $chunk;
		};
		list( $done, $written ) = $writer->copy_chunks( $handle, 0, strlen( $data ), $reader, FileFixtures::tiny_budget( 2 ) );
		$this->assertFalse( $done, 'the budget stopped it between chunks' );
		$this->assertSame( SafeWriter::CHUNK * 2, $written, 'a chunk is copied before the first check and one more while the budget lasts' );
		fclose( $handle );

		// Resume from what is in the temp file (the source is positioned by the caller).
		list( $handle, $start ) = $writer->open_temp( $temp, $written + 5000 );
		$this->assertSame( $written, $start, 'cut back to the bytes the file really holds' );
		$pos                  = $start;
		list( $done, $total ) = $writer->copy_chunks( $handle, $start, strlen( $data ), $reader, null );
		fclose( $handle );
		$this->assertTrue( $done );
		$this->assertSame( strlen( $data ), $total );

		$this->assertTrue( $writer->commit( $temp, $this->dir . '/root/a.txt', 1500000000 ) );
		$this->assertSame( hash( 'sha256', $data ), hash_file( 'sha256', $this->dir . '/root/a.txt' ) );
		$this->assertFileDoesNotExist( $temp );
		clearstatcache();
		$this->assertSame( 1500000000, filemtime( $this->dir . '/root/a.txt' ) );
	}

	public function test_a_resume_offset_larger_than_the_temp_file_starts_at_what_is_there() {
		$writer = new SafeWriter();
		$temp   = SafeWriter::temp_path( $this->dir . '/root', 'a.txt', 'aa11' );
		file_put_contents( $temp, 'abc' );
		list( $handle, $start ) = $writer->open_temp( $temp, 999 );
		fclose( $handle );
		$this->assertSame( 3, $start );
		list( $handle, $start ) = $writer->open_temp( SafeWriter::temp_path( $this->dir . '/root', 'zzz', 'aa11' ), 50 );
		fclose( $handle );
		$this->assertSame( 0, $start, 'no temp file: a new one' );
	}

	public function test_a_planted_symlink_or_file_at_the_temp_name_is_replaced_never_followed() {
		$victim = $this->dir . '/outside/victim.txt';
		file_put_contents( $victim, 'precious' );
		$temp = SafeWriter::temp_path( $this->dir . '/root', 'a.txt', 'aa11' );
		$this->symlink_or_skip( $victim, $temp );

		$writer                 = new SafeWriter();
		list( $handle, $start ) = $writer->open_temp( $temp, 0 );
		fwrite( $handle, 'new data' );
		fclose( $handle );
		$this->assertSame( 0, $start );
		$this->assertSame( 'precious', file_get_contents( $victim ), 'the link target is untouched' );
		$this->assertFalse( is_link( $temp ) );
		$this->assertSame( 'new data', file_get_contents( $temp ) );

		// With an offset: a link is no resume point either.
		unlink( $temp );
		symlink( $victim, $temp );
		list( $handle, $start ) = $writer->open_temp( $temp, 4 );
		fclose( $handle );
		$this->assertSame( 0, $start );
		$this->assertSame( 'precious', file_get_contents( $victim ) );
	}

	public function test_a_full_disk_is_a_clear_error_and_the_reader_is_not_asked_for_more() {
		$writer         = new class() extends SafeWriter {
			/**
			 * Pretend the disk is full.
			 *
			 * @param resource $handle Handle.
			 * @param string   $data   Data.
			 * @return bool
			 */
			public function write_chunk( $handle, $data ) {
				unset( $handle, $data );
				return false;
			}
		};
		$temp           = SafeWriter::temp_path( $this->dir . '/root', 'a.txt', 'aa11' );
		list( $handle ) = $writer->open_temp( $temp, 0 );
		try {
			$writer->copy_chunks(
				$handle,
				0,
				10,
				static function () {
					return 'abcdefghij';
				},
				null
			);
			$this->fail( 'must throw' );
		} catch ( FileRestoreException $e ) {
			$this->assertSame( 'disk_full', $e->reason() );
			$this->assertStringContainsString( 'disk may be full', $e->getMessage() );
		}
		fclose( $handle );
	}

	public function test_a_source_that_ends_early_is_an_archive_error() {
		$writer         = new SafeWriter();
		$temp           = SafeWriter::temp_path( $this->dir . '/root', 'a.txt', 'aa11' );
		list( $handle ) = $writer->open_temp( $temp, 0 );
		$this->expectException( ArchiveException::class );
		try {
			$writer->copy_chunks(
				$handle,
				0,
				100,
				static function () {
					return '';
				},
				null
			);
		} finally {
			fclose( $handle );
		}
	}

	public function test_restored_files_never_get_an_execute_bit_and_replace_an_existing_file() {
		$writer = new SafeWriter();
		file_put_contents( $this->dir . '/root/run.sh', 'old' );
		chmod( $this->dir . '/root/run.sh', 0755 );
		$temp = SafeWriter::temp_path( $this->dir . '/root', 'run.sh', 'aa11' );
		file_put_contents( $temp, 'new' );
		chmod( $temp, 0777 );
		$this->assertTrue( $writer->commit( $temp, $this->dir . '/root/run.sh', 0 ) );
		clearstatcache();
		$this->assertSame( 'new', file_get_contents( $this->dir . '/root/run.sh' ) );
		$this->assertSame( 0, fileperms( $this->dir . '/root/run.sh' ) & 0111, 'no execute bit' );
		$this->assertSame( 0644, SafeWriter::file_mode() );
		$this->assertSame( 0755, SafeWriter::dir_mode() );
	}

	public function test_commit_over_a_symlink_target_never_writes_through_it() {
		// The caller refuses a link target; this documents what rename does if one slips in: the link is replaced, not followed.
		$victim = $this->dir . '/outside/victim.txt';
		file_put_contents( $victim, 'precious' );
		$this->symlink_or_skip( $victim, $this->dir . '/root/t.txt' );
		$temp = SafeWriter::temp_path( $this->dir . '/root', 't.txt', 'aa11' );
		file_put_contents( $temp, 'new' );
		( new SafeWriter() )->commit( $temp, $this->dir . '/root/t.txt', 0 );
		$this->assertSame( 'precious', file_get_contents( $victim ) );
	}

	public function test_discard_removes_a_temp_file() {
		$temp = SafeWriter::temp_path( $this->dir . '/root', 'a.txt', 'aa11' );
		file_put_contents( $temp, 'x' );
		( new SafeWriter() )->discard( $temp );
		$this->assertFileDoesNotExist( $temp );
		( new SafeWriter() )->discard( $temp );
	}

	public function test_the_original_is_copied_with_its_checksum_and_the_first_copy_wins() {
		$writer = new SafeWriter();
		$target = $this->dir . '/root/orig.bin';
		$data   = random_bytes( 600000 );
		file_put_contents( $target, $data );
		touch( $target, 1400000000 );
		$saved = $this->dir . '/undo/files/content/uploads/orig.bin';

		$result = $writer->save_original( $target, $saved );
		$this->assertSame( strlen( $data ), $result['size'] );
		$this->assertSame( hash( 'sha256', $data ), $result['sha256'] );
		$this->assertSame( $data, file_get_contents( $saved ) );
		clearstatcache();
		$this->assertSame( 1400000000, filemtime( $saved ), 'the modification time of the original comes back with it' );
		$this->assertFileDoesNotExist( $saved . '.tmp' );

		// The target changes (a restore that ran again after a crash): the saved original must not be replaced by the newer file.
		file_put_contents( $target, 'the restored version' );
		$again = $writer->save_original( $target, $saved );
		$this->assertSame( $data, file_get_contents( $saved ) );
		$this->assertSame( hash( 'sha256', $data ), $again['sha256'] );
	}

	public function test_an_original_that_cannot_be_read_is_reported_not_thrown() {
		$this->assertNull( ( new SafeWriter() )->save_original( $this->dir . '/root/not-there', $this->dir . '/undo/files/x' ) );
	}

	public function test_a_full_disk_while_saving_the_original_is_a_systemic_error_and_leaves_no_half_copy() {
		$writer = new class() extends SafeWriter {
			/**
			 * Pretend the disk is full.
			 *
			 * @param resource $handle Handle.
			 * @param string   $data   Data.
			 * @return bool
			 */
			public function write_chunk( $handle, $data ) {
				unset( $handle, $data );
				return false;
			}
		};
		file_put_contents( $this->dir . '/root/o.txt', 'original' );
		$saved = $this->dir . '/undo/files/o.txt';
		try {
			$writer->save_original( $this->dir . '/root/o.txt', $saved );
			$this->fail( 'must throw' );
		} catch ( FileRestoreException $e ) {
			$this->assertSame( 'disk_full', $e->reason() );
		}
		$this->assertFileDoesNotExist( $saved );
		$this->assertFileDoesNotExist( $saved . '.tmp' );
		$this->assertSame( 'original', file_get_contents( $this->dir . '/root/o.txt' ) );
	}
}
