<?php
/**
 * Pure rules of the preflight step.
 *
 * Phase: MVP
 *
 * Purpose: Turns facts about the environment (gathered by PreflightStep with real probes, or handed in
 * by a unit test) into findings. No WordPress, no filesystem access.
 *
 * Each finding is array( 'level' => 'error'|'warning'|'info', 'code' => string, 'message' => string ).
 * Any "error" finding stops the backup before it starts, with an actionable message.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Steps;

use OpenVault\Backup\Support\SystemInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Preflight rules.
 */
final class PreflightChecks {

	const ERROR   = 'error';
	const WARNING = 'warning';
	const INFO    = 'info';

	const DEFAULT_MIN_FREE_MB = 100;

	/**
	 * Minimum free space in bytes from the settings (option ovb_settings, key min_free_space_mb).
	 * Missing or invalid gives 100 MB; 0 turns the check off.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return int
	 */
	public static function min_free_bytes( array $settings ) {
		$mb = self::DEFAULT_MIN_FREE_MB;
		if ( isset( $settings['min_free_space_mb'] ) && is_numeric( $settings['min_free_space_mb'] ) && (float) $settings['min_free_space_mb'] >= 0 ) {
			$mb = (int) $settings['min_free_space_mb'];
		}
		return $mb * 1024 * 1024;
	}

	/**
	 * Evaluate the facts.
	 *
	 * Facts (all optional, missing means "not checked"):
	 *  - work_dir_ok (bool)          the work folder exists and a file can be written in it
	 *  - local_dir_error (string)    why the local backup folder cannot be used ('' = fine)
	 *  - local_dir_writable (bool)   the local backup folder is writable
	 *  - free_bytes (int|null)       free disk space, null when the server will not say
	 *  - min_free_bytes (int)        required free space, 0 = do not check
	 *  - format (string)             format id of the backup
	 *  - format_available (bool)     CapabilityDetector says the format works here
	 *  - format_reason (string)      why not
	 *  - contents (array)            backup contents selection
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<int,array{level:string,code:string,message:string}>
	 */
	public static function evaluate( array $facts ) {
		$findings = array();

		if ( isset( $facts['local_dir_error'] ) && '' !== $facts['local_dir_error'] ) {
			$findings[] = self::finding( self::ERROR, 'local_dir', (string) $facts['local_dir_error'] );
		} elseif ( isset( $facts['local_dir_writable'] ) && ! $facts['local_dir_writable'] ) {
			$findings[] = self::finding( self::ERROR, 'local_dir_not_writable', __( 'The local backup folder is not writable. Check the permissions of the wp-content folder.', 'openvault-backup' ) );
		}

		if ( isset( $facts['work_dir_ok'] ) && ! $facts['work_dir_ok'] ) {
			$findings[] = self::finding( self::ERROR, 'work_dir', __( 'The temporary work folder cannot be created or written. Check the permissions of the wp-content folder.', 'openvault-backup' ) );
		}

		$min_free = isset( $facts['min_free_bytes'] ) ? (int) $facts['min_free_bytes'] : 0;
		if ( array_key_exists( 'free_bytes', $facts ) ) {
			if ( null === $facts['free_bytes'] ) {
				$findings[] = self::finding( self::WARNING, 'disk_unknown', __( 'The free disk space cannot be determined on this server. Continuing without that check.', 'openvault-backup' ) );
			} elseif ( $min_free > 0 && (int) $facts['free_bytes'] < $min_free ) {
				$findings[] = self::finding(
					self::ERROR,
					'disk_space',
					sprintf(
						/* translators: 1: free disk space, 2: required disk space. */
						__( 'Not enough free disk space: %1$s free, %2$s needed.', 'openvault-backup' ),
						SystemInfo::format_bytes( (int) $facts['free_bytes'] ),
						SystemInfo::format_bytes( $min_free )
					)
				);
			}
		}

		if ( isset( $facts['format'] ) && isset( $facts['format_available'] ) && ! $facts['format_available'] ) {
			$reason     = isset( $facts['format_reason'] ) ? (string) $facts['format_reason'] : '';
			$findings[] = self::finding(
				self::ERROR,
				'format_unavailable',
				trim(
					sprintf(
						/* translators: 1: archive format, 2: reason. */
						__( 'The archive format "%1$s" is not available on this server. %2$s', 'openvault-backup' ),
						(string) $facts['format'],
						$reason
					)
				)
			);
		}

		if ( isset( $facts['contents'] ) ) {
			$contents = is_array( $facts['contents'] ) ? $facts['contents'] : array();
			if ( array() === $contents ) {
				$findings[] = self::finding( self::INFO, 'contents_default', __( 'No explicit contents selection was stored; the default selection is used.', 'openvault-backup' ) );
			} elseif ( ! in_array( true, array_map( 'boolval', $contents ), true ) ) {
				$findings[] = self::finding( self::ERROR, 'contents_empty', __( 'Nothing is selected to back up. Select at least one item, for example the database.', 'openvault-backup' ) );
			}
		}

		return $findings;
	}

	/**
	 * Only the errors.
	 *
	 * @param array<int,array{level:string,code:string,message:string}> $findings Findings.
	 * @return array<int,array{level:string,code:string,message:string}>
	 */
	public static function errors( array $findings ) {
		return array_values(
			array_filter(
				$findings,
				static function ( $finding ) {
					return self::ERROR === $finding['level'];
				}
			)
		);
	}

	/**
	 * Build a finding.
	 *
	 * @param string $level   Level.
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @return array{level:string,code:string,message:string}
	 */
	private static function finding( $level, $code, $message ) {
		return array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
		);
	}
}
