<?php
/**
 * Collects server capability data for UI and preflight.
 *
 * Phase: MVP
 *
 * Purpose: PHP/WP versions, limits, extensions, disk space, cron health, supported formats.
 *
 * The "formats" rows are a provisional check; Archive\CapabilityDetector will replace them.
 * Nothing secret is collected. Folder names of the local backup directory are not exposed.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Support;

use OpenVault\Backup\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * System information.
 */
class SystemInfo {

	/**
	 * Human-readable byte size, e.g. 1536 => "1.5 KB".
	 *
	 * @param int|float $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (float) $bytes );
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$last  = count( $units ) - 1;
		$i     = 0;
		while ( $bytes >= 1024 && $i < $last ) {
			$bytes /= 1024;
			++$i;
		}
		return ( 0 === $i ? (string) (int) $bytes : number_format( $bytes, 1, '.', '' ) ) . ' ' . $units[ $i ];
	}

	/**
	 * Plain-text report for support requests.
	 *
	 * @param array<string,array<string,string>> $sections Section title => label => value.
	 * @return string
	 */
	public static function as_text( array $sections ) {
		$lines = array();
		foreach ( $sections as $title => $rows ) {
			$lines[] = '### ' . $title . ' ###';
			foreach ( $rows as $label => $value ) {
				$lines[] = $label . ': ' . $value;
			}
			$lines[] = '';
		}
		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	/**
	 * Collect everything.
	 *
	 * @return array<string,array<string,string>> Section title => label => value.
	 */
	public static function collect() {
		return array(
			__( 'OpenVault', 'openvault-backup' )          => self::plugin_section(),
			__( 'WordPress', 'openvault-backup' )          => self::wordpress_section(),
			__( 'Server', 'openvault-backup' )             => self::server_section(),
			__( 'PHP limits', 'openvault-backup' )         => self::limits_section(),
			__( 'Database', 'openvault-backup' )           => self::database_section(),
			__( 'PHP extensions', 'openvault-backup' )     => self::extensions_section(),
			__( 'Storage', 'openvault-backup' )            => self::storage_section(),
			__( 'Cron and scheduler', 'openvault-backup' ) => self::cron_section(),
			__( 'Archive formats (provisional)', 'openvault-backup' ) => self::formats_section(),
		);
	}

	/**
	 * Yes/No.
	 *
	 * @param bool $value Value.
	 * @return string
	 */
	private static function yes_no( $value ) {
		return $value ? __( 'Yes', 'openvault-backup' ) : __( 'No', 'openvault-backup' );
	}

	/**
	 * Plugin rows.
	 *
	 * @return array<string,string>
	 */
	private static function plugin_section() {
		return array(
			__( 'Plugin version', 'openvault-backup' ) => OVB_VERSION,
			__( 'Schema version (installed)', 'openvault-backup' ) => (string) get_option( Installer::OPTION, '0' ),
			__( 'Schema version (expected)', 'openvault-backup' ) => OVB_DB_VERSION,
		);
	}

	/**
	 * WordPress rows.
	 *
	 * @return array<string,string>
	 */
	private static function wordpress_section() {
		return array(
			__( 'Version', 'openvault-backup' )      => get_bloginfo( 'version' ),
			__( 'Site URL', 'openvault-backup' )     => home_url(),
			__( 'Multisite', 'openvault-backup' )    => self::yes_no( is_multisite() ),
			__( 'Language', 'openvault-backup' )     => get_locale(),
			__( 'Timezone', 'openvault-backup' )     => wp_timezone_string(),
			__( 'Memory limit', 'openvault-backup' ) => (string) WP_MEMORY_LIMIT,
			__( 'WP_DEBUG', 'openvault-backup' )     => self::yes_no( defined( 'WP_DEBUG' ) && WP_DEBUG ),
		);
	}

	/**
	 * Server rows.
	 *
	 * @return array<string,string>
	 */
	private static function server_section() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'Unknown', 'openvault-backup' );

		return array(
			__( 'PHP version', 'openvault-backup' )      => PHP_VERSION,
			__( 'PHP SAPI', 'openvault-backup' )         => PHP_SAPI,
			__( 'PHP bits', 'openvault-backup' )         => (string) ( PHP_INT_SIZE * 8 ),
			__( 'Operating system', 'openvault-backup' ) => PHP_OS_FAMILY,
			__( 'Web server', 'openvault-backup' )       => $software,
		);
	}

	/**
	 * PHP limit rows.
	 *
	 * @return array<string,string>
	 */
	private static function limits_section() {
		$out = array();
		foreach ( array( 'max_execution_time', 'memory_limit', 'upload_max_filesize', 'post_max_size', 'max_input_vars' ) as $key ) {
			$value       = ini_get( $key );
			$out[ $key ] = false === $value || '' === $value ? __( 'Unknown', 'openvault-backup' ) : (string) $value;
		}
		return $out;
	}

	/**
	 * Database rows.
	 *
	 * @return array<string,string>
	 */
	private static function database_section() {
		global $wpdb;

		$present = array();
		$missing = array();
		foreach ( Installer::table_names() as $name ) {
			$table = $wpdb->prefix . 'ovb_' . $name;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $found === $table ) {
				$present[] = $name;
			} else {
				$missing[] = $name;
			}
		}

		$tables = sprintf(
			/* translators: 1: tables found, 2: tables expected. */
			__( '%1$d of %2$d present', 'openvault-backup' ),
			count( $present ),
			count( $present ) + count( $missing )
		);
		if ( $missing ) {
			$tables .= ' (' . sprintf(
				/* translators: %s: comma-separated table names. */
				__( 'missing: %s', 'openvault-backup' ),
				implode( ', ', $missing )
			) . ')';
		}

		return array(
			__( 'Server', 'openvault-backup' )           => (string) $wpdb->db_server_info(),
			__( 'Charset', 'openvault-backup' )          => (string) $wpdb->charset,
			__( 'Collation', 'openvault-backup' )        => (string) $wpdb->collate,
			__( 'Table prefix', 'openvault-backup' )     => (string) $wpdb->prefix,
			__( 'OpenVault tables', 'openvault-backup' ) => $tables,
		);
	}

	/**
	 * Extension rows.
	 *
	 * @return array<string,string>
	 */
	private static function extensions_section() {
		$out = array();
		foreach ( array( 'zip', 'zlib', 'bz2', 'curl', 'openssl', 'mbstring', 'json', 'sodium' ) as $ext ) {
			$out[ $ext ] = extension_loaded( $ext ) ? __( 'Loaded', 'openvault-backup' ) : __( 'Missing', 'openvault-backup' );
		}
		return $out;
	}

	/**
	 * Storage rows.
	 *
	 * @return array<string,string>
	 */
	private static function storage_section() {
		$uploads   = wp_upload_dir( null, false );
		$local_dir = (string) get_option( 'ovb_local_dir', '' );
		$local     = '' !== $local_dir ? trailingslashit( WP_CONTENT_DIR ) . $local_dir : '';
		$free      = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fails on restricted hosts.

		if ( '' !== $local && is_dir( $local ) ) {
			$local_state = wp_is_writable( $local ) ? __( 'Exists, writable', 'openvault-backup' ) : __( 'Exists, NOT writable', 'openvault-backup' );
		} else {
			$local_state = __( 'Missing', 'openvault-backup' );
		}

		return array(
			__( 'wp-content writable', 'openvault-backup' ) => self::yes_no( wp_is_writable( WP_CONTENT_DIR ) ),
			__( 'Uploads folder writable', 'openvault-backup' ) => self::yes_no( ! empty( $uploads['basedir'] ) && wp_is_writable( $uploads['basedir'] ) ),
			__( 'Local backup folder', 'openvault-backup' ) => $local_state,
			__( 'Temp folder writable', 'openvault-backup' ) => self::yes_no( wp_is_writable( get_temp_dir() ) ),
			__( 'Free disk space', 'openvault-backup' ) => false === $free ? __( 'Unknown', 'openvault-backup' ) : self::format_bytes( $free ),
		);
	}

	/**
	 * Cron and Action Scheduler rows.
	 *
	 * @return array<string,string>
	 */
	private static function cron_section() {
		$scheduler = class_exists( 'ActionScheduler_Versions' )
			? (string) \ActionScheduler_Versions::instance()->latest_version()
			: __( 'Not loaded', 'openvault-backup' );

		return array(
			__( 'WP-Cron disabled', 'openvault-backup' )  => self::yes_no( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			__( 'Alternate WP-Cron', 'openvault-backup' ) => self::yes_no( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ),
			__( 'Action Scheduler', 'openvault-backup' )  => $scheduler,
		);
	}

	/**
	 * Archive format rows (simple extension checks until CapabilityDetector exists).
	 *
	 * @return array<string,string>
	 */
	private static function formats_section() {
		$yes = __( 'Supported', 'openvault-backup' );
		$no  = __( 'Not supported', 'openvault-backup' );

		return array(
			__( 'ZIP (deflate, store)', 'openvault-backup' ) => class_exists( 'ZipArchive' ) ? $yes : $no,
			__( 'TAR.GZ', 'openvault-backup' )            => extension_loaded( 'zlib' ) ? $yes : $no,
			__( 'TAR.BZ2 (Phase 2)', 'openvault-backup' ) => extension_loaded( 'bz2' ) ? $yes : $no,
		);
	}
}
