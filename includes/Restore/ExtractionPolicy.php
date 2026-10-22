<?php
/**
 * Decides what a restore may do with one archive entry (pure, heavily unit tested).
 *
 * Phase: MVP
 *
 * Purpose: An archive is untrusted input (a bucket or a folder that somebody else can write). For every entry this class answers: write it
 * (and where), skip it, or reject it, with a fixed reason code. Nothing here touches the disk; Restore\SafeWriter does the writing and
 * checks the destination tree. The decision uses the entry name and the entry type only.
 *
 * Mapping (the entry-name contract of docs/plan/04-backup-engine.md): "abspath/<rel>" and "content/<rel>" are mapped through the roots of the
 * backup's contents flags (Engine\Scan\ContentSelector: so a plugins, themes, uploads or mu-plugins folder that lives elsewhere is put back
 * where it lives now). Only the roots the backup contains are touched. "db/..." entries are never extracted here. A name no root owns is
 * skipped. A shallow root (the files directly in the WordPress folder) takes no deeper path, a file root takes exactly its name.
 *
 * Rejected (the archive is hostile or damaged, counted and logged): a name with "..", ".", an empty segment, a leading "/", a backslash, a NUL or any
 * control character, a drive letter, bidirectional-control characters, a segment that ends in a dot or space (Windows resolves it to another
 * name), a ":" in a segment on Windows, invalid UTF-8, or more than 1024 bytes; a symlink, hardlink, device, fifo or any other special entry.
 *
 * Skipped by design (counted, reported): protected paths (the plugin's own folder, the plugin's storage folder, wp-config.php, the
 * .maintenance file, and unless the job opts in .htaccess, web.config, php.ini and .user.ini in the WordPress folder), directories (a restore only
 * creates the folders its files need), names no root owns, the database entry.
 *
 * Protected paths are compared case-insensitively (a case-insensitive file system treats "OpenVault-Backup" as the same folder).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Archive\EntryWalker;
use OpenVault\Backup\Engine\Scan\ContentSelector;
use OpenVault\Backup\Engine\Scan\EntryName;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Extraction policy.
 */
final class ExtractionPolicy {

	const WRITE  = 'write';
	const SKIP   = 'skip';
	const REJECT = 'reject';

	const MAX_NAME = 1024;

	const R_DIR               = 'dir';
	const R_DB                = 'db';
	const R_UNOWNED           = 'unowned';
	const R_NAME_UNSAFE       = 'name_unsafe';
	const R_NAME_LONG         = 'name_long';
	const R_LINK              = 'link';
	const R_SPECIAL           = 'special';
	const R_PROTECTED_PLUGIN  = 'protected_plugin';
	const R_PROTECTED_STORAGE = 'protected_storage';
	const R_PROTECTED_CONFIG  = 'protected_config';
	const R_PROTECTED_SERVER  = 'protected_server';
	const R_PROTECTED_MAINT   = 'protected_maintenance';
	const R_DEST_SYMLINK      = 'dest_symlink';
	const R_DEST_NOT_DIR      = 'dest_not_dir';
	const R_DEST_IS_DIR       = 'dest_is_dir';
	const R_WRITE_FAILED      = 'write_failed';
	const R_ENTRY_ERROR       = 'entry_error';
	const R_CORRUPT_SAVED     = 'corrupt_saved';

	/**
	 * Reasons that mean the archive or the destination is not what a restore expects (logged one by one, up to a limit).
	 * The others (protected paths, directories, the database entry) are normal and only counted.
	 */
	const NOTABLE = array(
		self::R_NAME_UNSAFE,
		self::R_NAME_LONG,
		self::R_LINK,
		self::R_SPECIAL,
		self::R_UNOWNED,
		self::R_DEST_SYMLINK,
		self::R_DEST_NOT_DIR,
		self::R_DEST_IS_DIR,
		self::R_WRITE_FAILED,
		self::R_ENTRY_ERROR,
		self::R_CORRUPT_SAVED,
	);

	/**
	 * Roots: entry prefix, absolute folder, type (dir, shallow, file).
	 *
	 * @var array<int,array{entry:string,abs:string,type:string}>
	 */
	private $roots;

	/**
	 * Protected paths: path (lower case, no trailing slash), reason, mode (tree, exact, prefix).
	 *
	 * @var array<int,array{path:string,reason:string,mode:string}>
	 */
	private $protected;

	/**
	 * Whether ":" is refused in a segment (Windows hosts).
	 *
	 * @var bool
	 */
	private $windows;

	/**
	 * Constructor.
	 *
	 * @param array<int,array{entry:string,abs:string,type:string}>   $roots     Roots (ContentSelector::roots()).
	 * @param array<int,array{path:string,reason:string,mode:string}> $guarded   Protected paths (see protected_paths()).
	 * @param bool|null                                               $windows   Refuse ":" in names (default: on a Windows host).
	 */
	public function __construct( array $roots, array $guarded, $windows = null ) {
		$this->roots = array();
		foreach ( $roots as $root ) {
			if ( isset( $root['entry'], $root['abs'], $root['type'] ) && EntryName::is_safe( $root['entry'] ) ) {
				$this->roots[] = array(
					'entry' => (string) $root['entry'],
					'abs'   => EntryName::normalise_path( $root['abs'] ),
					'type'  => (string) $root['type'],
				);
			}
		}
		$this->protected = array();
		foreach ( $guarded as $item ) {
			$path = self::fold( $item['path'] );
			if ( '' !== $path ) {
				$this->protected[] = array(
					'path'   => $path,
					'reason' => (string) $item['reason'],
					'mode'   => isset( $item['mode'] ) ? (string) $item['mode'] : 'tree',
				);
			}
		}
		$this->windows = null === $windows ? '\\' === DIRECTORY_SEPARATOR : (bool) $windows;
	}

	/**
	 * Policy for the running site.
	 *
	 * @param array<string,mixed> $contents      Contents flags of the backup.
	 * @param string              $local_dir     The plugin's storage folder.
	 * @param bool                $server_config Restore .htaccess, web.config, php.ini and .user.ini of the WordPress folder too.
	 * @return self
	 */
	public static function from_environment( array $contents, $local_dir, $server_config = false ) {
		$selector = ContentSelector::from_environment( $local_dir );
		$roots    = $selector->roots( $contents );
		$config   = array();
		$abspath  = EntryName::normalise_path( ABSPATH );
		foreach ( array( $abspath . '/wp-config.php', dirname( $abspath ) . '/wp-config.php' ) as $candidate ) {
			$config[] = $candidate;
		}
		foreach ( $selector->roots( array( 'wp_config' => true ) ) as $root ) {
			$config[] = $root['abs'];
		}

		$own = defined( 'OVB_PATH' ) ? (string) OVB_PATH : '';
		return new self(
			$roots,
			self::protected_paths(
				$abspath,
				EntryName::normalise_path( WP_CONTENT_DIR ),
				EntryName::normalise_path( WP_PLUGIN_DIR ),
				'' !== $own ? array( $own, (string) realpath( $own ) ) : array(),
				array( $local_dir, (string) realpath( $local_dir ) ),
				$config,
				$server_config
			)
		);
	}

	/**
	 * Self-test for the running site: a policy with EVERY content flag on must refuse entries that map into the plugin's folder, its storage
	 * folder, wp-config.php and the other protected places. Nothing is written.
	 *
	 * @param bool $server_config Whether the job opts in to the server configuration files.
	 * @return string '' when the protection works, otherwise a description.
	 * @throws \RuntimeException When the local backup folder cannot be used.
	 */
	public static function site_self_test( $server_config ) {
		$local = Paths::local_dir();
		$all   = array();
		foreach ( ContentSelector::FLAGS as $flag ) {
			$all[ $flag ] = true;
		}
		$policy = self::from_environment( $all, $local, (bool) $server_config );
		$roots  = ContentSelector::from_environment( $local )->roots( $all );
		return $policy->self_test( $policy->probe_entries( $roots ) );
	}

	/**
	 * The protected paths. Pure.
	 *
	 * @param string   $abspath       The WordPress folder.
	 * @param string   $content_dir   wp-content.
	 * @param string   $plugins_dir   The plugins folder.
	 * @param string[] $own_dirs      The folder(s) of this plugin as found now (path and real path).
	 * @param string[] $storage_dirs  The plugin's storage folder (path and real path).
	 * @param string[] $config_files  Places of wp-config.php.
	 * @param bool     $server_config Whether the server configuration files may be restored.
	 * @return array<int,array{path:string,reason:string,mode:string}>
	 */
	public static function protected_paths( $abspath, $content_dir, $plugins_dir, array $own_dirs, array $storage_dirs, array $config_files, $server_config = false ) {
		$out = array();
		foreach ( array_merge( $own_dirs, array( rtrim( (string) $plugins_dir, '/' ) . '/openvault-backup' ) ) as $dir ) {
			if ( '' !== (string) $dir ) {
				$out[] = array(
					'path'   => (string) $dir,
					'reason' => self::R_PROTECTED_PLUGIN,
					'mode'   => 'tree',
				);
			}
		}
		foreach ( $storage_dirs as $dir ) {
			if ( '' !== (string) $dir ) {
				$out[] = array(
					'path'   => (string) $dir,
					'reason' => self::R_PROTECTED_STORAGE,
					'mode'   => 'tree',
				);
			}
		}
		if ( '' !== (string) $content_dir ) {
			// Any storage folder of this plugin, also a stale one: a restore never creates a folder that looks like one.
			$out[] = array(
				'path'   => rtrim( (string) $content_dir, '/' ) . '/ovb-',
				'reason' => self::R_PROTECTED_STORAGE,
				'mode'   => 'prefix',
			);
		}
		foreach ( $config_files as $file ) {
			if ( '' !== (string) $file ) {
				$out[] = array(
					'path'   => (string) $file,
					'reason' => self::R_PROTECTED_CONFIG,
					'mode'   => 'exact',
				);
			}
		}
		$base  = rtrim( (string) $abspath, '/' );
		$out[] = array(
			'path'   => $base . '/.maintenance',
			'reason' => self::R_PROTECTED_MAINT,
			'mode'   => 'exact',
		);
		if ( ! $server_config ) {
			foreach ( array( '.htaccess', 'web.config', 'php.ini', '.user.ini' ) as $name ) {
				$out[] = array(
					'path'   => $base . '/' . $name,
					'reason' => self::R_PROTECTED_SERVER,
					'mode'   => 'exact',
				);
			}
		}
		return $out;
	}

	/**
	 * Decide for one entry.
	 *
	 * @param mixed  $name Entry name as found in the archive.
	 * @param string $type EntryWalker::TYPE_* constant.
	 * @return array{action:string,reason:string,entry:string,abs:string,rel:string,root:string,root_abs:string}
	 *         For WRITE: entry (the checked name), abs (the destination), rel (below the root) and root (the root's entry prefix).
	 */
	public function decide( $name, $type = EntryWalker::TYPE_FILE ) {
		if ( EntryWalker::TYPE_SYMLINK === $type || EntryWalker::TYPE_HARDLINK === $type ) {
			return self::verdict( self::REJECT, self::R_LINK );
		}
		if ( EntryWalker::TYPE_SPECIAL === $type ) {
			return self::verdict( self::REJECT, self::R_SPECIAL );
		}

		if ( EntryWalker::TYPE_DIR === $type && is_string( $name ) && '/' === substr( $name, -1 ) ) {
			$name = substr( $name, 0, -1 );
		}
		$reason = $this->name_problem( $name );
		if ( '' !== $reason ) {
			return self::verdict( self::REJECT, $reason );
		}
		$name = (string) $name;

		if ( EntryWalker::TYPE_DIR === $type ) {
			return self::verdict( self::SKIP, self::R_DIR );
		}
		if ( 'db' === $name || 0 === strpos( $name, 'db/' ) ) {
			return self::verdict( self::SKIP, self::R_DB );
		}

		$resolved = $this->resolve( $name, false );
		if ( null === $resolved ) {
			return self::verdict( self::SKIP, self::R_UNOWNED );
		}

		$why = $this->protected_reason( $resolved['abs'] );
		if ( '' !== $why ) {
			return self::verdict( self::SKIP, $why );
		}
		return array(
			'action'   => self::WRITE,
			'reason'   => '',
			'entry'    => $name,
			'abs'      => $resolved['abs'],
			'rel'      => $resolved['rel'],
			'root'     => $resolved['root'],
			'root_abs' => $resolved['root_abs'],
		);
	}

	/**
	 * Absolute folder of a directory entry (a root itself or a folder below a dir root). For the undo of created folders.
	 *
	 * @param mixed $entry Entry name of a folder.
	 * @return array{abs:string,root:string,rel:string,root_abs:string,entry:string}|null Null for an unsafe name, a name no root owns, or a protected folder.
	 */
	public function resolve_dir( $entry ) {
		if ( '' !== $this->name_problem( $entry ) ) {
			return null;
		}
		$resolved = $this->resolve( (string) $entry, true );
		if ( null === $resolved || '' !== $this->protected_reason( $resolved['abs'] ) ) {
			return null;
		}
		return $resolved;
	}

	/**
	 * The reason a path is protected, or ''. The path is an absolute destination (also a resolved real path).
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public function protected_reason( $abs ) {
		$path = self::fold( $abs );
		foreach ( $this->protected as $item ) {
			$p = $item['path'];
			if ( 'exact' === $item['mode'] ) {
				if ( $path === $p ) {
					return $item['reason'];
				}
			} elseif ( 'prefix' === $item['mode'] ) {
				if ( 0 === strpos( $path, $p ) ) {
					return $item['reason'];
				}
			} elseif ( $path === $p || 0 === strpos( $path, $p . '/' ) ) {
				return $item['reason'];
			}
		}
		return '';
	}

	/**
	 * Self-test run before the first write: the protected paths of this site must really be protected, and an entry that maps into the plugin's own
	 * folder or its storage folder must be refused. Nothing is written.
	 *
	 * @param string[] $probe_entries Entry names that point into protected places (see probe_entries()).
	 * @return string '' when the protection works, otherwise a description.
	 */
	public function self_test( array $probe_entries ) {
		foreach ( $probe_entries as $entry ) {
			$verdict = $this->decide( $entry );
			if ( self::WRITE === $verdict['action'] ) {
				return 'The protection of the plugin\'s own files did not work for a test entry.';
			}
		}
		return '';
	}

	/**
	 * Entry names that must never be written, built from the roots of this policy and the protected paths (the plugin folder, wp-config.php ...).
	 *
	 * @param array<int,array{entry:string,abs:string,type:string}> $all_roots Roots of EVERY content flag (ContentSelector::roots with all flags on).
	 * @return string[]
	 */
	public function probe_entries( array $all_roots ) {
		$out = array();
		foreach ( $this->protected as $item ) {
			if ( 'prefix' === $item['mode'] ) {
				continue;
			}
			foreach ( $all_roots as $root ) {
				$abs = strtolower( EntryName::normalise_path( $root['abs'] ) );
				if ( 'dir' === $root['type'] && 0 === strpos( $item['path'], $abs . '/' ) ) {
					$rest  = substr( $item['path'], strlen( $abs ) + 1 );
					$out[] = $root['entry'] . '/' . $rest . ( 'tree' === $item['mode'] ? '/x.php' : '' );
				} elseif ( 'file' === $root['type'] && $item['path'] === $abs ) {
					$out[] = $root['entry'];
				} elseif ( 'shallow' === $root['type'] && 0 === strpos( $item['path'], $abs . '/' ) && false === strpos( substr( $item['path'], strlen( $abs ) + 1 ), '/' ) ) {
					$out[] = $root['entry'] . '/' . substr( $item['path'], strlen( $abs ) + 1 );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A name that is safe to put in a log line: control and bidirectional characters replaced, cut at 80 bytes.
	 *
	 * @param mixed $name Name from an archive.
	 * @return string
	 */
	public static function safe_label( $name ) {
		$name = (string) $name;
		$name = substr( $name, 0, 80 );
		$name = (string) preg_replace( '/[\x00-\x1f\x7f]/', '?', $name );
		$name = (string) preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}\x{FEFF}\x{2028}\x{2029}]/u', '?', $name );
		return 1 === preg_match( '//u', $name ) ? $name : (string) preg_replace( '/[^\x20-\x7e]/', '?', $name );
	}

	/**
	 * Translatable text of a reason.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function label( $reason ) {
		switch ( $reason ) {
			case self::R_DIR:
				return __( 'folders (created only when a file needs them)', 'openvault-backup' );
			case self::R_DB:
				return __( 'database dump (handled by the database restore)', 'openvault-backup' );
			case self::R_UNOWNED:
				return __( 'not part of the restored content', 'openvault-backup' );
			case self::R_NAME_UNSAFE:
				return __( 'unsafe file name', 'openvault-backup' );
			case self::R_NAME_LONG:
				return __( 'file name too long', 'openvault-backup' );
			case self::R_LINK:
				return __( 'link in the archive (never restored)', 'openvault-backup' );
			case self::R_SPECIAL:
				return __( 'special file in the archive (never restored)', 'openvault-backup' );
			case self::R_PROTECTED_PLUGIN:
				return __( 'protected: the OpenVault Backup folder', 'openvault-backup' );
			case self::R_PROTECTED_STORAGE:
				return __( 'protected: the OpenVault Backup storage folder', 'openvault-backup' );
			case self::R_PROTECTED_CONFIG:
				return __( 'protected: wp-config.php', 'openvault-backup' );
			case self::R_PROTECTED_SERVER:
				return __( 'protected: server configuration file', 'openvault-backup' );
			case self::R_PROTECTED_MAINT:
				return __( 'protected: .maintenance', 'openvault-backup' );
			case self::R_DEST_SYMLINK:
				return __( 'the destination is or lies behind a symlink', 'openvault-backup' );
			case self::R_DEST_NOT_DIR:
				return __( 'a file is in the way of a folder', 'openvault-backup' );
			case self::R_DEST_IS_DIR:
				return __( 'a folder is in the way of a file', 'openvault-backup' );
			case self::R_WRITE_FAILED:
				return __( 'could not be written', 'openvault-backup' );
			case self::R_ENTRY_ERROR:
				return __( 'damaged entry', 'openvault-backup' );
			case self::R_CORRUPT_SAVED:
				return __( 'the saved original is damaged', 'openvault-backup' );
		}//end switch
		return $reason;
	}

	/**
	 * Why a name cannot be used ('' when it can). Pure.
	 *
	 * @param mixed $name Name.
	 * @return string A reason constant or ''.
	 */
	private function name_problem( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return self::R_NAME_UNSAFE;
		}
		if ( strlen( $name ) > self::MAX_NAME ) {
			return self::R_NAME_LONG;
		}
		if ( ! EntryName::is_safe( $name ) ) {
			return self::R_NAME_UNSAFE;
		}
		if ( 1 === preg_match( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}\x{FEFF}\x{2028}\x{2029}]/u', $name ) ) {
			return self::R_NAME_UNSAFE;
		}
		foreach ( explode( '/', $name ) as $segment ) {
			$last = substr( $segment, -1 );
			if ( '.' === $last || ' ' === $last || ( $this->windows && false !== strpos( $segment, ':' ) ) ) {
				return self::R_NAME_UNSAFE;
			}
		}
		return '';
	}

	/**
	 * The root that owns a name and the destination below it.
	 *
	 * @param string $name    Safe entry name.
	 * @param bool   $as_dir  Resolve a folder (the root's own entry is allowed).
	 * @return array{abs:string,rel:string,root:string,entry:string,root_abs:string}|null
	 */
	private function resolve( $name, $as_dir ) {
		$best = null;
		foreach ( $this->roots as $root ) {
			$prefix = $root['entry'];
			if ( $name === $prefix || 0 === strpos( $name, $prefix . '/' ) ) {
				if ( null === $best || strlen( $prefix ) > strlen( $best['entry'] ) ) {
					$best = $root;
				}
			}
		}
		if ( null === $best ) {
			return null;
		}

		$rel = $name === $best['entry'] ? '' : substr( $name, strlen( $best['entry'] ) + 1 );
		if ( ContentSelector::TYPE_FILE === $best['type'] ) {
			if ( '' !== $rel || $as_dir ) {
				return null;
			}
			return array(
				'abs'      => $best['abs'],
				'rel'      => '',
				'root'     => $best['entry'],
				'entry'    => $name,
				'root_abs' => dirname( $best['abs'] ),
			);
		}
		if ( '' === $rel && ! $as_dir ) {
			return null;
		}
		if ( ContentSelector::TYPE_SHALLOW === $best['type'] && false !== strpos( $rel, '/' ) ) {
			return null;
		}
		return array(
			'abs'      => '' === $rel ? $best['abs'] : $best['abs'] . '/' . $rel,
			'rel'      => $rel,
			'root'     => $best['entry'],
			'entry'    => $name,
			'root_abs' => $best['abs'],
		);
	}

	/**
	 * Lower-cased path with forward slashes and no trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function fold( $path ) {
		$path = EntryName::normalise_path( $path );
		return '/' === $path ? '/' : strtolower( $path );
	}

	/**
	 * A verdict without a destination.
	 *
	 * @param string $action Action.
	 * @param string $reason Reason.
	 * @return array{action:string,reason:string,entry:string,abs:string,rel:string,root:string,root_abs:string}
	 */
	private static function verdict( $action, $reason ) {
		return array(
			'action'   => $action,
			'reason'   => $reason,
			'entry'    => '',
			'abs'      => '',
			'rel'      => '',
			'root'     => '',
			'root_abs' => '',
		);
	}
}
