<?php
/**
 * Structured job logging to ovb_logs.
 *
 * Phase: MVP
 *
 * Secrets are masked in the context before anything is stored. Never put tokens or
 * passwords in the message text itself.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Support;

use OpenVault\Backup\Database\LogsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
class Logger {

	const DEBUG   = 'debug';
	const INFO    = 'info';
	const WARNING = 'warning';
	const ERROR   = 'error';

	/**
	 * Level => severity.
	 */
	const LEVELS = array(
		self::DEBUG   => 100,
		self::INFO    => 200,
		self::WARNING => 300,
		self::ERROR   => 400,
	);

	const REDACTED = '[redacted]';

	/**
	 * Context keys that hold secrets.
	 */
	const SENSITIVE_KEY = '/password|passwd|passphrase|secret|token|authorization|api[_-]?key|access[_-]?key|private[_-]?key|credential|bearer|signature|cookie/i';

	/**
	 * Storage.
	 *
	 * @var LogsRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param LogsRepository|null $repository Storage (defaults to the DB table).
	 */
	public function __construct( ?LogsRepository $repository = null ) {
		$this->repository = $repository ? $repository : new LogsRepository();
	}

	/**
	 * Map any input to a known level slug (unknown becomes info).
	 *
	 * @param mixed $level Level.
	 * @return string
	 */
	public static function normalize_level( $level ) {
		$level = is_string( $level ) ? strtolower( $level ) : '';
		return isset( self::LEVELS[ $level ] ) ? $level : self::INFO;
	}

	/**
	 * Whether a line at $level passes the minimum level.
	 *
	 * @param string $level   Line level.
	 * @param string $minimum Minimum level.
	 * @return bool
	 */
	public static function should_log( $level, $minimum ) {
		return self::LEVELS[ self::normalize_level( $level ) ] >= self::LEVELS[ self::normalize_level( $minimum ) ];
	}

	/**
	 * Mask secrets, recursively. Objects are replaced by their class name.
	 *
	 * @param array<string|int,mixed> $context Context.
	 * @param int                     $depth   Current depth.
	 * @return array<string|int,mixed>
	 */
	public static function redact( array $context, $depth = 0 ) {
		$out = array();
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SENSITIVE_KEY, $key ) ) {
				$out[ $key ] = self::REDACTED;
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = $depth >= 5 ? '[max depth]' : self::redact( $value, $depth + 1 );
			} elseif ( is_object( $value ) ) {
				$out[ $key ] = '[object ' . get_class( $value ) . ']';
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Write a log line.
	 *
	 * @param string              $level     Level.
	 * @param string              $message   Message.
	 * @param array<string,mixed> $context   Extra data (secrets are masked).
	 * @param int|null            $backup_id Related backup.
	 * @return bool Whether a line was stored.
	 */
	public function log( $level, $message, array $context = array(), $backup_id = null ) {
		$level = self::normalize_level( $level );

		/**
		 * Filters the minimum level that is stored.
		 *
		 * @param string $minimum One of debug, info, warning, error.
		 */
		$minimum = (string) apply_filters( 'ovb_log_min_level', self::INFO );
		if ( ! self::should_log( $level, $minimum ) ) {
			return false;
		}

		return $this->repository->insert( $level, (string) $message, self::redact( $context ), $backup_id ) > 0;
	}

	/**
	 * Debug line.
	 *
	 * @param string              $message   Message.
	 * @param array<string,mixed> $context   Context.
	 * @param int|null            $backup_id Backup id.
	 * @return bool
	 */
	public function debug( $message, array $context = array(), $backup_id = null ) {
		return $this->log( self::DEBUG, $message, $context, $backup_id );
	}

	/**
	 * Info line.
	 *
	 * @param string              $message   Message.
	 * @param array<string,mixed> $context   Context.
	 * @param int|null            $backup_id Backup id.
	 * @return bool
	 */
	public function info( $message, array $context = array(), $backup_id = null ) {
		return $this->log( self::INFO, $message, $context, $backup_id );
	}

	/**
	 * Warning line.
	 *
	 * @param string              $message   Message.
	 * @param array<string,mixed> $context   Context.
	 * @param int|null            $backup_id Backup id.
	 * @return bool
	 */
	public function warning( $message, array $context = array(), $backup_id = null ) {
		return $this->log( self::WARNING, $message, $context, $backup_id );
	}

	/**
	 * Error line.
	 *
	 * @param string              $message   Message.
	 * @param array<string,mixed> $context   Context.
	 * @param int|null            $backup_id Backup id.
	 * @return bool
	 */
	public function error( $message, array $context = array(), $backup_id = null ) {
		return $this->log( self::ERROR, $message, $context, $backup_id );
	}
}
