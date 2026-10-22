<?php
/**
 * Maintenance mode during the database part of a restore (see docs/plan/08-restore.md).
 *
 * Phase: MVP
 *
 * Purpose: While tables are dropped and recreated, visitors must not see (or write into) a half-restored site.
 *
 * Why not the usual ABSPATH/.maintenance file: WordPress evaluates it before any plugin loads and answers EVERY request with it,
 * including this plugin's own progress polling and the Action Scheduler requests that run the restore. So the plugin has its own flag.
 *
 * The flag is a small JSON file <local backup folder>/maintenance.json: { restore_id, started, expires }. It is a file and not an
 * option because the options table is the thing being replaced. It expires on its own after 30 minutes (TTL) and the running job
 * pushes the expiry forward every slice (refresh()); a flag that is expired, malformed or implausible (expiry further away than twice
 * the TTL) counts as OFF: the site fails open rather than staying locked. A failed restore therefore keeps the maintenance page for
 * at most the remaining TTL, until the user decides (undo or restore again); nothing refreshes the flag after a failure.
 *
 * While the flag is valid the plugin hooks plugins_loaded (priority 0, before the theme and the other plugins do anything) and
 * decides per request (decide(), pure):
 *  - allowed always: WP-CLI and CLI requests, wp-login.php, REST routes of "ovb/v1" (their own permission checks apply),
 *    admin-ajax.php with an action "ovb_*" or Action Scheduler's "as_async_request_queue_runner" (the loopback request that runs the
 *    restore's next slice);
 *  - wp-cron.php is allowed, but only the cron event "action_scheduler_run_queue" runs (pre_get_ready_cron_jobs), so the restore
 *    keeps going when the loopback request is blocked on the server while every other scheduled event waits (that queue run also
 *    executes other plugins' Action Scheduler actions: a known limit);
 *  - admin area requests (wp-admin, admin-post.php, other admin-ajax.php actions) are allowed only for users with manage_options,
 *    checked on "init" (the capability needs the user): everyone else gets the 503 page;
 *  - everything else (the front end, other REST routes, xmlrpc.php) gets 503 Service Unavailable with Retry-After: 120.
 * During the few milliseconds in which the users table is being recreated an administrator cannot be recognised and sees the 503
 * page for that request (the progress screen must retry).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Maintenance flag and request gate.
 */
class MaintenanceMode {

	const FILE        = 'maintenance.json';
	const TTL         = 1800;
	const RETRY_AFTER = 120;

	const DECISION_ALLOW = 'allow';
	const DECISION_BLOCK = 'block';
	const DECISION_CRON  = 'cron';
	const DECISION_ADMIN = 'admin';

	const AJAX_ALLOWED = '/^(?:as_async_request_queue_runner|ovb_[a-z0-9_]{1,60})\z/';

	/**
	 * Cron events that still run while the flag is valid.
	 */
	const CRON_HOOKS = array( 'action_scheduler_run_queue' );

	/**
	 * The flag for a restore. Pure.
	 *
	 * @param int $restore_id Restore id.
	 * @param int $now        Unix time.
	 * @param int $started    Start time to keep when refreshing (0 = now).
	 * @param int $ttl        Seconds until the flag expires.
	 * @return array{restore_id:int,started:int,expires:int}
	 */
	public static function build( $restore_id, $now, $started = 0, $ttl = self::TTL ) {
		return array(
			'restore_id' => (int) $restore_id,
			'started'    => $started > 0 ? (int) $started : (int) $now,
			'expires'    => (int) $now + (int) $ttl,
		);
	}

	/**
	 * Read the flag from JSON. Pure.
	 *
	 * @param mixed $json File contents.
	 * @return array{restore_id:int,started:int,expires:int}|null Null when missing or malformed.
	 */
	public static function parse( $json ) {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > 1024 ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'restore_id', 'started', 'expires' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_int( $data[ $key ] ) || $data[ $key ] < 1 ) {
				return null;
			}
		}
		return array(
			'restore_id' => $data['restore_id'],
			'started'    => $data['started'],
			'expires'    => $data['expires'],
		);
	}

	/**
	 * Whether a flag is in force at $now: not expired and not implausibly far in the future. Pure.
	 *
	 * @param array<string,int>|null $flag Parsed flag.
	 * @param int                    $now  Unix time.
	 * @return bool
	 */
	public static function is_valid( $flag, $now ) {
		if ( ! is_array( $flag ) || ! isset( $flag['expires'] ) ) {
			return false;
		}
		$left = (int) $flag['expires'] - (int) $now;
		return $left > 0 && $left <= 2 * self::TTL;
	}

	/**
	 * What to do with a request. Pure.
	 *
	 * Facts: cli (bool), script (lower-case file name of the entry script), rest_ovb (bool: a route of ovb/v1), ajax_action (string),
	 * is_admin (bool: an admin area request).
	 *
	 * @param array<string,mixed> $facts Request facts.
	 * @return string DECISION_ALLOW, DECISION_BLOCK, DECISION_CRON (allowed, with the event filter) or DECISION_ADMIN (allowed for administrators).
	 */
	public static function decide( array $facts ) {
		$script = isset( $facts['script'] ) ? (string) $facts['script'] : '';

		if ( ! empty( $facts['cli'] ) || 'wp-login.php' === $script || ! empty( $facts['rest_ovb'] ) ) {
			return self::DECISION_ALLOW;
		}
		if ( 'admin-ajax.php' === $script && isset( $facts['ajax_action'] ) && 1 === preg_match( self::AJAX_ALLOWED, (string) $facts['ajax_action'] ) ) {
			return self::DECISION_ALLOW;
		}
		if ( 'wp-cron.php' === $script ) {
			return self::DECISION_CRON;
		}
		if ( ! empty( $facts['is_admin'] ) ) {
			return self::DECISION_ADMIN;
		}
		return self::DECISION_BLOCK;
	}

	/**
	 * Request facts from the PHP superglobals' values. Pure.
	 *
	 * @param array<string,mixed> $server   $_SERVER.
	 * @param array<string,mixed> $request  $_REQUEST.
	 * @param bool                $is_admin Whether this is an admin area request.
	 * @param bool                $cli      Whether PHP runs on the command line.
	 * @return array{cli:bool,script:string,rest_ovb:bool,ajax_action:string,is_admin:bool}
	 */
	public static function facts_from_request( array $server, array $request, $is_admin, $cli ) {
		$script = isset( $server['SCRIPT_NAME'] ) && is_string( $server['SCRIPT_NAME'] ) ? strtolower( basename( str_replace( '\\', '/', $server['SCRIPT_NAME'] ) ) ) : '';
		$uri    = isset( $server['REQUEST_URI'] ) && is_string( $server['REQUEST_URI'] ) ? $server['REQUEST_URI'] : '';
		$action = isset( $request['action'] ) && is_string( $request['action'] ) ? $request['action'] : '';

		// The path and the query string are looked at separately: a query value must not pass for the route.
		$split = strpos( $uri, '?' );
		$path  = false === $split ? $uri : substr( $uri, 0, $split );
		$query = false === $split ? '' : substr( $uri, $split + 1 );
		$rest  = 1 === preg_match( '#(?:^|/)wp-json/ovb/v1(?:/|$)#', $path )
			|| 1 === preg_match( '#(?:^|&)rest_route=(?:%2F|/)ovb(?:%2F|/)v1(?:%2F|/|&|$)#i', $query );

		return array(
			'cli'         => (bool) $cli,
			'script'      => $script,
			'rest_ovb'    => $rest,
			'ajax_action' => $action,
			'is_admin'    => (bool) $is_admin,
		);
	}

	/**
	 * Keep only the allowed events of a list of ready cron jobs (timestamp => hook => events). Pure.
	 *
	 * @param array<int|string,mixed> $jobs    Ready jobs as wp_get_ready_cron_jobs() returns them.
	 * @param string[]                $allowed Allowed hook names.
	 * @return array<int|string,mixed>
	 */
	public static function filter_cron_jobs( array $jobs, array $allowed ) {
		$out = array();
		foreach ( $jobs as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			$kept = array_intersect_key( $hooks, array_flip( $allowed ) );
			if ( array() !== $kept ) {
				$out[ $timestamp ] = $kept;
			}
		}
		return $out;
	}

	/**
	 * Path of the flag file without creating anything (cheap: runs on every request while the plugin is active).
	 *
	 * @return string '' when the local folder is not known yet.
	 */
	public static function flag_path() {
		$name = get_option( Paths::OPTION_LOCAL_DIR );
		if ( ! is_string( $name ) || '' === $name ) {
			return '';
		}
		try {
			return Paths::build_local_dir( WP_CONTENT_DIR, $name ) . '/' . self::FILE;
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
	}

	/**
	 * Hook the gate. Called on every request type.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'plugins_loaded', array( $this, 'gate' ), 0 );
	}

	/**
	 * Turn maintenance mode on for a restore.
	 *
	 * @param int $restore_id Restore id.
	 * @return bool
	 */
	public function enable( $restore_id ) {
		try {
			$path = Paths::local_dir() . '/' . self::FILE;
		} catch ( \RuntimeException $e ) {
			return false;
		}
		return $this->write( $path, self::build( (int) $restore_id, time() ) );
	}

	/**
	 * Push the expiry forward (the job is alive). Does nothing when the flag is off.
	 *
	 * @return bool True when the flag was refreshed.
	 */
	public function refresh() {
		$path = self::flag_path();
		$flag = $this->read();
		if ( '' === $path || null === $flag ) {
			return false;
		}
		return $this->write( $path, self::build( $flag['restore_id'], time(), $flag['started'] ) );
	}

	/**
	 * Turn maintenance mode off.
	 *
	 * @return void
	 */
	public function disable() {
		$path = self::flag_path();
		if ( '' !== $path && ( file_exists( $path ) || is_link( $path ) ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Whether the flag is in force right now.
	 *
	 * @return bool
	 */
	public function is_active() {
		return self::is_valid( $this->read(), time() );
	}

	/**
	 * The flag when it is in force, for the progress screen.
	 *
	 * @return array{restore_id:int,started:int,expires:int}|null
	 */
	public function status() {
		$flag = $this->read();
		return self::is_valid( $flag, time() ) ? $flag : null;
	}

	/**
	 * Decide for this request (hooked on plugins_loaded, priority 0).
	 *
	 * @return void
	 */
	public function gate() {
		if ( ! $this->is_active() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Read-only request facts for an allow-list; nothing is stored or output.
		$facts    = self::facts_from_request( $_SERVER, $_REQUEST, is_admin(), 'cli' === PHP_SAPI || defined( 'WP_CLI' ) );
		$decision = self::decide( $facts );

		if ( self::DECISION_BLOCK === $decision ) {
			// Answered on init (priority 0), not here: translated texts must not be loaded before init.
			add_action( 'init', array( $this, 'respond_unavailable' ), 0 );
		} elseif ( self::DECISION_CRON === $decision ) {
			add_filter( 'pre_get_ready_cron_jobs', array( $this, 'filter_cron' ) );
		} elseif ( self::DECISION_ADMIN === $decision ) {
			add_action( 'init', array( $this, 'gate_admin' ), 0 );
		}
	}

	/**
	 * Allow only administrators in the admin area during the restore (hooked on init, priority 0).
	 *
	 * @return void
	 */
	public function gate_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->respond_unavailable();
		}
	}

	/**
	 * Let only the Action Scheduler queue runner event run (filter pre_get_ready_cron_jobs).
	 *
	 * @param mixed $pre Value from an earlier filter.
	 * @return mixed
	 */
	public function filter_cron( $pre ) {
		if ( null !== $pre ) {
			return $pre;
		}
		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$now   = microtime( true );
		$ready = array();
		foreach ( is_array( $crons ) ? $crons : array() as $timestamp => $hooks ) {
			if ( $timestamp > $now ) {
				break;
			}
			$ready[ $timestamp ] = $hooks;
		}
		return self::filter_cron_jobs( $ready, self::CRON_HOOKS );
	}

	/**
	 * Answer with the 503 page and stop.
	 *
	 * @return void
	 */
	public function respond_unavailable() {
		if ( ! headers_sent() ) {
			status_header( 503 );
			header( 'Retry-After: ' . (int) self::RETRY_AFTER );
			header( 'Content-Type: text/html; charset=utf-8' );
			nocache_headers();
		}
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . esc_html__( 'Down for maintenance', 'openvault-backup' ) . '</title></head><body style="font-family:sans-serif;max-width:32em;margin:15vh auto;padding:0 1em;text-align:center"><h1>' . esc_html__( 'Down for maintenance', 'openvault-backup' ) . '</h1><p>' . esc_html__( 'This site is being restored from a backup. Please try again in a few minutes.', 'openvault-backup' ) . '</p><p><small>OpenVault Backup</small></p></body></html>';
		exit;
	}

	/**
	 * The parsed flag file (not checked for expiry).
	 *
	 * @return array{restore_id:int,started:int,expires:int}|null
	 */
	private function read() {
		$path = self::flag_path();
		if ( '' === $path || ! is_file( $path ) || is_link( $path ) ) {
			return null;
		}
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Tiny private file; reading it must not need WordPress to be fully loaded.
		return false === $json ? null : self::parse( $json );
	}

	/**
	 * Write the flag atomically.
	 *
	 * @param string            $path Flag file.
	 * @param array<string,int> $flag Flag.
	 * @return bool
	 */
	private function write( $path, array $flag ) {
		$json = json_encode( $flag ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Three integers.
		$tmp  = $path . '.tmp';
		if ( false === $json || false === file_put_contents( $tmp, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny private file, replaced atomically below.
			return false;
		}
		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace.
			wp_delete_file( $tmp );
			return false;
		}
		return true;
	}
}
