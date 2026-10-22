<?php
/**
 * Error while validating or importing a dump.
 *
 * Phase: MVP
 *
 * Purpose: Carries what the user and the log may see: a fixed message, the line number, the table name (a validated identifier)
 * and the MySQL error number. Never a statement, a value or a database error text (those can hold row data).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Import error.
 */
final class ImportException extends \RuntimeException {

	/**
	 * Line number (1-based), 0 when not tied to a line.
	 *
	 * @var int
	 */
	private $line_number;

	/**
	 * Table name or ''.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * MySQL error number or 0.
	 *
	 * @var int
	 */
	private $errno;

	/**
	 * Whether a later attempt can succeed (the connection was lost) as opposed to a refusal or a data error.
	 *
	 * @var bool
	 */
	private $transient;

	/**
	 * Constructor.
	 *
	 * @param string $message   Fixed message.
	 * @param int    $line      Line number.
	 * @param string $table     Table name.
	 * @param int    $errno     MySQL error number.
	 * @param bool   $transient Retryable.
	 */
	public function __construct( $message, $line = 0, $table = '', $errno = 0, $transient = false ) {
		parent::__construct( $message );
		$this->line_number = (int) $line;
		$this->table       = (string) $table;
		$this->errno       = (int) $errno;
		$this->transient   = (bool) $transient;
	}

	/**
	 * Line number.
	 *
	 * @return int
	 */
	public function line_number() {
		return $this->line_number;
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public function table() {
		return $this->table;
	}

	/**
	 * MySQL error number.
	 *
	 * @return int
	 */
	public function errno() {
		return $this->errno;
	}

	/**
	 * Whether another attempt may work.
	 *
	 * @return bool
	 */
	public function is_transient() {
		return $this->transient;
	}
}
