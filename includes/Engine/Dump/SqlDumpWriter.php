<?php
/**
 * Streams the SQL dump file.
 *
 * Phase: MVP
 *
 * Purpose: Writes db.sql statement by statement; the dump is never built in memory.
 *
 * Responsibilities:
 *  - Enforce the dump format contract: every statement on exactly one line ending with ";\n"; a statement with
 *    a raw line break is refused
 *  - begin() starts a file, resume() opens an existing one after cutting it back to the last saved byte offset
 *    (a batch written by a run that was killed before it saved its cursor is discarded and written again)
 *  - Tracks the byte offset (bytes()) that the export stores in its cursor
 *  - Header, per-table DROP + single-line CREATE, multi-row INSERT batches capped at STATEMENT_CAP bytes (one
 *    row at least), footer
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Dump;

defined( 'ABSPATH' ) || exit;

/**
 * Dump file writer.
 */
class SqlDumpWriter {

	const FORMAT_VERSION = 1;
	const END_MARKER     = '-- ovb-dump-end';

	/**
	 * Largest INSERT statement in bytes, except that a single row is never split.
	 */
	const STATEMENT_CAP = BatchSizer::STATEMENT_CAP;

	/**
	 * File path.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Open handle.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Current size of the file in bytes.
	 *
	 * @var int
	 */
	private $bytes = 0;

	/**
	 * Constructor.
	 *
	 * @param string $path File to write.
	 */
	public function __construct( $path ) {
		$this->path = (string) $path;
	}

	/**
	 * Start a new, empty file.
	 *
	 * @return void
	 * @throws DumpException When the file cannot be created.
	 */
	public function begin() {
		$this->open();
		$this->truncate_to( 0 );
	}

	/**
	 * Continue an existing file: cut it back to $offset bytes and append from there.
	 *
	 * @param int $offset Byte offset saved in the cursor.
	 * @return void
	 * @throws DumpException When the file is missing or shorter than the saved offset (data would be lost).
	 */
	public function resume( $offset ) {
		$offset = (int) $offset;
		clearstatcache( true, $this->path );
		$size = is_file( $this->path ) ? filesize( $this->path ) : false;
		if ( false === $size || $size < $offset ) {
			throw new DumpException( 'The partial database dump is missing or shorter than expected, so the export cannot continue.' );
		}
		$this->open();
		$this->truncate_to( $offset );
	}

	/**
	 * Cut the file to $offset bytes and position the writer there.
	 *
	 * @param int $offset Byte offset (0 or more).
	 * @return void
	 * @throws DumpException When the file cannot be cut.
	 */
	public function truncate_to( $offset ) {
		$offset = max( 0, (int) $offset );
		$this->open();
		if ( ! ftruncate( $this->handle, $offset ) || 0 !== fseek( $this->handle, $offset ) ) {
			throw new DumpException( 'The database dump file could not be reset to its last saved position.' );
		}
		$this->bytes = $offset;
	}

	/**
	 * Bytes written so far (the file size).
	 *
	 * @return int
	 */
	public function bytes() {
		return $this->bytes;
	}

	/**
	 * Header lines of a dump.
	 *
	 * @param array<string,scalar> $facts Key => value (written in this order after "ovb-dump").
	 * @return string[] Lines without line ends.
	 */
	public static function header_lines( array $facts ) {
		$lines = array( '-- ovb-dump: ' . self::FORMAT_VERSION );
		foreach ( $facts as $key => $value ) {
			$key     = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) );
			$value   = trim( preg_replace( '/[\x00-\x1f\x7f]+/', ' ', (string) $value ) );
			$lines[] = '-- ' . $key . ': ' . $value;
		}
		return $lines;
	}

	/**
	 * Write the header and the session settings every import needs.
	 *
	 * @param array<string,scalar> $facts   Header facts (see header_lines()).
	 * @param string               $charset Connection character set used for the export.
	 * @return void
	 */
	public function write_header( array $facts, $charset ) {
		foreach ( self::header_lines( $facts ) as $line ) {
			$this->write_raw( $line . "\n" );
		}
		$this->write_statement( 'SET NAMES ' . self::charset_name( $charset ) );
		$this->write_statement( 'SET FOREIGN_KEY_CHECKS=0' );
		$this->write_statement( 'SET UNIQUE_CHECKS=0' );
		$this->write_statement( "SET sql_mode='NO_AUTO_VALUE_ON_ZERO'" );
		$this->write_statement( "SET time_zone='+00:00'" );
	}

	/**
	 * Write DROP TABLE IF EXISTS and the single-line CREATE TABLE.
	 *
	 * @param string $table      Table name.
	 * @param string $create_sql CREATE TABLE as reported by SHOW CREATE TABLE.
	 * @return void
	 * @throws DumpException For an unsafe name or a definition that cannot be put on one line.
	 */
	public function write_table( $table, $create_sql ) {
		$this->write_statement( 'DROP TABLE IF EXISTS ' . SqlValueFormatter::identifier( $table ) );
		$this->write_statement( SqlValueFormatter::single_line( $create_sql ) );
	}

	/**
	 * Write rows as multi-row INSERT statements of at most STATEMENT_CAP bytes (a row is never split).
	 *
	 * @param string        $table   Table name.
	 * @param string[]|null $columns Column names for an explicit column list, or null for "VALUES (...)" only.
	 * @param iterable      $tuples  Rows already formatted as "(v1,v2,...)" (no raw line breaks).
	 * @return int Number of INSERT statements written.
	 * @throws DumpException For an unsafe name or a row containing a raw line break.
	 */
	public function write_rows( $table, $columns, $tuples ) {
		$prefix = 'INSERT INTO ' . SqlValueFormatter::identifier( $table );
		if ( null !== $columns ) {
			$prefix .= ' (' . implode( ',', array_map( array( SqlValueFormatter::class, 'identifier' ), $columns ) ) . ')';
		}
		$prefix .= ' VALUES ';

		$statements = 0;
		$open       = false;
		$length     = 0;
		foreach ( $tuples as $tuple ) {
			if ( false !== strpbrk( $tuple, "\r\n" ) ) {
				throw new DumpException( 'A formatted row contains a raw line break.' );
			}
			$size = strlen( $tuple );
			if ( $open && $length + $size + 1 > self::STATEMENT_CAP ) {
				$this->write_raw( ";\n" );
				$open = false;
			}
			if ( ! $open ) {
				$this->write_raw( $prefix . $tuple );
				$length = strlen( $prefix ) + $size;
				$open   = true;
				++$statements;
				continue;
			}
			$this->write_raw( ',' . $tuple );
			$length += $size + 1;
		}
		if ( $open ) {
			$this->write_raw( ";\n" );
		}
		return $statements;
	}

	/**
	 * Write the footer. The end marker proves that the dump is complete.
	 *
	 * @return void
	 */
	public function write_footer() {
		$this->write_statement( 'SET FOREIGN_KEY_CHECKS=1' );
		$this->write_statement( 'SET UNIQUE_CHECKS=1' );
		$this->write_raw( self::END_MARKER . "\n" );
	}

	/**
	 * Write one statement on one line.
	 *
	 * @param string $sql Statement without the trailing semicolon.
	 * @return void
	 * @throws DumpException When the statement contains a raw line break.
	 */
	public function write_statement( $sql ) {
		if ( false !== strpbrk( $sql, "\r\n" ) ) {
			throw new DumpException( 'A statement contains a raw line break and cannot be written to the dump.' );
		}
		$this->write_raw( $sql . ";\n" );
	}

	/**
	 * Flush and close the file.
	 *
	 * @return void
	 */
	public function close() {
		if ( null !== $this->handle ) {
			fflush( $this->handle );
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming writer for a large file.
			$this->handle = null;
		}
	}

	/**
	 * Make sure data reaches the file (called before the cursor is saved).
	 *
	 * @return void
	 */
	public function flush() {
		if ( null !== $this->handle ) {
			fflush( $this->handle );
		}
	}

	/**
	 * Character set name for "SET NAMES": letters, digits and underscore only.
	 *
	 * @param string $charset Character set.
	 * @return string
	 */
	public static function charset_name( $charset ) {
		$charset = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $charset ) );
		return '' === $charset ? 'utf8mb4' : $charset;
	}

	/**
	 * Append bytes.
	 *
	 * @param string $data Data.
	 * @return void
	 * @throws DumpException When the write fails (for example the disk is full).
	 */
	private function write_raw( $data ) {
		$this->open();
		$length  = strlen( $data );
		$written = fwrite( $this->handle, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming writer for a large file.
		if ( $written !== $length ) {
			throw new DumpException( 'The database dump could not be written. The disk may be full.' );
		}
		$this->bytes += $length;
	}

	/**
	 * Open the file for reading and writing without truncating it.
	 *
	 * @return void
	 * @throws DumpException When it cannot be opened.
	 */
	private function open() {
		if ( null !== $this->handle ) {
			return;
		}
		$handle = @fopen( $this->path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Streaming writer for a large file; a failure is reported as a DumpException.
		if ( false === $handle ) {
			throw new DumpException( 'The database dump file could not be opened for writing.' );
		}
		$this->handle = $handle;
		fseek( $this->handle, 0, SEEK_END );
	}
}
