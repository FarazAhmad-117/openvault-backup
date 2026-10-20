<?php
/**
 * Generic S3-compatible provider (AWS, R2, B2, Wasabi, Spaces, IDrive e2, MinIO).
 *
 * Phase: MVP
 *
 * Purpose: Stores backups in an S3-compatible bucket with the user's own keys.
 *
 * Responsibilities:
 *  - Endpoint presets per provider + custom endpoint (S3Presets), endpoint safety (EndpointValidator)
 *  - Settings: preset, region, account_id (r2), endpoint (custom), addressing (path|virtual|""), bucket, prefix,
 *    allow_insecure_http, allow_private_endpoint, storage_class (whitelist), part_size_mb.
 *    Credentials (from the vault only): access_key, secret_key. They live only inside the signer.
 *  - Remote references are relative to the configured prefix, like the Local destination's folder:
 *    OpenVault-Backups/<site-host>/<folder>/<file>. The object key is <prefix>/<reference>. Every reference must be
 *    below OpenVault-Backups/, so a forged reference can never delete or read anything else in the bucket.
 *  - Upload: MultipartUploader (single PUT up to one part, multipart above, resumable, size verified).
 *  - test_connection(): HEAD bucket, then a tiny put / get / delete probe object <prefix>/.ovb-probe-<random>.
 *  - download() uses 8 MiB ranged GETs; list() is lazy; quota() is null; abort_stale_uploads() is a utility that
 *    aborts old multipart uploads below OpenVault-Backups/ (not wired to anything yet).
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings or scrubbed values; they are stored and shown through escaping output functions, never echoed as HTML here.

namespace OpenVault\Backup\Destinations\S3;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\DestinationInterface;
use OpenVault\Backup\Destinations\RemotePath;
use OpenVault\Backup\Destinations\TestResult;
use OpenVault\Backup\Destinations\UploadProgress;
use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * S3-compatible destination.
 */
class S3Destination implements DestinationInterface {

	const RANGE = 8388608;

	/**
	 * Settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Credentials.
	 *
	 * @var array<string,mixed>
	 */
	private $credentials;

	/**
	 * Transport override (tests).
	 *
	 * @var callable|null
	 */
	private $transport;

	/**
	 * Clock override (tests).
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Resolved configuration (lazy).
	 *
	 * @var array<string,mixed>|null
	 */
	private $config;

	/**
	 * Client (lazy).
	 *
	 * @var S3Client|null
	 */
	private $client;

	/**
	 * Constructor. Nothing is validated here, so creating a destination never throws; the first use does.
	 *
	 * @param array<string,mixed> $settings    Settings.
	 * @param array<string,mixed> $credentials access_key and secret_key.
	 * @param callable|null       $transport   Transport (tests); defaults to the WordPress HTTP API.
	 * @param callable|null       $clock       Clock returning the unix time (tests).
	 */
	public function __construct( array $settings = array(), array $credentials = array(), ?callable $transport = null, ?callable $clock = null ) {
		$this->settings    = $settings;
		$this->credentials = $credentials;
		$this->transport   = $transport;
		$this->clock       = $clock;
	}

	/**
	 * Type slug.
	 *
	 * @return string
	 */
	public function id() {
		return 's3';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'S3-compatible storage', 'openvault-backup' );
	}

	/**
	 * Top folder of all backups below the prefix.
	 *
	 * @return string
	 */
	public function remote_root() {
		return RemotePath::ROOT;
	}

	/**
	 * Not available for S3.
	 *
	 * @return null
	 */
	public function quota() {
		return null;
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- random_bytes() failure is caught below as Throwable.
	/**
	 * HEAD the bucket, then write, read back and delete a probe object. Never throws.
	 *
	 * @return TestResult
	 */
	public function test_connection() {
		$key = '';
		try {
			$client = $this->client();
			$config = $this->config();
			$client->head_bucket();

			$key  = ( '' === $config['prefix'] ? '' : $config['prefix'] . '/' ) . '.ovb-probe-' . bin2hex( random_bytes( 8 ) );
			$data = bin2hex( random_bytes( 16 ) );
			$client->put_object( $key, $data );
			$back = $client->get_object_range( $key, 0, strlen( $data ) - 1 );
			$client->delete_object( $key );
			$key = '';
			if ( $back !== $data ) {
				throw DestinationException::permanent( __( 'A test file could not be read back from the bucket.', 'openvault-backup' ) );
			}
			return TestResult::success(
				/* translators: %s: bucket name. */
				sprintf( __( 'The bucket "%s" works: a test file was written, read back and removed.', 'openvault-backup' ), $config['bucket'] ),
				array(
					'preset'     => $config['preset'],
					'region'     => $config['region'],
					'addressing' => $client->is_virtual() ? 'virtual' : 'path',
				)
			);
		} catch ( \Throwable $e ) {
			if ( '' !== $key ) {
				try {
					$this->client()->delete_object( $key );
				} catch ( \Throwable $ignored ) {
					unset( $ignored );
					// Best effort.
				}
			}
			return TestResult::failure( $e instanceof DestinationException ? $e->getMessage() : __( 'The connection test failed unexpectedly.', 'openvault-backup' ) );
		}//end try
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing

	/**
	 * Start an upload.
	 *
	 * @param string              $remote_path Relative remote path.
	 * @param int                 $size        Size in bytes.
	 * @param array<string,mixed> $options     Unused (the size is verified after the upload).
	 * @return array<string,mixed>
	 * @throws DestinationException When the path or size is invalid, or the multipart upload cannot be created.
	 */
	public function begin_upload( $remote_path, $size, array $options = array() ) {
		unset( $options );
		if ( ! is_int( $size ) || $size < 0 ) {
			throw DestinationException::permanent( __( 'Invalid file size.', 'openvault-backup' ) );
		}
		return $this->uploader()->begin( $this->key( $remote_path ), $remote_path, $size );
	}

	/**
	 * Upload as many parts as the budget allows.
	 *
	 * @param array<string,mixed> $session    Session.
	 * @param string              $local_file Local file.
	 * @param Budget              $budget     Budget.
	 * @return UploadProgress
	 * @throws DestinationException On failure.
	 */
	public function upload_chunk( array $session, $local_file, Budget $budget ) {
		$s = MultipartUploader::read_session( $session );
		return $this->uploader()->upload_chunk( $this->key( $s['path'] ), $session, $local_file, $budget );
	}

	/**
	 * Abort a multipart upload. Best effort.
	 *
	 * @param array<string,mixed> $session Session.
	 * @return void
	 */
	public function abort_upload( array $session ) {
		try {
			$s = MultipartUploader::read_session( $session );
			$this->uploader()->abort( $this->key( $s['path'] ), $session );
		} catch ( \Throwable $e ) {
			unset( $e );
			// Best effort.
		}
	}

	/**
	 * Stream an object in 8 MiB ranges.
	 *
	 * @param string   $remote_ref Reference.
	 * @param callable $sink       Receives chunks; false stops.
	 * @return void
	 * @throws DestinationException When missing or unreadable.
	 */
	public function download( $remote_ref, callable $sink ) {
		$key    = $this->key( $remote_ref );
		$client = $this->client();
		$head   = $client->head_object( $key );
		if ( null === $head ) {
			throw DestinationException::permanent( __( 'The file does not exist in the bucket.', 'openvault-backup' ) );
		}
		for ( $start = 0; $start < $head['size']; $start += self::RANGE ) {
			$end   = min( $head['size'], $start + self::RANGE ) - 1;
			$chunk = $this->range( $client, $key, $start, $end );
			if ( false === call_user_func( $sink, $chunk ) ) {
				return;
			}
		}
	}

	/**
	 * Delete an object, and everything below it when the reference is a backup folder.
	 *
	 * @param string $remote_ref Reference.
	 * @return void
	 * @throws DestinationException On failure.
	 */
	public function delete( $remote_ref ) {
		$key    = $this->key( $remote_ref );
		$client = $this->client();
		// A backup folder is only a key prefix: delete what is below it. Some servers fail a DELETE on such a prefix, so the
		// key itself is only deleted when nothing lies below it (a file).
		$below = false;
		foreach ( $client->list_objects_v2( $key . '/', 100000 ) as $object ) {
			$below = true;
			$client->delete_object( $object['key'] );
		}
		if ( ! $below ) {
			$client->delete_object( $key );
		}
	}

	/**
	 * List objects below a prefix, lazily.
	 *
	 * @param string $remote_prefix Reference prefix ('' = below remote_root()).
	 * @param int    $limit         Most entries.
	 * @return \Generator
	 * @throws DestinationException On failure.
	 */
	public function list( $remote_prefix = '', $limit = 10000 ) {
		$start  = '' === $remote_prefix ? RemotePath::ROOT : (string) $remote_prefix;
		$prefix = $this->config()['prefix'];
		$base   = '' === $prefix ? '' : $prefix . '/';
		$key    = RemotePath::ROOT === $start ? $base . $start : $this->key( $start );
		foreach ( $this->client()->list_objects_v2( $key . '/', max( 0, (int) $limit ) ) as $object ) {
			$ref = substr( $object['key'], strlen( $base ) );
			if ( '/' === substr( $ref, -1 ) || ! RemotePath::is_safe( $ref ) ) {
				continue;
			}
			yield array(
				'ref'      => $ref,
				'name'     => basename( $ref ),
				'size'     => $object['size'],
				'modified' => $object['modified'],
			);
		}
	}

	/**
	 * Abort multipart uploads below OpenVault-Backups/ that were started more than $older_than seconds ago (left behind
	 * by killed jobs). Choose a value well above the longest upload (a day or more): a running upload would be aborted too.
	 *
	 * @param int $older_than Age in seconds.
	 * @return int Number of uploads aborted.
	 * @throws DestinationException On failure.
	 */
	public function abort_stale_uploads( $older_than ) {
		$client = $this->client();
		$prefix = $this->config()['prefix'];
		$limit  = ( null === $this->clock ? time() : (int) call_user_func( $this->clock ) ) - max( 0, (int) $older_than );
		$stale  = array();
		foreach ( $client->list_multipart_uploads( ( '' === $prefix ? '' : $prefix . '/' ) . RemotePath::ROOT . '/' ) as $upload ) {
			if ( $upload['initiated'] > 0 && $upload['initiated'] < $limit ) {
				$stale[] = $upload;
			}
		}
		foreach ( $stale as $upload ) {
			$client->abort_multipart_upload( $upload['key'], $upload['upload_id'] );
		}
		return count( $stale );
	}

	/**
	 * One ranged GET with up to three attempts for transient errors.
	 *
	 * @param S3Client $client Client.
	 * @param string   $key    Key.
	 * @param int      $start  First byte.
	 * @param int      $end    Last byte.
	 * @return string
	 * @throws DestinationException On failure.
	 */
	private function range( S3Client $client, $key, $start, $end ) {
		for ( $attempt = 1; ; $attempt++ ) {
			try {
				$chunk = $client->get_object_range( $key, $start, $end );
				if ( strlen( $chunk ) !== $end - $start + 1 ) {
					throw DestinationException::transient( __( 'The storage server returned an incomplete range.', 'openvault-backup' ) );
				}
				return $chunk;
			} catch ( DestinationException $e ) {
				if ( ! $e->retryable() || $attempt >= 3 ) {
					throw $e;
				}
				usleep( 250000 * $attempt );
			}
		}
	}

	/**
	 * Object key of a reference.
	 *
	 * @param string $path Reference.
	 * @return string
	 * @throws DestinationException When the reference is not allowed.
	 */
	private function key( $path ) {
		return S3Presets::key( $this->config()['prefix'], $path );
	}

	/**
	 * Resolved settings.
	 *
	 * @return array<string,mixed>
	 * @throws DestinationException For invalid settings.
	 */
	private function config() {
		if ( null === $this->config ) {
			$this->config = S3Presets::resolve( $this->settings );
		}
		return $this->config;
	}

	/**
	 * Client with the signer built from the credentials.
	 *
	 * @return S3Client
	 * @throws DestinationException For invalid settings or missing credentials.
	 */
	private function client() {
		if ( null === $this->client ) {
			$config = $this->config();
			$access = isset( $this->credentials['access_key'] ) ? trim( (string) $this->credentials['access_key'] ) : '';
			$secret = isset( $this->credentials['secret_key'] ) ? (string) $this->credentials['secret_key'] : '';
			if ( '' === $access || '' === $secret ) {
				throw DestinationException::permanent( __( 'The access key or the secret key is missing.', 'openvault-backup' ) );
			}
			$this->client = new S3Client( $config, new SigV4Signer( $access, $secret, $config['region'], 's3', $this->clock ), $this->transport );
		}
		return $this->client;
	}

	/**
	 * Uploader for the current settings.
	 *
	 * @return MultipartUploader
	 * @throws DestinationException For invalid settings.
	 */
	private function uploader() {
		$config = $this->config();
		$extra  = '' === $config['storage_class'] ? array() : array( 'x-amz-storage-class' => $config['storage_class'] );
		$mb     = isset( $this->settings['part_size_mb'] ) ? (int) $this->settings['part_size_mb'] : 0;
		$limit  = Budget::parse_bytes( (string) ini_get( 'memory_limit' ) );
		return new MultipartUploader( $this->client(), $extra, $mb * 1048576, $limit );
	}
}
