<?php
/**
 * History page.
 *
 * Phase: MVP
 *
 * Purpose: WP_List_Table of backups with download/restore/delete/lock/log actions.
 * Built so far: list, download (authenticated), lock/unlock, delete, cancel and a link to the log. Restore arrives in
 * Phase 2 of the roadmap.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

use OpenVault\Backup\Admin\BackupsListTable;
use OpenVault\Backup\Admin\Menu;

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

	/**
	 * Page body.
	 *
	 * @return void
	 */
	protected function content() {
		// WP_List_Table is an admin-only class: load it before our subclass is autoloaded.
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new BackupsListTable();
		$table->prepare_items();

		echo '<noscript><div class="notice notice-warning inline"><p>' . esc_html__( 'JavaScript is needed for Lock, Unlock, Cancel and Delete. Downloads work without it.', 'openvault-backup' ) . '</p></div></noscript>';
		echo '<div id="ovb-history-notice" class="notice inline" role="status" aria-live="polite" hidden><p></p></div>';

		if ( ! $table->has_items() ) {
			echo '<div class="ovb-empty"><p><strong>' . esc_html__( 'No backups yet.', 'openvault-backup' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Backups you create appear here, with their downloads and actions.', 'openvault-backup' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( Menu::url( 'openvault-backup-now' ) ) . '">' . esc_html__( 'Back up now', 'openvault-backup' ) . '</a></p></div>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Backups are stored in the protected backup folder on this server. A locked backup cannot be deleted until you unlock it.', 'openvault-backup' ) . '</p>';
		echo '<div id="ovb-history">';
		$table->display();
		echo '</div>';
	}
}
