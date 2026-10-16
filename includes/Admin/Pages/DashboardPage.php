<?php
/**
 * Dashboard page.
 *
 * Phase: MVP
 *
 * Purpose: Status overview, next run, storage per destination, health warnings.
 * Currently a "coming soon" placeholder (Milestone 1).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard.
 */
class DashboardPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Dashboard', 'openvault-backup' );
	}
}
