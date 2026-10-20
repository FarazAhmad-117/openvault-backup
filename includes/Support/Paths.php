<?php
/**
 * Filesystem locations used by the plugin.
 *
 * Phase: MVP
 *
 * Purpose: One place that knows where backups and temporary files live, so no other code
 * builds paths by hand and no user input ever ends up in a path.
 *
 * Layout:
 *   WP_CONTENT_DIR/<ovb_local_dir>/           protected local backup folder (random name, option ovb_local_dir)
 *   WP_CONTENT_DIR/<ovb_local_dir>/tmp/       temporary files
 *   WP_CONTENT_DIR/<ovb_local_dir>/tmp/<uuid> work directory of one backup job
 *   WP_CONTENT_DIR/<ovb_local_dir>/backups/   finished backups
 *   WP_CONTENT_DIR/<ovb_local_dir>/backups/<YYYY-MM-DD_HHMMSS>_<uuid8>/  one backup: manifest.json and its parts
 *
 * The pure helpers (build_*, is_valid_dir_name, guard_files) work without WordPress.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Paths.
 */
class Paths {

	const OPTION_LOCAL_DIR = 'ovb_local_dir';
	const TMP_NAME         = 'tmp';
	const BACKUPS_NAME     = 'backups';

	/**
	 * Files that stop directory listing and web access to a protected folder.
	 *
	 * @return array<string,string> File name => contents.
	 */
	public static function guard_files() {
		return array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
	}

	/**
	 * Whether a folder name is acceptable: letters, digits, dot, dash and underscore, no leading dot, no path separator.
	 *
	 * @param mixed $name Name.
	 * @return bool
	 */
	public static function is_valid_dir_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,99}$/', $name ) && false === strpos( $name, '..' );
	}

	/**
	 * Join the content directory and the local folder name.
	 *
	 * @param string $content_dir Absolute wp-content path.
	 * @param string $name        Folder name.
	 * @return string Absolute path without trailing slash.
	 * @throws \InvalidArgumentException For an invalid folder name.
	 */
	public static function build_local_dir( $content_dir, $name ) {
		if ( ! self::is_valid_dir_name( $name ) ) {
			throw new \InvalidArgumentException( 'Invalid local backup folder name.' );
		}
		return rtrim( str_replace( '\\', '/', (string) $content_dir ), '/' ) . '/' . $name;
	}

	/**
	 * Temporary folder inside a local backup folder.
	 *
	 * @param string $local_dir Absolute local folder.
	 * @return string
	 */
	public static function build_tmp_dir( $local_dir ) {
		return rtrim( str_replace( '\\', '/', (string) $local_dir ), '/' ) . '/' . self::TMP_NAME;
	}

	/**
	 * Work folder of one backup inside a temporary folder.
	 *
	 * @param string $tmp_dir Absolute temporary folder.
	 * @param string $uuid    Backup uuid (validated).
	 * @return string
	 * @throws \InvalidArgumentException For an invalid uuid.
	 */
	public static function build_work_dir( $tmp_dir, $uuid ) {
		if ( ! Manifest::is_valid_uuid( $uuid ) ) {
			throw new \InvalidArgumentException( 'Invalid backup uuid.' );
		}
		return rtrim( str_replace( '\\', '/', (string) $tmp_dir ), '/' ) . '/' . strtolower( $uuid );
	}

	/**
	 * Folder that holds finished backups inside a local backup folder.
	 *
	 * @param string $local_dir Absolute local folder.
	 * @return string
	 */
	public static function build_backups_dir( $local_dir ) {
		return rtrim( str_replace( '\\', '/', (string) $local_dir ), '/' ) . '/' . self::BACKUPS_NAME;
	}

	/**
	 * Name of the folder of one backup: <YYYY-MM-DD_HHMMSS>_<first 8 characters of the uuid> (UTC).
	 *
	 * @param string $created_at Creation time as stored in the database (Y-m-d H:i:s, UTC).
	 * @param string $uuid       Backup uuid (validated).
	 * @return string
	 * @throws \InvalidArgumentException For an invalid time or uuid.
	 */
	public static function build_backup_folder_name( $created_at, $uuid ) {
		if ( ! Manifest::is_valid_uuid( $uuid ) ) {
			throw new \InvalidArgumentException( 'Invalid backup uuid.' );
		}
		if ( ! is_string( $created_at ) || 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):(\d{2})\z/', $created_at, $found ) ) {
			throw new \InvalidArgumentException( 'Invalid backup time.' );
		}
		return $found[1] . '_' . $found[2] . $found[3] . $found[4] . '_' . substr( strtolower( $uuid ), 0, 8 );
	}

	/**
	 * Absolute path of the local backup folder. It is created and protected when missing, and its name
	 * is generated on first use.
	 *
	 * @return string Absolute path without trailing slash.
	 * @throws \RuntimeException When the stored folder name is invalid or the folder cannot be created.
	 */
	public static function local_dir() {
		$name = get_option( self::OPTION_LOCAL_DIR );
		if ( ! $name ) {
			$name = 'ovb-' . strtolower( wp_generate_password( 16, false, false ) );
			add_option( self::OPTION_LOCAL_DIR, $name );
			$name = get_option( self::OPTION_LOCAL_DIR, $name );
		}

		try {
			$dir = self::build_local_dir( WP_CONTENT_DIR, (string) $name );
		} catch ( \InvalidArgumentException $e ) {
			throw new \RuntimeException( 'The local backup folder name is invalid. Deactivate and reactivate the plugin to create a new one.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed message.
		}

		if ( ! self::ensure_protected_dir( $dir ) ) {
			throw new \RuntimeException( 'The local backup folder could not be created. Check that wp-content is writable.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed message.
		}
		return $dir;
	}

	/**
	 * Temporary folder (created and protected when missing).
	 *
	 * @return string
	 * @throws \RuntimeException When it cannot be created.
	 */
	public static function tmp_dir() {
		$dir = self::build_tmp_dir( self::local_dir() );
		if ( ! self::ensure_protected_dir( $dir ) ) {
			throw new \RuntimeException( 'The temporary backup folder could not be created.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed message.
		}
		return $dir;
	}

	/**
	 * Folder that holds finished backups (created and protected when missing).
	 *
	 * @return string
	 * @throws \RuntimeException When it cannot be created.
	 */
	public static function backups_dir() {
		$dir = self::build_backups_dir( self::local_dir() );
		if ( ! self::ensure_protected_dir( $dir ) ) {
			throw new \RuntimeException( 'The backups folder could not be created.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed message.
		}
		return $dir;
	}

	/**
	 * Folder of one finished backup (created and protected when missing).
	 *
	 * @param string $created_at Creation time of the backup row (Y-m-d H:i:s, UTC).
	 * @param string $uuid       Backup uuid.
	 * @return string
	 * @throws \RuntimeException When the time or uuid is invalid or the folder cannot be created.
	 */
	public static function backup_dir( $created_at, $uuid ) {
		try {
			$dir = self::backups_dir() . '/' . self::build_backup_folder_name( $created_at, $uuid );
		} catch ( \InvalidArgumentException $e ) {
			throw new \RuntimeException( 'The backup has an invalid time or uuid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed message.
		}
		if ( ! self::ensure_protected_dir( $dir ) ) {
			throw new \RuntimeException( 'The folder of the backup could not be created.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed message.
		}
		return $dir;
	}

	/**
	 * Work folder of one backup. The folder itself is not created (see ensure_work_dir()).
	 *
	 * @param string $uuid Backup uuid.
	 * @return string
	 * @throws \InvalidArgumentException For an invalid uuid.
	 * @throws \RuntimeException         When the temporary folder cannot be created.
	 */
	public static function work_dir( $uuid ) {
		return self::build_work_dir( self::tmp_dir(), $uuid );
	}

	/**
	 * Create a work folder.
	 *
	 * @param string $uuid Backup uuid.
	 * @return bool
	 */
	public static function ensure_work_dir( $uuid ) {
		try {
			$dir = self::work_dir( $uuid );
		} catch ( \Exception $e ) {
			return false;
		}
		return is_dir( $dir ) || wp_mkdir_p( $dir );
	}

	/**
	 * Create a folder with guard files that stop directory listing and web access.
	 *
	 * @param string $dir Absolute path.
	 * @return bool Whether the folder exists afterwards.
	 */
	public static function ensure_protected_dir( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		foreach ( self::guard_files() as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny static guard files.
			}
		}
		return true;
	}
}
