<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Budget;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Budget
 */
class BudgetTest extends TestCase {

	/**
	 * @dataProvider seconds_provider
	 */
	public function test_seconds_for( $max_execution_time, $expected ) {
		$this->assertSame( $expected, Budget::seconds_for( $max_execution_time ) );
	}

	public function seconds_provider() {
		return array(
			'unlimited (0)'     => array( 0, 20 ),
			'negative'          => array( -5, 20 ),
			'30 s default'      => array( 30, 21 ),
			'15 s low'          => array( 15, 10 ),
			'tiny is lifted'    => array( 5, 5 ),
			'1 s is lifted'     => array( 1, 5 ),
			'60 s is capped'    => array( 60, 25 ),
			'300 s is capped'   => array( 300, 25 ),
			'36 s hits the cap' => array( 36, 25 ),
			'35 s rounds down'  => array( 35, 24 ),
		);
	}

	/**
	 * @dataProvider bytes_provider
	 */
	public function test_parse_bytes( $input, $expected ) {
		$this->assertSame( $expected, Budget::parse_bytes( $input ) );
	}

	public function bytes_provider() {
		return array(
			'plain'         => array( '1024', 1024 ),
			'kilobytes'     => array( '512K', 512 * 1024 ),
			'lower k'       => array( '512k', 512 * 1024 ),
			'megabytes'     => array( '128M', 128 * 1024 * 1024 ),
			'gigabytes'     => array( '2G', 2 * 1024 * 1024 * 1024 ),
			'with b suffix' => array( '64MB', 64 * 1024 * 1024 ),
			'with spaces'   => array( ' 256 M ', 256 * 1024 * 1024 ),
			'unlimited'     => array( '-1', -1 ),
			'unlimited int' => array( -1, -1 ),
			'int'           => array( 4096, 4096 ),
			'garbage'       => array( 'lots', 0 ),
			'empty'         => array( '', 0 ),
			'negative text' => array( '-5M', 0 ),
			'array'         => array( array(), 0 ),
		);
	}

	/**
	 * @dataProvider ceiling_provider
	 */
	public function test_memory_ceiling_for( $limit, $expected ) {
		$this->assertSame( $expected, Budget::memory_ceiling_for( $limit ) );
	}

	public function ceiling_provider() {
		return array(
			'128M is 80%'         => array( '128M', (int) floor( 128 * 1024 * 1024 * 0.8 ) ),
			'64M'                 => array( '64M', (int) floor( 64 * 1024 * 1024 * 0.8 ) ),
			'1G'                  => array( '1G', (int) floor( 1024 * 1024 * 1024 * 0.8 ) ),
			'unlimited gets 256M' => array( '-1', 256 * 1024 * 1024 ),
			'unreadable is 128M'  => array( '', (int) floor( 128 * 1024 * 1024 * 0.8 ) ),
			'zero is 128M'        => array( '0', (int) floor( 128 * 1024 * 1024 * 0.8 ) ),
			'tiny is lifted'      => array( '1M', 8 * 1024 * 1024 ),
		);
	}

	public function test_from_environment_is_sane() {
		$budget = Budget::from_environment();
		$this->assertGreaterThanOrEqual( 5, $budget->seconds() );
		$this->assertLessThanOrEqual( 25, $budget->seconds() );
		$this->assertGreaterThan( 0, $budget->memory_ceiling() );
		$this->assertFalse( $budget->exhausted() );
	}

	public function test_time_runs_out_with_a_fake_clock() {
		$time   = 1000.0;
		$budget = new Budget(
			10,
			PHP_INT_MAX,
			function () use ( &$time ) {
				return $time;
			},
			function () {
				return 0;
			}
		);

		$this->assertSame( 0.0, $budget->elapsed() );
		$this->assertSame( 10.0, $budget->time_left() );
		$this->assertFalse( $budget->exhausted() );

		$time = 1004.5;
		$this->assertSame( 4.5, $budget->elapsed() );
		$this->assertSame( 5.5, $budget->time_left() );
		$this->assertFalse( $budget->exhausted() );

		$time = 1010.0;
		$this->assertSame( 0.0, $budget->time_left() );
		$this->assertTrue( $budget->exhausted() );

		$time = 5000.0;
		$this->assertSame( 0.0, $budget->time_left(), 'never negative' );
		$this->assertTrue( $budget->exhausted() );
	}

	public function test_clock_going_backwards_is_harmless() {
		$time   = 1000.0;
		$budget = new Budget(
			10,
			PHP_INT_MAX,
			function () use ( &$time ) {
				return $time;
			}
		);
		$time   = 900.0;
		$this->assertSame( 0.0, $budget->elapsed() );
		$this->assertFalse( $budget->exhausted() );
	}

	public function test_memory_exhaustion_with_a_fake_probe() {
		$used   = 10;
		$budget = new Budget(
			1000,
			100,
			null,
			function () use ( &$used ) {
				return $used;
			}
		);

		$this->assertTrue( $budget->memory_ok() );
		$this->assertFalse( $budget->exhausted() );

		$used = 99;
		$this->assertTrue( $budget->memory_ok() );

		$used = 100;
		$this->assertFalse( $budget->memory_ok(), 'reaching the ceiling counts as exhausted' );
		$this->assertTrue( $budget->exhausted() );
	}

	public function test_zero_seconds_is_exhausted_at_once() {
		$budget = new Budget( 0, PHP_INT_MAX );
		$this->assertTrue( $budget->exhausted() );
	}

	public function test_accessors() {
		$budget = new Budget( 7, 12345 );
		$this->assertSame( 7, $budget->seconds() );
		$this->assertSame( 12345, $budget->memory_ceiling() );
	}
}
