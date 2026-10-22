<?php
/**
 * Protects the plugin during a restore (see docs/plan/08-restore.md, "Self-preservation").
 *
 * Phase: MVP
 *
 * Purpose: A database import replaces the options table, and with it the plugin's own settings, the job locks and the list of active
 * plugins. The plugin's tables ({prefix}ovb_*) are never dropped (the dump excludes them and DumpValidator refuses them), so the job
 * record and the history survive by themselves; this class keeps what lives in the options table.
 *
 * What is kept:
 *  - every option row whose name starts with "ovb_" (ovb_settings, ovb_local_dir, ovb_db_version, ovb_installed_at, the job locks
 *    ovb_lock_*, throttles such as ovb_recovery_checked, ...), and the Action Scheduler state rows ("action_scheduler_*",
 *    "schema-ActionScheduler_*") because the Action Scheduler tables are not imported either;
 *  - this plugin's entry in the "active_plugins" option (added when the restored list lacks it; the other plugins of the backup
 *    stay as they are). Network-wide activation on multisite is out of scope (a multisite restore is refused);
 *  - optionally the current "siteurl" and "home" (job option keep_site_urls): a safety valve that keeps the site reachable on its
 *    current address. It is not a migration: serialized data in the dump still carries the old address.
 *  - the login of the user who started the restore, as plain metadata for the result page (a session cannot be kept: the users
 *    table is replaced).
 *
 * How: capture() reads the live rows into a JSON file in the restore work folder (values base64 encoded, so binary-safe), NOT in the
 * database that is being replaced. The importer calls capture() again immediately before the options table is swapped in (so the
 * running job's lock row is current) and apply() immediately after it. apply() deletes any plugin rows that came from the backup,
 * writes the kept rows, makes sure the plugin is active, re-applies the kept URLs, resets the rewrite rules, flushes the object
 * cache and verifies by reading everything back from the database; a mismatch throws. verify() is the same read-back for the end of
 * the job (the lock rows and ovb_recovery_checked are compared by existence only: they change while the job runs).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Self-preservation.
 */
class SelfPreserver {

	const FILE      = 'self-preserve.json';
	const MAX_BYTES = 4194304;

	/**
	 * Option name prefixes whose rows are kept (compared case-sensitively).
	 */
	const KEEP_PREFIXES = array( 'ovb_', 'action_scheduler_', 'schema-ActionScheduler_' );

	/**
	 * Options that change while the job runs: only their existence is verified.
	 */
	const VOLATILE_PREFIXES = array( 'ovb_lock_', 'ovb_recovery_checked', 'action_scheduler_lock_' );

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db Database (defaults to $wpdb).
	 */
	public function __construct( $db = null ) {
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->db = $db;
	}

	/**
	 * Whether an option row is kept across a restore. Pure.
	 *
	 * @param mixed $name Option name.
	 * @return bool
	 */
	public static function is_preserved_option( $name ) {
		if ( ! is_string( $name ) ) {
			return false;
		}
		foreach ( self::KEEP_PREFIXES as $prefix ) {
			if ( 0 === strncmp( $name, $prefix, strlen( $prefix ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether only the existence of an option is verified (it changes while the job runs). Pure.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_volatile_option( $name ) {
		foreach ( self::VOLATILE_PREFIXES as $prefix ) {
			if ( 0 === strncmp( (string) $name, $prefix, strlen( $prefix ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keep only the rows that are preserved, in a normalised shape. Pure.
	 *
	 * @param array<int,mixed> $rows Rows with option_name, option_value and autoload.
	 * @return array<int,array{n:string,v:string,a:string}>
	 */
	public static function select_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$row = (array) $row;
			if ( ! isset( $row['option_name'], $row['option_value'] ) || ! self::is_preserved_option( $row['option_name'] ) ) {
				continue;
			}
			$out[] = array(
				'n' => (string) $row['option_name'],
				'v' => (string) $row['option_value'],
				'a' => isset( $row['autoload'] ) && 1 === preg_match( '/^[a-z-]{1,20}\z/', (string) $row['autoload'] ) ? (string) $row['autoload'] : 'yes',
			);
		}
		return $out;
	}

	/**
	 * The list of active plugins with this plugin in it (the other entries unchanged and in order). Pure.
	 *
	 * @param mixed  $active   Value of the option (anything that is not an array counts as an empty list).
	 * @param string $basename This plugin, for example "openvault-backup/openvault-backup.php".
	 * @return string[]
	 */
	public static function ensure_plugin_active( $active, $basename ) {
		$list = array();
		foreach ( is_array( $active ) ? $active : array() as $entry ) {
			if ( is_string( $entry ) && '' !== $entry ) {
				$list[] = $entry;
			}
		}
		if ( ! in_array( $basename, $list, true ) ) {
			$list[] = $basename;
		}
		return $list;
	}

	/**
	 * JSON of a capture. Pure.
	 *
	 * @param array<string,mixed> $data Capture.
	 * @return string
	 */
	public static function encode( array $data ) {
		$rows = array();
		foreach ( isset( $data['options'] ) && is_array( $data['options'] ) ? $data['options'] : array() as $row ) {
			$rows[] = array(
				'n' => (string) $row['n'],
				'v' => base64_encode( (string) $row['v'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage of option values in a private JSON file.
				'a' => (string) $row['a'],
			);
		}
		$urls = null;
		if ( isset( $data['urls'] ) && is_array( $data['urls'] ) ) {
			$urls = array();
			foreach ( $data['urls'] as $name => $value ) {
				$urls[ (string) $name ] = base64_encode( (string) $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage in a private JSON file.
			}
		}
		$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Works without WordPress loaded (unit tests).

			array(
				'version' => 1,
				'plugin'  => (string) ( isset( $data['plugin'] ) ? $data['plugin'] : '' ),
				'user'    => (string) ( isset( $data['user'] ) ? $data['user'] : '' ),
				'at'      => (int) ( isset( $data['at'] ) ? $data['at'] : 0 ),
				'options' => $rows,
				'urls'    => $urls,
			)
		);
		return is_string( $json ) ? $json : '';
	}

	/**
	 * Read a capture back. Pure.
	 *
	 * @param mixed $json JSON text.
	 * @return array{plugin:string,user:string,at:int,options:array<int,array{n:string,v:string,a:string}>,urls:array<string,string>|null}|null Null when invalid.
	 */
	public static function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['version'], $data['plugin'], $data['options'] ) || 1 !== $data['version'] || ! is_array( $data['options'] ) ) {
			return null;
		}

		$rows = array();
		foreach ( $data['options'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['n'], $row['v'], $row['a'] ) || ! self::is_preserved_option( $row['n'] ) || ! is_string( $row['v'] ) ) {
				return null;
			}
			$value = base64_decode( $row['v'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reads the private JSON file back.
			if ( false === $value ) {
				return null;
			}
			$rows[] = array(
				'n' => (string) $row['n'],
				'v' => $value,
				'a' => is_string( $row['a'] ) && 1 === preg_match( '/^[a-z-]{1,20}\z/', $row['a'] ) ? $row['a'] : 'yes',
			);
		}

		$urls = null;
		if ( isset( $data['urls'] ) && is_array( $data['urls'] ) ) {
			$urls = array();
			foreach ( array( 'siteurl', 'home' ) as $name ) {
				if ( isset( $data['urls'][ $name ] ) && is_string( $data['urls'][ $name ] ) ) {
					$value = base64_decode( $data['urls'][ $name ], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reads the private JSON file back.
					if ( false === $value ) {
						return null;
					}
					$urls[ $name ] = $value;
				}
			}
		}

		return array(
			'plugin'  => (string) $data['plugin'],
			'user'    => isset( $data['user'] ) ? (string) $data['user'] : '',
			'at'      => isset( $data['at'] ) ? (int) $data['at'] : 0,
			'options' => $rows,
			'urls'    => $urls,
		);
	}

	/**
	 * Path of the capture file in a work folder.
	 *
	 * @param string $dir Work folder.
	 * @return string
	 */
	public static function path( $dir ) {
		return rtrim( str_replace( '\\', '/', (string) $dir ), '/' ) . '/' . self::FILE;
	}

	/**
	 * Whether a capture file exists.
	 *
	 * @param string $dir Work folder.
	 * @return bool
	 */
	public function has_capture( $dir ) {
		return is_file( self::path( $dir ) );
	}

	/**
	 * Read the live rows and write them to the capture file (replacing an earlier capture).
	 *
	 * @param string $dir       Work folder.
	 * @param bool   $keep_urls Keep the current siteurl and home as well.
	 * @param string $user      Login of the user who started the restore (metadata).
	 * @return array{options:int}
	 * @throws \RuntimeException When the rows cannot be read or the file cannot be written.
	 */
	public function capture( $dir, $keep_urls = false, $user = '' ) {
		$table = $this->db->options;
		$likes = array();
		foreach ( self::KEEP_PREFIXES as $prefix ) {
			$likes[] = $this->db->prepare( 'option_name LIKE %s', $this->db->esc_like( $prefix ) . '%' );
		}
		$sql  = $this->db->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE ', $table ) . implode( ' OR ', $likes ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every part is built with prepare().
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Reads the live rows; built with prepare().
		if ( ! is_array( $rows ) || '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'The plugin settings could not be read before the restore.' );
		}

		$data = array(
			'plugin'  => plugin_basename( OVB_FILE ),
			'user'    => (string) $user,
			'at'      => time(),
			'options' => self::select_rows( $rows ),
			'urls'    => null,
		);
		if ( $keep_urls ) {
			$data['urls'] = array();
			foreach ( array( 'siteurl', 'home' ) as $name ) {
				$value = $this->read_option( $name );
				if ( null === $value ) {
					throw new \RuntimeException( 'The current site address could not be read.' );
				}
				$data['urls'][ $name ] = $value;
			}
		}

		$json = self::encode( $data );
		if ( '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			throw new \RuntimeException( 'The plugin settings are too large to be kept.' );
		}
		$path = self::path( $dir );
		$tmp  = $path . '.tmp';
		if ( false === file_put_contents( $tmp, $json ) || ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.rename_rename -- Small private file in the restore work folder, replaced atomically.
			throw new \RuntimeException( 'The plugin settings could not be saved before the restore.' );
		}
		return array( 'options' => count( $data['options'] ) );
	}

	/**
	 * Read a capture file.
	 *
	 * @param string $dir Work folder.
	 * @return array{plugin:string,user:string,at:int,options:array<int,array{n:string,v:string,a:string}>,urls:array<string,string>|null}
	 * @throws \RuntimeException When the file is missing or invalid.
	 */
	public function load( $dir ) {
		$path = self::path( $dir );
		$json = is_file( $path ) && ! is_link( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private file in the restore work folder.
		$data = false === $json ? null : self::decode( $json );
		if ( null === $data ) {
			throw new \RuntimeException( 'The saved plugin settings are missing or damaged, so they cannot be put back.' );
		}
		return $data;
	}

	/**
	 * Write the kept rows back into the (new) options table and make the site work with this plugin.
	 *
	 * @param string $dir Work folder.
	 * @return array{options:int}
	 * @throws \RuntimeException When the capture is missing or a read-back does not match.
	 */
	public function apply( $dir ) {
		$data  = $this->load( $dir );
		$table = $this->db->options;
		wp_cache_flush();

		// Rows that came from the backup first (the dump excludes them, this is for a backup made some other way).
		foreach ( self::KEEP_PREFIXES as $prefix ) {
			$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $table, $this->db->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed prefixes, bound.
		}
		foreach ( $data['options'] as $row ) {
			$this->write_row( $row['n'], $row['v'], $row['a'] );
		}

		$active = $this->read_option( 'active_plugins' );
		$list   = self::ensure_plugin_active( null === $active ? array() : maybe_unserialize( $active ), $data['plugin'] );
		$this->write_row( 'active_plugins', serialize( $list ), 'yes' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Same format as update_option() writes; the options table is edited directly while WordPress is in between two databases.

		if ( is_array( $data['urls'] ) ) {
			foreach ( $data['urls'] as $name => $value ) {
				$this->write_row( $name, $value, 'yes' );
			}
		}

		$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'rewrite_rules' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed name, bound.
		wp_cache_flush();

		$this->verify( $dir, true );
		return array( 'options' => count( $data['options'] ) );
	}

	/**
	 * Read everything back from the database and compare it with the capture.
	 *
	 * @param string $dir    Work folder.
	 * @param bool   $strict Compare the volatile rows by value as well (right after apply()).
	 * @return void
	 * @throws \RuntimeException On the first difference.
	 */
	public function verify( $dir, $strict = false ) {
		$data = $this->load( $dir );

		foreach ( $data['options'] as $row ) {
			$now = $this->read_option( $row['n'] );
			if ( null === $now ) {
				throw new \RuntimeException( 'A plugin setting is missing after the restore.' );
			}
			if ( ( $strict || ! self::is_volatile_option( $row['n'] ) ) && $now !== $row['v'] ) {
				throw new \RuntimeException( 'A plugin setting changed during the restore.' );
			}
		}

		$active = $this->read_option( 'active_plugins' );
		$list   = null === $active ? array() : maybe_unserialize( $active );
		if ( ! is_array( $list ) || ! in_array( $data['plugin'], $list, true ) ) {
			throw new \RuntimeException( 'The plugin is not in the list of active plugins after the restore.' );
		}

		if ( is_array( $data['urls'] ) ) {
			foreach ( $data['urls'] as $name => $value ) {
				if ( $this->read_option( $name ) !== $value ) {
					throw new \RuntimeException( 'The site address was not kept.' );
				}
			}
		}
	}

	/**
	 * Delete the capture file.
	 *
	 * @param string $dir Work folder.
	 * @return void
	 */
	public function remove( $dir ) {
		$path = self::path( $dir );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * One option value straight from the database (never from the cache).
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private function read_option( $name ) {
		$value = $this->db->get_var( $this->db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->db->options, $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the row itself, bound.
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Insert or replace one option row.
	 *
	 * @param string $name     Name.
	 * @param string $value    Raw value.
	 * @param string $autoload Autoload flag.
	 * @return void
	 * @throws \RuntimeException When the write fails.
	 */
	private function write_row( $name, $value, $autoload ) {
		$result = $this->db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The options table is replaced by the import; its rows are written directly.
			$this->db->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)', $this->db->options, $name, $value, $autoload )
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'A plugin setting could not be written back after the restore.' );
		}
	}
}
