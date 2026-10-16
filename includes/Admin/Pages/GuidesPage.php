<?php
/**
 * Setup Guides page.
 *
 * Phase: MVP
 *
 * Purpose: Renders setup guides from docs/guides content (screenshots in assets/images/guides).
 * Currently a "coming soon" placeholder (Milestone 1).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Setup Guides.
 */
class GuidesPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Setup Guides', 'openvault-backup' );
	}
}
