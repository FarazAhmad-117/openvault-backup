<?php
/**
 * Result of a destination connection test.
 *
 * Phase: MVP
 *
 * Purpose: Immutable ok/message/details triple returned by DestinationInterface::test_connection().
 * The message is scrubbed of secrets; details hold small non-secret facts (free space, region ...).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Destinations;

use OpenVault\Backup\Database\DestinationsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Connection test result.
 */
final class TestResult {

	/**
	 * Passed.
	 *
	 * @var bool
	 */
	private $ok;

	/**
	 * Safe message.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Non-secret facts.
	 *
	 * @var array<string,mixed>
	 */
	private $details;

	/**
	 * Use the factories.
	 *
	 * @param bool                $ok      Passed.
	 * @param string              $message Message.
	 * @param array<string,mixed> $details Details.
	 */
	private function __construct( $ok, $message, array $details ) {
		$this->ok      = $ok;
		$this->message = DestinationsRepository::scrub_error( $message );
		$this->details = $details;
	}

	/**
	 * A passed test.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $details Details.
	 * @return self
	 */
	public static function success( $message, array $details = array() ) {
		return new self( true, $message, $details );
	}

	/**
	 * A failed test.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $details Details.
	 * @return self
	 */
	public static function failure( $message, array $details = array() ) {
		return new self( false, $message, $details );
	}

	/**
	 * Whether the test passed.
	 *
	 * @return bool
	 */
	public function ok() {
		return $this->ok;
	}

	/**
	 * Safe message.
	 *
	 * @return string
	 */
	public function message() {
		return $this->message;
	}

	/**
	 * Details.
	 *
	 * @return array<string,mixed>
	 */
	public function details() {
		return $this->details;
	}
}
