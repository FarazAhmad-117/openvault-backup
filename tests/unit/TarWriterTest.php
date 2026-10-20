<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real files in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\TarWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Archive\TarWriter
 */
class TarWriterTest extends TestCase {

	/**
	 * @var string[]
	 */
	private $cleanup = array();

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		parent::tearDown();
	}

	private function checksum_ok( $block ) {
		$sum = array_sum( unpack( 'C*', substr( $block, 0, 148 ) . '        ' . substr( $block, 156 ) ) );
		return sprintf( '%06o', $sum ) . "\0 " === substr( $block, 148, 8 );
	}

	public function test_byte_exact_header_of_a_tiny_file() {
		$header = TarWriter::entry_header( 'a.txt', 5, 1700000000 );
		$this->assertSame( 512, strlen( $header ) );

		$this->assertSame( str_pad( 'a.txt', 100, "\0" ), substr( $header, 0, 100 ), 'name' );
		$this->assertSame( "0000644\0", substr( $header, 100, 8 ), 'mode' );
		$this->assertSame( "0000000\0", substr( $header, 108, 8 ), 'uid' );
		$this->assertSame( "0000000\0", substr( $header, 116, 8 ), 'gid' );
		$this->assertSame( "00000000005\0", substr( $header, 124, 12 ), 'size' );
		$this->assertSame( sprintf( '%011o', 1700000000 ) . "\0", substr( $header, 136, 12 ), 'mtime' );
		$this->assertSame( '0', substr( $header, 156, 1 ), 'type: regular file' );
		$this->assertSame( str_repeat( "\0", 100 ), substr( $header, 157, 100 ), 'no link name' );
		$this->assertSame( "ustar\0" . '00', substr( $header, 257, 8 ), 'magic and version' );
		$this->assertSame( str_repeat( "\0", 64 ), substr( $header, 265, 64 ), 'no user or group names' );
		$this->assertSame( str_repeat( "\0", 183 ), substr( $header, 329, 183 ), 'device numbers, empty prefix, padding' );
		$this->assertTrue( $this->checksum_ok( $header ) );
	}

	public function test_padding_and_end_blocks() {
		$this->assertSame( 0, TarWriter::padding( 0 ) );
		$this->assertSame( 507, TarWriter::padding( 5 ) );
		$this->assertSame( 0, TarWriter::padding( 512 ) );
		$this->assertSame( 511, TarWriter::padding( 513 ) );
		$this->assertSame( 1, TarWriter::padding( 1023 ) );
		$this->assertSame( str_repeat( "\0", 1024 ), TarWriter::end_blocks() );
	}

	public function test_a_name_of_exactly_100_bytes_needs_no_extension() {
		$name   = str_repeat( 'a', 100 );
		$header = TarWriter::entry_header( $name, 1, 0 );
		$this->assertSame( 512, strlen( $header ) );
		$this->assertSame( $name, TarWriter::parse_header( $header )['name'] );
	}

	public function test_a_long_name_that_splits_uses_the_prefix_field() {
		$name   = 'content/plugins/' . str_repeat( 'd', 60 ) . '/' . str_repeat( 'f', 80 ) . '.php';
		$header = TarWriter::entry_header( $name, 10, 0 );
		$this->assertSame( 512, strlen( $header ), 'one block, no PAX header' );
		$parsed = TarWriter::parse_header( $header );
		$this->assertSame( $name, $parsed['name'] );
		$this->assertSame( str_repeat( 'f', 80 ) . '.php', rtrim( substr( $header, 0, 100 ), "\0" ) );
		$this->assertSame( 'content/plugins/' . str_repeat( 'd', 60 ), rtrim( substr( $header, 345, 155 ), "\0" ) );
		$this->assertTrue( $this->checksum_ok( $header ) );
	}

	public function test_a_name_that_cannot_split_uses_a_pax_header() {
		$name   = 'content/' . str_repeat( 'x', 300 ) . '.txt';
		$blocks = TarWriter::entry_header( $name, 3, 1700000000 );
		$this->assertSame( 0, strlen( $blocks ) % 512 );
		$this->assertSame( 3 * 512, strlen( $blocks ), 'pax header, one block of records, entry header' );

		$pax = TarWriter::parse_header( substr( $blocks, 0, 512 ) );
		$this->assertSame( 'x', $pax['type'] );
		$records = TarWriter::parse_pax( substr( $blocks, 512, $pax['size'] ) );
		$this->assertSame( array( 'path' => $name ), $records );
		$entry = TarWriter::parse_header( substr( $blocks, 1024, 512 ) );
		$this->assertSame( '0', $entry['type'] );
		$this->assertSame( 3, $entry['size'] );
		$this->assertSame( 100, strlen( rtrim( substr( $blocks, 1024, 100 ), "\0" ) ), 'the name field holds a shortened copy' );
	}

	public function test_a_non_ascii_name_uses_a_pax_header() {
		$name   = "content/uploads/caf\xc3\xa9 \xf0\x9f\x98\x80.txt";
		$blocks = TarWriter::entry_header( $name, 1, 0 );
		$pax    = TarWriter::parse_header( substr( $blocks, 0, 512 ) );
		$this->assertSame( 'x', $pax['type'] );
		$this->assertSame( array( 'path' => $name ), TarWriter::parse_pax( substr( $blocks, 512, $pax['size'] ) ) );
		$this->assertSame( 'content/uploads/caf__ ____.txt', rtrim( substr( $blocks, 1024, 100 ), "\0" ), 'ASCII fallback name' );
	}

	public function test_pax_record_lengths_are_exact_at_every_digit_boundary() {
		for ( $length = 80; $length <= 140; $length++ ) {
			$name   = str_pad( "caf\xc3\xa9/", $length, 'n' );
			$blocks = TarWriter::entry_header( $name, 0, 0 );
			$pax    = TarWriter::parse_header( substr( $blocks, 0, 512 ) );
			$this->assertSame( array( 'path' => $name ), TarWriter::parse_pax( substr( $blocks, 512, $pax['size'] ) ), 'name length ' . $length );
			$body = substr( $blocks, 512, $pax['size'] );
			$this->assertSame( (int) $body, strlen( $body ), 'the record starts with its own length' );
		}
	}

	public function test_a_size_above_8_gib_goes_into_a_pax_record() {
		$size   = 9000000000;
		$blocks = TarWriter::entry_header( 'content/big.bin', $size, 5 );
		$this->assertSame( 3 * 512, strlen( $blocks ), 'pax header, records, entry header' );
		$pax = TarWriter::parse_header( substr( $blocks, 0, 512 ) );
		$this->assertSame( 'x', $pax['type'] );
		$this->assertSame( array( 'size' => '9000000000' ), TarWriter::parse_pax( substr( $blocks, 512, $pax['size'] ) ) );
		$this->assertSame( 0, TarWriter::parse_header( substr( $blocks, 1024, 512 ) )['size'], 'the size field is left at 0, the PAX record wins' );
	}

	public function test_a_size_of_exactly_the_octal_limit_stays_in_the_header() {
		$blocks = TarWriter::entry_header( 'a', TarWriter::MAX_OCTAL, 0 );
		$this->assertSame( 512, strlen( $blocks ) );
		$this->assertSame( TarWriter::MAX_OCTAL, TarWriter::parse_header( $blocks )['size'] );
		$this->assertSame( 3 * 512, strlen( TarWriter::entry_header( 'a', TarWriter::MAX_OCTAL + 1, 0 ) ) );
	}

	public function test_parse_header_rejects_damage() {
		$header = TarWriter::entry_header( 'a.txt', 5, 0 );
		$this->assertNotNull( TarWriter::parse_header( $header ) );
		$this->assertNull( TarWriter::parse_header( substr( $header, 0, 511 ) ), 'short block' );
		$this->assertNull( TarWriter::parse_header( 'b' . substr( $header, 1 ) ), 'checksum no longer matches' );
		$this->assertNull( TarWriter::parse_header( str_repeat( "\0", 512 ) ), 'a zero block is not a header' );
	}

	public function test_negative_values_are_clamped() {
		$parsed = TarWriter::parse_header( TarWriter::entry_header( 'a', -5, -9 ) );
		$this->assertSame( 0, $parsed['size'] );
	}

	/**
	 * Round trip through PharData (short and prefix names only: PharData does not read PAX records).
	 */
	public function test_round_trip_through_phardata() {
		if ( ! class_exists( 'PharData' ) ) {
			$this->markTestSkipped( 'The phar extension is not loaded.' );
		}
		$path            = sys_get_temp_dir() . '/ovb-tar-' . bin2hex( random_bytes( 4 ) ) . '.tar';
		$this->cleanup[] = $path;

		$files = array(
			'db/db.sql'                      => "SELECT 1;\n",
			'empty.txt'                      => '',
			'exact512.bin'                   => str_repeat( 'x', 512 ),
			'content/plugins/' . str_repeat( 'd', 60 ) . '/' . str_repeat( 'f', 80 ) . '.php' => '<?php // long name via prefix',
			'content/uploads/with space.txt' => 'space',
		);
		$data  = '';
		foreach ( $files as $name => $body ) {
			$data .= TarWriter::entry_header( $name, strlen( $body ), 1700000000 ) . $body . str_repeat( "\0", TarWriter::padding( strlen( $body ) ) );
		}
		file_put_contents( $path, $data . TarWriter::end_blocks() );

		$phar = new \PharData( $path );
		$seen = array();
		foreach ( new \RecursiveIteratorIterator( $phar ) as $item ) {
			$seen[ substr( $item->getPathname(), strlen( 'phar://' . $path . '/' ) ) ] = file_get_contents( $item->getPathname() );
		}
		ksort( $seen );
		$expected = $files;
		ksort( $expected );
		$this->assertSame( $expected, $seen );
	}
}
