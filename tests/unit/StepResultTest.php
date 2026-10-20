<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\StepResult
 */
class StepResultTest extends TestCase {

	private function state() {
		return JobState::new_state( 'preflight', 1700000000 );
	}

	public function test_continue() {
		$state  = $this->state()->with_cursor( array( 'offset' => 5 ) );
		$result = StepResult::continue_step( $state );

		$this->assertTrue( $result->is_continue() );
		$this->assertFalse( $result->is_next() || $result->is_fail() || $result->is_wait() );
		$this->assertSame( StepResult::KIND_CONTINUE, $result->kind() );
		$this->assertSame( $state, $result->state() );
		$this->assertSame( '', $result->message() );
		$this->assertSame( 0, $result->seconds() );
	}

	public function test_next() {
		$result = StepResult::next_step( $this->state() );
		$this->assertTrue( $result->is_next() );
		$this->assertSame( StepResult::KIND_NEXT, $result->kind() );
	}

	public function test_fail_carries_the_message() {
		$result = StepResult::fail( 'Not enough free disk space.', $this->state() );
		$this->assertTrue( $result->is_fail() );
		$this->assertSame( 'Not enough free disk space.', $result->message() );
		$this->assertSame( StepResult::KIND_FAIL, $result->kind() );
	}

	/**
	 * @dataProvider wait_provider
	 */
	public function test_wait_clamps_the_delay( $given, $expected ) {
		$result = StepResult::wait( $given, $this->state() );
		$this->assertTrue( $result->is_wait() );
		$this->assertSame( $expected, $result->seconds() );
	}

	public function wait_provider() {
		return array(
			'normal'   => array( 30, 30 ),
			'zero'     => array( 0, 1 ),
			'negative' => array( -10, 1 ),
			'huge'     => array( 999999, 3600 ),
		);
	}
}
