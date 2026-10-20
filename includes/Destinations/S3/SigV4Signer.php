<?php
/**
 * AWS Signature Version 4 for the S3 service (pure logic, no WordPress, no AWS SDK).
 *
 * Phase: MVP
 *
 * Purpose: Lightweight signer used by every S3-compatible provider.
 *
 * Responsibilities:
 *  - canonical request, string to sign, signing key and the Authorization header (header based auth)
 *  - S3 URI rules: every path segment is encoded once, "/" is kept, duplicate slashes are preserved (S3 does not
 *    normalise paths), unreserved characters are A-Z a-z 0-9 - _ . ~ and hex digits are upper case
 *  - canonical query string sorted by encoded name then value; headers lower-cased, trimmed, folded and sorted
 *  - x-amz-content-sha256 is the real payload hash; UNSIGNED-PAYLOAD only when the caller passes it explicitly
 *  - injectable clock (unix time) so tests are deterministic
 *  - the secret key is held only in a private property and never leaves the class (no getter, never in an exception)
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Destinations\S3;

defined( 'ABSPATH' ) || exit;

/**
 * SigV4 signer.
 */
final class SigV4Signer {

	const ALGORITHM    = 'AWS4-HMAC-SHA256';
	const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
	const UNSIGNED     = 'UNSIGNED-PAYLOAD';

	/**
	 * Access key id.
	 *
	 * @var string
	 */
	private $access_key;

	/**
	 * Secret access key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Signing region.
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Service name.
	 *
	 * @var string
	 */
	private $service;

	/**
	 * Clock returning a unix timestamp (null = time()).
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param string        $access_key Access key id.
	 * @param string        $secret_key Secret access key.
	 * @param string        $region     Signing region.
	 * @param string        $service    Service name.
	 * @param callable|null $clock      Returns the unix time; defaults to time().
	 */
	public function __construct( $access_key, $secret_key, $region, $service = 's3', ?callable $clock = null ) {
		$this->access_key = (string) $access_key;
		$this->secret_key = (string) $secret_key;
		$this->region     = (string) $region;
		$this->service    = (string) $service;
		$this->clock      = $clock;
	}

	/**
	 * Hide the secret from var_dump(), print_r() and error dumps.
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo() {
		return array(
			'region'  => $this->region,
			'service' => $this->service,
		);
	}

	/**
	 * Hex SHA-256 of a payload.
	 *
	 * @param string $payload Payload.
	 * @return string
	 */
	public static function payload_hash( $payload ) {
		return hash( 'sha256', (string) $payload );
	}

	/**
	 * RFC 3986 percent encoding with upper case hex; only A-Z a-z 0-9 - _ . ~ stay.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function encode( $value ) {
		return rawurlencode( (string) $value );
	}

	/**
	 * Encode an object path: every segment once, "/" kept, empty segments kept. The result is both the canonical URI
	 * and the path that goes on the wire.
	 *
	 * @param string $path Raw path (not encoded).
	 * @return string
	 */
	public static function encode_path( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return '/';
		}
		return implode( '/', array_map( array( __CLASS__, 'encode' ), explode( '/', $path ) ) );
	}

	/**
	 * Canonical query string: names and values encoded, sorted by encoded name then value, "name=value" joined by "&".
	 *
	 * @param array<string,string|int|null> $query Raw query parameters.
	 * @return string
	 */
	public static function canonical_query( array $query ) {
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[] = array( self::encode( $name ), self::encode( null === $value ? '' : $value ) );
		}
		usort(
			$pairs,
			static function ( $a, $b ) {
				$by_name = strcmp( $a[0], $b[0] );
				return 0 !== $by_name ? $by_name : strcmp( $a[1], $b[1] );
			}
		);
		$out = array();
		foreach ( $pairs as $pair ) {
			$out[] = $pair[0] . '=' . $pair[1];
		}
		return implode( '&', $out );
	}

	/**
	 * Canonical headers block and the signed header list.
	 *
	 * @param array<string,string> $headers Header name => value.
	 * @return array{0:string,1:string} Block (each line ends with a newline) and "a;b;c".
	 */
	public static function canonical_headers( array $headers ) {
		$lines = array();
		foreach ( $headers as $name => $value ) {
			$lines[ strtolower( trim( (string) $name ) ) ] = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
		}
		ksort( $lines, SORT_STRING );
		$block = '';
		foreach ( $lines as $name => $value ) {
			$block .= $name . ':' . $value . "\n";
		}
		return array( $block, implode( ';', array_keys( $lines ) ) );
	}

	/**
	 * Canonical request.
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $path         Raw path, e.g. "/bucket/my key".
	 * @param array<string,string> $query        Raw query parameters.
	 * @param array<string,string> $headers      Headers to sign (must include host, x-amz-content-sha256 and x-amz-date).
	 * @param string               $payload_hash Hex payload hash or UNSIGNED-PAYLOAD.
	 * @return string
	 */
	public function canonical_request( $method, $path, array $query, array $headers, $payload_hash ) {
		list( $block, $signed ) = self::canonical_headers( $headers );
		return strtoupper( (string) $method ) . "\n"
			. self::encode_path( $path ) . "\n"
			. self::canonical_query( $query ) . "\n"
			. $block . "\n"
			. $signed . "\n"
			. $payload_hash;
	}

	/**
	 * String to sign.
	 *
	 * @param string $canonical_request Canonical request.
	 * @param string $amz_date          Time as YYYYMMDDTHHMMSSZ.
	 * @return string
	 */
	public function string_to_sign( $canonical_request, $amz_date ) {
		return self::ALGORITHM . "\n" . $amz_date . "\n" . $this->scope( substr( $amz_date, 0, 8 ) ) . "\n" . hash( 'sha256', $canonical_request );
	}

	/**
	 * Hex signature of a string to sign.
	 *
	 * @param string $string_to_sign String to sign.
	 * @param string $date           Date as YYYYMMDD.
	 * @return string
	 */
	public function signature( $string_to_sign, $date ) {
		$key = hash_hmac( 'sha256', 'aws4_request', hash_hmac( 'sha256', $this->service, hash_hmac( 'sha256', $this->region, hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true ), true ), true ), true );
		return hash_hmac( 'sha256', $string_to_sign, $key );
	}

	/**
	 * Sign a request. Returns every header to send: the given ones, x-amz-date, x-amz-content-sha256 and Authorization.
	 * Every given header is signed, so pass only headers that really go on the wire unchanged (host included).
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $path         Raw path.
	 * @param array<string,string> $query        Raw query parameters.
	 * @param array<string,string> $headers      Headers; "host" is required.
	 * @param string               $payload_hash Hex payload hash (default: hash of the empty payload) or UNSIGNED-PAYLOAD.
	 * @return array<string,string> Header name => value (lower-case names).
	 */
	public function sign( $method, $path, array $query, array $headers, $payload_hash = self::EMPTY_SHA256 ) {
		$timestamp = null === $this->clock ? time() : (int) call_user_func( $this->clock );
		$amz_date  = gmdate( 'Ymd\THis\Z', $timestamp );

		$signed = array();
		foreach ( $headers as $name => $value ) {
			$signed[ strtolower( trim( (string) $name ) ) ] = (string) $value;
		}
		$signed['x-amz-date']           = $amz_date;
		$signed['x-amz-content-sha256'] = $payload_hash;

		$canonical       = $this->canonical_request( $method, $path, $query, $signed, $payload_hash );
		$signature       = $this->signature( $this->string_to_sign( $canonical, $amz_date ), substr( $amz_date, 0, 8 ) );
		list( , $names ) = self::canonical_headers( $signed );

		$signed['authorization'] = self::ALGORITHM . ' Credential=' . $this->access_key . '/' . $this->scope( substr( $amz_date, 0, 8 ) )
			. ', SignedHeaders=' . $names . ', Signature=' . $signature;
		return $signed;
	}

	/**
	 * Credential scope.
	 *
	 * @param string $date YYYYMMDD.
	 * @return string
	 */
	private function scope( $date ) {
		return $date . '/' . $this->region . '/' . $this->service . '/aws4_request';
	}
}
