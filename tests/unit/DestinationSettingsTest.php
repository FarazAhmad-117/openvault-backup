<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test messages only; WordPress is not loaded in unit tests.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\DestinationSettings as S;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Destinations\DestinationSettings
 */
class DestinationSettingsTest extends TestCase {

	const ACCOUNT = '0123456789abcdef0123456789abcdef';

	private function s3( array $over = array() ) {
		return array_merge(
			array(
				'preset' => 'aws',
				'region' => 'eu-central-1',
				'bucket' => 'my-backups',
				'prefix' => 'sites/main',
			),
			$over
		);
	}

	private function refuses( $type, $input, $needle = null ) {
		try {
			S::validate_settings( $type, $input, static function () {} );
		} catch ( DestinationException $e ) {
			if ( null !== $needle ) {
				$this->assertStringContainsString( $needle, $e->getMessage() );
			}
			$this->assertNotSame( '', $e->getMessage() );
			return $e->getMessage();
		}
		$this->fail( 'accepted: ' . json_encode( $input ) );
	}

	public function test_valid_s3_settings_are_normalised() {
		$out = S::validate_settings(
			's3',
			$this->s3(
				array(
					'prefix'        => 'sites//main/',
					'storage_class' => 'standard_ia',
					'addressing'    => 'auto',
				)
			)
		);
		$this->assertSame(
			array(
				'preset'        => 'aws',
				'bucket'        => 'my-backups',
				'prefix'        => 'sites/main',
				'region'        => 'eu-central-1',
				'storage_class' => 'STANDARD_IA',
			),
			$out
		);
	}

	public function test_every_preset_accepts_its_own_fields() {
		$cases = array(
			array(
				'preset'     => 'r2',
				'account_id' => strtoupper( self::ACCOUNT ),
				'bucket'     => 'bkt',
			),
			array(
				'preset' => 'b2',
				'region' => 'us-east-005',
				'bucket' => 'bkt',
			),
			array(
				'preset' => 'wasabi',
				'region' => 'eu-central-1',
				'bucket' => 'bkt',
			),
			array(
				'preset' => 'spaces',
				'region' => 'nyc3',
				'bucket' => 'bkt',
			),
			array(
				'preset'   => 'custom',
				'endpoint' => 'https://s3.example.com',
				'bucket'   => 'bkt',
			),
		);
		foreach ( $cases as $case ) {
			$out = S::validate_settings( 's3', $case );
			$this->assertSame( $case['preset'], $out['preset'] );
			$this->assertSame( '', $out['prefix'] );
		}
		$out = S::validate_settings( 's3', $cases[0] );
		$this->assertSame( self::ACCOUNT, $out['account_id'], 'account id is lower-cased' );
	}

	public function test_custom_endpoint_with_flags_for_a_private_test_server() {
		$out = S::validate_settings(
			's3',
			array(
				'preset'                 => 'custom',
				'endpoint'               => 'http://s3srv:8333',
				'bucket'                 => 'bkt',
				'allow_insecure_http'    => true,
				'allow_private_endpoint' => '1',
			)
		);
		$this->assertTrue( $out['allow_insecure_http'] );
		$this->assertTrue( $out['allow_private_endpoint'] );
		$this->refuses(
			's3',
			array(
				'preset'   => 'custom',
				'endpoint' => 'http://s3srv:8333',
				'bucket'   => 'bkt',
			)
		);
	}

	public function test_ssrf_endpoints_are_refused_whatever_the_flags() {
		foreach ( array( 'http://169.254.169.254', 'https://169.254.169.254/', 'http://metadata.google.internal', 'http://[fe80::1]', 'http://0.0.0.0', 'http://2130706433', 'https://user:pw@example.com', 'https://example.com/path', 'ftp://example.com', 'javascript:alert(1)' ) as $endpoint ) {
			$this->refuses(
				's3',
				array(
					'preset'                 => 'custom',
					'endpoint'               => $endpoint,
					'bucket'                 => 'bkt',
					'allow_insecure_http'    => true,
					'allow_private_endpoint' => true,
				)
			);
		}
		$this->refuses(
			's3',
			array(
				'preset'   => 'custom',
				'endpoint' => 'http://127.0.0.1',
				'bucket'   => 'bkt',
			)
		);
	}

	public function test_endpoint_and_flags_only_for_custom() {
		$this->refuses( 's3', $this->s3( array( 'endpoint' => 'https://evil.example.com' ) ), 'does not apply' );
		$this->refuses( 's3', $this->s3( array( 'allow_private_endpoint' => true ) ), 'does not apply' );
		$this->refuses( 's3', $this->s3( array( 'account_id' => self::ACCOUNT ) ), 'does not apply' );
		$this->refuses(
			's3',
			array(
				'preset'     => 'r2',
				'account_id' => self::ACCOUNT,
				'region'     => 'eu-west-1',
				'bucket'     => 'bkt',
			),
			'does not apply'
		);
		// Empty or false values for them are tolerated (a form may send them).
		$out = S::validate_settings(
			's3',
			$this->s3(
				array(
					'endpoint'               => '',
					'allow_private_endpoint' => false,
					'allow_insecure_http'    => '0',
				)
			)
		);
		$this->assertArrayNotHasKey( 'endpoint', $out );
		$this->assertArrayNotHasKey( 'allow_private_endpoint', $out );
	}

	public function test_unknown_keys_and_wrong_types() {
		$this->refuses( 's3', $this->s3( array( 'part_size_mb' => '5' ) ), 'not allowed' );
		$this->refuses( 's3', $this->s3( array( 'credentials' => 'x' ) ), 'not allowed' );
		$this->refuses( 's3', array_merge( $this->s3(), array( 0 => 'x' ) ), 'not allowed' );
		$this->refuses( 's3', $this->s3( array( 'bucket' => array( 'a' ) ) ) );
		$this->refuses( 's3', $this->s3( array( 'bucket' => 12345 ) ) );
		$this->refuses( 's3', $this->s3( array( 'allow_insecure_http' => 'yes' ) ) );
		$this->refuses( 's3', $this->s3( array( 'prefix' => str_repeat( 'a', 300 ) ) ) );
		$this->refuses( 's3', 'text' );
		$this->refuses( 's3', null );
		$this->refuses( 's3', array() );
		$this->refuses(
			's3',
			array(
				'preset' => 'gdrive',
				'bucket' => 'bkt',
			)
		);
		$this->refuses(
			's3',
			array(
				'preset' => array( 'aws' ),
				'bucket' => 'bkt',
			)
		);
	}

	public function test_bucket_rules() {
		foreach ( array( 'abc', 'my-bucket', 'a.b.c', 'bucket-123', str_repeat( 'a', 63 ) ) as $bucket ) {
			$this->assertSame( $bucket, S::validate_settings( 's3', $this->s3( array( 'bucket' => $bucket ) ) )['bucket'], $bucket );
		}
		foreach ( array( '', 'ab', str_repeat( 'a', 64 ), 'UPPER', 'Mixed-case', '-start', 'end-', '.dot', 'dot.', 'a..b', '192.168.5.4', '10.0.0.1', 'under_score', 'sp ace', 'a/b', "bk\nt", "x'; DROP TABLE x;--", '<script>' ) as $bucket ) {
			$this->refuses( 's3', $this->s3( array( 'bucket' => $bucket ) ) );
		}
	}

	public function test_prefix_rules() {
		$this->refuses( 's3', $this->s3( array( 'prefix' => '/leading' ) ), 'start with' );
		$this->refuses( 's3', $this->s3( array( 'prefix' => 'a/../b' ) ) );
		$this->refuses( 's3', $this->s3( array( 'prefix' => '..' ) ) );
		$this->refuses( 's3', $this->s3( array( 'prefix' => 'a/./b' ) ) );
		$this->refuses( 's3', $this->s3( array( 'prefix' => '..\\x' ) ) );
		$this->refuses( 's3', $this->s3( array( 'prefix' => 'a b' ) ) );
		$this->refuses( 's3', $this->s3( array( 'prefix' => "a'; DROP TABLE wp_options;--" ) ) );
		$this->assertSame( '', S::validate_settings( 's3', $this->s3( array( 'prefix' => '' ) ) )['prefix'] );
		$this->assertSame( 'a/b', S::validate_settings( 's3', $this->s3( array( 'prefix' => 'a/b/' ) ) )['prefix'] );
	}

	public function test_region_addressing_and_storage_class() {
		$this->refuses( 's3', $this->s3( array( 'region' => 'EU-CENTRAL-1' ) ) );
		$this->refuses( 's3', $this->s3( array( 'region' => '' ) ) );
		$this->refuses( 's3', $this->s3( array( 'region' => 'eu-central-1.evil.com' ) ) );
		$this->refuses( 's3', $this->s3( array( 'addressing' => 'dns' ) ) );
		$this->refuses( 's3', $this->s3( array( 'storage_class' => 'GLACIER' ) ) );
		$this->assertSame( 'path', S::validate_settings( 's3', $this->s3( array( 'addressing' => 'path' ) ) )['addressing'] );
		$this->assertArrayNotHasKey( 'storage_class', S::validate_settings( 's3', $this->s3( array( 'storage_class' => 'STANDARD' ) ) ) );
	}

	public function test_local_settings() {
		$seen = array();
		$spy  = static function ( $settings ) use ( &$seen ) {
			$seen[] = $settings;
		};
		$this->assertSame( array( 'path' => '' ), S::validate_settings( 'local', array(), $spy ) );
		$this->assertSame( array( 'path' => '' ), S::validate_settings( 'local', null, $spy ) );
		$this->assertSame( array( 'path' => '' ), S::validate_settings( 'local', array( 'path' => '  ' ), $spy ) );
		$this->assertSame( array(), $seen, 'the default folder needs no check' );
		$this->assertSame( array( 'path' => '/mnt/backups' ), S::validate_settings( 'local', array( 'path' => '/mnt/backups/' ), $spy ) );
		$this->assertSame( array( array( 'path' => '/mnt/backups' ) ), $seen, 'the folder rules of LocalDestination are applied' );
	}

	public function test_local_hostile_input() {
		foreach ( array( '../etc', '/var/../etc', '/a/./b', 'relative/path', '/', "/tmp/a\0b", array( '/tmp' ), 123, str_repeat( '/a', 600 ) ) as $path ) {
			$this->refuses( 'local', array( 'path' => $path ) );
		}
		$this->refuses(
			'local',
			array(
				'path'  => '/tmp',
				'extra' => 1,
			),
			'not allowed'
		);
		$this->refuses( 'local', 'string' );
	}

	public function test_local_check_failures_are_passed_on() {
		$deny = static function () {
			throw DestinationException::permanent( 'This folder cannot be used.' );
		};
		$this->expectException( DestinationException::class );
		S::validate_settings( 'local', array( 'path' => '/var/www/html/wp-admin' ), $deny );
	}

	public function test_unknown_type() {
		$this->refuses( 'gdrive', array() );
		$this->assertTrue( S::is_supported_type( 's3' ) );
		$this->assertTrue( S::is_supported_type( 'local' ) );
		foreach ( array( 'gdrive', '', null, array( 's3' ), 'S3' ) as $type ) {
			$this->assertFalse( S::is_supported_type( $type ) );
		}
	}

	public function test_label_rules() {
		foreach ( array( 'Offsite', 'a', str_repeat( 'x', 100 ), 'Café ünïcode', "O'Brien \"quoted\"", '<b>x</b>' ) as $label ) {
			$this->assertTrue( S::is_label( $label ), $label );
		}
		foreach ( array( '', '   ', "\n\t", str_repeat( 'x', 101 ), str_repeat( 'x', 5000 ), null, 12, array( 'x' ) ) as $label ) {
			$this->assertFalse( S::is_label( $label ), var_export( $label, true ) );
		}
		$this->assertSame( 'ab', S::clean_label( " a\x00b\n" ) );
	}

	public function test_credentials_shape() {
		$good = array(
			'access_key' => ' AKIAEXAMPLE12345 ',
			'secret_key' => 'abc/DEF+123=ghi',
		);
		$this->assertSame(
			array(
				'access_key' => 'AKIAEXAMPLE12345',
				'secret_key' => 'abc/DEF+123=ghi',
			),
			S::validate_credentials( 's3', $good, true )
		);
		// Update: nothing supplied keeps the stored ones.
		$this->assertSame( array(), S::validate_credentials( 's3', null, false ) );
		$this->assertSame( array(), S::validate_credentials( 's3', array(), false ) );
		$this->assertSame(
			array(),
			S::validate_credentials(
				's3',
				array(
					'access_key' => '',
					'secret_key' => '',
				),
				false
			)
		);
		// Local needs none.
		$this->assertSame( array(), S::validate_credentials( 'local', null, true ) );
	}

	public function test_credentials_refusals_never_contain_the_secret() {
		$secret = 'TopSecret-' . str_repeat( 'Z', 8 ) . '/value';
		$bad    = array(
			array( 'access_key' => 'AKIAEXAMPLE12345' ),
			array( 'secret_key' => $secret ),
			array(
				'access_key' => 'AK',
				'secret_key' => $secret,
			),
			array(
				'access_key' => 'AKIA EXAMPLE',
				'secret_key' => $secret,
			),
			array(
				'access_key' => 'AKIAEXAMPLE12345',
				'secret_key' => 'short',
			),
			array(
				'access_key' => 'AKIAEXAMPLE12345',
				'secret_key' => $secret . ' with space',
			),
			array(
				'access_key' => 'AKIAEXAMPLE12345',
				'secret_key' => $secret,
				'session'    => 'x',
			),
			array(
				'access_key' => array( 'x' ),
				'secret_key' => $secret,
			),
			array(
				'access_key' => 'AKIAEXAMPLE12345',
				'secret_key' => str_repeat( 'k', 300 ),
			),
			array(
				'access_key' => 'AKIAEXAMPLE12345',
				'secret_key' => "multi\nline-secret",
			),
		);
		foreach ( $bad as $input ) {
			try {
				S::validate_credentials( 's3', $input, true );
				$this->fail( 'accepted: ' . json_encode( $input ) );
			} catch ( DestinationException $e ) {
				$this->assertStringNotContainsString( 'TopSecret', $e->getMessage() );
				$this->assertStringNotContainsString( 'AKIA', $e->getMessage() );
				$this->assertStringNotContainsString( 'line-secret', $e->getMessage() );
			}
		}
		foreach ( array( null, array(), 'string' ) as $input ) {
			try {
				S::validate_credentials( 's3', $input, true );
				$this->fail( 'required credentials accepted' );
			} catch ( DestinationException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->expectException( DestinationException::class );
		S::validate_credentials(
			'local',
			array(
				'access_key' => 'AKIAEXAMPLE12345',
				'secret_key' => $secret,
			),
			false
		);
	}

	public function test_no_settings_error_contains_submitted_values() {
		$marker = 'ZZ-MARKER-q1w2e3';
		$inputs = array(
			$this->s3( array( 'bucket' => $marker ) ),
			$this->s3( array( 'region' => $marker ) ),
			$this->s3( array( 'prefix' => '/' . $marker ) ),
			$this->s3( array( $marker => 'x' ) ),
			array(
				'preset'   => 'custom',
				'endpoint' => 'http://' . $marker . '.local',
				'bucket'   => 'bkt',
			),
			array(
				'preset' => $marker,
				'bucket' => 'bkt',
			),
		);
		foreach ( $inputs as $input ) {
			$message = $this->refuses( 's3', $input );
			$this->assertStringNotContainsString( $marker, $message );
		}
	}
}
