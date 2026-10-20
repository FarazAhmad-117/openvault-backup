<?php
/**
 * Failure raised by the CredentialVault.
 *
 * Phase: MVP
 *
 * Carries a machine-readable reason so the UI can react (for example "wrong_key" means the
 * encryption key changed and the destination must be reconnected). Messages are fixed strings
 * and never contain plaintext, ciphertext or key material.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Security;

defined( 'ABSPATH' ) || exit;

/**
 * VaultException.
 */
class VaultException extends \RuntimeException {

	const INVALID_FORMAT      = 'invalid_format';
	const WRONG_KEY           = 'wrong_key';
	const UNSUPPORTED_VERSION = 'unsupported_version';
	const UNAVAILABLE         = 'unavailable';

	/**
	 * Fixed, secret-free message per reason.
	 */
	const MESSAGES = array(
		self::INVALID_FORMAT      => 'The stored value is not a valid encrypted envelope.',
		self::WRONG_KEY           => 'The value could not be decrypted with the current encryption key.',
		self::UNSUPPORTED_VERSION => 'The encrypted envelope uses an unsupported version.',
		self::UNAVAILABLE         => 'Encryption is not available on this server.',
	);

	/**
	 * Reason slug.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the reason constants.
	 */
	public function __construct( $reason ) {
		$this->reason = isset( self::MESSAGES[ $reason ] ) ? $reason : self::INVALID_FORMAT;
		parent::__construct( self::MESSAGES[ $this->reason ] );
	}

	/**
	 * Machine-readable reason.
	 *
	 * @return string
	 */
	public function get_reason() {
		return $this->reason;
	}
}
