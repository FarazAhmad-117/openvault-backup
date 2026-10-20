<?php
/**
 * Adaptive row batch size of the database export.
 *
 * Phase: MVP
 *
 * Purpose: Pure rules that decide how many rows one SELECT fetches, so a table of tiny rows is exported
 * quickly and a table of huge rows never exhausts memory.
 *
 * Responsibilities:
 *  - Start at 500 rows (10 for tables with TEXT/BLOB/JSON columns, whose rows can be megabytes)
 *  - Halve (minimum 1) when a batch is larger than the statement byte cap or memory is tight
 *  - Double (up to 2,000) when the last batch was small enough that doubling still fits under the cap
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Dump;

defined( 'ABSPATH' ) || exit;

/**
 * Batch size rules.
 */
final class BatchSizer {

	const START           = 500;
	const START_HEAVY     = 10;
	const MIN             = 1;
	const MAX             = 2000;
	const STATEMENT_CAP   = 524288;
	const GROW_BELOW_RATE = 2;

	/**
	 * Batch size for the first batch of a table.
	 *
	 * @param bool $heavy The table has TEXT, BLOB, JSON or very wide columns.
	 * @return int
	 */
	public static function initial( $heavy ) {
		return $heavy ? self::START_HEAVY : self::START;
	}

	/**
	 * Batch size for the next batch.
	 *
	 * @param int  $current      Rows of the batch that was just exported.
	 * @param int  $batch_bytes  Bytes of the formatted rows of that batch.
	 * @param bool $memory_tight Memory use is at the ceiling.
	 * @return int
	 */
	public static function next( $current, $batch_bytes, $memory_tight ) {
		$current = max( self::MIN, min( self::MAX, (int) $current ) );

		if ( $memory_tight || (int) $batch_bytes > self::STATEMENT_CAP ) {
			return max( self::MIN, (int) floor( $current / 2 ) );
		}
		if ( (int) $batch_bytes * self::GROW_BELOW_RATE <= self::STATEMENT_CAP && $current < self::MAX ) {
			return min( self::MAX, $current * 2 );
		}
		return $current;
	}
}
