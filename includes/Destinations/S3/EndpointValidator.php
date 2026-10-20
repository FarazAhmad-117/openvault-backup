<?php
/**
 * Validates the endpoint of an S3-compatible destination (pure logic, SSRF guard).
 *
 * Phase: MVP
 *
 * Purpose: The endpoint is user input and the plugin's server will connect to it, so it is checked strictly.
 *
 * Rules:
 *  - https is required. http is accepted only when allow_insecure_http is true AND the host is private (loopback,
 *    private range, ".local", a plain host name without dots such as a Docker service name).
 *  - A private host at all (also over https) needs allow_private_endpoint. Public hosts may only use ports 443, 80
 *    and 8080 (WordPress refuses other ports for unsafe URLs).
 *  - No user info, no query, no fragment, no path (a bare "/" is fine), no whitespace, control or backslash characters.
 *  - Host names: ASCII letters, digits and "-" in dot separated labels (IDN: use the punycode form).
 *  - IP addresses must be a clean dotted quad or a bracketed IPv6 address. Decimal, hex and octal spellings
 *    ("2130706433", "0x7f.1", "017700000001") are refused.
 *  - ALWAYS refused, whatever the allow flags say: link-local and cloud metadata addresses (169.254.0.0/16,
 *    fe80::/10, fd00:ec2::254, metadata.google.internal, ...), the unspecified addresses (0.0.0.0/8, ::) and
 *    the broadcast address.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings; they are stored and shown through escaping output functions, never echoed as HTML here.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, runs without WordPress in unit tests; the result is validated field by field.

namespace OpenVault\Backup\Destinations\S3;

use OpenVault\Backup\Destinations\DestinationException;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint validator.
 */
final class EndpointValidator {

	const MAX_LENGTH = 255;

	const BLOCKED_HOSTS = array( 'metadata.google.internal', 'metadata.goog', 'metadata', 'instance-data', 'instance-data.ec2.internal' );

	/**
	 * Validate an endpoint.
	 *
	 * @param mixed $endpoint      Endpoint such as "https://s3.eu-west-1.amazonaws.com".
	 * @param bool  $allow_http    Setting allow_insecure_http.
	 * @param bool  $allow_private Setting allow_private_endpoint.
	 * @return array{scheme:string,host:string,port:?int,private:bool,ip:bool} Port is null for the scheme's default.
	 * @throws DestinationException With a safe message when the endpoint is not acceptable.
	 */
	public static function parse( $endpoint, $allow_http = false, $allow_private = false ) {
		if ( ! is_string( $endpoint ) ) {
			throw self::refuse( __( 'Enter the endpoint of the storage server, for example https://s3.example.com.', 'openvault-backup' ) );
		}
		$endpoint = trim( $endpoint );
		if ( '' === $endpoint || strlen( $endpoint ) > self::MAX_LENGTH ) {
			throw self::refuse( __( 'The endpoint is empty or too long.', 'openvault-backup' ) );
		}
		if ( 1 === preg_match( '/[\x00-\x20\x7f-\xff\\\\?#@]/', $endpoint ) ) {
			throw self::refuse( __( 'The endpoint must be a plain address like https://host (no spaces, user name, password, query or special characters; use the punycode form for international names).', 'openvault-backup' ) );
		}

		$parts = parse_url( $endpoint );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			throw self::refuse( __( 'The endpoint is not a valid address. Use the form https://host.', 'openvault-backup' ) );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			throw self::refuse( __( 'The endpoint must start with https://.', 'openvault-backup' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ( isset( $parts['path'] ) && '/' !== $parts['path'] ) ) {
			throw self::refuse( __( 'The endpoint must be only a host name and an optional port, without a path, user name, password or query.', 'openvault-backup' ) );
		}

		$port = null;
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( $port < 1 || $port > 65535 ) {
				throw self::refuse( __( 'The port of the endpoint is not valid.', 'openvault-backup' ) );
			}
		} elseif ( ':' === substr( rtrim( $endpoint, '/' ), -1 ) ) {
			throw self::refuse( __( 'The port of the endpoint is not valid.', 'openvault-backup' ) );
		}

		$host = self::clean_host( (string) $parts['host'] );
		$ip   = self::ip_of( $host );
		if ( false === $ip ) {
			self::check_name( $host );
		}
		self::refuse_dangerous( $host, $ip );

		$private = self::is_private( $host, $ip );
		if ( $private && ! $allow_private ) {
			throw self::refuse( __( 'This address is on a private or local network. Turn on "allow private endpoint" only for a server you run yourself.', 'openvault-backup' ) );
		}
		if ( 'http' === $scheme ) {
			if ( ! $allow_http ) {
				throw self::refuse( __( 'The endpoint must use https://. Plain http is only possible for a private test server when it is explicitly allowed.', 'openvault-backup' ) );
			}
			if ( ! $private ) {
				throw self::refuse( __( 'Plain http is only allowed for private or local hosts. Use https:// for this address.', 'openvault-backup' ) );
			}
		}
		if ( ! $private && null !== $port && ! in_array( $port, array( 443, 80, 8080 ), true ) ) {
			throw self::refuse( __( 'A public endpoint can only use port 443 (the default). Other ports are only possible for private servers.', 'openvault-backup' ) );
		}
		if ( null !== $port && ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) ) {
			$port = null;
		}

		return array(
			'scheme'  => $scheme,
			'host'    => $host,
			'port'    => $port,
			'private' => $private,
			'ip'      => false !== $ip,
		);
	}

	/**
	 * "scheme://host[:port]" of a parsed endpoint.
	 *
	 * @param array{scheme:string,host:string,port:?int} $parsed Result of parse().
	 * @return string
	 */
	public static function origin( array $parsed ) {
		return $parsed['scheme'] . '://' . $parsed['host'] . ( null === $parsed['port'] ? '' : ':' . $parsed['port'] );
	}

	/**
	 * Whether a host is a private, loopback or local one (no network access needed: names are judged by their form).
	 *
	 * @param string       $host Host.
	 * @param string|false $ip   Packed binary address as returned by ip_of(), or false for a name.
	 * @return bool
	 */
	public static function is_private( $host, $ip ) {
		if ( false !== $ip ) {
			$text = (string) inet_ntop( $ip );
			return false === filter_var( $text, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
				|| 1 === preg_match( '/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $text );
		}
		return 'localhost' === $host
			|| false === strpos( $host, '.' )
			|| 1 === preg_match( '/\.(local|localhost|internal|lan|home|intranet|corp)\z/', $host );
	}

	/**
	 * Packed address of an IP literal (bracketed IPv6 or clean dotted quad), false for a name.
	 *
	 * @param string $host Lower-case host as given by parse_url().
	 * @return string|false
	 * @throws DestinationException For an IP written in an unusual way.
	 */
	public static function ip_of( $host ) {
		if ( '[' === substr( $host, 0, 1 ) ) {
			$inner = substr( $host, 1, -1 );
			if ( ']' !== substr( $host, -1 ) || false === filter_var( $inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				throw self::refuse( __( 'The IPv6 address of the endpoint is not valid.', 'openvault-backup' ) );
			}
			return (string) inet_pton( $inner );
		}
		// Anything that ends in a number or a 0x hex number is an IPv4 spelling (decimal, octal, hex, short forms): it must be a clean dotted quad.
		if ( 1 === preg_match( '/(^|\.)(0x[0-9a-f]*|\d+)\z/', $host ) ) {
			if ( false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				throw self::refuse( __( 'IP addresses must be written as four plain numbers, like 203.0.113.10.', 'openvault-backup' ) );
			}
			return (string) inet_pton( $host );
		}
		return false;
	}

	/**
	 * Lower-case, without one trailing dot.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function clean_host( $host ) {
		$host = strtolower( $host );
		return '.' === substr( $host, -1 ) ? substr( $host, 0, -1 ) : $host;
	}

	/**
	 * Check a DNS name.
	 *
	 * @param string $host Name.
	 * @return void
	 * @throws DestinationException When it is not a valid host name.
	 */
	private static function check_name( $host ) {
		if ( '' === $host || strlen( $host ) > 253 ) {
			throw self::refuse( __( 'The host name of the endpoint is not valid.', 'openvault-backup' ) );
		}
		foreach ( explode( '.', $host ) as $label ) {
			if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\z/', $label ) ) {
				throw self::refuse( __( 'The host name of the endpoint is not valid. Use letters, digits and "-" only (international names in punycode form).', 'openvault-backup' ) );
			}
		}
	}

	/**
	 * Refuse link-local, metadata, unspecified and broadcast targets whatever the allow flags are.
	 *
	 * @param string       $host Host.
	 * @param string|false $ip   Packed address or false.
	 * @return void
	 * @throws DestinationException When the target is never allowed.
	 */
	private static function refuse_dangerous( $host, $ip ) {
		$message = __( 'This address is never allowed (link-local, cloud metadata or reserved).', 'openvault-backup' );
		if ( false === $ip ) {
			if ( in_array( $host, self::BLOCKED_HOSTS, true ) || 1 === preg_match( '/\.(metadata\.google\.internal)\z/', $host ) ) {
				throw self::refuse( $message );
			}
			return;
		}
		// IPv4 mapped IPv6 (::ffff:a.b.c.d) is judged by its IPv4 part.
		if ( 16 === strlen( $ip ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $ip, 0, 12 ) ) {
			$ip = substr( $ip, 12 );
		}
		if ( 4 === strlen( $ip ) ) {
			$first = ord( $ip[0] );
			if ( 0 === $first || ( 169 === $first && 254 === ord( $ip[1] ) ) || "\xff\xff\xff\xff" === $ip ) {
				throw self::refuse( $message );
			}
			return;
		}
		$text = (string) inet_ntop( $ip );
		$top  = ord( $ip[0] ) * 256 + ord( $ip[1] );
		if ( str_repeat( "\0", 16 ) === $ip || 0xfe80 === ( $top & 0xffc0 ) || 0 === strcasecmp( $text, 'fd00:ec2::254' ) ) {
			throw self::refuse( $message );
		}
	}

	/**
	 * A permanent error with a safe message.
	 *
	 * @param string $message Message.
	 * @return DestinationException
	 */
	private static function refuse( $message ) {
		return DestinationException::permanent( $message );
	}
}
