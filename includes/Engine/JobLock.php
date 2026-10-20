<?php
/**
 * Per-backup mutex.
 *
 * Phase: MVP
 *
 * Purpose: Make sure two PHP processes never run the same backup job at once.
 *
 * Design:
 *  - The lock is the row "ovb_lock_<backup id>" with the value "<unix time>:<owner token>".
 *  - Acquiring is an atomic INSERT (unique option_name), so exactly one caller wins.
 *  - A lock older than the TTL is stale (its owner died, e.g. PHP timeout). Takeover deletes the stale value
 *    with a conditional DELETE (only succeeds while the row still holds that exact value) and then inserts
 *    again, so with several callers exactly one takes over.
 *  - refresh() and release() are conditional on the owner token, so a process that lost its lock can
 *    never extend or delete someone else's lock.
 *  - The clock is injectable for unit tests.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Job lock.
 */
final class JobLock {

	const OPTION_PREFIX = 'ovb_lock_';

	/**
	 * Backup id.
	 *
	 * @var int
	 */
	private $backup_id;

	/**
	 * Storage.
	 *
	 * @var LockStore
	 */
	private $store;

	/**
	 * Clock returning unix time (null = time()).
	 *
	 * @var callable|null
	 */
	private $now;

	/**
	 * Owner token of this instance.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * The value this instance currently holds ('' when not holding the lock).
	 *
	 * @var string
	 */
	private $held = '';

	/**
	 * Constructor.
	 *
	 * @param int            $backup_id Backup id.
	 * @param LockStore|null $store     Storage (defaults to the options table).
	 * @param callable|null  $now       Returns unix time. Defaults to time().
	 */
	public function __construct( int $backup_id, ?LockStore $store = null, ?callable $now = null ) {
		$this->backup_id = $backup_id;
		$this->store     = null === $store ? new OptionsLockStore() : $store;
		$this->now       = $now;
		$this->token     = bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Option name of a backup's lock.
	 *
	 * @param int $backup_id Backup id.
	 * @return string
	 */
	public static function option_name( $backup_id ) {
		return self::OPTION_PREFIX . (int) $backup_id;
	}

	/**
	 * Split a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return array{time:int,token:string} Time 0 when the value is missing or malformed.
	 */
	public static function parse( $value ) {
		if ( is_string( $value ) && 1 === preg_match( '/^(\d{1,12}):([0-9a-f]{16})$/', $value, $found ) ) {
			return array(
				'time'  => (int) $found[1],
				'token' => $found[2],
			);
		}
		return array(
			'time'  => 0,
			'token' => '',
		);
	}

	/**
	 * Whether a stored value is older than the TTL. Malformed values count as stale.
	 *
	 * @param string|null $value Stored value.
	 * @param int         $now   Unix time.
	 * @param int         $ttl   Seconds.
	 * @return bool
	 */
	public static function is_stale( $value, $now, $ttl ) {
		$parsed = self::parse( $value );
		return ( (int) $now - $parsed['time'] ) >= (int) $ttl;
	}

	/**
	 * Try to take the lock.
	 *
	 * @param int $ttl Seconds after which an existing lock counts as abandoned.
	 * @return bool True when this instance now holds the lock.
	 */
	public function acquire( $ttl ) {
		$name  = self::option_name( $this->backup_id );
		$value = $this->make_value();

		if ( $this->store->insert( $name, $value ) ) {
			$this->held = $value;
			return true;
		}

		$existing = $this->store->read( $name );
		if ( null === $existing ) {
			// Released between our insert and our read: one more try.
			return $this->claim( $name, $value );
		}

		if ( ! self::is_stale( $existing, $this->time(), (int) $ttl ) ) {
			return false;
		}

		// Stale: only the caller whose conditional delete succeeds may continue.
		if ( ! $this->store->delete_if( $name, $existing ) ) {
			return false;
		}
		return $this->claim( $name, $value );
	}

	/**
	 * Extend the lock (proof of life). Fails when this instance no longer owns it.
	 *
	 * @return bool
	 */
	public function refresh() {
		if ( '' === $this->held ) {
			return false;
		}
		$name = self::option_name( $this->backup_id );
		$new  = $this->make_value();
		if ( $new === $this->held ) {
			// Same second: an UPDATE would change nothing (MySQL reports 0 rows), so just verify ownership.
			if ( $this->store->read( $name ) === $this->held ) {
				return true;
			}
			$this->held = '';
			return false;
		}
		if ( $this->store->update_if( $name, $this->held, $new ) ) {
			$this->held = $new;
			return true;
		}
		$this->held = '';
		return false;
	}

	/**
	 * Give the lock back. Only deletes the lock if this instance still owns it.
	 *
	 * @return bool True when the lock was removed by this call.
	 */
	public function release() {
		if ( '' === $this->held ) {
			return false;
		}
		$released   = $this->store->delete_if( self::option_name( $this->backup_id ), $this->held );
		$this->held = '';
		return $released;
	}

	/**
	 * Whether this instance holds the lock.
	 *
	 * @return bool
	 */
	public function is_held() {
		return '' !== $this->held;
	}

	/**
	 * Whether somebody holds a lock that is not stale (does not acquire anything).
	 *
	 * @param int $ttl Seconds.
	 * @return bool
	 */
	public function is_locked( $ttl ) {
		$existing = $this->store->read( self::option_name( $this->backup_id ) );
		return null !== $existing && ! self::is_stale( $existing, $this->time(), (int) $ttl );
	}

	/**
	 * Insert after a failed read or a successful stale delete.
	 *
	 * @param string $name  Name.
	 * @param string $value Value.
	 * @return bool
	 */
	private function claim( $name, $value ) {
		if ( $this->store->insert( $name, $value ) ) {
			$this->held = $value;
			return true;
		}
		return false;
	}

	/**
	 * Value for this owner at the current time.
	 *
	 * @return string
	 */
	private function make_value() {
		return $this->time() . ':' . $this->token;
	}

	/**
	 * Unix time.
	 *
	 * @return int
	 */
	private function time() {
		return null === $this->now ? time() : (int) call_user_func( $this->now );
	}
}
