<?php
/**
 * Activation logic.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Run Installer, create protected local backup dir (via Support\Paths)
 *  - Set default options
 *
 * PHP/WP minimums are enforced in openvault-backup.php before this class can load.
 * No custom capability is added: Core\Capabilities maps to manage_options.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Core;

use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 */
class Activator {

	/**
	 * Default global settings (option ovb_settings).
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings() {
		return array(
			'log_retention_days'       => 30,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Activate.
	 *
	 * @return void
	 */
	public static function activate() {
		Installer::install();

		add_option( 'ovb_settings', self::default_settings() );
		add_option( 'ovb_installed_at', gmdate( 'Y-m-d H:i:s' ) );

		// Generates the random folder name on first use, creates the folder and writes the guard files.
		try {
			Paths::local_dir();
		} catch ( \RuntimeException $e ) {
			// Not fatal: preflight reports an unusable folder before every backup.
			return;
		}
	}
}
