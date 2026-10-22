<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobState;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Database\RestoresRepository
 */
class RestoresRepositoryTest extends TestCase {

	public function test_defaults_on_create() {
		$out = RestoresRepository::normalise_fields( array( 'backup_id' => 5 ) );

		$this->assertSame(
			array(
				'backup_id'          => 5,
				'mode'               => 'full',
				'status'             => 'queued',
				'step'               => 'preflight',
				'snapshot_backup_id' => null,
				'error'              => null,
				'created_by'         => 0,
			),
			$out
		);
	}

	public function test_a_backup_id_is_required_on_create_only() {
		$this->assertSame( array(), RestoresRepository::normalise_fields( array(), false ) );

		$this->expectException( \InvalidArgumentException::class );
		RestoresRepository::normalise_fields( array() );
	}

	/**
	 * @dataProvider invalid_fields
	 */
	public function test_invalid_values_are_rejected( array $fields ) {
		$this->expectException( \InvalidArgumentException::class );
		RestoresRepository::normalise_fields( array_merge( array( 'backup_id' => 5 ), $fields ) );
	}

	public function invalid_fields() {
		return array(
			'zero backup id'     => array( array( 'backup_id' => 0 ) ),
			'negative backup id' => array( array( 'backup_id' => -1 ) ),
			'text backup id'     => array( array( 'backup_id' => 'x' ) ),
			'unknown mode'       => array( array( 'mode' => 'everything' ) ),
			'unknown status'     => array( array( 'status' => 'done' ) ),
			'non-string status'  => array( array( 'status' => array( 'queued' ) ) ),
			'unknown step'       => array( array( 'step' => 'explode' ) ),
			'negative snapshot'  => array( array( 'snapshot_backup_id' => -3 ) ),
			'float created_by'   => array( array( 'created_by' => 1.5 ) ),
		);
	}

	public function test_valid_values_are_kept() {
		$out = RestoresRepository::normalise_fields(
			array(
				'backup_id'          => '9',
				'mode'               => 'db',
				'status'             => 'verified',
				'step'               => JobState::STEP_SNAPSHOT,
				'snapshot_backup_id' => '12',
				'error'              => "Line one\nLine two\x00",
				'created_by'         => 3,
				'unknown'            => 'dropped',
			)
		);

		$this->assertSame( 9, $out['backup_id'] );
		$this->assertSame( 'db', $out['mode'] );
		$this->assertSame( 'verified', $out['status'] );
		$this->assertSame( 'snapshot', $out['step'] );
		$this->assertSame( 12, $out['snapshot_backup_id'] );
		$this->assertSame( "Line one\nLine two", $out['error'] );
		$this->assertSame( 3, $out['created_by'] );
		$this->assertArrayNotHasKey( 'unknown', $out );
	}

	public function test_snapshot_id_zero_or_empty_means_none() {
		foreach ( array( null, '', 0 ) as $value ) {
			$this->assertNull( RestoresRepository::normalise_fields( array( 'snapshot_backup_id' => $value ), false )['snapshot_backup_id'] );
		}
	}

	public function test_a_long_error_is_cut() {
		$out = RestoresRepository::normalise_fields( array( 'error' => str_repeat( 'x', 5000 ) ), false );

		$this->assertSame( RestoresRepository::ERROR_MAX, strlen( $out['error'] ) );
	}

	public function test_status_groups() {
		$this->assertSame( array( 'queued', 'running', 'verified', 'complete', 'failed', 'cancelled' ), RestoresRepository::STATUSES );
		foreach ( array_merge( RestoresRepository::ACTIVE, RestoresRepository::FINISHED ) as $status ) {
			$this->assertContains( $status, RestoresRepository::STATUSES );
		}
		$this->assertSame( array(), array_intersect( RestoresRepository::ACTIVE, RestoresRepository::FINISHED ) );
		$this->assertSame( array(), array_diff( RestoresRepository::INCOMPLETE, RestoresRepository::ACTIVE ) );
		$this->assertContains( 'verified', RestoresRepository::ACTIVE );
		$this->assertNotContains( 'verified', RestoresRepository::INCOMPLETE );
		$this->assertSame( array( 'full', 'db', 'files', 'undo' ), RestoresRepository::MODES );
	}

	public function test_hydrate_gives_typed_values() {
		$row = RestoresRepository::hydrate(
			array(
				'id'                 => '4',
				'backup_id'          => '9',
				'mode'               => 'files',
				'status'             => 'failed',
				'step'               => 'fetch',
				'snapshot_backup_id' => null,
				'error'              => 'Boom',
				'started_at'         => '2026-01-01 00:00:00',
				'finished_at'        => null,
				'created_at'         => '2026-01-01 00:00:00',
				'updated_at'         => '2026-01-01 00:00:01',
				'created_by'         => '2',
			)
		);

		$this->assertSame( 4, $row['id'] );
		$this->assertSame( 9, $row['backup_id'] );
		$this->assertNull( $row['snapshot_backup_id'] );
		$this->assertNull( $row['finished_at'] );
		$this->assertSame( 'Boom', $row['error'] );
		$this->assertSame( 2, $row['created_by'] );
		$this->assertArrayNotHasKey( 'state', $row );
	}
}
