<?php
/**
 * Activation logic.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Run Installer, create protected local backup dir
 *  - Set default options
 *
 * PHP/WP minimums are enforced in openvault-backup.php before this class can load.
 * No custom capability is added: Core\Capabilities maps to manage_options.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Core;

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

		$dir_name = get_option( 'ovb_local_dir' );
		if ( ! $dir_name ) {
			$dir_name = 'ovb-' . strtolower( wp_generate_password( 16, false, false ) );
			add_option( 'ovb_local_dir', $dir_name );
		}
		self::protect_dir( trailingslashit( WP_CONTENT_DIR ) . $dir_name );
	}

	/**
	 * Create the local backup folder with files that stop directory listing and web access.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private static function protect_dir( $dir ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);

		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny static guard files.
			}
		}
	}
}
