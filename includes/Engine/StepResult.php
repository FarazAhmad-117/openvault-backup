<?php
/**
 * What a step tells the runner after one slice of work.
 *
 * Phase: MVP
 *
 * Purpose: Immutable outcome of StepInterface::run() carrying the (already updated) JobState.
 *
 * Responsibilities:
 *  - continue_step(): more work in this step, state holds the new cursor
 *  - next_step(): this step is finished, move on
 *  - fail(): permanent failure, no retry (an exception thrown from a step is retried instead)
 *  - wait(): retry later after N seconds (backoff, rate limits)
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Step outcome.
 */
final class StepResult {

	const KIND_CONTINUE = 'continue';
	const KIND_NEXT     = 'next';
	const KIND_FAIL     = 'fail';
	const KIND_WAIT     = 'wait';

	const MAX_WAIT_SECONDS = 3600;

	/**
	 * Kind.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * State carried back to the runner.
	 *
	 * @var JobState
	 */
	private $state;

	/**
	 * Failure message.
	 *
	 * @var string
	 */
	private $message = '';

	/**
	 * Seconds to wait.
	 *
	 * @var int
	 */
	private $seconds = 0;

	/**
	 * Use the factories.
	 *
	 * @param string   $kind  Kind.
	 * @param JobState $state State.
	 */
	private function __construct( $kind, JobState $state ) {
		$this->kind  = $kind;
		$this->state = $state;
	}

	/**
	 * More work to do in the same step. The state must hold the updated cursor.
	 *
	 * @param JobState $state State with the new cursor.
	 * @return self
	 */
	public static function continue_step( JobState $state ) {
		return new self( self::KIND_CONTINUE, $state );
	}

	/**
	 * The step is finished.
	 *
	 * @param JobState $state State (its meta may carry results for later steps).
	 * @return self
	 */
	public static function next_step( JobState $state ) {
		return new self( self::KIND_NEXT, $state );
	}

	/**
	 * Permanent failure. The message is stored and shown, so it must be actionable and free of secrets.
	 *
	 * @param string   $message Message.
	 * @param JobState $state   State.
	 * @return self
	 */
	public static function fail( $message, JobState $state ) {
		$result          = new self( self::KIND_FAIL, $state );
		$result->message = (string) $message;
		return $result;
	}

	/**
	 * Try again later.
	 *
	 * @param int      $seconds Delay, clamped to 1..3600 seconds.
	 * @param JobState $state   State.
	 * @return self
	 */
	public static function wait( $seconds, JobState $state ) {
		$result          = new self( self::KIND_WAIT, $state );
		$result->seconds = (int) max( 1, min( self::MAX_WAIT_SECONDS, (int) $seconds ) );
		return $result;
	}

	/**
	 * Kind.
	 *
	 * @return string One of the KIND_ constants.
	 */
	public function kind() {
		return $this->kind;
	}

	/**
	 * State.
	 *
	 * @return JobState
	 */
	public function state() {
		return $this->state;
	}

	/**
	 * Failure message ('' unless failed).
	 *
	 * @return string
	 */
	public function message() {
		return $this->message;
	}

	/**
	 * Delay in seconds (0 unless waiting).
	 *
	 * @return int
	 */
	public function seconds() {
		return $this->seconds;
	}

	/**
	 * Whether more work remains in the current step.
	 *
	 * @return bool
	 */
	public function is_continue() {
		return self::KIND_CONTINUE === $this->kind;
	}

	/**
	 * Whether the step is finished.
	 *
	 * @return bool
	 */
	public function is_next() {
		return self::KIND_NEXT === $this->kind;
	}

	/**
	 * Whether the step failed permanently.
	 *
	 * @return bool
	 */
	public function is_fail() {
		return self::KIND_FAIL === $this->kind;
	}

	/**
	 * Whether the step wants to be retried later.
	 *
	 * @return bool
	 */
	public function is_wait() {
		return self::KIND_WAIT === $this->kind;
	}
}
