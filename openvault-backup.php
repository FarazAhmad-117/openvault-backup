<?php
/**
 * Plugin Name:       OpenVault Backup
 * Plugin URI:        https://github.com/FarazAhmad-117/openvault-backup
 * Description:       Free, open-source backups to your own S3-compatible or local storage, with restore from your backup history and undo. No relay servers, no paid tiers. Pre-alpha: try it on a copy of your site first.
 * Version:           0.3.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            OpenVault contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       openvault-backup
 * Domain Path:       /languages
 *
 * Bundled library: Action Scheduler (GPLv3), see lib/action-scheduler/.
 *
 * @package OpenVault\Backup
 */

defined( 'ABSPATH' ) || exit;

define( 'OVB_VERSION', '0.3.0' );
define( 'OVB_DB_VERSION', '2' );
define( 'OVB_FILE', __FILE__ );
define( 'OVB_PATH', plugin_dir_path( __FILE__ ) );
define( 'OVB_URL', plugin_dir_url( __FILE__ ) );
define( 'OVB_MIN_PHP', '7.4' );
define( 'OVB_MIN_WP', '6.5' );

/**
 * Show an admin notice and stop loading when the environment is too old.
 *
 * @return bool True when requirements are met.
 */
function ovb_requirements_met() {
	global $wp_version;

	if ( version_compare( PHP_VERSION, OVB_MIN_PHP, '>=' ) && version_compare( $wp_version, OVB_MIN_WP, '>=' ) ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: required WordPress version. */
						__( 'OpenVault Backup requires PHP %1$s+ and WordPress %2$s+. The plugin has not been loaded.', 'openvault-backup' ),
						OVB_MIN_PHP,
						OVB_MIN_WP
					)
				)
			);
		}
	);
	return false;
}

if ( ! ovb_requirements_met() ) {
	return;
}

/**
 * PSR-4 autoloader for includes/ (namespace OpenVault\Backup).
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'OpenVault\\Backup\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = OVB_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Bundled Action Scheduler registers itself with its own version negotiation.
if ( file_exists( OVB_PATH . 'lib/action-scheduler/action-scheduler.php' ) ) {
	require_once OVB_PATH . 'lib/action-scheduler/action-scheduler.php';
}

register_activation_hook( __FILE__, array( 'OpenVault\\Backup\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OpenVault\\Backup\\Core\\Deactivator', 'deactivate' ) );

OpenVault\Backup\Core\Plugin::instance()->boot();
