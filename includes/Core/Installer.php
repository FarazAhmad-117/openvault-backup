<?php
/**
 * Creates/updates custom tables with dbDelta (see docs/plan/03-database-schema.md).
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Versioned schema migrations
 *  - Store ovb_db_version option
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs schema migrations in version order.
 *
 * To change the schema: add a `migrate_N()` method, register it in migrations(),
 * and bump OVB_DB_VERSION. Never edit an old migration.
 */
class Installer {

	const OPTION = 'ovb_db_version';

	/**
	 * Migrations: version => method name (PHP turns numeric string keys into ints).
	 *
	 * @return array<int|string,string>
	 */
	public static function migrations() {
		return array(
			'1' => 'migrate_1',
			'2' => 'migrate_2',
		);
	}

	/**
	 * Short names of every plugin table (full name is $wpdb->prefix . 'ovb_' . name).
	 *
	 * @return string[]
	 */
	public static function table_names() {
		return array( 'backups', 'backup_files', 'destinations', 'schedules', 'logs', 'restores' );
	}

	/**
	 * Versions newer than the installed one, oldest first.
	 *
	 * @param string                   $installed Installed version ('0' when fresh).
	 * @param array<int|string,string> $all       Version => method map.
	 * @return array<int|string,string>
	 */
	public static function pending( $installed, array $all ) {
		uksort(
			$all,
			static function ( $a, $b ) {
				return version_compare( (string) $a, (string) $b );
			}
		);

		return array_filter(
			$all,
			static function ( $version ) use ( $installed ) {
				return version_compare( (string) $version, (string) $installed, '>' );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Install on activation.
	 *
	 * @return void
	 */
	public static function install() {
		self::maybe_upgrade();
	}

	/**
	 * Run any migrations the stored version has not seen yet.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( self::OPTION, '0' );
		if ( version_compare( $installed, OVB_DB_VERSION, '>=' ) ) {
			return;
		}

		foreach ( self::pending( $installed, self::migrations() ) as $version => $method ) {
			self::$method();
			update_option( self::OPTION, (string) $version );
		}
	}

	/**
	 * Schema v1: all MVP tables.
	 *
	 * The dbDelta function is picky: two spaces after PRIMARY KEY, KEY (not INDEX), no backticks.
	 *
	 * @return void
	 */
	private static function migrate_1() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$p       = $wpdb->prefix . 'ovb_';
		$charset = $wpdb->get_charset_collate();

		$tables = array(
			"CREATE TABLE {$p}backups (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				trigger_type varchar(20) NOT NULL DEFAULT 'manual',
				schedule_id bigint(20) unsigned DEFAULT NULL,
				contents longtext,
				format varchar(20) NOT NULL DEFAULT 'zip',
				encrypted tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'queued',
				state longtext,
				total_size bigint(20) unsigned NOT NULL DEFAULT 0,
				locked tinyint(1) NOT NULL DEFAULT 0,
				note text,
				started_at datetime DEFAULT NULL,
				finished_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY status (status),
				KEY schedule_id (schedule_id)
			) $charset;",
			"CREATE TABLE {$p}backup_files (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				backup_id bigint(20) unsigned NOT NULL,
				destination_id bigint(20) unsigned NOT NULL DEFAULT 0,
				part_number int(10) unsigned NOT NULL DEFAULT 0,
				file_name varchar(255) NOT NULL,
				size bigint(20) unsigned NOT NULL DEFAULT 0,
				checksum_sha256 char(64) NOT NULL DEFAULT '',
				remote_ref text,
				upload_status varchar(20) NOT NULL DEFAULT 'pending',
				upload_session text,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY backup_id (backup_id),
				KEY destination_id (destination_id)
			) $charset;",
			"CREATE TABLE {$p}destinations (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(20) NOT NULL,
				label varchar(190) NOT NULL DEFAULT '',
				settings longtext,
				credentials longtext,
				status varchar(20) NOT NULL DEFAULT 'new',
				last_error text,
				last_tested_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY type (type)
			) $charset;",
			"CREATE TABLE {$p}schedules (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(190) NOT NULL DEFAULT '',
				recurrence varchar(20) NOT NULL DEFAULT 'daily',
				start_time datetime DEFAULT NULL,
				contents longtext,
				exclusions longtext,
				format varchar(20) NOT NULL DEFAULT 'zip',
				destination_ids longtext,
				retention longtext,
				enabled tinyint(1) NOT NULL DEFAULT 1,
				next_run_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY enabled_next (enabled,next_run_at)
			) $charset;",
			"CREATE TABLE {$p}logs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				backup_id bigint(20) unsigned DEFAULT NULL,
				level varchar(10) NOT NULL DEFAULT 'info',
				message text NOT NULL,
				context longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY backup_id (backup_id),
				KEY level (level),
				KEY created_at (created_at)
			) $charset;",
		);

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Schema v2: restore jobs (Milestone 5). A restore replaces the database, and every plugin table survives a database import, so the
	 * job record lives in its own table instead of in the options table.
	 *
	 * @return void
	 */
	private static function migrate_2() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$p       = $wpdb->prefix . 'ovb_';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$p}restores (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				backup_id bigint(20) unsigned NOT NULL,
				mode varchar(10) NOT NULL DEFAULT 'full',
				status varchar(20) NOT NULL DEFAULT 'queued',
				step varchar(20) NOT NULL DEFAULT 'preflight',
				state longtext,
				snapshot_backup_id bigint(20) unsigned DEFAULT NULL,
				error text,
				started_at datetime DEFAULT NULL,
				finished_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY backup_id (backup_id),
				KEY status (status)
			) $charset;"
		);
	}
}
