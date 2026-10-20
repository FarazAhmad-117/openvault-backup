<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Database\Util
 */
class RepositoryUtilTest extends TestCase {

	public function test_pagination_defaults_and_clamps() {
		$this->assertSame(
			array(
				'per_page' => 20,
				'page'     => 1,
				'offset'   => 0,
			),
			Util::clamp_pagination( array() )
		);
		$this->assertSame( 200, Util::clamp_pagination( array( 'per_page' => 99999 ) )['per_page'] );
		$this->assertSame( 1, Util::clamp_pagination( array( 'per_page' => 0 ) )['per_page'] );
		$this->assertSame( 1, Util::clamp_pagination( array( 'per_page' => -5 ) )['per_page'] );
		$this->assertSame( 1, Util::clamp_pagination( array( 'page' => 0 ) )['page'] );
		$this->assertSame( 1, Util::clamp_pagination( array( 'page' => -3 ) )['page'] );
		$this->assertSame( 20, Util::clamp_pagination( array( 'per_page' => '1; DROP TABLE x' ) )['per_page'] );
		$page = Util::clamp_pagination(
			array(
				'per_page' => '10',
				'page'     => '3',
			)
		);
		$this->assertSame( 20, $page['offset'] );
		$this->assertSame( 200, Util::clamp_pagination( array( 'per_page' => 200 ) )['per_page'] );
		$this->assertSame( 1, Util::clamp_pagination( array( 'per_page' => 1 ) )['per_page'] );
	}

	public function test_decode_json_never_fatals() {
		$this->assertSame( array(), Util::decode_json( null ) );
		$this->assertSame( array(), Util::decode_json( '' ) );
		$this->assertSame( array(), Util::decode_json( '{not json' ) );
		$this->assertSame( array(), Util::decode_json( '"a string"' ) );
		$this->assertSame( array(), Util::decode_json( '42' ) );
		$this->assertSame( array(), Util::decode_json( array( 'x' ) ) );
		$this->assertSame( array( 'a' => 1 ), Util::decode_json( '{"a":1}' ) );
	}

	public function test_is_datetime() {
		$this->assertTrue( Util::is_datetime( '2026-02-28 23:59:59' ) );
		$this->assertTrue( Util::is_datetime( '2024-02-29 00:00:00' ) );
		$this->assertFalse( Util::is_datetime( '2026-02-29 00:00:00' ) );
		$this->assertFalse( Util::is_datetime( '2026-13-01 00:00:00' ) );
		$this->assertFalse( Util::is_datetime( '2026-01-01 24:00:00' ) );
		$this->assertFalse( Util::is_datetime( '2026-01-01T10:00:00' ) );
		$this->assertFalse( Util::is_datetime( "2026-01-01 10:00:00\n" ) );
		$this->assertFalse( Util::is_datetime( "2026-01-01 10:00:00'; DROP TABLE x" ) );
		$this->assertFalse( Util::is_datetime( null ) );
		$this->assertFalse( Util::is_datetime( 12345 ) );
	}

	public function test_filter_datetime_bounds() {
		$this->assertSame( '2026-03-01 00:00:00', Util::filter_datetime( '2026-03-01', false ) );
		$this->assertSame( '2026-03-01 23:59:59', Util::filter_datetime( '2026-03-01', true ) );
		$this->assertSame( '2026-03-01 10:11:12', Util::filter_datetime( '2026-03-01 10:11:12' ) );
		$this->assertNull( Util::filter_datetime( 'yesterday' ) );
		$this->assertNull( Util::filter_datetime( '2026-02-30' ) );
		$this->assertNull( Util::filter_datetime( array( '2026-03-01' ) ) );
	}

	public function test_non_negative_int() {
		$this->assertSame( 0, Util::non_negative_int( 0, 'x' ) );
		$this->assertSame( 7, Util::non_negative_int( '7', 'x' ) );
		foreach ( array( -1, '-1', 1.5, '1.5', true, null, 'abc', '1 OR 1=1', '', array(), '99999999999999999999' ) as $bad ) {
			try {
				Util::non_negative_int( $bad, 'x' );
				$this->fail( 'Expected rejection of ' . var_export( $bad, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Invalid x.', $e->getMessage() );
			}
		}
	}

	public function test_clean_text() {
		$this->assertSame( 'abc', Util::clean_text( "  a\x00b\x07c\x7f ", 10 ) );
		$this->assertSame( 'ab', Util::clean_text( "a\nb", 10 ) );
		$this->assertSame( "a\nb", Util::clean_text( "a\nb", 10, true ) );
		$this->assertSame( 'abc', Util::clean_text( 'abcdef', 3 ) );
		$this->assertSame( '', Util::clean_text( array( 'x' ), 3 ) );
		$this->assertSame( "'; DROP TABLE x;--", Util::clean_text( "'; DROP TABLE x;--", 50 ), 'Metacharacters are kept: they are only ever bound parameters.' );
		$this->assertSame( 2, mb_strlen( Util::clean_text( "\u{1F512}\u{1F512}\u{1F512}", 2 ), 'UTF-8' ) );
	}

	public function test_direction_and_one_of() {
		$this->assertSame( 'ASC', Util::direction( 'asc' ) );
		$this->assertSame( 'DESC', Util::direction( 'DESC' ) );
		$this->assertSame( 'DESC', Util::direction( 'ASC; DROP TABLE x' ) );
		$this->assertSame( 'DESC', Util::direction( null ) );
		$this->assertSame( 'a', Util::one_of( 'a', array( 'a', 'b' ) ) );
		$this->assertNull( Util::one_of( 'A', array( 'a', 'b' ) ) );
		$this->assertNull( Util::one_of( 0, array( '0', 'b' ) ) );
		$this->assertNull( Util::one_of( array( 'a' ), array( 'a' ) ) );
	}
}
