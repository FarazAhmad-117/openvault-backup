<?php
/**
 * Resumable step-based executor of restore jobs (see docs/plan/08-restore.md).
 *
 * Phase: MVP
 *
 * Purpose: The engine of a restore. It is built on the same primitives as Engine\JobRunner (JobState, StepResult, Budget, JobLock,
 * Action Scheduler) but works over RestoresRepository, so the job record survives the database import of a later step.
 *
 * Responsibilities:
 *  - The Action Scheduler action "ovb_run_restore" (argument: restore id) calls handle(). A run takes the global restore lock,
 *    loads the JobState and runs steps from the registry (filter "ovb_restore_steps") one slice at a time within the Budget.
 *    The state is saved after every result; when the budget is used up a new async action is queued.
 *  - Locking: one global lock for all heavy jobs of restores, the option "ovb_lock_restore" (JobLock key "restore"). Only one
 *    restore may be active at all (RestoreManager::start() refuses a second one), so a per-restore lock would add nothing. The other
 *    direction of the exclusion is a database fact, not a lock: RestoreManager::start() refuses while a backup is queued, running
 *    or uploading, and the backup start (REST) refuses while a restore is queued or running.
 *  - Steps in order: preflight, fetch, verify, snapshot, db_restore, file_restore, file_undo, finish. is_skipped() is the one place that decides
 *    which of them a job passes over: no safety snapshot for a files-only restore (its undo journal is the recoverable state) or for an
 *    undo job, no database step without a database part, no file step without files (or for an undo job), no file_undo except in an undo job
 *    that has files to put back, and no preflight/fetch/verify for an undo job without a database snapshot to restore.
 *  - The confirmation gate: after "verify" the job stops BEFORE "snapshot" with the status "verified": everything is downloaded and
 *    checked and the job waits for the user to confirm the changes (the apply steps need the meta flag "confirmed", set by
 *    RestoreManager::confirm()). A verified job is not queued again, holds no lock, and keeps its work folder until it is applied or
 *    cancelled. If the flag is set but a later step has no implementation the job fails clearly.
 *  - A step may jump: next_step() with a state that already names another step moves the job there (used to go back to "fetch" with
 *    the next source).
 *  - Failure rules are the backup runner's: a thrown Throwable is an attempt with backoff (MAX_ATTEMPTS), StepResult::fail() fails at
 *    once, a continue_step() that does not move the cursor counts as an attempt, jobs without a heartbeat for STALL_TIMEOUT seconds
 *    are resumed or failed by recover_stalled() (called from the same 5-minute recovery action as the backup runner).
 *  - cancel() marks the job "cancelled", drops its pending actions and removes tmp/restore-<id> (and nothing else). It is refused once
 *    the database import or the first file write has started (cancel_refusal()).
 *
 * Capability and nonce checks belong to the callers (REST/admin); this class trusts its caller.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Core\Deactivator;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobLock;
use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\Steps\StepInterface;
use OpenVault\Backup\Restore\Steps\DbRestoreStep;
use OpenVault\Backup\Restore\Steps\FetchStep;
use OpenVault\Backup\Restore\Steps\FileRestoreStep;
use OpenVault\Backup\Restore\Steps\FileUndoStep;
use OpenVault\Backup\Restore\Steps\FinishStep;
use OpenVault\Backup\Restore\Steps\PreflightStep;
use OpenVault\Backup\Restore\Steps\SnapshotStep;
use OpenVault\Backup\Restore\Steps\VerifyStep;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Restore job runner.
 */
class RestoreRunner {

	const ACTION   = 'ovb_run_restore';
	const GROUP    = Deactivator::AS_GROUP;
	const LOCK_KEY = 'restore';

	const STEP_ORDER = array(
		JobState::STEP_PREFLIGHT,
		JobState::STEP_FETCH,
		JobState::STEP_VERIFY,
		JobState::STEP_SNAPSHOT,
		JobState::STEP_DB_RESTORE,
		JobState::STEP_FILE_RESTORE,
		JobState::STEP_FILE_UNDO,
		JobState::STEP_FINISH,
	);

	/**
	 * The step the job waits in front of until the user confirms.
	 */
	const GATE_STEP = JobState::STEP_SNAPSHOT;

	const MAX_ATTEMPTS = 3;

	/**
	 * Seconds to wait before attempt 2 and attempt 3 after a thrown error.
	 */
	const BACKOFF = array( 30, 120 );

	/**
	 * Seconds after which the lock counts as abandoned. A run refreshes it after every step.
	 */
	const LOCK_TTL = 120;

	/**
	 * Seconds without a heartbeat after which a job counts as stalled.
	 */
	const STALL_TIMEOUT = 600;

	/**
	 * Hard cap on step slices per run.
	 */
	const MAX_SLICES_PER_RUN = 500;

	const OUTCOME_COMPLETE  = 'complete';
	const OUTCOME_FAILED    = 'failed';
	const OUTCOME_CONTINUED = 'continued';
	const OUTCOME_WAITING   = 'waiting';
	const OUTCOME_RETRY     = 'retry';
	const OUTCOME_BUSY      = 'busy';
	const OUTCOME_MISSING   = 'missing';
	const OUTCOME_FINISHED  = 'finished';
	const OUTCOME_ABORTED   = 'aborted';
	const OUTCOME_VERIFIED  = 'verified';

	/**
	 * Restores.
	 *
	 * @var RestoresRepository
	 */
	private $restores;

	/**
	 * Backups.
	 *
	 * @var BackupsRepository
	 */
	private $backups;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Creates the Budget of a run.
	 *
	 * @var callable
	 */
	private $budget_factory;

	/**
	 * Constructor.
	 *
	 * @param RestoresRepository|null $restores       Restores.
	 * @param BackupsRepository|null  $backups        Backups.
	 * @param Logger|null             $logger         Logger.
	 * @param callable|null           $budget_factory Returns a Budget. Defaults to Budget::from_environment().
	 */
	public function __construct( ?RestoresRepository $restores = null, ?BackupsRepository $backups = null, ?Logger $logger = null, ?callable $budget_factory = null ) {
		$this->restores       = null === $restores ? new RestoresRepository() : $restores;
		$this->backups        = null === $backups ? new BackupsRepository() : $backups;
		$this->logger         = null === $logger ? new Logger() : $logger;
		$this->budget_factory = null === $budget_factory ? array( Budget::class, 'from_environment' ) : $budget_factory;
	}

	/**
	 * Hook the runner into Action Scheduler and the stalled-job scan. Called on every request type.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( self::ACTION, array( $this, 'handle' ) );
		add_action( JobRunner::RECOVER_ACTION, array( $this, 'run_recovery' ) );
	}

	/**
	 * Action Scheduler callback of the recurring scan (the backup runner schedules it).
	 *
	 * @return void
	 */
	public function run_recovery() {
		$this->recover_stalled( time(), self::STALL_TIMEOUT );
	}

	/**
	 * The step a job moves to after $step_id ("done" after the last one).
	 *
	 * @param string $step_id Step id.
	 * @return string
	 */
	public static function next_step_id( $step_id ) {
		$index = array_search( $step_id, self::STEP_ORDER, true );
		if ( false === $index || ! isset( self::STEP_ORDER[ $index + 1 ] ) ) {
			return JobState::STEP_DONE;
		}
		return self::STEP_ORDER[ $index + 1 ];
	}

	/**
	 * Where a finished step sends the job. A step that returned a state with another (known) step jumps there.
	 *
	 * @param string $step_id      Step that ran.
	 * @param string $state_step   Step named by the state the step returned.
	 * @return string
	 */
	public static function target_step( $step_id, $state_step ) {
		if ( $state_step !== $step_id && in_array( $state_step, self::STEP_ORDER, true ) ) {
			return $state_step;
		}
		return self::next_step_id( $step_id );
	}

	/**
	 * Whether a step does not apply to this job and is passed over (pure).
	 *
	 * - preflight, fetch, verify: skipped by an undo job that has no database snapshot to restore (nothing to download).
	 * - snapshot: skipped without a database change (a files-only restore: the undo journal is its recoverable state) and by undo jobs (an undo
	 *   is not itself undoable; the snapshot it restores stays).
	 * - db_restore: skipped without a database change.
	 * - file_restore: skipped without file changes and by undo jobs.
	 * - file_undo: runs only in an undo job with files to put back.
	 *
	 * @param string       $step_id Step about to run.
	 * @param array<mixed> $meta    Job meta.
	 * @return bool
	 */
	public static function is_skipped( $step_id, array $meta ) {
		$undo  = RestoreMeta::MODE_UNDO === RestoreMeta::mode( $meta );
		$db    = RestoreMeta::does_db( $meta );
		$files = RestoreMeta::does_files( $meta );
		switch ( $step_id ) {
			case JobState::STEP_PREFLIGHT:
			case JobState::STEP_FETCH:
			case JobState::STEP_VERIFY:
				return $undo && ! $db;
			case JobState::STEP_SNAPSHOT:
				return $undo || ! $db;
			case JobState::STEP_DB_RESTORE:
				return ! $db;
			case JobState::STEP_FILE_RESTORE:
				return $undo || ! $files;
			case JobState::STEP_FILE_UNDO:
				return ! $undo || ! $files;
		}
		return false;
	}

	/**
	 * Why a job at this point can no longer be cancelled ('' when it can). Pure.
	 *
	 * Until the first change nothing on the site was changed. The database import starts when the dump has been checked (cursor
	 * phase "i" of db_restore: the site is in maintenance mode and tables are being replaced); a file restore or file undo starts writing
	 * when its setup phase is over (cursor phase "a"). From then on, in finish, and in any file step of a job that also changes the database
	 * (the database is already replaced), cancelling would leave a half-restored site, so it is refused. Undo restore is the way back.
	 *
	 * @param string       $step   Step of the job.
	 * @param array<mixed> $cursor Cursor of the job.
	 * @param array<mixed> $meta   Meta of the job (decides whether the database is already changed in a file step).
	 * @return string
	 */
	public static function cancel_refusal_for( $step, array $cursor, array $meta = array() ) {
		$in_file_step = JobState::STEP_FILE_RESTORE === $step || JobState::STEP_FILE_UNDO === $step;
		$started      = JobState::STEP_FINISH === $step
			|| ( $in_file_step && ( RestoreMeta::does_db( $meta ) || ( isset( $cursor['ph'] ) && 'a' === $cursor['ph'] ) ) )
			|| ( JobState::STEP_DB_RESTORE === $step && isset( $cursor['ph'] ) && DbRestoreStep::PHASE_IMPORT === $cursor['ph'] );
		return $started ? __( 'The restore is already changing your site and cannot be cancelled. Wait for it to finish; if it fails, use Undo restore (the safety snapshot) or restore again.', 'openvault-backup' ) : '';
	}

	/**
	 * Whether the job must wait for the user before running this step.
	 *
	 * @param string       $step_id Step about to run.
	 * @param array<mixed> $meta    Job meta.
	 * @return bool
	 */
	public static function is_gate( $step_id, array $meta ) {
		return self::GATE_STEP === $step_id && ! RestoreMeta::confirmed( $meta );
	}

	/**
	 * Start a restore that already has a row: save the first state (with the meta), mark it running and queue the first slice.
	 *
	 * @param int                 $restore_id Restore id.
	 * @param array<string,mixed> $meta       Job meta (see RestoreMeta::make()).
	 * @return bool False when the restore does not exist or could not be queued.
	 */
	public function start( int $restore_id, array $meta ) {
		$row = $this->restores->find( $restore_id );
		if ( null === $row ) {
			return false;
		}

		// State first: a "queued" row without state is never touched by recover_stalled().
		$this->persist( $restore_id, JobState::new_state( JobState::STEP_PREFLIGHT )->with_meta( $meta ) );
		$this->restores->update_status( $restore_id, 'running' );
		$this->logger->info( __( 'Restore started.', 'openvault-backup' ), array( 'mode' => $row['mode'] ), $this->log_id( $row ) );

		if ( ! $this->enqueue( $restore_id ) ) {
			$this->fail_restore( $restore_id, null, __( 'The restore could not be queued because Action Scheduler is not available.', 'openvault-backup' ) );
			return false;
		}
		return true;
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $restore_id Restore id.
	 * @return void
	 */
	public function handle( $restore_id ) {
		$this->run( (int) $restore_id );
	}

	/**
	 * Run one slice of a job: as many steps as fit in the budget.
	 *
	 * @param int $restore_id Restore id.
	 * @return string One of the OUTCOME_ constants.
	 */
	public function run( $restore_id ) {
		$restore_id = (int) $restore_id;

		$row = $this->restores->find( $restore_id );
		if ( null === $row ) {
			$this->logger->warning( __( 'A job was requested for a restore that does not exist.', 'openvault-backup' ), array( 'restore_id' => $restore_id ) );
			return self::OUTCOME_MISSING;
		}
		if ( in_array( $row['status'], RestoresRepository::FINISHED, true ) ) {
			return self::OUTCOME_FINISHED;
		}

		$lock = $this->make_lock();
		if ( ! $lock->acquire( self::LOCK_TTL ) ) {
			$this->logger->debug( 'A restore slice is already running, skipping this trigger.', array(), $this->log_id( $row ) );
			return self::OUTCOME_BUSY;
		}

		try {
			return $this->execute( $row, $lock );
		} catch ( \Throwable $e ) {
			// Anything that escapes here is a bug or an infrastructure failure (for example the database), not a step error.
			return $this->fail_restore(
				$restore_id,
				null,
				sprintf(
					/* translators: %s: error message. */
					__( 'Unexpected error: %s', 'openvault-backup' ),
					DestinationsRepository::scrub_error( $e->getMessage() )
				)
			);
		} finally {
			$lock->release();
		}
	}

	/**
	 * Cancel a restore: status "cancelled", pending actions dropped, tmp/restore-<id> removed. A run that is active right now
	 * notices the status change after its current slice and stops.
	 *
	 * @param int $restore_id Restore id.
	 * @return bool False when the restore does not exist or is already finished.
	 */
	public function cancel( int $restore_id ) {
		$row = $this->restores->find( $restore_id );
		if ( null === $row || in_array( $row['status'], RestoresRepository::FINISHED, true ) || '' !== $this->cancel_refusal( $restore_id ) ) {
			return false;
		}

		$this->fail_restore( $restore_id, $this->restores->load_state( $restore_id ), __( 'Cancelled by user', 'openvault-backup' ), 'cancelled' );
		return true;
	}

	/**
	 * Why a restore cannot be cancelled right now ('' when it can).
	 *
	 * @param int $restore_id Restore id.
	 * @return string
	 */
	public function cancel_refusal( int $restore_id ) {
		$state = $this->restores->load_state( $restore_id );
		return null === $state ? '' : self::cancel_refusal_for( $state->get_step(), $state->get_cursor(), $state->get_meta() );
	}

	/**
	 * Find jobs that stopped making progress and resume or fail them (queued and running only; a verified job is waiting for the user).
	 *
	 * @param int $now     Unix time.
	 * @param int $timeout Seconds without a heartbeat.
	 * @return array{requeued:int[],failed:int[]} Restore ids by outcome.
	 */
	public function recover_stalled( int $now, int $timeout ) {
		$result = array(
			'requeued' => array(),
			'failed'   => array(),
		);

		foreach ( $this->restores->find_incomplete() as $row ) {
			$restore_id = (int) $row['id'];
			$state      = $this->restores->load_state( $restore_id );

			if ( null === $state ) {
				if ( 'queued' !== $row['status'] ) {
					$this->fail_restore( $restore_id, null, __( 'The saved job state is missing or corrupt.', 'openvault-backup' ) );
					$result['failed'][] = $restore_id;
				}
				continue;
			}
			if ( ! $state->is_stalled( $now, $timeout ) || $this->make_lock()->is_locked( self::LOCK_TTL ) ) {
				continue;
			}

			if ( $state->get_attempts() >= self::MAX_ATTEMPTS ) {
				$this->fail_restore(
					$restore_id,
					$state,
					sprintf(
						/* translators: %d: minutes. */
						__( 'The restore stopped making progress for %d minutes and was given up after repeated attempts.', 'openvault-backup' ),
						(int) floor( $timeout / 60 )
					)
				);
				$result['failed'][] = $restore_id;
				continue;
			}

			$this->persist( $restore_id, $state->increment_attempts( $now ) );
			$this->logger->warning(
				__( 'The restore stopped making progress and is being resumed.', 'openvault-backup' ),
				array(
					'step'    => $state->get_step(),
					'attempt' => $state->get_attempts() + 1,
				),
				$this->log_id( $row )
			);
			if ( $this->enqueue( $restore_id ) ) {
				$result['requeued'][] = $restore_id;
			}
		}//end foreach

		return $result;
	}

	/**
	 * The step implementations by id. Filter "ovb_restore_steps" may add, replace or remove steps.
	 *
	 * @return array<string,StepInterface>
	 */
	public function steps() {
		$steps = array(
			JobState::STEP_PREFLIGHT    => new PreflightStep(),
			JobState::STEP_FETCH        => new FetchStep(),
			JobState::STEP_VERIFY       => new VerifyStep(),
			JobState::STEP_SNAPSHOT     => new SnapshotStep(),
			JobState::STEP_DB_RESTORE   => new DbRestoreStep(),
			JobState::STEP_FILE_RESTORE => new FileRestoreStep(),
			JobState::STEP_FILE_UNDO    => new FileUndoStep(),
			JobState::STEP_FINISH       => new FinishStep(),
		);

		/**
		 * Filters the restore steps.
		 *
		 * @param array<string,StepInterface> $steps Step id => implementation. Ids are the JobState step ids.
		 */
		$filtered = apply_filters( 'ovb_restore_steps', $steps );

		$valid = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $id => $step ) {
			if ( is_string( $id ) && in_array( $id, self::STEP_ORDER, true ) && $step instanceof StepInterface ) {
				$valid[ $id ] = $step;
			}
		}
		return $valid;
	}

	/**
	 * Whether a not-yet-started action for this restore is queued (running ones do not count).
	 *
	 * @param int $restore_id Restore id.
	 * @return bool
	 */
	public function has_pending_action( int $restore_id ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'args'     => array( $restore_id ),
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		return ! empty( $ids );
	}

	/**
	 * Queue the next slice as soon as possible (or after $delay seconds). Does nothing if one is already pending.
	 *
	 * @param int $restore_id Restore id.
	 * @param int $delay      Seconds to wait (0 = async).
	 * @return bool True when an action is pending afterwards.
	 */
	public function enqueue( int $restore_id, int $delay = 0 ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		if ( $this->has_pending_action( $restore_id ) ) {
			return true;
		}

		if ( $delay > 0 ) {
			$id = as_schedule_single_action( time() + $delay, self::ACTION, array( $restore_id ), self::GROUP );
		} else {
			$id = as_enqueue_async_action( self::ACTION, array( $restore_id ), self::GROUP );
		}
		return (int) $id > 0;
	}

	/**
	 * The global restore lock (overridable for tests).
	 *
	 * @return JobLock
	 */
	protected function make_lock() {
		return new JobLock( self::LOCK_KEY );
	}

	/**
	 * Run steps while the budget lasts. Called with the lock held.
	 *
	 * @param array<string,mixed> $row  Restore row.
	 * @param JobLock             $lock Held lock.
	 * @return string Outcome.
	 */
	private function execute( array $row, JobLock $lock ) {
		$restore_id = (int) $row['id'];

		$state = $this->restores->load_state( $restore_id );
		if ( null === $state ) {
			return $this->fail_restore( $restore_id, null, __( 'The saved job state is missing or corrupt.', 'openvault-backup' ) );
		}
		if ( $state->is_terminal() ) {
			return $this->reconcile_terminal( $restore_id, $state );
		}

		$backup = $this->backups->find( (int) $row['backup_id'] );
		if ( null === $backup ) {
			return $this->fail_restore( $restore_id, $state, __( 'The backup to restore no longer exists.', 'openvault-backup' ) );
		}

		try {
			$work_dir = RestoreWorkDir::path( $restore_id );
		} catch ( \Exception $e ) {
			return $this->fail_restore( $restore_id, $state, $e->getMessage() );
		}

		if ( 'queued' === $row['status'] ) {
			$this->restores->update_status( $restore_id, 'running' );
		}

		$budget   = call_user_func( $this->budget_factory );
		$settings = get_option( 'ovb_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$steps    = $this->steps();
		$slices   = 0;

		while ( ! $state->is_terminal() ) {
			if ( $slices > 0 && ( $budget->exhausted() || $slices >= self::MAX_SLICES_PER_RUN ) ) {
				return $this->yield_run( $restore_id );
			}
			++$slices;

			$step_id = $state->get_step();
			if ( self::is_gate( $step_id, $state->get_meta() ) ) {
				return $this->park( $row, $state );
			}
			if ( self::is_skipped( $step_id, $state->get_meta() ) ) {
				$state = $state->with_step( self::next_step_id( $step_id ) );
				$this->persist( $restore_id, $state );
				continue;
			}
			if ( ! isset( $steps[ $step_id ] ) ) {
				return $this->fail_restore( $restore_id, $state, __( 'A step of this restore is not available.', 'openvault-backup' ) );
			}

			if ( ! is_dir( $work_dir ) ) {
				try {
					RestoreWorkDir::ensure( $restore_id );
				} catch ( \Exception $e ) {
					return $this->fail_restore( $restore_id, $state, $e->getMessage() );
				}
			}

			$context = new JobContext( $backup, $state, $budget, $this->logger, $work_dir, $settings );
			try {
				$result = $steps[ $step_id ]->run( $context );
			} catch ( \Throwable $e ) {
				return $this->step_threw( $restore_id, $state, $e );
			}

			// Stop if the job was cancelled or deleted while the step ran, and never overwrite that state.
			$fresh = $this->restores->find( $restore_id );
			if ( null === $fresh || in_array( $fresh['status'], RestoresRepository::FINISHED, true ) ) {
				$this->logger->info( __( 'The restore was stopped while a step was running.', 'openvault-backup' ), array( 'step' => $step_id ), $this->log_id( $row ) );
				RestoreWorkDir::remove( $restore_id );
				return self::OUTCOME_ABORTED;
			}
			if ( ! $lock->refresh() ) {
				$this->logger->warning( __( 'The restore lock was lost to another process; this run stops.', 'openvault-backup' ), array( 'step' => $step_id ), $this->log_id( $row ) );
				return self::OUTCOME_ABORTED;
			}

			if ( $result->is_fail() ) {
				return $this->fail_restore( $restore_id, $result->state(), $result->message() );
			}

			if ( $result->is_wait() ) {
				$this->persist( $restore_id, $result->state()->touch() );
				$this->enqueue( $restore_id, $result->seconds() );
				return self::OUTCOME_WAITING;
			}

			if ( $result->is_next() ) {
				$state = $result->state()->with_step( self::target_step( $step_id, $result->state()->get_step() ) );
				$this->persist( $restore_id, $state );
				continue;
			}

			// Continue: the same step has more work.
			$progressed = $result->state()->get_cursor() !== $state->get_cursor();
			if ( $progressed ) {
				$state = $result->state()->with_step( $step_id, $result->state()->get_cursor() );
			} else {
				$state = $result->state()->increment_attempts();
				if ( $state->get_attempts() >= self::MAX_ATTEMPTS ) {
					return $this->fail_restore(
						$restore_id,
						$state,
						sprintf(
							/* translators: %s: step id. */
							__( 'The "%s" step is not making progress.', 'openvault-backup' ),
							$step_id
						)
					);
				}
			}
			$this->persist( $restore_id, $state );
		}//end while

		return $this->finalise( $restore_id, $state );
	}

	/**
	 * Everything is downloaded and verified: wait for the user's confirmation. No action is queued and no lock is kept.
	 *
	 * @param array<string,mixed> $row   Restore row.
	 * @param JobState            $state State positioned at the gate step.
	 * @return string Outcome.
	 */
	private function park( array $row, JobState $state ) {
		$restore_id = (int) $row['id'];
		$this->persist( $restore_id, $state->touch() );
		if ( 'verified' !== $row['status'] ) {
			$this->restores->update_status( $restore_id, 'verified' );
			$this->logger->info( __( 'The backup is downloaded and verified. The restore waits for your confirmation.', 'openvault-backup' ), array(), $this->log_id( $row ) );
		}
		return self::OUTCOME_VERIFIED;
	}

	/**
	 * A step threw: count an attempt, retry with backoff, or give up.
	 *
	 * @param int        $restore_id Restore id.
	 * @param JobState   $state      State before the step ran.
	 * @param \Throwable $error      What was thrown.
	 * @return string Outcome.
	 */
	private function step_threw( $restore_id, JobState $state, \Throwable $error ) {
		$message = DestinationsRepository::scrub_error( $error->getMessage() );
		$state   = $state->increment_attempts();
		$step_id = $state->get_step();

		if ( $state->get_attempts() >= self::MAX_ATTEMPTS ) {
			return $this->fail_restore(
				$restore_id,
				$state,
				sprintf(
					/* translators: 1: step id, 2: number of attempts, 3: error message. */
					__( 'The "%1$s" step failed after %2$d attempts: %3$s', 'openvault-backup' ),
					$step_id,
					$state->get_attempts(),
					$message
				)
			);
		}

		$delay = self::BACKOFF[ min( $state->get_attempts(), count( self::BACKOFF ) ) - 1 ];
		$this->logger->warning(
			__( 'A step failed and will be retried.', 'openvault-backup' ),
			array(
				'step'         => $step_id,
				'attempt'      => $state->get_attempts(),
				'max_attempts' => self::MAX_ATTEMPTS,
				'retry_in'     => $delay,
				'error'        => $message,
				'exception'    => get_class( $error ),
			),
			$this->log_id( $this->restores->find( $restore_id ) )
		);
		$this->persist( $restore_id, $state );
		$this->enqueue( $restore_id, $delay );
		return self::OUTCOME_RETRY;
	}

	/**
	 * The budget is used up: queue the next slice.
	 *
	 * @param int $restore_id Restore id.
	 * @return string Outcome.
	 */
	private function yield_run( $restore_id ) {
		if ( ! $this->enqueue( $restore_id ) ) {
			return $this->fail_restore( $restore_id, $this->restores->load_state( $restore_id ), __( 'The restore could not continue because the next step could not be queued.', 'openvault-backup' ) );
		}
		return self::OUTCOME_CONTINUED;
	}

	/**
	 * All steps are done.
	 *
	 * @param int      $restore_id Restore id.
	 * @param JobState $state      Final state.
	 * @return string Outcome.
	 */
	private function finalise( $restore_id, JobState $state ) {
		$this->restores->update_status( $restore_id, 'complete' );
		$this->logger->info(
			__( 'Restore finished.', 'openvault-backup' ),
			array( 'seconds' => max( 0, time() - $state->get_started_at() ) ),
			$this->log_id( $this->restores->find( $restore_id ) )
		);
		return self::OUTCOME_COMPLETE;
	}

	/**
	 * A stored state is already terminal: make the row agree with it.
	 *
	 * @param int      $restore_id Restore id.
	 * @param JobState $state      Terminal state.
	 * @return string Outcome.
	 */
	private function reconcile_terminal( $restore_id, JobState $state ) {
		if ( JobState::STEP_FAILED === $state->get_step() ) {
			$this->restores->update_status( $restore_id, 'failed', array( 'error' => (string) $state->get_error() ) );
			return self::OUTCOME_FAILED;
		}
		return $this->finalise( $restore_id, $state );
	}

	/**
	 * End a restore for good (failed or cancelled): state, status, message, pending actions and work files.
	 *
	 * @param int           $restore_id Restore id.
	 * @param JobState|null $state      State to keep the cursor of (a fresh one is used when null).
	 * @param string        $message    Message without secrets.
	 * @param string        $status     "failed" or "cancelled".
	 * @return string Outcome.
	 */
	private function fail_restore( $restore_id, ?JobState $state, $message, $status = 'failed' ) {
		$state = null === $state ? JobState::new_state( JobState::STEP_PREFLIGHT ) : $state;
		$state = $state->mark_failed( $message );

		$this->restores->save_state( $restore_id, $state );
		$this->restores->update_status( $restore_id, $status, array( 'error' => (string) $state->get_error() ) );
		$this->logger->log(
			'cancelled' === $status ? Logger::INFO : Logger::ERROR,
			$state->get_error() ? (string) $state->get_error() : (string) $message,
			array( 'step' => $state->get_step() ),
			$this->log_id( $this->restores->find( $restore_id ) )
		);

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array( $restore_id ), self::GROUP );
		}
		try {
			RestoreWorkDir::remove( $restore_id );
		} catch ( \Throwable $e ) {
			$this->logger->warning( __( 'Temporary restore files could not be removed.', 'openvault-backup' ), array( 'restore_id' => $restore_id ) );
		}

		return self::OUTCOME_FAILED;
	}

	/**
	 * The id under which a restore logs: the backup it restores.
	 *
	 * @param array<string,mixed>|null $row Restore row.
	 * @return int|null
	 */
	private function log_id( $row ) {
		return is_array( $row ) && isset( $row['backup_id'] ) ? (int) $row['backup_id'] : null;
	}

	/**
	 * Save a state; a failed save stops the run (the caller marks the restore failed).
	 *
	 * @param int      $restore_id Restore id.
	 * @param JobState $state      State.
	 * @return void
	 * @throws \RuntimeException When the state cannot be stored.
	 */
	private function persist( $restore_id, JobState $state ) {
		if ( ! $this->restores->save_state( $restore_id, $state ) ) {
			throw new \RuntimeException( 'The job state could not be saved.' );
		}
	}
}
