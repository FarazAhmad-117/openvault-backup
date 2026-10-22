<?php
/**
 * Orchestrates restore as a resumable job (see docs/plan/08-restore.md).
 *
 * Phase: MVP
 *
 * Purpose: Orchestrates restore as a resumable job (see docs/plan/08-restore.md).
 *
 * Responsibilities:
 *  - Fetch -> verify checksum -> safety snapshot -> maintenance on -> restore DB/files -> maintenance off
 *  - start() checks everything, creates the restore job and queues it. The job runs through RestoreRunner as far as "verified"
 *    (downloaded, checked, waiting for the user's confirmation).
 *  - confirm() is the user's go: it sets the meta flag "confirmed", moves the job from "verified" to "running" and queues it. The job
 *    then runs, in this order and as far as the mode needs: the safety snapshot (database modes), the database import, the file restore
 *    (maintenance mode, self-preservation, undo journal) and the finish step.
 *  - cancel() is safe until the first change (the database import or the first file write); after that it is refused (cancel_refusal() has the message).
 *  - undo() reverses a finished (or failed after it started) restore: a new job of mode "undo" restores the database snapshot and replays the file
 *    journal backwards. can_undo() says whether and why not; dismiss_undo() deletes the journal and its saved files.
 *  - Capability and nonce checks belong to the callers (REST/admin, step 5d); this class trusts its caller.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobLock;
use OpenVault\Backup\Engine\JobState;

defined( 'ABSPATH' ) || exit;

/**
 * Restore manager.
 */
class RestoreManager {

	/**
	 * Seconds a start holds the start guard (the check and the creation of the job happen inside it).
	 */
	const START_GUARD_TTL = 30;

	/**
	 * Backups.
	 *
	 * @var BackupsRepository
	 */
	private $backups;

	/**
	 * Restores.
	 *
	 * @var RestoresRepository
	 */
	private $restores;

	/**
	 * Plan loader.
	 *
	 * @var PlanLoader
	 */
	private $loader;

	/**
	 * Preflight.
	 *
	 * @var RestorePreflight
	 */
	private $preflight;

	/**
	 * Runner.
	 *
	 * @var RestoreRunner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param BackupsRepository|null  $backups   Backups.
	 * @param RestoresRepository|null $restores  Restores.
	 * @param PlanLoader|null         $loader    Plan loader.
	 * @param RestorePreflight|null   $preflight Preflight.
	 * @param RestoreRunner|null      $runner    Runner.
	 */
	public function __construct( ?BackupsRepository $backups = null, ?RestoresRepository $restores = null, ?PlanLoader $loader = null, ?RestorePreflight $preflight = null, ?RestoreRunner $runner = null ) {
		$this->backups   = null === $backups ? new BackupsRepository() : $backups;
		$this->restores  = null === $restores ? new RestoresRepository() : $restores;
		$this->loader    = null === $loader ? new PlanLoader() : $loader;
		$this->preflight = null === $preflight ? new RestorePreflight( $this->backups, $this->restores ) : $preflight;
		$this->runner    = null === $runner ? new RestoreRunner( $this->restores, $this->backups ) : $runner;
	}

	/**
	 * Check a restore and, when everything is fine, create its job and queue it.
	 *
	 * Options: "acknowledge_url_mismatch" (bool) confirms that the restored database will carry the old site address;
	 * "user_id" (int) is recorded as the creator (defaults to the current user). "keep_site_urls" (bool, default false) keeps this site's
	 * own siteurl and home across the database import; it only counts together with "acknowledge_url_mismatch" (a safety valve so the
	 * restored site stays reachable on its current address, not a migration: serialized data still carries the old address).
	 *
	 * @param int                 $backup_id Backup to restore (a finished backup of this site's history).
	 * @param string              $mode      full, db or files.
	 * @param array<string,mixed> $options   Options (see above; "status_token_hash" (string) is the sha256 of the token of the unauthenticated status route, stored in the job meta; "restore_server_config" (bool, default false) restores .htaccess, web.config, php.ini and
	 *                                       .user.ini of the WordPress folder too: they are skipped by default because a wrong one can lock you out).
	 * @return array{ok:bool,restore_id:int,errors:array<int,array{code:string,message:string}>,warnings:array<int,array{code:string,message:string}>,requires_ack:array<int,array{code:string,message:string}>,plan:array<string,mixed>|null}
	 *         ok true and restore_id >= 1 when the job was created; otherwise the structured preflight failure (restore_id 0).
	 */
	public function start( $backup_id, $mode, array $options = array() ) {
		if ( ! is_string( $mode ) || ! in_array( $mode, RestorePlan::MODES, true ) ) {
			return self::failure( 'mode', __( 'Choose what to restore: everything, the database only, or the files only.', 'openvault-backup' ) );
		}

		$backup = $this->backups->find( (int) $backup_id );
		if ( null === $backup ) {
			return self::failure( 'backup_missing', __( 'This backup does not exist.', 'openvault-backup' ) );
		}
		if ( ! in_array( $backup['status'], array( 'complete', 'partial' ), true ) ) {
			return self::failure( 'backup_not_finished', __( 'Only a finished backup can be restored.', 'openvault-backup' ) );
		}

		$guard = new JobLock( 'restore_start' );
		if ( ! $guard->acquire( self::START_GUARD_TTL ) ) {
			return self::failure( 'restore_running', __( 'A restore is already starting. Wait a moment and try again.', 'openvault-backup' ) );
		}

		try {
			$ack    = ! empty( $options['acknowledge_url_mismatch'] ) ? array( RestorePreflight::ACK_URL_MISMATCH ) : array();
			$loaded = $this->loader->load( $backup, $mode );
			$server = ! empty( $options['restore_server_config'] );
			$result = $this->preflight->run( $loaded, $ack, 0, array( 'server_config' => $server ) );
			$plan   = $loaded['plan'] instanceof RestorePlan ? $loaded['plan']->to_array() : null;
			if ( ! $result['ok'] ) {
				return array_merge(
					$result,
					array(
						'restore_id' => 0,
						'plan'       => $plan,
					)
				);
			}

			$user_id = isset( $options['user_id'] ) ? (int) $options['user_id'] : get_current_user_id();
			$id      = $this->restores->create(
				array(
					'backup_id'  => (int) $backup['id'],
					'mode'       => $mode,
					'created_by' => max( 0, $user_id ),
				)
			);
			if ( $id < 1 ) {
				return self::failure( 'create_failed', __( 'The restore could not be created.', 'openvault-backup' ) );
			}

			$keep        = ! empty( $options['keep_site_urls'] ) && array() !== $ack;
			$meta        = RestoreMeta::make( $id, $mode, $ack, $keep );
			$plan_object = $loaded['plan'];
			if ( $plan_object instanceof RestorePlan ) {
				$meta = RestoreMeta::with_scope( $meta, $plan_object->restores_db(), $plan_object->restores_files() );
			}
			if ( $server ) {
				$meta = RestoreMeta::with_server_config( $meta );
			}
			$meta = RestoreMeta::with_status_token( $meta, isset( $options['status_token_hash'] ) ? (string) $options['status_token_hash'] : '' );
			if ( ! $this->runner->start( $id, $meta ) ) {
				return self::failure( 'start_failed', __( 'The restore could not be started. See the logs for details.', 'openvault-backup' ) );
			}

			return array_merge(
				$result,
				array(
					'restore_id' => $id,
					'plan'       => $plan,
				)
			);
		} finally {
			$guard->release();
		}//end try
	}

	/**
	 * Run the checks of start() without creating anything: what a restore of this backup in this mode would do, and whether it may start.
	 *
	 * Same loader and preflight as start(), but no guard, no job, no status change. Result: the start() shape (restore_id is always 0) plus
	 * "disk" { free, needed, checked } (bytes, null when unknown) for the page.
	 *
	 * @param int                 $backup_id Backup.
	 * @param string              $mode      full, db or files.
	 * @param array<string,mixed> $options   Options: restore_server_config (bool).
	 * @return array<string,mixed>
	 */
	public function preview( $backup_id, $mode, array $options = array() ) {
		if ( ! is_string( $mode ) || ! in_array( $mode, RestorePlan::MODES, true ) ) {
			return self::failure( 'mode', __( 'Choose what to restore: everything, the database only, or the files only.', 'openvault-backup' ) );
		}
		$backup = $this->backups->find( (int) $backup_id );
		if ( null === $backup ) {
			return self::failure( 'backup_missing', __( 'This backup does not exist.', 'openvault-backup' ) );
		}
		if ( ! in_array( $backup['status'], array( 'complete', 'partial' ), true ) ) {
			return self::failure( 'backup_not_finished', __( 'Only a finished backup can be restored.', 'openvault-backup' ) );
		}

		$loaded                 = $this->loader->load( $backup, $mode );
		$facts                  = $this->preflight->gather( $loaded, array(), 0 );
		$facts['server_config'] = ! empty( $options['restore_server_config'] );
		$result                 = RestorePreflight::evaluate( $facts );
		$result['restore_id']   = 0;
		$result['plan']         = $loaded['plan'] instanceof RestorePlan ? $loaded['plan']->to_array() : null;
		$result['disk']         = array(
			'free'    => isset( $facts['free_bytes'] ) ? $facts['free_bytes'] : null,
			'needed'  => isset( $facts['needed_bytes'] ) ? (int) $facts['needed_bytes'] : 0,
			'checked' => ! empty( $facts['disk_check'] ),
		);
		return $result;
	}

	/**
	 * The user confirmed a verified restore: apply it.
	 *
	 * Refused (nothing changes) when the restore does not exist, is not waiting for confirmation, or a backup is running right now.
	 *
	 * @param int $restore_id Restore id.
	 * @return array{ok:bool,code:string,message:string}
	 */
	public function confirm( $restore_id ) {
		$restore_id = (int) $restore_id;
		$row        = $this->restores->find( $restore_id );
		if ( null === $row ) {
			return self::answer( false, 'restore_missing', __( 'This restore does not exist.', 'openvault-backup' ) );
		}
		$state = $this->restores->load_state( $restore_id );
		if ( 'verified' !== $row['status'] || null === $state || JobState::STEP_SNAPSHOT !== $state->get_step() ) {
			return self::answer( false, 'not_verified', __( 'This restore is not waiting for your confirmation.', 'openvault-backup' ) );
		}
		if ( array() !== $this->backups->find_incomplete( 1 ) ) {
			return self::answer( false, 'backup_running', __( 'A backup is running. Wait for it to finish or cancel it, then confirm the restore.', 'openvault-backup' ) );
		}

		if ( ! $this->restores->save_state( $restore_id, $state->with_meta( RestoreMeta::with_confirmed( $state->get_meta() ) ) ) ) {
			return self::answer( false, 'save_failed', __( 'The confirmation could not be saved.', 'openvault-backup' ) );
		}
		$this->restores->update_status( $restore_id, 'running' );
		if ( ! $this->runner->enqueue( $restore_id ) ) {
			return self::answer( false, 'enqueue_failed', __( 'The restore could not be queued because Action Scheduler is not available.', 'openvault-backup' ) );
		}
		return self::answer( true, 'confirmed', __( 'The restore was confirmed and is starting.', 'openvault-backup' ) );
	}

	/**
	 * Cancel a restore (removes its download folder and, when it is still running, its safety snapshot job). Refused once the
	 * database import has started; see cancel_refusal().
	 *
	 * @param int $restore_id Restore id.
	 * @return bool False when it does not exist, is already finished or cannot be cancelled any more.
	 */
	public function cancel( $restore_id ) {
		$restore_id = (int) $restore_id;
		$row        = $this->restores->find( $restore_id );
		if ( null === $row || in_array( $row['status'], RestoresRepository::FINISHED, true ) || '' !== $this->runner->cancel_refusal( $restore_id ) ) {
			return false;
		}

		$state = $this->restores->load_state( $restore_id );
		if ( null !== $state && RestoreMeta::snapshot_id( $state->get_meta() ) > 0 ) {
			// A snapshot that is still being taken is stopped; a finished one stays (locked): it is a valid backup of the site.
			( new SafetySnapshot( $this->backups ) )->cancel( RestoreMeta::snapshot_id( $state->get_meta() ) );
		}
		$cancelled = $this->runner->cancel( $restore_id );
		if ( $cancelled ) {
			// A job that was cancelled before its first change may have switched maintenance mode on in its setup: never leave it on.
			$flag = ( new MaintenanceMode() )->status();
			if ( null !== $flag && $flag['restore_id'] === $restore_id ) {
				( new MaintenanceMode() )->disable();
			}
		}
		return $cancelled;
	}

	/**
	 * Why a restore cannot be cancelled right now ('' when it can).
	 *
	 * @param int $restore_id Restore id.
	 * @return string
	 */
	public function cancel_refusal( $restore_id ) {
		return $this->runner->cancel_refusal( (int) $restore_id );
	}

	/**
	 * Whether a restore can be undone, and what an undo would do. Pure rules (see undo_eligibility()) over the real state.
	 *
	 * @param int $restore_id Restore id.
	 * @return array{ok:bool,code:string,message:string,undo_db:bool,undo_files:bool,snapshot_id:int,journal:bool}
	 */
	public function can_undo( $restore_id ) {
		$restore_id = (int) $restore_id;
		$row        = $this->restores->find( $restore_id );
		$state      = null === $row ? null : $this->restores->load_state( $restore_id );
		$meta       = null === $state ? array() : $state->get_meta();

		$snapshot_id = null === $row ? 0 : RestoreMeta::snapshot_id( $meta );
		$snapshot    = $snapshot_id > 0 ? ( new SafetySnapshot( $this->backups ) )->state( $snapshot_id ) : SafetySnapshot::STATE_MISSING;
		$journal     = false;
		$actions     = false;
		if ( null !== $row ) {
			try {
				$store   = UndoStore::for_restore( $restore_id );
				$journal = $store->exists();
				$actions = $journal && $store->has_actions();
			} catch ( \Exception $e ) {
				$journal = false;
			}
		}

		$busy = '';
		if ( array() !== $this->restores->find_active( 1 ) ) {
			$busy = 'restore';
		} elseif ( array() !== $this->backups->find_incomplete( 1 ) ) {
			$busy = 'backup';
		}

		$answer                = self::undo_eligibility(
			$row,
			$meta,
			array(
				'snapshot'        => $snapshot,
				'journal'         => $journal,
				'journal_actions' => $actions,
				'busy'            => $busy,
			)
		);
		$answer['snapshot_id'] = $snapshot_id;
		$answer['journal']     = $journal;
		return $answer;
	}

	/**
	 * The undo rules. Pure.
	 *
	 * A restore can be undone when it is complete, or failed after it started changing the site (the database import began, or the undo journal
	 * records an action), it is not an undo job itself, it was not undone or dismissed before, and what an undo needs is still there: a COMPLETE
	 * safety snapshot when the database was restored, the journal when files were restored. Nothing else may be running.
	 *
	 * @param array<string,mixed>|null $row   Restore row (null when it does not exist).
	 * @param array<mixed>             $meta  Job meta.
	 * @param array<string,mixed>      $facts snapshot (a SafetySnapshot::STATE_ constant), journal (bool), journal_actions (bool), busy ('', 'backup', 'restore').
	 * @return array{ok:bool,code:string,message:string,undo_db:bool,undo_files:bool}
	 */
	public static function undo_eligibility( $row, array $meta, array $facts ) {
		$no = static function ( $code, $message ) {
			return array(
				'ok'         => false,
				'code'       => $code,
				'message'    => $message,
				'undo_db'    => false,
				'undo_files' => false,
			);
		};

		if ( ! is_array( $row ) ) {
			return $no( 'restore_missing', __( 'This restore does not exist.', 'openvault-backup' ) );
		}
		if ( RestoreMeta::MODE_UNDO === $row['mode'] ) {
			return $no( 'not_undoable', __( 'An undo cannot be undone. Restore the backup again instead.', 'openvault-backup' ) );
		}
		if ( RestoreMeta::undone_by( $meta ) > 0 ) {
			return $no( 'already_undone', __( 'This restore was already undone.', 'openvault-backup' ) );
		}
		if ( RestoreMeta::undo_dismissed( $meta ) ) {
			return $no( 'dismissed', __( 'The undo data of this restore was deleted.', 'openvault-backup' ) );
		}

		$journal = ! empty( $facts['journal'] );
		$actions = ! empty( $facts['journal_actions'] );
		$db_done = RestoreMeta::does_db( $meta ) && ( 'complete' === $row['status'] || RestoreMeta::db_started( $meta ) );
		if ( 'complete' === $row['status'] ) {
			$files_done = RestoreMeta::does_files( $meta ) && $journal;
			if ( RestoreMeta::does_files( $meta ) && ! $journal ) {
				return $no( 'journal_gone', __( 'The undo data of this restore (the saved copies of the replaced files) is gone, so it cannot be undone.', 'openvault-backup' ) );
			}
		} elseif ( 'failed' === $row['status'] ) {
			$files_done = RestoreMeta::does_files( $meta ) && $actions;
		} else {
			return $no( 'not_finished', __( 'Only a finished restore can be undone.', 'openvault-backup' ) );
		}

		if ( ! $db_done && ! $files_done ) {
			return $no( 'nothing_to_undo', __( 'This restore did not change anything that can be undone.', 'openvault-backup' ) );
		}
		if ( $db_done && SafetySnapshot::STATE_COMPLETE !== ( isset( $facts['snapshot'] ) ? $facts['snapshot'] : '' ) ) {
			return $no( 'snapshot_gone', __( 'The safety snapshot of this restore is gone, so the database cannot be put back.', 'openvault-backup' ) );
		}
		if ( ! empty( $facts['busy'] ) ) {
			return $no(
				'backup' === $facts['busy'] ? 'backup_running' : 'restore_running',
				'backup' === $facts['busy'] ? __( 'A backup is running. Wait for it to finish, then undo the restore.', 'openvault-backup' ) : __( 'Another restore is in progress. Finish or cancel it first.', 'openvault-backup' )
			);
		}

		return array(
			'ok'         => true,
			'code'       => 'ok',
			'message'    => '',
			'undo_db'    => $db_done,
			'undo_files' => $files_done,
		);
	}

	/**
	 * Undo a restore: creates a NEW job of mode "undo" that restores the database safety snapshot (when the database was restored) and replays the
	 * file journal backwards (when files were restored), in maintenance mode, then finishes like any restore. It starts at once (it needs no
	 * confirmation: the user asked for it) and returns the same shape as start(); poll the new restore id.
	 *
	 * Taking a new snapshot first is NOT done: an undo is not itself undoable (can_undo() says so). The snapshot it restores stays, and so does the
	 * journal, until dismiss_undo(). Undoing the database restores the snapshot of BEFORE the restore: everything saved to the database since then
	 * (new posts, orders ...) is lost, which the user must be told.
	 *
	 * @param int                 $restore_id Restore to undo.
	 * @param array<string,mixed> $options    Options: user_id, status_token_hash (see start()).
	 * @return array{ok:bool,restore_id:int,errors:array<int,array{code:string,message:string}>,warnings:array<int,array{code:string,message:string}>,requires_ack:array<int,array{code:string,message:string}>,plan:array<string,mixed>|null,undo_db?:bool,undo_files?:bool}
	 */
	public function undo( $restore_id, array $options = array() ) {
		$restore_id = (int) $restore_id;
		$guard      = new JobLock( 'restore_start' );
		if ( ! $guard->acquire( self::START_GUARD_TTL ) ) {
			return self::failure( 'restore_running', __( 'A restore is already starting. Wait a moment and try again.', 'openvault-backup' ) );
		}

		try {
			$can = $this->can_undo( $restore_id );
			if ( ! $can['ok'] ) {
				return self::failure( $can['code'], $can['message'] );
			}

			$row   = $this->restores->find( $restore_id );
			$state = $this->restores->load_state( $restore_id );
			if ( null === $row || null === $state ) {
				return self::failure( 'restore_missing', __( 'This restore does not exist.', 'openvault-backup' ) );
			}
			$meta = $state->get_meta();

			// With a database part the job restores the snapshot backup (an ordinary local backup); without one it still needs a valid backup row.
			$backup_id = $can['undo_db'] ? $can['snapshot_id'] : (int) $row['backup_id'];
			if ( ! $can['undo_db'] && null === $this->backups->find( $backup_id ) ) {
				// A files-only undo needs no backup, but every job record points at one: the original may have been deleted since.
				$newest    = $this->backups->paginate( array( 'per_page' => 1 ) );
				$backup_id = isset( $newest['items'][0]['id'] ) ? (int) $newest['items'][0]['id'] : 0;
				if ( $backup_id < 1 ) {
					return self::failure( 'backup_missing', __( 'The backup history is empty, so the undo cannot be recorded.', 'openvault-backup' ) );
				}
			}
			$warnings = array();
			if ( $can['undo_db'] ) {
				$loaded = $this->loader->load( (array) $this->backups->find( $backup_id ), 'db' );
				$check  = $this->preflight->run( $loaded, array( RestorePreflight::ACK_URL_MISMATCH ), 0 );
				if ( ! $check['ok'] ) {
					return array_merge(
						$check,
						array(
							'restore_id' => 0,
							'plan'       => $loaded['plan'] instanceof RestorePlan ? $loaded['plan']->to_array() : null,
						)
					);
				}
				$warnings = $check['warnings'];
			}

			$user_id = isset( $options['user_id'] ) ? (int) $options['user_id'] : get_current_user_id();
			$id      = $this->restores->create(
				array(
					'backup_id'  => $backup_id,
					'mode'       => RestoreMeta::MODE_UNDO,
					'created_by' => max( 0, $user_id ),
				)
			);
			if ( $id < 1 ) {
				return self::failure( 'create_failed', __( 'The undo could not be created.', 'openvault-backup' ) );
			}
			$undo_meta = RestoreMeta::make_undo( $id, $restore_id, $can['undo_db'], $can['undo_files'], RestoreMeta::server_config( $meta ) );
			$undo_meta = RestoreMeta::with_status_token( $undo_meta, isset( $options['status_token_hash'] ) ? (string) $options['status_token_hash'] : '' );
			if ( ! $this->runner->start( $id, $undo_meta ) ) {
				return self::failure( 'start_failed', __( 'The undo could not be started. See the logs for details.', 'openvault-backup' ) );
			}

			return array(
				'ok'           => true,
				'restore_id'   => $id,
				'errors'       => array(),
				'warnings'     => $warnings,
				'requires_ack' => array(),
				'plan'         => null,
				'undo_db'      => $can['undo_db'],
				'undo_files'   => $can['undo_files'],
			);
		} finally {
			$guard->release();
		}//end try
	}

	/**
	 * Delete the undo data of a restore (the journal and the saved copies of the replaced files). The restore can no longer be undone afterwards.
	 * The safety snapshot backup is NOT deleted or unlocked (a locked backup is removed from History by the user; retention comes later).
	 *
	 * @param int $restore_id Restore id.
	 * @return array{ok:bool,code:string,message:string}
	 */
	public function dismiss_undo( $restore_id ) {
		$restore_id = (int) $restore_id;
		$row        = $this->restores->find( $restore_id );
		if ( null === $row || ! in_array( $row['status'], array( 'complete', 'failed' ), true ) || RestoreMeta::MODE_UNDO === $row['mode'] ) {
			return self::answer( false, 'not_dismissable', __( 'This restore has no undo data to delete.', 'openvault-backup' ) );
		}
		if ( array() !== $this->restores->find_active( 1 ) ) {
			return self::answer( false, 'restore_running', __( 'A restore is in progress. Wait for it to finish.', 'openvault-backup' ) );
		}

		try {
			$removed = UndoStore::for_restore( $restore_id )->remove();
		} catch ( \Exception $e ) {
			$removed = false;
		}
		if ( ! $removed ) {
			return self::answer( false, 'remove_failed', __( 'The undo data could not be deleted. Check the permissions of the local backup folder.', 'openvault-backup' ) );
		}

		$state = $this->restores->load_state( $restore_id );
		if ( null !== $state ) {
			$meta                   = $state->get_meta();
			$meta['undo_dismissed'] = true;
			$this->restores->save_state( $restore_id, $state->with_meta( $meta ) );
		}
		return self::answer( true, 'dismissed', __( 'The undo data was deleted.', 'openvault-backup' ) );
	}

	/**
	 * Result of confirm().
	 *
	 * @param bool   $ok      Success.
	 * @param string $code    Machine code.
	 * @param string $message Message.
	 * @return array{ok:bool,code:string,message:string}
	 */
	private static function answer( $ok, $code, $message ) {
		return array(
			'ok'      => $ok,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * A refusal with one error.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Message.
	 * @return array{ok:bool,restore_id:int,errors:array<int,array{code:string,message:string}>,warnings:array<int,array{code:string,message:string}>,requires_ack:array<int,array{code:string,message:string}>,plan:null}
	 */
	private static function failure( $code, $message ) {
		return array(
			'ok'           => false,
			'restore_id'   => 0,
			'errors'       => array(
				array(
					'code'    => $code,
					'message' => $message,
				),
			),
			'warnings'     => array(),
			'requires_ack' => array(),
			'plan'         => null,
		);
	}
}
