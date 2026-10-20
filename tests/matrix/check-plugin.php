<?php
/**
 * Runs INSIDE WordPress (wp eval-file) in every matrix environment.
 * Prints "PASS name" / "FAIL name: reason" and exits non-zero on any failure.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable

$GLOBALS['ovb_matrix_failures'] = 0;

function ovb_check( $name, $ok, $detail = '' ) {
	if ( $ok ) {
		echo "PASS $name\n";
		return;
	}
	++$GLOBALS['ovb_matrix_failures'];
	echo "FAIL $name" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
}

global $wpdb;
wp_set_current_user( 1 );

// 1. Activation result.
ovb_check( 'plugin active', is_plugin_active( 'openvault-backup/openvault-backup.php' ) || is_plugin_active_for_network( 'openvault-backup/openvault-backup.php' ) );
ovb_check( 'constants defined', defined( 'OVB_VERSION' ) && defined( 'OVB_DB_VERSION' ) && defined( 'OVB_PATH' ) );
ovb_check( 'db version stored', (string) get_option( 'ovb_db_version' ) === OVB_DB_VERSION, 'got ' . var_export( get_option( 'ovb_db_version' ), true ) );

// 2. Schema.
$expected_columns = array(
	'backups'      => array( 'id', 'uuid', 'trigger_type', 'status', 'state', 'total_size', 'locked', 'created_at' ),
	'backup_files' => array( 'id', 'backup_id', 'destination_id', 'file_name', 'checksum_sha256', 'upload_status' ),
	'destinations' => array( 'id', 'type', 'settings', 'credentials', 'last_tested_at' ),
	'schedules'    => array( 'id', 'recurrence', 'enabled', 'next_run_at', 'retention' ),
	'logs'         => array( 'id', 'backup_id', 'level', 'message', 'context', 'created_at' ),
);
foreach ( $expected_columns as $table => $columns ) {
	$full = $wpdb->prefix . 'ovb_' . $table;
	$have = $wpdb->get_col( "SHOW COLUMNS FROM `$full`" );
	ovb_check( "table $table exists", ! empty( $have ), $wpdb->last_error );
	ovb_check( "table $table columns", ! array_diff( $columns, (array) $have ), 'missing: ' . implode( ',', array_diff( $columns, (array) $have ) ) );
}
$unique = $wpdb->get_results( "SHOW INDEX FROM `{$wpdb->prefix}ovb_backups` WHERE Key_name = 'uuid'", ARRAY_A );
ovb_check( 'backups.uuid is unique', $unique && '0' === (string) $unique[0]['Non_unique'] );

// 3. Migration is idempotent and re-runnable.
update_option( 'ovb_db_version', '0' );
OpenVault\Backup\Core\Installer::maybe_upgrade();
ovb_check( 'migration re-run', (string) get_option( 'ovb_db_version' ) === OVB_DB_VERSION && '' === $wpdb->last_error, $wpdb->last_error );

// 4. Defaults and protected local folder.
$settings = get_option( 'ovb_settings' );
ovb_check( 'default settings', is_array( $settings ) && isset( $settings['log_retention_days'] ) );
$dir = WP_CONTENT_DIR . '/' . get_option( 'ovb_local_dir' );
ovb_check( 'local dir created', is_dir( $dir ) && file_exists( $dir . '/index.php' ) && file_exists( $dir . '/.htaccess' ) );

// 5. Action Scheduler really works in this environment.
ovb_check( 'action scheduler loaded', class_exists( 'ActionScheduler_Versions' ) && function_exists( 'as_enqueue_async_action' ) );
if ( function_exists( 'as_enqueue_async_action' ) ) {
	add_action(
		'ovb_matrix_ping',
		static function () {
			update_option( 'ovb_matrix_ping', 'pong' );
		}
	);
	delete_option( 'ovb_matrix_ping' );
	$action_id = as_enqueue_async_action( 'ovb_matrix_ping', array(), 'openvault-backup' );
	ActionScheduler_QueueRunner::instance()->run();
	ovb_check( 'action scheduler runs an action', $action_id > 0 && 'pong' === get_option( 'ovb_matrix_ping' ) );
	delete_option( 'ovb_matrix_ping' );
}

// 6. Logger: stores, redacts, filters.
$logger = new OpenVault\Backup\Support\Logger();
$stored = $logger->info( 'matrix probe', array( 'bucket' => 'b', 'secret_key' => 'LEAK-ME', 'nested' => array( 'api_key' => 'LEAK-ME-2' ) ) );
$row    = $wpdb->get_row( "SELECT level, context FROM {$wpdb->prefix}ovb_logs ORDER BY id DESC LIMIT 1", ARRAY_A );
ovb_check( 'logger stores a line', $stored && $row && 'info' === $row['level'] );
ovb_check( 'logger redacts secrets', $row && false === strpos( (string) $row['context'], 'LEAK-ME' ) && false !== strpos( (string) $row['context'], '[redacted]' ) );
ovb_check( 'logger drops debug by default', false === $logger->debug( 'hidden' ) );
$pruned = ( new OpenVault\Backup\Database\LogsRepository() )->prune( 30 );
ovb_check( 'log prune runs', is_int( $pruned ) && '' === $wpdb->last_error, $wpdb->last_error );

// 7. Capabilities.
$expected_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
ovb_check( 'capability mapping', OpenVault\Backup\Core\Capabilities::required() === $expected_cap );
ovb_check( 'admin may manage backups', OpenVault\Backup\Core\Capabilities::current_user_can_manage_backups() );

// 8. Menu and pages.
( new OpenVault\Backup\Admin\Menu() )->register();
global $submenu;
ovb_check( 'menu has 10 subpages', isset( $submenu['openvault'] ) && 10 === count( $submenu['openvault'] ), 'got ' . ( isset( $submenu['openvault'] ) ? count( $submenu['openvault'] ) : 0 ) );
foreach ( OpenVault\Backup\Admin\Menu::pages() as $slug => $page_class ) {
	ob_start();
	( new $page_class() )->render();
	$html = ob_get_clean();
	ovb_check( "page $slug renders", false !== strpos( $html, '<h1>' ) && false === stripos( $html, 'fatal error' ) );
}

// 9. System Info shows real data and all tables.
$info = OpenVault\Backup\Support\SystemInfo::collect();
$flat = OpenVault\Backup\Support\SystemInfo::as_text( $info );
ovb_check( 'system info has PHP version', false !== strpos( $flat, PHP_VERSION ) );
ovb_check( 'system info sees all 5 tables', false !== strpos( $flat, '5 of 5 present' ) );

// 10. Repositories against the real MySQL/MariaDB. Everything created here is removed again.
function ovb_throws( $callable ) {
	try {
		$callable();
	} catch ( \Throwable $e ) {
		return get_class( $e );
	}
	return null;
}

$ovb_marker  = 'ovbmatrix' . wp_generate_password( 8, false );
$ovb_cleanup = array(
	'backups'      => array(),
	'destinations' => array(),
	'schedules'    => array(),
);
$ovb_prefix  = $wpdb->prefix . 'ovb_';
$ovb_errors_before = $wpdb->last_error;

try {
	$backups = new OpenVault\Backup\Database\BackupsRepository();
	$files   = new OpenVault\Backup\Database\BackupFilesRepository();

	// 10a. Backups: create / find.
	$hostile = "{$ovb_marker} it's \"quoted\"; DROP TABLE x; -- %_";
	$id      = $backups->create( array( 'contents' => array( 'db' => true, 'uploads' => true ), 'note' => $hostile, 'format' => 'tar.gz' ) );
	$ovb_cleanup['backups'][] = $id;
	ovb_check( 'repo backups: create returns an id', $id > 0, $wpdb->last_error );

	$row = $backups->find( $id );
	ovb_check( 'repo backups: find returns typed defaults', is_array( $row ) && $row['id'] === $id && 'queued' === $row['status'] && 'manual' === $row['trigger_type'] && 'tar.gz' === $row['format'] && false === $row['locked'] && 0 === $row['total_size'] && null === $row['schedule_id'] );
	ovb_check( 'repo backups: uuid generated and findable', is_array( $row ) && OpenVault\Backup\Support\Manifest::is_valid_uuid( $row['uuid'] ) && ( $backups->find_by_uuid( $row['uuid'] )['id'] ?? 0 ) === $id );
	ovb_check( 'repo backups: contents JSON round trip', is_array( $row ) && array( 'db' => true, 'uploads' => true ) === $row['contents'] );
	ovb_check( 'repo backups: hostile note stored verbatim (bound parameter)', is_array( $row ) && $hostile === $row['note'], is_array( $row ) ? $row['note'] : '' );
	ovb_check( 'repo backups: row has no state column', is_array( $row ) && ! array_key_exists( 'state', $row ) );
	ovb_check( 'repo backups: timestamps are UTC datetimes', is_array( $row ) && 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['created_at'] ) && abs( strtotime( $row['created_at'] . ' UTC' ) - time() ) < 300 );
	ovb_check( 'repo backups: find of a missing id is null', null === $backups->find( 2147483000 ) && null === $backups->find_by_uuid( 'not-a-uuid' ) );
	ovb_check( 'repo backups: invalid enums rejected', 'InvalidArgumentException' === ovb_throws( function () use ( $backups ) { $backups->create( array( 'status' => "complete' OR '1'='1" ) ); } ) && 'InvalidArgumentException' === ovb_throws( function () use ( $backups ) { $backups->create( array( 'format' => '7z' ) ); } ) && 'InvalidArgumentException' === ovb_throws( function () use ( $backups ) { $backups->create( array( 'trigger_type' => 'cron' ) ); } ) );

	// 10b. Status transitions.
	ovb_check( 'repo backups: update_status running', $backups->update_status( $id, 'running' ) && 'running' === $backups->find( $id )['status'] );
	$started = $backups->find( $id )['started_at'];
	ovb_check( 'repo backups: started_at set on first running', is_string( $started ) && null === $backups->find( $id )['finished_at'] );
	$wpdb->update( $ovb_prefix . 'backups', array( 'started_at' => '2020-01-01 00:00:00' ), array( 'id' => $id ) );
	$backups->update_status( $id, 'running' );
	ovb_check( 'repo backups: started_at not overwritten by a second running', '2020-01-01 00:00:00' === $backups->find( $id )['started_at'] );
	ovb_check( 'repo backups: update_status complete sets finished_at and size', $backups->update_status( $id, 'complete', array( 'total_size' => 1000 ) ) && is_string( $backups->find( $id )['finished_at'] ) && 1000 === $backups->find( $id )['total_size'] );
	ovb_check( 'repo backups: update_status of a missing row is false', false === $backups->update_status( 2147483000, 'failed' ) );
	ovb_check( 'repo backups: unknown status rejected on update', 'InvalidArgumentException' === ovb_throws( function () use ( $backups, $id ) { $backups->update_status( $id, 'bogus' ); } ) && 'complete' === $backups->find( $id )['status'] );
	$backups->add_size( $id, 500 );
	ovb_check( 'repo backups: add_size is atomic addition', 1500 === $backups->find( $id )['total_size'] );
	$backups->set_total_size( $id, 42 );
	ovb_check( 'repo backups: set_total_size', 42 === $backups->find( $id )['total_size'] && 'InvalidArgumentException' === ovb_throws( function () use ( $backups, $id ) { $backups->add_size( $id, -1 ); } ) );
	$backups->set_note( $id, $ovb_marker . ' ' . str_repeat( 'n', 3000 ) . "\0" );
	ovb_check( 'repo backups: note is length limited and cleaned', 1000 === strlen( $backups->find( $id )['note'] ) );
	$backups->set_note( $id, $hostile );

	// 10c. JobState round trip through ovb_backups.state.
	ovb_check( 'repo backups: load_state is null before any state', null === $backups->load_state( $id ) );
	$state = OpenVault\Backup\Engine\JobState::new_state( OpenVault\Backup\Engine\JobState::STEP_ARCHIVE )->with_cursor( array( 'file_index' => 12, 'offset' => 345, 'path' => "wp-content/uploads/ü'x.png" ) );
	ovb_check( 'repo backups: save_state', $backups->save_state( $id, $state ) );
	$loaded = $backups->load_state( $id );
	ovb_check( 'repo backups: state round trip', $loaded instanceof OpenVault\Backup\Engine\JobState && 'archive' === $loaded->get_step() && $loaded->get_cursor() === $state->get_cursor() && $loaded->to_json() === $state->to_json() );
	$raw_state = $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$ovb_prefix}backups WHERE id = %d", $id ) );
	ovb_check( 'repo backups: state column holds JSON (not serialize)', is_string( $raw_state ) && null !== json_decode( $raw_state, true ) && 0 !== strpos( $raw_state, 'O:' ) );
	$wpdb->update( $ovb_prefix . 'backups', array( 'state' => '{corrupt' ), array( 'id' => $id ) );
	ovb_check( 'repo backups: corrupt state loads as null, no fatal', null === $backups->load_state( $id ) );
	$wpdb->update( $ovb_prefix . 'backups', array( 'contents' => '{corrupt' ), array( 'id' => $id ) );
	ovb_check( 'repo backups: corrupt contents JSON reads as empty array', array() === $backups->find( $id )['contents'] );

	// 10d. Pagination and filters (scoped by a unique note marker).
	$schedule_id_for_filter = 987001;
	$ids = array(
		$backups->create( array( 'note' => $ovb_marker . ' a', 'status' => 'failed', 'trigger_type' => 'schedule', 'schedule_id' => $schedule_id_for_filter ) ),
		$backups->create( array( 'note' => $ovb_marker . ' b', 'status' => 'queued', 'trigger_type' => 'pre-update' ) ),
		$backups->create( array( 'note' => $ovb_marker . ' c', 'status' => 'complete', 'trigger_type' => 'schedule', 'schedule_id' => $schedule_id_for_filter, 'total_size' => 7 ) ),
	);
	$ovb_cleanup['backups'] = array_merge( $ovb_cleanup['backups'], $ids );
	$all = $backups->paginate( array( 'search' => $ovb_marker, 'per_page' => 2 ) );
	ovb_check( 'repo backups: paginate counts the full set, returns one page', 4 === $all['total'] && 2 === count( $all['items'] ), 'total ' . $all['total'] . ' items ' . count( $all['items'] ) );
	$page2 = $backups->paginate( array( 'search' => $ovb_marker, 'per_page' => 2, 'page' => 2 ) );
	ovb_check( 'repo backups: second page differs and is newest-first', 2 === count( $page2['items'] ) && $page2['items'][0]['id'] < $all['items'][1]['id'] && $all['items'][0]['id'] > $all['items'][1]['id'] );
	ovb_check( 'repo backups: filter by status', 1 === $backups->paginate( array( 'search' => $ovb_marker, 'status' => 'failed' ) )['total'] );
	ovb_check( 'repo backups: filter by trigger_type', 2 === $backups->paginate( array( 'search' => $ovb_marker, 'trigger_type' => 'schedule' ) )['total'] );
	ovb_check( 'repo backups: filter by schedule_id', 2 === $backups->paginate( array( 'schedule_id' => $schedule_id_for_filter ) )['total'] );
	ovb_check( 'repo backups: filter by date range', 4 === $backups->paginate( array( 'search' => $ovb_marker, 'date_from' => gmdate( 'Y-m-d', time() - 86400 ), 'date_to' => gmdate( 'Y-m-d', time() + 86400 ) ) )['total'] && 0 === $backups->paginate( array( 'search' => $ovb_marker, 'date_to' => '2001-01-01' ) )['total'] );
	ovb_check( 'repo backups: hostile search is inert', 0 === $backups->paginate( array( 'search' => "%' OR 1=1 -- " ) )['total'] && '' === $wpdb->last_error, $wpdb->last_error );
	ovb_check( 'repo backups: hostile filters and order are ignored, not executed', $backups->paginate( array( 'search' => $ovb_marker, 'status' => "failed' OR '1'='1", 'orderby' => 'id; DROP TABLE x', 'order' => 'ASC; --' ) )['total'] >= 4 && '' === $wpdb->last_error, $wpdb->last_error );
	ovb_check( 'repo backups: per_page clamped to 200 and page to 1', count( $backups->paginate( array( 'per_page' => 99999, 'page' => -5 ) )['items'] ) <= 200 );
	$asc = $backups->paginate( array( 'search' => $ovb_marker, 'orderby' => 'total_size', 'order' => 'asc' ) );
	ovb_check( 'repo backups: whitelisted order column and direction', $asc['items'][ count( $asc['items'] ) - 1 ]['total_size'] >= $asc['items'][0]['total_size'] );
	$counts = $backups->count_by_status();
	ovb_check( 'repo backups: count_by_status has every status', array( 'queued', 'running', 'uploading', 'complete', 'failed', 'partial' ) === array_keys( $counts ) && $counts['failed'] >= 1 && $counts['queued'] >= 1 );
	$incomplete_ids = array_column( $backups->find_incomplete(), 'id' );
	ovb_check( 'repo backups: find_incomplete lists queued, not complete', in_array( $ids[1], $incomplete_ids, true ) && ! in_array( $id, $incomplete_ids, true ) && ! in_array( $ids[0], $incomplete_ids, true ) );
	$latest = $backups->latest_complete( $schedule_id_for_filter );
	ovb_check( 'repo backups: latest_complete by schedule', is_array( $latest ) && $latest['id'] === $ids[2] && null !== $backups->latest_complete() );
	ovb_check( 'repo backups: latest_complete of an unknown schedule is null', null === $backups->latest_complete( 2147483000 ) );

	// 10e. Backup files.
	$hex = hash( 'sha256', 'matrix' );
	$f2  = $files->add( array( 'backup_id' => $id, 'destination_id' => 0, 'part_number' => 2, 'file_name' => 'site-part2.zip', 'size' => 200, 'checksum_sha256' => strtoupper( $hex ) ) );
	$f1  = $files->add( array( 'backup_id' => $id, 'destination_id' => 0, 'part_number' => 1, 'file_name' => 'site-part1.zip', 'size' => 100 ) );
	$f3  = $files->add( array( 'backup_id' => $id, 'destination_id' => 77, 'part_number' => 1, 'file_name' => 'site-part1.zip', 'size' => 100 ) );
	ovb_check( 'repo files: add returns ids', $f1 > 0 && $f2 > 0 && $f3 > 0, $wpdb->last_error );
	ovb_check( 'repo files: checksum lower-cased and stored', is_array( $files->find( $f2 ) ) && $hex === $files->find( $f2 )['checksum_sha256'] && '' === $files->find( $f1 )['checksum_sha256'] );
	ovb_check( 'repo files: for_backup ordered by destination then part', array( $f1, $f2, $f3 ) === array_column( $files->for_backup( $id ), 'id' ) );
	ovb_check( 'repo files: for_backup_and_destination', array( $f1, $f2 ) === array_column( $files->for_backup_and_destination( $id, 0 ), 'id' ) && array( $f3 ) === array_column( $files->for_backup_and_destination( $id, 77 ), 'id' ) );
	ovb_check( 'repo files: unsafe names and bad values rejected', 'InvalidArgumentException' === ovb_throws( function () use ( $files, $id ) { $files->add( array( 'backup_id' => $id, 'file_name' => '../evil.zip' ) ); } ) && 'InvalidArgumentException' === ovb_throws( function () use ( $files, $id ) { $files->add( array( 'backup_id' => $id, 'file_name' => 'a/b.zip' ) ); } ) && 'InvalidArgumentException' === ovb_throws( function () use ( $files, $id ) { $files->add( array( 'backup_id' => $id, 'file_name' => 'ok.zip', 'checksum_sha256' => 'xyz' ) ); } ) && 'InvalidArgumentException' === ovb_throws( function () use ( $files, $id ) { $files->add( array( 'backup_id' => $id, 'file_name' => 'ok.zip', 'upload_status' => 'weird' ) ); } ) );
	$next = $files->next_pending( $id, 0 );
	ovb_check( 'repo files: next_pending is the lowest pending part', is_array( $next ) && $next['id'] === $f1 && 'pending' === $next['upload_status'] );
	ovb_check( 'repo files: update_upload uploading with session and ref', $files->update_upload( $f2, 'uploading', array( 'upload_session' => 'https://example.test/session?id=1', 'remote_ref' => 'remote-ref-2' ) ) && 'uploading' === $files->find( $f2 )['upload_status'] && 'remote-ref-2' === $files->find( $f2 )['remote_ref'] && 'https://example.test/session?id=1' === $files->find( $f2 )['upload_session'] );
	$next = $files->next_pending( $id, 0 );
	ovb_check( 'repo files: an in-flight upload is resumed first', is_array( $next ) && $next['id'] === $f2 );
	$files->update_upload( $f2, 'done', array( 'upload_session' => '' ) );
	$files->update_upload( $f1, 'done' );
	ovb_check( 'repo files: next_pending is null when everything is done', null === $files->next_pending( $id, 0 ) && is_array( $files->next_pending( $id, 77 ) ) );
	ovb_check( 'repo files: update_upload rejects an unknown status and missing rows', 'InvalidArgumentException' === ovb_throws( function () use ( $files, $f1 ) { $files->update_upload( $f1, 'nope' ); } ) && false === $files->update_upload( 2147483000, 'done' ) );
	$totals = $files->totals_for_backup( $id );
	ovb_check( 'repo files: totals_for_backup', 3 === $totals['rows'] && 400 === $totals['bytes'] && 2 === $totals['by_status']['done'] && 1 === $totals['by_status']['pending'], wp_json_encode( $totals ) );

	// 10f. Lock and delete.
	ovb_check( 'repo backups: set_locked', $backups->set_locked( $id, true ) && true === $backups->find( $id )['locked'] );
	ovb_check( 'repo backups: a locked backup refuses delete', false === $backups->delete( $id ) && null !== $backups->find( $id ) && 3 === count( $files->for_backup( $id ) ) );
	ovb_check( 'repo backups: force delete removes it and its file rows', true === $backups->delete( $id, true ) && null === $backups->find( $id ) && array() === $files->for_backup( $id ) );
	ovb_check( 'repo backups: unlocked delete works and a missing id is false', true === $backups->delete( $ids[1] ) && false === $backups->delete( $ids[1] ) );
	ovb_check( 'repo files: delete_for_backup on nothing returns 0', 0 === $files->delete_for_backup( $id ) );

	// 10g. Destinations: credentials only ever stored encrypted.
	$vault_a = new OpenVault\Backup\Security\CredentialVault( function () { return 'matrix-key-A'; } );
	$vault_b = new OpenVault\Backup\Security\CredentialVault( function () { return 'matrix-key-B'; } );
	$dests_a = new OpenVault\Backup\Database\DestinationsRepository( $vault_a );
	$dests_b = new OpenVault\Backup\Database\DestinationsRepository( $vault_b );
	$secret  = 'PLAIN-SECRET-' . $ovb_marker;
	$akid    = 'AKIAMATRIX' . strtoupper( $ovb_marker );
	$did     = $dests_a->create( 's3', "Matrix \x00S3", array( 'bucket' => 'b', 'region' => 'eu-west-1' ), array( 'access_key_id' => $akid, 'secret_access_key' => $secret ) );
	$ovb_cleanup['destinations'][] = $did;
	ovb_check( 'repo destinations: create returns an id', $did > 0, $wpdb->last_error );
	$raw_credentials = (string) $wpdb->get_var( $wpdb->prepare( "SELECT credentials FROM {$ovb_prefix}destinations WHERE id = %d", $did ) );
	ovb_check( 'repo destinations: raw column is a vault envelope', 0 === strpos( $raw_credentials, 'ovb1:' ) );
	ovb_check( 'repo destinations: raw column never contains the plaintext secret', false === strpos( $raw_credentials, $secret ) && false === strpos( $raw_credentials, $akid ) && false === strpos( $raw_credentials, base64_encode( $secret ) ) );
	$drow = $dests_a->find( $did );
	ovb_check( 'repo destinations: find has no credentials and a clean label', is_array( $drow ) && ! array_key_exists( 'credentials', $drow ) && 'Matrix S3' === $drow['label'] && 'new' === $drow['status'] && true === $drow['has_credentials'] && array( 'bucket' => 'b', 'region' => 'eu-west-1' ) === $drow['settings'] );
	ovb_check( 'repo destinations: no read path returns the secret', false === strpos( wp_json_encode( array( $dests_a->find( $did ), $dests_a->all(), $dests_a->by_type( 's3' ) ) ), $secret ) && false === strpos( wp_json_encode( $dests_a->all() ), 'ovb1:' ) );
	ovb_check( 'repo destinations: has_credentials without decrypting', $dests_a->has_credentials( $did ) && ! $dests_a->has_credentials( 2147483000 ) );
	$creds = $dests_a->get_credentials( $did );
	ovb_check( 'repo destinations: get_credentials round trip', true === $creds['ok'] && null === $creds['reason'] && array( 'access_key_id' => $akid, 'secret_access_key' => $secret ) === $creds['data'] );
	$none = $dests_a->get_credentials( 2147483000 );
	ovb_check( 'repo destinations: get_credentials of a missing row', false === $none['ok'] && 'not_found' === $none['reason'] && array() === $none['data'] );
	ovb_check( 'repo destinations: invalid type rejected', 'InvalidArgumentException' === ovb_throws( function () use ( $dests_a ) { $dests_a->create( 'S3; DROP', 'x', array(), array() ); } ) );
	$local_id = $dests_a->create( 'local', 'Matrix Local', array( 'path' => 'x' ), array() );
	$ovb_cleanup['destinations'][] = $local_id;
	ovb_check( 'repo destinations: destination without credentials', ! $dests_a->has_credentials( $local_id ) && array( 'ok' => true, 'data' => array(), 'reason' => null ) === $dests_a->get_credentials( $local_id ), 'has=' . var_export( $dests_a->has_credentials( $local_id ), true ) . ' id=' . $local_id . ' creds=' . wp_json_encode( $dests_a->get_credentials( $local_id ) ) . ' err=' . $wpdb->last_error );
	ovb_check( 'repo destinations: by_type and all', in_array( $did, array_column( $dests_a->by_type( 's3' ), 'id' ), true ) && array() === $dests_a->by_type( 'Bad Type' ) && count( $dests_a->all() ) >= 2 );

	$token = 'ya29.' . str_repeat( 'Ab1', 30 );
	ovb_check( 'repo destinations: mark_tested failure scrubs the error', $dests_a->mark_tested( $did, false, "HTTP 401 Authorization: Bearer {$token} AccessKey={$akid} " . str_repeat( 'x', 800 ) ) );
	$drow = $dests_a->find( $did );
	ovb_check( 'repo destinations: last_error is scrubbed and limited', 'error' === $drow['status'] && is_string( $drow['last_tested_at'] ) && strlen( $drow['last_error'] ) <= 500 && false === strpos( $drow['last_error'], $token ) && false === strpos( $drow['last_error'], 'Ab1Ab1Ab1' ) && false !== strpos( $drow['last_error'], '[redacted]' ), (string) $drow['last_error'] );
	$dests_a->mark_tested( $did, true );
	$drow = $dests_a->find( $did );
	ovb_check( 'repo destinations: mark_tested success clears the error', 'connected' === $drow['status'] && null === $drow['last_error'] );
	ovb_check( 'repo destinations: update_settings', $dests_a->update_settings( $did, array( 'bucket' => 'b2' ), 'Renamed' ) && array( 'bucket' => 'b2' ) === $dests_a->find( $did )['settings'] && 'Renamed' === $dests_a->find( $did )['label'] );

	// 10h. Changed encryption key: typed failure, needs_reconnect, no exception.
	$escaped = null;
	try {
		$bad = $dests_b->get_credentials( $did );
	} catch ( \Throwable $e ) {
		$escaped = get_class( $e );
		$bad     = array( 'ok' => true, 'data' => array(), 'reason' => null );
	}
	ovb_check( 'repo destinations: wrong key does not throw', null === $escaped, (string) $escaped );
	ovb_check( 'repo destinations: wrong key gives ok=false, reason wrong_key, no data', false === $bad['ok'] && 'wrong_key' === $bad['reason'] && array() === $bad['data'] );
	$drow = $dests_a->find( $did );
	ovb_check( 'repo destinations: wrong key sets needs_reconnect with a clear error', 'needs_reconnect' === $drow['status'] && OpenVault\Backup\Database\DestinationsRepository::RECONNECT_MESSAGE === $drow['last_error'] );
	ovb_check( 'repo destinations: wrong key leaves the stored envelope untouched', $raw_credentials === (string) $wpdb->get_var( $wpdb->prepare( "SELECT credentials FROM {$ovb_prefix}destinations WHERE id = %d", $did ) ) );
	$wpdb->update( $ovb_prefix . 'destinations', array( 'credentials' => 'garbage-not-an-envelope', 'status' => 'connected', 'last_error' => null ), array( 'id' => $did ) );
	$garbled = $dests_a->get_credentials( $did );
	ovb_check( 'repo destinations: corrupt envelope is invalid_format and needs_reconnect', false === $garbled['ok'] && 'invalid_format' === $garbled['reason'] && 'needs_reconnect' === $dests_a->find( $did )['status'] );
	ovb_check( 'repo destinations: replace_credentials recovers (status new, error cleared)', $dests_b->replace_credentials( $did, array( 'secret_access_key' => 'rotated-' . $ovb_marker ) ) && 'new' === $dests_a->find( $did )['status'] && null === $dests_a->find( $did )['last_error'] && array( 'secret_access_key' => 'rotated-' . $ovb_marker ) === $dests_b->get_credentials( $did )['data'] );
	ovb_check( 'repo destinations: delete', true === $dests_a->delete( $did ) && null === $dests_a->find( $did ) && false === $dests_a->delete( $did ) );

	// 10i. Schedules.
	$schedules = new OpenVault\Backup\Database\SchedulesRepository();
	$sid       = $schedules->create( array( 'label' => "Nightly {$ovb_marker}", 'recurrence' => 'weekly', 'start_time' => '2026-01-05 03:00:00', 'contents' => array( 'db' => true ), 'exclusions' => array( 'paths' => array( 'wp-content/cache' ) ), 'format' => 'zip-store', 'destination_ids' => array( 3, '4', 3 ), 'retention' => array( 'keep_last' => '5', 'evil' => 1 ), 'enabled' => true ) );
	$ovb_cleanup['schedules'][] = $sid;
	ovb_check( 'repo schedules: create returns an id', $sid > 0, $wpdb->last_error );
	$srow = $schedules->find( $sid );
	ovb_check( 'repo schedules: JSON columns round trip', is_array( $srow ) && 'weekly' === $srow['recurrence'] && 'zip-store' === $srow['format'] && array( 3, 4 ) === $srow['destination_ids'] && array( 'keep_last' => 5, 'keep_days' => 0 ) === $srow['retention'] && array( 'db' => true ) === $srow['contents'] && array( 'paths' => array( 'wp-content/cache' ) ) === $srow['exclusions'] && true === $srow['enabled'] && '2026-01-05 03:00:00' === $srow['start_time'] && null === $srow['next_run_at'] );
	ovb_check( 'repo schedules: not due without next_run_at', ! in_array( $sid, array_column( $schedules->due( time() ), 'id' ), true ) );
	$schedules->set_next_run( $sid, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
	ovb_check( 'repo schedules: due when next_run_at has passed', in_array( $sid, array_column( $schedules->due( time() ), 'id' ), true ) );
	$schedules->set_next_run( $sid, gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
	ovb_check( 'repo schedules: not due when next_run_at is in the future', ! in_array( $sid, array_column( $schedules->due( time() ), 'id' ), true ) && in_array( $sid, array_column( $schedules->due( time() + 7200 ), 'id' ), true ) );
	$schedules->set_next_run( $sid, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
	ovb_check( 'repo schedules: set_enabled false hides it from due()', $schedules->set_enabled( $sid, false ) && ! in_array( $sid, array_column( $schedules->due( time() ), 'id' ), true ) && false === $schedules->find( $sid )['enabled'] );
	$schedules->set_enabled( $sid, true );
	ovb_check( 'repo schedules: update validates and applies', $schedules->update( $sid, array( 'recurrence' => 'monthly', 'retention' => array( 'keep_days' => 30 ) ) ) && 'monthly' === $schedules->find( $sid )['recurrence'] && 30 === $schedules->find( $sid )['retention']['keep_days'] && 'Nightly ' . $ovb_marker === $schedules->find( $sid )['label'] );
	ovb_check( 'repo schedules: invalid input rejected and nothing changes', 'InvalidArgumentException' === ovb_throws( function () use ( $schedules, $sid ) { $schedules->update( $sid, array( 'recurrence' => 'yearly' ) ); } ) && 'InvalidArgumentException' === ovb_throws( function () use ( $schedules ) { $schedules->create( array( 'destination_ids' => array( 0 ) ) ); } ) && 'InvalidArgumentException' === ovb_throws( function () use ( $schedules ) { $schedules->create( array( 'retention' => array( 'keep_last' => -1 ) ) ); } ) && 'monthly' === $schedules->find( $sid )['recurrence'] );
	ovb_check( 'repo schedules: bad next run datetime rejected', 'InvalidArgumentException' === ovb_throws( function () use ( $schedules, $sid ) { $schedules->set_next_run( $sid, "2026-01-01 00:00:00'; --" ); } ) );
	$wpdb->update( $ovb_prefix . 'schedules', array( 'retention' => '{corrupt', 'destination_ids' => 'nope' ), array( 'id' => $sid ) );
	$srow = $schedules->find( $sid );
	ovb_check( 'repo schedules: corrupt JSON reads back as defaults', array( 'keep_last' => 0, 'keep_days' => 0 ) === $srow['retention'] && array() === $srow['destination_ids'] );
	ovb_check( 'repo schedules: all() lists it and delete removes it', in_array( $sid, array_column( $schedules->all(), 'id' ), true ) && true === $schedules->delete( $sid ) && null === $schedules->find( $sid ) );
	ovb_check( 'repo schedules: normalise is pure and strict', 'InvalidArgumentException' === ovb_throws( function () { OpenVault\Backup\Database\SchedulesRepository::normalise( array( 'format' => 'rar' ) ); } ) && 'daily' === OpenVault\Backup\Database\SchedulesRepository::normalise( array() )['recurrence'] );

	// 10j. Logs.
	$logs   = new OpenVault\Backup\Database\LogsRepository();
	$log_bk = 987654321;
	$logs->insert( 'info', "{$ovb_marker} first it's 100% fine", array( 'step' => 'one' ), $log_bk );
	$logs->insert( 'error', "{$ovb_marker} second", array( 'code' => 7 ), $log_bk );
	$logs->insert( 'warning', "{$ovb_marker} third", array(), null );
	$by_backup = $logs->paginate( array( 'backup_id' => $log_bk ) );
	ovb_check( 'repo logs: paginate by backup_id, newest first, context decoded', 2 === $by_backup['total'] && 2 === count( $by_backup['items'] ) && 'error' === $by_backup['items'][0]['level'] && array( 'step' => 'one' ) === $by_backup['items'][1]['context'] && $log_bk === $by_backup['items'][0]['backup_id'] );
	ovb_check( 'repo logs: filter by level', 1 === $logs->paginate( array( 'search' => $ovb_marker, 'level' => 'warning' ) )['total'] && null === $logs->paginate( array( 'search' => $ovb_marker, 'level' => 'warning' ) )['items'][0]['backup_id'] );
	ovb_check( 'repo logs: search treats % literally', 1 === $logs->paginate( array( 'search' => '100% fine' ) )['total'] && 3 === $logs->paginate( array( 'search' => $ovb_marker ) )['total'] );
	ovb_check( 'repo logs: hostile filters are inert', 0 === $logs->paginate( array( 'search' => "x' OR 1=1 -- " ) )['total'] && 3 === $logs->paginate( array( 'search' => $ovb_marker, 'level' => "error' OR '1'='1", 'order' => '; DROP' ) )['total'] && '' === $wpdb->last_error, $wpdb->last_error );
	ovb_check( 'repo logs: date range and pagination clamp', 3 === $logs->paginate( array( 'search' => $ovb_marker, 'date_from' => gmdate( 'Y-m-d', time() - 86400 ), 'date_to' => gmdate( 'Y-m-d', time() + 86400 ) ) )['total'] && 0 === $logs->paginate( array( 'search' => $ovb_marker, 'date_to' => '2001-01-01' ) )['total'] && 1 === count( $logs->paginate( array( 'search' => $ovb_marker, 'per_page' => 1, 'page' => 2 ) )['items'] ) && count( $logs->paginate( array( 'per_page' => 99999 ) )['items'] ) <= 200 );
	ovb_check( 'repo logs: count and delete_all', $logs->count() >= 3 && $logs->delete_all() >= 3 && 0 === $logs->count() );
} catch ( \Throwable $e ) {
	ovb_check( 'repositories: no uncaught exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	foreach ( $ovb_cleanup['backups'] as $cleanup_id ) {
		$wpdb->delete( $ovb_prefix . 'backup_files', array( 'backup_id' => $cleanup_id ) );
		$wpdb->delete( $ovb_prefix . 'backups', array( 'id' => $cleanup_id ) );
	}
	foreach ( $ovb_cleanup['destinations'] as $cleanup_id ) {
		$wpdb->delete( $ovb_prefix . 'destinations', array( 'id' => $cleanup_id ) );
	}
	foreach ( $ovb_cleanup['schedules'] as $cleanup_id ) {
		$wpdb->delete( $ovb_prefix . 'schedules', array( 'id' => $cleanup_id ) );
	}
}
$leftovers = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}backups WHERE note LIKE %s", $wpdb->esc_like( $ovb_marker ) . '%' ) )
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}schedules WHERE label LIKE %s", '%' . $wpdb->esc_like( $ovb_marker ) ) )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ovb_prefix}backup_files WHERE backup_id > 0 AND file_name LIKE 'site-part%'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ovb_prefix}destinations WHERE label LIKE 'Matrix%' OR label = 'Renamed'" );
ovb_check( 'repositories: test data cleaned up', 0 === $leftovers, "leftover rows: $leftovers" );

// 12. Engine core (Milestone 3, step 3a): JobRunner, budget, lock, resumption, preflight, cleanup. Everything created here is removed again.
use OpenVault\Backup\Archive\CapabilityDetector;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobLock;
use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\OptionsLockStore;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Engine\Steps\PreflightStep;
use OpenVault\Backup\Engine\Steps\StepInterface;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Manifest;
use OpenVault\Backup\Support\Paths;

if ( ! class_exists( 'OvbMatrixStep' ) ) {
	class OvbMatrixStep implements StepInterface {
		public $step_id;
		public $callback;
		public function __construct( $step_id, $callback ) {
			$this->step_id  = $step_id;
			$this->callback = $callback;
		}
		public function id() {
			return $this->step_id;
		}
		public function run( JobContext $context ) {
			return call_user_func( $this->callback, $context );
		}
	}
}

function ovb_lock_row( $backup_id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'ovb_lock_' . (int) $backup_id ) );
}

function ovb_clear_pending( $backup_id ) {
	as_unschedule_all_actions( JobRunner::ACTION, array( (int) $backup_id ), JobRunner::GROUP );
}

function ovb_pending_count( $hook, $args = null ) {
	$query = array( 'hook' => $hook, 'group' => JobRunner::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 20 );
	if ( null !== $args ) {
		$query['args'] = $args;
	}
	return count( as_get_scheduled_actions( $query, 'ids' ) );
}

$ovb_engine_ids   = array();
$ovb_engine_steps = null;
$ovb_engine_hook  = static function ( $steps ) use ( &$ovb_engine_steps ) {
	return null === $ovb_engine_steps ? $steps : $ovb_engine_steps;
};
add_filter( 'ovb_engine_steps', $ovb_engine_hook );
$ovb_saved_settings = get_option( 'ovb_settings' );

try {
	$ebackups = new BackupsRepository();
	$new_job  = static function ( $label ) use ( $ebackups, $ovb_marker, &$ovb_engine_ids ) {
		$id = $ebackups->create( array( 'note' => $ovb_marker . ' engine ' . $label, 'contents' => array( 'db' => true ), 'format' => 'zip' ) );
		$ovb_engine_ids[] = $id;
		return $id;
	};
	$step_index = static function ( $step ) {
		$pos = array_search( $step, array_merge( JobRunner::STEP_ORDER, array( 'done', 'failed' ) ), true );
		return false === $pos ? -1 : $pos;
	};

	ovb_check( 'engine: step order and hook names', array( 'preflight', 'db_export', 'file_scan', 'archive', 'encrypt', 'upload', 'retention', 'cleanup' ) === JobRunner::STEP_ORDER && 'ovb_run_job' === JobRunner::ACTION && 'openvault-backup' === JobRunner::GROUP );
	ovb_check( 'engine: job hooks are registered', false !== has_action( 'ovb_run_job' ) && false !== has_action( 'ovb_recover_stalled' ) );
	ovb_check( 'engine: next_step_id walks the order and ends at done', 'db_export' === JobRunner::next_step_id( 'preflight' ) && 'done' === JobRunner::next_step_id( 'cleanup' ) && 'done' === JobRunner::next_step_id( 'bogus' ) );

	// 12a. Budget on the real clock and memory.
	$real_budget = Budget::from_environment();
	ovb_check( 'engine: budget from the real environment is sane', $real_budget->seconds() >= 5 && $real_budget->seconds() <= 25 && $real_budget->memory_ceiling() > 0 && ! $real_budget->exhausted() );

	// 12b. End-to-end job over several runs with an injected tiny budget.
	$fake_time = 1000.0;
	$tiny      = static function () use ( &$fake_time ) {
		return new Budget(
			5,
			PHP_INT_MAX,
			static function () use ( &$fake_time ) {
				return $fake_time;
			}
		);
	};
	$runner = new JobRunner( null, null, $tiny );

	$ovb_engine_steps = array(
		'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) {
			return StepResult::next_step( $c->state() );
		} ),
		'db_export' => new OvbMatrixStep( 'db_export', static function ( JobContext $c ) use ( &$fake_time ) {
			$n = isset( $c->state()->get_cursor()['n'] ) ? (int) $c->state()->get_cursor()['n'] : 0;
			++$n;
			$fake_time += 100; // Use up the whole budget.
			$state = $c->state()->with_cursor( array( 'n' => $n ) );
			return $n >= 3 ? StepResult::next_step( $state ) : StepResult::continue_step( $state );
		} ),
		'file_scan' => new OvbMatrixStep( 'file_scan', static function ( JobContext $c ) {
			return StepResult::next_step( $c->state() );
		} ),
	);

	$job = $new_job( 'e2e' );
	ovb_check( 'engine: start queues the first slice', $runner->start( $job ) && 'running' === $ebackups->find( $job )['status'] && 'preflight' === $ebackups->load_state( $job )->get_step() && $runner->has_pending_action( $job ) );

	$outcomes     = array();
	$history      = array();
	$released     = true;
	$queued_after = true;
	for ( $i = 0; $i < 10; $i++ ) {
		ovb_clear_pending( $job );
		$outcome    = $runner->run( $job );
		$outcomes[] = $outcome;
		$state      = $ebackups->load_state( $job );
		$history[]  = array( $state->get_step(), isset( $state->get_cursor()['n'] ) ? (int) $state->get_cursor()['n'] : 0 );
		$released   = $released && null === ovb_lock_row( $job );
		if ( 'continued' !== $outcome ) {
			break;
		}
		$queued_after = $queued_after && $runner->has_pending_action( $job ) && as_has_scheduled_action( JobRunner::ACTION, array( $job ), JobRunner::GROUP );
	}
	$monotonic = true;
	for ( $i = 1; $i < count( $history ); $i++ ) {
		$before = $step_index( $history[ $i - 1 ][0] );
		$after  = $step_index( $history[ $i ][0] );
		if ( $after < $before || ( $after === $before && $history[ $i ][1] < $history[ $i - 1 ][1] ) ) {
			$monotonic = false;
		}
	}
	$final = $ebackups->find( $job );
	ovb_check( 'engine: job spreads over several runs', count( array_keys( $outcomes, 'continued', true ) ) >= 2 && 'complete' === end( $outcomes ), wp_json_encode( $outcomes ) );
	ovb_check( 'engine: cursor advances monotonically', $monotonic && $history[0][1] >= 1, wp_json_encode( $history ) );
	ovb_check( 'engine: a continuation action is pending after every budget stop', $queued_after && in_array( 'continued', $outcomes, true ) );
	ovb_check( 'engine: final status complete with finished_at and state done', 'complete' === $final['status'] && is_string( $final['finished_at'] ) && 'done' === $ebackups->load_state( $job )->get_step(), wp_json_encode( $final ) );
	ovb_check( 'engine: lock released after every run', $released && null === ovb_lock_row( $job ) );
	ovb_check( 'engine: a finished job ignores further triggers', 'finished' === $runner->run( $job ) );
	ovb_check( 'engine: a missing backup is ignored quietly', 'missing' === $runner->run( 2147483000 ) );

	// 12c. Lock contention.
	$ovb_engine_steps = array(
		'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) {
			return StepResult::next_step( $c->state() );
		} ),
	);
	$plain = new JobRunner();
	$job   = $new_job( 'contention' );
	$plain->start( $job );
	$before = $ebackups->load_state( $job )->to_json();
	$holder = new JobLock( $job );
	ovb_check( 'engine lock: first caller acquires', $holder->acquire( 120 ) && null !== ovb_lock_row( $job ) );
	ovb_check( 'engine lock: second caller is refused', false === ( new JobLock( $job ) )->acquire( 120 ) );
	ovb_check( 'engine lock: run() with the lock held does nothing', 'busy' === $plain->run( $job ) && $before === $ebackups->load_state( $job )->to_json() && 'running' === $ebackups->find( $job )['status'] );
	$plain->handle( $job );
	ovb_check( 'engine lock: handle() leaves the holder\'s lock and the state alone', $before === $ebackups->load_state( $job )->to_json() && null !== ovb_lock_row( $job ) );
	ovb_check( 'engine lock: refresh keeps ownership', $holder->refresh() && $holder->is_held() );
	ovb_check( 'engine lock: release removes the row', $holder->release() && null === ovb_lock_row( $job ) );
	ovb_check( 'engine lock: runs normally once the lock is free', 'complete' === $plain->run( $job ) && 'complete' === $ebackups->find( $job )['status'] );

	// 12d. Stale lock takeover (real conditional DELETE and INSERT IGNORE).
	$job = $new_job( 'stale' );
	$plain->start( $job );
	$wpdb->insert( $wpdb->options, array( 'option_name' => 'ovb_lock_' . $job, 'option_value' => ( time() - 1000 ) . ':0123456789abcdef', 'autoload' => 'no' ) );
	$taker_a = new JobLock( $job );
	$taker_b = new JobLock( $job );
	$won_a   = $taker_a->acquire( 120 );
	$won_b   = $taker_b->acquire( 120 );
	ovb_check( 'engine lock: exactly one caller takes over a stale lock', true === $won_a && false === $won_b && $taker_a->is_held() && ! $taker_b->is_held() );
	ovb_check( 'engine lock: the new owner holds a fresh timestamp', abs( JobLock::parse( ovb_lock_row( $job ) )['time'] - time() ) < 10 );
	ovb_check( 'engine lock: a fresh lock is not stolen', false === ( new JobLock( $job ) )->acquire( 120 ) );
	$taker_a->release();
	$wpdb->insert( $wpdb->options, array( 'option_name' => 'ovb_lock_' . $job, 'option_value' => 'garbage', 'autoload' => 'no' ) );
	ovb_check( 'engine lock: a corrupt lock value counts as stale and the job runs', 'complete' === $plain->run( $job ) && null === ovb_lock_row( $job ) );
	$old_owner = new JobLock( $job );
	$old_owner->acquire( 120 );
	$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 1000 ) . ':0123456789abcdef' ), array( 'option_name' => 'ovb_lock_' . $job ) );
	$new_owner = new JobLock( $job );
	ovb_check( 'engine lock: takeover after the old owner died; the old owner cannot interfere', $new_owner->acquire( 120 ) && false === $old_owner->refresh() && false === $old_owner->release() && $new_owner->is_held() && null !== ovb_lock_row( $job ) );
	$new_owner->release();
	$wpdb->insert( $wpdb->options, array( 'option_name' => 'ovb_lock_999999991', 'option_value' => '1:0123456789abcdef', 'autoload' => 'no' ) );
	ovb_check( 'engine lock: clear_all removes every job lock', ( new OptionsLockStore() )->clear_all() >= 1 && null === ovb_lock_row( 999999991 ) );

	// 12e. Failure handling: transient errors are retried, permanent ones fail without leaking secrets.
	$calls  = 0;
	$secret = 'ya29.' . str_repeat( 'Zq9', 20 );
	$ovb_engine_steps = array(
		'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) use ( &$calls, $secret ) {
			++$calls;
			if ( $calls < 3 ) {
				throw new \RuntimeException( "upload failed: Authorization: Bearer {$secret} secret_access_key=AKIAIOSFODNN7EXAMPLE1" );
			}
			return StepResult::next_step( $c->state() );
		} ),
	);
	$job = $new_job( 'flaky' );
	$plain->start( $job );
	ovb_clear_pending( $job );
	$r1 = $plain->run( $job );
	$a1 = $ebackups->load_state( $job )->get_attempts();
	$p1 = $plain->has_pending_action( $job );
	$r2 = $plain->run( $job );
	$a2 = $ebackups->load_state( $job )->get_attempts();
	$r3 = $plain->run( $job );
	ovb_check( 'engine retry: a step that throws twice is retried with backoff', 'retry' === $r1 && 'retry' === $r2 && 1 === $a1 && 2 === $a2 && $p1, "$r1/$r2 attempts $a1/$a2 pending " . var_export( $p1, true ) );
	ovb_check( 'engine retry: third attempt succeeds, backup complete, attempts reset', 'complete' === $r3 && 'complete' === $ebackups->find( $job )['status'] && 3 === $calls );
	$retry_logs = $wpdb->get_results( $wpdb->prepare( "SELECT context FROM {$ovb_prefix}logs WHERE backup_id = %d AND message = %s ORDER BY id", $job, 'A step failed and will be retried.' ), ARRAY_A );
	ovb_check( 'engine retry: both failed attempts are logged with attempt numbers', 2 === count( $retry_logs ) && 1 === (int) json_decode( $retry_logs[0]['context'], true )['attempt'] && 2 === (int) json_decode( $retry_logs[1]['context'], true )['attempt'] );
	$leaked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}logs WHERE backup_id = %d AND ( message LIKE %s OR context LIKE %s OR context LIKE %s )", $job, '%Zq9Zq9Zq9%', '%Zq9Zq9Zq9%', '%AKIAIOSFODNN7EXAMPLE1%' ) );
	ovb_check( 'engine retry: logs hold no token or access key', 0 === $leaked );
	ovb_clear_pending( $job );

	$calls = 0;
	$ovb_engine_steps = array(
		'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) use ( &$calls, $secret ) {
			++$calls;
			throw new \RuntimeException( "always broken: Authorization: Bearer {$secret} password=hunter2hunter2" );
		} ),
	);
	$job = $new_job( 'doomed' );
	$plain->start( $job );
	$outs = array( $plain->run( $job ), $plain->run( $job ), $plain->run( $job ) );
	$row  = $ebackups->find( $job );
	$st   = $ebackups->load_state( $job );
	ovb_check( 'engine retry: a step that always throws fails after 3 attempts', array( 'retry', 'retry', 'failed' ) === $outs && 3 === $calls && 'failed' === $row['status'] && 'failed' === $st->get_step() && is_string( $row['finished_at'] ), wp_json_encode( $outs ) );
	ovb_check( 'engine retry: the error message is stored', is_string( $st->get_error() ) && false !== strpos( $st->get_error(), '3 attempts' ) && false !== strpos( $st->get_error(), 'preflight' ), (string) $st->get_error() );
	$leaked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}logs WHERE backup_id = %d AND ( message LIKE %s OR context LIKE %s OR context LIKE %s )", $job, '%Zq9Zq9Zq9%', '%Zq9Zq9Zq9%', '%hunter2hunter2%' ) );
	ovb_check( 'engine retry: stored error and logs hold no secrets', 0 === $leaked && false === strpos( (string) $st->get_error(), 'Zq9Zq9Zq9' ) && false === strpos( (string) $st->get_error(), 'hunter2' ) );
	ovb_check( 'engine retry: nothing is left pending or locked for a failed job', ! $plain->has_pending_action( $job ) && null === ovb_lock_row( $job ) );

	$ovb_engine_steps = array(
		'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) {
			return StepResult::fail( 'Not enough free disk space: 40 MB free, 100 MB needed.', $c->state() );
		} ),
	);
	$job = $new_job( 'permanent' );
	$plain->start( $job );
	$out = $plain->run( $job );
	ovb_check( 'engine retry: StepResult::fail() fails at once without retries', 'failed' === $out && 'failed' === $ebackups->find( $job )['status'] && 'Not enough free disk space: 40 MB free, 100 MB needed.' === $ebackups->load_state( $job )->get_error() );

	$ovb_engine_steps = array(
		'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) {
			return StepResult::continue_step( $c->state() ); // Never moves the cursor.
		} ),
	);
	$job = $new_job( 'noprogress' );
	$plain->start( $job );
	$out = $plain->run( $job );
	ovb_check( 'engine retry: a step that never makes progress is failed, not looped forever', 'failed' === $out && false !== strpos( (string) $ebackups->load_state( $job )->get_error(), 'not making progress' ), (string) $ebackups->load_state( $job )->get_error() );

	$ovb_engine_steps = array(
		'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) {
			return StepResult::wait( 90, $c->state() );
		} ),
	);
	$job = $new_job( 'wait' );
	$plain->start( $job );
	ovb_clear_pending( $job );
	$out  = $plain->run( $job );
	$next = as_next_scheduled_action( JobRunner::ACTION, array( $job ), JobRunner::GROUP );
	ovb_check( 'engine retry: a wait result reschedules the job later', 'waiting' === $out && is_int( $next ) && $next >= time() + 60 && 'running' === $ebackups->find( $job )['status'] && null === ovb_lock_row( $job ), var_export( $next, true ) );
	ovb_clear_pending( $job );

	// A corrupt state fails the job cleanly.
	$ovb_engine_steps = null;
	$job = $new_job( 'corrupt' );
	$wpdb->update( $ovb_prefix . 'backups', array( 'state' => '{corrupt', 'status' => 'running' ), array( 'id' => $job ) );
	ovb_check( 'engine: a corrupt state fails the backup with a clear message', 'failed' === $plain->run( $job ) && 'failed' === $ebackups->find( $job )['status'] && false !== strpos( (string) $ebackups->load_state( $job )->get_error(), 'state' ) && null === ovb_lock_row( $job ) );

	// 12f. Stalled-job recovery.
	$stalled = $new_job( 'stalled' );
	$ebackups->update_status( $stalled, 'running' );
	$ebackups->save_state( $stalled, JobState::new_state( 'db_export', time() - 3600 ) );
	$spent = $new_job( 'spent' );
	$ebackups->update_status( $spent, 'running' );
	$ebackups->save_state( $spent, JobState::new_state( 'db_export', time() - 3600 )->increment_attempts( time() - 3600 )->increment_attempts( time() - 3600 )->increment_attempts( time() - 3600 ) );
	$healthy = $new_job( 'healthy' );
	$ebackups->update_status( $healthy, 'running' );
	$ebackups->save_state( $healthy, JobState::new_state( 'db_export' ) );
	$locked = $new_job( 'locked' );
	$ebackups->update_status( $locked, 'running' );
	$ebackups->save_state( $locked, JobState::new_state( 'db_export', time() - 3600 ) );
	$locked_lock = new JobLock( $locked );
	$locked_lock->acquire( 120 );
	$stateless = $new_job( 'stateless' ); // Still "queued" without state: not started yet, must be left alone.
	foreach ( array( $stalled, $spent, $healthy, $locked, $stateless ) as $clear_id ) {
		ovb_clear_pending( $clear_id );
	}

	$recovered = $plain->recover_stalled( time(), 600 );
	$touched   = array_merge( $recovered['requeued'], $recovered['failed'] );
	ovb_check( 'engine recover: a stalled job is re-enqueued with attempts + 1', in_array( $stalled, $recovered['requeued'], true ) && 1 === $ebackups->load_state( $stalled )->get_attempts() && $plain->has_pending_action( $stalled ) && 'running' === $ebackups->find( $stalled )['status'], wp_json_encode( $recovered ) );
	ovb_check( 'engine recover: a job past the maximum attempts is failed', in_array( $spent, $recovered['failed'], true ) && 'failed' === $ebackups->find( $spent )['status'] && 'failed' === $ebackups->load_state( $spent )->get_step() && ! $plain->has_pending_action( $spent ) );
	ovb_check( 'engine recover: healthy, locked and not-yet-started jobs are left alone', ! in_array( $healthy, $touched, true ) && ! in_array( $locked, $touched, true ) && ! in_array( $stateless, $touched, true ) && 'running' === $ebackups->find( $healthy )['status'] && 'queued' === $ebackups->find( $stateless )['status'] );
	$again = $plain->recover_stalled( time(), 600 );
	ovb_check( 'engine recover: a second scan does not queue a duplicate action', 1 === ovb_pending_count( JobRunner::ACTION, array( $stalled ) ) && 1 === $ebackups->load_state( $stalled )->get_attempts() && ! in_array( $stalled, $again['requeued'], true ) );
	$locked_lock->release();
	ovb_clear_pending( $stalled );

	$plain->schedule_recovery( true );
	$plain->schedule_recovery( true );
	ovb_check( 'engine recover: the recurring scan exists exactly once', 1 === ovb_pending_count( JobRunner::RECOVER_ACTION ), 'count ' . ovb_pending_count( JobRunner::RECOVER_ACTION ) );

	// 12g. Preflight and cleanup against the real environment.
	$ovb_engine_steps = null;
	$format           = ( new CapabilityDetector() )->default_format();
	$pf_job           = $new_job( 'preflight' );
	$pf_row           = $ebackups->find( $pf_job );
	$pf_row['format'] = null === $format ? 'zip' : $format;
	$work_dir         = Paths::work_dir( $pf_row['uuid'] );
	$mk_ctx           = static function ( $row, $dir, $settings = array() ) {
		return new JobContext( $row, JobState::new_state( 'preflight' ), Budget::from_environment(), new Logger(), $dir, $settings );
	};
	$result = ( new PreflightStep() )->run( $mk_ctx( $pf_row, $work_dir ) );
	ovb_check( 'engine preflight: passes in the real environment', $result->is_next() && is_dir( $work_dir ), $result->message() );
	ovb_check( 'engine preflight: work folder is <local dir>/tmp/<uuid>', $work_dir === WP_CONTENT_DIR . '/' . get_option( 'ovb_local_dir' ) . '/tmp/' . $pf_row['uuid'] );
	ovb_check( 'engine preflight: local and tmp folders are protected', file_exists( Paths::local_dir() . '/.htaccess' ) && file_exists( Paths::tmp_dir() . '/index.php' ) && file_exists( Paths::tmp_dir() . '/.htaccess' ) );
	ovb_check( 'engine preflight: write probe is cleaned up', ! file_exists( $work_dir . '/.ovb-write-test' ) );

	if ( function_exists( 'disk_free_space' ) && false !== @disk_free_space( WP_CONTENT_DIR ) ) {
		$huge = ( new PreflightStep() )->run( $mk_ctx( $pf_row, $work_dir, array( 'min_free_space_mb' => 999999999 ) ) );
		ovb_check( 'engine preflight: not enough disk space fails with an actionable message', $huge->is_fail() && false !== strpos( $huge->message(), 'Not enough free disk space' ), $huge->message() );
	} else {
		ovb_check( 'engine preflight: unknown disk space only warns', ( new PreflightStep() )->run( $mk_ctx( $pf_row, $work_dir, array( 'min_free_space_mb' => 999999999 ) ) )->is_next() );
	}
	$bz_row           = $pf_row;
	$bz_row['format'] = 'tar.bz2';
	$bz               = ( new PreflightStep() )->run( $mk_ctx( $bz_row, $work_dir ) );
	ovb_check( 'engine preflight: format availability follows CapabilityDetector', ( new CapabilityDetector() )->is_available( 'tar.bz2' ) ? $bz->is_next() : ( $bz->is_fail() && false !== strpos( $bz->message(), 'tar.bz2' ) ), $bz->message() );
	$empty_row             = $pf_row;
	$empty_row['contents'] = array( 'db' => false );
	ovb_check( 'engine preflight: an empty contents selection fails', ( new PreflightStep() )->run( $mk_ctx( $empty_row, $work_dir ) )->is_fail() );

	// Cleanup removes the work folder and only that.
	mkdir( $work_dir . '/sub/deeper', 0777, true );
	file_put_contents( $work_dir . '/sub/deeper/list.txt', 'x' );
	file_put_contents( $work_dir . '/db.sql', 'x' );
	$keep = Paths::local_dir() . '/ovbmatrix-keep-' . $ovb_marker . '.zip';
	file_put_contents( $keep, 'a finished archive' );
	$cleanup = ( new CleanupStep() )->run( $mk_ctx( $pf_row, $work_dir ) );
	ovb_check( 'engine cleanup: removes the work folder recursively', $cleanup->is_next() && ! file_exists( $work_dir ), $work_dir );
	ovb_check( 'engine cleanup: finished archives are untouched', file_exists( $keep ) && 'a finished archive' === file_get_contents( $keep ) );
	unlink( $keep );
	ovb_check( 'engine cleanup: a missing folder is fine', ( new CleanupStep() )->run( $mk_ctx( $pf_row, $work_dir ) )->is_next() );

	$outside = Paths::local_dir() . '/ovbmatrix-notmp-' . $ovb_marker;
	mkdir( $outside );
	file_put_contents( $outside . '/precious.txt', 'keep' );
	$refused = array(
		'folder outside tmp'    => $outside,
		'the tmp folder itself' => Paths::tmp_dir(),
		'traversal out of tmp'  => Paths::tmp_dir() . '/..',
		'the local folder'      => Paths::local_dir(),
		'wp-content'            => WP_CONTENT_DIR,
	);
	$all_refused = true;
	foreach ( $refused as $label => $path ) {
		( new CleanupStep() )->run( $mk_ctx( $pf_row, $path ) );
		if ( ! is_dir( $path ) ) {
			$all_refused = false;
			ovb_check( "engine cleanup: refused $label", false, $path );
		}
	}
	ovb_check( 'engine cleanup: refuses anything that is not a work folder inside tmp', $all_refused && 'keep' === file_get_contents( $outside . '/precious.txt' ) && file_exists( Paths::local_dir() . '/index.php' ) );
	if ( function_exists( 'symlink' ) ) {
		$target = Paths::local_dir() . '/ovbmatrix-target-' . $ovb_marker;
		mkdir( $target );
		file_put_contents( $target . '/precious.txt', 'keep' );
		$linked = Paths::tmp_dir() . '/' . Manifest::generate_uuid();
		if ( @symlink( $target, $linked ) ) {
			( new CleanupStep() )->run( $mk_ctx( $pf_row, $linked ) );
			ovb_check( 'engine cleanup: a symlinked work folder is refused and its target survives', is_link( $linked ) && 'keep' === file_get_contents( $target . '/precious.txt' ) );
			unlink( $linked );
		}
		unlink( $target . '/precious.txt' );
		rmdir( $target );
	}
	unlink( $outside . '/precious.txt' );
	rmdir( $outside );

	// 12h. Default step map: preflight, db_export, file_scan, archive and cleanup are real, the others are skipped for now; the job completes.
	$default_map = $plain->steps();
	ovb_check( 'engine: default step map holds the implemented steps', isset( $default_map['preflight'], $default_map['db_export'], $default_map['file_scan'], $default_map['archive'], $default_map['cleanup'] ) && $default_map['preflight'] instanceof PreflightStep && $default_map['db_export'] instanceof \OpenVault\Backup\Engine\Steps\DatabaseExportStep && $default_map['file_scan'] instanceof \OpenVault\Backup\Engine\Steps\FileScanStep && $default_map['archive'] instanceof \OpenVault\Backup\Engine\Steps\ArchiveStep && $default_map['cleanup'] instanceof CleanupStep && 5 === count( $default_map ) );
	$job = $new_job( 'default' );
	$ebackups->save_state( $job, JobState::new_state( 'preflight' ) );
	$ebackups->update_status( $job, 'running' );
	$default_row = $ebackups->find( $job );
	$uuid_dir    = Paths::work_dir( $default_row['uuid'] );
	$default_out = 'continued';
	for ( $i = 0; $i < 30 && 'continued' === $default_out; $i++ ) {
		ovb_clear_pending( $job );
		$default_out = $plain->run( $job );
	}
	ovb_check( 'engine: default job (real preflight, database export, file scan, archive, skipped steps, real cleanup) completes', 'complete' === $default_out && 'complete' === $ebackups->find( $job )['status'] && ! file_exists( $uuid_dir ), $default_out . ' ' . $ebackups->find( $job )['status'] );
	$default_folder = Paths::backup_dir( $default_row['created_at'], $default_row['uuid'] );
	ovb_check( 'engine: the default job leaves its backup folder with a manifest and a db part (cleanup removes the work folder only)', is_file( $default_folder . '/manifest.json' ) && is_file( $default_folder . '/db.part01.zip' ) && $ebackups->find( $job )['total_size'] > 0 );
	CleanupStep::remove_tree( $default_folder );
	$drop_hook = static function () {
		return array( 'preflight' => 'not a step', 'bogus' => new OvbMatrixStep( 'bogus', static function () {} ) );
	};
	add_filter( 'ovb_engine_steps', $drop_hook, 99 );
	ovb_check( 'engine: the step filter drops invalid entries', array() === $plain->steps() );
	remove_filter( 'ovb_engine_steps', $drop_hook, 99 );

	// 12i. Cancel.
	$job = $new_job( 'cancel' );
	$plain->start( $job );
	Paths::ensure_work_dir( $ebackups->find( $job )['uuid'] );
	$cancel_dir = Paths::work_dir( $ebackups->find( $job )['uuid'] );
	file_put_contents( $cancel_dir . '/partial.sql', 'x' );
	ovb_check( 'engine cancel: an action and work files exist before cancelling', $plain->has_pending_action( $job ) && is_dir( $cancel_dir ) );
	ovb_check( 'engine cancel: cancel() succeeds', true === $plain->cancel( $job ) );
	$cancelled = $ebackups->find( $job );
	ovb_check( 'engine cancel: status failed with "Cancelled by user"', 'failed' === $cancelled['status'] && 'Cancelled by user' === $ebackups->load_state( $job )->get_error() && is_string( $cancelled['finished_at'] ) );
	ovb_check( 'engine cancel: pending actions are removed and work files deleted', ! $plain->has_pending_action( $job ) && ! file_exists( $cancel_dir ) );
	ovb_check( 'engine cancel: a cancelled job does not run again', 'finished' === $plain->run( $job ) && 'failed' === $ebackups->find( $job )['status'] );
	ovb_check( 'engine cancel: cancelling twice or a missing backup is false', false === $plain->cancel( $job ) && false === $plain->cancel( 2147483000 ) );

	// Cancelling while a run is active: the run notices and does not overwrite the failed state.
	$job = $new_job( 'cancel-live' );
	$plain->start( $job );
	$ovb_engine_steps = array(
		'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) use ( $plain, $job ) {
			$plain->cancel( $job ); // Another process cancels while this step is working.
			return StepResult::next_step( $c->state() );
		} ),
		'db_export' => new OvbMatrixStep( 'db_export', static function ( JobContext $c ) {
			throw new \RuntimeException( 'must never run after a cancel' );
		} ),
	);
	$live = $plain->run( $job );
	ovb_check( 'engine cancel: a running job stops and keeps the cancelled state', 'aborted' === $live && 'failed' === $ebackups->find( $job )['status'] && 'Cancelled by user' === $ebackups->load_state( $job )->get_error() && null === ovb_lock_row( $job ), $live );
	$ovb_engine_steps = null;

	// System Info shows the detector's answer.
	$flat_info = OpenVault\Backup\Support\SystemInfo::as_text( OpenVault\Backup\Support\SystemInfo::collect() );
	$det       = new CapabilityDetector();
	ovb_check( 'engine: System Info formats come from CapabilityDetector', false !== strpos( $flat_info, 'ZIP (deflate, store): ' . ( $det->is_available( 'zip' ) ? 'Supported' : 'Not supported' ) ) && false !== strpos( $flat_info, 'TAR.GZ: ' . ( $det->is_available( 'tar.gz' ) ? 'Supported' : 'Not supported' ) ) );
} catch ( \Throwable $e ) {
	ovb_check( 'engine: no uncaught exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	remove_filter( 'ovb_engine_steps', $ovb_engine_hook );
	update_option( 'ovb_settings', $ovb_saved_settings );
	foreach ( $ovb_engine_ids as $cleanup_id ) {
		ovb_clear_pending( $cleanup_id );
		$wpdb->delete( $ovb_prefix . 'logs', array( 'backup_id' => $cleanup_id ) );
		$wpdb->delete( $ovb_prefix . 'backups', array( 'id' => $cleanup_id ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'ovb_lock_' . $cleanup_id ) );
	}
	foreach ( glob( Paths::tmp_dir() . '/*', GLOB_ONLYDIR ) ?: array() as $leftover_dir ) {
		if ( Manifest::is_valid_uuid( basename( $leftover_dir ) ) ) {
			CleanupStep::remove_tree( $leftover_dir );
		}
	}
}
$engine_leftovers = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}backups WHERE note LIKE %s", $wpdb->esc_like( $ovb_marker ) . '%' ) )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'ovb\\_lock\\_%'" );
ovb_check( 'engine: test data, locks and work folders cleaned up', 0 === $engine_leftovers && 0 === count( glob( Paths::tmp_dir() . '/*', GLOB_ONLYDIR ) ?: array() ), "leftovers: $engine_leftovers" );

// 13. Database export (Milestone 3, step 3b): a real dump of hostile data, resumed over many slices, then imported into a
// second database and compared with the source. Everything created here is removed again.
use OpenVault\Backup\Engine\Dump\BatchSizer;
use OpenVault\Backup\Engine\Dump\DumpException;
use OpenVault\Backup\Engine\Dump\TableInspector;
use OpenVault\Backup\Engine\Steps\DatabaseExportStep;

if ( ! class_exists( 'OvbCountingStep' ) ) {
	class OvbCountingStep implements StepInterface {
		public $inner;
		public $log = array();
		public function __construct( StepInterface $inner ) {
			$this->inner = $inner;
		}
		public function id() {
			return $this->inner->id();
		}
		public function run( JobContext $context ) {
			$result      = $this->inner->run( $context );
			$this->log[] = array( 'kind' => $result->kind(), 'cursor' => $result->state()->get_cursor(), 'message' => $result->message() );
			return $result;
		}
	}
	class OvbGhostInspector extends TableInspector {
		public function list_tables() {
			$tables   = parent::list_tables();
			$tables[] = array( $GLOBALS['wpdb']->prefix . 'ovbtest_ghost', 'BASE TABLE' );
			return $tables;
		}
	}
	class OvbFailingInspector extends TableInspector {
		public function describe( $table ) {
			throw new DumpException( 'Table "' . $table . '" could not be read: simulated failure' );
		}
	}
}

function ovb_open_mysqli( $db, $database ) {
	$parsed = $db->parse_db_host( DB_HOST );
	if ( ! $parsed ) {
		return null;
	}
	mysqli_report( MYSQLI_REPORT_OFF );
	$m = mysqli_init();
	if ( ! @$m->real_connect( $parsed[0], DB_USER, DB_PASSWORD, $database, $parsed[1] ? (int) $parsed[1] : 3306, $parsed[2] ? $parsed[2] : null ) ) {
		return null;
	}
	$m->set_charset( 'utf8mb4' );
	return $m;
}

function ovb_x( $s ) {
	return '' === $s ? "''" : "X'" . bin2hex( $s ) . "'";
}

function ovb_q( $name ) {
	return '`' . str_replace( '`', '``', $name ) . '`';
}

function ovb_exec( $m, $sql, &$errors ) {
	if ( ! $m->query( $sql ) ) {
		$errors[] = $m->error . ' :: ' . substr( $sql, 0, 120 );
		return false;
	}
	return true;
}

function ovb_table_digest( $m, $schema, $table ) {
	$res = $m->query( 'SELECT * FROM ' . ovb_q( $schema ) . '.' . ovb_q( $table ), MYSQLI_USE_RESULT );
	if ( ! $res ) {
		return 'error: ' . $m->error;
	}
	$count = 0;
	$crc   = 0;
	$md5   = 0;
	while ( $row = $res->fetch_row() ) {
		++$count;
		$flat = '';
		foreach ( $row as $value ) {
			$flat .= null === $value ? 'N;' : strlen( $value ) . ':' . $value . ';';
		}
		$crc += crc32( $flat );
		$md5 += hexdec( substr( md5( $flat ), 0, 11 ) );
	}
	$res->free();
	return $count . '/' . $crc . '/' . $md5;
}

// Rows of the copy that equal the source row, compared by primary key and NULL-safe on every column.
function ovb_equal_rows( $m, $source_schema, $copy_schema, $table, $pk ) {
	$cols = array();
	$res  = $m->query( 'SHOW COLUMNS FROM ' . ovb_q( $source_schema ) . '.' . ovb_q( $table ) );
	while ( $row = $res->fetch_assoc() ) {
		$cols[ $row['Field'] ] = 'a.' . ovb_q( $row['Field'] ) . ' <=> b.' . ovb_q( $row['Field'] );
	}
	$res->free();
	$sql = 'SELECT COUNT(*) FROM ' . ovb_q( $source_schema ) . '.' . ovb_q( $table ) . ' a JOIN ' . ovb_q( $copy_schema ) . '.' . ovb_q( $table ) . ' b ON a.' . ovb_q( $pk ) . ' = b.' . ovb_q( $pk ) . ' AND ' . implode( ' AND ', $cols );
	$res = $m->query( $sql );
	if ( ! $res ) {
		return 'error: ' . $m->error;
	}
	$row = $res->fetch_row();
	$res->free();
	if ( (int) $row[0] === (int) $m->query( 'SELECT COUNT(*) FROM ' . ovb_q( $source_schema ) . '.' . ovb_q( $table ) )->fetch_row()[0] ) {
		return (int) $row[0];
	}
	// Mismatch: say which columns differ (row count of differing values per column, joined on the key).
	$diff = array();
	foreach ( $cols as $name => $cond ) {
		$r = $m->query( 'SELECT COUNT(*) FROM ' . ovb_q( $source_schema ) . '.' . ovb_q( $table ) . ' a JOIN ' . ovb_q( $copy_schema ) . '.' . ovb_q( $table ) . ' b ON a.' . ovb_q( $pk ) . ' = b.' . ovb_q( $pk ) . ' WHERE NOT (' . $cond . ')' );
		$n = $r ? (int) $r->fetch_row()[0] : -1;
		if ( $n ) {
			$ex   = $m->query( 'SELECT HEX(LEFT(a.' . ovb_q( $name ) . ', 24)), HEX(LEFT(b.' . ovb_q( $name ) . ', 24)) FROM ' . ovb_q( $source_schema ) . '.' . ovb_q( $table ) . ' a JOIN ' . ovb_q( $copy_schema ) . '.' . ovb_q( $table ) . ' b ON a.' . ovb_q( $pk ) . ' = b.' . ovb_q( $pk ) . ' WHERE NOT (' . $cond . ') LIMIT 1' );
			$ex = $ex ? $ex->fetch_row() : array( $m->error, '' );
			$diff[] = $name . "=$n " . $ex[0] . ' vs ' . $ex[1];
		}
	}
	return (int) $row[0] . ' (differing: ' . implode( ',', $diff ) . ')';
}

function ovb_checksum( $m, $schema, $table ) {
	$res = $m->query( 'CHECKSUM TABLE ' . ovb_q( $schema ) . '.' . ovb_q( $table ) );
	if ( ! $res ) {
		return 'error: ' . $m->error;
	}
	$row = $res->fetch_row();
	$res->free();
	return (string) $row[1];
}

function ovb_scan_dump( $path, $options_table, $markers ) {
	$scan = array(
		'facts' => array(), 'tables' => array(), 'inserts' => array(), 'drops' => 0, 'comments' => 0, 'statements' => 0, 'unterminated' => 0,
		'end' => false, 'garbage' => false, 'max_insert' => 0, 'comment_after_statement' => false, 'markers' => array_fill_keys( $markers, false ), 'first_statements' => array(),
	);
	$h = fopen( $path, 'rb' );
	while ( false !== ( $line = fgets( $h ) ) ) {
		if ( "\n" !== substr( $line, -1 ) ) {
			++$scan['unterminated'];
		}
		$text = rtrim( $line, "\n" );
		if ( false !== strpos( $text, 'GARBAGEBYTES' ) ) {
			$scan['garbage'] = true;
		}
		if ( '-- ovb-dump-end' === $text ) {
			$scan['end'] = true;
			continue;
		}
		if ( 0 === strpos( $text, '-- ' ) ) {
			++$scan['comments'];
			if ( $scan['statements'] > 0 ) {
				$scan['comment_after_statement'] = true;
			}
			if ( preg_match( '/^-- ([a-z0-9_-]+): (.*)$/', $text, $f ) ) {
				$scan['facts'][ $f[1] ] = $f[2];
			}
			continue;
		}
		if ( '' === $text ) {
			continue;
		}
		++$scan['statements'];
		if ( count( $scan['first_statements'] ) < 6 ) {
			$scan['first_statements'][] = $text;
		}
		if ( ';' !== substr( $text, -1 ) ) {
			++$scan['unterminated'];
		}
		if ( preg_match( '/^CREATE TABLE `((?:[^`]|``)+)`/', $text, $c ) ) {
			$scan['tables'][] = str_replace( '``', '`', $c[1] );
		} elseif ( 0 === strpos( $text, 'DROP TABLE IF EXISTS ' ) ) {
			++$scan['drops'];
		} elseif ( preg_match( '/^INSERT INTO `((?:[^`]|``)+)`/', $text, $c ) ) {
			$name                     = str_replace( '``', '`', $c[1] );
			$scan['inserts'][ $name ] = ( isset( $scan['inserts'][ $name ] ) ? $scan['inserts'][ $name ] : 0 ) + 1;
			if ( ! isset( $scan['samples'][ $name ] ) ) {
				$scan['samples'][ $name ] = substr( $text, -140 );
			}
			$scan['max_insert']       = max( $scan['max_insert'], strlen( $text ) );
			if ( $name === $options_table ) {
				foreach ( $markers as $marker ) {
					if ( false !== strpos( $text, "'" . $marker . "'" ) ) {
						$scan['markers'][ $marker ] = true;
					}
				}
			}
		}
	}
	fclose( $h );
	return $scan;
}

$ovb_dump_ids      = array();
$ovb_dump_steps    = null;
$ovb_dump_hook     = static function ( $steps ) use ( &$ovb_dump_steps ) {
	return null === $ovb_dump_steps ? $steps : $ovb_dump_steps;
};
$ovb_dump_filter   = static function ( $exclusions ) use ( $wpdb ) {
	$exclusions['tables'][] = $wpdb->prefix . 'ovbtest_excl_*';
	return $exclusions;
};
$ovb_dump_settings = get_option( 'ovb_settings' );
$ovb_dump_p        = $wpdb->prefix . 'ovbtest_';
$ovb_dump_t        = array(
	'text' => $ovb_dump_p . 'text', 'blob' => $ovb_dump_p . 'blob', 'num' => $ovb_dump_p . 'num', 'nopk' => $ovb_dump_p . 'nopk', 'comp' => $ovb_dump_p . 'comp',
	'empty' => $ovb_dump_p . 'empty', 'big' => $ovb_dump_p . 'big', 'uniq' => $ovb_dump_p . 'uniq', 'gen' => $ovb_dump_p . 'gen', 'weird' => $ovb_dump_p . 'we`ird sp',
	'meta' => $ovb_dump_p . 'meta', 'keep' => $ovb_dump_p . 'keep', 'excl' => $ovb_dump_p . 'excl_a', 'skip' => $ovb_dump_p . 'skipme', 'foreign' => 'other_ovbtest_app',
	'view' => $ovb_dump_p . 'view',
);
$ovb_dump_opts     = array(
	'ovb_ovbtest_opt' => false, '_transient_ovbtest_t' => false, '_transient_timeout_ovbtest_t' => false, '_site_transient_ovbtest_s' => false,
	'ovbtest_keep_me' => true, '_transientx_keep' => true, 'xovb_keep' => true,
);
$ovb_dump_setup    = null;
$ovb_dump_verify   = null;
$ovb_dump_dbname   = 'ovb_dump_verify';

try {
	$dbk = new BackupsRepository();
	add_filter( 'ovb_engine_steps', $ovb_dump_hook );
	add_filter( 'ovb_backup_exclusions', $ovb_dump_filter );

	$ovb_dump_setup = ovb_open_mysqli( $wpdb, DB_NAME );
	ovb_check( 'db export: second connection for test data', null !== $ovb_dump_setup );
	if ( null === $ovb_dump_setup ) {
		throw new \RuntimeException( 'no second connection' );
	}
	$err = array();
	ovb_exec( $ovb_dump_setup, "SET sql_mode=''", $err );
	ovb_exec( $ovb_dump_setup, "SET time_zone = '+05:00'", $err );
	$T = $ovb_dump_t;

	// Scratch tables with hostile data.
	$json_type = 'JSON';
	$err_probe = array();
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['num'] . '_probe' ) . ' (j JSON NULL)', $err_probe );
	if ( ! empty( $err_probe ) ) {
		$json_type = 'LONGTEXT';
	}
	$cs = 'DEFAULT CHARSET=utf8mb4';
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['text'] ) . " (id INT NOT NULL AUTO_INCREMENT, label VARCHAR(100) NOT NULL DEFAULT '', body LONGTEXT NULL, note TEXT NULL, PRIMARY KEY (id)) ENGINE=InnoDB $cs", $err );
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['blob'] ) . ' (id INT NOT NULL, b BLOB NULL, vb VARBINARY(300) NULL, bn BINARY(16) NULL, bits BIT(8) NULL, PRIMARY KEY (id)) ENGINE=InnoDB', $err );
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['num'] ) . " (id BIGINT UNSIGNED NOT NULL, ti TINYINT NULL, si SMALLINT UNSIGNED NULL, bi BIGINT NULL, dec_v DECIMAL(30,10) NULL, fl FLOAT NULL, db_v DOUBLE NULL, en ENUM('a','b c','it''s') NULL, st SET('r','g b','it''s') NULL, dt DATETIME NULL, ts TIMESTAMP NULL DEFAULT NULL, d DATE NULL, t TIME NULL, y YEAR NULL, bits BIT(13) NULL, js $json_type NULL, PRIMARY KEY (id)) ENGINE=InnoDB $cs", $err );
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['nopk'] ) . " (a INT NULL, b VARCHAR(30) NULL) ENGINE=InnoDB $cs", $err );
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['comp'] ) . " (a INT NOT NULL, b INT NOT NULL, c VARCHAR(10) NULL, PRIMARY KEY (a, b)) ENGINE=InnoDB $cs", $err );
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['empty'] ) . " (id INT NOT NULL, x TEXT NULL, PRIMARY KEY (id)) ENGINE=InnoDB $cs", $err );
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['big'] ) . " (id INT NOT NULL AUTO_INCREMENT, v VARCHAR(40) NOT NULL, n INT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB $cs", $err );
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['uniq'] ) . " (u INT NOT NULL, w TEXT NULL, UNIQUE KEY uk (u)) ENGINE=InnoDB $cs", $err );
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['weird'] ) . " (id INT NOT NULL, v VARCHAR(20) NULL, PRIMARY KEY (id)) ENGINE=InnoDB $cs", $err );
	ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['meta'] ) . " (id INT NOT NULL, c VARCHAR(30) NOT NULL DEFAULT 'a\nb ''q''  spaced' COMMENT 'multi\nline ''x''   gap; -- not a comment', PRIMARY KEY (id)) ENGINE=InnoDB $cs COMMENT='table; comment\nline2 ''z'''", $err );
	foreach ( array( 'keep', 'excl', 'skip', 'foreign' ) as $k ) {
		ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T[ $k ] ) . ' (id INT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB', $err );
		ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T[ $k ] ) . ' VALUES (1),(2)', $err );
	}
	$gen_err = array();
	$gen_ok  = ovb_exec( $ovb_dump_setup, 'CREATE TABLE ' . ovb_q( $T['gen'] ) . ' (id INT NOT NULL, a INT NOT NULL, g INT AS (a * 2) VIRTUAL, PRIMARY KEY (id)) ENGINE=InnoDB', $gen_err );
	$view_err = array();
	$view_ok  = ovb_exec( $ovb_dump_setup, 'CREATE VIEW ' . ovb_q( $T['view'] ) . ' AS SELECT id FROM ' . ovb_q( $T['keep'] ), $view_err );

	// text table: unicode, quotes, backslashes, newlines, NUL, injection payloads, 300 KB.
	$unit      = "Lorem 'ipsum' \"dolor\" \\ sit\r\namet \0 ünï©ode 😀\n";
	$big_one   = substr( str_repeat( $unit, (int) ceil( 300000 / strlen( $unit ) ) ), 0, 300000 );
	$text_rows = array(
		array( "Ünïcödé 日本語 العربية 😀🎉 \u{1F9D1}\u{200D}\u{1F4BB}", "Ünïcödé 日本語 العربية 😀🎉", null ),
		array( "it's \"quoted\"", "it's \"quoted\" back\\slash \\' trailing\\", 'x' ),
		array( 'newlines', "line1\nline2\r\nline3\rline4\n", '' ),
		array( 'nul and ctrl', "a\0b\x1ac\x01\x1f\x7f", null ),
		array( 'injection', "'); DROP TABLE x; --\n/* */ ; DELETE FROM wp_options; -- ", "' OR '1'='1" ),
		array( '0x41', '0x414243', '0x' ),
		array( 'big one', $big_one, null ),
		array( '', '', null ),
		array( 'dump lookalike', "a;\n-- b\nINSERT INTO t VALUES (1);\n-- ovb-dump-end\n", null ),
		array( 'big two', $big_one, 'second 300 KB row' ),
		array( 'big three', strrev( $big_one ), null ),
		array( str_repeat( '😀', 25 ), 'last', null ),
	);
	foreach ( $text_rows as $r ) {
		ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['text'] ) . ' (label, body, note) VALUES (' . ovb_x( $r[0] ) . ', ' . ovb_x( $r[1] ) . ', ' . ( null === $r[2] ? 'NULL' : ovb_x( $r[2] ) ) . ')', $err );
	}

	// blob table.
	$bytes = '';
	for ( $i = 0; $i < 256; $i++ ) {
		$bytes .= chr( $i );
	}
	$random = '';
	for ( $i = 0; strlen( $random ) < 5000; $i++ ) {
		$random .= hash( 'sha256', 'ovb' . $i, true );
	}
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['blob'] ) . ' VALUES (1, ' . ovb_x( $bytes ) . ', ' . ovb_x( $bytes ) . ", X'00FF00000000000000000000000000AA', 255)", $err );
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['blob'] ) . ' VALUES (2, NULL, NULL, NULL, NULL)', $err );
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['blob'] ) . " VALUES (3, '', '', '', 0)", $err );
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['blob'] ) . ' VALUES (4, ' . ovb_x( substr( $random, 0, 5000 ) ) . ", X'27', X'5C27', 1)", $err );

	// numeric, temporal and JSON table.
	$json_value = 'CONVERT(' . ovb_x( '{"k":"it\'s \u2603","n":[1,2,{"x":null}]}'  ) . ' USING utf8mb4)';
	$num_rows   = array(
		'(1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL)',
		"(2, -128, 65535, -9223372036854775808, '12345678901234567890.1234567890', 3.14159274, 3.141592653589793, 'it''s', 'r,it''s', '9999-12-31 23:59:59', '2026-03-04 05:06:07', '1000-01-01', '-838:59:59', 2155, b'1010101010101', $json_value)",
		"(18446744073709551615, 127, 0, 9223372036854775807, '-0.0000000001', 1.17549435e-38, 1.7976931348623157e308, 'b c', 'g b', '1970-01-01 00:00:01', '2038-01-19 03:14:07', '2000-02-29', '838:59:59', 1901, b'0000000000001', NULL)",
		"(100, 1, 1, 1, '0.5', 16777217, 0.30000000000000004, '', '', '0000-00-00 00:00:00', '2026-06-01 12:00:00', '0000-00-00', '00:00:00', 0, b'1111111111111', '[]')",
		"(101, 1, 1, 1, '99999999999999999999.9999999999', 0.1, 2.2250738585072014e-308, 'a', 'r,g b', '2026-01-01 00:00:00', NULL, NULL, NULL, NULL, NULL, '{}')",
	);
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['num'] ) . ' VALUES ' . implode( ',', $num_rows ), $err );

	// No primary key (with exact duplicate rows), composite key, unique key, many rows, odd names.
	$rows = array();
	for ( $i = 0; $i < 700; $i++ ) {
		$rows[] = '(' . ( $i % 50 ) . ", 'dup " . ( $i % 7 ) . " it''s')";
	}
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['nopk'] ) . ' VALUES ' . implode( ',', $rows ) . ', (NULL, NULL), (NULL, NULL)', $err );
	$rows = array();
	for ( $i = 0; $i < 1500; $i++ ) {
		$rows[] = '(' . ( $i % 30 ) . ', ' . intdiv( $i, 30 ) . ", 'c" . $i . "')";
	}
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['comp'] ) . ' VALUES ' . implode( ',', $rows ), $err );
	$rows = array();
	for ( $i = 0; $i < 300; $i++ ) {
		$rows[] = '(' . ( $i * 3 - 100 ) . ", 'w$i')";
	}
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['uniq'] ) . ' VALUES ' . implode( ',', $rows ), $err );
	for ( $chunk = 0; $chunk < 12; $chunk++ ) {
		$rows = array();
		for ( $i = 1; $i <= 1000; $i++ ) {
			$n      = $chunk * 1000 + $i;
			$rows[] = "('row $n it''s', " . ( $n * 7 ) . ')';
		}
		ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['big'] ) . ' (v, n) VALUES ' . implode( ',', $rows ), $err );
	}
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['weird'] ) . " VALUES (1, 'a'), (2, 'b`c'), (3, NULL)", $err );
	ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['meta'] ) . ' (id) VALUES (1), (2)', $err );
	if ( $gen_ok ) {
		ovb_exec( $ovb_dump_setup, 'INSERT INTO ' . ovb_q( $T['gen'] ) . ' (id, a) VALUES (1, 5), (2, 7), (3, -1)', $err );
	}
	foreach ( array_keys( $ovb_dump_opts ) as $opt_name ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $opt_name ) );
		$wpdb->insert( $wpdb->options, array( 'option_name' => $opt_name, 'option_value' => 'v', 'autoload' => 'no' ) );
	}
	ovb_check( 'db export: scratch data created without errors', array() === $err, implode( ' | ', array_slice( $err, 0, 3 ) ) );

	// Strategy selection against real index metadata.
	$inspector = new TableInspector();
	$strategy  = static function ( $table ) use ( $inspector ) {
		$d = $inspector->describe( $table );
		return $d ? TableInspector::choose_strategy( $d['columns'], $d['indexes'] ) : null;
	};
	ovb_check( 'db export: strategy keyset for integer primary keys', array( 'type' => 'keyset', 'key' => 'id' ) === $strategy( $T['big'] ) && array( 'type' => 'keyset', 'key' => 'id' ) === $strategy( $T['num'] ) && array( 'type' => 'keyset', 'key' => 'id' ) === $strategy( $T['text'] ) );
	ovb_check( 'db export: strategy keyset for a unique not-null integer key without primary key', array( 'type' => 'keyset', 'key' => 'u' ) === $strategy( $T['uniq'] ), wp_json_encode( $strategy( $T['uniq'] ) ) );
	ovb_check( 'db export: strategy offset ordered by a composite key', array( 'type' => 'offset', 'order' => array( 'a', 'b' ) ) === $strategy( $T['comp'] ), wp_json_encode( $strategy( $T['comp'] ) ) );
	ovb_check( 'db export: strategy unordered offset for a table without any key', array( 'type' => 'offset', 'order' => array() ) === $strategy( $T['nopk'] ) );
	ovb_check( 'db export: structure of a vanished table reads as null, not an error', null === $inspector->describe( $T['text'] . '_nope' ) && null === $inspector->create_statement( $T['text'] . '_nope' ) );
	if ( $gen_ok ) {
		$gd = $inspector->describe( $T['gen'] );
		ovb_check( 'db export: generated column is detected', $gd && true === $gd['columns'][2]['generated'] && true === TableInspector::needs_column_list( $gd['columns'] ), wp_json_encode( $gd ) );
	}

	// Exclusion settings: a valid exact name, a bare "*", a regex, a SQL payload.
	$settings                    = is_array( $ovb_dump_settings ) ? $ovb_dump_settings : array();
	$settings['excluded_tables'] = array( $T['skip'], '*', 'wp_.+', "x'; DROP TABLE y; --" );
	update_option( 'ovb_settings', $settings );

	// 13a. Run the export through the JobRunner with a budget that allows one batch per slice.
	$clock   = 0.0;
	$mem_max = 0;
	$factory = static function () use ( &$clock, &$mem_max ) {
		return new Budget(
			1,
			PHP_INT_MAX,
			static function () use ( &$clock ) {
				$clock += 1.0;
				return $clock;
			},
			static function () use ( &$mem_max ) {
				$used    = memory_get_usage( true );
				$mem_max = max( $mem_max, $used );
				return $used;
			}
		);
	};
	$counting       = new OvbCountingStep( new DatabaseExportStep() );
	$ovb_dump_steps = array(
		'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) {
			return StepResult::next_step( $c->state() );
		} ),
		'db_export' => $counting,
	);
	$drunner        = new JobRunner( null, null, $factory );
	$job            = $dbk->create( array( 'note' => $ovb_marker . ' dump', 'contents' => array( 'db' => true ), 'format' => 'zip' ) );
	$ovb_dump_ids[] = $job;
	$drunner->start( $job );
	$wpdb->query( "SET time_zone = '-07:00'" );
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	$mem_before = memory_get_usage( true );
	$started    = microtime( true );

	$dump_path   = Paths::work_dir( $dbk->find( $job )['uuid'] ) . '/db.sql';
	$outcomes    = array();
	$points      = array();
	$monotonic   = true;
	$replayed    = false;
	$crashed     = 0;
	$last_point  = null;
	$saved_state = null;
	for ( $i = 0; $i < 600; $i++ ) {
		ovb_clear_pending( $job );
		$out        = $drunner->run( $job );
		$outcomes[] = $out;
		$state      = $dbk->load_state( $job );
		$cursor     = $state->get_cursor();
		$point      = isset( $cursor['file_offset'] ) ? array( (int) $cursor['tables_done'], (int) $cursor['rows_done'], (int) $cursor['file_offset'] ) : null;
		if ( null !== $point && null !== $last_point && 'db_export' === $state->get_step() ) {
			if ( $point[0] < $last_point[0] || $point[1] < $last_point[1] || $point[2] < $last_point[2] || $point === $last_point ) {
				$monotonic = false;
			}
		}
		if ( null !== $point ) {
			$points[]   = $point;
			$last_point = $point;
		}
		if ( 'continued' !== $out ) {
			break;
		}
		if ( 6 === $i || 30 === $i ) {
			// A run killed after it wrote more of the file but before its cursor was saved: garbage past the saved offset.
			file_put_contents( $dump_path, "GARBAGEBYTES\n" . str_repeat( 'x', 1000 ) . "\nINSERT INTO `half` VALUES (1,'ab", FILE_APPEND );
			++$crashed;
		}
		if ( 12 === $i ) {
			$saved_state = $dbk->load_state( $job );
		}
		if ( 15 === $i && null !== $saved_state ) {
			// Two slices ran and their cursors were lost: the older cursor comes back, the file has more bytes than it says.
			$size_before = filesize( $dump_path );
			$dbk->save_state( $job, $saved_state );
			$last_point = null;
			$replayed   = $size_before > (int) $saved_state->get_cursor()['file_offset'];
		}
	}
	$elapsed    = microtime( true ) - $started;
	$final      = $dbk->find( $job );
	$final_st   = $dbk->load_state( $job );
	$meta       = $final_st->get_meta();
	$continues  = 0;
	$strategies = array();
	$batches    = array();
	foreach ( $counting->log as $entry ) {
		if ( 'continue' === $entry['kind'] ) {
			++$continues;
		}
		if ( ! empty( $entry['cursor']['table'] ) && isset( $entry['cursor']['strategy'] ) && is_array( $entry['cursor']['strategy'] ) ) {
			$strategies[ $entry['cursor']['table'] ] = $entry['cursor']['strategy'];
			$batches[ $entry['cursor']['table'] ][]  = (int) $entry['cursor']['batch'];
		}
	}

	ovb_check( 'db export: finished through the runner', 'complete' === end( $outcomes ) && 'complete' === $final['status'] && 'done' === $final_st->get_step(), wp_json_encode( array_count_values( $outcomes ) ) . ' ' . (string) $final_st->get_error() );
	ovb_check( 'db export: resumed over many slices (at least 5 continue results)', $continues >= 5 && count( array_keys( $outcomes, 'continued', true ) ) >= 5, "continues $continues, runs " . count( $outcomes ) );
	ovb_check( 'db export: cursor advances monotonically (tables, rows and file offset never go back, every slice moves the cursor)', $monotonic && count( $points ) >= 5, count( $points ) . ' points' );
	ovb_check( 'db export: crash simulation 1 and 2: garbage after the saved offset was written', 2 === $crashed );
	ovb_check( 'db export: crash simulation 3: an older cursor replayed over a longer file', $replayed );
	ovb_check( 'db export: result meta carries file, size, sha256, tables and rows', isset( $meta['db'] ) && 'db.sql' === $meta['db']['file'] && is_file( $dump_path ) && filesize( $dump_path ) === $meta['db']['size'] && hash_file( 'sha256', $dump_path ) === $meta['db']['sha256'] && $meta['db']['tables'] >= 14 && $meta['db']['rows'] >= 12000, wp_json_encode( isset( $meta['db'] ) ? $meta['db'] : null ) );
	ovb_check( 'db export: session time zone was restored after the export', '-07:00' === $wpdb->get_var( 'SELECT @@session.time_zone' ), (string) $wpdb->get_var( 'SELECT @@session.time_zone' ) );
	$wpdb->query( "SET time_zone = 'SYSTEM'" );

	ovb_check( 'db export: strategies recorded in the cursor match the metadata', isset( $strategies[ $T['big'] ], $strategies[ $T['comp'] ], $strategies[ $T['nopk'] ], $strategies[ $T['uniq'] ] ) && 'keyset' === $strategies[ $T['big'] ]['type'] && 'u' === $strategies[ $T['uniq'] ]['key'] && array( 'a', 'b' ) === $strategies[ $T['comp'] ]['order'] && array() === $strategies[ $T['nopk'] ]['order'] );
	ovb_check( 'db export: batch size shrinks for 300 KB rows and grows to 2000 for tiny rows', isset( $batches[ $T['text'] ], $batches[ $T['big'] ] ) && min( $batches[ $T['text'] ] ) <= 5 && 2000 === max( $batches[ $T['big'] ] ), 'text ' . wp_json_encode( isset( $batches[ $T['text'] ] ) ? $batches[ $T['text'] ] : null ) . ' big ' . wp_json_encode( isset( $batches[ $T['big'] ] ) ? array_slice( $batches[ $T['big'] ], 0, 5 ) : null ) );

	// 13b. The file itself.
	$markers = array_keys( $ovb_dump_opts );
	$scan    = ovb_scan_dump( $dump_path, $wpdb->options, $markers );
	ovb_check( 'db export: header facts exist and are right', '1' === ( $scan['facts']['ovb-dump'] ?? '' ) && $wpdb->base_prefix === ( $scan['facts']['table-prefix'] ?? '' ) && 'utf8mb4' === ( $scan['facts']['charset'] ?? '' ) && OVB_VERSION === ( $scan['facts']['plugin-version'] ?? '' ) && get_bloginfo( 'version' ) === ( $scan['facts']['wp-version'] ?? '' ) && get_option( 'siteurl' ) === ( $scan['facts']['site-url'] ?? '' ) && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $scan['facts']['created-at'] ?? '' ) && (string) count( $scan['tables'] ) === ( $scan['facts']['tables'] ?? '' ), wp_json_encode( $scan['facts'] ) );
	ovb_check( 'db export: every non-comment line is one statement ending with ";" and the file ends with the end marker', 0 === $scan['unterminated'] && $scan['end'] && ! $scan['comment_after_statement'], 'unterminated ' . $scan['unterminated'] );
	ovb_check( 'db export: prologue statements', array_slice( $scan['first_statements'], 0, 5 ) === array( 'SET NAMES utf8mb4;', 'SET FOREIGN_KEY_CHECKS=0;', 'SET UNIQUE_CHECKS=0;', "SET sql_mode='NO_AUTO_VALUE_ON_ZERO';", "SET time_zone='+00:00';" ), wp_json_encode( $scan['first_statements'] ) );
	ovb_check( 'db export: crash leftovers are gone (no garbage in the final file)', ! $scan['garbage'] );
	ovb_check( 'db export: one DROP and one CREATE per table, tables in name order', $scan['drops'] === count( $scan['tables'] ) && $scan['tables'] === array_values( array_unique( $scan['tables'] ) ) && ( function ( $names ) { $sorted = $names; usort( $sorted, 'strcmp' ); return $sorted === $names; } )( $scan['tables'] ) );
	ovb_check( 'db export: INSERT statements stay near the size cap (one row at least)', $scan['max_insert'] <= 524288 + 700000, 'max ' . $scan['max_insert'] );
	$ovb_in_dump = static function ( $table ) use ( $scan ) {
		return in_array( $table, $scan['tables'], true );
	};
	ovb_check( 'db export: plugin tables are not exported', array() === array_filter( $scan['tables'], static function ( $n ) use ( $wpdb ) { return 0 === strpos( $n, $wpdb->prefix . 'ovb_' ); } ) && ! isset( $scan['inserts'][ $wpdb->prefix . 'ovb_backups' ] ) );
	ovb_check( 'db export: scratch tables are exported; views, other applications\' tables and excluded tables are not', $ovb_in_dump( $T['text'] ) && $ovb_in_dump( $T['blob'] ) && $ovb_in_dump( $T['num'] ) && $ovb_in_dump( $T['nopk'] ) && $ovb_in_dump( $T['comp'] ) && $ovb_in_dump( $T['empty'] ) && $ovb_in_dump( $T['big'] ) && $ovb_in_dump( $T['uniq'] ) && $ovb_in_dump( $T['weird'] ) && $ovb_in_dump( $T['meta'] ) && $ovb_in_dump( $T['keep'] ) && ! $ovb_in_dump( $T['view'] ) && ! $ovb_in_dump( $T['foreign'] ) && ! $ovb_in_dump( $T['excl'] ) && ! $ovb_in_dump( $T['skip'] ), wp_json_encode( array_filter( $scan['tables'], static function ( $n ) { return false !== strpos( $n, 'ovbtest' ); } ) ) );
	ovb_check( 'db export: a bare "*", a regex and a SQL payload in the settings exclude nothing else', $ovb_in_dump( $wpdb->prefix . 'posts' ) && $ovb_in_dump( $wpdb->prefix . 'users' ) && $ovb_in_dump( $wpdb->options ) && $ovb_in_dump( $wpdb->prefix . 'usermeta' ) );
	ovb_check( 'db export: options of the plugin and transients are excluded, look-alikes are kept', array( 'ovb_ovbtest_opt' => false, '_transient_ovbtest_t' => false, '_transient_timeout_ovbtest_t' => false, '_site_transient_ovbtest_s' => false, 'ovbtest_keep_me' => true, '_transientx_keep' => true, 'xovb_keep' => true ) === $scan['markers'], wp_json_encode( $scan['markers'] ) );
	ovb_check( 'db export: the empty table has a CREATE and no INSERT', $ovb_in_dump( $T['empty'] ) && ! isset( $scan['inserts'][ $T['empty'] ] ) );
	$gen_line = false;
	if ( $gen_ok ) {
		$h = fopen( $dump_path, 'rb' );
		while ( false !== ( $line = fgets( $h ) ) ) {
			if ( 0 === strpos( $line, 'INSERT INTO ' . ovb_q( $T['gen'] ) . ' (`id`,`a`) VALUES ' ) ) {
				$gen_line = true;
				break;
			}
		}
		fclose( $h );
	}
	ovb_check( 'db export: a generated column is skipped and the INSERT has an explicit column list', ! $gen_ok || $gen_line );

	// 13c. Import the dump into a second database and compare.
	$ovb_dump_verify = ovb_open_mysqli( $wpdb, null );
	$can_create      = false;
	$create_errors   = array();
	if ( null !== $ovb_dump_verify ) {
		ovb_exec( $ovb_dump_verify, 'DROP DATABASE IF EXISTS ' . ovb_q( $ovb_dump_dbname ), $create_errors );
		$can_create = ovb_exec( $ovb_dump_verify, 'CREATE DATABASE ' . ovb_q( $ovb_dump_dbname ) . ' CHARACTER SET utf8mb4', $create_errors ) && $ovb_dump_verify->select_db( $ovb_dump_dbname );
	}
	if ( ! $can_create ) {
		ovb_check( 'db export: import verification SKIPPED (CREATE DATABASE not permitted for ' . DB_USER . ')', true, implode( ' | ', $create_errors ) );
	} else {
		$stats = array( 'statements' => 0, 'errors' => array(), 'bad_lines' => 0 );
		$h     = fopen( $dump_path, 'rb' );
		while ( false !== ( $line = fgets( $h ) ) ) {
			$text = rtrim( $line, "\n" );
			if ( '' === $text || 0 === strpos( $text, '-- ' ) ) {
				continue;
			}
			if ( ';' !== substr( $text, -1 ) || "\n" !== substr( $line, -1 ) ) {
				++$stats['bad_lines'];
				continue;
			}
			++$stats['statements'];
			if ( ! $ovb_dump_verify->query( substr( $text, 0, -1 ) ) ) {
				$stats['errors'][] = $ovb_dump_verify->error . ' :: ' . substr( $text, 0, 100 );
				if ( count( $stats['errors'] ) >= 5 ) {
					break;
				}
			}
		}
		fclose( $h );
		ovb_check( 'db export: dump imports statement by statement into a fresh database without errors', array() === $stats['errors'] && 0 === $stats['bad_lines'] && $stats['statements'] > 100, wp_json_encode( $stats ) );

		$src = DB_NAME;
		$dst = $ovb_dump_dbname;
		foreach ( array( 'text', 'blob', 'num', 'nopk', 'comp', 'empty', 'big', 'uniq', 'weird', 'meta', 'keep' ) as $k ) {
			$a = ovb_table_digest( $ovb_dump_verify, $src, $T[ $k ] );
			$b = ovb_table_digest( $ovb_dump_verify, $dst, $T[ $k ] );
			ovb_check( "db export: round trip rows identical ($k)", $a === $b && 0 !== strpos( $a, 'error' ), "$a vs $b " . ( $a === $b ? '' : (string) ( isset( $scan['samples'][ $T[ $k ] ] ) ? $scan['samples'][ $T[ $k ] ] : '' ) ) );
		}
		if ( $gen_ok ) {
			$a = ovb_table_digest( $ovb_dump_verify, $src, $T['gen'] );
			$b = ovb_table_digest( $ovb_dump_verify, $dst, $T['gen'] );
			ovb_check( 'db export: round trip rows identical (generated column)', $a === $b && 0 !== strpos( $a, 'error' ), "$a vs $b" );
		}
		foreach ( array( 'users', 'terms', 'term_taxonomy' ) as $core ) {
			$a = ovb_table_digest( $ovb_dump_verify, $src, $wpdb->prefix . $core );
			$b = ovb_table_digest( $ovb_dump_verify, $dst, $wpdb->prefix . $core );
			ovb_check( "db export: round trip rows identical (WordPress table $core)", $a === $b && 0 !== strpos( $a, 'error' ), "$a vs $b" );
		}
		foreach ( array( 'text' => 'id', 'blob' => 'id', 'num' => 'id', 'comp' => 'a', 'big' => 'id', 'weird' => 'id', 'uniq' => 'u' ) as $k => $pk ) {
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ovb_q( $T[ $k ] ) );
			$equal = ovb_equal_rows( $ovb_dump_verify, $src, $dst, $T[ $k ], $pk );
			ovb_check( "db export: every row equal NULL-safe on every column, floats, doubles and TIMESTAMPs exact ($k)", $count > 0 && $equal === $count, "rows $count equal " . var_export( $equal, true ) );
		}
		foreach ( array( 'text', 'blob', 'nopk', 'comp', 'empty', 'big', 'uniq', 'weird', 'keep' ) as $k ) {
			$a = ovb_checksum( $ovb_dump_verify, $src, $T[ $k ] );
			$b = ovb_checksum( $ovb_dump_verify, $dst, $T[ $k ] );
			ovb_check( "db export: CHECKSUM TABLE equal ($k)", $a === $b && 0 !== strpos( $a, 'error' ), "$a vs $b" );
		}

		// Definitions survive: defaults and comments with newlines, quotes and spaces.
		$def   = static function ( $schema ) use ( $ovb_dump_verify, $T ) {
			$res = $ovb_dump_verify->query( 'SELECT COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = \'' . $ovb_dump_verify->real_escape_string( $schema ) . '\' AND TABLE_NAME = \'' . $ovb_dump_verify->real_escape_string( $T['meta'] ) . '\' AND COLUMN_NAME = \'c\'' );
			$row = $res->fetch_row();
			$res->free();
			$res2 = $ovb_dump_verify->query( 'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = \'' . $ovb_dump_verify->real_escape_string( $schema ) . '\' AND TABLE_NAME = \'' . $ovb_dump_verify->real_escape_string( $T['meta'] ) . '\'' );
			$tc = $res2->fetch_row();
			$res2->free();
			return array( $row, $tc );
		};
		$d_src = $def( $src );
		$d_dst = $def( $dst );
		ovb_check( 'db export: defaults and comments containing newlines, quotes and spaces survive the one-line CREATE TABLE', $d_src === $d_dst && false !== strpos( (string) $d_src[0][0], 'spaced' ) && false !== strpos( (string) $d_src[0][1], "\n" ), wp_json_encode( array( $d_src, $d_dst ) ) );

		$res   = $ovb_dump_verify->query( 'SELECT option_name FROM ' . ovb_q( $dst ) . '.' . ovb_q( $wpdb->options ) . " WHERE option_name IN ('" . implode( "','", array_keys( $ovb_dump_opts ) ) . "') ORDER BY option_name" );
		$names = array();
		while ( $row = $res->fetch_row() ) {
			$names[] = $row[0];
		}
		$res->free();
		ovb_check( 'db export: imported options table has the kept option rows and none of the excluded ones', array( '_transientx_keep', 'ovbtest_keep_me', 'xovb_keep' ) === $names, wp_json_encode( $names ) );
		$res = $ovb_dump_verify->query( 'SELECT COUNT(*) FROM ' . ovb_q( $src ) . '.' . ovb_q( $wpdb->options ) . " WHERE LEFT(option_name, 4) <> 'ovb_' AND LEFT(option_name, 11) <> '_transient_' AND LEFT(option_name, 16) <> '_site_transient_'" );
		$exp = (int) $res->fetch_row()[0];
		$res->free();
		$res = $ovb_dump_verify->query( 'SELECT COUNT(*) FROM ' . ovb_q( $dst ) . '.' . ovb_q( $wpdb->options ) );
		$got = (int) $res->fetch_row()[0];
		$res->free();
		ovb_check( 'db export: imported options table has exactly the non-excluded rows', $exp === $got && $got > 10, "$exp vs $got" );
		$res    = $ovb_dump_verify->query( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = \'' . $dst . '\' AND TABLE_NAME LIKE \'%ovb\\_%\'' );
		$leaked = (int) $res->fetch_row()[0];
		$res->free();
		$res   = $ovb_dump_verify->query( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = \'' . $dst . '\'' );
		$total = (int) $res->fetch_row()[0];
		$res->free();
		ovb_check( 'db export: no plugin table in the imported database; the copy has exactly the dumped tables', 0 === $leaked && $total === count( $scan['tables'] ), "leaked $leaked, tables $total vs " . count( $scan['tables'] ) );
	}

	// 13d. Memory: the 12,000-row table and the 300 KB rows must not need much memory.
	$limit = Budget::parse_bytes( ini_get( 'memory_limit' ) );
	$peak  = max( $mem_max, function_exists( 'memory_reset_peak_usage' ) ? memory_get_peak_usage( true ) : 0 );
	ovb_check(
		sprintf( 'db export memory: %.1f MB at the heaviest batch (%.1f MB before the export), %d slices, %.1f s, limit %s', $peak / 1048576, $mem_before / 1048576, $continues, $elapsed, ini_get( 'memory_limit' ) ),
		$limit <= 0 || $peak < 0.85 * $limit
	);

	// 13e. contents['db'] = false skips the step; vanished tables are skipped; failures are clean.
	$mk_ctx         = static function ( $row, $dir ) {
		return new JobContext( $row, JobState::new_state( 'db_export' ), new Budget( 600, PHP_INT_MAX ), new Logger(), $dir, array() );
	};
	$job_skip       = $dbk->create( array( 'note' => $ovb_marker . ' dump skip', 'contents' => array( 'db' => false, 'uploads' => true ), 'format' => 'zip' ) );
	$ovb_dump_ids[] = $job_skip;
	$skip_row       = $dbk->find( $job_skip );
	$skip_dir       = Paths::work_dir( $skip_row['uuid'] );
	wp_mkdir_p( $skip_dir );
	$skip_res = ( new DatabaseExportStep() )->run( $mk_ctx( $skip_row, $skip_dir ) );
	ovb_check( 'db export: contents db=false skips the step without writing anything', $skip_res->is_next() && ! file_exists( $skip_dir . '/db.sql' ) && ! isset( $skip_res->state()->get_meta()['db'] ) );

	$job_ghost      = $dbk->create( array( 'note' => $ovb_marker . ' dump ghost', 'contents' => array( 'db' => true ), 'format' => 'zip' ) );
	$ovb_dump_ids[] = $job_ghost;
	$ghost_row      = $dbk->find( $job_ghost );
	$ghost_dir      = Paths::work_dir( $ghost_row['uuid'] );
	wp_mkdir_p( $ghost_dir );
	$ghost_res  = ( new DatabaseExportStep( new OvbGhostInspector() ) )->run( $mk_ctx( $ghost_row, $ghost_dir ) );
	$ghost_scan = $ghost_res->is_next() ? ovb_scan_dump( $ghost_dir . '/db.sql', $wpdb->options, array() ) : array( 'tables' => array( 'x' ), 'end' => false );
	$ghost_log  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}logs WHERE backup_id = %d AND message = %s", $job_ghost, 'A table was removed while the backup was running and is skipped.' ) );
	ovb_check( 'db export: a table that vanished while the export ran is skipped with a warning', $ghost_res->is_next() && ! in_array( $wpdb->prefix . 'ovbtest_ghost', $ghost_scan['tables'], true ) && $ghost_scan['end'] && 1 === $ghost_log, $ghost_res->message() . ' log ' . $ghost_log );

	$job_fail       = $dbk->create( array( 'note' => $ovb_marker . ' dump fail', 'contents' => array( 'db' => true ), 'format' => 'zip' ) );
	$ovb_dump_ids[] = $job_fail;
	$fail_row       = $dbk->find( $job_fail );
	$fail_dir       = Paths::work_dir( $fail_row['uuid'] );
	wp_mkdir_p( $fail_dir );
	$fail_res = ( new DatabaseExportStep( new OvbFailingInspector() ) )->run( $mk_ctx( $fail_row, $fail_dir ) );
	ovb_check( 'db export: a database error fails the step with a clear message', $fail_res->is_fail() && 0 === strpos( $fail_res->message(), 'The database export failed: ' ) && false !== strpos( $fail_res->message(), 'simulated failure' ), $fail_res->message() );
	ovb_check( 'db export: session settings are restored after a failed run too', 'SYSTEM' === $wpdb->get_var( 'SELECT @@session.time_zone' ), (string) $wpdb->get_var( 'SELECT @@session.time_zone' ) );

	$logged_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}logs WHERE backup_id = %d AND message = %s", $job, 'Table exported.' ) );
	$leaky_logs  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}logs WHERE backup_id = %d AND ( message LIKE %s OR context LIKE %s OR context LIKE %s )", $job, '%Lorem%', '%Lorem%', '%DROP TABLE%' ) );
	ovb_check( 'db export: progress is logged per table (name and row count) and no row data reaches the log', $logged_rows >= 14 && 0 === $leaky_logs, "logged $logged_rows leaky $leaky_logs" );
} catch ( \Throwable $e ) {
	ovb_check( 'db export: no uncaught exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	remove_filter( 'ovb_engine_steps', $ovb_dump_hook );
	remove_filter( 'ovb_backup_exclusions', $ovb_dump_filter );
	update_option( 'ovb_settings', $ovb_dump_settings );
	$wpdb->query( "SET time_zone = 'SYSTEM'" );
	if ( $ovb_dump_verify ) {
		$ovb_dump_verify->query( 'DROP DATABASE IF EXISTS ' . ovb_q( $ovb_dump_dbname ) );
		$ovb_dump_verify->close();
	}
	if ( $ovb_dump_setup ) {
		$ovb_dump_setup->query( 'DROP VIEW IF EXISTS ' . ovb_q( $ovb_dump_t['view'] ) );
		foreach ( $ovb_dump_t as $drop_table ) {
			$ovb_dump_setup->query( 'DROP TABLE IF EXISTS ' . ovb_q( $drop_table ) );
		}
		$ovb_dump_setup->query( 'DROP TABLE IF EXISTS ' . ovb_q( $ovb_dump_t['num'] . '_probe' ) );
		$ovb_dump_setup->close();
	}
	foreach ( array_keys( $ovb_dump_opts ) as $opt_name ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $opt_name ) );
	}
	foreach ( $ovb_dump_ids as $cleanup_id ) {
		ovb_clear_pending( $cleanup_id );
		$wpdb->delete( $ovb_prefix . 'logs', array( 'backup_id' => $cleanup_id ) );
		$wpdb->delete( $ovb_prefix . 'backups', array( 'id' => $cleanup_id ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'ovb_lock_' . $cleanup_id ) );
	}
	foreach ( glob( Paths::tmp_dir() . '/*', GLOB_ONLYDIR ) ?: array() as $leftover_dir ) {
		if ( Manifest::is_valid_uuid( basename( $leftover_dir ) ) ) {
			CleanupStep::remove_tree( $leftover_dir );
		}
	}
}
$dump_leftovers = count( (array) $wpdb->get_col( "SHOW TABLES LIKE '%ovbtest\\_%'" ) ) + count( (array) $wpdb->get_col( "SHOW TABLES LIKE 'other\\_ovbtest\\_app'" ) )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%ovbtest%' OR option_name LIKE 'xovb\\_keep' OR option_name LIKE '\\_transientx\\_keep'" )
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}backups WHERE note LIKE %s", $wpdb->esc_like( $ovb_marker ) . '%' ) )
	+ count( glob( Paths::tmp_dir() . '/*', GLOB_ONLYDIR ) ?: array() );
$verify_db_left = 0;
$probe          = ovb_open_mysqli( $wpdb, null );
if ( $probe ) {
	$res            = $probe->query( "SHOW DATABASES LIKE 'ovb_dump_verify'" );
	$verify_db_left = $res ? $res->num_rows : 0;
	$probe->close();
}
ovb_check( 'db export: scratch tables, options, jobs, work folders and the verify database cleaned up', 0 === $dump_leftovers && 0 === $verify_db_left, "leftovers: $dump_leftovers, verify db: $verify_db_left" );

// 14. File scan and archiving (Milestone 3, step 3c): a fixture site (about 3,000 files, a 20 MB incompressible file, odd names,
// symlinks, exclusions) is scanned and archived through the JobRunner with a tiny budget (many slices, simulated crashes) as
// zip, zip-store and tar.gz with 10 MB parts, then extracted with real tools and compared byte for byte.
// Everything created here is removed again.
use OpenVault\Backup\Archive\TarWriter;
use OpenVault\Backup\Archive\ZipArchiver;
use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Engine\Scan\ContentSelector;
use OpenVault\Backup\Engine\Scan\EntryName;
use OpenVault\Backup\Engine\Steps\ArchiveStep;
use OpenVault\Backup\Engine\Steps\FileScanStep;

function ovb_arc_mkdir( $path ) {
	if ( ! is_dir( $path ) ) {
		mkdir( $path, 0777, true );
	}
}

function ovb_arc_put( $path, $content ) {
	ovb_arc_mkdir( dirname( $path ) );
	file_put_contents( $path, $content );
}

function ovb_arc_random_file( $path, $megabytes ) {
	ovb_arc_mkdir( dirname( $path ) );
	$h = fopen( $path, 'wb' );
	for ( $i = 0; $i < $megabytes; $i++ ) {
		fwrite( $h, random_bytes( 1048576 ) );
	}
	fclose( $h );
}

function ovb_arc_files( $dir ) {
	$out = array();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $item ) {
		if ( $item->isFile() ) {
			$out[] = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $dir ) + 1 ) );
		}
	}
	sort( $out );
	return $out;
}

function ovb_arc_run( $cmd, &$output = array() ) {
	$output = array();
	$code   = 1;
	exec( $cmd . ' 2>&1', $output, $code );
	return $code;
}

/** Extract a zip with ZipArchive (used when exec is disabled). Returns the entry names. */
function ovb_arc_extract_zip_php( $part, $dir ) {
	$zip   = new ZipArchive();
	$names = array();
	if ( true !== $zip->open( $part, ZipArchive::CHECKCONS ) ) {
		return null;
	}
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name    = $zip->getNameIndex( $i );
		$names[] = $name;
		if ( ! EntryName::is_safe( $name ) ) {
			continue;
		}
		ovb_arc_mkdir( dirname( $dir . '/' . $name ) );
		$in  = $zip->getStream( $name );
		$out = fopen( $dir . '/' . $name, 'wb' );
		if ( ! $in || ! $out ) {
			return null;
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
	}
	$zip->close();
	return $names;
}

/** Extract a .tar.gz with gzopen and TarWriter::parse_header (used when exec is disabled). Returns the entry names. */
function ovb_arc_extract_targz_php( $part, $dir ) {
	$gz    = gzopen( $part, 'rb' );
	$names = array();
	$pax   = array();
	$zeros = 0;
	if ( ! $gz ) {
		return null;
	}
	$read = static function ( $gz, $length ) {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$piece = gzread( $gz, $length - strlen( $data ) );
			if ( false === $piece || '' === $piece ) {
				return null;
			}
			$data .= $piece;
		}
		return $data;
	};
	while ( $zeros < 2 ) {
		$block = $read( $gz, 512 );
		if ( null === $block ) {
			return null;
		}
		if ( 512 === strspn( $block, "\0" ) ) {
			++$zeros;
			continue;
		}
		$zeros  = 0;
		$header = TarWriter::parse_header( $block );
		if ( null === $header ) {
			return null;
		}
		$size = isset( $pax['size'] ) ? (int) $pax['size'] : $header['size'];
		if ( 'x' === $header['type'] ) {
			$body = $read( $gz, $size + TarWriter::padding( $size ) );
			$pax  = TarWriter::parse_pax( substr( (string) $body, 0, $size ) );
			continue;
		}
		$name    = isset( $pax['path'] ) ? $pax['path'] : $header['name'];
		$pax     = array();
		$names[] = $name;
		$target  = EntryName::is_safe( $name ) ? $dir . '/' . $name : null;
		if ( null !== $target ) {
			ovb_arc_mkdir( dirname( $target ) );
			$out = fopen( $target, 'wb' );
		}
		$left = $size + TarWriter::padding( $size );
		$kept = $size;
		while ( $left > 0 ) {
			$piece = gzread( $gz, min( 1048576, $left ) );
			if ( false === $piece || '' === $piece ) {
				return null;
			}
			$left -= strlen( $piece );
			if ( null !== $target && $kept > 0 ) {
				$take  = min( $kept, strlen( $piece ) );
				fwrite( $out, substr( $piece, 0, $take ) );
				$kept -= $take;
			}
		}
		if ( null !== $target ) {
			fclose( $out );
		}
	}
	gzclose( $gz );
	return $names;
}

function ovb_arc_rm( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path );
		return;
	}
	if ( is_dir( $path ) ) {
		CleanupStep::remove_tree( $path );
	}
}

$ovb_arc_ids      = array();
$ovb_arc_dirs     = array();
$ovb_arc_steps    = null;
$ovb_arc_hook     = static function ( $steps ) use ( &$ovb_arc_steps ) {
	return null === $ovb_arc_steps ? $steps : $ovb_arc_steps;
};
$ovb_arc_settings = get_option( 'ovb_settings' );
$ovb_arc_fixture  = null;
$ovb_arc_can_exec = function_exists( 'exec' );

try {
	$abk   = new BackupsRepository();
	$afile = new BackupFilesRepository();
	add_filter( 'ovb_engine_steps', $ovb_arc_hook );

	// 14a. Build the fixture site.
	$fx   = Paths::tmp_dir() . '/ovb-fixture-' . bin2hex( random_bytes( 4 ) );
	$site = $fx . '/site';
	$ovb_arc_fixture = $fx;
	$expected = array();
	$absent   = array();
	$add      = static function ( $rel, $content, $entry ) use ( $site, &$expected ) {
		ovb_arc_put( $site . '/' . $rel, $content );
		$expected[ $entry ] = $site . '/' . $rel;
	};
	foreach ( array( 'wp-admin', 'wp-includes', 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads', 'wp-content/mu-plugins', 'wp-content/languages', 'wp-content/ovb-fake-local' ) as $dir ) {
		ovb_arc_mkdir( $site . '/' . $dir );
	}
	$add( 'index.php', "<?php\n// index\n", 'abspath/index.php' );
	$add( 'wp-load.php', "<?php\n", 'abspath/wp-load.php' );
	$add( '.htaccess', "# BEGIN WordPress\n", 'abspath/.htaccess' );
	$add( 'wp-config.php', "<?php\ndefine( 'DB_NAME', 'x' );\n", 'abspath/wp-config.php' );
	$add( 'wp-content/index.php', "<?php // silence\n", 'content/index.php' );
	$add( 'wp-content/languages/en.mo', random_bytes( 3000 ), 'content/languages/en.mo' );
	$add( 'wp-content/mu-plugins/mu.php', "<?php // mu\n", 'content/mu-plugins/mu.php' );
	$add( 'wp-content/plugins/index.php', "<?php // silence\n", 'content/plugins/index.php' );

	$small = 0;
	for ( $i = 0; $i < 300; $i++ ) {
		$add( 'wp-includes/inc/f' . $i . '.php', random_bytes( 4096 ) . $i, 'abspath/wp-includes/inc/f' . $i . '.php' );
		++$small;
	}
	for ( $i = 0; $i < 200; $i++ ) {
		$add( 'wp-admin/sub' . ( $i % 5 ) . '/a' . $i . '.php', random_bytes( 4096 ) . $i, 'abspath/wp-admin/sub' . ( $i % 5 ) . '/a' . $i . '.php' );
		++$small;
	}
	for ( $p = 0; $p < 20; $p++ ) {
		for ( $i = 0; $i < 40; $i++ ) {
			$sub = 0 === $i % 2 ? 'includes' : 'assets/js';
			$add( 'wp-content/plugins/plug' . $p . '/' . $sub . '/p' . $i . '.php', random_bytes( 8192 ) . $i, 'content/plugins/plug' . $p . '/' . $sub . '/p' . $i . '.php' );
			++$small;
		}
	}
	for ( $t = 0; $t < 10; $t++ ) {
		for ( $i = 0; $i < 30; $i++ ) {
			$add( 'wp-content/themes/theme' . $t . '/t' . $i . '.css', random_bytes( 6000 ) . $i, 'content/themes/theme' . $t . '/t' . $i . '.css' );
			++$small;
		}
	}
	for ( $i = 0; $i < 1000; $i++ ) {
		$y = 2020 + ( $i % 6 );
		$m = sprintf( '%02d', 1 + ( $i % 12 ) );
		$add( 'wp-content/uploads/' . $y . '/' . $m . '/img' . $i . '.jpg', random_bytes( 7000 ) . $i, 'content/uploads/' . $y . '/' . $m . '/img' . $i . '.jpg' );
		++$small;
	}
	for ( $i = 0; $i < 450; $i++ ) {
		$add( 'wp-content/uploads/bulk/b' . $i . '.txt', 'bulk ' . $i . str_repeat( 'x', $i % 50 ), 'content/uploads/bulk/b' . $i . '.txt' );
		++$small;
	}

	ovb_arc_random_file( $site . '/wp-content/uploads/big-random.bin', 20 );
	$expected['content/uploads/big-random.bin'] = $site . '/wp-content/uploads/big-random.bin';
	$text = '';
	while ( strlen( $text ) < 5 * 1048576 ) {
		$text .= "Lorem ipsum dolor sit amet, consectetur adipiscing elit, line " . strlen( $text ) . "\n";
	}
	$add( 'wp-content/uploads/compressible.txt', $text, 'content/uploads/compressible.txt' );
	$add( 'wp-content/uploads/caf' . "\xc3\xa9" . ' ' . "\xc3\xbc" . 'n' . "\xc3\xaf" . 'code.txt', 'unicode name', 'content/uploads/caf' . "\xc3\xa9" . ' ' . "\xc3\xbc" . 'n' . "\xc3\xaf" . 'code.txt' );
	$add( 'wp-content/uploads/with space  double.txt', 'spaces', 'content/uploads/with space  double.txt' );
	$add( 'wp-content/uploads/emoji ' . "\xf0\x9f\x98\x80" . '.png', 'emoji', 'content/uploads/emoji ' . "\xf0\x9f\x98\x80" . '.png' );
	$long_name = str_repeat( 'long-name-', 12 ) . '.txt';
	$add( 'wp-content/uploads/' . $long_name, 'name over 100 characters', 'content/uploads/' . $long_name );
	$deep = str_repeat( 'd', 90 ) . '/' . str_repeat( 'e', 90 ) . '/' . str_repeat( 'f', 90 );
	$add( 'wp-content/uploads/' . $deep . '/deep-file-with-a-long-name.txt', 'path over 255 characters', 'content/uploads/' . $deep . '/deep-file-with-a-long-name.txt' );
	$add( 'wp-content/uploads/empty.txt', '', 'content/uploads/empty.txt' );
	$add( 'wp-content/uploads/empty2.dat', '', 'content/uploads/empty2.dat' );
	ovb_arc_mkdir( $site . '/wp-content/uploads/empty-dir/nested-empty' );

	// Excluded by pattern or rule.
	foreach ( array( 'cache/a.tmp', 'cache/sub/b.tmp' ) as $rel ) {
		ovb_arc_put( $site . '/wp-content/uploads/' . $rel, 'cache' );
		$absent[] = 'content/uploads/' . $rel;
	}
	ovb_arc_put( $site . '/wp-content/uploads/debug.log', 'log' );
	ovb_arc_put( $site . '/wp-content/plugins/plug0/node_modules/lib/x.js', 'js' );
	ovb_arc_put( $site . '/wp-content/plugins/plug0/.git/config', 'git' );
	ovb_arc_put( $site . '/wp-content/ovb-fake-local/secret-backup.zip', 'this is the plugin own folder' );
	ovb_arc_put( $site . '/wp-content/plugins/unreadable-dir/hidden.php', 'unreadable' );
	array_push( $absent, 'content/uploads/debug.log', 'content/plugins/plug0/node_modules/lib/x.js', 'content/plugins/plug0/.git/config', 'content/ovb-fake-local/secret-backup.zip', 'content/plugins/unreadable-dir/hidden.php' );

	// Oversize (sparse, 27 MB; the limit is 25 MB).
	$h = fopen( $site . '/wp-content/uploads/oversize.bin', 'wb' );
	fseek( $h, 27 * 1048576 - 1 );
	fwrite( $h, "\0" );
	fclose( $h );
	$absent[] = 'content/uploads/oversize.bin';

	// Symlinks: to a file, to a folder, a loop and a link out of the site.
	$links = 0;
	if ( function_exists( 'symlink' ) ) {
		foreach ( array(
			'link-to-file'   => $site . '/index.php',
			'link-to-dir'    => $site . '/wp-content/plugins',
			'loop'           => $site . '/wp-content/uploads',
			'link-out'       => '/etc',
		) as $name => $target ) {
			if ( @symlink( $target, $site . '/wp-content/uploads/' . $name ) ) {
				++$links;
				$absent[] = 'content/uploads/' . $name;
			}
		}
	}
	ovb_check( 'file scan fixture: symlinks could be created', 4 === $links, "links $links" );

	// Names that cannot be stored safely: a backslash, a newline, a tab, bytes that are not UTF-8.
	$bad = 0;
	foreach ( array( 'back\\slash.txt', "new\nline.txt", "tab\there.txt", "bad\xff" . 'utf8.txt' ) as $name ) {
		if ( false !== @file_put_contents( $site . '/wp-content/uploads/' . $name, 'bad' ) ) {
			++$bad;
		}
	}
	ovb_check( 'file scan fixture: odd file names could be created', 4 === $bad, "bad $bad" );

	$total_expected = count( $expected );
	ovb_check( sprintf( 'file scan fixture: %d files to archive (%d small), %d must stay out', $total_expected, $small, count( $absent ) ), $total_expected > 3000 );

	$selector = new ContentSelector(
		array(
			'abspath'        => $site,
			'content_dir'    => $site . '/wp-content',
			'plugins_dir'    => $site . '/wp-content/plugins',
			'themes_dir'     => $site . '/wp-content/themes',
			'uploads_dir'    => $site . '/wp-content/uploads',
			'mu_plugins_dir' => $site . '/wp-content/mu-plugins',
			'local_dir'      => $site . '/wp-content/ovb-fake-local',
			'config_file'    => $site . '/wp-config.php',
		)
	);
	$all_contents = array( 'db' => true, 'plugins' => true, 'themes' => true, 'uploads' => true, 'mu_plugins' => true, 'other_content' => true, 'core' => true, 'wp_config' => true );
	$unreadable   = $site . '/wp-content/plugins/unreadable-dir';
	$opener       = static function ( $abs ) use ( $unreadable ) {
		if ( $abs === $unreadable ) {
			return false;
		}
		try {
			return new \DirectoryIterator( $abs );
		} catch ( \Exception $e ) {
			return false;
		}
	};
	$settings                   = is_array( $ovb_arc_settings ) ? $ovb_arc_settings : array();
	$settings['part_size_mb']   = 10;
	$settings['max_file_size_mb'] = 25;
	$settings['excluded_paths'] = array( 'content/uploads/cache/*', '*.log', '*/node_modules/*', '*', '../../etc' );
	update_option( 'ovb_settings', $settings );

	// 14b. One scan in one slice: the list file.
	$scan_job         = $abk->create( array( 'note' => $ovb_marker . ' scan', 'contents' => $all_contents, 'format' => 'zip' ) );
	$ovb_arc_ids[]    = $scan_job;
	$scan_row         = $abk->find( $scan_job );
	$scan_dir         = Paths::work_dir( $scan_row['uuid'] );
	wp_mkdir_p( $scan_dir );
	$big_budget       = static function () {
		return new Budget( 600, PHP_INT_MAX );
	};
	$scan_ctx         = new JobContext( $scan_row, JobState::new_state( 'file_scan' ), $big_budget(), new Logger(), $scan_dir, $settings );
	$scan_started     = microtime( true );
	$scan_res         = ( new FileScanStep( $opener, 500, $selector ) )->run( $scan_ctx );
	$scan_time        = microtime( true ) - $scan_started;
	$scan_meta        = $scan_res->state()->get_meta();
	$one_list         = is_file( $scan_dir . '/files.list' ) ? file( $scan_dir . '/files.list', FILE_IGNORE_NEW_LINES ) : array();
	$list_ok          = true;
	$list_entries     = array();
	foreach ( $one_list as $line ) {
		$parts = explode( "\t", $line, 3 );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || ! EntryName::is_safe( $parts[2] ) || ! isset( $expected[ $parts[2] ] ) || (int) $parts[0] !== filesize( $expected[ $parts[2] ] ) ) {
			$list_ok = false;
		}
		$list_entries[] = $parts[2];
	}
	sort( $list_entries );
	$expected_entries = array_keys( $expected );
	sort( $expected_entries );
	ovb_check( sprintf( 'file scan: one slice finished (%.2f s)', $scan_time ), $scan_res->is_next() && isset( $scan_meta['files'] ), $scan_res->message() );
	ovb_check( 'file scan: files.list has one "<size>\t<mtime>\t<entry>" record per file with the right size and a safe name', $list_ok && count( $one_list ) === $total_expected, count( $one_list ) . ' lines vs ' . $total_expected );
	ovb_check( 'file scan: the list holds exactly the expected files (no duplicates, nothing excluded, no symlinks, no oversize, no bad names, not the plugin folder)', $list_entries === $expected_entries, wp_json_encode( array( 'missing' => array_slice( array_diff( $expected_entries, $list_entries ), 0, 5 ), 'extra' => array_slice( array_diff( $list_entries, $expected_entries ), 0, 5 ) ) ) );
	$skipped = isset( $scan_meta['files']['skipped'] ) ? $scan_meta['files']['skipped'] : array();
	ovb_check( 'file scan: counters: 4 symlinks, 1 unreadable folder, 1 oversize file, 5 excluded (cache folder, *.log, node_modules, .git, the plugin folder), 4 bad names', array( 'symlink' => 4, 'unreadable' => 1, 'oversize' => 1, 'excluded' => 5, 'badname' => 4 ) === $skipped, wp_json_encode( $skipped ) );
	ovb_check( 'file scan: meta carries list, size, count, bytes and roots; the queue file is gone', isset( $scan_meta['files']['list'], $scan_meta['files']['roots'] ) && $scan_meta['files']['count'] === $total_expected && $scan_meta['files']['size'] === filesize( $scan_dir . '/files.list' ) && ! file_exists( $scan_dir . '/scan-queue.txt' ) );
	ovb_check( 'file scan: meta stays within the job state limit', strlen( wp_json_encode( $scan_meta ) ) < JobState::MAX_META_BYTES );
	$scan_logs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}logs WHERE backup_id = %d AND level = 'warning'", $scan_job ) );
	ovb_check( 'file scan: skipped entries are logged as warnings, at most 20', $scan_logs >= 5 && $scan_logs <= 21, "warnings $scan_logs" );

	// A selection without file contents skips the step.
	$none_row = $scan_row;
	$none_row['contents'] = array( 'db' => true );
	$none_res = ( new FileScanStep( $opener, 500, $selector ) )->run( new JobContext( $none_row, JobState::new_state( 'file_scan' ), $big_budget(), new Logger(), $scan_dir, $settings ) );
	ovb_check( 'file scan: contents without files skip the step', $none_res->is_next() && ! isset( $none_res->state()->get_meta()['files'] ) );

	// Format refusal and missing input.
	$bz_row = $scan_row;
	$bz_row['format'] = 'tar.bz2';
	$bz_res = ( new ArchiveStep() )->run( new JobContext( $bz_row, JobState::new_state( 'archive' ), $big_budget(), new Logger(), $scan_dir, $settings ) );
	ovb_check( 'archive: tar.bz2 is refused with a clear message', $bz_res->is_fail() && false !== strpos( $bz_res->message(), 'later release' ), $bz_res->message() );
	$empty_res = ( new ArchiveStep() )->run( new JobContext( $scan_row, JobState::new_state( 'archive' ), $big_budget(), new Logger(), $scan_dir, $settings ) );
	ovb_check( 'archive: nothing to archive fails with a clear message', $empty_res->is_fail() && false !== strpos( $empty_res->message(), 'nothing to archive' ), $empty_res->message() );
	CleanupStep::remove_tree( $scan_dir );

	// 14c. The runs: scan and archive through the JobRunner, several slices per step, simulated crashes.
	$verifier_name = $ovb_arc_can_exec ? 'unzip / tar / gzip (real tools)' : 'ZipArchive / gzopen + TarWriter (exec is disabled here)';
	$peak_max      = 0;
	foreach ( array( 'zip', 'zip-store', 'tar.gz' ) as $format ) {
		$tag    = '[' . $format . '] ';
		$clock  = 0.0;
		$mem    = 0;
		$factory = static function () use ( &$clock, &$mem ) {
			return new Budget(
				45,
				PHP_INT_MAX,
				static function () use ( &$clock ) {
					$clock += 1.0;
					return $clock;
				},
				static function () use ( &$mem ) {
					$used = memory_get_usage( true );
					$mem  = max( $mem, $used );
					return $used;
				}
			);
		};
		$scan_counter    = new OvbCountingStep( new FileScanStep( $opener, 50, $selector ) );
		$archive_counter = new OvbCountingStep( new ArchiveStep() );
		$ovb_arc_steps   = array(
			'preflight' => new OvbMatrixStep( 'preflight', static function ( JobContext $c ) {
				return StepResult::next_step( $c->state() );
			} ),
			'db_export' => new \OpenVault\Backup\Engine\Steps\DatabaseExportStep(),
			'file_scan' => $scan_counter,
			'archive'   => $archive_counter,
		);
		$runner = new JobRunner( null, null, $factory );
		$job    = $abk->create( array( 'note' => $ovb_marker . ' archive ' . $format, 'contents' => $all_contents, 'format' => $format ) );
		$ovb_arc_ids[] = $job;
		$runner->start( $job );
		$row    = $abk->find( $job );
		$work   = Paths::work_dir( $row['uuid'] );
		$folder = Paths::backup_dir( $row['created_at'], $row['uuid'] );
		$ovb_arc_dirs[] = $folder;
		$ext    = 'tar.gz' === $format ? 'tar.gz' : 'zip';
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$mem_before = memory_get_usage( true );
		$started    = microtime( true );

		$outcomes   = array();
		$arc_prev   = null;
		$scan_prev  = null;
		$arc_mono   = true;
		$scan_mono  = true;
		$arc_points = 0;
		$scan_points = 0;
		$crashed    = 0;
		$replayed   = false;
		$saved_arc  = null;
		$arc_slices = 0;
		$scan_garbage = false;
		for ( $i = 0; $i < 3000; $i++ ) {
			ovb_clear_pending( $job );
			$out        = $runner->run( $job );
			$outcomes[] = $out;
			$state      = $abk->load_state( $job );
			$cursor     = $state->get_cursor();
			if ( 'file_scan' === $state->get_step() && isset( $cursor['queue_end'] ) ) {
				$point = array( (int) $cursor['queue_offset'], (int) $cursor['dir_skip'], (int) $cursor['list_end'] );
				if ( null !== $scan_prev && ( $point < $scan_prev || $point === $scan_prev ) ) {
					$scan_mono = false;
				}
				$scan_prev = $point;
				++$scan_points;
				if ( 3 === $scan_points ) {
					// A killed scan: bytes written to both work files after the saved sizes.
					file_put_contents( $work . '/files.list', "999\t1\tcontent/GARBAGE\nGARBAGE-LINE-WITHOUT-NEWLINE", FILE_APPEND );
					file_put_contents( $work . '/scan-queue.txt', "0\tGARBAGE-DIR\n", FILE_APPEND );
					$scan_garbage = true;
				}
			}
			if ( 'archive' === $state->get_step() && isset( $cursor['phase'] ) ) {
				++$arc_slices;
				$rank  = array( 'db' => 0, 'files' => 1, 'finalize' => 2 );
				$point = array( $rank[ $cursor['phase'] ], (int) $cursor['parts_done'], (int) $cursor['list_offset'], (int) $cursor['file_pos'] );
				if ( null !== $arc_prev && ( $point < $arc_prev || $point === $arc_prev ) ) {
					$arc_mono = false;
				}
				$arc_prev = $point;
				++$arc_points;
				$part_file = $folder . '/' . ( 'db' === $cursor['part_kind'] ? 'db' : 'files' ) . '.part' . sprintf( '%02d', $cursor['part_index'] ) . '.' . $ext;
				if ( 4 === $arc_points || 9 === $arc_points ) {
					// A killed run: bytes written to the open part after the last saved slice, and a temporary file of libzip.
					if ( is_file( $part_file ) ) {
						file_put_contents( $part_file, "GARBAGE\x1f\x8b\x08partial" . random_bytes( 700 ), FILE_APPEND );
						if ( 'tar.gz' !== $format ) {
							file_put_contents( $part_file . '.aB3dE9xy', 'temporary file of a killed run' );
						}
						++$crashed;
					}
				}
				if ( 12 === $arc_points ) {
					$saved_arc = $abk->load_state( $job );
				}
				if ( 20 === $arc_points && null !== $saved_arc ) {
					// Eight slices ran and their cursors were lost: an older cursor comes back, the parts on disk are newer.
					$abk->save_state( $job, $saved_arc );
					$arc_prev = null;
					$replayed = true;
				}
			}
			if ( 'continued' !== $out ) {
				break;
			}
		}
		$elapsed = microtime( true ) - $started;
		$final   = $abk->find( $job );
		$fstate  = $abk->load_state( $job );
		$meta    = $fstate->get_meta();
		$peak    = max( $mem, function_exists( 'memory_reset_peak_usage' ) ? memory_get_peak_usage( true ) : 0 );
		$peak_max = max( $peak_max, $peak );
		$scan_continues    = count( array_filter( $scan_counter->log, static function ( $e ) { return 'continue' === $e['kind']; } ) );
		$archive_continues = count( array_filter( $archive_counter->log, static function ( $e ) { return 'continue' === $e['kind']; } ) );

		ovb_check( $tag . 'job finished through the runner (status complete, state done)', 'complete' === end( $outcomes ) && 'complete' === $final['status'] && 'done' === $fstate->get_step(), wp_json_encode( array_count_values( $outcomes ) ) . ' ' . (string) $fstate->get_error() );
		ovb_check( sprintf( '%sscan needed %d slices and archive %d slices (more than 10 archive slices)', $tag, $scan_continues + 1, $archive_continues + 1 ), $scan_continues >= 3 && $archive_continues > 10 );
		ovb_check( $tag . 'cursors move forward in every slice (scan: queue offset, folder position, list size; archive: phase, parts, list offset, file position)', $scan_mono && $arc_mono && $scan_points >= 3 && $arc_points > 10, "scan $scan_points archive $arc_points" );
		ovb_check( $tag . 'crash simulations ran: garbage in the scan work files, garbage and stray temporary files after the saved part size (twice), an older cursor replayed over newer parts', $scan_garbage && 2 === $crashed && $replayed, "garbage $scan_garbage crashed $crashed replayed $replayed" );

		$rows       = $afile->for_backup_and_destination( $job, 0 );
		$part_rows  = array_values( array_filter( $rows, static function ( $r ) { return 'manifest.json' !== $r['file_name']; } ) );
		$manifest_rows = array_values( array_filter( $rows, static function ( $r ) { return 'manifest.json' === $r['file_name']; } ) );
		$part_names = array_column( $part_rows, 'file_name' );
		ovb_check( sprintf( '%s%d parts of about 10 MB (%s)', $tag, count( $part_rows ), implode( ', ', $part_names ) ), count( $part_rows ) >= 4 && 'db.part01.' . $ext === $part_names[0] && 'files.part01.' . $ext === $part_names[1] );
		$on_disk = array_values( array_diff( scandir( $folder ), array( '.', '..', 'index.php', '.htaccess', 'web.config' ) ) );
		sort( $on_disk );
		$should = array_merge( $part_names, array( 'manifest.json' ) );
		sort( $should );
		ovb_check( $tag . 'the backup folder holds the parts and manifest.json and nothing else (no temporary or half written files); guard files are present', $on_disk === $should && is_file( $folder . '/index.php' ) && is_file( $folder . '/.htaccess' ), wp_json_encode( array( $on_disk, $should ) ) );

		$sizes_ok  = true;
		$sha_ok    = true;
		$total_on_disk = 0;
		foreach ( $part_rows as $r ) {
			clearstatcache( true, $folder . '/' . $r['file_name'] );
			$sizes_ok = $sizes_ok && filesize( $folder . '/' . $r['file_name'] ) === $r['size'] && 'done' === $r['upload_status'] && 0 === $r['destination_id'];
			$sha_ok   = $sha_ok && hash_file( 'sha256', $folder . '/' . $r['file_name'] ) === $r['checksum_sha256'];
			$total_on_disk += $r['size'];
		}
		ovb_check( $tag . 'every part row matches the file on disk: size, sha256 (hash_file), destination 0, upload_status done', $sizes_ok && $sha_ok );
		$manifest = null;
		try {
			$manifest = Manifest::from_json( (string) file_get_contents( $folder . '/manifest.json' ) );
		} catch ( \Exception $e ) {
			$manifest = null;
		}
		$manifest_parts_ok = null !== $manifest && count( $manifest->get_parts() ) === count( $part_rows );
		if ( $manifest_parts_ok ) {
			foreach ( $manifest->get_parts() as $idx => $mp ) {
				if ( $mp['name'] !== $part_rows[ $idx ]['file_name'] || $mp['size'] !== $part_rows[ $idx ]['size'] || $mp['sha256'] !== $part_rows[ $idx ]['checksum_sha256'] ) {
					$manifest_parts_ok = false;
				}
			}
		}
		ovb_check( $tag . 'manifest.json parses with Manifest::from_json and lists exactly the produced parts with their sizes and checksums', $manifest_parts_ok );
		ovb_check( $tag . 'manifest facts: uuid, format, not encrypted, table prefix, contents, db kind first', null !== $manifest && $manifest->get_uuid() === $row['uuid'] && $manifest->get_format() === $format && ! $manifest->is_encrypted() && $manifest->get_table_prefix() === $wpdb->base_prefix && $manifest->get_contents() === $all_contents && 'db' === $manifest->get_parts()[0]['kind'] && 'files' === $manifest->get_parts()[1]['kind'] );
		ovb_check( $tag . 'the manifest has its own backup file row and the backup row total_size is the sum of the parts', 1 === count( $manifest_rows ) && hash( 'sha256', (string) file_get_contents( $folder . '/manifest.json' ) ) === $manifest_rows[0]['checksum_sha256'] && $manifest_rows[0]['size'] === filesize( $folder . '/manifest.json' ) && $final['total_size'] === $total_on_disk && null !== $manifest && $manifest->total_size() === $total_on_disk, $final['total_size'] . ' vs ' . $total_on_disk );

		// Real extraction.
		$extract = $fx . '/extract-' . str_replace( '.', '-', $format );
		ovb_arc_mkdir( $extract );
		$entry_lists = array();
		$tool_ok     = true;
		$tool_log    = '';
		foreach ( $part_names as $name ) {
			$path = $folder . '/' . $name;
			if ( $ovb_arc_can_exec ) {
				if ( 'tar.gz' === $format ) {
					$c1 = ovb_arc_run( 'gzip -t ' . escapeshellarg( $path ), $o1 );
					$c2 = ovb_arc_run( 'tar --quoting-style=literal -tzf ' . escapeshellarg( $path ), $listing );
					$c3 = ovb_arc_run( 'tar -xzf ' . escapeshellarg( $path ) . ' -C ' . escapeshellarg( $extract ), $o3 );
				} else {
					$c1 = ovb_arc_run( 'LC_ALL=C.UTF-8 unzip -tq ' . escapeshellarg( $path ), $o1 );
					$c2 = ovb_arc_run( 'LC_ALL=C.UTF-8 unzip -Z1 ' . escapeshellarg( $path ), $listing );
					$c3 = ovb_arc_run( 'LC_ALL=C.UTF-8 unzip -q -o ' . escapeshellarg( $path ) . ' -d ' . escapeshellarg( $extract ), $o3 );
				}
				if ( 0 !== $c1 || 0 !== $c2 || 0 !== $c3 ) {
					$tool_ok  = false;
					$tool_log .= $name . ': ' . $c1 . '/' . $c2 . '/' . $c3 . ' ' . substr( implode( ' | ', array_merge( $o1, $o3 ) ), 0, 300 ) . ' ';
				}
				$entry_lists[ $name ] = array_values( array_filter( $listing, static function ( $l ) { return '' !== $l && '/' !== substr( $l, -1 ); } ) );
			} else {
				$names = 'tar.gz' === $format ? ovb_arc_extract_targz_php( $path, $extract ) : ovb_arc_extract_zip_php( $path, $extract );
				if ( null === $names ) {
					$tool_ok  = false;
					$tool_log .= $name . ' could not be read ';
					$names = array();
				}
				$entry_lists[ $name ] = $names;
			}
		}
		ovb_check( $tag . 'verifier: ' . $verifier_name . ': every part passes the integrity test and extracts', $tool_ok, $tool_log );

		$extracted = ovb_arc_files( $extract );
		$want      = array_merge( array_keys( $expected ), array( 'db/db.sql' ) );
		sort( $want );
		ovb_check( sprintf( '%sextracted tree has exactly the %d expected files: db/db.sql, abspath/..., content/...; no excluded file, no symlink, no oversize file, no bad name, nothing of the plugin folder', $tag, count( $want ) ), $extracted === $want, wp_json_encode( array( 'missing' => array_slice( array_diff( $want, $extracted ), 0, 5 ), 'extra' => array_slice( array_diff( $extracted, $want ), 0, 5 ) ) ) );

		$content_bad = array();
		foreach ( $expected as $entry => $source ) {
			$target = $extract . '/' . $entry;
			if ( ! is_file( $target ) || filesize( $target ) !== filesize( $source ) || hash_file( 'sha256', $target ) !== hash_file( 'sha256', $source ) ) {
				$content_bad[] = $entry;
				if ( count( $content_bad ) >= 5 ) {
					break;
				}
			}
		}
		ovb_check( sprintf( '%severy extracted file has the size and sha256 of its source (%d files, including unicode, emoji, spaces, a name over 100 characters, a path over 255 characters, empty files, the 20 MB random file)', $tag, count( $expected ) ), array() === $content_bad, implode( ', ', $content_bad ) );
		$absent_found = array();
		foreach ( $absent as $entry ) {
			if ( file_exists( $extract . '/' . $entry ) || is_link( $extract . '/' . $entry ) ) {
				$absent_found[] = $entry;
			}
		}
		ovb_check( $tag . 'excluded, symlinked, oversize and unreadable entries are absent', array() === $absent_found, implode( ', ', $absent_found ) );

		$all_names = array();
		foreach ( $entry_lists as $list ) {
			$all_names = array_merge( $all_names, $list );
		}
		$unsafe = array_filter( $all_names, static function ( $n ) { return ! EntryName::is_safe( $n ); } );
		ovb_check( sprintf( '%sno entry name has "..", a leading slash, a backslash or a control character (%d names checked)', $tag, count( $all_names ) ), array() === $unsafe && count( $all_names ) === count( $want ), implode( ', ', array_slice( $unsafe, 0, 3 ) ) . ' count ' . count( $all_names ) );
		$db_part = $entry_lists['db.part01.' . $ext];
		ovb_check( $tag . 'db.part01 holds only db/db.sql and it is identical (sha256, size) to the dump of the export step', array( 'db/db.sql' ) === $db_part && isset( $meta['db'] ) && is_file( $extract . '/db/db.sql' ) && hash_file( 'sha256', $extract . '/db/db.sql' ) === $meta['db']['sha256'] && filesize( $extract . '/db/db.sql' ) === $meta['db']['size'] );
		$alone = false;
		foreach ( $entry_lists as $name => $list ) {
			if ( in_array( 'content/uploads/big-random.bin', $list, true ) ) {
				$alone = array( 'content/uploads/big-random.bin' ) === $list;
			}
		}
		ovb_check( $tag . 'the 20 MB file (larger than the 10 MB part limit) is alone in its part', $alone );

		// Work files, the scan result and the archive meta.
		$meta_ok = isset( $meta['files'], $meta['archive'] ) && $meta['files']['count'] === $total_expected && $meta['archive']['parts'] === count( $part_rows ) && $meta['archive']['files'] === $total_expected && $meta['archive']['folder'] === basename( $folder ) && $meta['archive']['total_size'] === $total_on_disk;
		ovb_check( $tag . 'state meta: files (count), archive (parts, files, folder, total_size)', $meta_ok, wp_json_encode( isset( $meta['archive'] ) ? $meta['archive'] : null ) );
		$list_after = is_file( $work . '/files.list' ) ? file( $work . '/files.list', FILE_IGNORE_NEW_LINES ) : array();
		sort( $list_after );
		sort( $one_list );
		ovb_check( $tag . 'the list built over many slices with crashes equals the list built in one slice', $list_after === $one_list, count( $list_after ) . ' vs ' . count( $one_list ) );
		ovb_check( $tag . 'no leftover scan queue', ! file_exists( $work . '/scan-queue.txt' ) );

		// Cleanup removes the work folder and leaves the backup folder.
		$clean_res = ( new CleanupStep() )->run( new JobContext( $row, JobState::new_state( 'cleanup' ), $big_budget(), new Logger(), $work, $settings ) );
		ovb_check( $tag . 'cleanup removes the work folder and leaves the backup folder with its parts', ! file_exists( $work ) && is_dir( $folder ) && is_file( $folder . '/manifest.json' ) && $clean_res->is_next() );

		$compressed = (int) $total_on_disk;
		ovb_check( sprintf( '%smemory: %.1f MB peak (%.1f MB before), %.1f s, limit %s; %.1f MB compressed for %.1f MB of files (parts: %s)', $tag, $peak / 1048576, $mem_before / 1048576, $elapsed, ini_get( 'memory_limit' ), $compressed / 1048576, array_sum( array_map( 'filesize', array_values( $expected ) ) ) / 1048576, implode( ', ', array_map( static function ( $r ) { return sprintf( '%.1f', $r['size'] / 1048576 ); }, $part_rows ) ) ), true );
		$limit = Budget::parse_bytes( ini_get( 'memory_limit' ) );
		ovb_check( $tag . 'peak memory stays below 85% of the PHP memory limit', $limit <= 0 || $peak < 0.85 * $limit, sprintf( 'peak %.1f MB, limit %s', $peak / 1048576, ini_get( 'memory_limit' ) ) );

		// Free disk space before the next format.
		ovb_arc_rm( $extract );
		ovb_arc_rm( $folder );
	}//end foreach

	// 14d. Files that vanished, shrank or grew between the scan and the archive never abort the backup.
	foreach ( array( 'zip', 'tar.gz' ) as $format ) {
		$tag  = '[' . $format . ' changing files] ';
		$ext  = 'tar.gz' === $format ? 'tar.gz' : 'zip';
		$base = $fx . '/changing-' . str_replace( '.', '-', $format );
		ovb_arc_mkdir( $base );
		ovb_arc_put( $base . '/normal.txt', 'normal content' );
		ovb_arc_put( $base . '/vanished.txt', 'will disappear' );
		ovb_arc_put( $base . '/shrunk.txt', '0123456789' );
		ovb_arc_put( $base . '/grown.txt', '0123456789' );
		ovb_arc_put( $base . '/empty.txt', '' );
		$records = '';
		foreach ( array( 'normal.txt', 'vanished.txt', 'shrunk.txt', 'grown.txt', 'empty.txt' ) as $n ) {
			$records .= filesize( $base . '/' . $n ) . "\t1700000000\tcontent/" . $n . "\n";
		}
		$records .= "garbage record without tabs\n";
		unlink( $base . '/vanished.txt' );
		file_put_contents( $base . '/shrunk.txt', 'abc' );
		file_put_contents( $base . '/grown.txt', '0123456789ABCDEFGHIJ' );

		$job            = $abk->create( array( 'note' => $ovb_marker . ' changing ' . $format, 'contents' => array( 'uploads' => true ), 'format' => $format ) );
		$ovb_arc_ids[]  = $job;
		$row            = $abk->find( $job );
		$work           = Paths::work_dir( $row['uuid'] );
		$folder         = Paths::backup_dir( $row['created_at'], $row['uuid'] );
		$ovb_arc_dirs[] = $folder;
		wp_mkdir_p( $work );
		file_put_contents( $work . '/files.list', $records );
		$state = JobState::new_state( 'archive' )->with_meta(
			array(
				'files' => array(
					'list'  => 'files.list',
					'roots' => array( array( 'entry' => 'content', 'abs' => $base, 'type' => 'dir' ) ),
				),
			)
		);
		$res = null;
		for ( $i = 0; $i < 20; $i++ ) {
			$res = ( new ArchiveStep() )->run( new JobContext( $row, $state, $big_budget(), new Logger(), $work, $settings ) );
			if ( ! $res->is_continue() ) {
				break;
			}
			$state = $res->state();
		}
		ovb_check( $tag . 'the step finishes (never aborts) with a vanished, a shrunk, a grown, an empty file and a damaged record', null !== $res && $res->is_next(), null !== $res ? $res->message() : '' );
		$part    = $folder . '/files.part01.' . $ext;
		$extract = $fx . '/extract-changing-' . str_replace( '.', '-', $format );
		ovb_arc_mkdir( $extract );
		$names = 'tar.gz' === $format ? ovb_arc_extract_targz_php( $part, $extract ) : ovb_arc_extract_zip_php( $part, $extract );
		ovb_check( $tag . 'the part is readable and holds the surviving files (the vanished file is not in it)', is_array( $names ) && in_array( 'content/normal.txt', $names, true ) && ! in_array( 'content/vanished.txt', $names, true ) && 'normal content' === (string) @file_get_contents( $extract . '/content/normal.txt' ) && is_file( $extract . '/content/empty.txt' ) && 0 === filesize( $extract . '/content/empty.txt' ), wp_json_encode( $names ) );
		$shrunk = (string) @file_get_contents( $extract . '/content/shrunk.txt' );
		$grown  = (string) @file_get_contents( $extract . '/content/grown.txt' );
		if ( 'tar.gz' === $format ) {
			ovb_check( $tag . 'tar: the shrunk file is padded with zeros to the scanned size, the grown file is cut at the scanned size', "abc\0\0\0\0\0\0\0" === $shrunk && '0123456789' === $grown, bin2hex( $shrunk ) . ' / ' . $grown );
		} else {
			ovb_check( $tag . 'zip: the shrunk and the grown file are stored as they are now', 'abc' === $shrunk && '0123456789ABCDEFGHIJ' === $grown, $shrunk . ' / ' . $grown );
		}
		$warn_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}logs WHERE backup_id = %d AND level = 'warning'", $job ) );
		ovb_check( $tag . 'the changes are logged as warnings', $warn_count >= 2, "warnings $warn_count" );
		$meta_arc = null !== $res ? $res->state()->get_meta() : array();
		ovb_check( $tag . 'meta archive counts one skipped file and the warnings', isset( $meta_arc['archive'] ) && $meta_arc['archive']['files'] >= 4 && $meta_arc['archive']['skipped'] >= 1 && $meta_arc['archive']['warnings'] >= 2, wp_json_encode( isset( $meta_arc['archive'] ) ? $meta_arc['archive'] : null ) );
		ovb_arc_rm( $extract );
		ovb_arc_rm( $folder );
		CleanupStep::remove_tree( $work );
	}

	// 14e. Direct archiver check of the ZIP slice recovery with real files (a file that vanishes after it was queued).
	if ( class_exists( 'ZipArchive' ) ) {
		$zdir = $fx . '/zip-direct';
		ovb_arc_mkdir( $zdir );
		$za = new ZipArchiver( false );
		$za->begin_part( $zdir . '/p.zip' );
		ovb_arc_put( $zdir . '/a.txt', 'one' );
		ovb_arc_put( $zdir . '/doomed.txt', 'two' );
		ovb_arc_put( $zdir . '/c.txt', 'three' );
		$za->add_file( $zdir . '/a.txt', 'a.txt', 3, 0 );
		$za->add_file( $zdir . '/doomed.txt', 'doomed.txt', 3, 0 );
		$za->add_file( $zdir . '/c.txt', 'c.txt', 5, 0 );
		unlink( $zdir . '/doomed.txt' );
		$za->end_slice();
		$za->finish_part();
		$zip_check = new ZipArchive();
		$opened    = true === $zip_check->open( $zdir . '/p.zip', ZipArchive::CHECKCONS );
		$one       = $opened ? $zip_check->getFromName( 'a.txt' ) : false;
		$three     = $opened ? $zip_check->getFromName( 'c.txt' ) : false;
		$doomed    = $opened ? $zip_check->getFromName( 'doomed.txt' ) : false;
		if ( $opened ) {
			$zip_check->close();
		}
		ovb_check( 'zip: a file that vanishes after it was queued does not corrupt the archive or the other files (skipped or empty, plus a warning)', $opened && 'one' === $one && 'three' === $three && ( false === $doomed || '' === $doomed ) && 1 === count( $za->take_warnings() ) );
	}
} catch ( \Throwable $e ) {
	ovb_check( 'file scan and archive: no uncaught exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	remove_filter( 'ovb_engine_steps', $ovb_arc_hook );
	update_option( 'ovb_settings', $ovb_arc_settings );
	if ( null !== $ovb_arc_fixture ) {
		ovb_arc_rm( $ovb_arc_fixture );
	}
	foreach ( $ovb_arc_dirs as $arc_dir ) {
		ovb_arc_rm( $arc_dir );
	}
	foreach ( $ovb_arc_ids as $cleanup_id ) {
		ovb_clear_pending( $cleanup_id );
		$wpdb->delete( $ovb_prefix . 'backup_files', array( 'backup_id' => $cleanup_id ) );
		$wpdb->delete( $ovb_prefix . 'logs', array( 'backup_id' => $cleanup_id ) );
		$wpdb->delete( $ovb_prefix . 'backups', array( 'id' => $cleanup_id ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'ovb_lock_' . $cleanup_id ) );
	}
	foreach ( glob( Paths::tmp_dir() . '/*', GLOB_ONLYDIR ) ?: array() as $leftover_dir ) {
		if ( Manifest::is_valid_uuid( basename( $leftover_dir ) ) || 0 === strpos( basename( $leftover_dir ), 'ovb-fixture-' ) ) {
			CleanupStep::remove_tree( $leftover_dir );
		}
	}
}
$arc_leftovers = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ovb_prefix}backups WHERE note LIKE %s", $wpdb->esc_like( $ovb_marker ) . '%' ) )
	+ count( glob( Paths::tmp_dir() . '/*', GLOB_ONLYDIR ) ?: array() )
	+ count( glob( Paths::backups_dir() . '/*', GLOB_ONLYDIR ) ?: array() )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'ovb\\_lock\\_%'" );
ovb_check( 'file scan and archive: fixture, backup folders, work folders, rows and locks cleaned up', 0 === $arc_leftovers, "leftovers: $arc_leftovers" );

// 11. Plugin did not leave an error behind.
ovb_check( 'no database error', '' === $wpdb->last_error, $wpdb->last_error );

echo "\n" . ( $GLOBALS['ovb_matrix_failures'] ? "RESULT: {$GLOBALS['ovb_matrix_failures']} FAILED\n" : "RESULT: ALL PASSED\n" );
exit( $GLOBALS['ovb_matrix_failures'] ? 1 : 0 );
