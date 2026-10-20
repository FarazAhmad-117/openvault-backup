<?php
/**
 * REST routes for destinations under /wp-json/ovb/v1/destinations (see docs/plan/10-admin-ui.md).
 *
 * Phase: MVP
 *
 * Purpose: Create, list, edit, test and remove destinations from the Destinations page.
 *
 * Responsibilities:
 *  - Same conventions as RestController: permission_callback is RestController::permission() (capability + the standard
 *    REST nonce), every argument is declared with a validate and a sanitize callback, errors are WP_Error with fixed,
 *    safe messages.
 *  - GET /destinations (list), POST /destinations (create), POST /destinations/{id} (update label, settings and, only
 *    when new keys are sent, the credentials), POST /destinations/{id}/test, DELETE /destinations/{id}.
 *  - Secrets are write-only. Responses are built by DestinationPresenter from rows that never contain credentials; no
 *    message, error or log line contains a submitted secret. The test decrypts the credentials on the server only
 *    (DestinationRegistry::create()).
 *  - The settings are validated per type by DestinationSettings (SSRF guard, bucket and prefix rules, local folder rules).
 *  - Deleting removes the row and its stored credentials only, never remote files, and is refused (409) while a queued
 *    or running backup is using the destination.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Rest;

use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\DestinationPresenter;
use OpenVault\Backup\Destinations\DestinationRegistry;
use OpenVault\Backup\Destinations\DestinationSettings;
use OpenVault\Backup\Engine\Steps\UploadPlan;
use OpenVault\Backup\Security\VaultException;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Destinations REST controller.
 */
class DestinationsRestController {

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$permission = array( RestController::class, 'permission' );
		$id_arg     = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => array( RestController::class, 'validate_id' ),
		);
		$label      = array(
			'type'              => 'string',
			'validate_callback' => array( __CLASS__, 'validate_label' ),
			'sanitize_callback' => array( DestinationSettings::class, 'clean_label' ),
		);
		$settings   = array(
			'type'              => 'object',
			'validate_callback' => array( __CLASS__, 'validate_map' ),
		);
		$secrets    = array(
			'type'              => 'object',
			'validate_callback' => array( __CLASS__, 'validate_map' ),
		);

		register_rest_route(
			RestController::NAMESPACE_V1,
			'/destinations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_destinations' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_destination' ),
					'permission_callback' => $permission,
					'args'                => array(
						'type'        => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( __CLASS__, 'validate_type' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'label'       => array_merge( $label, array( 'required' => true ) ),
						'settings'    => $settings,
						'credentials' => $secrets,
					),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE_V1,
			'/destinations/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_destination' ),
					'permission_callback' => $permission,
					'args'                => array(
						'id'          => $id_arg,
						'label'       => $label,
						'settings'    => $settings,
						'credentials' => $secrets,
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_destination' ),
					'permission_callback' => $permission,
					'args'                => array( 'id' => $id_arg ),
				),
			)
		);

		register_rest_route(
			RestController::NAMESPACE_V1,
			'/destinations/(?P<id>\d+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_destination' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $id_arg ),
			)
		);
	}

	// Pure validators (no WordPress needed).

	/**
	 * Type: one that has a validator here.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_type( $value ) {
		return DestinationSettings::is_supported_type( $value );
	}

	/**
	 * Label: text of 1 to 100 characters.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_label( $value ) {
		return DestinationSettings::is_label( $value );
	}

	/**
	 * Settings and credentials arrive as a JSON object: an array with string keys (the content is checked per type).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_map( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) ) {
				return false;
			}
		}
		return true;
	}

	// Route callbacks.

	/**
	 * GET /destinations.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_destinations() {
		$items = array();
		foreach ( ( new DestinationsRepository() )->all() as $row ) {
			$items[] = DestinationPresenter::item( $row );
		}
		return new \WP_REST_Response( $items, 200 );
	}

	/**
	 * POST /destinations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_destination( $request ) {
		$type = (string) $request->get_param( 'type' );
		if ( ! ( new DestinationRegistry() )->has( $type ) ) {
			return self::error( 'ovb_bad_type', __( 'This destination type is not available.', 'openvault-backup' ), 400 );
		}

		try {
			$settings    = DestinationSettings::validate_settings( $type, $request->get_param( 'settings' ) );
			$credentials = DestinationSettings::validate_credentials( $type, $request->get_param( 'credentials' ), true );
		} catch ( DestinationException $e ) {
			return self::error( 'ovb_invalid_destination', $e->getMessage(), 400 );
		}

		$repository = new DestinationsRepository();
		try {
			$id = $repository->create( $type, (string) $request->get_param( 'label' ), $settings, $credentials );
		} catch ( VaultException $e ) {
			unset( $e );
			return self::error( 'ovb_vault_unavailable', __( 'The credentials could not be encrypted on this server, so nothing was saved.', 'openvault-backup' ), 500 );
		}
		$row = $id > 0 ? $repository->find( $id ) : null;
		if ( null === $row ) {
			return self::error( 'ovb_create_failed', __( 'The destination could not be saved.', 'openvault-backup' ), 500 );
		}

		( new Logger() )->info(
			__( 'Destination created.', 'openvault-backup' ),
			array(
				'destination_id' => $id,
				'type'           => $type,
			)
		);
		return new \WP_REST_Response( DestinationPresenter::item( $row ), 201 );
	}

	/**
	 * POST /destinations/{id}: label and settings; the credentials only when new keys are sent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_destination( $request ) {
		$id         = (int) $request['id'];
		$repository = new DestinationsRepository();
		$row        = $repository->find( $id );
		if ( null === $row ) {
			return self::not_found();
		}

		$label       = $request->get_param( 'label' );
		$settings_in = $request->get_param( 'settings' );
		try {
			$settings    = null === $settings_in ? null : DestinationSettings::validate_settings( $row['type'], $settings_in );
			$credentials = DestinationSettings::validate_credentials( $row['type'], $request->get_param( 'credentials' ), false );
		} catch ( DestinationException $e ) {
			return self::error( 'ovb_invalid_destination', $e->getMessage(), 400 );
		}
		if ( null === $label && null === $settings && array() === $credentials ) {
			return self::error( 'ovb_nothing_to_update', __( 'Nothing to update.', 'openvault-backup' ), 400 );
		}

		$changed = null !== $settings && ! self::same_settings( $settings, $row['settings'] );
		try {
			// Credentials first: when they cannot be encrypted nothing has been changed.
			if ( array() !== $credentials && ! $repository->replace_credentials( $id, $credentials ) ) {
				return self::error( 'ovb_update_failed', __( 'The destination could not be updated.', 'openvault-backup' ), 500 );
			}
		} catch ( VaultException $e ) {
			unset( $e );
			return self::error( 'ovb_vault_unavailable', __( 'The credentials could not be encrypted on this server, so nothing was saved.', 'openvault-backup' ), 500 );
		}
		if ( null !== $label || null !== $settings ) {
			if ( ! $repository->update_settings( $id, null === $settings ? $row['settings'] : $settings, null === $label ? null : (string) $label ) ) {
				return self::error( 'ovb_update_failed', __( 'The destination could not be updated.', 'openvault-backup' ), 500 );
			}
		}
		if ( $changed ) {
			$repository->mark_untested( $id );
		}

		$fresh = $repository->find( $id );
		return null === $fresh ? self::not_found() : new \WP_REST_Response( DestinationPresenter::item( $fresh ), 200 );
	}

	/**
	 * POST /destinations/{id}/test.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_destination( $request ) {
		$id         = (int) $request['id'];
		$repository = new DestinationsRepository();
		$row        = $repository->find( $id );
		if ( null === $row ) {
			return self::not_found();
		}

		$ok      = false;
		$message = '';
		try {
			$result  = ( new DestinationRegistry() )->create( $row )->test_connection();
			$ok      = $result->ok();
			$message = $result->message();
			$repository->mark_tested( $id, $ok, $ok ? null : $message );
		} catch ( DestinationException $e ) {
			// Unreadable credentials (the repository has already switched the row to needs_reconnect) or an unknown type.
			$message = $e->getMessage();
		} catch ( \Throwable $e ) {
			unset( $e );
			$message = __( 'The connection test failed unexpectedly.', 'openvault-backup' );
			$repository->mark_tested( $id, false, $message );
		}

		$fresh = $repository->find( $id );
		$item  = null === $fresh ? array() : DestinationPresenter::item( $fresh );
		if ( ! $ok && isset( $item['status'] ) && DestinationsRepository::STATUS_NEEDS_RECONNECT === $item['status'] ) {
			$message = __( 'Saved credentials can no longer be read (the site\'s encryption key changed). Enter the keys again.', 'openvault-backup' );
		}

		return new \WP_REST_Response(
			array(
				'ok'          => $ok,
				'message'     => DestinationsRepository::scrub_error( $message ),
				'destination' => $item,
			),
			200
		);
	}

	/**
	 * DELETE /destinations/{id}: the row and its credentials; remote files stay.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_destination( $request ) {
		$id         = (int) $request['id'];
		$repository = new DestinationsRepository();
		if ( null === $repository->find( $id ) ) {
			return self::not_found();
		}
		if ( $this->in_use( $id ) ) {
			return self::error( 'ovb_destination_in_use', __( 'A backup that uses this destination is still running. Wait for it to finish or cancel it first.', 'openvault-backup' ), 409 );
		}
		if ( ! $repository->delete( $id ) ) {
			return self::error( 'ovb_delete_failed', __( 'The destination could not be removed.', 'openvault-backup' ), 500 );
		}

		( new Logger() )->info( __( 'Destination removed.', 'openvault-backup' ), array( 'destination_id' => $id ) );
		return new \WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	// Internals.

	/**
	 * Whether two settings arrays hold the same values, whatever their key order.
	 *
	 * @param array<string,mixed> $a First.
	 * @param array<string,mixed> $b Second.
	 * @return bool
	 */
	public static function same_settings( array $a, array $b ) {
		ksort( $a );
		ksort( $b );
		return $a === $b;
	}

	/**
	 * Whether a queued or running backup was started with this destination.
	 *
	 * @param int $id Destination id.
	 * @return bool
	 */
	private function in_use( $id ) {
		$backups = new BackupsRepository();
		foreach ( $backups->find_incomplete( 50 ) as $backup ) {
			$state = $backups->load_state( (int) $backup['id'] );
			if ( null !== $state && in_array( $id, UploadPlan::destination_ids( $state->get_meta() ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A WP_Error with a status.
	 *
	 * @param string $code    Error code.
	 * @param string $message Safe message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * 404 error.
	 *
	 * @return \WP_Error
	 */
	private static function not_found() {
		return self::error( 'ovb_not_found', __( 'That destination does not exist.', 'openvault-backup' ), 404 );
	}
}
