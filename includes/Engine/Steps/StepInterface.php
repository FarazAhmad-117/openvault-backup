<?php
/**
 * Contract for engine steps.
 *
 * Phase: MVP
 *
 * Purpose: Contract for engine steps.
 *
 * Responsibilities:
 *  - id(): the JobState step id this implementation handles (preflight, db_export, ...)
 *  - run( JobContext ): StepResult (next | continue | fail | wait)
 *  - Work only while $context->budget() is not exhausted, then return continue_step() with the cursor
 *    saved in the returned JobState. Never keep the whole job in memory.
 *  - A thrown exception is treated as a transient failure: the runner counts an attempt and retries
 *    with backoff (3 attempts), then fails the backup. Return StepResult::fail() for permanent failures.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Steps;

use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * An engine step.
 */
interface StepInterface {

	/**
	 * The JobState step id this step implements.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Do one slice of work.
	 *
	 * @param JobContext $context Backup, state, budget, logger, work dir, settings.
	 * @return StepResult
	 */
	public function run( JobContext $context );
}
