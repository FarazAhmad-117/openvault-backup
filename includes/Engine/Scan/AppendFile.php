<?php
/**
 * Append-only work file that can be cut back to a saved size.
 *
 * Phase: MVP
 *
 * Purpose: A slice writes lines and then saves a cursor holding the file size. A run that was killed before it
 * saved leaves extra bytes; the next slice cuts the file back to the saved size first (see truncate_to()).
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Streams files in the work folder; memory must not grow with the file size.

namespace OpenVault\Backup\Engine\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only file.
 */
final class AppendFile {

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
	 * Bytes in the file.
	 *
	 * @var int
	 */
	private $size = 0;

	/**
	 * Constructor.
	 *
	 * @param string $path File (created when missing).
	 */
	public function __construct( $path ) {
		$this->path = (string) $path;
	}

	/**
	 * Open and cut the file back to $size bytes (0 starts it again). A file shorter than $size is an error.
	 *
	 * @param int $size Saved size.
	 * @return void
	 * @throws \RuntimeException When the file cannot be opened or is shorter than the saved size.
	 */
	public function truncate_to( $size ) {
		$this->close();
		$handle = @fopen( $this->path, 'c+b' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'A work file could not be opened for writing.' );
		}
		$stat = fstat( $handle );
		if ( false === $stat || (int) $stat['size'] < $size || ! ftruncate( $handle, $size ) || 0 !== fseek( $handle, 0, SEEK_END ) ) {
			fclose( $handle );
			throw new \RuntimeException( 'A work file is shorter than its saved position or cannot be cut back.' );
		}
		$this->handle = $handle;
		$this->size   = (int) $size;
	}

	/**
	 * Append bytes.
	 *
	 * @param string $data Data.
	 * @return void
	 * @throws \RuntimeException When the write fails (disk full).
	 */
	public function append( $data ) {
		if ( null === $this->handle ) {
			throw new \RuntimeException( 'The work file is not open.' );
		}
		$length = strlen( $data );
		if ( 0 === $length ) {
			return;
		}
		$written = fwrite( $this->handle, $data );
		if ( $length !== $written ) {
			throw new \RuntimeException( 'A work file could not be written. The disk may be full.' );
		}
		$this->size += $length;
	}

	/**
	 * Bytes in the file (after the cut and the appends).
	 *
	 * @return int
	 */
	public function size() {
		return $this->size;
	}

	/**
	 * Flush and close.
	 *
	 * @return void
	 */
	public function close() {
		if ( null !== $this->handle ) {
			fflush( $this->handle );
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
