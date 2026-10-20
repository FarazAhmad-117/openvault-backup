<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DevelopmentFunctions.error_log_var_export -- These tests exercise real folders in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Backups\BackupFiles;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Backups\BackupFiles
 */
class BackupFilesTest extends TestCase {

	const UUID   = '3f2a9c1e-5b7d-4e8a-9c3b-1d2e3f4a5b6c';
	const TIME   = '2026-01-02 03:04:05';
	const FOLDER = '2026-01-02_030405_3f2a9c1e';

	/**
	 * @var string
	 */
	private $root;

	/**
	 * @var string
	 */
	private $backups;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/ovb-bf-' . uniqid( '', true );
		mkdir( $this->root . '/backups', 0777, true );
		$this->backups = str_replace( '\\', '/', realpath( $this->root . '/backups' ) );
	}

	protected function tearDown(): void {
		CleanupStep::remove_tree( $this->root );
		parent::tearDown();
	}

	private function make_backup() {
		$dir = $this->backups . '/' . self::FOLDER;
		mkdir( $dir );
		file_put_contents( $dir . '/files.part01.zip', 'zipdata' );
		file_put_contents( $dir . '/manifest.json', '{}' );
		return $dir;
	}

	public function test_folder_path_comes_from_the_row() {
		$this->assertSame( '/b/' . self::FOLDER, BackupFiles::folder_path( '/b/', self::TIME, self::UUID ) );
		$this->assertNull( BackupFiles::folder_path( '/b', '../../x', self::UUID ) );
		$this->assertNull( BackupFiles::folder_path( '/b', self::TIME, '../../etc' ) );
	}

	public function test_resolves_a_real_file() {
		$dir = $this->make_backup();
		$this->assertSame( $dir . '/files.part01.zip', BackupFiles::resolve_file( $this->backups, self::TIME, self::UUID, 'files.part01.zip' ) );
		$this->assertSame( $dir . '/manifest.json', BackupFiles::resolve_file( $this->backups, self::TIME, self::UUID, 'manifest.json' ) );
	}

	/**
	 * @dataProvider hostile_names
	 */
	public function test_refuses_hostile_file_names( $name ) {
		$this->make_backup();
		file_put_contents( $this->root . '/secret.txt', 'secret' );
		file_put_contents( $this->backups . '/outside.zip', 'x' );
		$this->assertNull( BackupFiles::resolve_file( $this->backups, self::TIME, self::UUID, $name ), var_export( $name, true ) );
	}

	public function hostile_names() {
		return array(
			'parent'       => array( '../outside.zip' ),
			'deep parent'  => array( '../../secret.txt' ),
			'absolute'     => array( '/etc/passwd' ),
			'backslash'    => array( '..\\outside.zip' ),
			'subfolder'    => array( 'sub/a.zip' ),
			'nul'          => array( "files.part01.zip\0.png" ),
			'newline'      => array( "files.part01.zip\n" ),
			'empty'        => array( '' ),
			'dot'          => array( '.' ),
			'dotdot'       => array( '..' ),
			'missing'      => array( 'nope.zip' ),
			'not a string' => array( array( 'a' ) ),
		);
	}

	public function test_refuses_a_directory_and_a_symlink() {
		$dir = $this->make_backup();
		mkdir( $dir . '/sub' );
		$this->assertNull( BackupFiles::resolve_file( $this->backups, self::TIME, self::UUID, 'sub' ) );

		file_put_contents( $this->root . '/secret.txt', 'secret' );
		if ( ! @symlink( $this->root . '/secret.txt', $dir . '/link.zip' ) ) {
			$this->markTestSkipped( 'Symlinks cannot be created here.' );
		}
		$this->assertNull( BackupFiles::resolve_file( $this->backups, self::TIME, self::UUID, 'link.zip' ) );
	}

	public function test_refuses_when_the_backup_folder_is_a_symlink_out() {
		mkdir( $this->root . '/elsewhere' );
		file_put_contents( $this->root . '/elsewhere/files.part01.zip', 'x' );
		if ( ! @symlink( $this->root . '/elsewhere', $this->backups . '/' . self::FOLDER ) ) {
			$this->markTestSkipped( 'Symlinks cannot be created here.' );
		}
		$this->assertNull( BackupFiles::resolve_file( $this->backups, self::TIME, self::UUID, 'files.part01.zip' ) );
		$this->assertFalse( BackupFiles::delete_folder( $this->backups, self::TIME, self::UUID ) );
		$this->assertFileExists( $this->root . '/elsewhere/files.part01.zip', 'nothing behind the link is touched' );
	}

	public function test_delete_folder_removes_only_that_folder() {
		$dir = $this->make_backup();
		mkdir( $this->backups . '/2026-01-02_030405_aaaaaaaa' );
		file_put_contents( $this->backups . '/2026-01-02_030405_aaaaaaaa/keep.zip', 'x' );

		$this->assertTrue( BackupFiles::delete_folder( $this->backups, self::TIME, self::UUID ) );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertFileExists( $this->backups . '/2026-01-02_030405_aaaaaaaa/keep.zip' );
		$this->assertDirectoryExists( $this->backups );
	}

	public function test_delete_of_a_missing_folder_counts_as_done() {
		$this->assertTrue( BackupFiles::delete_folder( $this->backups, self::TIME, self::UUID ) );
	}

	public function test_delete_refuses_bad_row_data() {
		$this->make_backup();
		$this->assertFalse( BackupFiles::delete_folder( $this->backups, 'bad time', self::UUID ) );
		$this->assertFalse( BackupFiles::delete_folder( $this->backups, self::TIME, '../..' ) );
		$this->assertFileExists( $this->backups . '/' . self::FOLDER . '/manifest.json' );
	}

	public function test_a_missing_backups_folder_resolves_to_nothing() {
		$this->assertNull( BackupFiles::resolve_folder( $this->root . '/nope', self::TIME, self::UUID ) );
		$this->assertNull( BackupFiles::resolve_file( $this->root . '/nope', self::TIME, self::UUID, 'a.zip' ) );
	}
}
