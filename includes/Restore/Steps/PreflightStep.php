<?php
/**
 * Checks before a restore runs (the first step of a restore job).
 *
 * Phase: MVP
 *
 * Purpose: Step "preflight" of a restore job. RestoreManager::start() runs the same checks before it creates the job; this step
 * runs them again when the job starts (a backup may have started, a destination may have gone away in between). It also makes
 * sure a manifest that came from a destination is saved into the restore work folder.
 *
 * Any error fails the job with the message of the rule; unacknowledged confirmations (the site address differs) fail it too, so a
 * job never goes on without the user's acknowledgement stored in its meta.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore\Steps;

use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePreflight;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Restore preflight step.
 */
class PreflightStep extends AbstractRestoreStep {

	/**
	 * Rules and facts.
	 *
	 * @var RestorePreflight|null
	 */
	private $preflight;

	/**
	 * Constructor.
	 *
	 * @param PlanLoader|null       $loader       Plan loader.
	 * @param callable|null         $destinations Provider factory.
	 * @param RestorePreflight|null $preflight    Preflight (defaults to the real one).
	 */
	public function __construct( ?PlanLoader $loader = null, ?callable $destinations = null, ?RestorePreflight $preflight = null ) {
		parent::__construct( $loader, $destinations );
		$this->preflight = $preflight;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_PREFLIGHT;
	}

	/**
	 * Run the checks.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$state = $context->state();
		$meta  = $state->get_meta();
		if ( null === $this->preflight ) {
			$this->preflight = new RestorePreflight( null, null, $this->destinations );
		}

		$loaded = $this->load_plan( $context );
		$result = $this->preflight->run( $loaded, RestoreMeta::ack( $meta ), RestoreMeta::restore_id( $meta ), array( 'server_config' => RestoreMeta::server_config( $meta ) ) );

		$blocking = array_merge( $result['errors'], $result['requires_ack'] );
		if ( array() !== $blocking ) {
			return StepResult::fail( $blocking[0]['message'], $state );
		}

		foreach ( $result['warnings'] as $warning ) {
			$context->log( Logger::WARNING, $warning['message'], array( 'code' => $warning['code'] ) );
		}
		$context->log( Logger::INFO, __( 'The restore checks passed.', 'openvault-backup' ), array( 'warnings' => count( $result['warnings'] ) ) );
		return StepResult::next_step( $state );
	}
}
