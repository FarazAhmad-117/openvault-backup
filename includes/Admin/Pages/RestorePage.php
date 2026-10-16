<?php
/**
 * Restore page.
 *
 * Phase: MVP
 *
 * Purpose: Restore from history, upload (Phase 2), remote scan (Phase 2).
 * Currently a "coming soon" placeholder (Milestone 1).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Restore.
 */
class RestorePage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Restore', 'openvault-backup' );
	}
}
