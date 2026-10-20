<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions, Universal.NamingConventions.NoReservedKeywordParameterNames
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\S3\S3Client;
use OpenVault\Backup\Destinations\S3\S3Presets;
use OpenVault\Backup\Destinations\S3\SigV4Signer;
use OpenVault\Backup\Engine\Budget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeS3.php';

/**
 * @covers \OpenVault\Backup\Destinations\S3\S3Client
 */
class S3ClientTest extends TestCase {

	private function config( array $extra = array() ) {
		return S3Presets::resolve(
			array_merge(
				array(
					'preset'   => 'custom',
					'endpoint' => 'https://fake.test',
					'bucket'   => 'bkt',
				),
				$extra
			)
		);
	}

	private function client( FakeS3 $fake, array $extra = array(), $secret = null ) {
		return new S3Client(
			$this->config( $extra ),
			new SigV4Signer(
				$fake->access,
				null === $secret ? $fake->secret : $secret,
				'us-east-1',
				's3',
				function () use ( $fake ) {
					return $fake->now;
				}
			),
			$fake
		);
	}

	private function code( callable $fn ) {
		try {
			$fn();
		} catch ( DestinationException $e ) {
			return $e;
		}
		$this->fail( 'no exception' );
	}

	public function test_url_building_for_both_styles_and_the_dotted_bucket_fallback() {
		$fake = new FakeS3();
		$path = $this->client( $fake );
		$this->assertSame(
			'https://fake.test/bkt/a%20b/c%2Bd?list-type=2&prefix=x%2Fy',
			$path->url(
				'a b/c+d',
				array(
					'prefix'    => 'x/y',
					'list-type' => '2',
				)
			)
		);
		$this->assertSame( 'https://fake.test/bkt', $path->url( '' ) );

		$virtual = $this->client( $fake, array( 'addressing' => 'virtual' ) );
		$this->assertTrue( $virtual->is_virtual() );
		$this->assertSame( 'https://bkt.fake.test/a%20b', $virtual->url( 'a b' ) );

		$dotted = $this->client(
			$fake,
			array(
				'addressing' => 'virtual',
				'bucket'     => 'my.bucket',
			)
		);
		$this->assertFalse( $dotted->is_virtual() );
		$this->assertSame( 'https://fake.test/my.bucket/k', $dotted->url( 'k' ) );

		$port = $this->client(
			$fake,
			array(
				'endpoint'               => 'http://minio:9000',
				'allow_insecure_http'    => true,
				'allow_private_endpoint' => true,
			)
		);
		$this->assertSame( 'http://minio:9000/bkt/k', $port->url( 'k' ) );
	}

	public function test_requests_work_in_both_styles_and_every_signature_is_verified() {
		foreach ( array( 'path', 'virtual' ) as $style ) {
			$fake = new FakeS3();
			$c    = $this->client( $fake, array( 'addressing' => $style ) );
			$c->put_object( 'dir/odd name+~&=%.txt', 'hello' );
			$this->assertSame( 'hello', $c->get_object_range( 'dir/odd name+~&=%.txt', 0, 4 ), $style );
			$this->assertSame( 5, $c->head_object( 'dir/odd name+~&=%.txt' )['size'] );
			$this->assertNull( $c->head_object( 'missing' ) );
			$c->delete_object( 'dir/odd name+~&=%.txt' );
			$c->delete_object( 'dir/odd name+~&=%.txt' );
			$c->head_bucket();
		}
	}

	public function test_a_double_slash_in_a_key_survives_signing() {
		$fake = new FakeS3();
		$c    = $this->client( $fake );
		$c->put_object( 'a//b/', 'x' );
		$this->assertArrayHasKey( 'a//b/', $fake->objects );
	}

	public function test_content_md5_is_sent_and_a_damaged_body_is_a_retryable_error() {
		$fake = new FakeS3();
		$c    = $this->client( $fake );
		$c->put_object( 'k', 'data' );
		$this->assertSame( base64_encode( md5( 'data', true ) ), $fake->calls[0]['headers']['content-md5'] );
		$this->assertArrayNotHasKey( 'host', $fake->calls[0]['headers'] );

		$bad          = new FakeS3();
		$bad->queue[] = array( 400, '<Error><Code>BadDigest</Code></Error>' );
		$e            = $this->code(
			function () use ( $bad ) {
				$this->client( $bad )->put_object( 'k', 'x' );
			}
		);
		$this->assertTrue( $e->retryable() );
	}

	public function test_error_mapping_table() {
		$cases = array(
			array( 403, 'SignatureDoesNotMatch', false, 'signature' ),
			array( 403, 'InvalidAccessKeyId', false, 'access key ID' ),
			array( 403, 'AccessDenied', false, 'Access denied' ),
			array( 404, 'NoSuchBucket', false, 'bucket "bkt"' ),
			array( 403, 'RequestTimeTooSkewed', false, 'by more than 15 minutes' ),
			array( 400, 'AuthorizationHeaderMalformed', false, 'region' ),
			array( 503, 'SlowDown', true, 'busy' ),
			array( 400, 'RequestTimeout', true, 'busy' ),
			array( 500, 'InternalError', true, 'busy' ),
			array( 503, 'ServiceUnavailable', true, 'busy' ),
			array( 500, '', true, 'HTTP 500' ),
			array( 502, '', true, 'HTTP 502' ),
			array( 429, '', true, 'HTTP 429' ),
			array( 301, '', false, 'redirected' ),
			array( 307, 'TemporaryRedirect', false, 'redirected' ),
			array( 301, 'PermanentRedirect', false, 'redirected' ),
			array( 400, 'EntityTooSmall', false, 'EntityTooSmall' ),
			array( 403, '', false, 'Access denied or the credentials' ),
			array( 418, '', false, 'HTTP 418' ),
		);
		foreach ( $cases as $case ) {
			$fake          = new FakeS3();
			$fake->queue[] = array( $case[0], '' === $case[1] ? '' : '<?xml version="1.0"?><Error><Code>' . $case[1] . '</Code><Message>leak ' . $fake->secret . '</Message></Error>' );
			$e             = $this->code(
				function () use ( $fake ) {
					$this->client( $fake )->put_object( 'k', 'x' );
				}
			);
			$this->assertSame( $case[2], $e->retryable(), $case[0] . ' ' . $case[1] );
			$this->assertStringContainsString( $case[3], $e->getMessage(), $case[0] . ' ' . $case[1] );
			$this->assertStringNotContainsString( $fake->secret, $e->getMessage() );
		}
	}

	public function test_the_clock_skew_message_is_the_documented_one() {
		$fake          = new FakeS3();
		$fake->queue[] = array( 403, '<Error><Code>RequestTimeTooSkewed</Code></Error>' );
		$e             = $this->code(
			function () use ( $fake ) {
				$this->client( $fake )->put_object( 'k', 'x' );
			}
		);
		$this->assertStringContainsString( "differs from the storage server's clock by more than 15 minutes", $e->getMessage() );
		$this->assertFalse( $e->retryable() );
	}

	public function test_the_region_the_server_asks_for_is_surfaced() {
		$fake          = new FakeS3();
		$fake->queue[] = array( 400, '<Error><Code>AuthorizationHeaderMalformed</Code><Region>eu-west-3</Region><Message>x</Message></Error>' );
		$e             = $this->code(
			function () use ( $fake ) {
				$this->client( $fake )->put_object( 'k', 'x' );
			}
		);
		$this->assertStringContainsString( '"eu-west-3"', $e->getMessage() );

		$fake          = new FakeS3();
		$fake->queue[] = array( 400, '<Error><Code>AuthorizationHeaderMalformed</Code><Region>eu west; drop</Region></Error>' );
		$e             = $this->code(
			function () use ( $fake ) {
				$this->client( $fake )->put_object( 'k', 'x' );
			}
		);
		$this->assertStringNotContainsString( 'drop', $e->getMessage() );
	}

	public function test_a_wrong_secret_is_a_signature_error_without_the_secret() {
		$fake = new FakeS3();
		$e    = $this->code(
			function () use ( $fake ) {
				$this->client( $fake, array(), 'WRONG-SECRET-VALUE' )->put_object( 'k', 'x' );
			}
		);
		$this->assertFalse( $e->retryable() );
		$this->assertStringContainsString( 'signature', $e->getMessage() );
		$this->assertStringNotContainsString( 'WRONG-SECRET-VALUE', $e->getMessage() );
		$this->assertStringNotContainsString( $fake->secret, $e->getMessage() );
	}

	public function test_head_bucket_on_a_bodiless_error_asks_for_the_real_reason() {
		$fake         = new FakeS3();
		$fake->bucket = 'other';
		$e            = $this->code(
			function () use ( $fake ) {
				$this->client( $fake )->head_bucket();
			}
		);
		$this->assertStringContainsString( 'bucket "bkt"', $e->getMessage() );

		$fake          = new FakeS3();
		$fake->queue[] = array( 403, '' );
		$fake->queue[] = array( 403, '<Error><Code>InvalidAccessKeyId</Code></Error>' );
		$e             = $this->code(
			function () use ( $fake ) {
				$this->client( $fake )->head_bucket();
			}
		);
		$this->assertStringContainsString( 'access key ID', $e->getMessage() );
		$this->assertCount( 2, $fake->calls );
		$this->assertSame( 'GET', $fake->calls[1]['method'] );
	}

	public function test_a_network_error_from_the_transport_propagates_as_retryable() {
		$fake          = new FakeS3();
		$fake->queue[] = array( 'network' );
		$e             = $this->code(
			function () use ( $fake ) {
				$this->client( $fake )->put_object( 'k', 'x' );
			}
		);
		$this->assertTrue( $e->retryable() );
	}

	public function test_xxe_payloads_in_an_error_body_are_not_resolved() {
		$target = sys_get_temp_dir() . '/ovb-xxe-' . bin2hex( random_bytes( 4 ) ) . '.txt';
		file_put_contents( $target, 'XXE-LEAKED-CONTENT' );
		$bodies = array(
			'<?xml version="1.0"?><!DOCTYPE e [<!ENTITY x SYSTEM "file://' . $target . '">]><Error><Code>&x;</Code></Error>',
			'<?xml version="1.0"?><!DOCTYPE e SYSTEM "http://127.0.0.1:1/evil.dtd"><Error><Code>AccessDenied</Code></Error>',
			'<?xml version="1.0"?><!DOCTYPE e [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;">]><Error><Code>&b;</Code></Error>',
		);
		foreach ( $bodies as $body ) {
			$fake          = new FakeS3();
			$fake->queue[] = array( 403, $body );
			$e             = $this->code(
				function () use ( $fake ) {
					$this->client( $fake )->put_object( 'k', 'x' );
				}
			);
			$this->assertStringNotContainsString( 'XXE-LEAKED-CONTENT', $e->getMessage() );
			$this->assertStringNotContainsString( 'aaaa', $e->getMessage() );
		}
		unlink( $target );
		$this->assertNull( $this->client( new FakeS3() )->xml( '<!DOCTYPE x><a/>' ) );
		$this->assertNull( $this->client( new FakeS3() )->xml( '<a><b></a>' ) );
	}

	public function test_a_200_response_to_complete_multipart_upload_with_an_error_body_is_an_error() {
		$fake                 = new FakeS3();
		$c                    = $this->client( $fake );
		$id                   = $c->create_multipart_upload( 'big' );
		$etag                 = $c->upload_part( 'big', $id, 1, 'abc' );
		$fake->complete_error = '<?xml version="1.0"?><Error><Code>InternalError</Code><Message>We encountered an internal error.</Message></Error>';
		$e                    = $this->code(
			function () use ( $c, $id, $etag ) {
				$c->complete_multipart_upload( 'big', $id, array( 1 => $etag ) );
			}
		);
		$this->assertTrue( $e->retryable() );

		$fake->complete_error = '<?xml version="1.0"?><Error><Code>AccessDenied</Code></Error>';
		$e                    = $this->code(
			function () use ( $c, $id, $etag ) {
				$c->complete_multipart_upload( 'big', $id, array( 1 => $etag ) );
			}
		);
		$this->assertFalse( $e->retryable() );

		$fake->complete_error = '';
		$c->complete_multipart_upload( 'big', $id, array( 1 => $etag ) );
		$this->assertSame( 3, $c->head_object( 'big' )['size'] );
	}

	public function test_list_objects_and_list_parts_paginate() {
		$fake       = new FakeS3();
		$fake->page = 3;
		$c          = $this->client( $fake );
		for ( $i = 0; $i < 8; $i++ ) {
			$c->put_object( 'p/k' . $i, 'x' );
		}
		$c->put_object( 'other/z', 'x' );
		$keys = array_column( iterator_to_array( $c->list_objects_v2( 'p/' ), false ), 'key' );
		$this->assertCount( 8, $keys );
		$this->assertCount( 3, iterator_to_array( $c->list_objects_v2( 'p/', 3 ), false ), 'limit respected' );

		$id = $c->create_multipart_upload( 'mp' );
		for ( $n = 1; $n <= 7; $n++ ) {
			$c->upload_part( 'mp', $id, $n, 'part' . $n );
		}
		$fake->calls = array();
		$parts       = $c->list_parts( 'mp', $id );
		$this->assertSame( range( 1, 7 ), array_keys( $parts ) );
		$this->assertSame( 5, $parts[5]['size'] );
		$this->assertCount( 3, $fake->calls, 'three pages of up to three parts' );
	}

	public function test_list_parts_of_a_vanished_upload_has_the_no_such_upload_reason() {
		$fake = new FakeS3();
		$e    = $this->code(
			function () use ( $fake ) {
				$this->client( $fake )->list_parts( 'k', 'gone' );
			}
		);
		$this->assertSame( 'no_such_upload', $e->reason() );
		$this->assertFalse( $e->retryable() );
	}

	public function test_timeout_comes_from_the_budget_between_5_and_25_seconds() {
		$cases = array(
			100 => 25,
			12  => 12,
			2   => 5,
			0   => 5,
		);
		foreach ( $cases as $left => $expect ) {
			$fake = new FakeS3();
			$this->client( $fake )->put_object(
				'k',
				'x',
				array(),
				new Budget(
					$left,
					PHP_INT_MAX,
					function () {
						return 1000.0;
					}
				)
			);
			$this->assertSame( $expect, $fake->calls[0]['timeout'], "left $left" );
		}
		$fake = new FakeS3();
		$this->client( $fake )->put_object( 'k', 'x' );
		$this->assertSame( 20, $fake->calls[0]['timeout'] );
	}

	public function test_reject_unsafe_urls_stays_on_unless_the_validated_private_flag_is_set() {
		$fake = new FakeS3();
		$this->client( $fake )->put_object( 'k', 'x' );
		$this->assertTrue( $fake->calls[0]['reject_unsafe'] );

		$fake = new FakeS3();
		$this->client(
			$fake,
			array(
				'endpoint'               => 'http://fake:9000',
				'allow_insecure_http'    => true,
				'allow_private_endpoint' => true,
			)
		)->put_object( 'k', 'x' );
		$this->assertFalse( $fake->calls[0]['reject_unsafe'] );
	}

	public function test_the_authorization_header_and_keys_never_appear_in_exception_messages() {
		$fake          = new FakeS3();
		$fake->queue[] = array( 500, '<Error><Code>InternalError</Code><Message>Authorization: AWS4-HMAC-SHA256 Credential=' . $fake->access . '</Message></Error>' );
		$e             = $this->code(
			function () use ( $fake ) {
				$this->client( $fake )->put_object( 'k', 'x' );
			}
		);
		$this->assertStringNotContainsString( 'AWS4-HMAC', $e->getMessage() );
		$this->assertStringNotContainsString( $fake->access, $e->getMessage() );
	}
}
