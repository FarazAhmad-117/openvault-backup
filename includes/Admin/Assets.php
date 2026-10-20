<?php
/**
 * Admin scripts and styles.
 *
 * Phase: MVP
 *
 * Purpose: Enqueue assets/admin/css/admin.css on the plugin's own screens and the page scripts only on the screens
 * that use them (Backup Now, History). Nothing is loaded anywhere else in the admin, no build step, no external
 * requests. The REST nonce and all strings reach the scripts through wp_localize_script().
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin;

use OpenVault\Backup\Admin\Pages\BackupNowPage;
use OpenVault\Backup\Core\Capabilities;
use OpenVault\Backup\Rest\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Asset loader.
 */
class Assets {

	/**
	 * Hook into the admin.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Page slug of a screen hook suffix, or '' when it is not one of our screens.
	 *
	 * @param string $hook Hook suffix passed to admin_enqueue_scripts.
	 * @return string
	 */
	public static function slug_for_hook( $hook ) {
		if ( 'toplevel_page_openvault' === $hook ) {
			return 'openvault';
		}
		foreach ( array_keys( Menu::pages() ) as $slug ) {
			$suffix = '_page_' . $slug;
			if ( 'openvault' !== $slug && substr( (string) $hook, -strlen( $suffix ) ) === $suffix ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Enqueue on our screens.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		$slug = self::slug_for_hook( (string) $hook );
		if ( '' === $slug || ! Capabilities::current_user_can_manage_backups() ) {
			return;
		}

		wp_enqueue_style( 'ovb-admin', OVB_URL . 'assets/admin/css/admin.css', array(), OVB_VERSION );

		if ( 'openvault-backup-now' === $slug ) {
			wp_enqueue_script( 'ovb-backup-now', OVB_URL . 'assets/admin/js/backup-now.js', array(), OVB_VERSION, true );
			wp_localize_script( 'ovb-backup-now', 'ovbBackupNow', $this->config( $this->backup_now_strings() ) );
		} elseif ( 'openvault-backups' === $slug ) {
			wp_enqueue_script( 'ovb-history', OVB_URL . 'assets/admin/js/history.js', array(), OVB_VERSION, true );
			wp_localize_script( 'ovb-history', 'ovbHistory', $this->config( $this->history_strings() ) );
		}
	}

	/**
	 * Settings shared by the scripts.
	 *
	 * @param array<string,string> $strings Translated strings.
	 * @return array<string,mixed>
	 */
	private function config( array $strings ) {
		return array(
			'restUrl'    => esc_url_raw( rest_url( RestController::NAMESPACE_V1 . '/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'historyUrl' => esc_url_raw( Menu::url( 'openvault-backups' ) ),
			'runningId'  => BackupNowPage::running_id(),
			'i18n'       => $strings,
		);
	}

	/**
	 * Strings of the Backup Now script.
	 *
	 * @return array<string,string>
	 */
	private function backup_now_strings() {
		return array(
			'noFetch'      => __( 'This browser is too old to run the backup page. Please update your browser.', 'openvault-backup' ),
			'starting'     => __( 'Starting the backup...', 'openvault-backup' ),
			'requestError' => __( 'The request failed. Check your connection and try again.', 'openvault-backup' ),
			'lostContact'  => __( 'Lost contact with the server. The backup keeps running in the background; this page will keep trying.', 'openvault-backup' ),
			'nothing'      => __( 'Select at least one item to back up.', 'openvault-backup' ),
			'cancelling'   => __( 'Cancelling...', 'openvault-backup' ),
			'confirmStop'  => __( 'Cancel this backup? The files created so far are removed.', 'openvault-backup' ),
			'done'         => __( 'Backup complete', 'openvault-backup' ),
			'doneWarn'     => __( 'Backup complete with warnings', 'openvault-backup' ),
			'failed'       => __( 'Backup failed', 'openvault-backup' ),
			/* translators: 1: total size, 2: number of archive parts. */
			'summary'      => __( 'Size: %1$s. Archive parts: %2$s. Download them below or from the history page.', 'openvault-backup' ),
			/* translators: %d: number of warnings. */
			'warnings'     => __( '%d warnings were logged (for example files that could not be read).', 'openvault-backup' ),
			/* translators: %s: estimated time left, for example "about 2 min". */
			'eta'          => __( '%s left', 'openvault-backup' ),
			'about'        => __( 'about', 'openvault-backup' ),
			'min'          => __( 'min', 'openvault-backup' ),
			'sec'          => __( 'sec', 'openvault-backup' ),
		);
	}

	/**
	 * Strings of the History script.
	 *
	 * @return array<string,string>
	 */
	private function history_strings() {
		return array(
			'noFetch'       => __( 'This browser is too old for these actions. Please update your browser.', 'openvault-backup' ),
			'confirmDelete' => __( 'Delete this backup and its files from the server? This cannot be undone.', 'openvault-backup' ),
			'confirmCancel' => __( 'Cancel this backup? The files created so far are removed.', 'openvault-backup' ),
			'working'       => __( 'Working...', 'openvault-backup' ),
			'error'         => __( 'The action failed. Reload the page and try again.', 'openvault-backup' ),
		);
	}
}
