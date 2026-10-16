<?php
/**
 * Central bootstrap.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Hook everything on plugins_loaded / init / admin_menu
 *  - Run DB migrations if stored schema version < current
 *
 * Repositories, engine and REST are wired here in later milestones.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Core;

use OpenVault\Backup\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private: use instance().
	 */
	private function __construct() {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$menu = new Menu();
			add_action( 'admin_menu', array( $menu, 'register' ) );
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'openvault-backup', false, dirname( plugin_basename( OVB_FILE ) ) . '/languages' );
	}
}
