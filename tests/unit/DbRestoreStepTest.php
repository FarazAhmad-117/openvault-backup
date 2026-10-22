<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real folders in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\TarWriter;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Restore\RestoreRunner;
use OpenVault\Backup\Restore\Steps\DbRestoreStep;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RestoreFixtures.php';
require_once __DIR__ . '/RestoreDoubles.php';

/**
 * The extraction phase of the database restore step (the other phases need a database: see the matrix) and the rules around it.
 *
 * @covers \OpenVault\Backup\Restore\Steps\DbRestoreStep
 * @covers \OpenVault\Backup\Restore\RestoreRunner
 */
class DbRestoreStepTest extends TestCase {

	/** @var string[] */
	private $dirs = array();
	/** @var MemoryLogger */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = new MemoryLogger();
	}

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			CleanupStep::remove_tree( $dir );
		}
		parent::tearDown();
	}

	private function dir() {
		$dir = sys_get_temp_dir() . '/ovb-dbr-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * A local backup folder with a real tar.gz database part, its manifest, and the plan for it.
	 *
	 * @return array{dir:string,work:string,plan:RestorePlan,sql:string}
	 */
	private function backup( $name = 'db.part01.tar.gz', $mode = 'db' ) {
		$dir = $this->dir();
		$sql = "-- ovb-dump: 1\n" . str_repeat( "INSERT INTO `wp_t` VALUES (1,'abcdefghijklmnopqrstuvwxyz');\n", 20000 );
		$tar = TarWriter::entry_header( 'db/db.sql', strlen( $sql ), 1 ) . $sql . str_repeat( "\0", TarWriter::padding( strlen( $sql ) ) );
		$gz  = gzencode( $tar ) . gzencode( TarWriter::end_blocks() );
		file_put_contents( $dir . '/' . $name, $gz );

		$manifest = RestoreFixtures::manifest(
			array(
				'format' => 'tar.gz',
				'parts'  => array(
					array(
						'name'   => $name,
						'size'   => strlen( $gz ),
						'sha256' => hash( 'sha256', $gz ),
						'kind'   => 'db',
						'index'  => 0,
					),
				),
			)
		);
		$json     = $manifest->to_json();
		file_put_contents( $dir . '/manifest.json', $json );
		$rows = RestoreFixtures::rows(
			$manifest,
			0,
			array(
				'manifest.json' => array(
					'size'            => strlen( $json ),
					'checksum_sha256' => hash( 'sha256', $json ),
				),
			)
		);
		return array(
			'dir'  => $dir,
			'work' => $this->dir(),
			'plan' => RestorePlan::build( RestoreFixtures::backup(), $rows, $manifest, $mode, PlanLoader::list_files( $dir ) ),
			'sql'  => $sql,
		);
	}

	private function context( array $b, JobState $state, $reads = 100 ) {
		return new JobContext( RestoreFixtures::backup(), $state, StepDriver::budget( $reads ), $this->logger, $b['work'], array() );
	}

	private function state( $mode = 'db', array $cursor = array() ) {
		return JobState::new_state( 'db_restore' )->with_meta( array_merge( RestoreMeta::make( 5, $mode ), array( 'confirmed' => true ) ) )->with_cursor( $cursor );
	}

	public function test_the_dump_is_unpacked_over_several_slices_and_validation_comes_next() {
		$b    = $this->backup();
		$step = new DbRestoreStep( new FakePlanLoader( $b['plan'], $b['dir'] ) );

		$state  = $this->state();
		$slices = 0;
		do {
			$result = $step->run( $this->context( $b, $state, 2 ) );
			$this->assertTrue( $result->is_continue(), $result->message() );
			$state = $result->state();
			++$slices;
		} while ( 'x' === $state->get_cursor()['ph'] && $slices < 50 );

		$this->assertGreaterThan( 1, $slices );
		$this->assertSame( 'v', $state->get_cursor()['ph'], 'the next phase is validation' );
		$this->assertSame( 0, $state->get_cursor()['off'] );
		$this->assertFalse( $state->get_cursor()['prefix_ok'] );
		$this->assertSame( hash( 'sha256', $b['sql'] ), hash_file( 'sha256', $b['work'] . '/db.sql' ) );
		$this->assertSame( strlen( $b['sql'] ), $state->get_meta()['dump']['size'] );
		$this->assertSame( 'db_restore', $state->get_step() );
		$this->assertLessThan( 400, strlen( json_encode( $state->get_cursor() ) ), 'the cursor stays tiny' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_a_part_that_changed_since_it_was_verified_stops_the_restore_before_anything_is_unpacked() {
		$b        = $this->backup();
		$part     = $b['dir'] . '/db.part01.tar.gz';
		$data     = file_get_contents( $part );
		$data[40] = chr( ord( $data[40] ) ^ 1 );
		file_put_contents( $part, $data );

		$result = ( new DbRestoreStep( new FakePlanLoader( $b['plan'], $b['dir'] ) ) )->run( $this->context( $b, $this->state() ) );
		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'changed after it was verified', $result->message() );
		$this->assertStringContainsString( 'Nothing was changed', $result->message() );
		$this->assertFileDoesNotExist( $b['work'] . '/db.sql' );
	}

	public function test_a_missing_local_folder_and_a_backup_without_a_database_fail_clearly() {
		$b      = $this->backup();
		$result = ( new DbRestoreStep( new FakePlanLoader( $b['plan'], null ) ) )->run( $this->context( $b, $this->state() ) );
		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'local copy of the backup is gone', $result->message() );

		$plan   = $b['plan'];
		$files  = RestorePlan::build( RestoreFixtures::backup(), RestoreFixtures::rows( RestoreFixtures::manifest(), 0 ), RestoreFixtures::manifest( array( 'parts' => array( RestoreFixtures::part( 'files.part01.zip', 10, 'files', 0 ) ) ) ), 'files', array() );
		$result = ( new DbRestoreStep( new FakePlanLoader( $files, $b['dir'] ) ) )->run( $this->context( $b, $this->state() ) );
		$this->assertTrue( $result->is_fail() );
		unset( $plan );
	}

	public function test_a_part_in_an_unsupported_format_is_refused() {
		$b      = $this->backup( 'db.part01.tar.bz2' );
		$result = ( new DbRestoreStep( new FakePlanLoader( $b['plan'], $b['dir'] ) ) )->run( $this->context( $b, $this->state() ) );
		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'cannot be restored yet', $result->message() );
	}

	public function test_a_files_only_restore_skips_the_step() {
		$b      = $this->backup();
		$result = ( new DbRestoreStep( new FakePlanLoader( $b['plan'], $b['dir'] ) ) )->run( $this->context( $b, $this->state( 'files' ) ) );
		$this->assertTrue( $result->is_next() );
		$this->assertFileDoesNotExist( $b['work'] . '/db.sql' );
	}

	public function test_a_missing_unpacked_dump_in_the_validation_phase_goes_back_to_unpacking() {
		$b      = $this->backup();
		$cursor = array_merge( \OpenVault\Backup\Restore\DatabaseImporter::new_validate_cursor(), array( 'ph' => 'v' ) );
		$result = ( new DbRestoreStep( new FakePlanLoader( $b['plan'], $b['dir'] ) ) )->run( $this->context( $b, $this->state( 'db', $cursor ) ) );
		$this->assertTrue( $result->is_continue() );
		$this->assertSame( 'x', $result->state()->get_cursor()['ph'] );
	}

	public function test_skipped_steps_and_cancel_rules_of_the_runner() {
		$this->assertTrue( RestoreRunner::is_skipped( 'file_restore', array( 'mode' => 'db' ) ), 'a database-only restore has no files to restore' );
		$this->assertFalse( RestoreRunner::is_skipped( 'file_restore', array( 'mode' => 'full' ) ) );
		$this->assertFalse( RestoreRunner::is_skipped( 'file_restore', array( 'mode' => 'files' ) ) );
		$this->assertTrue( RestoreRunner::is_skipped( 'db_restore', array( 'mode' => 'files' ) ), 'a files-only restore has no database to restore' );
		$this->assertFalse( RestoreRunner::is_skipped( 'db_restore', array( 'mode' => 'db' ) ) );
		$this->assertFalse( RestoreRunner::is_skipped( 'db_restore', array( 'mode' => 'full' ) ) );
		$this->assertTrue( RestoreRunner::is_skipped( 'snapshot', array( 'mode' => 'files' ) ), 'a files-only restore takes no database snapshot: its undo journal is the recoverable state' );
		$this->assertFalse( RestoreRunner::is_skipped( 'snapshot', array( 'mode' => 'db' ) ) );
		$this->assertFalse( RestoreRunner::is_skipped( 'snapshot', array( 'mode' => 'full' ) ) );
		foreach ( array( 'preflight', 'fetch', 'verify', 'finish' ) as $step ) {
			$this->assertFalse( RestoreRunner::is_skipped( $step, array( 'mode' => 'db' ) ), $step );
			$this->assertFalse( RestoreRunner::is_skipped( $step, array( 'mode' => 'files' ) ), $step );
		}

		// Cancel is safe until the import starts.
		foreach ( array( 'preflight', 'fetch', 'verify', 'snapshot' ) as $step ) {
			$this->assertSame( '', RestoreRunner::cancel_refusal_for( $step, array() ), $step );
		}
		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'db_restore', array() ), 'before the dump was unpacked' );
		$this->assertSame(
			'',
			RestoreRunner::cancel_refusal_for(
				'db_restore',
				array(
					'ph'  => 'x',
					'off' => 5,
				)
			)
		);
		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'db_restore', array( 'ph' => 'v' ) ), 'while the dump is being checked nothing was changed' );
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'db_restore', array( 'ph' => 'i' ) ) );
		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'file_restore', array(), array( 'mode' => 'files' ) ), 'a files-only restore can be cancelled before its first write' );
		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'file_restore', array( 'ph' => 's' ), array( 'mode' => 'files' ) ) );
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'file_restore', array( 'ph' => 'a' ), array( 'mode' => 'files' ) ), 'files are being written' );
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'file_restore', array(), array( 'mode' => 'full' ) ), 'the database was already replaced' );
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'finish', array() ) );
		$this->assertStringContainsString( 'cannot be cancelled', RestoreRunner::cancel_refusal_for( 'finish', array() ) );
	}
}
