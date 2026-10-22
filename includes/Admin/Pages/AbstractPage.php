<?php
/**
 * Base class for admin pages.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Capability check before rendering anything
 *  - Common wrapper with the page title
 *  - "Coming soon" placeholder until a page is built
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

use OpenVault\Backup\Admin\Menu;
use OpenVault\Backup\Admin\RestoreChoices;
use OpenVault\Backup\Core\Capabilities;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Restore\MaintenanceMode;

defined( 'ABSPATH' ) || exit;

/**
 * Shared page shell.
 */
abstract class AbstractPage {

	/**
	 * Translated page title (also used as the submenu label).
	 *
	 * @return string
	 */
	abstract public function title();

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Capabilities::current_user_can_manage_backups() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'openvault-backup' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap ovb-wrap"><h1>' . esc_html( $this->title() ) . '</h1>';
		if ( $this->shows_restore_banner() ) {
			$this->restore_banner();
		}
		$this->content();
		echo '</div>';
	}

	/**
	 * Whether this page shows the "restore in progress" banner (the Restore page shows the status itself).
	 *
	 * @return bool
	 */
	protected function shows_restore_banner() {
		return true;
	}

	/**
	 * Banner while a restore is queued, running, waiting for confirmation or the maintenance flag is on.
	 *
	 * @return void
	 */
	private function restore_banner() {
		$active = array();
		foreach ( ( new RestoresRepository() )->find_active( 1 ) as $row ) {
			$active[] = (int) $row['id'];
		}
		$banner = RestoreChoices::banner( null !== ( new MaintenanceMode() )->status(), $active );
		if ( null === $banner ) {
			return;
		}
		$url = Menu::url( 'openvault-restore', $banner['restore_id'] > 0 ? array( 'restore' => (string) $banner['restore_id'] ) : array() );
		echo '<div class="notice notice-warning inline ovb-restore-banner" role="status"><p><strong>' . esc_html( $banner['message'] ) . '</strong> <a href="' . esc_url( $url ) . '">' . esc_html__( 'Open the restore status', 'openvault-backup' ) . '</a></p></div>';
	}

	/**
	 * Page body. Override once the page is implemented.
	 *
	 * @return void
	 */
	protected function content() {
		echo '<p>' . esc_html__( 'Coming soon.', 'openvault-backup' ) . '</p>';
	}
}
