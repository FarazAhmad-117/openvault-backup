<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestoreStatusToken as T;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Restore\RestoreStatusToken
 * @covers \OpenVault\Backup\Restore\RestoreMeta
 */
class RestoreStatusTokenTest extends TestCase {

	public function test_tokens_are_128_random_bits_in_hex() {
		$a = T::generate();
		$b = T::generate();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $a );
		$this->assertNotSame( $a, $b );
		$this->assertTrue( T::well_formed( $a ) );
	}

	public function test_only_the_hash_is_stored_and_it_is_sha256() {
		$token = T::generate();
		$hash  = T::hash( $token );
		$this->assertSame( hash( 'sha256', $token ), $hash );
		$this->assertStringNotContainsString( $token, $hash );
		$meta = RestoreMeta::with_status_token( array(), $hash );
		$this->assertSame( $hash, RestoreMeta::status_token_hash( $meta ) );
		$this->assertStringNotContainsString( $token, implode( ',', array_map( 'strval', $meta ) ) );
		$this->assertSame( array(), RestoreMeta::with_status_token( array(), 'not-a-hash' ) );
		$this->assertSame( '', RestoreMeta::status_token_hash( array( 'status_token' => $token ) ) );
	}

	public function test_the_right_token_opens_a_running_job() {
		$token = T::generate();
		$this->assertTrue( T::check( T::hash( $token ), $token, 0, 1000 ) );
	}

	public function test_every_kind_of_mismatch_is_refused() {
		$token = T::generate();
		$hash  = T::hash( $token );
		$other = T::generate();
		$this->assertFalse( T::check( $hash, $other, 0, 1000 ), 'wrong token' );
		$this->assertFalse( T::check( '', $token, 0, 1000 ), 'job without a token' );
		$this->assertFalse( T::check( 'zz', $token, 0, 1000 ), 'broken hash' );
		$this->assertFalse( T::check( $hash, '', 0, 1000 ), 'empty' );
		$this->assertFalse( T::check( $hash, null, 0, 1000 ), 'null' );
		$this->assertFalse( T::check( $hash, array( $token ), 0, 1000 ), 'array' );
		$this->assertFalse( T::check( $hash, strtoupper( $token ), 0, 1000 ), 'case matters' );
		$this->assertFalse( T::check( $hash, $token . ' ', 0, 1000 ), 'whitespace' );
		$this->assertFalse( T::check( $hash, substr( $token, 0, 31 ), 0, 1000 ), 'short' );
		$this->assertFalse( T::check( $hash, $hash, 0, 1000 ), 'the hash is not the token' );
		$this->assertFalse( T::check( str_repeat( '0', 64 ), str_repeat( '0', 32 ), 0, 1000 ), 'zero hash is no backdoor' );
	}

	public function test_the_token_stops_working_one_hour_after_the_job_finished() {
		$token = T::generate();
		$hash  = T::hash( $token );
		$this->assertTrue( T::check( $hash, $token, 1000, 1000 + 3600 ), 'exactly at the limit' );
		$this->assertFalse( T::check( $hash, $token, 1000, 1000 + 3601 ), 'one second later' );
		$this->assertTrue( T::check( $hash, $token, 0, 999999999 ), 'an unfinished job never expires by time' );
	}
}
