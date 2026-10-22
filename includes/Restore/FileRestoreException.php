<?php
/**
 * A problem that stops a file restore for good (the destination cannot be written, the disk is full, the undo journal cannot be written).
 *
 * Phase: MVP
 *
 * Purpose: Single entries that cannot be restored are counted and skipped. This exception is for the systemic errors that make going on
 * pointless or unsafe. The message is for the user and holds no server path.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Systemic file restore error.
 */
class FileRestoreException extends \RuntimeException {

	/**
	 * Short machine reason: disk_full, journal, unwritable.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason  Machine reason.
	 * @param string $message Message for the user.
	 */
	public function __construct( $reason, $message ) {
		parent::__construct( $message );
		$this->reason = (string) $reason;
	}

	/**
	 * Machine reason.
	 *
	 * @return string
	 */
	public function reason() {
		return $this->reason;
	}
}
