<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Restore\SelfPreserver;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the self-preservation: which option rows are kept, the active-plugins rule and the capture file format.
 *
 * @covers \OpenVault\Backup\Restore\SelfPreserver
 */
class SelfPreserverTest extends TestCase {

	public function test_which_options_are_kept() {
		foreach ( array( 'ovb_settings', 'ovb_local_dir', 'ovb_db_version', 'ovb_installed_at', 'ovb_lock_restore', 'ovb_lock_12', 'ovb_recovery_checked', 'ovb_', 'action_scheduler_lock_async-request-runner', 'action_scheduler_migration_status', 'schema-ActionScheduler_StoreSchema', 'schema-ActionScheduler_LoggerSchema' ) as $name ) {
			$this->assertTrue( SelfPreserver::is_preserved_option( $name ), $name );
		}
		foreach ( array( 'siteurl', 'home', 'active_plugins', 'blogname', 'OVB_settings', 'my_ovb_settings', ' ovb_x', 'ovb', 'action_scheduler', 'schema-ActionScheduler', 'schema-actionscheduler_x', '_transient_ovb_x', 'woocommerce_ovb_', '', null, 5, array() ) as $name ) {
			$this->assertFalse( SelfPreserver::is_preserved_option( $name ), is_scalar( $name ) ? (string) $name : gettype( $name ) );
		}
	}

	public function test_volatile_options_are_the_ones_that_change_while_the_job_runs() {
		$this->assertTrue( SelfPreserver::is_volatile_option( 'ovb_lock_restore' ) );
		$this->assertTrue( SelfPreserver::is_volatile_option( 'ovb_lock_5' ) );
		$this->assertTrue( SelfPreserver::is_volatile_option( 'ovb_recovery_checked' ) );
		$this->assertTrue( SelfPreserver::is_volatile_option( 'action_scheduler_lock_async-request-runner' ) );
		$this->assertFalse( SelfPreserver::is_volatile_option( 'ovb_settings' ) );
		$this->assertFalse( SelfPreserver::is_volatile_option( 'ovb_local_dir' ) );
	}

	public function test_row_selection_keeps_only_preserved_rows_in_a_normal_shape() {
		$rows = array(
			array(
				'option_name'  => 'siteurl',
				'option_value' => 'http://x',
				'autoload'     => 'yes',
			),
			array(
				'option_name'  => 'ovb_settings',
				'option_value' => 'a:1:{s:1:"a";i:1;}',
				'autoload'     => 'yes',
			),
			array(
				'option_name'  => 'ovb_lock_restore',
				'option_value' => '1700000000:abcd',
				'autoload'     => 'no',
			),
			array(
				'option_name'  => 'ovb_odd',
				'option_value' => 'v',
				'autoload'     => "'; DROP TABLE x",
			),
			array(
				'option_name'  => 'ovb_noauto',
				'option_value' => 'v',
			),
			array(
				'option_name'  => 'ovb_new_autoload',
				'option_value' => 'v',
				'autoload'     => 'auto-on',
			),
			array( 'option_name' => 'ovb_no_value' ),
			(object) array(
				'option_name'  => 'action_scheduler_x',
				'option_value' => 'z',
				'autoload'     => 'no',
			),
		);
		$kept = SelfPreserver::select_rows( $rows );
		$this->assertSame( array( 'ovb_settings', 'ovb_lock_restore', 'ovb_odd', 'ovb_noauto', 'ovb_new_autoload', 'action_scheduler_x' ), array_column( $kept, 'n' ) );
		$this->assertSame( 'no', $kept[1]['a'] );
		$this->assertSame( 'yes', $kept[2]['a'], 'an invalid autoload flag becomes the default' );
		$this->assertSame( 'yes', $kept[3]['a'] );
		$this->assertSame( 'auto-on', $kept[4]['a'] );
	}

	public function test_this_plugin_is_added_to_the_active_plugins_and_the_others_are_kept() {
		$me = 'openvault-backup/openvault-backup.php';
		$this->assertSame( array( 'a/a.php', 'b/b.php', $me ), SelfPreserver::ensure_plugin_active( array( 'a/a.php', 'b/b.php' ), $me ) );
		$this->assertSame( array( $me, 'a/a.php' ), SelfPreserver::ensure_plugin_active( array( $me, 'a/a.php' ), $me ), 'already there: unchanged, same order' );
		$this->assertSame( array( $me ), SelfPreserver::ensure_plugin_active( array(), $me ) );
		$this->assertSame( array( $me ), SelfPreserver::ensure_plugin_active( false, $me ) );
		$this->assertSame( array( $me ), SelfPreserver::ensure_plugin_active( 'garbage', $me ) );
		$this->assertSame( array( 'a/a.php', $me ), SelfPreserver::ensure_plugin_active( array( 'a/a.php', '', null, 5, array() ), $me ), 'entries that are not plugin paths are dropped' );
	}

	public function test_the_capture_file_round_trips_binary_values() {
		$data = array(
			'plugin'  => 'openvault-backup/openvault-backup.php',
			'user'    => 'admin',
			'at'      => 1700000000,
			'options' => array(
				array(
					'n' => 'ovb_settings',
					'v' => 'a:1:{s:1:"k";s:3:"v\'"";}',
					'a' => 'yes',
				),
				array(
					'n' => 'ovb_blob',
					'v' => "\0\xFF\xFE binary \n \r",
					'a' => 'no',
				),
				array(
					'n' => 'ovb_unicode',
					'v' => "\xF0\x9F\x94\x92",
					'a' => 'yes',
				),
				array(
					'n' => 'ovb_empty',
					'v' => '',
					'a' => 'yes',
				),
			),
			'urls'    => array(
				'siteurl' => 'https://a.example',
				'home'    => 'https://a.example/blog',
			),
		);
		$json = SelfPreserver::encode( $data );
		$this->assertNotSame( '', $json );
		$back = SelfPreserver::decode( $json );
		$this->assertSame( $data['plugin'], $back['plugin'] );
		$this->assertSame( 'admin', $back['user'] );
		$this->assertSame( $data['options'], $back['options'] );
		$this->assertSame( $data['urls'], $back['urls'] );

		$data['urls'] = null;
		$this->assertNull( SelfPreserver::decode( SelfPreserver::encode( $data ) )['urls'] );
	}

	public function test_a_damaged_or_foreign_capture_file_is_refused() {
		$good = array(
			'plugin'  => 'p/p.php',
			'options' => array(
				array(
					'n' => 'ovb_a',
					'v' => 'x',
					'a' => 'yes',
				),
			),
		);
		$this->assertNotNull( SelfPreserver::decode( SelfPreserver::encode( $good ) ) );

		$this->assertNull( SelfPreserver::decode( null ) );
		$this->assertNull( SelfPreserver::decode( '' ) );
		$this->assertNull( SelfPreserver::decode( 'not json' ) );
		$this->assertNull( SelfPreserver::decode( '[]' ) );
		$this->assertNull( SelfPreserver::decode( '{"version":2,"plugin":"p","options":[]}' ) );
		$this->assertNull( SelfPreserver::decode( '{"version":1,"options":[]}' ) );
		$this->assertNull( SelfPreserver::decode( '{"version":1,"plugin":"p","options":"x"}' ) );
		$this->assertNull( SelfPreserver::decode( '{"version":1,"plugin":"p","options":[{"n":"siteurl","v":"eA==","a":"yes"}]}' ), 'a row that is not a plugin row is never written back' );
		$this->assertNull( SelfPreserver::decode( '{"version":1,"plugin":"p","options":[{"n":"ovb_a","v":"***","a":"yes"}]}' ), 'bad base64' );
		$this->assertNull( SelfPreserver::decode( '{"version":1,"plugin":"p","options":[{"n":"ovb_a","v":5,"a":"yes"}]}' ) );
		$this->assertNull( SelfPreserver::decode( '{"version":1,"plugin":"p","options":[],"urls":{"siteurl":"***"}}' ) );
		$this->assertNull( SelfPreserver::decode( str_repeat( 'x', SelfPreserver::MAX_BYTES + 1 ) ) );
	}

	public function test_only_siteurl_and_home_can_be_kept_as_urls() {
		$json = '{"version":1,"plugin":"p","options":[],"urls":{"siteurl":"YQ==","home":"Yg==","admin_email":"Yw=="}}';
		$back = SelfPreserver::decode( $json );
		$this->assertSame(
			array(
				'siteurl' => 'a',
				'home'    => 'b',
			),
			$back['urls']
		);
	}

	public function test_the_file_lives_in_the_given_folder() {
		$this->assertSame( '/a/b/self-preserve.json', SelfPreserver::path( '/a/b/' ) );
		$this->assertSame( 'C:/x/restore-3/self-preserve.json', SelfPreserver::path( 'C:\\x\\restore-3' ) );
	}
}
