<?php
/**
 * What the Restore page offers for a backup, and when the "restore in progress" banner shows (pure logic, no WordPress needed).
 *
 * Phase: MVP
 *
 * Purpose: Honest choices. A backup is offered only when it is finished and not encrypted; a mode only when the backup holds what the mode
 * needs (a database part for "database", files for "files", both for "whole site"); everything is disabled with the reason on a multisite
 * network (not supported yet) or when no complete copy can be reached (for example the only copy is on a destination that needs reconnecting).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Restore page rules.
 */
final class RestoreChoices {

	/**
	 * Contents flags that make a backup have files.
	 */
	const FILE_FLAGS = array( 'plugins', 'themes', 'uploads', 'mu_plugins', 'other_content', 'core', 'wp_config' );

	/**
	 * Whether the contents flags include the database.
	 *
	 * @param array<string,mixed> $contents Contents flags.
	 * @return bool
	 */
	public static function has_db( array $contents ) {
		return ! empty( $contents['db'] );
	}

	/**
	 * Whether the contents flags include any files.
	 *
	 * @param array<string,mixed> $contents Contents flags.
	 * @return bool
	 */
	public static function has_files( array $contents ) {
		foreach ( self::FILE_FLAGS as $flag ) {
			if ( ! empty( $contents[ $flag ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What can be restored from a backup.
	 *
	 * Copies: each { label, state } with state "ok" (a complete copy is there), "needs_reconnect" (on a destination that must be reconnected) or
	 * "missing" (not there or incomplete).
	 *
	 * @param array<string,mixed>                         $backup    Backup row (status, encrypted, contents).
	 * @param array<int,array{label:string,state:string}> $copies    Copies in preference order.
	 * @param bool                                        $multisite Whether this is a multisite network.
	 * @return array{restorable:bool,reason:string,copies:array<int,string>,modes:array<string,array{enabled:bool,reason:string}>}
	 */
	public static function describe( array $backup, array $copies, $multisite ) {
		$contents = isset( $backup['contents'] ) && is_array( $backup['contents'] ) ? $backup['contents'] : array();
		$labels   = array();
		$locked   = '';
		foreach ( $copies as $copy ) {
			if ( 'ok' === $copy['state'] ) {
				$labels[] = $copy['label'];
			} elseif ( 'needs_reconnect' === $copy['state'] && '' === $locked ) {
				$locked = $copy['label'];
			}
		}

		$restorable = BackupRowPresenter::can_restore( (string) $backup['status'], ! empty( $backup['encrypted'] ) );
		$reason     = '';
		if ( ! $restorable ) {
			$reason = ! empty( $backup['encrypted'] ) ? __( 'Encrypted backups cannot be restored yet.', 'openvault-backup' ) : __( 'Only a finished backup can be restored.', 'openvault-backup' );
		} elseif ( $multisite ) {
			$reason = __( 'Restoring a multisite network is not supported yet.', 'openvault-backup' );
		} elseif ( array() === $labels ) {
			$reason = '' !== $locked
				? sprintf(
					/* translators: %s: destination name. */
					__( 'The only copy of this backup is on "%s", which must be reconnected first (Destinations page).', 'openvault-backup' ),
					$locked
				)
				: __( 'No complete copy of this backup is available on this server or on a destination.', 'openvault-backup' );
		}

		$db    = self::has_db( $contents );
		$files = self::has_files( $contents );
		$modes = array(
			'db'    => self::mode( $reason, $db, __( 'This backup does not contain the database.', 'openvault-backup' ) ),
			'files' => self::mode( $reason, $files, __( 'This backup contains no files.', 'openvault-backup' ) ),
			'full'  => self::mode( $reason, $db && $files, $db ? __( 'This backup contains only the database, so there is nothing more to restore than "Database only".', 'openvault-backup' ) : __( 'This backup contains only files, so there is nothing more to restore than "Files only".', 'openvault-backup' ) ),
		);

		return array(
			'restorable' => $restorable,
			'reason'     => $reason,
			'copies'     => $labels,
			'modes'      => $modes,
		);
	}

	/**
	 * What to say in the banner on every plugin page, or null for no banner.
	 *
	 * @param bool           $maintenance Whether the maintenance flag is on.
	 * @param array<int,int> $active_ids  Ids of restores that are queued, running or waiting for confirmation (oldest first).
	 * @return array{restore_id:int,message:string}|null
	 */
	public static function banner( $maintenance, array $active_ids ) {
		if ( array() !== $active_ids ) {
			$id = (int) $active_ids[0];
			return array(
				'restore_id' => $id,
				'message'    => $maintenance ? __( 'A restore is applying changes and the site is in maintenance mode.', 'openvault-backup' ) : __( 'A restore is in progress.', 'openvault-backup' ),
			);
		}
		if ( $maintenance ) {
			return array(
				'restore_id' => 0,
				'message'    => __( 'The site is in maintenance mode because of a restore.', 'openvault-backup' ),
			);
		}
		return null;
	}

	/**
	 * One mode entry.
	 *
	 * @param string $blocked    Reason that blocks every mode ('' = none).
	 * @param bool   $available  Whether the backup holds what the mode needs.
	 * @param string $missing    Reason when it does not.
	 * @return array{enabled:bool,reason:string}
	 */
	private static function mode( $blocked, $available, $missing ) {
		if ( '' !== $blocked ) {
			return array(
				'enabled' => false,
				'reason'  => $blocked,
			);
		}
		return array(
			'enabled' => $available,
			'reason'  => $available ? '' : $missing,
		);
	}
}
