<?php
/**
 * Token of the unauthenticated restore status route (pure logic, no WordPress needed).
 *
 * Phase: MVP
 *
 * Purpose: A database restore replaces the users and sessions tables, so the administrator's cookie and REST nonce can stop working in the
 * middle of the restore. GET /restores/{id}/status?t=<token> lets the progress page keep showing coarse progress anyway. The token is
 * 128 random bits, shown to the browser once (the response that starts the job), and only its SHA-256 is stored, in the meta of that job
 * (the ovb_restores table survives the import). It belongs to one restore id, is compared with hash_equals() and stops working one hour
 * after the job finished. Every mismatch (no job, no token, wrong token, expired, route disabled) is answered the same way: 404.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Status token.
 */
final class RestoreStatusToken {

	/**
	 * Seconds after the end of the job during which the token still works.
	 */
	const TTL_AFTER_FINISH = 3600;

	/**
	 * A new token: 32 lower-case hex characters (128 bits).
	 *
	 * @return string
	 * @throws \Exception When the system has no secure random source.
	 */
	public static function generate() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * The stored form of a token.
	 *
	 * @param string $token Token.
	 * @return string 64 hex characters.
	 */
	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Whether a value has the shape of a token.
	 *
	 * @param mixed $token Value.
	 * @return bool
	 */
	public static function well_formed( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{32}\z/', $token );
	}

	/**
	 * Whether a presented token opens the status of a job. Pure.
	 *
	 * @param string $stored_hash   Stored hash (RestoreMeta::status_token_hash()), '' when the job has none.
	 * @param mixed  $given         Token from the request.
	 * @param int    $finished_at   Unix time the job finished, 0 while it is not finished.
	 * @param int    $now           Unix time.
	 * @return bool
	 */
	public static function check( $stored_hash, $given, $finished_at, $now ) {
		// The hash is always computed so a missing job costs the same as a wrong token.
		$candidate = self::hash( is_string( $given ) ? $given : '' );
		$known     = is_string( $stored_hash ) && 1 === preg_match( '/^[a-f0-9]{64}\z/', $stored_hash );
		$formed    = self::well_formed( $given );
		$equal     = hash_equals( $known ? $stored_hash : str_repeat( '0', 64 ), $candidate );
		if ( ! $known || ! $formed || ! $equal ) {
			return false;
		}
		return $finished_at < 1 || ( (int) $now - (int) $finished_at ) <= self::TTL_AFTER_FINISH;
	}
}
