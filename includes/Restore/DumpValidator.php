<?php
/**
 * Statement whitelist for the database dump that a restore imports (SECURITY CRITICAL, pure).
 *
 * Phase: MVP
 *
 * Purpose: The dump is UNTRUSTED input. A backup can sit in a bucket or a folder that somebody else can write to, and a restore runs
 * its statements with the full rights of the site's database user. Nothing is ever executed unless check() accepts the line.
 *
 * What a line may be (the format that Engine\Dump\SqlDumpWriter writes, nothing else; exact keyword case, no extra spaces):
 *  - an empty line, or a comment line "-- ..." (never executed). "-- key: value" lines are returned as "header" (the importer reads
 *    the table prefix from it), "-- ovb-dump-end" as "end".
 *  - SET NAMES <charset> (charset from a short list), SET FOREIGN_KEY_CHECKS=0|1, SET UNIQUE_CHECKS=0|1, SET sql_mode='<modes>' (modes
 *    from a list that never contains NO_BACKSLASH_ESCAPES or ANSI_QUOTES), SET time_zone='+00:00'.
 *  - DROP TABLE IF EXISTS `table`.
 *  - CREATE TABLE `table` ( ... ) ...: one statement (no ";" outside a string literal), no comment of any kind (the one exact comment
 *    that MySQL 8 adds, /*!80016 DEFAULT ENCRYPTION='N' *\/, is the only exception), no backslash outside a literal, no "schema.table"
 *    name, no reference to a plugin table, ENGINE limited to InnoDB, MyISAM and Aria, and none of the words that read or write files,
 *    create code or users, or open connections (SELECT, OUTFILE, LOAD_FILE, DIRECTORY, TRIGGER, PROCEDURE, FUNCTION, EVENT, GRANT,
 *    PASSWORD, CONNECTION, SLEEP, ...).
 *  - INSERT INTO `table` [(`col`,...)] VALUES (v,...),(v,...); where a value is NULL, a number, 0x<hex> or a '...' string literal. The
 *    VALUES part is read with a real tokenizer (backslash escapes and doubled quotes), so a ";" or "--" inside a string is data while
 *    anything outside a string that is not the grammar above (a second statement, a comment, a function, a space) is refused.
 *  - Table names: only letters, digits, "_" and "$", at most 64 characters, they must start with the site's table prefix and must not
 *    start with "<prefix>ovb_" (the plugin's own tables, any letter case).
 *  - A line is at most 4 MB (the dump itself keeps statements near 512 KB), valid UTF-8, without NUL, CR or LF.
 *
 * A refusal throws DumpRejectedException with a fixed message (never the line, never a value). The line number is added by the caller.
 *
 * check() returns array{kind:string,table:string,rows:int,key:string,value:string}: kind is blank, comment, header, end, set, drop,
 * create or insert; table is set for drop, create and insert; rows counts the tuples of an insert; key and value belong to a header.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Dump line whitelist.
 */
final class DumpValidator {

	const MAX_LINE_BYTES = 4194304;
	const MAX_NAME_BYTES = 64;

	const KIND_BLANK   = 'blank';
	const KIND_COMMENT = 'comment';
	const KIND_HEADER  = 'header';
	const KIND_END     = 'end';
	const KIND_SET     = 'set';
	const KIND_DROP    = 'drop';
	const KIND_CREATE  = 'create';
	const KIND_INSERT  = 'insert';

	const END_MARKER = '-- ovb-dump-end';

	const CHARSETS = array( 'utf8mb4', 'utf8mb3', 'utf8', 'latin1', 'ascii', 'binary' );

	const SQL_MODES = array(
		'NO_AUTO_VALUE_ON_ZERO',
		'NO_ENGINE_SUBSTITUTION',
		'NO_ZERO_DATE',
		'NO_ZERO_IN_DATE',
		'ALLOW_INVALID_DATES',
		'STRICT_TRANS_TABLES',
		'STRICT_ALL_TABLES',
		'ONLY_FULL_GROUP_BY',
		'ERROR_FOR_DIVISION_BY_ZERO',
	);

	const ENGINES = array( 'innodb', 'myisam', 'aria' );

	/**
	 * Words that never belong in a CREATE TABLE statement of a WordPress dump.
	 */
	const FORBIDDEN_WORDS = array(
		'select',
		'outfile',
		'dumpfile',
		'load_file',
		'load',
		'directory',
		'trigger',
		'procedure',
		'function',
		'event',
		'grant',
		'password',
		'connection',
		'sleep',
		'benchmark',
		'handler',
		'call',
		'prepare',
		'execute',
		'tablespace',
		'user',
		'drop',
		'create',
		'insert',
		'truncate',
		'rename',
		'alter',
		'use',
		'union',
		'into',
	);

	/**
	 * The one executable comment a MySQL 8 server adds to SHOW CREATE TABLE.
	 */
	const ALLOWED_COMMENT = "/*!80016 DEFAULT ENCRYPTION='N' */";

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Lower-case "<prefix>ovb_".
	 *
	 * @var string
	 */
	private $plugin_prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Table prefix of this site ($wpdb->base_prefix): letters, digits, "_" and "$", not empty.
	 * @throws \InvalidArgumentException For an invalid prefix.
	 */
	public function __construct( $prefix ) {
		if ( ! is_string( $prefix ) || 1 !== preg_match( '/^[A-Za-z0-9_$]{1,40}\z/', $prefix ) ) {
			throw new \InvalidArgumentException( 'Invalid table prefix.' );
		}
		$this->prefix        = $prefix;
		$this->plugin_prefix = strtolower( $prefix . 'ovb_' );
	}

	/**
	 * Judge one line.
	 *
	 * @param string $line One line of the dump without its line break.
	 * @return array{kind:string,table:string,rows:int,key:string,value:string}
	 * @throws DumpRejectedException When the line is not allowed.
	 */
	public function check( $line ) {
		$line = (string) $line;
		$size = strlen( $line );

		if ( 0 === $size ) {
			return self::result( self::KIND_BLANK );
		}
		if ( $size > self::MAX_LINE_BYTES ) {
			throw new DumpRejectedException( 'the line is too long' );
		}
		if ( false !== strpbrk( $line, "\0\r\n" ) ) {
			throw new DumpRejectedException( 'the line contains a control character' );
		}
		if ( 1 !== preg_match( '//u', $line ) ) {
			throw new DumpRejectedException( 'the line is not valid UTF-8' );
		}

		if ( '-' === $line[0] ) {
			return $this->check_comment( $line );
		}
		if ( ';' !== $line[ $size - 1 ] ) {
			throw new DumpRejectedException( 'the statement does not end with a semicolon' );
		}
		$body = substr( $line, 0, -1 );

		if ( 0 === strncmp( $body, 'SET ', 4 ) ) {
			return $this->check_set( $body );
		}
		if ( 0 === strncmp( $body, 'DROP TABLE IF EXISTS ', 21 ) ) {
			return $this->check_drop( $body );
		}
		if ( 0 === strncmp( $body, 'CREATE TABLE ', 13 ) ) {
			return $this->check_create( $body );
		}
		if ( 0 === strncmp( $body, 'INSERT INTO ', 12 ) ) {
			return $this->check_insert( $body );
		}
		throw new DumpRejectedException( 'this kind of statement is not allowed' );
	}

	/**
	 * Whether a table name may be touched by a restore: valid identifier, starts with the prefix, is not a plugin table.
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	public function is_allowed_table( $name ) {
		if ( ! is_string( $name ) || strlen( $name ) > self::MAX_NAME_BYTES || 1 !== preg_match( '/^[A-Za-z0-9_$]+\z/', $name ) ) {
			return false;
		}
		if ( 0 !== strncmp( $name, $this->prefix, strlen( $this->prefix ) ) || strlen( $name ) <= strlen( $this->prefix ) ) {
			return false;
		}
		return 0 !== strncmp( strtolower( $name ), $this->plugin_prefix, strlen( $this->plugin_prefix ) );
	}

	/**
	 * Empty result.
	 *
	 * @param string $kind  Kind.
	 * @param string $table Table.
	 * @param int    $rows  Rows.
	 * @param string $key   Header key.
	 * @param string $value Header value.
	 * @return array{kind:string,table:string,rows:int,key:string,value:string}
	 */
	private static function result( $kind, $table = '', $rows = 0, $key = '', $value = '' ) {
		return array(
			'kind'  => $kind,
			'table' => $table,
			'rows'  => $rows,
			'key'   => $key,
			'value' => $value,
		);
	}

	/**
	 * A comment line: blank comment, header "-- key: value", end marker or free text.
	 *
	 * @param string $line Line.
	 * @return array{kind:string,table:string,rows:int,key:string,value:string}
	 * @throws DumpRejectedException For a line that starts with "-" but is not a comment.
	 */
	private function check_comment( $line ) {
		if ( self::END_MARKER === $line ) {
			return self::result( self::KIND_END );
		}
		if ( '--' !== $line && 0 !== strncmp( $line, '-- ', 3 ) ) {
			throw new DumpRejectedException( 'this kind of statement is not allowed' );
		}
		if ( 1 === preg_match( '/^-- ([a-z0-9_-]{1,40}): (.*)\z/s', $line, $found ) ) {
			return self::result( self::KIND_HEADER, '', 0, $found[1], $found[2] );
		}
		return self::result( self::KIND_COMMENT );
	}

	/**
	 * SET statements.
	 *
	 * @param string $body Statement without the semicolon.
	 * @return array{kind:string,table:string,rows:int,key:string,value:string}
	 * @throws DumpRejectedException When it is not one of the allowed SET statements.
	 */
	private function check_set( $body ) {
		if ( 1 === preg_match( '/^SET NAMES ([a-z0-9]+)\z/', $body, $found ) && in_array( $found[1], self::CHARSETS, true ) ) {
			return self::result( self::KIND_SET );
		}
		if ( 1 === preg_match( '/^SET (FOREIGN_KEY_CHECKS|UNIQUE_CHECKS)=[01]\z/', $body ) || "SET time_zone='+00:00'" === $body ) {
			return self::result( self::KIND_SET );
		}
		if ( 1 === preg_match( "/^SET sql_mode='([A-Z_,]*)'\\z/", $body, $found ) ) {
			$modes = '' === $found[1] ? array() : explode( ',', $found[1] );
			foreach ( $modes as $mode ) {
				if ( ! in_array( $mode, self::SQL_MODES, true ) ) {
					throw new DumpRejectedException( 'the SQL mode is not allowed' );
				}
			}
			return self::result( self::KIND_SET );
		}
		throw new DumpRejectedException( 'this SET statement is not allowed' );
	}

	/**
	 * DROP TABLE IF EXISTS `table`.
	 *
	 * @param string $body Statement without the semicolon.
	 * @return array{kind:string,table:string,rows:int,key:string,value:string}
	 * @throws DumpRejectedException For anything else.
	 */
	private function check_drop( $body ) {
		$name = $this->read_table( $body, 21, $end );
		if ( strlen( $body ) !== $end ) {
			throw new DumpRejectedException( 'unexpected text after the table name' );
		}
		return self::result( self::KIND_DROP, $name );
	}

	/**
	 * CREATE TABLE `table` ( ... ) ...
	 *
	 * @param string $body Statement without the semicolon.
	 * @return array{kind:string,table:string,rows:int,key:string,value:string}
	 * @throws DumpRejectedException When the statement is not a plain single CREATE TABLE.
	 */
	private function check_create( $body ) {
		$name = $this->read_table( $body, 13, $end );
		if ( ' (' !== substr( $body, $end, 2 ) ) {
			throw new DumpRejectedException( 'the CREATE TABLE statement has an unexpected shape' );
		}
		$this->scan_definition( $body, $end + 2 );
		return self::result( self::KIND_CREATE, $name );
	}

	/**
	 * INSERT INTO `table` [(`col`,...)] VALUES (...),(...)
	 *
	 * @param string $body Statement without the semicolon.
	 * @return array{kind:string,table:string,rows:int,key:string,value:string}
	 * @throws DumpRejectedException When the statement is not exactly one plain multi-row INSERT.
	 */
	private function check_insert( $body ) {
		$name = $this->read_table( $body, 12, $end );
		$pos  = $end;

		if ( ' (' === substr( $body, $pos, 2 ) ) {
			$pos = $this->skip_column_list( $body, $pos + 2 );
		}
		if ( ' VALUES ' !== substr( $body, $pos, 8 ) ) {
			throw new DumpRejectedException( 'the INSERT statement has an unexpected shape' );
		}
		$rows = $this->scan_values( $body, $pos + 8 );
		return self::result( self::KIND_INSERT, $name, $rows );
	}

	/**
	 * Read a back-quoted table name at $pos and check it.
	 *
	 * @param string   $body Statement.
	 * @param int      $pos  Position of the opening backtick.
	 * @param int|null $end  Set to the position after the closing backtick.
	 * @return string The name.
	 * @throws DumpRejectedException For a missing or invalid name or a table that may not be touched.
	 */
	private function read_table( $body, $pos, &$end ) {
		if ( '`' !== substr( $body, $pos, 1 ) ) {
			throw new DumpRejectedException( 'a table name is missing' );
		}
		$length = strspn( $body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$', $pos + 1 );
		if ( 0 === $length || '`' !== substr( $body, $pos + 1 + $length, 1 ) ) {
			throw new DumpRejectedException( 'a table name is not a plain identifier' );
		}
		$name = substr( $body, $pos + 1, $length );
		$end  = $pos + 2 + $length;
		if ( strlen( $name ) > self::MAX_NAME_BYTES ) {
			throw new DumpRejectedException( 'a table name is too long' );
		}
		if ( 0 !== strncmp( $name, $this->prefix, strlen( $this->prefix ) ) || strlen( $name ) <= strlen( $this->prefix ) ) {
			throw new DumpRejectedException( 'a table is outside the table prefix of this site' );
		}
		if ( 0 === strncmp( strtolower( $name ), $this->plugin_prefix, strlen( $this->plugin_prefix ) ) ) {
			throw new DumpRejectedException( 'a table belongs to the backup plugin itself' );
		}
		return $name;
	}

	/**
	 * Skip "`a`,`b`)" after the opening parenthesis of a column list.
	 *
	 * @param string $body Statement.
	 * @param int    $pos  Position after "(".
	 * @return int Position after ")".
	 * @throws DumpRejectedException For anything but back-quoted names separated by commas.
	 */
	private function skip_column_list( $body, $pos ) {
		$count = 0;
		while ( true ) {
			if ( '`' !== substr( $body, $pos, 1 ) ) {
				throw new DumpRejectedException( 'the column list is malformed' );
			}
			$close = strpos( $body, '`', $pos + 1 );
			if ( false === $close || $close === $pos + 1 || $close - $pos - 1 > 256 ) {
				throw new DumpRejectedException( 'the column list is malformed' );
			}
			if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', substr( $body, $pos + 1, $close - $pos - 1 ) ) ) {
				throw new DumpRejectedException( 'the column list is malformed' );
			}
			++$count;
			$pos = $close + 1;
			$sep = substr( $body, $pos, 1 );
			++$pos;
			if ( ')' === $sep ) {
				return $pos;
			}
			if ( ',' !== $sep || $count > 4096 ) {
				throw new DumpRejectedException( 'the column list is malformed' );
			}
		}//end while
	}

	/**
	 * Read "(v,v),(v,v)" up to the end of the statement.
	 *
	 * @param string $body Statement without the semicolon.
	 * @param int    $pos  Position of the first "(".
	 * @return int Number of tuples.
	 * @throws DumpRejectedException For anything that is not exactly the grammar of the dump.
	 */
	private function scan_values( $body, $pos ) {
		$length = strlen( $body );
		$rows   = 0;

		while ( true ) {
			if ( $pos >= $length || '(' !== $body[ $pos ] ) {
				throw new DumpRejectedException( 'the VALUES list is malformed' );
			}
			++$pos;
			while ( true ) {
				$pos = $this->skip_value( $body, $pos, $length );
				if ( $pos >= $length ) {
					throw new DumpRejectedException( 'the VALUES list is malformed' );
				}
				$char = $body[ $pos ];
				++$pos;
				if ( ')' === $char ) {
					break;
				}
				if ( ',' !== $char ) {
					throw new DumpRejectedException( 'the VALUES list is malformed' );
				}
			}
			++$rows;
			if ( $pos === $length ) {
				return $rows;
			}
			if ( ',' !== $body[ $pos ] ) {
				throw new DumpRejectedException( 'the statement continues after the VALUES list' );
			}
			++$pos;
		}//end while
	}

	/**
	 * Skip one value: NULL, a number, 0x<hex> or a quoted string.
	 *
	 * @param string $body   Statement.
	 * @param int    $pos    Position.
	 * @param int    $length Statement length.
	 * @return int Position after the value.
	 * @throws DumpRejectedException For anything else.
	 */
	private function skip_value( $body, $pos, $length ) {
		if ( $pos >= $length ) {
			throw new DumpRejectedException( 'the VALUES list is malformed' );
		}
		$char = $body[ $pos ];

		if ( "'" === $char ) {
			$end = self::skip_quoted( $body, $pos, "'", $length );
			if ( $end < 0 ) {
				throw new DumpRejectedException( 'a string value is not terminated' );
			}
			return $end;
		}
		if ( 'N' === $char ) {
			if ( 'NULL' !== substr( $body, $pos, 4 ) ) {
				throw new DumpRejectedException( 'a value is not allowed' );
			}
			return $pos + 4;
		}
		if ( '0' === $char && $pos + 1 < $length && 'x' === $body[ $pos + 1 ] ) {
			$digits = strspn( $body, '0123456789abcdefABCDEF', $pos + 2 );
			if ( 0 === $digits ) {
				throw new DumpRejectedException( 'a binary value is malformed' );
			}
			return $pos + 2 + $digits;
		}
		if ( 1 === preg_match( '/\G-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $body, $found, 0, $pos ) ) {
			return $pos + strlen( $found[0] );
		}
		throw new DumpRejectedException( 'a value is not allowed' );
	}

	/**
	 * Position after the closing quote of a string that starts at $pos (backslash escapes, doubled quotes).
	 *
	 * @param string $body   Text.
	 * @param int    $pos    Position of the opening quote.
	 * @param string $quote  Quote character.
	 * @param int    $length Text length.
	 * @return int Position after the string, -1 when it is not terminated.
	 */
	private static function skip_quoted( $body, $pos, $quote, $length ) {
		$mask = $quote . '\\';
		$i    = $pos + 1;
		while ( $i < $length ) {
			$i += strcspn( $body, $mask, $i );
			if ( $i >= $length ) {
				return -1;
			}
			if ( '\\' === $body[ $i ] ) {
				$i += 2;
				continue;
			}
			if ( $i + 1 < $length && $quote === $body[ $i + 1 ] ) {
				$i += 2;
				continue;
			}
			return $i + 1;
		}
		return -1;
	}

	/**
	 * Check everything after "CREATE TABLE `x` (": strings are skipped as literals, everything outside them is looked at.
	 *
	 * @param string $body Statement without the semicolon.
	 * @param int    $pos  Position after the opening parenthesis.
	 * @return void
	 * @throws DumpRejectedException For a second statement, a comment, a forbidden word, a plugin-table reference or a bad engine.
	 */
	private function scan_definition( $body, $pos ) {
		$length   = strlen( $body );
		$previous = '';
		$word_set = array_flip( self::FORBIDDEN_WORDS );
		$engines  = array_flip( self::ENGINES );

		while ( $pos < $length ) {
			$char = $body[ $pos ];

			if ( "'" === $char || '"' === $char ) {
				$end = self::skip_quoted( $body, $pos, $char, $length );
				if ( $end < 0 ) {
					throw new DumpRejectedException( 'a string in the table definition is not terminated' );
				}
				$pos      = $end;
				$previous = $char;
				continue;
			}
			if ( '`' === $char ) {
				$pos      = $this->scan_identifier( $body, $pos, $length, $previous );
				$previous = '`';
				continue;
			}
			if ( ';' === $char || '#' === $char || '\\' === $char ) {
				throw new DumpRejectedException( 'the table definition contains more than one statement or a comment' );
			}
			if ( '/' === $char ) {
				if ( $pos + 1 < $length && '*' === $body[ $pos + 1 ] ) {
					if ( self::ALLOWED_COMMENT !== substr( $body, $pos, strlen( self::ALLOWED_COMMENT ) ) ) {
						throw new DumpRejectedException( 'the table definition contains a comment' );
					}
					$pos     += strlen( self::ALLOWED_COMMENT );
					$previous = ' ';
					continue;
				}
			}
			if ( '-' === $char && $pos + 1 < $length && '-' === $body[ $pos + 1 ] ) {
				throw new DumpRejectedException( 'the table definition contains a comment' );
			}
			if ( 1 === preg_match( '/[A-Za-z_$]/', $char ) ) {
				$run  = strspn( $body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$', $pos );
				$word = strtolower( substr( $body, $pos, $run ) );
				$pos += $run;
				if ( isset( $word_set[ $word ] ) ) {
					throw new DumpRejectedException( 'the table definition contains a word that is not allowed' );
				}
				if ( 'engine' === $word ) {
					$this->check_engine( $body, $pos, $length, $engines );
				}
				$previous = 'w';
				continue;
			}
			if ( 1 === preg_match( '/[0-9]/', $char ) ) {
				$pos     += strspn( $body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$', $pos );
				$previous = '0';
				continue;
			}
			$previous = $char;
			++$pos;
		}//end while
	}

	/**
	 * A back-quoted identifier inside a definition: no qualified names, no plugin tables.
	 *
	 * @param string $body     Statement.
	 * @param int    $pos      Position of the opening backtick.
	 * @param int    $length   Statement length.
	 * @param string $previous Previous significant character outside literals.
	 * @return int Position after the closing backtick.
	 * @throws DumpRejectedException For a bad identifier.
	 */
	private function scan_identifier( $body, $pos, $length, $previous ) {
		if ( '.' === $previous ) {
			throw new DumpRejectedException( 'the table definition names another schema' );
		}
		$name = '';
		$i    = $pos + 1;
		while ( true ) {
			$close = strpos( $body, '`', $i );
			if ( false === $close ) {
				throw new DumpRejectedException( 'a name in the table definition is not terminated' );
			}
			$name .= substr( $body, $i, $close - $i );
			if ( $close + 1 < $length && '`' === $body[ $close + 1 ] ) {
				$name .= '`';
				$i     = $close + 2;
				continue;
			}
			$i = $close + 1;
			break;
		}
		if ( $i < $length && '.' === $body[ $i ] ) {
			throw new DumpRejectedException( 'the table definition names another schema' );
		}
		if ( 0 === strncmp( strtolower( $name ), $this->plugin_prefix, strlen( $this->plugin_prefix ) ) ) {
			throw new DumpRejectedException( 'the table definition refers to a table of the backup plugin' );
		}
		return $i;
	}

	/**
	 * After the word ENGINE: the engine must be one of the allowed ones.
	 *
	 * @param string            $body    Statement.
	 * @param int               $pos     Position after the word.
	 * @param int               $length  Statement length.
	 * @param array<string,int> $allowed Allowed engines as keys.
	 * @return void
	 * @throws DumpRejectedException For another engine (FEDERATED, MERGE, ...).
	 */
	private function check_engine( $body, $pos, $length, array $allowed ) {
		$pos += strspn( $body, ' ', $pos );
		if ( $pos < $length && '=' === $body[ $pos ] ) {
			++$pos;
			$pos += strspn( $body, ' ', $pos );
		}
		$run    = strspn( $body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_', $pos );
		$engine = strtolower( substr( $body, $pos, $run ) );
		if ( '' === $engine || ! isset( $allowed[ $engine ] ) ) {
			throw new DumpRejectedException( 'the table engine is not allowed' );
		}
	}
}
