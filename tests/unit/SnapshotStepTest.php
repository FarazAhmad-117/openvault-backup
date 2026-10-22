<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The doubles belong to this test only.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\SafetySnapshot;
use OpenVault\Backup\Restore\Steps\SnapshotStep;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RestoreFixtures.php';
require_once __DIR__ . '/RestoreDoubles.php';

/**
 * A repository that remembers what was written to it.
 */
class RecordingRestores extends RestoresRepository {

	public $saved   = array();
	public $updates = array();

	public function __construct() {
	}

	public function save_state( $id, JobState $state ) {
		$this->saved[] = array( $id, $state );
		return true;
	}

	public function update_status( $id, $status, array $extra = array() ) {
		$this->updates[] = array( $id, $status, $extra );
		return true;
	}
}

/**
 * A snapshot maker that never touches the database.
 */
class FakeSnapshot extends SafetySnapshot {

	public $state_value = SafetySnapshot::STATE_RUNNING;
	public $created     = array();
	public $locked      = array();
	public $unlocked    = array();
	public $error       = null;
	public $next_id     = 55;

	public function __construct() {
	}

	public function create( $restore_id, $mode, $preferred ) {
		if ( null !== $this->error ) {
			throw new \RuntimeException( $this->error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
		}
		$this->created[] = array( $restore_id, $mode, $preferred );
		return $this->next_id;
	}

	public function state( $backup_id ) {
		return $this->state_value;
	}

	public function lock( $backup_id ) {
		$this->locked[] = $backup_id;
	}

	public function unlock( $backup_id ) {
		$this->unlocked[] = $backup_id;
	}
}

/**
 * @covers \OpenVault\Backup\Restore\Steps\SnapshotStep
 * @covers \OpenVault\Backup\Restore\SafetySnapshot
 */
class SnapshotStepTest extends TestCase {

	/** @var RecordingRestores */
	private $restores;
	/** @var FakeSnapshot */
	private $snapshot;
	/** @var MemoryLogger */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->restores = new RecordingRestores();
		$this->snapshot = new FakeSnapshot();
		$this->logger   = new MemoryLogger();
	}

	private function run_step( array $meta_extra = array() ) {
		$meta  = array_merge( RestoreMeta::make( 9, 'db' ), array( 'confirmed' => true ), $meta_extra );
		$state = JobState::new_state( 'snapshot' )->with_meta( $meta );
		$step  = new SnapshotStep( new FakePlanLoader(), null, $this->restores, $this->snapshot );
		return $step->run( new JobContext( RestoreFixtures::backup( array( 'format' => 'tar.gz' ) ), $state, StepDriver::budget( 10 ), $this->logger, '/nonexistent', array() ) );
	}

	public function test_the_first_slice_starts_the_snapshot_and_remembers_it_at_once() {
		$result = $this->run_step();
		$this->assertTrue( $result->is_wait() );
		$this->assertSame( array( array( 9, 'db', 'tar.gz' ) ), $this->snapshot->created );
		$this->assertSame( 55, RestoreMeta::snapshot_id( $result->state()->get_meta() ) );
		$this->assertCount( 1, $this->restores->saved, 'the id was stored before the slice ended' );
		$this->assertSame( 55, RestoreMeta::snapshot_id( $this->restores->saved[0][1]->get_meta() ) );
		$this->assertSame( array( array( 9, 'running', array( 'snapshot_backup_id' => 55 ) ) ), $this->restores->updates );
	}

	public function test_while_the_snapshot_runs_the_step_waits_and_starts_no_second_one() {
		$this->snapshot->state_value = SafetySnapshot::STATE_RUNNING;
		$result                      = $this->run_step( RestoreMeta::with_snapshot( array(), 55, time() ) );
		$this->assertTrue( $result->is_wait() );
		$this->assertSame( SnapshotStep::POLL_SECONDS, $result->seconds() );
		$this->assertSame( array(), $this->snapshot->created );
	}

	public function test_a_complete_snapshot_is_locked_and_the_job_goes_on() {
		$this->snapshot->state_value = SafetySnapshot::STATE_COMPLETE;
		$result                      = $this->run_step( RestoreMeta::with_snapshot( array(), 55, time() ) );
		$this->assertTrue( $result->is_next() );
		$this->assertSame( array( 55 ), $this->snapshot->locked );
		$this->assertSame( array(), $result->state()->get_cursor() );
	}

	public function test_a_failed_snapshot_stops_the_restore_before_anything_is_changed() {
		$this->snapshot->state_value = SafetySnapshot::STATE_FAILED;
		$result                      = $this->run_step( RestoreMeta::with_snapshot( array(), 55, time() ) );
		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'safety snapshot failed', $result->message() );
		$this->assertStringContainsString( 'before anything was changed', $result->message() );
		$this->assertSame( array( 55 ), $this->snapshot->unlocked, 'a failed snapshot is not kept locked' );
		$this->assertSame( array(), $this->snapshot->locked );
	}

	public function test_a_vanished_snapshot_stops_the_restore() {
		$this->snapshot->state_value = SafetySnapshot::STATE_MISSING;
		$result                      = $this->run_step( RestoreMeta::with_snapshot( array(), 55, time() ) );
		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'no longer exists', $result->message() );
	}

	public function test_a_snapshot_that_never_finishes_ends_the_wait() {
		$this->snapshot->state_value = SafetySnapshot::STATE_RUNNING;
		$result                      = $this->run_step( RestoreMeta::with_snapshot( array(), 55, time() - SnapshotStep::GIVE_UP - 5 ) );
		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'did not finish', $result->message() );
	}

	public function test_a_snapshot_that_cannot_be_started_fails_the_restore_with_the_reason() {
		$this->snapshot->error = 'The local backup folder is not writable.';
		$result                = $this->run_step();
		$this->assertTrue( $result->is_fail() );
		$this->assertSame( 'The local backup folder is not writable.', $result->message() );
		$this->assertSame( array(), $this->restores->saved );
	}

	public function test_snapshot_state_classification() {
		$this->assertSame( 'missing', SafetySnapshot::classify( null ) );
		$this->assertSame( 'missing', SafetySnapshot::classify( array() ) );
		$this->assertSame( 'complete', SafetySnapshot::classify( array( 'status' => 'complete' ) ) );
		foreach ( array( 'queued', 'running', 'uploading' ) as $status ) {
			$this->assertSame( 'running', SafetySnapshot::classify( array( 'status' => $status ) ), $status );
		}
		$this->assertSame( 'failed', SafetySnapshot::classify( array( 'status' => 'failed' ) ) );
		$this->assertSame( 'failed', SafetySnapshot::classify( array( 'status' => 'partial' ) ), 'a snapshot with warnings is not relied on' );
	}

	public function test_snapshot_contents_note_and_trigger() {
		$contents = SafetySnapshot::contents_for_mode( 'db' );
		$this->assertTrue( $contents['db'] );
		$this->assertSame( array( 'db' ), array_keys( array_filter( $contents ) ) );
		$this->assertSame( array( 'db', 'plugins', 'themes', 'uploads', 'mu_plugins', 'other_content', 'core', 'wp_config' ), array_keys( $contents ), 'the same flags as a normal backup' );
		$this->assertSame( 'Safety snapshot before restore #12', SafetySnapshot::note( 12 ) );
		$this->assertSame( 'pre-restore', SafetySnapshot::TRIGGER );
	}
}
