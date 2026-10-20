<?php
/**
 * Server-local storage in protected folder.
 *
 * Phase: MVP
 *
 * Purpose: Server-local storage in protected folder.
 *
 * Settings: "path" = absolute folder. Empty (the default) means the plugin's own protected backups folder
 * (Paths::backups_dir()): the backup already lives there, so upload_chunk() returns "done" for a file that is
 * already inside the base without copying. A custom folder should be outside the web root; guard files are
 * created when it is inside the WordPress folder.
 *
 * Safety: a custom path must be absolute, without "." or ".." segments, creatable and writable, resolved with
 * realpath() and checked again, and it must not be the WordPress root, wp-admin, wp-includes or the plugin's tmp
 * folder. Every remote path is validated (RemotePath::is_safe()) and no part of it below the base may be a
 * symlink, so nothing outside the base is ever read, written or deleted. Messages never contain absolute paths.
 *
 * Upload: copies in 1 MB chunks into "<name>.part", the session ( v, p = remote path, o = offset, s = size,
 * h = sha256 or '' ) stores the offset; a resumed copy first cuts the partial file back to the saved offset.
 * On completion the size (and the sha256, up to HASH_LIMIT bytes) is verified and the file renamed.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads, writes and removes files in the user's own backup folder in bounded chunks; WP_Filesystem is not suitable for resumable streaming and is not available in unit tests.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings or scrubbed errors; they are stored and shown through escaping output functions, never echoed as HTML here.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Filesystem calls on restricted hosts warn instead of returning false; every result is checked.

namespace OpenVault\Backup\Destinations\Local;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\DestinationInterface;
use OpenVault\Backup\Destinations\RemotePath;
use OpenVault\Backup\Destinations\TestResult;
use OpenVault\Backup\Destinations\UploadProgress;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Local folder destination.
 */
class LocalDestination implements DestinationInterface {

	const CHUNK      = 1048576;
	const HASH_LIMIT = 268435456;
	const MAX_PATH   = 500;
	const GUARD      = array( 'index.php', '.htaccess', 'web.config' );

	/**
	 * Settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Environment: default_base, abspath, wp_admin, wp_includes, tmp_dir (callables or strings), protect (callable).
	 *
	 * @var array<string,mixed>
	 */
	private $env;

	/**
	 * Resolved base folder (realpath, forward slashes), cached.
	 *
	 * @var string|null
	 */
	private $base;

	/**
	 * Whether the base is the plugin's own backups folder.
	 *
	 * @var bool
	 */
	private $is_default = false;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>      $settings    Settings ("path").
	 * @param array<string,mixed>      $credentials Unused (a local folder has none).
	 * @param array<string,mixed>|null $env         Environment override (tests); defaults to the real site.
	 */
	public function __construct( array $settings = array(), array $credentials = array(), ?array $env = null ) {
		unset( $credentials );
		$this->settings = $settings;
		$this->env      = null === $env ? self::default_env() : $env;
	}

	/**
	 * Type slug.
	 *
	 * @return string
	 */
	public function id() {
		return 'local';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Local folder', 'openvault-backup' );
	}

	/**
	 * Top folder below the base ('' when the base is the plugin's own folder, where backups sit directly).
	 *
	 * @return string
	 */
	public function remote_root() {
		return $this->uses_default_base() ? '' : RemotePath::ROOT;
	}

	/**
	 * Write, read back and delete a probe file.
	 *
	 * @return TestResult
	 */
	public function test_connection() {
		try {
			return $this->probe();
		} catch ( DestinationException $e ) {
			return TestResult::failure( $e->getMessage() );
		}
	}

	/**
	 * Check that the saved folder is acceptable with the SAME rules the destination enforces when it is used (absolute
	 * path, no "." or ".." parts, not the WordPress folder, wp-admin, wp-includes or the plugin tmp folder, creatable
	 * and writable). Used when a destination is saved; it creates the folder like the first use would.
	 *
	 * @return void
	 * @throws DestinationException With a safe message when the folder is not acceptable.
	 */
	public function validate() {
		$this->base();
	}

	/**
	 * The actual test of test_connection().
	 *
	 * @return TestResult
	 * @throws DestinationException When the folder is not usable.
	 */
	private function probe() {
		$base  = $this->base();
		$probe = $base . '/.ovb-probe-' . md5( uniqid( '', true ) ) . '.tmp';
		$data  = md5( uniqid( '', true ) );

		if ( false === @file_put_contents( $probe, $data ) ) {
			throw DestinationException::permanent( __( 'The folder is not writable.', 'openvault-backup' ) );
		}
		$read = @file_get_contents( $probe );
		@unlink( $probe );
		if ( $read !== $data ) {
			throw DestinationException::permanent( __( 'A test file could not be read back from the folder.', 'openvault-backup' ) );
		}

		$details = array(
			'default'  => $this->is_default,
			'web_root' => $this->in_web_root( $base ),
		);
		$quota   = $this->quota();
		if ( null !== $quota ) {
			$details['free_bytes'] = $quota['free'];
		}
		$message = $details['web_root'] && ! $this->is_default
			? __( 'The folder works. It is inside the WordPress folder, so guard files were added; a folder outside the web root is safer.', 'openvault-backup' )
			: __( 'The folder works.', 'openvault-backup' );
		return TestResult::success( $message, $details );
	}

	/**
	 * Start an upload.
	 *
	 * @param string              $remote_path Relative remote path.
	 * @param int                 $size        Size in bytes.
	 * @param array<string,mixed> $options     sha256.
	 * @return array<string,mixed>
	 * @throws DestinationException When the path or size is invalid or the destination folder is not usable.
	 */
	public function begin_upload( $remote_path, $size, array $options = array() ) {
		$target = $this->locate( $remote_path );
		if ( ! is_int( $size ) || $size < 0 ) {
			throw DestinationException::permanent( __( 'Invalid file size.', 'openvault-backup' ) );
		}

		$sha = isset( $options['sha256'] ) && is_string( $options['sha256'] ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', strtolower( $options['sha256'] ) ) ? strtolower( $options['sha256'] ) : '';
		if ( is_file( $target . '.part' ) ) {
			@unlink( $target . '.part' );
		}

		return array(
			'v' => 1,
			'p' => $remote_path,
			'o' => 0,
			's' => $size,
			'h' => $sha,
		);
	}

	/**
	 * Copy as much as the budget allows.
	 *
	 * @param array<string,mixed> $session    Session.
	 * @param string              $local_file Source file.
	 * @param Budget              $budget     Budget.
	 * @return UploadProgress
	 * @throws DestinationException On failure.
	 */
	public function upload_chunk( array $session, $local_file, Budget $budget ) {
		$s      = self::read_session( $session );
		$target = $this->locate( $s['p'] );
		$source = $this->source( $local_file, $s['s'] );
		$base   = $this->base();

		// The file already lives below the base (the plugin's own folder): nothing to copy.
		if ( self::is_inside( $source, $base ) && $source !== $base ) {
			$ref = substr( $source, strlen( $base ) + 1 );
			if ( RemotePath::is_safe( $ref ) ) {
				$s['o'] = $s['s'];
				return UploadProgress::finished( $s, 0, $ref );
			}
		}

		// The folders are only created when a copy is needed (a file already in place needs none).
		$dir = dirname( $target );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw DestinationException::permanent( __( 'The folder for the backup could not be created.', 'openvault-backup' ) );
		}
		$this->locate( $s['p'] );

		$part   = $target . '.part';
		$offset = $s['o'];
		clearstatcache( true, $part );
		if ( $offset > 0 && ( ! is_file( $part ) || filesize( $part ) < $offset ) ) {
			$offset = 0;
			// The partial file is gone or shorter than the saved offset: start again.
		}

		$out = @fopen( $part, $offset > 0 ? 'c+b' : 'wb' );
		$in  = false === $out ? false : @fopen( $source, 'rb' );
		if ( false === $out || false === $in ) {
			if ( is_resource( $out ) ) {
				fclose( $out );
			}
			throw DestinationException::transient( __( 'The backup file could not be opened for copying.', 'openvault-backup' ) );
		}

		$sent = 0;
		try {
			if ( $offset > 0 ) {
				ftruncate( $out, $offset );
			}
			fseek( $out, $offset );
			fseek( $in, $offset );

			// At least one chunk per call, so a call always moves forward.
			do {
				$want = min( self::CHUNK, $s['s'] - $offset );
				if ( $want <= 0 ) {
					break;
				}
				$data = fread( $in, $want );
				if ( false === $data || '' === $data ) {
					throw DestinationException::transient( __( 'Reading the backup file failed.', 'openvault-backup' ) );
				}
				$length = strlen( $data );
				if ( fwrite( $out, $data ) !== $length ) {
					throw DestinationException::transient( __( 'Writing to the folder failed. The disk may be full.', 'openvault-backup' ) );
				}
				$offset += $length;
				$sent   += $length;
			} while ( $offset < $s['s'] && ! $budget->exhausted() );

			fflush( $out );
		} finally {
			fclose( $in );
			fclose( $out );
		}//end try

		$s['o'] = $offset;
		if ( $offset < $s['s'] ) {
			return UploadProgress::partial( $s, $sent );
		}

		$this->finalise( $part, $target, $s );
		return UploadProgress::finished( $s, $sent, $s['p'] );
	}

	/**
	 * Remove a partial copy.
	 *
	 * @param array<string,mixed> $session Session.
	 * @return void
	 */
	public function abort_upload( array $session ) {
		try {
			$s    = self::read_session( $session );
			$part = $this->locate( $s['p'] ) . '.part';
			if ( is_file( $part ) ) {
				@unlink( $part );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			// Best effort.
		}
	}

	/**
	 * Stream a stored file.
	 *
	 * @param string   $remote_ref Reference.
	 * @param callable $sink       Receives chunks; false stops.
	 * @return void
	 * @throws DestinationException When missing or unreadable.
	 */
	public function download( $remote_ref, callable $sink ) {
		$target = $this->locate( $remote_ref );
		if ( ! is_file( $target ) ) {
			throw DestinationException::permanent( __( 'The file does not exist in the folder.', 'openvault-backup' ) );
		}
		$in = @fopen( $target, 'rb' );
		if ( false === $in ) {
			throw DestinationException::permanent( __( 'The file could not be read.', 'openvault-backup' ) );
		}
		try {
			while ( ! feof( $in ) ) {
				$data = fread( $in, self::CHUNK );
				if ( false === $data ) {
					throw DestinationException::transient( __( 'Reading the file failed.', 'openvault-backup' ) );
				}
				if ( '' !== $data && false === call_user_func( $sink, $data ) ) {
					break;
				}
			}
		} finally {
			fclose( $in );
		}
	}

	/**
	 * Delete a file or a folder below the base; empty parents are removed up to the base.
	 *
	 * @param string $remote_ref Reference.
	 * @return void
	 * @throws DestinationException When it cannot be removed.
	 */
	public function delete( $remote_ref ) {
		$target = $this->locate( $remote_ref );
		$base   = $this->base();

		if ( file_exists( $target ) || is_link( $target ) ) {
			if ( is_link( $target ) ) {
				throw DestinationException::permanent( __( 'A link is never followed or removed.', 'openvault-backup' ) );
			}
			$gone = is_dir( $target ) ? CleanupStep::remove_tree( $target ) : @unlink( $target );
			if ( ! $gone ) {
				throw DestinationException::permanent( __( 'The file could not be deleted. Check the folder permissions.', 'openvault-backup' ) );
			}
		}

		$dir = dirname( $target );
		while ( $dir !== $base && self::is_inside( $dir, $base ) && @rmdir( $dir ) ) {
			$dir = dirname( $dir );
		}
	}

	/**
	 * List finished files below a prefix. Partial copies and guard files are left out.
	 *
	 * @param string $remote_prefix Relative prefix ('' = below remote_root()).
	 * @param int    $limit         Most entries.
	 * @return \Generator
	 * @throws DestinationException When the folder cannot be used.
	 */
	public function list( $remote_prefix = '', $limit = 10000 ) {
		$start = '' === $remote_prefix ? $this->remote_root() : (string) $remote_prefix;
		$base  = $this->base();
		$root  = '' === $start ? $base : $this->locate( $start );
		if ( ! is_dir( $root ) ) {
			return;
		}

		$limit    = max( 0, (int) $limit );
		$count    = 0;
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $item ) {
			if ( $count >= $limit ) {
				return;
			}
			$name = $item->getFilename();
			if ( $item->isLink() || ! $item->isFile() || '.part' === substr( $name, -5 ) || in_array( $name, self::GUARD, true ) ) {
				continue;
			}
			$ref = ltrim( substr( str_replace( '\\', '/', $item->getPathname() ), strlen( $base ) ), '/' );
			if ( ! RemotePath::is_safe( $ref ) ) {
				continue;
			}
			++$count;
			yield array(
				'ref'      => $ref,
				'name'     => $name,
				'size'     => (int) $item->getSize(),
				'modified' => (int) $item->getMTime(),
			);
		}
	}

	/**
	 * Free and total space of the volume.
	 *
	 * @return array{free:int,total:int}|null
	 */
	public function quota() {
		if ( ! function_exists( 'disk_free_space' ) || ! function_exists( 'disk_total_space' ) ) {
			return null;
		}
		try {
			$base = $this->base();
		} catch ( DestinationException $e ) {
			return null;
		}
		$free  = @disk_free_space( $base );
		$total = @disk_total_space( $base );
		if ( false === $free || false === $total ) {
			return null;
		}
		return array(
			'free'  => (int) $free,
			'total' => (int) $total,
		);
	}

	// Pure helpers (no WordPress, no filesystem).

	/**
	 * Normalise an absolute path: forward slashes, no trailing slash. Null when it is not acceptable: not a string,
	 * relative, NUL or control characters, a "." or ".." or empty segment, no folder below the root, too long.
	 *
	 * @param mixed $path Path.
	 * @return string|null
	 */
	public static function normalise_path( $path ) {
		if ( ! is_string( $path ) || '' === trim( $path ) || strlen( $path ) > self::MAX_PATH ) {
			return null;
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
			return null;
		}

		$path  = rtrim( str_replace( '\\', '/', trim( $path ) ), '/' );
		$drive = 1 === preg_match( '#^[A-Za-z]:/#', $path );
		if ( 0 !== strpos( $path, '/' ) && ! $drive ) {
			return null;
		}

		$rest     = $drive ? substr( $path, 3 ) : substr( $path, 1 );
		$segments = explode( '/', $rest );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}
		return $path;
	}

	/**
	 * Whether $path is $dir or lies below it (case-insensitive for Windows drive paths).
	 *
	 * @param string $path Path.
	 * @param string $dir  Folder.
	 * @return bool
	 */
	public static function is_inside( $path, $dir ) {
		$path = rtrim( str_replace( '\\', '/', (string) $path ), '/' );
		$dir  = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
		if ( '' === $dir ) {
			return false;
		}
		if ( 1 === preg_match( '#^[A-Za-z]:/#', $path ) || 1 === preg_match( '#^[A-Za-z]:/#', $dir ) ) {
			$path = strtolower( $path );
			$dir  = strtolower( $dir );
		}
		return $path === $dir || 0 === strpos( $path . '/', $dir . '/' );
	}

	/**
	 * Whether a folder must never be used as a destination: the WordPress root itself, anything inside wp-admin or
	 * wp-includes, or inside the plugin's tmp folder. Pure: the caller passes the candidate folders.
	 *
	 * @param string   $path      Candidate (normalised).
	 * @param string   $abspath   WordPress root.
	 * @param string[] $guarded   Folders that may not contain the destination (wp-admin, wp-includes, tmp ...).
	 * @return bool
	 */
	public static function is_forbidden( $path, $abspath, array $guarded ) {
		$abspath = rtrim( str_replace( '\\', '/', (string) $abspath ), '/' );
		if ( '' !== $abspath && strtolower( $path ) === strtolower( $abspath ) ) {
			return true;
		}
		foreach ( $guarded as $dir ) {
			if ( '' !== $dir && self::is_inside( $path, $dir ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate a session read from the database.
	 *
	 * @param array<string,mixed> $session Session.
	 * @return array{v:int,p:string,o:int,s:int,h:string}
	 * @throws DestinationException For a session that is not ours.
	 */
	public static function read_session( array $session ) {
		if ( ! isset( $session['v'], $session['p'], $session['o'], $session['s'] ) || 1 !== $session['v']
			|| ! is_int( $session['o'] ) || ! is_int( $session['s'] ) || $session['o'] < 0 || $session['s'] < $session['o']
			|| ! RemotePath::is_safe( $session['p'] ) ) {
			throw DestinationException::permanent( __( 'The saved upload session is not valid.', 'openvault-backup' ) );
		}
		return array(
			'v' => 1,
			'p' => (string) $session['p'],
			'o' => $session['o'],
			's' => $session['s'],
			'h' => isset( $session['h'] ) && is_string( $session['h'] ) ? $session['h'] : '',
		);
	}

	// Internals.

	/**
	 * Environment of the real site.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_env() {
		return array(
			'default_base' => array( Paths::class, 'backups_dir' ),
			'abspath'      => ABSPATH,
			'wp_admin'     => ABSPATH . 'wp-admin',
			'wp_includes'  => ABSPATH . 'wp-includes',
			'tmp_dir'      => array( Paths::class, 'tmp_dir' ),
			'protect'      => array( Paths::class, 'ensure_protected_dir' ),
		);
	}

	/**
	 * Whether the destination uses the plugin's own backups folder.
	 *
	 * @return bool
	 */
	private function uses_default_base() {
		return ! isset( $this->settings['path'] ) || ! is_string( $this->settings['path'] ) || '' === trim( $this->settings['path'] );
	}

	/**
	 * The base folder (resolved with realpath, forward slashes, no trailing slash).
	 *
	 * @return string
	 * @throws DestinationException When the folder is not acceptable.
	 */
	private function base() {
		if ( null !== $this->base ) {
			return $this->base;
		}

		if ( $this->uses_default_base() ) {
			try {
				$dir = (string) call_user_func( $this->env['default_base'] );
			} catch ( \RuntimeException $e ) {
				throw DestinationException::permanent( __( 'The plugin backup folder could not be created. Check that wp-content is writable.', 'openvault-backup' ) );
			}
			$real = realpath( $dir );
			if ( false === $real ) {
				throw DestinationException::permanent( __( 'The plugin backup folder is missing.', 'openvault-backup' ) );
			}
			$this->is_default = true;
			$this->base       = rtrim( str_replace( '\\', '/', $real ), '/' );
			return $this->base;
		}

		$path = self::normalise_path( $this->settings['path'] );
		if ( null === $path ) {
			throw DestinationException::permanent( __( 'Enter an absolute folder path without "." or ".." parts.', 'openvault-backup' ) );
		}
		$this->assert_allowed( $this->resolve_existing( $path ) );

		if ( ! is_dir( $path ) && ! @mkdir( $path, 0755, true ) && ! is_dir( $path ) ) {
			throw DestinationException::permanent( __( 'The folder does not exist and could not be created.', 'openvault-backup' ) );
		}
		$real = realpath( $path );
		if ( false === $real || ! is_dir( $real ) ) {
			throw DestinationException::permanent( __( 'The folder could not be resolved.', 'openvault-backup' ) );
		}
		$real = rtrim( str_replace( '\\', '/', $real ), '/' );
		$this->assert_allowed( $real );
		if ( ! is_writable( $real ) ) {
			throw DestinationException::permanent( __( 'The folder is not writable by the web server.', 'openvault-backup' ) );
		}

		if ( $this->in_web_root( $real ) ) {
			call_user_func( $this->env['protect'], $real );
		}

		$this->base = $real;
		return $this->base;
	}

	/**
	 * The path with its deepest existing ancestor resolved by realpath (so a symlink in the path is seen before
	 * anything is created).
	 *
	 * @param string $path Normalised path.
	 * @return string
	 */
	private function resolve_existing( $path ) {
		$tail    = '';
		$current = $path;
		while ( ! is_dir( $current ) && dirname( $current ) !== $current ) {
			$tail    = '/' . basename( $current ) . $tail;
			$current = dirname( $current );
		}
		$real = realpath( $current );
		return false === $real ? $path : rtrim( str_replace( '\\', '/', $real ), '/' ) . $tail;
	}

	/**
	 * Refuse the WordPress root, wp-admin, wp-includes and the plugin's tmp folder.
	 *
	 * @param string $path Resolved path.
	 * @return void
	 * @throws DestinationException When forbidden.
	 */
	private function assert_allowed( $path ) {
		$abspath = $this->dir_value( 'abspath' );
		$guarded = array(
			$this->dir_value( 'wp_admin' ),
			$this->dir_value( 'wp_includes' ),
			$this->dir_value( 'tmp_dir' ),
		);

		foreach ( array( $guarded[0], $guarded[1], $guarded[2] ) as $dir ) {
			$real = '' === $dir ? false : realpath( $dir );
			if ( false !== $real ) {
				$guarded[] = rtrim( str_replace( '\\', '/', $real ), '/' );
			}
		}
		$roots = array( $abspath );
		if ( false !== realpath( $abspath ) ) {
			$roots[] = rtrim( str_replace( '\\', '/', (string) realpath( $abspath ) ), '/' );
		}

		foreach ( $roots as $root ) {
			if ( self::is_forbidden( $path, $root, $guarded ) ) {
				throw DestinationException::permanent( __( 'This folder cannot be used: it is the WordPress folder, a WordPress core folder or the plugin temporary folder. Choose a separate folder, ideally outside the web root.', 'openvault-backup' ) );
			}
		}
	}

	/**
	 * An environment value that may be a string or a callable returning one.
	 *
	 * @param string $key Key.
	 * @return string Normalised ('' when unavailable).
	 */
	private function dir_value( $key ) {
		$value = isset( $this->env[ $key ] ) ? $this->env[ $key ] : '';
		if ( is_callable( $value ) ) {
			try {
				$value = call_user_func( $value );
			} catch ( \RuntimeException $e ) {
				$value = '';
			}
		}
		return rtrim( str_replace( '\\', '/', is_string( $value ) ? $value : '' ), '/' );
	}

	/**
	 * Whether a folder is inside the WordPress folder (and so possibly web readable).
	 *
	 * @param string $dir Resolved folder.
	 * @return bool
	 */
	private function in_web_root( $dir ) {
		$abspath = $this->dir_value( 'abspath' );
		if ( '' === $abspath ) {
			return false;
		}
		$real = realpath( $abspath );
		return self::is_inside( $dir, $abspath ) || ( false !== $real && self::is_inside( $dir, $real ) );
	}

	/**
	 * Absolute path of a remote path below the base. No part below the base may be a symlink.
	 *
	 * @param mixed $relative Remote path or reference.
	 * @return string
	 * @throws DestinationException For an unsafe path or a symlink.
	 */
	private function locate( $relative ) {
		if ( ! RemotePath::is_safe( $relative ) ) {
			throw DestinationException::permanent( __( 'The remote path is not valid.', 'openvault-backup' ) );
		}

		$base    = $this->base();
		$current = $base;
		foreach ( explode( '/', (string) $relative ) as $segment ) {
			$current .= '/' . $segment;
			if ( is_link( $current ) ) {
				throw DestinationException::permanent( __( 'A link inside the backup folder was refused.', 'openvault-backup' ) );
			}
			if ( ! file_exists( $current ) ) {
				break;
			}
		}
		return $base . '/' . $relative;
	}

	/**
	 * The local file to upload: a regular file of the expected size, as a resolved path.
	 *
	 * @param mixed $local_file Path.
	 * @param int   $size       Expected size.
	 * @return string
	 * @throws DestinationException When missing or changed.
	 */
	private function source( $local_file, $size ) {
		$real = is_string( $local_file ) ? realpath( $local_file ) : false;
		if ( false === $real || ! is_file( $real ) ) {
			throw DestinationException::permanent( __( 'The local backup file is missing.', 'openvault-backup' ) );
		}
		clearstatcache( true, $real );
		if ( filesize( $real ) !== $size ) {
			throw DestinationException::permanent( __( 'The local backup file changed while it was being uploaded.', 'openvault-backup' ) );
		}
		return rtrim( str_replace( '\\', '/', $real ), '/' );
	}

	/**
	 * Verify and rename a finished copy.
	 *
	 * @param string                                     $part   Partial file.
	 * @param string                                     $target Final file.
	 * @param array{v:int,p:string,o:int,s:int,h:string} $s      Session.
	 * @return void
	 * @throws DestinationException When the copy is wrong or cannot be renamed.
	 */
	private function finalise( $part, $target, array $s ) {
		clearstatcache( true, $part );
		if ( ! is_file( $part ) || filesize( $part ) !== $s['s'] ) {
			@unlink( $part );
			throw DestinationException::transient( __( 'The copy has the wrong size and will be repeated.', 'openvault-backup' ) );
		}
		// shortcut: the checksum is only verified up to HASH_LIMIT (a default part is smaller); larger copies are size checked. Upgrade with an incremental hash if parts grow.
		if ( '' !== $s['h'] && $s['s'] <= self::HASH_LIMIT && hash_file( 'sha256', $part ) !== $s['h'] ) {
			@unlink( $part );
			throw DestinationException::transient( __( 'The copy does not match its checksum and will be repeated.', 'openvault-backup' ) );
		}
		if ( ! @rename( $part, $target ) ) {
			@unlink( $target );
			if ( ! @rename( $part, $target ) ) {
				throw DestinationException::transient( __( 'The finished copy could not be moved into place.', 'openvault-backup' ) );
			}
		}
	}
}
