<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Support\Manifest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Database\BackupsRepository
 */
class BackupsRepositoryTest extends TestCase {

	public function test_create_defaults() {
		$fields = BackupsRepository::normalise_fields( array() );

		$this->assertTrue( Manifest::is_valid_uuid( $fields['uuid'] ) );
		$this->assertSame( 'manual', $fields['trigger_type'] );
		$this->assertSame( 'queued', $fields['status'] );
		$this->assertSame( 'zip', $fields['format'] );
		$this->assertSame( array(), $fields['contents'] );
		$this->assertNull( $fields['schedule_id'] );
		$this->assertSame( 0, $fields['encrypted'] );
		$this->assertSame( 0, $fields['locked'] );
		$this->assertSame( 0, $fields['total_size'] );
		$this->assertSame( '', $fields['note'] );
		$this->assertNull( $fields['started_at'] );
	}

	public function test_partial_validation_only_returns_given_keys() {
		$this->assertSame( array( 'total_size' => 5 ), BackupsRepository::normalise_fields( array( 'total_size' => '5' ), false ) );
		$this->assertSame( array(), BackupsRepository::normalise_fields( array(), false ) );
	}

	public function test_valid_enums_are_accepted() {
		foreach ( BackupsRepository::TRIGGERS as $value ) {
			$this->assertSame( $value, BackupsRepository::normalise_fields( array( 'trigger_type' => $value ) )['trigger_type'] );
		}
		foreach ( BackupsRepository::STATUSES as $value ) {
			$this->assertSame( $value, BackupsRepository::normalise_fields( array( 'status' => $value ) )['status'] );
			$this->assertSame( $value, BackupsRepository::validate_status( $value ) );
		}
		foreach ( BackupsRepository::FORMATS as $value ) {
			$this->assertSame( $value, BackupsRepository::normalise_fields( array( 'format' => $value ) )['format'] );
		}
	}

	/**
	 * @dataProvider invalid_fields
	 */
	public function test_invalid_fields_throw( array $input ) {
		$this->expectException( \InvalidArgumentException::class );
		BackupsRepository::normalise_fields( $input );
	}

	public function invalid_fields() {
		return array(
			'trigger unknown'     => array( array( 'trigger_type' => 'cron' ) ),
			'trigger injection'   => array( array( 'trigger_type' => "manual' OR '1'='1" ) ),
			'trigger case'        => array( array( 'trigger_type' => 'Manual' ) ),
			'status unknown'      => array( array( 'status' => 'done' ) ),
			'status array'        => array( array( 'status' => array( 'queued' ) ) ),
			'status null'         => array( array( 'status' => null ) ),
			'format unknown'      => array( array( 'format' => '7z' ) ),
			'format injection'    => array( array( 'format' => 'zip; DROP TABLE x' ) ),
			'uuid malformed'      => array( array( 'uuid' => 'not-a-uuid' ) ),
			'uuid sql'            => array( array( 'uuid' => "' OR 1=1 --" ) ),
			'contents not array'  => array( array( 'contents' => 'db' ) ),
			'size negative'       => array( array( 'total_size' => -1 ) ),
			'size float'          => array( array( 'total_size' => 1.5 ) ),
			'schedule exp-string' => array( array( 'schedule_id' => '1e3' ) ),
			'schedule negative'   => array( array( 'schedule_id' => -4 ) ),
			'started bad'         => array( array( 'started_at' => 'now' ) ),
			'finished injection'  => array( array( 'finished_at' => "2026-01-01 00:00:00'; --" ) ),
		);
	}

	public function test_validate_status_rejects() {
		$this->expectException( \InvalidArgumentException::class );
		BackupsRepository::validate_status( 'COMPLETE' );
	}

	public function test_uuid_is_lowercased_and_kept() {
		$uuid   = Manifest::generate_uuid();
		$fields = BackupsRepository::normalise_fields( array( 'uuid' => strtoupper( $uuid ) ) );
		$this->assertSame( $uuid, $fields['uuid'] );
	}

	public function test_unknown_keys_are_dropped() {
		$fields = BackupsRepository::normalise_fields(
			array(
				'id'     => 99,
				'hacker' => 'x',
			)
		);
		$this->assertArrayNotHasKey( 'id', $fields );
		$this->assertArrayNotHasKey( 'hacker', $fields );
	}

	public function test_schedule_id_null_and_zero_mean_none() {
		$this->assertNull( BackupsRepository::normalise_fields( array( 'schedule_id' => null ) )['schedule_id'] );
		$this->assertNull( BackupsRepository::normalise_fields( array( 'schedule_id' => 0 ) )['schedule_id'] );
		$this->assertSame( 12, BackupsRepository::normalise_fields( array( 'schedule_id' => '12' ) )['schedule_id'] );
	}

	public function test_note_is_sanitised_and_limited() {
		$this->assertSame( "Line one\nLine two", BackupsRepository::sanitise_note( "  Line one\x00\nLine two\x07  " ) );
		$this->assertSame( 1000, strlen( BackupsRepository::sanitise_note( str_repeat( 'a', 5000 ) ) ) );
		$this->assertSame( '', BackupsRepository::sanitise_note( array( 'x' ) ) );
		$this->assertSame( "it's '; DROP TABLE x;--", BackupsRepository::sanitise_note( "it's '; DROP TABLE x;--" ) );
	}

	public function test_query_normalisation_drops_unknown_values_and_clamps() {
		$query = BackupsRepository::normalise_query(
			array(
				'status'       => 'complete OR 1=1',
				'trigger_type' => 'nope',
				'schedule_id'  => '-3',
				'date_from'    => 'garbage',
				'date_to'      => '2026-02-30',
				'search'       => "  needle\x00  ",
				'orderby'      => 'note; DROP TABLE x',
				'order'        => 'sideways',
				'per_page'     => 100000,
				'page'         => -2,
			)
		);

		$this->assertNull( $query['status'] );
		$this->assertNull( $query['trigger_type'] );
		$this->assertNull( $query['schedule_id'] );
		$this->assertNull( $query['date_from'] );
		$this->assertNull( $query['date_to'] );
		$this->assertSame( 'needle', $query['search'] );
		$this->assertSame( 'created_at', $query['orderby'] );
		$this->assertSame( 'DESC', $query['order'] );
		$this->assertSame( 200, $query['per_page'] );
		$this->assertSame( 1, $query['page'] );
		$this->assertSame( 0, $query['offset'] );
	}

	public function test_query_normalisation_keeps_valid_values() {
		$query = BackupsRepository::normalise_query(
			array(
				'status'       => 'failed',
				'trigger_type' => 'schedule',
				'schedule_id'  => '7',
				'date_from'    => '2026-01-01',
				'date_to'      => '2026-01-31',
				'orderby'      => 'total_size',
				'order'        => 'asc',
				'per_page'     => 50,
				'page'         => 3,
			)
		);

		$this->assertSame( 'failed', $query['status'] );
		$this->assertSame( 'schedule', $query['trigger_type'] );
		$this->assertSame( 7, $query['schedule_id'] );
		$this->assertSame( '2026-01-01 00:00:00', $query['date_from'] );
		$this->assertSame( '2026-01-31 23:59:59', $query['date_to'] );
		$this->assertSame( 'total_size', $query['orderby'] );
		$this->assertSame( 'ASC', $query['order'] );
		$this->assertSame( 100, $query['offset'] );
	}

	public function test_hydrate_types_and_bad_json() {
		$row = BackupsRepository::hydrate(
			array(
				'id'           => '5',
				'uuid'         => Manifest::generate_uuid(),
				'trigger_type' => 'manual',
				'schedule_id'  => null,
				'contents'     => '{broken',
				'format'       => 'zip',
				'encrypted'    => '0',
				'status'       => 'complete',
				'total_size'   => '1234',
				'locked'       => '1',
				'note'         => null,
				'started_at'   => null,
				'finished_at'  => '2026-01-01 00:00:00',
				'created_at'   => '2026-01-01 00:00:00',
				'updated_at'   => '2026-01-01 00:00:00',
			)
		);

		$this->assertSame( 5, $row['id'] );
		$this->assertSame( array(), $row['contents'] );
		$this->assertFalse( $row['encrypted'] );
		$this->assertTrue( $row['locked'] );
		$this->assertSame( 1234, $row['total_size'] );
		$this->assertSame( '', $row['note'] );
		$this->assertNull( $row['schedule_id'] );
		$this->assertNull( $row['started_at'] );
		$this->assertArrayNotHasKey( 'state', $row );
	}
}
