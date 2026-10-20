<?php
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Envelope tests need raw base64.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Security\CredentialVault;
use OpenVault\Backup\Security\VaultException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Security\CredentialVault
 * @covers \OpenVault\Backup\Security\VaultException
 */
class CredentialVaultTest extends TestCase {

	private function vault( $material = 'key-material-one', $source = null ) {
		return new CredentialVault(
			function () use ( $material ) {
				return $material;
			},
			$source
		);
	}

	public function test_round_trip_string() {
		$vault = $this->vault();
		$this->assertSame( 'hunter2', $vault->decrypt( $vault->encrypt( 'hunter2' ) ) );
	}

	public function test_round_trip_unicode_and_empty() {
		$vault = $this->vault();
		$plain = "pässwörd \u{1F512} 密码\0nul";
		$this->assertSame( $plain, $vault->decrypt( $vault->encrypt( $plain ) ) );
		$this->assertSame( '', $vault->decrypt( $vault->encrypt( '' ) ) );
	}

	public function test_round_trip_one_megabyte() {
		$vault = $this->vault();
		$plain = str_repeat( 'abcdefgh', 131072 );
		$this->assertSame( 1048576, strlen( $plain ) );
		$this->assertSame( $plain, $vault->decrypt( $vault->encrypt( $plain ) ) );
	}

	public function test_round_trip_array() {
		$vault = $this->vault();
		$data  = array(
			'access_key' => 'AKIA',
			'nested'     => array(
				'a' => 1,
				'b' => array( true, null, 'ü' ),
			),
		);
		$this->assertSame( $data, $vault->decrypt_array( $vault->encrypt_array( $data ) ) );
	}

	public function test_envelope_format() {
		$vault    = $this->vault();
		$envelope = $vault->encrypt( 'x' );
		$this->assertSame( 0, strpos( $envelope, 'ovb1:' ) );
		$raw = base64_decode( substr( $envelope, 5 ), true );
		$this->assertNotFalse( $raw );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1 + SODIUM_CRYPTO_SECRETBOX_MACBYTES, strlen( $raw ) );
	}

	public function test_two_encryptions_differ() {
		$vault = $this->vault();
		$this->assertNotSame( $vault->encrypt( 'same' ), $vault->encrypt( 'same' ) );
	}

	public function test_wrong_key_fails_with_wrong_key_reason() {
		$envelope = $this->vault( 'key-A' )->encrypt( 'secret-value' );
		try {
			$this->vault( 'key-B' )->decrypt( $envelope );
			$this->fail( 'Expected VaultException.' );
		} catch ( VaultException $e ) {
			$this->assertSame( VaultException::WRONG_KEY, $e->get_reason() );
			$this->assertStringNotContainsString( 'secret-value', $e->getMessage() );
		}
	}

	public function test_try_decrypt_reports_failure_without_throwing() {
		$envelope = $this->vault( 'key-A' )->encrypt( 'secret-value' );
		$result   = $this->vault( 'key-B' )->try_decrypt( $envelope );
		$this->assertSame(
			array(
				'ok'     => false,
				'value'  => null,
				'reason' => 'wrong_key',
			),
			$result
		);

		$ok = $this->vault( 'key-A' )->try_decrypt( $envelope );
		$this->assertSame(
			array(
				'ok'     => true,
				'value'  => 'secret-value',
				'reason' => null,
			),
			$ok
		);
	}

	public function test_tampered_ciphertext_never_returns_plaintext() {
		$vault    = $this->vault();
		$envelope = $vault->encrypt( 'secret-value' );
		$raw      = base64_decode( substr( $envelope, 5 ), true );

		// Flip one bit in every position: each must fail, none may return plaintext.
		foreach ( array( 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, strlen( $raw ) - 1 ) as $pos ) {
			$bad         = $raw;
			$bad[ $pos ] = chr( ord( $bad[ $pos ] ) ^ 1 );
			$result      = $vault->try_decrypt( 'ovb1:' . base64_encode( $bad ) );
			$this->assertFalse( $result['ok'] );
			$this->assertNull( $result['value'] );
			$this->assertContains( $result['reason'], array( 'wrong_key', 'invalid_format' ) );
		}
	}

	public function test_truncated_and_garbage_are_invalid_format() {
		$vault = $this->vault();
		foreach ( array( 'ovb1:', 'ovb1:AAAA', 'ovb1:!!!not base64!!!', 'ovb1:' . base64_encode( str_repeat( 'a', 39 ) ), 'garbage', '' ) as $bad ) {
			$result = $vault->try_decrypt( $bad );
			$this->assertFalse( $result['ok'], $bad );
			$this->assertSame( 'invalid_format', $result['reason'], $bad );
		}
	}

	public function test_unknown_version_prefix() {
		$vault  = $this->vault();
		$result = $vault->try_decrypt( 'ovb2:' . base64_encode( str_repeat( 'a', 60 ) ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_version', $result['reason'] );
	}

	public function test_decrypt_array_rejects_non_json_payload() {
		$vault = $this->vault();
		$this->expectException( VaultException::class );
		$vault->decrypt_array( $vault->encrypt( 'not json' ) );
	}

	public function test_decrypt_array_rejects_json_scalar() {
		$vault = $this->vault();
		try {
			$vault->decrypt_array( $vault->encrypt( '"just a string"' ) );
			$this->fail( 'Expected VaultException.' );
		} catch ( VaultException $e ) {
			$this->assertSame( VaultException::INVALID_FORMAT, $e->get_reason() );
		}
	}

	public function test_is_envelope() {
		$vault = $this->vault();
		$this->assertTrue( $vault->is_envelope( $vault->encrypt( 'x' ) ) );
		$this->assertTrue( $vault->is_envelope( 'ovb2:abcd' ) );
		$this->assertFalse( $vault->is_envelope( 'plain-secret' ) );
		$this->assertFalse( $vault->is_envelope( 'ovb1:' ) );
		$this->assertFalse( $vault->is_envelope( '' ) );
	}

	public function test_key_source() {
		$this->assertSame( 'salts', $this->vault()->key_source() );
		$this->assertSame( 'constant', $this->vault( 'm', CredentialVault::SOURCE_CONSTANT )->key_source() );
		$this->assertSame( 'salts', $this->vault( 'm', CredentialVault::SOURCE_SALTS )->key_source() );
	}

	public function test_invalid_key_source_is_rejected() {
		$this->expectException( \InvalidArgumentException::class );
		$this->vault( 'm', 'bogus' );
	}

	public function test_empty_key_material_is_unavailable() {
		$vault = $this->vault( '' );
		try {
			$vault->encrypt( 'x' );
			$this->fail( 'Expected VaultException.' );
		} catch ( VaultException $e ) {
			$this->assertSame( VaultException::UNAVAILABLE, $e->get_reason() );
		}
		$this->assertSame( 'unavailable', $vault->try_decrypt( 'ovb1:' . base64_encode( str_repeat( 'a', 60 ) ) )['reason'] );
	}

	public function test_throwing_key_provider_is_unavailable() {
		$vault = new CredentialVault(
			function () {
				throw new \RuntimeException( 'boom-secret' );
			}
		);
		try {
			$vault->encrypt( 'x' );
			$this->fail( 'Expected VaultException.' );
		} catch ( VaultException $e ) {
			$this->assertSame( VaultException::UNAVAILABLE, $e->get_reason() );
			$this->assertStringNotContainsString( 'boom-secret', $e->getMessage() );
		}
	}

	public function test_exception_messages_never_contain_plaintext_or_key() {
		$plain = 'SUPER-SECRET-PLAINTEXT';
		$env   = $this->vault( 'KEY-ONE-MATERIAL' )->encrypt( $plain );
		$other = $this->vault( 'KEY-TWO-MATERIAL' );
		$cases = array( $env, 'ovb1:AAAA', 'ovb9:xxxx', 'junk' );
		foreach ( $cases as $case ) {
			$result = $other->try_decrypt( $case );
			$this->assertFalse( $result['ok'] );
			try {
				$other->decrypt( $case );
			} catch ( VaultException $e ) {
				$this->assertStringNotContainsString( $plain, $e->getMessage() );
				$this->assertStringNotContainsString( 'KEY-', $e->getMessage() );
				$this->assertStringNotContainsString( $case, $e->getMessage() );
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_key_is_used_and_changing_it_fails_safely() {
		define( 'OVB_ENCRYPTION_KEY', 'constant-key-one' );
		$vault = new CredentialVault();
		$this->assertSame( 'constant', $vault->key_source() );
		$this->assertSame( 'constant', CredentialVault::key_source_from_environment() );
		$this->assertSame( 'secret', $vault->decrypt( $vault->encrypt( 'secret' ) ) );

		$envelope = $vault->encrypt( 'secret' );
		$other    = new CredentialVault(
			function () {
				return 'constant|constant-key-two';
			}
		);
		$this->assertSame( 'wrong_key', $other->try_decrypt( $envelope )['reason'] );
	}

	public function test_environment_material_uses_constant_when_defined() {
		// Salts are not available without WordPress, so with no constant it must be "unavailable", not fatal.
		if ( ! defined( 'OVB_ENCRYPTION_KEY' ) ) {
			$this->assertSame( 'salts', ( new CredentialVault() )->key_source() );
			$result = ( new CredentialVault() )->try_decrypt( 'ovb1:' . base64_encode( str_repeat( 'a', 60 ) ) );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'unavailable', $result['reason'] );
		} else {
			$this->assertSame( 'constant', ( new CredentialVault() )->key_source() );
		}
	}
}
