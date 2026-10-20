<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\LogsRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Database\LogsRepository
 */
class LogsRepositoryTest extends TestCase {

	public function test_query_defaults() {
		$q = LogsRepository::normalise_query( array() );
		$this->assertNull( $q['level'] );
		$this->assertNull( $q['backup_id'] );
		$this->assertSame( '', $q['search'] );
		$this->assertSame( 'DESC', $q['order'] );
		$this->assertSame( 20, $q['per_page'] );
		$this->assertSame( 1, $q['page'] );
	}

	public function test_query_drops_unknown_values_and_clamps() {
		$q = LogsRepository::normalise_query(
			array(
				'level'     => "error' OR '1'='1",
				'backup_id' => '0',
				'date_from' => 'x',
				'date_to'   => '2026-99-99',
				'order'     => 'RAND()',
				'per_page'  => 5000,
				'page'      => 0,
			)
		);
		$this->assertNull( $q['level'] );
		$this->assertNull( $q['backup_id'] );
		$this->assertNull( $q['date_from'] );
		$this->assertNull( $q['date_to'] );
		$this->assertSame( 'DESC', $q['order'] );
		$this->assertSame( 200, $q['per_page'] );
		$this->assertSame( 1, $q['page'] );
	}

	public function test_query_keeps_valid_values() {
		foreach ( array( 'debug', 'info', 'warning', 'error' ) as $level ) {
			$this->assertSame( $level, LogsRepository::normalise_query( array( 'level' => $level ) )['level'] );
		}
		$q = LogsRepository::normalise_query(
			array(
				'backup_id' => '9',
				'search'    => '  disk full ',
				'date_from' => '2026-01-01',
				'date_to'   => '2026-01-02',
				'order'     => 'asc',
				'per_page'  => 1,
				'page'      => 4,
			)
		);
		$this->assertSame( 9, $q['backup_id'] );
		$this->assertSame( 'disk full', $q['search'] );
		$this->assertSame( '2026-01-02 23:59:59', $q['date_to'] );
		$this->assertSame( 'ASC', $q['order'] );
		$this->assertSame( 3, $q['offset'] );
	}

	public function test_hydrate_decodes_context() {
		$row = LogsRepository::hydrate(
			array(
				'id'         => '1',
				'backup_id'  => null,
				'level'      => 'info',
				'message'    => 'm',
				'context'    => '{"a":1}',
				'created_at' => '2026-01-01 00:00:00',
			)
		);
		$this->assertNull( $row['backup_id'] );
		$this->assertSame( array( 'a' => 1 ), $row['context'] );

		$bad = LogsRepository::hydrate(
			array(
				'id'         => '2',
				'backup_id'  => '5',
				'level'      => 'info',
				'message'    => 'm',
				'context'    => '{broken',
				'created_at' => '2026-01-01 00:00:00',
			)
		);
		$this->assertSame( 5, $bad['backup_id'] );
		$this->assertSame( array(), $bad['context'] );
	}
}
