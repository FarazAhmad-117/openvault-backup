<?php
/**
 * The folder of one restore's undo data: the journal and the copies of the files that were overwritten.
 *
 * Phase: MVP
 *
 * Purpose: "<local dir>/undo/restore-<id>/" holds journal.log (see UndoJournal) and files/<entry> (the original of every overwritten file, in the
 * same layout as the archive: "files/content/plugins/foo/bar.php"). It lives inside the plugin's protected storage folder (guard files in every
 * level that this class creates; the folder is never archived and no restore writes into it), and nothing deletes it automatically yet:
 * it is kept after a successful restore so the user can Undo, and removed by dismiss_undo() (RestoreManager). Its disk cost is the size of the
 * files that were overwritten.
 *
 * Containment: remove() deletes a folder only when its real path is "restore-<digits>" directly inside a real folder named "undo" and it is not a
 * symlink (same rule as the restore work folder).
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- File system calls of a restore report failures through their return value; a warning would end up in the log of the site.
// phpcs:disable WordPress.WP.AlternativeFunctions -- The journal is appended with the native functions on purpose (flushed per line, no WP_Filesystem credentials).

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Engine\Scan\EntryName;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Undo folder of a restore.
 */
final class UndoStore {

	const NAME    = 'undo';
	const PREFIX  = 'restore-';
	const JOURNAL = 'journal.log';
	const FILES   = 'files';

	/**
	 * Absolute folder of this restore's undo data.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Constructor.
	 *
	 * @param string $dir Absolute folder ("<local dir>/undo/restore-<id>").
	 */
	public function __construct( $dir ) {
		$this->dir = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
	}

	/**
	 * Store of a restore in the plugin's local folder.
	 *
	 * @param int $restore_id Restore id.
	 * @return self
	 * @throws \InvalidArgumentException For an invalid id.
	 * @throws \RuntimeException         When the local folder cannot be used.
	 */
	public static function for_restore( $restore_id ) {
		return new self( self::build( Paths::local_dir(), $restore_id ) );
	}

	/**
	 * Folder of a restore below a local folder (not checked on disk). Pure.
	 *
	 * @param string $local_dir  Local folder.
	 * @param int    $restore_id Restore id.
	 * @return string
	 * @throws \InvalidArgumentException For an invalid id.
	 */
	public static function build( $local_dir, $restore_id ) {
		return rtrim( str_replace( '\\', '/', (string) $local_dir ), '/' ) . '/' . self::NAME . '/' . RestoreWorkDir::folder_name( $restore_id );
	}

	/**
	 * Pure safety check: is $path "restore-<digits>" directly inside $base, and $base a folder named "undo"? Both absolute, no ".", ".." or empty segment.
	 *
	 * @param mixed $path Candidate.
	 * @param mixed $base The undo folder.
	 * @return bool
	 */
	public static function is_safe( $path, $base ) {
		if ( ! is_string( $path ) || ! is_string( $base ) || false !== strpos( $path . $base, "\0" ) ) {
			return false;
		}
		$path = EntryName::normalise_path( $path );
		$base = EntryName::normalise_path( $base );
		foreach ( array( $path, $base ) as $candidate ) {
			$absolute = ( 0 === strpos( $candidate, '/' ) ) || 1 === preg_match( '#^[A-Za-z]:/#', $candidate );
			if ( ! $absolute ) {
				return false;
			}
			foreach ( explode( '/', ltrim( $candidate, '/' ) ) as $segment ) {
				if ( '' === $segment || '.' === $segment || '..' === $segment ) {
					return false;
				}
			}
		}
		if ( self::NAME !== basename( $base ) || 0 !== strpos( $path, $base . '/' ) ) {
			return false;
		}
		$rest = substr( $path, strlen( $base ) + 1 );
		return false === strpos( $rest, '/' ) && 1 === preg_match( '/^restore-[1-9][0-9]{0,17}\z/', $rest );
	}

	/**
	 * The folder.
	 *
	 * @return string
	 */
	public function dir() {
		return $this->dir;
	}

	/**
	 * The journal file.
	 *
	 * @return string
	 */
	public function journal_path() {
		return $this->dir . '/' . self::JOURNAL;
	}

	/**
	 * Folder of the saved originals.
	 *
	 * @return string
	 */
	public function files_dir() {
		return $this->dir . '/' . self::FILES;
	}

	/**
	 * Where the saved original of an entry lives.
	 *
	 * @param string $entry Entry name.
	 * @return string
	 * @throws \InvalidArgumentException For an unsafe name.
	 */
	public function saved_path( $entry ) {
		if ( ! EntryName::is_safe( $entry ) ) {
			throw new \InvalidArgumentException( 'Unsafe name for the undo store.' );
		}
		return $this->files_dir() . '/' . $entry;
	}

	/**
	 * Create the folders (with guard files in the levels created here).
	 *
	 * @return bool
	 */
	public function ensure() {
		$undo = dirname( $this->dir );
		foreach ( array( $undo, $this->dir ) as $folder ) {
			if ( is_link( $folder ) ) {
				return false;
			}
			if ( ! is_dir( $folder ) && ! @mkdir( $folder, 0755, true ) && ! is_dir( $folder ) ) {
				return false;
			}
			foreach ( Paths::guard_files() as $name => $contents ) {
				$file = $folder . '/' . $name;
				if ( ! file_exists( $file ) ) {
					@file_put_contents( $file, $contents );
				}
			}
		}
		return is_dir( $this->dir );
	}

	/**
	 * Whether a journal exists.
	 *
	 * @return bool
	 */
	public function exists() {
		clearstatcache( true, $this->journal_path() );
		return is_file( $this->journal_path() ) && ! is_link( $this->journal_path() );
	}

	/**
	 * Whether the journal records at least one action (a header alone means nothing was changed).
	 *
	 * @return bool
	 */
	public function has_actions() {
		if ( ! $this->exists() ) {
			return false;
		}
		$handle = @fopen( $this->journal_path(), 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$found = false;
		$line  = fgets( $handle, UndoJournal::MAX_LINE + 2 );
		while ( false !== $line ) {
			$op = substr( $line, 0, 1 );
			if ( 'O' === $op || 'C' === $op || 'D' === $op ) {
				$found = true;
				break;
			}
			$line = fgets( $handle, UndoJournal::MAX_LINE + 2 );
		}
		fclose( $handle );
		return $found;
	}

	/**
	 * Create the journal with its header (nothing happens when it already exists).
	 *
	 * @param int                 $restore_id Restore id.
	 * @param array<string,mixed> $contents   Contents flags of the backup.
	 * @return bool
	 */
	public function open( $restore_id, array $contents ) {
		if ( ! $this->ensure() ) {
			return false;
		}
		if ( $this->exists() && UndoJournal::complete_end( $this->journal_path() ) > 0 ) {
			return true;
		}
		if ( file_exists( $this->journal_path() ) ) {
			@unlink( $this->journal_path() );
		}
		return $this->append( UndoJournal::header( $restore_id, time(), $contents ) );
	}

	/**
	 * Append one line (flushed before this returns: the action it announces happens after).
	 *
	 * @param string $line Line without newline (see UndoJournal).
	 * @return bool
	 */
	public function append( $line ) {
		if ( false !== strpos( $line, "\n" ) || false !== strpos( $line, "\r" ) ) {
			return false;
		}
		$handle = @fopen( $this->journal_path(), 'ab' );
		if ( false === $handle ) {
			return false;
		}
		$data = $line . "\n";
		$ok   = strlen( $data ) === @fwrite( $handle, $data ) && fflush( $handle );
		if ( $ok && function_exists( 'fsync' ) ) {
			@fsync( $handle );
		}
		fclose( $handle );
		return $ok;
	}

	/**
	 * Delete the whole folder (containment checked).
	 *
	 * @return bool True when it is gone.
	 */
	public function remove() {
		if ( is_link( $this->dir ) ) {
			return false;
		}
		if ( ! file_exists( $this->dir ) ) {
			return true;
		}
		$real      = realpath( $this->dir );
		$real_base = realpath( dirname( $this->dir ) );
		if ( false === $real || false === $real_base || ! self::is_safe( $real, $real_base ) ) {
			return false;
		}
		return CleanupStep::remove_tree( $real );
	}
}
