<?php
/**
 * Removes temp files; keeps local copy only if Local destination selected.
 *
 * Phase: MVP
 *
 * Purpose: Removes the work folder of a backup (<local_dir>/tmp/<uuid>) and nothing else.
 *
 * Responsibilities:
 *  - Only ever deletes a folder that is directly inside <local_dir>/tmp/ and is named like a uuid
 *    (is_safe_work_dir(), a pure check, plus a realpath comparison and a symlink refusal at runtime)
 *  - Never follows symlinks and never touches finished archives (they live in <local_dir>, not in tmp/)
 *  - Handles a missing folder, and is resumable: when the budget runs out it returns continue_step()
 *  - A refused or failed removal is logged as a warning and does not fail the backup
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Steps;

use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Manifest;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup step.
 */
class CleanupStep implements StepInterface {

	/**
	 * Entries deleted between two budget checks.
	 */
	const BATCH = 200;

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_CLEANUP;
	}

	/**
	 * Remove the work folder.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$done = self::remove_work_dir( $context->work_dir(), $context->budget(), $context );
		return $done ? StepResult::next_step( $context->state() ) : StepResult::continue_step( $context->state() );
	}

	/**
	 * Remove a work folder if it is safe to do so.
	 *
	 * @param string          $work_dir Work folder.
	 * @param Budget|null     $budget   Stop early when exhausted (the caller then continues later).
	 * @param JobContext|null $context  For logging.
	 * @return bool True when the folder is gone (or never existed, or was refused); false when more work remains.
	 */
	public static function remove_work_dir( $work_dir, ?Budget $budget = null, ?JobContext $context = null ) {
		try {
			$base = Paths::tmp_dir();
		} catch ( \RuntimeException $e ) {
			return true;
			// No local folder, so nothing of ours can be there.
		}

		$work_dir = rtrim( str_replace( '\\', '/', (string) $work_dir ), '/' );
		if ( '' === $work_dir || ( ! file_exists( $work_dir ) && ! is_link( $work_dir ) ) ) {
			return true;
		}

		$resolved = self::resolve( $work_dir, $base );
		if ( null === $resolved ) {
			if ( null !== $context ) {
				$context->log( Logger::WARNING, __( 'Cleanup refused to remove a folder outside the temporary backup folder.', 'openvault-backup' ) );
			}
			return true;
		}

		$complete = self::remove_tree( $resolved, $budget );
		if ( $complete && null !== $context ) {
			$context->log( Logger::DEBUG, __( 'Temporary files removed.', 'openvault-backup' ) );
		}
		return $complete;
	}

	/**
	 * Pure safety check: is $path a work folder directly inside $base?
	 *
	 * True only when: $base is a folder named "tmp", $path is $base + "/" + one uuid-named segment,
	 * neither contains "..", "." segments, NUL bytes or relative parts, and both are absolute.
	 * Backslashes are treated as separators.
	 *
	 * @param string $path Candidate work folder.
	 * @param string $base The tmp folder (<local_dir>/tmp).
	 * @return bool
	 */
	public static function is_safe_work_dir( $path, $base ) {
		if ( ! is_string( $path ) || ! is_string( $base ) || '' === $path || '' === $base ) {
			return false;
		}
		if ( false !== strpos( $path, "\0" ) || false !== strpos( $base, "\0" ) ) {
			return false;
		}

		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );

		if ( ! self::is_clean_absolute( $path ) || ! self::is_clean_absolute( $base ) ) {
			return false;
		}
		if ( 'tmp' !== basename( $base ) ) {
			return false;
		}
		if ( 0 !== strpos( $path, $base . '/' ) ) {
			return false;
		}

		$rest = substr( $path, strlen( $base ) + 1 );
		return false === strpos( $rest, '/' ) && Manifest::is_valid_uuid( $rest );
	}

	/**
	 * Delete a folder tree without following symlinks. Stops when the budget is exhausted.
	 *
	 * @param string      $dir    Folder (already validated by the caller).
	 * @param Budget|null $budget Budget.
	 * @return bool True when the folder is gone, false when more work remains.
	 */
	public static function remove_tree( $dir, ?Budget $budget = null ) {
		if ( is_link( $dir ) ) {
			return self::remove_link( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return ! file_exists( $dir ) || self::delete_file( $dir );
		}

		$count    = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isLink() ) {
				self::remove_link( $path );
			} elseif ( $item->isDir() ) {
				@rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Folder inside our own tmp folder.
			} else {
				self::delete_file( $path );
			}

			++$count;
			if ( null !== $budget && 0 === $count % self::BATCH && $budget->exhausted() ) {
				return false;
			}
		}

		return @rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Folder inside our own tmp folder.
	}

	/**
	 * Check the folder against the real filesystem and return its resolved path.
	 *
	 * @param string $work_dir Work folder.
	 * @param string $base     Tmp folder.
	 * @return string|null Null when the folder must not be removed.
	 */
	private static function resolve( $work_dir, $base ) {
		if ( is_link( $work_dir ) ) {
			return null;
			// A work folder is never a symlink.
		}
		$real_dir  = realpath( $work_dir );
		$real_base = realpath( $base );
		if ( false === $real_dir || false === $real_base ) {
			return null;
		}
		if ( ! self::is_safe_work_dir( $real_dir, $real_base ) ) {
			return null;
		}
		return $real_dir;
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

	/**
	 * Delete a file.
	 *
	 * @param string $path File.
	 * @return bool
	 */
	private static function delete_file( $path ) {
		return @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- File inside our own tmp folder.
	}

	/**
	 * Remove a symlink itself (never its target).
	 *
	 * @param string $path Link.
	 * @return bool
	 */
	private static function remove_link( $path ) {
		return @unlink( $path ) || @rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Removes the link, not the target.
	}
}
