<?php
/**
 * Checks before a restore starts: pure rules (evaluate) and a thin collector of real facts (run).
 *
 * Phase: MVP
 *
 * Purpose: Refuse, warn or ask for an acknowledgement, each with a precise and actionable message, before anything is
 * downloaded or changed. The result is { ok, errors[], warnings[], requires_ack[] }; every entry is { code, message }.
 *
 * Rules (evaluate()):
 *  - errors (the restore cannot start): the mode cannot be applied by this version, the site is a multisite network (any mode:
 *    "Restoring a multisite network is not supported yet"), the table prefix of the site is longer than 40 characters, the backup could not be read, it was created by a newer version of the plugin,
 *    it is encrypted (Phase 2), another backup or restore is running, the table prefix of a database restore differs from
 *    this site's (not supported yet), the mode asks for something the backup does not contain, no source holds a complete
 *    copy, the local folder is not writable, the destination of a remote source is unreachable or needs a reconnect, or
 *    there is not enough free disk space (download + extract + safety snapshot, see RestorePlan: the files parts x 2 cover the growth of the
 *    site and the undo journal's copies, and a files-only restore takes no snapshot).
 *  - requires_ack: the site URL of a database restore differs from this site's (the restored database carries the old URLs;
 *    migration with search-replace is Phase 3). It blocks until the caller passes the code "url_mismatch" in "ack".
 *  - warnings: WordPress or PHP version drift, a "full" restore of a backup that holds only one kind of data, the free disk
 *    space cannot be determined; for a restore of files: that wp-config.php
 *    (when the backup holds it), the server configuration files of the WordPress folder (when it holds core files, unless the job opts in) and
 *    the plugin's own folder (when it holds plugins) will NOT be restored.
 *  - A files-only restore never touches the database, so the prefix and URL rules do not apply to it.
 *  - ok is true only without errors and without unacknowledged requires_ack entries; requires_ack lists only the
 *    unacknowledged ones.
 *
 * Facts (all optional unless stated; a missing fact means "not checked"):
 *  manifest_error (string), manifest_newer (bool), manifest_plugin_version, plugin_version, encrypted (bool),
 *  manifest_prefix, base_prefix, manifest_site_url, current_site_url, manifest_wp, wp_version, manifest_php, php_version,
 *  mode, restores_db, restores_files, has_parts_for_mode (bool), db_parts, files_parts, chosen_source (int|null),
 *  source_reasons (string), busy ('', 'backup' or 'restore'), local_dir_error, local_dir_writable (bool),
 *  free_bytes (int|null), needed_bytes (int), disk_check (bool), source_remote (bool), source_reachable (bool),
 *  source_error (string), source_needs_reconnect (bool), ack (string[]), modes_available (string[]), multisite (bool),
 *  contents (the backup's contents flags), server_config (bool, the job restores the server configuration files too).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Engine\Steps\PreflightChecks;
use OpenVault\Backup\Support\Paths;
use OpenVault\Backup\Support\SystemInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Restore preflight.
 */
class RestorePreflight {

	const ACK_URL_MISMATCH = 'url_mismatch';

	/**
	 * Modes a user can start (an undo job is created by RestoreManager::undo(), not through the preflight).
	 */
	const AVAILABLE_MODES = array( 'db', 'files', 'full' );

	/**
	 * Backups.
	 *
	 * @var BackupsRepository
	 */
	private $backups;

	/**
	 * Restores.
	 *
	 * @var RestoresRepository
	 */
	private $restores;

	/**
	 * Destination provider factory: function( int $destination_id ): DestinationInterface.
	 *
	 * @var callable
	 */
	private $destinations;

	/**
	 * Constructor.
	 *
	 * @param BackupsRepository|null  $backups      Backups.
	 * @param RestoresRepository|null $restores     Restores.
	 * @param callable|null           $destinations Provider factory (defaults to DestinationFactory::make()).
	 */
	public function __construct( ?BackupsRepository $backups = null, ?RestoresRepository $restores = null, ?callable $destinations = null ) {
		$this->backups      = null === $backups ? new BackupsRepository() : $backups;
		$this->restores     = null === $restores ? new RestoresRepository() : $restores;
		$this->destinations = null === $destinations ? array( new DestinationFactory(), 'make' ) : $destinations;
	}

	/**
	 * Gather the real facts and evaluate them.
	 *
	 * @param array<string,mixed> $loaded     Result of PlanLoader::load(): plan, manifest, error, newer.
	 * @param string[]            $ack        Acknowledged codes (for example "url_mismatch").
	 * @param int                 $own_id     Id of the restore being checked (it is not "another" restore), 0 before it exists.
	 * @param array<string,mixed> $options    Job options: server_config (bool) restore the server configuration files too.
	 * @return array{ok:bool,errors:array<int,array{code:string,message:string}>,warnings:array<int,array{code:string,message:string}>,requires_ack:array<int,array{code:string,message:string}>}
	 */
	public function run( array $loaded, array $ack = array(), $own_id = 0, array $options = array() ) {
		$facts                  = $this->gather( $loaded, $ack, (int) $own_id );
		$facts['server_config'] = ! empty( $options['server_config'] );
		return self::evaluate( $facts );
	}

	/**
	 * Collect the facts from the real site.
	 *
	 * @param array<string,mixed> $loaded Result of PlanLoader::load().
	 * @param string[]            $ack    Acknowledged codes.
	 * @param int                 $own_id Id of this restore.
	 * @return array<string,mixed>
	 */
	public function gather( array $loaded, array $ack, $own_id ) {
		global $wpdb;

		$facts = array(
			'ack'                => $ack,
			'plugin_version'     => defined( 'OVB_VERSION' ) ? OVB_VERSION : '0',
			'base_prefix'        => (string) $wpdb->base_prefix,
			'current_site_url'   => (string) get_option( 'siteurl' ),
			'wp_version'         => (string) get_bloginfo( 'version' ),
			'php_version'        => PHP_VERSION,
			'manifest_error'     => isset( $loaded['error'] ) ? (string) $loaded['error'] : '',
			'manifest_newer'     => ! empty( $loaded['newer'] ),
			'busy'               => $this->busy( $own_id ),
			'modes_available'    => self::AVAILABLE_MODES,
			'multisite'          => is_multisite(),
			'local_dir_error'    => '',
			'local_dir_writable' => true,
		);

		$local_dir = '';
		try {
			$local_dir                   = Paths::local_dir();
			$facts['local_dir_writable'] = wp_is_writable( $local_dir );
		} catch ( \RuntimeException $e ) {
			$facts['local_dir_error'] = $e->getMessage();
		}

		$manifest = isset( $loaded['manifest'] ) ? $loaded['manifest'] : null;
		$plan     = isset( $loaded['plan'] ) ? $loaded['plan'] : null;
		if ( $manifest instanceof \OpenVault\Backup\Support\Manifest ) {
			$facts['manifest_plugin_version'] = $manifest->get_plugin_version();
			$facts['encrypted']               = $manifest->is_encrypted();
			$facts['manifest_prefix']         = $manifest->get_table_prefix();
			$facts['manifest_site_url']       = $manifest->get_site_url();
			$facts['manifest_wp']             = $manifest->get_wp_version();
			$facts['manifest_php']            = $manifest->get_php_version();
		}
		if ( $plan instanceof RestorePlan ) {
			$summary                     = $plan->summary();
			$facts['mode']               = $plan->mode();
			$facts['restores_db']        = $plan->restores_db();
			$facts['restores_files']     = $plan->restores_files();
			$facts['contents']           = $summary['contents'];
			$facts['has_parts_for_mode'] = $plan->has_parts_for_mode();
			$facts['db_parts']           = $summary['db_parts'];
			$facts['files_parts']        = $summary['files_parts'];
			$facts['chosen_source']      = $plan->chosen_source();
			$facts['source_reasons']     = self::describe_sources( $plan );
			$settings                    = get_option( 'ovb_settings', array() );
			$min_free                    = PreflightChecks::min_free_bytes( is_array( $settings ) ? $settings : array() );
			$facts['disk_check']         = $min_free > 0;
			$facts['needed_bytes']       = $plan->required_free_bytes( $min_free );
			$facts['free_bytes']         = self::free_bytes( '' !== $local_dir ? $local_dir : WP_CONTENT_DIR );
			$chosen                      = $plan->chosen_source();
			$facts['source_remote']      = null !== $chosen && RestorePlan::SOURCE_LOCAL !== $chosen;
			if ( $facts['source_remote'] ) {
				$facts = array_merge( $facts, $this->probe( (int) $chosen ) );
			}
		}//end if

		return $facts;
	}

	/**
	 * Apply the rules to a set of facts.
	 *
	 * @param array<string,mixed> $facts Facts (see the class comment).
	 * @return array{ok:bool,errors:array<int,array{code:string,message:string}>,warnings:array<int,array{code:string,message:string}>,requires_ack:array<int,array{code:string,message:string}>}
	 */
	public static function evaluate( array $facts ) {
		$errors   = array();
		$warnings = array();
		$acks     = array();
		$ack      = isset( $facts['ack'] ) && is_array( $facts['ack'] ) ? $facts['ack'] : array();

		self::rule_environment( $facts, $errors );
		self::rule_scope( $facts, $errors );

		if ( ! empty( $facts['manifest_newer'] ) ) {
			$errors[] = self::item( 'newer_version', __( 'This backup was created by a newer version of OpenVault Backup. Update the plugin first, then restore it.', 'openvault-backup' ) );
		} elseif ( isset( $facts['manifest_error'] ) && '' !== $facts['manifest_error'] ) {
			$errors[] = self::item( 'manifest', (string) $facts['manifest_error'] );
		}

		self::rule_manifest( $facts, $errors, $warnings, $acks, $ack );
		self::rule_plan( $facts, $errors, $warnings );
		self::rule_files( $facts, $warnings );
		self::rule_disk( $facts, $errors, $warnings );
		self::rule_source( $facts, $errors );

		return array(
			'ok'           => array() === $errors && array() === $acks,
			'errors'       => $errors,
			'warnings'     => $warnings,
			'requires_ack' => $acks,
		);
	}

	/**
	 * Compare two site URLs: scheme, host (case-insensitive), port and path, ignoring a trailing slash.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool True when they point to the same site address.
	 */
	public static function same_site_url( $a, $b ) {
		return self::normalise_url( $a ) === self::normalise_url( $b );
	}

	/**
	 * Normalised form of a site URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalise_url( $url ) {
		$url   = trim( (string) $url );
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Works without WordPress loaded (unit tests).
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return strtolower( rtrim( $url, '/' ) );
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		$path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port . $path;
	}

	/**
	 * Whether two versions differ in major or minor number ("6.5.2" and "6.5.3" do not).
	 *
	 * @param string $a Version.
	 * @param string $b Version.
	 * @return bool
	 */
	public static function version_drift( $a, $b ) {
		return self::major_minor( $a ) !== self::major_minor( $b );
	}

	/**
	 * First two numbers of a version.
	 *
	 * @param string $version Version.
	 * @return string
	 */
	private static function major_minor( $version ) {
		$numbers = explode( '.', preg_replace( '/[^0-9.].*$/', '', (string) $version ) );
		return ( isset( $numbers[0] ) ? (int) $numbers[0] : 0 ) . '.' . ( isset( $numbers[1] ) ? (int) $numbers[1] : 0 );
	}

	/**
	 * What this version can restore: the mode, multisite, the table prefix length.
	 *
	 * @param array<string,mixed>                          $facts  Facts.
	 * @param array<int,array{code:string,message:string}> $errors Errors (updated).
	 * @return void
	 */
	private static function rule_scope( array $facts, array &$errors ) {
		if ( isset( $facts['mode'], $facts['modes_available'] ) && is_array( $facts['modes_available'] ) && ! in_array( $facts['mode'], $facts['modes_available'], true ) ) {
			$errors[] = self::item( 'mode_unavailable', __( 'This kind of restore is not available in this version of the plugin.', 'openvault-backup' ) );
		}

		$touches_db = ! isset( $facts['restores_db'] ) || ! empty( $facts['restores_db'] );
		if ( ! empty( $facts['multisite'] ) ) {
			$errors[] = self::item( 'multisite_unsupported', __( 'Restoring a multisite network is not supported yet.', 'openvault-backup' ) );
		}
		if ( $touches_db && isset( $facts['base_prefix'] ) && strlen( (string) $facts['base_prefix'] ) > 40 ) {
			$errors[] = self::item( 'prefix_too_long', __( 'The database table prefix of this site is longer than 40 characters, which a restore does not support.', 'openvault-backup' ) );
		}
	}

	/**
	 * Busy, local folder.
	 *
	 * @param array<string,mixed>                          $facts  Facts.
	 * @param array<int,array{code:string,message:string}> $errors Errors (updated).
	 * @return void
	 */
	private static function rule_environment( array $facts, array &$errors ) {
		if ( isset( $facts['busy'] ) && 'backup' === $facts['busy'] ) {
			$errors[] = self::item( 'backup_running', __( 'A backup is running. Wait for it to finish or cancel it, then start the restore.', 'openvault-backup' ) );
		} elseif ( isset( $facts['busy'] ) && 'restore' === $facts['busy'] ) {
			$errors[] = self::item( 'restore_running', __( 'Another restore is already in progress. Finish or cancel it first.', 'openvault-backup' ) );
		}

		if ( isset( $facts['local_dir_error'] ) && '' !== $facts['local_dir_error'] ) {
			$errors[] = self::item( 'local_dir', (string) $facts['local_dir_error'] );
		} elseif ( isset( $facts['local_dir_writable'] ) && ! $facts['local_dir_writable'] ) {
			$errors[] = self::item( 'local_dir_not_writable', __( 'The local backup folder is not writable. Check the permissions of the wp-content folder.', 'openvault-backup' ) );
		}
	}

	/**
	 * Encryption, versions, prefix, site address.
	 *
	 * @param array<string,mixed>                          $facts    Facts.
	 * @param array<int,array{code:string,message:string}> $errors   Errors (updated).
	 * @param array<int,array{code:string,message:string}> $warnings Warnings (updated).
	 * @param array<int,array{code:string,message:string}> $acks     Unacknowledged confirmations (updated).
	 * @param string[]                                     $ack      Acknowledged codes.
	 * @return void
	 */
	private static function rule_manifest( array $facts, array &$errors, array &$warnings, array &$acks, array $ack ) {
		if ( ! empty( $facts['encrypted'] ) ) {
			$errors[] = self::item( 'encrypted', __( 'This backup is encrypted. Restoring encrypted backups is not available yet.', 'openvault-backup' ) );
		}

		if ( isset( $facts['manifest_plugin_version'], $facts['plugin_version'] ) && version_compare( (string) $facts['manifest_plugin_version'], (string) $facts['plugin_version'], '>' ) ) {
			$errors[] = self::item(
				'newer_version',
				sprintf(
					/* translators: 1: plugin version of the backup, 2: installed plugin version. */
					__( 'This backup was created by a newer version of OpenVault Backup (%1$s, installed: %2$s). Update the plugin first, then restore it.', 'openvault-backup' ),
					(string) $facts['manifest_plugin_version'],
					(string) $facts['plugin_version']
				)
			);
		}

		$touches_db = ! isset( $facts['restores_db'] ) || ! empty( $facts['restores_db'] );

		if ( $touches_db && isset( $facts['manifest_prefix'], $facts['base_prefix'] ) && (string) $facts['manifest_prefix'] !== (string) $facts['base_prefix'] ) {
			$errors[] = self::item(
				'prefix_mismatch',
				sprintf(
					/* translators: 1: table prefix of the backup, 2: table prefix of this site. */
					__( 'This backup uses the database table prefix "%1$s", but this site uses "%2$s". Restoring to a different table prefix is not supported yet. Restore the files only, or restore on a site that uses the prefix "%1$s".', 'openvault-backup' ),
					(string) $facts['manifest_prefix'],
					(string) $facts['base_prefix']
				)
			);
		}

		if ( $touches_db && isset( $facts['manifest_site_url'], $facts['current_site_url'] ) && ! self::same_site_url( (string) $facts['manifest_site_url'], (string) $facts['current_site_url'] ) && ! in_array( self::ACK_URL_MISMATCH, $ack, true ) ) {
			$acks[] = self::item(
				self::ACK_URL_MISMATCH,
				sprintf(
					/* translators: 1: site address of the backup, 2: site address of this site. */
					__( 'This backup was made on %1$s, but this site is %2$s. The restored database will contain the old address everywhere (links, images, settings) and the site may stop working. Moving a site to another address with search-replace is not available yet. Continue only if you understand this.', 'openvault-backup' ),
					(string) $facts['manifest_site_url'],
					(string) $facts['current_site_url']
				)
			);
		}

		if ( isset( $facts['manifest_wp'], $facts['wp_version'] ) && self::version_drift( (string) $facts['manifest_wp'], (string) $facts['wp_version'] ) ) {
			$newer      = version_compare( (string) $facts['manifest_wp'], (string) $facts['wp_version'], '>' );
			$warnings[] = self::item(
				'wp_version',
				sprintf(
					/* translators: 1: WordPress version of the backup, 2: installed WordPress version. */
					$newer ? __( 'This backup was made on a newer WordPress (%1$s, installed: %2$s). The restored database may not work with this version. Update WordPress first.', 'openvault-backup' ) : __( 'This backup was made on WordPress %1$s, this site runs %2$s. WordPress may need to update the database after the restore.', 'openvault-backup' ),
					(string) $facts['manifest_wp'],
					(string) $facts['wp_version']
				)
			);
		}

		if ( isset( $facts['manifest_php'], $facts['php_version'] ) && self::version_drift( (string) $facts['manifest_php'], (string) $facts['php_version'] ) ) {
			$warnings[] = self::item(
				'php_version',
				sprintf(
					/* translators: 1: PHP version of the backup, 2: installed PHP version. */
					__( 'This backup was made on PHP %1$s, this server runs PHP %2$s. Check that the restored plugins and themes support it.', 'openvault-backup' ),
					(string) $facts['manifest_php'],
					(string) $facts['php_version']
				)
			);
		}
	}

	/**
	 * What the mode needs.
	 *
	 * @param array<string,mixed>                          $facts    Facts.
	 * @param array<int,array{code:string,message:string}> $errors   Errors (updated).
	 * @param array<int,array{code:string,message:string}> $warnings Warnings (updated).
	 * @return void
	 */
	private static function rule_plan( array $facts, array &$errors, array &$warnings ) {
		if ( isset( $facts['has_parts_for_mode'] ) && ! $facts['has_parts_for_mode'] ) {
			$mode = isset( $facts['mode'] ) ? (string) $facts['mode'] : 'full';
			if ( 'db' === $mode ) {
				$message = __( 'This backup does not contain the database, so a database-only restore is not possible. Choose "files only" or another backup.', 'openvault-backup' );
			} elseif ( 'files' === $mode ) {
				$message = __( 'This backup contains no files, so a files-only restore is not possible. Choose "database only" or another backup.', 'openvault-backup' );
			} else {
				$message = __( 'This backup contains nothing to restore.', 'openvault-backup' );
			}
			$errors[] = self::item( 'no_parts', $message );
		} elseif ( isset( $facts['mode'], $facts['db_parts'], $facts['files_parts'] ) && 'full' === $facts['mode'] ) {
			if ( 0 === (int) $facts['db_parts'] && (int) $facts['files_parts'] > 0 ) {
				$warnings[] = self::item( 'files_only_backup', __( 'This backup contains only files. The database will not be changed.', 'openvault-backup' ) );
			} elseif ( (int) $facts['db_parts'] > 0 && 0 === (int) $facts['files_parts'] ) {
				$warnings[] = self::item( 'db_only_backup', __( 'This backup contains only the database. No files will be changed.', 'openvault-backup' ) );
			}
		}
	}

	/**
	 * What a restore of files will and will not do.
	 *
	 * @param array<string,mixed>                          $facts    Facts.
	 * @param array<int,array{code:string,message:string}> $warnings Warnings (updated).
	 * @return void
	 */
	private static function rule_files( array $facts, array &$warnings ) {
		if ( empty( $facts['restores_files'] ) ) {
			return;
		}
		$contents = isset( $facts['contents'] ) && is_array( $facts['contents'] ) ? $facts['contents'] : array();

		if ( ! empty( $contents['wp_config'] ) ) {
			$warnings[] = self::item( 'wp_config_skipped', __( 'This backup contains wp-config.php. It will NOT be restored: it holds the database credentials and keys of this site, and restoring an old one could lock you out of the site.', 'openvault-backup' ) );
		}
		if ( ! empty( $contents['core'] ) && empty( $facts['server_config'] ) ) {
			$warnings[] = self::item( 'server_config_skipped', __( 'The server configuration files in the WordPress folder (.htaccess, web.config, php.ini, .user.ini) will NOT be restored, so a restore cannot lock you out of the site.', 'openvault-backup' ) );
		}
		if ( ! empty( $contents['plugins'] ) ) {
			$warnings[] = self::item( 'plugin_protected', __( 'The folder of OpenVault Backup inside the plugins folder is never overwritten by a restore.', 'openvault-backup' ) );
		}
	}

	/**
	 * Free disk space.
	 *
	 * @param array<string,mixed>                          $facts    Facts.
	 * @param array<int,array{code:string,message:string}> $errors   Errors (updated).
	 * @param array<int,array{code:string,message:string}> $warnings Warnings (updated).
	 * @return void
	 */
	private static function rule_disk( array $facts, array &$errors, array &$warnings ) {
		if ( ! array_key_exists( 'free_bytes', $facts ) || ( isset( $facts['disk_check'] ) && ! $facts['disk_check'] ) ) {
			return;
		}
		if ( null === $facts['free_bytes'] ) {
			$warnings[] = self::item( 'disk_unknown', __( 'The free disk space cannot be determined on this server. Continuing without that check.', 'openvault-backup' ) );
			return;
		}
		$needed = isset( $facts['needed_bytes'] ) ? (int) $facts['needed_bytes'] : 0;
		if ( $needed > 0 && (int) $facts['free_bytes'] < $needed ) {
			$errors[] = self::item(
				'disk_space',
				sprintf(
					/* translators: 1: free disk space, 2: required disk space. */
					__( 'Not enough free disk space: %1$s free, %2$s needed.', 'openvault-backup' ),
					SystemInfo::format_bytes( (int) $facts['free_bytes'] ),
					SystemInfo::format_bytes( $needed )
				)
			);
		}
	}

	/**
	 * Source availability.
	 *
	 * @param array<string,mixed>                          $facts  Facts.
	 * @param array<int,array{code:string,message:string}> $errors Errors (updated).
	 * @return void
	 */
	private static function rule_source( array $facts, array &$errors ) {
		if ( array_key_exists( 'chosen_source', $facts ) && null === $facts['chosen_source'] ) {
			$detail   = isset( $facts['source_reasons'] ) ? trim( (string) $facts['source_reasons'] ) : '';
			$errors[] = self::item(
				'no_source',
				trim( __( 'No complete copy of this backup is available: the local files and the destinations are missing parts or the manifest.', 'openvault-backup' ) . ' ' . $detail )
			);
		}

		if ( ! empty( $facts['source_remote'] ) ) {
			if ( ! empty( $facts['source_needs_reconnect'] ) ) {
				$errors[] = self::item( 'needs_reconnect', DestinationsRepository::RECONNECT_MESSAGE );
			} elseif ( isset( $facts['source_reachable'] ) && ! $facts['source_reachable'] ) {
				$message  = isset( $facts['source_error'] ) && '' !== $facts['source_error'] ? (string) $facts['source_error'] : __( 'The destination could not be reached.', 'openvault-backup' );
				$errors[] = self::item(
					'source_unreachable',
					sprintf(
						/* translators: %s: error message. */
						__( 'The destination that holds this backup cannot be reached: %s', 'openvault-backup' ),
						$message
					)
				);
			}
		}
	}

	/**
	 * One message.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Message.
	 * @return array{code:string,message:string}
	 */
	private static function item( $code, $message ) {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Short text about why sources are unusable.
	 *
	 * @param RestorePlan $plan Plan.
	 * @return string
	 */
	private static function describe_sources( RestorePlan $plan ) {
		$names = array(
			'manifest_missing' => __( 'manifest.json is missing', 'openvault-backup' ),
			'part_missing'     => __( 'a part is missing', 'openvault-backup' ),
			'part_size'        => __( 'a part has the wrong size', 'openvault-backup' ),
		);
		$lines = array();
		foreach ( $plan->sources() as $source ) {
			if ( $source['complete'] ) {
				continue;
			}
			$reason  = isset( $names[ $source['reason'] ] ) ? $names[ $source['reason'] ] : $source['reason'];
			$lines[] = RestorePlan::SOURCE_LOCAL === $source['id']
				? sprintf(
					/* translators: %s: reason. */
					__( 'Local folder: %s.', 'openvault-backup' ),
					$reason
				)
				: sprintf(
					/* translators: 1: destination id, 2: reason. */
					__( 'Destination %1$d: %2$s.', 'openvault-backup' ),
					$source['id'],
					$reason
				);
		}
		return implode( ' ', $lines );
	}

	/**
	 * Whether a backup or restore is running.
	 *
	 * @param int $own_id Id of this restore.
	 * @return string '', 'backup' or 'restore'.
	 */
	private function busy( $own_id ) {
		if ( array() !== $this->backups->find_incomplete( 1 ) ) {
			return 'backup';
		}
		foreach ( $this->restores->find_active( 10 ) as $restore ) {
			if ( (int) $restore['id'] !== $own_id ) {
				return 'restore';
			}
		}
		return '';
	}

	/**
	 * Reachability of the destination of a remote source.
	 *
	 * @param int $destination_id Destination id.
	 * @return array<string,mixed>
	 */
	private function probe( $destination_id ) {
		try {
			$destination = call_user_func( $this->destinations, $destination_id );
			$result      = $destination->test_connection();
			return array(
				'source_reachable' => $result->ok(),
				'source_error'     => $result->ok() ? '' : $result->message(),
			);
		} catch ( DestinationException $e ) {
			return array(
				'source_reachable'       => false,
				'source_needs_reconnect' => DestinationException::REASON_NEEDS_RECONNECT === $e->reason(),
				'source_error'           => $e->getMessage(),
			);
		}
	}

	/**
	 * Free disk space, null when the server cannot tell.
	 *
	 * @param string $dir Folder on the volume to check.
	 * @return int|null
	 */
	private static function free_bytes( $dir ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fails on restricted hosts.
		return false === $free ? null : (int) $free;
	}
}
