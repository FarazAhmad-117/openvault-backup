<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestoreProgress as P;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Restore\RestoreProgress
 */
class RestoreProgressTest extends TestCase {

	private function meta( $mode, $db = null, $files = null ) {
		$meta = RestoreMeta::make( 1, $mode );
		return null === $db ? $meta : RestoreMeta::with_scope( $meta, $db, $files );
	}

	/**
	 * Steps in the order the runner takes them.
	 */
	private function order( $undo ) {
		return $undo
			? array( 'preflight', 'fetch', 'verify', 'db_restore', 'file_undo', 'finish' )
			: array( 'preflight', 'fetch', 'verify', 'snapshot', 'db_restore', 'file_restore', 'finish' );
	}

	public function test_bands_never_overlap_or_go_backwards_for_every_kind_of_job() {
		foreach ( array( true, false ) as $undo ) {
			foreach ( array( array( true, true ), array( true, false ), array( false, true ) ) as $scope ) {
				$bands = P::bands( $undo, $scope[0], $scope[1] );
				$last  = 0;
				foreach ( $this->order( $undo ) as $step ) {
					if ( ! isset( $bands[ $step ] ) ) {
						continue;
					}
					$skipped = ( $undo && ! $scope[0] && in_array( $step, array( 'preflight', 'fetch', 'verify' ), true ) ) || ( 'db_restore' === $step && ! $scope[0] ) || ( in_array( $step, array( 'file_restore', 'file_undo' ), true ) && ! $scope[1] ) || ( 'snapshot' === $step && ! $scope[0] );
					if ( $skipped ) {
						continue;
					}
					$this->assertGreaterThanOrEqual( $last, $bands[ $step ][0], $step . ' starts after the previous band ended' );
					$this->assertGreaterThanOrEqual( $bands[ $step ][0], $bands[ $step ][1] );
					$last = $bands[ $step ][1];
				}
				$this->assertSame( 100, $bands['finish'][1] );
			}
		}
	}

	public function test_percent_is_monotonic_along_a_full_restore() {
		$meta    = $this->meta( 'full', true, true );
		$last    = -1;
		$cursors = array(
			'preflight'    => array( array() ),
			'fetch'        => array( array(), array( 'i' => 1 ), array( 'i' => 40 ) ),
			'verify'       => array( array(), array( 'i' => 3 ) ),
			'snapshot'     => array( array() ),
			'db_restore'   => array(
				array( 'ph' => 'x' ),
				array( 'ph' => 'v' ),
				array( 'ph' => 'i' ),
				array(
					'ph'  => 'i',
					'off' => 500,
				),
				array(
					'ph'  => 'i',
					'off' => 1000,
				),
			),
			'file_restore' => array( array(), array( 'fl' => 10 ), array( 'fl' => 100000 ) ),
			'finish'       => array( array() ),
		);
		foreach ( $this->order( false ) as $step ) {
			foreach ( $cursors[ $step ] as $cursor ) {
				$meta['dump'] = array( 'size' => 1000 );
				$p            = P::percent( 'running', $step, $cursor, $meta );
				$this->assertGreaterThanOrEqual( $last, $p, $step );
				$last = max( $last, $p );
			}
		}
		$this->assertSame( 100, P::percent( 'complete', 'done', array(), $meta ) );
		$this->assertLessThan( 100, $last );
	}

	public function test_a_parked_job_waits_at_the_end_of_verify() {
		$this->assertSame( 40, P::percent( 'verified', JobState::STEP_SNAPSHOT, array(), $this->meta( 'full', true, true ) ) );
		$this->assertSame( 40, P::percent( 'verified', JobState::STEP_SNAPSHOT, array(), $this->meta( 'files', false, true ) ) );
	}

	public function test_scope_changes_the_bands() {
		$files_only = $this->meta( 'files', false, true );
		$this->assertSame( 40, P::percent( 'running', 'file_restore', array(), $files_only ) );
		$db_only = $this->meta( 'db', true, false );
		$this->assertGreaterThanOrEqual( 55, P::percent( 'running', 'db_restore', array(), $db_only ) );
		$this->assertLessThan( 60, P::percent( 'running', 'db_restore', array(), $db_only ) );
		$this->assertLessThanOrEqual(
			95,
			P::percent(
				'running',
				'db_restore',
				array(
					'ph'  => 'i',
					'off' => 10,
				),
				array_merge( $db_only, array( 'dump' => array( 'size' => 10 ) ) )
			)
		);
	}

	public function test_undo_jobs_have_their_own_bands() {
		$undo = RestoreMeta::make_undo( 5, 1, true, true );
		$this->assertSame( 30, P::percent( 'verified', 'verify', array(), $undo ) );
		$this->assertGreaterThanOrEqual( 30, P::percent( 'running', 'db_restore', array( 'ph' => 'x' ), $undo ) );
		$this->assertLessThan( 80, P::percent( 'running', 'db_restore', array( 'ph' => 'x' ), $undo ) );
		$this->assertGreaterThanOrEqual( 80, P::percent( 'running', 'file_undo', array(), $undo ) );
		$this->assertSame( 0, P::percent( 'running', 'file_undo', array(), RestoreMeta::make_undo( 6, 1, false, true ) ) );
		$this->assertLessThanOrEqual( 95, P::percent( 'running', 'file_undo', array( 'rs' => 1000000 ), RestoreMeta::make_undo( 6, 1, false, true ) ) );
	}

	public function test_bounds_and_finished_states() {
		$meta = $this->meta( 'full', true, true );
		foreach ( array( 'failed', 'cancelled' ) as $status ) {
			$this->assertSame( 0, P::percent( $status, 'failed', array(), $meta ) );
		}
		$this->assertSame( 0, P::percent( 'queued', '', array(), $meta ) );
		$this->assertSame( 0, P::percent( 'running', 'nonsense', array(), $meta ) );
		$this->assertSame( 95, P::percent( 'running', 'finish', array(), $meta ) );
		foreach ( array(
			array( 'i' => -5 ),
			array( 'i' => 'x' ),
			array( 'fl' => PHP_INT_MAX ),
			array(
				'off' => 'zz',
				'ph'  => 'i',
			),
		) as $cursor ) {
			foreach ( $this->order( false ) as $step ) {
				$p = P::percent( 'running', $step, $cursor, $meta );
				$this->assertGreaterThanOrEqual( 0, $p );
				$this->assertLessThanOrEqual( 99, $p );
			}
		}
	}
}
