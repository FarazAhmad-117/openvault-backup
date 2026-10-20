<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real folders in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Steps\CleanupStep
 */
class CleanupStepTest extends TestCase {

	const BASE = '/var/www/wp-content/ovb-abc/tmp';
	const UUID = '3f2a9c1e-5b7d-4e8a-9c3b-1d2e3f4a5b6c';

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

	/**
	 * @dataProvider safe_paths
	 */
	public function test_safe_work_dirs( $path ) {
		$this->assertTrue( CleanupStep::is_safe_work_dir( $path, self::BASE ), $path );
	}

	public function safe_paths() {
		return array(
			'plain'          => array( self::BASE . '/' . self::UUID ),
			'trailing slash' => array( self::BASE . '/' . self::UUID . '/' ),
			'backslashes'    => array( str_replace( '/', '\\', self::BASE . '/' . self::UUID ) ),
		);
	}

	/**
	 * @dataProvider unsafe_paths
	 */
	public function test_unsafe_work_dirs_are_refused( $path ) {
		$this->assertFalse( CleanupStep::is_safe_work_dir( $path, self::BASE ), (string) $path );
	}

	public function unsafe_paths() {
		$ok = self::BASE . '/' . self::UUID;
		return array(
			'empty'                 => array( '' ),
			'not a string'          => array( null ),
			'the tmp folder itself' => array( self::BASE ),
			'parent of tmp'         => array( '/var/www/wp-content/ovb-abc' ),
			'root'                  => array( '/' ),
			'wp-content'            => array( '/var/www/wp-content' ),
			'traversal out'         => array( self::BASE . '/../' . self::UUID ),
			'traversal inside'      => array( self::BASE . '/x/../' . self::UUID ),
			'traversal to etc'      => array( self::BASE . '/../../../../etc' ),
			'dot segment'           => array( self::BASE . '/./' . self::UUID ),
			'double slash'          => array( self::BASE . '//' . self::UUID ),
			'nested below the uuid' => array( $ok . '/sub' ),
			'sibling prefix trick'  => array( self::BASE . '-evil/' . self::UUID ),
			'other folder in tmp'   => array( self::BASE . '/not-a-uuid' ),
			'archive file name'     => array( self::BASE . '/site-backup.zip' ),
			'relative'              => array( 'tmp/' . self::UUID ),
			'relative traversal'    => array( '../tmp/' . self::UUID ),
			'nul byte'              => array( $ok . "\0/../../etc" ),
			'uuid with suffix'      => array( $ok . '.bak' ),
			'outside entirely'      => array( '/etc/' . self::UUID ),
			'backslash traversal'   => array( self::BASE . '\\..\\..\\' . self::UUID ),
		);
	}

	public function test_base_must_be_a_tmp_folder() {
		$this->assertFalse( CleanupStep::is_safe_work_dir( '/var/www/wp-content/ovb-abc/' . self::UUID, '/var/www/wp-content/ovb-abc' ) );
		$this->assertFalse( CleanupStep::is_safe_work_dir( '/data/' . self::UUID, '/data' ) );
		$this->assertFalse( CleanupStep::is_safe_work_dir( self::BASE . '/' . self::UUID, '' ) );
		$this->assertFalse( CleanupStep::is_safe_work_dir( self::BASE . '/' . self::UUID, '/var/www/../www/tmp' ) );
		$this->assertFalse( CleanupStep::is_safe_work_dir( self::BASE . '/' . self::UUID, 'relative/tmp' ) );
	}

	public function test_windows_style_paths() {
		$this->assertTrue( CleanupStep::is_safe_work_dir( 'C:\\site\\wp-content\\ovb-abc\\tmp\\' . self::UUID, 'C:\\site\\wp-content\\ovb-abc\\tmp' ) );
		$this->assertFalse( CleanupStep::is_safe_work_dir( 'C:\\site\\wp-content\\ovb-abc\\tmp\\..\\' . self::UUID, 'C:\\site\\wp-content\\ovb-abc\\tmp' ) );
	}

	private function make_tree() {
		$root = sys_get_temp_dir() . '/ovb-cleanup-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/a/b', 0777, true );
		file_put_contents( $root . '/one.txt', '1' );
		file_put_contents( $root . '/a/two.txt', '2' );
		file_put_contents( $root . '/a/b/three.txt', '3' );
		$this->dirs[] = $root;
		return $root;
	}

	public function test_remove_tree_deletes_everything() {
		$root = $this->make_tree();
		$this->assertTrue( CleanupStep::remove_tree( $root ) );
		$this->assertFalse( file_exists( $root ) );
	}

	public function test_remove_tree_of_a_missing_folder_is_fine() {
		$this->assertTrue( CleanupStep::remove_tree( sys_get_temp_dir() . '/ovb-does-not-exist-' . bin2hex( random_bytes( 4 ) ) ) );
	}

	public function test_remove_tree_does_not_follow_symlinks() {
		if ( ! function_exists( 'symlink' ) || '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Symlinks are not reliable here.' );
		}

		$outside = sys_get_temp_dir() . '/ovb-cleanup-outside-' . bin2hex( random_bytes( 4 ) );
		mkdir( $outside );
		file_put_contents( $outside . '/precious.txt', 'keep me' );
		$this->dirs[] = $outside;

		$root = $this->make_tree();
		symlink( $outside, $root . '/link-to-dir' );
		symlink( $outside . '/precious.txt', $root . '/link-to-file' );

		$this->assertTrue( CleanupStep::remove_tree( $root ) );
		$this->assertFalse( file_exists( $root ) );
		$this->assertSame( 'keep me', file_get_contents( $outside . '/precious.txt' ), 'the link targets are untouched' );
	}

	public function test_remove_tree_stops_when_the_budget_is_exhausted_and_can_resume() {
		$root = sys_get_temp_dir() . '/ovb-cleanup-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root );
		$this->dirs[] = $root;
		$files        = CleanupStep::BATCH * 3;
		for ( $i = 0; $i < $files; $i++ ) {
			file_put_contents( $root . '/f' . $i, 'x' );
		}

		$spent = new Budget( 0, PHP_INT_MAX );
		$this->assertFalse( CleanupStep::remove_tree( $root, $spent ), 'out of budget: more work remains' );
		$this->assertTrue( is_dir( $root ) );
		$this->assertLessThan( $files, count( scandir( $root ) ) - 2, 'some entries were deleted' );

		$this->assertTrue( CleanupStep::remove_tree( $root, new Budget( 1000, PHP_INT_MAX ) ), 'a later run finishes the job' );
		$this->assertFalse( file_exists( $root ) );
	}

	public function test_step_id() {
		$this->assertSame( 'cleanup', ( new CleanupStep() )->id() );
	}
}
