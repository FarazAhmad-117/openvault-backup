<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged,Generic.CodeAnalysis.AssignmentInCondition -- These tests exercise real files and links in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Restore\UndoJournal;
use OpenVault\Backup\Restore\UndoStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FileFixtures.php';

/**
 * The undo folder: names, containment, guard files, journal append.
 *
 * @covers \OpenVault\Backup\Restore\UndoStore
 */
class UndoStoreTest extends TestCase {

	/**
	 * Temp folder.
	 *
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = FileFixtures::tmp();
	}

	protected function tearDown(): void {
		FileFixtures::rrmdir( $this->dir );
		parent::tearDown();
	}

	public function test_the_folder_of_a_restore_is_inside_the_local_folder() {
		$this->assertSame( '/var/www/wp-content/ovb-abc/undo/restore-7', UndoStore::build( '/var/www/wp-content/ovb-abc/', 7 ) );
		$store = new UndoStore( '/x/undo/restore-7/' );
		$this->assertSame( '/x/undo/restore-7/journal.log', $store->journal_path() );
		$this->assertSame( '/x/undo/restore-7/files', $store->files_dir() );
		$this->assertSame( '/x/undo/restore-7/files/content/uploads/a.jpg', $store->saved_path( 'content/uploads/a.jpg' ) );
	}

	public function test_an_invalid_restore_id_or_entry_name_is_refused() {
		foreach ( array( 0, -1, 'abc', '1; rm -rf' ) as $id ) {
			try {
				UndoStore::build( '/x', $id );
				$this->fail( 'accepted ' . $id );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Invalid restore id.', $e->getMessage() );
			}
		}
		$store = new UndoStore( '/x/undo/restore-7' );
		foreach ( array( '../x', '/abs', "a\0b", 'a//b', '' ) as $entry ) {
			try {
				$store->saved_path( $entry );
				$this->fail( 'accepted ' . bin2hex( $entry ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Unsafe name for the undo store.', $e->getMessage() );
			}
		}
	}

	public function test_is_safe_accepts_only_restore_folders_directly_inside_undo() {
		$ok = array( array( '/w/ovb-a/undo/restore-1', '/w/ovb-a/undo' ), array( '/w/undo/restore-123456', '/w/undo' ), array( 'C:/w/undo/restore-9', 'C:/w/undo' ) );
		foreach ( $ok as $pair ) {
			$this->assertTrue( UndoStore::is_safe( $pair[0], $pair[1] ), $pair[0] );
		}
		$bad = array(
			array( '/w/undo/restore-1/files', '/w/undo' ),
			array( '/w/undo/restore-1', '/w/tmp' ),
			array( '/w/undo/restore-0', '/w/undo' ),
			array( '/w/undo/restore-01', '/w/undo' ),
			array( '/w/undo/restore-x', '/w/undo' ),
			array( '/w/undo/restore-1x', '/w/undo' ),
			array( '/w/undo/../restore-1', '/w/undo' ),
			array( '/w/undo/./restore-1', '/w/undo' ),
			array( '/w/undo//restore-1', '/w/undo' ),
			array( '/w/undo', '/w/undo' ),
			array( '/w/undo/', '/w/undo' ),
			array( '/w/undo/restore-1', '/w/undo/restore-1' ),
			array( 'undo/restore-1', 'undo' ),
			array( "/w/undo/restore-1\0", '/w/undo' ),
			array( '/w/undo/RESTORE-1', '/w/undo' ),
			array( '/w/other/restore-1', '/w/undo' ),
			array( '/w/undo-x/restore-1', '/w/undo-x' ),
			array( null, '/w/undo' ),
			array( '/w/undo/restore-1', null ),
		);
		foreach ( $bad as $pair ) {
			$this->assertFalse( UndoStore::is_safe( $pair[0], $pair[1] ), (string) $pair[0] );
		}
	}

	public function test_ensure_creates_the_folders_with_guard_files() {
		$store = new UndoStore( $this->dir . '/local/undo/restore-5' );
		$this->assertFalse( $store->exists() );
		$this->assertTrue( $store->ensure() );
		foreach ( array( $this->dir . '/local/undo', $this->dir . '/local/undo/restore-5' ) as $folder ) {
			foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $guard ) {
				$this->assertFileExists( $folder . '/' . $guard );
			}
		}
		$this->assertTrue( $store->ensure(), 'idempotent' );
	}

	public function test_open_writes_the_header_once_and_lines_are_appended_complete() {
		$store = new UndoStore( $this->dir . '/local/undo/restore-5' );
		$this->assertTrue( $store->open( 5, array( 'uploads' => true ) ) );
		$this->assertTrue( $store->exists() );
		$this->assertFalse( $store->has_actions(), 'a header alone means nothing was changed' );

		$this->assertTrue( $store->append( UndoJournal::folder( 'content/uploads/x' ) ) );
		$this->assertTrue( $store->append( UndoJournal::created( 'content/uploads/x/a.txt' ) ) );
		$this->assertTrue( $store->has_actions() );

		$before = file_get_contents( $store->journal_path() );
		$this->assertTrue( $store->open( 5, array( 'plugins' => true ) ), 'opening again keeps the journal' );
		$this->assertSame( $before, file_get_contents( $store->journal_path() ) );
		$lines = explode( "\n", rtrim( $before, "\n" ) );
		$this->assertCount( 3, $lines );
		$this->assertSame( array( 'uploads' => true ), UndoJournal::header_contents( $lines[0] ) );
	}

	public function test_a_journal_with_only_a_fragment_is_started_again() {
		$store = new UndoStore( $this->dir . '/local/undo/restore-5' );
		$store->ensure();
		file_put_contents( $store->journal_path(), 'H' . "\t" . '1' );
		$this->assertTrue( $store->open( 5, array() ) );
		$this->assertNotNull( UndoJournal::header_contents( strtok( file_get_contents( $store->journal_path() ), "\n" ) ) );
	}

	public function test_a_line_with_a_newline_is_not_appended() {
		$store = new UndoStore( $this->dir . '/local/undo/restore-5' );
		$store->open( 5, array() );
		$size = filesize( $store->journal_path() );
		$this->assertFalse( $store->append( "C\tcontent/a\nC\tcontent/b" ) );
		$this->assertFalse( $store->append( "C\tcontent/a\r" ) );
		clearstatcache();
		$this->assertSame( $size, filesize( $store->journal_path() ) );
	}

	public function test_append_fails_when_the_journal_cannot_be_opened() {
		$store = new UndoStore( $this->dir . '/local/undo/restore-5' );
		$store->ensure();
		mkdir( $store->journal_path() );
		$this->assertFalse( $store->append( UndoJournal::created( 'content/a' ) ) );
		$this->assertFalse( $store->exists(), 'a folder is no journal' );
	}

	public function test_remove_deletes_the_folder_and_everything_in_it() {
		$store = new UndoStore( $this->dir . '/local/undo/restore-5' );
		$store->open( 5, array() );
		mkdir( $store->files_dir() . '/content/uploads', 0777, true );
		file_put_contents( $store->files_dir() . '/content/uploads/a.txt', 'saved' );
		$this->assertTrue( $store->remove() );
		$this->assertDirectoryDoesNotExist( $this->dir . '/local/undo/restore-5' );
		$this->assertDirectoryExists( $this->dir . '/local/undo', 'the undo folder and other restores stay' );
		$this->assertTrue( $store->remove(), 'removing again is fine' );
	}

	public function test_remove_refuses_a_folder_that_is_not_a_restore_folder() {
		mkdir( $this->dir . '/precious/undo', 0777, true );
		file_put_contents( $this->dir . '/precious/undo/keep.txt', 'keep' );
		$wrong = new UndoStore( $this->dir . '/precious' );
		$this->assertFalse( $wrong->remove(), 'not "restore-<digits>" inside "undo"' );
		$this->assertFileExists( $this->dir . '/precious/undo/keep.txt' );

		$wrong = new UndoStore( $this->dir . '/precious/undo' );
		$this->assertFalse( $wrong->remove() );
		$this->assertFileExists( $this->dir . '/precious/undo/keep.txt' );
	}

	public function test_remove_refuses_a_symlink_and_never_touches_its_target() {
		mkdir( $this->dir . '/victim', 0777, true );
		file_put_contents( $this->dir . '/victim/keep.txt', 'keep' );
		mkdir( $this->dir . '/local/undo', 0777, true );
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->dir . '/victim', $this->dir . '/local/undo/restore-5' ) ) {
			$this->markTestSkipped( 'Symlinks are not available.' );
		}
		$store = new UndoStore( $this->dir . '/local/undo/restore-5' );
		$this->assertFalse( $store->remove() );
		$this->assertFileExists( $this->dir . '/victim/keep.txt' );
	}
}
