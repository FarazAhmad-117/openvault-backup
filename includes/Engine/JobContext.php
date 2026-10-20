<?php
/**
 * Everything a step needs for one run.
 *
 * Phase: MVP
 *
 * Purpose: Read-only bundle handed to StepInterface::run(): the backup row, the current JobState,
 * the Budget, a Logger bound to the backup, the work directory and the global settings.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Context of one step run.
 */
final class JobContext {

	/**
	 * Backup row.
	 *
	 * @var array<string,mixed>
	 */
	private $backup;

	/**
	 * Current state.
	 *
	 * @var JobState
	 */
	private $state;

	/**
	 * Budget.
	 *
	 * @var Budget
	 */
	private $budget;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Work directory (<local_dir>/tmp/<uuid>).
	 *
	 * @var string
	 */
	private $work_dir;

	/**
	 * Global settings (ovb_settings).
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $backup   Backup row (BackupsRepository::find()).
	 * @param JobState            $state    Current state.
	 * @param Budget              $budget   Budget of this run.
	 * @param Logger              $logger   Logger.
	 * @param string              $work_dir Absolute work directory (no trailing slash needed).
	 * @param array<string,mixed> $settings Global settings.
	 */
	public function __construct( array $backup, JobState $state, Budget $budget, Logger $logger, $work_dir, array $settings = array() ) {
		$this->backup   = $backup;
		$this->state    = $state;
		$this->budget   = $budget;
		$this->logger   = $logger;
		$this->work_dir = rtrim( (string) $work_dir, '/\\' );
		$this->settings = $settings;
	}

	/**
	 * Backup row.
	 *
	 * @return array<string,mixed>
	 */
	public function backup() {
		return $this->backup;
	}

	/**
	 * Backup id.
	 *
	 * @return int
	 */
	public function backup_id() {
		return isset( $this->backup['id'] ) ? (int) $this->backup['id'] : 0;
	}

	/**
	 * Current state.
	 *
	 * @return JobState
	 */
	public function state() {
		return $this->state;
	}

	/**
	 * Budget.
	 *
	 * @return Budget
	 */
	public function budget() {
		return $this->budget;
	}

	/**
	 * Logger.
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * Work directory without trailing slash.
	 *
	 * @return string
	 */
	public function work_dir() {
		return $this->work_dir;
	}

	/**
	 * Global settings.
	 *
	 * @return array<string,mixed>
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $key           Key.
	 * @param mixed  $default_value Value when missing.
	 * @return mixed
	 */
	public function setting( $key, $default_value = null ) {
		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $default_value;
	}

	/**
	 * Log a line for this backup (secrets in the context are masked by the Logger).
	 *
	 * @param string              $level   debug, info, warning or error.
	 * @param string              $message Message (no secrets).
	 * @param array<string,mixed> $context Extra data.
	 * @return bool
	 */
	public function log( $level, $message, array $context = array() ) {
		return $this->logger->log( $level, $message, $context, $this->backup_id() );
	}
}
