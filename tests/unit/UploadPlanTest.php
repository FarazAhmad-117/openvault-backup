<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Admin\BackupRowPresenter;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\Steps\UploadPlan as Plan;
use OpenVault\Backup\Engine\UploadSummary;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Steps\UploadPlan
 * @covers \OpenVault\Backup\Engine\UploadSummary
 */
class UploadPlanTest extends TestCase {

	private function row( $id, $name, $part, $status, $destination = 5 ) {
		return array(
			'id'             => $id,
			'file_name'      => $name,
			'part_number'    => $part,
			'upload_status'  => $status,
			'destination_id' => $destination,
		);
	}

	private function rows( array $statuses ) {
		// Statuses of: manifest (part 0, listed first like the database does), db, files 1, files 2.
		return array(
			$this->row( 1, 'manifest.json', 0, $statuses[0] ),
			$this->row( 2, 'db.part01.zip', 1, $statuses[1] ),
			$this->row( 3, 'files.part01.zip', 2, $statuses[2] ),
			$this->row( 4, 'files.part02.zip', 3, $statuses[3] ),
		);
	}

	public function test_destination_ids_are_clean() {
		$this->assertSame( array( 3, 9 ), Plan::destination_ids( array( 'destinations' => array( 3, 9, 3, 0, -1, '4', 'x', null, 2.5 ) ) ) );
		$this->assertSame( array(), Plan::destination_ids( array() ) );
		$this->assertSame( array(), Plan::destination_ids( array( 'destinations' => 'all' ) ) );
		$this->assertCount( Plan::MAX_DESTINATIONS, Plan::destination_ids( array( 'destinations' => range( 1, 40 ) ) ) );
	}

	public function test_order_puts_the_manifest_last() {
		$ordered = Plan::order( $this->rows( array( 'pending', 'pending', 'pending', 'pending' ) ) );
		$this->assertSame( array( 'db.part01.zip', 'files.part01.zip', 'files.part02.zip', 'manifest.json' ), array_column( $ordered, 'file_name' ) );
	}

	public function test_next_row_walks_parts_then_manifest() {
		$this->assertSame( 'db.part01.zip', Plan::next_row( $this->rows( array( 'pending', 'pending', 'pending', 'pending' ) ) )['file_name'] );
		$this->assertSame( 'files.part02.zip', Plan::next_row( $this->rows( array( 'pending', 'done', 'done', 'pending' ) ) )['file_name'] );
		$this->assertSame( 'manifest.json', Plan::next_row( $this->rows( array( 'pending', 'done', 'done', 'done' ) ) )['file_name'], 'manifest only when every part is done' );
		$this->assertNull( Plan::next_row( $this->rows( array( 'done', 'done', 'done', 'done' ) ) ) );
		$this->assertNull( Plan::next_row( array() ) );
	}

	public function test_an_interrupted_upload_resumes_first() {
		$next = Plan::next_row( $this->rows( array( 'pending', 'done', 'pending', 'uploading' ) ) );
		$this->assertSame( 'files.part02.zip', $next['file_name'] );
	}

	public function test_a_failed_part_stops_the_destination_and_the_manifest_is_never_uploaded() {
		$rows = $this->rows( array( 'pending', 'done', 'failed', 'pending' ) );
		$this->assertNull( Plan::next_row( $rows ) );
		$this->assertSame( Plan::STATE_FAILED, Plan::destination_state( $rows ) );
	}

	public function test_destination_state() {
		$this->assertSame( Plan::STATE_DONE, Plan::destination_state( $this->rows( array( 'done', 'done', 'done', 'done' ) ) ) );
		$this->assertSame( Plan::STATE_ACTIVE, Plan::destination_state( $this->rows( array( 'pending', 'done', 'uploading', 'pending' ) ) ) );
		$this->assertSame( Plan::STATE_FAILED, Plan::destination_state( $this->rows( array( 'failed', 'done', 'done', 'done' ) ) ), 'a failed manifest fails it too' );
		$this->assertSame( Plan::STATE_DONE, Plan::destination_state( array() ) );
	}

	public function test_final_result_is_never_complete_when_a_destination_failed() {
		$this->assertSame(
			'complete',
			Plan::final_result(
				array(
					1 => 'done',
					2 => 'done',
				)
			)
		);
		$this->assertSame(
			'partial',
			Plan::final_result(
				array(
					1 => 'done',
					2 => 'failed',
				)
			)
		);
		$this->assertSame( 'partial', Plan::final_result( array( 1 => 'failed' ) ) );
		$this->assertSame(
			'partial',
			Plan::final_result(
				array(
					1 => 'done',
					2 => 'active',
				)
			)
		);
	}

	public function test_backoff_and_attempts() {
		$this->assertSame( 30, Plan::backoff( 1 ) );
		$this->assertSame( 120, Plan::backoff( 2 ) );
		$this->assertSame( 120, Plan::backoff( 9 ) );
		$this->assertSame( 30, Plan::backoff( 0 ) );
		$this->assertTrue( Plan::may_retry( 1 ) );
		$this->assertTrue( Plan::may_retry( 2 ) );
		$this->assertFalse( Plan::may_retry( 3 ), 'three attempts in total' );
	}

	public function test_short_error() {
		$this->assertSame( 'short', Plan::short_error( "  short\n" ) );
		$this->assertSame( Plan::ERROR_MAX, strlen( Plan::short_error( str_repeat( 'x', 500 ) ) ) );
	}

	public function test_cursor_and_meta_stay_far_below_the_job_state_caps() {
		$cursor = array(
			'row'   => PHP_INT_MAX,
			'tries' => 3,
			'bytes' => PHP_INT_MAX,
			'n'     => PHP_INT_MAX,
		);
		$this->assertLessThan( 200, strlen( (string) json_encode( $cursor ) ) );

		$upload = array();
		foreach ( range( 1, Plan::MAX_DESTINATIONS ) as $id ) {
			$upload[ $id ] = array(
				's' => 'failed',
				'e' => Plan::short_error( str_repeat( 'e', 400 ) ),
			);
		}
		$meta  = array(
			'destinations' => range( 1, Plan::MAX_DESTINATIONS ),
			'upload'       => $upload,
			'result'       => 'partial',
		);
		$state = JobState::new_state( 'upload', 1700000000 )->with_cursor( $cursor )->with_meta( $meta );
		$this->assertLessThan( JobState::MAX_META_BYTES, strlen( json_encode( $meta ) ) );
		$this->assertLessThan( JobState::MAX_CURSOR_BYTES, strlen( json_encode( $state->get_cursor() ) ) );
		$this->assertSame( $upload[1]['e'], $state->get_meta()['upload'][1]['e'] );
		$this->assertSame( 5, array_keys( JobState::from_json( $state->to_json() )->get_meta()['upload'] )[4], 'ids survive a JSON round trip as keys' );
	}

	public function test_summary_status_per_destination() {
		$rows    = array(
			$this->row( 1, 'db.part01.zip', 1, 'done', 5 ),
			$this->row( 2, 'manifest.json', 0, 'done', 5 ),
			$this->row( 3, 'db.part01.zip', 1, 'done', 6 ),
			$this->row( 4, 'manifest.json', 0, 'failed', 6 ),
			$this->row( 5, 'db.part01.zip', 1, 'uploading', 7 ),
			$this->row( 6, 'manifest.json', 0, 'pending', 7 ),
			$this->row( 7, 'db.part01.zip', 1, 'done', 0 ),
		);
		$summary = UploadSummary::summarise(
			$rows,
			array(
				5 => 'Backup disk',
				6 => 'Spare',
			),
			array( 5, 6, 7, 8 ),
			array(
				6 => array(
					's' => 'failed',
					'e' => 'Disk full',
				),
			)
		);

		$this->assertSame( array( 5, 6, 7, 8 ), array_column( $summary, 'id' ), 'local rows (0) are ignored, selected ids always appear' );
		$this->assertSame( array( 'done', 'failed', 'uploading', 'pending' ), array_column( $summary, 'status' ) );
		$this->assertSame( array( 2, 1, 0, 0 ), array_column( $summary, 'files_done' ) );
		$this->assertSame( array( 2, 2, 2, 0 ), array_column( $summary, 'files_total' ) );
		$this->assertSame( 'Backup disk', $summary[0]['label'] );
		$this->assertSame( 'Disk full', $summary[1]['error'] );
		$this->assertSame( 'Destination 7', $summary[2]['label'], 'a deleted destination still shows up' );
	}

	public function test_summary_marks_a_destination_failed_from_the_job_meta_alone() {
		$summary = UploadSummary::summarise( array(), array( 9 => 'Gone' ), array( 9 ), array( 9 => array( 's' => 'failed' ) ) );
		$this->assertSame( 'failed', $summary[0]['status'] );
	}

	public function test_presenter_line_is_text_not_colour() {
		$summary = array(
			array(
				'id'          => 1,
				'label'       => 'Disk',
				'status'      => 'done',
				'files_done'  => 3,
				'files_total' => 3,
				'error'       => '',
			),
			array(
				'id'          => 2,
				'label'       => 'Spare',
				'status'      => 'failed',
				'files_done'  => 2,
				'files_total' => 3,
				'error'       => '',
			),
			array(
				'id'          => 3,
				'label'       => 'New',
				'status'      => 'pending',
				'files_done'  => 0,
				'files_total' => 0,
				'error'       => '',
			),
		);
		$this->assertSame( 'Disk: Uploaded; Spare: Failed (2 of 3 files); New: Waiting', BackupRowPresenter::destinations_line( $summary ) );
		$this->assertSame( '', BackupRowPresenter::destinations_line( array() ) );
		foreach ( array( 'done', 'failed', 'uploading', 'pending', 'odd' ) as $status ) {
			$this->assertNotSame( '', BackupRowPresenter::upload_status_label( $status ) );
		}
	}
}
