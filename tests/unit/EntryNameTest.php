<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Scan\EntryName;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Scan\EntryName
 */
class EntryNameTest extends TestCase {

	/**
	 * @dataProvider safe_names
	 */
	public function test_safe_names( $name ) {
		$this->assertTrue( EntryName::is_safe( $name ), $name );
	}

	public function safe_names() {
		return array(
			'db'          => array( 'db/db.sql' ),
			'content'     => array( 'content/uploads/2026/05/photo.jpg' ),
			'spaces'      => array( 'content/uploads/my file (1).jpg' ),
			'unicode'     => array( 'content/uploads/caf' . "\xc3\xa9" . '.txt' ),
			'emoji'       => array( 'content/uploads/' . "\xf0\x9f\x98\x80" . '.png' ),
			'dots inside' => array( 'abspath/.htaccess' ),
			'dotted dir'  => array( 'content/a..b/c' ),
			'long'        => array( 'content/' . str_repeat( 'a', 200 ) . '/' . str_repeat( 'b', 200 ) ),
		);
	}

	/**
	 * @dataProvider unsafe_names
	 */
	public function test_unsafe_names( $name ) {
		$this->assertFalse( EntryName::is_safe( $name ) );
	}

	public function unsafe_names() {
		return array(
			'empty'            => array( '' ),
			'not a string'     => array( null ),
			'array'            => array( array( 'a' ) ),
			'leading slash'    => array( '/etc/passwd' ),
			'trailing slash'   => array( 'content/dir/' ),
			'dot dot'          => array( 'content/../../etc/passwd' ),
			'leading dot dot'  => array( '../x' ),
			'only dot dot'     => array( '..' ),
			'single dot'       => array( 'content/./x' ),
			'empty segment'    => array( 'content//x' ),
			'backslash'        => array( 'content\\x' ),
			'backslash dotdot' => array( 'content\\..\\x' ),
			'drive letter'     => array( 'C:/Windows/x' ),
			'nul'              => array( "content/a\0b" ),
			'newline'          => array( "content/a\nb" ),
			'tab'              => array( "content/a\tb" ),
			'delete char'      => array( "content/a\x7fb" ),
			'invalid utf8'     => array( "content/a\xffb" ),
			'too long'         => array( 'content/' . str_repeat( 'a', EntryName::MAX_BYTES ) ),
		);
	}

	public function test_join() {
		$this->assertSame( 'content/plugins', EntryName::join( 'content/plugins', '' ) );
		$this->assertSame( 'content/plugins/x/y.php', EntryName::join( 'content/plugins', 'x/y.php' ) );
		$this->assertNull( EntryName::join( 'content/plugins', '../x' ) );
		$this->assertNull( EntryName::join( 'content/plugins', 'x/../../y' ) );
		$this->assertNull( EntryName::join( 'content/plugins', "a\nb" ) );
	}

	public function test_basename_and_normalise_path() {
		$this->assertSame( 'c.txt', EntryName::basename( 'a/b/c.txt' ) );
		$this->assertSame( 'c.txt', EntryName::basename( 'c.txt' ) );
		$this->assertSame( '/var/www', EntryName::normalise_path( '/var/www/' ) );
		$this->assertSame( 'C:/site/wp', EntryName::normalise_path( 'C:\\site\\wp\\' ) );
		$this->assertSame( '/', EntryName::normalise_path( '/' ) );
		$this->assertSame( '', EntryName::normalise_path( '' ) );
	}
}
