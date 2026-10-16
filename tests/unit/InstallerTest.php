<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Core\Installer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Core\Installer
 */
class InstallerTest extends TestCase {

	public function test_pending_returns_everything_on_a_fresh_install() {
		$all = array(
			'1' => 'migrate_1',
			'2' => 'migrate_2',
		);

		$this->assertSame( array( 'migrate_1', 'migrate_2' ), array_values( Installer::pending( '0', $all ) ) );
	}

	public function test_pending_skips_versions_already_installed() {
		$all = array(
			'1' => 'migrate_1',
			'2' => 'migrate_2',
			'3' => 'migrate_3',
		);

		$this->assertSame( array( 'migrate_3' ), array_values( Installer::pending( '2', $all ) ) );
	}

	public function test_pending_is_empty_when_up_to_date() {
		$this->assertSame(
			array(),
			Installer::pending(
				'2',
				array(
					'1' => 'a',
					'2' => 'b',
				)
			)
		);
	}

	public function test_pending_orders_versions_numerically_not_alphabetically() {
		$all = array(
			'10' => 'migrate_10',
			'2'  => 'migrate_2',
			'1'  => 'migrate_1',
		);

		$this->assertSame( array( 'migrate_1', 'migrate_2', 'migrate_10' ), array_values( Installer::pending( '0', $all ) ) );
	}

	public function test_every_registered_migration_has_a_method() {
		foreach ( Installer::migrations() as $method ) {
			$this->assertTrue( method_exists( Installer::class, $method ), "Missing {$method}()" );
		}
	}

	public function test_db_version_constant_matches_latest_migration() {
		$versions = array_map( 'strval', array_keys( Installer::migrations() ) );
		usort( $versions, 'version_compare' );

		$this->assertSame( OVB_DB_VERSION, end( $versions ) );
	}

	public function test_table_names_cover_the_planned_schema() {
		$this->assertSame(
			array( 'backups', 'backup_files', 'destinations', 'schedules', 'logs' ),
			Installer::table_names()
		);
	}
}
