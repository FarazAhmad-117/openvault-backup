<?php
/**
 * Backup Now page.
 *
 * Phase: MVP
 *
 * Purpose: One-off backup form + live progress.
 *
 * A classic PHP page (decision D3) enhanced by assets/admin/js/backup-now.js: the script starts the backup through
 * the REST API (POST /backups), polls GET /backups/{id}/progress and shows the result. If a backup is already
 * running when the page loads, the form is replaced by its progress. Only formats the server supports are
 * offered (Archive\CapabilityDetector); a format that is not available is listed disabled with the reason.
 * Destinations arrive in a later milestone: today a backup is stored in the local backup folder only.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

use OpenVault\Backup\Admin\Menu;
use OpenVault\Backup\Archive\CapabilityDetector;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Rest\RestController;

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

	/**
	 * Id of the backup that is queued or running, or 0.
	 *
	 * @return int
	 */
	public static function running_id() {
		$running = ( new BackupsRepository() )->find_incomplete( 1 );
		return array() === $running ? 0 : (int) $running[0]['id'];
	}

	/**
	 * Explanations of the contents options: key => array( label, help ).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function content_options() {
		return array(
			'db'            => array( __( 'Database', 'openvault-backup' ), __( 'Your posts, pages, settings and users. The plugin\'s own settings and saved credentials are left out.', 'openvault-backup' ) ),
			'plugins'       => array( __( 'Plugins', 'openvault-backup' ), __( 'Installed plugin files, including OpenVault Backup itself.', 'openvault-backup' ) ),
			'themes'        => array( __( 'Themes', 'openvault-backup' ), __( 'Installed theme files.', 'openvault-backup' ) ),
			'uploads'       => array( __( 'Uploads', 'openvault-backup' ), __( 'Your media library and other uploaded files. Usually the biggest part of a backup.', 'openvault-backup' ) ),
			'mu_plugins'    => array( __( 'Must-use plugins', 'openvault-backup' ), __( 'Files in wp-content/mu-plugins, if your site has any.', 'openvault-backup' ) ),
			'other_content' => array( __( 'Other wp-content files', 'openvault-backup' ), __( 'Everything else inside wp-content, for example language files. Backups made by this plugin are never included.', 'openvault-backup' ) ),
			'core'          => array( __( 'WordPress core files', 'openvault-backup' ), __( 'wp-admin, wp-includes and the files in the site root. Usually not needed because WordPress can be downloaded again. Off by default.', 'openvault-backup' ) ),
			'wp_config'     => array( __( 'wp-config.php', 'openvault-backup' ), __( 'Holds your database password, so a backup that contains it must be kept private. Off by default.', 'openvault-backup' ) ),
		);
	}

	/**
	 * Page body.
	 *
	 * @return void
	 */
	protected function content() {
		$running = self::running_id();

		echo '<noscript><div class="notice notice-warning inline"><p>' . esc_html__( 'JavaScript is required on this page to start a backup and to show its progress. Enable JavaScript and reload the page.', 'openvault-backup' ) . '</p></div></noscript>';
		echo '<div id="ovb-backup-now" data-ovb-backup-id="' . esc_attr( (string) $running ) . '">';
		echo '<div id="ovb-error" class="notice notice-error inline" role="alert" hidden><p></p></div>';

		if ( $running < 1 ) {
			$this->render_form();
		}
		$this->render_progress( $running > 0 );

		echo '</div>';
	}

	/**
	 * The options form.
	 *
	 * @return void
	 */
	private function render_form() {
		$detector = new CapabilityDetector();
		$default  = $detector->default_format();

		echo '<p>' . esc_html__( 'Create a backup of this site now. It is stored in the protected backup folder on this server. A backup keeps running in the background if you close this page.', 'openvault-backup' ) . '</p>';

		if ( null === $default ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No archive format is available on this server, so backups cannot be created. Open System Info to see which PHP extensions are missing.', 'openvault-backup' ) . '</p></div>';
		}

		echo '<form id="ovb-backup-form" class="ovb-form" method="post" action="#" novalidate>';

		echo '<fieldset class="ovb-fieldset"><legend>' . esc_html__( 'What to back up', 'openvault-backup' ) . '</legend>';
		foreach ( self::content_options() as $key => $option ) {
			$checked = ! empty( RestController::DEFAULT_CONTENTS[ $key ] );
			echo '<p class="ovb-option"><label for="ovb-c-' . esc_attr( $key ) . '"><input type="checkbox" id="ovb-c-' . esc_attr( $key ) . '" name="contents[' . esc_attr( $key ) . ']" value="1" data-ovb-content="' . esc_attr( $key ) . '" aria-describedby="ovb-h-' . esc_attr( $key ) . '"' . checked( $checked, true, false ) . '> <strong>' . esc_html( $option[0] ) . '</strong></label>';
			echo '<span class="description ovb-help" id="ovb-h-' . esc_attr( $key ) . '">' . esc_html( $option[1] ) . '</span></p>';
		}
		echo '</fieldset>';

		echo '<fieldset class="ovb-fieldset"><legend>' . esc_html__( 'Archive format', 'openvault-backup' ) . '</legend>';
		foreach ( $detector->formats() as $id => $format ) {
			if ( ! empty( $format['phase2'] ) ) {
				continue;
				// Not offered yet (planned for a later release).
			}
			$disabled = empty( $format['available'] );
			echo '<p class="ovb-option"><label for="ovb-f-' . esc_attr( $id ) . '"><input type="radio" id="ovb-f-' . esc_attr( $id ) . '" name="format" value="' . esc_attr( $id ) . '" aria-describedby="ovb-fh-' . esc_attr( $id ) . '"' . checked( $id === $default, true, false ) . disabled( $disabled, true, false ) . '> <strong>' . esc_html( $format['label'] ) . '</strong></label>';
			echo '<span class="description ovb-help" id="ovb-fh-' . esc_attr( $id ) . '">' . esc_html( $disabled ? sprintf( /* translators: %s: reason a format is unavailable. */ __( 'Not available: %s', 'openvault-backup' ), $format['reason_unavailable'] ) : $format['best_for'] ) . '</span></p>';
		}
		echo '</fieldset>';

		echo '<p class="ovb-option"><label for="ovb-note"><strong>' . esc_html__( 'Note (optional)', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-note" class="regular-text" maxlength="' . esc_attr( (string) RestController::NOTE_MAX ) . '" aria-describedby="ovb-note-help"> ';
		echo '<span class="description ovb-help" id="ovb-note-help">' . esc_html__( 'A reminder shown in the backup list, for example "before the theme update".', 'openvault-backup' ) . '</span></p>';

		echo '<p><button type="submit" class="button button-primary button-hero" id="ovb-start"' . disabled( null === $default, true, false ) . '>' . esc_html__( 'Back up now', 'openvault-backup' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * The progress panel (hidden until a backup is running).
	 *
	 * @param bool $visible Show it right away.
	 * @return void
	 */
	private function render_progress( $visible ) {
		echo '<section id="ovb-progress" class="ovb-panel" aria-labelledby="ovb-progress-title"' . ( $visible ? '' : ' hidden' ) . '>';
		echo '<h2 id="ovb-progress-title">' . esc_html__( 'Backup progress', 'openvault-backup' ) . '</h2>';
		echo '<p id="ovb-live" class="ovb-live" role="status" aria-live="polite"></p>';
		echo '<p class="ovb-bar-row"><progress id="ovb-bar" class="ovb-bar" max="100" value="0" aria-labelledby="ovb-progress-title"></progress> <span id="ovb-percent" class="ovb-percent">0%</span></p>';
		echo '<p class="ovb-meta"><span id="ovb-step"></span> <span id="ovb-detail"></span></p>';
		echo '<p class="ovb-meta">' . esc_html__( 'Elapsed:', 'openvault-backup' ) . ' <span id="ovb-elapsed">0:00</span> <span id="ovb-eta"></span></p>';
		echo '<p id="ovb-warnings" class="ovb-meta" hidden></p>';
		echo '<p><button type="button" class="button" id="ovb-cancel">' . esc_html__( 'Cancel backup', 'openvault-backup' ) . '</button></p>';

		echo '<div id="ovb-summary" class="ovb-summary" hidden>';
		echo '<h3 id="ovb-summary-title"></h3><p id="ovb-summary-text"></p><ul id="ovb-downloads" class="ovb-downloads-list"></ul>';
		echo '<p><a class="button button-primary" href="' . esc_url( Menu::url( 'openvault-backups' ) ) . '">' . esc_html__( 'Go to backup history', 'openvault-backup' ) . '</a> ';
		echo '<a class="button" id="ovb-again" href="' . esc_url( Menu::url( 'openvault-backup-now' ) ) . '">' . esc_html__( 'Back up again', 'openvault-backup' ) . '</a></p>';
		echo '</div>';
		echo '</section>';
	}
}
