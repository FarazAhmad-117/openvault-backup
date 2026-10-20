<?php
/**
 * Per-destination upload summary of a backup (pure logic).
 *
 * Phase: MVP
 *
 * Purpose: Turns the ovb_backup_files rows of a backup (all destinations) into one small entry per destination
 * for the REST progress payload and the History list. Status is always text, never colour alone.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Upload summary.
 */
final class UploadSummary {

	/**
	 * One entry per remote destination.
	 *
	 * @param array<int,array<string,mixed>> $rows        Every file row of the backup (destination 0 is ignored).
	 * @param array<int,string>              $labels      Destination id => label.
	 * @param int[]                          $selected    Destination ids chosen for the backup (shown even before their first row).
	 * @param array<mixed>                   $meta_upload Job meta "upload": destination id => array( s = state, e = error ).
	 * @return array<int,array{id:int,label:string,status:string,files_done:int,files_total:int,error:string}>
	 */
	public static function summarise( array $rows, array $labels, array $selected = array(), array $meta_upload = array() ) {
		$by_destination = array();
		foreach ( $selected as $id ) {
			$by_destination[ (int) $id ] = array();
		}
		foreach ( $rows as $row ) {
			$id = isset( $row['destination_id'] ) ? (int) $row['destination_id'] : 0;
			if ( $id > 0 ) {
				$by_destination[ $id ][] = $row;
			}
		}
		ksort( $by_destination );

		$out = array();
		foreach ( $by_destination as $id => $files ) {
			$total  = count( $files );
			$done   = 0;
			$failed = false;
			$active = false;
			foreach ( $files as $file ) {
				$status = isset( $file['upload_status'] ) ? $file['upload_status'] : '';
				$done  += 'done' === $status ? 1 : 0;
				$failed = $failed || 'failed' === $status;
				$active = $active || 'uploading' === $status;
			}

			$meta   = isset( $meta_upload[ $id ] ) && is_array( $meta_upload[ $id ] ) ? $meta_upload[ $id ] : array();
			$failed = $failed || ( isset( $meta['s'] ) && 'failed' === $meta['s'] );

			if ( $failed ) {
				$status = 'failed';
			} elseif ( $total > 0 && $done === $total ) {
				$status = 'done';
			} elseif ( $active || $done > 0 ) {
				$status = 'uploading';
			} else {
				$status = 'pending';
			}

			$out[] = array(
				'id'          => (int) $id,
				'label'       => isset( $labels[ $id ] ) && '' !== $labels[ $id ] ? (string) $labels[ $id ] : sprintf(
					/* translators: %d: destination id. */
					__( 'Destination %d', 'openvault-backup' ),
					$id
				),
				'status'      => $status,
				'files_done'  => $done,
				'files_total' => $total,
				'error'       => isset( $meta['e'] ) && is_string( $meta['e'] ) ? $meta['e'] : '',
			);
		}//end foreach

		return $out;
	}
}
