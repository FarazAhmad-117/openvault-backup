<?php
/**
 * Text for the backups list (pure logic, no WordPress needed).
 *
 * Phase: MVP
 *
 * Purpose: Labels, status badges, size and contents summaries used by the History list table.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Presenter helpers.
 */
final class BackupRowPresenter {

	/**
	 * Translated status label.
	 *
	 * @param string $status Backup status.
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( $status ) {
			case 'queued':
				return __( 'Queued', 'openvault-backup' );
			case 'running':
				return __( 'Running', 'openvault-backup' );
			case 'uploading':
				return __( 'Uploading', 'openvault-backup' );
			case 'complete':
				return __( 'Complete', 'openvault-backup' );
			case 'partial':
				return __( 'Complete with warnings', 'openvault-backup' );
			case 'failed':
				return __( 'Failed', 'openvault-backup' );
		}
		return __( 'Unknown', 'openvault-backup' );
	}

	/**
	 * CSS class of the status badge. The badge always carries the label text, so colour is never the only signal.
	 *
	 * @param string $status Backup status.
	 * @return string
	 */
	public static function status_class( $status ) {
		$known = array( 'queued', 'running', 'uploading', 'complete', 'partial', 'failed' );
		return 'ovb-status ovb-status--' . ( in_array( $status, $known, true ) ? $status : 'unknown' );
	}

	/**
	 * Translated trigger label.
	 *
	 * @param string $trigger Trigger type.
	 * @return string
	 */
	public static function trigger_label( $trigger ) {
		switch ( $trigger ) {
			case 'manual':
				return __( 'Manual', 'openvault-backup' );
			case 'schedule':
				return __( 'Scheduled', 'openvault-backup' );
			case 'pre-update':
				return __( 'Before update', 'openvault-backup' );
			case 'pre-restore':
				return __( 'Before restore', 'openvault-backup' );
		}
		return __( 'Unknown', 'openvault-backup' );
	}

	/**
	 * Human readable size (binary units, one decimal).
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		$units = array( 'KB', 'MB', 'GB', 'TB' );
		$value = $bytes / 1024;
		$index = 0;
		while ( $value >= 1024 && $index < 3 ) {
			$value /= 1024;
			++$index;
		}
		return number_format( $value, $value >= 100 ? 0 : 1, '.', '' ) . ' ' . $units[ $index ];
	}

	/**
	 * Comma separated names of the selected contents.
	 *
	 * @param array<string,mixed> $contents Contents flags.
	 * @return string
	 */
	public static function contents_summary( array $contents ) {
		$names = array(
			'db'            => __( 'Database', 'openvault-backup' ),
			'plugins'       => __( 'Plugins', 'openvault-backup' ),
			'themes'        => __( 'Themes', 'openvault-backup' ),
			'uploads'       => __( 'Uploads', 'openvault-backup' ),
			'mu_plugins'    => __( 'Must-use plugins', 'openvault-backup' ),
			'other_content' => __( 'Other wp-content', 'openvault-backup' ),
			'core'          => __( 'WordPress core', 'openvault-backup' ),
			'wp_config'     => __( 'wp-config.php', 'openvault-backup' ),
		);

		$selected = array();
		foreach ( $names as $key => $label ) {
			if ( ! empty( $contents[ $key ] ) ) {
				$selected[] = $label;
			}
		}
		return array() === $selected ? '-' : implode( ', ', $selected );
	}

	/**
	 * Whether a backup of this status can be cancelled.
	 *
	 * @param string $status Backup status.
	 * @return bool
	 */
	public static function is_running( $status ) {
		return in_array( $status, array( 'queued', 'running', 'uploading' ), true );
	}

	/**
	 * Whether a backup of this status has files that can be downloaded.
	 *
	 * @param string $status Backup status.
	 * @return bool
	 */
	public static function has_files( $status ) {
		return 'complete' === $status || 'partial' === $status;
	}
}
