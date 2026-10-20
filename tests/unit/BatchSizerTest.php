<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Dump\BatchSizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Dump\BatchSizer
 */
class BatchSizerTest extends TestCase {

	public function test_initial_sizes() {
		$this->assertSame( 500, BatchSizer::initial( false ) );
		$this->assertSame( 10, BatchSizer::initial( true ) );
	}

	public function test_halves_when_the_batch_exceeds_the_statement_cap() {
		$this->assertSame( 250, BatchSizer::next( 500, BatchSizer::STATEMENT_CAP + 1, false ) );
		$this->assertSame( 1000, BatchSizer::next( 2000, 10 * BatchSizer::STATEMENT_CAP, false ) );
	}

	public function test_halves_when_memory_is_tight_even_for_a_small_batch() {
		$this->assertSame( 250, BatchSizer::next( 500, 100, true ) );
	}

	public function test_never_goes_below_one_row() {
		$this->assertSame( 1, BatchSizer::next( 1, PHP_INT_MAX, true ) );
		$this->assertSame( 1, BatchSizer::next( 2, PHP_INT_MAX, false ) );
		$this->assertSame( 1, BatchSizer::next( 0, PHP_INT_MAX, false ) );
		$this->assertSame( 1, BatchSizer::next( -5, 0, true ) );
	}

	public function test_grows_back_when_small_and_stops_at_the_maximum() {
		$this->assertSame( 1000, BatchSizer::next( 500, 1000, false ) );
		$this->assertSame( 2000, BatchSizer::next( 1000, 1000, false ) );
		$this->assertSame( 2000, BatchSizer::next( 2000, 1000, false ) );
		$this->assertSame( 2000, BatchSizer::next( 99999, 1000, false ) );
	}

	public function test_keeps_the_size_in_the_middle_band() {
		// Larger than half the cap: doubling would exceed it, but it is not over the cap itself.
		$this->assertSame( 500, BatchSizer::next( 500, (int) ( BatchSizer::STATEMENT_CAP * 0.75 ), false ) );
		$this->assertSame( 500, BatchSizer::next( 500, BatchSizer::STATEMENT_CAP, false ) );
	}

	public function test_converges_for_large_rows() {
		// Rows of 300 KB: a batch only ever holds a couple of them.
		$size = BatchSizer::initial( true );
		for ( $i = 0; $i < 10; $i++ ) {
			$size = BatchSizer::next( $size, $size * 300000, false );
		}
		$this->assertSame( 1, $size );
	}

	public function test_converges_for_small_rows() {
		$size = BatchSizer::initial( false );
		for ( $i = 0; $i < 5; $i++ ) {
			$size = BatchSizer::next( $size, $size * 100, false );
		}
		$this->assertSame( 2000, $size );
	}
}
