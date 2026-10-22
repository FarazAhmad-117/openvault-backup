<?php
/**
 * Read-only file system questions of the safe writer (replaceable in tests).
 *
 * Phase: MVP
 *
 * Purpose: Restore\SafeWriter asks these four questions before it writes anywhere. The real implementation uses PHP's file functions
 * without any cache; a test passes a subclass that claims a path is a symlink without creating one.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * File system probe.
 */
class FsProbe {

	/**
	 * Whether the path itself is a symlink (the link, not its target).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_link( $path ) {
		clearstatcache( true, $path );
		return is_link( $path );
	}

	/**
	 * Whether the path is a directory (a symlink to a directory counts as one here; callers check is_link() first).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_dir( $path ) {
		clearstatcache( true, $path );
		return is_dir( $path );
	}

	/**
	 * Whether the path is a regular file.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_file( $path ) {
		clearstatcache( true, $path );
		return is_file( $path );
	}

	/**
	 * Whether anything exists at the path (a dangling symlink counts).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		clearstatcache( true, $path );
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * Resolved real path or false.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	public function realpath( $path ) {
		clearstatcache( true, $path );
		return realpath( $path );
	}
}
