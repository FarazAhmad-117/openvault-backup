<?php
/**
 * The JSON shape of "what will happen" for POST /restores/preview (pure logic, no WordPress needed).
 *
 * Phase: MVP
 *
 * Purpose: Turns the result of RestoreManager::preview() (checks + plan) and the backup row into the facts the Restore page shows before the
 * user confirms: what the backup is, which parts of the site will be touched, how big everything is, where the data comes from, the
 * errors, warnings (stable codes) and acknowledgements, the protected paths in plain language and the disk need against the free space.
 * Built from whitelists: no secret, no absolute server path (replaced by "[site]"), the plan's internal file lists are not included.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Admin\BackupRowPresenter;
use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Rest\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Preview payload.
 */
final class RestorePreviewPresenter {

	/**
	 * What a restore never overwrites, in plain language.
	 *
	 * @param bool $files Whether the restore changes files.
	 * @param bool $db    Whether the restore changes the database.
	 * @return array<int,string>
	 */
	public static function protected_paths( $files, $db ) {
		$out = array();
		if ( $files ) {
			$out[] = __( 'The OpenVault Backup plugin itself, its backup folder and wp-config.php are never overwritten.', 'openvault-backup' );
			$out[] = __( 'Server configuration files (.htaccess, web.config, php.ini, .user.ini) are not restored unless you choose to, because a wrong one can lock you out.', 'openvault-backup' );
			$out[] = __( 'Links and special files inside the archive are never restored, and nothing is ever written outside the WordPress folder.', 'openvault-backup' );
		}
		if ( $db ) {
			$out[] = __( 'The plugin\'s own tables, settings and saved credentials are kept, so your destinations and backup history survive the restore.', 'openvault-backup' );
		}
		return $out;
	}

	/**
	 * Build the payload.
	 *
	 * @param array<string,mixed> $backup Backup row (BackupsRepository).
	 * @param array<string,mixed> $result Result of RestoreManager::preview().
	 * @param array<int,string>   $labels Destination labels by id.
	 * @param array<int,string>   $roots  Folders to hide in messages.
	 * @return array<string,mixed>
	 */
	public static function build( array $backup, array $result, array $labels, array $roots ) {
		$plan     = isset( $result['plan'] ) && is_array( $result['plan'] ) ? $result['plan'] : null;
		$summary  = null !== $plan && isset( $plan['summary'] ) && is_array( $plan['summary'] ) ? $plan['summary'] : array();
		$db       = ! empty( $summary['restores_db'] );
		$files    = ! empty( $summary['restores_files'] );
		$contents = isset( $backup['contents'] ) && is_array( $backup['contents'] ) ? $backup['contents'] : array();

		$source = null;
		if ( null !== $plan && array_key_exists( 'chosen_source', $plan ) && null !== $plan['chosen_source'] ) {
			$id     = (int) $plan['chosen_source'];
			$source = array(
				'kind'  => 0 === $id ? 'local' : 'destination',
				'label' => 0 === $id ? __( 'This server (local backup folder)', 'openvault-backup' ) : ( isset( $labels[ $id ] ) ? (string) $labels[ $id ] : sprintf( /* translators: %d: destination id. */ __( 'Destination %d', 'openvault-backup' ), $id ) ),
			);
		}

		$ack_codes = array();
		$acks      = self::items( isset( $result['requires_ack'] ) ? $result['requires_ack'] : array(), $roots );
		foreach ( $acks as $item ) {
			$ack_codes[] = $item['code'];
		}

		$disk = isset( $result['disk'] ) && is_array( $result['disk'] ) ? $result['disk'] : array();

		return array(
			'ok'           => ! empty( $result['ok'] ),
			'backup'       => array(
				'id'               => (int) $backup['id'],
				'created_at'       => RestController::iso_time( $backup['created_at'] ),
				'trigger'          => (string) $backup['trigger_type'],
				'trigger_label'    => BackupRowPresenter::trigger_label( (string) $backup['trigger_type'] ),
				'format'           => (string) $backup['format'],
				'size'             => (int) $backup['total_size'],
				'contents'         => $contents,
				'contents_summary' => BackupRowPresenter::contents_summary( $contents ),
			),
			'mode'         => null !== $plan && isset( $plan['mode'] ) ? (string) $plan['mode'] : '',
			'touches'      => array(
				'db'    => $db,
				'files' => $files,
			),
			'source'       => $source,
			'sizes'        => array(
				'download' => null === $plan ? 0 : (int) $plan['download_bytes'],
				'extract'  => null === $plan ? 0 : (int) $plan['extract_bytes'],
				'snapshot' => null === $plan ? 0 : (int) $plan['snapshot_bytes'],
				'journal'  => null === $plan ? 0 : (int) $plan['journal_bytes'],
				'db'       => isset( $summary['db_bytes'] ) ? (int) $summary['db_bytes'] : 0,
				'files'    => isset( $summary['files_bytes'] ) ? (int) $summary['files_bytes'] : 0,
			),
			'disk'         => array(
				'free'    => isset( $disk['free'] ) && null !== $disk['free'] ? (int) $disk['free'] : null,
				'needed'  => isset( $disk['needed'] ) ? (int) $disk['needed'] : 0,
				'checked' => ! empty( $disk['checked'] ),
			),
			'errors'       => self::items( isset( $result['errors'] ) ? $result['errors'] : array(), $roots ),
			'warnings'     => self::items( isset( $result['warnings'] ) ? $result['warnings'] : array(), $roots ),
			'requires_ack' => $acks,
			'protected'    => self::protected_paths( $files, $db ),
			'options'      => array(
				'url_mismatch'         => in_array( RestorePreflight::ACK_URL_MISMATCH, $ack_codes, true ),
				'server_config_choice' => $files && ! empty( $contents['core'] ),
			),
		);
	}

	/**
	 * Messages as { code, message } with paths hidden and secrets masked.
	 *
	 * @param mixed             $items Items { code, message }.
	 * @param array<int,string> $roots Folders to hide.
	 * @return array<int,array{code:string,message:string}>
	 */
	private static function items( $items, array $roots ) {
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['code'], $item['message'] ) ) {
				continue;
			}
			$out[] = array(
				'code'    => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $item['code'] ) ),
				'message' => RestController::hide_paths( DestinationsRepository::scrub_error( (string) $item['message'] ), $roots ),
			);
		}
		return $out;
	}
}
