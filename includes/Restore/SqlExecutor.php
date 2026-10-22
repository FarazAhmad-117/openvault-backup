<?php
/**
 * Runs one SQL statement for the database importer.
 *
 * Phase: MVP
 *
 * Purpose: The importer never talks to $wpdb directly, so its resume and whitelist logic can be tested without a database.
 * WpdbExecutor is the real implementation.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Statement executor.
 */
interface SqlExecutor {

	/**
	 * Run a statement that DumpValidator accepted (or one the importer built from validated names).
	 *
	 * @param string $sql Statement.
	 * @return bool False when the database reported an error.
	 */
	public function execute( $sql );

	/**
	 * MySQL error number of the last failed statement (0 when unknown). Never the message: it can contain row data.
	 *
	 * @return int
	 */
	public function errno();
}
