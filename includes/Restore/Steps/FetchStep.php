<?php
/**
 * Makes sure every file the restore needs is on this server (resumable download).
 *
 * Phase: MVP
 *
 * Purpose: Step "fetch" of a restore job.
 *
 * Responsibilities:
 *  - Local source (the plan's chosen source is the local backup folder): nothing is copied. The files are read and verified in
 *    place, so the step finishes at once.
 *  - Destination source: manifest.json and every part the mode needs are downloaded through DestinationInterface into
 *    <local dir>/tmp/restore-<id>/ as "<name>.part" files, then renamed to "<name>" when complete. A file that is already there with
 *    the right size is skipped. The sink writes to disk chunk by chunk (1 MiB for Local, 8 MiB for S3), never a whole file in memory.
 *  - Resume: the cursor holds the file index and the number of bytes written. A new slice truncates the ".part" file to that
 *    offset (bytes written after the last saved cursor by a killed run are discarded) and continues there: destinations that
 *    implement ResumableDownloadInterface read from the offset; for the others the first bytes are read and skipped.
 *  - Budget: the sink stops the download (returns false) when the Budget is exhausted; the step returns continue_step().
 *  - Errors: a retryable DestinationException is retried 3 times with the upload backoff (StepResult::wait()); a permanent one, an
 *    exhausted retry or a file that ends with the wrong size makes the source "tried": the job continues with the next complete
 *    source (once per source) or fails with a clear message.
 *
 * Cursor (tiny): i (index in the plan's file list), off (bytes written of that file), tries (failed attempts of that file),
 * n (slices saved; always changes so a resumed step is seen to move).
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings or scrubbed errors; they are stored and shown through escaping output functions, never echoed as HTML here.

namespace OpenVault\Backup\Restore\Steps;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\DestinationInterface;
use OpenVault\Backup\Destinations\ResumableDownloadInterface;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Engine\Steps\UploadPlan;
use OpenVault\Backup\Restore\DestinationFactory;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Restore\RestoreWorkDir;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch step.
 */
class FetchStep extends AbstractRestoreStep {

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_FETCH;
	}

	/**
	 * Resume point of a file: never past what the cursor saved, what is really on disk, or the expected size.
	 *
	 * @param int $saved    Offset saved in the cursor.
	 * @param int $on_disk  Size of the ".part" file on disk (0 when missing).
	 * @param int $expected Final size of the file.
	 * @return int
	 */
	public static function resume_offset( $saved, $on_disk, $expected ) {
		return max( 0, min( (int) $saved, (int) $on_disk, (int) $expected ) );
	}

	/**
	 * Whether a finished copy is already in place.
	 *
	 * @param string $path     Final path.
	 * @param int    $expected Expected size.
	 * @return bool
	 */
	public static function is_complete( $path, $expected ) {
		clearstatcache( true, $path );
		return ! is_link( $path ) && is_file( $path ) && filesize( $path ) === (int) $expected;
	}

	/**
	 * Download as much as the budget allows.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws \RuntimeException When the restore work folder is missing (the runner retries and then fails the job).
	 */
	public function run( JobContext $context ) {
		$state  = $context->state();
		$loaded = $this->load_plan( $context );
		$plan   = $loaded['plan'];
		if ( ! $plan instanceof RestorePlan ) {
			return StepResult::fail( $loaded['error'], $state );
		}
		if ( null === $plan->chosen_source() ) {
			return StepResult::fail( __( 'No complete copy of this backup is available any more.', 'openvault-backup' ), $state );
		}
		if ( $plan->is_local() ) {
			$context->log( Logger::INFO, __( 'The local copy of the backup is used in place, nothing to download.', 'openvault-backup' ) );
			return StepResult::next_step( $state->with_cursor( array() ) );
		}

		$dir = $context->work_dir();
		if ( ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'The restore work folder is missing.' );
		}

		$files  = $plan->files();
		$cursor = $state->get_cursor();
		$run    = array(
			'i'     => isset( $cursor['i'] ) ? (int) $cursor['i'] : 0,
			'off'   => isset( $cursor['off'] ) ? (int) $cursor['off'] : 0,
			'tries' => isset( $cursor['tries'] ) ? (int) $cursor['tries'] : 0,
			'n'     => isset( $cursor['n'] ) ? (int) $cursor['n'] : 0,
		);

		$destination = null;
		$worked      = false;
		$count       = count( $files );
		while ( $run['i'] < $count ) {
			if ( $worked && $context->budget()->exhausted() ) {
				return StepResult::continue_step( $state->with_cursor( $this->next_cursor( $run ) ) );
			}

			$file   = $files[ $run['i'] ];
			$target = RestoreWorkDir::join( $dir, $file['name'] );
			if ( self::is_complete( $target, $file['size'] ) ) {
				$this->advance( $run );
				continue;
			}
			$worked = true;

			try {
				if ( null === $destination ) {
					$destination = $this->destination( (int) $plan->chosen_source() );
				}
				$outcome = $this->download_file( $context, $destination, $file, $target, $run );
			} catch ( DestinationException $e ) {
				return $this->after_error( $context, $plan, $state, $file, $run, $e );
			}

			if ( 'budget' === $outcome ) {
				return StepResult::continue_step( $state->with_cursor( $this->next_cursor( $run ) ) );
			}
			$this->advance( $run );
		}//end while

		$context->log(
			Logger::INFO,
			__( 'The backup was downloaded.', 'openvault-backup' ),
			array(
				'files' => $count,
				'bytes' => $plan->download_bytes(),
			)
		);
		return StepResult::next_step( $state->with_cursor( array() ) );
	}

	/**
	 * Download one file as far as the budget allows.
	 *
	 * @param JobContext           $context     Context.
	 * @param DestinationInterface $destination Provider.
	 * @param array<string,mixed>  $file        File entry of the plan.
	 * @param string               $target       Final path.
	 * @param array<string,int>    $run         Cursor values (updated).
	 * @return string "done" or "budget".
	 * @throws DestinationException When the download fails or the file ends with the wrong size.
	 */
	private function download_file( JobContext $context, DestinationInterface $destination, array $file, $target, array &$run ) {
		$part = $target . '.part';
		clearstatcache( true, $part );
		$offset = self::resume_offset( $run['off'], is_file( $part ) ? (int) filesize( $part ) : 0, $file['size'] );

		$handle = @fopen( $part, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Partial download in the plugin work folder.
		if ( false === $handle ) {
			throw DestinationException::permanent( __( 'The download folder is not writable. Check the permissions of the wp-content folder.', 'openvault-backup' ) );
		}

		$written = 0;
		$stopped = false;
		try {
			// Bytes after the saved offset belong to a run that was killed before it saved its cursor.
			if ( ! ftruncate( $handle, $offset ) || 0 !== fseek( $handle, $offset ) ) {
				throw DestinationException::permanent( __( 'The partial download could not be resumed.', 'openvault-backup' ) );
			}

			$skip   = $destination instanceof ResumableDownloadInterface ? 0 : $offset;
			$budget = $context->budget();
			$sink   = static function ( $chunk ) use ( &$written, &$stopped, &$skip, $handle, $budget ) {
				if ( $skip > 0 ) {
					$length = strlen( $chunk );
					if ( $length <= $skip ) {
						$skip -= $length;
						return true;
					}
					$chunk = (string) substr( $chunk, $skip );
					$skip  = 0;
				}
				$length = strlen( $chunk );
				if ( fwrite( $handle, $chunk ) !== $length ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Partial download in the plugin work folder.
					throw DestinationException::permanent( __( 'The download could not be written to disk. The disk may be full.', 'openvault-backup' ) );
				}
				$written += $length;
				if ( $budget->exhausted() ) {
					$stopped = true;
					return false;
				}
				return true;
			};

			if ( $destination instanceof ResumableDownloadInterface ) {
				$destination->download_from( (string) $file['ref'], $offset, $sink );
			} else {
				$destination->download( (string) $file['ref'], $sink );
			}
			fflush( $handle );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Partial download in the plugin work folder.
			// Whatever reached the disk in order is kept for the next attempt.
			clearstatcache( true, $part );
			$run['off'] = is_file( $part ) ? (int) filesize( $part ) : 0;
		}//end try

		$size = $run['off'];

		if ( $stopped && $size < (int) $file['size'] ) {
			$run['off']   = $size;
			$run['tries'] = 0;
			return 'budget';
		}

		if ( $size !== (int) $file['size'] ) {
			// A file that ends too short is retried from where it stopped; one that is too long cannot be trusted at all.
			if ( $size > (int) $file['size'] ) {
				@unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Partial download in the plugin work folder.
				$run['off'] = 0;
			} else {
				$run['off'] = $size;
			}
			throw DestinationException::transient( __( 'The download ended with the wrong size.', 'openvault-backup' ) );
		}

		if ( ! rename( $part, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same folder in the plugin work folder.
			throw DestinationException::permanent( __( 'The downloaded file could not be moved into place.', 'openvault-backup' ) );
		}
		$context->log(
			Logger::INFO,
			__( 'File downloaded.', 'openvault-backup' ),
			array(
				'file' => $file['name'],
				'size' => (int) $file['size'],
			)
		);
		return 'done';
	}

	/**
	 * A download failed: retry with backoff, or give up on this source.
	 *
	 * @param JobContext           $context Context.
	 * @param RestorePlan          $plan    Plan.
	 * @param JobState             $state   State at the start of the slice.
	 * @param array<string,mixed>  $file    File entry.
	 * @param array<string,int>    $run     Cursor values.
	 * @param DestinationException $error   Error.
	 * @return StepResult
	 */
	private function after_error( JobContext $context, RestorePlan $plan, JobState $state, array $file, array $run, DestinationException $error ) {
		$tries = $run['tries'] + 1;
		if ( $error->retryable() && UploadPlan::may_retry( $tries ) ) {
			$run['tries'] = $tries;
			$context->log(
				Logger::WARNING,
				__( 'A download failed and will be retried.', 'openvault-backup' ),
				array(
					'file'     => $file['name'],
					'attempt'  => $tries,
					'retry_in' => UploadPlan::backoff( $tries ),
					'error'    => $error->getMessage(),
				)
			);
			return StepResult::wait( UploadPlan::backoff( $tries ), $state->with_cursor( $this->next_cursor( $run ) ) );
		}

		return $this->switch_source(
			$context,
			$plan,
			sprintf(
				/* translators: 1: file name, 2: error message. */
				__( 'The file %1$s could not be downloaded: %2$s', 'openvault-backup' ),
				$file['name'],
				UploadPlan::short_error( $error->getMessage() )
			),
			__( 'No other copy of this backup is left to try. Check the destination, then start the restore again.', 'openvault-backup' )
		);
	}

	/**
	 * Move the cursor to the next file.
	 *
	 * @param array<string,int> $run Cursor values (updated).
	 * @return void
	 */
	private function advance( array &$run ) {
		++$run['i'];
		$run['off']   = 0;
		$run['tries'] = 0;
	}

	/**
	 * Cursor to save (n always changes, so the runner sees a slice that moved).
	 *
	 * @param array<string,int> $run Cursor values.
	 * @return array<string,int>
	 */
	private function next_cursor( array $run ) {
		++$run['n'];
		return $run;
	}

	/**
	 * Provider of the chosen destination.
	 *
	 * @param int $destination_id Destination id.
	 * @return DestinationInterface
	 * @throws DestinationException When it cannot be built.
	 */
	private function destination( $destination_id ) {
		if ( null === $this->destinations ) {
			$this->destinations = array( new DestinationFactory(), 'make' );
		}
		return call_user_func( $this->destinations, $destination_id );
	}
}
