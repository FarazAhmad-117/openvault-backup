<?php
/**
 * Progress of a backup job: percent, step label and ETA (pure logic, no WordPress needed).
 *
 * Phase: MVP
 *
 * Purpose: Turn the job step + cursor + meta of a JobState into something a progress bar can show.
 *
 * Percent mapping (each step owns a fixed band so the number never goes backwards between steps, and inside a
 * step it only follows cursors that only grow):
 *   preflight   0-3     fixed
 *   db_export   3-25    tables_done / tables_total (tables are the only known total)
 *   file_scan  25-40    unknown total: a fixed 25 while running, the band is passed when the step ends
 *   archive    40-95    bytes archived / bytes found by the scan (the database part counts as the first slice)
 *   encrypt, upload, retention (not implemented yet, skipped by the runner) 95-97
 *   cleanup    97-99    fixed
 *   done       100
 * A queued backup is 0. A failed backup reports the last known 0 (the state does not keep the step it failed in).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Progress calculator.
 */
final class Progress {

	/**
	 * Band of each step: start percent, end percent.
	 */
	const BANDS = array(
		JobState::STEP_PREFLIGHT => array( 0, 3 ),
		JobState::STEP_DB_EXPORT => array( 3, 25 ),
		JobState::STEP_FILE_SCAN => array( 25, 40 ),
		JobState::STEP_ARCHIVE   => array( 40, 95 ),
		JobState::STEP_ENCRYPT   => array( 95, 96 ),
		JobState::STEP_UPLOAD    => array( 96, 97 ),
		JobState::STEP_RETENTION => array( 97, 97 ),
		JobState::STEP_CLEANUP   => array( 97, 99 ),
	);

	/**
	 * Percent, label and detail text of a job.
	 *
	 * @param string              $step   JobState step id (including "done" and "failed").
	 * @param array<string,mixed> $cursor JobState cursor.
	 * @param array<string,mixed> $meta   JobState meta.
	 * @return array{percent:int,label:string,detail:string}
	 */
	public static function describe( $step, array $cursor, array $meta ) {
		return array(
			'percent' => self::percent( $step, $cursor, $meta ),
			'label'   => self::label( $step ),
			'detail'  => self::detail( $step, $cursor, $meta ),
		);
	}

	/**
	 * Percent complete, 0 to 100.
	 *
	 * @param string              $step   Step id.
	 * @param array<string,mixed> $cursor Cursor.
	 * @param array<string,mixed> $meta   Meta.
	 * @return int
	 */
	public static function percent( $step, array $cursor, array $meta ) {
		if ( JobState::STEP_DONE === $step ) {
			return 100;
		}
		if ( ! isset( self::BANDS[ $step ] ) ) {
			return 0;
		}

		$band     = self::BANDS[ $step ];
		$fraction = self::fraction( $step, $cursor, $meta );
		return (int) max( 0, min( 99, floor( $band[0] + ( $band[1] - $band[0] ) * $fraction ) ) );
	}

	/**
	 * Translatable name of a step.
	 *
	 * @param string $step Step id.
	 * @return string
	 */
	public static function label( $step ) {
		switch ( $step ) {
			case JobState::STEP_PREFLIGHT:
				return __( 'Checking the server', 'openvault-backup' );
			case JobState::STEP_DB_EXPORT:
				return __( 'Exporting the database', 'openvault-backup' );
			case JobState::STEP_FILE_SCAN:
				return __( 'Scanning files', 'openvault-backup' );
			case JobState::STEP_ARCHIVE:
				return __( 'Creating the archive', 'openvault-backup' );
			case JobState::STEP_ENCRYPT:
				return __( 'Encrypting', 'openvault-backup' );
			case JobState::STEP_UPLOAD:
				return __( 'Uploading', 'openvault-backup' );
			case JobState::STEP_RETENTION:
				return __( 'Removing old backups', 'openvault-backup' );
			case JobState::STEP_CLEANUP:
				return __( 'Cleaning up temporary files', 'openvault-backup' );
			case JobState::STEP_FETCH:
				return __( 'Downloading the backup', 'openvault-backup' );
			case JobState::STEP_VERIFY:
				return __( 'Verifying the backup', 'openvault-backup' );
			case JobState::STEP_SNAPSHOT:
				return __( 'Taking a safety snapshot', 'openvault-backup' );
			case JobState::STEP_DB_RESTORE:
				return __( 'Restoring the database', 'openvault-backup' );
			case JobState::STEP_FILE_RESTORE:
				return __( 'Restoring files', 'openvault-backup' );
			case JobState::STEP_FILE_UNDO:
				return __( 'Putting the files back', 'openvault-backup' );
			case JobState::STEP_FINISH:
				return __( 'Finishing the restore', 'openvault-backup' );
			case JobState::STEP_DONE:
				return __( 'Finished', 'openvault-backup' );
			case JobState::STEP_FAILED:
				return __( 'Stopped', 'openvault-backup' );
		}//end switch
		return __( 'Waiting to start', 'openvault-backup' );
	}

	/**
	 * Estimated seconds left, or null when it is too early to say.
	 *
	 * @param int $percent Percent complete.
	 * @param int $elapsed Seconds since the start.
	 * @return int|null
	 */
	public static function eta_seconds( $percent, $elapsed ) {
		$percent = (int) $percent;
		$elapsed = (int) $elapsed;
		if ( $percent < 5 || $percent >= 100 || $elapsed < 5 ) {
			return null;
		}
		return (int) round( $elapsed * ( 100 - $percent ) / $percent );
	}

	/**
	 * Fraction (0 to just under 1) of a step that is done.
	 *
	 * @param string              $step   Step id.
	 * @param array<string,mixed> $cursor Cursor.
	 * @param array<string,mixed> $meta   Meta.
	 * @return float
	 */
	private static function fraction( $step, array $cursor, array $meta ) {
		if ( JobState::STEP_DB_EXPORT === $step ) {
			$total = self::int( $cursor, 'tables_total' );
			return $total > 0 ? self::clamp( self::int( $cursor, 'tables_done' ) / $total ) : 0.0;
		}
		if ( JobState::STEP_FILE_SCAN === $step ) {
			return 0.0;
		}
		if ( JobState::STEP_ARCHIVE === $step ) {
			$files = isset( $meta['files'] ) && is_array( $meta['files'] ) ? $meta['files'] : array();
			$total = self::int( $files, 'bytes' );
			$phase = isset( $cursor['phase'] ) ? $cursor['phase'] : '';
			if ( 'finalize' === $phase ) {
				return 0.99;
			}
			if ( $total < 1 ) {
				return 0.0;
			}
			return self::clamp( self::int( $cursor, 'bytes_done' ) / $total );
		}
		return 0.0;
	}

	/**
	 * Short detail line for a step (numbers only, translatable).
	 *
	 * @param string              $step   Step id.
	 * @param array<string,mixed> $cursor Cursor.
	 * @param array<string,mixed> $meta   Meta.
	 * @return string
	 */
	private static function detail( $step, array $cursor, array $meta ) {
		if ( JobState::STEP_DB_EXPORT === $step && self::int( $cursor, 'tables_total' ) > 0 ) {
			return sprintf(
				/* translators: 1: tables exported, 2: total tables. */
				__( '%1$d of %2$d database tables', 'openvault-backup' ),
				self::int( $cursor, 'tables_done' ),
				self::int( $cursor, 'tables_total' )
			);
		}
		if ( JobState::STEP_ARCHIVE === $step ) {
			$files = isset( $meta['files'] ) && is_array( $meta['files'] ) ? $meta['files'] : array();
			if ( self::int( $files, 'count' ) > 0 ) {
				return sprintf(
					/* translators: 1: files archived, 2: total files. */
					__( '%1$d of %2$d files', 'openvault-backup' ),
					min( self::int( $cursor, 'files_done' ), self::int( $files, 'count' ) ),
					self::int( $files, 'count' )
				);
			}
		}
		return '';
	}

	/**
	 * Read a non-negative integer from an array.
	 *
	 * @param array<string,mixed> $data Data.
	 * @param string              $key  Key.
	 * @return int
	 */
	private static function int( array $data, $key ) {
		return isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? max( 0, (int) $data[ $key ] ) : 0;
	}

	/**
	 * Clamp to 0..0.99 so a step never claims to be finished before it hands over.
	 *
	 * @param float $value Value.
	 * @return float
	 */
	private static function clamp( $value ) {
		return max( 0.0, min( 0.99, (float) $value ) );
	}
}
