<?php
/**
 * Validation of what the browser sends when a destination is created or edited (pure logic).
 *
 * Phase: MVP
 *
 * Purpose: One place that turns untrusted REST input into the settings and credentials that are stored.
 *
 * Rules:
 *  - Only types with a validator here can be created from the browser (local, s3); the registry may know more.
 *  - Unknown keys, wrong types and values that do not apply to the chosen preset are refused, never ignored.
 *  - s3: the real checks are S3Presets::resolve() (preset, region or account id, bucket, prefix, storage class) and
 *    EndpointValidator (SSRF guard); this class adds the key whitelist, strict types, the "endpoint only for custom"
 *    rule and a leading "/" in the prefix is refused. The stored settings are normalised (only the keys that matter).
 *  - local: "path" empty (= the plugin's own protected folder) or an absolute folder checked by LocalDestination itself.
 *  - Credentials (s3 access key and secret key) are checked for shape and length only. They are write-only: nothing
 *    here ever puts a submitted value into a message, so no error can leak a secret.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings; they never contain submitted values and are shown through escaping output functions.

namespace OpenVault\Backup\Destinations;

use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Database\Util;
use OpenVault\Backup\Destinations\Local\LocalDestination;
use OpenVault\Backup\Destinations\S3\S3Presets;

defined( 'ABSPATH' ) || exit;

/**
 * Destination input validation.
 */
final class DestinationSettings {

	const TYPES     = array( 'local', 's3' );
	const LABEL_MAX = 100;
	const TEXT_MAX  = 255;

	const S3_KEYS = array( 'preset', 'region', 'account_id', 'endpoint', 'bucket', 'prefix', 'addressing', 'storage_class', 'allow_insecure_http', 'allow_private_endpoint' );
	const S3_FLAG = array( 'allow_insecure_http', 'allow_private_endpoint' );

	/**
	 * Whether a destination of this type can be created from the browser.
	 *
	 * @param mixed $type Type.
	 * @return bool
	 */
	public static function is_supported_type( $type ) {
		return is_string( $type ) && in_array( $type, self::TYPES, true );
	}

	/**
	 * A label as stored: control characters removed, trimmed.
	 *
	 * @param mixed $label Label.
	 * @return string
	 */
	public static function clean_label( $label ) {
		return Util::clean_text( $label, self::LABEL_MAX * 4 );
	}

	/**
	 * Whether a label is acceptable: text of 1 to 100 characters.
	 *
	 * @param mixed $label Label.
	 * @return bool
	 */
	public static function is_label( $label ) {
		if ( ! is_string( $label ) || strlen( $label ) > self::LABEL_MAX * 8 ) {
			return false;
		}
		$clean  = self::clean_label( $label );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $clean, 'UTF-8' ) : strlen( $clean );
		return '' !== $clean && $length <= self::LABEL_MAX;
	}

	/**
	 * Validate and normalise the settings of a destination.
	 *
	 * @param string        $type        Destination type.
	 * @param mixed         $input       Submitted settings.
	 * @param callable|null $local_check Checks a local folder: function( array $settings ) that throws a DestinationException
	 *                                   (defaults to LocalDestination::validate()). Tests inject one.
	 * @return array<string,mixed> The settings to store.
	 * @throws DestinationException With a safe message that never contains a submitted value.
	 */
	public static function validate_settings( $type, $input, ?callable $local_check = null ) {
		if ( 's3' === $type ) {
			return self::validate_s3( $input );
		}
		if ( 'local' === $type ) {
			return self::validate_local( $input, $local_check );
		}
		throw DestinationException::permanent( __( 'This destination type cannot be set up here.', 'openvault-backup' ) );
	}

	/**
	 * Validate the credentials of a destination.
	 *
	 * @param string $type     Destination type.
	 * @param mixed  $input    Submitted credentials (null or empty = none).
	 * @param bool   $required Whether credentials are needed (creating a destination).
	 * @return array<string,string> The credentials to store; an empty array means "none supplied, keep the stored ones".
	 * @throws DestinationException With a safe message that never contains a submitted value.
	 */
	public static function validate_credentials( $type, $input, $required ) {
		$none = null === $input || array() === $input;
		if ( 's3' !== $type ) {
			if ( $none ) {
				return array();
			}
			throw DestinationException::permanent( __( 'This destination type does not use credentials.', 'openvault-backup' ) );
		}
		if ( $none ) {
			if ( $required ) {
				throw DestinationException::permanent( __( 'Enter the access key and the secret key.', 'openvault-backup' ) );
			}
			return array();
		}
		if ( ! is_array( $input ) ) {
			throw DestinationException::permanent( __( 'The credentials are not valid.', 'openvault-backup' ) );
		}
		foreach ( array_keys( $input ) as $key ) {
			if ( 'access_key' !== $key && 'secret_key' !== $key ) {
				throw DestinationException::permanent( __( 'The credentials contain a field that is not allowed.', 'openvault-backup' ) );
			}
		}
		$access = isset( $input['access_key'] ) ? $input['access_key'] : '';
		$secret = isset( $input['secret_key'] ) ? $input['secret_key'] : '';
		if ( ! is_string( $access ) || ! is_string( $secret ) ) {
			throw DestinationException::permanent( __( 'The credentials are not valid.', 'openvault-backup' ) );
		}
		$access = trim( $access );
		$secret = trim( $secret );
		if ( '' === $access && '' === $secret ) {
			if ( $required ) {
				throw DestinationException::permanent( __( 'Enter the access key and the secret key.', 'openvault-backup' ) );
			}
			return array();
		}
		if ( '' === $access || '' === $secret ) {
			throw DestinationException::permanent( __( 'Enter both the access key and the secret key. To keep the saved keys, leave both empty.', 'openvault-backup' ) );
		}
		if ( 1 !== preg_match( '/^[\x21-\x7e]{4,128}\z/', $access ) ) {
			throw DestinationException::permanent( __( 'The access key must be 4 to 128 visible characters without spaces.', 'openvault-backup' ) );
		}
		if ( 1 !== preg_match( '/^[\x21-\x7e]{8,256}\z/', $secret ) ) {
			throw DestinationException::permanent( __( 'The secret key must be 8 to 256 visible characters without spaces.', 'openvault-backup' ) );
		}
		return array(
			'access_key' => $access,
			'secret_key' => $secret,
		);
	}

	/**
	 * Validate S3-compatible settings.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string,mixed>
	 * @throws DestinationException For anything wrong.
	 */
	private static function validate_s3( $input ) {
		if ( ! is_array( $input ) ) {
			throw DestinationException::permanent( __( 'The storage settings are missing.', 'openvault-backup' ) );
		}
		foreach ( $input as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, self::S3_KEYS, true ) ) {
				throw DestinationException::permanent( __( 'The settings contain a field that is not allowed.', 'openvault-backup' ) );
			}
			if ( in_array( $key, self::S3_FLAG, true ) ) {
				if ( ! self::is_flag( $value ) ) {
					throw DestinationException::permanent( __( 'A setting that should be on or off is not valid.', 'openvault-backup' ) );
				}
			} elseif ( ! is_string( $value ) || strlen( $value ) > self::TEXT_MAX ) {
				throw DestinationException::permanent( __( 'A setting is not valid or too long.', 'openvault-backup' ) );
			}
		}

		$presets = S3Presets::all();
		$preset  = isset( $input['preset'] ) ? $input['preset'] : '';
		if ( ! is_string( $preset ) || ! isset( $presets[ $preset ] ) ) {
			throw DestinationException::permanent( __( 'Choose a storage provider.', 'openvault-backup' ) );
		}
		$field = $presets[ $preset ]['field'];

		// A value that does not belong to the chosen provider is refused, not silently dropped.
		$applies = array(
			'region'                 => 'region' === $field || 'custom' === $preset,
			'account_id'             => 'account_id' === $field,
			'endpoint'               => 'custom' === $preset,
			'allow_insecure_http'    => 'custom' === $preset,
			'allow_private_endpoint' => 'custom' === $preset,
		);
		foreach ( $applies as $key => $allowed ) {
			if ( ! $allowed && isset( $input[ $key ] ) && '' !== $input[ $key ] && false !== $input[ $key ] && 0 !== $input[ $key ] && '0' !== $input[ $key ] && 'false' !== $input[ $key ] ) {
				throw DestinationException::permanent( __( 'A setting does not apply to the chosen storage provider.', 'openvault-backup' ) );
			}
		}

		$prefix = isset( $input['prefix'] ) ? trim( $input['prefix'] ) : '';
		if ( 0 === strpos( $prefix, '/' ) ) {
			throw DestinationException::permanent( __( 'The folder prefix must not start with "/".', 'openvault-backup' ) );
		}
		$addressing = isset( $input['addressing'] ) ? trim( $input['addressing'] ) : '';
		if ( ! in_array( $addressing, array( '', 'auto', 'path', 'virtual' ), true ) ) {
			throw DestinationException::permanent( __( 'The addressing style must be automatic, path or virtual.', 'openvault-backup' ) );
		}

		$candidate = array(
			'preset'                 => $preset,
			'bucket'                 => isset( $input['bucket'] ) ? trim( $input['bucket'] ) : '',
			'prefix'                 => $prefix,
			'storage_class'          => isset( $input['storage_class'] ) ? trim( $input['storage_class'] ) : '',
			'allow_insecure_http'    => $applies['allow_insecure_http'] && isset( $input['allow_insecure_http'] ) && self::to_bool( $input['allow_insecure_http'] ),
			'allow_private_endpoint' => $applies['allow_private_endpoint'] && isset( $input['allow_private_endpoint'] ) && self::to_bool( $input['allow_private_endpoint'] ),
		);
		if ( 'path' === $addressing || 'virtual' === $addressing ) {
			$candidate['addressing'] = $addressing;
		}
		if ( $applies['region'] && isset( $input['region'] ) ) {
			$candidate['region'] = trim( $input['region'] );
		}
		if ( $applies['account_id'] && isset( $input['account_id'] ) ) {
			$candidate['account_id'] = strtolower( trim( $input['account_id'] ) );
		}
		if ( $applies['endpoint'] && isset( $input['endpoint'] ) ) {
			$candidate['endpoint'] = trim( $input['endpoint'] );
		}

		$resolved = S3Presets::resolve( $candidate );

		$out = array(
			'preset' => $preset,
			'bucket' => $resolved['bucket'],
			'prefix' => $resolved['prefix'],
		);
		foreach ( array( 'region', 'account_id', 'endpoint', 'addressing' ) as $key ) {
			if ( isset( $candidate[ $key ] ) && '' !== $candidate[ $key ] ) {
				$out[ $key ] = $candidate[ $key ];
			}
		}
		if ( '' !== $resolved['storage_class'] ) {
			$out['storage_class'] = $resolved['storage_class'];
		}
		foreach ( self::S3_FLAG as $key ) {
			if ( true === $candidate[ $key ] ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	/**
	 * Validate local folder settings.
	 *
	 * @param mixed         $input       Submitted settings (null = defaults).
	 * @param callable|null $local_check Folder check, see validate_settings().
	 * @return array<string,string>
	 * @throws DestinationException For anything wrong.
	 */
	private static function validate_local( $input, ?callable $local_check ) {
		$input = null === $input ? array() : $input;
		if ( ! is_array( $input ) ) {
			throw DestinationException::permanent( __( 'The folder settings are not valid.', 'openvault-backup' ) );
		}
		foreach ( array_keys( $input ) as $key ) {
			if ( 'path' !== $key ) {
				throw DestinationException::permanent( __( 'The settings contain a field that is not allowed.', 'openvault-backup' ) );
			}
		}
		$path = isset( $input['path'] ) ? $input['path'] : '';
		if ( ! is_string( $path ) || strlen( $path ) > LocalDestination::MAX_PATH * 2 ) {
			throw DestinationException::permanent( __( 'The folder path is not valid.', 'openvault-backup' ) );
		}
		if ( '' === trim( $path ) ) {
			return array( 'path' => '' );
		}
		$normal = LocalDestination::normalise_path( $path );
		if ( null === $normal ) {
			throw DestinationException::permanent( __( 'Enter an absolute folder path without "." or ".." parts.', 'openvault-backup' ) );
		}
		$settings = array( 'path' => $normal );
		if ( null === $local_check ) {
			( new LocalDestination( $settings ) )->validate();
		} else {
			call_user_func( $local_check, $settings );
		}
		return $settings;
	}

	/**
	 * Whether a value is a boolean flag as JSON or a form sends it.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_flag( $value ) {
		return is_bool( $value ) || in_array( $value, array( 0, 1, '0', '1', 'true', 'false' ), true );
	}

	/**
	 * Boolean from a flag value.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}
}
