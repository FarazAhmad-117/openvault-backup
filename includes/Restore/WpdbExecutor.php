<?php
/**
 * SqlExecutor on top of $wpdb, plus the session settings of an import.
 *
 * Phase: MVP
 *
 * Purpose: Runs the importer's statements without printing or logging database errors (they can contain row data) and gives the
 * error number only. Also prepares the connection (utf8mb4, UTC, no query history) and puts it back.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * $wpdb executor.
 */
class WpdbExecutor implements SqlExecutor {

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * MySQL error number of the last failure.
	 *
	 * @var int
	 */
	private $errno = 0;

	/**
	 * Session values to put back.
	 *
	 * @var array<string,mixed>|null
	 */
	private $saved = null;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Run a statement quietly.
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	public function execute( $sql ) {
		$db          = $this->db;
		$hidden      = $db->hide_errors();
		$suppressed  = $db->suppress_errors( true );
		$this->errno = 0;

		try {
			$result = $db->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- A statement that DumpValidator accepted (or built from validated names); the dump is data, there is nothing to bind.
			if ( false === $result ) {
				$this->errno = $this->read_errno();
			}
		} finally {
			$db->suppress_errors( $suppressed );
			if ( $hidden ) {
				$db->show_errors();
			}
			if ( ! empty( $db->queries ) ) {
				// SAVEQUERIES would keep every statement of the dump in memory.
				$db->queries = array();
			}
			$db->last_query = '';
			$db->last_error = '';
		}

		return false !== $result;
	}

	/**
	 * MySQL error number of the last failed statement.
	 *
	 * @return int
	 */
	public function errno() {
		return $this->errno;
	}

	/**
	 * Prepare the connection for an import: read what is set now, then utf8mb4 and UTC.
	 *
	 * @return void
	 */
	public function begin_session() {
		$db          = $this->db;
		$row         = $db->get_row( 'SELECT @@session.sql_mode AS m, @@session.time_zone AS z', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Session settings, fixed statement.
		$this->saved = array(
			'sql_mode'  => is_array( $row ) && isset( $row['m'] ) ? (string) $row['m'] : null,
			'time_zone' => is_array( $row ) && isset( $row['z'] ) ? (string) $row['z'] : null,
		);
		if ( $db->has_cap( 'utf8mb4' ) ) {
			$this->execute( "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'" );
		}
		$this->execute( "SET time_zone = '+00:00'" );
	}

	/**
	 * Put the connection settings back (also after a failure).
	 *
	 * @return void
	 */
	public function end_session() {
		$db = $this->db;
		$this->execute( 'SET FOREIGN_KEY_CHECKS=1' );
		$this->execute( 'SET UNIQUE_CHECKS=1' );
		if ( null === $this->saved ) {
			return;
		}
		if ( null !== $this->saved['sql_mode'] ) {
			$this->execute( $db->prepare( 'SET sql_mode = %s', $this->saved['sql_mode'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built with prepare().
		}
		if ( null !== $this->saved['time_zone'] ) {
			$this->execute( $db->prepare( 'SET time_zone = %s', $this->saved['time_zone'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built with prepare().
		}
		if ( '' !== (string) $db->charset ) {
			$sql = $db->prepare( 'SET NAMES %s', $db->charset );
			if ( '' !== (string) $db->collate ) {
				$sql .= $db->prepare( ' COLLATE %s', $db->collate );
			}
			$this->execute( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built with prepare().
		}
		$this->saved = null;
	}

	/**
	 * Error number from the server (SHOW ERRORS, whose message column is never read).
	 *
	 * @return int
	 */
	private function read_errno() {
		$db             = $this->db;
		$row            = $db->get_row( 'SHOW ERRORS LIMIT 1', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics of the failed statement.
		$db->last_error = '';
		return is_array( $row ) && isset( $row[1] ) ? (int) $row[1] : 0;
	}
}
