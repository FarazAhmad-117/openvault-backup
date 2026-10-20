<?php
/**
 * Checks before a backup starts.
 *
 * Phase: MVP
 *
 * Purpose: Checks before a backup starts.
 *
 * Responsibilities:
 *  - Disk space estimate, writable temp dir, destinations reachable
 *  - Gathers facts with real probes and lets the pure PreflightChecks rules judge them
 *  - Fails (permanently, no retry) with an actionable message, e.g. "Not enough free disk space: 40.0 MB free, 100.0 MB needed."
 *
 * Destination reachability is not checked yet: destinations arrive with the upload step.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Steps;

use OpenVault\Backup\Archive\CapabilityDetector;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Preflight step.
 */
class PreflightStep implements StepInterface {

	/**
	 * Format detection.
	 *
	 * @var CapabilityDetector
	 */
	private $detector;

	/**
	 * Constructor.
	 *
	 * @param CapabilityDetector|null $detector Detector (defaults to the real server).
	 */
	public function __construct( ?CapabilityDetector $detector = null ) {
		$this->detector = null === $detector ? new CapabilityDetector() : $detector;
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
		$findings = PreflightChecks::evaluate( $this->gather_facts( $context ) );

		$level_map = array(
			PreflightChecks::ERROR   => Logger::ERROR,
			PreflightChecks::WARNING => Logger::WARNING,
			PreflightChecks::INFO    => Logger::INFO,
		);
		foreach ( $findings as $finding ) {
			$context->log( $level_map[ $finding['level'] ], $finding['message'], array( 'check' => $finding['code'] ) );
		}

		$errors = PreflightChecks::errors( $findings );
		if ( $errors ) {
			return StepResult::fail( implode( ' ', array_column( $errors, 'message' ) ), $context->state() );
		}

		$context->log( Logger::INFO, __( 'Preflight checks passed.', 'openvault-backup' ) );
		return StepResult::next_step( $context->state() );
	}

	/**
	 * Collect facts with real probes.
	 *
	 * @param JobContext $context Context.
	 * @return array<string,mixed>
	 */
	private function gather_facts( JobContext $context ) {
		$backup = $context->backup();
		$facts  = array(
			'min_free_bytes' => PreflightChecks::min_free_bytes( $context->settings() ),
			'contents'       => isset( $backup['contents'] ) && is_array( $backup['contents'] ) ? $backup['contents'] : array(),
		);

		$local_dir = '';
		try {
			$local_dir                   = Paths::local_dir();
			$facts['local_dir_error']    = '';
			$facts['local_dir_writable'] = wp_is_writable( $local_dir );
			$facts['work_dir_ok']        = $this->work_dir_writable( $context->work_dir() );
		} catch ( \RuntimeException $e ) {
			$facts['local_dir_error'] = $e->getMessage();
		}

		$facts['free_bytes'] = $this->free_bytes( '' !== $local_dir ? $local_dir : WP_CONTENT_DIR );

		if ( isset( $backup['format'] ) ) {
			$format                    = (string) $backup['format'];
			$facts['format']           = $format;
			$facts['format_available'] = $this->detector->is_available( $format );
			$facts['format_reason']    = $this->detector->reason_unavailable( $format );
		}

		return $facts;
	}

	/**
	 * Create the work folder and prove a file can be written in it.
	 *
	 * @param string $dir Work folder.
	 * @return bool
	 */
	private function work_dir_writable( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$probe   = $dir . '/.ovb-write-test';
		$written = file_put_contents( $probe, 'ok' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny write probe.
		if ( false === $written ) {
			return false;
		}
		wp_delete_file( $probe );
		return true;
	}

	/**
	 * Free disk space, null when the server cannot tell.
	 *
	 * @param string $dir Folder on the volume to check.
	 * @return int|null
	 */
	private function free_bytes( $dir ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fails on restricted hosts.
		return false === $free ? null : (int) $free;
	}
}
