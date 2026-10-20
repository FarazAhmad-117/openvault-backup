<?php
/**
 * Builds remote paths (pure logic).
 *
 * Phase: MVP
 *
 * Purpose: One strict place that turns the site host, the backup folder name and a file name into
 * OpenVault-Backups/<site-host>/<YYYY-MM-DD_HHMMSS>_<uuid8>/<file name>. Nothing user supplied reaches a remote
 * path without passing through here.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Remote path helper.
 */
final class RemotePath {

	const ROOT          = 'OpenVault-Backups';
	const MANIFEST      = 'manifest.json';
	const FOLDER_FORMAT = '/^\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{8}\z/';
	const MAX_LENGTH    = 1024;

	/**
	 * Folder name of a site: the host (and port) lower-cased, only letters, digits, "." and "-".
	 *
	 * @param string $url_or_host Site URL or host name.
	 * @return string Never empty ("site" when nothing usable remains).
	 */
	public static function site_host( $url_or_host ) {
		$value = strtolower( trim( (string) $url_or_host ) );
		if ( false !== strpos( $value, '://' ) ) {
			$host = '';
			$port = '';
			$info = parse_url( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, runs without WordPress in unit tests; only host and port are read.
			if ( is_array( $info ) ) {
				$host = isset( $info['host'] ) ? (string) $info['host'] : '';
				$port = isset( $info['port'] ) ? '-' . (int) $info['port'] : '';
			}
			$value = $host . $port;
		} else {
			$value = (string) preg_replace( '/[\/?#].*$/', '', $value );
			$value = str_replace( ':', '-', $value );
		}

		$value = (string) preg_replace( '/[^a-z0-9.\-]+/', '-', $value );
		$value = (string) preg_replace( '/-{2,}/', '-', $value );
		$value = trim( $value, '.-' );
		$value = (string) preg_replace( '/\.{2,}/', '.', $value );
		$value = trim( substr( $value, 0, 100 ), '.-' );
		return '' === $value ? 'site' : $value;
	}

	/**
	 * Whether a name is a valid single path segment (letters, digits, ".", "-", "_"; no leading dot; no "..").
	 *
	 * @param mixed $name Name.
	 * @return bool
	 */
	public static function is_segment( $name ) {
		return is_string( $name )
			&& 1 === preg_match( '/^[A-Za-z0-9_\-][A-Za-z0-9._\-]{0,254}\z/', $name )
			&& false === strpos( $name, '..' );
	}

	/**
	 * Whether a backup folder name has the form <YYYY-MM-DD_HHMMSS>_<uuid8>.
	 *
	 * @param mixed $folder Folder name.
	 * @return bool
	 */
	public static function is_backup_folder( $folder ) {
		return is_string( $folder ) && 1 === preg_match( self::FOLDER_FORMAT, $folder );
	}

	/**
	 * OpenVault-Backups/<site-host>/<folder> (no trailing slash).
	 *
	 * @param string $url_or_host Site URL or host.
	 * @param string $folder      Backup folder name.
	 * @return string
	 * @throws \InvalidArgumentException For a folder name of the wrong form.
	 */
	public static function folder( $url_or_host, $folder ) {
		if ( ! self::is_backup_folder( $folder ) ) {
			throw new \InvalidArgumentException( 'Invalid backup folder name.' );
		}
		return self::ROOT . '/' . self::site_host( $url_or_host ) . '/' . $folder;
	}

	/**
	 * Full remote path of one file.
	 *
	 * @param string $url_or_host Site URL or host.
	 * @param string $folder      Backup folder name.
	 * @param string $file_name   File name (one segment).
	 * @return string
	 * @throws \InvalidArgumentException For an invalid folder or file name.
	 */
	public static function file( $url_or_host, $folder, $file_name ) {
		if ( ! self::is_segment( $file_name ) ) {
			throw new \InvalidArgumentException( 'Invalid file name.' );
		}
		return self::folder( $url_or_host, $folder ) . '/' . $file_name;
	}

	/**
	 * Whether a relative remote path or reference is safe to use: forward slashes only, no empty, "." or ".."
	 * segment, no leading slash, no drive letter, no NUL or control character, not too long.
	 *
	 * @param mixed $path Path.
	 * @return bool
	 */
	public static function is_safe( $path ) {
		if ( ! is_string( $path ) || '' === $path || strlen( $path ) > self::MAX_LENGTH ) {
			return false;
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f\\\\]/', $path ) || 1 === preg_match( '/^[A-Za-z]:/', $path ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}
}
