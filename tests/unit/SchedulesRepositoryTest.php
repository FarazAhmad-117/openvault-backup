<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\SchedulesRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Database\SchedulesRepository
 */
class SchedulesRepositoryTest extends TestCase {

	public function test_defaults() {
		$s = SchedulesRepository::normalise( array() );

		$this->assertSame( '', $s['label'] );
		$this->assertSame( 'daily', $s['recurrence'] );
		$this->assertSame( 'zip', $s['format'] );
		$this->assertNull( $s['start_time'] );
		$this->assertNull( $s['next_run_at'] );
		$this->assertSame( array(), $s['contents'] );
		$this->assertSame( array(), $s['exclusions'] );
		$this->assertSame( array(), $s['destination_ids'] );
		$this->assertSame(
			array(
				'keep_last' => 0,
				'keep_days' => 0,
			),
			$s['retention']
		);
		$this->assertSame( 1, $s['enabled'] );
	}

	public function test_partial_mode_only_returns_given_keys() {
		$this->assertSame( array( 'recurrence' => 'weekly' ), SchedulesRepository::normalise( array( 'recurrence' => 'weekly' ), false ) );
		$this->assertSame( array(), SchedulesRepository::normalise( array(), false ) );
	}

	public function test_every_recurrence_and_format_accepted() {
		foreach ( SchedulesRepository::RECURRENCES as $r ) {
			$this->assertSame( $r, SchedulesRepository::normalise( array( 'recurrence' => $r ) )['recurrence'] );
		}
		foreach ( array( 'zip', 'zip-store', 'tar.gz', 'tar.bz2' ) as $f ) {
			$this->assertSame( $f, SchedulesRepository::normalise( array( 'format' => $f ) )['format'] );
		}
	}

	/**
	 * @dataProvider invalid_input
	 */
	public function test_invalid_input_throws( array $input ) {
		$this->expectException( \InvalidArgumentException::class );
		SchedulesRepository::normalise( $input );
	}

	public function invalid_input() {
		return array(
			'recurrence unknown'   => array( array( 'recurrence' => 'yearly' ) ),
			'recurrence injection' => array( array( 'recurrence' => "daily' OR '1'='1" ) ),
			'recurrence uppercase' => array( array( 'recurrence' => 'Daily' ) ),
			'format unknown'       => array( array( 'format' => 'rar' ) ),
			'start bad'            => array( array( 'start_time' => 'tomorrow' ) ),
			'next bad'             => array( array( 'next_run_at' => '2026-02-30 10:00:00' ) ),
			'contents scalar'      => array( array( 'contents' => 'db' ) ),
			'exclusions scalar'    => array( array( 'exclusions' => 1 ) ),
			'dest not list'        => array( array( 'destination_ids' => '1,2' ) ),
			'dest zero'            => array( array( 'destination_ids' => array( 1, 0 ) ) ),
			'dest negative'        => array( array( 'destination_ids' => array( -2 ) ) ),
			'dest text'            => array( array( 'destination_ids' => array( '1 OR 1=1' ) ) ),
			'dest float'           => array( array( 'destination_ids' => array( 1.5 ) ) ),
			'dest too many'        => array( array( 'destination_ids' => range( 1, 51 ) ) ),
			'retention scalar'     => array( array( 'retention' => 5 ) ),
			'keep_last negative'   => array( array( 'retention' => array( 'keep_last' => -1 ) ) ),
			'keep_days text'       => array( array( 'retention' => array( 'keep_days' => 'many' ) ) ),
			'keep_last float'      => array( array( 'retention' => array( 'keep_last' => 2.5 ) ) ),
		);
	}

	public function test_destination_ids_deduplicated_in_order() {
		$this->assertSame( array( 3, 1, 2 ), SchedulesRepository::destination_ids( array( 3, '1', 3, 2, 1 ) ) );
		$this->assertSame( array(), SchedulesRepository::destination_ids( array() ) );
	}

	public function test_retention_drops_unknown_keys_and_accepts_strings() {
		$this->assertSame(
			array(
				'keep_last' => 5,
				'keep_days' => 0,
			),
			SchedulesRepository::retention(
				array(
					'keep_last' => '5',
					'evil'      => 'x',
					'__proto__' => 1,
				)
			)
		);
		$this->assertSame(
			array(
				'keep_last' => 0,
				'keep_days' => 30,
			),
			SchedulesRepository::retention( array( 'keep_days' => 30 ) )
		);
	}

	public function test_label_cleaned_and_unknown_keys_dropped() {
		$s = SchedulesRepository::normalise(
			array(
				'label' => "  Nightly\x00 backup  ",
				'id'    => 99,
				'extra' => 'x',
			)
		);
		$this->assertSame( 'Nightly backup', $s['label'] );
		$this->assertArrayNotHasKey( 'id', $s );
		$this->assertArrayNotHasKey( 'extra', $s );
		$this->assertSame( 190, strlen( SchedulesRepository::normalise( array( 'label' => str_repeat( 'x', 400 ) ) )['label'] ) );
	}

	public function test_enabled_flag_and_datetimes() {
		$this->assertSame( 0, SchedulesRepository::normalise( array( 'enabled' => false ) )['enabled'] );
		$this->assertSame( 0, SchedulesRepository::normalise( array( 'enabled' => '0' ) )['enabled'] );
		$this->assertSame( 1, SchedulesRepository::normalise( array( 'enabled' => true ) )['enabled'] );

		$s = SchedulesRepository::normalise(
			array(
				'start_time'  => '2026-05-01 03:00:00',
				'next_run_at' => null,
			)
		);
		$this->assertSame( '2026-05-01 03:00:00', $s['start_time'] );
		$this->assertNull( $s['next_run_at'] );
	}

	public function test_hydrate_repairs_bad_stored_json() {
		$row = SchedulesRepository::hydrate(
			array(
				'id'              => '2',
				'label'           => 'x',
				'recurrence'      => 'daily',
				'start_time'      => null,
				'contents'        => '{oops',
				'exclusions'      => null,
				'format'          => 'zip',
				'destination_ids' => '[3,"4",0,-1,"x",3]',
				'retention'       => '{"keep_last":"7","keep_days":-5}',
				'enabled'         => '1',
				'next_run_at'     => null,
				'created_at'      => '2026-01-01 00:00:00',
				'updated_at'      => '2026-01-01 00:00:00',
			)
		);
		$this->assertSame( array(), $row['contents'] );
		$this->assertSame( array( 3, 4 ), $row['destination_ids'] );
		$this->assertSame( 7, $row['retention']['keep_last'] );
		$this->assertSame( 0, $row['retention']['keep_days'] );
		$this->assertTrue( $row['enabled'] );

		$empty = SchedulesRepository::hydrate(
			array(
				'id'         => 1,
				'label'      => '',
				'recurrence' => 'daily',
				'format'     => 'zip',
				'enabled'    => 0,
				'created_at' => 'x',
				'updated_at' => 'x',
			)
		);
		$this->assertSame( array(), $empty['destination_ids'] );
		$this->assertFalse( $empty['enabled'] );
	}
}
