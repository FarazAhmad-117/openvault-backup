<?php
/**
 * The JSON shapes of a restore job for the browser (pure logic, no WordPress needed).
 *
 * Phase: MVP
 *
 * Purpose: GET /restores/{id} (build()), the list (summary()) and the unauthenticated status route (public_payload()). Everything is built
 * from whitelists: no absolute server path (they are replaced by "[site]"), no secret, no SQL, no row data, no statement text and no
 * user name ever reaches the browser; error text is scrubbed (DestinationsRepository::scrub_error) before it is shown.
 *
 * Phases ("phase"): preparing (preflight, download, verification), ready (verified, waits for the user), applying (the site is being
 * changed), done, failed, cancelled. An undo job has no waiting phase: it applies at once.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\Progress;
use OpenVault\Backup\Rest\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Restore payloads.
 */
final class RestoreStatusPresenter {

	/**
	 * Keys of the public (token) payload, and nothing else.
	 */
	const PUBLIC_KEYS = array( 'status', 'step_label', 'percent', 'done', 'failed', 'message' );

	/**
	 * Phase of a job. Pure.
	 *
	 * @param string       $status Row status.
	 * @param string       $step   Step.
	 * @param array<mixed> $meta   Meta.
	 * @return string
	 */
	public static function phase( $status, $step, array $meta ) {
		if ( 'complete' === $status ) {
			return 'done';
		}
		if ( 'failed' === $status || 'cancelled' === $status ) {
			return $status;
		}
		if ( 'verified' === $status ) {
			return 'ready';
		}
		$preparing = in_array( $step, array( JobState::STEP_PREFLIGHT, JobState::STEP_FETCH, JobState::STEP_VERIFY ), true );
		if ( RestoreMeta::MODE_UNDO === RestoreMeta::mode( $meta ) ) {
			return $preparing ? 'preparing' : 'applying';
		}
		return RestoreMeta::confirmed( $meta ) && ! $preparing ? 'applying' : 'preparing';
	}

	/**
	 * Words for the reasons a file was skipped (fixed set of codes of ExtractionPolicy; anything else is "other").
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function skip_reason_label( $reason ) {
		switch ( $reason ) {
			case 'protected_plugin':
				return __( 'part of OpenVault Backup itself (never overwritten)', 'openvault-backup' );
			case 'protected_storage':
				return __( 'the plugin\'s backup folder (never overwritten)', 'openvault-backup' );
			case 'protected_config':
				return __( 'wp-config.php (never restored)', 'openvault-backup' );
			case 'protected_server':
				return __( 'server configuration files (not restored)', 'openvault-backup' );
			case 'protected_maintenance':
				return __( 'the maintenance flag of the restore', 'openvault-backup' );
			case 'write_failed':
				return __( 'could not be written (permissions)', 'openvault-backup' );
			case 'link':
			case 'special':
				return __( 'links and special files (never restored)', 'openvault-backup' );
			case 'name_unsafe':
			case 'name_long':
				return __( 'unsafe or too long names', 'openvault-backup' );
			case 'db':
				return __( 'database files inside the archive', 'openvault-backup' );
			case 'dir':
				return __( 'folders', 'openvault-backup' );
		}//end switch
		return __( 'other reasons', 'openvault-backup' );
	}

	/**
	 * The result summary of a finished job (whitelisted numbers and reason counts).
	 *
	 * @param array<mixed> $meta Meta.
	 * @return array<string,mixed>|null Null when the job has no result.
	 */
	public static function result( array $meta ) {
		if ( ! isset( $meta['result'] ) || ! is_array( $meta['result'] ) ) {
			return null;
		}
		$r     = $meta['result'];
		$files = isset( $r['files'] ) && is_array( $r['files'] ) ? $r['files'] : array();
		$undo  = isset( $r['undo_files'] ) && is_array( $r['undo_files'] ) ? $r['undo_files'] : array();

		return array(
			'mode'           => RestoreMeta::mode( array( 'mode' => isset( $r['mode'] ) ? $r['mode'] : '' ) ),
			'tables'         => self::count( $r, 'tables' ),
			'statements'     => self::count( $r, 'statements' ),
			'rows'           => self::count( $r, 'rows' ),
			'skipped_tables' => self::count( $r, 'skipped' ),
			'seconds'        => self::count( $r, 'seconds' ),
			'snapshot_id'    => self::count( $r, 'snapshot_id' ),
			'kept_urls'      => ! empty( $r['kept_urls'] ),
			'journal'        => ! empty( $r['journal'] ),
			'files'          => self::file_counts( $files, array( 'restored', 'created', 'overwritten', 'bytes', 'parts' ) ),
			'undo_files'     => self::file_counts( $undo, array( 'restored', 'removed', 'folders_removed', 'folders_kept', 'errors' ) ),
		);
	}

	/**
	 * The JSON shape of a job (see the class comment).
	 *
	 * Facts: maintenance (bool), cancel_refusal (string), undo (array from RestoreManager::can_undo() plus "journal"), roots (folders to hide).
	 *
	 * @param array<string,mixed> $row   Restore row.
	 * @param JobState|null       $state State.
	 * @param array<string,mixed> $facts Facts.
	 * @param int                 $now   Unix time.
	 * @return array<string,mixed>
	 */
	public static function build( array $row, ?JobState $state, array $facts, $now ) {
		$status   = (string) $row['status'];
		$meta     = null === $state ? array() : $state->get_meta();
		$cursor   = null === $state ? array() : $state->get_cursor();
		$step     = (string) $row['step'];
		$finished = in_array( $status, RestoresRepository::FINISHED, true );
		$undo_job = RestoreMeta::MODE_UNDO === (string) $row['mode'];
		$phase    = self::phase( $status, $step, $meta );
		$roots    = isset( $facts['roots'] ) && is_array( $facts['roots'] ) ? $facts['roots'] : array();

		$percent = RestoreProgress::percent( $status, $step, $cursor, $meta );
		$label   = 'complete' === $status ? Progress::label( JobState::STEP_DONE ) : ( 'failed' === $status || 'cancelled' === $status ? Progress::label( JobState::STEP_FAILED ) : Progress::label( $step ) );

		$started = self::time_value( $row['started_at'], null === $state ? 0 : $state->get_started_at() );
		$ended   = $finished ? self::time_value( $row['finished_at'], 0 ) : (int) $now;
		$elapsed = $started > 0 && $ended >= $started ? $ended - $started : 0;

		$error = null;
		if ( 'failed' === $status || 'cancelled' === $status ) {
			$text  = null !== $state && null !== $state->get_error() && '' !== $state->get_error() ? (string) $state->get_error() : ( isset( $row['error'] ) && '' !== (string) $row['error'] ? (string) $row['error'] : ( 'cancelled' === $status ? __( 'Cancelled by user', 'openvault-backup' ) : __( 'The restore failed.', 'openvault-backup' ) ) );
			$error = RestController::hide_paths( DestinationsRepository::scrub_error( $text ), $roots );
		}

		$refusal     = isset( $facts['cancel_refusal'] ) ? (string) $facts['cancel_refusal'] : '';
		$cancellable = ! $finished;
		$snapshot    = isset( $row['snapshot_backup_id'] ) && $row['snapshot_backup_id'] > 0 ? (int) $row['snapshot_backup_id'] : RestoreMeta::snapshot_id( $meta );

		$out = array(
			'id'                    => (int) $row['id'],
			'backup_id'             => (int) $row['backup_id'],
			'mode'                  => (string) $row['mode'],
			'kind'                  => $undo_job ? 'undo' : 'restore',
			'status'                => $status,
			'phase'                 => $phase,
			'step'                  => $step,
			'step_label'            => $label,
			'percent'               => $percent,
			'message'               => self::message( $status, $phase, $label ),
			'finished'              => $finished,
			'awaiting_confirmation' => 'verified' === $status,
			'started_at'            => RestController::iso_time( $row['started_at'] ),
			'finished_at'           => RestController::iso_time( $row['finished_at'] ),
			'elapsed_seconds'       => $elapsed,
			'maintenance'           => ! empty( $facts['maintenance'] ),
			'can_cancel'            => $cancellable && '' === $refusal,
			'cancel_refusal'        => $cancellable ? $refusal : '',
			'snapshot_id'           => $snapshot,
			'error'                 => $error,
			'warnings'              => array(),
			'result'                => 'complete' === $status ? self::result( $meta ) : null,
			'undo_of'               => RestoreMeta::undo_of( $meta ),
			'undo'                  => null,
		);

		if ( null !== $out['result'] ) {
			$out['warnings'] = self::warnings( $out['result'] );
		}
		if ( ! $undo_job && in_array( $status, array( 'complete', 'failed' ), true ) && isset( $facts['undo'] ) && is_array( $facts['undo'] ) ) {
			$undo        = $facts['undo'];
			$out['undo'] = array(
				'can_undo'   => ! empty( $undo['ok'] ),
				'reason'     => ! empty( $undo['ok'] ) ? '' : (string) $undo['message'],
				'undo_db'    => ! empty( $undo['undo_db'] ),
				'undo_files' => ! empty( $undo['undo_files'] ),
				'journal'    => ! empty( $undo['journal'] ),
				'undone_by'  => RestoreMeta::undone_by( $meta ),
				'dismissed'  => RestoreMeta::undo_dismissed( $meta ),
				'warning'    => __( 'Undoing the database also removes everything written to the database since the restore (new posts, orders, comments...).', 'openvault-backup' ),
			);
		}
		return $out;
	}

	/**
	 * The small payload of the list (no result, no undo).
	 *
	 * @param array<string,mixed> $row Restore row.
	 * @return array<string,mixed>
	 */
	public static function summary( array $row ) {
		return array(
			'id'          => (int) $row['id'],
			'backup_id'   => (int) $row['backup_id'],
			'mode'        => (string) $row['mode'],
			'kind'        => RestoreMeta::MODE_UNDO === (string) $row['mode'] ? 'undo' : 'restore',
			'status'      => (string) $row['status'],
			'started_at'  => RestController::iso_time( $row['started_at'] ),
			'finished_at' => RestController::iso_time( $row['finished_at'] ),
		);
	}

	/**
	 * The payload of the unauthenticated status route: only the whitelisted keys, never the error text.
	 *
	 * @param array<string,mixed> $full Payload of build().
	 * @return array{status:string,step_label:string,percent:int,done:bool,failed:bool,message:string}
	 */
	public static function public_payload( array $full ) {
		$failed = in_array( $full['status'], array( 'failed', 'cancelled' ), true );
		return array(
			'status'     => (string) $full['status'],
			'step_label' => (string) $full['step_label'],
			'percent'    => (int) $full['percent'],
			'done'       => (bool) $full['finished'],
			'failed'     => $failed,
			'message'    => $failed ? __( 'The restore did not finish. Log in and open the Restore page for details.', 'openvault-backup' ) : (string) $full['message'],
		);
	}

	/**
	 * Short status line.
	 *
	 * @param string $status Status.
	 * @param string $phase  Phase.
	 * @param string $label  Step label.
	 * @return string
	 */
	private static function message( $status, $phase, $label ) {
		if ( 'complete' === $status ) {
			return __( 'The restore is complete.', 'openvault-backup' );
		}
		if ( 'failed' === $status ) {
			return __( 'The restore failed.', 'openvault-backup' );
		}
		if ( 'cancelled' === $status ) {
			return __( 'The restore was cancelled.', 'openvault-backup' );
		}
		if ( 'ready' === $phase ) {
			return __( 'The backup is downloaded and verified. Waiting for your confirmation.', 'openvault-backup' );
		}
		return $label;
	}

	/**
	 * Plain warnings from a result.
	 *
	 * @param array<string,mixed> $result Result.
	 * @return array<int,string>
	 */
	private static function warnings( array $result ) {
		$out = array();
		if ( $result['skipped_tables'] > 0 ) {
			$out[] = sprintf(
				/* translators: %d: number of tables. */
				__( 'Database tables not restored: %d (tables of the plugin itself are never replaced).', 'openvault-backup' ),
				$result['skipped_tables']
			);
		}
		return $out;
	}

	/**
	 * Selected counters of a file result, plus the skipped reasons.
	 *
	 * @param array<mixed>      $data Source.
	 * @param array<int,string> $keys Counters to keep.
	 * @return array<string,mixed>
	 */
	private static function file_counts( array $data, array $keys ) {
		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = self::count( $data, $key );
		}
		$skipped = array();
		if ( isset( $data['skipped'] ) && is_array( $data['skipped'] ) ) {
			foreach ( $data['skipped'] as $reason => $count ) {
				if ( is_string( $reason ) && 1 === preg_match( '/^[a-z_]{1,30}\z/', $reason ) && is_numeric( $count ) ) {
					$skipped[ self::skip_reason_label( $reason ) ] = ( isset( $skipped[ self::skip_reason_label( $reason ) ] ) ? $skipped[ self::skip_reason_label( $reason ) ] : 0 ) + max( 0, (int) $count );
				}
			}
		}
		$out['skipped']   = $skipped;
		$out['skipped_n'] = (int) array_sum( $skipped );
		return $out;
	}

	/**
	 * Non-negative integer from an array.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $key  Key.
	 * @return int
	 */
	private static function count( array $data, $key ) {
		return isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? max( 0, (int) $data[ $key ] ) : 0;
	}

	/**
	 * Unix time of a row time string, falling back to $fallback.
	 *
	 * @param mixed $value    UTC "Y-m-d H:i:s" or null.
	 * @param int   $fallback Fallback.
	 * @return int
	 */
	private static function time_value( $value, $fallback ) {
		if ( is_string( $value ) ) {
			$time = strtotime( $value . ' UTC' );
			if ( false !== $time ) {
				return $time;
			}
		}
		return (int) $fallback;
	}
}
