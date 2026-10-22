<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Restore\RestorePreflight;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Restore\RestorePreflight
 */
class RestorePreflightTest extends TestCase {

	const MB = 1048576;

	private function healthy() {
		return array(
			'ack'                     => array(),
			'plugin_version'          => '0.3.0',
			'manifest_plugin_version' => '0.2.0',
			'encrypted'               => false,
			'manifest_prefix'         => 'wp_',
			'base_prefix'             => 'wp_',
			'manifest_site_url'       => 'https://example.com',
			'current_site_url'        => 'https://example.com',
			'manifest_wp'             => '6.9.1',
			'wp_version'              => '6.9.3',
			'manifest_php'            => '8.3.4',
			'php_version'             => '8.3.10',
			'mode'                    => 'full',
			'restores_db'             => true,
			'restores_files'          => true,
			'has_parts_for_mode'      => true,
			'db_parts'                => 1,
			'files_parts'             => 2,
			'chosen_source'           => 0,
			'busy'                    => '',
			'local_dir_error'         => '',
			'local_dir_writable'      => true,
			'free_bytes'              => 500 * self::MB,
			'needed_bytes'            => 100 * self::MB,
			'disk_check'              => true,
			'source_remote'           => false,
		);
	}

	private function codes( array $items ) {
		return array_column( $items, 'code' );
	}

	private function check( array $override ) {
		return RestorePreflight::evaluate( array_merge( $this->healthy(), $override ) );
	}

	public function test_a_healthy_restore_is_ok_without_messages() {
		$result = RestorePreflight::evaluate( $this->healthy() );

		$this->assertSame( true, $result['ok'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame( array(), $result['requires_ack'] );
	}

	public function test_no_facts_means_nothing_to_report() {
		$this->assertSame( true, RestorePreflight::evaluate( array() )['ok'] );
	}

	/**
	 * @dataProvider refusals
	 */
	public function test_each_refusal( array $override, $code ) {
		$result = $this->check( $override );

		$this->assertFalse( $result['ok'], $code );
		$this->assertContains( $code, $this->codes( $result['errors'] ) );
	}

	public function refusals() {
		return array(
			'newer plugin version'           => array( array( 'manifest_plugin_version' => '0.4.0' ), 'newer_version' ),
			'newer schema (unparsable)'      => array(
				array(
					'manifest_newer' => true,
					'manifest_error' => 'x',
				),
				'newer_version',
			),
			'unreadable manifest'            => array( array( 'manifest_error' => 'The manifest of this backup is damaged.' ), 'manifest' ),
			'encrypted'                      => array( array( 'encrypted' => true ), 'encrypted' ),
			'different table prefix'         => array( array( 'manifest_prefix' => 'site2_' ), 'prefix_mismatch' ),
			'db mode without a db part'      => array(
				array(
					'mode'               => 'db',
					'has_parts_for_mode' => false,
				),
				'no_parts',
			),
			'files mode without files'       => array(
				array(
					'mode'               => 'files',
					'has_parts_for_mode' => false,
				),
				'no_parts',
			),
			'empty backup'                   => array( array( 'has_parts_for_mode' => false ), 'no_parts' ),
			'no complete source'             => array(
				array(
					'chosen_source'  => null,
					'source_reasons' => 'Local folder: a part is missing.',
				),
				'no_source',
			),
			'a backup is running'            => array( array( 'busy' => 'backup' ), 'backup_running' ),
			'another restore is running'     => array( array( 'busy' => 'restore' ), 'restore_running' ),
			'local folder cannot be created' => array( array( 'local_dir_error' => 'The local backup folder could not be created.' ), 'local_dir' ),
			'local folder not writable'      => array( array( 'local_dir_writable' => false ), 'local_dir_not_writable' ),
			'not enough disk space'          => array( array( 'free_bytes' => 10 * self::MB ), 'disk_space' ),
			'destination needs a reconnect'  => array(
				array(
					'source_remote'          => true,
					'source_needs_reconnect' => true,
					'source_reachable'       => false,
				),
				'needs_reconnect',
			),
			'destination unreachable'        => array(
				array(
					'source_remote'    => true,
					'source_reachable' => false,
					'source_error'     => 'Timeout',
				),
				'source_unreachable',
			),
		);
	}

	public function test_messages_are_precise() {
		$prefix = $this->check( array( 'manifest_prefix' => 'site2_' ) )['errors'][0]['message'];
		$this->assertStringContainsString( '"site2_"', $prefix );
		$this->assertStringContainsString( '"wp_"', $prefix );
		$this->assertStringContainsString( 'not supported yet', $prefix );

		$newer = $this->check( array( 'manifest_plugin_version' => '0.4.0' ) )['errors'][0]['message'];
		$this->assertStringContainsString( 'newer version of OpenVault Backup', $newer );
		$this->assertStringContainsString( 'Update the plugin', $newer );

		$disk = $this->check( array( 'free_bytes' => 40 * self::MB ) )['errors'][0]['message'];
		$this->assertSame( 'Not enough free disk space: 40.0 MB free, 100.0 MB needed.', $disk );

		$unreachable = $this->check(
			array(
				'source_remote'    => true,
				'source_reachable' => false,
				'source_error'     => 'Timeout',
			)
		)['errors'][0]['message'];
		$this->assertStringContainsString( 'Timeout', $unreachable );

		$no_source = $this->check(
			array(
				'chosen_source'  => null,
				'source_reasons' => 'Local folder: a part is missing.',
			)
		)['errors'][0]['message'];
		$this->assertStringContainsString( 'Local folder: a part is missing.', $no_source );
	}

	public function test_the_same_or_an_older_plugin_version_is_accepted() {
		$this->assertTrue( $this->check( array( 'manifest_plugin_version' => '0.3.0' ) )['ok'] );
		$this->assertTrue( $this->check( array( 'manifest_plugin_version' => '0.1.0' ) )['ok'] );
		$this->assertFalse( $this->check( array( 'manifest_plugin_version' => '0.3.1' ) )['ok'] );
	}

	public function test_a_files_only_restore_ignores_prefix_and_site_address() {
		$result = $this->check(
			array(
				'mode'              => 'files',
				'restores_db'       => false,
				'manifest_prefix'   => 'site2_',
				'manifest_site_url' => 'https://old.example.org',
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['requires_ack'] );
	}

	public function test_a_site_address_mismatch_needs_an_acknowledgement() {
		$facts  = array( 'manifest_site_url' => 'https://old.example.org' );
		$result = $this->check( $facts );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( 'url_mismatch' ), $this->codes( $result['requires_ack'] ) );
		$this->assertStringContainsString( 'https://old.example.org', $result['requires_ack'][0]['message'] );
		$this->assertStringContainsString( 'search-replace', $result['requires_ack'][0]['message'] );

		$acked = $this->check( array_merge( $facts, array( 'ack' => array( 'url_mismatch' ) ) ) );
		$this->assertTrue( $acked['ok'] );
		$this->assertSame( array(), $acked['requires_ack'] );

		$wrong = $this->check( array_merge( $facts, array( 'ack' => array( 'something_else' ) ) ) );
		$this->assertFalse( $wrong['ok'] );
	}

	public function test_an_acknowledgement_never_overrides_an_error() {
		$result = $this->check(
			array(
				'manifest_site_url' => 'https://old.example.org',
				'encrypted'         => true,
				'ack'               => array( 'url_mismatch' ),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'encrypted' ), $this->codes( $result['errors'] ) );
	}

	public function test_site_address_comparison_ignores_case_trailing_slash_and_default_ports() {
		$this->assertTrue( RestorePreflight::same_site_url( 'https://Example.com/', 'https://example.com' ) );
		$this->assertTrue( RestorePreflight::same_site_url( 'https://example.com:443/blog/', 'https://example.com/blog' ) );
		$this->assertTrue( RestorePreflight::same_site_url( 'http://example.com', 'http://example.com:80' ) );
		$this->assertFalse( RestorePreflight::same_site_url( 'http://example.com', 'https://example.com' ) );
		$this->assertFalse( RestorePreflight::same_site_url( 'https://example.com/blog', 'https://example.com' ) );
		$this->assertFalse( RestorePreflight::same_site_url( 'https://www.example.com', 'https://example.com' ) );
		$this->assertFalse( RestorePreflight::same_site_url( 'https://example.com:8443', 'https://example.com' ) );
	}

	public function test_version_drift_warns_and_does_not_block() {
		$result = $this->check(
			array(
				'manifest_wp'  => '6.4.2',
				'manifest_php' => '7.4.33',
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'wp_version', 'php_version' ), $this->codes( $result['warnings'] ) );

		$newer = $this->check( array( 'manifest_wp' => '7.0' ) )['warnings'][0]['message'];
		$this->assertStringContainsString( 'newer WordPress', $newer );

		$this->assertSame( array(), $this->check( array( 'manifest_wp' => '6.9.0' ) )['warnings'], 'same major.minor is no drift' );
	}

	public function test_a_backup_with_only_one_kind_of_data_warns_in_full_mode() {
		$files = $this->check(
			array(
				'db_parts'    => 0,
				'restores_db' => false,
			)
		);
		$this->assertTrue( $files['ok'] );
		$this->assertSame( array( 'files_only_backup' ), $this->codes( $files['warnings'] ) );

		$db = $this->check(
			array(
				'files_parts'    => 0,
				'restores_files' => false,
			)
		);
		$this->assertSame( array( 'db_only_backup' ), $this->codes( $db['warnings'] ) );
	}

	public function test_disk_space_maths() {
		$this->assertTrue( $this->check( array( 'free_bytes' => 100 * self::MB ) )['ok'], 'exactly enough' );
		$this->assertFalse( $this->check( array( 'free_bytes' => 100 * self::MB - 1 ) )['ok'] );
		$this->assertTrue(
			$this->check(
				array(
					'free_bytes' => 0,
					'disk_check' => false,
				)
			)['ok'],
			'check switched off'
		);
		$this->assertTrue(
			$this->check(
				array(
					'free_bytes'   => 0,
					'needed_bytes' => 0,
				)
			)['ok'],
			'nothing needed'
		);
	}

	public function test_unknown_free_space_warns_and_continues() {
		$result = $this->check( array( 'free_bytes' => null ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'disk_unknown' ), $this->codes( $result['warnings'] ) );
	}

	public function test_remote_checks_apply_only_to_a_remote_source() {
		$this->assertTrue(
			$this->check(
				array(
					'source_remote'    => false,
					'source_reachable' => false,
				)
			)['ok']
		);
		$this->assertTrue(
			$this->check(
				array(
					'source_remote'    => true,
					'source_reachable' => true,
				)
			)['ok']
		);
	}

	public function test_version_drift_helper() {
		$this->assertFalse( RestorePreflight::version_drift( '6.9.1', '6.9.3' ) );
		$this->assertFalse( RestorePreflight::version_drift( '6.9', '6.9.0' ) );
		$this->assertTrue( RestorePreflight::version_drift( '6.9.1', '6.10.0' ) );
		$this->assertTrue( RestorePreflight::version_drift( '7.4.33', '8.3.10' ) );
		$this->assertFalse( RestorePreflight::version_drift( '8.3.0-alpha', '8.3.9' ) );
	}
}
