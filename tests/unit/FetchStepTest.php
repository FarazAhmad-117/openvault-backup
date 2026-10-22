<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real folders in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Restore\Steps\FetchStep;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RestoreFixtures.php';
require_once __DIR__ . '/RestoreDoubles.php';

/**
 * @covers \OpenVault\Backup\Restore\Steps\FetchStep
 * @covers \OpenVault\Backup\Restore\Steps\AbstractRestoreStep
 */
class FetchStepTest extends TestCase {

	/**
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * @var MemoryLogger
	 */
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

	private function work_dir() {
		$dir = sys_get_temp_dir() . '/ovb-fetch-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * A backup of three parts held by the given destinations (same bytes everywhere).
	 *
	 * @return array{manifest:\OpenVault\Backup\Support\Manifest,contents:array<string,string>,rows:array<int,array<string,mixed>>}
	 */
	private function backup( array $destination_ids ) {
		$sizes    = array(
			'db.part01.zip'    => array( 'db', 3500 ),
			'files.part01.zip' => array( 'files', 12000 ),
			'files.part02.zip' => array( 'files', 7777 ),
		);
		$parts    = array();
		$contents = array();
		$index    = 0;
		foreach ( $sizes as $name => $spec ) {
			$contents[ $name ] = random_bytes( $spec[1] );
			$parts[]           = array(
				'name'   => $name,
				'size'   => $spec[1],
				'sha256' => hash( 'sha256', $contents[ $name ] ),
				'kind'   => $spec[0],
				'index'  => $index++,
			);
		}
		$contents['manifest.json'] = random_bytes( RestoreFixtures::MANIFEST_SIZE );
		$manifest                  = RestoreFixtures::manifest( array( 'parts' => $parts ) );
		$rows                      = array();
		foreach ( $destination_ids as $id ) {
			$rows = array_merge( $rows, RestoreFixtures::rows( $manifest, $id ) );
		}
		return array(
			'manifest' => $manifest,
			'contents' => $contents,
			'rows'     => $rows,
		);
	}

	private function remote( array $backup, array $destination_ids, $resumable = true ) {
		$fakes = array();
		foreach ( $destination_ids as $id ) {
			$fake = $resumable ? new FakeResumableDownloads() : new FakeDownloads();
			foreach ( $backup['rows'] as $row ) {
				if ( $row['destination_id'] === $id ) {
					$fake->files[ $row['remote_ref'] ] = $backup['contents'][ $row['file_name'] ];
				}
			}
			$fakes[ $id ] = $fake;
		}
		return $fakes;
	}

	private function plan( array $backup, array $tried = array(), array $local = array() ) {
		return RestorePlan::build( RestoreFixtures::backup(), $backup['rows'], $backup['manifest'], 'full', $local, $tried );
	}

	private function step( FakePlanLoader $loader, array $fakes ) {
		return new FetchStep(
			$loader,
			static function ( $id ) use ( $fakes ) {
				if ( ! isset( $fakes[ $id ] ) ) {
					throw DestinationException::permanent( 'The destination no longer exists.' );
				}
				return $fakes[ $id ];
			}
		);
	}

	private function state( array $cursor = array() ) {
		return JobState::new_state( 'fetch' )->with_meta( RestoreMeta::make( 0, 'full' ) )->with_cursor( $cursor );
	}

	private function assert_downloaded( array $backup, $dir ) {
		foreach ( $backup['contents'] as $name => $data ) {
			$this->assertFileExists( $dir . '/' . $name, $name );
			$this->assertSame( hash( 'sha256', $data ), hash_file( 'sha256', $dir . '/' . $name ), $name );
			$this->assertFileDoesNotExist( $dir . '/' . $name . '.part', $name );
		}
	}

	public function test_resume_offset_is_the_smallest_trustworthy_value() {
		$this->assertSame( 100, FetchStep::resume_offset( 100, 5000, 9000 ) );
		$this->assertSame( 50, FetchStep::resume_offset( 9999, 50, 9000 ), 'the cursor is ahead of the disk' );
		$this->assertSame( 9000, FetchStep::resume_offset( 9999, 9999, 9000 ), 'never past the expected size' );
		$this->assertSame( 0, FetchStep::resume_offset( 100, 0, 9000 ) );
		$this->assertSame( 0, FetchStep::resume_offset( -5, 100, 9000 ) );
	}

	public function test_the_local_copy_is_used_in_place() {
		$backup = $this->backup( array( 0 ) );
		$plan   = $this->plan( $backup, array(), RestoreFixtures::local_files( $backup['manifest'] ) );
		$this->assertTrue( $plan->is_local() );

		$step   = $this->step( new FakePlanLoader( $plan ), array() );
		$result = $step->run( new JobContext( RestoreFixtures::backup(), $this->state(), StepDriver::budget( 5 ), $this->logger, $this->work_dir(), array() ) );

		$this->assertTrue( $result->is_next() );
		$this->assertSame( array(), glob( $this->dirs[0] . '/*' ) );
	}

	public function test_a_remote_backup_is_downloaded_over_many_slices_with_a_tiny_cursor() {
		$backup = $this->backup( array( 5 ) );
		$fakes  = $this->remote( $backup, array( 5 ) );
		$dir    = $this->work_dir();
		$loader = new FakePlanLoader( $this->plan( $backup ) );

		$out = StepDriver::drive( $this->step( $loader, $fakes ), $this->state(), RestoreFixtures::backup(), $dir, 3, $this->logger );

		$this->assertSame( StepResult::KIND_NEXT, $out['kind'] );
		$this->assertGreaterThan( 5, $out['slices'], 'many slices' );
		$this->assertContains( StepResult::KIND_CONTINUE, $out['kinds'] );
		$this->assertLessThan( 80, $out['max_cursor'], 'the cursor stays tiny' );
		$this->assert_downloaded( $backup, $dir );
		$this->assertSame( array(), $out['state']->get_cursor() );
		$this->assertLessThan( JobState::MAX_JSON_BYTES, strlen( $out['state']->to_json() ) );
	}

	public function test_a_provider_without_offsets_is_resumed_by_skipping_the_bytes_it_has() {
		$backup = $this->backup( array( 5 ) );
		$fakes  = $this->remote( $backup, array( 5 ), false );
		$dir    = $this->work_dir();

		$out = StepDriver::drive( $this->step( new FakePlanLoader( $this->plan( $backup ) ), $fakes ), $this->state(), RestoreFixtures::backup(), $dir, 4, $this->logger );

		$this->assertSame( StepResult::KIND_NEXT, $out['kind'] );
		$this->assert_downloaded( $backup, $dir );
		foreach ( $fakes[5]->calls as $call ) {
			$this->assertSame( 0, $call[1], 'a provider without offsets is always read from the start' );
		}
		$this->assertGreaterThan( 4, count( $fakes[5]->calls ) );
	}

	public function test_a_resumable_provider_is_asked_for_the_saved_offset() {
		$backup = $this->backup( array( 5 ) );
		$fakes  = $this->remote( $backup, array( 5 ) );
		$dir    = $this->work_dir();

		StepDriver::drive( $this->step( new FakePlanLoader( $this->plan( $backup ) ), $fakes ), $this->state(), RestoreFixtures::backup(), $dir, 3, $this->logger );

		$offsets = array_column( $fakes[5]->calls, 1 );
		$this->assertContains( 0, $offsets );
		$this->assertGreaterThan( 0, max( $offsets ), 'later slices continue at an offset' );
	}

	public function test_garbage_past_the_saved_offset_is_discarded_on_resume() {
		foreach ( array( true, false ) as $resumable ) {
			$backup = $this->backup( array( 5 ) );
			$fakes  = $this->remote( $backup, array( 5 ), $resumable );
			$dir    = $this->work_dir();
			// Where files.part01.zip stands in the plan: manifest first, then db, then this one.
			$name = 'files.part01.zip';
			file_put_contents( $dir . '/' . $name . '.part', substr( $backup['contents'][ $name ], 0, 100 ) . 'GARBAGE' . random_bytes( 4000 ) );
			$cursor = array(
				'i'     => 2,
				'off'   => 100,
				'tries' => 0,
				'n'     => 4,
			);

			$out = StepDriver::drive( $this->step( new FakePlanLoader( $this->plan( $backup ) ), $fakes ), $this->state( $cursor ), RestoreFixtures::backup(), $dir, 3, $this->logger );

			$this->assertSame( StepResult::KIND_NEXT, $out['kind'] );
			$this->assertSame( hash( 'sha256', $backup['contents'][ $name ] ), hash_file( 'sha256', $dir . '/' . $name ), 'resumable: ' . ( $resumable ? 'yes' : 'no' ) );
			$this->assertFileDoesNotExist( $dir . '/' . $name . '.part' );
			$this->assertNotContains( 'manifest.json', array_column( $fakes[5]->calls, 0 ), 'files before the cursor are not fetched again' );
		}
	}

	public function test_a_cursor_ahead_of_the_disk_resumes_at_the_real_size() {
		$backup = $this->backup( array( 5 ) );
		$fakes  = $this->remote( $backup, array( 5 ) );
		$dir    = $this->work_dir();
		$name   = 'db.part01.zip';
		file_put_contents( $dir . '/' . $name . '.part', substr( $backup['contents'][ $name ], 0, 50 ) );
		$cursor = array(
			'i'     => 1,
			'off'   => 3000,
			'tries' => 0,
			'n'     => 2,
		);

		$out = StepDriver::drive( $this->step( new FakePlanLoader( $this->plan( $backup ) ), $fakes ), $this->state( $cursor ), RestoreFixtures::backup(), $dir, 50, $this->logger );

		$this->assertSame( StepResult::KIND_NEXT, $out['kind'] );
		$this->assertSame(
			array( $name, 50 ),
			array_values(
				array_filter(
					$fakes[5]->calls,
					static function ( $c ) use ( $name ) {
						return $c[0] === $name;
					}
				)
			)[0]
		);
		$this->assertSame( hash( 'sha256', $backup['contents'][ $name ] ), hash_file( 'sha256', $dir . '/' . $name ) );
	}

	public function test_files_that_are_already_there_are_skipped() {
		$backup = $this->backup( array( 5 ) );
		$fakes  = $this->remote( $backup, array( 5 ) );
		$dir    = $this->work_dir();
		file_put_contents( $dir . '/manifest.json', $backup['contents']['manifest.json'] );
		file_put_contents( $dir . '/db.part01.zip', $backup['contents']['db.part01.zip'] );

		StepDriver::drive( $this->step( new FakePlanLoader( $this->plan( $backup ) ), $fakes ), $this->state(), RestoreFixtures::backup(), $dir, 100, $this->logger );

		$this->assertSame( array( 'files.part01.zip', 'files.part02.zip' ), array_values( array_unique( array_column( $fakes[5]->calls, 0 ) ) ) );
	}

	public function test_a_retryable_error_waits_with_backoff_and_then_succeeds() {
		$backup                          = $this->backup( array( 5 ) );
		$fakes                           = $this->remote( $backup, array( 5 ) );
		$dir                             = $this->work_dir();
		$fakes[5]->fail['db.part01.zip'] = array( DestinationException::transient( 'Temporary glitch' ), DestinationException::transient( 'Temporary glitch' ) );
		$step                            = $this->step( new FakePlanLoader( $this->plan( $backup ) ), $fakes );

		$ctx   = new JobContext( RestoreFixtures::backup(), $this->state(), StepDriver::budget( 200 ), $this->logger, $dir, array() );
		$first = $step->run( $ctx );
		$this->assertTrue( $first->is_wait() );
		$this->assertSame( 30, $first->seconds() );
		$this->assertSame( 1, $first->state()->get_cursor()['tries'] );

		$second = $step->run( new JobContext( RestoreFixtures::backup(), $first->state(), StepDriver::budget( 200 ), $this->logger, $dir, array() ) );
		$this->assertTrue( $second->is_wait() );
		$this->assertSame( 120, $second->seconds() );
		$this->assertSame( 2, $second->state()->get_cursor()['tries'] );

		$out = StepDriver::drive( $step, $second->state(), RestoreFixtures::backup(), $dir, 200, $this->logger );
		$this->assertSame( StepResult::KIND_NEXT, $out['kind'] );
		$this->assert_downloaded( $backup, $dir );
	}

	public function test_the_third_failure_gives_up_on_the_source_and_names_the_file() {
		$backup                             = $this->backup( array( 5 ) );
		$fakes                              = $this->remote( $backup, array( 5 ) );
		$dir                                = $this->work_dir();
		$glitch                             = DestinationException::transient( 'Temporary glitch' );
		$fakes[5]->fail['files.part02.zip'] = array( $glitch, $glitch, $glitch );

		$out = StepDriver::drive( $this->step( new FakePlanLoader( $this->plan( $backup ) ), $fakes ), $this->state(), RestoreFixtures::backup(), $dir, 200, $this->logger );

		$this->assertSame( StepResult::KIND_FAIL, $out['kind'] );
		$this->assertContains( StepResult::KIND_WAIT, $out['kinds'] );
		$this->assertStringContainsString( 'files.part02.zip', $out['message'] );
		$this->assertStringContainsString( 'Temporary glitch', $out['message'] );
		$this->assertStringContainsString( 'No other copy', $out['message'] );
		$this->assertSame( array( 5 ), RestoreMeta::tried( $out['state']->get_meta() ) );
	}

	public function test_a_permanent_error_fails_at_once_without_retries() {
		$backup                          = $this->backup( array( 5 ) );
		$fakes                           = $this->remote( $backup, array( 5 ) );
		$fakes[5]->fail['manifest.json'] = array( DestinationException::permanent( 'Access denied' ) );

		$out = StepDriver::drive( $this->step( new FakePlanLoader( $this->plan( $backup ) ), $fakes ), $this->state(), RestoreFixtures::backup(), $this->work_dir(), 200, $this->logger );

		$this->assertSame( StepResult::KIND_FAIL, $out['kind'] );
		$this->assertSame( array( StepResult::KIND_FAIL ), $out['kinds'] );
		$this->assertStringContainsString( 'manifest.json', $out['message'] );
		$this->assertStringContainsString( 'Access denied', $out['message'] );
	}

	public function test_a_permanent_error_falls_back_to_the_next_source_once() {
		$backup                          = $this->backup( array( 5, 6 ) );
		$fakes                           = $this->remote( $backup, array( 5, 6 ) );
		$dir                             = $this->work_dir();
		$fakes[5]->fail['db.part01.zip'] = array( DestinationException::permanent( 'Access denied' ) );
		$loader                          = new FakePlanLoader( $this->plan( $backup ) );
		$loader->plans['5']              = $this->plan( $backup, array( 5 ) );

		$out = StepDriver::drive( $this->step( $loader, $fakes ), $this->state(), RestoreFixtures::backup(), $dir, 200, $this->logger );

		$this->assertSame( StepResult::KIND_NEXT, $out['kind'] );
		$this->assertSame( array( 5 ), RestoreMeta::tried( $out['state']->get_meta() ) );
		$this->assert_downloaded( $backup, $dir );
		$this->assertNotEmpty( $fakes[6]->calls );
		$warnings = array_filter(
			$this->logger->lines,
			static function ( $l ) {
				return 'warning' === $l[0];
			}
		);
		$this->assertNotEmpty( $warnings );
	}

	public function test_the_restart_after_a_source_switch_moves_the_cursor() {
		$backup                          = $this->backup( array( 5, 6 ) );
		$fakes                           = $this->remote( $backup, array( 5, 6 ) );
		$fakes[5]->fail['manifest.json'] = array( DestinationException::permanent( 'Access denied' ) );
		$loader                          = new FakePlanLoader( $this->plan( $backup ) );
		$loader->plans['5']              = $this->plan( $backup, array( 5 ) );
		$step                            = $this->step( $loader, $fakes );

		$before = $this->state();
		$result = $step->run( new JobContext( RestoreFixtures::backup(), $before, StepDriver::budget( 50 ), $this->logger, $this->work_dir(), array() ) );

		$this->assertTrue( $result->is_continue() );
		$this->assertNotSame( $before->get_cursor(), $result->state()->get_cursor(), 'the runner must see the step move' );
		$this->assertSame( array( 5 ), RestoreMeta::tried( $result->state()->get_meta() ) );
		$this->assertSame( 'fetch', $result->state()->get_step() );
	}

	public function test_a_file_that_ends_short_is_retried_from_where_it_stopped() {
		$backup                            = $this->backup( array( 5 ) );
		$fakes                             = $this->remote( $backup, array( 5 ) );
		$dir                               = $this->work_dir();
		$fakes[5]->cut['files.part01.zip'] = 5000;

		$out = StepDriver::drive( $this->step( new FakePlanLoader( $this->plan( $backup ) ), $fakes ), $this->state(), RestoreFixtures::backup(), $dir, 300, $this->logger );

		$this->assertSame( StepResult::KIND_NEXT, $out['kind'] );
		$this->assertContains( StepResult::KIND_WAIT, $out['kinds'] );
		$this->assert_downloaded( $backup, $dir );
		$offsets = array();
		foreach ( $fakes[5]->calls as $call ) {
			if ( 'files.part01.zip' === $call[0] ) {
				$offsets[] = $call[1];
			}
		}
		$this->assertSame( array( 0, 5000 ), $offsets );
	}

	public function test_a_plan_without_a_source_fails_clearly() {
		$backup = $this->backup( array( 5 ) );
		$plan   = $this->plan( $backup, array( 5 ) );
		$this->assertNull( $plan->chosen_source() );

		$result = $this->step( new FakePlanLoader( $plan ), array() )->run( new JobContext( RestoreFixtures::backup(), $this->state(), StepDriver::budget( 5 ), $this->logger, $this->work_dir(), array() ) );

		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'No complete copy', $result->message() );
	}

	public function test_an_unreadable_manifest_fails_with_its_message() {
		$result = $this->step( new FakePlanLoader( null ), array() )->run( new JobContext( RestoreFixtures::backup(), $this->state(), StepDriver::budget( 5 ), $this->logger, $this->work_dir(), array() ) );

		$this->assertTrue( $result->is_fail() );
		$this->assertSame( 'No plan.', $result->message() );
	}

	public function test_an_unavailable_destination_is_a_permanent_failure_of_that_source() {
		$backup = $this->backup( array( 5 ) );

		$out = StepDriver::drive( $this->step( new FakePlanLoader( $this->plan( $backup ) ), array() ), $this->state(), RestoreFixtures::backup(), $this->work_dir(), 10, $this->logger );

		$this->assertSame( StepResult::KIND_FAIL, $out['kind'] );
		$this->assertStringContainsString( 'no longer exists', $out['message'] );
	}

	public function test_the_work_folder_must_exist() {
		$backup = $this->backup( array( 5 ) );
		$step   = $this->step( new FakePlanLoader( $this->plan( $backup ) ), $this->remote( $backup, array( 5 ) ) );

		$this->expectException( \RuntimeException::class );
		$step->run( new JobContext( RestoreFixtures::backup(), $this->state(), StepDriver::budget( 5 ), $this->logger, sys_get_temp_dir() . '/ovb-missing-' . bin2hex( random_bytes( 4 ) ), array() ) );
	}
}
