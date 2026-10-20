<?php
/**
 * HTTP layer of the S3 API (own client over the WordPress HTTP API, no AWS SDK).
 *
 * Phase: MVP
 *
 * Purpose: Builds, signs and sends S3 requests and turns every failure into a DestinationException.
 *
 * Responsibilities:
 *  - URLs: path-style (https://endpoint/bucket/key) or virtual-hosted (https://bucket.endpoint/key); a bucket that is
 *    not DNS-safe (contains a dot) always uses path-style. Paths and queries are encoded by SigV4Signer, so the signed
 *    and the sent values are identical.
 *  - Transport is injectable: callable( method, url, headers, body, timeout, reject_unsafe_urls ) returning
 *    array( code, headers (lower-case names), body ), or throwing DestinationException for a network failure.
 *    The default transport uses wp_remote_request() with redirects OFF; reject_unsafe_urls is true unless the
 *    validated setting allow_private_endpoint is on (it is never switched off globally).
 *  - Errors: S3 XML (<Error><Code>) is parsed with entity loading disabled and DOCTYPE bodies refused. Transient:
 *    network errors, 5xx, 429, SlowDown, RequestTimeout, InternalError, ServiceUnavailable and corrupted-in-transit
 *    digests. Everything else is permanent with an actionable message. Messages never contain the secret, the
 *    Authorization header, a signature, a signed URL or the server's own error text (it can echo keys).
 *  - A 200 response to CompleteMultipartUpload may carry an <Error> body: it is treated as an error.
 *  - Request timeout comes from the remaining Budget (5 to 25 seconds).
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings or scrubbed values; they are stored and shown through escaping output functions, never echoed as HTML here.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_libxml_disable_entity_loader -- Disabled on purpose (PHP < 8) so XML from the network can never load external entities.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- SimpleXML exposes the S3 element names (Code, Part, IsTruncated ...) as properties.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Content-MD5 is the base64 of a raw md5, as S3 requires.

namespace OpenVault\Backup\Destinations\S3;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * S3 client.
 */
class S3Client {

	const MAX_XML = 4194304;

	/**
	 * Configuration from S3Presets::resolve().
	 *
	 * @var array<string,mixed>
	 */
	private $config;

	/**
	 * Signer (holds the credentials).
	 *
	 * @var SigV4Signer
	 */
	private $signer;

	/**
	 * Transport.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $config    Result of S3Presets::resolve().
	 * @param SigV4Signer         $signer    Signer.
	 * @param callable|null       $transport Transport (defaults to the WordPress HTTP API).
	 */
	public function __construct( array $config, SigV4Signer $signer, ?callable $transport = null ) {
		$this->config    = $config;
		$this->signer    = $signer;
		$this->transport = null === $transport ? array( __CLASS__, 'wp_transport' ) : $transport;
	}

	/**
	 * Whether requests use virtual-hosted style.
	 *
	 * @return bool
	 */
	public function is_virtual() {
		return 'virtual' === $this->config['addressing'] && S3Presets::is_dns_safe( $this->config['bucket'] );
	}

	/**
	 * Absolute URL of a key (without credentials; used by tests and never logged).
	 *
	 * @param string               $key   Object key ("" = the bucket).
	 * @param array<string,string> $query Query parameters.
	 * @return string
	 */
	public function url( $key, array $query = array() ) {
		list( $origin, $path ) = $this->location( $key );
		$qs                    = SigV4Signer::canonical_query( $query );
		return $origin . SigV4Signer::encode_path( $path ) . ( '' === $qs ? '' : '?' . $qs );
	}

	/**
	 * Default transport: wp_remote_request() without redirects.
	 *
	 * @param string               $method        Method.
	 * @param string               $url           URL.
	 * @param array<string,string> $headers       Headers.
	 * @param string               $body          Body.
	 * @param int                  $timeout       Seconds.
	 * @param bool                 $reject_unsafe Let WordPress refuse private addresses and unusual ports.
	 * @return array{code:int,headers:array<string,string>,body:string}
	 * @throws DestinationException On a network failure.
	 */
	public static function wp_transport( $method, $url, array $headers, $body, $timeout, $reject_unsafe ) {
		$response = wp_remote_request(
			$url,
			array(
				'method'             => $method,
				'headers'            => $headers,
				'body'               => $body,
				'timeout'            => $timeout,
				'redirection'        => 0,
				'reject_unsafe_urls' => (bool) $reject_unsafe,
				'sslverify'          => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			$text = $response->get_error_message();
			if ( false !== stripos( $text, 'valid URL was not provided' ) ) {
				throw DestinationException::permanent( __( 'WordPress refused the endpoint address (private addresses and unusual ports are blocked unless "allow private endpoint" is on).', 'openvault-backup' ) );
			}
			// The URL is not part of the message: host names are not secrets, but signed values never are shown.
			throw DestinationException::transient( __( 'The storage server could not be reached: ', 'openvault-backup' ) . preg_replace( '#https?://\S+#', '[url]', $text ) );
		}
		$raw     = wp_remote_retrieve_headers( $response );
		$headers = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		$flat    = array();
		foreach ( $headers as $name => $value ) {
			$flat[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $flat,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * PUT object (single request).
	 *
	 * @param string               $key     Key.
	 * @param string               $body    Contents.
	 * @param array<string,string> $extra   Extra headers (storage class ...).
	 * @param Budget|null          $budget  Budget for the timeout.
	 * @return array<string,string> Response headers (etag ...).
	 * @throws DestinationException On failure.
	 */
	public function put_object( $key, $body, array $extra = array(), ?Budget $budget = null ) {
		$headers = array_merge(
			array(
				'content-type' => 'application/octet-stream',
				'content-md5'  => base64_encode( md5( $body, true ) ),
			),
			$extra
		);
		return $this->call( 'PUT', $key, array(), $headers, $body, $budget )['headers'];
	}

	/**
	 * GET a byte range (inclusive).
	 *
	 * @param string      $key    Key.
	 * @param int         $start  First byte.
	 * @param int         $end    Last byte.
	 * @param Budget|null $budget Budget.
	 * @return string
	 * @throws DestinationException On failure.
	 */
	public function get_object_range( $key, $start, $end, ?Budget $budget = null ) {
		return $this->call( 'GET', $key, array(), array( 'range' => 'bytes=' . (int) $start . '-' . (int) $end ), '', $budget )['body'];
	}

	/**
	 * HEAD object.
	 *
	 * @param string $key Key.
	 * @return array{size:int,etag:string,modified:int}|null Null when it does not exist.
	 * @throws DestinationException On failure other than "not found".
	 */
	public function head_object( $key ) {
		$res = $this->call( 'HEAD', $key, array(), array(), '', null, array( 404 ) );
		if ( 404 === $res['code'] ) {
			return null;
		}
		return array(
			'size'     => isset( $res['headers']['content-length'] ) ? (int) $res['headers']['content-length'] : 0,
			'etag'     => isset( $res['headers']['etag'] ) ? trim( $res['headers']['etag'], '"' ) : '',
			'modified' => isset( $res['headers']['last-modified'] ) ? (int) strtotime( $res['headers']['last-modified'] ) : 0,
		);
	}

	/**
	 * DELETE object (a missing object is fine).
	 *
	 * @param string $key Key.
	 * @return void
	 * @throws DestinationException On failure.
	 */
	public function delete_object( $key ) {
		$this->call( 'DELETE', $key, array(), array(), '', null, array( 404 ) );
	}

	/**
	 * HEAD bucket. On a bare 400/403 (HEAD has no body) a one-key listing is requested to learn the precise error.
	 *
	 * @return void
	 * @throws DestinationException On failure.
	 */
	public function head_bucket() {
		$res = $this->send( 'HEAD', '', array(), array(), '', null );
		if ( $res['code'] >= 200 && $res['code'] < 300 ) {
			return;
		}
		if ( 400 === $res['code'] || 403 === $res['code'] ) {
			$this->call(
				'GET',
				'',
				array(
					'list-type' => '2',
					'max-keys'  => '1',
				)
			);
			return;
		}
		throw $this->error( $res, 'HEAD', true );
	}

	/**
	 * ListObjectsV2, paginated lazily.
	 *
	 * @param string $prefix Key prefix.
	 * @param int    $limit  Most entries.
	 * @return \Generator<int,array{key:string,size:int,modified:int}>
	 * @throws DestinationException On failure.
	 */
	public function list_objects_v2( $prefix, $limit = 10000 ) {
		$token = '';
		$count = 0;
		do {
			$query = array(
				'list-type' => '2',
				'prefix'    => $prefix,
				'max-keys'  => (string) max( 1, min( 1000, $limit - $count ) ),
			);
			if ( '' !== $token ) {
				$query['continuation-token'] = $token;
			}
			$xml = $this->xml( $this->call( 'GET', '', $query )['body'] );
			if ( null === $xml ) {
				throw DestinationException::transient( __( 'The storage server sent a listing that could not be read.', 'openvault-backup' ) );
			}
			foreach ( $xml->Contents as $item ) {
				if ( $count >= $limit ) {
					return;
				}
				++$count;
				yield array(
					'key'      => (string) $item->Key,
					'size'     => (int) $item->Size,
					'modified' => (int) strtotime( (string) $item->LastModified ),
				);
			}
			$token = 'true' === strtolower( (string) $xml->IsTruncated ) ? (string) $xml->NextContinuationToken : '';
		} while ( '' !== $token && $count < $limit );
	}

	/**
	 * CreateMultipartUpload.
	 *
	 * @param string               $key   Key.
	 * @param array<string,string> $extra Extra headers.
	 * @return string Upload id.
	 * @throws DestinationException On failure.
	 */
	public function create_multipart_upload( $key, array $extra = array() ) {
		$headers = array_merge( array( 'content-type' => 'application/octet-stream' ), $extra );
		$xml     = $this->xml( $this->call( 'POST', $key, array( 'uploads' => '' ), $headers )['body'] );
		$id      = null === $xml ? '' : (string) $xml->UploadId;
		if ( '' === $id || strlen( $id ) > 1024 ) {
			throw DestinationException::permanent( __( 'The storage server did not start the multipart upload.', 'openvault-backup' ) );
		}
		return $id;
	}

	/**
	 * UploadPart.
	 *
	 * @param string      $key       Key.
	 * @param string      $upload_id Upload id.
	 * @param int         $number    Part number (1 to 10000).
	 * @param string      $body      Part contents.
	 * @param Budget|null $budget    Budget.
	 * @return string ETag as returned (with quotes).
	 * @throws DestinationException On failure.
	 */
	public function upload_part( $key, $upload_id, $number, $body, ?Budget $budget = null ) {
		$res = $this->call(
			'PUT',
			$key,
			array(
				'partNumber' => (string) (int) $number,
				'uploadId'   => $upload_id,
			),
			array( 'content-md5' => base64_encode( md5( $body, true ) ) ),
			$body,
			$budget
		);
		return isset( $res['headers']['etag'] ) ? $res['headers']['etag'] : '';
	}

	/**
	 * ListParts, fully paginated.
	 *
	 * @param string $key       Key.
	 * @param string $upload_id Upload id.
	 * @return array<int,array{size:int,etag:string}> Part number => size and ETag.
	 * @throws DestinationException On failure (reason "no_such_upload" when the upload is gone).
	 */
	public function list_parts( $key, $upload_id ) {
		$parts  = array();
		$marker = 0;
		do {
			$query = array(
				'uploadId'  => $upload_id,
				'max-parts' => '1000',
			);
			if ( $marker > 0 ) {
				$query['part-number-marker'] = (string) $marker;
			}
			$xml = $this->xml( $this->call( 'GET', $key, $query )['body'] );
			if ( null === $xml ) {
				throw DestinationException::transient( __( 'The storage server sent a part list that could not be read.', 'openvault-backup' ) );
			}
			foreach ( $xml->Part as $part ) {
				$parts[ (int) $part->PartNumber ] = array(
					'size' => (int) $part->Size,
					'etag' => (string) $part->ETag,
				);
			}
			$next   = (int) $xml->NextPartNumberMarker;
			$marker = 'true' === strtolower( (string) $xml->IsTruncated ) && $next > $marker ? $next : 0;
		} while ( $marker > 0 );
		return $parts;
	}

	/**
	 * CompleteMultipartUpload.
	 *
	 * @param string            $key       Key.
	 * @param string            $upload_id Upload id.
	 * @param array<int,string> $etags     Part number => ETag, every part.
	 * @return void
	 * @throws DestinationException On failure, including an <Error> inside a 200 response.
	 */
	public function complete_multipart_upload( $key, $upload_id, array $etags ) {
		ksort( $etags );
		$xml = '<CompleteMultipartUpload>';
		foreach ( $etags as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars( $etag, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$res = $this->call( 'POST', $key, array( 'uploadId' => $upload_id ), array( 'content-type' => 'application/xml' ), $xml );
		if ( false !== strpos( substr( $res['body'], 0, 4096 ), '<Error>' ) ) {
			throw $this->error(
				array(
					'code'    => 500,
					'headers' => $res['headers'],
					'body'    => $res['body'],
				),
				'POST',
				false,
				true
			);
		}
	}

	/**
	 * AbortMultipartUpload (an unknown upload is fine).
	 *
	 * @param string $key       Key.
	 * @param string $upload_id Upload id.
	 * @return void
	 * @throws DestinationException On failure.
	 */
	public function abort_multipart_upload( $key, $upload_id ) {
		$this->call( 'DELETE', $key, array( 'uploadId' => $upload_id ), array(), '', null, array( 404 ) );
	}

	/**
	 * ListMultipartUploads, paginated lazily.
	 *
	 * @param string $prefix Key prefix.
	 * @return \Generator<int,array{key:string,upload_id:string,initiated:int}>
	 * @throws DestinationException On failure.
	 */
	public function list_multipart_uploads( $prefix ) {
		$key_marker = '';
		$id_marker  = '';
		do {
			$query = array(
				'uploads' => '',
				'prefix'  => $prefix,
			);
			if ( '' !== $key_marker ) {
				$query['key-marker']       = $key_marker;
				$query['upload-id-marker'] = $id_marker;
			}
			$xml = $this->xml( $this->call( 'GET', '', $query )['body'] );
			if ( null === $xml ) {
				throw DestinationException::transient( __( 'The storage server sent an upload list that could not be read.', 'openvault-backup' ) );
			}
			foreach ( $xml->Upload as $upload ) {
				yield array(
					'key'       => (string) $upload->Key,
					'upload_id' => (string) $upload->UploadId,
					'initiated' => (int) strtotime( (string) $upload->Initiated ),
				);
			}
			$more       = 'true' === strtolower( (string) $xml->IsTruncated );
			$key_marker = $more ? (string) $xml->NextKeyMarker : '';
			$id_marker  = $more ? (string) $xml->NextUploadIdMarker : '';
		} while ( '' !== $key_marker );
	}

	/**
	 * Send and require a 2xx (or one of the accepted codes).
	 *
	 * @param string               $method  Method.
	 * @param string               $key     Key.
	 * @param array<string,string> $query   Query.
	 * @param array<string,string> $headers Headers.
	 * @param string               $body    Body.
	 * @param Budget|null          $budget  Budget.
	 * @param int[]                $accept  Extra status codes that are not errors.
	 * @return array{code:int,headers:array<string,string>,body:string}
	 * @throws DestinationException On failure.
	 */
	private function call( $method, $key, array $query = array(), array $headers = array(), $body = '', ?Budget $budget = null, array $accept = array() ) {
		$res = $this->send( $method, $key, $query, $headers, $body, $budget );
		if ( ( $res['code'] >= 200 && $res['code'] < 300 ) || in_array( $res['code'], $accept, true ) ) {
			return $res;
		}
		throw $this->error( $res, $method, '' === $key && 'HEAD' === $method );
	}

	/**
	 * Sign and send one request.
	 *
	 * @param string               $method  Method.
	 * @param string               $key     Key.
	 * @param array<string,string> $query   Query.
	 * @param array<string,string> $headers Headers.
	 * @param string               $body    Body.
	 * @param Budget|null          $budget  Budget.
	 * @return array{code:int,headers:array<string,string>,body:string}
	 * @throws DestinationException On a network failure.
	 */
	private function send( $method, $key, array $query, array $headers, $body, ?Budget $budget ) {
		list( $origin, $path ) = $this->location( $key );
		$host                  = $this->host_header();
		$headers['host']       = $host;

		$signed = $this->signer->sign( $method, $path, $query, $headers, SigV4Signer::payload_hash( $body ) );
		unset( $signed['host'] );
		// The transport sets Host itself from the URL; it is the value that was signed.

		$qs  = SigV4Signer::canonical_query( $query );
		$url = $origin . SigV4Signer::encode_path( $path ) . ( '' === $qs ? '' : '?' . $qs );
		$res = call_user_func( $this->transport, $method, $url, $signed, $body, $this->timeout( $budget ), ! $this->config['allow_private'] );

		return array(
			'code'    => (int) $res['code'],
			'headers' => isset( $res['headers'] ) && is_array( $res['headers'] ) ? array_change_key_case( $res['headers'], CASE_LOWER ) : array(),
			'body'    => isset( $res['body'] ) ? (string) $res['body'] : '',
		);
	}

	/**
	 * Request timeout: the time left of the budget, 5 to 25 seconds (20 without a budget).
	 *
	 * @param Budget|null $budget Budget.
	 * @return int
	 */
	private function timeout( ?Budget $budget ) {
		return null === $budget ? 20 : (int) max( 5, min( 25, floor( $budget->time_left() ) ) );
	}

	/**
	 * Origin and raw path of a key.
	 *
	 * @param string $key Key ("" = the bucket).
	 * @return array{0:string,1:string}
	 */
	private function location( $key ) {
		$port   = null === $this->config['port'] ? '' : ':' . $this->config['port'];
		$scheme = $this->config['scheme'] . '://';
		if ( $this->is_virtual() ) {
			return array( $scheme . $this->config['bucket'] . '.' . $this->config['host'] . $port, '/' . $key );
		}
		return array( $scheme . $this->config['host'] . $port, '/' . $this->config['bucket'] . ( '' === $key ? '' : '/' . $key ) );
	}

	/**
	 * Host header value.
	 *
	 * @return string
	 */
	private function host_header() {
		$host = $this->is_virtual() ? $this->config['bucket'] . '.' . $this->config['host'] : $this->config['host'];
		return $host . ( null === $this->config['port'] ? '' : ':' . $this->config['port'] );
	}

	/**
	 * Map a failed response to an exception.
	 *
	 * @param array{code:int,headers:array<string,string>,body:string} $res       Response.
	 * @param string                                                   $method    Method.
	 * @param bool                                                     $bucket_op The request addressed the bucket itself.
	 * @param bool                                                     $in_200    The error came inside a 200 response.
	 * @return DestinationException
	 */
	private function error( array $res, $method, $bucket_op = false, $in_200 = false ) {
		$status = $res['code'];
		$info   = $this->error_info( $res['body'] );
		$code   = $info['code'];

		if ( in_array( $status, array( 301, 302, 307, 308 ), true ) || in_array( $code, array( 'PermanentRedirect', 'TemporaryRedirect', 'IllegalLocationConstraintException' ), true ) ) {
			return DestinationException::permanent( __( 'The bucket is in another region or the endpoint is wrong (the server redirected the request). Check the region and the endpoint.', 'openvault-backup' ) );
		}
		if ( 'AuthorizationHeaderMalformed' === $code || 'AuthorizationQueryParametersError' === $code ) {
			$region = '' !== $info['region'] ? $info['region'] : ( isset( $res['headers']['x-amz-bucket-region'] ) ? $this->safe_token( $res['headers']['x-amz-bucket-region'] ) : '' );
			return DestinationException::permanent(
				'' !== $region
					/* translators: %s: region code asked for by the server. */
					? sprintf( __( 'The region does not match the bucket. The server asks for the region "%s".', 'openvault-backup' ), $region )
					: __( 'The region does not match the bucket. Check the region setting.', 'openvault-backup' )
			);
		}
		switch ( $code ) {
			case 'RequestTimeTooSkewed':
				return DestinationException::permanent( __( "The clock of this server differs from the storage server's clock by more than 15 minutes. Correct the server time.", 'openvault-backup' ) );
			case 'SignatureDoesNotMatch':
				return DestinationException::permanent( __( 'The storage server rejected the signature. Check the access key, the secret key, the region and the endpoint.', 'openvault-backup' ) );
			case 'InvalidAccessKeyId':
				return DestinationException::permanent( __( 'The access key ID is not known to the storage server. Check the access key and the endpoint.', 'openvault-backup' ) );
			case 'AccessDenied':
			case 'AllAccessDisabled':
			case 'AccountProblem':
				return DestinationException::permanent( __( 'Access denied. The keys are accepted but may not do this on the bucket. Check the permissions of the key.', 'openvault-backup' ) );
			case 'NoSuchBucket':
				return DestinationException::permanent( sprintf( /* translators: %s: bucket name. */ __( 'The bucket "%s" does not exist for these keys (or it is in another region).', 'openvault-backup' ), $this->config['bucket'] ) );
			case 'NoSuchUpload':
				return new DestinationException( __( 'The multipart upload no longer exists on the storage server.', 'openvault-backup' ), false, 'no_such_upload' );
			case 'NoSuchKey':
				return new DestinationException( __( 'The file does not exist in the bucket.', 'openvault-backup' ), false, 'no_such_key' );
			case 'BadDigest':
			case 'InvalidDigest':
			case 'XAmzContentSHA256Mismatch':
			case 'IncompleteBody':
				return DestinationException::transient( __( 'The upload was damaged in transit and will be retried.', 'openvault-backup' ) );
			case 'SlowDown':
			case 'RequestTimeout':
			case 'InternalError':
			case 'ServiceUnavailable':
			case 'Throttling':
			case 'ThrottlingException':
			case 'RequestLimitExceeded':
			case 'OperationAborted':
				return DestinationException::transient( __( 'The storage server is busy or had a temporary problem. It will be retried.', 'openvault-backup' ) );
		}//end switch
		if ( 429 === $status || ( $status >= 500 && 501 !== $status ) || ( $in_200 && '' === $code ) ) {
			return DestinationException::transient( sprintf( /* translators: %d: HTTP status. */ __( 'The storage server had a temporary problem (HTTP %d). It will be retried.', 'openvault-backup' ), $status ) );
		}
		if ( '' !== $code ) {
			return DestinationException::permanent( sprintf( /* translators: 1: S3 error code, 2: HTTP status. */ __( 'The storage server refused the request (%1$s, HTTP %2$d).', 'openvault-backup' ), $code, $status ) );
		}
		if ( 404 === $status && $bucket_op ) {
			return DestinationException::permanent( sprintf( /* translators: %s: bucket name. */ __( 'The bucket "%s" does not exist for these keys (or it is in another region).', 'openvault-backup' ), $this->config['bucket'] ) );
		}
		if ( 403 === $status ) {
			return DestinationException::permanent( __( 'Access denied or the credentials were rejected. Check the access key, the secret key, the region, the endpoint and the permissions of the key.', 'openvault-backup' ) );
		}
		return DestinationException::permanent( sprintf( /* translators: 1: HTTP method, 2: HTTP status. */ __( 'The storage server refused the request (%1$s, HTTP %2$d).', 'openvault-backup' ), $method, $status ) );
	}

	/**
	 * Code and region of an S3 error body.
	 *
	 * @param string $body Body.
	 * @return array{code:string,region:string}
	 */
	private function error_info( $body ) {
		$xml  = $this->xml( $body );
		$info = array(
			'code'   => '',
			'region' => '',
		);
		if ( null !== $xml && 'Error' === $xml->getName() ) {
			$info['code']   = $this->safe_token( (string) $xml->Code );
			$info['region'] = $this->safe_token( (string) $xml->Region );
		}
		return $info;
	}

	/**
	 * Keep only letters, digits and "-" (codes and regions are shown, never free text from the server).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function safe_token( $value ) {
		return 1 === preg_match( '/^[A-Za-z0-9\-]{1,64}\z/', $value ) ? $value : '';
	}

	/**
	 * Parse S3 XML safely: no DOCTYPE or entity declarations, no network, no entity substitution.
	 *
	 * @param string $body XML.
	 * @return \SimpleXMLElement|null
	 */
	public function xml( $body ) {
		if ( '' === $body || strlen( $body ) > self::MAX_XML || false !== stripos( $body, '<!DOCTYPE' ) || false !== stripos( $body, '<!ENTITY' ) ) {
			return null;
		}
		$body = (string) preg_replace( '/\sxmlns="[^"]*"/', '', $body, 1 );

		$previous = null;
		if ( PHP_VERSION_ID < 80000 ) {
			$previous = libxml_disable_entity_loader( true ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated -- Needed below PHP 8, guarded by the version check.
		}
		$use_errors = libxml_use_internal_errors( true );
		$xml        = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $use_errors );
		if ( null !== $previous ) {
			libxml_disable_entity_loader( $previous ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated -- Restores the previous state, guarded by the version check.
		}
		return false === $xml ? null : $xml;
	}
}
