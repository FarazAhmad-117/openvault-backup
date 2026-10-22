<?php
/**
 * Writes restored files safely: no symlink is ever followed, every write is a temp file renamed into place.
 *
 * Phase: MVP
 *
 * Purpose: The destination tree is untrusted too: somebody may have planted a symlink inside wp-content/uploads that points outside the site.
 * This class does the file system work of a restore and of an undo; Restore\ExtractionPolicy decides what may be written where.
 *
 * Rules:
 *  - The ROOT folders (the uploads folder, the plugins folder, wp-content, the WordPress folder ...) come from WordPress and are trusted, also when
 *    the site owner links one of them elsewhere. Every folder BELOW a root is walked component by component: a component that is a symlink is
 *    refused (dest_symlink), a component that is a file is refused (dest_not_dir); missing folders are created one by one (mode FS_CHMOD_DIR or
 *    0755) after the caller's journal hook ran. The real path of the final folder must lie inside the real path of the root.
 *  - The target itself is refused when it is a symlink (never written through) or a folder.
 *  - A file is written to "<name>.ovbtmp<token>" in the SAME folder (created exclusively: "x" mode does not follow a planted link) and renamed over
 *    the target (atomic on one file system). Hosts where rename() cannot replace a file get unlink and rename; the original is already saved.
 *    Mode FS_CHMOD_FILE or 0644, never an execute bit; the modification time of the archive is kept when known.
 *  - A short write is a full disk (FileRestoreException "disk_full").
 *  - save_original() copies a file that is about to be replaced into the undo store (streamed, SHA-256 while copying, written under a temporary name
 *    and renamed, so a half copy is never mistaken for a saved original).
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- File system calls of a restore report failures through their return value; a warning would end up in the log of the site.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated texts; they are escaped where they are displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions -- A restore streams files with the native functions on purpose (no WP_Filesystem credentials, no directory listing).

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Safe file writer.
 */
class SafeWriter {

	const CHUNK      = 262144;
	const TEMP_TOKEN = 'ovbtmp';

	/**
	 * Probe.
	 *
	 * @var FsProbe
	 */
	protected $fs;

	/**
	 * Constructor.
	 *
	 * @param FsProbe|null $fs Probe (default: the real file system).
	 */
	public function __construct( ?FsProbe $fs = null ) {
		$this->fs = null === $fs ? new FsProbe() : $fs;
	}

	/**
	 * Walk the folders below a root, creating the missing ones.
	 *
	 * @param string        $root_abs    Absolute root folder (trusted).
	 * @param string        $root_entry  Entry prefix of the root (for the journal names of created folders).
	 * @param string[]      $segments    Folder names below the root.
	 * @param callable|null $before_mkdir function( string $dir_entry ): bool, called before each folder is created (false stops with "journal").
	 * @return array{ok:bool,reason:string,dir:string}
	 * @throws FileRestoreException When a folder cannot be created for a systemic reason.
	 */
	public function prepare_dir( $root_abs, $root_entry, array $segments, ?callable $before_mkdir = null ) {
		$current = rtrim( str_replace( '\\', '/', (string) $root_abs ), '/' );
		$entry   = (string) $root_entry;

		if ( ! $this->fs->is_dir( $current ) ) {
			if ( $this->fs->exists( $current ) ) {
				return self::refusal( ExtractionPolicy::R_DEST_NOT_DIR );
			}
			if ( ! $this->make_dir( $current, $entry, $before_mkdir, true ) ) {
				return self::refusal( ExtractionPolicy::R_WRITE_FAILED );
			}
		}

		foreach ( $segments as $segment ) {
			$current .= '/' . $segment;
			$entry   .= '/' . $segment;
			if ( $this->fs->is_link( $current ) ) {
				return self::refusal( ExtractionPolicy::R_DEST_SYMLINK );
			}
			if ( $this->fs->is_dir( $current ) ) {
				continue;
			}
			if ( $this->fs->exists( $current ) ) {
				return self::refusal( ExtractionPolicy::R_DEST_NOT_DIR );
			}
			if ( ! $this->make_dir( $current, $entry, $before_mkdir, false ) ) {
				return self::refusal( ExtractionPolicy::R_WRITE_FAILED );
			}
		}

		if ( ! $this->inside( $current, (string) $root_abs ) ) {
			return self::refusal( ExtractionPolicy::R_DEST_SYMLINK );
		}
		return array(
			'ok'     => true,
			'reason' => '',
			'dir'    => $current,
		);
	}

	/**
	 * Walk the folders below a root without creating anything (for the undo of a created file or folder).
	 *
	 * @param string   $root_abs Absolute root folder (trusted).
	 * @param string[] $segments Folder names below the root.
	 * @return array{ok:bool,reason:string,exists:bool,dir:string}
	 */
	public function walk_existing( $root_abs, array $segments ) {
		$current = rtrim( str_replace( '\\', '/', (string) $root_abs ), '/' );
		foreach ( $segments as $segment ) {
			$current .= '/' . $segment;
			if ( $this->fs->is_link( $current ) ) {
				return array(
					'ok'     => false,
					'reason' => ExtractionPolicy::R_DEST_SYMLINK,
					'exists' => true,
					'dir'    => $current,
				);
			}
			if ( ! $this->fs->exists( $current ) ) {
				return array(
					'ok'     => true,
					'reason' => '',
					'exists' => false,
					'dir'    => $current,
				);
			}
			if ( ! $this->fs->is_dir( $current ) ) {
				return array(
					'ok'     => false,
					'reason' => ExtractionPolicy::R_DEST_NOT_DIR,
					'exists' => true,
					'dir'    => $current,
				);
			}
		}//end foreach
		$inside = $this->inside( $current, (string) $root_abs );
		return array(
			'ok'     => $inside,
			'reason' => $inside ? '' : ExtractionPolicy::R_DEST_SYMLINK,
			'exists' => true,
			'dir'    => $current,
		);
	}

	/**
	 * What is at a target path: missing, file, link, dir or other.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public function target_state( $path ) {
		if ( $this->fs->is_link( $path ) ) {
			return 'link';
		}
		if ( $this->fs->is_dir( $path ) ) {
			return 'dir';
		}
		if ( $this->fs->is_file( $path ) ) {
			return 'file';
		}
		return $this->fs->exists( $path ) ? 'other' : 'missing';
	}

	/**
	 * Name of the temporary file of a target.
	 *
	 * @param string $dir   Folder of the target.
	 * @param string $name  File name of the target.
	 * @param string $token Random token (hex).
	 * @return string
	 */
	public static function temp_path( $dir, $name, $token ) {
		$base = strlen( $name ) > 200 ? substr( hash( 'sha256', $name ), 0, 16 ) : $name;
		return rtrim( $dir, '/' ) . '/' . $base . '.' . self::TEMP_TOKEN . $token;
	}

	/**
	 * Open the temporary file: new (exclusive, a planted link or file is removed first) or, when $offset > 0, the file of an earlier slice cut back
	 * to the bytes it really holds (at most $offset).
	 *
	 * @param string $temp   Temp path.
	 * @param int    $offset Bytes already written by earlier slices.
	 * @return array{0:resource,1:int}|null Handle (positioned at the end) and the real start offset; null when it cannot be opened.
	 */
	public function open_temp( $temp, $offset ) {
		$start = 0;
		if ( $offset > 0 && ! $this->fs->is_link( $temp ) && $this->fs->is_file( $temp ) ) {
			$start = min( (int) $offset, (int) filesize( $temp ) );
		}

		if ( $start > 0 ) {
			$handle = @fopen( $temp, 'r+b' );
			if ( false === $handle ) {
				return null;
			}
			if ( ! ftruncate( $handle, $start ) || 0 !== fseek( $handle, $start ) ) {
				fclose( $handle );
				return null;
			}
			return array( $handle, $start );
		}

		if ( $this->fs->exists( $temp ) ) {
			@unlink( $temp );
		}
		$handle = @fopen( $temp, 'xb' );
		return false === $handle ? null : array( $handle, 0 );
	}

	/**
	 * Write one chunk. A short write (full disk) returns false. Overridable so a test can simulate a full disk.
	 *
	 * @param resource $handle Handle.
	 * @param string   $data   Data.
	 * @return bool
	 */
	public function write_chunk( $handle, $data ) {
		$written = @fwrite( $handle, $data );
		return strlen( $data ) === $written;
	}

	/**
	 * Copy chunks from a reader into an open handle until $size bytes are there or the budget is used up (one chunk is always copied).
	 *
	 * @param resource    $handle Handle of the temp file (positioned at $start).
	 * @param int         $start  Bytes already in the file.
	 * @param int         $size   Total size.
	 * @param callable    $read   function( int $max ): string. Returns '' (or throws) when the source ends early.
	 * @param Budget|null $budget Budget.
	 * @return array{0:bool,1:int} Done and the number of bytes now in the file.
	 * @throws ArchiveException      When the source ends before $size bytes.
	 * @throws FileRestoreException  When the disk is full.
	 */
	public function copy_chunks( $handle, $start, $size, callable $read, ?Budget $budget = null ) {
		$pos    = (int) $start;
		$worked = false;
		while ( $pos < $size ) {
			if ( $worked && null !== $budget && $budget->exhausted() ) {
				fflush( $handle );
				return array( false, $pos );
			}
			$worked = true;
			$data   = $read( (int) min( self::CHUNK, $size - $pos ) );
			if ( ! is_string( $data ) || '' === $data ) {
				throw new ArchiveException( 'A file ends before its recorded size.' );
			}
			if ( ! $this->write_chunk( $handle, $data ) ) {
				throw new FileRestoreException( 'disk_full', __( 'A file could not be written. The disk may be full.', 'openvault-backup' ) );
			}
			$pos += strlen( $data );
		}
		fflush( $handle );
		return array( true, $pos );
	}

	/**
	 * Move a finished temp file onto its target: mode, modification time, rename (unlink and rename where rename cannot replace).
	 *
	 * @param string $temp   Temp path.
	 * @param string $target Target path.
	 * @param int    $mtime  Modification time from the archive (0 = keep now).
	 * @return bool
	 */
	public function commit( $temp, $target, $mtime ) {
		@chmod( $temp, self::file_mode() );
		if ( $mtime > 0 && $mtime < 4102444800 ) {
			@touch( $temp, (int) $mtime );
		}
		if ( @rename( $temp, $target ) ) {
			return true;
		}
		if ( $this->fs->is_file( $target ) && ! $this->fs->is_link( $target ) && @unlink( $target ) ) {
			return @rename( $temp, $target );
		}
		return false;
	}

	/**
	 * Remove a temp file.
	 *
	 * @param string $temp Temp path.
	 * @return void
	 */
	public function discard( $temp ) {
		if ( $this->fs->exists( $temp ) ) {
			@unlink( $temp );
		}
	}

	/**
	 * Copy the file about to be replaced into the undo store.
	 *
	 * @param string $target Existing target (a regular file, checked by the caller).
	 * @param string $saved  Final path in the undo store.
	 * @return array{size:int,sha256:string}|null Null when the original cannot be read.
	 * @throws FileRestoreException When the copy cannot be written.
	 */
	public function save_original( $target, $saved ) {
		if ( $this->fs->is_file( $saved ) && ! $this->fs->is_link( $saved ) ) {
			// An earlier attempt saved it (a crash after the copy): the first copy is the original, keep it.
			$hash = hash_file( 'sha256', $saved );
			return false === $hash ? null : array(
				'size'   => (int) filesize( $saved ),
				'sha256' => $hash,
			);
		}

		$dir = dirname( $saved );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw new FileRestoreException( 'journal', __( 'The folder for the undo copies could not be created.', 'openvault-backup' ) );
		}

		$source = @fopen( $target, 'rb' );
		if ( false === $source ) {
			return null;
		}
		$tmp = $saved . '.tmp';
		if ( $this->fs->exists( $tmp ) ) {
			@unlink( $tmp );
		}
		$out = @fopen( $tmp, 'xb' );
		if ( false === $out ) {
			fclose( $source );
			throw new FileRestoreException( 'journal', __( 'The undo copy of a file could not be created.', 'openvault-backup' ) );
		}

		$mtime   = (int) @filemtime( $target );
		$context = hash_init( 'sha256' );
		$size    = 0;
		try {
			while ( ! feof( $source ) ) {
				$data = fread( $source, self::CHUNK );
				if ( false === $data ) {
					throw new FileRestoreException( 'journal', __( 'A file could not be read for the undo copy.', 'openvault-backup' ) );
				}
				if ( '' === $data ) {
					break;
				}
				if ( ! $this->write_chunk( $out, $data ) ) {
					throw new FileRestoreException( 'disk_full', __( 'The undo copy of a file could not be written. The disk may be full.', 'openvault-backup' ) );
				}
				hash_update( $context, $data );
				$size += strlen( $data );
			}
			fflush( $out );
		} catch ( FileRestoreException $e ) {
			fclose( $source );
			fclose( $out );
			@unlink( $tmp );
			throw $e;
		}//end try
		fclose( $source );
		fclose( $out );

		if ( ! @rename( $tmp, $saved ) ) {
			@unlink( $tmp );
			throw new FileRestoreException( 'journal', __( 'The undo copy of a file could not be stored.', 'openvault-backup' ) );
		}
		if ( $mtime > 0 ) {
			@touch( $saved, $mtime );
			// The original's modification time comes back with the original.
		}
		return array(
			'size'   => $size,
			'sha256' => hash_final( $context ),
		);
	}

	/**
	 * File mode of restored files: FS_CHMOD_FILE or 0644, without any execute bit. Pure apart from the constant.
	 *
	 * @return int
	 */
	public static function file_mode() {
		$mode = defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : 0644;
		return $mode & 0666;
	}

	/**
	 * Folder mode: FS_CHMOD_DIR or 0755.
	 *
	 * @return int
	 */
	public static function dir_mode() {
		return defined( 'FS_CHMOD_DIR' ) ? (int) FS_CHMOD_DIR : 0755;
	}

	/**
	 * Whether the real path of $path lies inside the real path of $root (or is it).
	 *
	 * @param string $path Folder.
	 * @param string $root Root folder.
	 * @return bool
	 */
	private function inside( $path, $root ) {
		$real_path = $this->fs->realpath( $path );
		$real_root = $this->fs->realpath( $root );
		if ( false === $real_path || false === $real_root ) {
			return false;
		}
		$real_path = rtrim( str_replace( '\\', '/', $real_path ), '/' );
		$real_root = rtrim( str_replace( '\\', '/', $real_root ), '/' );
		return $real_path === $real_root || 0 === strpos( $real_path . '/', $real_root . '/' );
	}

	/**
	 * Create one folder (after the journal hook).
	 *
	 * @param string        $dir          Absolute folder.
	 * @param string        $entry        Journal name of the folder.
	 * @param callable|null $before_mkdir Hook.
	 * @param bool          $recursive    Create missing parents too (a missing root).
	 * @return bool
	 * @throws FileRestoreException When the hook says the journal cannot be written.
	 */
	private function make_dir( $dir, $entry, ?callable $before_mkdir, $recursive ) {
		if ( null !== $before_mkdir && ! call_user_func( $before_mkdir, $entry ) ) {
			throw new FileRestoreException( 'journal', __( 'The undo journal could not be written.', 'openvault-backup' ) );
		}
		return @mkdir( $dir, self::dir_mode(), (bool) $recursive ) || $this->fs->is_dir( $dir );
	}

	/**
	 * A refusal result.
	 *
	 * @param string $reason Reason.
	 * @return array{ok:bool,reason:string,dir:string}
	 */
	private static function refusal( $reason ) {
		return array(
			'ok'     => false,
			'reason' => $reason,
			'dir'    => '',
		);
	}
}
