<?php
/**
 * Reads a text file line by line from a byte offset.
 *
 * Phase: MVP
 *
 * Purpose: The scan queue and the file list live in files in the work folder, not in memory or in the job state.
 * A cursor is the byte offset after the last line that was fully processed. A last line without "\n" is a write
 * that was cut off and is never returned.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Streams files in the work folder; memory must not grow with the file size.

namespace OpenVault\Backup\Engine\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Line reader.
 */
final class LineReader {

	const MAX_LINE = 8192;

	/**
	 * Path.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Handle.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Offset after the last returned line.
	 *
	 * @var int
	 */
	private $offset;

	/**
	 * Constructor.
	 *
	 * @param string $path   File.
	 * @param int    $offset Byte offset to start at (the start of a line).
	 */
	public function __construct( $path, $offset = 0 ) {
		$this->path   = (string) $path;
		$this->offset = max( 0, (int) $offset );
	}

	/**
	 * Next complete line without its line break.
	 *
	 * @return string|null Null at the end of the file (or at a cut-off last line).
	 * @throws \RuntimeException When the file cannot be read or a line is longer than MAX_LINE.
	 */
	public function next() {
		if ( null === $this->handle ) {
			$handle = @fopen( $this->path, 'rb' );
			if ( false === $handle ) {
				throw new \RuntimeException( 'A work file could not be opened for reading.' );
			}
			if ( 0 !== fseek( $handle, $this->offset ) ) {
				fclose( $handle );
				throw new \RuntimeException( 'A work file is shorter than its saved position.' );
			}
			$this->handle = $handle;
		}

		$line = fgets( $this->handle, self::MAX_LINE + 2 );
		if ( false === $line ) {
			return null;
		}
		if ( "\n" !== substr( $line, -1 ) ) {
			if ( strlen( $line ) > self::MAX_LINE ) {
				throw new \RuntimeException( 'A work file holds a line that is too long.' );
			}
			return null;
		}
		$this->offset += strlen( $line );
		return substr( $line, 0, -1 );
	}

	/**
	 * Byte offset after the last returned line.
	 *
	 * @return int
	 */
	public function offset() {
		return $this->offset;
	}

	/**
	 * Close the file.
	 *
	 * @return void
	 */
	public function close() {
		if ( null !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Close on destruction.
	 */
	public function __destruct() {
		$this->close();
	}
}
