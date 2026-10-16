<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Support\Logger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Support\Logger
 */
class LoggerTest extends TestCase {

	public function test_redact_masks_sensitive_keys() {
		$out = Logger::redact(
			array(
				'bucket'        => 'my-bucket',
				'secret_key'    => 'abc',
				'refresh_token' => 'xyz',
				'Authorization' => 'Bearer 123',
				'password'      => 'hunter2',
			)
		);

		$this->assertSame( 'my-bucket', $out['bucket'] );
		$this->assertSame( Logger::REDACTED, $out['secret_key'] );
		$this->assertSame( Logger::REDACTED, $out['refresh_token'] );
		$this->assertSame( Logger::REDACTED, $out['Authorization'] );
		$this->assertSame( Logger::REDACTED, $out['password'] );
	}

	public function test_redact_recurses_into_nested_arrays() {
		$out = Logger::redact(
			array(
				'request' => array(
					'headers' => array(
						'api_key' => 'k',
						'accept'  => 'json',
					),
				),
			)
		);

		$this->assertSame( Logger::REDACTED, $out['request']['headers']['api_key'] );
		$this->assertSame( 'json', $out['request']['headers']['accept'] );
	}

	public function test_redact_replaces_objects_with_class_name() {
		$out = Logger::redact( array( 'error' => new \RuntimeException( 'boom' ) ) );

		$this->assertSame( '[object RuntimeException]', $out['error'] );
	}

	public function test_redact_stops_at_max_depth() {
		$deep = array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => array( 'g' => 1 ) ) ) ) ) ) );
		$out  = Logger::redact( $deep );

		$this->assertSame( '[max depth]', $out['a']['b']['c']['d']['e']['f'] );
	}

	public function test_normalize_level_falls_back_to_info() {
		$this->assertSame( 'error', Logger::normalize_level( 'ERROR' ) );
		$this->assertSame( 'info', Logger::normalize_level( 'nonsense' ) );
		$this->assertSame( 'info', Logger::normalize_level( null ) );
	}

	public function test_should_log_respects_minimum_level() {
		$this->assertTrue( Logger::should_log( 'error', 'info' ) );
		$this->assertTrue( Logger::should_log( 'info', 'info' ) );
		$this->assertFalse( Logger::should_log( 'debug', 'info' ) );
		$this->assertFalse( Logger::should_log( 'warning', 'error' ) );
	}
}
