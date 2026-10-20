<?php
/**
 * Turns database values into SQL literals for the dump file.
 *
 * Phase: MVP
 *
 * Purpose: Pure, stateless formatting rules of the dump format (docs/plan/04-backup-engine.md).
 *
 * Responsibilities:
 *  - quote_string(): MySQL string escaping, so a literal never contains a raw newline, carriage return,
 *    NUL, backslash, quote or Ctrl-Z
 *  - format_value(): NULL, exact integers and decimals, round-trip safe floats, 0x<hex> for binary data and
 *    for text that is not valid UTF-8, quoted strings for everything else
 *  - identifier(): backtick quoting with doubled backticks (control characters are refused)
 *  - single_line(): collapses a SHOW CREATE TABLE statement to one line without touching string literals
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Dump;

defined( 'ABSPATH' ) || exit;

/**
 * SQL literal formatting.
 */
final class SqlValueFormatter {

	const BINARY_TYPES = array(
		'binary',
		'varbinary',
		'tinyblob',
		'blob',
		'mediumblob',
		'longblob',
		'geometry',
		'point',
		'linestring',
		'polygon',
		'multipoint',
		'multilinestring',
		'multipolygon',
		'geometrycollection',
	);

	const INTEGER_TYPES = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year' );

	const DECIMAL_TYPES = array( 'decimal', 'numeric', 'dec', 'fixed' );

	const FLOAT_TYPES = array( 'float', 'double', 'real' );

	/**
	 * Quote a string as a MySQL literal. Escapes backslash, quote, NUL, newline, carriage return and Ctrl-Z.
	 *
	 * @param mixed $value Value (cast to string).
	 * @return string
	 */
	public static function quote_string( $value ) {
		return "'" . strtr(
			(string) $value,
			array(
				'\\'   => '\\\\',
				"'"    => "\\'",
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"\x1a" => '\\Z',
			)
		) . "'";
	}

	/**
	 * Quote an identifier with backticks. A backtick inside the name is doubled.
	 *
	 * @param string $name Table or column name.
	 * @return string
	 * @throws DumpException For an empty name or one with control characters (they would break the one-line format).
	 */
	public static function identifier( $name ) {
		$name = (string) $name;
		if ( '' === $name || 1 === preg_match( '/[\x00-\x1f\x7f]/', $name ) ) {
			throw new DumpException( 'A table or column name is empty or contains control characters and cannot be exported.' );
		}
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Lower-case base type of a column type: "int(10) unsigned" gives "int", "ENUM('a')" gives "enum".
	 *
	 * @param string $column_type Type as shown by SHOW COLUMNS.
	 * @return string
	 */
	public static function base_type( $column_type ) {
		return 1 === preg_match( '/^\s*([a-z]+)/i', (string) $column_type, $found ) ? strtolower( $found[1] ) : '';
	}

	/**
	 * Whether a column type holds binary data that must be written as hex.
	 *
	 * @param string $column_type Type as shown by SHOW COLUMNS.
	 * @return bool
	 */
	public static function is_binary_type( $column_type ) {
		return in_array( self::base_type( $column_type ), self::BINARY_TYPES, true );
	}

	/**
	 * Format one value.
	 *
	 * @param mixed  $value       Value as returned by the database layer (string or null), or a PHP scalar.
	 * @param string $column_type Column type (for example "decimal(10,2)").
	 * @param bool   $is_binary   Force hex output (the binary types are detected from the type as well).
	 * @return string SQL literal without any raw newline.
	 * @throws \InvalidArgumentException For a value that is not a scalar.
	 */
	public static function format_value( $value, $column_type, $is_binary = false ) {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			return self::format_float( $value );
		}
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( 'A column value must be a scalar or null.' );
		}

		if ( $is_binary || self::is_binary_type( $column_type ) ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}

		$base = self::base_type( $column_type );
		if ( 'bit' === $base ) {
			// The export selects BIT as a number ("col + 0"), because some drivers return BIT as digits and others as raw bytes. Raw bytes stay exact as hex.
			return 1 === preg_match( '/^\d{1,20}$/', $value ) ? $value : '0x' . bin2hex( $value );
		}
		if ( in_array( $base, self::INTEGER_TYPES, true ) && 1 === preg_match( '/^-?\d+$/', $value ) ) {
			return $value;
		}
		if ( in_array( $base, self::DECIMAL_TYPES, true ) && 1 === preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return $value;
		}
		if ( in_array( $base, self::FLOAT_TYPES, true ) && 1 === preg_match( '/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $value ) ) {
			return $value;
		}

		// Text that is not valid UTF-8 would make the dump file invalid UTF-8: keep it exact as hex.
		if ( '' !== $value && 1 !== preg_match( '//u', $value ) ) {
			return '0x' . bin2hex( $value );
		}
		return self::quote_string( $value );
	}

	/**
	 * Format a row: "(v1,v2,...)".
	 *
	 * @param array<int,mixed>                          $row     Values in column order.
	 * @param array<int,array{type:string,binary:bool}> $columns Column meta in the same order.
	 * @return string
	 */
	public static function format_row( array $row, array $columns ) {
		$parts = array();
		foreach ( array_values( $row ) as $index => $value ) {
			$meta    = isset( $columns[ $index ] ) ? $columns[ $index ] : array(
				'type'   => '',
				'binary' => false,
			);
			$parts[] = self::format_value( $value, $meta['type'], $meta['binary'] );
		}
		return '(' . implode( ',', $parts ) . ')';
	}

	/**
	 * Shortest decimal text that reads back as exactly the same float. NaN and infinity cannot be stored by
	 * MySQL and become NULL.
	 *
	 * @param float $value Float.
	 * @return string
	 */
	public static function format_float( $value ) {
		if ( is_nan( $value ) || is_infinite( $value ) ) {
			return 'NULL';
		}
		foreach ( array( 15, 16, 17 ) as $precision ) {
			$text = sprintf( '%.' . $precision . 'g', $value );
			if ( (float) $text === $value ) {
				return $text;
			}
		}
		return sprintf( '%.17g', $value );
	}

	/**
	 * Collapse a SHOW CREATE TABLE statement to a single line.
	 *
	 * Outside quotes every run of whitespace becomes one space. Inside a string literal ('...' or "...") a raw
	 * newline, carriage return or NUL is replaced by its escape (\n, \r, \0), which means exactly the same
	 * thing, so default values and comments keep their value. Whitespace inside a backtick identifier is kept
	 * as it is; a line break there is refused. Nothing is ever collapsed inside a quote, which is why a plain
	 * regular expression on the whole statement is not used.
	 *
	 * @param string $sql Statement as returned by the server.
	 * @return string One line, no trailing semicolon.
	 * @throws DumpException When a quote is not closed or an identifier contains a line break.
	 */
	public static function single_line( $sql ) {
		$sql    = (string) $sql;
		$length = strlen( $sql );
		$out    = '';
		$quote  = '';
		$gap    = false;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];

			if ( '' === $quote ) {
				if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
					$gap = true;
					continue;
				}
				if ( $gap && '' !== $out ) {
					$out .= ' ';
				}
				$gap  = false;
				$out .= $char;
				if ( "'" === $char || '"' === $char || '`' === $char ) {
					$quote = $char;
				}
				continue;
			}

			// Inside a quote.
			if ( '\\' === $char && '`' !== $quote && $i + 1 < $length ) {
				$next = $sql[ $i + 1 ];
				++$i;
				$out .= '\\' . self::escape_control( $next, false );
				continue;
			}
			if ( $char === $quote ) {
				if ( $i + 1 < $length && $sql[ $i + 1 ] === $quote ) {
					$out .= $char . $char;
					// Doubled quote stays inside the literal.
					++$i;
					continue;
				}
				$out  .= $char;
				$quote = '';
				continue;
			}
			if ( "\n" === $char || "\r" === $char || "\0" === $char ) {
				if ( '`' === $quote ) {
					throw new DumpException( 'A name contains a line break and cannot be exported.' );
				}
				$out .= self::escape_control( $char, true );
				continue;
			}
			$out .= $char;
		}//end for

		if ( '' !== $quote ) {
			throw new DumpException( 'The table definition could not be converted to a single line (unterminated quote).' );
		}
		return rtrim( $out, "; \t" );
	}

	/**
	 * Escape a control character for use inside a literal.
	 *
	 * @param string $char          One byte.
	 * @param bool   $standalone    True for a bare control byte (becomes "\n"); false after a backslash, where
	 *                              a raw control byte replaces the "\<newline>" pair by "n" so the escape stays valid.
	 * @return string
	 */
	private static function escape_control( $char, $standalone ) {
		if ( "\n" === $char ) {
			return $standalone ? '\\n' : 'n';
		}
		if ( "\r" === $char ) {
			return $standalone ? '\\r' : 'r';
		}
		if ( "\0" === $char ) {
			return $standalone ? '\\0' : '0';
		}
		return $char;
	}
}
