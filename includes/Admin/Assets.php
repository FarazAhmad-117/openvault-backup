<?php
/**
 * Admin scripts and styles.
 *
 * Phase: MVP
 *
 * Purpose: Enqueue assets/admin/css/admin.css on the plugin's own screens and the page scripts only on the screens
 * that use them (Backup Now, History, Destinations, Restore). Nothing is loaded anywhere else in the admin, no build step, no external
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
		} elseif ( 'openvault-destinations' === $slug ) {
			wp_enqueue_script( 'ovb-destinations', OVB_URL . 'assets/admin/js/destinations.js', array(), OVB_VERSION, true );
			wp_localize_script( 'ovb-destinations', 'ovbDestinations', $this->config( $this->destinations_strings() ) );
		} elseif ( 'openvault-restore' === $slug ) {
			wp_enqueue_script( 'ovb-restore', OVB_URL . 'assets/admin/js/restore.js', array(), OVB_VERSION, true );
			$config               = $this->config( $this->restore_strings() );
			$config['restoreUrl'] = esc_url_raw( Menu::url( 'openvault-restore' ) );
			$config['loginUrl']   = esc_url_raw( wp_login_url( Menu::url( 'openvault-restore' ) ) );
			wp_localize_script( 'ovb-restore', 'ovbRestore', $config );
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
			'sent_done'    => __( 'Uploaded', 'openvault-backup' ),
			'sent_failed'  => __( 'Failed', 'openvault-backup' ),
			'sent_active'  => __( 'Uploading', 'openvault-backup' ),
			'sent_pending' => __( 'Waiting', 'openvault-backup' ),
			'about'        => __( 'about', 'openvault-backup' ),
			'min'          => __( 'min', 'openvault-backup' ),
			'sec'          => __( 'sec', 'openvault-backup' ),
		);
	}

	/**
	 * Strings of the Destinations script.
	 *
	 * @return array<string,string>
	 */
	private function destinations_strings() {
		return array(
			'noFetch'       => __( 'This browser is too old for this page. Please update your browser.', 'openvault-backup' ),
			'requestError'  => __( 'The request failed. Check your connection and try again.', 'openvault-backup' ),
			'testing'       => __( 'Testing the connection...', 'openvault-backup' ),
			'testOk'        => __( 'Connection works.', 'openvault-backup' ),
			'testFailed'    => __( 'Connection failed.', 'openvault-backup' ),
			/* translators: %s: date and time of the last connection test. */
			'tested'        => __( 'Last tested: %s', 'openvault-backup' ),
			'never'         => __( 'Never tested.', 'openvault-backup' ),
			/* translators: %s: destination name. */
			'confirmRemove' => __( 'Remove the destination "%s"? Its saved keys are deleted from this site. Backups already stored there are not deleted.', 'openvault-backup' ),
			'removed'       => __( 'The destination was removed. Files already stored there were not touched.', 'openvault-backup' ),
			'created'       => __( 'The destination was saved. Use "Test connection" to check that it works.', 'openvault-backup' ),
			'updated'       => __( 'The destination was updated. Use "Test connection" to check that it works.', 'openvault-backup' ),
			'needName'      => __( 'Enter a name for this destination.', 'openvault-backup' ),
		);
	}

	/**
	 * Strings of the Restore script.
	 *
	 * @return array<string,string>
	 */
	private function restore_strings() {
		return array(
			'headProgress'   => __( '3. Progress', 'openvault-backup' ),
			'headResult'     => __( '4. Result', 'openvault-backup' ),
			'noFetch'        => __( 'This browser is too old for the restore page. Please update your browser.', 'openvault-backup' ),
			'requestError'   => __( 'The request failed. Check your connection and try again.', 'openvault-backup' ),
			'previewing'     => __( 'Checking what a restore would do...', 'openvault-backup' ),
			'starting'       => __( 'Starting the restore...', 'openvault-backup' ),
			'applying'       => __( 'Applying the restore...', 'openvault-backup' ),
			/* translators: %s: backup date. */
			'factBackup'     => __( 'Backup: %s', 'openvault-backup' ),
			/* translators: %s: where the backup is read from. */
			'factSource'     => __( 'Read from: %s', 'openvault-backup' ),
			'factDb'         => __( 'The database will be replaced.', 'openvault-backup' ),
			'factNoDb'       => __( 'The database will not be changed.', 'openvault-backup' ),
			'factFiles'      => __( 'Files will be restored (plugins, themes, uploads and others as contained in the backup).', 'openvault-backup' ),
			'factNoFiles'    => __( 'No files will be changed.', 'openvault-backup' ),
			/* translators: 1: free disk space, 2: needed disk space. */
			'factDisk'       => __( 'Disk space: %1$s free, about %2$s needed.', 'openvault-backup' ),
			/* translators: %s: size. */
			'factDownload'   => __( 'To download first: %s.', 'openvault-backup' ),
			/* translators: %s: size. */
			'factSnapshot'   => __( 'A safety snapshot of the current database (about %s) is taken first.', 'openvault-backup' ),
			/* translators: %s: size. */
			'factJournal'    => __( 'The files it replaces are saved in a rollback journal (up to about %s).', 'openvault-backup' ),
			'diskUnknown'    => __( 'unknown', 'openvault-backup' ),
			'ackNeeded'      => __( 'Tick "I understand" to continue.', 'openvault-backup' ),
			'ready1'         => __( 'Replace the live database with the backup (a safety snapshot was taken first when the database is restored).', 'openvault-backup' ),
			'ready2'         => __( 'Replace files with the ones in the backup (a rollback journal keeps the originals).', 'openvault-backup' ),
			'readyNone'      => __( 'Apply the restore.', 'openvault-backup' ),
			'maintenanceOn'  => __( 'Maintenance mode is on: visitors see a maintenance page while the restore runs.', 'openvault-backup' ),
			'loggedOutNote'  => __( 'The restore is running on the server. You may have been logged out because the users table was replaced; progress is still shown here.', 'openvault-backup' ),
			'waiting'        => __( 'Waiting for the site to answer again. The restore keeps running in the background.', 'openvault-backup' ),
			'doneLogin'      => __( 'The restore finished. You may need to log in again.', 'openvault-backup' ),
			'logIn'          => __( 'Log in', 'openvault-backup' ),
			'doneTitle'      => __( 'Restore complete', 'openvault-backup' ),
			'undoDoneTitle'  => __( 'Undo complete', 'openvault-backup' ),
			'failedTitle'    => __( 'Restore failed', 'openvault-backup' ),
			'cancelledTitle' => __( 'Restore cancelled', 'openvault-backup' ),
			'failedHelp'     => __( 'What you can do: use "Undo restore" below if it is offered, or start the restore again. Details are in the Logs page.', 'openvault-backup' ),
			'cancelledHelp'  => __( 'Nothing on your site was changed.', 'openvault-backup' ),
			'confirmCancel'  => __( 'Cancel this restore? Nothing has been changed on your site yet.', 'openvault-backup' ),
			'cancelling'     => __( 'Cancelling...', 'openvault-backup' ),
			/* translators: 1: tables, 2: rows, 3: statements. */
			'resDb'          => __( 'Database: %1$s tables restored (%2$s rows, %3$s statements).', 'openvault-backup' ),
			/* translators: 1: restored, 2: created, 3: overwritten, 4: size. */
			'resFiles'       => __( 'Files: %1$s restored (%2$s new, %3$s overwritten, %4$s).', 'openvault-backup' ),
			/* translators: 1: number, 2: reason. */
			'resSkipped'     => __( 'Skipped: %1$s files - %2$s.', 'openvault-backup' ),
			/* translators: 1: files put back, 2: files removed. */
			'resUndoFiles'   => __( 'Files: %1$s put back, %2$s removed (they did not exist before the restore).', 'openvault-backup' ),
			/* translators: %s: duration. */
			'resTime'        => __( 'Took %s.', 'openvault-backup' ),
			'resKept'        => __( 'This site\'s current address was kept.', 'openvault-backup' ),
			'resSnapshot'    => __( 'The safety snapshot is in History (locked).', 'openvault-backup' ),
			'historyLink'    => __( 'Open History', 'openvault-backup' ),
			'undoNone'       => __( 'Undo is not available for this restore.', 'openvault-backup' ),
			'undoOffer'      => __( 'You can undo this restore: the database snapshot and the saved copies of the replaced files are put back.', 'openvault-backup' ),
			'undoStarting'   => __( 'Starting the undo...', 'openvault-backup' ),
			'confirmDismiss' => __( 'Delete the undo data? The restore can no longer be undone afterwards.', 'openvault-backup' ),
			'dismissed'      => __( 'The undo data was deleted.', 'openvault-backup' ),
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
