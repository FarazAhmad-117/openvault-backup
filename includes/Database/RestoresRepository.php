<?php
/**
 * CRUD for {prefix}ovb_restores (restore jobs).
 *
 * Phase: MVP
 *
 * Purpose: Stores restore jobs. A restore replaces the database, so its record must live in a table the database
 * import never touches: every {prefix}ovb_* table survives an import (the dump excludes them), the options table does not.
 *
 * Statuses: queued and running (a runner has work to do), verified (everything is downloaded and checked, the job waits for
 * the user to confirm the apply steps), complete, failed and cancelled (finished).
 *
 * Rows come back as plain arrays with typed values. The large `state` column is never part of a normal row; use
 * load_state() / save_state() (the state is an Engine\JobState, saved with its step in the `step` column).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Database;

use OpenVault\Backup\Engine\JobState;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for restore jobs.
 */
class RestoresRepository {

	/**
	 * Modes of a row: the three a user can start and "undo" (a job that reverses an earlier restore).
	 */
	const MODES    = array( 'full', 'db', 'files', 'undo' );
	const STATUSES = array( 'queued', 'running', 'verified', 'complete', 'failed', 'cancelled' );

	/**
	 * Statuses in which a runner has work to do.
	 */
	const INCOMPLETE = array( 'queued', 'running' );

	/**
	 * Statuses of a restore that still owns the site: no other restore may start. "verified" is waiting for the user.
	 */
	const ACTIVE = array( 'queued', 'running', 'verified' );

	/**
	 * Statuses that end a job.
	 */
	const FINISHED = array( 'complete', 'failed', 'cancelled' );

	const COLUMNS = 'id, backup_id, mode, status, step, snapshot_backup_id, error, started_at, finished_at, created_at, updated_at, created_by';

	const ERROR_MAX = 1000;

	/**
	 * Full table name (prefix aware).
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'ovb_restores';
	}

	/**
	 * Validate and normalise restore fields. Unknown keys are dropped.
	 *
	 * @param array<string,mixed> $input          Fields: backup_id, mode, status, step, snapshot_backup_id, error, created_by.
	 * @param bool                $apply_defaults Fill defaults for missing keys (create) or only validate given keys (update).
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When a value is invalid.
	 */
	public static function normalise_fields( array $input, $apply_defaults = true ) {
		$out = array();

		if ( array_key_exists( 'backup_id', $input ) ) {
			$backup_id = Util::non_negative_int( $input['backup_id'], 'backup_id' );
			if ( $backup_id < 1 ) {
				throw new \InvalidArgumentException( 'Invalid backup_id.' );
			}
			$out['backup_id'] = $backup_id;
		} elseif ( $apply_defaults ) {
			throw new \InvalidArgumentException( 'backup_id is required.' );
		}

		foreach ( array(
			'mode'   => array( self::MODES, 'full' ),
			'status' => array( self::STATUSES, 'queued' ),
		) as $key => $rule ) {
			if ( array_key_exists( $key, $input ) ) {
				$value = Util::one_of( $input[ $key ], $rule[0] );
				if ( null === $value ) {
					throw new \InvalidArgumentException( 'Invalid ' . $key . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed field name.
				}
				$out[ $key ] = $value;
			} elseif ( $apply_defaults ) {
				$out[ $key ] = $rule[1];
			}
		}

		if ( array_key_exists( 'step', $input ) ) {
			if ( ! is_string( $input['step'] ) || ! in_array( $input['step'], JobState::STEPS, true ) ) {
				throw new \InvalidArgumentException( 'Invalid step.' );
			}
			$out['step'] = $input['step'];
		} elseif ( $apply_defaults ) {
			$out['step'] = JobState::STEP_PREFLIGHT;
		}

		if ( array_key_exists( 'snapshot_backup_id', $input ) ) {
			$snapshot = $input['snapshot_backup_id'];
			if ( null === $snapshot || '' === $snapshot || 0 === $snapshot ) {
				$out['snapshot_backup_id'] = null;
			} else {
				$snapshot = Util::non_negative_int( $snapshot, 'snapshot_backup_id' );
				if ( $snapshot < 1 ) {
					throw new \InvalidArgumentException( 'Invalid snapshot_backup_id.' );
				}
				$out['snapshot_backup_id'] = $snapshot;
			}
		} elseif ( $apply_defaults ) {
			$out['snapshot_backup_id'] = null;
		}

		if ( array_key_exists( 'error', $input ) ) {
			$out['error'] = null === $input['error'] ? null : Util::clean_text( $input['error'], self::ERROR_MAX, true );
		} elseif ( $apply_defaults ) {
			$out['error'] = null;
		}

		if ( array_key_exists( 'created_by', $input ) ) {
			$out['created_by'] = Util::non_negative_int( $input['created_by'], 'created_by' );
		} elseif ( $apply_defaults ) {
			$out['created_by'] = 0;
		}

		return $out;
	}

	/**
	 * Create a restore row (status queued, no state yet).
	 *
	 * @param array<string,mixed> $fields See normalise_fields().
	 * @return int New id, 0 on database failure.
	 * @throws \InvalidArgumentException When a field is invalid.
	 */
	public function create( array $fields ) {
		global $wpdb;

		$data               = self::normalise_fields( $fields, true );
		$now                = Util::now();
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
	 * @param int $id Restore id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list; values are bound.
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Move a restore to a new status.
	 *
	 * Sets started_at the first time the status becomes "running" and finished_at when it becomes complete, failed or
	 * cancelled. $extra may carry error, snapshot_backup_id, step.
	 *
	 * @param int                 $id     Restore id.
	 * @param string              $status New status.
	 * @param array<string,mixed> $extra  Extra fields.
	 * @return bool False when the row does not exist or the query failed.
	 * @throws \InvalidArgumentException For an unknown status or an invalid extra field.
	 */
	public function update_status( $id, $status, array $extra = array() ) {
		global $wpdb;

		$status = Util::one_of( $status, self::STATUSES );
		if ( null === $status ) {
			throw new \InvalidArgumentException( 'Invalid status.' );
		}
		$extra = self::normalise_fields( $extra, false );
		unset( $extra['backup_id'], $extra['mode'], $extra['created_by'], $extra['status'] );

		$now  = Util::now();
		$data = array_merge( $extra, array( 'status' => $status ) );
		if ( in_array( $status, self::FINISHED, true ) ) {
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

		if ( 'running' === $status ) {
			$table = $this->table();
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "UPDATE {$table} SET started_at = %s WHERE id = %d AND started_at IS NULL", $now, (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return $this->row_exists( $id );
	}

	/**
	 * Store the job state (and its step in the step column).
	 *
	 * @param int      $id    Restore id.
	 * @param JobState $state State.
	 * @return bool
	 */
	public function save_state( $id, JobState $state ) {
		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			array(
				'state'      => $state->to_json(),
				'step'       => $state->get_step(),
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result && $this->row_exists( $id );
	}

	/**
	 * Load the job state.
	 *
	 * @param int $id Restore id.
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
	 * Restores that still own the site (queued, running, verified), oldest first.
	 *
	 * @param int $limit Maximum rows (1..500).
	 * @return array<int,array<string,mixed>>
	 */
	public function find_active( $limit = 50 ) {
		return $this->find_with_statuses( self::ACTIVE, $limit );
	}

	/**
	 * Restores a runner has work for (queued, running), oldest first. For stalled-job detection.
	 *
	 * @param int $limit Maximum rows (1..500).
	 * @return array<int,array<string,mixed>>
	 */
	public function find_incomplete( $limit = 50 ) {
		return $this->find_with_statuses( self::INCOMPLETE, $limit );
	}

	/**
	 * One page of restore jobs, newest first.
	 *
	 * @param int $per_page Rows per page (1..100).
	 * @param int $page     Page number (1 based).
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function paginate( $per_page = 20, $page = 1 ) {
		global $wpdb;

		$table    = $this->table();
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list; values are bound.
			ARRAY_A
		);
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Whether a restore is queued or running (a backup must not start then).
	 *
	 * @return bool
	 */
	public function has_running() {
		return array() !== $this->find_incomplete( 1 );
	}

	/**
	 * Convert a raw database row into a typed array.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ) {
		return array(
			'id'                 => (int) $row['id'],
			'backup_id'          => (int) $row['backup_id'],
			'mode'               => (string) $row['mode'],
			'status'             => (string) $row['status'],
			'step'               => (string) $row['step'],
			'snapshot_backup_id' => isset( $row['snapshot_backup_id'] ) ? (int) $row['snapshot_backup_id'] : null,
			'error'              => isset( $row['error'] ) ? (string) $row['error'] : null,
			'started_at'         => isset( $row['started_at'] ) ? (string) $row['started_at'] : null,
			'finished_at'        => isset( $row['finished_at'] ) ? (string) $row['finished_at'] : null,
			'created_at'         => (string) $row['created_at'],
			'updated_at'         => (string) $row['updated_at'],
			'created_by'         => (int) $row['created_by'],
		);
	}

	/**
	 * Rows with one of the given statuses (a fixed constant list), oldest first.
	 *
	 * @param string[] $statuses Statuses (from the constants of this class).
	 * @param int      $limit    Maximum rows (1..500).
	 * @return array<int,array<string,mixed>>
	 */
	private function find_with_statuses( array $statuses, $limit ) {
		global $wpdb;

		$table        = $this->table();
		$limit        = max( 1, min( 500, (int) $limit ) );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( $statuses, array( $limit ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Util::prepare_list( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE status IN ({$placeholders}) ORDER BY id ASC LIMIT %d", $params ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and placeholders only; values are bound.
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Placeholder formats for a column => value map.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string[]
	 */
	private static function formats( array $data ) {
		$ints    = array( 'backup_id', 'snapshot_backup_id', 'created_by' );
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = in_array( $column, $ints, true ) ? '%d' : '%s';
		}
		return $formats;
	}

	/**
	 * Whether a row exists (an UPDATE that changes nothing reports 0 rows, so existence is checked separately).
	 *
	 * @param int $id Restore id.
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
