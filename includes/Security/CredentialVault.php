<?php
/**
 * Encrypts/decrypts stored secrets with a key derived from WP salts (or OVB_ENCRYPTION_KEY constant).
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - libsodium secretbox (XSalsa20-Poly1305) with a random nonce per message.
 *  - Versioned envelope string: "ovb1:" . base64( nonce . ciphertext ).
 *  - Key source: OVB_ENCRYPTION_KEY if defined and non-empty, otherwise wp_salt( 'auth' ) + wp_salt( 'secure_auth' ).
 *  - If the key later changes, decryption fails safely with a typed reason (never garbage, never fatal),
 *    so the UI can ask the user to reconnect the destination.
 *  - Arrays are stored as JSON inside the envelope, never PHP serialize().
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Security;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- VaultException messages are fixed constant strings, not user data.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64 is the transport encoding of the envelope, not obfuscation.

/**
 * CredentialVault.
 */
class CredentialVault {

	const PREFIX          = 'ovb1:';
	const VERSION         = 1;
	const KEY_DOMAIN      = 'ovb-vault-v1|';
	const SOURCE_CONSTANT = 'constant';
	const SOURCE_SALTS    = 'salts';

	/**
	 * Provides the key material (any non-empty string).
	 *
	 * @var callable
	 */
	private $key_material;

	/**
	 * Where the key material comes from: constant or salts.
	 *
	 * @var string
	 */
	private $key_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $key_material Returns the key material. Defaults to the constant/salts logic.
	 * @param string|null   $key_source   "constant" or "salts". Defaults to the detected source when no
	 *                                    provider is injected, otherwise "salts".
	 * @throws \InvalidArgumentException For an unknown key source.
	 */
	public function __construct( ?callable $key_material = null, $key_source = null ) {
		if ( null === $key_source ) {
			$key_source = null === $key_material ? self::key_source_from_environment() : self::SOURCE_SALTS;
		}
		if ( self::SOURCE_CONSTANT !== $key_source && self::SOURCE_SALTS !== $key_source ) {
			throw new \InvalidArgumentException( 'Unknown key source.' );
		}

		$this->key_material = null === $key_material ? array( __CLASS__, 'key_material_from_environment' ) : $key_material;
		$this->key_source   = $key_source;
	}

	/**
	 * Default key material: the OVB_ENCRYPTION_KEY constant, otherwise the WordPress salts.
	 *
	 * @return string
	 * @throws VaultException When no key material can be obtained.
	 */
	public static function key_material_from_environment() {
		if ( self::has_constant_key() ) {
			return 'constant|' . (string) constant( 'OVB_ENCRYPTION_KEY' );
		}
		if ( function_exists( 'wp_salt' ) ) {
			return 'salts|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
		}
		throw new VaultException( VaultException::UNAVAILABLE );
	}

	/**
	 * Key source detected from the environment.
	 *
	 * @return string "constant" or "salts".
	 */
	public static function key_source_from_environment() {
		return self::has_constant_key() ? self::SOURCE_CONSTANT : self::SOURCE_SALTS;
	}

	/**
	 * Whether OVB_ENCRYPTION_KEY is defined and non-empty.
	 *
	 * @return bool
	 */
	private static function has_constant_key() {
		return defined( 'OVB_ENCRYPTION_KEY' ) && '' !== (string) constant( 'OVB_ENCRYPTION_KEY' );
	}

	/**
	 * Where the key comes from (for System Info).
	 *
	 * @return string "constant" or "salts".
	 */
	public function key_source() {
		return $this->key_source;
	}

	/**
	 * Whether a string looks like a vault envelope (any version).
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public function is_envelope( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^ovb\d+:.+$/s', $value );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain Plaintext.
	 * @return string Envelope.
	 * @throws VaultException When encryption is unavailable.
	 */
	public function encrypt( $plain ) {
		$plain = (string) $plain;
		$key   = $this->derive_key();

		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
		} catch ( \Exception $e ) {
			throw new VaultException( VaultException::UNAVAILABLE );
		} finally {
			sodium_memzero( $key );
		}

		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt an envelope.
	 *
	 * @param string $envelope Envelope.
	 * @return string Plaintext.
	 * @throws VaultException With a reason on any failure.
	 */
	public function decrypt( $envelope ) {
		if ( ! is_string( $envelope ) || 1 !== preg_match( '/^ovb(\d+):(.*)$/s', $envelope, $match ) ) {
			throw new VaultException( VaultException::INVALID_FORMAT );
		}
		if ( self::VERSION !== (int) $match[1] ) {
			throw new VaultException( VaultException::UNSUPPORTED_VERSION );
		}

		$raw = base64_decode( $match[2], true );
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			throw new VaultException( VaultException::INVALID_FORMAT );
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$key = $this->derive_key();
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		} catch ( \Exception $e ) {
			$plain = false;
		} finally {
			sodium_memzero( $key );
		}

		if ( false === $plain ) {
			// Wrong key and tampered data are indistinguishable by design.
			throw new VaultException( VaultException::WRONG_KEY );
		}

		return $plain;
	}

	/**
	 * Decrypt without throwing, for UI code.
	 *
	 * @param string $envelope Envelope.
	 * @return array{ok:bool,value:?string,reason:?string}
	 */
	public function try_decrypt( $envelope ) {
		try {
			return array(
				'ok'     => true,
				'value'  => $this->decrypt( $envelope ),
				'reason' => null,
			);
		} catch ( VaultException $e ) {
			return array(
				'ok'     => false,
				'value'  => null,
				'reason' => $e->get_reason(),
			);
		}
	}

	/**
	 * Encrypt an array as JSON inside an envelope.
	 *
	 * @param array<mixed> $data Data.
	 * @return string Envelope.
	 * @throws \InvalidArgumentException When the data cannot be JSON-encoded.
	 */
	public function encrypt_array( array $data ) {
		$json = json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Works without WordPress loaded (unit tests, standalone restore).
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'Data cannot be encoded as JSON.' );
		}
		return $this->encrypt( $json );
	}

	/**
	 * Decrypt an envelope holding a JSON array.
	 *
	 * @param string $envelope Envelope.
	 * @return array<mixed>
	 * @throws VaultException With a reason on any failure.
	 */
	public function decrypt_array( $envelope ) {
		$data = json_decode( $this->decrypt( $envelope ), true );
		if ( ! is_array( $data ) ) {
			throw new VaultException( VaultException::INVALID_FORMAT );
		}
		return $data;
	}

	/**
	 * Derive the 32-byte key. The caller must sodium_memzero() it.
	 *
	 * @return string
	 * @throws VaultException When sodium or the key material is unavailable.
	 */
	private function derive_key() {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'sodium_crypto_generichash' ) ) {
			throw new VaultException( VaultException::UNAVAILABLE );
		}

		try {
			$material = call_user_func( $this->key_material );
		} catch ( \Exception $e ) {
			throw new VaultException( VaultException::UNAVAILABLE );
		}
		if ( ! is_string( $material ) || '' === $material ) {
			throw new VaultException( VaultException::UNAVAILABLE );
		}

		$input = self::KEY_DOMAIN . $material;
		sodium_memzero( $material );
		$key = sodium_crypto_generichash( $input, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		sodium_memzero( $input );

		return $key;
	}
}
