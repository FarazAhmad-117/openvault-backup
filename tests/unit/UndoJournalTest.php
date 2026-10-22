<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged,Generic.CodeAnalysis.AssignmentInCondition -- These tests exercise real files and links in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Restore\UndoJournal;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FileFixtures.php';

/**
 * The journal format: lines, parser, backwards reader, partial lines.
 *
 * @covers \OpenVault\Backup\Restore\UndoJournal
 */
class UndoJournalTest extends TestCase {

	/**
	 * Temp folder.
	 *
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = FileFixtures::tmp();
	}

	protected function tearDown(): void {
		FileFixtures::rrmdir( $this->dir );
		parent::tearDown();
	}

	public function test_lines_round_trip_through_the_parser() {
		$sha = hash( 'sha256', 'x' );
		$o   = UndoJournal::parse_line( UndoJournal::overwritten( 'content/uploads/日本語 😀.txt', 1234, $sha ) );
		$this->assertSame( array( 'O', 'content/uploads/日本語 😀.txt', 1234, $sha ), array( $o['op'], $o['entry'], $o['size'], $o['sha256'] ) );

		$unknown_sha = UndoJournal::parse_line( UndoJournal::overwritten( 'content/a', 5, '' ) );
		$this->assertSame( '', $unknown_sha['sha256'] );
		$this->assertSame( '', UndoJournal::parse_line( UndoJournal::overwritten( 'content/a', 5, 'not-a-hash' ) )['sha256'], 'a malformed checksum is not written' );

		$this->assertSame( array( 'C', 'content/uploads/new.txt' ), $this->op_entry( UndoJournal::created( 'content/uploads/new.txt' ) ) );
		$this->assertSame( array( 'D', 'content/uploads/2026' ), $this->op_entry( UndoJournal::folder( 'content/uploads/2026' ) ) );
		$this->assertSame( 'S', UndoJournal::parse_line( UndoJournal::skipped( 'content/x', 'link' ) )['op'] );
		$this->assertSame( 'link', UndoJournal::parse_line( UndoJournal::skipped( 'content/x', 'link' ) )['reason'] );
	}

	/**
	 * Op and entry of a line.
	 *
	 * @param string $line Line.
	 * @return string[]
	 */
	private function op_entry( $line ) {
		$parsed = UndoJournal::parse_line( $line );
		return array( $parsed['op'], $parsed['entry'] );
	}

	public function test_an_unsafe_name_is_never_written() {
		foreach ( array( "content/a\nb", "content/a\tb", '../x', '/abs', 'a//b', "a\0b", '' ) as $name ) {
			foreach ( array( 'overwritten', 'created', 'folder' ) as $fn ) {
				try {
					'overwritten' === $fn ? UndoJournal::overwritten( $name, 1, '' ) : UndoJournal::$fn( $name );
					$this->fail( $fn . ' accepted ' . bin2hex( $name ) );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertSame( 'Unsafe name for the undo journal.', $e->getMessage() );
				}
			}
		}
	}

	public function test_a_skipped_name_from_a_hostile_archive_cannot_break_the_format() {
		$hostile = "content/uploads/a\nO\tcontent/plugins/x\t1\t\nC\tabspath/wp-config.php\0" . str_repeat( 'z', 500 );
		$line    = UndoJournal::skipped( $hostile, "link\nC\tx" );
		$this->assertStringNotContainsString( "\n", $line );
		$this->assertStringNotContainsString( "\0", $line );
		$this->assertSame( 3, count( explode( "\t", $line ) ), 'exactly three columns' );
		$parsed = UndoJournal::parse_line( $line );
		$this->assertSame( 'S', $parsed['op'] );
		$this->assertSame( 'linkx', $parsed['reason'], 'the reason keeps letters and underscores only' );
		$this->assertLessThan( 700, strlen( $line ), 'the name is cut' );
	}

	public function test_malformed_lines_are_ignored() {
		$sha = hash( 'sha256', 'x' );
		foreach ( array( '', 'X', "O\tcontent/a", "O\tcontent/a\t5", "O\tcontent/a\tfive\t", "O\tcontent/a\t5\tZZZ", "O\t../a\t5\t", "O\tcontent/a\t5\t{$sha}\textra", 'C', "C\t", "C\t../x", "C\tcontent/a\textra", "D\t/abs", "H\t2\t1\t1\t{}", "H\t1\t1", 'o' . "\tcontent/a\t5\t" ) as $line ) {
			$this->assertNull( UndoJournal::parse_line( $line ), bin2hex( $line ) );
		}
		$this->assertNull( UndoJournal::parse_line( str_repeat( 'a', UndoJournal::MAX_LINE + 1 ) ) );
		$this->assertNull( UndoJournal::parse_line( null ) );
	}

	public function test_the_header_carries_the_contents_flags_only() {
		$header = UndoJournal::header(
			12,
			1700000000,
			array(
				'db'         => true,
				'plugins'    => true,
				'uploads'    => false,
				'weird key!' => true,
				'themes'     => 'yes',
			)
		);
		$this->assertStringNotContainsString( "\n", $header );
		$this->assertSame(
			array(
				'db'      => true,
				'plugins' => true,
			),
			UndoJournal::header_contents( $header )
		);
		$this->assertSame( array( 'H', '', 0 ), array( UndoJournal::parse_line( $header )['op'], UndoJournal::parse_line( $header )['entry'], UndoJournal::parse_line( $header )['size'] ) );
		$this->assertNull( UndoJournal::header_contents( 'H' . "\t" . '9' . "\t1\t1\t{}" ) );
		$this->assertNull( UndoJournal::header_contents( "H\t1\t1\t1\tnot json" ) );
		$this->assertNull( UndoJournal::header_contents( "C\tcontent/a" ) );
		$this->assertSame( array(), UndoJournal::header_contents( UndoJournal::header( 1, 1, array() ) ) );
	}

	public function test_complete_end_ignores_a_fragment_after_a_crash() {
		$path = $this->dir . '/j.log';
		file_put_contents( $path, "H\t1\t1\t1\t{}\nC\tcontent/a\nC\tcont" );
		$this->assertSame( strlen( "H\t1\t1\t1\t{}\nC\tcontent/a\n" ), UndoJournal::complete_end( $path ) );
		file_put_contents( $path, "C\tcontent/a\n" );
		$this->assertSame( 12, UndoJournal::complete_end( $path ) );
		file_put_contents( $path, 'no newline at all' );
		$this->assertSame( 0, UndoJournal::complete_end( $path ) );
		file_put_contents( $path, '' );
		$this->assertSame( 0, UndoJournal::complete_end( $path ) );
		$this->assertSame( 0, UndoJournal::complete_end( $this->dir . '/missing.log' ) );
	}

	/**
	 * Read a journal backwards, line by line.
	 *
	 * @param string $path Path.
	 * @return string[]
	 */
	private function backwards( $path ) {
		$handle = fopen( $path, 'rb' );
		$end    = UndoJournal::complete_end( $path );
		$lines  = array();
		while ( null !== ( $found = UndoJournal::line_before( $handle, $end ) ) ) {
			$lines[] = $found[0];
			$this->assertLessThan( $end, $found[1], 'the reader always moves back' );
			$end = $found[1];
		}
		fclose( $handle );
		return $lines;
	}

	public function test_the_backwards_reader_returns_every_line_in_reverse_across_block_boundaries() {
		$path  = $this->dir . '/j.log';
		$lines = array( UndoJournal::header( 3, 1, array( 'db' => true ) ) );
		for ( $i = 0; $i < 900; $i++ ) {
			$lines[] = 0 === $i % 3 ? UndoJournal::created( 'content/uploads/file-' . $i . '-' . str_repeat( 'n', $i % 97 ) . '.txt' ) : UndoJournal::overwritten( 'content/plugins/p' . $i . '/f.php', $i, hash( 'sha256', (string) $i ) );
		}
		file_put_contents( $path, implode( "\n", $lines ) . "\n" );
		$this->assertGreaterThan( UndoJournal::BLOCK_SIZE * 5, filesize( $path ) );
		$this->assertSame( array_reverse( $lines ), $this->backwards( $path ) );
	}

	public function test_a_fragment_at_the_end_is_not_replayed_and_the_lines_before_it_are() {
		$path = $this->dir . '/j.log';
		file_put_contents( $path, UndoJournal::header( 3, 1, array() ) . "\n" . UndoJournal::created( 'content/a' ) . "\n" . UndoJournal::overwritten( 'content/b', 3, '' ) . "\n" . 'O' . "\t" . 'content/c' . "\t4" );
		$this->assertSame( array( UndoJournal::overwritten( 'content/b', 3, '' ), UndoJournal::created( 'content/a' ), UndoJournal::header( 3, 1, array() ) ), $this->backwards( $path ) );
	}

	public function test_a_single_line_and_empty_lines() {
		$path = $this->dir . '/j.log';
		file_put_contents( $path, "C\tcontent/a\n" );
		$this->assertSame( array( "C\tcontent/a" ), $this->backwards( $path ) );
		file_put_contents( $path, "\n\nC\tcontent/a\n\n" );
		$this->assertSame( array( '', "C\tcontent/a", '', '' ), $this->backwards( $path ) );
		file_put_contents( $path, '' );
		$this->assertSame( array(), $this->backwards( $path ) );
	}

	public function test_a_line_that_is_far_too_long_is_returned_empty_and_the_reader_moves_on() {
		$path = $this->dir . '/j.log';
		file_put_contents( $path, "C\tcontent/a\n" . str_repeat( 'x', 100000 ) . "\nC\tcontent/b\n" );
		$lines = $this->backwards( $path );
		$this->assertSame( "C\tcontent/b", $lines[0] );
		$this->assertSame( '', $lines[1], 'a monster line is not trusted' );
		$this->assertContains( "C\tcontent/a", $lines, 'the reader finds the line in front of it again' );
	}
}
