<?php
/**
 * Sidebar menu and page routing.
 *
 * Phase: MVP
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin;

use OpenVault\Backup\Admin\Pages\AbstractPage;
use OpenVault\Backup\Admin\Pages\BackupNowPage;
use OpenVault\Backup\Admin\Pages\DashboardPage;
use OpenVault\Backup\Admin\Pages\DestinationsPage;
use OpenVault\Backup\Admin\Pages\GuidesPage;
use OpenVault\Backup\Admin\Pages\HistoryPage;
use OpenVault\Backup\Admin\Pages\LogsPage;
use OpenVault\Backup\Admin\Pages\RestorePage;
use OpenVault\Backup\Admin\Pages\SchedulesPage;
use OpenVault\Backup\Admin\Pages\SettingsPage;
use OpenVault\Backup\Admin\Pages\SystemInfoPage;
use OpenVault\Backup\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "OpenVault" sidebar item and its subpages (classic PHP pages, decision D3).
 * Each slug maps to a Pages\* class that renders its own content.
 */
class Menu {

	const TOP_SLUG = 'openvault';

	/**
	 * Page slug => page class. The first entry is also the top-level page.
	 *
	 * @return array<string,class-string<AbstractPage>>
	 */
	public static function pages() {
		return array(
			'openvault'              => DashboardPage::class,
			'openvault-backup-now'   => BackupNowPage::class,
			'openvault-schedules'    => SchedulesPage::class,
			'openvault-backups'      => HistoryPage::class,
			'openvault-restore'      => RestorePage::class,
			'openvault-destinations' => DestinationsPage::class,
			'openvault-guides'       => GuidesPage::class,
			'openvault-settings'     => SettingsPage::class,
			'openvault-system'       => SystemInfoPage::class,
			'openvault-logs'         => LogsPage::class,
		);
	}

	/**
	 * URL of an admin page.
	 *
	 * Multisite network admin pages arrive in Phase 3.
	 *
	 * @param string               $slug Page slug.
	 * @param array<string,string> $args Extra query args.
	 * @return string
	 */
	public static function url( $slug, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * OAuth redirect URI the user pastes into Google/Dropbox/Microsoft.
	 *
	 * @return string
	 */
	public static function oauth_redirect_uri() {
		return self::url( 'openvault-destinations', array( 'ovb_oauth' => 'callback' ) );
	}

	/**
	 * Register menus.
	 *
	 * @return void
	 */
	public function register() {
		$cap = Capabilities::required();

		add_menu_page(
			__( 'OpenVault Backup', 'openvault-backup' ),
			__( 'OpenVault', 'openvault-backup' ),
			$cap,
			self::TOP_SLUG,
			$this->callback( DashboardPage::class ),
			$this->icon(),
			80
		);

		foreach ( self::pages() as $slug => $class ) {
			$title = ( new $class() )->title();
			add_submenu_page(
				self::TOP_SLUG,
				$title . ' ‹ OpenVault',
				$title,
				$cap,
				$slug,
				$this->callback( $class )
			);
		}
	}

	/**
	 * Build the render callback for a page class.
	 *
	 * @param class-string<AbstractPage> $page_class Page class.
	 * @return callable
	 */
	private function callback( $page_class ) {
		return static function () use ( $page_class ) {
			( new $page_class() )->render();
		};
	}

	/**
	 * Menu icon: a vault door dial, as a data URI so WordPress can tint it.
	 *
	 * @return string
	 */
	private function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M3 2h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-1v1h-2v-1H6v1H4v-1H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zm7 3.2a4.3 4.3 0 1 0 0 8.6 4.3 4.3 0 0 0 0-8.6zm0 1.6a2.7 2.7 0 0 1 .8.1l-.8 1.9-.8-1.9a2.7 2.7 0 0 1 .8-.1zM10 8.6a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
