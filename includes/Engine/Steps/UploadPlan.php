<?php
/**
 * Pure planning rules of the upload step (no WordPress, no database).
 *
 * Phase: MVP
 *
 * Purpose: Decides which file row to upload next, when a destination is finished, how the backup ends and how
 * long to wait after a transient error, so the rules can be unit tested without a site.
 *
 * Rules:
 *  - A row that is already "uploading" resumes first, then pending parts by part number.
 *  - manifest.json goes LAST and only when every part is done: a remote folder is complete only if it holds a manifest.
 *  - A failed part stops the destination (its manifest is never uploaded); a failed manifest fails it too.
 *  - The backup is "complete" only when every selected destination succeeded, otherwise "partial" (the local copy is
 *    always intact at this point).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Steps;

defined( 'ABSPATH' ) || exit;

/**
 * Upload planning.
 */
final class UploadPlan {

	const MANIFEST = 'manifest.json';

	const STATE_ACTIVE = 'active';
	const STATE_DONE   = 'done';
	const STATE_FAILED = 'failed';

	/**
	 * Attempts per file (the first try included).
	 */
	const MAX_TRIES = 3;

	/**
	 * Seconds to wait after attempt 1 and attempt 2.
	 */
	const BACKOFF = array( 30, 120 );

	/**
	 * Most destinations one backup may select.
	 */
	const MAX_DESTINATIONS = 10;

	/**
	 * Longest error text kept in the job meta.
	 */
	const ERROR_MAX = 160;

	/**
	 * Destination ids selected for a backup (meta key "destinations"): positive, unique integers, at most MAX_DESTINATIONS.
	 *
	 * @param array<mixed> $meta Job meta.
	 * @return int[]
	 */
	public static function destination_ids( array $meta ) {
		$ids = array();
		if ( isset( $meta['destinations'] ) && is_array( $meta['destinations'] ) ) {
			foreach ( $meta['destinations'] as $id ) {
				if ( is_int( $id ) && $id >= 1 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}
		return array_slice( $ids, 0, self::MAX_DESTINATIONS );
	}

	/**
	 * Whether a file row is the manifest.
	 *
	 * @param array<string,mixed> $row File row.
	 * @return bool
	 */
	public static function is_manifest( array $row ) {
		return isset( $row['file_name'] ) && self::MANIFEST === $row['file_name'];
	}

	/**
	 * Rows in upload order: parts by part number (then id), the manifest last.
	 *
	 * @param array<int,array<string,mixed>> $rows File rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function order( array $rows ) {
		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( $a, $b ) {
				$ma = self::is_manifest( $a ) ? 1 : 0;
				$mb = self::is_manifest( $b ) ? 1 : 0;
				if ( $ma !== $mb ) {
					return $ma - $mb;
				}
				$pa = isset( $a['part_number'] ) ? (int) $a['part_number'] : 0;
				$pb = isset( $b['part_number'] ) ? (int) $b['part_number'] : 0;
				if ( $pa !== $pb ) {
					return $pa - $pb;
				}
				return ( isset( $a['id'] ) ? (int) $a['id'] : 0 ) - ( isset( $b['id'] ) ? (int) $b['id'] : 0 );
			}
		);
		return $rows;
	}

	/**
	 * The next row to work on at one destination, or null when nothing can be uploaded any more
	 * (everything is done, or something failed).
	 *
	 * @param array<int,array<string,mixed>> $rows Rows of one backup at one destination.
	 * @return array<string,mixed>|null
	 */
	public static function next_row( array $rows ) {
		$pending = null;
		foreach ( self::order( $rows ) as $row ) {
			$status = isset( $row['upload_status'] ) ? $row['upload_status'] : '';
			if ( 'failed' === $status ) {
				return null;
			}
			if ( 'uploading' === $status ) {
				return $row;
			}
			// Parts sort before the manifest, so the first pending row is a part whenever one is left.
			if ( 'pending' === $status && null === $pending ) {
				$pending = $row;
			}
		}
		return $pending;
	}

	/**
	 * State of one destination.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows of one backup at one destination.
	 * @return string One of the STATE_ constants.
	 */
	public static function destination_state( array $rows ) {
		$all_done = true;
		foreach ( $rows as $row ) {
			$status = isset( $row['upload_status'] ) ? $row['upload_status'] : '';
			if ( 'failed' === $status ) {
				return self::STATE_FAILED;
			}
			if ( 'done' !== $status ) {
				$all_done = false;
			}
		}
		return $all_done ? self::STATE_DONE : self::STATE_ACTIVE;
	}

	/**
	 * How the backup ends once every destination has finished.
	 *
	 * @param array<int|string,string> $states Destination id => STATE_DONE or STATE_FAILED.
	 * @return string "complete" when all succeeded, otherwise "partial".
	 */
	public static function final_result( array $states ) {
		foreach ( $states as $state ) {
			if ( self::STATE_DONE !== $state ) {
				return 'partial';
			}
		}
		return 'complete';
	}

	/**
	 * Seconds to wait before the next attempt.
	 *
	 * @param int $tries Attempts made so far (1 or more).
	 * @return int
	 */
	public static function backoff( $tries ) {
		$index = max( 1, min( (int) $tries, count( self::BACKOFF ) ) ) - 1;
		return self::BACKOFF[ $index ];
	}

	/**
	 * Whether another attempt is allowed after $tries failed ones.
	 *
	 * @param int $tries Attempts made so far.
	 * @return bool
	 */
	public static function may_retry( $tries ) {
		return (int) $tries < self::MAX_TRIES;
	}

	/**
	 * An error text short enough for the job meta.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function short_error( $message ) {
		$message = trim( (string) $message );
		return strlen( $message ) > self::ERROR_MAX ? substr( $message, 0, self::ERROR_MAX - 3 ) . '...' : $message;
	}
}
