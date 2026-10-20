<?php
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\S3\EndpointValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Destinations\S3\EndpointValidator
 */
class EndpointValidatorTest extends TestCase {

	public function test_normal_https_endpoints_are_accepted_and_normalised() {
		$a = EndpointValidator::parse( 'https://s3.eu-west-1.amazonaws.com' );
		$this->assertSame( array( 'https', 's3.eu-west-1.amazonaws.com', null, false, false ), array( $a['scheme'], $a['host'], $a['port'], $a['private'], $a['ip'] ) );

		$b = EndpointValidator::parse( '  HTTPS://S3.Example.COM./ ' );
		$this->assertSame( 's3.example.com', $b['host'], 'upper case and one trailing dot are normalised' );

		$this->assertNull( EndpointValidator::parse( 'https://s3.example.com:443' )['port'], 'the default port is dropped' );
		$this->assertSame( 'https://xn--bcher-kva.example', EndpointValidator::origin( EndpointValidator::parse( 'https://xn--bcher-kva.example' ) ) );
		$this->assertSame( 8080, EndpointValidator::parse( 'https://s3.example.com:8080' )['port'] );
	}

	/**
	 * @dataProvider hostile
	 */
	public function test_hostile_input_is_refused( $endpoint, $http = false, $private = false ) {
		$this->expectException( DestinationException::class );
		EndpointValidator::parse( $endpoint, $http, $private );
	}

	public function hostile() {
		return array(
			'empty'                        => array( '' ),
			'not a string'                 => array( array( 'x' ) ),
			'no scheme'                    => array( 's3.example.com' ),
			'ftp'                          => array( 'ftp://s3.example.com' ),
			'file'                         => array( 'file:///etc/passwd' ),
			'javascript'                   => array( 'javascript://x' ),
			'userinfo'                     => array( 'https://user:pass@s3.example.com' ),
			'user only'                    => array( 'https://user@s3.example.com' ),
			'path'                         => array( 'https://s3.example.com/path' ),
			'query'                        => array( 'https://s3.example.com/path?x=1' ),
			'bare query'                   => array( 'https://s3.example.com?x' ),
			'fragment'                     => array( 'https://s3.example.com#x' ),
			'space'                        => array( 'https://s3 .example.com' ),
			'newline'                      => array( "https://s3.example.com\nhost" ),
			'backslash'                    => array( 'https://s3.example.com\\@evil.test' ),
			'idn'                          => array( "https://b\xc3\xbccher.example" ),
			'underscore host'              => array( 'https://bad_host.example.com' ),
			'empty label'                  => array( 'https://a..example.com' ),
			'dash edge'                    => array( 'https://-a.example.com' ),
			'port 0'                       => array( 'https://s3.example.com:0' ),
			'port too big'                 => array( 'https://s3.example.com:70000' ),
			'dangling colon'               => array( 'https://s3.example.com:' ),
			'public odd port'              => array( 'https://s3.example.com:9000' ),
			'plain http'                   => array( 'http://s3.example.com' ),
			'http public with flag'        => array( 'http://s3.example.com', true, true ),
			'metadata ip'                  => array( 'http://169.254.169.254/', true, true ),
			'metadata ip https'            => array( 'https://169.254.169.254', true, true ),
			'link local other'             => array( 'https://169.254.1.1', true, true ),
			'metadata google'              => array( 'https://metadata.google.internal', true, true ),
			'metadata google http'         => array( 'http://metadata.google.internal', true, true ),
			'aws ipv6 metadata'            => array( 'https://[fd00:ec2::254]', true, true ),
			'ipv6 link local'              => array( 'https://[fe80::1]', true, true ),
			'ipv6 link local upper'        => array( 'https://[FEBF::1]', true, true ),
			'ipv4 mapped metadata'         => array( 'https://[::ffff:169.254.169.254]', true, true ),
			'ipv6 unspecified'             => array( 'https://[::]', true, true ),
			'ipv4 unspecified'             => array( 'https://0.0.0.0', true, true ),
			'zero network'                 => array( 'https://0.1.2.3', true, true ),
			'broadcast'                    => array( 'https://255.255.255.255', true, true ),
			'hex ip'                       => array( 'https://0x7f.1/', true, true ),
			'full hex ip'                  => array( 'https://0x7f000001', true, true ),
			'decimal ip'                   => array( 'https://2130706433/', true, true ),
			'octal ip'                     => array( 'https://017700000001', true, true ),
			'short ip'                     => array( 'https://127.1', true, true ),
			'leading zero quad'            => array( 'https://010.0.0.1', true, true ),
			'hex metadata'                 => array( 'https://0xa9fea9fe', true, true ),
			'decimal metadata'             => array( 'https://2852039166', true, true ),
			'bad ipv6'                     => array( 'https://[zz::1]' ),
			'loopback without flag'        => array( 'https://127.0.0.1' ),
			'loopback ipv6 without flag'   => array( 'https://[::1]' ),
			'localhost without flag'       => array( 'https://localhost' ),
			'docker host without flag'     => array( 'https://minio:9000' ),
			'private 10 without flag'      => array( 'https://10.0.0.5' ),
			'private 192 without flag'     => array( 'https://192.168.1.5', true ),
			'cgnat without flag'           => array( 'https://100.64.0.1' ),
			'http private no http flag'    => array( 'http://minio:9000', false, true ),
			'http private no private flag' => array( 'http://minio:9000', true, false ),
			'too long'                     => array( 'https://' . str_repeat( 'a', 300 ) . '.com' ),
		);
	}

	public function test_private_endpoints_need_both_flags_for_http() {
		$minio = EndpointValidator::parse( 'http://minio:9000', true, true );
		$this->assertSame( array( 'http', 'minio', 9000, true ), array( $minio['scheme'], $minio['host'], $minio['port'], $minio['private'] ) );
		$this->assertSame( 'http://minio:9000', EndpointValidator::origin( $minio ) );

		$this->assertTrue( EndpointValidator::parse( 'https://[::1]', false, true )['ip'] );
		$this->assertTrue( EndpointValidator::parse( 'http://127.0.0.1:9000', true, true )['private'] );
		$this->assertTrue( EndpointValidator::parse( 'http://nas.local:9000', true, true )['private'] );
		$this->assertTrue( EndpointValidator::parse( 'https://192.168.0.10:9000', false, true )['private'] );
		$this->assertNull( EndpointValidator::parse( 'http://minio:80', true, true )['port'], 'default http port dropped' );
	}

	public function test_public_ip_literals_are_accepted() {
		$p = EndpointValidator::parse( 'https://203.0.113.10' );
		$this->assertTrue( $p['ip'] );
		$this->assertFalse( $p['private'] );
		$this->assertFalse( EndpointValidator::parse( 'https://[2606:4700::1111]' )['private'] );
	}

	public function test_error_messages_never_echo_the_input() {
		try {
			EndpointValidator::parse( 'https://user:SECRETPASS@s3.example.com' );
			$this->fail( 'expected refusal' );
		} catch ( DestinationException $e ) {
			$this->assertStringNotContainsString( 'SECRETPASS', $e->getMessage() );
			$this->assertFalse( $e->retryable() );
		}
	}
}
