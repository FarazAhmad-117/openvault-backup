<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real folders in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Restore\RestoreWorkDir;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Restore\RestoreWorkDir
 */
class RestoreWorkDirTest extends TestCase {

	const BASE = '/var/www/wp-content/ovb-abc/tmp';

	/**
	 * @var string[]
	 */
	private $dirs = array();

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			CleanupStep::remove_tree( $dir );
		}
		parent::tearDown();
	}

	public function test_folder_names() {
		$this->assertSame( 'restore-7', RestoreWorkDir::folder_name( 7 ) );
		$this->assertSame( 'restore-12', RestoreWorkDir::folder_name( '12' ) );
		$this->assertSame( self::BASE . '/restore-7', RestoreWorkDir::build( self::BASE . '/', 7 ) );
	}

	/**
	 * @dataProvider bad_ids
	 */
	public function test_bad_ids_are_rejected( $id ) {
		$this->expectException( \InvalidArgumentException::class );
		RestoreWorkDir::folder_name( $id );
	}

	public function bad_ids() {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
			'float'    => array( 1.5 ),
			'dots'     => array( '../1' ),
			'text'     => array( 'abc' ),
			'empty'    => array( '' ),
			'null'     => array( null ),
			'trailing' => array( '1/..' ),
		);
	}

	/**
	 * @dataProvider safe_paths
	 */
	public function test_safe_paths( $path ) {
		$this->assertTrue( RestoreWorkDir::is_safe( $path, self::BASE ), (string) $path );
	}

	public function safe_paths() {
		return array(
			'plain'          => array( self::BASE . '/restore-1' ),
			'big id'         => array( self::BASE . '/restore-123456' ),
			'trailing slash' => array( self::BASE . '/restore-5/' ),
			'backslashes'    => array( str_replace( '/', '\\', self::BASE . '/restore-5' ) ),
		);
	}

	/**
	 * @dataProvider unsafe_paths
	 */
	public function test_unsafe_paths_are_refused( $path ) {
		$this->assertFalse( RestoreWorkDir::is_safe( $path, self::BASE ), (string) $path );
	}

	public function unsafe_paths() {
		return array(
			'the tmp folder itself' => array( self::BASE ),
			'a uuid work folder'    => array( self::BASE . '/3f2a9c1e-5b7d-4e8a-9c3b-1d2e3f4a5b6c' ),
			'zero id'               => array( self::BASE . '/restore-0' ),
			'leading zero'          => array( self::BASE . '/restore-01' ),
			'no number'             => array( self::BASE . '/restore-' ),
			'text'                  => array( self::BASE . '/restore-x' ),
			'nested'                => array( self::BASE . '/restore-1/sub' ),
			'sibling of tmp'        => array( '/var/www/wp-content/ovb-abc/restore-1' ),
			'other prefix'          => array( '/var/www/wp-content/restore-1' ),
			'traversal'             => array( self::BASE . '/restore-1/../restore-2' ),
			'traversal out'         => array( self::BASE . '/../restore-1' ),
			'dot segment'           => array( self::BASE . '/./restore-1' ),
			'relative'              => array( 'tmp/restore-1' ),
			'empty'                 => array( '' ),
			'nul byte'              => array( self::BASE . "/restore-1\0" ),
			'not a string'          => array( null ),
			'prefix trick'          => array( self::BASE . '-evil/restore-1' ),
		);
	}

	public function test_the_base_must_be_named_tmp() {
		$this->assertFalse( RestoreWorkDir::is_safe( '/var/www/cache/restore-1', '/var/www/cache' ) );
		$this->assertFalse( RestoreWorkDir::is_safe( '/var/www/tmp/restore-1', '/var/www/tmp/../tmp' ) );
	}

	public function test_join_accepts_plain_names_only() {
		$this->assertSame( '/a/b/db.part01.zip', RestoreWorkDir::join( '/a/b/', 'db.part01.zip' ) );

		foreach ( array( '../x', 'a/b', 'a\\b', '', "a\0b", '..', "a\nb" ) as $name ) {
			try {
				RestoreWorkDir::join( '/a/b', $name );
				$this->fail( 'Expected a refusal for ' . json_encode( $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Unsafe file name.', $e->getMessage() );
			}
		}
	}

	public function test_a_real_symlink_named_like_a_restore_folder_is_not_a_safe_folder() {
		$root = sys_get_temp_dir() . '/ovb-rwd-' . bin2hex( random_bytes( 4 ) );
		$tmp  = $root . '/tmp';
		mkdir( $tmp, 0777, true );
		mkdir( $root . '/victim' );
		file_put_contents( $root . '/victim/keep.txt', 'keep' );
		$this->dirs[] = $root;

		if ( ! @symlink( $root . '/victim', $tmp . '/restore-3' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Symlinks may be unavailable.
			$this->markTestSkipped( 'Symlinks are not available here.' );
		}

		// The runtime check resolves the link first: its real path is not a restore folder inside tmp.
		$this->assertTrue( is_link( $tmp . '/restore-3' ) );
		$this->assertFalse( RestoreWorkDir::is_safe( realpath( $tmp . '/restore-3' ), realpath( $tmp ) ) );

		// Removing the folder entry removes the link, never the target.
		CleanupStep::remove_tree( $tmp . '/restore-3' );
		$this->assertFalse( is_link( $tmp . '/restore-3' ) );
		$this->assertFileExists( $root . '/victim/keep.txt' );
	}
}
