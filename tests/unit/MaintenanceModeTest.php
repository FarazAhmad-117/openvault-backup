<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Restore\MaintenanceMode as M;
use PHPUnit\Framework\TestCase;

/**
 * The pure rules of the restore maintenance flag: expiry (with an injected clock) and the request allow-list.
 *
 * @covers \OpenVault\Backup\Restore\MaintenanceMode
 */
class MaintenanceModeTest extends TestCase {

	public function test_a_new_flag_expires_after_thirty_minutes() {
		$flag = M::build( 7, 1000 );
		$this->assertSame(
			array(
				'restore_id' => 7,
				'started'    => 1000,
				'expires'    => 2800,
			),
			$flag
		);
		$this->assertTrue( M::is_valid( $flag, 1000 ) );
		$this->assertTrue( M::is_valid( $flag, 2799 ) );
		$this->assertFalse( M::is_valid( $flag, 2800 ), 'expired at the second the time is up' );
		$this->assertFalse( M::is_valid( $flag, 99999 ) );
	}

	public function test_a_refresh_keeps_the_start_and_pushes_the_expiry() {
		$flag = M::build( 7, 1000 );
		$next = M::build( $flag['restore_id'], 2000, $flag['started'] );
		$this->assertSame( 1000, $next['started'] );
		$this->assertSame( 3800, $next['expires'] );
		$this->assertTrue( M::is_valid( $next, 3000 ) );
		$this->assertFalse( M::is_valid( $flag, 3000 ) );
	}

	public function test_a_flag_that_is_missing_malformed_or_implausible_counts_as_off() {
		$now = 5000;
		$this->assertFalse( M::is_valid( null, $now ) );
		$this->assertFalse( M::is_valid( array(), $now ) );
		$this->assertFalse( M::is_valid( array( 'expires' => 0 ), $now ) );
		$this->assertFalse( M::is_valid( array( 'expires' => $now ), $now ) );
		$this->assertFalse( M::is_valid( array( 'expires' => $now + 2 * M::TTL + 1 ), $now ), 'an expiry far in the future is not believed' );
		$this->assertTrue( M::is_valid( array( 'expires' => $now + 2 * M::TTL ), $now ) );

		$this->assertNull( M::parse( null ) );
		$this->assertNull( M::parse( '' ) );
		$this->assertNull( M::parse( 'not json' ) );
		$this->assertNull( M::parse( '[]' ) );
		$this->assertNull( M::parse( '"x"' ) );
		$this->assertNull( M::parse( '{"restore_id":1,"started":1}' ) );
		$this->assertNull( M::parse( '{"restore_id":"1","started":1,"expires":2}' ) );
		$this->assertNull( M::parse( '{"restore_id":0,"started":1,"expires":2}' ) );
		$this->assertNull( M::parse( '{"restore_id":1,"started":1,"expires":-5}' ) );
		$this->assertNull( M::parse( '{"restore_id":1.5,"started":1,"expires":2}' ) );
		$this->assertNull( M::parse( str_repeat( ' ', 2000 ) . '{"restore_id":1,"started":1,"expires":2}' ) );
		$parsed = M::parse( '{"restore_id":3,"started":10,"expires":20,"extra":"ignored"}' );
		$this->assertSame(
			array(
				'restore_id' => 3,
				'started'    => 10,
				'expires'    => 20,
			),
			$parsed
		);
	}

	public function test_the_flag_round_trips_through_json() {
		$flag = M::build( 12, 1700000000 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
		$this->assertSame( $flag, M::parse( json_encode( $flag ) ) );
	}

	private function decide( array $facts ) {
		return M::decide(
			array_merge(
				array(
					'cli'         => false,
					'script'      => 'index.php',
					'rest_ovb'    => false,
					'ajax_action' => '',
					'is_admin'    => false,
				),
				$facts
			)
		);
	}

	public function test_front_end_and_other_requests_are_blocked() {
		$this->assertSame( M::DECISION_BLOCK, $this->decide( array() ) );
		$this->assertSame( M::DECISION_BLOCK, $this->decide( array( 'script' => 'xmlrpc.php' ) ) );
		$this->assertSame( M::DECISION_BLOCK, $this->decide( array( 'script' => 'wp-signup.php' ) ) );
		$this->assertSame( M::DECISION_BLOCK, $this->decide( array( 'script' => '' ) ) );
		$this->assertSame(
			M::DECISION_BLOCK,
			$this->decide(
				array(
					'script'      => 'admin-ajax.php',
					'ajax_action' => 'nopriv_form',
					'is_admin'    => false,
				)
			),
			'ajax of another plugin without an admin area request'
		);
	}

	public function test_the_plugins_own_requests_always_pass() {
		$this->assertSame( M::DECISION_ALLOW, $this->decide( array( 'cli' => true ) ) );
		$this->assertSame( M::DECISION_ALLOW, $this->decide( array( 'script' => 'wp-login.php' ) ) );
		$this->assertSame( M::DECISION_ALLOW, $this->decide( array( 'rest_ovb' => true ) ) );
		foreach ( array( 'as_async_request_queue_runner', 'ovb_progress' ) as $action ) {
			foreach ( array( true, false ) as $admin ) {
				$this->assertSame(
					M::DECISION_ALLOW,
					$this->decide(
						array(
							'script'      => 'admin-ajax.php',
							'ajax_action' => $action,
							'is_admin'    => $admin,
						)
					)
				);
			}
		}
	}

	public function test_ajax_actions_are_matched_exactly() {
		$actions = array( 'as_async_request_queue_runner2', 'xas_async_request_queue_runner', 'ovb', 'ovb_', 'OVB_x', 'ovb-x', "ovb_x\n", 'ovb_' . str_repeat( 'a', 61 ), 'ovb_x y', '' );
		foreach ( $actions as $action ) {
			$decision = $this->decide(
				array(
					'script'      => 'admin-ajax.php',
					'ajax_action' => $action,
					'is_admin'    => true,
				)
			);
			$this->assertNotSame( M::DECISION_ALLOW, $decision, addcslashes( $action, "\n" ) );
		}
		$this->assertSame(
			M::DECISION_ADMIN,
			$this->decide(
				array(
					'script'      => 'admin-ajax.php',
					'ajax_action' => 'heartbeat',
					'is_admin'    => true,
				)
			),
			'other ajax actions are for administrators'
		);
	}

	public function test_cron_runs_with_the_event_filter_and_the_admin_area_needs_the_capability() {
		$this->assertSame( M::DECISION_CRON, $this->decide( array( 'script' => 'wp-cron.php' ) ) );
		$this->assertSame( M::DECISION_ADMIN, $this->decide( array( 'is_admin' => true ) ) );
		$this->assertSame(
			M::DECISION_ADMIN,
			$this->decide(
				array(
					'script'   => 'admin-post.php',
					'is_admin' => true,
				)
			)
		);
	}

	private function facts( $script, $uri, array $request = array(), $admin = false, $cli = false ) {
		return M::facts_from_request(
			array(
				'SCRIPT_NAME' => $script,
				'REQUEST_URI' => $uri,
			),
			$request,
			$admin,
			$cli
		);
	}

	public function test_request_facts_from_the_superglobals() {
		$facts = $this->facts( '/blog/WP-Login.php', '/blog/wp-login.php' );
		$this->assertSame( 'wp-login.php', $facts['script'] );
		$this->assertFalse( $facts['rest_ovb'] );

		foreach ( array( '/wp-json/ovb/v1/restores/3/progress?x=1', '/sub/wp-json/ovb/v1', '/?rest_route=/ovb/v1/backups', '/?rest_route=%2Fovb%2Fv1%2Fbackups', '/?a=1&rest_route=/ovb/v1' ) as $uri ) {
			$this->assertTrue( $this->facts( '/index.php', $uri )['rest_ovb'], $uri );
		}
		foreach ( array( '/wp-json/ovb/v10/x', '/wp-json/ovbx/v1/x', '/wp-json/wp/v2/posts', '/x/wp-json/ovb/v1x', '/?rest_route=/ovb/v1x', '/ovb/v1/backups', '/blog-post/?x=/wp-json/ovb/v1/' ) as $uri ) {
			$this->assertFalse( $this->facts( '/index.php', $uri )['rest_ovb'], $uri );
		}

		$facts = $this->facts( '/wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php', array( 'action' => 'ovb_x' ), true );
		$this->assertSame( 'admin-ajax.php', $facts['script'] );
		$this->assertSame( 'ovb_x', $facts['ajax_action'] );
		$this->assertTrue( $facts['is_admin'] );
		$facts = $this->facts( '/index.php', '/', array( 'action' => array( 'ovb_x' ) ), false, true );
		$this->assertSame( '', $facts['ajax_action'], 'a non-string action is nothing' );
		$this->assertTrue( $facts['cli'] );
		$facts = M::facts_from_request( array(), array(), false, false );
		$this->assertSame( '', $facts['script'] );
	}

	public function test_cron_jobs_are_reduced_to_the_queue_runner() {
		$jobs = array(
			1000 => array(
				'wp_version_check'           => array( 'a' => array() ),
				'action_scheduler_run_queue' => array( 'b' => array( 'args' => array( 'WP Cron' ) ) ),
			),
			1001 => array( 'publish_future_post' => array( 'c' => array() ) ),
			1002 => 'garbage',
			1003 => array( 'action_scheduler_run_queue' => array( 'd' => array() ) ),
		);
		$kept = M::filter_cron_jobs( $jobs, M::CRON_HOOKS );
		$this->assertSame( array( 1000, 1003 ), array_keys( $kept ) );
		$this->assertSame( array( 'action_scheduler_run_queue' ), array_keys( $kept[1000] ) );
		$this->assertSame( array(), M::filter_cron_jobs( array(), M::CRON_HOOKS ) );
		$this->assertSame( array(), M::filter_cron_jobs( $jobs, array() ) );
	}
}
