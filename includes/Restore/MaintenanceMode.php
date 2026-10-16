<?php
/**
 * Maintenance mode during restores.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Writes/removes ABSPATH/.maintenance. A watchdog removes it after 30 minutes so a failed
 * restore can never lock the site.
 */
class MaintenanceMode {

	const MAX_AGE = 1800;

	/**
	 * Turn on.
	 *
	 * @return bool
	 */
	public function enable() {
		$contents = '<?php $upgrading = ' . time() . '; // OpenVault restore in progress.' . "\n";
		return false !== file_put_contents( ABSPATH . '.maintenance', $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Turn off.
	 *
	 * @return void
	 */
	public function disable() {
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			wp_delete_file( ABSPATH . '.maintenance' );
		}
	}

	/**
	 * Remove a stale .maintenance created by us.
	 *
	 * @return void
	 */
	public function clear_stale() {
		$file = ABSPATH . '.maintenance';
		if ( file_exists( $file ) && false !== strpos( (string) file_get_contents( $file ), 'OpenVault' ) && filemtime( $file ) < time() - self::MAX_AGE ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_delete_file( $file );
		}
	}
}
