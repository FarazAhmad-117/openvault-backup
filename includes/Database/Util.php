<?php
/**
 * Pure helpers shared by the repositories (no WordPress, no database access).
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Clamp pagination, validate dates and integers, decode stored JSON safely.
 *  - Keep every repository's validation in plain static methods that unit tests can call.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Shared repository helpers.
 */
final class Util {

	const MIN_PER_PAGE     = 1;
	const MAX_PER_PAGE     = 200;
	const DEFAULT_PER_PAGE = 20;

	/**
	 * Current UTC time in the database format.
	 *
	 * @param int|null $time Unix time (defaults to now).
	 * @return string
	 */
	public static function now( $time = null ) {
		return gmdate( 'Y-m-d H:i:s', null === $time ? time() : (int) $time );
	}

	/**
	 * Decode a stored JSON column into an array. NULL, empty, invalid or non-array JSON gives array().
	 *
	 * @param mixed $value Raw column value.
	 * @return array<mixed>
	 */
	public static function decode_json( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$data = json_decode( $value, true, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure helper, works without WordPress loaded.
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Clamp pagination arguments: per_page to 1..200 (default 20), page to >= 1.
	 *
	 * @param array<string,mixed> $args Arguments with optional per_page and page.
	 * @return array{per_page:int,page:int,offset:int}
	 */
	public static function clamp_pagination( array $args ) {
		$per_page = isset( $args['per_page'] ) && is_numeric( $args['per_page'] ) ? (int) $args['per_page'] : self::DEFAULT_PER_PAGE;
		$page     = isset( $args['page'] ) && is_numeric( $args['page'] ) ? (int) $args['page'] : 1;

		$per_page = max( self::MIN_PER_PAGE, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = max( 1, min( 1000000, $page ) );

		return array(
			'per_page' => $per_page,
			'page'     => $page,
			'offset'   => ( $page - 1 ) * $per_page,
		);
	}

	/**
	 * Strict "Y-m-d H:i:s" check (a real calendar date and time).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_datetime( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\z/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
	}

	/**
	 * Turn a filter value into a datetime. "Y-m-d" becomes 00:00:00 (or 23:59:59 for an end bound).
	 * Anything else returns null (filter ignored).
	 *
	 * @param mixed $value   Value.
	 * @param bool  $end_day Use the end of the day for date-only input.
	 * @return string|null
	 */
	public static function filter_datetime( $value, $end_day = false ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\z/', $value ) ) {
			$value .= $end_day ? ' 23:59:59' : ' 00:00:00';
		}
		return self::is_datetime( $value ) ? $value : null;
	}

	/**
	 * Prepare a statement whose placeholders are fixed by the calling repository (values are always bound).
	 *
	 * This is the only helper here that touches WordPress ($wpdb); unit tests do not call it.
	 *
	 * @param string       $sql    SQL with placeholders.
	 * @param array<mixed> $params Values to bind.
	 * @return string
	 */
	public static function prepare_list( $sql, array $params ) {
		global $wpdb;

		if ( array() === $params ) {
			return $sql;
			// Nothing to bind (and prepare() warns when a query has no placeholder).
		}

		return $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from constants in the repositories.
	}

	/**
	 * Require an integer >= 0 (int or digit string). Floats, booleans and negatives are rejected.
	 *
	 * @param mixed  $value Value.
	 * @param string $label Field name for the error message.
	 * @return int
	 * @throws \InvalidArgumentException When invalid.
	 */
	public static function non_negative_int( $value, $label ) {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d{1,18}\z/', $value ) ) {
			return (int) $value;
		}
		throw new \InvalidArgumentException( sprintf( 'Invalid %s.', $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Label is a fixed string from this plugin.
	}

	/**
	 * Remove control characters (keeping newlines and tabs when allowed), trim and limit the length.
	 *
	 * @param mixed $value      Value.
	 * @param int   $max        Maximum length in characters.
	 * @param bool  $multi_line Keep \n and \t.
	 * @return string
	 */
	public static function clean_text( $value, $max, $multi_line = false ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$pattern = $multi_line ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
		$text    = preg_replace( $pattern, '', (string) $value );
		$text    = trim( null === $text ? '' : $text );

		return self::truncate( $text, $max );
	}

	/**
	 * Truncate to a number of characters (multibyte-safe when mbstring exists).
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	public static function truncate( $text, $max ) {
		$max = max( 0, (int) $max );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max, 'UTF-8' );
		}
		return substr( $text, 0, $max );
	}

	/**
	 * Whitelisted sort direction.
	 *
	 * @param mixed $order Requested direction.
	 * @return string "ASC" or "DESC" (default DESC).
	 */
	public static function direction( $order ) {
		return is_string( $order ) && 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
	}

	/**
	 * Keep a filter value only when it is one of the allowed values.
	 *
	 * @param mixed    $value   Value.
	 * @param string[] $allowed Allowed values.
	 * @return string|null
	 */
	public static function one_of( $value, array $allowed ) {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : null;
	}
}
