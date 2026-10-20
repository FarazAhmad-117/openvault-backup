<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\DestinationsRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Database\DestinationsRepository
 */
class DestinationsRepositoryTest extends TestCase {

	public function test_type_validation() {
		foreach ( array( 'gdrive', 's3', 'local', 'dropbox', 'one_drive', 'my-type', 'a', str_repeat( 'a', 20 ) ) as $ok ) {
			$this->assertTrue( DestinationsRepository::is_valid_type( $ok ), $ok );
		}
		foreach ( array( '', 'GDrive', 's3 ', "s3\n", 'a b', 'a.b', "s3'; DROP TABLE x", str_repeat( 'a', 21 ), null, 5, array( 's3' ) ) as $bad ) {
			$this->assertFalse( DestinationsRepository::is_valid_type( $bad ), var_export( $bad, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
	}

	public function test_label_is_cleaned() {
		$this->assertSame( 'My Drive', DestinationsRepository::clean_label( "  My\x00 Drive\n" ) );
		$this->assertSame( 190, strlen( DestinationsRepository::clean_label( str_repeat( 'a', 500 ) ) ) );
	}

	public function test_scrub_masks_bearer_tokens() {
		$out = DestinationsRepository::scrub_error( 'HTTP 401: Authorization: Bearer ya29.a0AfH6SMBx-secret_value.more failed' );
		$this->assertStringNotContainsString( 'ya29', $out );
		$this->assertStringNotContainsString( 'secret_value', $out );
		$this->assertStringContainsString( '[redacted]', $out );
		$this->assertStringContainsString( 'failed', $out );
	}

	public function test_scrub_masks_long_tokens_and_hex() {
		$b64 = 'dGhpcyBpcyBhIHZlcnkgbG9uZyBiYXNlNjQgc2VjcmV0IHZhbHVl';
		$hex = str_repeat( 'a1b2c3d4', 8 );
		$out = DestinationsRepository::scrub_error( "Bad key {$b64} and sha {$hex} end" );
		$this->assertStringNotContainsString( $b64, $out );
		$this->assertStringNotContainsString( $hex, $out );
		$this->assertSame( 'Bad key [redacted] and sha [redacted] end', $out );
	}

	public function test_scrub_masks_key_value_pairs() {
		$out = DestinationsRepository::scrub_error( 'GET https://x/y?access_token=abc123&foo=bar&client_secret=s3cr3t&X-Amz-Signature=deadbeef failed' );
		$this->assertStringNotContainsString( 'abc123', $out );
		$this->assertStringNotContainsString( 's3cr3t', $out );
		$this->assertStringNotContainsString( 'deadbeef', $out );
		$this->assertStringContainsString( 'foo=bar', $out );

		$out = DestinationsRepository::scrub_error( 'password: "hunter2 hunter2" refused' );
		$this->assertStringNotContainsString( 'hunter2', $out );
	}

	public function test_scrub_masks_aws_key_ids() {
		$out = DestinationsRepository::scrub_error( 'The AWS Access Key Id AKIAIOSFODNN7EXAMPLE does not exist' );
		$this->assertStringNotContainsString( 'AKIAIOSFODNN7EXAMPLE', $out );
		$this->assertStringContainsString( 'does not exist', $out );
	}

	public function test_scrub_keeps_ordinary_messages() {
		$msg = 'Could not connect: cURL error 28: Operation timed out after 30001 milliseconds';
		$this->assertSame( $msg, DestinationsRepository::scrub_error( $msg ) );
		$this->assertSame( 'error=invalid_grant', DestinationsRepository::scrub_error( 'error=invalid_grant' ) );
		$this->assertSame( '', DestinationsRepository::scrub_error( '' ) );
		$this->assertSame( '', DestinationsRepository::scrub_error( array( 'x' ) ) );
	}

	public function test_scrub_truncates_to_500_and_strips_control_chars() {
		$out = DestinationsRepository::scrub_error( str_repeat( 'word ', 400 ) );
		$this->assertLessThanOrEqual( 500, strlen( $out ) );
		$this->assertSame( 'ab', DestinationsRepository::scrub_error( "a\x00\x07b" ) );
	}

	public function test_scrub_after_truncation_never_leaves_a_secret_prefix() {
		$secret = str_repeat( 'Zz9', 20 );
		$out    = DestinationsRepository::scrub_error( str_repeat( 'x ', 240 ) . $secret );
		$this->assertStringNotContainsString( 'Zz9Zz9Zz9', $out );
	}

	public function test_scrub_is_inert_for_sql_metacharacters() {
		$msg = "Duplicate entry '1' for key 'PRIMARY'; DROP TABLE wp_users;--";
		$this->assertSame( $msg, DestinationsRepository::scrub_error( $msg ) );
	}

	public function test_hydrate_never_exposes_credentials() {
		$row = DestinationsRepository::hydrate(
			array(
				'id'              => '4',
				'type'            => 'gdrive',
				'label'           => 'Drive',
				'settings'        => '{"folder":"x"}',
				'credentials'     => 'ovb1:SECRET',
				'has_credentials' => '1',
				'status'          => 'new',
				'last_error'      => null,
				'last_tested_at'  => null,
				'created_at'      => '2026-01-01 00:00:00',
				'updated_at'      => '2026-01-01 00:00:00',
			)
		);

		$this->assertArrayNotHasKey( 'credentials', $row );
		$this->assertTrue( $row['has_credentials'] );
		$this->assertSame( array( 'folder' => 'x' ), $row['settings'] );
		$encoded = json_encode( $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringNotContainsString( 'SECRET', (string) $encoded );
	}

	public function test_select_list_never_names_the_credentials_value() {
		// The column list must only reference credentials inside the has_credentials expression.
		$list = str_replace( "(credentials IS NOT NULL AND credentials <> '') AS has_credentials", '', DestinationsRepository::COLUMNS );
		$this->assertStringNotContainsString( 'credentials', $list );
		$this->assertStringNotContainsString( '*', DestinationsRepository::COLUMNS );
	}
}
