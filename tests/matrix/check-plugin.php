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

// 10. Plugin did not leave an error behind.
ovb_check( 'no database error', '' === $wpdb->last_error, $wpdb->last_error );

echo "\n" . ( $GLOBALS['ovb_matrix_failures'] ? "RESULT: {$GLOBALS['ovb_matrix_failures']} FAILED\n" : "RESULT: ALL PASSED\n" );
exit( $GLOBALS['ovb_matrix_failures'] ? 1 : 0 );
