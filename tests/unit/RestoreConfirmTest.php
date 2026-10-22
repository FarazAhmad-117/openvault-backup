<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The doubles belong to this test only.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Restore\RestoreManager;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePreflight;
use OpenVault\Backup\Restore\RestoreRunner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RestoreFixtures.php';
require_once __DIR__ . '/RestoreDoubles.php';

/**
 * Backups that live in memory.
 */
class MemoryBackups extends BackupsRepository {

	public $incomplete = array();

	public function __construct() {
	}

	public function find_incomplete( $limit = 200 ) {
		return $this->incomplete;
	}

	public function find( $id ) {
		return null;
	}
}

/**
 * Restores that live in memory.
 */
class MemoryRestores extends RestoresRepository {

	public $rows   = array();
	public $states = array();
	public $log    = array();

	public function __construct() {
	}

	public function find( $id ) {
		return isset( $this->rows[ $id ] ) ? $this->rows[ $id ] : null;
	}

	public function load_state( $id ) {
		return isset( $this->states[ $id ] ) ? $this->states[ $id ] : null;
	}

	public function save_state( $id, JobState $state ) {
		$this->states[ $id ] = $state;
		$this->log[]         = 'save';
		return true;
	}

	public function update_status( $id, $status, array $extra = array() ) {
		$this->rows[ $id ]['status'] = $status;
		$this->log[]                 = 'status:' . $status;
		return true;
	}
}

/**
 * A runner that does not touch Action Scheduler.
 */
class QuietRunner extends RestoreRunner {

	public $enqueued  = array();
	public $cancelled = array();
	public $can_queue = true;

	public function enqueue( int $restore_id, int $delay = 0 ) {
		$this->enqueued[] = $restore_id;
		return $this->can_queue;
	}
}

/**
 * RestoreManager::confirm() and cancel(), and the scope rules of the preflight.
 *
 * @covers \OpenVault\Backup\Restore\RestoreManager
 * @covers \OpenVault\Backup\Restore\RestorePreflight
 */
class RestoreConfirmTest extends TestCase {

	/** @var MemoryBackups */
	private $backups;
	/** @var MemoryRestores */
	private $restores;
	/** @var QuietRunner */
	private $runner;
	/** @var RestoreManager */
	private $manager;

	protected function setUp(): void {
		parent::setUp();
		$this->backups  = new MemoryBackups();
		$this->restores = new MemoryRestores();
		$this->runner   = new QuietRunner( $this->restores, $this->backups, new MemoryLogger() );
		$this->manager  = new RestoreManager( $this->backups, $this->restores, null, null, $this->runner );
	}

	private function job( $status, $step = 'snapshot', array $meta = array(), array $cursor = array() ) {
		$this->restores->rows[3]   = array(
			'id'        => 3,
			'backup_id' => 7,
			'mode'      => 'db',
			'status'    => $status,
			'step'      => $step,
		);
		$this->restores->states[3] = JobState::new_state( $step )->with_meta( array_merge( RestoreMeta::make( 3, 'db' ), $meta ) )->with_cursor( $cursor );
	}

	public function test_confirm_sets_the_flag_moves_the_job_to_running_and_queues_it() {
		$this->job( 'verified' );
		$answer = $this->manager->confirm( 3 );
		$this->assertTrue( $answer['ok'], $answer['message'] );
		$this->assertSame( 'confirmed', $answer['code'] );
		$this->assertTrue( RestoreMeta::confirmed( $this->restores->states[3]->get_meta() ) );
		$this->assertSame( 'snapshot', $this->restores->states[3]->get_step(), 'the job stays in front of the snapshot step' );
		$this->assertSame( 'running', $this->restores->rows[3]['status'] );
		$this->assertSame( array( 3 ), $this->runner->enqueued );
		$this->assertSame( array( 'save', 'status:running' ), $this->restores->log, 'the flag is saved before the job is released' );
		$this->assertFalse( RestoreRunner::is_gate( 'snapshot', $this->restores->states[3]->get_meta() ), 'the runner lets it through now' );
	}

	public function test_confirm_is_refused_for_anything_that_is_not_waiting() {
		$answer = $this->manager->confirm( 99 );
		$this->assertFalse( $answer['ok'] );
		$this->assertSame( 'restore_missing', $answer['code'] );

		foreach ( array( 'queued', 'running', 'complete', 'failed', 'cancelled' ) as $status ) {
			$this->job( $status );
			$answer = $this->manager->confirm( 3 );
			$this->assertFalse( $answer['ok'], $status );
			$this->assertSame( 'not_verified', $answer['code'], $status );
			$this->assertFalse( RestoreMeta::confirmed( $this->restores->states[3]->get_meta() ), $status );
		}
		$this->assertSame( array(), $this->runner->enqueued );

		$this->job( 'verified', 'fetch' );
		$this->assertSame( 'not_verified', $this->manager->confirm( 3 )['code'], 'a verified status with another step is not the gate' );
		$this->restores->states = array();
		$this->job( 'verified' );
		unset( $this->restores->states[3] );
		$this->assertSame( 'not_verified', $this->manager->confirm( 3 )['code'], 'no saved state' );
	}

	public function test_confirm_twice_does_not_queue_twice() {
		$this->job( 'verified' );
		$this->assertTrue( $this->manager->confirm( 3 )['ok'] );
		$this->assertFalse( $this->manager->confirm( 3 )['ok'], 'the second one finds a running job' );
		$this->assertSame( array( 3 ), $this->runner->enqueued );
	}

	public function test_confirm_is_refused_while_a_backup_runs() {
		$this->job( 'verified' );
		$this->backups->incomplete = array( array( 'id' => 11 ) );
		$answer                    = $this->manager->confirm( 3 );
		$this->assertFalse( $answer['ok'] );
		$this->assertSame( 'backup_running', $answer['code'] );
		$this->assertSame( 'verified', $this->restores->rows[3]['status'] );
		$this->assertFalse( RestoreMeta::confirmed( $this->restores->states[3]->get_meta() ) );
	}

	public function test_confirm_reports_a_job_that_cannot_be_queued() {
		$this->job( 'verified' );
		$this->runner->can_queue = false;
		$answer                  = $this->manager->confirm( 3 );
		$this->assertFalse( $answer['ok'] );
		$this->assertSame( 'enqueue_failed', $answer['code'] );
	}

	public function test_cancel_is_refused_once_the_database_import_has_started() {
		$this->job(
			'running',
			'db_restore',
			array( 'confirmed' => true ),
			array(
				'ph'  => 'i',
				'off' => 100,
			)
		);
		$this->assertNotSame( '', $this->manager->cancel_refusal( 3 ) );
		$this->assertFalse( $this->manager->cancel( 3 ) );
		$this->assertSame( 'running', $this->restores->rows[3]['status'], 'nothing changed' );

		$this->job( 'running', 'finish', array( 'confirmed' => true ) );
		$this->assertFalse( $this->manager->cancel( 3 ) );
	}

	public function test_cancel_is_refused_for_unknown_or_finished_restores() {
		$this->assertFalse( $this->manager->cancel( 99 ) );
		$this->job( 'complete', 'done' );
		$this->assertFalse( $this->manager->cancel( 3 ) );
	}

	public function test_the_refusal_text_says_what_to_do() {
		$this->job( 'running', 'db_restore', array(), array( 'ph' => 'i' ) );
		$text = $this->manager->cancel_refusal( 3 );
		$this->assertStringContainsString( 'Undo restore', $text );
		$this->job( 'verified' );
		$this->assertSame( '', $this->manager->cancel_refusal( 3 ) );
		$this->assertSame( '', $this->manager->cancel_refusal( 12345 ) );
	}

	// Preflight scope rules.

	private function facts( array $override = array() ) {
		return array_merge(
			array(
				'mode'            => 'db',
				'modes_available' => RestorePreflight::AVAILABLE_MODES,
				'multisite'       => false,
				'restores_db'     => true,
				'base_prefix'     => 'wp_',
			),
			$override
		);
	}

	private function codes( array $facts ) {
		return array_column( RestorePreflight::evaluate( $facts )['errors'], 'code' );
	}

	public function test_every_user_mode_is_available() {
		$this->assertSame( array( 'db', 'files', 'full' ), RestorePreflight::AVAILABLE_MODES );
		$this->assertSame( array(), $this->codes( $this->facts() ) );
		foreach ( array( 'db', 'files', 'full' ) as $mode ) {
			$this->assertSame( array(), $this->codes( $this->facts( array( 'mode' => $mode ) ) ), $mode );
		}
		$result = RestorePreflight::evaluate( $this->facts( array( 'mode' => 'undo' ) ) );
		$this->assertContains( 'mode_unavailable', array_column( $result['errors'], 'code' ), 'an undo job is created by RestoreManager::undo(), not by the preflight' );
	}

	public function test_a_multisite_network_is_refused_for_every_mode() {
		foreach ( array( 'files', 'full' ) as $mode ) {
			$result = RestorePreflight::evaluate(
				$this->facts(
					array(
						'mode'        => $mode,
						'restores_db' => 'full' === $mode,
						'multisite'   => true,
					)
				)
			);
			$this->assertContains( 'multisite_unsupported', array_column( $result['errors'], 'code' ), $mode );
		}
	}

	public function test_a_multisite_network_cannot_be_restored_yet() {
		$result = RestorePreflight::evaluate( $this->facts( array( 'multisite' => true ) ) );
		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'multisite_unsupported', array_column( $result['errors'], 'code' ) );
		$this->assertStringContainsString( 'Restoring a multisite network is not supported yet', implode( ' ', array_column( $result['errors'], 'message' ) ) );
	}

	public function test_a_long_table_prefix_is_refused_for_a_database_restore() {
		$this->assertContains( 'prefix_too_long', $this->codes( $this->facts( array( 'base_prefix' => str_repeat( 'a', 41 ) ) ) ) );
		$this->assertNotContains( 'prefix_too_long', $this->codes( $this->facts( array( 'base_prefix' => str_repeat( 'a', 40 ) ) ) ) );
	}

	public function test_facts_without_scope_information_are_not_judged() {
		$this->assertSame( array(), $this->codes( array( 'mode' => 'files' ) ) );
		$this->assertSame( array(), $this->codes( array() ) );
	}
}
