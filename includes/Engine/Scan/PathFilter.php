<?php
/**
 * What the file scan leaves out.
 *
 * Phase: MVP
 *
 * Purpose: Pure decision for one directory entry: archive it or skip it, and why.
 *
 * Responsibilities:
 *  - Never follow or archive symlinks (no loops, no escape from the backed-up roots)
 *  - Always skip the plugin's own local backup folder and ".git" folders or files
 *  - Honour user patterns (see normalise_patterns()), matched against the entry name ("content/uploads/a/b.log"):
 *    a pattern without "/" matches the last segment of any entry ("*.log", "node_modules"); a pattern with "/"
 *    matches the whole entry name and, for a folder, the folder with a trailing "/" so that "*" + "/node_modules/*"
 *    prunes the folder. "*" matches any characters including "/", "?" any single character except "/".
 *  - Skip files larger than the size cap (0 = unlimited)
 *  - Skip names that cannot be stored safely (see EntryName::is_safe())
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Path filter.
 */
final class PathFilter {

	const MAX_PATTERNS       = 200;
	const MAX_PATTERN_LENGTH = 255;

	const INCLUDE_ENTRY = 'include';
	const SYMLINK       = 'symlink';
	const EXCLUDED      = 'excluded';
	const OVERSIZE      = 'oversize';
	const BADNAME       = 'badname';
	const OTHER_ROOT    = 'other_root';

	/**
	 * Absolute folders that are never entered (normalised).
	 *
	 * @var string[]
	 */
	private $always_excluded;

	/**
	 * Absolute folders that belong to another root (normalised): skipped without being counted.
	 *
	 * @var string[]
	 */
	private $other_roots;

	/**
	 * Compiled patterns: regex and whether it matches the last segment only.
	 *
	 * @var array<int,array{regex:string,base:bool}>
	 */
	private $patterns = array();

	/**
	 * Size cap in bytes (0 = unlimited).
	 *
	 * @var int
	 */
	private $max_bytes;

	/**
	 * Constructor.
	 *
	 * @param string[] $always_excluded Absolute folders that are never entered (the local backup folder).
	 * @param mixed    $patterns        Raw user patterns (validated here).
	 * @param int      $max_bytes       Largest file to archive in bytes, 0 for no limit.
	 * @param string[] $other_roots     Absolute folders that another root archives (not counted as excluded).
	 */
	public function __construct( array $always_excluded = array(), $patterns = array(), $max_bytes = 0, array $other_roots = array() ) {
		$this->always_excluded = array_map( array( EntryName::class, 'normalise_path' ), $always_excluded );
		$this->other_roots     = array_map( array( EntryName::class, 'normalise_path' ), $other_roots );
		$this->max_bytes       = max( 0, (int) $max_bytes );
		foreach ( self::normalise_patterns( $patterns ) as $pattern ) {
			$base             = false === strpos( $pattern, '/' );
			$this->patterns[] = array(
				'regex' => '/^' . str_replace( array( '\\*', '\\?' ), array( '.*', '[^\/]' ), preg_quote( $pattern, '/' ) ) . '$/Dsu',
				'base'  => $base,
			);
		}
	}

	/**
	 * Keep only valid patterns. A pattern is valid UTF-8 text of 1 to 255 bytes without control characters and
	 * without a ".." segment, with at least one character besides "*" and "?" (so a bare "*" cannot exclude
	 * everything). Backslashes become "/", a leading "/" is dropped, duplicates are removed and at most 200 are kept.
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
			$item = ltrim( str_replace( '\\', '/', trim( $item ) ), '/' );
			if ( '' === $item || strlen( $item ) > self::MAX_PATTERN_LENGTH ) {
				continue;
			}
			if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $item ) || 1 !== preg_match( '//u', $item ) ) {
				continue;
			}
			if ( '' === str_replace( array( '*', '?', '/' ), '', $item ) || in_array( '..', explode( '/', $item ), true ) ) {
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
	 * Largest file that is archived, in bytes (0 = unlimited).
	 *
	 * @param mixed $megabytes Setting max_file_size_mb.
	 * @return int
	 */
	public static function max_bytes_from_setting( $megabytes ) {
		if ( ! is_numeric( $megabytes ) || (float) $megabytes <= 0 ) {
			return 0;
		}
		return (int) min( (float) $megabytes, 1048576 ) * 1048576;
	}

	/**
	 * Decide what to do with one entry.
	 *
	 * @param string $absolute Absolute path (forward slashes).
	 * @param string $entry    Entry name (see EntryName).
	 * @param bool   $is_dir   Whether it is a real directory (not a link to one).
	 * @param bool   $is_link  Whether it is a symbolic link.
	 * @param int    $size     Size in bytes (files only).
	 * @return string One of the class constants.
	 */
	public function decide( $absolute, $entry, $is_dir, $is_link, $size = 0 ) {
		if ( $is_link ) {
			return self::SYMLINK;
		}
		if ( ! EntryName::is_safe( $entry ) ) {
			return self::BADNAME;
		}
		if ( $is_dir && in_array( EntryName::normalise_path( $absolute ), $this->always_excluded, true ) ) {
			return self::EXCLUDED;
		}
		if ( $is_dir && in_array( EntryName::normalise_path( $absolute ), $this->other_roots, true ) ) {
			return self::OTHER_ROOT;
		}
		if ( '.git' === EntryName::basename( $entry ) || $this->matches( $entry, $is_dir ) ) {
			return self::EXCLUDED;
		}
		if ( ! $is_dir && $this->max_bytes > 0 && $size > $this->max_bytes ) {
			return self::OVERSIZE;
		}
		return self::INCLUDE_ENTRY;
	}

	/**
	 * Whether a user pattern matches an entry.
	 *
	 * @param string $entry  Entry name.
	 * @param bool   $is_dir Folder (also tested with a trailing slash).
	 * @return bool
	 */
	public function matches( $entry, $is_dir ) {
		$base = EntryName::basename( $entry );
		foreach ( $this->patterns as $pattern ) {
			if ( $pattern['base'] ) {
				if ( 1 === preg_match( $pattern['regex'], $base ) ) {
					return true;
				}
				continue;
			}
			if ( 1 === preg_match( $pattern['regex'], $entry ) || ( $is_dir && 1 === preg_match( $pattern['regex'], $entry . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
