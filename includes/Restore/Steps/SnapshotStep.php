<?php
/**
 * Takes the safety snapshot before anything is changed.
 *
 * Phase: MVP
 *
 * Purpose: Step "snapshot" of a restore job. It runs only after the user confirmed (the runner parks the job in front of it).
 *
 * Responsibilities:
 *  - First slice: SafetySnapshot::create() writes a real backup row (trigger "pre-restore", locked, local only) and starts the engine
 *    job. The snapshot id goes into the job meta, the restore row (snapshot_backup_id) and the log, and is saved at once, so a retry
 *    never starts a second snapshot.
 *  - Following slices: poll the backup (StepResult::wait). While it is queued, running or uploading the step waits (3 seconds);
 *    when it is complete the snapshot is locked and the job moves on; when it failed or vanished the restore FAILS ("nothing was
 *    changed"): the job never goes on to the database import without a complete snapshot. After two hours the wait ends the same way.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore\Steps;

use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\SafetySnapshot;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot step.
 */
class SnapshotStep extends AbstractRestoreStep {

	const POLL_SECONDS = 3;
	const GIVE_UP      = 7200;

	/**
	 * Restores.
	 *
	 * @var RestoresRepository
	 */
	private $restores;

	/**
	 * Snapshot maker.
	 *
	 * @var SafetySnapshot
	 */
	private $snapshot;

	/**
	 * Constructor.
	 *
	 * @param PlanLoader|null         $loader       Plan loader.
	 * @param callable|null           $destinations Provider factory.
	 * @param RestoresRepository|null $restores     Restores.
	 * @param SafetySnapshot|null     $snapshot     Snapshot maker.
	 */
	public function __construct( ?PlanLoader $loader = null, ?callable $destinations = null, ?RestoresRepository $restores = null, ?SafetySnapshot $snapshot = null ) {
		parent::__construct( $loader, $destinations );
		$this->restores = null === $restores ? new RestoresRepository() : $restores;
		$this->snapshot = null === $snapshot ? new SafetySnapshot() : $snapshot;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_SNAPSHOT;
	}

	/**
	 * Start the snapshot, or look at it.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$state       = $context->state();
		$meta        = $state->get_meta();
		$restore_id  = RestoreMeta::restore_id( $meta );
		$snapshot_id = RestoreMeta::snapshot_id( $meta );

		if ( 0 === $snapshot_id ) {
			return $this->start_snapshot( $context, $restore_id );
		}

		switch ( $this->snapshot->state( $snapshot_id ) ) {
			case SafetySnapshot::STATE_COMPLETE:
				$this->snapshot->lock( $snapshot_id );
				$context->log( Logger::INFO, __( 'The safety snapshot is complete.', 'openvault-backup' ), array( 'snapshot_backup_id' => $snapshot_id ) );
				return StepResult::next_step( $state->with_cursor( array() ) );

			case SafetySnapshot::STATE_RUNNING:
				$started = isset( $meta['snapshot']['at'] ) ? (int) $meta['snapshot']['at'] : time();
				if ( time() - $started > self::GIVE_UP ) {
					return StepResult::fail( $this->failure( __( 'The safety snapshot did not finish in two hours.', 'openvault-backup' ) ), $state );
				}
				return StepResult::wait( self::POLL_SECONDS, $state );

			case SafetySnapshot::STATE_MISSING:
				return StepResult::fail( $this->failure( __( 'The safety snapshot no longer exists.', 'openvault-backup' ) ), $state );

			default:
				// Failed: it is not a snapshot anyone can rely on, so it must not stay locked either.
				$this->snapshot->unlock( $snapshot_id );
				return StepResult::fail( $this->failure( __( 'The safety snapshot failed. See the log of the snapshot backup for the reason.', 'openvault-backup' ) ), $state );
		}//end switch
	}

	/**
	 * Create the snapshot and remember it.
	 *
	 * @param JobContext $context    Context.
	 * @param int        $restore_id Restore id.
	 * @return StepResult
	 */
	private function start_snapshot( JobContext $context, $restore_id ) {
		$state = $context->state();
		try {
			$snapshot_id = $this->snapshot->create( $restore_id, RestoreMeta::mode( $state->get_meta() ), (string) $context->backup()['format'] );
		} catch ( \RuntimeException $e ) {
			return StepResult::fail( $e->getMessage(), $state );
		}

		$state = $state->with_meta( RestoreMeta::with_snapshot( $state->get_meta(), $snapshot_id, time() ) );
		// Saved at once (not only when the slice returns): a retry must find the id and never start a second snapshot.
		$this->restores->save_state( $restore_id, $state );
		$this->restores->update_status( $restore_id, 'running', array( 'snapshot_backup_id' => $snapshot_id ) );
		$context->log( Logger::INFO, __( 'The safety snapshot of the current site was started.', 'openvault-backup' ), array( 'snapshot_backup_id' => $snapshot_id ) );

		return StepResult::wait( self::POLL_SECONDS, $state );
	}

	/**
	 * Message of a failed snapshot.
	 *
	 * @param string $why Reason.
	 * @return string
	 */
	private function failure( $why ) {
		return trim( $why . ' ' . __( 'The restore was stopped before anything was changed.', 'openvault-backup' ) );
	}
}
