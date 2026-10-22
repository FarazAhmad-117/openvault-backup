<?php
/**
 * The small job meta of a restore (stored in JobState meta, pure).
 *
 * Phase: MVP
 *
 * Purpose: One place that reads and writes the meta keys of a restore job so the runner, the manager and the steps agree.
 * The meta stays far below the 16 KB limit of a JobState: it holds ids, the mode and short lists, never the plan.
 *
 * Keys:
 *  - restore_id (int)     id of the row in ovb_restores
 *  - mode (string)        full, db, files or undo (undo: a job that reverses an earlier restore, see RestoreManager::undo())
 *  - ack (string[])       acknowledged warnings, for example "url_mismatch"
 *  - tried (int[])        source ids that failed and are not used again (0 = local backup folder)
 *  - confirmed (bool)     the user confirmed the apply steps (RestoreManager::confirm())
 *  - keep_urls (bool)     keep this site's siteurl and home across the database import (only with an acknowledged URL mismatch)
 *  - snapshot (array)     { id, at }: the safety snapshot backup (SnapshotStep)
 *  - dump (array)         facts of the validated dump: size, tables, statements, rows (DbRestoreStep)
 *  - dbrestore (array)    what the import did: tables, statements, rows, skipped (DbRestoreStep)
 *  - has_db / has_files (bool)  what the job really changes: a full restore of a backup without database parts has no database step. Missing = derived from the mode
 *  - server_config (bool) restore .htaccess, web.config, php.ini and .user.ini of the WordPress folder too (default false)
 *  - db_started (bool)    the database import began (set when the dump passed validation): the database is in an unknown state if the job fails later
 *  - files (array)        what the file restore did: restored, created, overwritten, skipped by reason, bytes, parts (FileRestoreStep)
 *  - undo_of (int)        undo jobs: the restore that is being reversed
 *  - undo_files (array)   undo jobs: what the file undo did (FileUndoStep)
 *  - undone_by (int)      the undo job that reversed this restore (set when that job finishes)
 *  - undo_dismissed (bool) the user dismissed the undo data of this restore
 *  - result (array)       summary of a finished restore (FinishStep)
 *  - status_token (string) sha256 of the token of the unauthenticated status route (RestoreStatusToken), set when the job is created
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Restore job meta.
 */
final class RestoreMeta {

	const MAX_TRIED = 20;

	/**
	 * Modes a user can start (see RestorePlan::MODES) and the internal mode of an undo job.
	 */
	const MODE_UNDO = 'undo';

	/**
	 * Meta of a new restore.
	 *
	 * @param int      $restore_id Restore id.
	 * @param string   $mode       Mode.
	 * @param string[] $ack        Acknowledged warning codes.
	 * @param bool     $keep_urls  Keep the site's own address (siteurl and home).
	 * @return array<string,mixed>
	 */
	public static function make( $restore_id, $mode, array $ack = array(), $keep_urls = false ) {
		$meta = array(
			'restore_id' => (int) $restore_id,
			'mode'       => (string) $mode,
			'ack'        => array_values( array_unique( array_filter( $ack, 'is_string' ) ) ),
			'tried'      => array(),
		);
		if ( $keep_urls ) {
			$meta['keep_urls'] = true;
		}
		return $meta;
	}

	/**
	 * Restore id.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return int
	 */
	public static function restore_id( array $meta ) {
		return isset( $meta['restore_id'] ) && is_int( $meta['restore_id'] ) ? $meta['restore_id'] : 0;
	}

	/**
	 * Mode (full when missing or unknown).
	 *
	 * @param array<mixed> $meta Meta.
	 * @return string
	 */
	public static function mode( array $meta ) {
		return isset( $meta['mode'] ) && is_string( $meta['mode'] ) && ( in_array( $meta['mode'], RestorePlan::MODES, true ) || self::MODE_UNDO === $meta['mode'] ) ? $meta['mode'] : 'full';
	}

	/**
	 * Mode the restore plan is built for: the same as mode(), except that an undo job restores the database snapshot (a database restore).
	 *
	 * @param array<mixed> $meta Meta.
	 * @return string
	 */
	public static function plan_mode( array $meta ) {
		$mode = self::mode( $meta );
		return self::MODE_UNDO === $mode ? 'db' : $mode;
	}

	/**
	 * Whether the job changes the database (an explicit flag, else derived from the mode).
	 *
	 * @param array<mixed> $meta Meta.
	 * @return bool
	 */
	public static function does_db( array $meta ) {
		if ( isset( $meta['has_db'] ) && is_bool( $meta['has_db'] ) ) {
			return $meta['has_db'];
		}
		return in_array( self::mode( $meta ), array( 'full', 'db' ), true );
	}

	/**
	 * Whether the job changes files (an explicit flag, else derived from the mode).
	 *
	 * @param array<mixed> $meta Meta.
	 * @return bool
	 */
	public static function does_files( array $meta ) {
		if ( isset( $meta['has_files'] ) && is_bool( $meta['has_files'] ) ) {
			return $meta['has_files'];
		}
		return in_array( self::mode( $meta ), array( 'full', 'files' ), true );
	}

	/**
	 * Meta with what the job changes.
	 *
	 * @param array<mixed> $meta  Meta.
	 * @param bool         $db    Changes the database.
	 * @param bool         $files Changes files.
	 * @return array<mixed>
	 */
	public static function with_scope( array $meta, $db, $files ) {
		$meta['has_db']    = (bool) $db;
		$meta['has_files'] = (bool) $files;
		return $meta;
	}

	/**
	 * Whether the server configuration files of the WordPress folder may be restored.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return bool
	 */
	public static function server_config( array $meta ) {
		return isset( $meta['server_config'] ) && true === $meta['server_config'];
	}

	/**
	 * Meta with the server configuration opt-in.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return array<mixed>
	 */
	public static function with_server_config( array $meta ) {
		$meta['server_config'] = true;
		return $meta;
	}

	/**
	 * Whether the database import began.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return bool
	 */
	public static function db_started( array $meta ) {
		return isset( $meta['db_started'] ) && true === $meta['db_started'];
	}

	/**
	 * Id of the restore an undo job reverses (0 for other jobs).
	 *
	 * @param array<mixed> $meta Meta.
	 * @return int
	 */
	public static function undo_of( array $meta ) {
		return isset( $meta['undo_of'] ) && is_int( $meta['undo_of'] ) && $meta['undo_of'] > 0 ? $meta['undo_of'] : 0;
	}

	/**
	 * Meta of an undo job.
	 *
	 * @param int  $restore_id  Id of the undo job.
	 * @param int  $undo_of     Restore that is reversed.
	 * @param bool $db          Restore the database snapshot.
	 * @param bool $files       Replay the file journal.
	 * @param bool $server_config Whether the original restore also restored server configuration files.
	 * @return array<string,mixed>
	 */
	public static function make_undo( $restore_id, $undo_of, $db, $files, $server_config = false ) {
		$meta            = self::make( $restore_id, self::MODE_UNDO, array( 'url_mismatch' ) );
		$meta            = self::with_confirmed( self::with_scope( $meta, $db, $files ) );
		$meta['undo_of'] = (int) $undo_of;
		if ( $server_config ) {
			$meta['server_config'] = true;
		}
		return $meta;
	}

	/**
	 * Id of the undo job that reversed this restore (0 = not undone).
	 *
	 * @param array<mixed> $meta Meta.
	 * @return int
	 */
	public static function undone_by( array $meta ) {
		return isset( $meta['undone_by'] ) && is_int( $meta['undone_by'] ) && $meta['undone_by'] > 0 ? $meta['undone_by'] : 0;
	}

	/**
	 * Whether the undo data was dismissed.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return bool
	 */
	public static function undo_dismissed( array $meta ) {
		return isset( $meta['undo_dismissed'] ) && true === $meta['undo_dismissed'];
	}

	/**
	 * Acknowledged warning codes.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return string[]
	 */
	public static function ack( array $meta ) {
		$out = array();
		if ( isset( $meta['ack'] ) && is_array( $meta['ack'] ) ) {
			foreach ( $meta['ack'] as $code ) {
				if ( is_string( $code ) ) {
					$out[] = $code;
				}
			}
		}
		return $out;
	}

	/**
	 * Source ids that failed.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return int[]
	 */
	public static function tried( array $meta ) {
		$out = array();
		if ( isset( $meta['tried'] ) && is_array( $meta['tried'] ) ) {
			foreach ( $meta['tried'] as $id ) {
				if ( is_int( $id ) && $id >= 0 && ! in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
			}
		}
		return array_slice( $out, 0, self::MAX_TRIED );
	}

	/**
	 * Meta with one more failed source.
	 *
	 * @param array<mixed> $meta      Meta.
	 * @param int          $source_id Source id.
	 * @return array<mixed>
	 */
	public static function with_tried( array $meta, $source_id ) {
		$tried   = self::tried( $meta );
		$tried[] = (int) $source_id;

		$meta['tried'] = array_slice( array_values( array_unique( $tried ) ), 0, self::MAX_TRIED );
		return $meta;
	}

	/**
	 * Whether the user confirmed the apply steps.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return bool
	 */
	public static function confirmed( array $meta ) {
		return isset( $meta['confirmed'] ) && true === $meta['confirmed'];
	}

	/**
	 * Meta with the confirmation flag set.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return array<mixed>
	 */
	public static function with_confirmed( array $meta ) {
		$meta['confirmed'] = true;
		return $meta;
	}

	/**
	 * Whether the site's own address is kept across the import.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return bool
	 */
	public static function keep_site_urls( array $meta ) {
		return isset( $meta['keep_urls'] ) && true === $meta['keep_urls'];
	}

	/**
	 * SHA-256 hash (hex) of the status token of this job ('' = none). The token itself is never stored (RestoreStatusToken).
	 *
	 * @param array<mixed> $meta Meta.
	 * @return string
	 */
	public static function status_token_hash( array $meta ) {
		return isset( $meta['status_token'] ) && is_string( $meta['status_token'] ) && 1 === preg_match( '/^[a-f0-9]{64}\z/', $meta['status_token'] ) ? $meta['status_token'] : '';
	}

	/**
	 * Meta with the hash of the status token.
	 *
	 * @param array<mixed> $meta Meta.
	 * @param string       $hash Hash from RestoreStatusToken::hash() ('' leaves the meta unchanged).
	 * @return array<mixed>
	 */
	public static function with_status_token( array $meta, $hash ) {
		if ( is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}\z/', $hash ) ) {
			$meta['status_token'] = $hash;
		}
		return $meta;
	}

	/**
	 * Id of the safety snapshot backup (0 before it exists).
	 *
	 * @param array<mixed> $meta Meta.
	 * @return int
	 */
	public static function snapshot_id( array $meta ) {
		return isset( $meta['snapshot']['id'] ) && is_int( $meta['snapshot']['id'] ) && $meta['snapshot']['id'] > 0 ? $meta['snapshot']['id'] : 0;
	}

	/**
	 * Meta with the snapshot recorded.
	 *
	 * @param array<mixed> $meta        Meta.
	 * @param int          $backup_id   Snapshot backup id.
	 * @param int          $created_at  Unix time.
	 * @return array<mixed>
	 */
	public static function with_snapshot( array $meta, $backup_id, $created_at ) {
		$meta['snapshot'] = array(
			'id' => (int) $backup_id,
			'at' => (int) $created_at,
		);
		return $meta;
	}
}
