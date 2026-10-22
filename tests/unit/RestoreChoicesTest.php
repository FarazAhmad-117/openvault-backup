<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Admin\BackupRowPresenter;
use OpenVault\Backup\Admin\RestoreChoices as C;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Admin\RestoreChoices
 * @covers \OpenVault\Backup\Admin\BackupRowPresenter::can_restore
 */
class RestoreChoicesTest extends TestCase {

	private function backup( array $over = array() ) {
		return array_merge(
			array(
				'status'    => 'complete',
				'encrypted' => false,
				'contents'  => array(
					'db'      => true,
					'uploads' => true,
				),
			),
			$over
		);
	}

	private function copies( $local = 'ok', array $more = array() ) {
		return array_merge(
			array(
				array(
					'label' => 'This server',
					'state' => $local,
				),
			),
			$more
		);
	}

	public function test_row_action_rules() {
		$this->assertTrue( BackupRowPresenter::can_restore( 'complete', false ) );
		$this->assertTrue( BackupRowPresenter::can_restore( 'partial', false ) );
		$this->assertFalse( BackupRowPresenter::can_restore( 'complete', true ), 'encrypted' );
		foreach ( array( 'queued', 'running', 'uploading', 'failed', 'weird' ) as $status ) {
			$this->assertFalse( BackupRowPresenter::can_restore( $status, false ), $status );
		}
	}

	public function test_a_full_backup_offers_every_mode() {
		$out = C::describe( $this->backup(), $this->copies(), false );
		$this->assertTrue( $out['restorable'] );
		$this->assertSame( array( 'This server' ), $out['copies'] );
		foreach ( array( 'db', 'files', 'full' ) as $mode ) {
			$this->assertTrue( $out['modes'][ $mode ]['enabled'], $mode );
			$this->assertSame( '', $out['modes'][ $mode ]['reason'] );
		}
	}

	public function test_modes_follow_the_contents() {
		$db_only = C::describe( $this->backup( array( 'contents' => array( 'db' => true ) ) ), $this->copies(), false );
		$this->assertTrue( $db_only['modes']['db']['enabled'] );
		$this->assertFalse( $db_only['modes']['files']['enabled'] );
		$this->assertFalse( $db_only['modes']['full']['enabled'] );
		$this->assertStringContainsString( 'only the database', $db_only['modes']['full']['reason'] );

		$files_only = C::describe( $this->backup( array( 'contents' => array( 'themes' => true ) ) ), $this->copies(), false );
		$this->assertFalse( $files_only['modes']['db']['enabled'] );
		$this->assertStringContainsString( 'does not contain the database', $files_only['modes']['db']['reason'] );
		$this->assertTrue( $files_only['modes']['files']['enabled'] );
		$this->assertFalse( $files_only['modes']['full']['enabled'] );
	}

	public function test_wp_config_alone_counts_as_files() {
		$this->assertTrue( C::has_files( array( 'wp_config' => true ) ) );
		$this->assertFalse( C::has_files( array( 'db' => true ) ) );
		$this->assertTrue( C::has_db( array( 'db' => 1 ) ) );
		$this->assertFalse( C::has_db( array() ) );
	}

	public function test_multisite_disables_everything_with_the_exact_reason() {
		$out = C::describe( $this->backup(), $this->copies(), true );
		foreach ( $out['modes'] as $mode ) {
			$this->assertFalse( $mode['enabled'] );
			$this->assertSame( 'Restoring a multisite network is not supported yet.', $mode['reason'] );
		}
	}

	public function test_encrypted_backups_are_not_restorable() {
		$out = C::describe( $this->backup( array( 'encrypted' => true ) ), $this->copies(), false );
		$this->assertFalse( $out['restorable'] );
		$this->assertSame( 'Encrypted backups cannot be restored yet.', $out['reason'] );
		$this->assertFalse( $out['modes']['db']['enabled'] );
	}

	public function test_a_backup_whose_only_copy_needs_a_reconnect_is_disabled_with_the_reason() {
		$out = C::describe(
			$this->backup(),
			$this->copies(
				'missing',
				array(
					array(
						'label' => 'My Drive',
						'state' => 'needs_reconnect',
					),
				)
			),
			false
		);
		$this->assertSame( array(), $out['copies'] );
		$this->assertFalse( $out['modes']['full']['enabled'] );
		$this->assertStringContainsString( 'My Drive', $out['modes']['db']['reason'] );
		$this->assertStringContainsString( 'reconnected', $out['modes']['db']['reason'] );
	}

	public function test_a_reachable_destination_copy_is_enough() {
		$out = C::describe(
			$this->backup(),
			$this->copies(
				'missing',
				array(
					array(
						'label' => 'My S3',
						'state' => 'ok',
					),
					array(
						'label' => 'Old Drive',
						'state' => 'needs_reconnect',
					),
				)
			),
			false
		);
		$this->assertSame( array( 'My S3' ), $out['copies'] );
		$this->assertTrue( $out['modes']['db']['enabled'] );
	}

	public function test_no_copy_at_all() {
		$out = C::describe( $this->backup(), $this->copies( 'missing' ), false );
		$this->assertFalse( $out['modes']['db']['enabled'] );
		$this->assertStringContainsString( 'No complete copy', $out['modes']['db']['reason'] );
	}

	public function test_the_banner_decision() {
		$this->assertNull( C::banner( false, array() ) );

		$running = C::banner( false, array( 12, 15 ) );
		$this->assertSame( 12, $running['restore_id'] );
		$this->assertStringContainsString( 'in progress', $running['message'] );

		$applying = C::banner( true, array( 12 ) );
		$this->assertStringContainsString( 'maintenance mode', $applying['message'] );

		$orphan = C::banner( true, array() );
		$this->assertSame( 0, $orphan['restore_id'] );
		$this->assertStringContainsString( 'maintenance mode', $orphan['message'] );
	}
}
