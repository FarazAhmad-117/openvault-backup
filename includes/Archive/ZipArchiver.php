<?php
/**
 * ZIP via ZipArchive (deflate or store mode).
 *
 * Phase: MVP
 *
 * Purpose: ZIP via ZipArchive (deflate or store mode).
 *
 * How it stays inside the limits:
 *  - ZipArchive::addFile() only remembers the path; the file is opened, compressed and written when close() runs, and
 *    every pending file keeps a handle open until then. So the archive is closed and reopened (ZipArchive::CREATE adds to
 *    the existing file) every 100 files or 16 MB, and right after a file of 8 MB or more.
 *  - libzip writes a temporary file and renames it on close(), so the part on disk always holds exactly the entries of
 *    the last successful close. end_slice() reports that size. begin_part() brings the file back to the saved state:
 *    bytes appended after it are cut off, and entries of a close that happened after the last saved cursor are
 *    deleted (a ZIP is rewritten at every close, so it cannot simply be truncated like a TAR).
 *  - Known limit: close() is atomic. One huge file (1 GB and more) is read and compressed in one go and cannot be split
 *    over several runs: with a short time limit the run can be killed again and again. Use TAR.GZ (which streams a
 *    file in chunks) or a larger time limit for such sites.
 *  - If close() fails because a file vanished or became unreadable after it was queued, the queued files are added again
 *    one by one so only the bad file is skipped (with a warning).
 *  - Empty files are stored with addFromString(); sizes above 4 GB use ZIP64 automatically; names are UTF-8.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Writes archive parts next to the other backup files; failures are thrown as ArchiveException or collected as warnings.

namespace OpenVault\Backup\Archive;

use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * ZIP archiver.
 */
final class ZipArchiver implements ArchiverInterface {

	const FLUSH_FILES = 100;
	const FLUSH_BYTES = 16777216;
	const BIG_FILE    = 8388608;

	/**
	 * Whether files are stored without compression.
	 *
	 * @var bool
	 */
	private $store;

	/**
	 * Part path.
	 *
	 * @var string
	 */
	private $path = '';

	/**
	 * Open archive (null between flushes).
	 *
	 * @var \ZipArchive|null
	 */
	private $zip;

	/**
	 * Files added since the last close: absolute path, entry name, size.
	 *
	 * @var array<int,array{0:string,1:string,2:int}>
	 */
	private $pending = array();

	/**
	 * Bytes of the pending files.
	 *
	 * @var int
	 */
	private $pending_bytes = 0;

	/**
	 * Uncompressed bytes added to this part.
	 *
	 * @var int
	 */
	private $load = 0;

	/**
	 * Warnings not yet taken.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param bool $store True to store files without compression ("zip-store").
	 */
	public function __construct( $store = false ) {
		$this->store = (bool) $store;
	}

	/**
	 * Format id.
	 *
	 * @return string
	 */
	public function id() {
		return $this->store ? 'zip-store' : 'zip';
	}

	/**
	 * Extension.
	 *
	 * @return string
	 */
	public function extension() {
		return 'zip';
	}

	/**
	 * A file is added whole.
	 *
	 * @return bool
	 */
	public function supports_partial() {
		return false;
	}

	/**
	 * Open a part.
	 *
	 * @param string $path        Part.
	 * @param int    $resume_size Saved size (0 = new part).
	 * @param int    $resume_load Saved load.
	 * @param int    $resume_entries Files in the part at the saved size.
	 * @return void
	 * @throws ArchiveException When the part is shorter than the saved size or cannot be continued.
	 */
	public function begin_part( $path, $resume_size = 0, $resume_load = 0, $resume_entries = 0 ) {
		$this->zip           = null;
		$this->pending       = array();
		$this->pending_bytes = 0;
		$this->path          = $path;
		$this->load          = (int) $resume_load;
		$this->remove_temporary_files();

		clearstatcache( true, $path );
		if ( 0 === (int) $resume_size ) {
			if ( file_exists( $path ) && ! @unlink( $path ) ) {
				throw new ArchiveException( 'An old archive part could not be replaced.' );
			}
			$this->load = 0;
			return;
		}

		$size = file_exists( $path ) ? (int) filesize( $path ) : -1;
		if ( $size < (int) $resume_size ) {
			throw new ArchiveException( 'The archive part is shorter than its saved size and cannot be continued.' );
		}
		if ( $size > (int) $resume_size ) {
			$this->cut_back( (int) $resume_size, (int) $resume_entries );
		}
	}

	/**
	 * Add a file (always whole).
	 *
	 * @param string      $absolute_path Source.
	 * @param string      $entry_name    Name in the archive.
	 * @param int         $size          Size from the list.
	 * @param int         $mtime         Ignored: libzip stores the modification time of the file.
	 * @param int         $resume_at     Must be 0.
	 * @param Budget|null $budget        Ignored: close() is atomic.
	 * @return int
	 * @throws ArchiveException When the archive cannot be opened.
	 */
	public function add_file( $absolute_path, $entry_name, $size, $mtime, $resume_at = 0, ?Budget $budget = null ) {
		$size = max( 0, (int) $size );
		clearstatcache( true, $absolute_path );
		if ( ! is_file( $absolute_path ) || ! is_readable( $absolute_path ) ) {
			$this->warnings[] = $entry_name . ' could not be read and is not in the archive';
			return -1;
		}

		$zip = $this->open();
		if ( 0 === (int) @filesize( $absolute_path ) ) {
			$added = $zip->addFromString( $entry_name, '' );
		} else {
			$added = $zip->addFile( $absolute_path, $entry_name );
		}
		if ( ! $added ) {
			$this->warnings[] = $entry_name . ' could not be added to the archive';
			return -1;
		}
		if ( $this->store ) {
			$zip->setCompressionIndex( $zip->count() - 1, \ZipArchive::CM_STORE );
		}

		$this->pending[]      = array( $absolute_path, $entry_name, $size );
		$this->pending_bytes += $size;
		$this->load          += $size;
		if ( count( $this->pending ) >= self::FLUSH_FILES || $this->pending_bytes >= self::FLUSH_BYTES || $size >= self::BIG_FILE ) {
			$this->flush();
		}
		return $size;
	}

	/**
	 * Close the archive so the next slice can continue from the size on disk.
	 *
	 * @return int Size of the part file (0 when nothing was written yet).
	 * @throws ArchiveException When the part cannot be written.
	 */
	public function end_slice() {
		$this->flush();
		clearstatcache( true, $this->path );
		return file_exists( $this->path ) ? (int) filesize( $this->path ) : 0;
	}

	/**
	 * Finish the part.
	 *
	 * @return array{path:string,size:int,sha256:string}
	 * @throws ArchiveException When the part is missing or cannot be read.
	 */
	public function finish_part() {
		$size = $this->end_slice();
		$hash = $size > 0 ? hash_file( 'sha256', $this->path ) : false;
		if ( 0 === $size || false === $hash ) {
			throw new ArchiveException( 'The archive part is empty or could not be read back.' );
		}
		return array(
			'path'   => $this->path,
			'size'   => $size,
			'sha256' => $hash,
		);
	}

	/**
	 * Uncompressed bytes added to the part.
	 *
	 * @return int
	 */
	public function size_so_far() {
		return $this->load;
	}

	/**
	 * Warnings since the last call.
	 *
	 * @return string[]
	 */
	public function take_warnings() {
		$warnings       = $this->warnings;
		$this->warnings = array();
		return $warnings;
	}

	/**
	 * Open the part and check the central directory and the number of entries.
	 *
	 * @param string $path             Part.
	 * @param int    $expected_entries Files that were added.
	 * @return string
	 */
	public function verify_part( $path, $expected_entries ) {
		$zip    = new \ZipArchive();
		$result = $zip->open( $path, \ZipArchive::CHECKCONS );
		if ( true !== $result ) {
			return 'The archive part cannot be opened as a ZIP file.';
		}
		$count = (int) $zip->count();
		$zip->close();
		if ( $count !== (int) $expected_entries ) {
			return sprintf( 'The archive part holds %1$d files, expected %2$d.', $count, (int) $expected_entries );
		}
		return '';
	}

	/**
	 * The open archive (opened on demand).
	 *
	 * @return \ZipArchive
	 * @throws ArchiveException When it cannot be opened.
	 */
	private function open() {
		if ( null === $this->zip ) {
			$zip    = new \ZipArchive();
			$result = $zip->open( $this->path, \ZipArchive::CREATE );
			if ( true !== $result ) {
				throw new ArchiveException( 'The archive part could not be opened for writing.' );
			}
			$this->zip = $zip;
		}
		return $this->zip;
	}

	/**
	 * Close the archive (this writes the queued files). On failure queue the files again one by one.
	 *
	 * @return void
	 * @throws ArchiveException When the archive cannot be opened again.
	 */
	private function flush() {
		if ( null === $this->zip ) {
			return;
		}
		$zip                 = $this->zip;
		$pending             = $this->pending;
		$this->zip           = null;
		$this->pending       = array();
		$this->pending_bytes = 0;

		if ( $this->try_close( $zip ) ) {
			// libzip stores a file that disappeared after it was queued as an empty entry and still reports success.
			clearstatcache();
			foreach ( $pending as $item ) {
				if ( $item[2] > 0 && ! is_file( $item[0] ) ) {
					$this->warnings[] = $item[1] . ' disappeared while it was archived and may be stored empty';
				}
			}
			return;
		}
		$this->discard( $zip );
		$this->remove_temporary_files();

		foreach ( $pending as $item ) {
			if ( ! is_file( $item[0] ) || ! is_readable( $item[0] ) ) {
				$this->skip_after_failure( $item );
				continue;
			}
			$single = $this->open();
			$ok     = 0 === (int) @filesize( $item[0] ) ? $single->addFromString( $item[1], '' ) : $single->addFile( $item[0], $item[1] );
			if ( $ok && $this->store ) {
				$single->setCompressionIndex( $single->count() - 1, \ZipArchive::CM_STORE );
			}
			$closed    = $ok && $this->try_close( $single );
			$this->zip = null;
			if ( ! $closed ) {
				$this->discard( $single );
				$this->remove_temporary_files();
				$this->skip_after_failure( $item );
			}
		}
	}

	/**
	 * Close an archive. PHP 8 throws for an object that is no longer usable, older versions return false.
	 *
	 * @param \ZipArchive $zip Archive.
	 * @return bool
	 */
	private function try_close( \ZipArchive $zip ) {
		try {
			return (bool) @$zip->close();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Drop the changes of an archive whose close failed, so a second close has nothing to write.
	 *
	 * @param \ZipArchive $zip Archive.
	 * @return bool
	 */
	private function discard( \ZipArchive $zip ) {
		try {
			return (bool) @$zip->unchangeAll();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * A queued file that cannot be added any more: forget its load and warn.
	 *
	 * @param array{0:string,1:string,2:int} $item Queued file.
	 * @return void
	 */
	private function skip_after_failure( array $item ) {
		$this->load       = max( 0, $this->load - $item[2] );
		$this->warnings[] = $item[1] . ' could not be added to the archive and is skipped';
	}

	/**
	 * The part on disk is larger than the saved size. libzip rewrites the whole file at every close, so there are two
	 * cases: a killed run that had closed the archive before it could save its cursor (the file is a valid ZIP with more
	 * entries than saved: the extra entries are deleted, entries keep their order), or bytes appended to the saved
	 * state (the same entries: the file is cut back to the saved size).
	 *
	 * @param int $resume_size    Saved size.
	 * @param int $resume_entries Saved number of entries.
	 * @return void
	 * @throws ArchiveException When the part cannot be brought back to the saved state.
	 */
	private function cut_back( $resume_size, $resume_entries ) {
		$zip = new \ZipArchive();
		if ( true === $zip->open( $this->path ) ) {
			$count = (int) $zip->count();
			if ( $count < $resume_entries ) {
				$zip->close();
				throw new ArchiveException( 'The archive part holds fewer files than saved and cannot be continued.' );
			}
			if ( $count > $resume_entries ) {
				for ( $index = $count - 1; $index >= $resume_entries; $index-- ) {
					$zip->deleteIndex( $index );
				}
				if ( ! $this->try_close( $zip ) ) {
					throw new ArchiveException( 'The archive part could not be brought back to its saved state.' );
				}
				return;
			}
			$zip->close();
		}

		$handle = @fopen( $this->path, 'r+b' );
		if ( false === $handle || ! ftruncate( $handle, $resume_size ) ) {
			throw new ArchiveException( 'The archive part could not be brought back to its saved size.' );
		}
		fclose( $handle );
		clearstatcache( true, $this->path );
		$check = new \ZipArchive();
		if ( true !== $check->open( $this->path, \ZipArchive::CHECKCONS ) ) {
			throw new ArchiveException( 'The archive part is damaged and cannot be continued.' );
		}
		$check->close();
	}

	/**
	 * Delete temporary files that libzip left next to the part after a crash.
	 *
	 * @return void
	 */
	private function remove_temporary_files() {
		$dir  = dirname( $this->path );
		$base = basename( $this->path );
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '\.[A-Za-z0-9]{6,}$/', (string) $name ) ) {
				@unlink( $dir . '/' . $name );
			}
		}
	}
}
