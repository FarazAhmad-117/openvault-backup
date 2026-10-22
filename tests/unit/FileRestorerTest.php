<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged,Generic.CodeAnalysis.AssignmentInCondition -- These tests exercise real files and links in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\EntryWalker;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Restore\ExtractionPolicy;
use OpenVault\Backup\Restore\FileRestoreException;
use OpenVault\Backup\Restore\FileRestorer;
use OpenVault\Backup\Restore\FileUndoer;
use OpenVault\Backup\Restore\SafeWriter;
use OpenVault\Backup\Restore\UndoJournal;
use OpenVault\Backup\Restore\UndoStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FileFixtures.php';

/**
 * A files restore and its undo on real archives and a real temp site: overwrite and add, protected paths, many slices, crashes, hostile archives.
 *
 * @covers \OpenVault\Backup\Restore\FileRestorer
 * @covers \OpenVault\Backup\Restore\FileUndoer
 */
class FileRestorerTest extends TestCase {

	const TOKEN = 'a1b2c3d4';

	/**
	 * Temp folder holding the site, the outside world and the undo store.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * The WordPress folder of the fake site.
	 *
	 * @var string
	 */
	private $wp;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = FileFixtures::tmp();
		$this->build_site();
	}

	/**
	 * Create the fake site and the outside world below the temp folder.
	 *
	 * @return void
	 */
	private function build_site() {
		$this->wp = $this->dir . '/site/wp';
		foreach ( array( 'plugins/openvault-backup', 'plugins/foo', 'plugins/other', 'themes', 'uploads/2026/05', 'mu-plugins' ) as $folder ) {
			mkdir( $this->wp . '/wp-content/' . $folder, 0777, true );
		}
		mkdir( $this->dir . '/outside' );
		file_put_contents( $this->dir . '/outside/victim.txt', 'precious' );
		file_put_contents( $this->wp . '/wp-content/plugins/openvault-backup/canary.php', 'CANARY' );
		file_put_contents( $this->wp . '/wp-content/plugins/foo/foo.php', 'old foo' );
		file_put_contents( $this->wp . '/wp-content/plugins/other/x.php', 'other plugin' );
		file_put_contents( $this->wp . '/wp-content/uploads/2026/05/b.txt', 'old b' );
		file_put_contents( $this->wp . '/wp-content/uploads/keep.txt', 'untouched' );
		file_put_contents( $this->wp . '/index.php', 'old index' );
		file_put_contents( $this->wp . '/wp-config.php', 'config with secrets' );
		file_put_contents( $this->wp . '/.htaccess', 'old htaccess' );
		touch( $this->wp . '/wp-content/uploads/2026/05/b.txt', 1300000000 );
	}

	protected function tearDown(): void {
		FileFixtures::rrmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * Entries of the backup.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function backup_entries() {
		return array(
			array( 'content/uploads/2026/a.jpg', str_repeat( 'J', 600000 ), 'file', '', 1600000001 ),
			array( 'content/uploads/2026/05/b.txt', 'new b', 'file', '', 1600000002 ),
			array( 'content/uploads/empty.txt', '', 'file' ),
			array( 'content/uploads/日本語 😀 name.txt', 'unicode', 'file' ),
			array( 'content/uploads/dir/', '', 'dir' ),
			array( 'content/plugins/foo/foo.php', 'new foo', 'file' ),
			array( 'content/plugins/openvault-backup/canary.php', 'EVIL', 'file' ),
			array( 'content/plugins/openvault-backup/new.php', 'EVIL', 'file' ),
			array( 'content/themes/t/style.css', 'body{}', 'file' ),
			array( 'content/languages/de.mo', 'mo', 'file' ),
			array( 'abspath/index.php', 'new index', 'file' ),
			array( 'abspath/wp-config.php', 'EVIL CONFIG', 'file' ),
			array( 'abspath/.htaccess', 'EVIL HTACCESS', 'file' ),
			array( 'db/db.sql', 'SELECT 1', 'file' ),
		);
	}

	/**
	 * Both kinds of part.
	 *
	 * @return array<string,array{string}>
	 */
	public function kinds() {
		return array(
			'tar.gz' => array( EntryWalker::TAR_GZ ),
			'zip'    => array( EntryWalker::ZIP ),
		);
	}

	/**
	 * Make a part and return it in the shape the restorer takes.
	 *
	 * @param string                      $kind    Kind.
	 * @param array<int,array<int,mixed>> $entries Entries.
	 * @param string                      $name    File name stem.
	 * @return array{kind:string,path:string,name:string}
	 */
	private function part( $kind, array $entries, $name = 'files.part01' ) {
		if ( EntryWalker::ZIP === $kind && ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$file = $name . ( EntryWalker::ZIP === $kind ? '.zip' : '.tar.gz' );
		$path = $this->dir . '/' . $file;
		if ( EntryWalker::ZIP === $kind ) {
			FileFixtures::zip( $path, $entries );
		} else {
			FileFixtures::tar_gz( $path, $entries );
		}
		return array(
			'kind' => $kind,
			'path' => $path,
			'name' => $file,
		);
	}

	private function store() {
		$store = new UndoStore( $this->dir . '/store/undo/restore-1' );
		$this->assertTrue(
			$store->open(
				1,
				array(
					'plugins' => true,
					'uploads' => true,
				)
			)
		);
		return $store;
	}

	private function restorer( UndoStore $store, ?SafeWriter $writer = null, ?callable $check = null, $persist_every = 1, ?ExtractionPolicy $policy = null ) {
		return new FileRestorer( null === $policy ? FileFixtures::policy( $this->dir . '/site' ) : $policy, null === $writer ? new SafeWriter() : $writer, $store, self::TOKEN, $check, $persist_every );
	}

	private function undoer( UndoStore $store, ?ExtractionPolicy $policy = null ) {
		return new FileUndoer( null === $policy ? FileFixtures::policy( $this->dir . '/site' ) : $policy, new SafeWriter(), $store, 'd4c3b2a1', 1 );
	}

	/**
	 * Run slices until done. Returns the final cursor.
	 *
	 * @param FileRestorer $restorer Restorer.
	 * @param array<int,array<string,string>> $parts Parts.
	 * @param bool         $tiny     Use a tiny budget per slice.
	 * @param int          $slices   Number of slices (out).
	 * @param array<int,array<string,mixed>> $cursors Cursor after every slice (out).
	 * @return array<string,mixed>
	 */
	private function run_all( FileRestorer $restorer, array $parts, $tiny, &$slices = 0, &$cursors = array() ) {
		$cursor = array();
		$slices = 0;
		do {
			++$slices;
			$result    = $restorer->restore_slice( $parts, $cursor, $tiny ? FileFixtures::tiny_budget( 2 ) : FileFixtures::big_budget() );
			$cursor    = $result['cursor'];
			$cursors[] = $cursor;
			$this->assertLessThan( 400, $slices, 'the restore must finish' );
		} while ( ! $result['done'] );
		return $cursor;
	}

	/**
	 * The site as a map, without the storage folder.
	 *
	 * @return array<string,string>
	 */
	private function site_state() {
		return FileFixtures::snapshot( $this->dir . '/site' );
	}

	private function temp_files() {
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir . '/site', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( false !== strpos( $file->getFilename(), '.ovbtmp' ) ) {
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_a_restore_overwrites_adds_protects_and_can_be_undone( $kind ) {
		$part   = $this->part( $kind, $this->backup_entries() );
		$store  = $this->store();
		$before = $this->site_state();

		$cursor = $this->run_all( $this->restorer( $store ), array( $part ), false );

		$uploads = $this->wp . '/wp-content/uploads';
		$this->assertSame( str_repeat( 'J', 600000 ), file_get_contents( $uploads . '/2026/a.jpg' ) );
		$this->assertSame( 'new b', file_get_contents( $uploads . '/2026/05/b.txt' ), 'an existing file is overwritten' );
		$this->assertSame( '', file_get_contents( $uploads . '/empty.txt' ) );
		$this->assertSame( 'unicode', file_get_contents( $uploads . '/日本語 😀 name.txt' ) );
		$this->assertSame( 'new foo', file_get_contents( $this->wp . '/wp-content/plugins/foo/foo.php' ) );
		$this->assertSame( 'body{}', file_get_contents( $this->wp . '/wp-content/themes/t/style.css' ), 'a missing folder is created' );
		$this->assertSame( 'mo', file_get_contents( $this->wp . '/wp-content/languages/de.mo' ), 'other wp-content' );
		$this->assertSame( 'new index', file_get_contents( $this->wp . '/index.php' ) );
		$this->assertSame( 'untouched', file_get_contents( $uploads . '/keep.txt' ), 'files that are not in the backup are not deleted' );
		$this->assertSame( 'other plugin', file_get_contents( $this->wp . '/wp-content/plugins/other/x.php' ) );
		$this->assertDirectoryDoesNotExist( $uploads . '/dir', 'folder entries are not created on their own' );

		// Protected: untouched.
		$this->assertSame( 'CANARY', file_get_contents( $this->wp . '/wp-content/plugins/openvault-backup/canary.php' ) );
		$this->assertFileDoesNotExist( $this->wp . '/wp-content/plugins/openvault-backup/new.php' );
		$this->assertSame( 'config with secrets', file_get_contents( $this->wp . '/wp-config.php' ) );
		$this->assertSame( 'old htaccess', file_get_contents( $this->wp . '/.htaccess' ) );

		clearstatcache();
		if ( EntryWalker::TAR_GZ === $kind || method_exists( 'ZipArchive', 'setMtimeIndex' ) ) {
			// A zip stores two-second DOS times.
			$this->assertEqualsWithDelta( 1600000001, filemtime( $uploads . '/2026/a.jpg' ), 2, 'the modification time of the archive is kept' );
		}
		$this->assertSame( 0, fileperms( $uploads . '/2026/a.jpg' ) & 0111 );
		$this->assertSame( array(), $this->temp_files(), 'no temp file is left' );

		$this->assertSame( 8, $cursor['fl'], 'files written' );
		$this->assertSame( 3, $cursor['ow'], 'overwritten: b.txt, foo.php, index.php' );
		$this->assertSame( 5, $cursor['cr'], 'created: a.jpg, empty.txt, the unicode name, style.css, de.mo' );
		$this->assertSame( 600000 + 5 + 0 + 7 + 7 + 6 + 2 + 9, $cursor['by'] );
		$this->assertSame( 2, $cursor['sk']['protected_plugin'] );
		$this->assertSame( 1, $cursor['sk']['protected_config'] );
		$this->assertSame( 1, $cursor['sk']['protected_server'] );
		$this->assertSame( 1, $cursor['sk']['dir'] );
		$this->assertSame( 1, $cursor['sk']['db'] );

		// The journal and the saved originals.
		$lines = explode( "\n", rtrim( file_get_contents( $store->journal_path() ), "\n" ) );
		$ops   = array_count_values(
			array_map(
				static function ( $line ) {
					return substr( $line, 0, 1 );
				},
				$lines
			)
		);
		$this->assertSame( 1, $ops['H'] );
		$this->assertSame( $cursor['ow'], $ops['O'] );
		$this->assertSame( $cursor['cr'], $ops['C'] );
		$this->assertSame( 'old b', file_get_contents( $store->saved_path( 'content/uploads/2026/05/b.txt' ) ) );
		$this->assertSame( 'old foo', file_get_contents( $store->saved_path( 'content/plugins/foo/foo.php' ) ) );
		$this->assertContains( UndoJournal::folder( 'content/themes/t' ), $lines );
		$this->assertContains( UndoJournal::folder( 'content/languages' ), $lines );
		$this->assertContains( UndoJournal::created( 'content/themes/t/style.css' ), $lines );

		// Undo: the site is exactly what it was.
		$undo = $this->undoer( $store );
		$res  = $undo->undo_slice( array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertSame( $before, $this->site_state(), 'every overwritten file is back, every created file and folder is gone' );
		$this->assertSame( $cursor['ow'], $res['cursor']['rs'] );
		$this->assertSame( $cursor['cr'], $res['cursor']['rm'] );
		clearstatcache();
		$this->assertSame( 1300000000, filemtime( $uploads . '/2026/05/b.txt' ), 'the original modification time is back too' );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_many_small_slices_give_the_same_result_and_a_small_cursor( $kind ) {
		$part   = $this->part( $kind, $this->backup_entries() );
		$before = $this->site_state();

		$one_store = $this->store();
		$this->run_all( $this->restorer( $one_store ), array( $part ), false );
		$expected = $this->site_state();
		$journal  = file_get_contents( $one_store->journal_path() );

		// Same site again, restored in tiny slices.
		FileFixtures::rrmdir( $this->dir . '/site' );
		FileFixtures::rrmdir( $this->dir . '/store' );
		FileFixtures::rrmdir( $this->dir . '/outside' );
		$this->build_site();
		$this->assertSame( $before, $this->site_state() );

		$store   = $this->store();
		$slices  = 0;
		$cursors = array();
		$cursor  = $this->run_all( $this->restorer( $store ), array( $part ), true, $slices, $cursors );
		$this->assertGreaterThan( 8, $slices, 'the tiny budget forces many slices, one in the middle of the 600 KB file' );
		$this->assertSame( $expected, $this->site_state() );
		$this->assertSame( array(), $this->temp_files() );
		$this->assertSame( 8, $cursor['fl'] );

		$progress = array_map(
			static function ( $c ) {
				return $c['p'] * 1000000 + $c['e'] * 1000 + ( $c['o'] > 0 ? 1 : 0 );
			},
			$cursors
		);
		$sorted   = $progress;
		sort( $sorted );
		$this->assertSame( $sorted, $progress, 'the cursor never moves backwards' );
		$this->assertGreaterThan(
			0,
			count(
				array_filter(
					$cursors,
					static function ( $c ) {
						return $c['o'] > 0;
					}
				)
			),
			'a slice stopped in the middle of a file'
		);
		foreach ( $cursors as $c ) {
			$this->assertLessThan( 500, strlen( FileFixtures::json( $c ) ), 'the cursor stays small' );
		}

		$this->assertSame( $this->journal_ops( $journal ), $this->journal_ops( file_get_contents( $store->journal_path() ) ), 'same actions, whatever the slices' );

		$res = $this->undoer( $store )->undo_slice( array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertSame( $before, $this->site_state() );
	}

	/**
	 * The sorted lines of a journal without its header.
	 *
	 * @param string $journal Journal text.
	 * @return string[]
	 */
	private function journal_ops( $journal ) {
		$lines = array_values(
			array_filter(
				explode( "\n", $journal ),
				static function ( $l ) {
					return '' !== $l && 'H' !== $l[0];
				}
			)
		);
		sort( $lines );
		return $lines;
	}

	public function test_the_undo_works_in_tiny_slices_too() {
		$part   = $this->part( EntryWalker::TAR_GZ, $this->backup_entries() );
		$store  = $this->store();
		$before = $this->site_state();
		$this->run_all( $this->restorer( $store ), array( $part ), false );

		$undo   = $this->undoer( $store );
		$cursor = array();
		$slices = 0;
		do {
			++$slices;
			$res    = $undo->undo_slice( $cursor, FileFixtures::tiny_budget( 2 ) );
			$cursor = $res['cursor'];
			$this->assertLessThan( 200, $slices );
		} while ( ! $res['done'] );
		$this->assertGreaterThan( 3, $slices );
		$this->assertSame( $before, $this->site_state() );
		$this->assertSame( 0, $cursor['er'] );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_killed_slices_leave_no_corruption_and_the_undo_still_restores_the_originals( $kind ) {
		$part   = $this->part( $kind, $this->backup_entries() );
		$store  = $this->store();
		$before = $this->site_state();

		$restorer = $this->restorer( $store, null, null, 2 );
		$saved    = array();
		$slices   = 0;
		$kills    = 0;
		mt_srand( 20260101 );
		do {
			++$slices;
			$persisted = $saved;
			$result    = $restorer->restore_slice(
				array( $part ),
				$saved,
				FileFixtures::tiny_budget( 2 ),
				static function ( array $cursor ) use ( &$persisted ) {
					$persisted = $cursor;
				}
			);
			if ( ! $result['done'] && 0 === mt_rand( 0, 2 ) ) {
				// The process died after the slice's work but before its result was saved: only what persist() stored survives, and a temp
				// file with garbage is left behind.
				++$kills;
				$saved = $persisted;
				foreach ( (array) glob( $this->wp . '/wp-content/uploads/2026/*.ovbtmp*' ) as $temp ) {
					file_put_contents( $temp, 'GARBAGE', FILE_APPEND );
				}
				file_put_contents( $this->wp . '/wp-content/uploads/2026/zzz.txt.ovbtmp' . self::TOKEN, 'stale garbage from a dead run' );
				continue;
			}
			$saved = $result['cursor'];
			$this->assertLessThan( 400, $slices );
		} while ( ! $result['done'] );
		$this->assertGreaterThan( 0, $kills );

		// A stale temp file of a dead run for a file that is never restored stays (it is not ours to guess), but real files are right.
		@unlink( $this->wp . '/wp-content/uploads/2026/zzz.txt.ovbtmp' . self::TOKEN );
		$this->assertSame( str_repeat( 'J', 600000 ), file_get_contents( $this->wp . '/wp-content/uploads/2026/a.jpg' ) );
		$this->assertSame( 'new b', file_get_contents( $this->wp . '/wp-content/uploads/2026/05/b.txt' ) );
		$this->assertSame( array(), $this->temp_files() );

		$res = $this->undoer( $store )->undo_slice( array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertSame( $before, $this->site_state(), 'the originals survived the dead runs: the first saved copy is kept' );
		$this->assertSame( 0, $res['cursor']['er'] );
	}

	/**
	 * Hostile entries for a tar.gz or a zip.
	 *
	 * @param string $kind Kind.
	 * @return array<int,array<int,mixed>>
	 */
	private function hostile_entries( $kind ) {
		$entries = array(
			array( 'content/uploads/../../outside.txt', 'ZIPSLIP', 'file' ),
			array( 'content/uploads/a/../../../../escape.txt', 'ZIPSLIP', 'file' ),
			array( '/etc/ovb-abs.txt', 'ABSOLUTE', 'file' ),
			array( 'C:\\ovb-drive.txt', 'DRIVE', 'file' ),
			array( 'content/uploads/..\\back.txt', 'BACKSLASH', 'file' ),
			array( 'content/uploads/link-entry', '', 'symlink', $this->dir . '/outside' ),
			array( 'content/uploads/linkdir/pwn.txt', 'THROUGH A LINK', 'file' ),
			array( 'content/uploads/linkfile.txt', 'OVERWRITE THROUGH A LINK', 'file' ),
			array( 'content/uploads/ok.txt', 'fine', 'file' ),
			array( 'content/plugins/openvault-backup/canary.php', 'EVIL', 'file' ),
			array( 'content/plugins/OpenVault-Backup/Case.php', 'EVIL', 'file' ),
			array( 'content/ovb-test/backups/x.zip', 'EVIL', 'file' ),
			array( 'content/ovb-other/backups/y.zip', 'EVIL', 'file' ),
			array( 'abspath/wp-config.php', 'EVIL CONFIG', 'file' ),
			array( 'abspath/.htaccess', 'EVIL', 'file' ),
			array( 'abspath/.maintenance', 'EVIL', 'file' ),
			array( "content/uploads/\u{202E}gpj.php", 'RTL', 'file' ),
			array( 'content/uploads/trailing-dot.', 'DOT', 'file' ),
			array( 'content/uploads/' . str_repeat( 'long', 400 ), 'LONG', 'file' ),
			array( 'content/uploads/file-in-the-way/x.txt', 'X', 'file' ),
			array( 'content/uploads/dir-in-the-way', 'X', 'file' ),
			array( 'elsewhere/x.txt', 'UNOWNED', 'file' ),
		);
		if ( EntryWalker::TAR_GZ === $kind ) {
			$entries[] = array( "content/uploads/nul\0byte.php", 'NUL', 'file' );
			$entries[] = array( 'content/uploads/pipe', '', 'fifo' );
			$entries[] = array( 'content/uploads/hard-entry', '', 'hardlink', 'content/uploads/keep.txt' );
		}
		return $entries;
	}

	/**
	 * Plant the destination traps: a symlinked folder and file, a file where a folder is needed, a folder where a file is needed.
	 *
	 * @return bool False when symlinks are not available.
	 */
	private function plant_traps() {
		$uploads = $this->wp . '/wp-content/uploads';
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->dir . '/outside', $uploads . '/linkdir' ) ) {
			return false;
		}
		symlink( $this->dir . '/outside/victim.txt', $uploads . '/linkfile.txt' );
		file_put_contents( $uploads . '/file-in-the-way', 'a file' );
		mkdir( $uploads . '/dir-in-the-way' );
		return true;
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_a_hostile_archive_writes_nothing_outside_the_roots_and_never_through_a_link( $kind ) {
		if ( ! $this->plant_traps() ) {
			$this->markTestSkipped( 'Symlinks are not available.' );
		}
		$entries = $this->hostile_entries( $kind );
		if ( EntryWalker::ZIP === $kind ) {
			// libzip refuses a few of the names a tar can carry; keep the ones it accepts.
			$entries = array_values(
				array_filter(
					$entries,
					static function ( $e ) {
						return false === strpos( $e[0], "\0" );
					}
				)
			);
		}
		$part   = $this->part( $kind, $entries );
		$store  = $this->store();
		$before = $this->site_state();
		$parent = FileFixtures::snapshot( $this->dir );

		$restorer = $this->restorer( $store );
		$cursor   = $this->run_all( $restorer, array( $part ), false );

		$after = $this->site_state();
		$added = array_diff_key( $after, $before );
		$this->assertSame( array( 'wp/wp-content/uploads/ok.txt' ), array_keys( $added ), 'the only new file in the whole site is the harmless one' );
		$this->assertSame( 'fine', file_get_contents( $this->wp . '/wp-content/uploads/ok.txt' ) );
		$changed = array_keys(
			array_filter(
				$before,
				function ( $hash, $path ) use ( $after ) {
					return isset( $after[ $path ] ) && $after[ $path ] !== $hash;
				},
				ARRAY_FILTER_USE_BOTH
			)
		);
		$this->assertSame( array(), $changed, 'nothing that existed was changed (b.txt is not in this archive)' );

		// The world outside the roots is untouched: the whole temp folder, the victim behind the links, the parents of the roots.
		$this->assertSame( 'precious', file_get_contents( $this->dir . '/outside/victim.txt' ) );
		$this->assertSame( array( 'victim.txt' ), array_keys( FileFixtures::snapshot( $this->dir . '/outside' ) ) );
		$parent_after = FileFixtures::snapshot( $this->dir );
		$new_anywhere = array_keys( array_diff_key( $parent_after, $parent ) );
		$this->assertSame( array( 'site/wp/wp-content/uploads/ok.txt' ), $new_anywhere, 'no new entry anywhere in the temp folder, outside the site or inside it, except the harmless file' );
		foreach ( array( '/etc/ovb-abs.txt', '/ovb-drive.txt' ) as $outside ) {
			$this->assertFileDoesNotExist( $outside );
		}
		$this->assertFileDoesNotExist( $this->wp . '/outside.txt' );
		$this->assertFileDoesNotExist( $this->wp . '/../escape.txt' );
		$this->assertFileDoesNotExist( $this->wp . '/wp-content/escape.txt' );
		$this->assertSame( 'CANARY', file_get_contents( $this->wp . '/wp-content/plugins/openvault-backup/canary.php' ) );
		$this->assertFileDoesNotExist( $this->wp . '/wp-content/plugins/OpenVault-Backup/Case.php' );
		$this->assertDirectoryDoesNotExist( $this->wp . '/wp-content/ovb-test' );
		$this->assertDirectoryDoesNotExist( $this->wp . '/wp-content/ovb-other' );
		$this->assertSame( 'config with secrets', file_get_contents( $this->wp . '/wp-config.php' ) );
		$this->assertFileDoesNotExist( $this->wp . '/.maintenance' );
		$this->assertSame( array(), $this->temp_files() );

		// The counters tell what happened.
		$sk = $cursor['sk'];
		$this->assertSame( 1, $cursor['fl'] );
		$this->assertSame( EntryWalker::TAR_GZ === $kind ? 8 : 7, $sk['name_unsafe'], 'dot dot x2, absolute, drive, backslash, right-to-left, trailing dot (and NUL in a tar)' );
		$this->assertSame( 1, $sk['name_long'] );
		$this->assertSame( EntryWalker::TAR_GZ === $kind ? 2 : 1, $sk['link'], 'symlink (and hardlink in a tar)' );
		$this->assertSame( 2, $sk['protected_plugin'] );
		$this->assertSame( 2, $sk['protected_storage'] );
		$this->assertSame( 1, $sk['protected_config'] );
		$this->assertSame( 1, $sk['protected_server'] );
		$this->assertSame( 1, $sk['protected_maintenance'] );
		$this->assertSame( 2, $sk['dest_symlink'], 'the symlinked folder and the symlinked file' );
		$this->assertSame( 1, $sk['dest_not_dir'] );
		$this->assertSame( 1, $sk['dest_is_dir'] );
		$this->assertSame( 1, $sk['unowned'] );
	}

	public function test_hostile_names_are_logged_cut_and_cleaned_and_the_journal_stays_well_formed() {
		$entries = array(
			array( "content/uploads/evil\n" . 'O' . "\t" . 'abspath/wp-config.php' . "\t1\t", 'x', 'file' ),
			array( "content/uploads/\u{202E}gpj.php", 'x', 'file' ),
			array( 'content/uploads/../../x', 'x', 'file' ),
		);
		$part    = $this->part( EntryWalker::TAR_GZ, $entries );
		$store   = $this->store();
		$res     = $this->restorer( $store )->restore_slice( array( $part ), array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertCount( 3, $res['log'] );
		foreach ( $res['log'] as $line ) {
			$this->assertSame( 'warning', $line['level'] );
			$this->assertSame( 'name_unsafe', $line['context']['reason'] );
			$this->assertDoesNotMatchRegularExpression( '/[\x00-\x1f\x{202E}]/u', $line['context']['name'] );
			$this->assertLessThanOrEqual( 80, strlen( $line['context']['name'] ) );
			$this->assertGreaterThan( 0, $line['context']['entry_index'] );
		}
		foreach ( explode( "\n", rtrim( file_get_contents( $store->journal_path() ), "\n" ) ) as $line ) {
			$this->assertNotNull( UndoJournal::parse_line( $line ), 'every journal line parses: ' . substr( $line, 0, 60 ) );
		}
		$this->assertFalse( $store->has_actions(), 'skips are informational, nothing was changed' );
	}

	public function test_a_full_disk_stops_the_restore_cleanly_and_the_undo_still_works() {
		$part   = $this->part( EntryWalker::TAR_GZ, $this->backup_entries() );
		$store  = $this->store();
		$before = $this->site_state();

		$writer = new class() extends SafeWriter {
			/**
			 * Calls so far.
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * The disk fills up after a few chunks.
			 *
			 * @param resource $handle Handle.
			 * @param string   $data   Data.
			 * @return bool
			 */
			public function write_chunk( $handle, $data ) {
				return ++$this->calls > 6 ? false : parent::write_chunk( $handle, $data );
			}
		};
		try {
			$this->restorer( $store, $writer )->restore_slice( array( $part ), array(), FileFixtures::big_budget() );
			$this->fail( 'the restore must stop' );
		} catch ( FileRestoreException $e ) {
			$this->assertSame( 'disk_full', $e->reason() );
			$this->assertStringContainsString( 'disk may be full', $e->getMessage() );
		}
		$this->assertSame( array(), $this->temp_files(), 'the half written file is removed' );
		$this->assertNotSame( $before, $this->site_state(), 'some files were already replaced' );

		$res = $this->undoer( $store )->undo_slice( array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertSame( $before, $this->site_state(), 'undo puts the partly restored site back exactly' );
	}

	public function test_a_journal_that_cannot_be_written_stops_before_the_destination_is_touched() {
		$part  = $this->part( EntryWalker::TAR_GZ, array( array( 'content/uploads/2026/05/b.txt', 'new b' ), array( 'content/uploads/new.txt', 'n' ) ) );
		$store = $this->store();
		// The journal can no longer be opened for appending.
		unlink( $store->journal_path() );
		mkdir( $store->journal_path() );
		$before = $this->site_state();
		try {
			$this->restorer( $store )->restore_slice( array( $part ), array(), FileFixtures::big_budget() );
			$this->fail( 'must stop' );
		} catch ( FileRestoreException $e ) {
			$this->assertSame( 'journal', $e->reason() );
			$this->assertStringContainsString( 'journal could not be written', $e->getMessage() );
		}
		$this->assertSame( $before, $this->site_state() );
	}

	public function test_a_part_that_changed_after_verification_stops_the_restore_before_its_first_write() {
		$part   = $this->part( EntryWalker::TAR_GZ, $this->backup_entries() );
		$store  = $this->store();
		$before = $this->site_state();
		$check  = static function () {
			return 'The backup file x changed after it was verified: the checksum does not match.';
		};
		try {
			$this->restorer( $store, null, $check )->restore_slice( array( $part ), array(), FileFixtures::big_budget() );
			$this->fail( 'must stop' );
		} catch ( FileRestoreException $e ) {
			$this->assertSame( 'part', $e->reason() );
			$this->assertStringContainsString( 'changed after it was verified', $e->getMessage() );
		}
		$this->assertSame( $before, $this->site_state() );
	}

	public function test_the_part_check_runs_once_per_part_and_not_on_a_resume() {
		$one   = $this->part( EntryWalker::TAR_GZ, array( array( 'content/uploads/a.txt', 'a' ), array( 'content/uploads/b.txt', 'b' ) ), 'files.part01' );
		$two   = $this->part( EntryWalker::TAR_GZ, array( array( 'content/uploads/c.txt', 'c' ) ), 'files.part02' );
		$store = $this->store();
		$seen  = array();
		$check = static function ( array $part, $index ) use ( &$seen ) {
			$seen[] = $index;
			return '';
		};
		$this->run_all( $this->restorer( $store, null, $check ), array( $one, $two ), true );
		$this->assertSame( array( 0, 1 ), $seen );
		$this->assertSame( 'c', file_get_contents( $this->wp . '/wp-content/uploads/c.txt' ) );
	}

	public function test_a_truncated_part_is_a_systemic_error_and_leaves_no_temp_file() {
		$part = $this->part( EntryWalker::TAR_GZ, array( array( 'content/uploads/a.txt', str_repeat( 'a', 5000 ) ) ) );
		$tar  = FileFixtures::tar_bytes( array( array( 'content/uploads/a.txt', str_repeat( 'a', 5000 ) ) ) );
		file_put_contents( $part['path'], gzencode( substr( $tar, 0, 2000 ) ) );
		$store = $this->store();
		try {
			$this->restorer( $store )->restore_slice( array( $part ), array(), FileFixtures::big_budget() );
			$this->fail( 'must stop' );
		} catch ( FileRestoreException $e ) {
			$this->assertSame( 'archive', $e->reason() );
		}
		$this->assertSame( array(), $this->temp_files() );
		$this->assertFileDoesNotExist( $this->wp . '/wp-content/uploads/a.txt' );
	}

	public function test_a_huge_declared_size_is_refused_before_the_disk_is_asked_for_it() {
		$tar  = FileFixtures::tar_block( 'content/uploads/huge.bin', '0', 8589934591 ) . str_repeat( 'a', 600 );
		$path = $this->dir . '/huge.tar.gz';
		file_put_contents( $path, gzencode( $tar ) );
		$free = function_exists( 'disk_free_space' ) ? disk_free_space( $this->dir ) : false;
		if ( false === $free || $free > 8589934591 ) {
			// Plenty of disk: the cut-off body is what stops it.
			$expected = 'archive';
		} else {
			$expected = 'disk_full';
		}
		try {
			$this->restorer( $this->store() )->restore_slice(
				array(
					array(
						'kind' => 'tar.gz',
						'path' => $path,
						'name' => 'huge.tar.gz',
					),
				),
				array(),
				FileFixtures::big_budget()
			);
			$this->fail( 'must stop' );
		} catch ( FileRestoreException $e ) {
			$this->assertSame( $expected, $e->reason() );
		}
		$this->assertSame( array(), $this->temp_files() );
	}

	public function test_many_write_failures_in_a_row_mean_the_destination_is_not_writable() {
		$entries = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$entries[] = array( 'content/uploads/f' . $i . '.txt', 'x', 'file' );
		}
		$part   = $this->part( EntryWalker::TAR_GZ, $entries );
		$writer = new class() extends SafeWriter {
			/**
			 * Nothing can be created.
			 *
			 * @param string $temp   Temp path.
			 * @param int    $offset Offset.
			 * @return null
			 */
			public function open_temp( $temp, $offset ) {
				unset( $temp, $offset );
				return null;
			}
		};
		try {
			$this->restorer( $this->store(), $writer )->restore_slice( array( $part ), array(), FileFixtures::big_budget() );
			$this->fail( 'must stop' );
		} catch ( FileRestoreException $e ) {
			$this->assertSame( 'unwritable', $e->reason() );
		}
	}

	public function test_a_few_write_failures_are_counted_and_the_restore_goes_on() {
		$part   = $this->part( EntryWalker::TAR_GZ, array( array( 'content/uploads/bad.txt', 'x' ), array( 'content/uploads/good.txt', 'y' ) ) );
		$writer = new class() extends SafeWriter {
			/**
			 * The first temp file cannot be created.
			 *
			 * @param string $temp   Temp path.
			 * @param int    $offset Offset.
			 * @return array|null
			 */
			public function open_temp( $temp, $offset ) {
				return false !== strpos( $temp, 'bad.txt' ) ? null : parent::open_temp( $temp, $offset );
			}
		};
		$res    = $this->restorer( $this->store(), $writer )->restore_slice( array( $part ), array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertSame( 1, $res['cursor']['sk']['write_failed'] );
		$this->assertSame( 1, $res['cursor']['fl'] );
		$this->assertSame( 'y', file_get_contents( $this->wp . '/wp-content/uploads/good.txt' ) );
		$this->assertSame( 'write_failed', $res['log'][0]['context']['reason'] );
	}

	public function test_only_the_roots_the_backup_contains_are_touched() {
		$part   = $this->part( EntryWalker::TAR_GZ, $this->backup_entries() );
		$policy = FileFixtures::policy( $this->dir . '/site', array( 'uploads' => true ) );
		$before = $this->site_state();
		$cursor = $this->run_all( $this->restorer( $this->store(), null, null, 1, $policy ), array( $part ), false );
		$this->assertSame( 'old foo', file_get_contents( $this->wp . '/wp-content/plugins/foo/foo.php' ) );
		$this->assertSame( 'old index', file_get_contents( $this->wp . '/index.php' ) );
		$this->assertFileDoesNotExist( $this->wp . '/wp-content/themes/t/style.css' );
		$this->assertSame( 'new b', file_get_contents( $this->wp . '/wp-content/uploads/2026/05/b.txt' ) );
		$this->assertGreaterThan( 5, $cursor['sk']['unowned'] );
		$this->assertNotSame( $before, $this->site_state() );
	}

	public function test_a_second_part_follows_the_first() {
		$one = $this->part( EntryWalker::TAR_GZ, array( array( 'content/uploads/a.txt', 'a' ) ), 'files.part01' );
		$two = $this->part( class_exists( '\ZipArchive' ) ? EntryWalker::ZIP : EntryWalker::TAR_GZ, array( array( 'content/uploads/b.txt', 'b' ) ), 'files.part02' );
		$res = $this->run_all( $this->restorer( $this->store() ), array( $one, $two ), true );
		$this->assertSame( 2, $res['fl'] );
		$this->assertSame( 2, $res['p'] );
		$this->assertSame( 'b', file_get_contents( $this->wp . '/wp-content/uploads/b.txt' ) );
	}

	public function test_a_restore_with_no_parts_is_done_at_once() {
		$res = $this->restorer( $this->store() )->restore_slice( array(), array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertSame( 0, $res['cursor']['fl'] );
	}

	public function test_a_garbled_stored_cursor_is_sanitised() {
		$cursor = FileRestorer::normalise_cursor(
			array(
				'p'   => -4,
				'e'   => 'x',
				'o'   => '12',
				'fl'  => 3.9,
				'sk'  => array(
					'link'     => 2,
					'Bad Key!' => 5,
					'dir'      => 'x',
					7          => 1,
				),
				'zzz' => 1,
			)
		);
		$this->assertSame( 0, $cursor['p'] );
		$this->assertSame( 0, $cursor['e'] );
		$this->assertSame( 12, $cursor['o'] );
		$this->assertSame( 3, $cursor['fl'] );
		$this->assertSame( array( 'link' => 2 ), $cursor['sk'] );
		$this->assertArrayNotHasKey( 'zzz', $cursor );
	}

	public function test_the_cursor_with_every_skip_reason_stays_small() {
		$cursor = FileRestorer::new_cursor();
		$class  = new \ReflectionClass( ExtractionPolicy::class );
		foreach ( $class->getConstants() as $name => $value ) {
			if ( 0 === strpos( $name, 'R_' ) ) {
				$cursor['sk'][ $value ] = 123456789;
			}
		}
		$cursor = array_merge(
			$cursor,
			array(
				'p'  => 99,
				'e'  => 99999999,
				'o'  => 4000000000,
				'fl' => 99999999,
				'by' => 99999999999,
				'cr' => 99999999,
				'ow' => 99999999,
			)
		);
		$this->assertLessThan( 700, strlen( FileFixtures::json( $cursor ) ) );
	}

	// ---- the undo of a tampered journal ----

	public function test_the_undo_ignores_a_fragment_and_refuses_what_a_tampered_journal_points_at() {
		$store = $this->store();
		$store->append( UndoJournal::created( 'content/uploads/created.txt' ) );
		file_put_contents( $this->wp . '/wp-content/uploads/created.txt', 'mine' );
		// A tampered journal: protected paths, a path through a link, an unowned name, a created folder that is a link.
		$store->append( UndoJournal::created( 'abspath/wp-config.php' ) );
		$store->append( UndoJournal::created( 'content/plugins/openvault-backup/canary.php' ) );
		$store->append( UndoJournal::overwritten( 'abspath/wp-config.php', 5, '' ) );
		$store->append( UndoJournal::created( 'nowhere/x.txt' ) );
		$store->append( UndoJournal::folder( 'content/plugins/openvault-backup' ) );
		if ( $this->plant_traps() ) {
			$store->append( UndoJournal::created( 'content/uploads/linkdir/victim.txt' ) );
			$store->append( UndoJournal::folder( 'content/uploads/linkdir' ) );
			file_put_contents( $this->dir . '/outside/victim.txt', 'precious' );
		}
		// A crash while writing the last line.
		file_put_contents( $store->journal_path(), "C\tcontent/uploads/keep.txt", FILE_APPEND );

		$undo = $this->undoer( $store );
		$res  = $undo->undo_slice( array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertFileDoesNotExist( $this->wp . '/wp-content/uploads/created.txt', 'the genuine line is replayed' );
		$this->assertSame( 'untouched', file_get_contents( $this->wp . '/wp-content/uploads/keep.txt' ), 'the fragment is not replayed' );
		$this->assertSame( 'config with secrets', file_get_contents( $this->wp . '/wp-config.php' ) );
		$this->assertSame( 'CANARY', file_get_contents( $this->wp . '/wp-content/plugins/openvault-backup/canary.php' ) );
		$this->assertSame( 'precious', file_get_contents( $this->dir . '/outside/victim.txt' ) );
		$this->assertDirectoryExists( $this->wp . '/wp-content/plugins/openvault-backup' );
		$this->assertGreaterThanOrEqual( 5, array_sum( $res['cursor']['sk'] ) );
		$this->assertArrayHasKey( 'protected_config', $res['cursor']['sk'] );
	}

	public function test_a_damaged_saved_original_is_counted_and_the_file_is_left_as_it_is() {
		$part  = $this->part( EntryWalker::TAR_GZ, array( array( 'content/plugins/foo/foo.php', 'new foo' ), array( 'content/uploads/2026/05/b.txt', 'new b' ) ) );
		$store = $this->store();
		$this->run_all( $this->restorer( $store ), array( $part ), false );

		file_put_contents( $store->saved_path( 'content/plugins/foo/foo.php' ), 'old fxx' ); // The same size is not enough: the checksum catches it.
		$res = $this->undoer( $store )->undo_slice( array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertSame( 'new foo', file_get_contents( $this->wp . '/wp-content/plugins/foo/foo.php' ), 'not replaced by a damaged copy' );
		$this->assertSame( 'old b', file_get_contents( $this->wp . '/wp-content/uploads/2026/05/b.txt' ), 'the other file is put back' );
		$this->assertSame( 1, $res['cursor']['er'] );
		$this->assertSame( 1, $res['cursor']['sk']['corrupt_saved'] );

		// A missing saved original is the same.
		$store2 = new UndoStore( $this->dir . '/store/undo/restore-2' );
		$store2->open( 2, array( 'uploads' => true ) );
		$store2->append( UndoJournal::overwritten( 'content/uploads/keep.txt', 9, '' ) );
		$res2 = $this->undoer( $store2 )->undo_slice( array(), FileFixtures::big_budget() );
		$this->assertSame( 1, $res2['cursor']['er'] );
		$this->assertSame( 'untouched', file_get_contents( $this->wp . '/wp-content/uploads/keep.txt' ) );
	}

	public function test_created_folders_with_other_files_in_them_are_kept_by_the_undo() {
		$part  = $this->part( EntryWalker::TAR_GZ, array( array( 'content/themes/t/style.css', 'body{}' ), array( 'content/themes/t/sub/a.css', 'a' ) ) );
		$store = $this->store();
		$this->run_all( $this->restorer( $store ), array( $part ), false );
		file_put_contents( $this->wp . '/wp-content/themes/t/user-added.txt', 'the user added this later' );

		$res = $this->undoer( $store )->undo_slice( array(), FileFixtures::big_budget() );
		$this->assertTrue( $res['done'] );
		$this->assertFileDoesNotExist( $this->wp . '/wp-content/themes/t/style.css' );
		$this->assertFileDoesNotExist( $this->wp . '/wp-content/themes/t/sub' );
		$this->assertSame( 'the user added this later', file_get_contents( $this->wp . '/wp-content/themes/t/user-added.txt' ) );
		$this->assertSame( 1, $res['cursor']['kd'], 'the folder with a foreign file stays' );
		$this->assertSame( 1, $res['cursor']['rd'], 'the empty folder goes' );
	}

	public function test_a_file_the_user_changed_after_the_restore_is_put_back_to_the_original_and_a_replaced_created_file_is_removed() {
		$part  = $this->part( EntryWalker::TAR_GZ, array( array( 'content/uploads/2026/05/b.txt', 'new b' ), array( 'content/uploads/new.txt', 'n' ) ) );
		$store = $this->store();
		$this->run_all( $this->restorer( $store ), array( $part ), false );
		file_put_contents( $this->wp . '/wp-content/uploads/2026/05/b.txt', 'edited after the restore' );
		file_put_contents( $this->wp . '/wp-content/uploads/new.txt', 'edited too' );
		$this->undoer( $store )->undo_slice( array(), FileFixtures::big_budget() );
		$this->assertSame( 'old b', file_get_contents( $this->wp . '/wp-content/uploads/2026/05/b.txt' ) );
		$this->assertFileDoesNotExist( $this->wp . '/wp-content/uploads/new.txt' );
	}
}
