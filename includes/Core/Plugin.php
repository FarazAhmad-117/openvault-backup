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
 * The job runners (backup and restore) and the REST routes are hooked on every request type; the menu, assets and the download endpoint in the admin.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Core;

use OpenVault\Backup\Admin\Assets;
use OpenVault\Backup\Admin\Menu;
use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Restore\MaintenanceMode;
use OpenVault\Backup\Restore\RestoreRunner;
use OpenVault\Backup\Rest\DestinationsRestController;
use OpenVault\Backup\Rest\RestController;
use OpenVault\Backup\Rest\RestoreRestController;
use OpenVault\Backup\Security\DownloadController;

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
	 * Job runner.
	 *
	 * @var JobRunner|null
	 */
	private $runner = null;

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

		// The job action runs inside Action Scheduler requests (cron, async loopback, WP-CLI), which are not admin
		// requests, so the runner is hooked on every request type.
		$this->runner = new JobRunner();
		$this->runner->register_hooks();
		( new RestoreRunner() )->register_hooks();

		// The maintenance page of a database restore (a no-op unless the flag file exists and is valid), on every request type.
		( new MaintenanceMode() )->register_hooks();

		// REST requests are not admin requests, so the routes are hooked everywhere. Every route checks capability + nonce.
		( new RestController() )->register_hooks();
		( new DestinationsRestController() )->register_hooks();
		( new RestoreRestController() )->register_hooks();

		if ( is_admin() ) {
			$menu = new Menu();
			add_action( 'admin_menu', array( $menu, 'register' ) );
			( new Assets() )->register_hooks();
			// admin-post.php is an admin request; the handler checks login, capability and nonce.
			( new DownloadController() )->register_hooks();
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
