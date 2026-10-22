<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged,Generic.CodeAnalysis.AssignmentInCondition -- These tests exercise real files and links in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Archive\EntryWalker;
use OpenVault\Backup\Archive\TarWriter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FileFixtures.php';

/**
 * Reading the entries of a part: names, types, sizes, bodies, resume by entry count, damage.
 *
 * @covers \OpenVault\Backup\Archive\EntryWalker
 */
class EntryWalkerTest extends TestCase {

	/**
	 * Temp folder.
	 *
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'gzopen' ) ) {
			$this->markTestSkipped( 'The zlib extension is not loaded.' );
		}
		$this->dir = FileFixtures::tmp();
	}

	protected function tearDown(): void {
		FileFixtures::rrmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * Entries of a typical archive.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function entries() {
		return array(
			array( 'content/uploads/a.txt', 'hello', 'file', '', 1700000001 ),
			array( 'content/uploads/empty.txt', '', 'file' ),
			array( 'content/uploads/sub/', '', 'dir' ),
			array( 'content/uploads/日本語 😀.txt', str_repeat( 'x', 700 ), 'file' ),
			array( 'content/uploads/link', '', 'symlink', '/etc/passwd' ),
			array( 'content/uploads/hard', '', 'hardlink', 'content/uploads/a.txt' ),
			array( 'content/uploads/pipe', '', 'fifo' ),
			array( 'content/uploads/' . str_repeat( 'long-name-', 14 ) . '.txt', 'long', 'file' ),
			array( 'abspath/index.php', '<?php // x', 'file' ),
		);
	}

	/**
	 * Read every entry (name, type, size, body of files).
	 *
	 * @param EntryWalker $walker Walker.
	 * @return array<int,array<string,mixed>>
	 */
	private function read_all( EntryWalker $walker ) {
		$out = array();
		while ( null !== ( $entry = $walker->next() ) ) {
			$body = '';
			while ( '' !== ( $chunk = $walker->read( 100 ) ) ) {
				$body .= $chunk;
			}
			$entry['body'] = $body;
			$out[]         = $entry;
		}
		return $out;
	}

	/**
	 * Both archive kinds.
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
	 * Make a part of a kind.
	 *
	 * @param string                      $kind    Kind.
	 * @param array<int,array<int,mixed>> $entries Entries.
	 * @return string Path.
	 */
	private function make( $kind, array $entries ) {
		if ( EntryWalker::ZIP === $kind && ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$path = $this->dir . '/part.' . ( EntryWalker::ZIP === $kind ? 'zip' : 'tar.gz' );
		if ( EntryWalker::ZIP === $kind ) {
			FileFixtures::zip( $path, $entries );
		} else {
			FileFixtures::tar_gz( $path, $entries );
		}
		return $path;
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_entries_come_back_in_order_with_their_types_and_bodies( $kind ) {
		$walker = EntryWalker::open( $kind, $this->make( $kind, $this->entries() ) );
		$all    = $this->read_all( $walker );
		$walker->close();

		$by_name = array();
		foreach ( $all as $entry ) {
			$by_name[ rtrim( $entry['name'], '/' ) ] = $entry;
		}
		$this->assertCount( count( $this->entries() ), $all );
		$this->assertSame( EntryWalker::TYPE_FILE, $by_name['content/uploads/a.txt']['type'] );
		$this->assertSame( 'hello', $by_name['content/uploads/a.txt']['body'] );
		$this->assertSame( 5, $by_name['content/uploads/a.txt']['size'] );
		if ( EntryWalker::TAR_GZ === $kind ) {
			$this->assertSame( 1700000001, $by_name['content/uploads/a.txt']['mtime'] );
		} else {
			$this->assertGreaterThan( 0, $by_name['content/uploads/a.txt']['mtime'] );
		}
		$this->assertSame( '', $by_name['content/uploads/empty.txt']['body'] );
		$this->assertSame( EntryWalker::TYPE_DIR, $by_name['content/uploads/sub']['type'] );
		$this->assertSame( str_repeat( 'x', 700 ), $by_name['content/uploads/日本語 😀.txt']['body'], 'a PAX name and a body of several reads' );
		$this->assertSame( EntryWalker::TYPE_SYMLINK, $by_name['content/uploads/link']['type'] );
		$this->assertSame( 0, $by_name['content/uploads/link']['size'] );
		$this->assertSame( 'long', $by_name[ 'content/uploads/' . str_repeat( 'long-name-', 14 ) . '.txt' ]['body'] );
		$this->assertSame( '<?php // x', $by_name['abspath/index.php']['body'] );
		if ( EntryWalker::TAR_GZ === $kind ) {
			$this->assertSame( EntryWalker::TYPE_HARDLINK, $by_name['content/uploads/hard']['type'] );
			$this->assertSame( EntryWalker::TYPE_SPECIAL, $by_name['content/uploads/pipe']['type'] );
		}
	}

	public function test_tar_names_with_pax_and_a_long_prefix_are_complete() {
		$name = 'content/' . str_repeat( 'd', 90 ) . '/' . str_repeat( 'e', 80 ) . '/file.txt';
		$path = $this->dir . '/p.tar.gz';
		FileFixtures::tar_gz( $path, array( array( $name, 'x' ), array( "content/uploads/\u{1F600}/\u{00FC}.txt", 'y' ) ) );
		$walker = EntryWalker::open( EntryWalker::TAR_GZ, $path );
		$this->assertSame( $name, $walker->next()['name'] );
		$this->assertSame( "content/uploads/\u{1F600}/\u{00FC}.txt", $walker->next()['name'] );
		$this->assertNull( $walker->next() );
		$walker->close();
	}

	public function test_a_tar_symlink_that_declares_a_body_does_not_swallow_the_next_entry() {
		$tar  = FileFixtures::tar_block( 'content/uploads/link', '2', 100, '/etc/passwd' );
		$tar .= FileFixtures::tar_bytes( array( array( 'content/uploads/after.txt', 'after' ) ) );
		$path = $this->dir . '/p.tar.gz';
		file_put_contents( $path, gzencode( $tar ) . gzencode( TarWriter::end_blocks() ) );
		$walker = EntryWalker::open( EntryWalker::TAR_GZ, $path );
		$link   = $walker->next();
		$this->assertSame( EntryWalker::TYPE_SYMLINK, $link['type'] );
		$this->assertSame( 0, $link['size'], 'the declared size of a link is ignored' );
		$after = $walker->next();
		$this->assertSame( 'content/uploads/after.txt', $after['name'] );
		$this->assertSame( 'after', $walker->read( 100 ) );
	}

	public function test_a_gnu_long_name_header_is_honoured() {
		$long = 'content/uploads/' . str_repeat( 'g', 150 ) . '.txt';
		$body = $long . "\0";
		$tar  = FileFixtures::tar_block( '././@LongLink', 'L', strlen( $body ) ) . $body . str_repeat( "\0", TarWriter::padding( strlen( $body ) ) );
		$tar .= FileFixtures::tar_block( substr( $long, 0, 100 ), '0', 3 ) . 'abc' . str_repeat( "\0", TarWriter::padding( 3 ) );
		$path = $this->dir . '/p.tar.gz';
		file_put_contents( $path, gzencode( $tar . TarWriter::end_blocks() ) );
		$walker = EntryWalker::open( EntryWalker::TAR_GZ, $path );
		$this->assertSame( $long, $walker->next()['name'] );
		$this->assertSame( 'abc', $walker->read( 10 ) );
	}

	public function test_a_pax_size_overrides_the_header_size() {
		$body = str_repeat( 'z', 1000 );
		// Built by hand: a PAX header with size=1000 followed by a ustar header whose size field says 0.
		$records = '13 size=1000' . "\n";
		$tar     = FileFixtures::tar_block( 'PaxHeaders.0/x', 'x', strlen( $records ) ) . $records . str_repeat( "\0", TarWriter::padding( strlen( $records ) ) );
		$tar    .= FileFixtures::tar_block( 'content/uploads/big.bin', '0', 0 ) . $body . str_repeat( "\0", TarWriter::padding( 1000 ) );
		$path    = $this->dir . '/p.tar.gz';
		file_put_contents( $path, gzencode( $tar . TarWriter::end_blocks() ) );
		$walker = EntryWalker::open( EntryWalker::TAR_GZ, $path );
		$entry  = $walker->next();
		$this->assertSame( 1000, $entry['size'] );
		$this->assertSame( $body, $walker->read( 5000 ) . $walker->read( 5000 ) );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_skip_entries_resumes_in_the_middle( $kind ) {
		$path = $this->make( $kind, $this->entries() );
		$all  = array();
		$w    = EntryWalker::open( $kind, $path );
		while ( null !== ( $e = $w->next() ) ) {
			$all[] = rtrim( $e['name'], '/' );
		}
		$w->close();

		foreach ( array( 0, 1, 4, count( $all ) - 1, count( $all ) ) as $skip ) {
			$w = EntryWalker::open( $kind, $path );
			$w->skip_entries( $skip );
			$next = $w->next();
			if ( count( $all ) === $skip ) {
				$this->assertNull( $next, 'after the last entry' );
			} else {
				$this->assertSame( $all[ $skip ], rtrim( $next['name'], '/' ), 'after skipping ' . $skip );
			}
			$w->close();
		}

		$w = EntryWalker::open( $kind, $path );
		$this->expectException( ArchiveException::class );
		$w->skip_entries( count( $all ) + 5 );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_an_unread_body_is_skipped_by_the_next_call( $kind ) {
		$path = $this->make( $kind, array( array( 'content/uploads/a.txt', str_repeat( 'a', 1500 ) ), array( 'content/uploads/b.txt', 'bbb' ) ) );
		$w    = EntryWalker::open( $kind, $path );
		$w->next();
		$this->assertSame( 'aaa', $w->read( 3 ) );
		$this->assertSame( 1497, $w->remaining() );
		$this->assertSame( 'content/uploads/b.txt', $w->next()['name'] );
		$this->assertSame( 'bbb', $w->read( 10 ) );
		$this->assertSame( '', $w->read( 10 ) );
		$w->skip_body();
		$this->assertNull( $w->next() );
	}

	public function test_a_zip_symlink_and_a_zip_folder_are_recognised() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$path = $this->make( EntryWalker::ZIP, array( array( 'content/uploads/l', '', 'symlink', '../../../etc/passwd' ), array( 'content/uploads/d', '', 'dir' ), array( 'content/uploads/f', 'x' ) ) );
		$w    = EntryWalker::open( EntryWalker::ZIP, $path );
		$this->assertSame( EntryWalker::TYPE_SYMLINK, $w->next()['type'] );
		$this->assertSame( EntryWalker::TYPE_DIR, $w->next()['type'] );
		$this->assertSame( EntryWalker::TYPE_FILE, $w->next()['type'] );
	}

	public function test_type_from_mode_and_flag() {
		$this->assertSame( EntryWalker::TYPE_SYMLINK, EntryWalker::type_from_mode( 0120777, false ) );
		$this->assertSame( EntryWalker::TYPE_DIR, EntryWalker::type_from_mode( 040755, false ) );
		$this->assertSame( EntryWalker::TYPE_FILE, EntryWalker::type_from_mode( 0100644, false ) );
		$this->assertSame( EntryWalker::TYPE_FILE, EntryWalker::type_from_mode( 0, false ), 'no Unix mode' );
		$this->assertSame( EntryWalker::TYPE_DIR, EntryWalker::type_from_mode( 0, true ) );
		$this->assertSame( EntryWalker::TYPE_SPECIAL, EntryWalker::type_from_mode( 0060000, false ), 'block device' );
		$this->assertSame( EntryWalker::TYPE_SPECIAL, EntryWalker::type_from_mode( 0020000, false ), 'character device' );
		$this->assertSame( EntryWalker::TYPE_SPECIAL, EntryWalker::type_from_mode( 0010000, false ), 'fifo' );
		$this->assertSame( EntryWalker::TYPE_SPECIAL, EntryWalker::type_from_mode( 0140000, false ), 'socket' );
		foreach ( array(
			'0'  => 'file',
			"\0" => 'file',
			'5'  => 'dir',
			'2'  => 'symlink',
			'1'  => 'hardlink',
			'3'  => 'special',
			'4'  => 'special',
			'6'  => 'special',
			'7'  => 'special',
			'S'  => 'special',
		) as $flag => $type ) {
			$this->assertSame( $type, EntryWalker::type_from_flag( (string) $flag ), 'flag ' . bin2hex( (string) $flag ) );
		}
	}

	public function test_a_damaged_tar_header_and_a_cut_off_body_are_refused() {
		$good = FileFixtures::tar_bytes( array( array( 'content/uploads/a.txt', str_repeat( 'a', 600 ) ) ) );

		$bad_header = 'X' . substr( $good, 1 );
		file_put_contents( $this->dir . '/bad.tar.gz', gzencode( $bad_header ) );
		$w = EntryWalker::open( EntryWalker::TAR_GZ, $this->dir . '/bad.tar.gz' );
		try {
			$w->next();
			$this->fail( 'a damaged header must throw' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'damaged', $e->getMessage() );
		}

		file_put_contents( $this->dir . '/cut.tar.gz', gzencode( substr( $good, 0, 700 ) ) );
		$w     = EntryWalker::open( EntryWalker::TAR_GZ, $this->dir . '/cut.tar.gz' );
		$entry = $w->next();
		$this->assertSame( 600, $entry['size'] );
		$this->expectException( ArchiveException::class );
		while ( '' !== $w->read( 100 ) ) {
			$this->assertTrue( true );
		}
	}

	public function test_a_huge_declared_size_with_a_short_body_ends_in_an_exception_not_in_memory_use() {
		// 8 GiB - 1 in the octal field (the writer's maximum); the body is not there.
		$tar = FileFixtures::tar_block( 'content/uploads/huge.bin', '0', TarWriter::MAX_OCTAL ) . str_repeat( 'a', 1000 );
		file_put_contents( $this->dir . '/huge.tar.gz', gzencode( $tar ) );
		$w     = EntryWalker::open( EntryWalker::TAR_GZ, $this->dir . '/huge.tar.gz' );
		$entry = $w->next();
		$this->assertSame( TarWriter::MAX_OCTAL, $entry['size'] );
		$read = 0;
		$this->expectException( ArchiveException::class );
		while ( $read < 100000 ) {
			$read += strlen( $w->read( 8192 ) );
		}
	}

	public function test_missing_or_linked_parts_and_unknown_kinds_are_refused() {
		$this->expectException( ArchiveException::class );
		EntryWalker::open( EntryWalker::TAR_GZ, $this->dir . '/missing.tar.gz' );
	}

	public function test_a_symlinked_part_is_refused() {
		$path = $this->dir . '/real.tar.gz';
		FileFixtures::tar_gz( $path, array( array( 'content/uploads/a', 'a' ) ) );
		if ( ! @symlink( $path, $this->dir . '/link.tar.gz' ) ) {
			$this->markTestSkipped( 'Symlinks are not available.' );
		}
		$this->expectException( ArchiveException::class );
		EntryWalker::open( EntryWalker::TAR_GZ, $this->dir . '/link.tar.gz' );
	}

	public function test_an_unknown_kind_is_refused() {
		file_put_contents( $this->dir . '/x.bin', 'x' );
		$this->expectException( ArchiveException::class );
		EntryWalker::open( 'rar', $this->dir . '/x.bin' );
	}
}
