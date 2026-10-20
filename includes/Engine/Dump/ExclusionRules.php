<?php
/**
 * What the database export leaves out.
 *
 * Phase: MVP
 *
 * Purpose: Pure rules for excluded tables and excluded rows.
 *
 * Responsibilities:
 *  - Always exclude the plugin's own tables ({prefix}ovb_*, also {prefix}<blog id>_ovb_* on multisite): they hold
 *    history, schedules, logs and destinations with encrypted credentials, and a restore preserves them
 *  - Always exclude rows of the options table whose name starts with "ovb_", "_transient_" or "_site_transient_"
 *    (on multisite also the same keys in the sitemeta table)
 *  - Honour user patterns: exact table names or "*" globs, validated (see normalise_patterns())
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Dump;

defined( 'ABSPATH' ) || exit;

/**
 * Exclusion rules.
 */
final class ExclusionRules {

	const MAX_PATTERNS       = 200;
	const MAX_PATTERN_LENGTH = 64;

	/**
	 * Option name prefixes whose rows are not exported.
	 */
	const EXCLUDED_ROW_PREFIXES = array( 'ovb_', '_transient_', '_site_transient_' );

	/**
	 * Table prefix (base prefix on multisite).
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Whether the site is a multisite network.
	 *
	 * @var bool
	 */
	private $multisite;

	/**
	 * Compiled user patterns.
	 *
	 * @var string[]
	 */
	private $regexes = array();

	/**
	 * Constructor.
	 *
	 * @param string $prefix    Table prefix ($wpdb->base_prefix).
	 * @param bool   $multisite Multisite network.
	 * @param mixed  $patterns  Raw user patterns (validated here).
	 */
	public function __construct( $prefix, $multisite = false, $patterns = array() ) {
		$this->prefix    = (string) $prefix;
		$this->multisite = (bool) $multisite;
		foreach ( self::normalise_patterns( $patterns ) as $pattern ) {
			$this->regexes[] = '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/D';
		}
	}

	/**
	 * Keep only valid patterns. A pattern is 1 to 64 characters of letters, digits, "_", "$" and "*", and has
	 * at least one character besides "*" (so a bare "*" cannot exclude everything). Duplicates and anything else
	 * (wrong type, spaces, quotes, regex or SQL characters) are dropped, and at most 200 are kept.
	 *
	 * @param mixed $raw List of patterns.
	 * @return string[]
	 */
	public static function normalise_patterns( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$valid = array();
		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$item = trim( $item );
			if ( '' === $item || strlen( $item ) > self::MAX_PATTERN_LENGTH ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[A-Za-z0-9_$*]+$/', $item ) || '' === str_replace( '*', '', $item ) ) {
				continue;
			}
			$valid[ $item ] = $item;
			if ( count( $valid ) >= self::MAX_PATTERNS ) {
				break;
			}
		}
		return array_values( $valid );
	}

	/**
	 * Whether a table is left out of the dump.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public function is_excluded_table( $table ) {
		$table = (string) $table;
		if ( $this->is_plugin_table( $table ) ) {
			return true;
		}
		foreach ( $this->regexes as $regex ) {
			if ( 1 === preg_match( $regex, $table ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this is one of the plugin's own tables.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public function is_plugin_table( $table ) {
		$pattern = $this->multisite
			? '/^' . preg_quote( $this->prefix, '/' ) . '(\d+_)?ovb_/'
			: '/^' . preg_quote( $this->prefix, '/' ) . 'ovb_/';
		return 1 === preg_match( $pattern, (string) $table );
	}

	/**
	 * SQL condition (without WHERE) that keeps only the rows to export, or '' when every row is kept.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	public function row_filter( $table ) {
		$column = $this->filtered_column( (string) $table );
		if ( '' === $column ) {
			return '';
		}

		$parts = array();
		foreach ( self::EXCLUDED_ROW_PREFIXES as $row_prefix ) {
			$parts[] = 'LEFT(' . SqlValueFormatter::identifier( $column ) . ', ' . strlen( $row_prefix ) . ') <> ' . SqlValueFormatter::quote_string( $row_prefix );
		}
		return implode( ' AND ', $parts );
	}

	/**
	 * Column that holds the key of a key/value table whose rows are filtered ('' for other tables).
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private function filtered_column( $table ) {
		$quoted = preg_quote( $this->prefix, '/' );
		$sites  = $this->multisite ? '(\d+_)?' : '';

		if ( 1 === preg_match( '/^' . $quoted . $sites . 'options$/', $table ) ) {
			return 'option_name';
		}
		if ( $this->multisite && 1 === preg_match( '/^' . $quoted . 'sitemeta$/', $table ) ) {
			return 'meta_key';
		}
		return '';
	}
}
