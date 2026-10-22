<?php
/**
 * Percent complete of a restore job (pure logic, no WordPress needed).
 *
 * Phase: MVP
 *
 * Purpose: Turn the status, step, cursor and meta of a restore into a number for the progress bar. Every step owns a fixed band so the
 * number never goes backwards when the job moves from one step to the next; inside a band it follows values that only grow.
 *
 * Bands (start-end), for the steps a job really runs:
 *  - restore:  preflight 0-3, fetch 3-30, verify 30-40 (a verified job waits at 40 for the user), snapshot 40-55, db_restore 55-80
 *              (55-95 without files), file_restore 80-95 (40-95 without a database part), finish 95-100.
 *  - undo:     preflight 0-3, fetch 3-20, verify 20-30, db_restore 30-80 (30-95 without files), file_undo 80-95 (0-95 without a database
 *              part), finish 95-100.
 * Inside a band: the import of the database follows bytes read / dump size; steps whose total is unknown (downloads, verification,
 * file restore) follow count / (count + K), which grows with the work done and never reaches the end of the band. "complete" is 100,
 * a failed or cancelled job reports 0 (the page keeps the highest value it has shown).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Engine\JobState;

defined( 'ABSPATH' ) || exit;

/**
 * Restore progress.
 */
final class RestoreProgress {

	/**
	 * Bands of the steps for the kind of job.
	 *
	 * @param bool $undo  Undo job.
	 * @param bool $db    The job changes the database.
	 * @param bool $files The job changes files.
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function bands( $undo, $db, $files ) {
		if ( $undo ) {
			$db_end = $files ? 80 : 95;
			return array(
				JobState::STEP_PREFLIGHT  => array( 0, 3 ),
				JobState::STEP_FETCH      => array( 3, 20 ),
				JobState::STEP_VERIFY     => array( 20, 30 ),
				JobState::STEP_DB_RESTORE => array( 30, $db_end ),
				JobState::STEP_FILE_UNDO  => array( $db ? 80 : 0, 95 ),
				JobState::STEP_FINISH     => array( 95, 100 ),
			);
		}
		return array(
			JobState::STEP_PREFLIGHT    => array( 0, 3 ),
			JobState::STEP_FETCH        => array( 3, 30 ),
			JobState::STEP_VERIFY       => array( 30, 40 ),
			JobState::STEP_SNAPSHOT     => array( 40, 55 ),
			JobState::STEP_DB_RESTORE   => array( 55, $files ? 80 : 95 ),
			JobState::STEP_FILE_RESTORE => array( $db ? 80 : 40, 95 ),
			JobState::STEP_FINISH       => array( 95, 100 ),
		);
	}

	/**
	 * Percent complete, 0 to 100.
	 *
	 * @param string       $status Row status (queued, running, verified, complete, failed, cancelled).
	 * @param string       $step   Step of the job state.
	 * @param array<mixed> $cursor Cursor of the job state.
	 * @param array<mixed> $meta   Meta of the job state.
	 * @return int
	 */
	public static function percent( $status, $step, array $cursor, array $meta ) {
		if ( 'complete' === $status ) {
			return 100;
		}
		if ( 'failed' === $status || 'cancelled' === $status ) {
			return 0;
		}

		$undo  = RestoreMeta::MODE_UNDO === RestoreMeta::mode( $meta );
		$bands = self::bands( $undo, RestoreMeta::does_db( $meta ), RestoreMeta::does_files( $meta ) );

		if ( 'verified' === $status ) {
			return $bands[ JobState::STEP_VERIFY ][1];
		}
		if ( ! isset( $bands[ $step ] ) ) {
			return 0;
		}

		list( $low, $high ) = $bands[ $step ];
		$fraction           = self::fraction( $step, $cursor, $meta );
		$value              = (int) floor( $low + ( $high - $low ) * $fraction );
		$cap                = JobState::STEP_FINISH === $step ? 99 : $high;
		return max( $low, min( $cap, $value ) );
	}

	/**
	 * Fraction (0 to just under 1) of a step that is done.
	 *
	 * @param string       $step   Step.
	 * @param array<mixed> $cursor Cursor.
	 * @param array<mixed> $meta   Meta.
	 * @return float
	 */
	private static function fraction( $step, array $cursor, array $meta ) {
		switch ( $step ) {
			case JobState::STEP_FETCH:
			case JobState::STEP_VERIFY:
				return self::asymptote( self::int( $cursor, 'i' ), 2 );
			case JobState::STEP_DB_RESTORE:
				$phase = isset( $cursor['ph'] ) && is_string( $cursor['ph'] ) ? $cursor['ph'] : 'x';
				if ( 'x' === $phase ) {
					return 0.05;
				}
				if ( 'v' === $phase ) {
					return 0.15;
				}
				$size = isset( $meta['dump']['size'] ) && is_numeric( $meta['dump']['size'] ) ? (int) $meta['dump']['size'] : 0;
				return $size > 0 ? 0.2 + 0.79 * min( 1.0, self::int( $cursor, 'off' ) / $size ) : 0.2;
			case JobState::STEP_FILE_RESTORE:
				return self::asymptote( self::int( $cursor, 'fl' ), 5000 );
			case JobState::STEP_FILE_UNDO:
				return self::asymptote( self::int( $cursor, 'rs' ) + self::int( $cursor, 'rm' ), 5000 );
		}
		return 0.0;
	}

	/**
	 * Work done over work done plus k: 0 for no work, growing towards (never reaching) 1.
	 *
	 * @param int $count Work done.
	 * @param int $k     Half-way point.
	 * @return float
	 */
	private static function asymptote( $count, $k ) {
		return $count / ( $count + $k );
	}

	/**
	 * Non-negative integer from an array.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $key  Key.
	 * @return int
	 */
	private static function int( array $data, $key ) {
		return isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? max( 0, (int) $data[ $key ] ) : 0;
	}
}
