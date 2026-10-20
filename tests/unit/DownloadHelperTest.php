<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test messages only.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Security\DownloadHelper as H;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Security\DownloadHelper
 */
class DownloadHelperTest extends TestCase {

	public function test_no_header_means_full_file() {
		foreach ( array( null, '', false, 5, array() ) as $header ) {
			$this->assertSame( H::RANGE_FULL, H::parse_range( $header, 1000 )['type'] );
		}
		$this->assertSame( 999, H::parse_range( null, 1000 )['end'] );
	}

	/**
	 * @dataProvider valid_ranges
	 */
	public function test_valid_single_ranges( $header, $size, $start, $end ) {
		$range = H::parse_range( $header, $size );
		$this->assertSame( H::RANGE_PARTIAL, $range['type'], $header );
		$this->assertSame( $start, $range['start'], $header );
		$this->assertSame( $end, $range['end'], $header );
	}

	public function valid_ranges() {
		return array(
			'first 100'        => array( 'bytes=0-99', 1000, 0, 99 ),
			'middle'           => array( 'bytes=100-199', 1000, 100, 199 ),
			'open ended'       => array( 'bytes=900-', 1000, 900, 999 ),
			'end past size'    => array( 'bytes=900-5000', 1000, 900, 999 ),
			'single byte'      => array( 'bytes=5-5', 1000, 5, 5 ),
			'suffix'           => array( 'bytes=-500', 1000, 500, 999 ),
			'suffix too large' => array( 'bytes=-5000', 1000, 0, 999 ),
			'last byte'        => array( 'bytes=999-999', 1000, 999, 999 ),
			'spaces around'    => array( '  bytes=0-9 ', 1000, 0, 9 ),
		);
	}

	/**
	 * @dataProvider ignored_headers
	 */
	public function test_malformed_or_multiple_ranges_are_ignored( $header ) {
		$this->assertSame( H::RANGE_FULL, H::parse_range( $header, 1000 )['type'], $header );
	}

	public function ignored_headers() {
		return array(
			'multiple'       => array( 'bytes=0-9,20-29' ),
			'overlapping'    => array( 'bytes=0-50,25-75' ),
			'other unit'     => array( 'items=0-9' ),
			'backwards'      => array( 'bytes=50-10' ),
			'garbage'        => array( 'bytes=abc-def' ),
			'no equals'      => array( 'bytes 0-9' ),
			'negative start' => array( 'bytes=--5' ),
			'empty both'     => array( 'bytes=-' ),
			'huge number'    => array( 'bytes=0-99999999999999999999999' ),
			'crlf injection' => array( "bytes=0-9\r\nX-Evil: 1" ),
			'nul'            => array( "bytes=0-\0" ),
			'very long'      => array( 'bytes=' . str_repeat( '1', 500 ) . '-' ),
		);
	}

	public function test_unsatisfiable_ranges() {
		$this->assertSame( H::RANGE_UNSATISFIABLE, H::parse_range( 'bytes=1000-', 1000 )['type'] );
		$this->assertSame( H::RANGE_UNSATISFIABLE, H::parse_range( 'bytes=2000-3000', 1000 )['type'] );
		$this->assertSame( H::RANGE_UNSATISFIABLE, H::parse_range( 'bytes=-0', 1000 )['type'] );
	}

	public function test_empty_file_is_always_full() {
		$this->assertSame( H::RANGE_FULL, H::parse_range( 'bytes=0-9', 0 )['type'] );
	}

	/**
	 * @dataProvider filenames
	 */
	public function test_sanitize_filename( $input, $expected ) {
		$this->assertSame( $expected, H::sanitize_filename( $input ) );
	}

	public function filenames() {
		return array(
			'plain'        => array( 'files.part01.zip', 'files.part01.zip' ),
			'manifest'     => array( 'manifest.json', 'manifest.json' ),
			'traversal'    => array( '../../etc/passwd', 'passwd' ),
			'absolute'     => array( '/etc/passwd', 'passwd' ),
			'windows'      => array( 'C:\\Windows\\system32\\evil.exe', 'evil.exe' ),
			'dots inside'  => array( 'a..b.zip', 'a_b.zip' ),
			'leading dots' => array( '...hidden', 'hidden' ),
			'quote'        => array( 'a"b.zip', 'a_b.zip' ),
			'nul'          => array( "a\0b.zip", 'a_b.zip' ),
			'empty'        => array( '', 'backup' ),
			'only dots'    => array( '....', 'backup' ),
			'not a string' => array( array( 'x' ), 'backup' ),
			'null'         => array( null, 'backup' ),
		);
	}

	public function test_crlf_never_survives_in_a_filename() {
		$name = H::sanitize_filename( "ok.zip\r\nSet-Cookie: x=1" );
		$this->assertDoesNotMatchRegularExpression( '/[\r\n :"]/', $name );
	}

	public function test_sanitize_filename_is_length_limited() {
		$this->assertLessThanOrEqual( 150, strlen( H::sanitize_filename( str_repeat( 'a', 1000 ) . '.zip' ) ) );
	}

	public function test_content_types() {
		$this->assertSame( 'application/json', H::content_type( 'manifest.json' ) );
		$this->assertSame( 'application/octet-stream', H::content_type( 'files.part01.zip' ) );
		$this->assertSame( 'application/octet-stream', H::content_type( 'x.json' ) );
	}

	/**
	 * @dataProvider inside_cases
	 */
	public function test_is_inside( $path, $base, $expected ) {
		$this->assertSame( $expected, H::is_inside( $path, $base ), var_export( $path, true ) );
	}

	public function inside_cases() {
		$b = '/var/www/wp-content/ovb-x/backups';
		return array(
			'child'          => array( $b . '/f/a.zip', $b, true ),
			'trailing slash' => array( $b . '/f/a.zip', $b . '/', true ),
			'backslashes'    => array( str_replace( '/', '\\', $b . '/f/a.zip' ), $b, true ),
			'same'           => array( $b, $b, false ),
			'sibling prefix' => array( $b . '-evil/a.zip', $b, false ),
			'dotdot'         => array( $b . '/../secret', $b, false ),
			'dotdot middle'  => array( $b . '/f/../../x', $b, false ),
			'dot segment'    => array( $b . '/./a', $b, false ),
			'double slash'   => array( $b . '//a', $b, false ),
			'outside'        => array( '/etc/passwd', $b, false ),
			'relative'       => array( 'backups/a.zip', $b, false ),
			'nul'            => array( $b . "/a\0.zip", $b, false ),
			'empty'          => array( '', $b, false ),
			'empty base'     => array( $b . '/a', '', false ),
			'root base'      => array( '/a', '/', false ),
			'not strings'    => array( null, $b, false ),
		);
	}
}
