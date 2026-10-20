<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\S3\S3Presets;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Destinations\S3\S3Presets
 */
class S3PresetsTest extends TestCase {

	private function resolve( array $s ) {
		return S3Presets::resolve( array_merge( array( 'bucket' => 'my-bucket' ), $s ) );
	}

	public function test_each_preset_builds_its_documented_endpoint() {
		$aws = $this->resolve(
			array(
				'preset' => 'aws',
				'region' => 'eu-central-1',
			)
		);
		$this->assertSame( array( 's3.eu-central-1.amazonaws.com', 'virtual', 'eu-central-1' ), array( $aws['host'], $aws['addressing'], $aws['region'] ) );

		$r2 = $this->resolve(
			array(
				'preset'     => 'r2',
				'account_id' => str_repeat( 'AB', 16 ),
			)
		);
		$this->assertSame( array( str_repeat( 'ab', 16 ) . '.r2.cloudflarestorage.com', 'path', 'auto' ), array( $r2['host'], $r2['addressing'], $r2['region'] ) );

		$b2 = $this->resolve(
			array(
				'preset' => 'b2',
				'region' => 'us-west-004',
			)
		);
		$this->assertSame( array( 's3.us-west-004.backblazeb2.com', 'us-west-004' ), array( $b2['host'], $b2['region'] ) );

		$wasabi = $this->resolve(
			array(
				'preset' => 'wasabi',
				'region' => 'eu-central-1',
			)
		);
		$this->assertSame( 's3.eu-central-1.wasabisys.com', $wasabi['host'] );

		$spaces = $this->resolve(
			array(
				'preset' => 'spaces',
				'region' => 'nyc3',
			)
		);
		$this->assertSame( array( 'nyc3.digitaloceanspaces.com', 'us-east-1' ), array( $spaces['host'], $spaces['region'] ), 'Spaces: the signing region is only validated' );

		$custom = $this->resolve(
			array(
				'preset'   => 'custom',
				'endpoint' => 'https://s3.example.com',
			)
		);
		$this->assertSame( array( 's3.example.com', 'path', 'us-east-1' ), array( $custom['host'], $custom['addressing'], $custom['region'] ) );
	}

	/**
	 * @dataProvider invalid
	 */
	public function test_invalid_settings_are_refused( array $settings ) {
		$this->expectException( DestinationException::class );
		$this->resolve( $settings );
	}

	public function invalid() {
		$c = array(
			'preset'   => 'custom',
			'endpoint' => 'https://s3.example.com',
		);
		return array(
			'unknown preset'       => array(
				array(
					'preset' => 'nope',
					'region' => 'x',
				),
			),
			'aws no region'        => array( array( 'preset' => 'aws' ) ),
			'aws bad region'       => array(
				array(
					'preset' => 'aws',
					'region' => 'eu-central-1.evil.com/x',
				),
			),
			'aws host injection'   => array(
				array(
					'preset' => 'aws',
					'region' => 'us-east-1@evil.test',
				),
			),
			'r2 short account'     => array(
				array(
					'preset'     => 'r2',
					'account_id' => 'abc',
				),
			),
			'r2 account injection' => array(
				array(
					'preset'     => 'r2',
					'account_id' => str_repeat( 'a', 31 ) . '.',
				),
			),
			'b2 two digits'        => array(
				array(
					'preset' => 'b2',
					'region' => 'us-west-04',
				),
			),
			'spaces bad slug'      => array(
				array(
					'preset' => 'spaces',
					'region' => 'new-york',
				),
			),
			'custom no endpoint'   => array( array( 'preset' => 'custom' ) ),
			'custom http'          => array(
				array(
					'preset'   => 'custom',
					'endpoint' => 'http://s3.example.com',
				),
			),
			'custom metadata'      => array(
				array(
					'preset'                 => 'custom',
					'endpoint'               => 'http://169.254.169.254',
					'allow_insecure_http'    => true,
					'allow_private_endpoint' => true,
				),
			),
			'custom bad region'    => array( array_merge( $c, array( 'region' => 'US East' ) ) ),
			'bucket empty'         => array( array_merge( $c, array( 'bucket' => '' ) ) ),
			'bucket upper'         => array( array_merge( $c, array( 'bucket' => 'MyBucket' ) ) ),
			'bucket slash'         => array( array_merge( $c, array( 'bucket' => 'a/b' ) ) ),
			'bucket ip'            => array( array_merge( $c, array( 'bucket' => '192.168.1.1' ) ) ),
			'bucket dotdot'        => array( array_merge( $c, array( 'bucket' => 'a..b' ) ) ),
			'bucket short'         => array( array_merge( $c, array( 'bucket' => 'ab' ) ) ),
			'prefix dotdot'        => array( array_merge( $c, array( 'prefix' => 'a/../b' ) ) ),
			'prefix dot'           => array( array_merge( $c, array( 'prefix' => './a' ) ) ),
			'prefix control'       => array( array_merge( $c, array( 'prefix' => "a\nb" ) ) ),
			'storage class bad'    => array( array_merge( $c, array( 'storage_class' => 'GLACIER' ) ) ),
		);
	}

	public function test_a_preset_ignores_a_foreign_endpoint_setting() {
		$aws = $this->resolve(
			array(
				'preset'   => 'aws',
				'region'   => 'us-east-1',
				'endpoint' => 'https://evil.test',
			)
		);
		$this->assertSame( 's3.us-east-1.amazonaws.com', $aws['host'] );
	}

	public function test_addressing_falls_back_to_path_style_for_a_dotted_bucket_or_an_ip() {
		$dotted = $this->resolve(
			array(
				'preset' => 'aws',
				'region' => 'us-east-1',
				'bucket' => 'my.dotted.bucket',
			)
		);
		$this->assertSame( 'path', $dotted['addressing'] );
		$ip = $this->resolve(
			array(
				'preset'     => 'custom',
				'endpoint'   => 'https://203.0.113.5',
				'addressing' => 'virtual',
			)
		);
		$this->assertSame( 'path', $ip['addressing'] );
		$virtual = $this->resolve(
			array(
				'preset'     => 'custom',
				'endpoint'   => 'https://s3.example.com',
				'addressing' => 'virtual',
			)
		);
		$this->assertSame( 'virtual', $virtual['addressing'] );
		$forced = $this->resolve(
			array(
				'preset'     => 'aws',
				'region'     => 'us-east-1',
				'addressing' => 'path',
			)
		);
		$this->assertSame( 'path', $forced['addressing'] );
	}

	public function test_prefix_and_storage_class_are_normalised() {
		$c = $this->resolve(
			array(
				'preset'        => 'custom',
				'endpoint'      => 'https://s3.example.com',
				'prefix'        => '//site-a//backups/',
				'storage_class' => 'standard_ia',
			)
		);
		$this->assertSame( 'site-a/backups', $c['prefix'] );
		$this->assertSame( 'STANDARD_IA', $c['storage_class'] );
		$std = $this->resolve(
			array(
				'preset'        => 'custom',
				'endpoint'      => 'https://s3.example.com',
				'storage_class' => 'STANDARD',
			)
		);
		$this->assertSame( '', $std['storage_class'], 'STANDARD is the default and is not sent' );
		$this->assertSame(
			'',
			$this->resolve(
				array(
					'preset'   => 'custom',
					'endpoint' => 'https://s3.example.com',
				)
			)['prefix']
		);
	}

	public function test_flags_allow_private_http_endpoints() {
		$c = $this->resolve(
			array(
				'preset'                 => 'custom',
				'endpoint'               => 'http://minio:9000',
				'allow_insecure_http'    => true,
				'allow_private_endpoint' => '1',
			)
		);
		$this->assertSame( array( 'http', 9000, true, true ), array( $c['scheme'], $c['port'], $c['private'], $c['allow_private'] ) );
		$this->assertFalse(
			$this->resolve(
				array(
					'preset' => 'aws',
					'region' => 'us-east-1',
				)
			)['allow_private']
		);
	}

	public function test_keys_are_below_the_prefix_and_the_openvault_root_only() {
		$path = 'OpenVault-Backups/site/2026-01-02_030405_3f2a9c1e/files.zip';
		$this->assertSame( $path, S3Presets::key( '', $path ) );
		$this->assertSame( 'p/q/' . $path, S3Presets::key( 'p/q', $path ) );
		foreach ( array( '../x', 'OpenVault-Backups/../x', '/OpenVault-Backups/a', 'other/file', 'OpenVault-Backups', 'OpenVault-Backups//a', "OpenVault-Backups/a\0b", 'OpenVault-Backups/' . str_repeat( 'a/', 600 ) ) as $bad ) {
			try {
				S3Presets::key( '', $bad );
				$this->fail( 'accepted: ' . bin2hex( $bad ) );
			} catch ( DestinationException $e ) {
				$this->assertFalse( $e->retryable() );
			}
		}
	}
}
