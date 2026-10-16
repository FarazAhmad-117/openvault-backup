<?php
/**
 * CRUD for {prefix}ovb_logs.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Append log lines per job, prune old logs
 *
 * Reading/filtering for the Logs page is added with that page.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Database;

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
}
