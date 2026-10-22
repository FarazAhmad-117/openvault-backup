<?php
/**
 * Restores the files of a backup: safe, resumable, with an undo journal.
 *
 * Phase: MVP
 *
 * Purpose: Step "file_restore" of a restore job (modes "files" and "full"; mode "db" and undo jobs skip it). It runs after the safety snapshot and
 * (for "full") after the database restore. The work is done by Restore\FileRestorer; this step supplies the real environment and the checks around it.
 *
 * Phases (cursor key "ph"):
 *  - s  setup. Nothing is written to the site. The protection of the plugin's own files is tested (ExtractionPolicy::site_self_test(): entries that
 *       map into the plugin's folder, its storage folder, wp-config.php ... must be refused), the undo journal is created, maintenance mode is
 *       switched on (it already is after a database restore). A failure here says "nothing was changed". Cancel is possible in this phase of a
 *       files-only restore.
 *  - a  apply. FileRestorer::restore_slice() runs within the Budget and the cursor is saved every few entries (see FileRestorer for the cursor and the
 *       resume cost of tar.gz parts). Each part is hashed again before its first entry (it may have changed since "verify"). Maintenance mode is
 *       refreshed every slice. From here on cancel is refused.
 *
 * Failure (a systemic error: the journal cannot be written, the disk is full, files cannot be written, a part changed or is damaged): the restore
 * fails with "The restore stopped part way ... use Undo restore (it puts the replaced files back)". Maintenance mode stays on (it expires on its own)
 * and the journal stays, so Undo works. Single files that cannot be restored never fail the restore: they are counted by reason in the result.
 *
 * Result (meta "files"): restored, created, overwritten, bytes, parts, skipped (reason => count) and its total.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated texts; they are escaped where they are displayed.

namespace OpenVault\Backup\Restore\Steps;

use OpenVault\Backup\Archive\ArchiveReader;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Restore\ExtractionPolicy;
use OpenVault\Backup\Restore\FileRestoreException;
use OpenVault\Backup\Restore\FileRestorer;
use OpenVault\Backup\Restore\MaintenanceMode;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Restore\RestoreWorkDir;
use OpenVault\Backup\Restore\SafeWriter;
use OpenVault\Backup\Restore\UndoStore;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * File restore step.
 */
class FileRestoreStep extends AbstractRestoreStep {

	const PHASE_SETUP = 's';
	const PHASE_APPLY = 'a';

	/**
	 * Restores.
	 *
	 * @var RestoresRepository
	 */
	private $restores;

	/**
	 * Maintenance flag.
	 *
	 * @var MaintenanceMode
	 */
	private $maintenance;

	/**
	 * Writer.
	 *
	 * @var SafeWriter
	 */
	private $writer;

	/**
	 * Entries between two saved cursors.
	 *
	 * @var int
	 */
	private $persist_every;

	/**
	 * Constructor.
	 *
	 * @param PlanLoader|null         $loader        Plan loader.
	 * @param callable|null           $destinations  Provider factory.
	 * @param RestoresRepository|null $restores      Restores.
	 * @param MaintenanceMode|null    $maintenance   Maintenance flag.
	 * @param SafeWriter|null         $writer        File writer (a test passes one that simulates a full disk).
	 * @param int                     $persist_every Entries between two saved cursors.
	 */
	public function __construct( ?PlanLoader $loader = null, ?callable $destinations = null, ?RestoresRepository $restores = null, ?MaintenanceMode $maintenance = null, ?SafeWriter $writer = null, $persist_every = FileRestorer::PERSIST_EVERY ) {
		parent::__construct( $loader, $destinations );
		$this->restores      = null === $restores ? new RestoresRepository() : $restores;
		$this->maintenance   = null === $maintenance ? new MaintenanceMode() : $maintenance;
		$this->writer        = null === $writer ? new SafeWriter() : $writer;
		$this->persist_every = max( 1, (int) $persist_every );
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_FILE_RESTORE;
	}

	/**
	 * Run one slice.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$state = $context->state();
		$meta  = $state->get_meta();
		if ( ! RestoreMeta::does_files( $meta ) || RestoreMeta::MODE_UNDO === RestoreMeta::mode( $meta ) ) {
			return StepResult::next_step( $state->with_cursor( array() ) );
		}

		$cursor = $state->get_cursor();
		$phase  = isset( $cursor['ph'] ) && is_string( $cursor['ph'] ) ? $cursor['ph'] : self::PHASE_SETUP;

		try {
			return self::PHASE_APPLY === $phase ? $this->apply( $context ) : $this->setup( $context );
		} catch ( FileRestoreException $e ) {
			return StepResult::fail( $this->failure( $context, $e->getMessage(), self::PHASE_APPLY === $phase ), $state );
		}
	}

	/**
	 * Phase s: check the protection, open the journal, switch maintenance mode on.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws FileRestoreException When the setup fails (nothing was changed).
	 */
	private function setup( JobContext $context ) {
		$state      = $context->state();
		$meta       = $state->get_meta();
		$restore_id = RestoreMeta::restore_id( $meta );
		$plan       = $this->plan_of( $context );
		if ( ! $plan instanceof RestorePlan ) {
			return StepResult::fail( $this->plan_error( $context ), $state );
		}

		$parts = $this->parts( $context, $plan );
		if ( array() === $parts ) {
			$meta['files'] = self::result( FileRestorer::new_cursor(), 0 );
			return StepResult::next_step( $state->with_meta( $meta )->with_cursor( array() ) );
		}
		foreach ( $parts as $part ) {
			if ( '' === $part['kind'] ) {
				return StepResult::fail( __( 'The files of this backup are in an archive format that cannot be restored yet. Nothing was changed.', 'openvault-backup' ), $state );
			}
		}

		$server = RestoreMeta::server_config( $meta );
		try {
			$problem = ExtractionPolicy::site_self_test( $server );
		} catch ( \RuntimeException $e ) {
			throw new FileRestoreException( 'setup', __( 'The local backup folder is not available. Nothing was changed.', 'openvault-backup' ) );
		}
		if ( '' !== $problem ) {
			throw new FileRestoreException( 'setup', __( 'The protection of the plugin\'s own files could not be verified, so no file was restored. Nothing was changed.', 'openvault-backup' ) );
		}

		$summary  = $plan->summary();
		$contents = isset( $summary['contents'] ) && is_array( $summary['contents'] ) ? $summary['contents'] : array();
		$store    = UndoStore::for_restore( $restore_id );
		if ( ! $store->open( $restore_id, $contents ) ) {
			throw new FileRestoreException( 'setup', __( 'The undo journal could not be created in the local backup folder. Nothing was changed.', 'openvault-backup' ) );
		}
		if ( ! $this->maintenance->enable( $restore_id ) ) {
			throw new FileRestoreException( 'setup', __( 'Maintenance mode could not be switched on (the local backup folder is not writable). Nothing was changed.', 'openvault-backup' ) );
		}

		$context->log( Logger::INFO, __( 'The file restore starts now and the site is in maintenance mode.', 'openvault-backup' ), array( 'parts' => count( $parts ) ) );
		return StepResult::continue_step( $state->with_cursor( array_merge( FileRestorer::new_cursor(), array( 'ph' => self::PHASE_APPLY ) ) ) );
	}

	/**
	 * Phase a: restore entries until the budget is used up.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws FileRestoreException When the restore must stop (a damaged part counts as one: reason "archive").
	 */
	private function apply( JobContext $context ) {
		$state      = $context->state();
		$meta       = $state->get_meta();
		$restore_id = RestoreMeta::restore_id( $meta );
		$plan       = $this->plan_of( $context );
		if ( ! $plan instanceof RestorePlan ) {
			return StepResult::fail( $this->failure( $context, $this->plan_error( $context ), true ), $state );
		}
		$parts = $this->parts( $context, $plan );

		$summary  = $plan->summary();
		$contents = isset( $summary['contents'] ) && is_array( $summary['contents'] ) ? $summary['contents'] : array();
		try {
			$policy = ExtractionPolicy::from_environment( $contents, Paths::local_dir(), RestoreMeta::server_config( $meta ) );
			$store  = UndoStore::for_restore( $restore_id );
		} catch ( \RuntimeException $e ) {
			throw new FileRestoreException( 'setup', __( 'The local backup folder is not available.', 'openvault-backup' ) );
		}
		if ( ! $store->exists() ) {
			throw new FileRestoreException( 'journal', __( 'The undo journal is missing.', 'openvault-backup' ) );
		}

		$this->maintenance->refresh();
		$uuid    = (string) $context->backup()['uuid'];
		$by_name = array();
		foreach ( $plan->files() as $file ) {
			$by_name[ (string) $file['name'] ] = $file;
		}
		$check    = static function ( array $part ) use ( $by_name, $uuid ) {
			if ( ! isset( $by_name[ $part['name'] ] ) ) {
				return __( 'A part of the backup is missing.', 'openvault-backup' );
			}
			$why = VerifyStep::check_file( $by_name[ $part['name'] ], $part['path'], $uuid );
			return '' === $why ? '' : sprintf(
				/* translators: 1: file name, 2: what is wrong. */
				__( 'The backup file %1$s changed after it was verified: %2$s.', 'openvault-backup' ),
				$part['name'],
				$why
			);
		};
		$restorer = new FileRestorer( $policy, $this->writer, $store, substr( md5( 'ovb-restore-' . $restore_id ), 0, 8 ), $check, $this->persist_every );

		$cursor = $state->get_cursor();
		unset( $cursor['ph'] );
		$restores = $this->restores;
		$current  = $state;
		$persist  = static function ( array $saved ) use ( $restores, &$current, $restore_id ) {
			$current = $current->with_cursor( array_merge( array( 'ph' => self::PHASE_APPLY ), $saved ) );
			if ( ! $restores->save_state( $restore_id, $current ) ) {
				throw new \RuntimeException( 'The job state could not be saved.' );
			}
		};

		$result = $restorer->restore_slice( $parts, $cursor, $context->budget(), $persist );
		foreach ( $result['log'] as $line ) {
			$context->log( $line['level'], $line['message'], $line['context'] );
		}

		if ( ! $result['done'] ) {
			return StepResult::continue_step( $state->with_cursor( array_merge( array( 'ph' => self::PHASE_APPLY ), $result['cursor'] ) ) );
		}

		$meta['files'] = self::result( $result['cursor'], count( $parts ) );
		$context->log( Logger::INFO, __( 'The files were restored.', 'openvault-backup' ), $meta['files'] );
		return StepResult::next_step( $state->with_meta( $meta )->with_cursor( array() ) );
	}

	/**
	 * The result summary stored in the meta. Pure.
	 *
	 * @param array<string,mixed> $cursor Final cursor of the restorer.
	 * @param int                 $parts  Number of parts.
	 * @return array<string,mixed>
	 */
	public static function result( array $cursor, $parts ) {
		$skipped = isset( $cursor['sk'] ) && is_array( $cursor['sk'] ) ? $cursor['sk'] : array();
		return array(
			'restored'    => isset( $cursor['fl'] ) ? (int) $cursor['fl'] : 0,
			'created'     => isset( $cursor['cr'] ) ? (int) $cursor['cr'] : 0,
			'overwritten' => isset( $cursor['ow'] ) ? (int) $cursor['ow'] : 0,
			'bytes'       => isset( $cursor['by'] ) ? (int) $cursor['by'] : 0,
			'parts'       => (int) $parts,
			'skipped'     => $skipped,
			'skipped_n'   => (int) array_sum( $skipped ),
		);
	}

	/**
	 * The files parts of the plan, in order, with their paths.
	 *
	 * @param JobContext  $context Context.
	 * @param RestorePlan $plan    Plan.
	 * @return array<int,array{kind:string,path:string,name:string}>
	 */
	private function parts( JobContext $context, RestorePlan $plan ) {
		$dir = $plan->is_local() ? $this->loader->local_folder( $context->backup() ) : $context->work_dir();
		if ( null === $dir ) {
			return array();
		}
		$out = array();
		foreach ( $plan->files() as $file ) {
			if ( 'manifest' === $file['kind'] || 'db' === $file['kind'] ) {
				continue;
			}
			$out[] = array(
				'kind' => ArchiveReader::kind_for( (string) $file['name'] ),
				'path' => RestoreWorkDir::join( $dir, (string) $file['name'] ),
				'name' => (string) $file['name'],
			);
		}
		return $out;
	}

	/**
	 * Load the plan (null when it cannot be built).
	 *
	 * @param JobContext $context Context.
	 * @return RestorePlan|null
	 */
	private function plan_of( JobContext $context ) {
		$loaded = $this->load_plan( $context );
		return $loaded['plan'] instanceof RestorePlan ? $loaded['plan'] : null;
	}

	/**
	 * Reason the plan is missing.
	 *
	 * @param JobContext $context Context.
	 * @return string
	 */
	private function plan_error( JobContext $context ) {
		$loaded = $this->load_plan( $context );
		return '' !== (string) $loaded['error'] ? (string) $loaded['error'] : __( 'The backup cannot be read any more.', 'openvault-backup' );
	}

	/**
	 * The text of a file restore that stopped.
	 *
	 * @param JobContext $context Context.
	 * @param string     $detail  What happened (no paths).
	 * @param bool       $applied Whether the apply phase had started (otherwise nothing was changed).
	 * @return string
	 */
	private function failure( JobContext $context, $detail, $applied ) {
		if ( ! $applied ) {
			return trim( $detail );
		}
		$meta     = $context->state()->get_meta();
		$snapshot = RestoreMeta::snapshot_id( $meta );
		$text     = __( 'The restore stopped part way. Some files were already replaced: use Undo restore (it puts the replaced files back) or restore again.', 'openvault-backup' );
		if ( $snapshot > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: safety snapshot backup id. */
				__( 'The database was restored too; its safety snapshot is backup #%d.', 'openvault-backup' ),
				$snapshot
			);
		}
		return trim( $text . ' ' . $detail );
	}
}
