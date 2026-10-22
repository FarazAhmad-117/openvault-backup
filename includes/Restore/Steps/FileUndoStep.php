<?php
/**
 * Undoes the file changes of an earlier restore by replaying its journal backwards.
 *
 * Phase: MVP
 *
 * Purpose: Step "file_undo" of an undo job (mode "undo" with files to put back; every other job skips it). The work is done by Restore\FileUndoer.
 *
 * Phases (cursor key "ph"):
 *  - s  setup. The journal of the restore named by the meta "undo_of" must exist and start with a valid header (its contents flags give the roots
 *       again; absolute paths are never read from the journal). The protection self-test runs, maintenance mode is switched on. Nothing is changed.
 *  - a  apply. FileUndoer::undo_slice() replays the journal from its end within the Budget (the cursor is the byte offset). Cancel is refused from here on.
 *
 * Result (meta "undo_files"): restored (originals put back), removed (created files removed), folders removed and kept, errors, skipped by reason.
 * The journal stays after the undo until the user dismisses it (RestoreManager::dismiss_undo()).
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated texts; they are escaped where they are displayed.

namespace OpenVault\Backup\Restore\Steps;

use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Restore\ExtractionPolicy;
use OpenVault\Backup\Restore\FileRestoreException;
use OpenVault\Backup\Restore\FileUndoer;
use OpenVault\Backup\Restore\MaintenanceMode;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\SafeWriter;
use OpenVault\Backup\Restore\UndoJournal;
use OpenVault\Backup\Restore\UndoStore;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * File undo step.
 */
class FileUndoStep extends AbstractRestoreStep {

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
	 * Lines between two saved cursors.
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
	 * @param SafeWriter|null         $writer        File writer.
	 * @param int                     $persist_every Lines between two saved cursors.
	 */
	public function __construct( ?PlanLoader $loader = null, ?callable $destinations = null, ?RestoresRepository $restores = null, ?MaintenanceMode $maintenance = null, ?SafeWriter $writer = null, $persist_every = FileUndoer::PERSIST_EVERY ) {
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
		return JobState::STEP_FILE_UNDO;
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
		if ( RestoreMeta::MODE_UNDO !== RestoreMeta::mode( $meta ) || ! RestoreMeta::does_files( $meta ) ) {
			return StepResult::next_step( $state->with_cursor( array() ) );
		}

		$cursor  = $state->get_cursor();
		$applied = isset( $cursor['ph'] ) && self::PHASE_APPLY === $cursor['ph'];
		try {
			return $applied ? $this->apply( $context ) : $this->setup( $context );
		} catch ( FileRestoreException $e ) {
			$text = $applied ? trim( __( 'The undo stopped part way. Some files may already be back; run Undo restore again.', 'openvault-backup' ) . ' ' . $e->getMessage() ) : $e->getMessage();
			return StepResult::fail( $text, $state );
		}
	}

	/**
	 * Phase s.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws FileRestoreException When the undo cannot start (nothing was changed).
	 */
	private function setup( JobContext $context ) {
		$state = $context->state();
		$meta  = $state->get_meta();
		$store = $this->store( $meta );
		$this->header( $store );

		try {
			$problem = ExtractionPolicy::site_self_test( RestoreMeta::server_config( $meta ) );
		} catch ( \RuntimeException $e ) {
			throw new FileRestoreException( 'setup', __( 'The local backup folder is not available. Nothing was changed.', 'openvault-backup' ) );
		}
		if ( '' !== $problem ) {
			throw new FileRestoreException( 'setup', __( 'The protection of the plugin\'s own files could not be verified, so nothing was undone. Nothing was changed.', 'openvault-backup' ) );
		}
		if ( ! $this->maintenance->enable( RestoreMeta::restore_id( $meta ) ) ) {
			throw new FileRestoreException( 'setup', __( 'Maintenance mode could not be switched on (the local backup folder is not writable). Nothing was changed.', 'openvault-backup' ) );
		}

		$undoer = $this->undoer( $meta, $store );
		$context->log( Logger::INFO, __( 'The undo of the file changes starts now and the site is in maintenance mode.', 'openvault-backup' ), array( 'undo_of' => RestoreMeta::undo_of( $meta ) ) );
		return StepResult::continue_step( $state->with_cursor( array_merge( $undoer->new_cursor(), array( 'ph' => self::PHASE_APPLY ) ) ) );
	}

	/**
	 * Phase a.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws FileRestoreException When the undo must stop.
	 */
	private function apply( JobContext $context ) {
		$state      = $context->state();
		$meta       = $state->get_meta();
		$restore_id = RestoreMeta::restore_id( $meta );
		$store      = $this->store( $meta );
		$this->header( $store );
		$undoer = $this->undoer( $meta, $store );

		$this->maintenance->refresh();
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

		$result = $undoer->undo_slice( $cursor, $context->budget(), $persist );
		foreach ( $result['log'] as $line ) {
			$context->log( $line['level'], $line['message'], $line['context'] );
		}
		if ( ! $result['done'] ) {
			return StepResult::continue_step( $state->with_cursor( array_merge( array( 'ph' => self::PHASE_APPLY ), $result['cursor'] ) ) );
		}

		$meta['undo_files'] = self::result( $result['cursor'] );
		$context->log( Logger::INFO, __( 'The file changes were undone.', 'openvault-backup' ), $meta['undo_files'] );
		return StepResult::next_step( $state->with_meta( $meta )->with_cursor( array() ) );
	}

	/**
	 * The result summary. Pure.
	 *
	 * @param array<string,mixed> $cursor Final cursor.
	 * @return array<string,mixed>
	 */
	public static function result( array $cursor ) {
		$skipped = isset( $cursor['sk'] ) && is_array( $cursor['sk'] ) ? $cursor['sk'] : array();
		return array(
			'restored'        => isset( $cursor['rs'] ) ? (int) $cursor['rs'] : 0,
			'removed'         => isset( $cursor['rm'] ) ? (int) $cursor['rm'] : 0,
			'folders_removed' => isset( $cursor['rd'] ) ? (int) $cursor['rd'] : 0,
			'folders_kept'    => isset( $cursor['kd'] ) ? (int) $cursor['kd'] : 0,
			'errors'          => isset( $cursor['er'] ) ? (int) $cursor['er'] : 0,
			'skipped'         => $skipped,
			'skipped_n'       => (int) array_sum( $skipped ),
		);
	}

	/**
	 * Store of the restore that is being undone.
	 *
	 * @param array<mixed> $meta Meta.
	 * @return UndoStore
	 * @throws FileRestoreException When the journal is gone.
	 */
	private function store( array $meta ) {
		$original = RestoreMeta::undo_of( $meta );
		try {
			$store = UndoStore::for_restore( $original );
		} catch ( \InvalidArgumentException $e ) {
			throw new FileRestoreException( 'journal', __( 'The undo journal is gone.', 'openvault-backup' ) );
		} catch ( \RuntimeException $e ) {
			throw new FileRestoreException( 'setup', __( 'The local backup folder is not available. Nothing was changed.', 'openvault-backup' ) );
		}
		if ( ! $store->exists() ) {
			throw new FileRestoreException( 'journal', __( 'The undo journal of the restore is gone, so its files cannot be put back.', 'openvault-backup' ) );
		}
		return $store;
	}

	/**
	 * The contents flags of the journal header.
	 *
	 * @param UndoStore $store Store.
	 * @return array<string,bool>
	 * @throws FileRestoreException When the header is not valid.
	 */
	private function header( UndoStore $store ) {
		$handle = @fopen( $store->journal_path(), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Own journal.
		$line   = false === $handle ? false : fgets( $handle, UndoJournal::MAX_LINE );
		if ( false !== $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Own journal.
		}
		$contents = false === $line ? null : UndoJournal::header_contents( rtrim( $line, "\r\n" ) );
		if ( null === $contents ) {
			throw new FileRestoreException( 'journal', __( 'The undo journal is damaged (its header cannot be read), so its files cannot be put back.', 'openvault-backup' ) );
		}
		return $contents;
	}

	/**
	 * The undoer for a store: roots from the journal's contents flags.
	 *
	 * @param array<mixed> $meta  Meta.
	 * @param UndoStore    $store Store.
	 * @return FileUndoer
	 * @throws FileRestoreException When the journal or the local folder is unusable.
	 */
	private function undoer( array $meta, UndoStore $store ) {
		$contents = $this->header( $store );
		try {
			$policy = ExtractionPolicy::from_environment( $contents, Paths::local_dir(), RestoreMeta::server_config( $meta ) );
		} catch ( \RuntimeException $e ) {
			throw new FileRestoreException( 'setup', __( 'The local backup folder is not available.', 'openvault-backup' ) );
		}
		return new FileUndoer( $policy, $this->writer, $store, substr( md5( 'ovb-undo-' . RestoreMeta::restore_id( $meta ) ), 0, 8 ), $this->persist_every );
	}
}
