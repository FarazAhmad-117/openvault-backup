<?php
/**
 * Ends a restore: maintenance off, caches reset, summary, clean-up.
 *
 * Phase: MVP
 *
 * Purpose: Step "finish" of a restore job.
 *
 * Responsibilities:
 *  - Maintenance mode off (the flag file is removed).
 *  - The kept plugin settings are verified one more time when the database was restored (SelfPreserver::verify()), the object cache
 *    is flushed and the "rewrite_rules" option is deleted, so WordPress rebuilds its permalinks on the next request.
 *  - The result summary goes into the job meta ("result": mode, tables, statements, rows, skipped tables, the file counts (restored, created,
 *    overwritten, bytes, skipped by reason), whether an undo journal exists, seconds, snapshot id, whether the site address was kept, login
 *    of the user who started the restore; for an undo job also "undo_of" and the counts of what was put back) for the result page, and a
 *    line into the log. The undo journal and its saved files are NOT removed here: they stay until RestoreManager::dismiss_undo().
 *  - An undo job marks the restore it reversed ("undone_by" in that job's meta), so it cannot be undone twice.
 *  - The work folder (dump, saved settings, downloaded parts) is removed. The job locks belong to the runner and the snapshot job and
 *    are released by them; nothing else is left behind.
 *  The runner then marks the restore "complete".
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore\Steps;

use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Restore\MaintenanceMode;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestoreWorkDir;
use OpenVault\Backup\Restore\SelfPreserver;
use OpenVault\Backup\Restore\UndoStore;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Finish step.
 */
class FinishStep extends AbstractRestoreStep {

	/**
	 * Maintenance flag.
	 *
	 * @var MaintenanceMode
	 */
	private $maintenance;

	/**
	 * Self-preservation.
	 *
	 * @var SelfPreserver
	 */
	private $preserver;

	/**
	 * Restores.
	 *
	 * @var RestoresRepository
	 */
	private $restores;

	/**
	 * Constructor.
	 *
	 * @param PlanLoader|null         $loader       Plan loader.
	 * @param callable|null           $destinations Provider factory.
	 * @param MaintenanceMode|null    $maintenance  Maintenance flag.
	 * @param SelfPreserver|null      $preserver    Self-preservation.
	 * @param RestoresRepository|null $restores     Restores.
	 */
	public function __construct( ?PlanLoader $loader = null, ?callable $destinations = null, ?MaintenanceMode $maintenance = null, ?SelfPreserver $preserver = null, ?RestoresRepository $restores = null ) {
		parent::__construct( $loader, $destinations );
		$this->maintenance = null === $maintenance ? new MaintenanceMode() : $maintenance;
		$this->preserver   = null === $preserver ? new SelfPreserver() : $preserver;
		$this->restores    = null === $restores ? new RestoresRepository() : $restores;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_FINISH;
	}

	/**
	 * Finish.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$state      = $context->state();
		$meta       = $state->get_meta();
		$restore_id = RestoreMeta::restore_id( $meta );
		$dir        = $context->work_dir();

		$user = '';
		$kept = false;
		if ( $this->preserver->has_capture( $dir ) ) {
			try {
				$saved = $this->preserver->load( $dir );
				$user  = $saved['user'];
				$kept  = is_array( $saved['urls'] );
				$this->preserver->verify( $dir, false );
			} catch ( \RuntimeException $e ) {
				// The maintenance flag stays on (it expires on its own): the plugin's settings did not survive.
				return StepResult::fail( $e->getMessage(), $state );
			}
		}

		$this->maintenance->disable();
		wp_cache_flush();
		delete_option( 'rewrite_rules' );

		$dump           = isset( $meta['dbrestore'] ) && is_array( $meta['dbrestore'] ) ? $meta['dbrestore'] : array();
		$files          = isset( $meta['files'] ) && is_array( $meta['files'] ) ? $meta['files'] : array();
		$undo_files     = isset( $meta['undo_files'] ) && is_array( $meta['undo_files'] ) ? $meta['undo_files'] : array();
		$undo_of        = RestoreMeta::undo_of( $meta );
		$meta['result'] = array(
			'mode'        => RestoreMeta::mode( $meta ),
			'files'       => $files,
			'journal'     => $this->has_journal( $meta, $restore_id ),
			'undo_of'     => $undo_of,
			'undo_files'  => $undo_files,
			'tables'      => isset( $dump['tables'] ) ? (int) $dump['tables'] : 0,
			'statements'  => isset( $dump['statements'] ) ? (int) $dump['statements'] : 0,
			'rows'        => isset( $dump['rows'] ) ? (int) $dump['rows'] : 0,
			'skipped'     => isset( $dump['skipped'] ) ? (int) $dump['skipped'] : 0,
			'seconds'     => max( 0, time() - $state->get_started_at() ),
			'snapshot_id' => RestoreMeta::snapshot_id( $meta ),
			'kept_urls'   => $kept,
			'user'        => $user,
		);
		$context->log( Logger::INFO, __( 'The restore is complete. Maintenance mode is off.', 'openvault-backup' ), $meta['result'] );

		if ( $undo_of > 0 ) {
			$this->mark_undone( $undo_of, $restore_id );
		}

		RestoreWorkDir::remove( $restore_id );
		return StepResult::next_step( $state->with_meta( $meta )->with_cursor( array() ) );
	}

	/**
	 * Whether this restore left an undo journal behind (a restore that changed files; an undo job does not write one).
	 *
	 * @param array<mixed> $meta       Meta.
	 * @param int          $restore_id Restore id.
	 * @return bool
	 */
	private function has_journal( array $meta, $restore_id ) {
		if ( ! RestoreMeta::does_files( $meta ) || RestoreMeta::MODE_UNDO === RestoreMeta::mode( $meta ) ) {
			return false;
		}
		try {
			return UndoStore::for_restore( $restore_id )->exists();
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Record on the reversed restore that an undo job completed.
	 *
	 * @param int $original Restore that was reversed.
	 * @param int $undo_id  The undo job.
	 * @return void
	 */
	private function mark_undone( $original, $undo_id ) {
		$state = $this->restores->load_state( $original );
		if ( null === $state ) {
			return;
		}
		$meta              = $state->get_meta();
		$meta['undone_by'] = (int) $undo_id;
		$this->restores->save_state( $original, $state->with_meta( $meta ) );
	}
}
