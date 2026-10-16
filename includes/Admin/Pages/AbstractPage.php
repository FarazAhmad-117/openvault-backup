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

use OpenVault\Backup\Core\Capabilities;

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
		$this->content();
		echo '</div>';
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
