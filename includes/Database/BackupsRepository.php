<?php
/**
 * CRUD for {prefix}ovb_backups.
 *
 * Phase: MVP
 *
 * Purpose: CRUD for {prefix}ovb_backups.
 *
 * Responsibilities:
 *  - create/update status, find, paginate for history table
 *  - Mark locked (keep forever)
 *  - Search by schedule, type, date
 *
 * Rows come back as plain arrays with typed values and decoded JSON. The large `state` column is
 * never part of a normal row; use load_state() / save_state() for it.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Database;

use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Support\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for backups.
 */
class BackupsRepository {

	const TRIGGERS = array( 'manual', 'schedule', 'pre-update', 'pre-restore' );
	const STATUSES = array( 'queued', 'running', 'uploading', 'complete', 'failed', 'partial' );
	const FORMATS  = array( 'zip', 'zip-store', 'tar.gz', 'tar.bz2' );

	/**
	 * Statuses of a job that has not finished yet.
	 */
	const INCOMPLETE = array( 'queued', 'running', 'uploading' );

	/**
	 * Statuses that end a job.
	 */
	const FINISHED = array( 'complete', 'failed', 'partial' );

	const ORDER_COLUMNS = array( 'id', 'created_at', 'finished_at', 'total_size', 'status' );

	const NOTE_MAX = 1000;

	const COLUMNS = 'id, uuid, trigger_type, schedule_id, contents, format, encrypted, status, total_size, locked, note, started_at, finished_at, created_at, updated_at';

	/**
	 * Backup file rows.
	 *
	 * @var BackupFilesRepository
	 */
	private $files;

	/**
	 * Constructor.
	 *
	 * @param BackupFilesRepository|null $files Repository used to remove a backup's file rows.
	 */
	public function __construct( ?BackupFilesRepository $files = null ) {
		$this->files = null === $files ? new BackupFilesRepository() : $files;
	}

	/**
	 * Full table name (prefix aware).
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'ovb_backups';
	}

	/**
	 * Validate and normalise backup fields. Unknown keys are dropped.
	 *
	 * @param array<string,mixed> $input          Fields.
	 * @param bool                $apply_defaults Fill defaults for missing keys (create) or only validate given keys (update).
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When a value is invalid.
	 */
	public static function normalise_fields( array $input, $apply_defaults = true ) {
		$out = array();

		if ( array_key_exists( 'uuid', $input ) && null !== $input['uuid'] ) {
			if ( ! Manifest::is_valid_uuid( $input['uuid'] ) ) {
				throw new \InvalidArgumentException( 'Invalid uuid.' );
			}
			$out['uuid'] = strtolower( $input['uuid'] );
		} elseif ( $apply_defaults ) {
			$out['uuid'] = Manifest::generate_uuid();
		}

		$out = array_merge(
			$out,
			self::enum_field( $input, 'trigger_type', self::TRIGGERS, 'manual', $apply_defaults ),
			self::enum_field( $input, 'status', self::STATUSES, 'queued', $apply_defaults ),
			self::enum_field( $input, 'format', self::FORMATS, 'zip', $apply_defaults )
		);

		if ( array_key_exists( 'schedule_id', $input ) ) {
			$schedule_id = $input['schedule_id'];
			if ( null === $schedule_id || '' === $schedule_id || 0 === $schedule_id ) {
				$out['schedule_id'] = null;
			} else {
				$schedule_id = Util::non_negative_int( $schedule_id, 'schedule_id' );
				if ( $schedule_id < 1 ) {
					throw new \InvalidArgumentException( 'Invalid schedule_id.' );
				}
				$out['schedule_id'] = $schedule_id;
			}
		} elseif ( $apply_defaults ) {
			$out['schedule_id'] = null;
		}

		if ( array_key_exists( 'contents', $input ) ) {
			if ( ! is_array( $input['contents'] ) ) {
				throw new \InvalidArgumentException( 'Invalid contents.' );
			}
			$out['contents'] = $input['contents'];
		} elseif ( $apply_defaults ) {
			$out['contents'] = array();
		}

		foreach ( array( 'encrypted', 'locked' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
			} elseif ( $apply_defaults ) {
				$out[ $flag ] = 0;
			}
		}

		if ( array_key_exists( 'total_size', $input ) ) {
			$out['total_size'] = Util::non_negative_int( $input['total_size'], 'total_size' );
		} elseif ( $apply_defaults ) {
			$out['total_size'] = 0;
		}

		if ( array_key_exists( 'note', $input ) ) {
			$out['note'] = self::sanitise_note( $input['note'] );
		} elseif ( $apply_defaults ) {
			$out['note'] = '';
		}

		foreach ( array( 'started_at', 'finished_at' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				if ( null !== $input[ $field ] && ! Util::is_datetime( $input[ $field ] ) ) {
					throw new \InvalidArgumentException( 'Invalid ' . $field . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed field name.
				}
				$out[ $field ] = $input[ $field ];
			} elseif ( $apply_defaults ) {
				$out[ $field ] = null;
			}
		}

		return $out;
	}

	/**
	 * A user note: control characters removed, trimmed, limited to 1000 characters.
	 *
	 * @param mixed $note Note.
	 * @return string
	 */
	public static function sanitise_note( $note ) {
		return Util::clean_text( $note, self::NOTE_MAX, true );
	}

	/**
	 * Validate a status.
	 *
	 * @param mixed $status Status.
	 * @return string
	 * @throws \InvalidArgumentException When unknown.
	 */
	public static function validate_status( $status ) {
		$ok = Util::one_of( $status, self::STATUSES );
		if ( null === $ok ) {
			throw new \InvalidArgumentException( 'Invalid status.' );
		}
		return $ok;
	}

	/**
	 * Validate and normalise list filters. Unknown filter values are dropped (never passed to SQL).
	 *
	 * @param array<string,mixed> $args Arguments: status, trigger_type, schedule_id, date_from, date_to, search,
	 *                                  orderby, order, per_page, page.
	 * @return array<string,mixed>
	 */
	public static function normalise_query( array $args ) {
		$query = Util::clamp_pagination( $args );

		$query['status']       = isset( $args['status'] ) ? Util::one_of( $args['status'], self::STATUSES ) : null;
		$query['trigger_type'] = isset( $args['trigger_type'] ) ? Util::one_of( $args['trigger_type'], self::TRIGGERS ) : null;
		$query['schedule_id']  = isset( $args['schedule_id'] ) && is_numeric( $args['schedule_id'] ) && (int) $args['schedule_id'] > 0 ? (int) $args['schedule_id'] : null;
		$query['date_from']    = isset( $args['date_from'] ) ? Util::filter_datetime( $args['date_from'], false ) : null;
		$query['date_to']      = isset( $args['date_to'] ) ? Util::filter_datetime( $args['date_to'], true ) : null;
		$query['search']       = isset( $args['search'] ) ? Util::clean_text( $args['search'], 100 ) : '';

		$orderby          = isset( $args['orderby'] ) ? Util::one_of( $args['orderby'], self::ORDER_COLUMNS ) : null;
		$query['orderby'] = null === $orderby ? 'created_at' : $orderby;
		$query['order']   = isset( $args['order'] ) ? Util::direction( $args['order'] ) : 'DESC';

		return $query;
	}

	/**
	 * Create a backup row.
	 *
	 * @param array<string,mixed> $fields Fields (see normalise_fields()). The uuid is generated when missing.
	 * @return int New id, 0 on database failure.
	 * @throws \InvalidArgumentException When a field is invalid.
	 */
	public function create( array $fields ) {
		global $wpdb;

		$data               = self::normalise_fields( $fields, true );
		$now                = Util::now();
		$data['contents']   = wp_json_encode( $data['contents'] );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			$data,
			self::formats( $data )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Find by id.
	 *
	 * @param int $id Backup id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Find by uuid.
	 *
	 * @param string $uuid UUID.
	 * @return array<string,mixed>|null
	 */
	public function find_by_uuid( $uuid ) {
		global $wpdb;

		if ( ! Manifest::is_valid_uuid( $uuid ) ) {
			return null;
		}

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE uuid = %s", strtolower( $uuid ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Move a backup to a new status.
	 *
	 * Sets started_at the first time the status becomes "running" and finished_at when it becomes
	 * complete, failed or partial. $extra may carry total_size, note, encrypted, locked, started_at, finished_at.
	 *
	 * @param int                 $id     Backup id.
	 * @param string              $status New status.
	 * @param array<string,mixed> $extra  Extra fields.
	 * @return bool False when the row does not exist or the query failed.
	 * @throws \InvalidArgumentException For an unknown status or an invalid extra field.
	 */
	public function update_status( $id, $status, array $extra = array() ) {
		global $wpdb;

		$status = self::validate_status( $status );
		$extra  = self::normalise_fields( $extra, false );
		unset( $extra['uuid'], $extra['trigger_type'], $extra['format'], $extra['schedule_id'], $extra['contents'], $extra['status'] );

		$now  = Util::now();
		$data = array_merge( $extra, array( 'status' => $status ) );
		if ( in_array( $status, self::FINISHED, true ) && ! isset( $data['finished_at'] ) ) {
			$data['finished_at'] = $now;
		}
		$data['updated_at'] = $now;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			$data,
			array( 'id' => (int) $id ),
			self::formats( $data ),
			array( '%d' )
		);
		if ( false === $result ) {
			return false;
		}

		if ( 'running' === $status && ! isset( $data['started_at'] ) ) {
			$table = $this->table();
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "UPDATE {$table} SET started_at = %s WHERE id = %d AND started_at IS NULL", $now, (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return $this->row_exists( $id );
	}

	/**
	 * Store the job state.
	 *
	 * @param int      $id    Backup id.
	 * @param JobState $state State.
	 * @return bool
	 */
	public function save_state( $id, JobState $state ) {
		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			array(
				'state'      => $state->to_json(),
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result && $this->row_exists( $id );
	}

	/**
	 * Load the job state.
	 *
	 * @param int $id Backup id.
	 * @return JobState|null Null when the row is missing or the stored state is empty or invalid.
	 */
	public function load_state( $id ) {
		global $wpdb;

		$table = $this->table();
		$json  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT state FROM {$table} WHERE id = %d", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		try {
			return JobState::from_json( $json );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Lock (keep forever) or unlock a backup.
	 *
	 * @param int  $id     Backup id.
	 * @param bool $locked Locked.
	 * @return bool
	 */
	public function set_locked( $id, $locked ) {
		return $this->update_columns( $id, array( 'locked' => $locked ? 1 : 0 ) );
	}

	/**
	 * Set the user note.
	 *
	 * @param int    $id   Backup id.
	 * @param string $note Note (sanitised, limited to 1000 characters).
	 * @return bool
	 */
	public function set_note( $id, $note ) {
		return $this->update_columns( $id, array( 'note' => self::sanitise_note( $note ) ) );
	}

	/**
	 * Set the total size.
	 *
	 * @param int $id    Backup id.
	 * @param int $bytes Bytes (>= 0).
	 * @return bool
	 * @throws \InvalidArgumentException For a negative or non-integer size.
	 */
	public function set_total_size( $id, $bytes ) {
		return $this->update_columns( $id, array( 'total_size' => Util::non_negative_int( $bytes, 'total_size' ) ) );
	}

	/**
	 * Add bytes to the total size atomically.
	 *
	 * @param int $id    Backup id.
	 * @param int $bytes Bytes to add (>= 0).
	 * @return bool
	 * @throws \InvalidArgumentException For a negative or non-integer size.
	 */
	public function add_size( $id, $bytes ) {
		global $wpdb;

		$bytes = Util::non_negative_int( $bytes, 'size' );
		$table = $this->table();

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "UPDATE {$table} SET total_size = total_size + %d, updated_at = %s WHERE id = %d", $bytes, Util::now(), (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return false !== $result;
	}

	/**
	 * One page of backups, newest first by default.
	 *
	 * @param array<string,mixed> $args See normalise_query().
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function paginate( array $args = array() ) {
		global $wpdb;

		$query  = self::normalise_query( $args );
		$where  = array( '1=1' );
		$params = array();

		if ( null !== $query['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $query['status'];
		}
		if ( null !== $query['trigger_type'] ) {
			$where[]  = 'trigger_type = %s';
			$params[] = $query['trigger_type'];
		}
		if ( null !== $query['schedule_id'] ) {
			$where[]  = 'schedule_id = %d';
			$params[] = $query['schedule_id'];
		}
		if ( null !== $query['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $query['date_from'];
		}
		if ( null !== $query['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = $query['date_to'];
		}
		if ( '' !== $query['search'] ) {
			$where[]  = 'note LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $query['search'] ) . '%';
		}

		$table     = $this->table();
		$where_sql = implode( ' AND ', $where );
		$order_sql = $query['orderby'] . ' ' . $query['order'] . ', id ' . $query['order'];
		// Both values come from whitelists.

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Util::prepare_list( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
		);

		$list_params   = $params;
		$list_params[] = $query['per_page'];
		$list_params[] = $query['offset'];
		$rows          = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Util::prepare_list( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d", $list_params ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Delete a backup and its file rows. Locked backups are refused unless $force is true.
	 *
	 * Removing the archive files themselves (locally and at destinations) is the caller's job.
	 *
	 * @param int  $id    Backup id.
	 * @param bool $force Delete even if locked.
	 * @return bool True when a row was deleted.
	 */
	public function delete( $id, $force = false ) {
		global $wpdb;

		$row = $this->find( $id );
		if ( null === $row ) {
			return false;
		}
		if ( $row['locked'] && ! $force ) {
			return false;
		}

		$this->files->delete_for_backup( (int) $id );

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			array( 'id' => (int) $id ),
			array( '%d' )
		);

		return 1 === $deleted;
	}

	/**
	 * Backups that have not finished (queued, running, uploading), oldest first. For stalled-job detection.
	 *
	 * @param int $limit Maximum rows (1..500).
	 * @return array<int,array<string,mixed>>
	 */
	public function find_incomplete( $limit = 200 ) {
		global $wpdb;

		$table = $this->table();
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE status IN (%s,%s,%s) ORDER BY id ASC LIMIT %d", self::INCOMPLETE[0], self::INCOMPLETE[1], self::INCOMPLETE[2], $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Most recent complete backup.
	 *
	 * @param int|null $schedule_id Only backups of this schedule.
	 * @return array<string,mixed>|null
	 */
	public function latest_complete( $schedule_id = null ) {
		global $wpdb;

		$table = $this->table();
		if ( null === $schedule_id ) {
			$sql = $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE status = %s ORDER BY finished_at DESC, id DESC LIMIT 1", 'complete' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
		} else {
			$sql = $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE status = %s AND schedule_id = %d ORDER BY finished_at DESC, id DESC LIMIT 1", 'complete', (int) $schedule_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
		}

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Number of backups per status (every known status is present, zero when none).
	 *
	 * @return array<string,int>
	 */
	public function count_by_status() {
		global $wpdb;

		$table  = $this->table();
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No user input in this query.
		$counts = array_fill_keys( self::STATUSES, 0 );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Convert a raw database row into a typed array.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ) {
		return array(
			'id'           => (int) $row['id'],
			'uuid'         => (string) $row['uuid'],
			'trigger_type' => (string) $row['trigger_type'],
			'schedule_id'  => isset( $row['schedule_id'] ) ? (int) $row['schedule_id'] : null,
			'contents'     => Util::decode_json( isset( $row['contents'] ) ? $row['contents'] : null ),
			'format'       => (string) $row['format'],
			'encrypted'    => ! empty( $row['encrypted'] ),
			'status'       => (string) $row['status'],
			'total_size'   => (int) $row['total_size'],
			'locked'       => ! empty( $row['locked'] ),
			'note'         => isset( $row['note'] ) ? (string) $row['note'] : '',
			'started_at'   => isset( $row['started_at'] ) ? (string) $row['started_at'] : null,
			'finished_at'  => isset( $row['finished_at'] ) ? (string) $row['finished_at'] : null,
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
		);
	}

	/**
	 * One enum field: validated when present, defaulted on create.
	 *
	 * @param array<string,mixed> $input          Input.
	 * @param string              $key            Field.
	 * @param string[]            $allowed        Allowed values.
	 * @param string              $default_value  Default on create.
	 * @param bool                $apply_defaults Whether to apply the default.
	 * @return array<string,string>
	 * @throws \InvalidArgumentException For an unknown value.
	 */
	private static function enum_field( array $input, $key, array $allowed, $default_value, $apply_defaults ) {
		if ( array_key_exists( $key, $input ) ) {
			$value = Util::one_of( $input[ $key ], $allowed );
			if ( null === $value ) {
				throw new \InvalidArgumentException( 'Invalid ' . $key . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed field name.
			}
			return array( $key => $value );
		}
		return $apply_defaults ? array( $key => $default_value ) : array();
	}

	/**
	 * Placeholder formats for a column => value map.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string[]
	 */
	private static function formats( array $data ) {
		$ints    = array( 'schedule_id', 'encrypted', 'locked', 'total_size' );
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = in_array( $column, $ints, true ) ? '%d' : '%s';
		}
		return $formats;
	}

	/**
	 * Update a few validated columns of one row (also bumps updated_at).
	 *
	 * @param int                 $id   Backup id.
	 * @param array<string,mixed> $data Columns.
	 * @return bool False when the row does not exist or the query failed.
	 */
	private function update_columns( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = Util::now();
		$result             = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			$data,
			array( 'id' => (int) $id ),
			self::formats( $data ),
			array( '%d' )
		);

		return false !== $result && $this->row_exists( $id );
	}

	/**
	 * Whether a row exists (an UPDATE that changes nothing reports 0 rows, so existence is checked separately).
	 *
	 * @param int $id Backup id.
	 * @return bool
	 */
	private function row_exists( $id ) {
		global $wpdb;

		$table = $this->table();
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return null !== $found;
	}
}
