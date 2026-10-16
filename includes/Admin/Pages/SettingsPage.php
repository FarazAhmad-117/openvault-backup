<?php
/**
 * Settings page.
 *
 * Phase: MVP
 *
 * Purpose: Exclusions, temp dir, split size, notifications, encryption, uninstall behaviour.
 * Currently a "coming soon" placeholder (Milestone 1).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class SettingsPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Settings', 'openvault-backup' );
	}
}
