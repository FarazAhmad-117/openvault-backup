<?php
/**
 * Database restore checks (Milestone 5, step 5b), included by check-plugin.php inside WordPress.
 *
 * A fixture site (posts and postmeta with serialized data, emoji and NUL, options, a user, a custom table with hostile data, plugin option
 * rows and a lock row) is backed up for real, WRECKED, and restored through RestoreManager / RestoreRunner with a tiny budget for every archive
 * format, then compared table by table with what it was. Also: the safety snapshot (a real backup of the pre-restore database), kills in the
 * middle of the import, tampered dumps, a failing snapshot, cancel rules, kept site URLs, the refusals of unsupported modes. Everything created
 * here is removed again. Uses ovb_check() and the helpers of check-plugin.php.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable

use OpenVault\Backup\Archive\ArchiveReader;
use OpenVault\Backup\Archive\CapabilityDetector;
use OpenVault\Backup\Archive\TarWriter;
use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobLock;
use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Engine\Steps\StepInterface;
use OpenVault\Backup\Restore\DatabaseImporter;
use OpenVault\Backup\Restore\MaintenanceMode;
use OpenVault\Backup\Restore\RestoreManager;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePreflight;
use OpenVault\Backup\Restore\RestoreRunner;
use OpenVault\Backup\Restore\RestoreWorkDir;
use OpenVault\Backup\Restore\SelfPreserver;
use OpenVault\Backup\Restore\Steps\DbRestoreStep;
use OpenVault\Backup\Support\Manifest;
use OpenVault\Backup\Support\Paths;

if ( ! class_exists( 'OvbKillRepo' ) ) {
	/** A repository whose save_state() "dies" at chosen counts during the import: the statement ran, its position was not stored. */
	class OvbKillRepo extends RestoresRepository {
		public $kill_at = array();
		public $count   = 0;
		public $killed  = false;
		public $kills   = 0;
		public function save_state( $id, JobState $state ) {
			$cursor = $state->get_cursor();
			if ( JobState::STEP_DB_RESTORE === $state->get_step() && isset( $cursor['ph'] ) && 'i' === $cursor['ph'] ) {
				++$this->count;
				if ( in_array( $this->count, $this->kill_at, true ) ) {
					$this->killed = true;
					++$this->kills;
					throw new \RuntimeException( 'simulated kill' );
				}
			}
			return parent::save_state( $id, $state );
		}
	}

	/** Runs the real step; when the repository "killed" the process, the result of the dead run is thrown away and the job resumes from what was stored. */
	class OvbKillStep implements StepInterface {
		private $inner;
		private $repo;
		public function __construct( StepInterface $inner, OvbKillRepo $repo ) {
			$this->inner = $inner;
			$this->repo  = $repo;
		}
		public function id() {
			return $this->inner->id();
		}
		public function run( JobContext $context ) {
			$result = $this->inner->run( $context );
			if ( $this->repo->killed ) {
				$this->repo->killed = false;
				return StepResult::wait( 1, $this->repo->load_state( RestoreMeta::restore_id( $context->state()->get_meta() ) ) );
			}
			return $result;
		}
	}

	/** Ends the checks of a multisite network early. */
	class OvbR5Done extends \Exception {
	}

	/** Makes the database export of a safety snapshot fail. */
	class OvbSnapshotFailStep implements StepInterface {
		private $inner;
		public function __construct( StepInterface $inner ) {
			$this->inner = $inner;
		}
		public function id() {
			return $this->inner->id();
		}
		public function run( JobContext $context ) {
			if ( 'pre-restore' === $context->backup()['trigger_type'] ) {
				return StepResult::fail( 'simulated snapshot failure', $context->state() );
			}
			return $this->inner->run( $context );
		}
	}
}

function ovb_r5_backup( $format, $note ) {
	$repo = new BackupsRepository();
	$id   = $repo->create( array( 'note' => $note, 'contents' => array( 'db' => true ), 'format' => $format ) );
	$run  = new JobRunner( null, null, static function () {
		return new Budget( 600, PHP_INT_MAX );
	} );
	$run->start( $id, array() );
	for ( $i = 0; $i < 200; $i++ ) {
		ovb_clear_pending( $id );
		$out = $run->run( $id );
		if ( ! in_array( $out, array( 'continued', 'waiting' ), true ) ) {
			break;
		}
	}
	return $id;
}

function ovb_r5_folder( $backup_id ) {
	$b = ( new BackupsRepository() )->find( $backup_id );
	return Paths::backups_dir() . '/' . Paths::build_backup_folder_name( $b['created_at'], $b['uuid'] );
}

function ovb_r5_dump_text( $backup_id, $format ) {
	$folder = ovb_r5_folder( $backup_id );
	$part   = $folder . '/db.part01.' . ( 'zip' === $format ? 'zip' : 'tar.gz' );
	$tmp    = get_temp_dir() . 'ovb-r5-dump-' . $backup_id . '.sql';
	@unlink( $tmp );
	do {
		$off = isset( $res ) ? $res['offset'] : 0;
		$res = ArchiveReader::extract( 'zip' === $format ? 'zip' : 'tar.gz', $part, 'db/db.sql', $tmp, $off, null );
	} while ( ! $res['done'] );
	$text = file_get_contents( $tmp );
	unlink( $tmp );
	return $text;
}

/** Replace the database part of a backup by one with another dump and keep the manifest and the rows consistent (what an attacker with write access to the bucket does). */
function ovb_r5_repack( $backup_id, $format, $sql ) {
	global $wpdb;
	$folder = ovb_r5_folder( $backup_id );
	$name   = 'db.part01.' . ( 'zip' === $format ? 'zip' : 'tar.gz' );
	$path   = $folder . '/' . $name;
	@unlink( $path );
	if ( 'zip' === $format ) {
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addFromString( 'db/db.sql', $sql );
		$zip->close();
	} else {
		$tar = TarWriter::entry_header( 'db/db.sql', strlen( $sql ), time() ) . $sql . str_repeat( "\0", TarWriter::padding( strlen( $sql ) ) );
		file_put_contents( $path, gzencode( $tar ) . gzencode( TarWriter::end_blocks() ) );
	}
	clearstatcache();

	$manifest_path = $folder . '/manifest.json';
	$data          = json_decode( file_get_contents( $manifest_path ), true );
	foreach ( $data['parts'] as $i => $part ) {
		if ( 'db' === $part['kind'] ) {
			$data['parts'][ $i ]['size']   = filesize( $path );
			$data['parts'][ $i ]['sha256'] = hash_file( 'sha256', $path );
		}
	}
	file_put_contents( $manifest_path, wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
	clearstatcache();
	$table = $wpdb->prefix . 'ovb_backup_files';
	$wpdb->update( $table, array( 'size' => filesize( $path ), 'checksum_sha256' => hash_file( 'sha256', $path ) ), array( 'backup_id' => $backup_id, 'destination_id' => 0, 'file_name' => $name ) );
	$wpdb->update( $table, array( 'size' => filesize( $manifest_path ), 'checksum_sha256' => hash_file( 'sha256', $manifest_path ) ), array( 'backup_id' => $backup_id, 'destination_id' => 0, 'file_name' => 'manifest.json' ) );
}

function ovb_r5_tables() {
	global $wpdb;
	$all = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
	$out = array();
	foreach ( $all as $t ) {
		if ( 0 === strpos( $t, $wpdb->prefix . 'ovb_' ) || 0 === strpos( $t, $wpdb->prefix . 'actionscheduler_' ) || $t === $wpdb->options || $t === $wpdb->prefix . 'ovbrs_extra' ) {
			continue;
		}
		$out[] = $t;
	}
	sort( $out );
	return $out;
}

function ovb_r5_digests( $m ) {
	$out = array();
	foreach ( ovb_r5_tables() as $t ) {
		$out[ $t ] = ovb_table_digest( $m, DB_NAME, $t );
	}
	return $out;
}

function ovb_r5_option_rows() {
	global $wpdb;
	$skip = array( 'cron' => 1, 'active_plugins' => 1, 'rewrite_rules' => 1, 'recently_activated' => 1, 'auto_updater.lock' => 1, 'core_updater.lock' => 1 );
	$rows = array();
	foreach ( (array) $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ) as $r ) {
		if ( isset( $skip[ $r['option_name'] ] ) || preg_match( '/^(ovb_|_transient_|_site_transient_|action_scheduler_|schema-ActionScheduler_)/', $r['option_name'] ) ) {
			continue;
		}
		$rows[ $r['option_name'] ] = md5( $r['option_value'] . '|' . $r['autoload'] );
	}
	return $rows;
}

function ovb_r5_diff( array $a, array $b ) {
	$names = array();
	foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
		if ( ! isset( $a[ $k ] ) || ! isset( $b[ $k ] ) || $a[ $k ] !== $b[ $k ] ) {
			$names[] = $k;
		}
	}
	return $names;
}

function ovb_r5_drive( RestoreRunner $rrunner, $id, $each = null, $max = 6000 ) {
	$repo    = new RestoresRepository();
	$backups = new BackupsRepository();
	$brunner = new JobRunner( null, null, static function () {
		return new Budget( 600, PHP_INT_MAX );
	} );
	$out_all = array();
	for ( $i = 0; $i < $max; $i++ ) {
		as_unschedule_all_actions( RestoreRunner::ACTION, array( (int) $id ), RestoreRunner::GROUP );
		$out       = $rrunner->run( $id );
		$out_all[] = $out;
		$state     = $repo->load_state( $id );
		$sid       = null === $state ? 0 : RestoreMeta::snapshot_id( $state->get_meta() );
		if ( $sid > 0 ) {
			$b = $backups->find( $sid );
			if ( null !== $b && in_array( $b['status'], BackupsRepository::INCOMPLETE, true ) ) {
				ovb_clear_pending( $sid );
				$brunner->run( $sid );
			}
		}
		if ( null !== $each ) {
			call_user_func( $each, $out, $i, $state );
		}
		if ( ! in_array( $out, array( 'continued', 'waiting', 'retry' ), true ) ) {
			break;
		}
	}
	return $out_all;
}

function ovb_r5_cleanup_backup( $backup_id ) {
	global $wpdb;
	$b = ( new BackupsRepository() )->find( $backup_id );
	if ( null === $b ) {
		return;
	}
	try {
		CleanupStep::remove_tree( ovb_r5_folder( $backup_id ) );
	} catch ( \Throwable $e ) {
		unset( $e );
	}
	$wpdb->delete( $wpdb->prefix . 'ovb_backup_files', array( 'backup_id' => $backup_id ) );
	$wpdb->delete( $wpdb->prefix . 'ovb_backups', array( 'id' => $backup_id ) );
	$wpdb->delete( $wpdb->prefix . 'ovb_logs', array( 'backup_id' => $backup_id ) );
	ovb_clear_pending( $backup_id );
}

// A strict manager (default preflight) shows what a user gets: files and full modes are refused with a clear message.
$r5_backups   = array();
$r5_restores  = array();
$r5_errors    = array();
$r5_prev_err  = set_error_handler( static function ( $no, $str, $file ) use ( &$r5_errors ) {
	if ( false !== strpos( (string) $file, 'openvault-backup' ) ) {
		$r5_errors[] = $str . ' @ ' . basename( (string) $file );
		return true;
	}
	return false;
} );
$r5_settings  = get_option( 'ovb_settings' );
$r5_active    = get_option( 'active_plugins' );
$r5_siteurl   = get_option( 'siteurl' );
$r5_home      = get_option( 'home' );
$r5_blogname  = get_option( 'blogname' );
$r5_basename  = plugin_basename( OVB_FILE );
$r5_marker    = 'ovbrs' . strtolower( wp_generate_password( 8, false ) );
$r5_secret    = 'OVBRS_SECRET_ROW_DATA_' . $r5_marker;
$r5_prefix    = $wpdb->prefix;
$r5_post_ids  = array();
$r5_user_ids  = array();
$r5_scratch   = 'ovb_r5_verify';
$r5_mysqli    = null;
$r5_formats   = array();
$r5_manager   = null;
$r5_filters   = array();

try {
	$settings                      = is_array( $r5_settings ) ? $r5_settings : array();
	$settings['min_free_space_mb'] = 0;
	update_option( 'ovb_settings', $settings );

	$detector = new CapabilityDetector();
	foreach ( array( 'zip', 'tar.gz' ) as $f ) {
		if ( $detector->is_available( $f ) ) {
			$r5_formats[] = $f;
		}
	}
	ovb_check( 'restore db: at least one archive format is available', array() !== $r5_formats );
	if ( array() === $r5_formats ) {
		throw new RuntimeException( 'no archive format' );
	}
	$main_format = $r5_formats[0];

	// A real backup to restore from (also used for the refusal checks).
	$first_id     = ovb_r5_backup( $main_format, $r5_marker . ' first' );
	$r5_backups[] = $first_id;
	$first        = ( new BackupsRepository() )->find( $first_id );
	ovb_check( 'restore db: a real database backup exists', 'complete' === $first['status'], $first['status'] );

	$strict = new RestoreManager();
	$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r5_prefix}ovb_restores" );

	// 19a. A multisite network is refused for every mode; on a single site every mode is accepted by the strict (real) preflight.
	$res = $strict->start( $first_id, 'undo', array( 'user_id' => 1 ) );
	ovb_check( 'restore db: "undo" is not a mode a user can start (refused, nothing created)', false === $res['ok'] && 0 === $res['restore_id'] && 'mode' === $res['errors'][0]['code'] );
	if ( is_multisite() ) {
		foreach ( array( 'files', 'full' ) as $mode ) {
			$res = $strict->start( $first_id, $mode, array( 'user_id' => 1 ) );
			ovb_check( "restore db: on a multisite network mode $mode is refused with a clear message and creates nothing", false === $res['ok'] && 0 === $res['restore_id'] && in_array( 'multisite_unsupported', array_column( $res['errors'], 'code' ), true ), wp_json_encode( $res['errors'] ) );
		}
	}
	if ( is_multisite() ) {
		$res = $strict->start( $first_id, 'db', array( 'user_id' => 1 ) );
		ovb_check( 'restore db: on a multisite network a database restore is refused with a clear message', false === $res['ok'] && in_array( 'multisite_unsupported', array_column( $res['errors'], 'code' ), true ) && false !== strpos( implode( ' ', array_column( $res['errors'], 'message' ) ), 'multisite network is not supported yet' ), wp_json_encode( $res['errors'] ) );
		ovb_check( 'restore db: the refusals created no restore rows', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r5_prefix}ovb_restores" ) === $rows_before );
		ovb_check( 'restore db: the wreck-and-restore checks are SKIPPED on multisite (a database restore of a network is refused, asserted above)', true );
		throw new OvbR5Done();
	}
	$res = $strict->start( $first_id, 'files', array( 'user_id' => 1 ) );
	ovb_check( 'restore db: mode files of a database-only backup is refused (no files in it), creates nothing', false === $res['ok'] && 0 === $res['restore_id'] && in_array( 'no_parts', array_column( $res['errors'], 'code' ), true ) && ! in_array( 'mode_unavailable', array_column( $res['errors'], 'code' ), true ), wp_json_encode( $res['errors'] ) );
	foreach ( array( 'db', 'full' ) as $mode ) {
		$res = $strict->start( $first_id, $mode, array( 'user_id' => 1 ) );
		ovb_check( "restore db: mode $mode is accepted by the strict (real) preflight", true === $res['ok'] && $res['restore_id'] > 0, wp_json_encode( $res['errors'] ) );
		if ( $res['restore_id'] > 0 ) {
			$r5_restores[] = $res['restore_id'];
			ovb_check( "restore db: that $mode job can be cancelled again before it was confirmed", $strict->cancel( $res['restore_id'] ) && 'cancelled' === ( new RestoresRepository() )->find( $res['restore_id'] )['status'] );
		}
	}
	ovb_check( 'restore db: the refusals created no restore rows (only the two accepted jobs)', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r5_prefix}ovb_restores" ) === $rows_before + 2 );

	$r5_mysqli = ovb_open_mysqli( $wpdb, DB_NAME );
	if ( null === $r5_mysqli ) {
		throw new RuntimeException( 'no second connection' );
	}
	$err = array();
	ovb_exec( $r5_mysqli, "SET sql_mode=''", $err );
	ovb_exec( $r5_mysqli, "SET time_zone = '+00:00'", $err );

	// Runners: a tiny budget (about two statements per slice) and a generous one.
	$clock       = 0.0;
	$tiny        = static function () use ( &$clock ) {
		return new Budget( 3, PHP_INT_MAX, static function () use ( &$clock ) {
			$clock += 1.0;
			return $clock;
		} );
	};
	$big         = static function () {
		return new Budget( 600, PHP_INT_MAX );
	};
	$rrunner     = new RestoreRunner( null, null, null, $tiny );
	$rmgr        = new RestoreManager( null, null, null, null, $rrunner );
	$frunner     = new RestoreRunner( null, null, null, $big );
	$fmgr        = new RestoreManager( null, null, null, null, $frunner );
	$rrepo       = new RestoresRepository();
	$brepo       = new BackupsRepository();
	$frepo       = new BackupFilesRepository();
	$extra_table = $r5_prefix . 'ovbrs_extra';
	$hostile     = $r5_prefix . 'ovbrs_hostile';
	$decoy       = 'other_ovbrs_decoy';

	// ---------------------------------------------------------------- fixtures
	$build_fixture = static function () use ( $wpdb, $r5_mysqli, $r5_marker, $r5_secret, $hostile, &$r5_post_ids, &$r5_user_ids, $r5_prefix ) {
		$err = array();
		ovb_exec( $r5_mysqli, 'DROP TABLE IF EXISTS ' . ovb_q( $hostile ), $err );
		ovb_exec( $r5_mysqli, 'CREATE TABLE ' . ovb_q( $hostile ) . " (id INT NOT NULL AUTO_INCREMENT, label VARCHAR(100) NOT NULL DEFAULT '', body LONGTEXT NULL, b BLOB NULL, ts TIMESTAMP NULL DEFAULT NULL, n DECIMAL(20,5) NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $err );
		$unit = "Lorem 'ipsum' \"dolor\" \\ sit\r\namet \0 ünï©ode 😀\n";
		$big  = substr( str_repeat( $unit, (int) ceil( 200000 / strlen( $unit ) ) ), 0, 200000 );
		$bytes = '';
		for ( $i = 0; $i < 256; $i++ ) {
			$bytes .= chr( $i );
		}
		$rows = array(
			array( 'unicode 日本語 😀', "Ünïcödé 日本語 العربية 😀🎉", $bytes, '2026-03-04 05:06:07', '12345.67890' ),
			array( "it's \"quoted\"", "back\\slash \\' trailing\\", null, null, null ),
			array( 'nul and ctrl', "a\0b\x1ac\x01\x1f\x7f", "\0\0", '2038-01-19 03:14:07', '-0.00001' ),
			array( 'injection', "'); DROP TABLE x; --\n/* */ ; DELETE FROM wp_options; -- " . $r5_secret, "' OR '1'='1", null, '0.5' ),
			array( 'lookalike', "a;\n-- b\nINSERT INTO t VALUES (1);\n-- ovb-dump-end\nDROP TABLE IF EXISTS `{$r5_prefix}users`;\n", '', null, null ),
			array( 'big', $big, random_bytes( 3000 ), null, null ),
			array( '', '', '', null, null ),
		);
		foreach ( $rows as $r ) {
			ovb_exec( $r5_mysqli, 'INSERT INTO ' . ovb_q( $hostile ) . ' (label, body, b, ts, n) VALUES (' . ovb_x( $r[0] ) . ', ' . ovb_x( $r[1] ) . ', ' . ( null === $r[2] ? 'NULL' : ovb_x( $r[2] ) ) . ', ' . ( null === $r[3] ? 'NULL' : "'" . $r[3] . "'" ) . ', ' . ( null === $r[4] ? 'NULL' : $r[4] ) . ')', $err );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$id = wp_insert_post( array( 'post_title' => $r5_marker . ' post ' . $i . " it's 😀", 'post_content' => "Body $i \"q\" \\ ; -- /* x */ 😀 " . str_repeat( 'x', 500 ), 'post_status' => 'publish' ) );
			$r5_post_ids[] = $id;
			ovb_exec( $r5_mysqli, "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ($id, 'ovbrs_ser', " . ovb_x( serialize( array( 'a' => 1, 'b' => array( 'x' => "it's \0 😀", 'y' => null, 'z' => 1.5 ), 'o' => (object) array( 'k' => 'v' ) ) ) ) . "), ($id, 'ovbrs_nul', " . ovb_x( "a\0b" ) . '), (' . $id . ", 'ovbrs_empty', '')", $err );
		}
		update_option( 'ovbrs_opt_ser', array( 'a' => array( 1, 2, 'it\'s' ), 'b' => 'x' ), false );
		update_option( 'ovbrs_opt_plain', 'plain “value” 😀' );
		ovb_exec( $r5_mysqli, "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('ovbrs_opt_nul', " . ovb_x( "n\0u" ) . ", 'no')", $err );
		$uid = wp_insert_user( array( 'user_login' => $r5_marker . '_user', 'user_pass' => wp_generate_password(), 'user_email' => $r5_marker . '@example.org', 'display_name' => "Díspláy 😀" ) );
		$r5_user_ids[] = $uid;
		$uid2 = wp_insert_user( array( 'user_login' => $r5_marker . '_gone', 'user_pass' => wp_generate_password(), 'user_email' => $r5_marker . '-gone@example.org' ) );
		$r5_user_ids[] = $uid2;
		update_user_meta( $uid, 'ovbrs_meta', array( 'k' => 'v' ) );
		return $err;
	};

	$wreck = static function () use ( $wpdb, $r5_mysqli, $r5_marker, $hostile, $extra_table, $decoy, &$r5_post_ids, &$r5_user_ids, $r5_prefix ) {
		$err = array();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_title LIKE %s AND ID <> %d", $wpdb->esc_like( $r5_marker ) . '%', $r5_post_ids[0] ) );
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'WRECKED CONTENT', 'post_title' => $r5_marker . ' wrecked' ), array( 'ID' => $r5_post_ids[0] ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'ovbrs\\_%'" );
		update_option( 'blogname', 'WRECKED NAME' );
		update_option( 'ovbrs_opt_ser', 'wrecked' );
		delete_option( 'ovbrs_opt_plain' );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'ovbrs_opt_nul'" );
		$wpdb->update( $wpdb->users, array( 'user_email' => 'wrecked@example.org' ), array( 'ID' => $r5_user_ids[0] ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE ID = %d", $r5_user_ids[1] ) );
		ovb_exec( $r5_mysqli, 'DROP TABLE IF EXISTS ' . ovb_q( $hostile ), $err );
		ovb_exec( $r5_mysqli, 'CREATE TABLE IF NOT EXISTS ' . ovb_q( $extra_table ) . ' (id INT NOT NULL, v VARCHAR(20) NULL, PRIMARY KEY (id)) ENGINE=InnoDB', $err );
		ovb_exec( $r5_mysqli, 'INSERT IGNORE INTO ' . ovb_q( $extra_table ) . " VALUES (1, 'made after backup')", $err );
		ovb_exec( $r5_mysqli, 'CREATE TABLE IF NOT EXISTS ' . ovb_q( $decoy ) . ' (id INT NOT NULL) ENGINE=InnoDB', $err );
		update_option( 'rewrite_rules', array( 'wrecked' => 'rules' ) );
		wp_cache_flush();
		return $err;
	};

	$build_fixture();
	$fixture_errors = array();
	update_option( 'ovb_rs_fixture', 'at-backup-time', false );
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", 'ovb_lock_9999991', '1700000000:aaaaaaaaaaaaaaaa' ) );
	ovb_check( 'restore db: the fixture site was created (posts, postmeta, options, users, a hostile custom table)', 3 === count( array_filter( $r5_post_ids ) ) && 2 === count( array_filter( $r5_user_ids ) ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$hostile}`" ) === 7 );

	$fake_plugin = 'ovbrs-fake/fake.php';
	$wreck_active = array_values( array_unique( array_merge( array_diff( (array) $r5_active, array( $r5_basename ) ), array( $fake_plugin ) ) ) );

	// ---------------------------------------------------------------- 19b. wreck-and-restore for every format
	foreach ( $r5_formats as $fmt ) {
		$tag = "restore db ($fmt):";

		// The backup is taken with this plugin missing from the active plugins list on purpose.
		update_option( 'active_plugins', $wreck_active );
		wp_cache_flush();
		$digests_before = ovb_r5_digests( $r5_mysqli );
		$options_before = ovb_r5_option_rows();
		$bid            = ovb_r5_backup( $fmt, $r5_marker . ' source ' . $fmt );
		$r5_backups[]   = $bid;
		$brow           = $brepo->find( $bid );
		ovb_check( "$tag the source backup is complete in the requested format", 'complete' === $brow['status'] && $fmt === $brow['format'], $brow['status'] . ' ' . $brow['format'] );
		$dump = ovb_r5_dump_text( $bid, $fmt );
		ovb_check( "$tag the dump has the end marker, the prefix header, no ovb_ table and no plugin option rows", false !== strpos( $dump, "\n-- ovb-dump-end\n" ) && false !== strpos( $dump, "\n-- table-prefix: {$r5_prefix}\n" ) && false === strpos( $dump, '`' . $r5_prefix . 'ovb_' ) && false === strpos( $dump, "'ovb_rs_fixture'" ) );
		unset( $dump );

		// WRECK. The plugin is active again; the settings changed after the backup; a table and a post were created after it.
		update_option( 'active_plugins', array_values( array_unique( array_merge( (array) $r5_active, array( $r5_basename ) ) ) ) );
		$wreck_err = $wreck();
		update_option( 'ovb_rs_fixture', 'wrecked-value', false );
		$cur_settings                       = get_option( 'ovb_settings' );
		$cur_settings['log_retention_days'] = 17;
		update_option( 'ovb_settings', $cur_settings );
		$lock_before = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'ovb_lock_9999991'" );
		$local_dir_before = get_option( 'ovb_local_dir' );
		$wrecked_digests = ovb_r5_digests( $r5_mysqli );
		ovb_check( "$tag the site is really wrecked (tables differ from the backup, the custom table is gone)", $wrecked_digests !== $digests_before && null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hostile ) ) && 'WRECKED CONTENT' === $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $r5_post_ids[0] ) ) && array() === $wreck_err, implode( ' | ', $wreck_err ) );

		// Start, verify, confirm.
		$start = $rmgr->start( $bid, 'db', array( 'user_id' => 1 ) );
		ovb_check( "$tag start() accepts the restore", true === $start['ok'] && $start['restore_id'] > 0, wp_json_encode( $start['errors'] ) . wp_json_encode( $start['requires_ack'] ) );
		$rid           = $start['restore_id'];
		$r5_restores[] = $rid;
		$out1          = ovb_r5_drive( $rrunner, $rid );
		$row           = $rrepo->find( $rid );
		ovb_check( "$tag the job parks at verified and has changed nothing so far", 'verified' === end( $out1 ) && 'verified' === $row['status'] && $wrecked_digests === ovb_r5_digests( $r5_mysqli ) && ! ( new MaintenanceMode() )->is_active(), wp_json_encode( array_count_values( $out1 ) ) );
		ovb_check( "$tag a restore that was not confirmed never runs (running a verified job again parks again)", 'verified' === $rrunner->run( $rid ) && 'verified' === $rrepo->find( $rid )['status'] );
		ovb_check( "$tag confirm() on an unknown restore is refused", false === $rmgr->confirm( 2147000001 )['ok'] );

		// Starting a backup through REST is refused while the restore is queued or running; the internal snapshot is not.
		$obs = array( 'import_seen' => 0, 'maintenance' => null, 'cancel_refused' => null, 'refusal_text' => '', 'rest_409' => null, 'flag_file' => null, 'gate_front' => null, 'gate_ovb' => null, 'gate_cron' => null, 'phases' => array(), 'max_cursor' => 0, 'max_state' => 0, 'snapshot_waited' => 0, 'lock_ok' => true, 'swap_seen' => false, 'statuses' => array() );
		$each = static function ( $out, $i, $state ) use ( &$obs, $rmgr, $rid, $wpdb, $r5_prefix, $local_dir_before ) {
			if ( null === $state ) {
				return;
			}
			$cursor = $state->get_cursor();
			$obs['max_cursor'] = max( $obs['max_cursor'], strlen( wp_json_encode( $cursor ) ) );
			$obs['max_state']  = max( $obs['max_state'], strlen( $state->to_json() ) );
			if ( 'snapshot' === $state->get_step() && 'waiting' === $out ) {
				++$obs['snapshot_waited'];
			}
			if ( 'db_restore' === $state->get_step() && isset( $cursor['ph'] ) ) {
				$obs['phases'][ $cursor['ph'] ] = true;
			}
			if ( 'db_restore' === $state->get_step() && isset( $cursor['ph'] ) && 'i' === $cursor['ph'] ) {
				++$obs['import_seen'];
				if ( 1 === $obs['import_seen'] ) {
					$mm                     = new MaintenanceMode();
					$obs['maintenance']     = $mm->is_active();
					$obs['flag_file']       = '' !== MaintenanceMode::flag_path() && is_file( MaintenanceMode::flag_path() );
					$obs['cancel_refused']  = false === $rmgr->cancel( $rid );
					$obs['refusal_text']    = $rmgr->cancel_refusal( $rid );
					$obs['gate_front']      = MaintenanceMode::decide( MaintenanceMode::facts_from_request( array( 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/' ), array(), false, false ) );
					$obs['gate_ovb']        = MaintenanceMode::decide( MaintenanceMode::facts_from_request( array( 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/wp-json/ovb/v1/restores/1' ), array(), false, false ) );
					$obs['gate_cron']       = MaintenanceMode::decide( MaintenanceMode::facts_from_request( array( 'SCRIPT_NAME' => '/wp-cron.php', 'REQUEST_URI' => '/wp-cron.php' ), array(), false, false ) );
					$req                    = new WP_REST_Request( 'POST', '/ovb/v1/backups' );
					$req->set_header( 'content-type', 'application/json' );
					$req->set_body( wp_json_encode( array( 'contents' => array( 'db' => true ) ) ) );
					$obs['rest_409']        = rest_do_request( $req )->get_status();
				}
				if ( get_option( 'ovb_local_dir' ) !== $local_dir_before || 'aborted' === $out ) {
					$obs['lock_ok'] = false;
				}
			}
		};
		$confirm = $rmgr->confirm( $rid );
		ovb_check( "$tag confirm() accepts the verified restore and queues it", true === $confirm['ok'] && 'running' === $rrepo->find( $rid )['status'] && RestoreMeta::confirmed( $rrepo->load_state( $rid )->get_meta() ), wp_json_encode( $confirm ) );
		ovb_check( "$tag confirming twice is refused", false === $rmgr->confirm( $rid )['ok'] );
		$rest_while_running = new WP_REST_Request( 'POST', '/ovb/v1/backups' );
		$rest_while_running->set_header( 'content-type', 'application/json' );
		$rest_while_running->set_body( wp_json_encode( array( 'contents' => array( 'db' => true ) ) ) );
		$rest_status = rest_do_request( $rest_while_running )->get_status();
		ovb_check( "$tag a user cannot start a backup while the restore runs (REST 409)", 409 === $rest_status, (string) $rest_status );

		$out2 = ovb_r5_drive( $rrunner, $rid, $each );
		$row  = $rrepo->find( $rid );
		$st   = $rrepo->load_state( $rid );
		ovb_check( sprintf( "$tag the job completes over %d slices (snapshot, unpack, validate, import, finish)", count( $out2 ) ), 'complete' === $row['status'] && 'complete' === end( $out2 ) && count( $out2 ) > 30, wp_json_encode( array_count_values( $out2 ) ) . ' ' . $row['status'] . ' ' . (string) $row['error'] );
		ovb_check( "$tag the validation and the import phases ran as separate steps of the job", isset( $obs['phases']['v'], $obs['phases']['i'] ) && ! in_array( 'aborted', $out2, true ), wp_json_encode( $obs['phases'] ) . ' ' . wp_json_encode( array_count_values( $out2 ) ) );
		ovb_check( "$tag the stored cursor stays tiny (max {$obs['max_cursor']} bytes) and so does the state ({$obs['max_state']} bytes)", $obs['max_cursor'] < 400 && $obs['max_state'] < 3000 );

		// While the import ran.
		ovb_check( "$tag maintenance mode was on during the import, with a flag file in the plugin folder", true === $obs['maintenance'] && true === $obs['flag_file'] );
		ovb_check( "$tag the request gate blocked the front end, let the plugin REST namespace through and ran cron with the event filter", MaintenanceMode::DECISION_BLOCK === $obs['gate_front'] && MaintenanceMode::DECISION_ALLOW === $obs['gate_ovb'] && MaintenanceMode::DECISION_CRON === $obs['gate_cron'], wp_json_encode( array( $obs['gate_front'], $obs['gate_ovb'], $obs['gate_cron'] ) ) );
		ovb_check( "$tag cancel was refused once the import had started, with a message that names Undo restore", true === $obs['cancel_refused'] && false !== strpos( (string) $obs['refusal_text'], 'Undo restore' ), (string) $obs['refusal_text'] );
		ovb_check( "$tag REST backup start stayed refused during the import (409)", 409 === $obs['rest_409'], (string) $obs['rest_409'] );
		ovb_check( "$tag the job never lost its lock to the options swap (no run was aborted) and the local folder option survived every slice", true === $obs['lock_ok'] );

		// Snapshot.
		$snap_id = (int) $row['snapshot_backup_id'];
		$snap    = $brepo->find( $snap_id );
		if ( $snap_id > 0 ) {
			$r5_backups[] = $snap_id;
		}
		ovb_check( "$tag the safety snapshot exists, is complete, locked and has the pre-restore trigger", null !== $snap && 'complete' === $snap['status'] && true === $snap['locked'] && 'pre-restore' === $snap['trigger_type'] && array( 'db' ) === array_keys( array_filter( $snap['contents'] ) ) && false !== strpos( $snap['note'], '#' . $rid ), wp_json_encode( $snap ) );
		ovb_check( "$tag the snapshot is a normal backup (listed by the repository, its files are on disk) and is labelled clearly", null !== $snap && is_file( ovb_r5_folder( $snap_id ) . '/manifest.json' ) && 'Safety snapshot before restore' === OpenVault\Backup\Admin\BackupRowPresenter::trigger_label( $snap['trigger_type'] ) );
		ovb_check( "$tag the snapshot cannot be deleted while it is locked", false === $brepo->delete( $snap_id ) && null !== $brepo->find( $snap_id ) );

		// Result.
		$after_digests = ovb_r5_digests( $r5_mysqli );
		$bad_tables    = ovb_r5_diff( $digests_before, $after_digests );
		ovb_check( "$tag every table is identical to what it was at backup time", array() === $bad_tables && count( $digests_before ) > 8, implode( ',', $bad_tables ) );
		$bad_options = ovb_r5_diff( $options_before, ovb_r5_option_rows() );
		ovb_check( "$tag the options table is identical too (apart from the plugin's own rows, transients and the active plugins list)", array() === $bad_options, implode( ',', $bad_options ) );
		ovb_check( "$tag the wrecked posts are back (three, with their titles)", 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s", $wpdb->esc_like( $r5_marker ) . ' post %' ) ) );
		ovb_check( "$tag the wrecked postmeta is back, serialized data included", 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'ovbrs_ser'" ) && "a\0b" === $wpdb->get_var( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'ovbrs_nul' LIMIT 1" ) );
		ovb_check( "$tag the wrecked options are back (serialized, plain with unicode, blogname)", array( 'a' => array( 1, 2, "it's" ), 'b' => 'x' ) === get_option( 'ovbrs_opt_ser' ) && 'plain “value” 😀' === get_option( 'ovbrs_opt_plain' ) && 'WRECKED NAME' !== get_option( 'blogname' ), wp_json_encode( array( get_option( 'ovbrs_opt_ser' ), get_option( 'ovbrs_opt_plain' ), get_option( 'blogname' ), $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'ovbrs\_opt%'" ), isset( wp_cache_get( 'notoptions', 'options' )['ovbrs_opt_ser'] ), isset( wp_cache_get( 'alloptions', 'options' )['blogname'] ) ? wp_cache_get( 'alloptions', 'options' )['blogname'] : null ) ) );
		$user_a = get_userdata( $r5_user_ids[0] );
		$user_b = get_userdata( $r5_user_ids[1] );
		ovb_check( "$tag the changed user and the deleted user are back", $user_a && $r5_marker . '@example.org' === $user_a->user_email && false !== $user_b, wp_json_encode( array( $r5_user_ids, $user_a ? $user_a->user_email : null, false !== $user_b ) ) );
		ovb_check( "$tag the custom table is back with its hostile data byte for byte", (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$hostile}`" ) === 7 && $digests_before[ $hostile ] === $after_digests[ $hostile ], isset( $after_digests[ $hostile ] ) ? $after_digests[ $hostile ] : 'missing' );
		ovb_check( "$tag a table that did not exist in the backup is left alone", 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$extra_table}`" ) );

		// Self-preservation.
		$restored_settings = get_option( 'ovb_settings' );
		ovb_check( "$tag the plugin's own option rows are intact: the fixture, the lock row, the settings changed after the backup, the folder name and the schema version", 'wrecked-value' === get_option( 'ovb_rs_fixture' ) && $lock_before === $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'ovb_lock_9999991'" ) && is_array( $restored_settings ) && 17 === (int) $restored_settings['log_retention_days'] && get_option( 'ovb_local_dir' ) === $local_dir_before && (string) get_option( 'ovb_db_version' ) === OVB_DB_VERSION );
		$active_now = get_option( 'active_plugins' );
		ovb_check( "$tag this plugin is still active although the backup's active plugins list lacked it, and the backup's other plugins stay", is_array( $active_now ) && in_array( $r5_basename, $active_now, true ) && in_array( $fake_plugin, $active_now, true ), wp_json_encode( $active_now ) );
		ovb_check( "$tag the restore job's own row and the plugin tables survived (job complete, history and logs intact)", 'complete' === $rrepo->find( $rid )['status'] && null !== $brepo->find( $bid ) && null !== $brepo->find( $snap_id ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r5_prefix}ovb_logs" ) > 0 );
		ovb_check( "$tag the Action Scheduler tables are still there (the job's own queue was not replaced)", null !== $wpdb->get_var( "SHOW TABLES LIKE '{$r5_prefix}actionscheduler_actions'" ) );
		ovb_check( "$tag maintenance mode is off, the flag file is gone", ! ( new MaintenanceMode() )->is_active() && ! is_file( MaintenanceMode::flag_path() ) );
		ovb_check( "$tag the permalink rules were reset (rewrite_rules deleted) and the object cache holds no stale copy", null === $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'rewrite_rules'" ) && false === get_option( 'rewrite_rules' ) );
		ovb_check( "$tag the lock is released, nothing is queued, the work folder is gone", null === ovb_rs_lock_value() && 0 === ovb_pending_count( RestoreRunner::ACTION, array( (int) $rid ) ) && ! file_exists( ovb_rs_work( $rid ) ) );
		$state_json = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$r5_prefix}ovb_restores WHERE id = %d", $rid ) );
		$logs_blob  = implode( "\n", (array) $wpdb->get_col( "SELECT CONCAT(message, ' ', COALESCE(context, '')) FROM {$r5_prefix}ovb_logs" ) );
		ovb_check( "$tag no row data is in the restore state, the restore row or the logs", false === strpos( $state_json . $logs_blob . wp_json_encode( $row ), $r5_secret ) && false === strpos( $state_json, 'INSERT INTO' ) );
		$result_meta = $st->get_meta();
		ovb_check( "$tag the result summary is in the job meta (tables, rows, duration, snapshot id)", isset( $result_meta['result'] ) && $result_meta['result']['tables'] >= 8 && $result_meta['result']['rows'] > 20 && $result_meta['result']['snapshot_id'] === $snap_id && $result_meta['result']['seconds'] >= 0 && $result_meta['result']['skipped'] >= 1, wp_json_encode( isset( $result_meta['result'] ) ? $result_meta['result'] : null ) );

		// The snapshot holds the PRE-restore database: import its dump into a scratch database and compare a few rows.
		$snap_dump = ovb_r5_dump_text( $snap_id, $snap['format'] );
		$scratch   = ovb_open_mysqli( $wpdb, null );
		$can       = null !== $scratch && ovb_exec( $scratch, 'DROP DATABASE IF EXISTS ' . ovb_q( $r5_scratch ), $err ) && ovb_exec( $scratch, 'CREATE DATABASE ' . ovb_q( $r5_scratch ) . ' CHARACTER SET utf8mb4', $err ) && $scratch->select_db( $r5_scratch );
		if ( ! $can ) {
			ovb_check( "$tag snapshot content check SKIPPED (CREATE DATABASE not permitted)", true );
		} else {
			$bad = 0;
			foreach ( explode( "\n", $snap_dump ) as $line ) {
				if ( '' === $line || 0 === strpos( $line, '-- ' ) ) {
					continue;
				}
				if ( ! $scratch->query( substr( $line, 0, -1 ) ) ) {
					++$bad;
				}
			}
			$wrecked_post = $scratch->query( "SELECT post_content FROM `{$r5_prefix}posts` WHERE ID = " . (int) $r5_post_ids[0] );
			$wrecked_user = $scratch->query( "SELECT user_email FROM `{$r5_prefix}users` WHERE ID = " . (int) $r5_user_ids[0] );
			$extra_there  = $scratch->query( "SELECT v FROM `{$extra_table}`" );
			$hostile_gone = $scratch->query( "SELECT COUNT(*) FROM `{$hostile}`" );
			$post_text = $wrecked_post ? $wrecked_post->fetch_row()[0] : null;
			$user_mail = $wrecked_user ? $wrecked_user->fetch_row()[0] : null;
			$extra_txt = $extra_there ? $extra_there->fetch_row()[0] : null;
			ovb_check( "$tag the snapshot dump imports without error into a scratch database", 0 === $bad, "bad=$bad" );
			ovb_check( "$tag the snapshot holds the wrecked content and the wrecked email (the database as it was just before the restore)", 'WRECKED CONTENT' === $post_text && 'wrecked@example.org' === $user_mail, wp_json_encode( array( $post_text, $user_mail ) ) );
			ovb_check( "$tag the snapshot has the table made after the backup and no custom table", 'made after backup' === $extra_txt && false === $hostile_gone, wp_json_encode( array( $extra_txt, $hostile_gone ) ) );
			$scratch->query( 'DROP DATABASE IF EXISTS ' . ovb_q( $r5_scratch ) );
		}
		unset( $snap_dump );

		// Clean the restored fixture content for the next round: the next format starts from a fresh fixture.
		foreach ( $r5_post_ids as $pid ) {
			wp_delete_post( $pid, true );
		}
		foreach ( $r5_user_ids as $uid ) {
			wp_delete_user( $uid );
		}
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'ovbrs\\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ovbrs\\_%'" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$hostile}`" );
		$r5_post_ids = array();
		$r5_user_ids = array();
		wp_cache_flush();
		update_option( 'active_plugins', array_values( array_unique( array_merge( (array) $r5_active, array( $r5_basename ) ) ) ) );
		if ( $fmt !== end( $r5_formats ) ) {
			$build_fixture();
		}
	}

	// From here on: one fixture, two backups of it (the main format), several scenarios.
	$build_fixture();
	update_option( 'ovb_rs_fixture', 'at-backup-time', false );
	update_option( 'active_plugins', array_values( array_unique( array_merge( (array) $r5_active, array( $r5_basename ) ) ) ) );
	$digests_main = ovb_r5_digests( $r5_mysqli );
	$main_id      = ovb_r5_backup( $main_format, $r5_marker . ' main' );
	$tamper_id    = ovb_r5_backup( $main_format, $r5_marker . ' tamper' );
	$r5_backups[] = $main_id;
	$r5_backups[] = $tamper_id;

	// 19c. Kills in the middle of the import: duplicate-free resume.
	$kill_repo          = new OvbKillRepo();
	$kill_repo->kill_at = array( 10, 37, 71 );
	$kill_filter        = static function ( $steps ) use ( $kill_repo ) {
		$steps['db_restore'] = new OvbKillStep( new DbRestoreStep( null, null, $kill_repo ), $kill_repo );
		return $steps;
	};
	add_filter( 'ovb_restore_steps', $kill_filter );
	$wreck();
	$kstart = $rmgr->start( $main_id, 'db', array( 'user_id' => 1 ) );
	$kid    = $kstart['restore_id'];
	$r5_restores[] = $kid;
	ovb_r5_drive( $rrunner, $kid );
	$rmgr->confirm( $kid );
	$kout = ovb_r5_drive( $rrunner, $kid );
	remove_filter( 'ovb_restore_steps', $kill_filter );
	$krow = $rrepo->find( $kid );
	if ( $krow['snapshot_backup_id'] > 0 ) {
		$r5_backups[] = $krow['snapshot_backup_id'];
	}
	ovb_check( sprintf( 'restore db kill: the import was "killed" %d times mid-way and the job still completed', $kill_repo->kills ), 3 === $kill_repo->kills && 'complete' === $krow['status'], $krow['status'] . ' ' . (string) $krow['error'] . ' ' . wp_json_encode( array_count_values( $kout ) ) . ' kills=' . $kill_repo->kills . ' count=' . $kill_repo->count );
	$bad_tables = ovb_r5_diff( $digests_main, ovb_r5_digests( $r5_mysqli ) );
	ovb_check( 'restore db kill: after the kills every table is identical to the source: no duplicate rows, nothing missing', array() === $bad_tables, implode( ',', $bad_tables ) );
	ovb_check( 'restore db kill: the plugin rows and the maintenance flag are in order afterwards', 'wrecked-value' !== get_option( 'ovb_rs_fixture' ) && ! ( new MaintenanceMode() )->is_active() && null === ovb_rs_lock_value() );

	// 19d. Kept site URLs, and the acknowledgement of a different address.
	update_option( 'siteurl', 'https://moved.example.org' );
	update_option( 'home', 'https://moved.example.org' );
	$wreck();
	$noack = $fmgr->start( $main_id, 'db', array( 'user_id' => 1 ) );
	ovb_check( 'restore db urls: a database backup of another site address needs an acknowledgement and starts nothing', false === $noack['ok'] && 0 === $noack['restore_id'] && array( 'url_mismatch' ) === array_column( $noack['requires_ack'], 'code' ) && array() === $noack['errors'], wp_json_encode( $noack ) );
	foreach ( array( false, true ) as $keep ) {
		update_option( 'siteurl', 'https://moved.example.org' );
		update_option( 'home', 'https://moved.example.org' );
		$u   = $fmgr->start( $main_id, 'db', array( 'user_id' => 1, 'acknowledge_url_mismatch' => true, 'keep_site_urls' => $keep ) );
		$uid = $u['restore_id'];
		$r5_restores[] = $uid;
		ovb_check( 'restore db urls: ' . ( $keep ? 'keep_site_urls' : 'without keep_site_urls' ) . ' starts with the acknowledgement and stores the choice', true === $u['ok'] && $uid > 0 && RestoreMeta::keep_site_urls( $rrepo->load_state( $uid )->get_meta() ) === $keep, wp_json_encode( $u['errors'] ) );
		ovb_r5_drive( $frunner, $uid );
		$fmgr->confirm( $uid );
		ovb_r5_drive( $frunner, $uid );
		$urow = $rrepo->find( $uid );
		if ( $urow['snapshot_backup_id'] > 0 ) {
			$r5_backups[] = $urow['snapshot_backup_id'];
		}
		$site_now = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" );
		$home_now = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'home'" );
		if ( $keep ) {
			ovb_check( 'restore db urls: with keep_site_urls the current address is still in place after the restore (and the result says so)', 'complete' === $urow['status'] && 'https://moved.example.org' === $site_now && 'https://moved.example.org' === $home_now && true === $rrepo->load_state( $uid )->get_meta()['result']['kept_urls'], $urow['status'] . ' ' . (string) $urow['error'] . ' ' . $site_now );
		} else {
			ovb_check( 'restore db urls: without it the address of the backup comes back', 'complete' === $urow['status'] && $r5_siteurl === $site_now, $urow['status'] . ' ' . (string) $urow['error'] . ' ' . $site_now );
		}
	}
	update_option( 'siteurl', $r5_siteurl );
	update_option( 'home', $r5_home );
	$wreck();

	// 19e. Tampered dumps are refused at their line, before any table is touched. The attacker rewrites the part, the manifest and the checksums.
	$pristine     = ovb_r5_dump_text( $tamper_id, $main_format );
	$lines        = explode( "\n", $pristine );
	$users_drop   = array_search( 'DROP TABLE IF EXISTS `' . $r5_prefix . 'users`;', $lines, true );
	$first_users_insert = $users_drop + 2;
	ovb_check( 'restore db tamper: the tamper fixture dump has the expected shape', false !== $users_drop && 0 === strpos( $lines[ $first_users_insert ], 'INSERT INTO `' . $r5_prefix . 'users`' ), 'users_drop=' . var_export( $users_drop, true ) );
	$decoy_ok = null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $decoy ) );
	$evil_probe = static function () use ( $wpdb ) {
		$n = $wpdb->get_var( "SELECT COUNT(*) FROM mysql.user WHERE User = 'ovbevil'" );
		return null === $n ? 0 : (int) $n;
	};
	$cases = array(
		'a stacked statement'  => array( 'insert_at' => null, 'replace' => array( $first_users_insert => rtrim( $lines[ $first_users_insert ], ';' ) . '; DROP TABLE `' . $r5_prefix . 'users`;' ), 'line' => $first_users_insert + 1 ),
		'CREATE USER'          => array( 'insert_at' => $users_drop, 'line_text' => "CREATE USER 'ovbevil'@'%' IDENTIFIED BY 'x';" ),
		'a table outside'      => array( 'insert_at' => $users_drop, 'line_text' => 'DROP TABLE IF EXISTS `' . $decoy . '`;' ),
		'a plugin table'       => array( 'insert_at' => $users_drop, 'line_text' => 'DROP TABLE IF EXISTS `' . $r5_prefix . 'ovb_logs`;' ),
		'INTO OUTFILE'         => array( 'insert_at' => $users_drop, 'line_text' => "SELECT 'x' INTO OUTFILE '/tmp/ovbrs-outfile.txt';" ),
		'SET GLOBAL'           => array( 'insert_at' => $users_drop, 'line_text' => 'SET GLOBAL sql_mode=@@GLOBAL.sql_mode;' ),
		'a comment smuggled in' => array( 'insert_at' => $users_drop, 'line_text' => 'DROP TABLE IF EXISTS `' . $r5_prefix . 'users`; -- x' ),
	);
	$posts_digest_now = ovb_table_digest( $r5_mysqli, DB_NAME, $r5_prefix . 'posts' );
	$users_digest_now = ovb_table_digest( $r5_mysqli, DB_NAME, $r5_prefix . 'users' );
	@unlink( '/tmp/ovbrs-outfile.txt' );
	foreach ( $cases as $label => $case ) {
		$copy = $lines;
		if ( isset( $case['replace'] ) ) {
			foreach ( $case['replace'] as $at => $text ) {
				$copy[ $at ] = $text;
			}
			$hostile_line = $case['line'];
		} else {
			array_splice( $copy, $case['insert_at'], 0, array( $case['line_text'] ) );
			$hostile_line = $case['insert_at'] + 1;
		}
		ovb_r5_repack( $tamper_id, $main_format, implode( "\n", $copy ) );
		$t = $fmgr->start( $tamper_id, 'db', array( 'user_id' => 1 ) );
		ovb_check( "restore db tamper ($label): the tampered backup still passes the checksums (that is the threat)", true === $t['ok'], wp_json_encode( $t['errors'] ) );
		$tid           = $t['restore_id'];
		$r5_restores[] = $tid;
		ovb_r5_drive( $frunner, $tid );
		$fmgr->confirm( $tid );
		ovb_r5_drive( $frunner, $tid );
		$trow = $rrepo->find( $tid );
		if ( $trow['snapshot_backup_id'] > 0 ) {
			$r5_backups[] = $trow['snapshot_backup_id'];
		}
		$tsnap = $brepo->find( (int) $trow['snapshot_backup_id'] );
		ovb_check( "restore db tamper ($label): the restore fails and names the line number", 'failed' === $trow['status'] && false !== strpos( (string) $trow['error'], 'line ' . $hostile_line ), $trow['status'] . ' / ' . (string) $trow['error'] . ' / expected line ' . $hostile_line );
		ovb_check( "restore db tamper ($label): the message says that nothing was changed and does not repeat the statement", false !== strpos( (string) $trow['error'], 'Nothing was changed' ) && false === strpos( (string) $trow['error'], 'ovbevil' ) && false === strpos( (string) $trow['error'], 'OUTFILE' ) && false === strpos( (string) $trow['error'], 'DROP TABLE `' ) );
		ovb_check( "restore db tamper ($label): no table was touched (even the ones in front of the bad line), the decoy and the plugin tables are there", $posts_digest_now === ovb_table_digest( $r5_mysqli, DB_NAME, $r5_prefix . 'posts' ) && $users_digest_now === ovb_table_digest( $r5_mysqli, DB_NAME, $r5_prefix . 'users' ) && ( ! $decoy_ok || null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $decoy ) ) ) && null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $r5_prefix . 'ovb_logs' ) ) && 0 === $evil_probe() && ! file_exists( '/tmp/ovbrs-outfile.txt' ) );
		ovb_check( "restore db tamper ($label): the safety snapshot is kept (complete, locked), maintenance mode never started, no lock or work folder is left", null !== $tsnap && 'complete' === $tsnap['status'] && true === $tsnap['locked'] && ! ( new MaintenanceMode() )->is_active() && null === ovb_rs_lock_value() && ! file_exists( ovb_rs_work( $tid ) ) );
	}
	// Incomplete dump (the end marker is missing).
	ovb_r5_repack( $tamper_id, $main_format, implode( "\n", array_slice( $lines, 0, count( $lines ) - 3 ) ) . "\n" );
	$t = $fmgr->start( $tamper_id, 'db', array( 'user_id' => 1 ) );
	$tid = $t['restore_id'];
	$r5_restores[] = $tid;
	ovb_r5_drive( $frunner, $tid );
	$fmgr->confirm( $tid );
	ovb_r5_drive( $frunner, $tid );
	$trow = $rrepo->find( $tid );
	if ( $trow['snapshot_backup_id'] > 0 ) {
		$r5_backups[] = $trow['snapshot_backup_id'];
	}
	ovb_check( 'restore db tamper (cut dump): a dump without its end marker fails before anything is changed', 'failed' === $trow['status'] && false !== strpos( (string) $trow['error'], 'incomplete' ) && false !== strpos( (string) $trow['error'], 'Nothing was changed' ) && $posts_digest_now === ovb_table_digest( $r5_mysqli, DB_NAME, $r5_prefix . 'posts' ), (string) $trow['error'] );

	// A hostile dump of another prefix.
	ovb_r5_repack( $tamper_id, $main_format, str_replace( "\n-- table-prefix: {$r5_prefix}\n", "\n-- table-prefix: other_\n", $pristine ) );
	$t = $fmgr->start( $tamper_id, 'db', array( 'user_id' => 1 ) );
	$tid = $t['restore_id'];
	$r5_restores[] = $tid;
	ovb_r5_drive( $frunner, $tid );
	$fmgr->confirm( $tid );
	ovb_r5_drive( $frunner, $tid );
	$trow = $rrepo->find( $tid );
	if ( $trow['snapshot_backup_id'] > 0 ) {
		$r5_backups[] = $trow['snapshot_backup_id'];
	}
	ovb_check( 'restore db tamper (prefix header): a dump that says it was made with another table prefix is refused before anything is changed', 'failed' === $trow['status'] && false !== strpos( (string) $trow['error'], 'another table prefix' ) && $posts_digest_now === ovb_table_digest( $r5_mysqli, DB_NAME, $r5_prefix . 'posts' ), (string) $trow['error'] );

	// The same backup, untouched, restores fine (the refusals did not poison anything).
	ovb_r5_repack( $tamper_id, $main_format, $pristine );
	$t = $fmgr->start( $tamper_id, 'db', array( 'user_id' => 1 ) );
	$tid = $t['restore_id'];
	$r5_restores[] = $tid;
	ovb_r5_drive( $frunner, $tid );
	$fmgr->confirm( $tid );
	ovb_r5_drive( $frunner, $tid );
	$trow = $rrepo->find( $tid );
	if ( $trow['snapshot_backup_id'] > 0 ) {
		$r5_backups[] = $trow['snapshot_backup_id'];
	}
	ovb_check( 'restore db tamper: the untampered dump of the same backup restores normally afterwards', 'complete' === $trow['status'] && array() === ovb_r5_diff( $digests_main, ovb_r5_digests( $r5_mysqli ) ), (string) $trow['error'] );

	// 19f. A failing snapshot blocks the restore.
	$wreck();
	$fail_filter = static function ( $steps ) {
		if ( isset( $steps['db_export'] ) ) {
			$steps['db_export'] = new OvbSnapshotFailStep( $steps['db_export'] );
		}
		return $steps;
	};
	add_filter( 'ovb_engine_steps', $fail_filter );
	$posts_wrecked = ovb_table_digest( $r5_mysqli, DB_NAME, $r5_prefix . 'posts' );
	$f             = $fmgr->start( $main_id, 'db', array( 'user_id' => 1 ) );
	$fid           = $f['restore_id'];
	$r5_restores[] = $fid;
	ovb_r5_drive( $frunner, $fid );
	$fmgr->confirm( $fid );
	ovb_r5_drive( $frunner, $fid );
	remove_filter( 'ovb_engine_steps', $fail_filter );
	$frow = $rrepo->find( $fid );
	$fsn  = $brepo->find( (int) $frow['snapshot_backup_id'] );
	if ( $frow['snapshot_backup_id'] > 0 ) {
		$r5_backups[] = $frow['snapshot_backup_id'];
	}
	ovb_check( 'restore db snapshot failure: the restore fails with "snapshot failed ... before anything was changed"', 'failed' === $frow['status'] && false !== strpos( (string) $frow['error'], 'safety snapshot failed' ) && false !== strpos( (string) $frow['error'], 'before anything was changed' ), (string) $frow['error'] );
	ovb_check( 'restore db snapshot failure: the database was not touched, maintenance mode never started, the failed snapshot is not left locked', $posts_wrecked === ovb_table_digest( $r5_mysqli, DB_NAME, $r5_prefix . 'posts' ) && ! ( new MaintenanceMode() )->is_active() && null !== $fsn && 'failed' === $fsn['status'] && false === $fsn['locked'] && 'pre-restore' === $fsn['trigger_type'] );

	// 19g. Cancel: before the confirmation, and while the snapshot is being taken.
	$c = $fmgr->start( $main_id, 'db', array( 'user_id' => 1 ) );
	$cid = $c['restore_id'];
	$r5_restores[] = $cid;
	ovb_r5_drive( $frunner, $cid );
	ovb_check( 'restore db cancel: a verified restore can be cancelled; its work folder is removed', 'verified' === $rrepo->find( $cid )['status'] && true === $fmgr->cancel( $cid ) && 'cancelled' === $rrepo->find( $cid )['status'] && ! file_exists( ovb_rs_work( $cid ) ) );
	$c = $fmgr->start( $main_id, 'db', array( 'user_id' => 1 ) );
	$cid = $c['restore_id'];
	$r5_restores[] = $cid;
	ovb_r5_drive( $frunner, $cid );
	$fmgr->confirm( $cid );
	as_unschedule_all_actions( RestoreRunner::ACTION, array( (int) $cid ), RestoreRunner::GROUP );
	$frunner->run( $cid );
	$csnap = RestoreMeta::snapshot_id( $rrepo->load_state( $cid )->get_meta() );
	if ( $csnap > 0 ) {
		$r5_backups[] = $csnap;
	}
	ovb_check( 'restore db cancel: while the safety snapshot runs, the restore can be cancelled and the snapshot job is stopped with it', $csnap > 0 && in_array( $brepo->find( $csnap )['status'], BackupsRepository::INCOMPLETE, true ) && true === $fmgr->cancel( $cid ) && 'cancelled' === $rrepo->find( $cid )['status'] && 'failed' === $brepo->find( $csnap )['status'], $csnap . ' ' . $rrepo->find( $cid )['status'] );
	ovb_check( 'restore db cancel: nothing was changed by the cancelled jobs', $posts_wrecked === ovb_table_digest( $r5_mysqli, DB_NAME, $r5_prefix . 'posts' ) && ! ( new MaintenanceMode() )->is_active() && null === ovb_rs_lock_value() );

	ovb_check( 'restore db: no PHP warning, notice or deprecation came from the plugin during all of the above', array() === $r5_errors, implode( ' | ', array_slice( $r5_errors, 0, 5 ) ) );
} catch ( OvbR5Done $done ) {
	unset( $done );
} catch ( \Throwable $e ) {
	ovb_check( 'restore db: no exception while running the database restore checks', false, get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	restore_error_handler();
	if ( isset( $kill_filter ) ) {
		remove_filter( 'ovb_restore_steps', $kill_filter );
	}
	if ( isset( $fail_filter ) ) {
		remove_filter( 'ovb_engine_steps', $fail_filter );
	}
	( new MaintenanceMode() )->disable();
	foreach ( array_unique( $r5_restores ) as $cleanup_restore ) {
		as_unschedule_all_actions( RestoreRunner::ACTION, array( (int) $cleanup_restore ), RestoreRunner::GROUP );
		RestoreWorkDir::remove( $cleanup_restore );
	}
	$wpdb->query( "DELETE FROM {$r5_prefix}ovb_restores" );
	foreach ( (array) $wpdb->get_col( "SELECT id FROM {$r5_prefix}ovb_backups WHERE trigger_type = 'pre-restore'" ) as $snap_cleanup ) {
		$r5_backups[] = (int) $snap_cleanup;
	}
	foreach ( array_unique( $r5_backups ) as $cleanup_backup ) {
		ovb_r5_cleanup_backup( $cleanup_backup );
	}
	foreach ( (array) glob( Paths::tmp_dir() . '/restore-[0-9]*' ) as $leftover ) {
		CleanupStep::remove_tree( $leftover );
	}
	foreach ( $r5_post_ids as $pid ) {
		wp_delete_post( $pid, true );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_title LIKE %s", $wpdb->esc_like( $r5_marker ) . '%' ) );
	foreach ( $r5_user_ids as $uid ) {
		wp_delete_user( $uid );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( $r5_marker ) . '%' ) );
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'ovbrs\\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ovbrs\\_%' OR option_name LIKE 'ovb\\_rs\\_%' OR option_name = 'ovb_lock_9999991'" );
	foreach ( array( $r5_prefix . 'ovbrs_hostile', $r5_prefix . 'ovbrs_extra', 'other_ovbrs_decoy' ) as $leftover_table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$leftover_table}`" );
	}
	$wpdb->query( "DROP DATABASE IF EXISTS `{$r5_scratch}`" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ovb\\_lock\\_%'" );
	@unlink( '/tmp/ovbrs-outfile.txt' );
	wp_cache_flush();
	update_option( 'siteurl', $r5_siteurl );
	update_option( 'home', $r5_home );
	update_option( 'blogname', $r5_blogname );
	update_option( 'active_plugins', $r5_active );
	update_option( 'ovb_settings', $r5_settings );
	delete_option( 'rewrite_rules' );
	wp_cache_flush();
	if ( null !== $r5_mysqli ) {
		$r5_mysqli->close();
	}
}

