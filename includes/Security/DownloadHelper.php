<?php
/**
 * Pure helpers of the download endpoint (no WordPress needed, unit tested).
 *
 * Phase: MVP
 *
 * Purpose: HTTP Range parsing and download file names, kept apart from DownloadController so they can be tested
 * with hostile input.
 *
 * Range policy (RFC 7233): only a single "bytes" range is served (206). A header that is malformed, uses another
 * unit or asks for several ranges is ignored and the whole file is sent (200), which the RFC allows. A well-formed
 * single range that starts beyond the end of the file is answered with 416.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Download helpers.
 */
final class DownloadHelper {

	const RANGE_FULL          = 'full';
	const RANGE_PARTIAL       = 'partial';
	const RANGE_UNSATISFIABLE = 'unsatisfiable';

	/**
	 * Decide what to send for a Range header.
	 *
	 * @param mixed $header Raw Range header value (or null/empty when absent).
	 * @param int   $size   File size in bytes.
	 * @return array{type:string,start:int,end:int} start and end are inclusive byte offsets (0 for full/unsatisfiable).
	 */
	public static function parse_range( $header, $size ) {
		$size = max( 0, (int) $size );
		$full = array(
			'type'  => self::RANGE_FULL,
			'start' => 0,
			'end'   => max( 0, $size - 1 ),
		);

		if ( ! is_string( $header ) || '' === $header || $size < 1 || strlen( $header ) > 100 ) {
			return $full;
		}
		if ( 1 !== preg_match( '/^bytes=(\d{0,18})-(\d{0,18})\z/', trim( $header, " \t" ), $found ) ) {
			return $full;
			// Other unit, several ranges, garbage or absurd numbers: ignore the header.
		}

		$first = $found[1];
		$last  = $found[2];

		if ( '' === $first && '' === $last ) {
			return $full;
		}

		if ( '' === $first ) {
			// Suffix range: the last N bytes.
			$length = (int) $last;
			if ( $length < 1 ) {
				return self::unsatisfiable();
			}
			return array(
				'type'  => self::RANGE_PARTIAL,
				'start' => max( 0, $size - $length ),
				'end'   => $size - 1,
			);
		}

		$start = (int) $first;
		$end   = '' === $last ? $size - 1 : (int) $last;
		if ( '' !== $last && $end < $start ) {
			return $full;
			// "bytes=5-2" is invalid syntax, so it is ignored.
		}
		if ( $start >= $size ) {
			return self::unsatisfiable();
		}

		return array(
			'type'  => self::RANGE_PARTIAL,
			'start' => $start,
			'end'   => min( $end, $size - 1 ),
		);
	}

	/**
	 * A file name that is safe in a Content-Disposition header: no path, no control characters, no quotes.
	 *
	 * @param mixed $name Candidate name.
	 * @return string Never empty.
	 */
	public static function sanitize_filename( $name ) {
		$name = is_string( $name ) ? $name : '';
		$name = str_replace( '\\', '/', $name );
		$name = substr( $name, (int) strrpos( $name, '/' ) );
		$name = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name );
		$name = ltrim( str_replace( '..', '_', $name ), '._' );
		$name = substr( $name, 0, 150 );

		return '' === $name ? 'backup' : $name;
	}

	/**
	 * Content type of a download.
	 *
	 * @param string $file_name Stored file name.
	 * @return string
	 */
	public static function content_type( $file_name ) {
		return 'manifest.json' === $file_name ? 'application/json' : 'application/octet-stream';
	}

	/**
	 * Whether $path lies strictly inside $base. Both are compared as given (resolve them with realpath() first).
	 * Backslashes count as separators; NUL bytes, relative segments and equal paths are refused.
	 *
	 * @param mixed $path Candidate path.
	 * @param mixed $base Folder it must be inside.
	 * @return bool
	 */
	public static function is_inside( $path, $base ) {
		if ( ! is_string( $path ) || ! is_string( $base ) || '' === $path || '' === $base ) {
			return false;
		}
		if ( false !== strpos( $path, "\0" ) || false !== strpos( $base, "\0" ) ) {
			return false;
		}

		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );
		if ( '' === $base || 0 !== strpos( $path, $base . '/' ) ) {
			return false;
		}

		foreach ( explode( '/', substr( $path, strlen( $base ) + 1 ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Result for an unsatisfiable range.
	 *
	 * @return array{type:string,start:int,end:int}
	 */
	private static function unsatisfiable() {
		return array(
			'type'  => self::RANGE_UNSATISFIABLE,
			'start' => 0,
			'end'   => 0,
		);
	}
}
