<?php
/**
 * REST routes under /wp-json/ovb/v1 used by admin JS (see docs/plan/10-admin-ui.md).
 *
 * Phase: MVP
 *
 * Purpose: REST routes under /wp-json/ovb/v1 used by admin JS (see docs/plan/10-admin-ui.md).
 *
 * Responsibilities:
 *  - All routes: capability check + nonce. permission_callback is Capabilities::current_user_can_manage_backups();
 *    the nonce is the standard REST one (X-WP-Nonce, action "wp_rest"): WordPress core resets the user of a
 *    cookie-authenticated request that has no valid nonce, so the capability check then fails (401/403).
 *  - POST /backups (start), GET /backups (list), GET /backups/{id}/progress, POST /backups/{id}/cancel,
 *    DELETE /backups/{id}, POST /backups/{id}/lock, POST /backups/{id}/note.
 *  - Responses are plain arrays (see payload()). Errors are WP_Error with fixed, safe messages.
 *
 * Browser-driven kick: GET progress also pushes a running job forward when nothing else does (hosts with WP-Cron
 * disabled or slow). If the backup is queued/running, its lock is free and its heartbeat is older than
 * KICK_AFTER seconds (filter "ovb_kick_after_seconds"), ONE JobRunner::run() slice is executed inline (the lock
 * prevents overlap, the Budget bounds the time) and fresh progress is returned. Action Scheduler keeps working in the
 * background when the tab is closed; the page only makes it faster and more reliable.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Rest;

use OpenVault\Backup\Archive\CapabilityDetector;
use OpenVault\Backup\Backups\BackupFiles;
use OpenVault\Backup\Core\Capabilities;
use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Engine\JobLock;
use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\Progress;
use OpenVault\Backup\Engine\Scan\ContentSelector;
use OpenVault\Backup\Security\DownloadController;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
class RestController {

	const NAMESPACE_V1 = 'ovb/v1';

	/**
	 * Seconds without a heartbeat before a progress request runs a slice itself.
	 */
	const KICK_AFTER = 10;

	/**
	 * Longest note accepted from the browser.
	 */
	const NOTE_MAX = 500;

	/**
	 * Option used as an atomic "a backup is being created" guard.
	 */
	const START_GUARD = 'ovb_start_guard';

	/**
	 * Contents offered by the UI (database plus the file selections) and their defaults.
	 */
	const DEFAULT_CONTENTS = array(
		'db'            => true,
		'plugins'       => true,
		'themes'        => true,
		'uploads'       => true,
		'mu_plugins'    => true,
		'other_content' => true,
		'core'          => false,
		'wp_config'     => false,
	);

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
		$permission = array( __CLASS__, 'permission' );
		$id_arg     = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => array( __CLASS__, 'validate_id' ),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/backups',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_backup' ),
					'permission_callback' => $permission,
					'args'                => array(
						'contents' => array(
							'type'              => 'object',
							'required'          => false,
							'validate_callback' => array( __CLASS__, 'validate_contents' ),
							'sanitize_callback' => array( __CLASS__, 'sanitize_contents' ),
						),
						'format'   => array(
							'type'              => 'string',
							'required'          => false,
							'validate_callback' => array( __CLASS__, 'validate_format_arg' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'note'     => array(
							'type'              => 'string',
							'required'          => false,
							'validate_callback' => array( __CLASS__, 'validate_note' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_backups' ),
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
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/backups/(?P<id>\d+)/progress',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_progress' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $id_arg ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/backups/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_backup' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $id_arg ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/backups/(?P<id>\d+)/lock',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'lock_backup' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id'     => $id_arg,
					'locked' => array(
						'type'              => 'boolean',
						'required'          => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/backups/(?P<id>\d+)/note',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'note_backup' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id'   => $id_arg,
					'note' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( __CLASS__, 'validate_note' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/backups/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_backup' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $id_arg ),
			)
		);
	}

	/**
	 * Permission callback of every route.
	 *
	 * @return bool|\WP_Error
	 */
	public static function permission() {
		if ( Capabilities::current_user_can_manage_backups() ) {
			return true;
		}
		return new \WP_Error( 'ovb_forbidden', __( 'You are not allowed to manage backups.', 'openvault-backup' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
	}

	// Pure validators and helpers (no WordPress needed).

	/**
	 * Backup id from the URL.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_id( $value ) {
		return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 2147483647 && (string) (int) $value === (string) $value;
	}

	/**
	 * The contents object: only the known flags, each a boolean, at least one of them true.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_contents( $value ) {
		if ( ! is_array( $value ) || array() === $value ) {
			return false;
		}
		$any = false;
		foreach ( $value as $key => $flag ) {
			if ( ! is_string( $key ) || ! array_key_exists( $key, self::DEFAULT_CONTENTS ) ) {
				return false;
			}
			if ( ! is_bool( $flag ) && ! in_array( $flag, array( 0, 1, '0', '1', 'true', 'false' ), true ) ) {
				return false;
			}
			$any = $any || self::to_bool( $flag );
		}
		return $any;
	}

	/**
	 * Contents as a full map of known flags to booleans (missing flags are off).
	 *
	 * @param mixed $value Value.
	 * @return array<string,bool>
	 */
	public static function sanitize_contents( $value ) {
		$out = array_fill_keys( array_keys( self::DEFAULT_CONTENTS ), false );
		foreach ( is_array( $value ) ? $value : array() as $key => $flag ) {
			if ( is_string( $key ) && array_key_exists( $key, $out ) ) {
				$out[ $key ] = self::to_bool( $flag );
			}
		}
		return $out;
	}

	/**
	 * Whether a format may be started: it exists in $formats, the server supports it and it is an MVP format.
	 *
	 * @param mixed                             $value   Format id.
	 * @param array<string,array<string,mixed>> $formats CapabilityDetector::formats().
	 * @return bool
	 */
	public static function validate_format( $value, array $formats ) {
		return is_string( $value )
			&& isset( $formats[ $value ] )
			&& ! empty( $formats[ $value ]['available'] )
			&& empty( $formats[ $value ]['phase2'] );
	}

	/**
	 * REST validate_callback for the format argument.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_format_arg( $value ) {
		return self::validate_format( $value, ( new CapabilityDetector() )->formats() );
	}

	/**
	 * Note: a string of at most NOTE_MAX characters.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_note( $value ) {
		return is_string( $value ) && strlen( $value ) <= self::NOTE_MAX * 4 && self::length( $value ) <= self::NOTE_MAX;
	}

	/**
	 * Shorten absolute paths in a message so the browser never learns where the site lives.
	 *
	 * @param string            $message Message.
	 * @param array<int,string> $roots   Absolute folders to hide, most specific first. Each is replaced by "[site]".
	 * @return string
	 */
	public static function hide_paths( $message, array $roots ) {
		$message = (string) $message;
		foreach ( $roots as $root ) {
			$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
			if ( strlen( $root ) < 2 ) {
				continue;
			}
			$message = str_replace( array( $root, str_replace( '/', '\\', $root ) ), '[site]', $message );
		}
		return $message;
	}

	/**
	 * Row time (UTC "Y-m-d H:i:s") as an ISO 8601 string, or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function iso_time( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\z/', $value, $found ) ) {
			return null;
		}
		return $found[1] . 'T' . $found[2] . 'Z';
	}

	/**
	 * The JSON shape of a backup (see the class docblock).
	 *
	 * @param array<string,mixed>            $backup Backup row.
	 * @param JobState|null                  $state  Its state.
	 * @param array<int,array<string,mixed>> $files  Local file rows (destination 0, done) of a finished backup.
	 * @param int                            $now    Unix time.
	 * @param callable|null                  $url_for function( backup_id, file_id ): string for download links.
	 * @param array<int,string>              $roots  Folders to hide in messages.
	 * @return array<string,mixed>
	 */
	public static function payload( array $backup, ?JobState $state, array $files, $now, $url_for = null, array $roots = array() ) {
		$status   = (string) $backup['status'];
		$finished = in_array( $status, BackupsRepository::FINISHED, true );

		if ( 'complete' === $status || 'partial' === $status ) {
			$step = JobState::STEP_DONE;
		} elseif ( 'failed' === $status ) {
			$step = JobState::STEP_FAILED;
		} elseif ( 'queued' === $status || null === $state ) {
			$step = '';
		} else {
			$step = $state->get_step();
		}

		$cursor   = null === $state ? array() : $state->get_cursor();
		$meta     = null === $state ? array() : $state->get_meta();
		$progress = Progress::describe( $step, $cursor, $meta );
		if ( 'failed' === $status ) {
			$progress['percent'] = 0;
		}

		$started = self::time_value( $backup['started_at'], $state ? $state->get_started_at() : 0 );
		$ended   = $finished ? self::time_value( $backup['finished_at'], 0 ) : $now;
		$elapsed = $started > 0 && $ended >= $started ? $ended - $started : 0;

		$error = null;
		if ( 'failed' === $status ) {
			$text  = null !== $state && null !== $state->get_error() ? (string) $state->get_error() : __( 'The backup failed.', 'openvault-backup' );
			$error = self::hide_paths( $text, $roots );
		}

		$warnings = 0;
		if ( isset( $meta['archive']['warnings'] ) ) {
			$warnings = (int) $meta['archive']['warnings'];
		} elseif ( isset( $cursor['warned'] ) ) {
			$warnings = (int) $cursor['warned'];
		}

		$parts = array();
		if ( 'complete' === $status || 'partial' === $status ) {
			foreach ( $files as $file ) {
				$parts[] = array(
					'id'           => (int) $file['id'],
					'name'         => (string) $file['file_name'],
					'size'         => (int) $file['size'],
					'sha256'       => (string) $file['checksum_sha256'],
					'kind'         => 'manifest.json' === $file['file_name'] ? 'manifest' : ( 0 === strpos( (string) $file['file_name'], 'db.' ) ? 'db' : 'files' ),
					'download_url' => is_callable( $url_for ) ? (string) call_user_func( $url_for, (int) $backup['id'], (int) $file['id'] ) : '',
				);
			}
		}

		$message = $progress['label'];
		if ( '' !== $progress['detail'] ) {
			$message .= ' (' . $progress['detail'] . ')';
		}
		if ( $finished ) {
			$message = 'complete' === $status ? __( 'The backup is complete.', 'openvault-backup' ) : ( 'partial' === $status ? __( 'The backup finished with warnings.', 'openvault-backup' ) : __( 'The backup failed.', 'openvault-backup' ) );
		}

		return array(
			'id'              => (int) $backup['id'],
			'status'          => $status,
			'finished'        => $finished,
			'step'            => $step,
			'step_label'      => $progress['label'],
			'percent'         => $progress['percent'],
			'message'         => $message,
			'detail'          => $progress['detail'],
			'started_at'      => self::iso_time( $backup['started_at'] ),
			'finished_at'     => self::iso_time( $backup['finished_at'] ),
			'elapsed_seconds' => $elapsed,
			'eta_seconds'     => $finished ? null : Progress::eta_seconds( $progress['percent'], $elapsed ),
			'total_size'      => (int) $backup['total_size'],
			'format'          => (string) $backup['format'],
			'locked'          => (bool) $backup['locked'],
			'note'            => (string) $backup['note'],
			'parts'           => $parts,
			'warnings'        => $warnings,
			'error'           => $error,
		);
	}

	// Route callbacks.

	/**
	 * POST /backups.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_backup( $request ) {
		$backups  = new BackupsRepository();
		$detector = new CapabilityDetector();

		$format = $request->get_param( 'format' );
		if ( null === $format || '' === $format ) {
			$format = $detector->default_format();
		}
		if ( null === $format || ! self::validate_format( $format, $detector->formats() ) ) {
			return self::error( 'ovb_no_format', __( 'No archive format is available on this server. Open System Info to see which PHP extensions are missing.', 'openvault-backup' ), 400 );
		}

		$contents = $request->get_param( 'contents' );
		$contents = null === $contents ? self::DEFAULT_CONTENTS : self::sanitize_contents( $contents );
		if ( ! in_array( true, $contents, true ) ) {
			return self::error( 'ovb_nothing_selected', __( 'Nothing is selected to back up. Select at least one item.', 'openvault-backup' ), 400 );
		}

		try {
			Paths::backups_dir();
		} catch ( \RuntimeException $e ) {
			return self::error( 'ovb_no_folder', __( 'The local backup folder could not be created. Check that the wp-content folder is writable.', 'openvault-backup' ), 500 );
		}

		// Atomic guard: add_option() fails when the option exists, so two requests cannot both pass the check below.
		$guard = add_option( self::START_GUARD, (string) time(), '', false );
		if ( ! $guard ) {
			$stamp = (int) get_option( self::START_GUARD, 0 );
			if ( time() - $stamp < 30 ) {
				return self::error( 'ovb_backup_running', __( 'A backup is already starting or running. Wait for it to finish or cancel it first.', 'openvault-backup' ), 409 );
			}
			update_option( self::START_GUARD, (string) time(), false );
		}

		try {
			$running = $backups->find_incomplete( 1 );
			if ( array() !== $running ) {
				return self::error(
					'ovb_backup_running',
					__( 'A backup is already running. Wait for it to finish or cancel it first.', 'openvault-backup' ),
					409,
					array( 'backup_id' => (int) $running[0]['id'] )
				);
			}

			$note = $request->get_param( 'note' );
			$id   = $backups->create(
				array(
					'trigger_type' => 'manual',
					'contents'     => $contents,
					'format'       => $format,
					'note'         => is_string( $note ) ? $note : '',
				)
			);
			if ( $id < 1 ) {
				return self::error( 'ovb_create_failed', __( 'The backup could not be created.', 'openvault-backup' ), 500 );
			}

			if ( ! ( new JobRunner() )->start( $id ) ) {
				return self::error( 'ovb_start_failed', __( 'The backup could not be started. See the logs for details.', 'openvault-backup' ), 500, array( 'backup_id' => $id ) );
			}
		} finally {
			delete_option( self::START_GUARD );
		}//end try

		return new \WP_REST_Response( $this->progress_for( $id ), 201 );
	}

	/**
	 * GET /backups.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_backups( $request ) {
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$result   = ( new BackupsRepository() )->paginate(
			array(
				'per_page' => $per_page,
				'page'     => $page,
			)
		);

		$items = array();
		foreach ( $result['items'] as $backup ) {
			$items[] = $this->progress_for( (int) $backup['id'], $backup, false );
		}

		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $per_page ) ) );
		return $response;
	}

	/**
	 * GET /backups/{id}/progress (may run one slice, see the class docblock).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_progress( $request ) {
		$id      = (int) $request['id'];
		$backups = new BackupsRepository();
		$backup  = $backups->find( $id );
		if ( null === $backup ) {
			return self::not_found();
		}

		if ( in_array( $backup['status'], BackupsRepository::INCOMPLETE, true ) ) {
			$this->kick( $id );
		}

		return new \WP_REST_Response( $this->progress_for( $id ), 200 );
	}

	/**
	 * POST /backups/{id}/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_backup( $request ) {
		$id = (int) $request['id'];
		if ( null === ( new BackupsRepository() )->find( $id ) ) {
			return self::not_found();
		}
		if ( ! ( new JobRunner() )->cancel( $id ) ) {
			return self::error( 'ovb_not_cancellable', __( 'This backup has already finished.', 'openvault-backup' ), 409 );
		}
		return new \WP_REST_Response( $this->progress_for( $id ), 200 );
	}

	/**
	 * POST /backups/{id}/lock.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lock_backup( $request ) {
		$id      = (int) $request['id'];
		$backups = new BackupsRepository();
		if ( null === $backups->find( $id ) ) {
			return self::not_found();
		}
		if ( ! $backups->set_locked( $id, (bool) $request->get_param( 'locked' ) ) ) {
			return self::error( 'ovb_update_failed', __( 'The backup could not be updated.', 'openvault-backup' ), 500 );
		}
		return new \WP_REST_Response( $this->progress_for( $id ), 200 );
	}

	/**
	 * POST /backups/{id}/note.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function note_backup( $request ) {
		$id      = (int) $request['id'];
		$backups = new BackupsRepository();
		if ( null === $backups->find( $id ) ) {
			return self::not_found();
		}
		if ( ! $backups->set_note( $id, (string) $request->get_param( 'note' ) ) ) {
			return self::error( 'ovb_update_failed', __( 'The backup could not be updated.', 'openvault-backup' ), 500 );
		}
		return new \WP_REST_Response( $this->progress_for( $id ), 200 );
	}

	/**
	 * DELETE /backups/{id}: refuses locked and unfinished backups, removes the folder, then the rows.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_backup( $request ) {
		$id      = (int) $request['id'];
		$backups = new BackupsRepository();
		$backup  = $backups->find( $id );
		if ( null === $backup ) {
			return self::not_found();
		}
		if ( in_array( $backup['status'], BackupsRepository::INCOMPLETE, true ) ) {
			return self::error( 'ovb_backup_running', __( 'This backup is still running. Cancel it before deleting it.', 'openvault-backup' ), 409 );
		}
		if ( $backup['locked'] ) {
			return self::error( 'ovb_backup_locked', __( 'This backup is locked. Unlock it before deleting it.', 'openvault-backup' ), 409 );
		}

		try {
			$removed = BackupFiles::delete_folder( Paths::backups_dir(), $backup['created_at'], $backup['uuid'] );
		} catch ( \RuntimeException $e ) {
			$removed = false;
		}
		if ( ! $removed ) {
			return self::error( 'ovb_delete_files_failed', __( 'The backup files could not be removed, so the backup was kept. Check the folder permissions and try again.', 'openvault-backup' ), 500 );
		}
		if ( ! $backups->delete( $id ) ) {
			return self::error( 'ovb_delete_failed', __( 'The backup could not be deleted.', 'openvault-backup' ), 500 );
		}

		( new Logger() )->info( __( 'Backup deleted.', 'openvault-backup' ), array( 'backup_id' => $id ) );
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
	 * Run one slice inline when no one else is making progress.
	 *
	 * @param int $id Backup id.
	 * @return void
	 */
	private function kick( $id ) {
		$state = ( new BackupsRepository() )->load_state( $id );
		if ( null === $state || $state->is_terminal() ) {
			return;
		}

		/**
		 * Seconds without a heartbeat before a progress request runs a slice itself.
		 *
		 * @param int $seconds Seconds.
		 */
		$after = max( 1, (int) apply_filters( 'ovb_kick_after_seconds', self::KICK_AFTER ) );
		if ( ( time() - $state->get_heartbeat() ) < $after || ( new JobLock( $id ) )->is_locked( JobRunner::LOCK_TTL ) ) {
			return;
		}

		( new JobRunner() )->run( $id );
	}

	/**
	 * Progress payload of a backup read fresh from the database.
	 *
	 * @param int                      $id     Backup id.
	 * @param array<string,mixed>|null $backup Row when already loaded.
	 * @param bool                     $parts  Load the file rows (finished backups).
	 * @return array<string,mixed>
	 */
	private function progress_for( $id, $backup = null, $parts = true ) {
		$backups = new BackupsRepository();
		$backup  = null === $backup ? $backups->find( $id ) : $backup;
		if ( null === $backup ) {
			return array();
		}

		$files = array();
		if ( $parts && in_array( $backup['status'], array( 'complete', 'partial' ), true ) ) {
			foreach ( ( new BackupFilesRepository() )->for_backup_and_destination( $id, 0 ) as $file ) {
				if ( 'done' === $file['upload_status'] ) {
					$files[] = $file;
				}
			}
		}
		$finished_ok = in_array( $backup['status'], array( 'complete', 'partial' ), true );
		$state       = $parts || ! $finished_ok ? $backups->load_state( $id ) : null;

		$roots = array();
		try {
			$roots[] = Paths::local_dir();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$roots[] = WP_CONTENT_DIR;
		$roots[] = ABSPATH;

		return self::payload( $backup, $state, $files, time(), array( DownloadController::class, 'url' ), $roots );
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
	 * 404 error.
	 *
	 * @return \WP_Error
	 */
	private static function not_found() {
		return self::error( 'ovb_not_found', __( 'That backup does not exist.', 'openvault-backup' ), 404 );
	}

	/**
	 * Boolean from a REST value.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}

	/**
	 * Character length that works without mbstring.
	 *
	 * @param string $value Text.
	 * @return int
	 */
	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Unix time of a row time string, falling back to $fallback.
	 *
	 * @param mixed $value    UTC "Y-m-d H:i:s" or null.
	 * @param int   $fallback Fallback.
	 * @return int
	 */
	private static function time_value( $value, $fallback ) {
		if ( is_string( $value ) ) {
			$time = strtotime( $value . ' UTC' );
			if ( false !== $time ) {
				return $time;
			}
		}
		return (int) $fallback;
	}
}
