<?php
/**
 * Resumable, streamed import of the database dump of a backup (see docs/plan/08-restore.md).
 *
 * Phase: MVP
 *
 * Purpose: Chunked SQL import with a resumable statement offset. The dump file is read line by line (never loaded whole), every line
 * goes through DumpValidator before it can reach the database, and the position is a cursor that the caller stores in the job state.
 *
 * Two passes over the same file:
 *  - validate_slice(): reads and judges EVERY line without executing anything. A hostile or damaged line stops the restore at its line
 *    number before a single table was touched. It also proves that the dump is complete (end marker) and was made with this site's
 *    table prefix, and it records the longest statement (to compare with max_allowed_packet).
 *  - import_slice(): reads the file again, judges each line again (the validation is not trusted blindly) and executes it through a
 *    SqlExecutor. Tables under "<prefix>actionscheduler_" are validated but never touched (the restore itself runs through Action
 *    Scheduler, so its queue must survive); "<prefix>ovb_" tables are refused by the validator.
 *
 * Resume safety. INSERT is not idempotent, so the cursor says whether the position can be trusted:
 *  - off, ln: byte offset and number of lines already consumed; tb: dump table in progress ('' between tables); to, tl: offset and line
 *    count in front of that table's DROP TABLE line; cl: "clean".
 *  - A slice first stores the cursor with cl = false (before it runs any statement) and stores it again after every executed
 *    statement. Only a slice that stops on its budget returns cl = true. A cursor with cl = false and a table in progress means
 *    that statements may have run after the last stored position, so resume_point() goes back to the table's DROP TABLE line and
 *    imports that table again (drop, create, insert): no duplicate rows, at the price of re-importing one table.
 *
 * The options table is special: it is imported into a shadow table "<prefix>options_ovbnew" and swapped in with one atomic RENAME TABLE
 * when its last statement is done. The plugin's own option rows (job locks, settings) are written back by the after-swap callback
 * immediately, so the running job never sees a database without them. A resume after a crash redoes the table from its DROP line.
 *
 * Errors never carry statements, values or database texts: ImportException has a fixed message, the line number, the table name and
 * the MySQL error number.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.PHP.NoSilencedErrors.Discouraged -- The dump is streamed line by line; a failure is reported as an ImportException.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ImportException texts are fixed strings with integers (line numbers, MySQL error numbers); they are escaped where they are displayed.

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Database importer.
 */
class DatabaseImporter {

	const SHADOW_SUFFIX     = '_ovbnew';
	const OLD_SUFFIX        = '_ovbold';
	const PROLOGUE_MAX_LINE = 200;
	const SKIP_PREFIX       = 'actionscheduler_';

	/**
	 * Validator.
	 *
	 * @var DumpValidator
	 */
	private $validator;

	/**
	 * Executor.
	 *
	 * @var SqlExecutor
	 */
	private $sql;

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Called just before the options table is swapped in (capture the plugin's live option rows).
	 *
	 * @var callable|null
	 */
	private $before_swap;

	/**
	 * Called right after the swap (write the plugin's option rows back).
	 *
	 * @var callable|null
	 */
	private $after_swap;

	/**
	 * Constructor.
	 *
	 * @param DumpValidator $validator   Whitelist.
	 * @param SqlExecutor   $sql         Executor.
	 * @param string        $prefix      Table prefix of the site.
	 * @param callable|null $before_swap Callback without arguments, run before the options table is swapped in.
	 * @param callable|null $after_swap  Callback without arguments, run after it.
	 */
	public function __construct( DumpValidator $validator, SqlExecutor $sql, $prefix, ?callable $before_swap = null, ?callable $after_swap = null ) {
		$this->validator   = $validator;
		$this->sql         = $sql;
		$this->prefix      = (string) $prefix;
		$this->before_swap = $before_swap;
		$this->after_swap  = $after_swap;
	}

	/**
	 * Cursor of the validation pass.
	 *
	 * @return array<string,mixed>
	 */
	public static function new_validate_cursor() {
		return array(
			'off'       => 0,
			'ln'        => 0,
			'tb'        => '',
			'cr'        => false,
			'en'        => false,
			'tables'    => 0,
			'stmts'     => 0,
			'rows'      => 0,
			'maxlen'    => 0,
			'prefix_ok' => false,
		);
	}

	/**
	 * Cursor of the import pass.
	 *
	 * @return array<string,mixed>
	 */
	public static function new_import_cursor() {
		return array(
			'off' => 0,
			'ln'  => 0,
			'tb'  => '',
			'to'  => 0,
			'tl'  => 0,
			'cr'  => false,
			'cl'  => true,
			'en'  => false,
			'td'  => 0,
			'rw'  => 0,
			'st'  => 0,
			'rt'  => 0,
			'sc'  => 0,
			'sk'  => 0,
		);
	}

	/**
	 * Where an import slice starts, given the stored cursor. Pure.
	 *
	 * A cursor that is not clean while a table is in progress goes back to the DROP TABLE line of that table (see the class comment).
	 *
	 * @param array<string,mixed> $cursor Stored cursor (missing keys get their defaults).
	 * @return array<string,mixed> Normalised cursor to start from (cl is false).
	 */
	public static function resume_point( array $cursor ) {
		$c = array_merge( self::new_import_cursor(), $cursor );
		foreach ( array( 'off', 'ln', 'to', 'tl', 'td', 'rw', 'st', 'rt', 'sc', 'sk' ) as $key ) {
			$c[ $key ] = max( 0, (int) $c[ $key ] );
		}
		$c['tb'] = is_string( $c['tb'] ) ? $c['tb'] : '';
		$c['cr'] = (bool) $c['cr'];
		$c['cl'] = true === $c['cl'];
		$c['en'] = (bool) $c['en'];

		if ( '' !== $c['tb'] && ! $c['cl'] ) {
			$c['off'] = $c['to'];
			$c['ln']  = $c['tl'];
			$c['tb']  = '';
			$c['cr']  = false;
			$c['rt']  = 0;
			$c['sc']  = 0;
			$c['to']  = 0;
			$c['tl']  = 0;
		}
		$c['cl'] = false;
		return $c;
	}

	/**
	 * Whether a table is validated but never touched: the Action Scheduler tables.
	 *
	 * @param string $name   Table name.
	 * @param string $prefix Table prefix.
	 * @return bool
	 */
	public static function is_skipped_table( $name, $prefix ) {
		$needle = strtolower( $prefix . self::SKIP_PREFIX );
		return 0 === strncmp( strtolower( (string) $name ), $needle, strlen( $needle ) );
	}

	/**
	 * Name of the shadow table of $table, null when it would be longer than a table name may be.
	 *
	 * @param string $table Table name.
	 * @return string|null
	 */
	public static function shadow_name( $table ) {
		$name = $table . self::SHADOW_SUFFIX;
		return strlen( $name ) <= DumpValidator::MAX_NAME_BYTES ? $name : null;
	}

	/**
	 * The statement to run for a validated line of the options table: the same statement for the shadow table.
	 *
	 * @param string $line   Validated line (with its semicolon).
	 * @param string $kind   DumpValidator::KIND_DROP, KIND_CREATE or KIND_INSERT.
	 * @param string $table  Real table name.
	 * @param string $shadow Shadow table name.
	 * @return string
	 */
	public static function shadow_statement( $line, $kind, $table, $shadow ) {
		if ( DumpValidator::KIND_DROP === $kind ) {
			return 'DROP TABLE IF EXISTS `' . $shadow . '`;';
		}
		$head = DumpValidator::KIND_CREATE === $kind ? 'CREATE TABLE ' : 'INSERT INTO ';
		return $head . '`' . $shadow . '`' . substr( $line, strlen( $head ) + strlen( $table ) + 2 );
	}

	/**
	 * Validate the dump without executing anything, as far as the budget allows.
	 *
	 * @param string              $path   Dump file.
	 * @param array<string,mixed> $cursor Cursor of the previous slice (new_validate_cursor() to start).
	 * @param Budget              $budget Budget.
	 * @return array{done:bool,cursor:array<string,mixed>}
	 * @throws ImportException For a refused line, an incomplete dump or an unreadable file.
	 */
	public function validate_slice( $path, array $cursor, Budget $budget ) {
		$c = array_merge( self::new_validate_cursor(), $cursor );
		foreach ( array( 'off', 'ln', 'tables', 'stmts', 'rows', 'maxlen' ) as $key ) {
			$c[ $key ] = max( 0, (int) $c[ $key ] );
		}
		$c['tb']        = is_string( $c['tb'] ) ? $c['tb'] : '';
		$c['cr']        = (bool) $c['cr'];
		$c['en']        = (bool) $c['en'];
		$c['prefix_ok'] = (bool) $c['prefix_ok'];

		$handle = self::open( $path, $c['off'] );
		try {
			$worked = false;
			while ( true ) {
				if ( $worked && $budget->exhausted() ) {
					return array(
						'done'   => false,
						'cursor' => $c,
					);
				}
				$worked = true;
				$read   = $this->read_line( $handle, $c['ln'] + 1 );
				if ( null === $read ) {
					if ( ! $c['en'] ) {
						throw new ImportException( 'The database dump is incomplete: its end marker is missing.' );
					}
					if ( ! $c['prefix_ok'] ) {
						throw new ImportException( 'The database dump does not say which table prefix it was made with.' );
					}
					if ( $c['tables'] < 1 ) {
						throw new ImportException( 'The database dump contains no tables.' );
					}
					return array(
						'done'   => true,
						'cursor' => $c,
					);
				}

				$ln   = $c['ln'] + 1;
				$info = $this->judge( $read, $ln );
				self::check_order( $c, $info, $ln );

				switch ( $info['kind'] ) {
					case DumpValidator::KIND_HEADER:
						if ( 'table-prefix' === $info['key'] ) {
							if ( $info['value'] !== $this->prefix ) {
								throw new ImportException( 'The database dump was made with another table prefix than this site uses.', $ln );
							}
							$c['prefix_ok'] = true;
						}
						break;
					case DumpValidator::KIND_END:
						$c['en'] = true;
						$c['tb'] = '';
						$c['cr'] = false;
						break;
					case DumpValidator::KIND_DROP:
						$c['tb'] = $info['table'];
						$c['cr'] = false;
						++$c['tables'];
						++$c['stmts'];
						break;
					case DumpValidator::KIND_CREATE:
						$c['cr'] = true;
						++$c['stmts'];
						break;
					case DumpValidator::KIND_INSERT:
						$c['rows'] += $info['rows'];
						++$c['stmts'];
						break;
					case DumpValidator::KIND_SET:
						++$c['stmts'];
						break;
				}//end switch
				$c['maxlen'] = max( $c['maxlen'], strlen( $read['raw'] ) );
				$c['off']   += strlen( $read['raw'] );
				$c['ln']     = $ln;
			}//end while
		} finally {
			fclose( $handle );
		}//end try
	}

	/**
	 * Import as far as the budget allows.
	 *
	 * $persist( array $cursor ) must store the cursor durably (it is called before the first statement with cl = false and after every
	 * executed statement); it throws when it cannot.
	 *
	 * @param string              $path    Dump file.
	 * @param array<string,mixed> $cursor  Stored cursor (new_import_cursor() to start).
	 * @param Budget              $budget  Budget.
	 * @param callable            $persist Cursor store.
	 * @return array{done:bool,cursor:array<string,mixed>} The returned cursor is clean (cl = true).
	 * @throws ImportException For a refused line or a statement the database rejected.
	 */
	public function import_slice( $path, array $cursor, Budget $budget, callable $persist ) {
		$c = self::resume_point( $cursor );
		$this->run_prologue( $path );

		$handle = self::open( $path, $c['off'] );
		try {
			call_user_func( $persist, $c );

			$worked = false;
			while ( true ) {
				if ( $worked && $budget->exhausted() ) {
					$c['cl'] = true;
					return array(
						'done'   => false,
						'cursor' => $c,
					);
				}
				$worked = true;
				$read   = $this->read_line( $handle, $c['ln'] + 1 );
				if ( null === $read ) {
					$this->finish_table( $c );
					$c['cl'] = true;
					return array(
						'done'   => true,
						'cursor' => $c,
					);
				}

				$ln   = $c['ln'] + 1;
				$info = $this->judge( $read, $ln );
				self::check_order( $c, $info, $ln );
				$ran = $this->apply_line( $c, $info, $read['raw'], $ln );

				$c['off'] += strlen( $read['raw'] );
				$c['ln']   = $ln;
				if ( $ran ) {
					call_user_func( $persist, $c );
				}
			}//end while
		} finally {
			fclose( $handle );
		}//end try
	}

	/**
	 * Run the statements of one validated line and update the cursor (not the offset or line count).
	 *
	 * @param array<string,mixed> $c    Cursor (updated).
	 * @param array<string,mixed> $info Result of DumpValidator::check().
	 * @param string              $raw  The line as read (with its line break).
	 * @param int                 $ln   Line number.
	 * @return bool True when a statement was executed (the cursor must be stored).
	 * @throws ImportException When the database rejects a statement.
	 */
	private function apply_line( array &$c, array $info, $raw, $ln ) {
		$kind = $info['kind'];
		$line = rtrim( $raw, "\n" );

		switch ( $kind ) {
			case DumpValidator::KIND_HEADER:
				return false;
			case DumpValidator::KIND_BLANK:
			case DumpValidator::KIND_COMMENT:
				return false;
			case DumpValidator::KIND_END:
				$this->finish_table( $c );
				$c['en'] = true;
				return false;
			case DumpValidator::KIND_SET:
				$this->finish_table( $c );
				$this->run( $line, $ln, '' );
				return true;
		}

		// drop, create, insert.
		$table = $info['table'];
		if ( DumpValidator::KIND_DROP === $kind ) {
			$this->finish_table( $c );
			$c['tb'] = $table;
			$c['to'] = $c['off'];
			$c['tl'] = $c['ln'];
			$c['cr'] = false;
			$c['rt'] = 0;
			$c['sc'] = 0;
		}

		if ( self::is_skipped_table( $table, $this->prefix ) ) {
			if ( DumpValidator::KIND_DROP === $kind ) {
				++$c['sk'];
			}
			if ( DumpValidator::KIND_CREATE === $kind ) {
				$c['cr'] = true;
			}
			return false;
		}

		$shadow = $table === $this->prefix . 'options' ? self::shadow_name( $table ) : null;
		$sql    = null === $shadow ? $line : self::shadow_statement( $line, $kind, $table, $shadow );
		$this->run( $sql, $ln, $table );

		if ( DumpValidator::KIND_CREATE === $kind ) {
			$c['cr'] = true;
		}
		if ( DumpValidator::KIND_INSERT === $kind ) {
			$c['rt'] += $info['rows'];
		}
		++$c['sc'];
		return true;
	}

	/**
	 * The table in progress is complete: swap the options table in, count it, leave "between tables".
	 *
	 * @param array<string,mixed> $c Cursor (updated).
	 * @return void
	 * @throws ImportException When the swap fails.
	 */
	private function finish_table( array &$c ) {
		if ( '' === $c['tb'] ) {
			return;
		}
		$options = $this->prefix . 'options';
		if ( $c['tb'] === $options && null !== self::shadow_name( $options ) ) {
			$this->swap_options( $options, (int) $c['ln'] );
		}
		++$c['td'];
		$c['rw'] += $c['rt'];
		$c['st'] += $c['sc'];
		$c['rt']  = 0;
		$c['sc']  = 0;
		$c['tb']  = '';
		$c['cr']  = false;
		$c['to']  = 0;
		$c['tl']  = 0;
	}

	/**
	 * Replace the options table by its shadow with one atomic RENAME TABLE, and let the plugin write its rows back.
	 *
	 * @param string $options Options table name.
	 * @param int    $ln      Line number for messages.
	 * @return void
	 * @throws ImportException When a statement fails.
	 */
	private function swap_options( $options, $ln ) {
		$shadow = (string) self::shadow_name( $options );
		$old    = $options . self::OLD_SUFFIX;

		if ( null !== $this->before_swap ) {
			call_user_func( $this->before_swap );
		}
		$this->run( 'DROP TABLE IF EXISTS `' . $old . '`', $ln, $options );
		if ( ! $this->sql->execute( 'RENAME TABLE `' . $options . '` TO `' . $old . '`, `' . $shadow . '` TO `' . $options . '`' ) ) {
			// The live options table is missing: move the new one into place.
			$this->run( 'RENAME TABLE `' . $shadow . '` TO `' . $options . '`', $ln, $options );
		}
		$this->run( 'DROP TABLE IF EXISTS `' . $old . '`', $ln, $options );
		if ( null !== $this->after_swap ) {
			call_user_func( $this->after_swap );
		}
	}

	/**
	 * Execute one statement.
	 *
	 * @param string $sql   Statement.
	 * @param int    $ln    Line number.
	 * @param string $table Table name or ''.
	 * @return void
	 * @throws ImportException When the database reports an error.
	 */
	private function run( $sql, $ln, $table ) {
		if ( $this->sql->execute( $sql ) ) {
			return;
		}
		$errno = $this->sql->errno();
		throw new ImportException(
			sprintf( 'The database rejected a statement (MySQL error %d).', $errno ),
			$ln,
			$table,
			$errno,
			in_array( $errno, array( 2006, 2013 ), true )
		);
	}

	/**
	 * Run the SET statements at the top of the dump (every slice is a new connection, so the session is prepared again) after a
	 * safe default session: no foreign key or unique checks and the sql_mode of the dump writer.
	 *
	 * @param string $path Dump file.
	 * @return void
	 * @throws ImportException When a line of the top is refused or a statement fails.
	 */
	private function run_prologue( $path ) {
		$this->run( "SET sql_mode='NO_AUTO_VALUE_ON_ZERO'", 0, '' );
		$this->run( 'SET FOREIGN_KEY_CHECKS=0', 0, '' );
		$this->run( 'SET UNIQUE_CHECKS=0', 0, '' );

		$handle = self::open( $path, 0 );
		try {
			$ln = 0;
			while ( $ln < self::PROLOGUE_MAX_LINE ) {
				$read = $this->read_line( $handle, $ln + 1 );
				++$ln;
				if ( null === $read ) {
					return;
				}
				$info = $this->judge( $read, $ln );
				if ( DumpValidator::KIND_SET === $info['kind'] ) {
					$this->run( rtrim( $read['raw'], "\n" ), $ln, '' );
					continue;
				}
				if ( in_array( $info['kind'], array( DumpValidator::KIND_DROP, DumpValidator::KIND_CREATE, DumpValidator::KIND_INSERT, DumpValidator::KIND_END ), true ) ) {
					return;
				}
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Enforce the order of one table's statements: DROP, then CREATE, then INSERTs, nothing after the end marker.
	 *
	 * @param array<string,mixed> $c    Cursor.
	 * @param array<string,mixed> $info Result of DumpValidator::check().
	 * @param int                 $ln   Line number.
	 * @return void
	 * @throws ImportException For a statement out of order.
	 */
	private static function check_order( array $c, array $info, $ln ) {
		$kind = $info['kind'];
		if ( ! empty( $c['en'] ) && ! in_array( $kind, array( DumpValidator::KIND_BLANK, DumpValidator::KIND_COMMENT, DumpValidator::KIND_HEADER ), true ) ) {
			throw new ImportException( 'The database dump contains data after its end marker.', $ln );
		}
		if ( DumpValidator::KIND_CREATE === $kind && ( $info['table'] !== $c['tb'] || $c['cr'] ) ) {
			throw new ImportException( 'The database dump has statements in an unexpected order.', $ln, '' );
		}
		if ( DumpValidator::KIND_INSERT === $kind && ( $info['table'] !== $c['tb'] || ! $c['cr'] ) ) {
			throw new ImportException( 'The database dump has statements in an unexpected order.', $ln, '' );
		}
	}

	/**
	 * Judge a line with the whitelist; a refusal becomes an ImportException that names the line number only.
	 *
	 * @param array{raw:string,line:string,terminated:bool} $read Result of read_line().
	 * @param int                                           $ln   Line number.
	 * @return array{kind:string,table:string,rows:int,key:string,value:string}
	 * @throws ImportException When the line is refused.
	 */
	private function judge( array $read, $ln ) {
		try {
			$info = $this->validator->check( $read['line'] );
		} catch ( DumpRejectedException $e ) {
			throw new ImportException(
				sprintf(
					'The database dump contains a line that is not allowed (line %1$d: %2$s).',
					$ln,
					$e->getMessage()
				),
				$ln
			);
		}
		if ( ! $read['terminated'] && ! in_array( $info['kind'], array( DumpValidator::KIND_BLANK, DumpValidator::KIND_COMMENT, DumpValidator::KIND_HEADER, DumpValidator::KIND_END ), true ) ) {
			throw new ImportException( sprintf( 'The database dump is cut off in the middle of a statement (line %d).', $ln ), $ln );
		}
		return $info;
	}

	/**
	 * Open the dump at a byte offset.
	 *
	 * @param string $path   File.
	 * @param int    $offset Offset.
	 * @return resource
	 * @throws ImportException When the file cannot be opened or positioned.
	 */
	private static function open( $path, $offset ) {
		$handle = is_string( $path ) && is_file( $path ) && ! is_link( $path ) ? @fopen( $path, 'rb' ) : false;
		if ( false === $handle ) {
			throw new ImportException( 'The database dump file cannot be read.' );
		}
		if ( $offset > 0 && 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			throw new ImportException( 'The database dump file cannot be positioned at the saved offset.' );
		}
		return $handle;
	}

	/**
	 * Read one line (at most MAX_LINE_BYTES plus its line break).
	 *
	 * @param resource $handle Open file.
	 * @param int      $ln     Number of this line (for messages).
	 * @return array{raw:string,line:string,terminated:bool}|null Null at the end of the file.
	 * @throws ImportException For a line that is longer than allowed.
	 */
	private function read_line( $handle, $ln ) {
		$raw = fgets( $handle, DumpValidator::MAX_LINE_BYTES + 2 );
		if ( false === $raw ) {
			return null;
		}
		$terminated = "\n" === substr( $raw, -1 );
		if ( ! $terminated && ! feof( $handle ) ) {
			throw new ImportException( sprintf( 'The database dump contains a line that is not allowed (line %d: the line is too long).', $ln ), $ln );
		}
		return array(
			'raw'        => $raw,
			'line'       => $terminated ? substr( $raw, 0, -1 ) : $raw,
			'terminated' => $terminated,
		);
	}
}
