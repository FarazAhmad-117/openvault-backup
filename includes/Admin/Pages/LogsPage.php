<?php
/**
 * Logs page.
 *
 * Phase: MVP
 *
 * Purpose: View/download/prune logs.
 * Currently a "coming soon" placeholder (Milestone 1).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Logs.
 */
class LogsPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Logs', 'openvault-backup' );
	}
}
