<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\RemotePath;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Destinations\RemotePath
 */
class RemotePathTest extends TestCase {

	const FOLDER = '2026-01-02_030405_3f2a9c1e';

	/**
	 * @dataProvider hosts
	 */
	public function test_site_host( $input, $expected ) {
		$this->assertSame( $expected, RemotePath::site_host( $input ) );
	}

	public function hosts() {
		return array(
			'plain url'            => array( 'https://Example.COM', 'example.com' ),
			'url with path'        => array( 'https://example.com/blog/', 'example.com' ),
			'url with port'        => array( 'http://localhost:8888', 'localhost-8888' ),
			'bare host'            => array( 'My-Site.example.org', 'my-site.example.org' ),
			'bare host with port'  => array( 'example.com:8080', 'example.com-8080' ),
			'user info dropped'    => array( 'https://user:pass@example.com/', 'example.com' ),
			'unicode'              => array( 'https://bücher.example', 'b-cher.example' ),
			'traversal in host'    => array( 'https://../../etc', 'site' ),
			'slashes and dots'     => array( '/../..//', 'site' ),
			'empty'                => array( '', 'site' ),
			'only symbols'         => array( '!!!', 'site' ),
			'double dots'          => array( 'a..b', 'a.b' ),
			'leading dot and dash' => array( '.-example.com-.', 'example.com' ),
			'space and quote'      => array( "exa mple'.com", 'exa-mple-.com' ),
		);
	}

	public function test_site_host_is_cut_to_100_characters() {
		$this->assertSame( 100, strlen( RemotePath::site_host( str_repeat( 'a', 300 ) . '.com' ) ) );
	}

	public function test_site_host_is_always_a_safe_segment() {
		foreach ( array( 'https://a/../b', "x\0y", '..', 'C:\\Windows', 'a/b', '%2e%2e' ) as $input ) {
			$host = RemotePath::site_host( $input );
			$this->assertTrue( RemotePath::is_segment( $host ), $input . ' -> ' . $host );
		}
	}

	public function test_file_builds_the_documented_layout() {
		$this->assertSame(
			'OpenVault-Backups/example.com/' . self::FOLDER . '/db.part01.zip',
			RemotePath::file( 'https://example.com', self::FOLDER, 'db.part01.zip' )
		);
		$this->assertSame(
			'OpenVault-Backups/example.com/' . self::FOLDER,
			RemotePath::folder( 'example.com', self::FOLDER )
		);
		$this->assertSame( 'OpenVault-Backups', RemotePath::ROOT );
	}

	/**
	 * @dataProvider bad_folders
	 */
	public function test_bad_folder_names_are_refused( $folder ) {
		$this->expectException( \InvalidArgumentException::class );
		RemotePath::folder( 'example.com', $folder );
	}

	public function bad_folders() {
		return array(
			'traversal'   => array( '../..' ),
			'empty'       => array( '' ),
			'wrong shape' => array( 'backup' ),
			'uppercase'   => array( '2026-01-02_030405_3F2A9C1E' ),
			'suffix'      => array( self::FOLDER . '/x' ),
			'newline'     => array( self::FOLDER . "\n" ),
			'not string'  => array( null ),
		);
	}

	/**
	 * @dataProvider bad_files
	 */
	public function test_bad_file_names_are_refused( $name ) {
		$this->expectException( \InvalidArgumentException::class );
		RemotePath::file( 'example.com', self::FOLDER, $name );
	}

	public function bad_files() {
		return array(
			'empty'        => array( '' ),
			'slash'        => array( 'a/b.zip' ),
			'backslash'    => array( 'a\\b.zip' ),
			'dotdot'       => array( '..' ),
			'embedded'     => array( 'a..b.zip' ),
			'hidden'       => array( '.htaccess' ),
			'nul'          => array( "a\0.zip" ),
			'space'        => array( 'my file.zip' ),
			'too long'     => array( str_repeat( 'a', 300 ) ),
			'not a string' => array( 5 ),
		);
	}

	public function test_manifest_and_part_names_are_valid_segments() {
		foreach ( array( 'manifest.json', 'db.part01.zip', 'files.part12.tar.gz', 'files.part03.zip' ) as $name ) {
			$this->assertTrue( RemotePath::is_segment( $name ), $name );
		}
	}

	/**
	 * @dataProvider safe_paths
	 */
	public function test_safe_paths( $path ) {
		$this->assertTrue( RemotePath::is_safe( $path ), (string) $path );
	}

	public function safe_paths() {
		return array(
			'file'   => array( 'manifest.json' ),
			'nested' => array( 'OpenVault-Backups/example.com/' . self::FOLDER . '/db.part01.zip' ),
		);
	}

	/**
	 * @dataProvider unsafe_paths
	 */
	public function test_unsafe_paths( $path ) {
		$this->assertFalse( RemotePath::is_safe( $path ), var_export( $path, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test message only.
	}

	public function unsafe_paths() {
		return array(
			'empty'           => array( '' ),
			'null'            => array( null ),
			'absolute'        => array( '/etc/passwd' ),
			'drive'           => array( 'C:/Windows' ),
			'traversal'       => array( '../x' ),
			'inner traversal' => array( 'a/../b' ),
			'end traversal'   => array( 'a/..' ),
			'dot segment'     => array( 'a/./b' ),
			'double slash'    => array( 'a//b' ),
			'trailing slash'  => array( 'a/b/' ),
			'backslash'       => array( 'a\\b' ),
			'nul'             => array( "a\0b" ),
			'newline'         => array( "a\nb" ),
			'too long'        => array( str_repeat( 'a/', 600 ) ),
		);
	}
}
