<?php
/**
 * Time and memory budget of one job run.
 *
 * Phase: MVP
 *
 * Purpose: Tells a step when to stop and save its cursor (see docs/plan/04-backup-engine.md).
 *
 * Responsibilities:
 *  - Derive a safe time budget (70% of max_execution_time, 5..25 s) and memory ceiling (80% of memory_limit)
 *  - Pure: the clock and the memory probe are injectable so tests need neither real time nor real memory
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Budget of a single run.
 */
final class Budget {

	const MIN_SECONDS       = 5;
	const MAX_SECONDS       = 25;
	const UNLIMITED_SECONDS = 20;
	const TIME_SHARE        = 0.7;
	const MEMORY_SHARE      = 0.8;
	const UNLIMITED_MEMORY  = 268435456;
	// 256 MB.
	const FALLBACK_MEMORY = 134217728;
	// 128 MB, used when memory_limit cannot be read.
	const MIN_MEMORY_CEILING = 8388608;
	// 8 MB, never go below this.

	/**
	 * Seconds allowed.
	 *
	 * @var int
	 */
	private $seconds;

	/**
	 * Memory ceiling in bytes.
	 *
	 * @var int
	 */
	private $memory_ceiling;

	/**
	 * Clock returning seconds as float (null = microtime).
	 *
	 * @var callable|null
	 */
	private $now;

	/**
	 * Probe returning the bytes currently used (null = memory_get_usage).
	 *
	 * @var callable|null
	 */
	private $memory;

	/**
	 * Start time.
	 *
	 * @var float
	 */
	private $started;

	/**
	 * Constructor. The clock starts now.
	 *
	 * @param int           $seconds              Seconds this run may take.
	 * @param int           $memory_bytes_ceiling Stop when memory use reaches this many bytes.
	 * @param callable|null $now                  Returns the time in seconds (float). Defaults to microtime( true ).
	 * @param callable|null $memory               Returns bytes in use. Defaults to memory_get_usage( true ).
	 */
	public function __construct( int $seconds, int $memory_bytes_ceiling, ?callable $now = null, ?callable $memory = null ) {
		$this->seconds        = max( 0, $seconds );
		$this->memory_ceiling = max( 0, $memory_bytes_ceiling );
		$this->now            = $now;
		$this->memory         = $memory;
		$this->started        = $this->read_clock();
	}

	/**
	 * Budget for the current PHP environment.
	 *
	 * @return self
	 */
	public static function from_environment() {
		$max_time = (int) ini_get( 'max_execution_time' );
		$limit    = ini_get( 'memory_limit' );

		return new self(
			self::seconds_for( $max_time ),
			self::memory_ceiling_for( false === $limit ? '' : (string) $limit )
		);
	}

	/**
	 * Seconds to work for a given max_execution_time: 70% of it, at least 5, at most 25; 0 (unlimited) gives 20.
	 *
	 * @param int $max_execution_time Value of max_execution_time (0 = unlimited).
	 * @return int
	 */
	public static function seconds_for( $max_execution_time ) {
		$max_execution_time = (int) $max_execution_time;
		if ( $max_execution_time <= 0 ) {
			return self::UNLIMITED_SECONDS;
		}
		return (int) max( self::MIN_SECONDS, min( self::MAX_SECONDS, floor( $max_execution_time * self::TIME_SHARE ) ) );
	}

	/**
	 * Memory ceiling for a php.ini memory_limit string: 80% of it; -1 (unlimited) gives 256 MB; unreadable gives 80% of 128 MB.
	 *
	 * @param string $memory_limit Value such as "128M", "1G", "134217728" or "-1".
	 * @return int Bytes.
	 */
	public static function memory_ceiling_for( $memory_limit ) {
		$bytes = self::parse_bytes( $memory_limit );
		if ( -1 === $bytes ) {
			return self::UNLIMITED_MEMORY;
		}
		if ( $bytes <= 0 ) {
			$bytes = self::FALLBACK_MEMORY;
		}
		return (int) max( self::MIN_MEMORY_CEILING, floor( $bytes * self::MEMORY_SHARE ) );
	}

	/**
	 * Parse a php.ini shorthand size.
	 *
	 * @param mixed $value "128M", "2G", "512k", "1024", "-1".
	 * @return int Bytes, -1 for unlimited, 0 when it cannot be parsed.
	 */
	public static function parse_bytes( $value ) {
		if ( is_int( $value ) ) {
			return $value < 0 ? -1 : $value;
		}
		if ( ! is_string( $value ) ) {
			return 0;
		}
		$value = trim( $value );
		if ( '-1' === $value ) {
			return -1;
		}
		if ( 1 !== preg_match( '/^(\d+)\s*([kmg]?)b?$/i', $value, $found ) ) {
			return 0;
		}

		$bytes = (int) $found[1];
		switch ( strtolower( $found[2] ) ) {
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
		}
		return $bytes;
	}

	/**
	 * Seconds since the budget was created.
	 *
	 * @return float
	 */
	public function elapsed() {
		return max( 0.0, $this->read_clock() - $this->started );
	}

	/**
	 * Seconds that remain (never negative).
	 *
	 * @return float
	 */
	public function time_left() {
		return max( 0.0, (float) $this->seconds - $this->elapsed() );
	}

	/**
	 * Whether memory use is still below the ceiling.
	 *
	 * @return bool
	 */
	public function memory_ok() {
		$used = null === $this->memory ? memory_get_usage( true ) : (int) call_user_func( $this->memory );
		return $used < $this->memory_ceiling;
	}

	/**
	 * Whether the run must stop now (time is up or memory reached the ceiling).
	 *
	 * @return bool
	 */
	public function exhausted() {
		return $this->time_left() <= 0.0 || ! $this->memory_ok();
	}

	/**
	 * Seconds allowed in total.
	 *
	 * @return int
	 */
	public function seconds() {
		return $this->seconds;
	}

	/**
	 * Memory ceiling in bytes.
	 *
	 * @return int
	 */
	public function memory_ceiling() {
		return $this->memory_ceiling;
	}

	/**
	 * Read the clock.
	 *
	 * @return float
	 */
	private function read_clock() {
		return null === $this->now ? microtime( true ) : (float) call_user_func( $this->now );
	}
}
