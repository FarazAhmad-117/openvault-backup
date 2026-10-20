<?php
/**
 * CRUD for {prefix}ovb_destinations.
 *
 * Phase: MVP
 *
 * Purpose: CRUD for {prefix}ovb_destinations.
 *
 * Responsibilities:
 *  - Credentials always passed through Security\CredentialVault
 *  - Connection test status / last error
 *
 * Secrets: credentials are written only as a CredentialVault envelope. Normal reads (find, all, by_type)
 * never select the credentials column; they are exposed only by get_credentials(). Error messages stored
 * in last_error are scrubbed of anything that looks like a token.
 *
 * Status values: new (never tested), connected, error (last test failed), needs_reconnect (stored
 * credentials cannot be read with the current encryption key).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Database;

use OpenVault\Backup\Security\CredentialVault;
use OpenVault\Backup\Security\VaultException;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for destinations.
 */
class DestinationsRepository {

	const STATUS_NEW             = 'new';
	const STATUS_CONNECTED       = 'connected';
	const STATUS_ERROR           = 'error';
	const STATUS_NEEDS_RECONNECT = 'needs_reconnect';

	const TYPE_PATTERN = '/^[a-z0-9_-]{1,20}\z/';
	const LABEL_MAX    = 190;
	const ERROR_MAX    = 500;

	const RECONNECT_MESSAGE = "Stored credentials can't be read with the current encryption key. Reconnect this destination.";

	/**
	 * Columns of a normal read. Never includes `credentials`.
	 */
	const COLUMNS = "id, type, label, settings, (credentials IS NOT NULL AND credentials <> '') AS has_credentials, status, last_error, last_tested_at, created_at, updated_at";

	/**
	 * Vault.
	 *
	 * @var CredentialVault
	 */
	private $vault;

	/**
	 * Constructor.
	 *
	 * @param CredentialVault|null $vault Vault (defaults to the environment's key).
	 */
	public function __construct( ?CredentialVault $vault = null ) {
		$this->vault = null === $vault ? new CredentialVault() : $vault;
	}

	/**
	 * Full table name (prefix aware).
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'ovb_destinations';
	}

	/**
	 * Whether a destination type slug is valid (lowercase letters, digits, "_" and "-", 1 to 20 characters).
	 *
	 * @param mixed $type Type.
	 * @return bool
	 */
	public static function is_valid_type( $type ) {
		return is_string( $type ) && 1 === preg_match( self::TYPE_PATTERN, $type );
	}

	/**
	 * A destination label: control characters removed, trimmed, at most 190 characters.
	 *
	 * @param mixed $label Label.
	 * @return string
	 */
	public static function clean_label( $label ) {
		return Util::clean_text( $label, self::LABEL_MAX );
	}

	/**
	 * Make a connection error safe to store and show: masks bearer tokens, secret-looking query/key
	 * values, AWS key ids and long base64-like or hex tokens, strips control characters, limits to 500 characters.
	 *
	 * Long lower-case words and paths without digits are kept; everything else of 32+ token characters is masked.
	 *
	 * @param mixed $message Raw message.
	 * @return string
	 */
	public static function scrub_error( $message ) {
		if ( ! is_scalar( $message ) ) {
			return '';
		}
		$text = (string) $message;
		$text = (string) preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text );

		$text = (string) preg_replace( '/\bBearer\s+[^\s"\']+/i', 'Bearer [redacted]', $text );
		$text = (string) preg_replace( '/\bya29\.[\w.\-]+/', '[redacted]', $text );
		$text = (string) preg_replace(
			'/\b((?:access_|refresh_|id_)?token|client_secret|secret(?:_access_key|_key)?|password|passwd|api_?key|signature|x-amz-signature|x-amz-credential|x-amz-security-token|authorization)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;]+)/i',
			'$1$2[redacted]',
			$text
		);
		$text = (string) preg_replace( '/\b(?:AKIA|ASIA|AIza)[0-9A-Za-z_\-]{16,}/', '[redacted]', $text );
		$text = (string) preg_replace_callback(
			'/[A-Za-z0-9+\/_\-=]{32,}/',
			static function ( $found ) {
				// Plain lower-case text such as a long path or word is not a secret; anything with a digit or capital is masked.
				return 1 === preg_match( '/[0-9A-Z]/', $found[0] ) ? '[redacted]' : $found[0];
			},
			$text
		);

		return Util::truncate( trim( $text ), self::ERROR_MAX );
	}

	/**
	 * Create a destination.
	 *
	 * @param string              $type        Type slug (gdrive, s3, local, ...).
	 * @param string              $label       Display name.
	 * @param array<string,mixed> $settings    Non-secret settings (folder, bucket, region, endpoint).
	 * @param array<string,mixed> $credentials Secrets; stored encrypted. Empty means none.
	 * @return int New id, 0 on database failure.
	 * @throws \InvalidArgumentException For an invalid type (a VaultException also propagates if encryption is unavailable).
	 */
	public function create( $type, $label, array $settings, array $credentials ) {
		global $wpdb;

		if ( ! self::is_valid_type( $type ) ) {
			throw new \InvalidArgumentException( 'Invalid destination type.' );
		}

		$now = Util::now();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'type'        => $type,
				'label'       => self::clean_label( $label ),
				'settings'    => wp_json_encode( $settings ),
				'credentials' => $this->seal( $credentials ),
				'status'      => self::STATUS_NEW,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Find by id. The row never contains credentials; `has_credentials` says whether any are stored.
	 *
	 * @param int $id Destination id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * All destinations, oldest first (there are only a handful). No credentials.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all() {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( 'SELECT ' . self::COLUMNS . " FROM {$table} ORDER BY id ASC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- No user input in this query.

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Destinations of one type. No credentials.
	 *
	 * @param string $type Type slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function by_type( $type ) {
		global $wpdb;

		if ( ! self::is_valid_type( $type ) ) {
			return array();
		}

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE type = %s ORDER BY id ASC LIMIT 500", $type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Replace the non-secret settings (and optionally the label).
	 *
	 * @param int                 $id       Destination id.
	 * @param array<string,mixed> $settings New settings.
	 * @param string|null         $label    New label, or null to keep it.
	 * @return bool False when the row does not exist or the query failed.
	 */
	public function update_settings( $id, array $settings, $label = null ) {
		$data = array( 'settings' => wp_json_encode( $settings ) );
		if ( null !== $label ) {
			$data['label'] = self::clean_label( $label );
		}
		return $this->update_columns( $id, $data );
	}

	/**
	 * Replace the stored credentials (encrypted). The destination becomes "new" again: it has not been tested with them.
	 *
	 * @param int                 $id          Destination id.
	 * @param array<string,mixed> $credentials Secrets.
	 * @return bool
	 * @throws VaultException When the credentials cannot be encrypted.
	 */
	public function replace_credentials( $id, array $credentials ) {
		return $this->update_columns(
			$id,
			array(
				'credentials' => $this->seal( $credentials ),
				'status'      => self::STATUS_NEW,
				'last_error'  => null,
			)
		);
	}

	/**
	 * Decrypt the credentials. Never throws.
	 *
	 * When the stored value cannot be read with the current key (wrong_key / invalid_format) the
	 * destination is switched to "needs_reconnect" with a clear last_error.
	 *
	 * @param int $id Destination id.
	 * @return array{ok:bool,data:array<mixed>,reason:?string} reason is null when ok, otherwise a
	 *         VaultException reason, "not_found" or "invalid_format".
	 */
	public function get_credentials( $id ) {
		global $wpdb;

		$table = $this->table();
		$found = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT credentials FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		// get_var() returns null both for a missing row and for a NULL column, so read the row instead.
		if ( ! is_array( $found ) ) {
			return self::credentials_result( false, array(), 'not_found' );
		}
		$raw = isset( $found['credentials'] ) ? (string) $found['credentials'] : '';
		if ( '' === $raw ) {
			return self::credentials_result( true, array(), null );
		}

		try {
			return self::credentials_result( true, $this->vault->decrypt_array( (string) $raw ), null );
		} catch ( VaultException $e ) {
			$reason = $e->get_reason();
			if ( VaultException::WRONG_KEY === $reason || VaultException::INVALID_FORMAT === $reason ) {
				$this->update_columns(
					$id,
					array(
						'status'     => self::STATUS_NEEDS_RECONNECT,
						'last_error' => self::RECONNECT_MESSAGE,
					)
				);
			}
			return self::credentials_result( false, array(), $reason );
		}
	}

	/**
	 * Whether any credentials are stored (does not decrypt).
	 *
	 * @param int $id Destination id.
	 * @return bool
	 */
	public function has_credentials( $id ) {
		global $wpdb;

		$table = $this->table();
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND credentials IS NOT NULL AND credentials <> ''", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return null !== $found;
	}

	/**
	 * Record the result of a connection test.
	 *
	 * @param int         $id    Destination id.
	 * @param bool        $ok    Whether the test passed.
	 * @param string|null $error Failure message; scrubbed of secrets and cut to 500 characters.
	 * @return bool
	 */
	public function mark_tested( $id, $ok, $error = null ) {
		return $this->update_columns(
			$id,
			array(
				'status'         => $ok ? self::STATUS_CONNECTED : self::STATUS_ERROR,
				'last_error'     => $ok || null === $error ? null : self::scrub_error( $error ),
				'last_tested_at' => Util::now(),
			)
		);
	}

	/**
	 * Forget the last test (settings changed, so "connected" would no longer be true): status new, no error, no test time.
	 * A destination that needs to be reconnected keeps that status.
	 *
	 * @param int $id Destination id.
	 * @return bool
	 */
	public function mark_untested( $id ) {
		$row = $this->find( $id );
		if ( null === $row || self::STATUS_NEEDS_RECONNECT === $row['status'] ) {
			return null !== $row;
		}
		return $this->update_columns(
			$id,
			array(
				'status'         => self::STATUS_NEW,
				'last_error'     => null,
				'last_tested_at' => null,
			)
		);
	}

	/**
	 * Delete a destination. Its backup_files rows are left alone (they record what was uploaded there).
	 *
	 * @param int $id Destination id.
	 * @return bool True when a row was deleted.
	 */
	public function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			array( 'id' => (int) $id ),
			array( '%d' )
		);

		return 1 === $deleted;
	}

	/**
	 * Convert a raw row into a typed array (no credentials).
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ) {
		return array(
			'id'              => (int) $row['id'],
			'type'            => (string) $row['type'],
			'label'           => (string) $row['label'],
			'settings'        => Util::decode_json( isset( $row['settings'] ) ? $row['settings'] : null ),
			'has_credentials' => ! empty( $row['has_credentials'] ),
			'status'          => (string) $row['status'],
			'last_error'      => isset( $row['last_error'] ) ? (string) $row['last_error'] : null,
			'last_tested_at'  => isset( $row['last_tested_at'] ) ? (string) $row['last_tested_at'] : null,
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		);
	}

	/**
	 * Result shape of get_credentials().
	 *
	 * @param bool         $ok     Success.
	 * @param array<mixed> $data   Credentials.
	 * @param string|null  $reason Failure reason.
	 * @return array{ok:bool,data:array<mixed>,reason:?string}
	 */
	private static function credentials_result( $ok, array $data, $reason ) {
		return array(
			'ok'     => $ok,
			'data'   => $data,
			'reason' => $reason,
		);
	}

	/**
	 * Encrypt credentials for storage. No credentials stores an empty string.
	 *
	 * @param array<string,mixed> $credentials Secrets.
	 * @return string
	 * @throws VaultException When encryption is unavailable.
	 */
	private function seal( array $credentials ) {
		return array() === $credentials ? '' : $this->vault->encrypt_array( $credentials );
	}

	/**
	 * Update validated columns of one row (also bumps updated_at). NULL values are written as SQL NULL.
	 *
	 * @param int                 $id   Destination id.
	 * @param array<string,mixed> $data Columns.
	 * @return bool False when the row does not exist or the query failed.
	 */
	private function update_columns( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = Util::now();
		$formats            = array_fill( 0, count( $data ), '%s' );

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			$data,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);
		if ( false === $result ) {
			return false;
		}

		$table = $this->table();
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return null !== $found;
	}
}
