<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Restore\RestoreManager;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestoreRunner;
use OpenVault\Backup\Restore\SafetySnapshot;
use OpenVault\Backup\Restore\Steps\FileRestoreStep;
use OpenVault\Backup\Restore\Steps\FileUndoStep;
use PHPUnit\Framework\TestCase;

/**
 * Pure rules of the files restore and of undo: which steps a job runs, when it can be cancelled, when it can be undone, the meta.
 *
 * @covers \OpenVault\Backup\Restore\RestoreManager
 * @covers \OpenVault\Backup\Restore\RestoreMeta
 * @covers \OpenVault\Backup\Restore\RestoreRunner
 * @covers \OpenVault\Backup\Restore\Steps\FileRestoreStep
 * @covers \OpenVault\Backup\Restore\Steps\FileUndoStep
 * @covers \OpenVault\Backup\Restore\SafetySnapshot
 */
class RestoreUndoTest extends TestCase {

	/**
	 * Steps a job runs, in order, using the same rules as the runner.
	 *
	 * @param array<string,mixed> $meta Meta.
	 * @return string[]
	 */
	private function steps( array $meta ) {
		return array_values(
			array_filter(
				RestoreRunner::STEP_ORDER,
				static function ( $step ) use ( $meta ) {
					return ! RestoreRunner::is_skipped( $step, $meta );
				}
			)
		);
	}

	public function test_the_steps_of_every_mode() {
		$this->assertSame( array( 'preflight', 'fetch', 'verify', 'snapshot', 'db_restore', 'finish' ), $this->steps( array( 'mode' => 'db' ) ) );
		$this->assertSame( array( 'preflight', 'fetch', 'verify', 'file_restore', 'finish' ), $this->steps( array( 'mode' => 'files' ) ), 'no database snapshot: the journal is the recoverable state' );
		$this->assertSame( array( 'preflight', 'fetch', 'verify', 'snapshot', 'db_restore', 'file_restore', 'finish' ), $this->steps( array( 'mode' => 'full' ) ) );
	}

	public function test_a_full_restore_of_a_backup_without_a_database_or_without_files_skips_the_matching_steps() {
		$no_db    = RestoreMeta::with_scope( RestoreMeta::make( 5, 'full' ), false, true );
		$no_files = RestoreMeta::with_scope( RestoreMeta::make( 5, 'full' ), true, false );
		$this->assertSame( array( 'preflight', 'fetch', 'verify', 'file_restore', 'finish' ), $this->steps( $no_db ) );
		$this->assertSame( array( 'preflight', 'fetch', 'verify', 'snapshot', 'db_restore', 'finish' ), $this->steps( $no_files ) );
	}

	public function test_the_steps_of_an_undo_job() {
		$both  = RestoreMeta::make_undo( 9, 5, true, true );
		$db    = RestoreMeta::make_undo( 9, 5, true, false );
		$files = RestoreMeta::make_undo( 9, 5, false, true );
		$this->assertSame( array( 'preflight', 'fetch', 'verify', 'db_restore', 'file_undo', 'finish' ), $this->steps( $both ), 'the snapshot is restored like a backup; no new snapshot: an undo is not undoable' );
		$this->assertSame( array( 'preflight', 'fetch', 'verify', 'db_restore', 'finish' ), $this->steps( $db ) );
		$this->assertSame( array( 'file_undo', 'finish' ), $this->steps( $files ), 'nothing to download for a files-only undo' );
	}

	public function test_file_undo_never_runs_in_an_ordinary_restore() {
		foreach ( array( 'db', 'files', 'full' ) as $mode ) {
			$this->assertTrue( RestoreRunner::is_skipped( 'file_undo', array( 'mode' => $mode ) ), $mode );
		}
	}

	public function test_an_undo_job_is_never_confirmed_again_and_plans_a_database_restore() {
		$meta = RestoreMeta::make_undo( 9, 5, true, true );
		$this->assertTrue( RestoreMeta::confirmed( $meta ) );
		$this->assertFalse( RestoreRunner::is_gate( 'snapshot', $meta ), 'it starts without a second confirmation' );
		$this->assertSame( 'undo', RestoreMeta::mode( $meta ) );
		$this->assertSame( 'db', RestoreMeta::plan_mode( $meta ) );
		$this->assertSame( 5, RestoreMeta::undo_of( $meta ) );
		$this->assertSame( array( 'url_mismatch' ), RestoreMeta::ack( $meta ), 'the snapshot is this very site: its address is not a surprise' );
		$this->assertSame( 'full', RestoreMeta::plan_mode( array( 'mode' => 'full' ) ) );
		$this->assertSame( 0, RestoreMeta::undo_of( array() ) );
		$this->assertSame( 0, RestoreMeta::undo_of( array( 'undo_of' => '5' ) ) );
	}

	public function test_meta_scope_flags_default_to_the_mode() {
		$this->assertTrue( RestoreMeta::does_db( array( 'mode' => 'db' ) ) );
		$this->assertFalse( RestoreMeta::does_files( array( 'mode' => 'db' ) ) );
		$this->assertFalse( RestoreMeta::does_db( array( 'mode' => 'files' ) ) );
		$this->assertTrue( RestoreMeta::does_files( array( 'mode' => 'files' ) ) );
		$this->assertTrue( RestoreMeta::does_db( array() ) );
		$this->assertTrue( RestoreMeta::does_files( array() ) );
		$this->assertFalse(
			RestoreMeta::does_db(
				array(
					'mode'   => 'full',
					'has_db' => false,
				)
			)
		);
		$this->assertTrue(
			RestoreMeta::does_db(
				array(
					'mode'   => 'files',
					'has_db' => true,
				)
			),
			'an explicit flag wins'
		);
		$this->assertTrue(
			RestoreMeta::does_db(
				array(
					'mode'   => 'full',
					'has_db' => 'no',
				)
			),
			'a non-boolean flag is ignored'
		);
	}

	public function test_meta_helpers() {
		$this->assertFalse( RestoreMeta::server_config( array() ) );
		$this->assertTrue( RestoreMeta::server_config( RestoreMeta::with_server_config( array() ) ) );
		$this->assertFalse( RestoreMeta::db_started( array() ) );
		$this->assertTrue( RestoreMeta::db_started( array( 'db_started' => true ) ) );
		$this->assertSame( 0, RestoreMeta::undone_by( array() ) );
		$this->assertSame( 12, RestoreMeta::undone_by( array( 'undone_by' => 12 ) ) );
		$this->assertTrue( RestoreMeta::undo_dismissed( array( 'undo_dismissed' => true ) ) );
		$undo = RestoreMeta::make_undo( 9, 5, false, true, true );
		$this->assertTrue( RestoreMeta::server_config( $undo ) );
		$this->assertFalse( RestoreMeta::does_db( $undo ) );
		$this->assertTrue( RestoreMeta::does_files( $undo ) );
		$this->assertLessThan( 600, strlen( (string) json_encode( $undo ) ), 'the meta stays small' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Plain PHP in a test.
	}

	public function test_cancel_rules_for_the_file_steps() {
		$files   = array( 'mode' => 'files' );
		$full    = array( 'mode' => 'full' );
		$undo    = RestoreMeta::make_undo( 9, 5, false, true );
		$undo_db = RestoreMeta::make_undo( 9, 5, true, true );

		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'snapshot', array(), $files ) );
		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'file_restore', array(), $files ), 'before the setup ran' );
		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'file_restore', array( 'ph' => 's' ), $files ), 'the setup writes nothing' );
		$this->assertNotSame(
			'',
			RestoreRunner::cancel_refusal_for(
				'file_restore',
				array(
					'ph' => 'a',
					'p'  => 0,
				),
				$files
			),
			'the first write may have happened'
		);
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'file_restore', array( 'ph' => 's' ), $full ), 'the database was replaced already' );
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'file_restore', array(), $full ) );
		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'file_undo', array( 'ph' => 's' ), $undo ) );
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'file_undo', array( 'ph' => 'a' ), $undo ), 'refused once the undo applies' );
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'file_undo', array( 'ph' => 's' ), $undo_db ), 'the snapshot is restored already' );
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'finish', array(), $files ) );
		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'verify', array(), $undo_db ), 'an undo can be cancelled before it changes anything' );
		$this->assertSame( '', RestoreRunner::cancel_refusal_for( 'db_restore', array( 'ph' => 'v' ), $undo_db ) );
		$this->assertNotSame( '', RestoreRunner::cancel_refusal_for( 'db_restore', array( 'ph' => 'i' ), $undo_db ) );
	}

	public function test_the_snapshot_covers_the_database_only_and_never_a_files_restore() {
		foreach ( array( 'db', 'full' ) as $mode ) {
			$contents = SafetySnapshot::contents_for_mode( $mode );
			$this->assertTrue( $contents['db'], $mode );
			$this->assertSame( array(), array_keys( array_filter( array_diff_key( $contents, array( 'db' => 1 ) ) ) ), 'no files in the snapshot: the undo journal holds them' );
		}
		$this->assertFalse( SafetySnapshot::contents_for_mode( 'files' )['db'] );
	}

	public function test_result_summaries_are_small_and_hold_counts_only() {
		$result = FileRestoreStep::result(
			array(
				'fl' => 8,
				'cr' => 5,
				'ow' => 3,
				'by' => 600036,
				'sk' => array(
					'protected_plugin' => 2,
					'dir'              => 1,
				),
			),
			2
		);
		$this->assertSame(
			array(
				'restored'    => 8,
				'created'     => 5,
				'overwritten' => 3,
				'bytes'       => 600036,
				'parts'       => 2,
				'skipped'     => array(
					'protected_plugin' => 2,
					'dir'              => 1,
				),
				'skipped_n'   => 3,
			),
			$result
		);
		$this->assertSame( 0, FileRestoreStep::result( array(), 0 )['restored'] );

		$undo = FileUndoStep::result(
			array(
				'rs' => 3,
				'rm' => 5,
				'rd' => 2,
				'kd' => 1,
				'er' => 0,
				'sk' => array( 'dest_symlink' => 1 ),
			)
		);
		$this->assertSame(
			array(
				'restored'        => 3,
				'removed'         => 5,
				'folders_removed' => 2,
				'folders_kept'    => 1,
				'errors'          => 0,
				'skipped'         => array( 'dest_symlink' => 1 ),
				'skipped_n'       => 1,
			),
			$undo
		);
	}

	// ---- who can undo what ----

	/**
	 * A restore row.
	 *
	 * @param string $status Status.
	 * @param string $mode   Mode.
	 * @return array<string,mixed>
	 */
	private function row( $status = 'complete', $mode = 'full' ) {
		return array(
			'id'     => 5,
			'mode'   => $mode,
			'status' => $status,
		);
	}

	/**
	 * Facts of a healthy site.
	 *
	 * @param array<string,mixed> $override Overrides.
	 * @return array<string,mixed>
	 */
	private function facts( array $override = array() ) {
		return array_merge(
			array(
				'snapshot'        => SafetySnapshot::STATE_COMPLETE,
				'journal'         => true,
				'journal_actions' => true,
				'busy'            => '',
			),
			$override
		);
	}

	public function test_a_complete_restore_can_be_undone_and_the_answer_says_what_an_undo_does() {
		$full = RestoreManager::undo_eligibility( $this->row(), array( 'mode' => 'full' ), $this->facts() );
		$this->assertTrue( $full['ok'] );
		$this->assertSame( array( true, true ), array( $full['undo_db'], $full['undo_files'] ) );

		$db = RestoreManager::undo_eligibility(
			$this->row( 'complete', 'db' ),
			array( 'mode' => 'db' ),
			$this->facts(
				array(
					'journal'         => false,
					'journal_actions' => false,
				)
			)
		);
		$this->assertTrue( $db['ok'] );
		$this->assertSame( array( true, false ), array( $db['undo_db'], $db['undo_files'] ) );

		$files = RestoreManager::undo_eligibility( $this->row( 'complete', 'files' ), array( 'mode' => 'files' ), $this->facts( array( 'snapshot' => SafetySnapshot::STATE_MISSING ) ) );
		$this->assertTrue( $files['ok'], 'a files-only restore has no snapshot and needs none' );
		$this->assertSame( array( false, true ), array( $files['undo_db'], $files['undo_files'] ) );
	}

	public function test_what_blocks_an_undo() {
		$meta  = array( 'mode' => 'full' );
		$cases = array(
			'missing restore'             => array( null, $meta, $this->facts(), 'restore_missing' ),
			'an undo job'                 => array( $this->row( 'complete', 'undo' ), array( 'mode' => 'undo' ), $this->facts(), 'not_undoable' ),
			'already undone'              => array( $this->row(), array_merge( $meta, array( 'undone_by' => 8 ) ), $this->facts(), 'already_undone' ),
			'dismissed'                   => array( $this->row(), array_merge( $meta, array( 'undo_dismissed' => true ) ), $this->facts(), 'dismissed' ),
			'still running'               => array( $this->row( 'running' ), $meta, $this->facts(), 'not_finished' ),
			'verified (never applied)'    => array( $this->row( 'verified' ), $meta, $this->facts(), 'not_finished' ),
			'cancelled'                   => array( $this->row( 'cancelled' ), $meta, $this->facts(), 'not_finished' ),
			'snapshot gone'               => array( $this->row(), $meta, $this->facts( array( 'snapshot' => SafetySnapshot::STATE_MISSING ) ), 'snapshot_gone' ),
			'snapshot failed'             => array( $this->row(), $meta, $this->facts( array( 'snapshot' => SafetySnapshot::STATE_FAILED ) ), 'snapshot_gone' ),
			'journal gone'                => array(
				$this->row(),
				$meta,
				$this->facts(
					array(
						'journal'         => false,
						'journal_actions' => false,
					)
				),
				'journal_gone',
			),
			'a backup is running'         => array( $this->row(), $meta, $this->facts( array( 'busy' => 'backup' ) ), 'backup_running' ),
			'another restore is running'  => array( $this->row(), $meta, $this->facts( array( 'busy' => 'restore' ) ), 'restore_running' ),
			'files restore, journal gone' => array(
				$this->row( 'complete', 'files' ),
				array( 'mode' => 'files' ),
				$this->facts(
					array(
						'journal'         => false,
						'journal_actions' => false,
					)
				),
				'journal_gone',
			),
			'failed before any change'    => array( $this->row( 'failed' ), $meta, $this->facts( array( 'journal_actions' => false ) ), 'nothing_to_undo' ),
		);
		foreach ( $cases as $name => $case ) {
			$answer = RestoreManager::undo_eligibility( $case[0], $case[1], $case[2] );
			$this->assertFalse( $answer['ok'], $name );
			$this->assertSame( $case[3], $answer['code'], $name );
			$this->assertNotSame( '', $answer['message'], $name );
		}
	}

	public function test_a_restore_that_failed_after_it_started_can_be_undone() {
		// The database import began.
		$db = RestoreManager::undo_eligibility(
			$this->row( 'failed', 'db' ),
			array(
				'mode'       => 'db',
				'db_started' => true,
			),
			$this->facts(
				array(
					'journal'         => false,
					'journal_actions' => false,
				)
			)
		);
		$this->assertTrue( $db['ok'] );
		$this->assertSame( array( true, false ), array( $db['undo_db'], $db['undo_files'] ) );

		// Only the first files were replaced: the journal has actions.
		$files = RestoreManager::undo_eligibility( $this->row( 'failed', 'files' ), array( 'mode' => 'files' ), $this->facts() );
		$this->assertTrue( $files['ok'] );
		$this->assertSame( array( false, true ), array( $files['undo_db'], $files['undo_files'] ) );

		// A failed full restore that stopped in the file step: both.
		$full = RestoreManager::undo_eligibility(
			$this->row( 'failed', 'full' ),
			array(
				'mode'       => 'full',
				'db_started' => true,
			),
			$this->facts()
		);
		$this->assertSame( array( true, true ), array( $full['undo_db'], $full['undo_files'] ) );

		// A failed full restore whose database import never began and whose files were not touched: nothing to undo.
		$none = RestoreManager::undo_eligibility( $this->row( 'failed', 'full' ), array( 'mode' => 'full' ), $this->facts( array( 'journal_actions' => false ) ) );
		$this->assertSame( 'nothing_to_undo', $none['code'] );

		// The database began but its snapshot is gone: refused (it cannot be put back).
		$gone = RestoreManager::undo_eligibility(
			$this->row( 'failed', 'db' ),
			array(
				'mode'       => 'db',
				'db_started' => true,
			),
			$this->facts( array( 'snapshot' => SafetySnapshot::STATE_MISSING ) )
		);
		$this->assertSame( 'snapshot_gone', $gone['code'] );
	}

	public function test_a_restore_of_a_backup_without_a_database_is_undone_without_the_snapshot() {
		$meta   = RestoreMeta::with_scope( array( 'mode' => 'full' ), false, true );
		$answer = RestoreManager::undo_eligibility( $this->row(), $meta, $this->facts( array( 'snapshot' => SafetySnapshot::STATE_MISSING ) ) );
		$this->assertTrue( $answer['ok'] );
		$this->assertSame( array( false, true ), array( $answer['undo_db'], $answer['undo_files'] ) );
	}

	public function test_the_steps_exist_in_the_job_state() {
		$this->assertContains( JobState::STEP_FILE_UNDO, JobState::STEPS );
		$this->assertContains( JobState::STEP_FILE_UNDO, RestoreRunner::STEP_ORDER );
		$this->assertLessThan( array_search( 'finish', RestoreRunner::STEP_ORDER, true ), array_search( 'file_undo', RestoreRunner::STEP_ORDER, true ) );
		$this->assertLessThanOrEqual( 20, strlen( JobState::STEP_FILE_UNDO ), 'fits the step column' );
	}
}
