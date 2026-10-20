<?php
/**
 * Uploads each part to each destination, chunked & resumable.
 *
 * Phase: MVP
 *
 * Purpose: Uploads each part to each destination, chunked & resumable.
 *
 * Responsibilities:
 *  - Record remote ids in ovb_backup_files
 *  - The destinations of a backup are the ids in the job state meta "destinations" (set when the backup is
 *    started). No ids: the step is skipped and the backup stays local only.
 *  - Per destination and file an ovb_backup_files row (destination_id = the destination) goes pending, uploading
 *    (with the destination's session JSON, saved after EVERY upload_chunk() result), then done (with remote_ref) or failed.
 *  - Remote path: OpenVault-Backups/<site-host>/<backup folder>/<file name> (Destinations\RemotePath). manifest.json is
 *    uploaded LAST, so a remote folder is complete only when its manifest exists (Engine\Steps\UploadPlan).
 *  - Transient DestinationExceptions are retried (3 attempts, StepResult::wait() with backoff); permanent ones, or the
 *    third failure, mark the file (and so the destination) failed. A failed destination never stops the others.
 *  - Result in meta: "upload" (destination id => { s = done|failed, e = short error }); meta "result" = partial when any
 *    destination failed, so the runner ends the backup as partial. Local files are never deleted here.
 *  - Credentials are decrypted only by the DestinationRegistry; nothing secret reaches logs, meta or messages.
 *
 * Cursor (JobState, tiny): row (file row in progress), tries, bytes (sent so far), n (chunks saved; always changes
 * so a resumed step is seen to move).
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings or scrubbed errors; they are stored and shown through escaping output functions, never echoed as HTML here.

namespace OpenVault\Backup\Engine\Steps;

use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\DestinationInterface;
use OpenVault\Backup\Destinations\DestinationRegistry;
use OpenVault\Backup\Destinations\RemotePath;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Upload step.
 */
class UploadStep implements StepInterface {

	const ROW_DONE   = 'done';
	const ROW_BUDGET = 'budget';
	const ROW_WAIT   = 'wait';

	/**
	 * Provider factory.
	 *
	 * @var DestinationRegistry|null
	 */
	private $registry;

	/**
	 * File rows.
	 *
	 * @var BackupFilesRepository
	 */
	private $files;

	/**
	 * Destinations.
	 *
	 * @var DestinationsRepository|null
	 */
	private $destinations;

	/**
	 * Constructor.
	 *
	 * @param DestinationRegistry|null    $registry     Providers (defaults to the real registry).
	 * @param BackupFilesRepository|null  $files        File rows.
	 * @param DestinationsRepository|null $destinations Destination rows.
	 */
	public function __construct( ?DestinationRegistry $registry = null, ?BackupFilesRepository $files = null, ?DestinationsRepository $destinations = null ) {
		$this->registry     = $registry;
		$this->files        = null === $files ? new BackupFilesRepository() : $files;
		$this->destinations = $destinations;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_UPLOAD;
	}

	/**
	 * Upload as much as the budget allows.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$state = $context->state();
		$meta  = $state->get_meta();
		$ids   = UploadPlan::destination_ids( $meta );
		if ( array() === $ids ) {
			$context->log( Logger::INFO, __( 'No destination was selected, the backup stays in the local folder.', 'openvault-backup' ) );
			return StepResult::next_step( $state );
		}

		try {
			$info = $this->prepare( $context );
		} catch ( \Exception $e ) {
			return StepResult::fail( __( 'The backup files could not be prepared for upload.', 'openvault-backup' ), $state );
		}
		if ( array() === $info['local'] ) {
			return StepResult::fail( __( 'There are no finished backup files to upload.', 'openvault-backup' ), $state );
		}

		$upload = isset( $meta['upload'] ) && is_array( $meta['upload'] ) ? $meta['upload'] : array();
		$cursor = $state->get_cursor();
		$run    = array(
			'row'   => isset( $cursor['row'] ) ? (int) $cursor['row'] : 0,
			'tries' => isset( $cursor['tries'] ) ? (int) $cursor['tries'] : 0,
			'bytes' => isset( $cursor['bytes'] ) ? (int) $cursor['bytes'] : 0,
			'n'     => isset( $cursor['n'] ) ? (int) $cursor['n'] : 0,
		);

		$instances = array();
		$worked    = false;
		while ( true ) {
			if ( $worked && $context->budget()->exhausted() ) {
				return StepResult::continue_step( $this->state_with( $state, $meta, $upload, $run ) );
			}

			$target = $this->next_target( $context, $ids, $info['local'], $upload );
			if ( null === $target ) {
				break;
			}
			$worked  = true;
			$outcome = $this->upload_row( $context, $target['destination'], $target['row'], $info, $instances, $run, $upload );
			if ( self::ROW_WAIT === $outcome ) {
				// A transient error: wait, then try the same file again (the session saved so far is the resume point).
				return StepResult::wait( UploadPlan::backoff( $run['tries'] ), $this->state_with( $state, $meta, $upload, $run ) );
			}
			if ( self::ROW_BUDGET === $outcome ) {
				return StepResult::continue_step( $this->state_with( $state, $meta, $upload, $run ) );
			}
		}

		return $this->finish( $context, $ids, $meta, $upload );
	}

	/**
	 * The folder, site host and finished local rows of the backup.
	 *
	 * @param JobContext $context Context.
	 * @return array{dir:string,folder:string,host:string,local:array<int,array<string,mixed>>}
	 * @throws \RuntimeException|\InvalidArgumentException When the folder or name cannot be built.
	 */
	private function prepare( JobContext $context ) {
		$backup = $context->backup();
		$folder = Paths::build_backup_folder_name( (string) $backup['created_at'], (string) $backup['uuid'] );
		$local  = array();
		foreach ( $this->files->for_backup_and_destination( $context->backup_id(), 0 ) as $row ) {
			if ( 'done' === $row['upload_status'] ) {
				$local[] = $row;
			}
		}
		return array(
			'dir'    => Paths::backup_dir( (string) $backup['created_at'], (string) $backup['uuid'] ),
			'folder' => $folder,
			'host'   => RemotePath::site_host( site_url() ),
			'local'  => UploadPlan::order( $local ),
		);
	}

	/**
	 * The next destination and file row to work on. Destinations with nothing left are recorded as done or failed.
	 *
	 * @param JobContext                     $context Context.
	 * @param int[]                          $ids     Destination ids in order.
	 * @param array<int,array<string,mixed>> $local   Local rows.
	 * @param array<mixed>                   $upload  Per destination results (updated).
	 * @return array{destination:int,row:array<string,mixed>}|null
	 */
	private function next_target( JobContext $context, array $ids, array $local, array &$upload ) {
		foreach ( $ids as $dest_id ) {
			if ( isset( $upload[ $dest_id ]['s'] ) && in_array( $upload[ $dest_id ]['s'], array( UploadPlan::STATE_DONE, UploadPlan::STATE_FAILED ), true ) ) {
				continue;
			}

			$rows = $this->ensure_rows( $context->backup_id(), $dest_id, $local );
			$row  = UploadPlan::next_row( $rows );
			if ( null !== $row ) {
				return array(
					'destination' => $dest_id,
					'row'         => $row,
				);
			}

			$result                  = UploadPlan::destination_state( $rows );
			$upload[ $dest_id ]      = isset( $upload[ $dest_id ] ) && is_array( $upload[ $dest_id ] ) ? $upload[ $dest_id ] : array();
			$upload[ $dest_id ]['s'] = UploadPlan::STATE_ACTIVE === $result ? UploadPlan::STATE_FAILED : $result;
			$context->log(
				UploadPlan::STATE_DONE === $upload[ $dest_id ]['s'] ? Logger::INFO : Logger::ERROR,
				UploadPlan::STATE_DONE === $upload[ $dest_id ]['s']
					? __( 'Upload to a destination finished.', 'openvault-backup' )
					: __( 'Upload to a destination failed.', 'openvault-backup' ),
				array( 'destination' => $dest_id )
			);
		}//end foreach
		return null;
	}

	/**
	 * Make sure every local file has a row at this destination.
	 *
	 * @param int                            $backup_id Backup id.
	 * @param int                            $dest_id   Destination id.
	 * @param array<int,array<string,mixed>> $local     Local rows.
	 * @return array<int,array<string,mixed>> Rows at the destination.
	 */
	private function ensure_rows( $backup_id, $dest_id, array $local ) {
		$rows = $this->files->for_backup_and_destination( $backup_id, $dest_id );
		if ( count( $rows ) >= count( $local ) ) {
			return $rows;
		}

		$have = array();
		foreach ( $rows as $row ) {
			$have[ $row['file_name'] ] = true;
		}
		foreach ( $local as $row ) {
			if ( ! isset( $have[ $row['file_name'] ] ) ) {
				$this->files->add(
					array(
						'backup_id'       => $backup_id,
						'destination_id'  => $dest_id,
						'part_number'     => $row['part_number'],
						'file_name'       => $row['file_name'],
						'size'            => $row['size'],
						'checksum_sha256' => $row['checksum_sha256'],
						'upload_status'   => 'pending',
					)
				);
			}
		}
		return $this->files->for_backup_and_destination( $backup_id, $dest_id );
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- DestinationException is thrown and caught inside upload_row().
	/**
	 * Upload one file row as far as the budget allows.
	 *
	 * @param JobContext                               $context   Context.
	 * @param int                                      $dest_id   Destination id.
	 * @param array<string,mixed>                      $row       File row at the destination.
	 * @param array<string,mixed>                      $info      Folder, host, local rows.
	 * @param array<int,DestinationInterface>          $instances Providers created in this run (updated).
	 * @param array{row:int,tries:int,bytes:int,n:int} $run      Cursor values (updated).
	 * @param array<mixed>                             $upload    Per destination results (updated).
	 * @return string ROW_DONE (finished or failed), ROW_BUDGET (budget used up) or ROW_WAIT (transient error, try again later).
	 * @throws \InvalidArgumentException When the backup folder name is invalid (prepare() has checked it, so this guards against a bug).
	 */
	private function upload_row( JobContext $context, $dest_id, array $row, array $info, array &$instances, array &$run, array &$upload ) {
		$budget  = $context->budget();
		$row_id  = (int) $row['id'];
		$session = array();

		try {
			if ( ! isset( $instances[ $dest_id ] ) ) {
				$instances[ $dest_id ] = $this->make( $dest_id );
			}
			$destination = $instances[ $dest_id ];

			$path = $info['dir'] . '/' . $row['file_name'];
			clearstatcache( true, $path );
			if ( ! RemotePath::is_segment( $row['file_name'] ) || ! is_file( $path ) || filesize( $path ) !== (int) $row['size'] ) {
				throw DestinationException::permanent( __( 'The local backup file is missing or has changed.', 'openvault-backup' ) );
			}

			$session = 'uploading' === $row['upload_status'] ? json_decode( (string) $row['upload_session'], true ) : null;
			if ( ! is_array( $session ) ) {
				$session = $destination->begin_upload(
					RemotePath::file( $info['host'], $info['folder'], $row['file_name'] ),
					(int) $row['size'],
					array( 'sha256' => $row['checksum_sha256'] )
				);
				$this->save( $row_id, 'uploading', array( 'upload_session' => $this->encode( $session ) ) );
			}

			do {
				$progress = $destination->upload_chunk( $session, $path, $budget );
				$session  = $progress->session();

				$run['bytes'] += $progress->bytes_sent();
				++$run['n'];
				$run['row']   = $row_id;
				$run['tries'] = 0;

				if ( $progress->done() ) {
					$this->save(
						$row_id,
						'done',
						array(
							'remote_ref'     => $progress->remote_ref(),
							'upload_session' => '',
						)
					);
					$context->log(
						Logger::INFO,
						__( 'File uploaded.', 'openvault-backup' ),
						array(
							'destination' => $dest_id,
							'file'        => $row['file_name'],
							'size'        => (int) $row['size'],
						)
					);
					return self::ROW_DONE;
				}
				$this->save( $row_id, 'uploading', array( 'upload_session' => $this->encode( $session ) ) );
			} while ( ! $budget->exhausted() );

			return self::ROW_BUDGET;
		} catch ( DestinationException $e ) {
			$tries = ( $run['row'] === $row_id ? $run['tries'] : 0 ) + 1;
			if ( $e->retryable() && UploadPlan::may_retry( $tries ) ) {
				$run['row']   = $row_id;
				$run['tries'] = $tries;
				++$run['n'];
				$delay = UploadPlan::backoff( $tries );
				$context->log(
					Logger::WARNING,
					__( 'An upload failed and will be retried.', 'openvault-backup' ),
					array(
						'destination' => $dest_id,
						'file'        => $row['file_name'],
						'attempt'     => $tries,
						'retry_in'    => $delay,
						'error'       => $e->getMessage(),
					)
				);
				return self::ROW_WAIT;
			}

			return $this->give_up( $context, $dest_id, $row, $instances, $session, $e, $upload );
		}//end try
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing

	/**
	 * A file failed for good: mark it failed, remember the first error of the destination.
	 *
	 * @param JobContext                      $context   Context.
	 * @param int                             $dest_id   Destination id.
	 * @param array<string,mixed>             $row       File row.
	 * @param array<int,DestinationInterface> $instances Providers.
	 * @param array<mixed>                    $session   Session (may be empty).
	 * @param DestinationException            $error     Error.
	 * @param array<mixed>                    $upload    Per destination results (updated).
	 * @return string ROW_DONE.
	 */
	private function give_up( JobContext $context, $dest_id, array $row, array $instances, array $session, DestinationException $error, array &$upload ) {
		if ( array() !== $session && isset( $instances[ $dest_id ] ) ) {
			$instances[ $dest_id ]->abort_upload( $session );
		}
		$this->save( (int) $row['id'], 'failed', array( 'upload_session' => '' ) );

		// A destination that cannot be reached at all (credentials, type) fails every file at once.
		if ( ! isset( $instances[ $dest_id ] ) ) {
			foreach ( $this->files->for_backup_and_destination( $context->backup_id(), $dest_id ) as $other ) {
				if ( 'done' !== $other['upload_status'] ) {
					$this->save( (int) $other['id'], 'failed', array( 'upload_session' => '' ) );
				}
			}
		}

		$upload[ $dest_id ] = isset( $upload[ $dest_id ] ) && is_array( $upload[ $dest_id ] ) ? $upload[ $dest_id ] : array();
		if ( empty( $upload[ $dest_id ]['e'] ) ) {
			$upload[ $dest_id ]['e'] = UploadPlan::short_error( $error->getMessage() );
		}
		$context->log(
			Logger::ERROR,
			__( 'An upload failed.', 'openvault-backup' ),
			array(
				'destination' => $dest_id,
				'file'        => $row['file_name'],
				'reason'      => $error->reason(),
				'error'       => $error->getMessage(),
			)
		);
		return self::ROW_DONE;
	}

	/**
	 * All destinations are finished: record the result.
	 *
	 * @param JobContext   $context Context.
	 * @param int[]        $ids     Destination ids.
	 * @param array<mixed> $meta    Job meta.
	 * @param array<mixed> $upload  Per destination results.
	 * @return StepResult
	 */
	private function finish( JobContext $context, array $ids, array $meta, array $upload ) {
		$states = array();
		foreach ( $ids as $id ) {
			$states[ $id ] = isset( $upload[ $id ]['s'] ) ? $upload[ $id ]['s'] : UploadPlan::STATE_FAILED;
		}
		$result = UploadPlan::final_result( $states );

		$meta['upload'] = $upload;
		if ( 'partial' === $result ) {
			$meta['result'] = 'partial';
		}
		$context->log(
			'complete' === $result ? Logger::INFO : Logger::WARNING,
			'complete' === $result
				? __( 'Every destination received the backup.', 'openvault-backup' )
				: __( 'At least one destination did not receive the whole backup. The local copy is complete.', 'openvault-backup' ),
			array(
				'destinations' => count( $ids ),
				'failed'       => count(
					array_filter(
						$states,
						static function ( $s ) {
							return UploadPlan::STATE_DONE !== $s; }
					)
				),
			)
		);
		return StepResult::next_step( $context->state()->with_cursor( array() )->with_meta( $meta ) );
	}

	/**
	 * State carrying the cursor and the results so far.
	 *
	 * @param JobState     $state  State at the start of the run.
	 * @param array<mixed> $meta   Meta at the start of the run.
	 * @param array<mixed> $upload Per destination results.
	 * @param array<mixed> $run    Cursor values.
	 * @return JobState
	 */
	private function state_with( JobState $state, array $meta, array $upload, array $run ) {
		$meta['upload'] = $upload;
		return $state->with_cursor( $run )->with_meta( $meta );
	}

	/**
	 * Build the provider of a destination.
	 *
	 * @param int $dest_id Destination id.
	 * @return DestinationInterface
	 * @throws DestinationException When it no longer exists, its credentials cannot be read or its type is unavailable.
	 */
	private function make( $dest_id ) {
		if ( null === $this->destinations ) {
			$this->destinations = new DestinationsRepository();
		}
		if ( null === $this->registry ) {
			$this->registry = new DestinationRegistry();
		}
		$row = $this->destinations->find( $dest_id );
		if ( null === $row ) {
			throw DestinationException::permanent( __( 'The destination no longer exists.', 'openvault-backup' ) );
		}
		return $this->registry->create( $row );
	}

	/**
	 * Save a row's upload state.
	 *
	 * @param int                 $row_id Row id.
	 * @param string              $status Status.
	 * @param array<string,mixed> $extra  remote_ref, upload_session.
	 * @return void
	 */
	private function save( $row_id, $status, array $extra ) {
		$this->files->update_upload( $row_id, $status, $extra );
	}

	/**
	 * Session as JSON for the database (65,535 bytes at most).
	 *
	 * @param array<mixed> $session Session.
	 * @return string
	 * @throws DestinationException When it cannot be stored.
	 */
	private function encode( array $session ) {
		$json = wp_json_encode( $session );
		if ( ! is_string( $json ) || strlen( $json ) > 60000 ) {
			throw DestinationException::permanent( __( 'The upload session is too large to store.', 'openvault-backup' ) );
		}
		return $json;
	}
}
