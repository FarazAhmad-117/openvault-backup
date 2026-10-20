<?php
/**
 * Where the files of a finished local backup live, and how they are removed.
 *
 * Phase: MVP
 *
 * Purpose: One place that turns a database row into a path on disk, always from the row (uuid + creation time) and
 * never from user input, and that refuses anything that resolves outside <local dir>/backups/.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Backups;

use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Security\DownloadHelper;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Backup folders on disk.
 */
class BackupFiles {

	/**
	 * Pattern of a backup folder name: <YYYY-MM-DD_HHMMSS>_<uuid8>.
	 */
	const FOLDER_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{8}\z/';

	/**
	 * Folder of a backup below $backups_dir (not checked on disk).
	 *
	 * @param string $backups_dir Absolute <local dir>/backups.
	 * @param string $created_at  Creation time of the row (Y-m-d H:i:s, UTC).
	 * @param string $uuid        Backup uuid.
	 * @return string|null Null for an invalid time or uuid.
	 */
	public static function folder_path( $backups_dir, $created_at, $uuid ) {
		try {
			$name = Paths::build_backup_folder_name( (string) $created_at, (string) $uuid );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
		return rtrim( str_replace( '\\', '/', (string) $backups_dir ), '/' ) . '/' . $name;
	}

	/**
	 * Real path of a stored file of a backup, or null when it must not be served.
	 *
	 * The file name comes from the database row, but it is still checked: a plain name, a regular file (no
	 * symlink), and its resolved path must be inside the resolved backup folder, which in turn must be inside the
	 * resolved backups folder.
	 *
	 * @param string $backups_dir Absolute <local dir>/backups.
	 * @param string $created_at  Row creation time.
	 * @param string $uuid        Row uuid.
	 * @param string $file_name   Stored file name.
	 * @return string|null
	 */
	public static function resolve_file( $backups_dir, $created_at, $uuid, $file_name ) {
		$folder = self::resolve_folder( $backups_dir, $created_at, $uuid );
		if ( null === $folder || ! is_string( $file_name ) || '' === $file_name || basename( str_replace( '\\', '/', $file_name ) ) !== $file_name || 1 === preg_match( '/[\x00-\x1f\x7f]/', $file_name ) ) {
			return null;
		}

		$candidate = $folder . '/' . $file_name;
		if ( is_link( $candidate ) || ! is_file( $candidate ) ) {
			return null;
		}
		$real = realpath( $candidate );
		if ( false === $real ) {
			return null;
		}
		$real = str_replace( '\\', '/', $real );
		return DownloadHelper::is_inside( $real, $folder ) ? $real : null;
	}

	/**
	 * Real path of a backup folder when it exists and is a proper folder inside $backups_dir.
	 *
	 * @param string $backups_dir Absolute <local dir>/backups.
	 * @param string $created_at  Row creation time.
	 * @param string $uuid        Row uuid.
	 * @return string|null
	 */
	public static function resolve_folder( $backups_dir, $created_at, $uuid ) {
		$path = self::folder_path( $backups_dir, $created_at, $uuid );
		if ( null === $path || is_link( $path ) || ! is_dir( $path ) ) {
			return null;
		}

		$real_base = realpath( (string) $backups_dir );
		$real      = realpath( $path );
		if ( false === $real_base || false === $real ) {
			return null;
		}
		$real      = str_replace( '\\', '/', $real );
		$real_base = str_replace( '\\', '/', $real_base );

		if ( ! DownloadHelper::is_inside( $real, $real_base ) || 1 !== preg_match( self::FOLDER_PATTERN, basename( $real ) ) || dirname( $real ) !== rtrim( $real_base, '/' ) ) {
			return null;
		}
		return $real;
	}

	/**
	 * Remove the folder of a backup (manifest and parts). A folder that does not exist counts as removed.
	 *
	 * @param string $backups_dir Absolute <local dir>/backups.
	 * @param string $created_at  Row creation time.
	 * @param string $uuid        Row uuid.
	 * @return bool False when the folder exists but is refused or could not be removed completely.
	 */
	public static function delete_folder( $backups_dir, $created_at, $uuid ) {
		$path = self::folder_path( $backups_dir, $created_at, $uuid );
		if ( null === $path ) {
			return false;
		}
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}

		$real = self::resolve_folder( $backups_dir, $created_at, $uuid );
		if ( null === $real ) {
			return false;
		}
		return CleanupStep::remove_tree( $real, null );
	}
}
