<?php
/**
 * Walks the entries of one archive part in order, with resume by entry count.
 *
 * Phase: MVP
 *
 * Purpose: The file restore reads every entry of a files part (zip, zip-store or tar.gz) and decides for each one what to do. This
 * class only READS: it never creates anything on disk and it never decides which names are acceptable (Restore\ExtractionPolicy does).
 * It reports the type of every entry (file, directory, symlink, hardlink, special) so links and devices can be refused by name.
 *
 * Rules:
 *  - ZIP / ZIP-store: ZipArchive statIndex() / getStream() (getStreamIndex() exists only from PHP 8.0, so the stream is opened by name; two entries
 *    with the same name are refused as damage, because the name would open the wrong body). The type comes from the Unix mode in the external attributes (symlink,
 *    directory, device, fifo, socket); a name ending in "/" is a directory.
 *  - TAR.GZ: gzopen() and TarWriter::parse_header() / parse_pax() (PharData cannot read the multi-member gzip files that the backup
 *    writes). PAX records "path", "size" and "mtime" are honoured, a GNU long name ("L") too; "g" and "K" headers are skipped. Only the
 *    type flags "0" and NUL are regular files; "5" is a directory, "2" a symlink, "1" a hardlink, everything else (devices, fifos,
 *    contiguous or sparse files) is special. The body of a non-regular entry is never read (its declared size is ignored, like tar does).
 *  - Resume is by entry count: skip_entries( n ) walks past the first n entries (tar: the compressed stream cannot seek, so it is read
 *    and dropped again; zip: the index is set). The cost of a tar.gz resume is therefore one decompression pass over the entries before it.
 *  - A damaged archive (bad tar header, a body that ends early) throws ArchiveException.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Archive entries are streamed in chunks.

namespace OpenVault\Backup\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Archive entry iterator.
 */
final class EntryWalker {

	const TYPE_FILE     = 'file';
	const TYPE_DIR      = 'dir';
	const TYPE_SYMLINK  = 'symlink';
	const TYPE_HARDLINK = 'hardlink';
	const TYPE_SPECIAL  = 'special';

	const ZIP    = 'zip';
	const TAR_GZ = 'tar.gz';

	const MAX_PAX_BYTES  = 65536;
	const MAX_LONG_NAME  = 8192;
	const SKIP_CHUNK     = 65536;
	const UNIX_TYPE_MASK = 0170000;

	/**
	 * ZipArchive (zip).
	 *
	 * @var \ZipArchive|null
	 */
	private $zip = null;

	/**
	 * Number of entries (zip).
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Index of the next entry (zip) or number of entries returned so far (tar).
	 *
	 * @var int
	 */
	private $index = 0;

	/**
	 * Gzip handle (tar.gz).
	 *
	 * @var resource|null
	 */
	private $gz = null;

	/**
	 * Zip stream of the current entry.
	 *
	 * @var resource|null
	 */
	private $stream = null;

	/**
	 * Body bytes of the current entry that were not read yet.
	 *
	 * @var int
	 */
	private $body_left = 0;

	/**
	 * Padding bytes after the body of the current tar entry.
	 *
	 * @var int
	 */
	private $padding = 0;

	/**
	 * Whether the end of the archive was reached.
	 *
	 * @var bool
	 */
	private $ended = false;

	/**
	 * Use open().
	 */
	private function __construct() {
	}

	/**
	 * Open an archive part.
	 *
	 * @param string $kind "zip" or "tar.gz".
	 * @param string $part Part file (a regular file, never a link).
	 * @return self
	 * @throws ArchiveException When the part cannot be opened.
	 */
	public static function open( $kind, $part ) {
		if ( ! is_string( $part ) || '' === $part || ! is_file( $part ) || is_link( $part ) ) {
			throw new ArchiveException( 'The archive part is missing.' );
		}
		$walker = new self();

		if ( self::ZIP === $kind ) {
			if ( ! class_exists( '\ZipArchive' ) ) {
				throw new ArchiveException( 'The ZipArchive PHP extension is not available, so this ZIP part cannot be read.' );
			}
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $part ) ) {
				throw new ArchiveException( 'The archive part cannot be opened as a ZIP file.' );
			}
			$walker->zip   = $zip;
			$walker->count = (int) $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			return $walker;
		}

		if ( self::TAR_GZ === $kind ) {
			$gz = @gzopen( $part, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A damaged part is reported as an ArchiveException.
			if ( false === $gz ) {
				throw new ArchiveException( 'The archive part cannot be opened.' );
			}
			$walker->gz = $gz;
			return $walker;
		}

		throw new ArchiveException( 'This archive format cannot be read.' );
	}

	/**
	 * Walk past the first $number entries (a resume).
	 *
	 * @param int $number Entries to skip.
	 * @return void
	 * @throws ArchiveException When the part has fewer entries or is damaged.
	 */
	public function skip_entries( $number ) {
		$number = max( 0, (int) $number );
		if ( null !== $this->zip ) {
			if ( $number > $this->count ) {
				throw new ArchiveException( 'The archive part has fewer entries than expected.' );
			}
			$this->close_stream();
			$this->index     = $number;
			$this->body_left = 0;
			return;
		}
		for ( $i = 0; $i < $number; $i++ ) {
			if ( null === $this->next() ) {
				throw new ArchiveException( 'The archive part has fewer entries than expected.' );
			}
		}
	}

	/**
	 * The next entry, or null at the end of the archive. Any unread body of the previous entry is skipped first.
	 *
	 * @return array{name:string,size:int,type:string,mtime:int}|null
	 * @throws ArchiveException When the archive is damaged.
	 */
	public function next() {
		if ( null !== $this->zip ) {
			return $this->next_zip();
		}
		return $this->next_tar();
	}

	/**
	 * Bytes of the current entry's body that are still unread.
	 *
	 * @return int
	 */
	public function remaining() {
		return $this->body_left;
	}

	/**
	 * Read up to $max bytes of the current entry's body. Returns '' only when the body is finished.
	 *
	 * @param int $max Maximum bytes.
	 * @return string
	 * @throws ArchiveException When the archive ends inside the body.
	 */
	public function read( $max ) {
		$want = min( max( 1, (int) $max ), $this->body_left );
		if ( $want < 1 ) {
			return '';
		}

		$data = false;
		if ( null !== $this->zip ) {
			// A zip stream hands out small pieces (16 KB): fill the buffer, so one call is one real chunk.
			$data = '';
			$have = 0;
			while ( $have < $want && null !== $this->stream ) {
				$piece = fread( $this->stream, $want - $have );
				if ( false === $piece || '' === $piece ) {
					break;
				}
				$data .= $piece;
				$have += strlen( $piece );
			}
		} elseif ( null !== $this->gz ) {
			$data = gzread( $this->gz, $want );
		}
		if ( false === $data || '' === $data ) {
			throw new ArchiveException( 'The archive part is cut off inside a file.' );
		}
		$this->body_left -= strlen( $data );
		return $data;
	}

	/**
	 * Drop the rest of the current entry's body.
	 *
	 * @return void
	 * @throws ArchiveException When the archive ends inside the body.
	 */
	public function skip_body() {
		if ( null !== $this->zip ) {
			$this->close_stream();
			$this->body_left = 0;
			return;
		}
		if ( $this->body_left > 0 ) {
			$this->skip_bytes( $this->body_left );
			$this->body_left = 0;
		}
	}

	/**
	 * Close the part.
	 *
	 * @return void
	 */
	public function close() {
		$this->close_stream();
		if ( null !== $this->zip ) {
			$this->zip->close();
			$this->zip = null;
		}
		if ( null !== $this->gz ) {
			gzclose( $this->gz );
			$this->gz = null;
		}
	}

	/**
	 * ZIP: the next entry.
	 *
	 * @return array{name:string,size:int,type:string,mtime:int}|null
	 * @throws ArchiveException When the entry cannot be read.
	 */
	private function next_zip() {
		$this->close_stream();
		$this->body_left = 0;
		if ( null === $this->zip || $this->index >= $this->count ) {
			return null;
		}

		$i    = $this->index;
		$stat = $this->zip->statIndex( $i );
		if ( ! is_array( $stat ) || ! isset( $stat['name'], $stat['size'] ) ) {
			throw new ArchiveException( 'The archive part is damaged (an entry cannot be read).' );
		}
		++$this->index;

		$name = (string) $stat['name'];
		$type = self::zip_type( $this->zip, $i, $name );
		$size = (int) $stat['size'];
		if ( self::TYPE_FILE === $type ) {
			if ( $this->zip->locateName( $name ) !== $i ) {
				throw new ArchiveException( 'The archive part has two entries with the same name.' );
			}
			$stream = $this->zip->getStream( $name );
			if ( false === $stream ) {
				throw new ArchiveException( 'An entry cannot be read from the archive part.' );
			}
			$this->stream    = $stream;
			$this->body_left = $size;
		} else {
			$size = 0;
		}
		return array(
			'name'  => $name,
			'size'  => $size,
			'type'  => $type,
			'mtime' => isset( $stat['mtime'] ) ? max( 0, (int) $stat['mtime'] ) : 0,
		);
	}

	/**
	 * Type of a zip entry from its Unix mode (when the creator was a Unix system) and its name.
	 *
	 * @param \ZipArchive $zip  Archive.
	 * @param int         $i    Index.
	 * @param string      $name Entry name.
	 * @return string
	 */
	private static function zip_type( \ZipArchive $zip, $i, $name ) {
		$opsys = 0;
		$attr  = 0;
		if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && \ZipArchive::OPSYS_UNIX === $opsys ) {
			return self::type_from_mode( ( (int) $attr >> 16 ) & 0xFFFF, '/' === substr( $name, -1 ) );
		}
		return '/' === substr( $name, -1 ) ? self::TYPE_DIR : self::TYPE_FILE;
	}

	/**
	 * Entry type from a Unix mode. Pure.
	 *
	 * @param int  $mode    Mode (type bits and permissions); 0 when unknown.
	 * @param bool $is_dir  Whether the name says directory.
	 * @return string One of the TYPE_ constants.
	 */
	public static function type_from_mode( $mode, $is_dir ) {
		$format = (int) $mode & self::UNIX_TYPE_MASK;
		if ( 0120000 === $format ) {
			return self::TYPE_SYMLINK;
		}
		if ( 0040000 === $format || ( 0 === $format && $is_dir ) ) {
			return self::TYPE_DIR;
		}
		if ( 0100000 === $format || 0 === $format ) {
			return $is_dir ? self::TYPE_DIR : self::TYPE_FILE;
		}
		return self::TYPE_SPECIAL;
	}

	/**
	 * Entry type from a tar type flag. Pure.
	 *
	 * @param string $flag One character.
	 * @return string One of the TYPE_ constants.
	 */
	public static function type_from_flag( $flag ) {
		switch ( $flag ) {
			case '0':
			case "\0":
				return self::TYPE_FILE;
			case '5':
				return self::TYPE_DIR;
			case '2':
				return self::TYPE_SYMLINK;
			case '1':
				return self::TYPE_HARDLINK;
		}
		return self::TYPE_SPECIAL;
	}

	/**
	 * TAR.GZ: the next entry.
	 *
	 * @return array{name:string,size:int,type:string,mtime:int}|null
	 * @throws ArchiveException When the archive is damaged.
	 */
	private function next_tar() {
		if ( $this->ended || null === $this->gz ) {
			return null;
		}
		// Finish the previous entry: unread body plus padding.
		if ( $this->body_left > 0 || $this->padding > 0 ) {
			$this->skip_bytes( $this->body_left + $this->padding );
			$this->body_left = 0;
			$this->padding   = 0;
		}

		$pending = array();
		$long    = null;
		$zeros   = 0;
		while ( true ) {
			$block = $this->read_block();
			if ( null === $block ) {
				$this->ended = true;
				return null;
			}
			if ( TarWriter::BLOCK === strspn( $block, "\0" ) ) {
				if ( ++$zeros >= 2 ) {
					$this->ended = true;
					return null;
				}
				continue;
			}
			$zeros  = 0;
			$header = TarWriter::parse_header( $block );
			if ( null === $header ) {
				throw new ArchiveException( 'The archive part holds a damaged tar header.' );
			}

			$flag = $header['type'];
			if ( 'x' === $flag ) {
				$body    = $this->read_body_block( $header['size'], self::MAX_PAX_BYTES );
				$pending = TarWriter::parse_pax( $body );
				continue;
			}
			if ( 'L' === $flag ) {
				$body = $this->read_body_block( $header['size'], self::MAX_LONG_NAME );
				$long = rtrim( $body, "\0" );
				continue;
			}
			if ( 'g' === $flag || 'K' === $flag ) {
				$this->read_body_block( $header['size'], self::MAX_PAX_BYTES );
				continue;
			}

			$name = isset( $pending['path'] ) ? (string) $pending['path'] : ( null !== $long ? $long : $header['name'] );
			$size = isset( $pending['size'] ) && 1 === preg_match( '/^[0-9]{1,18}\z/', (string) $pending['size'] ) ? (int) $pending['size'] : $header['size'];
			$type = self::type_from_flag( $flag );
			if ( isset( $pending['mtime'] ) && 1 === preg_match( '/^[0-9]{1,12}/', (string) $pending['mtime'], $found ) ) {
				$mtime = (int) $found[0];
			} else {
				$mtime = (int) octdec( trim( substr( $block, 136, 12 ), "\0 " ) );
			}

			++$this->index;
			if ( self::TYPE_FILE !== $type ) {
				$size = 0;
			}
			$this->body_left = $size;
			$this->padding   = TarWriter::padding( $size );
			return array(
				'name'  => $name,
				'size'  => $size,
				'type'  => $type,
				'mtime' => $mtime,
			);
		}//end while
	}

	/**
	 * Read one 512 byte header block. Null at a clean end of the stream.
	 *
	 * @return string|null
	 * @throws ArchiveException When the stream stops inside a block.
	 */
	private function read_block() {
		$data = '';
		$have = 0;
		while ( $have < TarWriter::BLOCK ) {
			$part = $this->gz ? gzread( $this->gz, TarWriter::BLOCK - $have ) : false;
			if ( false === $part || '' === $part ) {
				if ( '' === $data ) {
					return null;
				}
				throw new ArchiveException( 'The archive part is cut off inside a tar header.' );
			}
			$data .= $part;
			$have += strlen( $part );
		}
		return $data;
	}

	/**
	 * Read the body of a PAX / long-name header and its padding.
	 *
	 * @param int $size Body size.
	 * @param int $max  Largest accepted body.
	 * @return string Body without padding.
	 * @throws ArchiveException When the body is oversized or cut off.
	 */
	private function read_body_block( $size, $max ) {
		if ( $size > $max ) {
			throw new ArchiveException( 'The archive part holds an oversized tar header.' );
		}
		$total = $size + TarWriter::padding( $size );
		$data  = '';
		$have  = 0;
		while ( $have < $total ) {
			$part = $this->gz ? gzread( $this->gz, $total - $have ) : false;
			if ( false === $part || '' === $part ) {
				throw new ArchiveException( 'The archive part is cut off inside a header.' );
			}
			$data .= $part;
			$have += strlen( $part );
		}
		return substr( $data, 0, $size );
	}

	/**
	 * Read and drop bytes from the gzip stream.
	 *
	 * @param int $length Bytes.
	 * @return void
	 * @throws ArchiveException When the stream ends early.
	 */
	private function skip_bytes( $length ) {
		while ( $length > 0 ) {
			$part = $this->gz ? gzread( $this->gz, min( self::SKIP_CHUNK, $length ) ) : false;
			if ( false === $part || '' === $part ) {
				throw new ArchiveException( 'The archive part is cut off inside a file.' );
			}
			$length -= strlen( $part );
		}
	}

	/**
	 * Close the zip stream of the current entry.
	 *
	 * @return void
	 */
	private function close_stream() {
		if ( null !== $this->stream ) {
			fclose( $this->stream );
			$this->stream = null;
		}
	}
}
