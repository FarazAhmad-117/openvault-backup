<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Support\Paths;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Support\Paths
 */
class PathsTest extends TestCase {

	const UUID = '3f2a9c1e-5b7d-4e8a-9c3b-1d2e3f4a5b6c';

	/**
	 * @dataProvider valid_names
	 */
	public function test_valid_dir_names( $name ) {
		$this->assertTrue( Paths::is_valid_dir_name( $name ), (string) $name );
	}

	public function valid_names() {
		return array(
			'generated'   => array( 'ovb-abcdef0123456789' ),
			'with dots'   => array( 'ovb.backups' ),
			'underscore'  => array( 'my_backups' ),
			'digit start' => array( '1backup' ),
		);
	}

	/**
	 * @dataProvider invalid_names
	 */
	public function test_invalid_dir_names( $name ) {
		$this->assertFalse( Paths::is_valid_dir_name( $name ) );
	}

	public function invalid_names() {
		return array(
			'empty'         => array( '' ),
			'dot'           => array( '.' ),
			'dot dot'       => array( '..' ),
			'hidden'        => array( '.git' ),
			'traversal'     => array( '../etc' ),
			'embedded dots' => array( 'a..b' ),
			'slash'         => array( 'a/b' ),
			'backslash'     => array( 'a\\b' ),
			'absolute'      => array( '/etc' ),
			'space'         => array( 'my backups' ),
			'nul'           => array( "ovb\0x" ),
			'too long'      => array( str_repeat( 'a', 101 ) ),
			'not a string'  => array( 42 ),
			'null'          => array( null ),
			'array'         => array( array( 'a' ) ),
		);
	}

	public function test_build_local_dir() {
		$this->assertSame( '/var/www/wp-content/ovb-abc', Paths::build_local_dir( '/var/www/wp-content', 'ovb-abc' ) );
		$this->assertSame( '/var/www/wp-content/ovb-abc', Paths::build_local_dir( '/var/www/wp-content/', 'ovb-abc' ) );
		$this->assertSame( 'C:/site/wp-content/ovb-abc', Paths::build_local_dir( 'C:\\site\\wp-content', 'ovb-abc' ) );
	}

	public function test_build_local_dir_rejects_bad_names() {
		$this->expectException( \InvalidArgumentException::class );
		Paths::build_local_dir( '/var/www/wp-content', '../../etc' );
	}

	public function test_build_tmp_and_work_dir() {
		$tmp = Paths::build_tmp_dir( '/var/www/wp-content/ovb-abc/' );
		$this->assertSame( '/var/www/wp-content/ovb-abc/tmp', $tmp );
		$this->assertSame( $tmp . '/' . self::UUID, Paths::build_work_dir( $tmp, self::UUID ) );
		$this->assertSame( $tmp . '/' . self::UUID, Paths::build_work_dir( $tmp . '/', strtoupper( self::UUID ) ), 'uuid is lower-cased' );
	}

	/**
	 * @dataProvider bad_uuids
	 */
	public function test_work_dir_rejects_anything_but_a_uuid( $uuid ) {
		$this->expectException( \InvalidArgumentException::class );
		Paths::build_work_dir( '/var/www/wp-content/ovb-abc/tmp', $uuid );
	}

	public function bad_uuids() {
		return array(
			'traversal'  => array( '../../../etc/passwd' ),
			'empty'      => array( '' ),
			'plain text' => array( 'backup-1' ),
			'v1 uuid'    => array( '3f2a9c1e-5b7d-1e8a-9c3b-1d2e3f4a5b6c' ),
			'suffix'     => array( self::UUID . '/..' ),
			'null'       => array( null ),
		);
	}

	public function test_guard_files_deny_web_access() {
		$files = Paths::guard_files();
		$this->assertSame( array( 'index.php', '.htaccess', 'web.config' ), array_keys( $files ) );
		$this->assertStringContainsString( 'Require all denied', $files['.htaccess'] );
		$this->assertStringContainsString( 'Deny from all', $files['.htaccess'] );
		$this->assertStringContainsString( 'deny users="*"', $files['web.config'] );
		$this->assertStringStartsWith( '<?php', $files['index.php'] );
	}
}
