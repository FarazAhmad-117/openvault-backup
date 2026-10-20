<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests write and read real temp files.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Dump\DumpException;
use OpenVault\Backup\Engine\Dump\SqlDumpWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Dump\SqlDumpWriter
 */
class SqlDumpWriterTest extends TestCase {

	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	private function temp() {
		$path          = tempnam( sys_get_temp_dir(), 'ovbdump' );
		$this->files[] = $path;
		return $path;
	}

	private function lines( $path ) {
		$content = file_get_contents( $path );
		return '' === $content ? array() : explode( "\n", rtrim( $content, "\n" ) );
	}

	public function test_header_lines() {
		$lines = SqlDumpWriter::header_lines(
			array(
				'table-prefix' => 'wp_',
				'charset'      => 'utf8mb4',
				'site-url'     => "https://example.com/\nsneaky: line",
				'Bad Key!'     => 'x',
				'tables'       => 12,
			)
		);
		$this->assertSame(
			array(
				'-- ovb-dump: 1',
				'-- table-prefix: wp_',
				'-- charset: utf8mb4',
				'-- site-url: https://example.com/ sneaky: line',
				'-- badkey: x',
				'-- tables: 12',
			),
			$lines
		);
	}

	public function test_a_full_dump_has_the_contract_shape() {
		$path   = $this->temp();
		$writer = new SqlDumpWriter( $path );
		$writer->begin();
		$writer->write_header(
			array(
				'table-prefix' => 'wp_',
				'tables'       => 1,
			),
			'utf8mb4'
		);
		$writer->write_table( 'wp_t', "CREATE TABLE `wp_t` (\n  `id` int(11) NOT NULL,\n  `v` text,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB" );
		$writer->write_rows( 'wp_t', null, array( "(1,'a\\nb')", '(2,NULL)' ) );
		$writer->write_footer();
		$writer->close();

		$lines = $this->lines( $path );
		$this->assertSame( '-- ovb-dump: 1', $lines[0] );
		$this->assertSame( 'SET NAMES utf8mb4;', $lines[3] );
		$this->assertContains( 'SET FOREIGN_KEY_CHECKS=0;', $lines );
		$this->assertContains( 'SET UNIQUE_CHECKS=0;', $lines );
		$this->assertContains( "SET sql_mode='NO_AUTO_VALUE_ON_ZERO';", $lines );
		$this->assertContains( 'DROP TABLE IF EXISTS `wp_t`;', $lines );
		$this->assertContains( "INSERT INTO `wp_t` VALUES (1,'a\\nb'),(2,NULL);", $lines );
		$this->assertSame( '-- ovb-dump-end', end( $lines ) );
		$this->assertSame( 'SET UNIQUE_CHECKS=1;', $lines[ count( $lines ) - 2 ] );
		$this->assertSame( 'SET FOREIGN_KEY_CHECKS=1;', $lines[ count( $lines ) - 3 ] );

		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, '-- ' ) ) {
				continue;
			}
			$this->assertSame( ';', substr( $line, -1 ), "every statement line ends with ';': $line" );
		}
		$create = array_values( preg_grep( '/^CREATE TABLE/', $lines ) );
		$this->assertCount( 1, $create );
		$this->assertSame( 'CREATE TABLE `wp_t` ( `id` int(11) NOT NULL, `v` text, PRIMARY KEY (`id`) ) ENGINE=InnoDB;', $create[0] );
		$this->assertSame( filesize( $path ), $writer->bytes() );
	}

	public function test_column_list_insert() {
		$path   = $this->temp();
		$writer = new SqlDumpWriter( $path );
		$writer->begin();
		$writer->write_rows( 'we`ird', array( 'a', 'b`c' ), array( '(1,2)' ) );
		$writer->close();
		$this->assertSame( array( 'INSERT INTO `we``ird` (`a`,`b``c`) VALUES (1,2);' ), $this->lines( $path ) );
	}

	public function test_rows_are_split_into_statements_under_the_cap_but_never_split_a_row() {
		$path   = $this->temp();
		$writer = new SqlDumpWriter( $path );
		$writer->begin();
		$row   = "('" . str_repeat( 'x', 99990 ) . "')"; // About 100 KB each.
		$count = $writer->write_rows( 't', null, array_fill( 0, 20, $row ) );
		$writer->close();

		$lines = $this->lines( $path );
		$this->assertSame( $count, count( $lines ) );
		$this->assertGreaterThan( 3, $count );
		foreach ( $lines as $line ) {
			$this->assertLessThanOrEqual( SqlDumpWriter::STATEMENT_CAP, strlen( $line ) - 1 );
			$this->assertStringStartsWith( 'INSERT INTO `t` VALUES (', $line );
			$this->assertStringEndsWith( ')', substr( $line, 0, -1 ) );
		}
		$this->assertSame( 20, substr_count( implode( "\n", $lines ), "('" ) );
	}

	public function test_a_single_oversized_row_is_still_one_statement() {
		$path   = $this->temp();
		$writer = new SqlDumpWriter( $path );
		$writer->begin();
		$big   = "('" . str_repeat( 'y', SqlDumpWriter::STATEMENT_CAP * 2 ) . "')";
		$count = $writer->write_rows( 't', null, array( '(1)', $big, '(2)' ) );
		$writer->close();
		$this->assertSame( 3, $count );
		$lines = $this->lines( $path );
		$this->assertSame( 'INSERT INTO `t` VALUES (1);', $lines[0] );
		$this->assertSame( 'INSERT INTO `t` VALUES (2);', $lines[2] );
	}

	public function test_no_rows_writes_nothing() {
		$path   = $this->temp();
		$writer = new SqlDumpWriter( $path );
		$writer->begin();
		$this->assertSame( 0, $writer->write_rows( 't', null, array() ) );
		$writer->close();
		$this->assertSame( 0, filesize( $path ) );
	}

	public function test_a_raw_line_break_is_refused() {
		$writer = new SqlDumpWriter( $this->temp() );
		$writer->begin();
		foreach ( array( "SELECT 1\nSELECT 2", "SELECT 1\rSELECT 2" ) as $sql ) {
			try {
				$writer->write_statement( $sql );
				$this->fail( 'a statement with a line break must be refused' );
			} catch ( DumpException $e ) {
				$this->assertSame( 0, $writer->bytes(), 'nothing was written' );
			}
		}
		foreach ( array( "(1,'a\nb')", "(1,'a\rb')" ) as $tuple ) {
			try {
				$writer->write_rows( 't', null, array( $tuple ) );
				$this->fail( 'a row with a line break must be refused' );
			} catch ( DumpException $e ) {
				$this->assertSame( 0, $writer->bytes() );
			}
		}
	}

	public function test_an_unsafe_table_name_is_refused() {
		$writer = new SqlDumpWriter( $this->temp() );
		$writer->begin();
		$this->expectException( DumpException::class );
		$writer->write_table( "bad\nname", 'CREATE TABLE x (a int)' );
	}

	public function test_truncate_to_cuts_the_file_and_the_writer_continues_from_there() {
		$path   = $this->temp();
		$writer = new SqlDumpWriter( $path );
		$writer->begin();
		$writer->write_statement( 'SET a=1' );
		$saved = $writer->bytes();
		$writer->write_statement( 'SET b=2' );
		$writer->write_statement( 'SET c=3' );
		$this->assertGreaterThan( $saved, $writer->bytes() );

		$writer->truncate_to( $saved );
		$this->assertSame( $saved, $writer->bytes() );
		$writer->write_statement( 'SET d=4' );
		$writer->close();

		$this->assertSame( array( 'SET a=1;', 'SET d=4;' ), $this->lines( $path ) );
	}

	public function test_resume_discards_what_a_killed_run_left_behind() {
		$path   = $this->temp();
		$writer = new SqlDumpWriter( $path );
		$writer->begin();
		$writer->write_statement( 'SET a=1' );
		$saved = $writer->bytes();
		$writer->close();

		// A run that was killed: it wrote a batch and half of the next one, but never saved its cursor.
		file_put_contents( $path, "INSERT INTO `t` VALUES (1),(2);\nINSERT INTO `t` VALUES (3),(4", FILE_APPEND );

		$resumed = new SqlDumpWriter( $path );
		$resumed->resume( $saved );
		$this->assertSame( $saved, $resumed->bytes() );
		$resumed->write_rows( 't', null, array( '(1)', '(2)' ) );
		$resumed->write_footer();
		$resumed->close();

		$lines = $this->lines( $path );
		$this->assertSame( array( 'SET a=1;', 'INSERT INTO `t` VALUES (1),(2);' ), array_slice( $lines, 0, 2 ) );
		$this->assertSame( '-- ovb-dump-end', end( $lines ) );
		$this->assertStringNotContainsString( '(3)', file_get_contents( $path ) );
	}

	public function test_resume_at_the_exact_end_keeps_everything() {
		$path   = $this->temp();
		$writer = new SqlDumpWriter( $path );
		$writer->begin();
		$writer->write_statement( 'SET a=1' );
		$end = $writer->bytes();
		$writer->close();

		$resumed = new SqlDumpWriter( $path );
		$resumed->resume( $end );
		$resumed->write_statement( 'SET b=2' );
		$resumed->close();
		$this->assertSame( array( 'SET a=1;', 'SET b=2;' ), $this->lines( $path ) );
	}

	public function test_resume_refuses_a_file_shorter_than_the_saved_offset() {
		$path = $this->temp();
		file_put_contents( $path, 'short' );
		$this->expectException( DumpException::class );
		( new SqlDumpWriter( $path ) )->resume( 1000 );
	}

	public function test_resume_refuses_a_missing_file() {
		$this->expectException( DumpException::class );
		( new SqlDumpWriter( sys_get_temp_dir() . '/ovbdump-missing-' . uniqid() ) )->resume( 5 );
	}

	public function test_begin_empties_an_existing_file() {
		$path = $this->temp();
		file_put_contents( $path, "old content\nmore" );
		$writer = new SqlDumpWriter( $path );
		$writer->begin();
		$writer->write_statement( 'SET a=1' );
		$writer->close();
		$this->assertSame( array( 'SET a=1;' ), $this->lines( $path ) );
	}

	public function test_an_unwritable_location_is_a_dump_exception() {
		$writer = new SqlDumpWriter( sys_get_temp_dir() . '/ovb-no-such-folder-' . uniqid() . '/db.sql' );
		$this->expectException( DumpException::class );
		$writer->begin();
	}

	public function test_charset_name_is_sanitised() {
		$this->assertSame( 'utf8mb4', SqlDumpWriter::charset_name( 'UTF8MB4' ) );
		$this->assertSame( 'utf8mb4', SqlDumpWriter::charset_name( '' ) );
		$this->assertSame( 'latin1droptablex_', SqlDumpWriter::charset_name( "latin1'; DROP TABLE x_; --" ), 'only letters, digits and underscore survive' );
		$this->assertSame( 'utf8', SqlDumpWriter::charset_name( 'utf8; --' ) );
	}

	public function test_crash_replay_produces_the_same_bytes() {
		// Writing in two sessions with a simulated crash in between gives the same file as one clean session.
		$clean = $this->temp();
		$w     = new SqlDumpWriter( $clean );
		$w->begin();
		$w->write_header( array( 'tables' => 1 ), 'utf8mb4' );
		$w->write_rows( 't', null, array( '(1)', '(2)', '(3)' ) );
		$w->write_rows( 't', null, array( '(4)', '(5)' ) );
		$w->write_footer();
		$w->close();

		$crashy = $this->temp();
		$a      = new SqlDumpWriter( $crashy );
		$a->begin();
		$a->write_header( array( 'tables' => 1 ), 'utf8mb4' );
		$a->write_rows( 't', null, array( '(1)', '(2)', '(3)' ) );
		$saved = $a->bytes();
		$a->write_rows( 't', null, array( '(4)', '(5)' ) ); // Written, but the cursor was never saved.
		$a->close();
		$b = new SqlDumpWriter( $crashy );
		$b->resume( $saved );
		$b->write_rows( 't', null, array( '(4)', '(5)' ) );
		$b->write_footer();
		$b->close();

		$this->assertSame(
			preg_replace( '/^-- created.*$/m', '', file_get_contents( $clean ) ),
			preg_replace( '/^-- created.*$/m', '', file_get_contents( $crashy ) )
		);
	}
}
