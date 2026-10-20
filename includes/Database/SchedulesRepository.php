<?php
/**
 * CRUD for {prefix}ovb_schedules.
 *
 * Phase: MVP
 *
 * Purpose: CRUD for {prefix}ovb_schedules.
 *
 * Responsibilities:
 *  - Contents, format, destinations, retention, recurrence per schedule
 *
 * All validation lives in the static normalise() so it can be unit tested without a database.
 * Retention is stored as {"keep_last": int, "keep_days": int}; 0 means unlimited.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for schedules.
 */
class SchedulesRepository {

	const RECURRENCES = array( 'hourly', 'daily', 'weekly', 'monthly', 'custom' );
	const LABEL_MAX   = 190;
	const MAX_DESTS   = 50;

	const COLUMNS = 'id, label, recurrence, start_time, contents, exclusions, format, destination_ids, retention, enabled, next_run_at, created_at, updated_at';

	/**
	 * Full table name (prefix aware).
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'ovb_schedules';
	}

	/**
	 * Validate and normalise schedule fields. Unknown keys are dropped.
	 *
	 * @param array<string,mixed> $input          Fields: label, recurrence, start_time, contents, exclusions, format,
	 *                                            destination_ids, retention, enabled, next_run_at.
	 * @param bool                $apply_defaults Fill defaults for missing keys (create) or validate only the given keys (update).
	 * @return array<string,mixed> Arrays stay arrays; the repository encodes them as JSON.
	 * @throws \InvalidArgumentException When a value is invalid.
	 */
	public static function normalise( array $input, $apply_defaults = true ) {
		$out = array();

		if ( array_key_exists( 'label', $input ) ) {
			$out['label'] = Util::clean_text( $input['label'], self::LABEL_MAX );
		} elseif ( $apply_defaults ) {
			$out['label'] = '';
		}

		if ( array_key_exists( 'recurrence', $input ) ) {
			$recurrence = Util::one_of( $input['recurrence'], self::RECURRENCES );
			if ( null === $recurrence ) {
				throw new \InvalidArgumentException( 'Invalid recurrence.' );
			}
			$out['recurrence'] = $recurrence;
		} elseif ( $apply_defaults ) {
			$out['recurrence'] = 'daily';
		}

		if ( array_key_exists( 'format', $input ) ) {
			$format = Util::one_of( $input['format'], BackupsRepository::FORMATS );
			if ( null === $format ) {
				throw new \InvalidArgumentException( 'Invalid format.' );
			}
			$out['format'] = $format;
		} elseif ( $apply_defaults ) {
			$out['format'] = 'zip';
		}

		foreach ( array( 'start_time', 'next_run_at' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$out[ $field ] = self::nullable_datetime( $input[ $field ], $field );
			} elseif ( $apply_defaults ) {
				$out[ $field ] = null;
			}
		}

		foreach ( array( 'contents', 'exclusions' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				if ( ! is_array( $input[ $field ] ) ) {
					throw new \InvalidArgumentException( 'Invalid ' . $field . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed field name.
				}
				$out[ $field ] = $input[ $field ];
			} elseif ( $apply_defaults ) {
				$out[ $field ] = array();
			}
		}

		if ( array_key_exists( 'destination_ids', $input ) ) {
			$out['destination_ids'] = self::destination_ids( $input['destination_ids'] );
		} elseif ( $apply_defaults ) {
			$out['destination_ids'] = array();
		}

		if ( array_key_exists( 'retention', $input ) ) {
			$out['retention'] = self::retention( $input['retention'] );
		} elseif ( $apply_defaults ) {
			$out['retention'] = self::retention( array() );
		}

		if ( array_key_exists( 'enabled', $input ) ) {
			$out['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
		} elseif ( $apply_defaults ) {
			$out['enabled'] = 1;
		}

		return $out;
	}

	/**
	 * Retention policy with only the known keys; 0 means unlimited.
	 *
	 * @param mixed $retention Input.
	 * @return array{keep_last:int,keep_days:int}
	 * @throws \InvalidArgumentException When not an array or a value is not an integer >= 0.
	 */
	public static function retention( $retention ) {
		if ( ! is_array( $retention ) ) {
			throw new \InvalidArgumentException( 'Invalid retention.' );
		}
		return array(
			'keep_last' => isset( $retention['keep_last'] ) ? Util::non_negative_int( $retention['keep_last'], 'keep_last' ) : 0,
			'keep_days' => isset( $retention['keep_days'] ) ? Util::non_negative_int( $retention['keep_days'], 'keep_days' ) : 0,
		);
	}

	/**
	 * A list of positive destination ids (duplicates removed, order kept, at most 50).
	 *
	 * @param mixed $ids Input.
	 * @return int[]
	 * @throws \InvalidArgumentException When not a list of positive integers.
	 */
	public static function destination_ids( $ids ) {
		if ( ! is_array( $ids ) || count( $ids ) > self::MAX_DESTS ) {
			throw new \InvalidArgumentException( 'Invalid destination_ids.' );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$int = Util::non_negative_int( $id, 'destination_ids' );
			if ( $int < 1 ) {
				throw new \InvalidArgumentException( 'Invalid destination_ids.' );
			}
			$out[ $int ] = $int;
		}
		return array_values( $out );
	}

	/**
	 * Create a schedule.
	 *
	 * @param array<string,mixed> $fields See normalise().
	 * @return int New id, 0 on database failure.
	 * @throws \InvalidArgumentException When a value is invalid.
	 */
	public function create( array $fields ) {
		global $wpdb;

		$data               = $this->encode( self::normalise( $fields, true ) );
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
	 * Update some fields of a schedule.
	 *
	 * @param int                 $id     Schedule id.
	 * @param array<string,mixed> $fields Fields to change (see normalise()).
	 * @return bool False when the row does not exist or the query failed.
	 * @throws \InvalidArgumentException When a value is invalid.
	 */
	public function update( $id, array $fields ) {
		return $this->update_columns( $id, $this->encode( self::normalise( $fields, false ) ) );
	}

	/**
	 * Find by id.
	 *
	 * @param int $id Schedule id.
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
	 * All schedules, oldest first (there are only a handful).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all() {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( 'SELECT ' . self::COLUMNS . " FROM {$table} ORDER BY id ASC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- No user input in this query.

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Delete a schedule.
	 *
	 * @param int $id Schedule id.
	 * @return bool True when a row was deleted.
	 */
	public function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			array( 'id' => (int) $id ),
			array( '%d' )
		);

		return 1 === $deleted;
	}

	/**
	 * Enable or disable a schedule.
	 *
	 * @param int  $id      Schedule id.
	 * @param bool $enabled Enabled.
	 * @return bool
	 */
	public function set_enabled( $id, $enabled ) {
		return $this->update_columns( $id, array( 'enabled' => $enabled ? 1 : 0 ) );
	}

	/**
	 * Enabled schedules whose next run is at or before $now, earliest first.
	 *
	 * @param int $now   Unix time.
	 * @param int $limit Maximum rows (1..200).
	 * @return array<int,array<string,mixed>>
	 */
	public function due( $now, $limit = 100 ) {
		global $wpdb;

		$table = $this->table();
		$limit = max( 1, min( 200, (int) $limit ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= %s ORDER BY next_run_at ASC, id ASC LIMIT %d", Util::now( (int) $now ), $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Set (or clear with null) the next run time.
	 *
	 * @param int         $id       Schedule id.
	 * @param string|null $datetime UTC "Y-m-d H:i:s" or null.
	 * @return bool
	 * @throws \InvalidArgumentException For a malformed datetime.
	 */
	public function set_next_run( $id, $datetime ) {
		return $this->update_columns( $id, array( 'next_run_at' => self::nullable_datetime( $datetime, 'next_run_at' ) ) );
	}

	/**
	 * Convert a raw row into a typed array.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ) {
		$ids       = Util::decode_json( isset( $row['destination_ids'] ) ? $row['destination_ids'] : null );
		$retention = Util::decode_json( isset( $row['retention'] ) ? $row['retention'] : null );

		$clean_ids = array();
		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$clean_ids[ (int) $id ] = (int) $id;
			}
		}

		return array(
			'id'              => (int) $row['id'],
			'label'           => (string) $row['label'],
			'recurrence'      => (string) $row['recurrence'],
			'start_time'      => isset( $row['start_time'] ) ? (string) $row['start_time'] : null,
			'contents'        => Util::decode_json( isset( $row['contents'] ) ? $row['contents'] : null ),
			'exclusions'      => Util::decode_json( isset( $row['exclusions'] ) ? $row['exclusions'] : null ),
			'format'          => (string) $row['format'],
			'destination_ids' => array_values( $clean_ids ),
			'retention'       => array(
				'keep_last' => isset( $retention['keep_last'] ) && is_numeric( $retention['keep_last'] ) ? max( 0, (int) $retention['keep_last'] ) : 0,
				'keep_days' => isset( $retention['keep_days'] ) && is_numeric( $retention['keep_days'] ) ? max( 0, (int) $retention['keep_days'] ) : 0,
			),
			'enabled'         => ! empty( $row['enabled'] ),
			'next_run_at'     => isset( $row['next_run_at'] ) ? (string) $row['next_run_at'] : null,
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		);
	}

	/**
	 * A datetime or null.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field name for the error.
	 * @return string|null
	 * @throws \InvalidArgumentException When not null and not "Y-m-d H:i:s".
	 */
	private static function nullable_datetime( $value, $field ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! Util::is_datetime( $value ) ) {
			throw new \InvalidArgumentException( 'Invalid ' . $field . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed field name.
		}
		return $value;
	}

	/**
	 * JSON-encode the array columns of a normalised field set.
	 *
	 * @param array<string,mixed> $data Normalised fields.
	 * @return array<string,mixed>
	 */
	private function encode( array $data ) {
		foreach ( array( 'contents', 'exclusions', 'destination_ids', 'retention' ) as $column ) {
			if ( array_key_exists( $column, $data ) ) {
				$data[ $column ] = wp_json_encode( $data[ $column ] );
			}
		}
		return $data;
	}

	/**
	 * Placeholder formats for a column => value map.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string[]
	 */
	private static function formats( array $data ) {
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = 'enabled' === $column ? '%d' : '%s';
		}
		return $formats;
	}

	/**
	 * Update validated columns of one row (also bumps updated_at).
	 *
	 * @param int                 $id   Schedule id.
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
		if ( false === $result ) {
			return false;
		}

		$table = $this->table();
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return null !== $found;
	}
}
