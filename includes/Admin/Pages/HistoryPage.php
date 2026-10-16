<?php
/**
 * History page.
 *
 * Phase: MVP
 *
 * Purpose: WP_List_Table of backups with download/restore/delete/lock/log actions.
 * Currently a "coming soon" placeholder (Milestone 1).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * History.
 */
class HistoryPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'History', 'openvault-backup' );
	}
}
