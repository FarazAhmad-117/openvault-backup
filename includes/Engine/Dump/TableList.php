<?php
/**
 * The ordered list of tables a database export dumps.
 *
 * Phase: MVP
 *
 * Purpose: Pure helpers that pick the tables, and that let the export find its place again after a resume.
 *
 * Responsibilities:
 *  - select(): base tables (never views) that start with the table prefix, minus exclusions, sorted by name
 *    with a plain byte comparison, so the order is the same on every run and every server collation
 *  - fits_in_cursor(): whether the list may be stored inside the job cursor (see CURSOR_LIST_MAX_BYTES)
 *  - position(): index of a table by name, so a list that is recomputed on every slice still resumes at the
 *    right table when tables appear or disappear while the export runs
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Dump;

defined( 'ABSPATH' ) || exit;

/**
 * Table list rules.
 */
final class TableList {

	/**
	 * Largest JSON size of a table list that is stored in the cursor. The cursor limit is 64 KB; this keeps
	 * half of it free for everything else. A bigger list (about 500 tables of average length or more) is
	 * recomputed on every slice instead.
	 */
	const CURSOR_LIST_MAX_BYTES = 32768;

	/**
	 * Pick the tables to dump.
	 *
	 * @param array<int,array{0:string,1:string}> $tables Rows of SHOW FULL TABLES: name and type.
	 * @param string                              $prefix Table prefix: only tables starting with it are dumped.
	 * @param ExclusionRules                      $rules  Exclusions.
	 * @return string[] Sorted, unique names.
	 */
	public static function select( array $tables, $prefix, ExclusionRules $rules ) {
		$prefix = (string) $prefix;
		$names  = array();
		foreach ( $tables as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[0], $row[1] ) ) {
				continue;
			}
			$name = (string) $row[0];
			if ( 'BASE TABLE' !== strtoupper( (string) $row[1] ) ) {
				continue;
			}
			if ( '' !== $prefix && 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			if ( $rules->is_excluded_table( $name ) ) {
				continue;
			}
			$names[ $name ] = $name;
		}
		$names = array_values( $names );
		usort( $names, 'strcmp' );
		return $names;
	}

	/**
	 * Whether a list may be stored in the job cursor.
	 *
	 * @param string[] $names Table names.
	 * @return bool
	 */
	public static function fits_in_cursor( array $names ) {
		$json = json_encode( $names ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Works without WordPress loaded (unit tests).
		return false !== $json && strlen( $json ) <= self::CURSOR_LIST_MAX_BYTES;
	}

	/**
	 * Index of the first table whose name is not before $name. For a table that still exists this is its own
	 * index; for a table that disappeared it is the index of the next one; past the end it is count( $names ).
	 *
	 * @param string[] $names Sorted names.
	 * @param string   $name  Table name ('' gives 0).
	 * @return int
	 */
	public static function position( array $names, $name ) {
		$name = (string) $name;
		if ( '' === $name ) {
			return 0;
		}
		$low  = 0;
		$high = count( $names );
		while ( $low < $high ) {
			$mid = intdiv( $low + $high, 2 );
			if ( strcmp( $names[ $mid ], $name ) < 0 ) {
				$low = $mid + 1;
			} else {
				$high = $mid;
			}
		}
		return $low;
	}
}
