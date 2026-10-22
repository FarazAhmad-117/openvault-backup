<?php
/**
 * Shared base of the restore steps: plan loading and the one-time fallback to the next source.
 *
 * Phase: MVP
 *
 * Purpose: Every restore step (fetch, verify) rebuilds the RestorePlan from the database and the disk in each slice
 * (a plan can be large, so it is never stored in the job state) and reads the small job meta (RestoreMeta). When a source
 * turns out to be unusable (a download fails for good, a part is damaged) switch_source() records it as tried and sends the
 * job back to "fetch" with the next complete source, once per source. Without another source the job fails with a clear message.
 *
 * Jump rule: a step moves a job to another step by returning next_step() with a state that already has the target step
 * (verify -> fetch). RestoreRunner honours that (the backup runner does not need it). "fetch" itself restarts with
 * continue_step() and a fresh cursor.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore\Steps;

use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Engine\Steps\StepInterface;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Restore\RestoreWorkDir;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Base of the restore steps.
 */
abstract class AbstractRestoreStep implements StepInterface {

	/**
	 * Plan loader.
	 *
	 * @var PlanLoader
	 */
	protected $loader;

	/**
	 * Destination provider factory: function( int $destination_id ): DestinationInterface.
	 *
	 * @var callable|null
	 */
	protected $destinations;

	/**
	 * Constructor.
	 *
	 * @param PlanLoader|null $loader       Plan loader (defaults to the real one).
	 * @param callable|null   $destinations Provider factory (defaults to DestinationFactory::make()).
	 */
	public function __construct( ?PlanLoader $loader = null, ?callable $destinations = null ) {
		$this->destinations = $destinations;
		$this->loader       = null === $loader ? new PlanLoader( null, $destinations ) : $loader;
	}

	/**
	 * Build the plan of the restore this context belongs to.
	 *
	 * @param JobContext $context Context.
	 * @return array{plan:RestorePlan|null,manifest:\OpenVault\Backup\Support\Manifest|null,error:string,newer:bool}
	 */
	protected function load_plan( JobContext $context ) {
		$meta = $context->state()->get_meta();
		return $this->loader->load( $context->backup(), RestoreMeta::plan_mode( $meta ), RestoreMeta::tried( $meta ), $context->work_dir() );
	}

	/**
	 * The chosen source failed: try the next complete source once, or fail the job.
	 *
	 * @param JobContext  $context    Context.
	 * @param RestorePlan $plan       Plan (with the failed source chosen).
	 * @param string      $why        Short reason, shown to the user and logged (no secrets, no server paths).
	 * @param string      $final_text Message when no other source is left (what the user can do).
	 * @return StepResult
	 */
	protected function switch_source( JobContext $context, RestorePlan $plan, $why, $final_text ) {
		$state  = $context->state();
		$failed = $plan->chosen_source();
		$meta   = RestoreMeta::with_tried( $state->get_meta(), null === $failed ? 0 : $failed );
		$next   = $plan->fallback_sources();

		if ( array() === $next ) {
			return StepResult::fail( trim( $why . ' ' . $final_text ), $state->with_meta( $meta ) );
		}

		$context->log(
			Logger::WARNING,
			__( 'A copy of the backup could not be used, trying the next copy.', 'openvault-backup' ),
			array(
				'failed_source' => $failed,
				'next_source'   => $next[0],
				'reason'        => $why,
			)
		);

		// Files fetched from the failed source must not be mixed with the next source's files.
		$restore_id = RestoreMeta::restore_id( $meta );
		if ( $restore_id > 0 ) {
			RestoreWorkDir::remove( $restore_id );
			RestoreWorkDir::ensure( $restore_id );
		}

		if ( JobState::STEP_FETCH === $state->get_step() ) {
			// Already in "fetch": start it over for the next source. "n" always grows so the runner sees the step move.
			$cursor  = $state->get_cursor();
			$restart = array(
				'i'     => 0,
				'off'   => 0,
				'tries' => 0,
				'n'     => ( isset( $cursor['n'] ) ? (int) $cursor['n'] : 0 ) + 1,
			);
			return StepResult::continue_step( $state->with_meta( $meta )->with_cursor( $restart ) );
		}
		return StepResult::next_step( $state->with_meta( $meta )->with_step( JobState::STEP_FETCH ) );
	}
}
