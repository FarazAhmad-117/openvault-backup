<?php
/**
 * System Info page.
 *
 * Phase: MVP
 *
 * Purpose: PHP/WP versions, limits, extensions, disk space, cron health, supported formats.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

use OpenVault\Backup\Support\SystemInfo;

defined( 'ABSPATH' ) || exit;

/**
 * System Info.
 */
class SystemInfoPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'System Info', 'openvault-backup' );
	}

	/**
	 * Environment tables and a copyable report.
	 *
	 * @return void
	 */
	protected function content() {
		$sections = SystemInfo::collect();

		foreach ( $sections as $title => $rows ) {
			echo '<h2>' . esc_html( $title ) . '</h2>';
			echo '<table class="widefat striped" role="presentation"><tbody>';
			foreach ( $rows as $label => $value ) {
				echo '<tr><th scope="row" style="width:260px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Report for support', 'openvault-backup' ) . '</h2>';
		echo '<p><textarea id="ovb-system-report" class="large-text code" rows="10" readonly>' . esc_textarea( SystemInfo::as_text( $sections ) ) . '</textarea></p>';
		echo '<p><button type="button" class="button" id="ovb-copy-report">' . esc_html__( 'Copy report', 'openvault-backup' ) . '</button> ';
		echo '<span id="ovb-copy-status" role="status" aria-live="polite"></span></p>';

		wp_print_inline_script_tag( $this->copy_script() );
	}

	/**
	 * Small vanilla JS for the copy button.
	 *
	 * @return string
	 */
	private function copy_script() {
		$copied = wp_json_encode( __( 'Copied.', 'openvault-backup' ) );
		$failed = wp_json_encode( __( 'Copy failed. Select the text and copy it manually.', 'openvault-backup' ) );

		return '(function(){'
			. 'var b=document.getElementById("ovb-copy-report"),t=document.getElementById("ovb-system-report"),s=document.getElementById("ovb-copy-status");'
			. 'if(!b||!t||!s){return;}'
			. 'var done=function(ok){s.textContent=ok?' . $copied . ':' . $failed . ';};'
			. 'b.addEventListener("click",function(){'
			. 'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t.value).then(function(){done(true);},function(){done(false);});return;}'
			. 't.select();try{done(document.execCommand("copy"));}catch(e){done(false);}'
			. '});'
			. '})();';
	}
}
