<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestoreStatusPresenter as P;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Restore\RestoreStatusPresenter
 */
class RestoreStatusPresenterTest extends TestCase {

	const CANARIES = array( '/var/www/html/wp-content/ovb-secret-dir', 'CANARY_SQL_STATEMENT', 'DROP TABLE canary', 'admin_login_canary', 'AKIAABCDEFGHIJKLMNOP', 'ovb1:envelope' );

	private function row( $status, $step = 'preflight', $mode = 'full', array $extra = array() ) {
		return array_merge(
			array(
				'id'                 => 7,
				'backup_id'          => 3,
				'mode'               => $mode,
				'status'             => $status,
				'step'               => $step,
				'snapshot_backup_id' => null,
				'error'              => null,
				'started_at'         => '2026-01-01 10:00:00',
				'finished_at'        => in_array( $status, array( 'complete', 'failed', 'cancelled' ), true ) ? '2026-01-01 10:05:00' : null,
				'created_at'         => '2026-01-01 10:00:00',
				'updated_at'         => '2026-01-01 10:00:00',
				'created_by'         => 1,
			),
			$extra
		);
	}

	private function state( $step, array $meta = array(), array $cursor = array() ) {
		return JobState::new_state( $step, 1000 )->with_meta( array_merge( RestoreMeta::make( 7, 'full' ), $meta ) )->with_cursor( $cursor );
	}

	public function test_phases() {
		$confirmed = RestoreMeta::with_confirmed( RestoreMeta::make( 7, 'full' ) );
		$this->assertSame( 'preparing', P::phase( 'running', 'fetch', RestoreMeta::make( 7, 'full' ) ) );
		$this->assertSame( 'ready', P::phase( 'verified', 'snapshot', RestoreMeta::make( 7, 'full' ) ) );
		$this->assertSame( 'applying', P::phase( 'running', 'snapshot', $confirmed ) );
		$this->assertSame( 'applying', P::phase( 'running', 'db_restore', $confirmed ) );
		$this->assertSame( 'preparing', P::phase( 'running', 'verify', $confirmed ) );
		$this->assertSame( 'done', P::phase( 'complete', 'done', $confirmed ) );
		$this->assertSame( 'failed', P::phase( 'failed', 'failed', $confirmed ) );
		$this->assertSame( 'cancelled', P::phase( 'cancelled', 'failed', $confirmed ) );
		$undo = RestoreMeta::make_undo( 8, 7, true, true );
		$this->assertSame( 'preparing', P::phase( 'running', 'fetch', $undo ) );
		$this->assertSame( 'applying', P::phase( 'running', 'db_restore', $undo ) );
		$this->assertSame( 'applying', P::phase( 'running', 'file_undo', RestoreMeta::make_undo( 8, 7, false, true ) ) );
	}

	public function test_a_parked_job() {
		$out = P::build( $this->row( 'verified', 'snapshot' ), $this->state( 'snapshot' ), array( 'maintenance' => false ), 1000 );
		$this->assertTrue( $out['awaiting_confirmation'] );
		$this->assertSame( 'ready', $out['phase'] );
		$this->assertSame( 40, $out['percent'] );
		$this->assertTrue( $out['can_cancel'] );
		$this->assertFalse( $out['finished'] );
		$this->assertNull( $out['result'] );
		$this->assertNull( $out['undo'] );
	}

	public function test_cancel_is_offered_only_when_the_runner_allows_it() {
		$out = P::build( $this->row( 'running', 'db_restore' ), $this->state( 'db_restore' ), array( 'cancel_refusal' => 'Already changing the site' ), 1000 );
		$this->assertFalse( $out['can_cancel'] );
		$this->assertSame( 'Already changing the site', $out['cancel_refusal'] );
		$done = P::build( $this->row( 'complete', 'done' ), $this->state( 'done' ), array( 'cancel_refusal' => 'x' ), 1000 );
		$this->assertFalse( $done['can_cancel'] );
		$this->assertSame( '', $done['cancel_refusal'] );
	}

	public function test_the_maintenance_flag_is_reported() {
		$out = P::build( $this->row( 'running', 'db_restore' ), $this->state( 'db_restore', array( 'confirmed' => true ) ), array( 'maintenance' => true ), 1000 );
		$this->assertTrue( $out['maintenance'] );
		$this->assertSame( 'applying', $out['phase'] );
	}

	public function test_a_complete_job_has_a_whitelisted_result_and_undo_info() {
		$meta = array(
			'result' => array(
				'mode'        => 'full',
				'files'       => array(
					'restored'    => 12,
					'created'     => 4,
					'overwritten' => 8,
					'bytes'       => 2048,
					'parts'       => 1,
					'skipped'     => array(
						'protected_plugin' => 3,
						'weird_reason'     => 2,
						'../../etc'        => 9,
					),
					'skipped_n'   => 5,
					'secret_path' => '/var/www/html/wp-content/ovb-secret-dir',
				),
				'journal'     => true,
				'tables'      => 11,
				'statements'  => 99,
				'rows'        => 500,
				'skipped'     => 2,
				'seconds'     => 61,
				'snapshot_id' => 9,
				'kept_urls'   => false,
				'user'        => 'admin_login_canary',
				'sql'         => 'DROP TABLE canary',
			),
		);
		$undo = array(
			'ok'          => true,
			'code'        => 'ok',
			'message'     => '',
			'undo_db'     => true,
			'undo_files'  => true,
			'snapshot_id' => 9,
			'journal'     => true,
		);
		$out  = P::build( $this->row( 'complete', 'done' ), $this->state( 'done', $meta ), array( 'undo' => $undo ), 1000 );

		$this->assertSame( 100, $out['percent'] );
		$this->assertSame( 11, $out['result']['tables'] );
		$this->assertSame( 12, $out['result']['files']['restored'] );
		$this->assertSame( 3, $out['result']['files']['skipped']['part of OpenVault Backup itself (never overwritten)'] );
		$this->assertSame( 2, $out['result']['files']['skipped']['other reasons'], 'unknown codes are shown as other reasons' );
		$this->assertSame( 5, $out['result']['files']['skipped_n'], 'the path-like reason is dropped' );
		$this->assertTrue( $out['undo']['can_undo'] );
		$this->assertTrue( $out['undo']['journal'] );
		$this->assertStringContainsString( 'removes everything written to the database', $out['undo']['warning'] );
		$this->assertNotEmpty( $out['warnings'], 'skipped tables are reported' );
		$this->assertNoLeaks( $out );
	}

	public function test_undo_jobs_carry_no_undo_block() {
		$state = $this->state( 'done', RestoreMeta::make_undo( 8, 7, true, false ) );
		$out   = P::build( $this->row( 'complete', 'done', 'undo' ), $state, array( 'undo' => array( 'ok' => true ) ), 1000 );
		$this->assertSame( 'undo', $out['kind'] );
		$this->assertSame( 7, $out['undo_of'] );
		$this->assertNull( $out['undo'] );
	}

	public function test_a_dismissed_or_refused_undo_says_why() {
		$undo = array(
			'ok'         => false,
			'code'       => 'dismissed',
			'message'    => 'The undo data of this restore was deleted.',
			'undo_db'    => false,
			'undo_files' => false,
			'journal'    => false,
		);
		$out  = P::build( $this->row( 'complete', 'done' ), $this->state( 'done', array( 'undo_dismissed' => true ) ), array( 'undo' => $undo ), 1000 );
		$this->assertFalse( $out['undo']['can_undo'] );
		$this->assertSame( 'The undo data of this restore was deleted.', $out['undo']['reason'] );
		$this->assertTrue( $out['undo']['dismissed'] );
	}

	public function test_errors_are_scrubbed_and_paths_hidden() {
		$state = $this->state( 'db_restore' )->mark_failed( 'Import failed in /var/www/html/wp-content/ovb-secret-dir/tmp token=SuperSecretToken123 at Bearer abcDEF123456' );
		$out   = P::build( $this->row( 'failed', 'failed' ), $state, array( 'roots' => array( '/var/www/html' ) ), 1000 );
		$this->assertStringNotContainsString( '/var/www/html', (string) $out['error'] );
		$this->assertStringContainsString( '[site]', (string) $out['error'] );
		$this->assertStringNotContainsString( 'SuperSecretToken123', (string) $out['error'] );
		$this->assertStringNotContainsString( 'abcDEF123456', (string) $out['error'] );
		$this->assertNull( P::build( $this->row( 'running', 'fetch' ), $this->state( 'fetch' ), array(), 1000 )['error'] );
	}

	public function test_public_payload_has_only_the_whitelisted_keys_and_never_the_error() {
		$state = $this->state( 'db_restore' )->mark_failed( 'Secret failure in /var/www/html/wp-content password=hunter2hunter2' );
		$full  = P::build(
			$this->row( 'failed', 'failed' ),
			$state,
			array(
				'maintenance' => true,
				'undo'        => array( 'ok' => true ),
			),
			1000
		);
		$pub   = P::public_payload( $full );
		$this->assertSame( P::PUBLIC_KEYS, array_keys( $pub ) );
		$this->assertTrue( $pub['done'] );
		$this->assertTrue( $pub['failed'] );
		$this->assertStringNotContainsString( 'hunter2', $this->json( $pub ) );
		$this->assertStringNotContainsString( '/var/www', $this->json( $pub ) );

		$running = P::public_payload( P::build( $this->row( 'running', 'fetch' ), $this->state( 'fetch' ), array(), 1000 ) );
		$this->assertFalse( $running['done'] );
		$this->assertFalse( $running['failed'] );
		$ok = P::public_payload( P::build( $this->row( 'complete', 'done' ), $this->state( 'done' ), array(), 1000 ) );
		$this->assertTrue( $ok['done'] );
		$this->assertFalse( $ok['failed'] );
		$this->assertSame( 100, $ok['percent'] );
	}

	public function test_summary_is_small() {
		$sum = P::summary( $this->row( 'complete', 'done' ) );
		$this->assertSame( array( 'id', 'backup_id', 'mode', 'kind', 'status', 'started_at', 'finished_at' ), array_keys( $sum ) );
		$this->assertSame( '2026-01-01T10:05:00Z', $sum['finished_at'] );
	}

	/**
	 * JSON for assertions (production code uses wp_json_encode, which unit tests do not load).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function json( $value ) {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
	}

	/**
	 * @param mixed $payload Payload.
	 */
	private function assertNoLeaks( $payload ) {
		$json = $this->json( $payload );
		foreach ( self::CANARIES as $canary ) {
			$this->assertStringNotContainsString( $canary, $json, 'leaked: ' . $canary );
		}
	}
}
