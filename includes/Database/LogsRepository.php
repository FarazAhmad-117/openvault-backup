<?php
/**
 * CRUD for {prefix}ovb_logs.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Append log lines per job, prune old logs
 *
 *  - Read, filter and paginate lines for the Logs page; clear all lines
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Database;

use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for the log table.
 */
class LogsRepository {

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'ovb_logs';
	}

	/**
	 * Append a log line.
	 *
	 * @param string              $level     Level slug.
	 * @param string              $message   Message (never put secrets in it).
	 * @param array<string,mixed> $context   Already-redacted context.
	 * @param int|null            $backup_id Related backup, if any.
	 * @return int Inserted row id, 0 on failure.
	 */
	public function insert( $level, $message, array $context = array(), $backup_id = null ) {
		global $wpdb;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'backup_id'  => null === $backup_id ? null : (int) $backup_id,
				'level'      => $level,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Delete log lines older than the given number of days.
	 *
	 * @param int $days Keep this many days.
	 * @return int Rows deleted.
	 */
	public function prune( $days ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		$table  = $this->table();

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $deleted;
	}

	/**
	 * Validate and normalise list filters. Unknown filter values are dropped (never passed to SQL).
	 *
	 * @param array<string,mixed> $args Arguments: level, backup_id, search, date_from, date_to, order, per_page, page.
	 * @return array<string,mixed>
	 */
	public static function normalise_query( array $args ) {
		$query = Util::clamp_pagination( $args );

		$query['level']     = isset( $args['level'] ) ? Util::one_of( $args['level'], array_keys( Logger::LEVELS ) ) : null;
		$query['backup_id'] = isset( $args['backup_id'] ) && is_numeric( $args['backup_id'] ) && (int) $args['backup_id'] > 0 ? (int) $args['backup_id'] : null;
		$query['search']    = isset( $args['search'] ) ? Util::clean_text( $args['search'], 100 ) : '';
		$query['date_from'] = isset( $args['date_from'] ) ? Util::filter_datetime( $args['date_from'], false ) : null;
		$query['date_to']   = isset( $args['date_to'] ) ? Util::filter_datetime( $args['date_to'], true ) : null;
		$query['order']     = isset( $args['order'] ) ? Util::direction( $args['order'] ) : 'DESC';

		return $query;
	}

	/**
	 * One page of log lines, newest first by default.
	 *
	 * @param array<string,mixed> $args See normalise_query().
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function paginate( array $args = array() ) {
		global $wpdb;

		$query  = self::normalise_query( $args );
		$where  = array( '1=1' );
		$params = array();

		if ( null !== $query['level'] ) {
			$where[]  = 'level = %s';
			$params[] = $query['level'];
		}
		if ( null !== $query['backup_id'] ) {
			$where[]  = 'backup_id = %d';
			$params[] = $query['backup_id'];
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
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $query['search'] ) . '%';
		}

		$table     = $this->table();
		$where_sql = implode( ' AND ', $where );
		$order     = $query['order'];
		// Whitelisted: ASC or DESC.

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Util::prepare_list( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
		);

		$list_params   = $params;
		$list_params[] = $query['per_page'];
		$list_params[] = $query['offset'];
		$rows          = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Util::prepare_list( "SELECT id, backup_id, level, message, context, created_at FROM {$table} WHERE {$where_sql} ORDER BY created_at {$order}, id {$order} LIMIT %d OFFSET %d", $list_params ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Number of log lines.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No user input in this query.
	}

	/**
	 * Delete every log line (DELETE, not TRUNCATE: it needs no extra database privilege).
	 *
	 * @return int Rows deleted.
	 */
	public function delete_all() {
		global $wpdb;

		$table   = $this->table();
		$deleted = $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No user input in this query.

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Convert a raw row into a typed array with decoded context.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ) {
		return array(
			'id'         => (int) $row['id'],
			'backup_id'  => isset( $row['backup_id'] ) ? (int) $row['backup_id'] : null,
			'level'      => (string) $row['level'],
			'message'    => (string) $row['message'],
			'context'    => Util::decode_json( isset( $row['context'] ) ? $row['context'] : null ),
			'created_at' => (string) $row['created_at'],
		);
	}
}
