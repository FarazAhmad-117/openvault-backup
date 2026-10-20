<?php
/**
 * Contract for archive formats.
 *
 * Phase: MVP
 *
 * Purpose: Contract for archive formats.
 *
 * Responsibilities:
 *  - Write ONE part at a time, in slices: begin_part() (new, or continue at a saved size), add_file() any number of
 *    times, end_slice() (close handles, report the size that is safe to continue from), finish_part() (finalise, size
 *    and sha256). The engine saves the size from end_slice() in its cursor; begin_part() cuts the file back to it, so
 *    whatever a killed run wrote after the last saved slice is discarded.
 *  - add_file() works with a size and an mtime taken from the file list, never from the live file, and may add only
 *    a part of a big file when the budget runs out (supports_partial()); the caller calls it again with the returned
 *    position. A file that vanished, shrank or cannot be read never throws: it is skipped or padded and reported
 *    through take_warnings().
 *  - size_so_far(): the load of the part for the Splitter (compressed bytes for TAR.GZ, uncompressed bytes added for ZIP).
 *  - verify_part(): cheap re-read of a finished part (opens, counts entries). Extract and list belong to the restore.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Archive;

use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * An archive format writer.
 */
interface ArchiverInterface {

	/**
	 * Format id (zip, zip-store, tar.gz).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * File extension of a part without the dot ("zip", "tar.gz").
	 *
	 * @return string
	 */
	public function extension();

	/**
	 * Whether add_file() can stop inside a file and continue later.
	 *
	 * @return bool
	 */
	public function supports_partial();

	/**
	 * Open a part: a new one ($resume_size 0, an existing file is replaced) or continue at a saved size.
	 *
	 * @param string $path           Absolute path of the part.
	 * @param int    $resume_size    Size saved by end_slice() (0 = start a new part).
	 * @param int    $resume_load    Value of size_so_far() saved with it.
	 * @param int    $resume_entries Files in the part at that point (ZIP uses it: its file is rewritten at every close,
	 *                               so a part that is newer than the saved cursor is cut back by entries, not by size).
	 * @return void
	 * @throws ArchiveException When the part cannot be opened, is shorter than the saved size or cannot be continued.
	 */
	public function begin_part( $path, $resume_size = 0, $resume_load = 0, $resume_entries = 0 );

	/**
	 * Add a file (or continue one).
	 *
	 * @param string      $absolute_path Absolute path of the source.
	 * @param string      $entry_name    Name inside the archive (see EntryName).
	 * @param int         $size          Size from the file list.
	 * @param int         $mtime         Modification time from the file list.
	 * @param int         $resume_at     Bytes of the file already written (0 for a new file).
	 * @param Budget|null $budget        Stop inside a big file when exhausted (only when supports_partial()).
	 * @return int Bytes of the file written so far: $size when finished, less when the budget stopped it, -1 when the
	 *             file was skipped (vanished or unreadable, nothing was written for it).
	 * @throws ArchiveException When the archive cannot be written (disk full).
	 */
	public function add_file( $absolute_path, $entry_name, $size, $mtime, $resume_at = 0, ?Budget $budget = null );

	/**
	 * Close handles at a point the next slice can continue from.
	 *
	 * @return int Size of the part on disk (save it).
	 * @throws ArchiveException When the part cannot be written.
	 */
	public function end_slice();

	/**
	 * Finalise the part.
	 *
	 * @return array{path:string,size:int,sha256:string}
	 * @throws ArchiveException When the part cannot be written or read back.
	 */
	public function finish_part();

	/**
	 * Load of the open part for the Splitter.
	 *
	 * @return int
	 */
	public function size_so_far();

	/**
	 * Warnings since the last call (skipped or padded files), then forgotten.
	 *
	 * @return string[]
	 */
	public function take_warnings();

	/**
	 * Check a finished part by reading it back.
	 *
	 * @param string $path             Part.
	 * @param int    $expected_entries Number of files that were added.
	 * @return string Empty when fine, otherwise what is wrong.
	 */
	public function verify_part( $path, $expected_entries );
}
