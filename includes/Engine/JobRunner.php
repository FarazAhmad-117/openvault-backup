<?php
/**
 * Resumable step-based job executor (see docs/plan/04-backup-engine.md).
 *
 * Phase: MVP
 *
 * Purpose: Resumable step-based job executor (see docs/plan/04-backup-engine.md).
 *
 * Responsibilities:
 *  - Acquire lock, load JobState, run current step until time/memory budget hit
 *  - Persist progress, reschedule continuation via Action Scheduler
 *  - Handle failure, retries, final status, notifications (notifications arrive in Phase 2)
 *
 * How a run works: the Action Scheduler action "ovb_run_job" (argument: backup id) calls handle().
 * handle() takes the per-backup JobLock, loads the JobState, creates a Budget and runs steps from the
 * registry (filter "ovb_engine_steps") one slice at a time. The state is saved after every result.
 * When the budget is used up the runner enqueues a new async action and releases the lock.
 * Steps that have no implementation yet are skipped, so the engine can grow step by step.
 *
 * Failure rules: a thrown Throwable counts as an attempt and is retried with backoff (MAX_ATTEMPTS in
 * total), a StepResult::fail() fails at once, a continue_step() that does not move the cursor counts as
 * an attempt too. Jobs without a heartbeat for STALL_TIMEOUT seconds are recovered by recover_stalled().
 *
 * Capability and nonce checks belong to the callers (REST/admin); this class trusts its caller.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

use OpenVault\Backup\Core\Deactivator;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Engine\Steps\ArchiveStep;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Engine\Steps\DatabaseExportStep;
use OpenVault\Backup\Engine\Steps\FileScanStep;
use OpenVault\Backup\Engine\Steps\PreflightStep;
use OpenVault\Backup\Engine\Steps\StepInterface;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Job runner.
 */
class JobRunner {

	const ACTION         = 'ovb_run_job';
	const RECOVER_ACTION = 'ovb_recover_stalled';
	const GROUP          = Deactivator::AS_GROUP;

	/**
	 * Option that throttles the "is the recovery action scheduled" check.
	 */
	const OPTION_RECOVERY_CHECKED = 'ovb_recovery_checked';

	const STEP_ORDER = array(
		JobState::STEP_PREFLIGHT,
		JobState::STEP_DB_EXPORT,
		JobState::STEP_FILE_SCAN,
		JobState::STEP_ARCHIVE,
		JobState::STEP_ENCRYPT,
		JobState::STEP_UPLOAD,
		JobState::STEP_RETENTION,
		JobState::STEP_CLEANUP,
	);

	/**
	 * Attempts of one step before the backup fails.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Seconds to wait before attempt 2 and attempt 3 after a thrown error.
	 */
	const BACKOFF = array( 30, 120 );

	/**
	 * Seconds after which a lock counts as abandoned. A run refreshes it after every step.
	 */
	const LOCK_TTL = 120;

	/**
	 * Seconds without a heartbeat after which a job counts as stalled.
	 */
	const STALL_TIMEOUT = 600;

	/**
	 * Seconds between two stalled-job scans.
	 */
	const RECOVER_INTERVAL = 300;

	/**
	 * Hard cap on step slices per run (guards against a step that never uses any budget).
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

	/**
	 * Backups storage.
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
	 * @param BackupsRepository|null $backups        Storage.
	 * @param Logger|null            $logger         Logger.
	 * @param callable|null          $budget_factory Returns a Budget. Defaults to Budget::from_environment().
	 */
	public function __construct( ?BackupsRepository $backups = null, ?Logger $logger = null, ?callable $budget_factory = null ) {
		$this->backups        = null === $backups ? new BackupsRepository() : $backups;
		$this->logger         = null === $logger ? new Logger() : $logger;
		$this->budget_factory = null === $budget_factory ? array( Budget::class, 'from_environment' ) : $budget_factory;
	}

	/**
	 * Hook the runner into WordPress and Action Scheduler. Called on every request type
	 * (admin, front end, cron, Action Scheduler runs), never only in the admin.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( self::ACTION, array( $this, 'handle' ) );
		add_action( self::RECOVER_ACTION, array( $this, 'run_recovery' ) );
		add_action( 'init', array( $this, 'maybe_schedule_recovery' ), 20 );
	}

	/**
	 * Init hook: schedule the stalled-job scan (throttled).
	 *
	 * @return void
	 */
	public function maybe_schedule_recovery() {
		$this->schedule_recovery();
	}

	/**
	 * Make sure the recurring stalled-job scan is scheduled once. Checked at most once an hour.
	 *
	 * @param bool $force Check now, ignoring the throttle.
	 * @return bool True when the scan is scheduled afterwards.
	 */
	public function schedule_recovery( $force = false ) {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}
		if ( ! $force && ( time() - (int) get_option( self::OPTION_RECOVERY_CHECKED, 0 ) ) < HOUR_IN_SECONDS ) {
			return true;
		}
		if ( ! class_exists( 'ActionScheduler' ) || ! \ActionScheduler::is_initialized() ) {
			return false;
		}

		if ( ! as_has_scheduled_action( self::RECOVER_ACTION, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + self::RECOVER_INTERVAL, self::RECOVER_INTERVAL, self::RECOVER_ACTION, array(), self::GROUP, true );
		}
		update_option( self::OPTION_RECOVERY_CHECKED, time(), true );
		return true;
	}

	/**
	 * Action Scheduler callback of the recurring scan.
	 *
	 * @return void
	 */
	public function run_recovery() {
		$this->recover_stalled( time(), self::STALL_TIMEOUT );
	}

	/**
	 * Start a backup that already has a row: save a fresh state, mark it running and queue the first slice.
	 *
	 * @param int $backup_id Backup id.
	 * @return bool False when the backup does not exist or the job could not be queued.
	 */
	public function start( int $backup_id ) {
		$backup = $this->backups->find( $backup_id );
		if ( null === $backup ) {
			return false;
		}

		// State first: a "queued" row without state is never touched by recover_stalled().
		$this->persist( $backup_id, JobState::new_state( JobState::STEP_PREFLIGHT ) );
		$this->backups->update_status( $backup_id, 'running' );
		$this->logger->info( __( 'Backup started.', 'openvault-backup' ), array( 'format' => $backup['format'] ), $backup_id );

		if ( ! $this->enqueue( $backup_id ) ) {
			$this->fail_backup( $backup_id, null, __( 'The backup could not be queued because Action Scheduler is not available.', 'openvault-backup' ) );
			return false;
		}
		return true;
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $backup_id Backup id.
	 * @return void
	 */
	public function handle( $backup_id ) {
		$this->run( (int) $backup_id );
	}

	/**
	 * Run one slice of a job: as many steps as fit in the budget.
	 *
	 * @param int $backup_id Backup id.
	 * @return string One of the OUTCOME_ constants.
	 */
	public function run( $backup_id ) {
		$backup_id = (int) $backup_id;

		$backup = $this->backups->find( $backup_id );
		if ( null === $backup ) {
			$this->logger->warning( __( 'A job was requested for a backup that does not exist.', 'openvault-backup' ), array( 'backup_id' => $backup_id ) );
			return self::OUTCOME_MISSING;
		}
		if ( in_array( $backup['status'], BackupsRepository::FINISHED, true ) ) {
			return self::OUTCOME_FINISHED;
		}

		$lock = $this->make_lock( $backup_id );
		if ( ! $lock->acquire( self::LOCK_TTL ) ) {
			$this->logger->debug( 'Job is already running, skipping this trigger.', array(), $backup_id );
			return self::OUTCOME_BUSY;
		}

		try {
			return $this->execute( $backup, $lock );
		} catch ( \Throwable $e ) {
			// Anything that escapes here is a bug or an infrastructure failure (for example the database), not a step error.
			return $this->fail_backup(
				$backup_id,
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
	 * Cancel a backup: mark it failed ("Cancelled by user"), drop its pending actions, remove its work files.
	 * A run that is active right now notices the status change after its current slice and stops.
	 *
	 * @param int $backup_id Backup id.
	 * @return bool False when the backup does not exist or is already finished.
	 */
	public function cancel( int $backup_id ) {
		$backup = $this->backups->find( $backup_id );
		if ( null === $backup || in_array( $backup['status'], BackupsRepository::FINISHED, true ) ) {
			return false;
		}

		$this->fail_backup( $backup_id, $this->backups->load_state( $backup_id ), __( 'Cancelled by user', 'openvault-backup' ) );
		return true;
	}

	/**
	 * Find jobs that stopped making progress and resume or fail them.
	 *
	 * A job is stalled when its heartbeat is at least $timeout seconds old and nobody holds a fresh lock.
	 * It is resumed (attempt counter + 1) until it has used MAX_ATTEMPTS, then failed.
	 *
	 * @param int $now     Unix time.
	 * @param int $timeout Seconds without a heartbeat.
	 * @return array{requeued:int[],failed:int[]} Backup ids by outcome.
	 */
	public function recover_stalled( int $now, int $timeout ) {
		$result = array(
			'requeued' => array(),
			'failed'   => array(),
		);

		foreach ( $this->backups->find_incomplete() as $backup ) {
			$backup_id = (int) $backup['id'];
			$state     = $this->backups->load_state( $backup_id );

			if ( null === $state ) {
				if ( 'queued' !== $backup['status'] ) {
					$this->fail_backup( $backup_id, null, __( 'The saved job state is missing or corrupt.', 'openvault-backup' ) );
					$result['failed'][] = $backup_id;
				}
				continue;
			}
			if ( ! $state->is_stalled( $now, $timeout ) || $this->make_lock( $backup_id )->is_locked( self::LOCK_TTL ) ) {
				continue;
			}

			if ( $state->get_attempts() >= self::MAX_ATTEMPTS ) {
				$this->fail_backup(
					$backup_id,
					$state,
					sprintf(
						/* translators: %d: minutes. */
						__( 'The backup stopped making progress for %d minutes and was given up after repeated attempts.', 'openvault-backup' ),
						(int) floor( $timeout / 60 )
					)
				);
				$result['failed'][] = $backup_id;
				continue;
			}

			$this->persist( $backup_id, $state->increment_attempts( $now ) );
			$this->logger->warning(
				__( 'The backup stopped making progress and is being resumed.', 'openvault-backup' ),
				array(
					'step'    => $state->get_step(),
					'attempt' => $state->get_attempts() + 1,
				),
				$backup_id
			);
			if ( $this->enqueue( $backup_id ) ) {
				$result['requeued'][] = $backup_id;
			}
		}//end foreach

		return $result;
	}

	/**
	 * The step implementations by id (preflight, db_export, file_scan, archive and cleanup so far). Filter "ovb_engine_steps" may add, replace or remove steps.
	 * A step id without an implementation is skipped by the runner.
	 *
	 * @return array<string,StepInterface>
	 */
	public function steps() {
		$steps = array(
			JobState::STEP_PREFLIGHT => new PreflightStep(),
			JobState::STEP_DB_EXPORT => new DatabaseExportStep(),
			JobState::STEP_FILE_SCAN => new FileScanStep(),
			JobState::STEP_ARCHIVE   => new ArchiveStep(),
			JobState::STEP_CLEANUP   => new CleanupStep(),
		);

		/**
		 * Filters the engine steps.
		 *
		 * @param array<string,StepInterface> $steps Step id => implementation. Ids are the JobState step ids.
		 */
		$filtered = apply_filters( 'ovb_engine_steps', $steps );

		$valid = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $id => $step ) {
			if ( is_string( $id ) && in_array( $id, self::STEP_ORDER, true ) && $step instanceof StepInterface ) {
				$valid[ $id ] = $step;
			}
		}
		return $valid;
	}

	/**
	 * The step that follows $step_id ("done" after the last one).
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
	 * Whether a not-yet-started action for this backup is queued (running ones do not count).
	 *
	 * @param int $backup_id Backup id.
	 * @return bool
	 */
	public function has_pending_action( int $backup_id ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'args'     => array( $backup_id ),
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
	 * as_has_scheduled_action() is not used because it also counts the running action, which is the caller itself.
	 *
	 * @param int $backup_id Backup id.
	 * @param int $delay     Seconds to wait (0 = async).
	 * @return bool True when an action is pending afterwards.
	 */
	public function enqueue( int $backup_id, int $delay = 0 ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		if ( $this->has_pending_action( $backup_id ) ) {
			return true;
		}

		if ( $delay > 0 ) {
			$id = as_schedule_single_action( time() + $delay, self::ACTION, array( $backup_id ), self::GROUP );
		} else {
			$id = as_enqueue_async_action( self::ACTION, array( $backup_id ), self::GROUP );
		}
		return (int) $id > 0;
	}

	/**
	 * Lock for a backup (overridable for tests).
	 *
	 * @param int $backup_id Backup id.
	 * @return JobLock
	 */
	protected function make_lock( int $backup_id ) {
		return new JobLock( $backup_id );
	}

	/**
	 * Run steps while the budget lasts. Called with the lock held.
	 *
	 * @param array<string,mixed> $backup Backup row.
	 * @param JobLock             $lock   Held lock.
	 * @return string Outcome.
	 */
	private function execute( array $backup, JobLock $lock ) {
		$backup_id = (int) $backup['id'];

		$state = $this->backups->load_state( $backup_id );
		if ( null === $state ) {
			return $this->fail_backup( $backup_id, null, __( 'The saved job state is missing or corrupt.', 'openvault-backup' ) );
		}
		if ( $state->is_terminal() ) {
			return $this->reconcile_terminal( $backup_id, $state );
		}

		try {
			$work_dir = Paths::work_dir( (string) $backup['uuid'] );
		} catch ( \Exception $e ) {
			return $this->fail_backup( $backup_id, $state, $e->getMessage() );
		}

		if ( 'queued' === $backup['status'] ) {
			$this->backups->update_status( $backup_id, 'running' );
		}

		$budget   = call_user_func( $this->budget_factory );
		$settings = get_option( 'ovb_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$steps    = $this->steps();
		$slices   = 0;

		while ( ! $state->is_terminal() ) {
			if ( $slices > 0 && ( $budget->exhausted() || $slices >= self::MAX_SLICES_PER_RUN ) ) {
				return $this->yield_run( $backup_id );
			}
			++$slices;

			$step_id = $state->get_step();
			if ( ! isset( $steps[ $step_id ] ) ) {
				$this->logger->debug( 'No implementation for this step yet, skipping it.', array( 'step' => $step_id ), $backup_id );
				$state = $state->with_step( self::next_step_id( $step_id ) );
				$this->persist( $backup_id, $state );
				continue;
			}

			if ( JobState::STEP_UPLOAD === $step_id && 'uploading' !== $backup['status'] ) {
				$this->backups->update_status( $backup_id, 'uploading' );
				$backup['status'] = 'uploading';
			}
			if ( JobState::STEP_CLEANUP !== $step_id && ! is_dir( $work_dir ) ) {
				wp_mkdir_p( $work_dir );
			}

			$context = new JobContext( $backup, $state, $budget, $this->logger, $work_dir, $settings );
			try {
				$result = $steps[ $step_id ]->run( $context );
			} catch ( \Throwable $e ) {
				return $this->step_threw( $backup_id, $state, $e );
			}

			// Stop if the job was cancelled or deleted while the step ran, and never overwrite that state.
			$fresh = $this->backups->find( $backup_id );
			if ( null === $fresh || in_array( $fresh['status'], BackupsRepository::FINISHED, true ) ) {
				$this->logger->info( __( 'The backup was stopped while a step was running.', 'openvault-backup' ), array( 'step' => $step_id ), $backup_id );
				return self::OUTCOME_ABORTED;
			}
			if ( ! $lock->refresh() ) {
				$this->logger->warning( __( 'The job lock was lost to another process; this run stops.', 'openvault-backup' ), array( 'step' => $step_id ), $backup_id );
				return self::OUTCOME_ABORTED;
			}

			if ( $result->is_fail() ) {
				return $this->fail_backup( $backup_id, $result->state(), $result->message() );
			}

			if ( $result->is_wait() ) {
				$this->persist( $backup_id, $result->state()->touch() );
				$this->enqueue( $backup_id, $result->seconds() );
				return self::OUTCOME_WAITING;
			}

			if ( $result->is_next() ) {
				$state = $result->state()->with_step( self::next_step_id( $step_id ) );
				$this->persist( $backup_id, $state );
				continue;
			}

			// Continue: the same step has more work.
			$progressed = $result->state()->get_cursor() !== $state->get_cursor();
			if ( $progressed ) {
				$state = $result->state()->with_step( $step_id, $result->state()->get_cursor() );
			} else {
				$state = $result->state()->increment_attempts();
				if ( $state->get_attempts() >= self::MAX_ATTEMPTS ) {
					return $this->fail_backup(
						$backup_id,
						$state,
						sprintf(
							/* translators: %s: step id. */
							__( 'The "%s" step is not making progress.', 'openvault-backup' ),
							$step_id
						)
					);
				}
			}
			$this->persist( $backup_id, $state );
		}//end while

		return $this->finalise( $backup_id, $state );
	}

	/**
	 * A step threw: count an attempt, retry with backoff, or give up.
	 *
	 * @param int        $backup_id Backup id.
	 * @param JobState   $state     State before the step ran.
	 * @param \Throwable $error     What was thrown.
	 * @return string Outcome.
	 */
	private function step_threw( $backup_id, JobState $state, \Throwable $error ) {
		$message = DestinationsRepository::scrub_error( $error->getMessage() );
		$state   = $state->increment_attempts();
		$step_id = $state->get_step();

		if ( $state->get_attempts() >= self::MAX_ATTEMPTS ) {
			return $this->fail_backup(
				$backup_id,
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
			$backup_id
		);
		$this->persist( $backup_id, $state );
		$this->enqueue( $backup_id, $delay );
		return self::OUTCOME_RETRY;
	}

	/**
	 * The budget is used up: queue the next slice.
	 *
	 * @param int $backup_id Backup id.
	 * @return string Outcome.
	 */
	private function yield_run( $backup_id ) {
		if ( ! $this->enqueue( $backup_id ) ) {
			return $this->fail_backup( $backup_id, $this->backups->load_state( $backup_id ), __( 'The backup could not continue because the next step could not be queued.', 'openvault-backup' ) );
		}
		$this->logger->debug( 'Budget used up, continuing in the next run.', array(), $backup_id );
		return self::OUTCOME_CONTINUED;
	}

	/**
	 * All steps are done.
	 *
	 * @param int      $backup_id Backup id.
	 * @param JobState $state     Final state.
	 * @return string Outcome.
	 */
	private function finalise( $backup_id, JobState $state ) {
		$meta   = $state->get_meta();
		$result = isset( $meta['result'] ) ? $meta['result'] : '';

		if ( 'partial' === $result ) {
			$status = 'partial';
		} elseif ( 'failed' === $result ) {
			$status = 'failed';
		} else {
			$status = 'complete';
		}

		$this->backups->update_status( $backup_id, $status );
		$this->logger->info(
			__( 'Backup finished.', 'openvault-backup' ),
			array(
				'status'  => $status,
				'seconds' => max( 0, time() - $state->get_started_at() ),
			),
			$backup_id
		);
		return 'failed' === $status ? self::OUTCOME_FAILED : self::OUTCOME_COMPLETE;
	}

	/**
	 * A stored state is already terminal: make the row agree with it.
	 *
	 * @param int      $backup_id Backup id.
	 * @param JobState $state     Terminal state.
	 * @return string Outcome.
	 */
	private function reconcile_terminal( $backup_id, JobState $state ) {
		if ( JobState::STEP_FAILED === $state->get_step() ) {
			$this->backups->update_status( $backup_id, 'failed' );
			return self::OUTCOME_FAILED;
		}
		return $this->finalise( $backup_id, $state );
	}

	/**
	 * Fail a backup for good: state, status, message, pending actions and work files.
	 *
	 * @param int           $backup_id Backup id.
	 * @param JobState|null $state     State to keep the cursor of (a fresh one is used when null).
	 * @param string        $message   Message without secrets.
	 * @return string Outcome.
	 */
	private function fail_backup( $backup_id, ?JobState $state, $message ) {
		$state = null === $state ? JobState::new_state( JobState::STEP_PREFLIGHT ) : $state;
		$state = $state->mark_failed( $message );

		$this->backups->save_state( $backup_id, $state );
		$this->backups->update_status( $backup_id, 'failed' );
		$this->logger->error( $state->get_error() ? (string) $state->get_error() : (string) $message, array( 'step' => $state->get_step() ), $backup_id );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array( $backup_id ), self::GROUP );
		}
		$this->remove_work_files( $backup_id );

		return self::OUTCOME_FAILED;
	}

	/**
	 * Best-effort removal of the work folder.
	 *
	 * @param int $backup_id Backup id.
	 * @return void
	 */
	private function remove_work_files( $backup_id ) {
		try {
			$backup = $this->backups->find( $backup_id );
			if ( null !== $backup ) {
				CleanupStep::remove_work_dir( Paths::work_dir( (string) $backup['uuid'] ) );
			}
		} catch ( \Throwable $e ) {
			$this->logger->warning( __( 'Temporary files could not be removed.', 'openvault-backup' ), array(), $backup_id );
		}
	}

	/**
	 * Save a state; a failed save stops the run (the caller marks the backup failed).
	 *
	 * @param int      $backup_id Backup id.
	 * @param JobState $state     State.
	 * @return void
	 * @throws \RuntimeException When the state cannot be stored.
	 */
	private function persist( $backup_id, JobState $state ) {
		if ( ! $this->backups->save_state( $backup_id, $state ) ) {
			throw new \RuntimeException( 'The job state could not be saved.' );
		}
	}
}
