<?php
/**
 * Error raised by a storage destination.
 *
 * Phase: MVP
 *
 * Purpose: Tells the upload step whether a failure is worth retrying.
 *
 * Responsibilities:
 *  - retryable() true: a transient problem (network, rate limit, server error): back off and try again
 *  - retryable() false: a permanent problem (bad settings, rejected credentials, full quota): fail that file
 *  - The message is scrubbed of anything that looks like a token or key, so it is safe to store and show.
 *    Implementations must still not put secrets or absolute server paths in it.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Destinations;

use OpenVault\Backup\Database\DestinationsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Destination error.
 */
class DestinationException extends \RuntimeException {

	const REASON_TRANSIENT       = 'transient';
	const REASON_PERMANENT       = 'permanent';
	const REASON_NEEDS_RECONNECT = 'needs_reconnect';
	const REASON_UNKNOWN_TYPE    = 'unknown_type';

	/**
	 * Whether a retry may help.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Machine readable reason.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Constructor.
	 *
	 * @param string          $message   Safe message (it is scrubbed again).
	 * @param bool            $retryable Whether a retry may help.
	 * @param string          $reason    One of the REASON_ constants.
	 * @param \Throwable|null $previous  Cause (never shown).
	 */
	public function __construct( $message, $retryable = false, $reason = self::REASON_PERMANENT, ?\Throwable $previous = null ) {
		parent::__construct( DestinationsRepository::scrub_error( $message ), 0, $previous );
		$this->retryable = (bool) $retryable;
		$this->reason    = (string) $reason;
	}

	/**
	 * A problem that may go away by itself.
	 *
	 * @param string $message Safe message.
	 * @return self
	 */
	public static function transient( $message ) {
		return new self( $message, true, self::REASON_TRANSIENT );
	}

	/**
	 * A problem a retry cannot fix.
	 *
	 * @param string $message Safe message.
	 * @return self
	 */
	public static function permanent( $message ) {
		return new self( $message, false, self::REASON_PERMANENT );
	}

	/**
	 * The stored credentials cannot be read: the user must reconnect.
	 *
	 * @return self
	 */
	public static function needs_reconnect() {
		return new self( DestinationsRepository::RECONNECT_MESSAGE, false, self::REASON_NEEDS_RECONNECT );
	}

	/**
	 * Whether a retry may help.
	 *
	 * @return bool
	 */
	public function retryable() {
		return $this->retryable;
	}

	/**
	 * Machine readable reason.
	 *
	 * @return string
	 */
	public function reason() {
		return $this->reason;
	}
}
