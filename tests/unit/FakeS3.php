<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, Generic.Commenting, Squiz.Commenting, WordPress.NamingConventions.ValidVariableName, WordPress.PHP.DiscouragedPHPFunctions
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\S3\SigV4Signer;

/**
 * In-memory S3 used as the transport of S3Client in unit tests. It VERIFIES every signature with the same secret,
 * so the client and the signer are tested together.
 */
class FakeS3 {

	public $access = 'AKIAFAKEACCESS000001';
	public $secret = 'fake-secret-key-for-tests';
	public $bucket = 'bkt';
	public $host   = 'fake.test';

	/** @var array<string,array{size:int,etag:string,body:?string,modified:int}> */
	public $objects = array();
	/** @var array<string,array{key:string,initiated:int,parts:array<int,array{size:int,etag:string,body:?string}>}> */
	public $uploads = array();
	/** @var array<int,array<string,mixed>> */
	public $calls = array();
	/** @var array<int,array{0:int,1:string,2:array}> Queued responses (code, body, headers) returned instead of handling. */
	public $queue          = array();
	public $complete_error = '';
	public $etag_override  = null;
	public $corrupt_md5    = false;
	public $now            = 1700000000;
	public $page           = 1000;
	private $counter       = 0;

	/** @var string[] Temp files holding object and part contents (kept out of memory). */
	private $blobs = array();

	/** Store contents in a temp file and return its path (used as the 'body' of objects and parts). */
	public function blob( $data ) {
		$path = tempnam( sys_get_temp_dir(), 'ovbfs' );
		file_put_contents( $path, $data );
		$this->blobs[] = $path;
		return $path;
	}

	public function __destruct() {
		foreach ( $this->blobs as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	public function __invoke( $method, $url, array $headers, $body, $timeout, $reject_unsafe ) {
		$parts = parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = array();
		if ( isset( $parts['query'] ) ) {
			foreach ( explode( '&', $parts['query'] ) as $pair ) {
				$kv                              = explode( '=', $pair, 2 );
				$query[ rawurldecode( $kv[0] ) ] = rawurldecode( isset( $kv[1] ) ? $kv[1] : '' );
			}
		}
		$host_header   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$this->calls[] = array(
			'method'        => $method,
			'url'           => $url,
			'query'         => $query,
			'headers'       => $headers,
			'timeout'       => $timeout,
			'reject_unsafe' => $reject_unsafe,
			'body_len'      => strlen( $body ),
		);

		if ( $this->queue ) {
			$next = array_shift( $this->queue );
			if ( 'network' === $next[0] ) {
				throw \OpenVault\Backup\Destinations\DestinationException::transient( 'network down' );
			}
			return array(
				'code'    => $next[0],
				'headers' => isset( $next[2] ) ? $next[2] : array(),
				'body'    => $next[1],
			);
		}
		if ( ! $this->signature_ok( $method, $path, $query, $headers, $host_header, $body ) ) {
			return $this->error( 403, 'SignatureDoesNotMatch' );
		}

		$raw = rawurldecode( $path );
		if ( 0 === strpos( $host_header, $this->bucket . '.' ) ) {
			$key = ltrim( $raw, '/' );
		} elseif ( 0 === strpos( $raw, '/' . $this->bucket ) && ( strlen( $raw ) === strlen( $this->bucket ) + 1 || '/' === $raw[ strlen( $this->bucket ) + 1 ] ) ) {
			$key = (string) substr( $raw, strlen( $this->bucket ) + 2 );
		} else {
			return $this->error( 404, 'NoSuchBucket', 'HEAD' === $method );
		}
		return $this->route( $method, $key, $query, $headers, $body );
	}

	private function route( $method, $key, array $query, array $headers, $body ) {
		if ( '' === $key ) {
			if ( 'HEAD' === $method ) {
				return $this->ok( '', array() );
			}
			if ( isset( $query['uploads'] ) ) {
				return $this->list_uploads( $query );
			}
			return $this->list_objects( $query );
		}
		if ( 'POST' === $method && isset( $query['uploads'] ) ) {
			$id                   = 'upload-' . ( ++$this->counter );
			$this->uploads[ $id ] = array(
				'key'       => $key,
				'initiated' => $this->now,
				'parts'     => array(),
			);
			return $this->ok( '<?xml version="1.0"?><InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Bucket>' . $this->bucket . '</Bucket><Key>' . htmlspecialchars( $key ) . '</Key><UploadId>' . $id . '</UploadId></InitiateMultipartUploadResult>' );
		}
		if ( isset( $query['uploadId'] ) ) {
			$id = $query['uploadId'];
			if ( ! isset( $this->uploads[ $id ] ) ) {
				return $this->error( 404, 'NoSuchUpload' );
			}
			if ( 'PUT' === $method ) {
				$etag = md5( $body );
				if ( isset( $headers['content-md5'] ) && base64_encode( md5( $body, true ) ) !== $headers['content-md5'] ) {
					return $this->error( 400, 'BadDigest' );
				}
				$this->uploads[ $id ]['parts'][ (int) $query['partNumber'] ] = array(
					'size' => strlen( $body ),
					'etag' => $etag,
					'body' => $this->blob( $body ),
				);
				return $this->ok( '', array( 'etag' => '"' . $etag . '"' ) );
			}
			if ( 'GET' === $method ) {
				return $this->list_parts( $id, $query );
			}
			if ( 'DELETE' === $method ) {
				unset( $this->uploads[ $id ] );
				return array(
					'code'    => 204,
					'headers' => array(),
					'body'    => '',
				);
			}
			if ( 'POST' === $method ) {
				return $this->complete( $id, $key, $body );
			}
		}
		if ( 'PUT' === $method ) {
			if ( isset( $headers['content-md5'] ) && base64_encode( md5( $body, true ) ) !== $headers['content-md5'] ) {
				return $this->error( 400, 'BadDigest' );
			}
			$etag                  = md5( $body );
			$this->objects[ $key ] = array(
				'size'     => strlen( $body ),
				'etag'     => $etag,
				'body'     => $this->blob( $body ),
				'modified' => $this->now,
			);
			return $this->ok( '', array( 'etag' => '"' . ( $this->corrupt_md5 ? str_repeat( '0', 32 ) : ( null !== $this->etag_override ? $this->etag_override : $etag ) ) . '"' ) );
		}
		if ( 'HEAD' === $method || 'GET' === $method ) {
			if ( ! isset( $this->objects[ $key ] ) ) {
				return 'HEAD' === $method ? array(
					'code'    => 404,
					'headers' => array(),
					'body'    => '',
				) : $this->error( 404, 'NoSuchKey' );
			}
			$o = $this->objects[ $key ];
			if ( 'HEAD' === $method ) {
				return $this->ok(
					'',
					array(
						'content-length' => (string) $o['size'],
						'etag'           => '"' . $o['etag'] . '"',
						'last-modified'  => gmdate( 'D, d M Y H:i:s', $o['modified'] ) . ' GMT',
					)
				);
			}
			if ( isset( $headers['range'] ) && preg_match( '/^bytes=(\d+)-(\d+)$/', $headers['range'], $m ) ) {
				$chunk = (string) file_get_contents( $o['body'], false, null, (int) $m[1], (int) $m[2] - (int) $m[1] + 1 );
				return array(
					'code'    => 206,
					'headers' => array(),
					'body'    => $chunk,
				);
			}
			return $this->ok( (string) file_get_contents( $o['body'] ) );
		}
		if ( 'DELETE' === $method ) {
			unset( $this->objects[ $key ] );
			return array(
				'code'    => 204,
				'headers' => array(),
				'body'    => '',
			);
		}
		return $this->error( 400, 'InvalidRequest' );
	}

	private function complete( $id, $key, $xml ) {
		if ( '' !== $this->complete_error ) {
			return $this->ok( $this->complete_error );
		}
		$doc = simplexml_load_string( $xml );
		$sum = 0;
		$md5 = '';
		foreach ( $doc->Part as $p ) {
			$n = (int) $p->PartNumber;
			if ( ! isset( $this->uploads[ $id ]['parts'][ $n ] ) || trim( (string) $p->ETag, '"' ) !== $this->uploads[ $id ]['parts'][ $n ]['etag'] ) {
				return $this->error( 400, 'InvalidPart' );
			}
			$sum += $this->uploads[ $id ]['parts'][ $n ]['size'];
			$md5 .= $this->uploads[ $id ]['parts'][ $n ]['etag'];
		}
		$joined  = $this->blob( '' );
		$numbers = array();
		foreach ( $doc->Part as $p ) {
			$numbers[] = (int) $p->PartNumber;
		}
		sort( $numbers );
		foreach ( $numbers as $n ) {
			file_put_contents( $joined, fopen( $this->uploads[ $id ]['parts'][ $n ]['body'], 'rb' ), FILE_APPEND );
		}
		$this->objects[ $key ] = array(
			'size'     => $sum,
			'etag'     => md5( $md5 ) . '-' . count( $doc->Part ),
			'body'     => $joined,
			'modified' => $this->now,
		);
		unset( $this->uploads[ $id ] );
		return $this->ok( '<?xml version="1.0"?><CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Key>' . htmlspecialchars( $key ) . '</Key></CompleteMultipartUploadResult>' );
	}

	private function list_parts( $id, array $query ) {
		$marker = isset( $query['part-number-marker'] ) ? (int) $query['part-number-marker'] : 0;
		$max    = min( $this->page, (int) $query['max-parts'] );
		$parts  = $this->uploads[ $id ]['parts'];
		ksort( $parts );
		$out  = '';
		$last = 0;
		$n    = 0;
		$more = false;
		foreach ( $parts as $number => $part ) {
			if ( $number <= $marker ) {
				continue;
			}
			if ( $n >= $max ) {
				$more = true;
				break;
			}
			$out .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>&quot;' . $part['etag'] . '&quot;</ETag><Size>' . $part['size'] . '</Size></Part>';
			$last = $number;
			++$n;
		}
		return $this->ok( '<?xml version="1.0"?><ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>' . ( $more ? 'true' : 'false' ) . '</IsTruncated><NextPartNumberMarker>' . $last . '</NextPartNumberMarker>' . $out . '</ListPartsResult>' );
	}

	private function list_objects( array $query ) {
		$prefix = isset( $query['prefix'] ) ? $query['prefix'] : '';
		$token  = isset( $query['continuation-token'] ) ? $query['continuation-token'] : '';
		$max    = min( $this->page, (int) $query['max-keys'] );
		$keys   = array_keys( $this->objects );
		sort( $keys );
		$out  = '';
		$n    = 0;
		$more = false;
		$last = '';
		foreach ( $keys as $key ) {
			if ( 0 !== strpos( $key, $prefix ) || ( '' !== $token && strcmp( $key, $token ) <= 0 ) ) {
				continue;
			}
			if ( $n >= $max ) {
				$more = true;
				break;
			}
			$o    = $this->objects[ $key ];
			$out .= '<Contents><Key>' . htmlspecialchars( $key ) . '</Key><LastModified>' . gmdate( 'Y-m-d\TH:i:s', $o['modified'] ) . '.000Z</LastModified><Size>' . $o['size'] . '</Size></Contents>';
			$last = $key;
			++$n;
		}
		return $this->ok( '<?xml version="1.0"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>' . ( $more ? 'true' : 'false' ) . '</IsTruncated>' . ( $more ? '<NextContinuationToken>' . htmlspecialchars( $last ) . '</NextContinuationToken>' : '' ) . $out . '</ListBucketResult>' );
	}

	private function list_uploads( array $query ) {
		$prefix = isset( $query['prefix'] ) ? $query['prefix'] : '';
		$out    = '';
		foreach ( $this->uploads as $id => $u ) {
			if ( 0 === strpos( $u['key'], $prefix ) ) {
				$out .= '<Upload><Key>' . htmlspecialchars( $u['key'] ) . '</Key><UploadId>' . $id . '</UploadId><Initiated>' . gmdate( 'Y-m-d\TH:i:s', $u['initiated'] ) . '.000Z</Initiated></Upload>';
			}
		}
		return $this->ok( '<?xml version="1.0"?><ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>false</IsTruncated>' . $out . '</ListMultipartUploadsResult>' );
	}

	private function ok( $body, array $headers = array() ) {
		return array(
			'code'    => 200,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	private function error( $code, $s3code, $head = false ) {
		return array(
			'code'    => $code,
			'headers' => array(),
			'body'    => $head ? '' : '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $s3code . '</Code><Message>secret details ' . $this->secret . '</Message><RequestId>1</RequestId></Error>',
		);
	}

	private function signature_ok( $method, $path, array $query, array $headers, $host, $body ) {
		if ( ! isset( $headers['authorization'], $headers['x-amz-date'], $headers['x-amz-content-sha256'] ) ) {
			return false;
		}
		if ( ! preg_match( '/Credential=([^\/]+)\/(\d{8})\/([^\/]+)\/s3\/aws4_request, SignedHeaders=([^,]+), Signature=([0-9a-f]{64})/', $headers['authorization'], $m ) ) {
			return false;
		}
		if ( $m[1] !== $this->access || SigV4Signer::payload_hash( $body ) !== $headers['x-amz-content-sha256'] ) {
			return false;
		}
		$sign = array( 'host' => $host );
		foreach ( explode( ';', $m[4] ) as $name ) {
			if ( 'host' !== $name ) {
				if ( ! isset( $headers[ $name ] ) ) {
					return false;
				}
				$sign[ $name ] = $headers[ $name ];
			}
		}
		$time   = (int) strtotime( substr( $headers['x-amz-date'], 0, 4 ) . '-' . substr( $headers['x-amz-date'], 4, 2 ) . '-' . substr( $headers['x-amz-date'], 6, 2 ) . 'T' . substr( $headers['x-amz-date'], 9, 2 ) . ':' . substr( $headers['x-amz-date'], 11, 2 ) . ':' . substr( $headers['x-amz-date'], 13, 2 ) . 'Z' );
		$signer = new SigV4Signer(
			$this->access,
			$this->secret,
			$m[3],
			's3',
			static function () use ( $time ) {
				return $time;
			}
		);
		unset( $sign['x-amz-date'], $sign['x-amz-content-sha256'] );
		$again = $signer->sign( $method, rawurldecode( $path ), $query, $sign, $headers['x-amz-content-sha256'] );
		return hash_equals( $again['authorization'], $headers['authorization'] );
	}
}
