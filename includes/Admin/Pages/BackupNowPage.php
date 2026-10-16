<?php
/**
 * Backup Now page.
 *
 * Phase: MVP
 *
 * Purpose: One-off backup form + live progress.
 * Currently a "coming soon" placeholder (Milestone 1).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Backup Now.
 */
class BackupNowPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Backup Now', 'openvault-backup' );
	}
}
