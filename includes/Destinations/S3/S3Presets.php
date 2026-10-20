<?php
/**
 * Provider presets and settings validation for S3-compatible destinations (pure logic).
 *
 * Phase: MVP
 *
 * Purpose: Turns the saved settings (preset, region or account id, endpoint, addressing, bucket, prefix ...) into a
 * validated configuration: endpoint parts, addressing style, signing region, bucket, key prefix, storage class.
 *
 * Presets use only the endpoint patterns documented by each provider (see website/src/data/providers.ts):
 *  - aws:    https://s3.<region>.amazonaws.com        (virtual-hosted by default)
 *  - r2:     https://<account_id>.r2.cloudflarestorage.com, region "auto", path-style
 *  - b2:     https://s3.<region>.backblazeb2.com      (path-style by default)
 *  - wasabi: https://s3.<region>.wasabisys.com        (path-style by default)
 *  - spaces: https://<region>.digitaloceanspaces.com  (signed with us-east-1, which DigitalOcean documents as
 *            validation only; path-style by default)
 *  - custom: any endpoint (required), path-style by default; MinIO, Ceph, IDrive e2 and so on.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings; they are stored and shown through escaping output functions, never echoed as HTML here.

namespace OpenVault\Backup\Destinations\S3;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\RemotePath;

defined( 'ABSPATH' ) || exit;

/**
 * Presets.
 */
final class S3Presets {

	const STORAGE_CLASSES = array( 'STANDARD', 'STANDARD_IA', 'ONEZONE_IA', 'INTELLIGENT_TIERING', 'GLACIER_IR' );

	/**
	 * Preset table: label, how the endpoint is built, the field that is needed and its pattern.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function all() {
		return array(
			'aws'    => array(
				'label'      => __( 'Amazon S3', 'openvault-backup' ),
				'host'       => 's3.{region}.amazonaws.com',
				'field'      => 'region',
				'pattern'    => '/^[a-z]{2}(-[a-z]+)+-\d{1,2}\z/',
				'addressing' => 'virtual',
				'docs'       => 'https://docs.aws.amazon.com/general/latest/gr/s3.html',
			),
			'r2'     => array(
				'label'      => __( 'Cloudflare R2', 'openvault-backup' ),
				'host'       => '{account_id}.r2.cloudflarestorage.com',
				'field'      => 'account_id',
				'pattern'    => '/^[0-9a-f]{32}\z/',
				'addressing' => 'path',
				'docs'       => 'https://developers.cloudflare.com/r2/api/s3/api/',
			),
			'b2'     => array(
				'label'      => __( 'Backblaze B2', 'openvault-backup' ),
				'host'       => 's3.{region}.backblazeb2.com',
				'field'      => 'region',
				'pattern'    => '/^[a-z]{2}-[a-z]+-\d{3}\z/',
				'addressing' => 'path',
				'docs'       => 'https://www.backblaze.com/docs/cloud-storage-call-the-s3-compatible-api',
			),
			'wasabi' => array(
				'label'      => __( 'Wasabi', 'openvault-backup' ),
				'host'       => 's3.{region}.wasabisys.com',
				'field'      => 'region',
				'pattern'    => '/^[a-z]{2}(-[a-z0-9]+){1,2}\z/',
				'addressing' => 'path',
				'docs'       => 'https://docs.wasabi.com/docs/what-are-the-service-urls-for-wasabis-different-storage-regions',
			),
			'spaces' => array(
				'label'      => __( 'DigitalOcean Spaces', 'openvault-backup' ),
				'host'       => '{region}.digitaloceanspaces.com',
				'field'      => 'region',
				'pattern'    => '/^[a-z]{3}\d{1,2}\z/',
				'addressing' => 'path',
				'docs'       => 'https://docs.digitalocean.com/products/spaces/how-to/use-aws-sdks/',
			),
			'custom' => array(
				'label'      => __( 'Custom S3-compatible endpoint', 'openvault-backup' ),
				'host'       => '',
				'field'      => 'endpoint',
				'pattern'    => '',
				'addressing' => 'path',
				'docs'       => '',
			),
		);
	}

	/**
	 * Whether a bucket name is acceptable (3 to 63 characters: lower-case letters, digits, "." and "-", starting and ending
	 * with a letter or digit, no "..", not an IP address).
	 *
	 * @param mixed $bucket Bucket.
	 * @return bool
	 */
	public static function is_bucket( $bucket ) {
		return is_string( $bucket )
			&& 1 === preg_match( '/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]\z/', $bucket )
			&& false === strpos( $bucket, '..' )
			&& 1 !== preg_match( '/^\d+\.\d+\.\d+\.\d+\z/', $bucket );
	}

	/**
	 * Whether a bucket can be addressed as a sub-domain (virtual-hosted style): no dots, so the TLS certificate matches.
	 *
	 * @param string $bucket Bucket.
	 * @return bool
	 */
	public static function is_dns_safe( $bucket ) {
		return self::is_bucket( $bucket ) && false === strpos( $bucket, '.' );
	}

	/**
	 * Normalise a key prefix: no leading or trailing slash, no empty, "." or ".." segment.
	 *
	 * @param mixed $prefix Prefix ("" = bucket root).
	 * @return string
	 * @throws DestinationException For an unsafe prefix.
	 */
	public static function clean_prefix( $prefix ) {
		$prefix = trim( (string) $prefix );
		$prefix = trim( (string) preg_replace( '#/+#', '/', $prefix ), '/' );
		if ( '' === $prefix ) {
			return '';
		}
		if ( strlen( $prefix ) > 200 ) {
			throw DestinationException::permanent( __( 'The folder prefix is too long.', 'openvault-backup' ) );
		}
		foreach ( explode( '/', $prefix ) as $segment ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9._\-]+\z/', $segment ) || '.' === $segment || '..' === $segment ) {
				throw DestinationException::permanent( __( 'The folder prefix may only contain letters, digits, ".", "_", "-" and "/", without "." or ".." parts.', 'openvault-backup' ) );
			}
		}
		return $prefix;
	}

	/**
	 * Turn saved settings into a validated configuration.
	 *
	 * @param array<string,mixed> $settings preset, region, account_id, endpoint, addressing (path|virtual|""), bucket, prefix,
	 *                                      allow_insecure_http, allow_private_endpoint, storage_class.
	 * @return array{preset:string,label:string,scheme:string,host:string,port:?int,private:bool,addressing:string,region:string,bucket:string,prefix:string,storage_class:string,allow_private:bool}
	 * @throws DestinationException With a safe message for any invalid setting.
	 */
	public static function resolve( array $settings ) {
		$presets = self::all();
		$preset  = isset( $settings['preset'] ) ? (string) $settings['preset'] : 'custom';
		if ( ! isset( $presets[ $preset ] ) ) {
			throw DestinationException::permanent( __( 'Unknown storage provider preset.', 'openvault-backup' ) );
		}
		$def           = $presets[ $preset ];
		$allow_http    = self::flag( $settings, 'allow_insecure_http' );
		$allow_private = self::flag( $settings, 'allow_private_endpoint' );

		$region = isset( $settings['region'] ) ? trim( (string) $settings['region'] ) : '';
		if ( 'custom' === $preset ) {
			$endpoint = isset( $settings['endpoint'] ) ? (string) $settings['endpoint'] : '';
			if ( '' === trim( $endpoint ) ) {
				throw DestinationException::permanent( __( 'Enter the endpoint of the storage server.', 'openvault-backup' ) );
			}
			$region = '' === $region ? 'us-east-1' : $region;
			if ( 1 !== preg_match( '/^[a-z0-9\-]{1,32}\z/', $region ) ) {
				throw DestinationException::permanent( __( 'The region may only contain lower-case letters, digits and "-".', 'openvault-backup' ) );
			}
			$signing = $region;
		} else {
			$field = $def['field'];
			$value = 'account_id' === $field ? ( isset( $settings['account_id'] ) ? trim( strtolower( (string) $settings['account_id'] ) ) : '' ) : $region;
			if ( '' === $value || 1 !== preg_match( $def['pattern'], $value ) ) {
				throw DestinationException::permanent(
					'account_id' === $field
						? __( 'The Cloudflare account ID must be 32 hexadecimal characters.', 'openvault-backup' )
						: __( 'The region is missing or not valid for this provider (for example eu-central-1).', 'openvault-backup' )
				);
			}
			$endpoint = 'https://' . str_replace( array( '{region}', '{account_id}' ), $value, $def['host'] );
			$signing  = 'r2' === $preset ? 'auto' : ( 'spaces' === $preset ? 'us-east-1' : $value );
		}//end if

		$parsed = EndpointValidator::parse( $endpoint, $allow_http, $allow_private );

		$bucket = isset( $settings['bucket'] ) ? trim( (string) $settings['bucket'] ) : '';
		if ( ! self::is_bucket( $bucket ) ) {
			throw DestinationException::permanent( __( 'The bucket name is missing or not valid (3 to 63 lower-case letters, digits, "." or "-").', 'openvault-backup' ) );
		}

		$addressing = isset( $settings['addressing'] ) ? (string) $settings['addressing'] : '';
		if ( 'path' !== $addressing && 'virtual' !== $addressing ) {
			$addressing = $def['addressing'];
		}
		if ( 'virtual' === $addressing && ( ! self::is_dns_safe( $bucket ) || $parsed['ip'] ) ) {
			$addressing = 'path';
			// A dotted bucket breaks the TLS certificate, an IP has no sub-domains.
		}

		$class = isset( $settings['storage_class'] ) ? strtoupper( trim( (string) $settings['storage_class'] ) ) : '';
		if ( '' !== $class && ! in_array( $class, self::STORAGE_CLASSES, true ) ) {
			throw DestinationException::permanent( __( 'This storage class is not supported.', 'openvault-backup' ) );
		}

		return array(
			'preset'        => $preset,
			'label'         => $def['label'],
			'scheme'        => $parsed['scheme'],
			'host'          => $parsed['host'],
			'port'          => $parsed['port'],
			'private'       => $parsed['private'],
			'addressing'    => $addressing,
			'region'        => $signing,
			'bucket'        => $bucket,
			'prefix'        => self::clean_prefix( isset( $settings['prefix'] ) ? $settings['prefix'] : '' ),
			'storage_class' => 'STANDARD' === $class ? '' : $class,
			'allow_private' => $allow_private,
		);
	}

	/**
	 * Build the object key of a relative remote path below the prefix.
	 *
	 * @param string $prefix Normalised prefix.
	 * @param string $path   Relative remote path.
	 * @return string
	 * @throws DestinationException When the path is unsafe or the key too long.
	 */
	public static function key( $prefix, $path ) {
		if ( ! RemotePath::is_safe( $path ) || 0 !== strpos( (string) $path, RemotePath::ROOT . '/' ) ) {
			throw DestinationException::permanent( __( 'This remote path is not allowed.', 'openvault-backup' ) );
		}
		$key = '' === $prefix ? $path : $prefix . '/' . $path;
		if ( strlen( $key ) > 1024 ) {
			throw DestinationException::permanent( __( 'The remote path is too long for this storage.', 'openvault-backup' ) );
		}
		return $key;
	}

	/**
	 * Boolean setting.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $name     Name.
	 * @return bool
	 */
	private static function flag( array $settings, $name ) {
		return isset( $settings[ $name ] ) && ( true === $settings[ $name ] || 1 === $settings[ $name ] || '1' === $settings[ $name ] || 'true' === $settings[ $name ] );
	}
}
