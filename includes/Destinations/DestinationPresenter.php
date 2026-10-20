<?php
/**
 * How a destination is shown to the browser (pure logic, no credentials).
 *
 * Phase: MVP
 *
 * Purpose: Builds the item used by GET /destinations, the Destinations page and the Back up now picker from a repository
 * row. The item is built from a whitelist: it never contains credentials (the repository does not select them either),
 * the last error is scrubbed again, and only the non-secret settings that the edit form needs are included.
 * Status is always text, never colour alone.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Destinations;

use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Destinations\S3\S3Presets;
use OpenVault\Backup\Rest\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Destination presenter.
 */
final class DestinationPresenter {

	/**
	 * Translated status text.
	 *
	 * @param string $status new, connected, error or needs_reconnect.
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( $status ) {
			case DestinationsRepository::STATUS_CONNECTED:
				return __( 'Connected', 'openvault-backup' );
			case DestinationsRepository::STATUS_ERROR:
				return __( 'Connection failed', 'openvault-backup' );
			case DestinationsRepository::STATUS_NEEDS_RECONNECT:
				return __( 'Needs reconnect', 'openvault-backup' );
		}
		return __( 'Not tested yet', 'openvault-backup' );
	}

	/**
	 * Translated type name.
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public static function type_label( $type ) {
		if ( 's3' === $type ) {
			return __( 'S3-compatible storage', 'openvault-backup' );
		}
		if ( 'local' === $type ) {
			return __( 'Local folder', 'openvault-backup' );
		}
		return $type;
	}

	/**
	 * The JSON / template shape of a destination.
	 *
	 * @param array<string,mixed> $row Row of DestinationsRepository (never read with credentials).
	 * @return array<string,mixed>
	 */
	public static function item( array $row ) {
		$type     = isset( $row['type'] ) ? (string) $row['type'] : '';
		$status   = isset( $row['status'] ) ? (string) $row['status'] : DestinationsRepository::STATUS_NEW;
		$settings = isset( $row['settings'] ) && is_array( $row['settings'] ) ? $row['settings'] : array();
		$presets  = S3Presets::all();
		$preset   = 's3' === $type && isset( $settings['preset'] ) && is_string( $settings['preset'] ) && isset( $presets[ $settings['preset'] ] ) ? $settings['preset'] : '';

		$error = null;
		if ( DestinationsRepository::STATUS_ERROR === $status || DestinationsRepository::STATUS_NEEDS_RECONNECT === $status ) {
			$error = isset( $row['last_error'] ) && is_string( $row['last_error'] ) ? DestinationsRepository::scrub_error( $row['last_error'] ) : null;
			$error = '' === $error ? null : $error;
		}

		return array(
			'id'              => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'type'            => $type,
			'type_label'      => self::type_label( $type ),
			'preset'          => $preset,
			'preset_label'    => '' === $preset ? '' : $presets[ $preset ]['label'],
			'label'           => isset( $row['label'] ) ? (string) $row['label'] : '',
			'status'          => $status,
			'status_label'    => self::status_label( $status ),
			'has_credentials' => ! empty( $row['has_credentials'] ),
			'last_tested_at'  => isset( $row['last_tested_at'] ) ? RestController::iso_time( $row['last_tested_at'] ) : null,
			'last_error'      => $error,
			'details'         => self::details( $type, $settings ),
			'settings'        => self::public_settings( $type, $settings ),
		);
	}

	/**
	 * A short line about where the backups go: bucket, host and folder, or the local folder.
	 *
	 * @param string              $type     Type.
	 * @param array<string,mixed> $settings Stored settings.
	 * @return string
	 */
	public static function details( $type, array $settings ) {
		if ( 'local' === $type ) {
			$path = isset( $settings['path'] ) && is_string( $settings['path'] ) ? $settings['path'] : '';
			return '' === trim( $path ) ? __( 'The plugin backup folder on this server', 'openvault-backup' ) : $path;
		}
		if ( 's3' !== $type ) {
			return '';
		}

		$parts = array();
		try {
			$config  = S3Presets::resolve( $settings );
			$parts[] = sprintf(
				/* translators: %s: bucket name. */
				__( 'Bucket %s', 'openvault-backup' ),
				$config['bucket']
			);
			$parts[] = $config['host'] . ( null === $config['port'] ? '' : ':' . $config['port'] );
			if ( '' !== $config['prefix'] ) {
				$parts[] = sprintf(
					/* translators: %s: folder prefix inside the bucket. */
					__( 'Folder %s', 'openvault-backup' ),
					$config['prefix']
				);
			}
		} catch ( DestinationException $e ) {
			unset( $e );
			$parts[] = __( 'The saved settings are not valid. Edit this destination.', 'openvault-backup' );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * The non-secret settings the edit form pre-fills, by whitelist.
	 *
	 * @param string              $type     Type.
	 * @param array<string,mixed> $settings Stored settings.
	 * @return array<string,mixed>
	 */
	public static function public_settings( $type, array $settings ) {
		$keys = 's3' === $type ? DestinationSettings::S3_KEYS : ( 'local' === $type ? array( 'path' ) : array() );
		$out  = array();
		foreach ( $keys as $key ) {
			if ( isset( $settings[ $key ] ) && ( is_string( $settings[ $key ] ) || is_bool( $settings[ $key ] ) ) ) {
				$out[ $key ] = $settings[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Whether a destination can be selected for a new backup, and why not.
	 *
	 * New and connected destinations can be used. One that failed its last test or that needs to be reconnected is shown
	 * disabled, with the reason, until it is fixed on the Destinations page.
	 *
	 * @param array<string,mixed> $item  Result of item().
	 * @param bool                $known Whether a provider for the type is available.
	 * @return string '' when it can be used, otherwise the translated reason.
	 */
	public static function unusable_reason( array $item, $known = true ) {
		if ( ! $known ) {
			return __( 'This destination type is not available.', 'openvault-backup' );
		}
		if ( DestinationsRepository::STATUS_NEEDS_RECONNECT === $item['status'] ) {
			return __( 'Saved credentials can no longer be read. Enter the keys again on the Destinations page.', 'openvault-backup' );
		}
		if ( DestinationsRepository::STATUS_ERROR === $item['status'] ) {
			return __( 'The last connection test failed. Fix and test it on the Destinations page.', 'openvault-backup' );
		}
		return '';
	}
}
