<?php
/**
 * Resumable S3 upload of one file (single PUT for small files, multipart for bigger ones).
 *
 * Phase: MVP
 *
 * Purpose: S3 multipart upload (min 5 MiB parts), resumable under a 30 s / 128 MB budget.
 *
 * Part size: default 8 MiB, at least 5 MiB (S3 minimum except for the last part), at most 64 MiB, and never more
 * than about 25% of the PHP memory limit (a part is read into one string because requests are wp_remote_request()
 * string bodies, so one part costs roughly its size in memory). When a file would need more than 10,000 parts the
 * part size is raised to fit; a file that cannot fit even with 64 MiB parts is refused. The last part may be smaller.
 *
 * Session (JSON, stored by the upload step after EVERY call, far below the 64 KB column limit):
 *   { v, path, size, part_size, upload_id, next_part, offset, restarts }
 * It holds NO part list. "path" and "size" are needed because upload_chunk() receives neither. An empty upload_id
 * means "one PUT". After the last part the parts are listed from the server (ListParts, paginated) and the upload
 * is completed from that list; a part the server lacks (or holds with a wrong size) is re-uploaded from the first
 * missing one. A multipart upload that vanished (NoSuchUpload) is checked with HEAD (already complete?) and
 * otherwise started again, at most twice.
 *
 * Integrity: Content-MD5 on every request; for a single PUT the returned ETag is compared with the local md5
 * unless the server used server-side encryption (then it is not an md5). After completion the remote size must equal
 * the local size.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings; they are stored and shown through escaping output functions, never echoed as HTML here.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads the user's own backup file in bounded parts; WP_Filesystem is not suitable and not available in unit tests.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- File reads on restricted hosts warn instead of returning false; every result is checked.

namespace OpenVault\Backup\Destinations\S3;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\UploadProgress;
use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Multipart uploader.
 */
class MultipartUploader {

	const MIN_PART     = 5242880;
	const MAX_PART     = 67108864;
	const DEFAULT_PART = 8388608;
	const MAX_PARTS    = 10000;
	const MAX_RESTARTS = 2;

	/**
	 * Client.
	 *
	 * @var S3Client
	 */
	private $client;

	/**
	 * Headers added to the request that creates the object (storage class).
	 *
	 * @var array<string,string>
	 */
	private $extra;

	/**
	 * Configured part size in bytes (0 = default).
	 *
	 * @var int
	 */
	private $configured_part;

	/**
	 * PHP memory limit in bytes (-1 = unlimited).
	 *
	 * @var int
	 */
	private $memory_limit;

	/**
	 * Constructor.
	 *
	 * @param S3Client             $client          Client.
	 * @param array<string,string> $extra           Extra headers for the object (x-amz-storage-class).
	 * @param int                  $configured_part Part size in bytes, 0 for the default.
	 * @param int                  $memory_limit    PHP memory limit in bytes, -1 for unlimited.
	 */
	public function __construct( S3Client $client, array $extra = array(), $configured_part = 0, $memory_limit = -1 ) {
		$this->client          = $client;
		$this->extra           = $extra;
		$this->configured_part = (int) $configured_part;
		$this->memory_limit    = (int) $memory_limit;
	}

	/**
	 * Part size for a file.
	 *
	 * @param int $file_size    File size in bytes.
	 * @param int $configured   Configured part size (0 = default).
	 * @param int $memory_limit PHP memory limit in bytes (-1 or 0 = no cap).
	 * @return int
	 * @throws DestinationException When the file cannot be uploaded in 10,000 parts of at most 64 MiB.
	 */
	public static function part_size( $file_size, $configured = 0, $memory_limit = -1 ) {
		$part = $configured > 0 ? (int) $configured : self::DEFAULT_PART;
		$part = max( self::MIN_PART, min( self::MAX_PART, $part ) );
		if ( $memory_limit > 0 ) {
			$part = min( $part, max( self::MIN_PART, (int) floor( $memory_limit * 0.25 ) ) );
		}
		$needed = (int) ceil( $file_size / self::MAX_PARTS );
		if ( $part < $needed ) {
			$part = (int) ( ceil( $needed / 1048576 ) * 1048576 );
		}
		if ( $part > self::MAX_PART ) {
			throw DestinationException::permanent( __( 'This file is too large to upload to S3 storage in one piece. Use a smaller archive part size.', 'openvault-backup' ) );
		}
		return $part;
	}

	/**
	 * Start an upload.
	 *
	 * @param string $key  Object key.
	 * @param string $path Relative remote path (kept in the session).
	 * @param int    $size File size.
	 * @return array<string,mixed> Session.
	 * @throws DestinationException When the file is too large or the upload cannot be created.
	 */
	public function begin( $key, $path, $size ) {
		$part    = self::part_size( $size, $this->configured_part, $this->memory_limit );
		$session = array(
			'v'         => 1,
			'path'      => $path,
			'size'      => $size,
			'part_size' => $part,
			'upload_id' => '',
			'next_part' => 1,
			'offset'    => 0,
			'restarts'  => 0,
		);
		if ( $size > $part ) {
			$session['upload_id'] = $this->client->create_multipart_upload( $key, $this->extra );
		}
		return $session;
	}

	/**
	 * Send as much as the budget allows (at least one part).
	 *
	 * @param string              $key        Object key.
	 * @param array<string,mixed> $session    Session.
	 * @param string              $local_file Local file.
	 * @param Budget              $budget     Budget.
	 * @return UploadProgress
	 * @throws DestinationException On failure.
	 */
	public function upload_chunk( $key, array $session, $local_file, Budget $budget ) {
		$s = self::read_session( $session );

		clearstatcache( true, $local_file );
		if ( ! is_file( $local_file ) || filesize( $local_file ) !== $s['size'] ) {
			throw DestinationException::permanent( __( 'The local backup file is missing or has changed.', 'openvault-backup' ) );
		}
		if ( '' === $s['upload_id'] ) {
			return $this->single( $key, $s, $local_file, $budget );
		}

		$sent = 0;
		try {
			$total = (int) ceil( $s['size'] / $s['part_size'] );
			$parts = 0;
			$last  = 0.0;
			while ( $s['next_part'] <= $total ) {
				if ( $parts > 0 && ( $budget->exhausted() || $budget->time_left() < max( 5.0, 1.5 * $last ) ) ) {
					break;
					// The time left cannot fit another part: stop before starting it.
				}
				$number = $s['next_part'];
				$offset = ( $number - 1 ) * $s['part_size'];
				$length = min( $s['part_size'], $s['size'] - $offset );
				$body   = self::read( $local_file, $offset, $length );
				$began  = $budget->elapsed();
				$this->client->upload_part( $key, $s['upload_id'], $number, $body, $budget );
				unset( $body );
				$last = max( 0.0, $budget->elapsed() - $began );

				$s['next_part'] = $number + 1;
				$s['offset']    = min( $s['size'], $offset + $length );
				$sent          += $length;
				++$parts;
			}
			if ( $s['next_part'] <= $total ) {
				return UploadProgress::partial( $s, $sent );
			}
			return $this->finish( $key, $s, $total, $sent );
		} catch ( DestinationException $e ) {
			if ( 'no_such_upload' !== $e->reason() ) {
				throw $e;
			}
			return $this->recover( $key, $s, $sent );
		}//end try
	}

	/**
	 * Abort the multipart upload of a session. Best effort, never throws.
	 *
	 * @param string              $key     Object key.
	 * @param array<string,mixed> $session Session.
	 * @return void
	 */
	public function abort( $key, array $session ) {
		try {
			$s = self::read_session( $session );
			if ( '' !== $s['upload_id'] ) {
				$this->client->abort_multipart_upload( $key, $s['upload_id'] );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			// Best effort.
		}
	}

	/**
	 * Validate and normalise a session.
	 *
	 * @param array<string,mixed> $session Session.
	 * @return array{v:int,path:string,size:int,part_size:int,upload_id:string,next_part:int,offset:int,restarts:int}
	 * @throws DestinationException For a session that is not ours or is damaged.
	 */
	public static function read_session( array $session ) {
		if ( ! isset( $session['v'], $session['path'], $session['size'], $session['part_size'], $session['upload_id'], $session['next_part'], $session['offset'] )
			|| 1 !== (int) $session['v'] || ! is_string( $session['path'] ) || ! is_string( $session['upload_id'] )
			|| (int) $session['size'] < 0 || (int) $session['part_size'] < 1 || (int) $session['next_part'] < 1 || (int) $session['offset'] < 0 ) {
			throw DestinationException::permanent( __( 'The upload session is not valid.', 'openvault-backup' ) );
		}
		return array(
			'v'         => 1,
			'path'      => $session['path'],
			'size'      => (int) $session['size'],
			'part_size' => (int) $session['part_size'],
			'upload_id' => $session['upload_id'],
			'next_part' => (int) $session['next_part'],
			'offset'    => (int) $session['offset'],
			'restarts'  => isset( $session['restarts'] ) ? (int) $session['restarts'] : 0,
		);
	}

	/**
	 * One PUT for a file that fits in a single part.
	 *
	 * @param string              $key    Key.
	 * @param array<string,mixed> $s      Session.
	 * @param string              $file   Local file.
	 * @param Budget              $budget Budget.
	 * @return UploadProgress
	 * @throws DestinationException On failure.
	 */
	private function single( $key, array $s, $file, Budget $budget ) {
		$body    = self::read( $file, 0, $s['size'] );
		$md5     = md5( $body );
		$headers = $this->client->put_object( $key, $body, $this->extra, $budget );
		unset( $body );

		$etag = isset( $headers['etag'] ) ? trim( $headers['etag'], '"' ) : '';
		$sse  = isset( $headers['x-amz-server-side-encryption'] ) && 'AES256' !== $headers['x-amz-server-side-encryption'];
		if ( ! $sse && 1 === preg_match( '/^[0-9a-f]{32}\z/i', $etag ) && 0 !== strcasecmp( $etag, $md5 ) ) {
			throw DestinationException::transient( __( 'The storage server reports a different checksum for the uploaded file. It will be retried.', 'openvault-backup' ) );
		}
		$this->verify_size( $key, $s['size'] );
		$s['offset'] = $s['size'];
		return UploadProgress::finished( $s, $s['size'], $s['path'] );
	}

	/**
	 * All parts are sent: rebuild the part list from the server, re-upload a missing part or complete.
	 *
	 * @param string              $key   Key.
	 * @param array<string,mixed> $s     Session.
	 * @param int                 $total Number of parts.
	 * @param int                 $sent  Bytes sent by this call so far.
	 * @return UploadProgress
	 * @throws DestinationException On failure.
	 */
	private function finish( $key, array $s, $total, $sent ) {
		$remote = $this->client->list_parts( $key, $s['upload_id'] );
		$etags  = array();
		for ( $n = 1; $n <= $total; $n++ ) {
			$expected = $n < $total ? $s['part_size'] : $s['size'] - ( $total - 1 ) * $s['part_size'];
			if ( ! isset( $remote[ $n ] ) || $remote[ $n ]['size'] !== $expected ) {
				if ( $s['restarts'] >= self::MAX_RESTARTS + 1 ) {
					throw DestinationException::permanent( __( 'The storage server keeps losing parts of this upload.', 'openvault-backup' ) );
				}
				++$s['restarts'];
				$s['next_part'] = $n;
				$s['offset']    = ( $n - 1 ) * $s['part_size'];
				return UploadProgress::partial( $s, $sent );
			}
			$etags[ $n ] = $remote[ $n ]['etag'];
		}
		unset( $remote );

		$this->client->complete_multipart_upload( $key, $s['upload_id'], $etags );
		$this->verify_size( $key, $s['size'] );
		$s['offset'] = $s['size'];
		return UploadProgress::finished( $s, $sent, $s['path'] );
	}

	/**
	 * The multipart upload is gone: finished already (a crash after completion), or start again.
	 *
	 * @param string              $key  Key.
	 * @param array<string,mixed> $s    Session.
	 * @param int                 $sent Bytes sent by this call so far.
	 * @return UploadProgress
	 * @throws DestinationException When it keeps vanishing.
	 */
	private function recover( $key, array $s, $sent ) {
		$head = $this->client->head_object( $key );
		if ( null !== $head && $head['size'] === $s['size'] ) {
			$s['offset'] = $s['size'];
			return UploadProgress::finished( $s, $sent, $s['path'] );
		}
		if ( $s['restarts'] >= self::MAX_RESTARTS ) {
			throw DestinationException::permanent( __( 'The multipart upload keeps disappearing on the storage server.', 'openvault-backup' ) );
		}
		$s['upload_id'] = $this->client->create_multipart_upload( $key, $this->extra );
		$s['next_part'] = 1;
		$s['offset']    = 0;
		++$s['restarts'];
		return UploadProgress::partial( $s, $sent );
	}

	/**
	 * The stored object must have the local size.
	 *
	 * @param string $key  Key.
	 * @param int    $size Expected size.
	 * @return void
	 * @throws DestinationException When it does not.
	 */
	private function verify_size( $key, $size ) {
		$head = $this->client->head_object( $key );
		if ( null === $head ) {
			throw DestinationException::permanent( __( 'The uploaded file cannot be found on the storage server after the upload.', 'openvault-backup' ) );
		}
		if ( $head['size'] !== $size ) {
			throw DestinationException::permanent(
				sprintf(
					/* translators: 1: size on the server in bytes, 2: expected size in bytes. */
					__( 'The stored file has %1$d bytes but %2$d were expected. The upload was not complete.', 'openvault-backup' ),
					$head['size'],
					$size
				)
			);
		}
	}

	/**
	 * Read a slice of the file.
	 *
	 * @param string $file   File.
	 * @param int    $offset Offset.
	 * @param int    $length Length.
	 * @return string
	 * @throws DestinationException When it cannot be read.
	 */
	private static function read( $file, $offset, $length ) {
		if ( $length <= 0 ) {
			return '';
		}
		$data = @file_get_contents( $file, false, null, $offset, $length );
		if ( false === $data || strlen( $data ) !== $length ) {
			throw DestinationException::transient( __( 'Reading the backup file failed.', 'openvault-backup' ) );
		}
		return $data;
	}
}
