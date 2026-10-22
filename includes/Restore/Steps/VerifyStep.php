<?php
/**
 * Checks every file of the restore against the manifest and the database (size and SHA-256).
 *
 * Phase: MVP
 *
 * Purpose: Step "verify" of a restore job. Nothing is applied before everything passed.
 *
 * Responsibilities:
 *  - The files are the plan's files for the chosen source: manifest.json and the parts the mode needs. They are read in place when the
 *    source is the local backup folder and from the restore work folder otherwise. A link or a missing file is a damaged copy.
 *  - Size must equal the recorded size. A part's SHA-256 must equal the manifest's (and the database row's, when it has one).
 *  - manifest.json must be valid (Manifest::from_json(), strict), describe the same uuid as the backup row, and match the checksum
 *    stored in its database row.
 *  - Hashing: a part is hashed in one go with hash_file() (PHP 7.4 cannot save a hash context, so a part cannot be hashed half and
 *    resumed). The budget is checked between files, so a slice may run over by the time one part takes to read (part size is capped by
 *    the "part size" setting, 100 MB by default; about 0.3 s per 100 MB of disk read at 300 MB/s). Cursor: i (next file), n.
 *  - A damaged file makes the source "tried": the job returns to "fetch" with the next complete source (once per source), or fails
 *    naming the part and what to do. Downloaded files of the damaged source are deleted.
 *  - Success: meta "verified" = { source, files, bytes, at } and the step ends; the runner then waits for the user's confirmation.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore\Steps;

use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Restore\RestoreWorkDir;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Verify step.
 */
class VerifyStep extends AbstractRestoreStep {

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_VERIFY;
	}

	/**
	 * Check one file against what is recorded. Pure apart from reading the file.
	 *
	 * @param array<string,mixed> $file Plan file entry: name, size, sha256, kind, row_sha256.
	 * @param string              $path File to check.
	 * @param string              $uuid Uuid of the backup row.
	 * @return string '' when the file is good, otherwise what is wrong (no paths).
	 */
	public static function check_file( array $file, $path, $uuid ) {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return __( 'the file is missing', 'openvault-backup' );
		}
		if ( filesize( $path ) !== (int) $file['size'] ) {
			return __( 'the file has the wrong size', 'openvault-backup' );
		}

		if ( 'manifest' === $file['kind'] ) {
			$json   = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin; its size was checked against the manifest limit by PlanLoader.
			$parsed = false === $json ? array( 'manifest' => null ) : PlanLoader::parse( $json, $uuid );
			if ( null === $parsed['manifest'] ) {
				return __( 'the manifest is not valid', 'openvault-backup' );
			}
			if ( '' !== $file['sha256'] && hash( 'sha256', (string) $json ) !== $file['sha256'] ) {
				return __( 'the manifest changed after the backup was made', 'openvault-backup' );
			}
			return '';
		}

		if ( '' !== $file['row_sha256'] && $file['row_sha256'] !== $file['sha256'] ) {
			return __( 'the recorded checksums disagree', 'openvault-backup' );
		}
		if ( hash_file( 'sha256', $path ) !== $file['sha256'] ) {
			return __( 'the checksum does not match', 'openvault-backup' );
		}
		return '';
	}

	/**
	 * Verify as many files as the budget allows.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$state  = $context->state();
		$loaded = $this->load_plan( $context );
		$plan   = $loaded['plan'];
		if ( ! $plan instanceof RestorePlan ) {
			return StepResult::fail( $loaded['error'], $state );
		}
		$source = $plan->chosen_source();
		if ( null === $source ) {
			return StepResult::fail( __( 'No complete copy of this backup is available any more.', 'openvault-backup' ), $state );
		}

		$dir = $plan->is_local() ? $this->loader->local_folder( $context->backup() ) : $context->work_dir();
		if ( null === $dir ) {
			return $this->damaged( $context, $plan, __( 'The local copy of the backup is gone.', 'openvault-backup' ) );
		}

		$files  = $plan->files();
		$cursor = $state->get_cursor();
		$index  = isset( $cursor['i'] ) ? (int) $cursor['i'] : 0;
		$n      = isset( $cursor['n'] ) ? (int) $cursor['n'] : 0;
		$uuid   = (string) $context->backup()['uuid'];
		$worked = false;
		$count  = count( $files );

		while ( $index < $count ) {
			if ( $worked && $context->budget()->exhausted() ) {
				return StepResult::continue_step(
					$state->with_cursor(
						array(
							'i' => $index,
							'n' => $n + 1,
						)
					)
				);
			}
			$worked = true;
			$file   = $files[ $index ];
			$why    = self::check_file( $file, RestoreWorkDir::join( $dir, $file['name'] ), $uuid );
			if ( '' !== $why ) {
				return $this->damaged(
					$context,
					$plan,
					sprintf(
						/* translators: 1: file name, 2: what is wrong. */
						__( 'The backup file %1$s is damaged: %2$s.', 'openvault-backup' ),
						$file['name'],
						$why
					)
				);
			}
			++$index;
		}//end while

		$meta             = $state->get_meta();
		$meta['verified'] = array(
			'source' => $source,
			'files'  => $count,
			'bytes'  => $plan->summary()['needed_bytes'],
			'at'     => time(),
		);
		$context->log(
			Logger::INFO,
			__( 'The backup was verified.', 'openvault-backup' ),
			array(
				'source' => $source,
				'files'  => $count,
			)
		);
		return StepResult::next_step( $state->with_cursor( array() )->with_meta( $meta ) );
	}

	/**
	 * A file of the chosen source is bad.
	 *
	 * @param JobContext  $context Context.
	 * @param RestorePlan $plan    Plan.
	 * @param string      $why     What is wrong.
	 * @return StepResult
	 */
	private function damaged( JobContext $context, RestorePlan $plan, $why ) {
		return $this->switch_source(
			$context,
			$plan,
			$why,
			__( 'No other complete copy is left. Start the restore again to download it again, or choose another backup.', 'openvault-backup' )
		);
	}
}
