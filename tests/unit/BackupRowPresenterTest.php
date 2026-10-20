<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Admin\BackupRowPresenter as P;
use OpenVault\Backup\Database\BackupsRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Admin\BackupRowPresenter
 */
class BackupRowPresenterTest extends TestCase {

	public function test_every_status_and_trigger_has_a_text_label() {
		foreach ( BackupsRepository::STATUSES as $status ) {
			$this->assertNotSame( 'Unknown', P::status_label( $status ), $status );
			$this->assertSame( 'ovb-status ovb-status--' . $status, P::status_class( $status ) );
		}
		foreach ( BackupsRepository::TRIGGERS as $trigger ) {
			$this->assertNotSame( 'Unknown', P::trigger_label( $trigger ), $trigger );
		}
	}

	public function test_unknown_values_are_safe() {
		$this->assertSame( 'Unknown', P::status_label( '<script>' ) );
		$this->assertSame( 'ovb-status ovb-status--unknown', P::status_class( '" onmouseover="x' ) );
		$this->assertSame( 'Unknown', P::trigger_label( 'cron' ) );
	}

	public function test_status_groups() {
		$this->assertTrue( P::is_running( 'queued' ) );
		$this->assertTrue( P::is_running( 'running' ) );
		$this->assertFalse( P::is_running( 'complete' ) );
		$this->assertTrue( P::has_files( 'complete' ) );
		$this->assertTrue( P::has_files( 'partial' ) );
		$this->assertFalse( P::has_files( 'failed' ) );
		$this->assertFalse( P::has_files( 'running' ) );
	}

	public function test_format_bytes() {
		$this->assertSame( '0 B', P::format_bytes( 0 ) );
		$this->assertSame( '1023 B', P::format_bytes( 1023 ) );
		$this->assertSame( '1.0 KB', P::format_bytes( 1024 ) );
		$this->assertSame( '1.5 MB', P::format_bytes( 1572864 ) );
		$this->assertSame( '250 MB', P::format_bytes( 250 * 1048576 ) );
		$this->assertSame( '2.0 GB', P::format_bytes( 2 * 1073741824 ) );
		$this->assertSame( '0 B', P::format_bytes( -5 ) );
		$this->assertStringEndsWith( 'TB', P::format_bytes( PHP_INT_MAX ) );
	}

	public function test_contents_summary() {
		$this->assertSame(
			'Database, Uploads',
			P::contents_summary(
				array(
					'db'      => true,
					'plugins' => false,
					'uploads' => 1,
				)
			)
		);
		$this->assertSame( '-', P::contents_summary( array() ) );
		$this->assertSame( '-', P::contents_summary( array( 'evil' => true ) ) );
	}
}
