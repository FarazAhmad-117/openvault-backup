<?php
/**
 * Destinations page.
 *
 * Phase: MVP
 *
 * Purpose: Add/connect/test destinations; shows OAuth redirect URI with copy button.
 * Currently a "coming soon" placeholder (Milestone 1).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Destinations.
 */
class DestinationsPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Destinations', 'openvault-backup' );
	}
}
