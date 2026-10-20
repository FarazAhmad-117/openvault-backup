<?php
/**
 * Names of entries inside an archive.
 *
 * Phase: MVP
 *
 * Purpose: Pure rules for the archive-internal path of a file (the contract the restore relies on, see
 * docs/plan/04-backup-engine.md): "abspath/<path relative to ABSPATH>", "content/<path relative to WP_CONTENT_DIR>"
 * and "db/db.sql". Always forward slashes, UTF-8, no leading slash, no "." or ".." or empty segments,
 * no control characters and no backslashes.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Entry name rules.
 */
final class EntryName {

	const MAX_BYTES = 4000;

	const DB_ENTRY = 'db/db.sql';

	/**
	 * Whether a name may be used inside an archive.
	 *
	 * @param mixed $name Name.
	 * @return bool
	 */
	public static function is_safe( $name ) {
		if ( ! is_string( $name ) || '' === $name || strlen( $name ) > self::MAX_BYTES ) {
			return false;
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f\\\\]/', $name ) || 1 !== preg_match( '//u', $name ) ) {
			return false;
		}
		if ( '/' === $name[0] || '/' === substr( $name, -1 ) || 1 === preg_match( '#^[A-Za-z]:#', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Join an entry prefix and a path below it. Returns null when the result would not be a safe name
	 * (so a path can never climb out of its root).
	 *
	 * @param string $prefix   Entry prefix such as "content/plugins" (safe name).
	 * @param string $relative Path below the prefix ('' for the prefix itself).
	 * @return string|null
	 */
	public static function join( $prefix, $relative ) {
		$name = '' === $relative ? $prefix : $prefix . '/' . $relative;
		return self::is_safe( $name ) ? $name : null;
	}

	/**
	 * Last segment of a name.
	 *
	 * @param string $name Entry name.
	 * @return string
	 */
	public static function basename( $name ) {
		$position = strrpos( $name, '/' );
		return false === $position ? $name : substr( $name, $position + 1 );
	}

	/**
	 * Convert a path to forward slashes and drop trailing slashes (a lone root stays "/").
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalise_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$trim = rtrim( $path, '/' );
		return '' === $trim && '' !== $path ? '/' : $trim;
	}
}
