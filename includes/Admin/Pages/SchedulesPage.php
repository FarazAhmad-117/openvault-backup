<?php
/**
 * Schedules page.
 *
 * Phase: MVP
 *
 * Purpose: List/create/edit schedules.
 * Currently a "coming soon" placeholder (Milestone 1).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules.
 */
class SchedulesPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Schedules', 'openvault-backup' );
	}
}
