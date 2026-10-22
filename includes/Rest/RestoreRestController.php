<?php
/**
 * REST routes of restore under /wp-json/ovb/v1 (see docs/plan/08-restore.md and 10-admin-ui.md).
 *
 * Phase: MVP
 *
 * Purpose: The browser side of a restore: preview, start, confirm, progress, cancel, undo and dismiss.
 *
 * Responsibilities:
 *  - Every route except the token status route: capability (Capabilities::current_user_can_manage_backups()) + the REST nonce (X-WP-Nonce,
 *    action "wp_rest"): core resets the user of a cookie request without a valid nonce, so the capability check then fails (401/403).
 *  - POST /restores/preview (read only), POST /restores (start: needs confirm_phrase "RESTORE"; it downloads and verifies, then parks at
 *    "verified"), POST /restores/{id}/confirm (the second step, needs the phrase again and status "verified"), GET /restores,
 *    GET /restores/{id}, POST /restores/{id}/cancel, POST /restores/{id}/undo (needs the phrase), POST /restores/{id}/undo/dismiss.
 *  - GET /restores/{id}/status?t=<token>: no nonce, no login (a database restore replaces users and sessions, so the administrator can be
 *    logged out mid-restore). It returns only RestoreStatusPresenter::PUBLIC_KEYS, changes nothing, never runs a slice, sends
 *    "Cache-Control: no-store" and answers 404 for every mismatch (see RestoreStatusToken). Filter "ovb_restore_status_token_enabled"
 *    (default true) switches the route and the token off.
 *  - Browser kick: GET /restores/{id} runs ONE RestoreRunner slice inline when the job is queued or running (never "verified"), the
 *    restore lock is free and its heartbeat is older than KICK_AFTER seconds (filter "ovb_restore_kick_after_seconds"), so a site whose
 *    Action Scheduler loopback does not work still finishes while the page is open.
 *  - Responses never contain absolute paths, secrets, SQL or row data (RestoreStatusPresenter, RestorePreviewPresenter).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Rest;

use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobLock;
use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Restore\MaintenanceMode;
use OpenVault\Backup\Restore\RestoreManager;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Restore\RestorePreviewPresenter;
use OpenVault\Backup\Restore\RestoreRunner;
use OpenVault\Backup\Restore\RestoreStatusPresenter;
use OpenVault\Backup\Restore\RestoreStatusToken;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Restore REST controller.
 */
class RestoreRestController {

	/**
	 * The word the user must type (case-sensitive) before a destructive step.
	 */
	const CONFIRM_PHRASE = 'RESTORE';

	/**
	 * Seconds without a heartbeat before a status request runs a slice itself.
	 */
	const KICK_AFTER = 10;

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
		$ns         = RestController::NAMESPACE_V1;
		$id_arg     = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => array( RestController::class, 'validate_id' ),
		);
		$phrase_arg = array(
			'type'              => 'string',
			'required'          => false,
			'validate_callback' => array( __CLASS__, 'validate_phrase_type' ),
		);
		$bool_arg   = array(
			'type'              => 'boolean',
			'required'          => false,
			'validate_callback' => array( __CLASS__, 'validate_flag' ),
			'sanitize_callback' => array( __CLASS__, 'to_bool' ),
		);
		$start_args = array(
			'backup_id'             => $id_arg,
			'mode'                  => array(
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => array( __CLASS__, 'validate_mode' ),
			),
			'restore_server_config' => $bool_arg,
		);

		register_rest_route(
			$ns,
			'/restores/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $permission,
				'args'                => $start_args,
			)
		);

		register_rest_route(
			$ns,
			'/restores',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start' ),
					'permission_callback' => $permission,
					'args'                => array_merge(
						$start_args,
						array(
							'acknowledge_url_mismatch' => $bool_arg,
							'keep_site_urls'           => $bool_arg,
							'confirm_phrase'           => $phrase_arg,
						)
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_restores' ),
					'permission_callback' => $permission,
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'maximum'           => 100000,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 10,
							'minimum'           => 1,
							'maximum'           => 50,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/restores/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_restore' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $id_arg ),
			)
		);

		foreach ( array(
			'confirm' => 'confirm',
			'cancel'  => 'cancel',
			'undo'    => 'undo',
		) as $path => $callback ) {
			register_rest_route(
				$ns,
				'/restores/(?P<id>\d+)/' . $path,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => $permission,
					'args'                => 'cancel' === $path ? array( 'id' => $id_arg ) : array(
						'id'             => $id_arg,
						'confirm_phrase' => $phrase_arg,
					),
				)
			);
		}

		register_rest_route(
			$ns,
			'/restores/(?P<id>\d+)/undo/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $id_arg ),
			)
		);

		// Public on purpose: the token in the query string is the credential (see RestoreStatusToken and docs/plan/09-security.md).
		register_rest_route(
			$ns,
			'/restores/(?P<id>\d+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'public_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => $id_arg,
					't'  => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	// Pure validators (no WordPress needed).

	/**
	 * Whether the typed phrase is exactly the required word: case-sensitive, no trimming, no look-alike characters.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function phrase_ok( $value ) {
		return is_string( $value ) && self::CONFIRM_PHRASE === $value;
	}

	/**
	 * The phrase argument may be any string here; phrase_ok() decides in the callback so the error says what to type.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_phrase_type( $value ) {
		return is_string( $value ) && strlen( $value ) <= 64;
	}

	/**
	 * Mode: one of the three a user may start (never "undo").
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_mode( $value ) {
		return is_string( $value ) && in_array( $value, RestorePlan::MODES, true );
	}

	/**
	 * Boolean flag: a real boolean or one of the usual spellings.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_flag( $value ) {
		return is_bool( $value ) || in_array( $value, array( 0, 1, '0', '1', 'true', 'false' ), true );
	}

	/**
	 * Boolean from a REST value.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function to_bool( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}

	/**
	 * HTTP status for a refused start or undo (codes of RestoreManager and RestorePreflight).
	 *
	 * @param string $code Error code.
	 * @return int
	 */
	public static function status_for_code( $code ) {
		if ( in_array( $code, array( 'restore_missing', 'backup_missing' ), true ) ) {
			return 404;
		}
		if ( in_array( $code, array( 'restore_running', 'backup_running', 'not_verified', 'already_undone', 'dismissed', 'not_finished', 'not_undoable', 'journal_gone', 'snapshot_gone', 'nothing_to_undo', 'not_dismissable', 'backup_not_finished' ), true ) ) {
			return 409;
		}
		if ( in_array( $code, array( 'save_failed', 'enqueue_failed', 'create_failed', 'start_failed', 'remove_failed' ), true ) ) {
			return 500;
		}
		return 400;
	}

	/**
	 * Whether the unauthenticated status route and its token are on.
	 *
	 * @return bool
	 */
	public static function token_enabled() {
		/**
		 * Switch the unauthenticated restore status route off (false): no token is created and the route answers 404.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'ovb_restore_status_token_enabled', true );
	}

	// Route callbacks.

	/**
	 * POST /restores/preview: the same checks as a start, nothing is created.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( $request ) {
		$backups = new BackupsRepository();
		$backup  = $backups->find( (int) $request['backup_id'] );
		if ( null === $backup ) {
			return self::error( 'ovb_not_found', __( 'That backup does not exist.', 'openvault-backup' ), 404 );
		}

		$result = ( new RestoreManager() )->preview(
			(int) $backup['id'],
			(string) $request['mode'],
			array( 'restore_server_config' => (bool) $request->get_param( 'restore_server_config' ) )
		);
		if ( ! $result['ok'] && null === $result['plan'] && array() !== $result['errors'] && ! isset( $result['disk'] ) ) {
			// A refusal before any plan exists (a backup that is not finished): still a normal preview answer for the page.
			$result['disk'] = array();
		}
		return new \WP_REST_Response( RestorePreviewPresenter::build( $backup, $result, self::destination_labels(), self::roots() ), 200 );
	}

	/**
	 * POST /restores: start (download and verify); the job then waits for confirm.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start( $request ) {
		if ( ! self::phrase_ok( $request->get_param( 'confirm_phrase' ) ) ) {
			return self::phrase_error();
		}

		$token   = '';
		$options = array(
			'user_id'                  => get_current_user_id(),
			'acknowledge_url_mismatch' => (bool) $request->get_param( 'acknowledge_url_mismatch' ),
			'keep_site_urls'           => (bool) $request->get_param( 'keep_site_urls' ),
			'restore_server_config'    => (bool) $request->get_param( 'restore_server_config' ),
		);
		if ( self::token_enabled() ) {
			$token                        = RestoreStatusToken::generate();
			$options['status_token_hash'] = RestoreStatusToken::hash( $token );
		}

		$result = ( new RestoreManager() )->start( (int) $request['backup_id'], (string) $request['mode'], $options );
		if ( ! $result['ok'] ) {
			return self::refusal( $result );
		}

		$payload = $this->payload_for( (int) $result['restore_id'] );
		if ( '' !== $token ) {
			$payload['status_token'] = $token;
		}
		return new \WP_REST_Response( $payload, 201 );
	}

	/**
	 * GET /restores.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_restores( $request ) {
		$per_page = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$result   = ( new RestoresRepository() )->paginate( $per_page, $page );

		$response = new \WP_REST_Response( array_map( array( RestoreStatusPresenter::class, 'summary' ), $result['items'] ), 200 );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $per_page ) ) );
		return $response;
	}

	/**
	 * GET /restores/{id} (may run one slice, see the class comment).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_restore( $request ) {
		$id   = (int) $request['id'];
		$rows = new RestoresRepository();
		$row  = $rows->find( $id );
		if ( null === $row ) {
			return self::not_found();
		}
		if ( in_array( $row['status'], RestoresRepository::INCOMPLETE, true ) ) {
			$this->kick( $id );
		}
		return new \WP_REST_Response( $this->payload_for( $id ), 200 );
	}

	/**
	 * POST /restores/{id}/confirm.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm( $request ) {
		$id  = (int) $request['id'];
		$row = ( new RestoresRepository() )->find( $id );
		if ( null === $row ) {
			return self::not_found();
		}
		if ( ! self::phrase_ok( $request->get_param( 'confirm_phrase' ) ) ) {
			return self::phrase_error();
		}
		if ( 'verified' !== $row['status'] ) {
			return self::error( 'ovb_not_verified', __( 'This restore is not waiting for your confirmation.', 'openvault-backup' ), 409 );
		}

		$answer = ( new RestoreManager() )->confirm( $id );
		if ( ! $answer['ok'] ) {
			return self::error( 'ovb_' . $answer['code'], $answer['message'], self::status_for_code( $answer['code'] ) );
		}
		return new \WP_REST_Response( $this->payload_for( $id ), 200 );
	}

	/**
	 * POST /restores/{id}/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( $request ) {
		$id      = (int) $request['id'];
		$manager = new RestoreManager();
		if ( null === ( new RestoresRepository() )->find( $id ) ) {
			return self::not_found();
		}
		if ( ! $manager->cancel( $id ) ) {
			$why = $manager->cancel_refusal( $id );
			return self::error( 'ovb_not_cancellable', '' !== $why ? $why : __( 'This restore has already finished.', 'openvault-backup' ), 409 );
		}
		return new \WP_REST_Response( $this->payload_for( $id ), 200 );
	}

	/**
	 * POST /restores/{id}/undo: starts the undo job (needs the phrase).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo( $request ) {
		$id = (int) $request['id'];
		if ( null === ( new RestoresRepository() )->find( $id ) ) {
			return self::not_found();
		}
		if ( ! self::phrase_ok( $request->get_param( 'confirm_phrase' ) ) ) {
			return self::phrase_error();
		}

		$token   = '';
		$options = array( 'user_id' => get_current_user_id() );
		if ( self::token_enabled() ) {
			$token                        = RestoreStatusToken::generate();
			$options['status_token_hash'] = RestoreStatusToken::hash( $token );
		}

		$result = ( new RestoreManager() )->undo( $id, $options );
		if ( ! $result['ok'] ) {
			return self::refusal( $result );
		}

		$payload               = $this->payload_for( (int) $result['restore_id'] );
		$payload['undo_db']    = ! empty( $result['undo_db'] );
		$payload['undo_files'] = ! empty( $result['undo_files'] );
		if ( '' !== $token ) {
			$payload['status_token'] = $token;
		}
		return new \WP_REST_Response( $payload, 201 );
	}

	/**
	 * POST /restores/{id}/undo/dismiss.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function dismiss( $request ) {
		$id = (int) $request['id'];
		if ( null === ( new RestoresRepository() )->find( $id ) ) {
			return self::not_found();
		}
		$answer = ( new RestoreManager() )->dismiss_undo( $id );
		if ( ! $answer['ok'] ) {
			return self::error( 'ovb_' . $answer['code'], $answer['message'], self::status_for_code( $answer['code'] ) );
		}
		return new \WP_REST_Response( $this->payload_for( $id ), 200 );
	}

	/**
	 * GET /restores/{id}/status?t=token: coarse progress for a browser that was logged out by the restore. Read only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function public_status( $request ) {
		$id    = (int) $request['id'];
		$rows  = new RestoresRepository();
		$row   = self::token_enabled() ? $rows->find( $id ) : null;
		$state = null === $row ? null : $rows->load_state( $id );
		$hash  = null === $state ? '' : RestoreMeta::status_token_hash( $state->get_meta() );

		$finished = null === $row || ! in_array( $row['status'], RestoresRepository::FINISHED, true ) ? 0 : (int) strtotime( (string) $row['finished_at'] . ' UTC' );
		if ( null === $row || null === $state || ! RestoreStatusToken::check( $hash, $request->get_param( 't' ), $finished, time() ) ) {
			return self::not_found();
		}

		$full     = RestoreStatusPresenter::build( $row, $state, array( 'maintenance' => false ), time() );
		$response = new \WP_REST_Response( RestoreStatusPresenter::public_payload( $full ), 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	// Internals.

	/**
	 * Full payload of a restore read fresh from the database.
	 *
	 * @param int $id Restore id.
	 * @return array<string,mixed>
	 */
	private function payload_for( $id ) {
		$rows = new RestoresRepository();
		$row  = $rows->find( $id );
		if ( null === $row ) {
			return array();
		}
		$state   = $rows->load_state( $id );
		$manager = new RestoreManager();
		$flag    = ( new MaintenanceMode() )->status();

		$facts = array(
			'maintenance'    => null !== $flag,
			'cancel_refusal' => in_array( $row['status'], RestoresRepository::FINISHED, true ) ? '' : $manager->cancel_refusal( $id ),
			'roots'          => self::roots(),
		);
		if ( in_array( $row['status'], array( 'complete', 'failed' ), true ) && RestoreMeta::MODE_UNDO !== $row['mode'] ) {
			$facts['undo'] = $manager->can_undo( $id );
		}
		return RestoreStatusPresenter::build( $row, $state, $facts, time() );
	}

	/**
	 * Run one slice inline when no one else is making progress.
	 *
	 * @param int $id Restore id.
	 * @return void
	 */
	private function kick( $id ) {
		$rows  = new RestoresRepository();
		$row   = $rows->find( $id );
		$state = $rows->load_state( $id );
		if ( null === $row || ! in_array( $row['status'], RestoresRepository::INCOMPLETE, true ) || null === $state || $state->is_terminal() ) {
			return;
		}

		/**
		 * Seconds without a heartbeat before a restore status request runs a slice itself.
		 *
		 * @param int $seconds Seconds.
		 */
		$after = max( 1, (int) apply_filters( 'ovb_restore_kick_after_seconds', self::KICK_AFTER ) );

		// The safety snapshot is an ordinary backup job driven by Action Scheduler: without it the restore would wait for it forever.
		$snapshot_id = RestoreMeta::snapshot_id( $state->get_meta() );
		if ( JobState::STEP_SNAPSHOT === $state->get_step() && $snapshot_id > 0 ) {
			$this->kick_backup( $snapshot_id, $after );
		}

		if ( ( time() - $state->get_heartbeat() ) < $after || ( new JobLock( RestoreRunner::LOCK_KEY ) )->is_locked( RestoreRunner::LOCK_TTL ) ) {
			return;
		}

		( new RestoreRunner() )->run( $id );
	}

	/**
	 * Run one slice of the safety snapshot backup when nothing else is moving it.
	 *
	 * @param int $backup_id Snapshot backup id.
	 * @param int $after     Seconds without a heartbeat.
	 * @return void
	 */
	private function kick_backup( $backup_id, $after ) {
		$backups = new BackupsRepository();
		$backup  = $backups->find( $backup_id );
		$state   = null === $backup || ! in_array( $backup['status'], BackupsRepository::INCOMPLETE, true ) ? null : $backups->load_state( $backup_id );
		if ( null === $state || $state->is_terminal() || ( time() - $state->get_heartbeat() ) < $after || ( new JobLock( $backup_id ) )->is_locked( JobRunner::LOCK_TTL ) ) {
			return;
		}
		( new JobRunner() )->run( $backup_id );
	}

	/**
	 * Error for a refused start or undo: the first message, plus every error and acknowledgement for the page.
	 *
	 * @param array<string,mixed> $result Result of RestoreManager::start() or undo().
	 * @return \WP_Error
	 */
	private static function refusal( array $result ) {
		$errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
		$acks   = isset( $result['requires_ack'] ) && is_array( $result['requires_ack'] ) ? $result['requires_ack'] : array();
		$first  = array() !== $errors ? $errors[0] : ( array() !== $acks ? $acks[0] : array(
			'code'    => 'refused',
			'message' => __( 'The restore could not be started.', 'openvault-backup' ),
		) );
		$roots  = self::roots();
		$clean  = static function ( array $items ) use ( $roots ) {
			$out = array();
			foreach ( $items as $item ) {
				$out[] = array(
					'code'    => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $item['code'] ) ),
					'message' => RestController::hide_paths( DestinationsRepository::scrub_error( (string) $item['message'] ), $roots ),
				);
			}
			return $out;
		};
		$code   = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $first['code'] ) );
		$status = array() === $errors && array() !== $acks ? 400 : self::status_for_code( (string) $code );
		return self::error(
			'ovb_' . $code,
			RestController::hide_paths( DestinationsRepository::scrub_error( (string) $first['message'] ), $roots ),
			$status,
			array(
				'errors'       => $clean( $errors ),
				'requires_ack' => $clean( $acks ),
			)
		);
	}

	/**
	 * Destination labels by id.
	 *
	 * @return array<int,string>
	 */
	private static function destination_labels() {
		$labels = array();
		foreach ( ( new DestinationsRepository() )->all() as $destination ) {
			$labels[ (int) $destination['id'] ] = (string) $destination['label'];
		}
		return $labels;
	}

	/**
	 * Folders hidden in messages.
	 *
	 * @return array<int,string>
	 */
	private static function roots() {
		$roots = array();
		try {
			$roots[] = Paths::local_dir();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$roots[] = WP_CONTENT_DIR;
		$roots[] = ABSPATH;
		return $roots;
	}

	/**
	 * 400 for a missing or wrong phrase.
	 *
	 * @return \WP_Error
	 */
	private static function phrase_error() {
		return self::error( 'ovb_confirm_phrase', __( 'Type the word RESTORE in capital letters to confirm.', 'openvault-backup' ), 400 );
	}

	/**
	 * A WP_Error with a status.
	 *
	 * @param string              $code    Error code.
	 * @param string              $message Safe message.
	 * @param int                 $status  HTTP status.
	 * @param array<string,mixed> $extra   Extra data.
	 * @return \WP_Error
	 */
	private static function error( $code, $message, $status, array $extra = array() ) {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	/**
	 * 404 error (the same for every kind of "no such restore" so ids cannot be probed).
	 *
	 * @return \WP_Error
	 */
	private static function not_found() {
		return self::error( 'ovb_not_found', __( 'That restore does not exist.', 'openvault-backup' ), 404 );
	}
}
