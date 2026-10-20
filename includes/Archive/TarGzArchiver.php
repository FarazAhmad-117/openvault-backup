<?php
/**
 * TAR.GZ writer (streaming, needs only the zlib extension).
 *
 * Phase: MVP
 *
 * Purpose: TAR.GZ without PharData: TarWriter produces the tar stream, zlib compresses it.
 *
 * How it resumes: every slice writes ONE complete gzip member (deflate_init with ZLIB_ENCODING_GZIP, finished with
 * ZLIB_FINISH) and ends at a byte boundary of the file. Gzip members concatenate into one valid stream and the tar
 * stream simply continues across them (a file can even span slices). The engine saves the file size after each slice;
 * begin_part() cuts the part back to that size, so a killed run leaves nothing behind. deflate_add() is used instead of
 * gzopen( ..., 'ab' ) because it reports the exact compressed size at any moment (needed to split parts) and never
 * hides write errors. The compression level is zlib's default, 6.
 *
 * Source files are read in 64 KB chunks, never whole. The size and mtime come from the file list: the header holds
 * exactly that many body bytes. A file that shrank is padded with zeros, one that grew is cut at the recorded size
 * (a warning in both cases), one that vanished is skipped.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Streams archive parts and source files in chunks; failures are thrown as ArchiveException or collected as warnings.

namespace OpenVault\Backup\Archive;

use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * TAR.GZ archiver.
 */
final class TarGzArchiver implements ArchiverInterface {

	const CHUNK = 65536;

	/**
	 * Part path.
	 *
	 * @var string
	 */
	private $path = '';

	/**
	 * Part handle.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Compressed bytes in the part file so far.
	 *
	 * @var int
	 */
	private $written = 0;

	/**
	 * Deflate context of the open gzip member.
	 *
	 * @var mixed
	 */
	private $context;

	/**
	 * Warnings not yet taken.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Format id.
	 *
	 * @return string
	 */
	public function id() {
		return 'tar.gz';
	}

	/**
	 * Extension.
	 *
	 * @return string
	 */
	public function extension() {
		return 'tar.gz';
	}

	/**
	 * A file can be written over several slices.
	 *
	 * @return bool
	 */
	public function supports_partial() {
		return true;
	}

	/**
	 * Open a part.
	 *
	 * @param string $path        Part.
	 * @param int    $resume_size Saved size (0 = new part).
	 * @param int    $resume_load Ignored: the load is the file size.
	 * @param int    $resume_entries Ignored: the file is only ever appended to.
	 * @return void
	 * @throws ArchiveException When the part cannot be opened or is shorter than the saved size.
	 */
	public function begin_part( $path, $resume_size = 0, $resume_load = 0, $resume_entries = 0 ) {
		$this->abort();
		$handle = @fopen( $path, 'c+b' );
		if ( false === $handle ) {
			throw new ArchiveException( 'The archive part could not be opened for writing.' );
		}
		$stat = fstat( $handle );
		if ( false === $stat || (int) $stat['size'] < $resume_size || ! ftruncate( $handle, (int) $resume_size ) || 0 !== fseek( $handle, 0, SEEK_END ) ) {
			fclose( $handle );
			throw new ArchiveException( 'The archive part is shorter than its saved size and cannot be continued.' );
		}
		$this->path    = $path;
		$this->handle  = $handle;
		$this->written = (int) $resume_size;
		$this->context = null;
	}

	/**
	 * Add a file or continue one.
	 *
	 * @param string      $absolute_path Source.
	 * @param string      $entry_name    Name in the archive.
	 * @param int         $size          Size from the list.
	 * @param int         $mtime         Mtime from the list.
	 * @param int         $resume_at     Bytes already written.
	 * @param Budget|null $budget        Budget.
	 * @return int
	 * @throws ArchiveException When the part cannot be written.
	 */
	public function add_file( $absolute_path, $entry_name, $size, $mtime, $resume_at = 0, ?Budget $budget = null ) {
		$size       = max( 0, (int) $size );
		$position   = max( 0, (int) $resume_at );
		$source     = @fopen( $absolute_path, 'rb' );
		$unreadable = false === $source || ( $position > 0 && 0 !== fseek( $source, $position ) );

		if ( $unreadable && 0 === $position ) {
			$this->warnings[] = $this->name_for_log( $entry_name, 'could not be read and is not in the archive' );
			return -1;
		}
		if ( 0 === $position ) {
			$this->write( TarWriter::entry_header( $entry_name, $size, $mtime ) );
		}
		if ( $unreadable ) {
			if ( is_resource( $source ) ) {
				fclose( $source );
			}
			$this->pad_with_zeros( $size - $position );
			$this->write( str_repeat( "\0", TarWriter::padding( $size ) ) );
			$this->warnings[] = $this->name_for_log( $entry_name, 'disappeared while it was archived and is filled with zeros' );
			return $size;
		}

		while ( $position < $size ) {
			$chunk = fread( $source, min( self::CHUNK, $size - $position ) );
			if ( false === $chunk || '' === $chunk ) {
				$this->pad_with_zeros( $size - $position );
				$position         = $size;
				$this->warnings[] = $this->name_for_log( $entry_name, 'became smaller while it was archived and is padded with zeros' );
				break;
			}
			$this->write( $chunk );
			$position += strlen( $chunk );
			if ( $position < $size && null !== $budget && $budget->exhausted() ) {
				fclose( $source );
				return $position;
			}
		}

		if ( 0 !== $size && '' !== (string) fread( $source, 1 ) ) {
			$this->warnings[] = $this->name_for_log( $entry_name, 'became larger while it was archived; only the size seen by the scan is stored' );
		}
		fclose( $source );
		$this->write( str_repeat( "\0", TarWriter::padding( $size ) ) );
		return $size;
	}

	/**
	 * End the gzip member and close the part.
	 *
	 * @return int Size of the part file.
	 * @throws ArchiveException When the part cannot be written.
	 */
	public function end_slice() {
		$this->close_member();
		if ( null !== $this->handle ) {
			if ( ! fflush( $this->handle ) ) {
				throw new ArchiveException( 'The archive part could not be written. The disk may be full.' );
			}
			fclose( $this->handle );
			$this->handle = null;
		}
		return $this->written;
	}

	/**
	 * Write the end of the tar stream and finish the part.
	 *
	 * @return array{path:string,size:int,sha256:string}
	 * @throws ArchiveException When the part cannot be written or read back.
	 */
	public function finish_part() {
		$this->write( TarWriter::end_blocks() );
		$this->end_slice();

		clearstatcache( true, $this->path );
		$size = @filesize( $this->path );
		// A cancel can remove the folder while a slice is still running: report it, do not warn.
		$hash = is_file( $this->path ) ? hash_file( 'sha256', $this->path ) : false;
		if ( false === $size || false === $hash || (int) $size !== $this->written ) {
			throw new ArchiveException( 'The finished archive part could not be read back.' );
		}
		return array(
			'path'   => $this->path,
			'size'   => (int) $size,
			'sha256' => $hash,
		);
	}

	/**
	 * Compressed size of the part so far.
	 *
	 * @return int
	 */
	public function size_so_far() {
		return $this->written;
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
	 * Read the part back: decompress it completely and walk the tar headers.
	 *
	 * The whole part is decompressed once (a few seconds for a part of the default size); the budget is not checked
	 * while it runs.
	 *
	 * @param string $path             Part.
	 * @param int    $expected_entries Files that were added.
	 * @return string
	 */
	public function verify_part( $path, $expected_entries ) {
		$gz = @gzopen( $path, 'rb' );
		if ( false === $gz ) {
			return 'The archive part cannot be opened.';
		}
		$files   = 0;
		$zeros   = 0;
		$problem = '';
		$pending = array();
		while ( $zeros < 2 && '' === $problem ) {
			$block = $this->read_exact( $gz, TarWriter::BLOCK );
			if ( null === $block ) {
				$problem = 'The archive part ends before the end of the tar stream.';
				break;
			}
			if ( TarWriter::BLOCK === strspn( $block, "\0" ) ) {
				++$zeros;
				continue;
			}
			$zeros  = 0;
			$header = TarWriter::parse_header( $block );
			if ( null === $header ) {
				$problem = 'The archive part holds a damaged tar header.';
				break;
			}
			$size = isset( $pending['size'] ) ? (int) $pending['size'] : $header['size'];
			if ( 'x' === $header['type'] ) {
				$body = $this->read_exact( $gz, $header['size'] + TarWriter::padding( $header['size'] ) );
				if ( null === $body ) {
					$problem = 'The archive part is cut off inside a header.';
					break;
				}
				$pending = TarWriter::parse_pax( substr( $body, 0, $header['size'] ) );
				continue;
			}
			$pending = array();
			++$files;
			if ( ! $this->skip( $gz, $size + TarWriter::padding( $size ) ) ) {
				$problem = 'The archive part is cut off inside a file.';
			}
		}//end while
		gzclose( $gz );

		if ( '' === $problem && $files !== (int) $expected_entries ) {
			return sprintf( 'The archive part holds %1$d files, expected %2$d.', $files, (int) $expected_entries );
		}
		return $problem;
	}

	/**
	 * Drop a half-written state without touching the file (used before a new part starts).
	 *
	 * @return void
	 */
	private function abort() {
		$this->context = null;
		if ( null !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Compress and write bytes of the tar stream.
	 *
	 * @param string $data Data.
	 * @return void
	 * @throws ArchiveException When no part is open or the write fails.
	 */
	private function write( $data ) {
		if ( null === $this->handle ) {
			throw new ArchiveException( 'No archive part is open.' );
		}
		if ( '' === $data ) {
			return;
		}
		if ( null === $this->context ) {
			$this->context = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 6 ) );
			if ( false === $this->context ) {
				throw new ArchiveException( 'The compression could not be started.' );
			}
		}
		$this->emit( (string) deflate_add( $this->context, $data, ZLIB_NO_FLUSH ) );
	}

	/**
	 * Finish the open gzip member.
	 *
	 * @return void
	 */
	private function close_member() {
		if ( null !== $this->context && null !== $this->handle ) {
			$this->emit( (string) deflate_add( $this->context, '', ZLIB_FINISH ) );
		}
		$this->context = null;
	}

	/**
	 * Write compressed bytes to the part file.
	 *
	 * @param string $compressed Bytes.
	 * @return void
	 * @throws ArchiveException When the write fails.
	 */
	private function emit( $compressed ) {
		$length = strlen( $compressed );
		if ( 0 === $length ) {
			return;
		}
		$bytes = fwrite( $this->handle, $compressed );
		if ( $length !== $bytes ) {
			throw new ArchiveException( 'The archive part could not be written. The disk may be full.' );
		}
		$this->written += $length;
	}

	/**
	 * Write zero bytes of the body.
	 *
	 * @param int $count Bytes.
	 * @return void
	 */
	private function pad_with_zeros( $count ) {
		while ( $count > 0 ) {
			$step = min( self::CHUNK, $count );
			$this->write( str_repeat( "\0", $step ) );
			$count -= $step;
		}
	}

	/**
	 * Read exactly $length bytes from a gzip stream.
	 *
	 * @param resource $gz     Stream.
	 * @param int      $length Bytes.
	 * @return string|null Null when the stream ends early or is damaged.
	 */
	private function read_exact( $gz, $length ) {
		$data = '';
		$have = 0;
		while ( $have < $length ) {
			$part = gzread( $gz, $length - $have );
			if ( false === $part || '' === $part ) {
				return null;
			}
			$data .= $part;
			$have += strlen( $part );
		}
		return $data;
	}

	/**
	 * Read and drop $length bytes from a gzip stream.
	 *
	 * @param resource $gz     Stream.
	 * @param int      $length Bytes.
	 * @return bool False when the stream ends early or is damaged.
	 */
	private function skip( $gz, $length ) {
		while ( $length > 0 ) {
			$part = gzread( $gz, min( 1048576, $length ) );
			if ( false === $part || '' === $part ) {
				return false;
			}
			$length -= strlen( $part );
		}
		return true;
	}

	/**
	 * Warning text for one entry.
	 *
	 * @param string $entry   Entry name.
	 * @param string $message What happened.
	 * @return string
	 */
	private function name_for_log( $entry, $message ) {
		return $entry . ' ' . $message;
	}
}
