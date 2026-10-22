<?php
/**
 * Serializable state of a running job.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Current step, cursor (table/row offset, file index, upload chunk offset)
 *  - Stored in ovb_backups.state JSON
 *  - Plain value object: immutable, round-trips through JSON only (never PHP serialize()).
 *  - The cursor is step-specific, must be JSON-safe and stays under 64 KB (a job state must stay small).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * JobState.
 */
final class JobState {

	const SCHEMA_VERSION = 1;

	const STEP_PREFLIGHT = 'preflight';
	const STEP_DB_EXPORT = 'db_export';
	const STEP_FILE_SCAN = 'file_scan';
	const STEP_ARCHIVE   = 'archive';
	const STEP_ENCRYPT   = 'encrypt';
	const STEP_UPLOAD    = 'upload';
	const STEP_RETENTION = 'retention';
	const STEP_CLEANUP   = 'cleanup';

	// Restore jobs (Milestone 5) use the same state class with these steps.
	const STEP_FETCH        = 'fetch';
	const STEP_VERIFY       = 'verify';
	const STEP_SNAPSHOT     = 'snapshot';
	const STEP_DB_RESTORE   = 'db_restore';
	const STEP_FILE_RESTORE = 'file_restore';
	const STEP_FILE_UNDO    = 'file_undo';
	const STEP_FINISH       = 'finish';

	const STEP_DONE   = 'done';
	const STEP_FAILED = 'failed';

	const STEPS = array(
		self::STEP_PREFLIGHT,
		self::STEP_DB_EXPORT,
		self::STEP_FILE_SCAN,
		self::STEP_ARCHIVE,
		self::STEP_ENCRYPT,
		self::STEP_UPLOAD,
		self::STEP_RETENTION,
		self::STEP_CLEANUP,
		self::STEP_FETCH,
		self::STEP_VERIFY,
		self::STEP_SNAPSHOT,
		self::STEP_DB_RESTORE,
		self::STEP_FILE_RESTORE,
		self::STEP_FILE_UNDO,
		self::STEP_FINISH,
		self::STEP_DONE,
		self::STEP_FAILED,
	);

	const MAX_CURSOR_BYTES = 65536;
	const MAX_META_BYTES   = 16384;
	const MAX_JSON_BYTES   = 131072;
	const MAX_ERROR_BYTES  = 1000;
	const MAX_DEPTH        = 16;

	/**
	 * Step.
	 *
	 * @var string
	 */
	private $step;

	/**
	 * Step-specific cursor.
	 *
	 * @var array<mixed>
	 */
	private $cursor = array();

	/**
	 * Last sign of life (unix time).
	 *
	 * @var int
	 */
	private $heartbeat = 0;

	/**
	 * Attempts of the current step.
	 *
	 * @var int
	 */
	private $attempts = 0;

	/**
	 * Start time.
	 *
	 * @var int
	 */
	private $started_at = 0;

	/**
	 * Last change time.
	 *
	 * @var int
	 */
	private $updated_at = 0;

	/**
	 * Error message of a failed job.
	 *
	 * @var string|null
	 */
	private $error;

	/**
	 * Small free-form data.
	 *
	 * @var array<mixed>
	 */
	private $meta = array();

	/**
	 * Use the factories.
	 *
	 * @param string $step Step.
	 * @throws \InvalidArgumentException For an unknown step.
	 */
	private function __construct( $step ) {
		self::assert_step( $step );
		$this->step = $step;
	}

	/**
	 * Fresh state.
	 *
	 * @param string   $step Starting step.
	 * @param int|null $now  Unix time (defaults to now).
	 * @return self
	 * @throws \InvalidArgumentException For an unknown step.
	 */
	public static function new_state( $step = self::STEP_PREFLIGHT, ?int $now = null ) {
		$now               = self::clock( $now );
		$state             = new self( $step );
		$state->heartbeat  = $now;
		$state->started_at = $now;
		$state->updated_at = $now;
		return $state;
	}

	/**
	 * Parse stored JSON.
	 *
	 * @param string $json JSON.
	 * @return self
	 * @throws \InvalidArgumentException On malformed input, unknown version or unknown step.
	 */
	public static function from_json( $json ) {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_JSON_BYTES ) {
			throw new \InvalidArgumentException( 'Job state JSON is empty or too large.' );
		}

		$data = json_decode( $json, true, 32 );
		if ( ! is_array( $data ) || array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			throw new \InvalidArgumentException( 'Job state JSON is malformed.' );
		}

		if ( ! isset( $data['version'] ) || ! is_int( $data['version'] ) || self::SCHEMA_VERSION !== $data['version'] ) {
			throw new \InvalidArgumentException( 'Unsupported job state version.' );
		}
		if ( ! isset( $data['step'] ) || ! is_string( $data['step'] ) ) {
			throw new \InvalidArgumentException( 'Job state has no valid step.' );
		}

		$state             = new self( $data['step'] );
		$state->cursor     = self::read_array( $data, 'cursor', self::MAX_CURSOR_BYTES );
		$state->meta       = self::read_array( $data, 'meta', self::MAX_META_BYTES );
		$state->heartbeat  = self::read_int( $data, 'heartbeat' );
		$state->attempts   = self::read_int( $data, 'attempts' );
		$state->started_at = self::read_int( $data, 'started_at' );
		$state->updated_at = self::read_int( $data, 'updated_at' );

		if ( isset( $data['error'] ) ) {
			if ( ! is_string( $data['error'] ) ) {
				throw new \InvalidArgumentException( 'Job state error must be a string.' );
			}
			$state->error = $data['error'];
		}

		return $state;
	}

	/**
	 * Plain array form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'version'    => self::SCHEMA_VERSION,
			'step'       => $this->step,
			'cursor'     => $this->cursor,
			'heartbeat'  => $this->heartbeat,
			'attempts'   => $this->attempts,
			'started_at' => $this->started_at,
			'updated_at' => $this->updated_at,
			'error'      => $this->error,
			'meta'       => $this->meta,
		);
	}

	/**
	 * JSON form.
	 *
	 * @return string
	 * @throws \InvalidArgumentException If encoding fails (cannot happen for validated state).
	 */
	public function to_json() {
		$json = json_encode( $this->to_array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Works without WordPress loaded (unit tests).
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'Job state cannot be encoded.' );
		}
		return $json;
	}

	/**
	 * Move to another step. The cursor is replaced and the attempt counter reset.
	 *
	 * @param string       $step   New step.
	 * @param array<mixed> $cursor New cursor.
	 * @param int|null     $now    Unix time.
	 * @return self
	 * @throws \InvalidArgumentException For an unknown step or an invalid cursor.
	 */
	public function with_step( $step, array $cursor = array(), ?int $now = null ) {
		$copy = new self( $step );
		$copy->copy_from( $this );
		$copy->cursor   = self::validated( $cursor, self::MAX_CURSOR_BYTES );
		$copy->attempts = 0;
		$copy->error    = self::STEP_FAILED === $step ? $this->error : null;
		$copy->stamp( $now );
		return $copy;
	}

	/**
	 * Replace the cursor (also counts as a heartbeat).
	 *
	 * @param array<mixed> $cursor Cursor.
	 * @param int|null     $now    Unix time.
	 * @return self
	 * @throws \InvalidArgumentException For an invalid cursor.
	 */
	public function with_cursor( array $cursor, ?int $now = null ) {
		$copy         = clone $this;
		$copy->cursor = self::validated( $cursor, self::MAX_CURSOR_BYTES );
		$copy->stamp( $now );
		return $copy;
	}

	/**
	 * Replace the meta data.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return self
	 * @throws \InvalidArgumentException For invalid meta.
	 */
	public function with_meta( array $meta ) {
		$copy       = clone $this;
		$copy->meta = self::validated( $meta, self::MAX_META_BYTES );
		return $copy;
	}

	/**
	 * Count another attempt of the current step (also a heartbeat).
	 *
	 * @param int|null $now Unix time.
	 * @return self
	 */
	public function increment_attempts( ?int $now = null ) {
		$copy = clone $this;
		++$copy->attempts;
		$copy->stamp( $now );
		return $copy;
	}

	/**
	 * Record a heartbeat.
	 *
	 * @param int|null $now Unix time.
	 * @return self
	 */
	public function touch( ?int $now = null ) {
		$copy = clone $this;
		$copy->stamp( $now );
		return $copy;
	}

	/**
	 * Mark as failed. The cursor is kept so the failure can be inspected. The message must not
	 * contain secrets; it is truncated to 1000 bytes.
	 *
	 * @param string   $message Message.
	 * @param int|null $now     Unix time.
	 * @return self
	 */
	public function mark_failed( $message, ?int $now = null ) {
		$copy        = clone $this;
		$copy->step  = self::STEP_FAILED;
		$copy->error = substr( (string) $message, 0, self::MAX_ERROR_BYTES );
		$copy->stamp( $now );
		return $copy;
	}

	/**
	 * Whether the job made no progress for at least $timeout_seconds. Finished jobs are never stalled.
	 *
	 * @param int $now             Unix time.
	 * @param int $timeout_seconds Allowed silence.
	 * @return bool
	 */
	public function is_stalled( $now, $timeout_seconds ) {
		if ( $this->is_terminal() ) {
			return false;
		}
		return ( (int) $now - $this->heartbeat ) >= (int) $timeout_seconds;
	}

	/**
	 * Whether the job is finished (done or failed).
	 *
	 * @return bool
	 */
	public function is_terminal() {
		return self::STEP_DONE === $this->step || self::STEP_FAILED === $this->step;
	}

	/**
	 * Schema version.
	 *
	 * @return int
	 */
	public function get_version() {
		return self::SCHEMA_VERSION;
	}

	/**
	 * Step.
	 *
	 * @return string
	 */
	public function get_step() {
		return $this->step;
	}

	/**
	 * Cursor.
	 *
	 * @return array<mixed>
	 */
	public function get_cursor() {
		return $this->cursor;
	}

	/**
	 * Heartbeat.
	 *
	 * @return int
	 */
	public function get_heartbeat() {
		return $this->heartbeat;
	}

	/**
	 * Attempts.
	 *
	 * @return int
	 */
	public function get_attempts() {
		return $this->attempts;
	}

	/**
	 * Start time.
	 *
	 * @return int
	 */
	public function get_started_at() {
		return $this->started_at;
	}

	/**
	 * Last update time.
	 *
	 * @return int
	 */
	public function get_updated_at() {
		return $this->updated_at;
	}

	/**
	 * Error.
	 *
	 * @return string|null
	 */
	public function get_error() {
		return $this->error;
	}

	/**
	 * Meta.
	 *
	 * @return array<mixed>
	 */
	public function get_meta() {
		return $this->meta;
	}

	/**
	 * Copy all fields from another state.
	 *
	 * @param self $other Source.
	 * @return void
	 */
	private function copy_from( self $other ) {
		$this->cursor     = $other->cursor;
		$this->heartbeat  = $other->heartbeat;
		$this->attempts   = $other->attempts;
		$this->started_at = $other->started_at;
		$this->updated_at = $other->updated_at;
		$this->error      = $other->error;
		$this->meta       = $other->meta;
	}

	/**
	 * Set heartbeat and updated_at.
	 *
	 * @param int|null $now Unix time.
	 * @return void
	 */
	private function stamp( ?int $now ) {
		$now              = self::clock( $now );
		$this->heartbeat  = $now;
		$this->updated_at = $now;
	}

	/**
	 * Unix time.
	 *
	 * @param int|null $now Given time.
	 * @return int
	 */
	private static function clock( ?int $now ) {
		return null === $now ? time() : $now;
	}

	/**
	 * Reject unknown steps.
	 *
	 * @param mixed $step Step.
	 * @return void
	 * @throws \InvalidArgumentException For an unknown step.
	 */
	private static function assert_step( $step ) {
		if ( ! is_string( $step ) || ! in_array( $step, self::STEPS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown job step.' );
		}
	}

	/**
	 * Read an optional array field.
	 *
	 * @param array<mixed> $data  Decoded JSON.
	 * @param string       $key   Field.
	 * @param int          $limit Byte limit.
	 * @return array<mixed>
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function read_array( array $data, $key, $limit ) {
		if ( ! isset( $data[ $key ] ) ) {
			return array();
		}
		if ( ! is_array( $data[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Job state field has the wrong type.' );
		}
		return self::validated( $data[ $key ], $limit );
	}

	/**
	 * Read an optional non-negative integer field.
	 *
	 * @param array<mixed> $data Decoded JSON.
	 * @param string       $key  Field.
	 * @return int
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function read_int( array $data, $key ) {
		if ( ! isset( $data[ $key ] ) ) {
			return 0;
		}
		if ( ! is_int( $data[ $key ] ) || $data[ $key ] < 0 ) {
			throw new \InvalidArgumentException( 'Job state field has the wrong type.' );
		}
		return $data[ $key ];
	}

	/**
	 * Check that a value is JSON-safe and small enough.
	 *
	 * @param array<mixed> $value Value.
	 * @param int          $limit Byte limit of its JSON form.
	 * @return array<mixed>
	 * @throws \InvalidArgumentException When unsafe or too large.
	 */
	private static function validated( array $value, $limit ) {
		self::assert_json_safe( $value, 0 );
		$json = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Works without WordPress loaded (unit tests).
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'Job state data is not JSON-safe.' );
		}
		if ( strlen( $json ) > $limit ) {
			throw new \InvalidArgumentException( 'Job state data is too large.' );
		}
		return $value;
	}

	/**
	 * Only scalars, null and arrays; finite floats; bounded depth.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Current depth.
	 * @return void
	 * @throws \InvalidArgumentException When unsafe.
	 */
	private static function assert_json_safe( $value, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			throw new \InvalidArgumentException( 'Job state data is nested too deeply.' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				self::assert_json_safe( $item, $depth + 1 );
			}
			return;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
			return;
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			return;
		}
		throw new \InvalidArgumentException( 'Job state data is not JSON-safe.' );
	}
}
