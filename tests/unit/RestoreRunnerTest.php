<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestoreRunner;
use PHPUnit\Framework\TestCase;

/**
 * Pure decisions of the restore runner and the job meta (the runner itself runs against a real site in the matrix).
 *
 * @covers \OpenVault\Backup\Restore\RestoreRunner
 * @covers \OpenVault\Backup\Restore\RestoreMeta
 */
class RestoreRunnerTest extends TestCase {

	public function test_step_order_is_the_documented_one() {
		$this->assertSame(
			array( 'preflight', 'fetch', 'verify', 'snapshot', 'db_restore', 'file_restore', 'file_undo', 'finish' ),
			RestoreRunner::STEP_ORDER
		);
		foreach ( RestoreRunner::STEP_ORDER as $step ) {
			$this->assertContains( $step, JobState::STEPS );
		}
	}

	public function test_next_step_follows_the_order_and_ends_in_done() {
		$this->assertSame( 'fetch', RestoreRunner::next_step_id( 'preflight' ) );
		$this->assertSame( 'verify', RestoreRunner::next_step_id( 'fetch' ) );
		$this->assertSame( 'snapshot', RestoreRunner::next_step_id( 'verify' ) );
		$this->assertSame( 'file_undo', RestoreRunner::next_step_id( 'file_restore' ) );
		$this->assertSame( 'finish', RestoreRunner::next_step_id( 'file_undo' ) );
		$this->assertSame( 'done', RestoreRunner::next_step_id( 'finish' ) );
		$this->assertSame( 'done', RestoreRunner::next_step_id( 'unknown' ) );
		$this->assertSame( 'done', RestoreRunner::next_step_id( 'db_export' ), 'a backup step is not a restore step' );
	}

	public function test_a_step_that_returns_another_step_jumps_there() {
		$this->assertSame( 'fetch', RestoreRunner::target_step( 'verify', 'fetch' ), 'verify goes back to fetch with the next source' );
		$this->assertSame( 'verify', RestoreRunner::target_step( 'fetch', 'fetch' ), 'no jump: the following step' );
		$this->assertSame( 'fetch', RestoreRunner::target_step( 'preflight', 'preflight' ) );
		$this->assertSame( 'verify', RestoreRunner::target_step( 'fetch', 'failed' ), 'a terminal or unknown step is no jump' );
		$this->assertSame( 'verify', RestoreRunner::target_step( 'fetch', 'db_export' ), 'a backup step is no jump' );
	}

	public function test_the_job_waits_for_the_user_in_front_of_the_first_apply_step() {
		$this->assertTrue( RestoreRunner::is_gate( 'snapshot', array() ) );
		$this->assertTrue( RestoreRunner::is_gate( 'snapshot', array( 'confirmed' => false ) ) );
		$this->assertTrue( RestoreRunner::is_gate( 'snapshot', array( 'confirmed' => 'yes' ) ), 'only a real true confirms' );
		$this->assertFalse( RestoreRunner::is_gate( 'snapshot', array( 'confirmed' => true ) ) );
		foreach ( array( 'preflight', 'fetch', 'verify', 'db_restore', 'file_restore', 'finish' ) as $step ) {
			$this->assertFalse( RestoreRunner::is_gate( $step, array() ), $step );
		}
		$this->assertSame( 'snapshot', RestoreRunner::GATE_STEP );
	}

	public function test_after_verify_the_next_step_is_the_gate() {
		$this->assertSame( RestoreRunner::GATE_STEP, RestoreRunner::next_step_id( 'verify' ) );
	}

	public function test_limits_match_the_backup_runner() {
		$this->assertSame( 3, RestoreRunner::MAX_ATTEMPTS );
		$this->assertSame( array( 30, 120 ), RestoreRunner::BACKOFF );
		$this->assertSame( 'ovb_run_restore', RestoreRunner::ACTION );
		$this->assertSame( 'openvault-backup', RestoreRunner::GROUP );
		$this->assertSame( 'restore', RestoreRunner::LOCK_KEY );
	}

	public function test_meta_round_trips_and_stays_small() {
		$meta = RestoreMeta::make( 9, 'db', array( 'url_mismatch', 'url_mismatch', 5 ) );

		$this->assertSame( 9, RestoreMeta::restore_id( $meta ) );
		$this->assertSame( 'db', RestoreMeta::mode( $meta ) );
		$this->assertSame( array( 'url_mismatch' ), RestoreMeta::ack( $meta ) );
		$this->assertSame( array(), RestoreMeta::tried( $meta ) );
		$this->assertFalse( RestoreMeta::confirmed( $meta ) );

		$state = JobState::new_state( 'preflight' )->with_meta( $meta );
		$this->assertSame( $meta, JobState::from_json( $state->to_json() )->get_meta() );
	}

	public function test_meta_tried_is_deduplicated_and_capped() {
		$meta = RestoreMeta::make( 1, 'full' );
		$meta = RestoreMeta::with_tried( RestoreMeta::with_tried( $meta, 0 ), 0 );
		$this->assertSame( array( 0 ), RestoreMeta::tried( $meta ) );

		for ( $i = 1; $i < 60; $i++ ) {
			$meta = RestoreMeta::with_tried( $meta, $i );
		}
		$this->assertCount( RestoreMeta::MAX_TRIED, RestoreMeta::tried( $meta ) );
		$this->assertLessThan( JobState::MAX_META_BYTES, strlen( json_encode( $meta ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_meta_readers_ignore_garbage() {
		$this->assertSame( 0, RestoreMeta::restore_id( array( 'restore_id' => '5' ) ) );
		$this->assertSame( 'full', RestoreMeta::mode( array( 'mode' => 'everything' ) ) );
		$this->assertSame( array( 'ok' ), RestoreMeta::ack( array( 'ack' => array( 'ok', 7, null ) ) ) );
		$this->assertSame( array( 3 ), RestoreMeta::tried( array( 'tried' => array( 3, '4', -1, 3, 'x' ) ) ) );
		$this->assertFalse( RestoreMeta::confirmed( array( 'confirmed' => 1 ) ) );
		$this->assertTrue( RestoreMeta::confirmed( array( 'confirmed' => true ) ) );
	}

	public function test_meta_helpers_of_the_apply_steps() {
		$meta = RestoreMeta::make( 5, 'db' );
		$this->assertFalse( RestoreMeta::keep_site_urls( $meta ) );
		$this->assertArrayNotHasKey( 'keep_urls', $meta );
		$this->assertTrue( RestoreMeta::keep_site_urls( RestoreMeta::make( 5, 'db', array( 'url_mismatch' ), true ) ) );
		$this->assertFalse( RestoreMeta::keep_site_urls( array( 'keep_urls' => 1 ) ), 'only a real true counts' );

		$this->assertFalse( RestoreMeta::confirmed( $meta ) );
		$this->assertTrue( RestoreMeta::confirmed( RestoreMeta::with_confirmed( $meta ) ) );

		$this->assertSame( 0, RestoreMeta::snapshot_id( $meta ) );
		$this->assertSame( 0, RestoreMeta::snapshot_id( array( 'snapshot' => array( 'id' => '5' ) ) ) );
		$this->assertSame( 0, RestoreMeta::snapshot_id( array( 'snapshot' => array( 'id' => -1 ) ) ) );
		$with = RestoreMeta::with_snapshot( $meta, 42, 1700000000 );
		$this->assertSame( 42, RestoreMeta::snapshot_id( $with ) );
		$this->assertSame( 1700000000, $with['snapshot']['at'] );
	}

	public function test_state_and_cursor_size_caps_hold_for_restore_cursors() {
		$state = JobState::new_state( 'fetch' )->with_cursor(
			array(
				'i'     => 9999,
				'off'   => PHP_INT_MAX,
				'tries' => 3,
				'n'     => 100000,
			)
		);
		$this->assertLessThan( 200, strlen( json_encode( $state->get_cursor() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.

		$this->expectException( \InvalidArgumentException::class );
		$state->with_cursor( array( 'blob' => str_repeat( 'x', JobState::MAX_CURSOR_BYTES ) ) );
	}

	public function test_a_restore_state_moves_through_the_restore_steps() {
		$state = JobState::new_state( 'preflight' );
		foreach ( array( 'fetch', 'verify', 'snapshot', 'db_restore', 'file_restore', 'finish', 'done' ) as $step ) {
			$state = $state->with_step( $step );
			$this->assertSame( $step, $state->get_step() );
		}
		$this->assertTrue( $state->is_terminal() );
	}
}
