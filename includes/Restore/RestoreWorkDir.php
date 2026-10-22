<?php
/**
 * Work folder of one restore job: <local dir>/tmp/restore-<id>/.
 *
 * Phase: MVP
 *
 * Purpose: One place that knows where a restore keeps its downloaded parts, builds file paths inside it without trusting
 * names, and removes it again.
 *
 * Rules:
 *  - The folder is named restore-<restore id> and sits directly inside <local dir>/tmp/ (next to the uuid-named work folders of
 *    backups). Removal only ever deletes such a folder, never the tmp folder itself or anything else in it
 *    (is_safe(), a pure check, plus a realpath comparison and a symlink refusal at runtime).
 *  - The folder is created with the same guard files as every protected plugin folder.
 *  - join() accepts a plain file name only (no separators, no "..", no control characters).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Restore work folder.
 */
final class RestoreWorkDir {

	const PREFIX = 'restore-';

	/**
	 * Name of the folder of a restore.
	 *
	 * @param int $restore_id Restore id (>= 1).
	 * @return string
	 * @throws \InvalidArgumentException For an invalid id.
	 */
	public static function folder_name( $restore_id ) {
		if ( ! is_int( $restore_id ) && ! ( is_string( $restore_id ) && ctype_digit( $restore_id ) ) ) {
			throw new \InvalidArgumentException( 'Invalid restore id.' );
		}
		$restore_id = (int) $restore_id;
		if ( $restore_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid restore id.' );
		}
		return self::PREFIX . $restore_id;
	}

	/**
	 * Folder of a restore below a tmp folder (not checked on disk).
	 *
	 * @param string $tmp_dir    Absolute <local dir>/tmp.
	 * @param int    $restore_id Restore id.
	 * @return string
	 * @throws \InvalidArgumentException For an invalid id.
	 */
	public static function build( $tmp_dir, $restore_id ) {
		return rtrim( str_replace( '\\', '/', (string) $tmp_dir ), '/' ) . '/' . self::folder_name( $restore_id );
	}

	/**
	 * Pure safety check: is $path a restore folder directly inside $base?
	 *
	 * True only when $base is a folder named "tmp", $path is $base + "/" + restore-<digits>, both are absolute and neither
	 * contains ".", ".." or empty segments or NUL bytes. Backslashes count as separators.
	 *
	 * @param mixed $path Candidate folder.
	 * @param mixed $base The tmp folder.
	 * @return bool
	 */
	public static function is_safe( $path, $base ) {
		if ( ! is_string( $path ) || ! is_string( $base ) || '' === $path || '' === $base ) {
			return false;
		}
		if ( false !== strpos( $path, "\0" ) || false !== strpos( $base, "\0" ) ) {
			return false;
		}

		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );

		if ( ! self::is_clean_absolute( $path ) || ! self::is_clean_absolute( $base ) || 'tmp' !== basename( $base ) ) {
			return false;
		}
		if ( 0 !== strpos( $path, $base . '/' ) ) {
			return false;
		}

		$rest = substr( $path, strlen( $base ) + 1 );
		return false === strpos( $rest, '/' ) && 1 === preg_match( '/^restore-[1-9][0-9]{0,17}\z/', $rest );
	}

	/**
	 * Path of a file inside a work folder. Only a plain file name is accepted.
	 *
	 * @param string $dir  Work folder.
	 * @param string $name File name.
	 * @return string
	 * @throws \InvalidArgumentException For an unsafe name.
	 */
	public static function join( $dir, $name ) {
		if ( ! BackupFilesRepository::validate_file_name( $name ) ) {
			throw new \InvalidArgumentException( 'Unsafe file name.' );
		}
		return rtrim( str_replace( '\\', '/', (string) $dir ), '/' ) . '/' . $name;
	}

	/**
	 * Absolute path of the folder of a restore (not created).
	 *
	 * @param int $restore_id Restore id.
	 * @return string
	 * @throws \InvalidArgumentException For an invalid id.
	 * @throws \RuntimeException         When the temporary folder cannot be created.
	 */
	public static function path( $restore_id ) {
		return self::build( Paths::tmp_dir(), $restore_id );
	}

	/**
	 * Create the folder with its guard files.
	 *
	 * @param int $restore_id Restore id.
	 * @return string The folder.
	 * @throws \RuntimeException When the folder cannot be created.
	 */
	public static function ensure( $restore_id ) {
		$dir = self::path( $restore_id );
		if ( ! Paths::ensure_protected_dir( $dir ) ) {
			throw new \RuntimeException( 'The restore work folder could not be created. Check that wp-content is writable.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed message.
		}
		return $dir;
	}

	/**
	 * Remove the folder of a restore if it is safe to do so.
	 *
	 * @param int         $restore_id Restore id.
	 * @param Budget|null $budget     Stop early when exhausted (the caller then continues later).
	 * @return bool True when the folder is gone (or never existed or was refused); false when more work remains.
	 */
	public static function remove( $restore_id, ?Budget $budget = null ) {
		try {
			$base = Paths::tmp_dir();
			$dir  = self::build( $base, $restore_id );
		} catch ( \Exception $e ) {
			return true;
			// No folder, so nothing of ours can be there.
		}

		if ( ! file_exists( $dir ) && ! is_link( $dir ) ) {
			return true;
		}
		if ( is_link( $dir ) ) {
			return CleanupStep::remove_tree( $dir );
			// Removes the link itself, never its target.
		}

		$real_dir  = realpath( $dir );
		$real_base = realpath( $base );
		if ( false === $real_dir || false === $real_base || ! self::is_safe( $real_dir, $real_base ) ) {
			return true;
		}
		return CleanupStep::remove_tree( $real_dir, $budget );
	}

	/**
	 * Absolute path without ".", ".." or empty segments.
	 *
	 * @param string $path Path with forward slashes.
	 * @return bool
	 */
	private static function is_clean_absolute( $path ) {
		$absolute = ( 0 === strpos( $path, '/' ) ) || 1 === preg_match( '#^[A-Za-z]:/#', $path );
		if ( ! $absolute ) {
			return false;
		}
		foreach ( explode( '/', ltrim( $path, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}
}
