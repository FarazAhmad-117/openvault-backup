<?php
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- symlink() warns when the platform does not allow links; the result is checked.
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real folders in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\Local\LocalDestination;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Destinations\Local\LocalDestination
 */
class LocalDestinationTest extends TestCase {

	const REMOTE = 'OpenVault-Backups/example.com/2026-01-02_030405_3f2a9c1e/files.part01.zip';

	/**
	 * Scratch root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Folders that were "protected" by the fake guard writer.
	 *
	 * @var string[]
	 */
	private $protected = array();

	protected function setUp(): void {
		parent::setUp();
		$this->root = rtrim( str_replace( '\\', '/', realpath( sys_get_temp_dir() ) ), '/' ) . '/ovb-local-' . bin2hex( random_bytes( 4 ) );
		foreach ( array( 'site/wp-admin', 'site/wp-includes', 'site/wp-content/ovb-x/backups', 'site/wp-content/ovb-x/tmp', 'outside', 'source' ) as $dir ) {
			mkdir( $this->root . '/' . $dir, 0777, true );
		}
		$this->protected = array();
	}

	protected function tearDown(): void {
		CleanupStep::remove_tree( $this->root );
		parent::tearDown();
	}

	private function env() {
		$root = $this->root;
		return array(
			'default_base' => static function () use ( $root ) {
				return $root . '/site/wp-content/ovb-x/backups';
			},
			'abspath'      => $root . '/site/',
			'wp_admin'     => $root . '/site/wp-admin',
			'wp_includes'  => $root . '/site/wp-includes',
			'tmp_dir'      => $root . '/site/wp-content/ovb-x/tmp',
			'protect'      => function ( $dir ) {
				$this->protected[] = $dir;
				file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
			},
		);
	}

	private function custom( $path = null ) {
		return new LocalDestination( array( 'path' => null === $path ? $this->root . '/outside/dest' : $path ), array(), $this->env() );
	}

	private function default_destination() {
		return new LocalDestination( array(), array(), $this->env() );
	}

	private function source( $bytes, $name = 'files.part01.zip' ) {
		$path = $this->root . '/source/' . $name;
		$h    = fopen( $path, 'wb' );
		for ( $left = $bytes; $left > 0; $left -= 65536 ) {
			fwrite( $h, random_bytes( min( 65536, $left ) ) );
		}
		fclose( $h );
		return $path;
	}

	/**
	 * A budget that allows about $calls checks before it is exhausted (the fake clock advances one second per read).
	 */
	private function budget( $calls ) {
		$time = 0.0;
		return new Budget(
			$calls,
			PHP_INT_MAX,
			static function () use ( &$time ) {
				$time += 1.0;
				return $time;
			}
		);
	}

	private function big_budget() {
		return new Budget(
			600,
			PHP_INT_MAX,
			static function () {
				return 0.0;
			}
		);
	}

	private function message_of( callable $call ) {
		try {
			$call();
		} catch ( DestinationException $e ) {
			return $e;
		}
		$this->fail( 'No DestinationException was thrown.' );
	}

	// Path rules.

	/**
	 * @dataProvider acceptable_paths
	 */
	public function test_normalise_accepts_clean_absolute_paths( $input, $expected ) {
		$this->assertSame( $expected, LocalDestination::normalise_path( $input ) );
	}

	public function acceptable_paths() {
		return array(
			'unix'               => array( '/srv/backups', '/srv/backups' ),
			'trailing slash'     => array( '/srv/backups/', '/srv/backups' ),
			'windows'            => array( 'D:\\Backups\\site', 'D:/Backups/site' ),
			'windows forward'    => array( 'd:/backups', 'd:/backups' ),
			'surrounding spaces' => array( '  /srv/b  ', '/srv/b' ),
		);
	}

	/**
	 * @dataProvider rejected_paths
	 */
	public function test_normalise_rejects_everything_else( $input ) {
		$this->assertNull( LocalDestination::normalise_path( $input ) );
	}

	public function rejected_paths() {
		return array(
			'relative'        => array( 'backups/site' ),
			'dot relative'    => array( './backups' ),
			'parent'          => array( '/srv/../etc' ),
			'trailing parent' => array( '/srv/backups/..' ),
			'dot segment'     => array( '/srv/./b' ),
			'double slash'    => array( '/srv//b' ),
			'root'            => array( '/' ),
			'drive root'      => array( 'C:/' ),
			'nul'             => array( "/srv/b\0/x" ),
			'newline'         => array( "/srv/b\n" ),
			'empty'           => array( '' ),
			'spaces'          => array( '   ' ),
			'not a string'    => array( array( '/srv' ) ),
			'null'            => array( null ),
			'too long'        => array( '/' . str_repeat( 'a', 600 ) ),
			'windows parent'  => array( 'C:\\a\\..\\b' ),
			'url'             => array( 'https://example.com/x' ),
		);
	}

	public function test_is_inside() {
		$this->assertTrue( LocalDestination::is_inside( '/a/b', '/a/b' ) );
		$this->assertTrue( LocalDestination::is_inside( '/a/b/c/d', '/a/b' ) );
		$this->assertTrue( LocalDestination::is_inside( '/a/b/', '/a/b/' ) );
		$this->assertFalse( LocalDestination::is_inside( '/a/bc', '/a/b' ), 'sibling with the same prefix' );
		$this->assertFalse( LocalDestination::is_inside( '/a', '/a/b' ) );
		$this->assertFalse( LocalDestination::is_inside( '/a/b', '' ) );
		$this->assertTrue( LocalDestination::is_inside( 'C:\\Site\\WP-Admin\\x', 'c:/site/wp-admin' ), 'drive paths ignore case' );
		$this->assertFalse( LocalDestination::is_inside( '/A/b', '/a/b' ), 'unix paths are case sensitive' );
	}

	public function test_is_forbidden() {
		$protected = array( '/var/www/wp-admin', '/var/www/wp-includes', '/var/www/wp-content/ovb-x/tmp' );
		$this->assertTrue( LocalDestination::is_forbidden( '/var/www', '/var/www/', $protected ), 'the WordPress root' );
		$this->assertTrue( LocalDestination::is_forbidden( '/var/www/wp-admin', '/var/www', $protected ) );
		$this->assertTrue( LocalDestination::is_forbidden( '/var/www/wp-includes/js', '/var/www', $protected ) );
		$this->assertTrue( LocalDestination::is_forbidden( '/var/www/wp-content/ovb-x/tmp/abc', '/var/www', $protected ) );
		$this->assertFalse( LocalDestination::is_forbidden( '/var/www/wp-content/my-backups', '/var/www', $protected ) );
		$this->assertFalse( LocalDestination::is_forbidden( '/var/www-backups', '/var/www', $protected ), 'sibling prefix' );
		$this->assertFalse( LocalDestination::is_forbidden( '/srv/backups', '/var/www', $protected ) );
	}

	public function test_read_session_validation() {
		$good = array(
			'v' => 1,
			'p' => self::REMOTE,
			'o' => 3,
			's' => 10,
			'h' => '',
		);
		$this->assertSame( 3, LocalDestination::read_session( $good )['o'] );

		$bad = array(
			array(),
			array_merge( $good, array( 'v' => 2 ) ),
			array_merge( $good, array( 'p' => '../x' ) ),
			array_merge( $good, array( 'p' => '/etc/passwd' ) ),
			array_merge( $good, array( 'o' => -1 ) ),
			array_merge( $good, array( 'o' => 11 ) ),
			array_merge( $good, array( 'o' => '3' ) ),
			array_merge( $good, array( 's' => '10' ) ),
		);
		foreach ( $bad as $session ) {
			try {
				LocalDestination::read_session( $session );
				$this->fail( 'accepted ' . json_encode( $session ) );
			} catch ( DestinationException $e ) {
				$this->assertFalse( $e->retryable() );
			}
		}
	}

	// Choosing the folder.

	public function test_a_custom_folder_is_created_and_used() {
		$destination = $this->custom();
		$result      = $destination->test_connection();
		$this->assertTrue( $result->ok(), $result->message() );
		$this->assertDirectoryExists( $this->root . '/outside/dest' );
		$this->assertSame( array(), glob( $this->root . '/outside/dest/.ovb-probe-*' ), 'the probe file is removed' );
		$this->assertSame( array(), $this->protected, 'outside the web root no guard files are needed' );
		$this->assertFalse( $result->details()['web_root'] );
		$this->assertArrayHasKey( 'free_bytes', $result->details() );
		$this->assertSame( 'OpenVault-Backups', $destination->remote_root() );
		$this->assertSame( 'local', $destination->id() );
	}

	public function test_a_folder_inside_the_web_root_gets_guard_files() {
		$result = $this->custom( $this->root . '/site/wp-content/my-backups' )->test_connection();
		$this->assertTrue( $result->ok(), $result->message() );
		$this->assertTrue( $result->details()['web_root'] );
		$this->assertSame( array( $this->root . '/site/wp-content/my-backups' ), $this->protected );
		$this->assertFileExists( $this->root . '/site/wp-content/my-backups/index.php' );
	}

	public function test_the_default_is_the_plugin_backups_folder() {
		$destination = $this->default_destination();
		$result      = $destination->test_connection();
		$this->assertTrue( $result->ok(), $result->message() );
		$this->assertTrue( $result->details()['default'] );
		$this->assertSame( '', $destination->remote_root() );
		$this->assertSame( array(), $this->protected, 'the plugin folder is already protected by Paths' );
	}

	/**
	 * @dataProvider forbidden_settings
	 */
	public function test_unacceptable_folders_are_refused_without_touching_disk( $path ) {
		$path        = str_replace( '{root}', $this->root, $path );
		$destination = $this->custom( $path );
		$result      = $destination->test_connection();
		$this->assertFalse( $result->ok(), $path );
		$this->assertStringNotContainsString( $this->root, $result->message(), 'no absolute paths in messages' );
		$this->assertSame( array(), $this->protected );
		$e = $this->message_of(
			static function () use ( $destination ) {
				$destination->begin_upload( self::REMOTE, 1 );
			}
		);
		$this->assertFalse( $e->retryable() );
		$this->assertFalse( is_dir( $this->root . '/site/created-by-mistake' ) );
	}

	public function forbidden_settings() {
		return array(
			'relative'          => array( 'backups' ),
			'parent segment'    => array( '{root}/outside/../site' ),
			'wordpress root'    => array( '{root}/site' ),
			'wp-admin'          => array( '{root}/site/wp-admin' ),
			'inside wp-admin'   => array( '{root}/site/wp-admin/sub' ),
			'wp-includes'       => array( '{root}/site/wp-includes/x/y' ),
			'plugin tmp'        => array( '{root}/site/wp-content/ovb-x/tmp' ),
			'inside plugin tmp' => array( '{root}/site/wp-content/ovb-x/tmp/abc' ),
			'nul byte'          => array( "{root}/outside/a\0b" ),
			'root'              => array( '/' ),
		);
	}

	public function test_a_symlink_to_a_forbidden_folder_is_refused_after_resolving_it() {
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->root . '/site/wp-admin', $this->root . '/outside/looks-fine' ) ) {
			$this->markTestSkipped( 'Symlinks are not available.' );
		}
		$result = $this->custom( $this->root . '/outside/looks-fine/inner' )->test_connection();
		$this->assertFalse( $result->ok() );
		$this->assertDirectoryDoesNotExist( $this->root . '/site/wp-admin/inner' );

		$result = $this->custom( $this->root . '/outside/looks-fine' )->test_connection();
		$this->assertFalse( $result->ok() );
	}

	public function test_a_file_in_place_of_the_folder_is_refused() {
		file_put_contents( $this->root . '/outside/afile', 'x' );
		$this->assertFalse( $this->custom( $this->root . '/outside/afile' )->test_connection()->ok() );
		$this->assertFalse( $this->custom( $this->root . '/outside/afile/sub' )->test_connection()->ok() );
	}

	// Upload.

	public function test_upload_in_one_call_copies_verifies_and_renames() {
		$source      = $this->source( 2500000 );
		$sha         = hash_file( 'sha256', $source );
		$destination = $this->custom();

		$session  = $destination->begin_upload( self::REMOTE, 2500000, array( 'sha256' => strtoupper( $sha ) ) );
		$progress = $destination->upload_chunk( $session, $source, $this->big_budget() );

		$this->assertTrue( $progress->done() );
		$this->assertSame( self::REMOTE, $progress->remote_ref() );
		$this->assertSame( 2500000, $progress->bytes_sent() );
		$final = $this->root . '/outside/dest/' . self::REMOTE;
		$this->assertFileExists( $final );
		$this->assertFileDoesNotExist( $final . '.part' );
		$this->assertSame( $sha, hash_file( 'sha256', $final ) );
		$this->assertSame( $progress->session(), json_decode( json_encode( $progress->session() ), true ), 'the session is JSON safe' );
	}

	public function test_an_empty_file_uploads() {
		$source      = $this->source( 0, 'empty.bin' );
		$destination = $this->custom();
		$session     = $destination->begin_upload( 'OpenVault-Backups/x/empty.bin', 0 );
		$progress    = $destination->upload_chunk( $session, $source, $this->big_budget() );
		$this->assertTrue( $progress->done() );
		$this->assertSame( 0, filesize( $this->root . '/outside/dest/OpenVault-Backups/x/empty.bin' ) );
	}

	public function test_a_small_budget_gives_partial_progress_with_a_growing_offset_and_resumes_to_the_same_bytes() {
		$source      = $this->source( 3500000 );
		$sha         = hash_file( 'sha256', $source );
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 3500000, array( 'sha256' => $sha ) );

		$offsets = array();
		$calls   = 0;
		do {
			$progress  = $destination->upload_chunk( $session, $source, $this->budget( 2 ) );
			$session   = $progress->session();
			$offsets[] = $session['o'];
			++$calls;
			$this->assertLessThan( 20, $calls );
		} while ( ! $progress->done() );

		$this->assertGreaterThan( 1, $calls, 'the copy needed several calls' );
		$sorted = $offsets;
		sort( $sorted );
		$this->assertSame( $sorted, $offsets, 'offsets only grow' );
		$this->assertSame( 0, $offsets[0] % LocalDestination::CHUNK, 'an interrupted copy stops on a chunk boundary' );
		$this->assertSame( $sha, hash_file( 'sha256', $this->root . '/outside/dest/' . self::REMOTE ) );
	}

	public function test_every_call_moves_forward_even_with_an_exhausted_budget() {
		$source      = $this->source( 2200000 );
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 2200000 );
		$progress    = $destination->upload_chunk( $session, $source, $this->budget( 0 ) );
		$this->assertFalse( $progress->done() );
		$this->assertSame( LocalDestination::CHUNK, $progress->session()['o'] );
		$this->assertSame( LocalDestination::CHUNK, $progress->bytes_sent() );
	}

	public function test_resume_cuts_garbage_after_the_saved_offset() {
		$source      = $this->source( 3300000 );
		$sha         = hash_file( 'sha256', $source );
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 3300000, array( 'sha256' => $sha ) );

		$first = $destination->upload_chunk( $session, $source, $this->budget( 2 ) );
		$saved = $first->session();
		$this->assertFalse( $first->done() );
		$part = $this->root . '/outside/dest/' . self::REMOTE . '.part';
		$this->assertSame( $saved['o'], filesize( $part ) );

		// A run that was killed after writing more, before saving the session.
		file_put_contents( $part, 'GARBAGE-FROM-A-KILLED-RUN' . random_bytes( 5000 ), FILE_APPEND );
		clearstatcache( true, $part );
		$this->assertGreaterThan( $saved['o'], filesize( $part ) );

		$progress = $destination->upload_chunk( $saved, $source, $this->big_budget() );
		$this->assertTrue( $progress->done() );
		$this->assertSame( $sha, hash_file( 'sha256', $this->root . '/outside/dest/' . self::REMOTE ), 'the copy equals the source, garbage included in nothing' );
	}

	public function test_resume_restarts_when_the_partial_file_is_gone_or_shorter() {
		$source      = $this->source( 2300000 );
		$sha         = hash_file( 'sha256', $source );
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 2300000, array( 'sha256' => $sha ) );
		$first       = $destination->upload_chunk( $session, $source, $this->budget( 2 ) );
		$saved       = $first->session();
		$part        = $this->root . '/outside/dest/' . self::REMOTE . '.part';

		file_put_contents( $part, 'short' );
		$progress = $destination->upload_chunk( $saved, $source, $this->big_budget() );
		$this->assertTrue( $progress->done() );
		$this->assertSame( $sha, hash_file( 'sha256', $this->root . '/outside/dest/' . self::REMOTE ) );

		// And when the partial file vanished altogether.
		$destination->delete( self::REMOTE );
		$session = $destination->begin_upload( self::REMOTE, 2300000 );
		$first   = $destination->upload_chunk( $session, $source, $this->budget( 2 ) );
		unlink( $part );
		$progress = $destination->upload_chunk( $first->session(), $source, $this->big_budget() );
		$this->assertTrue( $progress->done() );
		$this->assertSame( $sha, hash_file( 'sha256', $this->root . '/outside/dest/' . self::REMOTE ) );
	}

	public function test_a_source_that_changed_size_is_refused_permanently() {
		$source      = $this->source( 1000 );
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 999 );
		$e           = $this->message_of(
			static function () use ( $destination, $session, $source ) {
				$destination->upload_chunk( $session, $source, new Budget( 600, PHP_INT_MAX ) );
			}
		);
		$this->assertFalse( $e->retryable() );
		$this->assertFileDoesNotExist( $this->root . '/outside/dest/' . self::REMOTE );
	}

	public function test_a_missing_source_is_refused_permanently() {
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 10 );
		$e           = $this->message_of(
			function () use ( $destination, $session ) {
				$destination->upload_chunk( $session, $this->root . '/source/none.zip', new Budget( 600, PHP_INT_MAX ) );
			}
		);
		$this->assertFalse( $e->retryable() );
		$this->assertStringNotContainsString( $this->root, $e->getMessage() );
	}

	public function test_a_wrong_checksum_removes_the_copy_and_is_retryable() {
		$source      = $this->source( 1500000 );
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 1500000, array( 'sha256' => str_repeat( 'a', 64 ) ) );
		$e           = $this->message_of(
			function () use ( $destination, $session, $source ) {
				$destination->upload_chunk( $session, $source, $this->big_budget() );
			}
		);
		$this->assertTrue( $e->retryable() );
		$this->assertFileDoesNotExist( $this->root . '/outside/dest/' . self::REMOTE );
		$this->assertFileDoesNotExist( $this->root . '/outside/dest/' . self::REMOTE . '.part' );
	}

	public function test_a_file_already_inside_the_base_is_not_copied() {
		$folder = $this->root . '/site/wp-content/ovb-x/backups/2026-01-02_030405_3f2a9c1e';
		mkdir( $folder );
		$local = $folder . '/db.part01.zip';
		file_put_contents( $local, 'archive bytes' );

		$destination = $this->default_destination();
		$session     = $destination->begin_upload( 'OpenVault-Backups/example.com/2026-01-02_030405_3f2a9c1e/db.part01.zip', 13 );
		$progress    = $destination->upload_chunk( $session, $local, $this->budget( 0 ) );

		$this->assertTrue( $progress->done() );
		$this->assertSame( 0, $progress->bytes_sent(), 'nothing was copied' );
		$this->assertSame( '2026-01-02_030405_3f2a9c1e/db.part01.zip', $progress->remote_ref() );
		$this->assertFileDoesNotExist( $local . '.part' );
		$this->assertDirectoryDoesNotExist( $this->root . '/site/wp-content/ovb-x/backups/OpenVault-Backups', 'no copy and no second folder tree' );
		$this->assertSame( 'archive bytes', file_get_contents( $local ) );
	}

	public function test_begin_upload_refuses_bad_input() {
		$destination = $this->custom();
		foreach ( array( '../x', '/etc/passwd', 'a/../../b', 'a//b', '', "a\0b", 'C:/x' ) as $path ) {
			try {
				$destination->begin_upload( $path, 1 );
				$this->fail( 'accepted ' . $path );
			} catch ( DestinationException $e ) {
				$this->assertFalse( $e->retryable() );
			}
		}
		foreach ( array( -1, '5', 1.5, null ) as $size ) {
			try {
				$destination->begin_upload( self::REMOTE, $size );
				$this->fail( 'accepted a bad size' );
			} catch ( DestinationException $e ) {
				$this->assertFalse( $e->retryable() );
			}
		}
	}

	public function test_a_symlinked_remote_folder_is_never_followed_out_of_the_base() {
		$destination = $this->custom();
		$destination->test_connection();
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->root . '/outside', $this->root . '/outside/dest/OpenVault-Backups' ) ) {
			$this->markTestSkipped( 'Symlinks are not available.' );
		}
		file_put_contents( $this->root . '/outside/precious.txt', 'keep' );

		foreach ( array( 'upload', 'delete', 'download' ) as $action ) {
			try {
				if ( 'upload' === $action ) {
					$destination->begin_upload( 'OpenVault-Backups/x.zip', 1 );
				} elseif ( 'delete' === $action ) {
					$destination->delete( 'OpenVault-Backups/precious.txt' );
				} else {
					$destination->download( 'OpenVault-Backups/precious.txt', static function () {} );
				}
				$this->fail( $action . ' followed a symlink' );
			} catch ( DestinationException $e ) {
				$this->assertFalse( $e->retryable() );
			}
		}
		$this->assertSame( 'keep', file_get_contents( $this->root . '/outside/precious.txt' ) );
		$this->assertFileDoesNotExist( $this->root . '/outside/x.zip' );
	}

	// Abort, download, delete, list, quota.

	public function test_abort_removes_the_partial_file_only() {
		$source      = $this->source( 2300000 );
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 2300000 );
		$progress    = $destination->upload_chunk( $session, $source, $this->budget( 2 ) );
		$part        = $this->root . '/outside/dest/' . self::REMOTE . '.part';
		$this->assertFileExists( $part );

		$destination->abort_upload( $progress->session() );
		$this->assertFileDoesNotExist( $part );

		$destination->abort_upload( array( 'garbage' => true ) ); // Never throws.
		$destination->abort_upload( array() );
		$this->assertTrue( true );
	}

	public function test_download_streams_in_chunks_and_can_be_stopped() {
		$source      = $this->source( 2500000 );
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 2500000 );
		$destination->upload_chunk( $session, $source, $this->big_budget() );

		$received = '';
		$chunks   = 0;
		$destination->download(
			self::REMOTE,
			static function ( $data ) use ( &$received, &$chunks ) {
				$received .= $data;
				++$chunks;
			}
		);
		$this->assertSame( hash_file( 'sha256', $source ), hash( 'sha256', $received ) );
		$this->assertGreaterThanOrEqual( 3, $chunks );

		$chunks = 0;
		$destination->download(
			self::REMOTE,
			static function () use ( &$chunks ) {
				++$chunks;
				return false;
			}
		);
		$this->assertSame( 1, $chunks );

		$this->expectException( DestinationException::class );
		$destination->download( 'OpenVault-Backups/none.zip', static function () {} );
	}

	public function test_delete_removes_the_file_and_prunes_empty_parents_but_not_the_base() {
		$source      = $this->source( 100 );
		$destination = $this->custom();
		$session     = $destination->begin_upload( self::REMOTE, 100 );
		$destination->upload_chunk( $session, $source, $this->big_budget() );

		$destination->delete( self::REMOTE );
		$base = $this->root . '/outside/dest';
		$this->assertDirectoryExists( $base, 'the base stays' );
		$this->assertSame( array(), glob( $base . '/*' ), 'every empty parent folder is gone' );

		$destination->delete( self::REMOTE ); // Idempotent.
		$this->assertTrue( true );
	}

	public function test_delete_of_a_backup_folder_keeps_siblings() {
		$source      = $this->source( 100 );
		$destination = $this->custom();
		foreach ( array( '2026-01-02_030405_aaaaaaaa', '2026-01-03_030405_bbbbbbbb' ) as $folder ) {
			foreach ( array( 'db.part01.zip', 'manifest.json' ) as $name ) {
				$session = $destination->begin_upload( "OpenVault-Backups/example.com/$folder/$name", 100 );
				$destination->upload_chunk( $session, $source, $this->big_budget() );
			}
		}
		$destination->delete( 'OpenVault-Backups/example.com/2026-01-02_030405_aaaaaaaa' );

		$refs = array_column( iterator_to_array( $destination->list(), false ), 'ref' );
		sort( $refs );
		$this->assertSame(
			array(
				'OpenVault-Backups/example.com/2026-01-03_030405_bbbbbbbb/db.part01.zip',
				'OpenVault-Backups/example.com/2026-01-03_030405_bbbbbbbb/manifest.json',
			),
			$refs
		);
	}

	public function test_delete_and_download_refuse_paths_outside_the_base() {
		file_put_contents( $this->root . '/outside/precious.txt', 'keep' );
		$destination = $this->custom();
		$destination->test_connection();
		foreach ( array( '../precious.txt', '../../outside/precious.txt', '/etc/passwd', 'a/../../precious.txt', '' ) as $ref ) {
			try {
				$destination->delete( $ref );
				$this->fail( 'delete accepted ' . $ref );
			} catch ( DestinationException $e ) {
				$this->assertFalse( $e->retryable() );
			}
			try {
				$destination->download( $ref, static function () {} );
				$this->fail( 'download accepted ' . $ref );
			} catch ( DestinationException $e ) {
				$this->assertFalse( $e->retryable() );
			}
		}
		$this->assertSame( 'keep', file_get_contents( $this->root . '/outside/precious.txt' ) );
	}

	public function test_a_symlink_inside_the_base_is_neither_listed_nor_deleted() {
		$destination = $this->custom();
		$destination->test_connection();
		file_put_contents( $this->root . '/outside/precious.txt', 'keep' );
		$base = $this->root . '/outside/dest';
		mkdir( $base . '/OpenVault-Backups' );
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->root . '/outside/precious.txt', $base . '/OpenVault-Backups/link.zip' ) ) {
			$this->markTestSkipped( 'Symlinks are not available.' );
		}
		$this->assertSame( array(), iterator_to_array( $destination->list(), false ) );
		try {
			$destination->delete( 'OpenVault-Backups/link.zip' );
			$this->fail( 'deleted a link' );
		} catch ( DestinationException $e ) {
			$this->assertSame( 'keep', file_get_contents( $this->root . '/outside/precious.txt' ) );
		}
	}

	public function test_list_is_lazy_limited_and_skips_partial_files_and_guard_files() {
		$destination = $this->custom();
		$source      = $this->source( 10 );
		for ( $i = 1; $i <= 5; $i++ ) {
			$session = $destination->begin_upload( 'OpenVault-Backups/example.com/2026-01-02_030405_aaaaaaaa/f' . $i . '.zip', 10 );
			$destination->upload_chunk( $session, $source, $this->big_budget() );
		}
		$base = $this->root . '/outside/dest/OpenVault-Backups/example.com/2026-01-02_030405_aaaaaaaa';
		file_put_contents( $base . '/half.zip.part', 'x' );
		file_put_contents( $base . '/index.php', 'x' );
		file_put_contents( $base . '/.htaccess', 'x' );

		$all = iterator_to_array( $destination->list(), false );
		$this->assertCount( 5, $all );
		$this->assertSame( 10, $all[0]['size'] );
		$this->assertSame( 'OpenVault-Backups/example.com/2026-01-02_030405_aaaaaaaa/' . $all[0]['name'], $all[0]['ref'] );
		$this->assertIsInt( $all[0]['modified'] );

		$this->assertCount( 2, iterator_to_array( $destination->list( '', 2 ), false ) );
		$this->assertCount( 5, iterator_to_array( $destination->list( 'OpenVault-Backups/example.com' ), false ) );
		$this->assertCount( 0, iterator_to_array( $destination->list( 'OpenVault-Backups/nothing-here' ), false ) );
		$this->assertInstanceOf( \Generator::class, $destination->list() );

		$this->expectException( DestinationException::class );
		iterator_to_array( $destination->list( '../outside' ), false );
	}

	public function test_quota() {
		$quota = $this->custom()->quota();
		if ( null === $quota ) {
			$this->markTestSkipped( 'disk_free_space is unavailable.' );
		}
		$this->assertGreaterThan( 0, $quota['total'] );
		$this->assertGreaterThanOrEqual( 0, $quota['free'] );
		$this->assertNull( $this->custom( $this->root . '/site' )->quota(), 'a refused folder has no quota' );
	}
}
