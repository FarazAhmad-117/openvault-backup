<?php
/**
 * Streams ONE named entry out of an archive part.
 *
 * Phase: MVP
 *
 * Purpose: The restore needs a single file of a part (db/db.sql of the database part) without unpacking anything else. This reader
 * copies exactly that entry into a destination file in bounded chunks and can continue after a stop at a byte offset.
 *
 * Rules:
 *  - The entry name is never a user path: it must be on the allow-list given by the caller (default: only "db/db.sql") and pass
 *    is_safe_name() (no "..", no absolute path, no backslash, no NUL or other control byte, no empty segment).
 *  - Nothing else is ever extracted. The destination is chosen by the caller (a file in the restore work folder).
 *  - ZIP and ZIP-store parts: ZipArchive locateName() / statIndex() / getStream(). TAR.GZ parts: gzopen() and TarWriter::parse_header() /
 *    parse_pax() (PAX long names and sizes, base-256 sizes); PharData cannot read the multi-member gzip files that the backup writes.
 *    Other tar entries are skipped by reading through the decompressed stream in 64 KB chunks; only a regular file with the exact
 *    name counts (links, directories and PAX/GNU headers are never extracted).
 *  - Resume: extract() takes the number of bytes already written. A compressed stream cannot seek, so the bytes before the offset are
 *    read and dropped again (decompression only, no disk write); the destination is cut back to the offset first, so bytes written by
 *    a run that was killed after its last saved offset are discarded. The reading time of the skipped bytes is not part of the budget.
 *  - The extracted size must equal the size in the archive (zip entry, tar header or PAX record), otherwise the archive is damaged.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Archive entries are streamed in chunks.

namespace OpenVault\Backup\Archive;

use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Single-entry archive reader.
 */
final class ArchiveReader {

	const CHUNK         = 262144;
	const ENTRY_DB      = 'db/db.sql';
	const ZIP           = 'zip';
	const TAR_GZ        = 'tar.gz';
	const MAX_NAME_SIZE = 4096;

	/**
	 * Archive kind of a part file name: "zip" for .zip, "tar.gz" for .tar.gz or .tgz, '' for anything else.
	 *
	 * @param string $file_name File name.
	 * @return string
	 */
	public static function kind_for( $file_name ) {
		$name = strtolower( (string) $file_name );
		if ( '.zip' === substr( $name, -4 ) ) {
			return self::ZIP;
		}
		if ( '.tar.gz' === substr( $name, -7 ) || '.tgz' === substr( $name, -4 ) ) {
			return self::TAR_GZ;
		}
		return '';
	}

	/**
	 * Whether an entry name is safe to look up: not empty, at most 4096 bytes, no NUL or control byte, no backslash, not absolute,
	 * no empty, "." or ".." segment.
	 *
	 * @param mixed $name Entry name.
	 * @return bool
	 */
	public static function is_safe_name( $name ) {
		if ( ! is_string( $name ) || '' === $name || strlen( $name ) > self::MAX_NAME_SIZE ) {
			return false;
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f\\\\]/', $name ) || '/' === $name[0] || 1 === preg_match( '#^[A-Za-z]:#', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether $name may be extracted: safe and exactly one of the allowed names.
	 *
	 * @param mixed    $name    Entry name.
	 * @param string[] $allowed Allowed names.
	 * @return bool
	 */
	public static function is_allowed( $name, array $allowed = array( self::ENTRY_DB ) ) {
		return self::is_safe_name( $name ) && in_array( $name, $allowed, true );
	}

	/**
	 * Size of an entry without extracting it.
	 *
	 * @param string $kind  "zip" or "tar.gz".
	 * @param string $part  Part file.
	 * @param string $entry Entry name.
	 * @return int Size in bytes.
	 * @throws ArchiveException When the entry is not allowed or missing, or the part is damaged.
	 */
	public static function entry_size( $kind, $part, $entry = self::ENTRY_DB ) {
		$result = self::extract( $kind, $part, $entry, '', 0, null, true );
		return $result['size'];
	}

	/**
	 * Copy an entry to a file, or continue a copy.
	 *
	 * @param string      $kind    "zip" or "tar.gz".
	 * @param string      $part    Part file.
	 * @param string      $entry   Entry name (must be allowed).
	 * @param string      $dest    Destination file (created or cut back to $offset).
	 * @param int         $offset  Bytes already copied by earlier slices (0 to start).
	 * @param Budget|null $budget  Stop after the current chunk when exhausted (at least one chunk is always copied).
	 * @param bool        $measure Only find the entry and report its size.
	 * @return array{done:bool,offset:int,size:int} Offset is the number of bytes now in the destination.
	 * @throws ArchiveException When the entry is not allowed or missing, the archive is damaged or the disk write fails.
	 */
	public static function extract( $kind, $part, $entry, $dest, $offset = 0, ?Budget $budget = null, $measure = false ) {
		if ( ! self::is_allowed( $entry, array( self::ENTRY_DB ) ) ) {
			throw new ArchiveException( 'This archive entry may not be extracted.' );
		}
		if ( ! is_string( $part ) || '' === $part || ! is_file( $part ) || is_link( $part ) ) {
			throw new ArchiveException( 'The archive part is missing.' );
		}
		$offset = max( 0, (int) $offset );

		if ( self::ZIP === $kind ) {
			return self::extract_zip( $part, $entry, $dest, $offset, $budget, (bool) $measure );
		}
		if ( self::TAR_GZ === $kind ) {
			return self::extract_tar_gz( $part, $entry, $dest, $offset, $budget, (bool) $measure );
		}
		throw new ArchiveException( 'This archive format cannot be read.' );
	}

	/**
	 * ZIP: locateName() + getStream().
	 *
	 * @param string      $part    Part.
	 * @param string      $entry   Entry.
	 * @param string      $dest    Destination.
	 * @param int         $offset  Offset.
	 * @param Budget|null $budget  Budget.
	 * @param bool        $measure Measure only.
	 * @return array{done:bool,offset:int,size:int}
	 * @throws ArchiveException On any problem.
	 */
	private static function extract_zip( $part, $entry, $dest, $offset, ?Budget $budget, $measure ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new ArchiveException( 'The ZipArchive PHP extension is not available, so this ZIP part cannot be read.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $part ) ) {
			throw new ArchiveException( 'The archive part cannot be opened as a ZIP file.' );
		}
		try {
			$index = $zip->locateName( $entry );
			if ( false === $index ) {
				throw new ArchiveException( 'The archive part does not contain the database dump.' );
			}
			$stat = $zip->statIndex( $index );
			if ( ! is_array( $stat ) || ! isset( $stat['size'] ) || '/' === substr( (string) $stat['name'], -1 ) ) {
				throw new ArchiveException( 'The archive part is damaged (the database dump entry cannot be read).' );
			}
			$size = (int) $stat['size'];
			if ( $measure ) {
				return array(
					'done'   => true,
					'offset' => 0,
					'size'   => $size,
				);
			}
			$stream = $zip->getStream( $entry );
			if ( false === $stream ) {
				throw new ArchiveException( 'The database dump cannot be read from the archive part.' );
			}
			try {
				return self::copy_stream( $stream, $size, $dest, $offset, $budget );
			} finally {
				fclose( $stream );
			}
		} finally {
			$zip->close();
		}//end try
	}

	/**
	 * TAR.GZ: gzopen() and a walk over the headers.
	 *
	 * @param string      $part    Part.
	 * @param string      $entry   Entry.
	 * @param string      $dest    Destination.
	 * @param int         $offset  Offset.
	 * @param Budget|null $budget  Budget.
	 * @param bool        $measure Measure only.
	 * @return array{done:bool,offset:int,size:int}
	 * @throws ArchiveException On any problem.
	 */
	private static function extract_tar_gz( $part, $entry, $dest, $offset, ?Budget $budget, $measure ) {
		$gz = @gzopen( $part, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A damaged part is reported as an ArchiveException.
		if ( false === $gz ) {
			throw new ArchiveException( 'The archive part cannot be opened.' );
		}
		try {
			$pending = array();
			$zeros   = 0;
			while ( $zeros < 2 ) {
				$block = self::read_exact( $gz, TarWriter::BLOCK );
				if ( null === $block ) {
					throw new ArchiveException( 'The archive part ends before the database dump was found.' );
				}
				if ( TarWriter::BLOCK === strspn( $block, "\0" ) ) {
					++$zeros;
					continue;
				}
				$zeros  = 0;
				$header = TarWriter::parse_header( $block );
				if ( null === $header ) {
					throw new ArchiveException( 'The archive part holds a damaged tar header.' );
				}
				$size = isset( $pending['size'] ) && 1 === preg_match( '/^[0-9]{1,18}\z/', (string) $pending['size'] ) ? (int) $pending['size'] : $header['size'];

				if ( 'x' === $header['type'] ) {
					if ( $header['size'] > 65536 ) {
						throw new ArchiveException( 'The archive part holds an oversized tar header.' );
					}
					$body = self::read_exact( $gz, $header['size'] + TarWriter::padding( $header['size'] ) );
					if ( null === $body ) {
						throw new ArchiveException( 'The archive part is cut off inside a header.' );
					}
					$pending = TarWriter::parse_pax( substr( $body, 0, $header['size'] ) );
					continue;
				}

				$name    = isset( $pending['path'] ) ? (string) $pending['path'] : $header['name'];
				$regular = '0' === $header['type'] || "\0" === $header['type'];
				$pending = array();

				if ( $regular && $name === $entry ) {
					if ( $measure ) {
						return array(
							'done'   => true,
							'offset' => 0,
							'size'   => $size,
						);
					}
					return self::copy_stream( $gz, $size, $dest, $offset, $budget );
				}
				if ( ! self::skip( $gz, $size + TarWriter::padding( $size ) ) ) {
					throw new ArchiveException( 'The archive part is cut off inside a file.' );
				}
			}//end while
			throw new ArchiveException( 'The archive part does not contain the database dump.' );
		} finally {
			gzclose( $gz );
		}//end try
	}

	/**
	 * Copy $size bytes from a stream positioned at the start of the entry into $dest, starting at $offset.
	 *
	 * @param resource    $stream Source (zip stream or gzip handle).
	 * @param int         $size   Entry size.
	 * @param string      $dest   Destination file.
	 * @param int         $offset Bytes already copied.
	 * @param Budget|null $budget Budget.
	 * @return array{done:bool,offset:int,size:int}
	 * @throws ArchiveException On a damaged archive or a write failure.
	 */
	private static function copy_stream( $stream, $size, $dest, $offset, ?Budget $budget ) {
		if ( '' === (string) $dest ) {
			throw new ArchiveException( 'No destination file was given.' );
		}
		if ( $offset > $size ) {
			$offset = 0;
		}
		clearstatcache( true, $dest );
		$have = is_file( $dest ) ? (int) filesize( $dest ) : 0;
		if ( $have < $offset ) {
			// The saved offset is ahead of the file (it cannot be trusted): start again.
			$offset = 0;
		}

		$out = @fopen( $dest, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming write of a large file; a failure is an ArchiveException.
		if ( false === $out ) {
			throw new ArchiveException( 'The database dump file could not be created. Check that wp-content is writable.' );
		}
		try {
			if ( ! ftruncate( $out, $offset ) || 0 !== fseek( $out, $offset ) ) {
				throw new ArchiveException( 'The database dump file could not be reset to its saved position.' );
			}
			if ( $offset > 0 && ! self::skip( $stream, $offset ) ) {
				throw new ArchiveException( 'The archive part is cut off inside the database dump.' );
			}

			$position = $offset;
			$worked   = false;
			while ( $position < $size ) {
				if ( $worked && null !== $budget && $budget->exhausted() ) {
					fflush( $out );
					return array(
						'done'   => false,
						'offset' => $position,
						'size'   => $size,
					);
				}
				$worked = true;
				$data   = fread( $stream, min( self::CHUNK, $size - $position ) );
				if ( false === $data || '' === $data ) {
					throw new ArchiveException( 'The archive part is cut off inside the database dump.' );
				}
				$length  = strlen( $data );
				$written = fwrite( $out, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming write of a large file.
				if ( $written !== $length ) {
					throw new ArchiveException( 'The database dump could not be written. The disk may be full.' );
				}
				$position += $length;
			}//end while
			fflush( $out );
			return array(
				'done'   => true,
				'offset' => $position,
				'size'   => $size,
			);
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming write of a large file.
		}//end try
	}

	/**
	 * Read exactly $length bytes from a gzip handle.
	 *
	 * @param resource $gz     Handle.
	 * @param int      $length Bytes.
	 * @return string|null Null when the stream ends early or is damaged.
	 */
	private static function read_exact( $gz, $length ) {
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
	 * Read and drop $length bytes from a gzip handle or a zip stream (64 KB at a time).
	 *
	 * @param resource $stream Handle or stream.
	 * @param int      $length Bytes.
	 * @return bool False when the stream ends early or is damaged.
	 */
	private static function skip( $stream, $length ) {
		while ( $length > 0 ) {
			$part = fread( $stream, min( 65536, $length ) );
			if ( false === $part || '' === $part ) {
				return false;
			}
			$length -= strlen( $part );
		}
		return true;
	}
}
