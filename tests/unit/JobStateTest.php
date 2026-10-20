<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobState;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\JobState
 */
class JobStateTest extends TestCase {

	const NOW = 1700000000;

	public function test_defaults() {
		$state = JobState::new_state( 'preflight', self::NOW );
		$this->assertSame( 1, $state->get_version() );
		$this->assertSame( 'preflight', $state->get_step() );
		$this->assertSame( array(), $state->get_cursor() );
		$this->assertSame( self::NOW, $state->get_heartbeat() );
		$this->assertSame( self::NOW, $state->get_started_at() );
		$this->assertSame( self::NOW, $state->get_updated_at() );
		$this->assertSame( 0, $state->get_attempts() );
		$this->assertNull( $state->get_error() );
		$this->assertSame( array(), $state->get_meta() );
		$this->assertFalse( $state->is_terminal() );
	}

	public function test_new_state_default_step_and_clock() {
		$before = time();
		$state  = JobState::new_state();
		$this->assertSame( JobState::STEP_PREFLIGHT, $state->get_step() );
		$this->assertGreaterThanOrEqual( $before, $state->get_started_at() );
	}

	public function test_round_trip() {
		$state = JobState::new_state( 'db_export', self::NOW )
			->with_cursor(
				array(
					'table'  => 'wp_posts',
					'offset' => 5000,
					'nested' => array( 'a' => array( 1, 2, 3 ) ),
				),
				self::NOW + 5
			)
			->with_meta( array( 'backup_id' => 12 ) )
			->increment_attempts( self::NOW + 6 );

		$copy = JobState::from_json( $state->to_json() );
		$this->assertEquals( $state, $copy );
		$this->assertSame( $state->to_array(), $copy->to_array() );
		$this->assertSame( 'wp_posts', $copy->get_cursor()['table'] );
		$this->assertSame( 1, $copy->get_attempts() );
	}

	public function test_to_array_keys() {
		$keys = array_keys( JobState::new_state( 'preflight', self::NOW )->to_array() );
		$this->assertSame( array( 'version', 'step', 'cursor', 'heartbeat', 'attempts', 'started_at', 'updated_at', 'error', 'meta' ), $keys );
	}

	public function test_all_steps_are_accepted() {
		foreach ( array( 'preflight', 'db_export', 'file_scan', 'archive', 'encrypt', 'upload', 'retention', 'cleanup', 'done', 'failed' ) as $step ) {
			$this->assertSame( $step, JobState::new_state( $step, self::NOW )->get_step() );
		}
	}

	public function test_unknown_step_is_rejected() {
		$this->expectException( \InvalidArgumentException::class );
		JobState::new_state( 'explode', self::NOW );
	}

	public function test_with_step_rejects_unknown_step() {
		$this->expectException( \InvalidArgumentException::class );
		JobState::new_state( 'preflight', self::NOW )->with_step( 'nope', array(), self::NOW );
	}

	public function test_with_step_resets_cursor_and_attempts() {
		$state = JobState::new_state( 'db_export', self::NOW )
			->with_cursor( array( 'offset' => 1 ), self::NOW )
			->increment_attempts( self::NOW );
		$next  = $state->with_step( 'file_scan', array(), self::NOW + 10 );
		$this->assertSame( 'file_scan', $next->get_step() );
		$this->assertSame( array(), $next->get_cursor() );
		$this->assertSame( 0, $next->get_attempts() );
		$this->assertSame( self::NOW + 10, $next->get_heartbeat() );
		$this->assertSame( self::NOW, $next->get_started_at() );
	}

	public function test_immutability() {
		$state = JobState::new_state( 'preflight', self::NOW );
		$json  = $state->to_json();

		$state->with_step( 'archive', array(), self::NOW + 1 );
		$state->with_cursor( array( 'x' => 1 ), self::NOW + 1 );
		$state->with_meta( array( 'x' => 1 ) );
		$state->touch( self::NOW + 99 );
		$state->mark_failed( 'bad', self::NOW + 1 );
		$state->increment_attempts( self::NOW + 1 );

		$this->assertSame( $json, $state->to_json() );
	}

	public function test_touch_updates_heartbeat() {
		$state = JobState::new_state( 'preflight', self::NOW )->touch( self::NOW + 30 );
		$this->assertSame( self::NOW + 30, $state->get_heartbeat() );
		$this->assertSame( self::NOW + 30, $state->get_updated_at() );
		$this->assertSame( self::NOW, $state->get_started_at() );
	}

	public function test_is_stalled_boundaries() {
		$state = JobState::new_state( 'archive', self::NOW );
		$this->assertFalse( $state->is_stalled( self::NOW, 300 ) );
		$this->assertFalse( $state->is_stalled( self::NOW + 299, 300 ) );
		$this->assertTrue( $state->is_stalled( self::NOW + 300, 300 ) );
		$this->assertTrue( $state->is_stalled( self::NOW + 301, 300 ) );
		// Clock going backwards is never "stalled".
		$this->assertFalse( $state->is_stalled( self::NOW - 1000, 300 ) );
		// A heartbeat resets the clock.
		$this->assertFalse( $state->touch( self::NOW + 290 )->is_stalled( self::NOW + 400, 300 ) );
	}

	public function test_terminal_states_are_never_stalled() {
		$done   = JobState::new_state( 'done', self::NOW );
		$failed = JobState::new_state( 'preflight', self::NOW )->mark_failed( 'boom', self::NOW + 1 );
		$this->assertTrue( $done->is_terminal() );
		$this->assertTrue( $failed->is_terminal() );
		$this->assertFalse( $done->is_stalled( self::NOW + 99999, 60 ) );
		$this->assertFalse( $failed->is_stalled( self::NOW + 99999, 60 ) );
	}

	public function test_mark_failed() {
		$failed = JobState::new_state( 'upload', self::NOW )
			->with_cursor( array( 'part' => 2 ), self::NOW )
			->mark_failed( 'Upload failed.', self::NOW + 5 );
		$this->assertSame( 'failed', $failed->get_step() );
		$this->assertSame( 'Upload failed.', $failed->get_error() );
		$this->assertSame( array( 'part' => 2 ), $failed->get_cursor() );
		$this->assertSame( self::NOW + 5, $failed->get_updated_at() );

		$this->assertSame( 'Upload failed.', JobState::from_json( $failed->to_json() )->get_error() );
	}

	public function test_mark_failed_truncates_long_message() {
		$failed = JobState::new_state( 'upload', self::NOW )->mark_failed( str_repeat( 'x', 5000 ), self::NOW );
		$this->assertLessThanOrEqual( 1000, strlen( $failed->get_error() ) );
	}

	public function test_malformed_json() {
		foreach ( array( '', 'not json', '{', '[]', '"str"', '123', 'null' ) as $bad ) {
			try {
				JobState::from_json( $bad );
				$this->fail( 'Expected exception for: ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertInstanceOf( \InvalidArgumentException::class, $e );
			}
		}
	}

	public function test_unknown_or_missing_version() {
		foreach ( array( '{"version":2,"step":"preflight"}', '{"version":"1","step":"preflight"}', '{"step":"preflight"}' ) as $bad ) {
			try {
				JobState::from_json( $bad );
				$this->fail( 'Expected exception for: ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertInstanceOf( \InvalidArgumentException::class, $e );
			}
		}
	}

	public function test_unknown_step_in_json() {
		$this->expectException( \InvalidArgumentException::class );
		JobState::from_json( '{"version":1,"step":"hack"}' );
	}

	public function test_wrong_field_types_rejected() {
		foreach (
			array(
				'{"version":1,"step":"preflight","cursor":"x"}',
				'{"version":1,"step":"preflight","heartbeat":"1"}',
				'{"version":1,"step":"preflight","attempts":-1}',
				'{"version":1,"step":"preflight","error":5}',
				'{"version":1,"step":"preflight","meta":"x"}',
			) as $bad
		) {
			try {
				JobState::from_json( $bad );
				$this->fail( 'Expected exception for: ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertInstanceOf( \InvalidArgumentException::class, $e );
			}
		}
	}

	public function test_missing_optional_fields_tolerated() {
		$state = JobState::from_json( '{"version":1,"step":"archive"}' );
		$this->assertSame( 'archive', $state->get_step() );
		$this->assertSame( array(), $state->get_cursor() );
		$this->assertSame( 0, $state->get_attempts() );
		$this->assertNull( $state->get_error() );
		$this->assertSame( array(), $state->get_meta() );
	}

	public function test_unknown_extra_keys_ignored() {
		$state = JobState::from_json( '{"version":1,"step":"archive","evil":{"a":1},"cursor":{"i":3}}' );
		$this->assertArrayNotHasKey( 'evil', $state->to_array() );
		$this->assertSame( array( 'i' => 3 ), $state->get_cursor() );
	}

	public function test_oversize_cursor_rejected() {
		$state = JobState::new_state( 'file_scan', self::NOW );
		try {
			$state->with_cursor( array( 'blob' => str_repeat( 'a', 70000 ) ), self::NOW );
			$this->fail( 'Expected exception.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertInstanceOf( \InvalidArgumentException::class, $e );
		}

		$json = '{"version":1,"step":"file_scan","cursor":{"blob":"' . str_repeat( 'a', 70000 ) . '"}}';
		$this->expectException( \InvalidArgumentException::class );
		JobState::from_json( $json );
	}

	public function test_cursor_just_under_limit_is_accepted() {
		$state = JobState::new_state( 'file_scan', self::NOW )->with_cursor( array( 'blob' => str_repeat( 'a', 60000 ) ), self::NOW );
		$this->assertSame( 60000, strlen( $state->get_cursor()['blob'] ) );
	}

	public function test_cursor_must_be_json_safe() {
		$bad_values = array(
			array( 'o' => new \stdClass() ),
			array( 'f' => NAN ),
			array( 'f' => INF ),
			array( 'r' => fopen( 'php://memory', 'r' ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Resource must be rejected.
			array( 's' => "\xB1\x31" ),
			array( 'cb' => function () {} ),
		);
		foreach ( $bad_values as $cursor ) {
			try {
				JobState::new_state( 'archive', self::NOW )->with_cursor( $cursor, self::NOW );
				$this->fail( 'Expected exception.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertInstanceOf( \InvalidArgumentException::class, $e );
			}
		}
	}

	public function test_cursor_depth_is_limited() {
		$deep = array( 'x' => 1 );
		for ( $i = 0; $i < 40; $i++ ) {
			$deep = array( 'n' => $deep );
		}
		$this->expectException( \InvalidArgumentException::class );
		JobState::new_state( 'archive', self::NOW )->with_cursor( $deep, self::NOW );
	}

	public function test_json_never_contains_php_serialization() {
		$json = JobState::new_state( 'upload', self::NOW )
			->with_cursor(
				array(
					'part'    => 3,
					'session' => 'abc',
				),
				self::NOW
			)
			->to_json();
		$this->assertIsArray( json_decode( $json, true ) );
		$this->assertDoesNotMatchRegularExpression( '/(^|[;{])[aOsibdCN]:\d+[:;]/', $json );
		$this->assertStringNotContainsString( 'O:8:', $json );
		$this->assertFalse( @unserialize( $json ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- proving it is not serialized data.
	}

	public function test_from_json_never_unserializes() {
		$this->expectException( \InvalidArgumentException::class );
		JobState::from_json( 'a:2:{s:7:"version";i:1;s:4:"step";s:9:"preflight";}' );
	}
}
