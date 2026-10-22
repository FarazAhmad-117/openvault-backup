<?php
/**
 * Restore REST routes in-process (Milestone 5, step 5d), included by check-plugin.php inside WordPress.
 *
 * The routes are registered, every route except the token status route needs capability + nonce, the phrase rules, the status token rules
 * (hash only, whitelist of keys, 404 for every mismatch, expiry, filter), no leak of paths, secrets or SQL in any payload, a subscriber and an
 * anonymous request are refused. Synthetic restore rows only (no restore is applied here); everything is removed again.
 * The real wreck-and-restore through HTTP is in http-e2e-restore.sh.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable

use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestoreStatusToken;
use OpenVault\Backup\Rest\RestController;

$rr_repo    = new RestoresRepository();
$rr_table   = $wpdb->prefix . 'ovb_restores';
$rr_routes  = rest_get_server()->get_routes();
$rr_call    = static function ( $method, $path, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/ovb/v1' . $path );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};
$rr_status  = static function ( $response ) {
	return $response->get_status();
};

// 21a. Routes and who may call them.
$rr_expected = array(
	'/ovb/v1/restores/preview'                   => 'POST',
	'/ovb/v1/restores'                           => 'POST',
	'/ovb/v1/restores/(?P<id>\d+)'               => 'GET',
	'/ovb/v1/restores/(?P<id>\d+)/confirm'       => 'POST',
	'/ovb/v1/restores/(?P<id>\d+)/cancel'        => 'POST',
	'/ovb/v1/restores/(?P<id>\d+)/undo'          => 'POST',
	'/ovb/v1/restores/(?P<id>\d+)/undo/dismiss'  => 'POST',
	'/ovb/v1/restores/(?P<id>\d+)/status'        => 'GET',
);
$rr_all = true;
foreach ( $rr_expected as $route => $method ) {
	$found = false;
	foreach ( isset( $rr_routes[ $route ] ) ? $rr_routes[ $route ] : array() as $handler ) {
		$found = $found || isset( $handler['methods'][ $method ] );
	}
	$rr_all = $rr_all && $found;
}
ovb_check( 'restore rest: all eight routes are registered with the right methods', $rr_all );
$rr_guarded = true;
foreach ( $rr_expected as $route => $method ) {
	foreach ( $rr_routes[ $route ] as $handler ) {
		$is_status = '/ovb/v1/restores/(?P<id>\d+)/status' === $route;
		$cb        = $handler['permission_callback'];
		$guarded   = is_array( $cb ) && RestController::class === $cb[0] && 'permission' === $cb[1];
		if ( $is_status ? '__return_true' !== $cb : ! $guarded ) {
			$rr_guarded = false;
		}
	}
}
ovb_check( 'restore rest: every route uses the capability check; only the token status route is public', $rr_guarded );

// 21b. A synthetic running job with a token, and a failed job whose error is full of things that must never reach a browser.
$rr_token = RestoreStatusToken::generate();
$rr_id    = $rr_repo->create( array( 'backup_id' => 1, 'mode' => 'full', 'created_by' => 1 ) );
$rr_meta  = RestoreMeta::with_status_token( RestoreMeta::make( $rr_id, 'full' ), RestoreStatusToken::hash( $rr_token ) );
$rr_repo->save_state( $rr_id, JobState::new_state( JobState::STEP_FETCH )->with_meta( $rr_meta ) );
$rr_repo->update_status( $rr_id, 'running' );
ovb_check( 'restore rest: the token is stored as a sha256 hash only', false === strpos( (string) $wpdb->get_var( "SELECT state FROM `{$rr_table}` WHERE id = {$rr_id}" ), $rr_token ) && 64 === strlen( RestoreMeta::status_token_hash( $rr_repo->load_state( $rr_id )->get_meta() ) ) );

wp_set_current_user( 1 );
$rr_full = $rr_call( 'GET', '/restores/' . $rr_id );
ovb_check( 'restore rest: the admin sees the job (running, kind restore, phase preparing, step label)', 200 === $rr_status( $rr_full ) && 'running' === $rr_full->get_data()['status'] && 'restore' === $rr_full->get_data()['kind'] && 'preparing' === $rr_full->get_data()['phase'] && '' !== $rr_full->get_data()['step_label'] );
$rr_pub = $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => $rr_token ) );
$rr_keys = array_keys( (array) $rr_pub->get_data() );
sort( $rr_keys );
ovb_check( 'restore rest: the status route with the right token answers 200 with exactly the six whitelisted keys', 200 === $rr_status( $rr_pub ) && array( 'done', 'failed', 'message', 'percent', 'status', 'step_label' ) === $rr_keys );
$rr_headers = $rr_pub->get_headers();
ovb_check( 'restore rest: the status route sends Cache-Control no-store', isset( $rr_headers['Cache-Control'] ) && false !== strpos( $rr_headers['Cache-Control'], 'no-store' ) );
wp_set_current_user( 0 );
ovb_check( 'restore rest: it works without a login (no cookie, no nonce)', 200 === $rr_status( $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => $rr_token ) ) ) );
ovb_check( 'restore rest: wrong, malformed, empty and missing tokens are 404; so are other ids', 404 === $rr_status( $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => str_repeat( '0', 32 ) ) ) ) && 404 === $rr_status( $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => 'x' ) ) ) && 404 === $rr_status( $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => '' ) ) ) && 404 === $rr_status( $rr_call( 'GET', '/restores/' . $rr_id . '/status' ) ) && 404 === $rr_status( $rr_call( 'GET', '/restores/' . ( $rr_id + 100000 ) . '/status', array( 't' => $rr_token ) ) ) );
ovb_check( 'restore rest: a wrong token and an unknown job answer with the same body', $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => str_repeat( '1', 32 ) ) )->get_data() === $rr_call( 'GET', '/restores/9999999/status', array( 't' => $rr_token ) )->get_data() );
add_filter( 'ovb_restore_status_token_enabled', '__return_false' );
ovb_check( 'restore rest: the filter ovb_restore_status_token_enabled=false turns the route off (404 even with the right token)', 404 === $rr_status( $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => $rr_token ) ) ) );
remove_filter( 'ovb_restore_status_token_enabled', '__return_false' );

// Failed job with a hostile error text: the admin payload is scrubbed, the public payload has no error text at all.
$rr_failed = JobState::new_state( JobState::STEP_DB_RESTORE )->with_meta( $rr_meta )->mark_failed( 'Import failed in ' . ABSPATH . 'wp-content/ovb-leak token=LeakySecretToken123456 password=hunter2hunter2' );
$rr_repo->save_state( $rr_id, $rr_failed );
$rr_repo->update_status( $rr_id, 'failed', array( 'error' => (string) $rr_failed->get_error() ) );
wp_set_current_user( 1 );
$rr_body = wp_json_encode( $rr_call( 'GET', '/restores/' . $rr_id )->get_data() );
ovb_check( 'restore rest: the failed job payload hides the server path and masks the secrets', false === strpos( $rr_body, rtrim( ABSPATH, '/' ) ) && false === strpos( $rr_body, 'LeakySecretToken123456' ) && false === strpos( $rr_body, 'hunter2' ) && false !== strpos( $rr_body, '[site]' ), $rr_body );
wp_set_current_user( 0 );
$rr_pub = $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => $rr_token ) );
$rr_pub_body = wp_json_encode( $rr_pub->get_data() );
ovb_check( 'restore rest: the public payload of a failed job says failed and shows no error text, path or secret', true === $rr_pub->get_data()['failed'] && true === $rr_pub->get_data()['done'] && false === strpos( $rr_pub_body, 'Import failed' ) && false === strpos( $rr_pub_body, 'LeakySecret' ) && false === strpos( $rr_pub_body, rtrim( ABSPATH, '/' ) ), $rr_pub_body );

// Expiry: a job that finished more than an hour ago.
$wpdb->query( $wpdb->prepare( "UPDATE `{$rr_table}` SET finished_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s', time() - 3 * 3600 ), $rr_id ) );
ovb_check( 'restore rest: the token is refused (404) more than an hour after the job finished', 404 === $rr_status( $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => $rr_token ) ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE `{$rr_table}` SET finished_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s', time() - 600 ), $rr_id ) );
ovb_check( 'restore rest: ... and works ten minutes after it', 200 === $rr_status( $rr_call( 'GET', '/restores/' . $rr_id . '/status', array( 't' => $rr_token ) ) ) );

// 21c. Capability, phrase and argument rules in-process.
$rr_sub = wp_insert_user( array( 'user_login' => 'ovb_rr_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( (int) $rr_sub );
$rr_denied = true;
foreach ( array( array( 'POST', '/restores/preview' ), array( 'POST', '/restores' ), array( 'GET', '/restores' ), array( 'GET', '/restores/' . $rr_id ), array( 'POST', '/restores/' . $rr_id . '/confirm' ), array( 'POST', '/restores/' . $rr_id . '/cancel' ), array( 'POST', '/restores/' . $rr_id . '/undo' ), array( 'POST', '/restores/' . $rr_id . '/undo/dismiss' ) ) as $rr_pair ) {
	$rr_denied = $rr_denied && 403 === $rr_status( $rr_call( $rr_pair[0], $rr_pair[1], array( 'backup_id' => 1, 'mode' => 'db', 'confirm_phrase' => 'RESTORE' ) ) );
}
ovb_check( 'restore rest: a subscriber gets 403 on every authenticated route, even with the right phrase', $rr_denied );
wp_set_current_user( 0 );
$rr_anon = true;
foreach ( array( array( 'POST', '/restores/preview' ), array( 'POST', '/restores' ), array( 'GET', '/restores' ), array( 'GET', '/restores/' . $rr_id ), array( 'POST', '/restores/' . $rr_id . '/confirm' ) ) as $rr_pair ) {
	$rr_anon = $rr_anon && 401 === $rr_status( $rr_call( $rr_pair[0], $rr_pair[1], array( 'backup_id' => 1, 'mode' => 'db', 'confirm_phrase' => 'RESTORE' ) ) );
}
ovb_check( 'restore rest: an anonymous request gets 401 on every authenticated route', $rr_anon );
wp_delete_user( (int) $rr_sub );

wp_set_current_user( 1 );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$rr_table}`" );
$rr_phrase = true;
foreach ( array( null, '', 'restore', 'Restore', 'RESTORE ', ' RESTORE', 'RESTOR' ) as $rr_try ) {
	$params   = array( 'backup_id' => 1, 'mode' => 'db' );
	if ( null !== $rr_try ) {
		$params['confirm_phrase'] = $rr_try;
	}
	$response = $rr_call( 'POST', '/restores', $params );
	$rr_phrase = $rr_phrase && 400 === $rr_status( $response ) && 'ovb_confirm_phrase' === $response->get_data()['code'];
}
ovb_check( 'restore rest: start without the exact phrase RESTORE is 400 with a clear message and creates nothing', $rr_phrase && $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$rr_table}`" ) );
ovb_check( 'restore rest: confirm and undo without the phrase are 400; confirm with the phrase on a job that is not verified is 409', 400 === $rr_status( $rr_call( 'POST', '/restores/' . $rr_id . '/confirm' ) ) && 400 === $rr_status( $rr_call( 'POST', '/restores/' . $rr_id . '/undo', array( 'confirm_phrase' => 'nope' ) ) ) && 409 === $rr_status( $rr_call( 'POST', '/restores/' . $rr_id . '/confirm', array( 'confirm_phrase' => 'RESTORE' ) ) ) );
ovb_check( 'restore rest: modes and ids are whitelisted (undo, unknown, non numeric ids are 400 or 404)', 400 === $rr_status( $rr_call( 'POST', '/restores/preview', array( 'backup_id' => 1, 'mode' => 'undo' ) ) ) && 400 === $rr_status( $rr_call( 'POST', '/restores/preview', array( 'backup_id' => 'x', 'mode' => 'db' ) ) ) && 404 === $rr_status( $rr_call( 'POST', '/restores/preview', array( 'backup_id' => 2147000099, 'mode' => 'db' ) ) ) && 404 === $rr_status( $rr_call( 'GET', '/restores/2147000099' ) ) );
ovb_check( 'restore rest: a finished job cannot be cancelled (409 with a message) and has no work to dismiss', 409 === $rr_status( $rr_call( 'POST', '/restores/' . $rr_id . '/cancel' ) ) );
$rr_list = $rr_call( 'GET', '/restores', array( 'per_page' => 5, 'page' => 1 ) );
ovb_check( 'restore rest: the list is small, newest first and paginated', 200 === $rr_status( $rr_list ) && $rr_id === $rr_list->get_data()[0]['id'] && isset( $rr_list->get_headers()['X-WP-Total'] ) && array( 'id', 'backup_id', 'mode', 'kind', 'status', 'started_at', 'finished_at' ) === array_keys( $rr_list->get_data()[0] ) );

// 21d. Pages: the Restore page renders for the admin, the banner shows while a restore is active.
$rr_active = $rr_repo->create( array( 'backup_id' => 1, 'mode' => 'db', 'created_by' => 1 ) );
$rr_repo->save_state( $rr_active, JobState::new_state( JobState::STEP_FETCH )->with_meta( RestoreMeta::make( $rr_active, 'db' ) ) );
$rr_repo->update_status( $rr_active, 'running' );
ob_start();
( new OpenVault\Backup\Admin\Pages\HistoryPage() )->render();
$rr_history = (string) ob_get_clean();
ovb_check( 'restore page: the banner "A restore is in progress" with a link to the status shows on other plugin pages while a restore is active', false !== strpos( $rr_history, 'ovb-restore-banner' ) && false !== strpos( $rr_history, 'restore=' . $rr_active ) );
ob_start();
( new OpenVault\Backup\Admin\Pages\RestorePage() )->render();
$rr_page = (string) ob_get_clean();
ovb_check( 'restore page: it shows the running restore instead of the chooser and has no banner of its own', false !== strpos( $rr_page, 'data-ovb-restore-id="' . $rr_active . '"' ) && false === strpos( $rr_page, 'ovb-restore-banner' ) && false !== strpos( $rr_page, 'Type RESTORE' ) );
$wpdb->query( "DELETE FROM `{$rr_table}` WHERE id IN ({$rr_id}, {$rr_active})" );
ob_start();
( new OpenVault\Backup\Admin\Pages\HistoryPage() )->render();
$rr_history = (string) ob_get_clean();
ovb_check( 'restore page: the banner is gone when no restore is active and the synthetic rows are removed', false === strpos( $rr_history, 'ovb-restore-banner' ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$rr_table}` WHERE id IN ({$rr_id}, {$rr_active})" ) );
