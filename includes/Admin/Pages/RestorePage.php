<?php
/**
 * Restore page.
 *
 * Phase: MVP
 *
 * Purpose: Restore from history (upload and remote scan are planned for later releases).
 *
 * A classic PHP page (decision D3) enhanced by assets/admin/js/restore.js. The page renders the list of restorable backups and the
 * skeleton of the four steps (choose, preview, progress with the "ready to apply" gate, result with undo); the script fills them from the
 * REST API (RestoreRestController). The list and every label are escaped here; the script only ever writes text, never HTML.
 *
 * Which restore is shown: "?restore=<id>" opens that job; without it a job that is queued, running or waiting for confirmation is shown;
 * otherwise the chooser, with the recent restores listed below it. "?backup=<id>" (History's Restore action) pre-selects a backup.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

use OpenVault\Backup\Admin\BackupRowPresenter;
use OpenVault\Backup\Admin\Menu;
use OpenVault\Backup\Admin\RestoreChoices;
use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Destinations\DestinationRegistry;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePlan;

defined( 'ABSPATH' ) || exit;

/**
 * Restore.
 */
class RestorePage extends AbstractPage {

	/**
	 * How many backups the chooser lists.
	 */
	const LIST_LIMIT = 30;

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Restore', 'openvault-backup' );
	}

	/**
	 * The page shows the restore status itself.
	 *
	 * @return bool
	 */
	protected function shows_restore_banner() {
		return false;
	}

	/**
	 * Id of the restore to show: the one in the address, else one that is still active, else 0.
	 *
	 * @return int
	 */
	public static function shown_restore_id() {
		$id = isset( $_GET['restore'] ) ? absint( wp_unslash( $_GET['restore'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter; every action goes through the REST API with a nonce.
		if ( $id > 0 ) {
			return $id;
		}
		$active = ( new RestoresRepository() )->find_active( 1 );
		return array() === $active ? 0 : (int) $active[0]['id'];
	}

	/**
	 * Page body.
	 *
	 * @return void
	 */
	protected function content() {
		$restore_id = self::shown_restore_id();
		$preselect  = isset( $_GET['backup'] ) ? absint( wp_unslash( $_GET['backup'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter.

		echo '<noscript><div class="notice notice-warning inline"><p>' . esc_html__( 'JavaScript is required on this page to preview, start and follow a restore. Enable JavaScript and reload the page.', 'openvault-backup' ) . '</p></div></noscript>';
		echo '<div id="ovb-restore" data-ovb-restore-id="' . esc_attr( (string) $restore_id ) . '" data-ovb-backup-id="' . esc_attr( (string) $preselect ) . '">';
		echo '<div id="ovb-rs-error" class="notice notice-error inline" role="alert" hidden><p></p></div>';
		echo '<p id="ovb-rs-live" class="screen-reader-text" role="status" aria-live="polite"></p>';

		$this->render_choose( $restore_id < 1 );
		$this->render_preview();
		$this->render_run( $restore_id > 0 );

		echo '</div>';
		$this->render_recent();
	}

	/**
	 * Step 1: choose a backup and what to restore.
	 *
	 * @param bool $visible Show it right away.
	 * @return void
	 */
	private function render_choose( $visible ) {
		echo '<section id="ovb-rs-choose" class="ovb-panel" aria-labelledby="ovb-rs-choose-title"' . ( $visible ? '' : ' hidden' ) . '>';
		echo '<h2 id="ovb-rs-choose-title" tabindex="-1">' . esc_html__( '1. Choose a backup', 'openvault-backup' ) . '</h2>';
		echo '<p>' . esc_html__( 'Restore this site from one of its own backups (From history). Restoring an uploaded archive or a folder on the server is planned for a later release.', 'openvault-backup' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Not supported yet: encrypted backups (they are not listed) and multisite networks.', 'openvault-backup' ) . '</p>';
		if ( is_multisite() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Restoring a multisite network is not supported yet.', 'openvault-backup' ) . '</p></div>';
		}

		$backups = ( new BackupsRepository() )->paginate(
			array(
				'per_page' => self::LIST_LIMIT,
				'page'     => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);
		$labels  = array();
		$types   = array();
		foreach ( ( new DestinationsRepository() )->all() as $destination ) {
			$labels[ (int) $destination['id'] ] = (string) $destination['label'];
			$types[ (int) $destination['id'] ]  = $destination;
		}

		$shown = 0;
		echo '<fieldset class="ovb-fieldset" id="ovb-rs-list"><legend>' . esc_html__( 'Backups', 'openvault-backup' ) . '</legend>';
		foreach ( $backups['items'] as $backup ) {
			if ( ! BackupRowPresenter::can_restore( (string) $backup['status'], ! empty( $backup['encrypted'] ) ) ) {
				continue;
			}
			++$shown;
			$this->render_backup( $backup, $labels, $types );
		}
		if ( 0 === $shown ) {
			echo '<p>' . esc_html__( 'There is no backup that can be restored yet.', 'openvault-backup' ) . ' <a href="' . esc_url( Menu::url( 'openvault-backup-now' ) ) . '">' . esc_html__( 'Back up now', 'openvault-backup' ) . '</a></p>';
		}
		echo '</fieldset>';

		echo '<fieldset class="ovb-fieldset" id="ovb-rs-modes"><legend>' . esc_html__( 'What to restore', 'openvault-backup' ) . '</legend>';
		$modes = array(
			'db'    => array( __( 'Database only', 'openvault-backup' ), __( 'Replaces your posts, pages, comments, users and settings with the ones in the backup. Files are not touched. Options: none; the plugin\'s own settings and backup history are always kept.', 'openvault-backup' ) ),
			'files' => array( __( 'Files only', 'openvault-backup' ), __( 'Puts the plugins, themes, uploads and other files of the backup back in place. The database is not touched. A rollback journal keeps the files it overwrites so you can undo.', 'openvault-backup' ) ),
			'full'  => array( __( 'Whole site (database and files)', 'openvault-backup' ), __( 'Does both: first the database, then the files. The most complete restore, and the one that changes the most.', 'openvault-backup' ) ),
		);
		foreach ( $modes as $mode => $text ) {
			echo '<p class="ovb-option"><label for="ovb-m-' . esc_attr( $mode ) . '"><input type="radio" name="ovb_mode" id="ovb-m-' . esc_attr( $mode ) . '" value="' . esc_attr( $mode ) . '" aria-describedby="ovb-mh-' . esc_attr( $mode ) . '" disabled> <strong>' . esc_html( $text[0] ) . '</strong></label>';
			echo '<span class="description ovb-help" id="ovb-mh-' . esc_attr( $mode ) . '">' . esc_html( $text[1] ) . '</span><span class="description ovb-help ovb-mode-reason" id="ovb-mr-' . esc_attr( $mode ) . '"></span></p>';
		}
		echo '</fieldset>';

		echo '<p><button type="button" class="button button-primary" id="ovb-rs-preview-btn" disabled>' . esc_html__( 'Preview what will happen', 'openvault-backup' ) . '</button></p>';
		echo '</section>';
	}

	/**
	 * One backup in the chooser.
	 *
	 * @param array<string,mixed>            $backup Backup row.
	 * @param array<int,string>              $labels Destination labels by id.
	 * @param array<int,array<string,mixed>> $types  Destination rows by id.
	 * @return void
	 */
	private function render_backup( array $backup, array $labels, array $types ) {
		$id        = (int) $backup['id'];
		$info      = RestoreChoices::describe( $backup, $this->copies( $backup, $labels, $types ), is_multisite() );
		$timestamp = strtotime( $backup['created_at'] . ' UTC' );
		$date      = false === $timestamp ? $backup['created_at'] : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		$ago       = false === $timestamp ? '' : sprintf(
			/* translators: %s: human readable time difference, for example "3 hours". */
			__( '%s ago', 'openvault-backup' ),
			human_time_diff( $timestamp, time() )
		);
		$any = false;
		foreach ( $info['modes'] as $mode ) {
			$any = $any || $mode['enabled'];
		}

		$details = array(
			BackupRowPresenter::trigger_label( (string) $backup['trigger_type'] ),
			$backup['total_size'] > 0 ? BackupRowPresenter::format_bytes( (int) $backup['total_size'] ) : '',
			BackupRowPresenter::contents_summary( is_array( $backup['contents'] ) ? $backup['contents'] : array() ),
		);
		$where   = array() === $info['copies'] ? '' : sprintf(
			/* translators: %s: list of places that hold a copy. */
			__( 'Copies: %s', 'openvault-backup' ),
			implode( ', ', $info['copies'] )
		);

		echo '<p class="ovb-option ovb-backup-choice"><label for="ovb-b-' . esc_attr( (string) $id ) . '"><input type="radio" name="ovb_backup" id="ovb-b-' . esc_attr( (string) $id ) . '" value="' . esc_attr( (string) $id ) . '" data-ovb-modes="' . esc_attr( (string) wp_json_encode( $info['modes'] ) ) . '" aria-describedby="ovb-bh-' . esc_attr( (string) $id ) . '"' . disabled( ! $any, true, false ) . '> <strong>' . esc_html( $date ) . '</strong>' . ( '' !== $ago ? ' <span class="description">(' . esc_html( $ago ) . ')</span>' : '' ) . '</label>';
		echo '<span class="description ovb-help" id="ovb-bh-' . esc_attr( (string) $id ) . '">' . esc_html( implode( ' - ', array_filter( $details ) ) );
		if ( '' !== $where ) {
			echo '<br>' . esc_html( $where );
		}
		if ( '' !== $backup['note'] ) {
			echo '<br>' . esc_html( sprintf( /* translators: %s: note of the backup. */ __( 'Note: %s', 'openvault-backup' ), $backup['note'] ) );
		}
		if ( ! $any && '' !== $info['reason'] ) {
			echo '<br><strong>' . esc_html( $info['reason'] ) . '</strong>';
		}
		echo '</span></p>';
	}

	/**
	 * Where complete copies of a backup can be read from.
	 *
	 * @param array<string,mixed>            $backup Backup row.
	 * @param array<int,string>              $labels Destination labels by id.
	 * @param array<int,array<string,mixed>> $types  Destination rows by id.
	 * @return array<int,array{label:string,state:string}>
	 */
	private function copies( array $backup, array $labels, array $types ) {
		$copies   = array();
		$local    = ( new PlanLoader() )->local_folder( $backup );
		$copies[] = array(
			'label' => __( 'This server', 'openvault-backup' ),
			'state' => null !== $local && is_file( $local . '/' . RestorePlan::MANIFEST_FILE ) ? 'ok' : 'missing',
		);

		$registry = new DestinationRegistry();
		$seen     = array();
		foreach ( ( new BackupFilesRepository() )->for_backup( (int) $backup['id'] ) as $file ) {
			$dest = (int) $file['destination_id'];
			if ( $dest < 1 || isset( $seen[ $dest ] ) || RestorePlan::MANIFEST_FILE !== $file['file_name'] || 'done' !== $file['upload_status'] ) {
				continue;
			}
			$seen[ $dest ] = true;
			$row           = isset( $types[ $dest ] ) ? $types[ $dest ] : null;
			$state         = 'ok';
			if ( null === $row || ! $registry->has( $row['type'] ) ) {
				$state = 'missing';
			} elseif ( DestinationsRepository::STATUS_NEEDS_RECONNECT === $row['status'] ) {
				$state = 'needs_reconnect';
			}
			$copies[] = array(
				'label' => isset( $labels[ $dest ] ) ? $labels[ $dest ] : sprintf( /* translators: %d: destination id. */ __( 'Destination %d', 'openvault-backup' ), $dest ),
				'state' => $state,
			);
		}
		return $copies;
	}

	/**
	 * Step 2: preview and the typed confirmation.
	 *
	 * @return void
	 */
	private function render_preview() {
		echo '<section id="ovb-rs-preview" class="ovb-panel" aria-labelledby="ovb-rs-preview-title" hidden>';
		echo '<h2 id="ovb-rs-preview-title" tabindex="-1">' . esc_html__( '2. What will happen', 'openvault-backup' ) . '</h2>';
		echo '<ul id="ovb-pv-facts" class="ovb-needs"></ul>';
		echo '<div id="ovb-pv-errors" class="notice notice-error inline" hidden><p><strong>' . esc_html__( 'This restore cannot start:', 'openvault-backup' ) . '</strong></p><ul class="ovb-needs"></ul></div>';
		echo '<div id="ovb-pv-warnings" class="notice notice-warning inline" hidden><p><strong>' . esc_html__( 'Please note:', 'openvault-backup' ) . '</strong></p><ul class="ovb-needs"></ul></div>';
		echo '<h3>' . esc_html__( 'What is never overwritten', 'openvault-backup' ) . '</h3><ul id="ovb-pv-protected" class="ovb-needs"></ul>';

		echo '<div id="ovb-pv-ack-box" class="ovb-fieldset" hidden>';
		echo '<p><strong>' . esc_html__( 'This backup comes from a different site address', 'openvault-backup' ) . '</strong></p>';
		echo '<p id="ovb-pv-ack-text"></p>';
		echo '<p class="ovb-option"><label for="ovb-pv-ack"><input type="checkbox" id="ovb-pv-ack"> <strong>' . esc_html__( 'I understand, restore it anyway', 'openvault-backup' ) . '</strong></label></p>';
		echo '<p class="ovb-option"><label for="ovb-pv-keep"><input type="checkbox" id="ovb-pv-keep" disabled aria-describedby="ovb-pv-keep-help"> <strong>' . esc_html__( 'Keep this site\'s current address (siteurl and home)', 'openvault-backup' ) . '</strong></label>';
		echo '<span class="description ovb-help" id="ovb-pv-keep-help">' . esc_html__( 'A safety valve so the restored site stays reachable here. It is not a migration: links, images and settings inside the restored content still carry the old address.', 'openvault-backup' ) . '</span></p>';
		echo '</div>';

		echo '<p id="ovb-pv-server-box" class="ovb-option" hidden><label for="ovb-pv-server"><input type="checkbox" id="ovb-pv-server" aria-describedby="ovb-pv-server-help"> <strong>' . esc_html__( 'Also restore server config files (.htaccess etc.)', 'openvault-backup' ) . '</strong></label>';
		echo '<span class="description ovb-help" id="ovb-pv-server-help">' . esc_html__( 'Risk: a wrong .htaccess, web.config, php.ini or .user.ini can lock you out of the whole site, including this page. Leave this off unless you know you need it.', 'openvault-backup' ) . '</span></p>';

		echo '<div class="ovb-danger" role="group" aria-labelledby="ovb-danger-title">';
		echo '<h3 id="ovb-danger-title">' . esc_html__( 'This replaces your live site', 'openvault-backup' ) . '</h3>';
		echo '<p>' . esc_html__( 'This replaces your live database and/or files. A safety snapshot (database) and a rollback journal (files) are created first, you can Undo afterwards. Visitors will see a maintenance page while it runs. You may be logged out when the database is replaced.', 'openvault-backup' ) . '</p>';
		echo '<p><label for="ovb-pv-phrase"><strong>' . esc_html__( 'Type RESTORE to confirm', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-pv-phrase" class="regular-text" autocomplete="off" autocapitalize="off" spellcheck="false" aria-describedby="ovb-pv-phrase-help"> ';
		echo '<span class="description" id="ovb-pv-phrase-help">' . esc_html__( 'Capital letters, exactly as shown.', 'openvault-backup' ) . '</span></p>';
		echo '<p class="description">' . esc_html__( 'Next the backup is downloaded and checked. Nothing on your site changes until you confirm a second time.', 'openvault-backup' ) . '</p>';
		echo '</div>';
		echo '<p><button type="button" class="button button-primary" id="ovb-pv-start" disabled>' . esc_html__( 'Download and verify the backup', 'openvault-backup' ) . '</button> <button type="button" class="button" id="ovb-pv-back">' . esc_html__( 'Back', 'openvault-backup' ) . '</button></p>';
		echo '</section>';
	}

	/**
	 * Steps 3 and 4: progress, the ready-to-apply gate, the result and undo.
	 *
	 * @param bool $visible Show it right away.
	 * @return void
	 */
	private function render_run( $visible ) {
		echo '<section id="ovb-rs-run" class="ovb-panel" aria-labelledby="ovb-run-title"' . ( $visible ? '' : ' hidden' ) . '>';
		echo '<h2 id="ovb-run-title" tabindex="-1">' . esc_html__( '3. Progress', 'openvault-backup' ) . '</h2>';

		echo '<div id="ovb-run-progress">';
		echo '<p class="ovb-bar-row"><progress id="ovb-run-bar" class="ovb-bar" max="100" value="0" aria-labelledby="ovb-run-title"></progress> <span id="ovb-run-percent" class="ovb-percent">0%</span></p>';
		echo '<p class="ovb-meta"><strong id="ovb-run-step"></strong></p>';
		echo '<p class="ovb-meta">' . esc_html__( 'Elapsed:', 'openvault-backup' ) . ' <span id="ovb-run-elapsed">0:00</span></p>';
		echo '<p id="ovb-run-note" class="ovb-meta" hidden></p>';
		echo '<p><button type="button" class="button" id="ovb-run-cancel" hidden>' . esc_html__( 'Cancel restore', 'openvault-backup' ) . '</button> <span id="ovb-run-cancel-note" class="description"></span></p>';
		echo '</div>';

		echo '<div id="ovb-run-ready" class="ovb-danger" hidden role="group" aria-labelledby="ovb-ready-title">';
		echo '<h3 id="ovb-ready-title" tabindex="-1">' . esc_html__( 'Ready to apply', 'openvault-backup' ) . '</h3>';
		echo '<p>' . esc_html__( 'The backup is downloaded and verified. Nothing on your site has changed yet. Applying it now will:', 'openvault-backup' ) . '</p>';
		echo '<ul id="ovb-ready-list" class="ovb-needs"></ul>';
		echo '<p>' . esc_html__( 'Visitors will see a maintenance page while it runs, and you may be logged out. The restore keeps running if you close this page.', 'openvault-backup' ) . '</p>';
		echo '<p><label for="ovb-ready-phrase"><strong>' . esc_html__( 'Type RESTORE again to apply', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-ready-phrase" class="regular-text" autocomplete="off" autocapitalize="off" spellcheck="false"></p>';
		echo '<p><button type="button" class="button button-primary" id="ovb-ready-apply" disabled>' . esc_html__( 'Apply restore now', 'openvault-backup' ) . '</button></p>';
		echo '</div>';

		echo '<div id="ovb-run-result" class="ovb-summary" hidden>';
		echo '<h3 id="ovb-result-title" tabindex="-1"></h3>';
		echo '<div id="ovb-result-body"></div>';
		echo '<p id="ovb-result-links"></p>';
		echo '</div>';

		echo '<div id="ovb-run-undo" class="ovb-summary" hidden>';
		echo '<h3>' . esc_html__( 'Undo', 'openvault-backup' ) . '</h3>';
		echo '<p id="ovb-undo-text"></p>';
		echo '<div id="ovb-undo-action" hidden>';
		echo '<p id="ovb-undo-warning" class="ovb-dest-error" hidden></p>';
		echo '<p><label for="ovb-undo-phrase"><strong>' . esc_html__( 'Type RESTORE to undo the restore', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-undo-phrase" class="regular-text" autocomplete="off" autocapitalize="off" spellcheck="false"> ';
		echo '<button type="button" class="button" id="ovb-undo-btn" disabled>' . esc_html__( 'Undo restore', 'openvault-backup' ) . '</button></p>';
		echo '</div>';
		echo '<p id="ovb-dismiss-box" hidden><button type="button" class="button-link" id="ovb-undo-dismiss">' . esc_html__( 'Dismiss undo data', 'openvault-backup' ) . '</button> <span class="description">' . esc_html__( 'Deletes the saved copies of the replaced files. This frees disk space, but the restore can no longer be undone afterwards.', 'openvault-backup' ) . '</span></p>';
		echo '</div>';

		echo '<p><a class="button" id="ovb-run-again" href="' . esc_url( Menu::url( 'openvault-restore', array( 'restore' => '0' ) ) ) . '" hidden>' . esc_html__( 'Start another restore', 'openvault-backup' ) . '</a></p>';
		echo '</section>';
	}

	/**
	 * Recent restores with a link to each one's status (so a result and its undo can be reopened after a reload).
	 *
	 * @return void
	 */
	private function render_recent() {
		$rows = ( new RestoresRepository() )->paginate( 5, 1 );
		if ( array() === $rows['items'] ) {
			return;
		}
		$status_names = array(
			'queued'    => __( 'Queued', 'openvault-backup' ),
			'running'   => __( 'Running', 'openvault-backup' ),
			'verified'  => __( 'Waiting for confirmation', 'openvault-backup' ),
			'complete'  => __( 'Complete', 'openvault-backup' ),
			'failed'    => __( 'Failed', 'openvault-backup' ),
			'cancelled' => __( 'Cancelled', 'openvault-backup' ),
		);
		echo '<div class="ovb-panel" id="ovb-recent"><h2>' . esc_html__( 'Recent restores', 'openvault-backup' ) . '</h2><ul class="ovb-downloads-list">';
		foreach ( $rows['items'] as $row ) {
			$time = strtotime( $row['created_at'] . ' UTC' );
			$when = false === $time ? $row['created_at'] : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
			$kind = RestoreMeta::MODE_UNDO === $row['mode'] ? __( 'Undo', 'openvault-backup' ) : __( 'Restore', 'openvault-backup' );
			echo '<li><a href="' . esc_url( Menu::url( 'openvault-restore', array( 'restore' => (string) $row['id'] ) ) ) . '">' . esc_html( $kind . ' - ' . $when ) . '</a> <span class="ovb-status ovb-status--' . esc_attr( 'complete' === $row['status'] ? 'complete' : ( in_array( $row['status'], array( 'failed', 'cancelled' ), true ) ? 'failed' : 'running' ) ) . '">' . esc_html( isset( $status_names[ $row['status'] ] ) ? $status_names[ $row['status'] ] : $row['status'] ) . '</span></li>';
		}
		echo '</ul></div>';
	}
}
