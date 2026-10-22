<?php
/**
 * Restores the database of a backup, in place, safely and resumably.
 *
 * Phase: MVP
 *
 * Purpose: Step "db_restore" of a restore job (modes "db" and "full"; mode "files" skips it). It runs after the safety snapshot.
 *
 * Phases (cursor key "ph"):
 *  - x  extract: db/db.sql is read out of the database part (ArchiveReader, resumable by byte offset) into <work folder>/db.sql.
 *       Before the first byte the part is hashed again (it may have changed since "verify") and the free disk space is checked.
 *  - v  validate: DatabaseImporter::validate_slice() judges every line of the dump with the whitelist (DumpValidator) without
 *       executing anything. A refused line fails the restore here, with its line number, and NOTHING has been changed. At the end
 *       the largest statement is compared with max_allowed_packet, the plugin's own settings are captured (SelfPreserver) and
 *       maintenance mode is switched on.
 *  - i  import: DatabaseImporter::import_slice() runs the dump through $wpdb, one statement at a time, storing the cursor in the job
 *       state after EVERY statement (see DatabaseImporter for the resume rule: a crash restarts only the table in progress). The
 *       options table is swapped in atomically and the plugin's rows are written back at once (SelfPreserver). Maintenance mode is
 *       refreshed every slice. At the end the kept settings are read back and verified.
 *
 * Failure. Before the import starts the job fails with "nothing was changed". Once it runs, a refused line or a statement error
 * fails the restore with the actionable text "The restore stopped part way. Your site may be in a mixed state: use Undo restore
 * (safety snapshot) or restore again." and the snapshot id; the message holds the line number, table name and MySQL error number,
 * never a statement or a value. Maintenance mode STAYS ON then (it expires 30 minutes after the last refresh) until the user
 * decides. A lost connection (error 2006 or 2013) is retried with the runner's backoff; the stored cursor is unclean, so the table
 * in progress is imported again from its DROP TABLE line.
 *
 * Cancel is possible in phases x and v (nothing was changed), and refused from phase i on (RestoreRunner::cancel_refusal()).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore\Steps;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Archive\ArchiveReader;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Restore\DatabaseImporter;
use OpenVault\Backup\Restore\DumpValidator;
use OpenVault\Backup\Restore\ImportException;
use OpenVault\Backup\Restore\MaintenanceMode;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Restore\RestoreWorkDir;
use OpenVault\Backup\Restore\SelfPreserver;
use OpenVault\Backup\Restore\WpdbExecutor;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Database restore step.
 */
class DbRestoreStep extends AbstractRestoreStep {

	const PHASE_EXTRACT  = 'x';
	const PHASE_VALIDATE = 'v';
	const PHASE_IMPORT   = 'i';

	const DUMP_FILE = 'db.sql';

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
	 * Self-preservation.
	 *
	 * @var SelfPreserver
	 */
	private $preserver;

	/**
	 * Constructor.
	 *
	 * @param PlanLoader|null         $loader       Plan loader.
	 * @param callable|null           $destinations Provider factory.
	 * @param RestoresRepository|null $restores     Restores.
	 * @param MaintenanceMode|null    $maintenance  Maintenance flag.
	 * @param SelfPreserver|null      $preserver    Self-preservation.
	 */
	public function __construct( ?PlanLoader $loader = null, ?callable $destinations = null, ?RestoresRepository $restores = null, ?MaintenanceMode $maintenance = null, ?SelfPreserver $preserver = null ) {
		parent::__construct( $loader, $destinations );
		$this->restores    = null === $restores ? new RestoresRepository() : $restores;
		$this->maintenance = null === $maintenance ? new MaintenanceMode() : $maintenance;
		$this->preserver   = null === $preserver ? new SelfPreserver() : $preserver;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_DB_RESTORE;
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
		if ( ! RestoreMeta::does_db( $meta ) ) {
			return StepResult::next_step( $state->with_cursor( array() ) );
		}

		$cursor = $state->get_cursor();
		$phase  = isset( $cursor['ph'] ) && is_string( $cursor['ph'] ) ? $cursor['ph'] : self::PHASE_EXTRACT;

		try {
			if ( self::PHASE_IMPORT === $phase ) {
				return $this->import( $context );
			}
			if ( self::PHASE_VALIDATE === $phase ) {
				return $this->validate( $context );
			}
			return $this->extract( $context );
		} catch ( ArchiveException $e ) {
			return StepResult::fail( $e->getMessage(), $state );
		} catch ( ImportException $e ) {
			return $this->import_failed( $context, $state, $e, self::PHASE_IMPORT === $phase );
		}
	}

	/**
	 * Phase x: copy db/db.sql out of the database part.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws ArchiveException When the part cannot be read.
	 */
	private function extract( JobContext $context ) {
		$state  = $context->state();
		$loaded = $this->load_plan( $context );
		$plan   = $loaded['plan'];
		if ( ! $plan instanceof RestorePlan ) {
			return StepResult::fail( $loaded['error'], $state );
		}
		$dir = $plan->is_local() ? $this->loader->local_folder( $context->backup() ) : $context->work_dir();
		if ( null === $dir ) {
			return StepResult::fail( __( 'The local copy of the backup is gone.', 'openvault-backup' ), $state );
		}

		$part = null;
		foreach ( $plan->files() as $file ) {
			if ( 'db' === $file['kind'] ) {
				$part = $file;
				break;
			}
		}
		if ( null === $part ) {
			return StepResult::fail( __( 'This backup does not contain the database.', 'openvault-backup' ), $state );
		}

		$kind = ArchiveReader::kind_for( (string) $part['name'] );
		if ( '' === $kind ) {
			return StepResult::fail( __( 'The database of this backup is in an archive format that cannot be restored yet.', 'openvault-backup' ), $state );
		}
		$path   = RestoreWorkDir::join( $dir, (string) $part['name'] );
		$dest   = RestoreWorkDir::join( $context->work_dir(), self::DUMP_FILE );
		$cursor = $state->get_cursor();
		$offset = isset( $cursor['off'] ) ? max( 0, (int) $cursor['off'] ) : 0;

		if ( 0 === $offset ) {
			// The part was verified some time ago: make sure it is still the same file, then make room.
			$why = VerifyStep::check_file( $part, $path, (string) $context->backup()['uuid'] );
			if ( '' !== $why ) {
				return StepResult::fail(
					sprintf(
						/* translators: 1: file name, 2: what is wrong. */
						__( 'The backup file %1$s changed after it was verified: %2$s. Nothing was changed. Start the restore again.', 'openvault-backup' ),
						$part['name'],
						$why
					),
					$state
				);
			}
			$size = ArchiveReader::entry_size( $kind, $path );
			$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $context->work_dir() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fails on restricted hosts.
			if ( false !== $free && (float) $free < $size + 33554432 ) {
				return StepResult::fail(
					sprintf(
						/* translators: 1: free disk space, 2: required disk space. */
						__( 'Not enough free disk space to unpack the database: %1$s free, %2$s needed. Nothing was changed.', 'openvault-backup' ),
						\OpenVault\Backup\Support\SystemInfo::format_bytes( (int) $free ),
						\OpenVault\Backup\Support\SystemInfo::format_bytes( (int) ( $size + 33554432 ) )
					),
					$state
				);
			}
		}//end if

		$result = ArchiveReader::extract( $kind, $path, ArchiveReader::ENTRY_DB, $dest, $offset, $context->budget() );
		if ( ! $result['done'] ) {
			return StepResult::continue_step(
				$state->with_cursor(
					array(
						'ph'  => self::PHASE_EXTRACT,
						'off' => $result['offset'],
					)
				)
			);
		}

		$context->log( Logger::INFO, __( 'The database dump was unpacked.', 'openvault-backup' ), array( 'bytes' => $result['size'] ) );
		$meta         = $state->get_meta();
		$meta['dump'] = array( 'size' => $result['size'] );
		return StepResult::continue_step(
			$state->with_meta( $meta )->with_cursor( array_merge( DatabaseImporter::new_validate_cursor(), array( 'ph' => self::PHASE_VALIDATE ) ) )
		);
	}

	/**
	 * Phase v: judge every line without executing anything, then prepare the import.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws ImportException When a line is refused or the dump is incomplete.
	 */
	private function validate( JobContext $context ) {
		global $wpdb;

		$state = $context->state();
		$path  = RestoreWorkDir::join( $context->work_dir(), self::DUMP_FILE );
		if ( ! is_file( $path ) ) {
			// The unpacked file is gone: unpack again.
			return StepResult::continue_step( $state->with_cursor( array( 'ph' => self::PHASE_EXTRACT ) ) );
		}

		$importer = $this->importer( $context, new WpdbExecutor( $wpdb ) );
		$cursor   = $state->get_cursor();
		unset( $cursor['ph'] );
		$result = $importer->validate_slice( $path, $cursor, $context->budget() );
		$next   = array_merge( $result['cursor'], array( 'ph' => self::PHASE_VALIDATE ) );
		if ( ! $result['done'] ) {
			return StepResult::continue_step( $state->with_cursor( $next ) );
		}

		$found  = $result['cursor'];
		$packet = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Server setting, fixed statement.
		if ( $packet > 0 && $found['maxlen'] + 1024 > $packet ) {
			return StepResult::fail(
				sprintf(
					/* translators: 1: size of the largest statement, 2: max_allowed_packet. */
					__( 'The database dump has a statement of %1$s but the database server accepts at most %2$s per statement (max_allowed_packet). Ask your host to raise it, then start the restore again. Nothing was changed.', 'openvault-backup' ),
					\OpenVault\Backup\Support\SystemInfo::format_bytes( (int) $found['maxlen'] ),
					\OpenVault\Backup\Support\SystemInfo::format_bytes( $packet )
				),
				$state
			);
		}

		$meta       = $state->get_meta();
		$restore_id = RestoreMeta::restore_id( $meta );
		try {
			$this->preserver->capture( $context->work_dir(), RestoreMeta::keep_site_urls( $meta ), $this->starter_login( $restore_id ) );
		} catch ( \RuntimeException $e ) {
			return StepResult::fail( $e->getMessage() . ' ' . __( 'Nothing was changed.', 'openvault-backup' ), $state );
		}
		if ( ! $this->maintenance->enable( $restore_id ) ) {
			return StepResult::fail( __( 'Maintenance mode could not be switched on (the local backup folder is not writable). Nothing was changed.', 'openvault-backup' ), $state );
		}

		$meta['db_started'] = true;
		// From the next slice on tables are replaced: the job can only be undone, not cancelled.
		$meta['dump'] = array(
			'size'       => isset( $meta['dump']['size'] ) ? (int) $meta['dump']['size'] : 0,
			'tables'     => $found['tables'],
			'statements' => $found['stmts'],
			'rows'       => $found['rows'],
		);
		$context->log(
			Logger::INFO,
			__( 'The database dump was checked. The restore starts now and the site is in maintenance mode.', 'openvault-backup' ),
			array(
				'tables'     => $found['tables'],
				'statements' => $found['stmts'],
			)
		);
		return StepResult::continue_step(
			$state->with_meta( $meta )->with_cursor( array_merge( DatabaseImporter::new_import_cursor(), array( 'ph' => self::PHASE_IMPORT ) ) )
		);
	}

	/**
	 * Phase i: import statements until the budget is used up.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws ImportException When a statement fails.
	 */
	private function import( JobContext $context ) {
		global $wpdb;

		$state      = $context->state();
		$meta       = $state->get_meta();
		$restore_id = RestoreMeta::restore_id( $meta );
		$path       = RestoreWorkDir::join( $context->work_dir(), self::DUMP_FILE );
		if ( ! is_file( $path ) ) {
			return StepResult::fail( $this->part_way( $context, __( 'The unpacked database dump is missing.', 'openvault-backup' ) ), $state );
		}
		if ( ! $this->preserver->has_capture( $context->work_dir() ) ) {
			return StepResult::fail( $this->part_way( $context, __( 'The saved plugin settings are missing.', 'openvault-backup' ) ), $state );
		}

		$this->maintenance->refresh();

		$executor = new WpdbExecutor( $wpdb );
		$executor->begin_session();
		$dir      = $context->work_dir();
		$keep     = RestoreMeta::keep_site_urls( $meta );
		$user     = $this->starter_login( $restore_id );
		$importer = new DatabaseImporter(
			new DumpValidator( $wpdb->base_prefix ),
			$executor,
			$wpdb->base_prefix,
			function () use ( $dir, $keep, $user ) {
				// Just before the swap: the live rows (the running job's lock row included) as they are right now.
				$this->preserver->capture( $dir, $keep, $user );
			},
			function () use ( $dir ) {
				$this->preserver->apply( $dir );
			}
		);

		$persist = function ( array $cursor ) use ( &$state, $restore_id ) {
			$state = $state->with_cursor( array_merge( array( 'ph' => self::PHASE_IMPORT ), $cursor ) );
			if ( ! $this->restores->save_state( $restore_id, $state ) ) {
				throw new \RuntimeException( 'The job state could not be saved.' );
			}
		};

		$cursor = $state->get_cursor();
		unset( $cursor['ph'] );
		try {
			$result = $importer->import_slice( $path, $cursor, $context->budget(), $persist );
		} catch ( ImportException $e ) {
			$this->failed_state = $state;
			throw $e;
		} catch ( \Throwable $e ) {
			return StepResult::fail( $this->part_way( $context, __( 'An unexpected error stopped the import.', 'openvault-backup' ) ), $state );
		} finally {
			$executor->end_session();
		}

		$next = array_merge( array( 'ph' => self::PHASE_IMPORT ), $result['cursor'] );
		if ( ! $result['done'] ) {
			return StepResult::continue_step( $state->with_cursor( $next ) );
		}

		try {
			$this->preserver->verify( $dir, false );
		} catch ( \RuntimeException $e ) {
			return StepResult::fail( $this->part_way( $context, $e->getMessage() ), $state );
		}
		wp_cache_flush();
		$this->maintenance->refresh();

		$found             = $result['cursor'];
		$meta              = $state->get_meta();
		$meta['dbrestore'] = array(
			'tables'     => $found['td'],
			'statements' => $found['st'],
			'rows'       => $found['rw'],
			'skipped'    => $found['sk'],
		);
		$context->log(
			Logger::INFO,
			__( 'The database was restored.', 'openvault-backup' ),
			array(
				'tables' => $found['td'],
				'rows'   => $found['rw'],
			)
		);
		return StepResult::next_step( $state->with_meta( $meta )->with_cursor( array() ) );
	}

	/**
	 * State saved by the last statement of a failed import slice (the cursor of a resume).
	 *
	 * @var JobState|null
	 */
	private $failed_state = null;

	/**
	 * An import error: retry a lost connection, otherwise fail with the actionable text.
	 *
	 * @param JobContext      $context  Context.
	 * @param JobState        $state    State at the start of the slice.
	 * @param ImportException $error    Error.
	 * @param bool            $imported Whether the import (phase i) had started.
	 * @return StepResult
	 */
	private function import_failed( JobContext $context, JobState $state, ImportException $error, $imported ) {
		// The state of the last stored statement, not the one the slice started with: its cursor is the resume point.
		$latest = null !== $this->failed_state ? $this->failed_state : $state;

		if ( $error->is_transient() && $imported ) {
			$latest = $latest->increment_attempts();
			if ( $latest->get_attempts() < 3 ) {
				$context->log(
					Logger::WARNING,
					__( 'The database connection was lost during the import. It will be retried.', 'openvault-backup' ),
					array(
						'table' => $error->table(),
						'line'  => $error->line_number(),
						'errno' => $error->errno(),
					)
				);
				return StepResult::wait( 30 * $latest->get_attempts(), $latest );
			}
		}

		$text = $error->getMessage();
		if ( $error->table() !== '' ) {
			$text .= ' ' . sprintf(
				/* translators: 1: table name, 2: line number of the dump. */
				__( '(table %1$s, line %2$d)', 'openvault-backup' ),
				$error->table(),
				$error->line_number()
			);
		}
		$message = $imported
			? $this->part_way( $context, $text )
			: trim( $text . ' ' . __( 'Nothing was changed. The backup may be damaged or may have been tampered with.', 'openvault-backup' ) );
		return StepResult::fail( $message, $latest );
	}

	/**
	 * The text of a restore that stopped after the import began.
	 *
	 * @param JobContext $context Context.
	 * @param string     $detail  What happened (no statements or values).
	 * @return string
	 */
	private function part_way( JobContext $context, $detail ) {
		$snapshot = RestoreMeta::snapshot_id( $context->state()->get_meta() );
		return trim(
			sprintf(
				/* translators: 1: safety snapshot backup id, 2: detail. */
				__( 'The restore stopped part way. Your site may be in a mixed state: use Undo restore (safety snapshot, backup #%1$d) or restore again. %2$s', 'openvault-backup' ),
				$snapshot,
				$detail
			)
		);
	}

	/**
	 * Importer for validation (no statements are executed by validate_slice()).
	 *
	 * @param JobContext   $context  Context.
	 * @param WpdbExecutor $executor Executor.
	 * @return DatabaseImporter
	 */
	private function importer( JobContext $context, WpdbExecutor $executor ) {
		global $wpdb;
		unset( $context );
		return new DatabaseImporter( new DumpValidator( $wpdb->base_prefix ), $executor, $wpdb->base_prefix );
	}

	/**
	 * Login name of the user who started the restore (metadata for the result page).
	 *
	 * @param int $restore_id Restore id.
	 * @return string
	 */
	private function starter_login( $restore_id ) {
		$row  = $this->restores->find( $restore_id );
		$user = is_array( $row ) && $row['created_by'] > 0 ? get_userdata( $row['created_by'] ) : false;
		return $user instanceof \WP_User ? (string) $user->user_login : '';
	}
}
