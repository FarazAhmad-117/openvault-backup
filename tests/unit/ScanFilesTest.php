<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real files in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\Scan\AppendFile;
use OpenVault\Backup\Engine\Scan\LineReader;
use OpenVault\Backup\Engine\Steps\ArchiveStep;
use PHPUnit\Framework\TestCase;

/**
 * Queue and list files (LineReader, AppendFile), the list record format and the cursors' size.
 *
 * @covers \OpenVault\Backup\Engine\Scan\LineReader
 * @covers \OpenVault\Backup\Engine\Scan\AppendFile
 * @covers \OpenVault\Backup\Engine\Steps\ArchiveStep
 */
class ScanFilesTest extends TestCase {

	/**
	 * @var string
	 */
	private $file;

	protected function setUp(): void {
		parent::setUp();
		$this->file = sys_get_temp_dir() . '/ovb-lines-' . bin2hex( random_bytes( 4 ) ) . '.txt';
	}

	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}
		parent::tearDown();
	}

	public function test_lines_are_read_from_a_byte_offset() {
		file_put_contents( $this->file, "one\ntwo\nthree\n" );
		$reader = new LineReader( $this->file, 0 );
		$this->assertSame( 'one', $reader->next() );
		$this->assertSame( 4, $reader->offset() );
		$this->assertSame( 'two', $reader->next() );
		$this->assertSame( 'three', $reader->next() );
		$this->assertSame( 14, $reader->offset() );
		$this->assertNull( $reader->next() );
		$this->assertSame( 14, $reader->offset(), 'the offset stays after the last complete line' );

		$resumed = new LineReader( $this->file, 4 );
		$this->assertSame( 'two', $resumed->next() );
		$this->assertSame( 8, $resumed->offset() );
	}

	public function test_a_cut_off_last_line_is_never_returned() {
		file_put_contents( $this->file, "complete\npart" );
		$reader = new LineReader( $this->file );
		$this->assertSame( 'complete', $reader->next() );
		$this->assertNull( $reader->next() );
		$this->assertSame( 9, $reader->offset() );

		file_put_contents( $this->file, "ial\n", FILE_APPEND );
		$again = new LineReader( $this->file, 9 );
		$this->assertSame( 'partial', $again->next(), 'once the write is completed the line is there' );
	}

	public function test_empty_lines_and_tabs_survive() {
		file_put_contents( $this->file, "\n12\t34\tcontent/a b.txt\n" );
		$reader = new LineReader( $this->file );
		$this->assertSame( '', $reader->next() );
		$this->assertSame( "12\t34\tcontent/a b.txt", $reader->next() );
	}

	public function test_a_missing_file_or_an_offset_past_the_end_is_an_error() {
		$reader = new LineReader( $this->file );
		try {
			$reader->next();
			$this->fail( 'expected an exception for a missing file' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'opened', $e->getMessage() );
		}

		file_put_contents( $this->file, "a\n" );
		$beyond = new LineReader( $this->file, 1000 );
		$this->assertNull( $beyond->next(), 'seeking beyond the end reads nothing' );
	}

	public function test_a_line_that_is_too_long_is_an_error() {
		file_put_contents( $this->file, str_repeat( 'x', LineReader::MAX_LINE + 10 ) . "\n" );
		$this->expectException( \RuntimeException::class );
		( new LineReader( $this->file ) )->next();
	}

	public function test_append_file_cuts_back_to_the_saved_size() {
		$writer = new AppendFile( $this->file );
		$writer->truncate_to( 0 );
		$writer->append( "a\nb\n" );
		$saved = $writer->size();
		$this->assertSame( 4, $saved );
		$writer->append( "lost\nwork\n" );
		$writer->close();
		$this->assertSame( 14, filesize( $this->file ) );

		$resumed = new AppendFile( $this->file );
		$resumed->truncate_to( $saved );
		$resumed->append( "c\n" );
		$resumed->close();
		$this->assertSame( "a\nb\nc\n", file_get_contents( $this->file ) );
	}

	public function test_append_file_refuses_a_file_shorter_than_its_saved_size() {
		file_put_contents( $this->file, 'abc' );
		$this->expectException( \RuntimeException::class );
		( new AppendFile( $this->file ) )->truncate_to( 100 );
	}

	public function test_queue_consumed_while_it_grows() {
		$writer = new AppendFile( $this->file );
		$writer->truncate_to( 0 );
		$writer->append( "0\t\n" );

		$offset = 0;
		$seen   = array();
		for ( $round = 0; $round < 5; $round++ ) {
			$reader = new LineReader( $this->file, $offset );
			$line   = $reader->next();
			if ( null === $line ) {
				break;
			}
			$seen[] = $line;
			$offset = $reader->offset();
			$reader->close();
			if ( $round < 3 ) {
				$writer->append( '0' . "\t" . 'dir' . $round . "\n" );
			}
		}
		$this->assertSame( array( "0\t", "0\tdir0", "0\tdir1", "0\tdir2" ), $seen );
	}

	public function test_list_records() {
		$this->assertSame(
			array(
				'size'  => 12,
				'mtime' => 1700000000,
				'entry' => 'content/a b.txt',
			),
			ArchiveStep::parse_record( "12\t1700000000\tcontent/a b.txt" )
		);
		$this->assertSame( 0, ArchiveStep::parse_record( "0\t0\tabspath/index.php" )['size'] );
		foreach ( array( '', 'x', "1\t2", "a\t2\tcontent/x", "1\tb\tcontent/x", "-1\t2\tcontent/x", "1\t2\t../x", "1\t2\t/abs", "1\t2\tcontent/a\tb", "1\t2\t", "99999999999999999999\t2\tcontent/x" ) as $bad ) {
			$this->assertNull( ArchiveStep::parse_record( $bad ), $bad );
		}
	}

	public function test_manifest_parts_are_ordered_db_first() {
		$row    = static function ( $name, $size ) {
			return array(
				'file_name'       => $name,
				'size'            => $size,
				'checksum_sha256' => str_repeat( 'a', 64 ),
				'upload_status'   => 'done',
			);
		};
		$rows   = array(
			$row( 'files.part02.zip', 20 ),
			$row( 'manifest.json', 5 ),
			$row( 'files.part10.zip', 100 ),
			$row( 'db.part01.zip', 1 ),
			$row( 'files.part01.zip', 10 ),
			$row( 'weird.txt', 1 ),
		);
		$rows[] = array_merge( $row( 'files.part03.zip', 30 ), array( 'upload_status' => 'pending' ) );
		$parts  = ArchiveStep::manifest_parts( $rows );
		$this->assertSame( array( 'db.part01.zip', 'files.part01.zip', 'files.part02.zip', 'files.part10.zip' ), array_column( $parts, 'name' ) );
		$this->assertSame( array( 'db', 'files', 'files', 'files' ), array_column( $parts, 'kind' ) );
		$this->assertSame( array( 1, 1, 2, 10 ), array_column( $parts, 'index' ) );
	}

	public function test_manifest_contents_keeps_only_valid_flags() {
		$this->assertSame(
			array(
				'db'      => true,
				'plugins' => false,
				'uploads' => true,
			),
			ArchiveStep::manifest_contents(
				array(
					'db'      => 1,
					'plugins' => 0,
					'uploads' => 'yes',
					'Bad Key' => true,
					0         => true,
					'../etc'  => true,
				)
			)
		);
	}

	public function test_archiver_for_known_formats() {
		$this->assertSame( 'zip', ArchiveStep::archiver_for( 'zip' )->id() );
		$this->assertSame( 'zip-store', ArchiveStep::archiver_for( 'zip-store' )->id() );
		$this->assertSame( 'tar.gz', ArchiveStep::archiver_for( 'tar.gz' )->id() );
		$this->assertNull( ArchiveStep::archiver_for( 'tar.bz2' ), 'phase 2' );
		$this->assertNull( ArchiveStep::archiver_for( '7z' ) );
	}

	/**
	 * The scan and archive cursors must fit the job state's 64 KB cursor limit, also in the worst case.
	 */
	public function test_cursors_fit_the_job_state_limit() {
		$roots = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$roots[] = array(
				'entry' => 'content/' . str_repeat( 'e', 100 ),
				'abs'   => '/' . str_repeat( 'a', 900 ),
				'type'  => 'dir',
			);
		}
		$scan = array(
			'roots'        => $roots,
			'queue_offset' => PHP_INT_MAX,
			'queue_end'    => PHP_INT_MAX,
			'list_end'     => PHP_INT_MAX,
			'dir'          => "7\t" . str_repeat( 'd', 3900 ),
			'dir_skip'     => PHP_INT_MAX,
			'files'        => PHP_INT_MAX,
			'bytes'        => PHP_INT_MAX,
			'skipped'      => array_fill_keys( array( 'symlink', 'unreadable', 'oversize', 'excluded', 'badname' ), PHP_INT_MAX ),
			'warned'       => PHP_INT_MAX,
		);
		$this->assertLessThan( JobState::MAX_CURSOR_BYTES, strlen( $this->json( $scan ) ) );
		$state = JobState::new_state( 'file_scan' )->with_cursor( $scan );
		$this->assertSame( $scan, $state->get_cursor() );

		$archive = array_fill_keys( array( 'list_offset', 'part_index', 'part_files', 'part_bytes', 'slice_end_size', 'file_pos', 'parts_done', 'files_done', 'bytes_done', 'skipped', 'warned' ), PHP_INT_MAX );
		$archive = array_merge(
			$archive,
			array(
				'phase'     => 'files',
				'part_kind' => 'files',
			)
		);
		$this->assertLessThan( 1024, strlen( $this->json( $archive ) ), 'the archive cursor is tiny: finished parts live in the database' );
		$this->assertSame( $archive, JobState::new_state( 'archive' )->with_cursor( $archive )->get_cursor() );

		$this->expectException( \InvalidArgumentException::class );
		JobState::new_state( 'file_scan' )->with_cursor( array( 'dir' => str_repeat( 'x', JobState::MAX_CURSOR_BYTES ) ) );
	}

	/**
	 * JSON length helper.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function json( $value ) {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}
}
