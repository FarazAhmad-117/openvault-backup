<?php
// phpcs:disable PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- Expected values are SQL hex literals such as "0x41", never used as numbers.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Dump\DumpException;
use OpenVault\Backup\Engine\Dump\SqlValueFormatter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Dump\SqlValueFormatter
 */
class SqlValueFormatterTest extends TestCase {

	/**
	 * Read a literal back the way MySQL does: returns the value, and fails if anything follows the closing quote
	 * or if a raw control character or a lone quote appears inside.
	 */
	private function unquote( $literal ) {
		$this->assertSame( "'", $literal[0], 'literal starts with a quote' );
		$map    = array(
			'0'  => "\0",
			'n'  => "\n",
			'r'  => "\r",
			'Z'  => "\x1a",
			'\\' => '\\',
			"'"  => "'",
		);
		$out    = '';
		$length = strlen( $literal );
		for ( $i = 1; $i < $length; $i++ ) {
			$char = $literal[ $i ];
			if ( '\\' === $char ) {
				++$i;
				$this->assertArrayHasKey( $literal[ $i ], $map, 'only known escapes are produced' );
				$out .= $map[ $literal[ $i ] ];
				continue;
			}
			if ( "'" === $char ) {
				$this->assertSame( $length - 1, $i, 'the closing quote is the last character: nothing escapes the literal' );
				return $out;
			}
			$this->assertFalse( in_array( $char, array( "\n", "\r", "\0", "\x1a" ), true ), 'no raw control character inside a literal' );
			$out .= $char;
		}
		$this->fail( 'literal is not terminated' );
	}

	public function hostile_strings() {
		$all_bytes = '';
		for ( $i = 0; $i < 256; $i++ ) {
			$all_bytes .= chr( $i );
		}
		return array(
			'plain'                  => array( 'hello' ),
			'empty'                  => array( '' ),
			'single quote'           => array( "it's" ),
			'double quote'           => array( 'say "hi"' ),
			'many quotes'            => array( "'''''" ),
			'backslash'              => array( 'C:\\path\\file' ),
			'backslash before quote' => array( "\\'" ),
			'trailing backslash'     => array( 'end\\' ),
			'double backslash'       => array( '\\\\' ),
			'newline'                => array( "a\nb" ),
			'crlf'                   => array( "a\r\nb\r\n" ),
			'carriage return only'   => array( "a\rb" ),
			'nul byte'               => array( "a\0b" ),
			'ctrl-z'                 => array( "a\x1ab" ),
			'emoji'                  => array( "party \u{1F389}\u{1F680} done" ),
			'cjk and accents'        => array( '日本語 ñandú ü' ),
			'0x lookalike'           => array( '0x41424344' ),
			'0x lookalike in text'   => array( 'value 0xDEADBEEF' ),
			'sql injection'          => array( "'); DROP TABLE wp_users; -- " ),
			'sql injection 2'        => array( "' OR '1'='1" ),
			'comment markers'        => array( "-- not a comment\n/* nor this */ # or this" ),
			'semicolon newline'      => array( ";\nDELETE FROM wp_options;\n" ),
			'quote then newline'     => array( "'\n'" ),
			'backslash n text'       => array( 'literally \\n not a newline' ),
			'percent and underscore' => array( '100% _ok_' ),
			'serialized php'         => array( 'a:1:{s:3:"key";s:5:"it\'s";}' ),
			'long text'              => array( str_repeat( "line \"x\" 'y' \\ \n", 20000 ) ),
			'all ascii control'      => array( implode( '', array_map( 'chr', range( 0, 31 ) ) ) ),
		);
	}

	/**
	 * @dataProvider hostile_strings
	 */
	public function test_quote_string_round_trips_and_stays_one_line( $value ) {
		$literal = SqlValueFormatter::quote_string( $value );
		$this->assertFalse( strpbrk( $literal, "\r\n\0\x1a" ), 'no raw line break, NUL or Ctrl-Z' );
		$this->assertSame( $value, $this->unquote( $literal ) );
	}

	public function test_quote_string_exact_output() {
		$this->assertSame( "'it\\'s'", SqlValueFormatter::quote_string( "it's" ) );
		$this->assertSame( "'a\\nb\\r\\0\\\\\\Z'", SqlValueFormatter::quote_string( "a\nb\r\0\\\x1a" ) );
		$this->assertSame( "''", SqlValueFormatter::quote_string( '' ) );
	}

	public function test_injection_payload_stays_inside_the_literal() {
		$payload = "x'); DROP TABLE wp_users; SELECT ('";
		$literal = SqlValueFormatter::quote_string( $payload );
		$this->assertSame( "'x\\'); DROP TABLE wp_users; SELECT (\\''", $literal );
	}

	public function test_identifier() {
		$this->assertSame( '`wp_posts`', SqlValueFormatter::identifier( 'wp_posts' ) );
		$this->assertSame( '`we``ird`', SqlValueFormatter::identifier( 'we`ird' ) );
		$this->assertSame( '``````', SqlValueFormatter::identifier( '``' ) );
		$this->assertSame( '`sp ace`', SqlValueFormatter::identifier( 'sp ace' ) );
		$this->assertSame( '`naïve_日本`', SqlValueFormatter::identifier( 'naïve_日本' ) );
		$this->assertSame( '`a;DROP TABLE x`', SqlValueFormatter::identifier( 'a;DROP TABLE x' ) );
	}

	public function unsafe_identifiers() {
		return array(
			'empty'   => array( '' ),
			'newline' => array( "a\nb" ),
			'cr'      => array( "a\rb" ),
			'nul'     => array( "a\0b" ),
			'tab'     => array( "a\tb" ),
			'del'     => array( "a\x7fb" ),
		);
	}

	/**
	 * @dataProvider unsafe_identifiers
	 */
	public function test_identifier_refuses_control_characters( $name ) {
		$this->expectException( DumpException::class );
		SqlValueFormatter::identifier( $name );
	}

	public function value_provider() {
		return array(
			'null'                       => array( null, 'varchar(10)', false, 'NULL' ),
			'null in int'                => array( null, 'int(11)', false, 'NULL' ),
			'int string'                 => array( '42', 'int(11)', false, '42' ),
			'negative int'               => array( '-7', 'tinyint(4)', false, '-7' ),
			'bigint unsigned max'        => array( '18446744073709551615', 'bigint(20) unsigned', false, '18446744073709551615' ),
			'year'                       => array( '2026', 'year(4)', false, '2026' ),
			'int-type but junk'          => array( '1; DROP TABLE x', 'int(11)', false, "'1; DROP TABLE x'" ),
			'decimal exact'              => array( '12345678901234567890.1234567890', 'decimal(30,10)', false, '12345678901234567890.1234567890' ),
			'decimal negative'           => array( '-0.50', 'decimal(5,2)', false, '-0.50' ),
			'decimal junk'               => array( "1e5'", 'decimal(5,2)', false, "'1e5\\''" ),
			'float text'                 => array( '3.14159', 'float', false, '3.14159' ),
			'double exponent'            => array( '1.7976931348623157E308', 'double', false, '1.7976931348623157E308' ),
			'double small exponent'      => array( '-1.5e-7', 'double unsigned', false, '-1.5e-7' ),
			'float junk'                 => array( 'NaN', 'float', false, "'NaN'" ),
			'varchar numeric stays text' => array( '007', 'varchar(10)', false, "'007'" ),
			'text 0x lookalike'          => array( '0x41', 'text', false, "'0x41'" ),
			'enum'                       => array( "it's", "enum('a','it''s')", false, "'it\\'s'" ),
			'set'                        => array( 'a,b', "set('a','b')", false, "'a,b'" ),
			'datetime'                   => array( '2026-01-02 03:04:05', 'datetime', false, "'2026-01-02 03:04:05'" ),
			'zero date'                  => array( '0000-00-00 00:00:00', 'datetime', false, "'0000-00-00 00:00:00'" ),
			'json'                       => array( '{"a":"it\'s","b":[1,2]}', 'json', false, "'{\"a\":\"it\\'s\",\"b\":[1,2]}'" ),
			'longtext newline'           => array( "a\nb", 'longtext', false, "'a\\nb'" ),
			'blob hex'                   => array( "\x00\x01\xff", 'blob', false, '0x0001ff' ),
			'empty blob'                 => array( '', 'longblob', false, "''" ),
			'varbinary'                  => array( "ab'", 'varbinary(20)', false, '0x616227' ),
			'binary(16)'                 => array( "\x00\x00", 'binary(16)', false, '0x0000' ),
			'bit'                        => array( "\x05", 'bit(8)', false, '0x05' ),
			'bit as digits'              => array( '5461', 'bit(13)', false, '5461' ),
			'bit zero digits'            => array( '0', 'bit(1)', false, '0' ),
			'geometry'                   => array( "\x00\x00\x00\x00\x01", 'point', false, '0x0000000001' ),
			'forced binary on text type' => array( 'abc', 'varchar(10)', true, '0x616263' ),
			'empty forced binary'        => array( '', 'varchar(10)', true, "''" ),
			'empty string'               => array( '', 'varchar(10)', false, "''" ),
			'bool true'                  => array( true, 'tinyint(1)', false, '1' ),
			'bool false'                 => array( false, 'tinyint(1)', false, '0' ),
			'php int'                    => array( 5, 'int', false, '5' ),
			'php negative int'           => array( -5, 'int', false, '-5' ),
			'php float'                  => array( 0.1, 'double', false, '0.1' ),
			'php float exact'            => array( 0.30000000000000004, 'double', false, '0.30000000000000004' ),
			'php float whole'            => array( 3.0, 'double', false, '3' ),
			'php float nan'              => array( NAN, 'double', false, 'NULL' ),
			'php float inf'              => array( INF, 'double', false, 'NULL' ),
			'invalid utf8 in text'       => array( "caf\xe9", 'varchar(20)', false, '0x636166e9' ),
			'truncated utf8'             => array( "\xf0\x9f\x8e", 'text', false, '0xf09f8e' ),
			'overlong utf8'              => array( "\xc0\xaf", 'text', false, '0xc0af' ),
			'valid emoji'                => array( "\u{1F389}", 'text', false, "'\u{1F389}'" ),
		);
	}

	/**
	 * @dataProvider value_provider
	 */
	public function test_format_value( $value, $type, $binary, $expected ) {
		$this->assertSame( $expected, SqlValueFormatter::format_value( $value, $type, $binary ) );
	}

	public function test_every_byte_value_round_trips_as_hex() {
		$all = '';
		for ( $i = 0; $i < 256; $i++ ) {
			$all .= chr( $i );
		}
		$out = SqlValueFormatter::format_value( $all, 'blob', false );
		$this->assertSame( '0x' . bin2hex( $all ), $out );
		$this->assertSame( 2 + 512, strlen( $out ) );
		$this->assertSame( $all, hex2bin( substr( $out, 2 ) ) );
	}

	public function test_invalid_utf8_never_reaches_the_file_raw() {
		for ( $i = 128; $i < 256; $i++ ) {
			$value = 'x' . chr( $i ) . 'y';
			$out   = SqlValueFormatter::format_value( $value, 'varchar(10)', false );
			if ( 1 === preg_match( '//u', $value ) ) {
				continue;
			}
			$this->assertSame( 1, preg_match( '/^0x[0-9a-f]+$/', $out ), "byte $i" );
		}
	}

	public function test_non_scalar_is_refused() {
		$this->expectException( \InvalidArgumentException::class );
		SqlValueFormatter::format_value( array( 1 ), 'text', false );
	}

	public function test_format_row() {
		$columns = array(
			array(
				'type'   => 'int(11)',
				'binary' => false,
			),
			array(
				'type'   => 'varchar(10)',
				'binary' => false,
			),
			array(
				'type'   => 'blob',
				'binary' => true,
			),
			array(
				'type'   => 'text',
				'binary' => false,
			),
		);
		$this->assertSame(
			"(7,'a\\nb',0xff00,NULL)",
			SqlValueFormatter::format_row( array( '7', "a\nb", "\xff\x00", null ), $columns )
		);
		$this->assertSame( '()', SqlValueFormatter::format_row( array(), array() ) );
	}

	public function test_base_type_and_binary_detection() {
		$this->assertSame( 'int', SqlValueFormatter::base_type( 'INT(10) UNSIGNED' ) );
		$this->assertSame( 'enum', SqlValueFormatter::base_type( "enum('a')" ) );
		$this->assertSame( '', SqlValueFormatter::base_type( '' ) );
		$this->assertTrue( SqlValueFormatter::is_binary_type( 'LONGBLOB' ) );
		$this->assertFalse( SqlValueFormatter::is_binary_type( 'bit(1)' ) );
		$this->assertFalse( SqlValueFormatter::is_binary_type( 'longtext' ) );
		$this->assertFalse( SqlValueFormatter::is_binary_type( 'varchar(20)' ) );
	}

	public function test_float_formatting_round_trips() {
		foreach ( array( 0.1, 1 / 3, 1.0E-10, 1.7976931348623157E308, 5.0E-324, -123456.789, 1.1920929E-7, 2.2250738585072014E-308 ) as $float ) {
			$text = SqlValueFormatter::format_float( $float );
			$this->assertSame( $float, (float) $text, $text );
		}
	}

	public function single_line_cases() {
		return array(
			'plain create'           => array(
				"CREATE TABLE `t` (\n  `id` int(11) NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB",
				'CREATE TABLE `t` ( `id` int(11) NOT NULL, PRIMARY KEY (`id`) ) ENGINE=InnoDB',
			),
			'spaces in default kept' => array(
				"CREATE TABLE `t` (\n  `c` varchar(20) DEFAULT 'a   b\t c'\n)",
				"CREATE TABLE `t` ( `c` varchar(20) DEFAULT 'a   b\t c' )",
			),
			'newline in default'     => array(
				"CREATE TABLE `t` (\n  `c` varchar(20) DEFAULT 'a\nb'\n)",
				"CREATE TABLE `t` ( `c` varchar(20) DEFAULT 'a\\nb' )",
			),
			'crlf in comment'        => array(
				"CREATE TABLE `t` (\n  `c` int COMMENT 'one\r\ntwo'\n)",
				"CREATE TABLE `t` ( `c` int COMMENT 'one\\r\\ntwo' )",
			),
			'doubled quote'          => array(
				"CREATE TABLE `t` (\n  `c` int COMMENT 'it''s\n  here'\n)",
				"CREATE TABLE `t` ( `c` int COMMENT 'it''s\\n  here' )",
			),
			'escaped quote'          => array(
				"CREATE TABLE `t` (\n  `c` int COMMENT 'it\\'s   ok'\n)",
				"CREATE TABLE `t` ( `c` int COMMENT 'it\\'s   ok' )",
			),
			'backslash newline'      => array(
				"CREATE TABLE `t` (\n  `c` int COMMENT 'a\\\nb'\n)",
				"CREATE TABLE `t` ( `c` int COMMENT 'a\\nb' )",
			),
			'double quoted literal'  => array(
				"CREATE TABLE `t` (\n  `c` int COMMENT \"x  \n y\"\n)",
				"CREATE TABLE `t` ( `c` int COMMENT \"x  \\n y\" )",
			),
			'backtick name spaces'   => array(
				"CREATE TABLE `a  b` (\n  `c d` int\n)",
				'CREATE TABLE `a  b` ( `c d` int )',
			),
			'quote inside backtick'  => array(
				"CREATE TABLE `it's` (\n  `x` int COMMENT 'ok'\n)",
				"CREATE TABLE `it's` ( `x` int COMMENT 'ok' )",
			),
			'version comment'        => array(
				"CREATE TABLE `t` (\n  `a` int\n) /*!50100 PARTITION BY HASH (`a`)\nPARTITIONS 4 */",
				'CREATE TABLE `t` ( `a` int ) /*!50100 PARTITION BY HASH (`a`) PARTITIONS 4 */',
			),
			'trailing semicolon'     => array( "CREATE TABLE `t` (`a` int);\n", 'CREATE TABLE `t` (`a` int)' ),
			'leading whitespace'     => array( "\n  CREATE TABLE `t` (`a` int)", 'CREATE TABLE `t` (`a` int)' ),
		);
	}

	/**
	 * @dataProvider single_line_cases
	 */
	public function test_single_line( $input, $expected ) {
		$out = SqlValueFormatter::single_line( $input );
		$this->assertSame( $expected, $out );
		$this->assertFalse( strpbrk( $out, "\r\n" ) );
	}

	public function test_single_line_refuses_unterminated_quote() {
		$this->expectException( DumpException::class );
		SqlValueFormatter::single_line( "CREATE TABLE `t` (`a` int COMMENT 'oops\n)" );
	}

	public function test_single_line_refuses_newline_in_identifier() {
		$this->expectException( DumpException::class );
		SqlValueFormatter::single_line( "CREATE TABLE `a\nb` (`a` int)" );
	}
}
