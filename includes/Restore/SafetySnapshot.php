<?php
/**
 * Quick pre-restore backup so a restore can be undone (see docs/plan/08-restore.md).
 *
 * Phase: MVP
 *
 * Purpose: Before a restore changes anything, the CURRENT state of the site is saved by a real backup that runs through the normal
 * backup engine (JobRunner, its steps, its manifest and checksums): trigger_type "pre-restore", stored in the local backup folder
 * only (no destinations), locked so that retention never deletes it, and shown in History like any other backup (labelled "Safety
 * snapshot before restore"). "Undo restore" is then an ordinary restore from that backup.
 *
 * The snapshot is a backup of the DATABASE only (contents { db: true }), taken for the modes that replace the database ("db" and "full"). The files
 * a restore overwrites are NOT in it: copying a whole site before every restore would cost terabytes. Their recoverable state is the undo
 * journal (RestoreNDOJOURNAL): each file is copied there just before it is replaced. A files-only restore therefore takes no snapshot at all
 * (contents_for_mode() is empty for it). The format is the one of the backup being restored when this server supports it, else the default format.
 *
 * Exclusion between backups and restores: users cannot start a backup while a restore is queued or running (the REST route answers
 * 409 ovb_restore_running). The snapshot is the one backup that must run inside a running restore, so it does not go through that
 * route: create() writes the backup row and starts the engine job directly. That is the explicit internal bypass. The restore step
 * waits for it (SnapshotStep) and never goes on unless the snapshot is complete.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Archive\CapabilityDetector;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Safety snapshot.
 */
class SafetySnapshot {

	const TRIGGER = 'pre-restore';

	const STATE_RUNNING  = 'running';
	const STATE_COMPLETE = 'complete';
	const STATE_FAILED   = 'failed';
	const STATE_MISSING  = 'missing';

	/**
	 * Backups.
	 *
	 * @var BackupsRepository
	 */
	private $backups;

	/**
	 * Engine runner used to start the snapshot job.
	 *
	 * @var JobRunner
	 */
	private $runner;

	/**
	 * Format detection.
	 *
	 * @var CapabilityDetector
	 */
	private $detector;

	/**
	 * Constructor.
	 *
	 * @param BackupsRepository|null  $backups  Backups.
	 * @param JobRunner|null          $runner   Engine runner.
	 * @param CapabilityDetector|null $detector Format detection.
	 */
	public function __construct( ?BackupsRepository $backups = null, ?JobRunner $runner = null, ?CapabilityDetector $detector = null ) {
		$this->backups  = null === $backups ? new BackupsRepository() : $backups;
		$this->runner   = null === $runner ? new JobRunner( $this->backups ) : $runner;
		$this->detector = null === $detector ? new CapabilityDetector() : $detector;
	}

	/**
	 * What the snapshot saves for a restore mode. Pure.
	 *
	 * The database for "db" and "full" (the files are covered by the undo journal, not by the snapshot); nothing for "files" (no snapshot is taken).
	 *
	 * @param string $mode Restore mode.
	 * @return array<string,bool>
	 */
	public static function contents_for_mode( $mode ) {
		return array(
			'db'            => 'files' !== $mode,
			'plugins'       => false,
			'themes'        => false,
			'uploads'       => false,
			'mu_plugins'    => false,
			'other_content' => false,
			'core'          => false,
			'wp_config'     => false,
		);
	}

	/**
	 * Note of the snapshot backup. Pure.
	 *
	 * @param int $restore_id Restore id.
	 * @return string
	 */
	public static function note( $restore_id ) {
		return 'Safety snapshot before restore #' . (int) $restore_id;
	}

	/**
	 * What a backup row says about the snapshot. Pure.
	 *
	 * Only "complete" counts as a snapshot that can be relied on ("partial" means files were skipped).
	 *
	 * @param array<string,mixed>|null $row Backup row, null when it does not exist.
	 * @return string One of the STATE_ constants.
	 */
	public static function classify( $row ) {
		if ( ! is_array( $row ) || ! isset( $row['status'] ) ) {
			return self::STATE_MISSING;
		}
		if ( 'complete' === $row['status'] ) {
			return self::STATE_COMPLETE;
		}
		if ( in_array( $row['status'], BackupsRepository::INCOMPLETE, true ) ) {
			return self::STATE_RUNNING;
		}
		return self::STATE_FAILED;
	}

	/**
	 * The format for the snapshot: the preferred one when this server can write it, else the default format.
	 *
	 * @param string $preferred Format of the backup being restored.
	 * @return string|null Null when the server has no usable archive format.
	 */
	public function format_for( $preferred ) {
		if ( $this->detector->is_available( $preferred ) ) {
			return $preferred;
		}
		return $this->detector->default_format();
	}

	/**
	 * Create the snapshot backup and start its job.
	 *
	 * @param int    $restore_id Restore id.
	 * @param string $mode       Restore mode.
	 * @param string $preferred  Format of the backup being restored.
	 * @return int Backup id.
	 * @throws \RuntimeException With a message for the user when the snapshot cannot be started.
	 */
	public function create( $restore_id, $mode, $preferred ) {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated texts; they are escaped where they are displayed.
		$format = $this->format_for( (string) $preferred );
		if ( null === $format ) {
			throw new \RuntimeException( __( 'No archive format is available on this server, so the safety snapshot cannot be made. The restore was not started.', 'openvault-backup' ) );
		}
		try {
			Paths::backups_dir();
		} catch ( \RuntimeException $e ) {
			throw new \RuntimeException( __( 'The local backup folder is not writable, so the safety snapshot cannot be made. The restore was not started.', 'openvault-backup' ) );
		}

		$id = $this->backups->create(
			array(
				'trigger_type' => self::TRIGGER,
				'contents'     => self::contents_for_mode( $mode ),
				'format'       => $format,
				'locked'       => 1,
				'note'         => self::note( $restore_id ),
			)
		);
		if ( $id < 1 ) {
			throw new \RuntimeException( __( 'The safety snapshot could not be created. The restore was not started.', 'openvault-backup' ) );
		}
		if ( ! $this->runner->start( $id, array() ) ) {
			throw new \RuntimeException( __( 'The safety snapshot could not be started. The restore was not started.', 'openvault-backup' ) );
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		return $id;
	}

	/**
	 * State of a snapshot backup.
	 *
	 * @param int $backup_id Backup id.
	 * @return string One of the STATE_ constants.
	 */
	public function state( $backup_id ) {
		return self::classify( $this->backups->find( (int) $backup_id ) );
	}

	/**
	 * Make sure the snapshot is locked (retention and manual delete leave it alone).
	 *
	 * @param int $backup_id Backup id.
	 * @return void
	 */
	public function lock( $backup_id ) {
		$this->backups->set_locked( (int) $backup_id, true );
	}

	/**
	 * Remove the lock (a failed snapshot is not worth keeping).
	 *
	 * @param int $backup_id Backup id.
	 * @return void
	 */
	public function unlock( $backup_id ) {
		$this->backups->set_locked( (int) $backup_id, false );
	}

	/**
	 * Stop a snapshot that has not finished (the restore was cancelled).
	 *
	 * @param int $backup_id Backup id.
	 * @return void
	 */
	public function cancel( $backup_id ) {
		if ( self::STATE_RUNNING === $this->state( $backup_id ) ) {
			$this->runner->cancel( (int) $backup_id );
		}
	}
}
