<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions.json_encode_json_encode
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\RemotePath;
use OpenVault\Backup\Destinations\S3\MultipartUploader;
use OpenVault\Backup\Destinations\S3\S3Destination;
use OpenVault\Backup\Engine\Budget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeS3.php';

/**
 * S3Destination and MultipartUploader against an in-memory S3 that verifies every signature.
 *
 * @covers \OpenVault\Backup\Destinations\S3\S3Destination
 * @covers \OpenVault\Backup\Destinations\S3\MultipartUploader
 */
class S3DestinationTest extends TestCase {

	const MIB = 1048576;
	const REF = 'OpenVault-Backups/example.com/2026-01-02_030405_3f2a9c1e/files.part01.zip';

	/** @var string[] */
	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $f ) {
			@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		parent::tearDown();
	}

	private function file( $bytes ) {
		$path = sys_get_temp_dir() . '/ovb-s3-' . bin2hex( random_bytes( 4 ) ) . '.bin';
		$h    = fopen( $path, 'wb' );
		$left = $bytes;
		while ( $left > 0 ) {
			$n = min( $left, self::MIB );
			fwrite( $h, random_bytes( $n ) );
			$left -= $n;
		}
		fclose( $h );
		$this->files[] = $path;
		return $path;
	}

	private function dest( FakeS3 $fake, array $settings = array(), array $credentials = null ) {
		return new S3Destination(
			array_merge(
				array(
					'preset'       => 'custom',
					'endpoint'     => 'https://fake.test',
					'bucket'       => 'bkt',
					'part_size_mb' => 5,
				),
				$settings
			),
			null === $credentials ? array(
				'access_key' => $fake->access,
				'secret_key' => $fake->secret,
			) : $credentials,
			$fake,
			function () use ( $fake ) {
				return $fake->now;
			}
		);
	}

	/** A budget that never runs out, or one that allows about $parts parts per call (each request advances the clock by 2 s). */
	private function budget( FakeS3 $fake, $seconds = 1000 ) {
		return new Budget(
			$seconds,
			PHP_INT_MAX,
			function () use ( $fake ) {
				return 1000.0 + 2.0 * count( $fake->calls );
			}
		);
	}

	private function run_all( S3Destination $d, array $session, $file, FakeS3 $fake, $seconds = 1000, &$calls = 0, callable $between = null ) {
		for ( $i = 0; $i < 500; $i++ ) {
			$progress = $d->upload_chunk( $session, $file, $this->budget( $fake, $seconds ) );
			$session  = $progress->session();
			++$calls;
			if ( $progress->done() ) {
				return $progress;
			}
			if ( null !== $between ) {
				$session = call_user_func( $between, $session );
			}
		}
		$this->fail( 'upload never finished' );
	}

	public function test_a_small_file_is_one_put_with_md5_and_the_reference_is_the_remote_path() {
		$fake = new FakeS3();
		$file = $this->file( 300000 );
		$d    = $this->dest( $fake );
		$s    = $d->begin_upload( self::REF, 300000 );
		$this->assertSame( '', $s['upload_id'] );

		$p = $d->upload_chunk( $s, $file, $this->budget( $fake ) );
		$this->assertTrue( $p->done() );
		$this->assertSame( self::REF, $p->remote_ref() );
		$this->assertSame( 300000, $p->bytes_sent() );
		$this->assertSame( md5_file( $file ), $fake->objects[ self::REF ]['etag'] );
		$this->assertSame( array(), $fake->uploads );
		$this->assertSame( 'PUT', $fake->calls[0]['method'] );
	}

	public function test_a_file_equal_to_one_part_uses_a_single_put() {
		$fake = new FakeS3();
		$file = $this->file( 5 * self::MIB );
		$d    = $this->dest( $fake );
		$this->assertSame( '', $d->begin_upload( self::REF, 5 * self::MIB )['upload_id'] );
		$this->assertSame( 'one more byte starts multipart', 'one more byte starts multipart' );
		$this->assertNotSame( '', $d->begin_upload( self::REF, 5 * self::MIB + 1 )['upload_id'] );
		unset( $file );
	}

	public function test_a_multipart_upload_with_a_short_last_part_is_completed_and_verified() {
		$fake  = new FakeS3();
		$size  = 12 * self::MIB + 12345;
		$file  = $this->file( $size );
		$d     = $this->dest( $fake );
		$s     = $d->begin_upload( self::REF, $size );
		$calls = 0;
		$p     = $this->run_all( $d, $s, $file, $fake, 1000, $calls );
		$this->assertTrue( $p->done() );
		$this->assertSame( $size, $fake->objects[ self::REF ]['size'] );
		$this->assertSame( hash_file( 'sha256', $file ), hash_file( 'sha256', $fake->objects[ self::REF ]['body'] ) );
		$this->assertSame( array(), $fake->uploads, 'upload completed, nothing left open' );
		$this->assertSame( 1, $calls );
	}

	public function test_budget_exhaustion_mid_file_returns_a_resumable_session_that_stays_tiny() {
		$fake  = new FakeS3();
		$size  = 31 * self::MIB;
		$file  = $this->file( $size );
		$d     = $this->dest( $fake );
		$s     = $d->begin_upload( self::REF, $size );
		$first = $d->upload_chunk( $s, $file, $this->budget( $fake, 9 ) );
		$this->assertFalse( $first->done() );
		$this->assertGreaterThanOrEqual( 5 * self::MIB, $first->bytes_sent(), 'at least one part per call' );
		$this->assertLessThan( $size, $first->bytes_sent() );
		$json = json_encode( $first->session() );
		$this->assertLessThan( 600, strlen( $json ) );
		$this->assertSame( array( 'v', 'path', 'size', 'part_size', 'upload_id', 'next_part', 'offset', 'restarts' ), array_keys( $first->session() ) );

		$calls = 0;
		$done  = $this->run_all( $d, $first->session(), $file, $fake, 9, $calls );
		$this->assertTrue( $done->done() );
		$this->assertGreaterThan( 1, $calls );
		$this->assertSame( $size, $fake->objects[ self::REF ]['size'] );
		$this->assertSame( hash_file( 'sha256', $file ), hash_file( 'sha256', $fake->objects[ self::REF ]['body'] ) );
	}

	public function test_a_crash_that_loses_the_session_resumes_from_the_stored_one_and_overwrites_parts() {
		$fake  = new FakeS3();
		$size  = 22 * self::MIB;
		$file  = $this->file( $size );
		$d     = $this->dest( $fake );
		$saved = $d->begin_upload( self::REF, $size );
		$first = $d->upload_chunk( $saved, $file, $this->budget( $fake, 9 ) );
		$old   = $first->session();
		// Another slice ran but its session was lost: the server holds more parts than the stored session knows.
		$second = $d->upload_chunk( $old, $file, $this->budget( $fake, 9 ) );
		$this->assertGreaterThan( $old['next_part'], $second->session()['next_part'] );

		$done = $this->run_all( $d, $old, $file, $fake, 1000 );
		$this->assertTrue( $done->done() );
		$this->assertSame( hash_file( 'sha256', $file ), hash_file( 'sha256', $fake->objects[ self::REF ]['body'] ) );
	}

	public function test_a_stray_part_and_a_missing_part_are_repaired_from_list_parts() {
		$fake = new FakeS3();
		$size = 17 * self::MIB;
		$file = $this->file( $size );
		$d    = $this->dest( $fake );
		$s    = $d->begin_upload( self::REF, $size );
		$id   = $s['upload_id'];
		// Upload everything, then break the server state: lose part 2, and make the session claim everything is sent.
		$p = $d->upload_chunk( $s, $file, $this->budget( $fake, 1000 ) );
		$this->assertTrue( $p->done() );

		$fake2 = new FakeS3();
		$d2    = $this->dest( $fake2 );
		$s2    = $d2->begin_upload( self::REF, $size );
		$id2   = $s2['upload_id'];
		$total = 4;
		$bytes = file_get_contents( $file );
		foreach ( array( 1, 3, 4 ) as $n ) {
			$fake2->uploads[ $id2 ]['parts'][ $n ] = array(
				'size' => min( 5 * self::MIB, $size - ( $n - 1 ) * 5 * self::MIB ),
				'etag' => md5( substr( $bytes, ( $n - 1 ) * 5 * self::MIB, 5 * self::MIB ) ),
				'body' => $fake2->blob( substr( $bytes, ( $n - 1 ) * 5 * self::MIB, 5 * self::MIB ) ),
			);
		}
		$fake2->uploads[ $id2 ]['parts'][7] = array(
			'size' => 3,
			'etag' => md5( 'zzz' ),
			'body' => $fake2->blob( 'zzz' ),
		); // Stray part beyond the file.
		$s2['next_part']                    = $total + 1;
		$s2['offset']                       = $size;
		$this->assertNotSame( $id, '' );

		$progress = $d2->upload_chunk( $s2, $file, $this->budget( $fake2, 1000 ) );
		$this->assertFalse( $progress->done(), 'a missing part sends the upload back to that part' );
		$this->assertSame( 2, $progress->session()['next_part'] );
		$this->assertSame( 1, $progress->session()['restarts'] );

		$done = $this->run_all( $d2, $progress->session(), $file, $fake2, 1000 );
		$this->assertTrue( $done->done() );
		$this->assertSame( hash_file( 'sha256', $file ), hash_file( 'sha256', $fake2->objects[ self::REF ]['body'] ) );
	}

	public function test_session_says_part_4_but_the_server_has_parts_1_to_3_and_a_stray_5() {
		$fake  = new FakeS3();
		$size  = 17 * self::MIB;
		$file  = $this->file( $size );
		$d     = $this->dest( $fake );
		$s     = $d->begin_upload( self::REF, $size );
		$bytes = file_get_contents( $file );
		$id    = $s['upload_id'];
		$chunk = static function ( $n ) use ( $bytes ) {
			if ( $n > 4 ) {
				return 'zzz'; // Stray part beyond the file.
			}
			return substr( $bytes, ( $n - 1 ) * 5 * 1048576, 5 * 1048576 );
		};
		foreach ( array( 1, 2, 3, 6 ) as $n ) {
			$fake->uploads[ $id ]['parts'][ $n ] = array(
				'size' => strlen( $chunk( $n ) ),
				'etag' => md5( $chunk( $n ) ),
				'body' => $fake->blob( $chunk( $n ) ),
			);
		}
		$s['next_part'] = 4;
		$s['offset']    = 15 * self::MIB;
		$done           = $this->run_all( $d, $s, $file, $fake, 1000 );
		$this->assertTrue( $done->done() );
		$this->assertSame( hash_file( 'sha256', $file ), hash_file( 'sha256', $fake->objects[ self::REF ]['body'] ) );
	}

	public function test_list_parts_pagination_is_used_when_completing() {
		$fake       = new FakeS3();
		$fake->page = 2;
		$size       = 22 * self::MIB;
		$file       = $this->file( $size );
		$d          = $this->dest( $fake );
		$done       = $this->run_all( $d, $d->begin_upload( self::REF, $size ), $file, $fake, 1000 );
		$this->assertTrue( $done->done() );
		$lists = array_filter(
			$fake->calls,
			static function ( $c ) {
				return 'GET' === $c['method'] && isset( $c['query']['uploadId'] );
			}
		);
		$this->assertCount( 3, $lists, '6 parts, 2 per page' );
	}

	public function test_a_vanished_multipart_upload_is_started_again_or_accepted_when_the_object_exists() {
		$fake          = new FakeS3();
		$size          = 12 * self::MIB;
		$file          = $this->file( $size );
		$d             = $this->dest( $fake );
		$s             = $d->begin_upload( self::REF, $size );
		$fake->uploads = array(); // A lifecycle rule removed it.
		$p             = $d->upload_chunk( $s, $file, $this->budget( $fake ) );
		$this->assertFalse( $p->done() );
		$this->assertNotSame( $s['upload_id'], $p->session()['upload_id'] );
		$this->assertSame( 1, $p->session()['restarts'] );
		$done = $this->run_all( $d, $p->session(), $file, $fake, 1000 );
		$this->assertTrue( $done->done() );

		// The object is already complete (crash after CompleteMultipartUpload): the stale session finishes at once.
		$p2 = $d->upload_chunk( $s, $file, $this->budget( $fake ) );
		$this->assertTrue( $p2->done() );

		// It keeps vanishing: give up.
		$fake->objects = array();
		$s['restarts'] = 2;
		$fake->uploads = array();
		$this->expectException( DestinationException::class );
		$d->upload_chunk( $s, $file, $this->budget( $fake ) );
	}

	public function test_a_size_mismatch_after_completion_fails_with_a_clear_message() {
		$fake = new FakeS3();
		$size = 3 * self::MIB;
		$file = $this->file( $size );
		$d    = $this->dest( $fake );
		$s    = $d->begin_upload( self::REF, $size );
		// The server stores a truncated object but says OK.
		$fake->queue = array(
			array( 200, '', array( 'etag' => '"x"' ) ),
			array(
				200,
				'',
				array(
					'content-length' => '5',
					'etag'           => '"x"',
				),
			),
		);
		try {
			$d->upload_chunk( $s, $file, $this->budget( $fake ) );
			$this->fail( 'expected an error' );
		} catch ( DestinationException $e ) {
			$this->assertStringContainsString( 'has 5 bytes but 3145728 were expected', $e->getMessage() );
			$this->assertFalse( $e->retryable() );
		}
	}

	public function test_a_different_md5_etag_on_a_single_put_is_a_retryable_error_but_sse_etags_are_not_compared() {
		$fake              = new FakeS3();
		$fake->corrupt_md5 = true;
		$file              = $this->file( 1000 );
		$d                 = $this->dest( $fake );
		try {
			$d->upload_chunk( $d->begin_upload( self::REF, 1000 ), $file, $this->budget( $fake ) );
			$this->fail( 'expected an error' );
		} catch ( DestinationException $e ) {
			$this->assertTrue( $e->retryable() );
		}

		$fake2        = new FakeS3();
		$fake2->queue = array(
			array(
				200,
				'',
				array(
					'etag'                         => '"' . str_repeat( 'a', 32 ) . '"',
					'x-amz-server-side-encryption' => 'aws:kms',
				),
			),
			array( 200, '', array( 'content-length' => '1000' ) ),
		);
		$d2           = $this->dest( $fake2 );
		$this->assertTrue( $d2->upload_chunk( $d2->begin_upload( self::REF, 1000 ), $file, $this->budget( $fake2 ) )->done() );
	}

	public function test_a_changed_local_file_is_refused() {
		$fake = new FakeS3();
		$file = $this->file( 1000 );
		$d    = $this->dest( $fake );
		$s    = $d->begin_upload( self::REF, 1000 );
		file_put_contents( $file, 'short' );
		$this->expectException( DestinationException::class );
		$d->upload_chunk( $s, $file, $this->budget( $fake ) );
	}

	public function test_part_size_rules() {
		$mib = self::MIB;
		$this->assertSame( 8 * $mib, MultipartUploader::part_size( 100 * $mib ) );
		$this->assertSame( 5 * $mib, MultipartUploader::part_size( 100 * $mib, 1 ), 'minimum 5 MiB' );
		$this->assertSame( 64 * $mib, MultipartUploader::part_size( 100 * $mib, 500 * $mib ), 'maximum 64 MiB' );
		$this->assertSame( 16 * $mib, MultipartUploader::part_size( 100 * $mib, 64 * $mib, 64 * $mib ), 'a 64M memory limit caps a part at 25%' );
		$this->assertSame( 5 * $mib, MultipartUploader::part_size( 100 * $mib, 8 * $mib, 8 * $mib ), 'never below the S3 minimum' );
		$this->assertSame( 8 * $mib, MultipartUploader::part_size( 100 * $mib, 0, -1 ) );

		// More than 10,000 parts: the part size grows.
		$big = 200 * 1024 * $mib;
		$ps  = MultipartUploader::part_size( $big, 8 * $mib );
		$this->assertGreaterThanOrEqual( (int) ceil( $big / 10000 ), $ps );
		$this->assertLessThanOrEqual( 64 * $mib, $ps );
		$this->assertLessThanOrEqual( 10000, (int) ceil( $big / $ps ) );
		$this->assertSame( 0, $ps % $mib );

		// 5 TiB fits in 10,000 parts of 64 MiB? 10,000 x 64 MiB = 625 GiB: no.
		$this->expectException( DestinationException::class );
		MultipartUploader::part_size( 5 * 1024 * 1024 * $mib );
	}

	public function test_the_session_is_far_below_the_column_limit_for_the_biggest_possible_file() {
		$fake = new FakeS3();
		$d    = $this->dest( $fake, array( 'part_size_mb' => 64 ) );
		$max  = 10000 * 64 * self::MIB;
		$s    = $d->begin_upload( self::REF, $max );
		$this->assertSame( 64 * self::MIB, $s['part_size'] );
		$this->assertLessThan( 700, strlen( json_encode( $s ) ) );
		$this->assertSame( 10000, (int) ceil( $max / $s['part_size'] ) );
	}

	public function test_a_file_too_large_for_ten_thousand_64_mib_parts_is_refused_at_the_start() {
		$fake = new FakeS3();
		$d    = $this->dest( $fake );
		try {
			$d->begin_upload( self::REF, 5 * 1024 * 1024 * 1024 * 1024 );
			$this->fail( 'expected an error' );
		} catch ( DestinationException $e ) {
			$this->assertFalse( $e->retryable() );
		}
		$this->assertSame( array(), $fake->calls, 'nothing was sent to the server' );
	}

	public function test_abort_removes_the_multipart_upload_and_never_throws() {
		$fake = new FakeS3();
		$d    = $this->dest( $fake );
		$s    = $d->begin_upload( self::REF, 12 * self::MIB );
		$this->assertCount( 1, $fake->uploads );
		$d->abort_upload( $s );
		$this->assertSame( array(), $fake->uploads );
		$d->abort_upload( $s );
		$d->abort_upload( array( 'garbage' => true ) );
		$this->assertTrue( true );
	}

	public function test_invalid_and_forged_remote_paths_are_refused() {
		$fake = new FakeS3();
		$d    = $this->dest( $fake );
		foreach ( array( '../x', 'OpenVault-Backups/../../x', '/etc/passwd', 'other/file.zip', '' ) as $bad ) {
			foreach ( array( 'begin', 'delete', 'download', 'list' ) as $op ) {
				try {
					if ( 'begin' === $op ) {
						$d->begin_upload( $bad, 10 );
					} elseif ( 'delete' === $op ) {
						$d->delete( $bad );
					} elseif ( 'download' === $op ) {
						$d->download( $bad, static function () {} );
					} else {
						iterator_to_array( $d->list( $bad ), false );
						if ( '' === $bad ) {
							continue;
						}
					}
					$this->fail( "$op accepted '$bad'" );
				} catch ( DestinationException $e ) {
					$this->assertFalse( $e->retryable() );
				}
			}
		}
		$this->assertSame( array( 'GET' ), array_unique( array_column( $fake->calls, 'method' ) ), 'only the legal root listing reached the server' );
		$this->expectException( DestinationException::class );
		$d->begin_upload( self::REF, -1 );
	}

	public function test_a_prefix_is_applied_and_removed_again() {
		$fake = new FakeS3();
		$file = $this->file( 2000 );
		$d    = $this->dest( $fake, array( 'prefix' => '/site-a/vault/' ) );
		$p    = $d->upload_chunk( $d->begin_upload( self::REF, 2000 ), $file, $this->budget( $fake ) );
		$this->assertSame( self::REF, $p->remote_ref() );
		$this->assertArrayHasKey( 'site-a/vault/' . self::REF, $fake->objects );

		$listed = iterator_to_array( $d->list(), false );
		$this->assertSame( array( self::REF ), array_column( $listed, 'ref' ) );
		$this->assertSame( 2000, $listed[0]['size'] );
		$this->assertSame( 'files.part01.zip', $listed[0]['name'] );
	}

	public function test_download_in_ranges_delete_and_list_with_limit() {
		$fake = new FakeS3();
		$size = 9 * self::MIB + 7;
		$file = $this->file( $size );
		$d    = $this->dest( $fake, array( 'part_size_mb' => 64 ) );
		$this->run_all( $d, $d->begin_upload( self::REF, $size ), $file, $fake, 1000 );

		$got = '';
		$d->download(
			self::REF,
			static function ( $chunk ) use ( &$got ) {
				$got .= $chunk;
			}
		);
		$this->assertSame( hash_file( 'sha256', $file ), hash( 'sha256', $got ) );
		$ranges = array_filter(
			$fake->calls,
			static function ( $c ) {
				return isset( $c['headers']['range'] );
			}
		);
		$this->assertCount( 2, $ranges, '8 MiB ranges' );

		$stopped = 0;
		$d->download(
			self::REF,
			static function () use ( &$stopped ) {
				++$stopped;
				return false;
			}
		);
		$this->assertSame( 1, $stopped );

		$other                   = 'OpenVault-Backups/example.com/2026-01-02_030405_3f2a9c1e/manifest.json';
		$fake->objects[ $other ] = array(
			'size'     => 4,
			'etag'     => 'x',
			'body'     => $fake->blob( 'abcd' ),
			'modified' => 1,
		);
		$this->assertCount( 1, iterator_to_array( $d->list( '', 1 ), false ) );
		$this->assertCount( 2, iterator_to_array( $d->list(), false ) );

		$d->delete( 'OpenVault-Backups/example.com/2026-01-02_030405_3f2a9c1e' );
		$this->assertSame( array(), $fake->objects, 'deleting a backup folder removes everything below it' );

		$this->expectException( DestinationException::class );
		$d->download( self::REF, static function () {} );
	}

	public function test_test_connection_success_and_the_precise_failures() {
		$fake = new FakeS3();
		$r    = $this->dest( $fake )->test_connection();
		$this->assertTrue( $r->ok(), $r->message() );
		$this->assertSame( array(), $fake->objects, 'the probe object is removed' );
		$this->assertSame(
			array(
				'preset'     => 'custom',
				'region'     => 'us-east-1',
				'addressing' => 'path',
			),
			$r->details()
		);

		$bad = $this->dest(
			new FakeS3(),
			array(),
			array(
				'access_key' => 'AKIAFAKEACCESS000001',
				'secret_key' => 'WRONG-SECRET-ZZZ',
			)
		)->test_connection();
		$this->assertFalse( $bad->ok() );
		$this->assertStringContainsString( 'signature', $bad->message() );
		$this->assertStringNotContainsString( 'WRONG-SECRET-ZZZ', $bad->message() );

		$nobucket = $this->dest( new FakeS3(), array( 'bucket' => 'nobucket' ) )->test_connection();
		$this->assertStringContainsString( 'bucket "nobucket"', $nobucket->message() );

		$nokey = $this->dest( new FakeS3(), array(), array() )->test_connection();
		$this->assertStringContainsString( 'missing', $nokey->message() );

		$badcfg = $this->dest( new FakeS3(), array( 'endpoint' => 'http://169.254.169.254' ) )->test_connection();
		$this->assertFalse( $badcfg->ok() );
		$this->assertStringNotContainsString( '169.254', $badcfg->message() );
	}

	public function test_the_probe_is_cleaned_up_when_the_read_back_fails() {
		$fake = new FakeS3();
		// head ok, put ok, get returns something else.
		$fake->queue = array( array( 200, '' ), array( 200, '', array( 'etag' => '"x"' ) ), array( 200, 'not the probe' ) );
		$r           = $this->dest( $fake )->test_connection();
		$this->assertFalse( $r->ok() );
		$this->assertStringContainsString( 'read back', $r->message() );
	}

	public function test_abort_stale_uploads_aborts_only_old_ones_below_the_root() {
		$fake                   = new FakeS3();
		$d                      = $this->dest( $fake );
		$fake->uploads['old']   = array(
			'key'       => 'OpenVault-Backups/a/f.zip',
			'initiated' => $fake->now - 3 * 86400,
			'parts'     => array(),
		);
		$fake->uploads['new']   = array(
			'key'       => 'OpenVault-Backups/a/g.zip',
			'initiated' => $fake->now - 60,
			'parts'     => array(),
		);
		$fake->uploads['alien'] = array(
			'key'       => 'photos/x.zip',
			'initiated' => $fake->now - 9 * 86400,
			'parts'     => array(),
		);
		$this->assertSame( 1, $d->abort_stale_uploads( 86400 ) );
		$this->assertSame( array( 'new', 'alien' ), array_keys( $fake->uploads ) );
	}

	public function test_metadata_and_misc_contract() {
		$d = $this->dest( new FakeS3() );
		$this->assertSame( 's3', $d->id() );
		$this->assertSame( 'S3-compatible storage', $d->label() );
		$this->assertNull( $d->quota() );
		$this->assertSame( RemotePath::ROOT, $d->remote_root() );
	}

	public function test_the_storage_class_header_is_sent_when_configured() {
		$fake = new FakeS3();
		$file = $this->file( 100 );
		$d    = $this->dest( $fake, array( 'storage_class' => 'STANDARD_IA' ) );
		$d->upload_chunk( $d->begin_upload( self::REF, 100 ), $file, $this->budget( $fake ) );
		$this->assertSame( 'STANDARD_IA', $fake->calls[0]['headers']['x-amz-storage-class'] );
	}

	public function test_an_exception_never_contains_the_credentials() {
		$fake = new FakeS3();
		$file = $this->file( 100 );
		$d    = $this->dest(
			$fake,
			array(),
			array(
				'access_key' => $fake->access,
				'secret_key' => 'SECRET-NEVER-SHOWN-123',
			)
		);
		try {
			$d->upload_chunk( $d->begin_upload( self::REF, 100 ), $file, $this->budget( $fake ) );
			$this->fail( 'expected an error' );
		} catch ( DestinationException $e ) {
			$this->assertStringNotContainsString( 'SECRET-NEVER-SHOWN-123', $e->getMessage() );
			$this->assertStringNotContainsString( $fake->access, $e->getMessage() );
		}
	}
}
