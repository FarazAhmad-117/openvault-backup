<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\Progress;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Progress
 */
class ProgressTest extends TestCase {

	public function test_empty_and_unknown_steps_are_zero() {
		$this->assertSame( 0, Progress::percent( '', array(), array() ) );
		$this->assertSame( 0, Progress::percent( 'nonsense', array(), array() ) );
		$this->assertSame( 0, Progress::percent( JobState::STEP_FAILED, array(), array() ) );
	}

	public function test_done_is_100_and_nothing_else_reaches_it() {
		$this->assertSame( 100, Progress::percent( JobState::STEP_DONE, array(), array() ) );
		foreach ( JobRunner::STEP_ORDER as $step ) {
			$this->assertLessThan(
				100,
				Progress::percent(
					$step,
					array(
						'tables_done'  => 9,
						'tables_total' => 9,
						'bytes_done'   => 99,
						'phase'        => 'finalize',
					),
					array( 'files' => array( 'bytes' => 1 ) )
				)
			);
		}
	}

	public function test_bands_are_ordered_so_steps_never_go_backwards() {
		$last = -1;
		foreach ( JobRunner::STEP_ORDER as $step ) {
			$this->assertArrayHasKey( $step, Progress::BANDS );
			$this->assertGreaterThanOrEqual( $last, Progress::BANDS[ $step ][0], $step );
			$this->assertGreaterThanOrEqual( Progress::BANDS[ $step ][0], Progress::BANDS[ $step ][1], $step );
			$last = Progress::BANDS[ $step ][1];
		}
		$this->assertLessThanOrEqual( 99, $last );
	}

	public function test_db_export_follows_tables() {
		$p0 = Progress::percent(
			JobState::STEP_DB_EXPORT,
			array(
				'tables_total' => 10,
				'tables_done'  => 0,
			),
			array()
		);
		$p5 = Progress::percent(
			JobState::STEP_DB_EXPORT,
			array(
				'tables_total' => 10,
				'tables_done'  => 5,
			),
			array()
		);
		$p9 = Progress::percent(
			JobState::STEP_DB_EXPORT,
			array(
				'tables_total' => 10,
				'tables_done'  => 10,
			),
			array()
		);
		$this->assertSame( 3, $p0 );
		$this->assertGreaterThan( $p0, $p5 );
		$this->assertGreaterThanOrEqual( $p5, $p9 );
		$this->assertLessThan( 25, $p9 );
	}

	public function test_archive_follows_bytes_and_clamps_garbage() {
		$meta = array(
			'files' => array(
				'bytes' => 1000,
				'count' => 10,
			),
		);
		$a    = Progress::percent(
			JobState::STEP_ARCHIVE,
			array(
				'phase'      => 'files',
				'bytes_done' => 100,
			),
			$meta
		);
		$b    = Progress::percent(
			JobState::STEP_ARCHIVE,
			array(
				'phase'      => 'files',
				'bytes_done' => 900,
			),
			$meta
		);
		$c    = Progress::percent(
			JobState::STEP_ARCHIVE,
			array(
				'phase'      => 'files',
				'bytes_done' => 999999,
			),
			$meta
		);
		$this->assertGreaterThan( $a, $b );
		$this->assertLessThan( 95, $c );
		$this->assertSame( 40, Progress::percent( JobState::STEP_ARCHIVE, array( 'phase' => 'db' ), $meta ) );
		$this->assertSame(
			40,
			Progress::percent(
				JobState::STEP_ARCHIVE,
				array(
					'phase'      => 'files',
					'bytes_done' => -50,
				),
				$meta
			)
		);
		$this->assertSame(
			40,
			Progress::percent(
				JobState::STEP_ARCHIVE,
				array(
					'phase'      => 'files',
					'bytes_done' => 5,
				),
				array()
			)
		);
		$this->assertGreaterThan( $b, Progress::percent( JobState::STEP_ARCHIVE, array( 'phase' => 'finalize' ), $meta ) );
	}

	public function test_monotonic_over_a_whole_run() {
		$meta  = array(
			'files' => array(
				'bytes' => 5000,
				'count' => 50,
			),
		);
		$seq   = array();
		$seq[] = array( JobState::STEP_PREFLIGHT, array() );
		for ( $i = 0; $i <= 6; $i++ ) {
			$seq[] = array(
				JobState::STEP_DB_EXPORT,
				array(
					'tables_total' => 6,
					'tables_done'  => $i,
				),
			);
		}
		$seq[] = array( JobState::STEP_FILE_SCAN, array() );
		for ( $i = 0; $i <= 5000; $i += 500 ) {
			$seq[] = array(
				JobState::STEP_ARCHIVE,
				array(
					'phase'      => 'files',
					'bytes_done' => $i,
				),
			);
		}
		$seq[] = array( JobState::STEP_ARCHIVE, array( 'phase' => 'finalize' ) );
		$seq[] = array( JobState::STEP_CLEANUP, array() );
		$seq[] = array( JobState::STEP_DONE, array() );

		$last = 0;
		foreach ( $seq as $item ) {
			$percent = Progress::percent( $item[0], $item[1], $meta );
			$this->assertGreaterThanOrEqual( $last, $percent, $item[0] );
			$last = $percent;
		}
		$this->assertSame( 100, $last );
	}

	public function test_labels_exist_for_every_step() {
		foreach ( array_merge( JobState::STEPS, array( '' ) ) as $step ) {
			$this->assertNotSame( '', Progress::label( $step ), $step );
		}
		$this->assertSame( 'Waiting to start', Progress::label( '' ) );
	}

	public function test_describe_and_detail_text() {
		$info = Progress::describe(
			JobState::STEP_DB_EXPORT,
			array(
				'tables_total' => 12,
				'tables_done'  => 3,
			),
			array()
		);
		$this->assertSame( '3 of 12 database tables', $info['detail'] );
		$this->assertSame( 'Exporting the database', $info['label'] );
		$info = Progress::describe(
			JobState::STEP_ARCHIVE,
			array(
				'phase'      => 'files',
				'files_done' => 99,
				'bytes_done' => 1,
			),
			array(
				'files' => array(
					'bytes' => 10,
					'count' => 20,
				),
			)
		);
		$this->assertSame( '20 of 20 files', $info['detail'] );
	}

	public function test_eta() {
		$this->assertNull( Progress::eta_seconds( 2, 100 ) );
		$this->assertNull( Progress::eta_seconds( 50, 2 ) );
		$this->assertNull( Progress::eta_seconds( 100, 100 ) );
		$this->assertSame( 60, Progress::eta_seconds( 50, 60 ) );
		$this->assertSame( 20, Progress::eta_seconds( 80, 80 ) );
	}
}
