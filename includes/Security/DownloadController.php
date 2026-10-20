<?php
/**
 * Authenticated download of a finished backup file.
 *
 * Phase: MVP
 *
 * Purpose: Streams backup downloads through an authenticated endpoint (capability + nonce), never public URLs.
 *
 * Contract:
 *  - URL: admin-post.php?action=ovb_download&backup=<backup id>&file=<backup file id>&_wpnonce=<nonce>, built only by
 *    url(). The nonce action is "ovb_download_<file id>".
 *  - Anonymous: 403. Missing capability or bad nonce: 403. Unknown ids, a file of another backup, a remote copy
 *    (destination other than 0), an unfinished or failed backup, a missing file: 404. Bodies are one short line.
 *  - The path is built from the database row (uuid + creation time + stored file name) by Backups\BackupFiles, never
 *    from request input, and must resolve inside <local dir>/backups/<folder>.
 *  - Sends attachment headers, Content-Length, nosniff and no-cache headers, supports one HTTP Range (206/416, see
 *    DownloadHelper), clears output buffers, streams 1 MB chunks and stops when the client disconnects. The file is
 *    never read into memory.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Security;

use OpenVault\Backup\Backups\BackupFiles;
use OpenVault\Backup\Core\Capabilities;
use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Download endpoint.
 */
class DownloadController {

	const ACTION     = 'ovb_download';
	const CHUNK_SIZE = 1048576;

	/**
	 * Hook the endpoint (admin-post.php, logged in and anonymous).
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Download URL of one stored file, with its nonce.
	 *
	 * @param int $backup_id Backup id.
	 * @param int $file_id   Backup file id.
	 * @return string
	 */
	public static function url( $backup_id, $file_id ) {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'backup'   => (int) $backup_id,
				'file'     => (int) $file_id,
				'_wpnonce' => wp_create_nonce( self::ACTION . '_' . (int) $file_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Handle a download request. Ends the request.
	 *
	 * @return void
	 */
	public function handle() {
		// The file id is needed to build the nonce action, so it is read first and the nonce is verified right below.
		$file_id   = isset( $_GET['file'] ) ? absint( wp_unslash( $_GET['file'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$backup_id = isset( $_GET['backup'] ) ? absint( wp_unslash( $_GET['backup'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! is_user_logged_in() || ! Capabilities::current_user_can_manage_backups() || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $file_id ) ) {
			self::deny( 403, __( 'Forbidden.', 'openvault-backup' ) );
		}

		$path = $this->locate( $backup_id, $file_id );
		if ( null === $path ) {
			self::deny( 404, __( 'Not found.', 'openvault-backup' ) );
		}

		$this->send( $path['real'], $path['name'] );
	}

	/**
	 * Find the file on disk from the database rows.
	 *
	 * @param int $backup_id Backup id from the request.
	 * @param int $file_id   File id from the request.
	 * @return array{real:string,name:string}|null
	 */
	private function locate( $backup_id, $file_id ) {
		if ( $backup_id < 1 || $file_id < 1 ) {
			return null;
		}

		$file   = ( new BackupFilesRepository() )->find( $file_id );
		$backup = ( new BackupsRepository() )->find( $backup_id );
		if ( null === $file || null === $backup || (int) $file['backup_id'] !== $backup_id || 0 !== (int) $file['destination_id'] || 'done' !== $file['upload_status'] ) {
			return null;
		}
		if ( ! in_array( $backup['status'], array( 'complete', 'partial' ), true ) ) {
			return null;
		}

		try {
			$real = BackupFiles::resolve_file( Paths::backups_dir(), $backup['created_at'], $backup['uuid'], (string) $file['file_name'] );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		return null === $real ? null : array(
			'real' => $real,
			'name' => (string) $file['file_name'],
		);
	}

	/**
	 * Stream a file, honouring a single Range. Ends the request.
	 *
	 * @param string $real Real path (already checked).
	 * @param string $name Stored file name.
	 * @return void
	 */
	private function send( $real, $name ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.Security.EscapeOutput.OutputNotEscaped -- Streams a binary file: raw output is the point, and fopen/fread are needed to avoid loading it into memory.
		$size = filesize( $real );
		if ( false === $size ) {
			self::deny( 404, __( 'Not found.', 'openvault-backup' ) );
		}

		$range  = DownloadHelper::parse_range( isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '', $size );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( DownloadHelper::RANGE_UNSATISFIABLE === $range['type'] ) {
			self::clear_buffers();
			status_header( 416 );
			nocache_headers();
			header( 'Content-Range: bytes */' . $size );
			header( 'Content-Length: 0' );
			exit;
		}

		$partial = DownloadHelper::RANGE_PARTIAL === $range['type'];
		$start   = $partial ? $range['start'] : 0;
		$length  = $partial ? $range['end'] - $range['start'] + 1 : $size;

		$handle = fopen( $real, 'rb' );
		if ( false === $handle ) {
			self::deny( 404, __( 'Not found.', 'openvault-backup' ) );
		}

		// Release the session lock and the time limit before a long transfer.
		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}
		@set_time_limit( 0 );
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Compression would break Content-Length and Range.
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv -- Stops mod_deflate from altering a binary download.
		}
		self::clear_buffers();

		status_header( $partial ? 206 : 200 );
		nocache_headers();
		header( 'Content-Type: ' . DownloadHelper::content_type( $name ) );
		header( 'Content-Disposition: attachment; filename="' . DownloadHelper::sanitize_filename( $name ) . '"' );
		header( 'Content-Length: ' . $length );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Accel-Buffering: no' );
		if ( $partial ) {
			header( 'Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size );
		}

		if ( 'HEAD' === $method ) {
			fclose( $handle );
			exit;
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}
		$left = $length;
		while ( $left > 0 && ! feof( $handle ) && ! connection_aborted() ) {
			$chunk = fread( $handle, (int) min( self::CHUNK_SIZE, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk;
			$left -= strlen( $chunk );
			flush();
		}
		fclose( $handle );
		exit;
		// phpcs:enable
	}

	/**
	 * Drop every output buffer so nothing is held in memory.
	 *
	 * @return void
	 */
	private static function clear_buffers() {
		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some buffers refuse to close; stop trying.
				break;
			}
		}
	}

	/**
	 * Minimal plain-text refusal. Ends the request.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message One short line.
	 * @return void
	 */
	private static function deny( $status, $message ) {
		self::clear_buffers();
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo esc_html( $message );
		exit;
	}
}
