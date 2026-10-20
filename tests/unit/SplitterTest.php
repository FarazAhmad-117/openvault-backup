<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\Splitter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Archive\Splitter
 */
class SplitterTest extends TestCase {

	public function test_limit_defaults_and_clamps() {
		$mb = 1048576;
		$this->assertSame( 250 * $mb, Splitter::limit_bytes( array() ) );
		$this->assertSame( 250 * $mb, Splitter::limit_bytes( array( 'part_size_mb' => 'abc' ) ) );
		$this->assertSame( 10 * $mb, Splitter::limit_bytes( array( 'part_size_mb' => 10 ) ) );
		$this->assertSame( 10 * $mb, Splitter::limit_bytes( array( 'part_size_mb' => 1 ) ), 'minimum 10 MB' );
		$this->assertSame( 10 * $mb, Splitter::limit_bytes( array( 'part_size_mb' => -50 ) ) );
		$this->assertSame( 10 * $mb, Splitter::limit_bytes( array( 'part_size_mb' => 0 ) ) );
		$this->assertSame( 500 * $mb, Splitter::limit_bytes( array( 'part_size_mb' => '500' ) ) );
		$this->assertSame( 4096 * $mb, Splitter::limit_bytes( array( 'part_size_mb' => 999999 ) ), 'maximum 4096 MB' );
	}

	public function test_should_roll() {
		$limit = 100;
		$this->assertFalse( Splitter::should_roll( 0, 0, 10, $limit ), 'an empty part never rolls' );
		$this->assertFalse( Splitter::should_roll( 5000, 0, 5000, $limit ), 'a part without files never rolls, even for a huge file' );
		$this->assertFalse( Splitter::should_roll( 50, 3, 40, $limit ), 'room left' );
		$this->assertFalse( Splitter::should_roll( 50, 3, 99, $limit ), 'the file that crosses the limit stays in the part' );
		$this->assertTrue( Splitter::should_roll( 100, 3, 1, $limit ), 'at the limit' );
		$this->assertTrue( Splitter::should_roll( 150, 3, 1, $limit ), 'over the limit' );
		$this->assertTrue( Splitter::should_roll( 10, 3, 100, $limit ), 'a file as big as the limit gets its own part' );
		$this->assertTrue( Splitter::should_roll( 10, 3, 5000, $limit ), 'a file bigger than the limit gets its own part' );
	}

	public function test_a_part_exceeds_the_limit_by_less_than_one_file() {
		$limit = 1000;
		$sizes = array();
		for ( $i = 0; $i < 400; $i++ ) {
			$sizes[] = 1 + ( $i * 7919 ) % 700;
			// A fixed pseudo-random spread of sizes between 1 and 700.
		}
		$sizes[] = 5000;
		$sizes[] = 1000;
		$parts   = array( array() );
		$load    = 0;
		foreach ( $sizes as $size ) {
			if ( Splitter::should_roll( $load, count( $parts[ count( $parts ) - 1 ] ), $size, $limit ) ) {
				$parts[] = array();
				$load    = 0;
			}
			$parts[ count( $parts ) - 1 ][] = $size;
			$load                          += $size;
		}
		$this->assertSame( array_sum( $sizes ), array_sum( array_map( 'array_sum', $parts ) ), 'every file is in exactly one part' );
		foreach ( $parts as $part ) {
			$this->assertNotEmpty( $part );
			if ( count( $part ) > 1 ) {
				$this->assertLessThan( 2 * $limit, array_sum( $part ) );
				$this->assertLessThan( $limit + max( $part ), array_sum( $part ) + 1, 'over the limit by less than one file' );
			}
		}
		$alone = array_values(
			array_filter(
				$parts,
				static function ( $part ) {
					return in_array( 5000, $part, true );
				}
			)
		);
		$this->assertSame( array( 5000 ), $alone[0], 'the file bigger than the limit is alone' );
	}

	public function test_part_names() {
		$this->assertSame( 'db.part01.zip', Splitter::part_name( 'db', 1, 'zip' ) );
		$this->assertSame( 'files.part01.tar.gz', Splitter::part_name( 'files', 1, 'tar.gz' ) );
		$this->assertSame( 'files.part12.zip', Splitter::part_name( 'files', 12, 'zip' ) );
		$this->assertSame( 'files.part123.zip', Splitter::part_name( 'files', 123, 'zip' ) );
	}

	/**
	 * @dataProvider bad_parts
	 */
	public function test_bad_part_names_throw( $kind, $index, $extension ) {
		$this->expectException( \InvalidArgumentException::class );
		Splitter::part_name( $kind, $index, $extension );
	}

	public function bad_parts() {
		return array(
			'unknown kind'   => array( 'other', 1, 'zip' ),
			'zero index'     => array( 'files', 0, 'zip' ),
			'negative index' => array( 'files', -1, 'zip' ),
			'path extension' => array( 'files', 1, '../zip' ),
			'empty ext'      => array( 'files', 1, '' ),
		);
	}

	public function test_parse_part_name() {
		$this->assertSame(
			array(
				'kind'  => 'db',
				'index' => 1,
			),
			Splitter::parse_part_name( 'db.part01.tar.gz' )
		);
		$this->assertSame(
			array(
				'kind'  => 'files',
				'index' => 123,
			),
			Splitter::parse_part_name( 'files.part123.zip' )
		);
		$this->assertNull( Splitter::parse_part_name( 'manifest.json' ) );
		$this->assertNull( Splitter::parse_part_name( '../files.part01.zip' ) );
		$this->assertNull( Splitter::parse_part_name( 'files.part1.zip' ) );
		$this->assertNull( Splitter::parse_part_name( "files.part01.zip\n" ) );
	}
}
