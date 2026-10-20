<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\S3\SigV4Signer;
use PHPUnit\Framework\TestCase;

/**
 * Byte-exact vectors from the AWS documentation "Signature Calculations for the Authorization Header: Transferring
 * Payload in a Single Chunk (AWS Signature Version 4)":
 * https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
 * (access key AKIAIOSFODNN7EXAMPLE, region us-east-1, 20130524T000000Z, bucket examplebucket).
 *
 * @covers \OpenVault\Backup\Destinations\S3\SigV4Signer
 */
class SigV4SignerTest extends TestCase {

	const KEY    = 'AKIAIOSFODNN7EXAMPLE';
	const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
	const TIME   = 1369353600; // 2013-05-24T00:00:00Z.
	const HOST   = 'examplebucket.s3.amazonaws.com';

	private function signer( $region = 'us-east-1' ) {
		return new SigV4Signer(
			self::KEY,
			self::SECRET,
			$region,
			's3',
			static function () {
				return self::TIME;
			}
		);
	}

	/**
	 * Run the three steps and return them.
	 */
	private function steps( $method, $path, array $query, array $headers, $payload_hash ) {
		$signer                      = $this->signer();
		$all                         = $headers;
		$all['x-amz-date']           = '20130524T000000Z';
		$all['x-amz-content-sha256'] = $payload_hash;
		$canonical                   = $signer->canonical_request( $method, $path, $query, $all, $payload_hash );
		$sts                         = $signer->string_to_sign( $canonical, '20130524T000000Z' );
		return array( $canonical, $sts, $signer->signature( $sts, '20130524' ), $signer->sign( $method, $path, $query, $headers, $payload_hash ) );
	}

	public function test_get_object_with_range() {
		list( $canonical, $sts, $signature, $signed ) = $this->steps(
			'GET',
			'/test.txt',
			array(),
			array(
				'Host'  => self::HOST,
				'Range' => 'bytes=0-9',
			),
			SigV4Signer::EMPTY_SHA256
		);

		$this->assertSame(
			"GET\n/test.txt\n\nhost:examplebucket.s3.amazonaws.com\nrange:bytes=0-9\nx-amz-content-sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\nx-amz-date:20130524T000000Z\n\nhost;range;x-amz-content-sha256;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
			$canonical
		);
		$this->assertSame( "AWS4-HMAC-SHA256\n20130524T000000Z\n20130524/us-east-1/s3/aws4_request\n7344ae5b7ee6c3e7e6b0fe0640412a37625d1fbfff95c48bbb2dc43964946972", $sts );
		$this->assertSame( 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $signature );
		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			str_replace( ', ', ',', $signed['authorization'] )
		);
	}

	public function test_put_object_with_special_characters_in_the_key() {
		$payload = SigV4Signer::payload_hash( 'Welcome to Amazon S3.' );
		$this->assertSame( '44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', $payload );

		list( $canonical, $sts, $signature ) = $this->steps(
			'PUT',
			'/test$file.text',
			array(),
			array(
				'date'                => 'Fri, 24 May 2013 00:00:00 GMT',
				'Host'                => self::HOST,
				'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
			),
			$payload
		);

		$this->assertSame(
			"PUT\n/test%24file.text\n\ndate:Fri, 24 May 2013 00:00:00 GMT\nhost:examplebucket.s3.amazonaws.com\nx-amz-content-sha256:44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072\nx-amz-date:20130524T000000Z\nx-amz-storage-class:REDUCED_REDUNDANCY\n\ndate;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class\n44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072",
			$canonical
		);
		$this->assertSame( "AWS4-HMAC-SHA256\n20130524T000000Z\n20130524/us-east-1/s3/aws4_request\n9e0e90d9c76de8fa5b200d8c849cd5b8dc7a3be3951ddb7f6a76b4158342019d", $sts );
		$this->assertSame( '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', $signature );
	}

	public function test_get_bucket_lifecycle_has_an_empty_valued_query_parameter() {
		list( $canonical, $sts, $signature ) = $this->steps( 'GET', '/', array( 'lifecycle' => '' ), array( 'host' => self::HOST ), SigV4Signer::EMPTY_SHA256 );

		$this->assertSame(
			"GET\n/\nlifecycle=\nhost:examplebucket.s3.amazonaws.com\nx-amz-content-sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\nx-amz-date:20130524T000000Z\n\nhost;x-amz-content-sha256;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
			$canonical
		);
		$this->assertSame( "AWS4-HMAC-SHA256\n20130524T000000Z\n20130524/us-east-1/s3/aws4_request\n9766c798316ff2757b517bc739a67f6213b4ab36dd5da2f94eaebf79c77395ca", $sts );
		$this->assertSame( 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', $signature );
	}

	public function test_get_bucket_list_objects_sorts_the_query() {
		list( $canonical, $sts, $signature ) = $this->steps(
			'GET',
			'/',
			array(
				'prefix'   => 'J',
				'max-keys' => '2',
			),
			array( 'host' => self::HOST ),
			SigV4Signer::EMPTY_SHA256
		);

		$this->assertSame(
			"GET\n/\nmax-keys=2&prefix=J\nhost:examplebucket.s3.amazonaws.com\nx-amz-content-sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\nx-amz-date:20130524T000000Z\n\nhost;x-amz-content-sha256;x-amz-date\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
			$canonical
		);
		$this->assertSame( "AWS4-HMAC-SHA256\n20130524T000000Z\n20130524/us-east-1/s3/aws4_request\ndf57d21db20da04d7fa30298dd4488ba3a2b47ca3a489c74750e0f1e7df1b9b7", $sts );
		$this->assertSame( '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $signature );
	}

	public function test_empty_payload_hash_constant() {
		$this->assertSame( SigV4Signer::EMPTY_SHA256, SigV4Signer::payload_hash( '' ) );
	}

	public function test_sign_adds_the_date_and_content_hash_headers_and_uses_the_clock() {
		$headers = $this->signer()->sign(
			'GET',
			'/test.txt',
			array(),
			array(
				'Host'  => self::HOST,
				'Range' => 'bytes=0-9',
			)
		);
		$this->assertSame( '20130524T000000Z', $headers['x-amz-date'] );
		$this->assertSame( SigV4Signer::EMPTY_SHA256, $headers['x-amz-content-sha256'] );
		$this->assertStringContainsString( 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $headers['authorization'] );
	}

	public function test_unsigned_payload_is_only_used_when_asked_for() {
		$headers = $this->signer()->sign( 'PUT', '/b/k', array(), array( 'host' => 'h' ), SigV4Signer::UNSIGNED );
		$this->assertSame( 'UNSIGNED-PAYLOAD', $headers['x-amz-content-sha256'] );
	}

	public function test_a_different_region_changes_the_signature_and_scope() {
		$headers = $this->signer( 'eu-west-1' )->sign( 'GET', '/test.txt', array(), array( 'host' => self::HOST ) );
		$this->assertStringContainsString( '/20130524/eu-west-1/s3/aws4_request', $headers['authorization'] );
		$this->assertStringNotContainsString( 'f0e8bdb87c96', $headers['authorization'] );
	}

	public function test_path_encoding_rules_for_s3() {
		$cases = array(
			'/b/my file.zip'       => '/b/my%20file.zip',
			'/b/a+b'               => '/b/a%2Bb',
			'/b/100%'              => '/b/100%25',
			'/b/~tilde'            => '/b/~tilde',
			'/b/a=b&c'             => '/b/a%3Db%26c',
			'/b/caf' . "\xc3\xa9"  => '/b/caf%C3%A9',
			'/b/' . "\xe2\x82\xac" => '/b/%E2%82%AC',
			'/b//double//slash'    => '/b//double//slash',
			'//leading'            => '//leading',
			'/b/trailing/'         => '/b/trailing/',
			'/b/a/./b/../c'        => '/b/a/./b/../c',
			''                     => '/',
			'/b/!*\'()'            => '/b/%21%2A%27%28%29',
		);
		foreach ( $cases as $raw => $encoded ) {
			$this->assertSame( $encoded, SigV4Signer::encode_path( $raw ), $raw );
		}
	}

	public function test_query_encoding_sorting_and_empty_values() {
		$this->assertSame(
			'a%20b=c%2Bd%3De%26f&prefix=OpenVault-Backups%2Fsite%2F&uploads=',
			SigV4Signer::canonical_query(
				array(
					'uploads' => '',
					'prefix'  => 'OpenVault-Backups/site/',
					'a b'     => 'c+d=e&f',
				)
			)
		);
		$this->assertSame(
			'A=1&a=2&b=3',
			SigV4Signer::canonical_query(
				array(
					'b' => 3,
					'a' => 2,
					'A' => 1,
				)
			),
			'bytewise order: capitals first'
		);
		$this->assertSame( 'k=', SigV4Signer::canonical_query( array( 'k' => null ) ) );
		$this->assertSame( 'x=%25~', SigV4Signer::canonical_query( array( 'x' => '%~' ) ) );
		$this->assertSame( '', SigV4Signer::canonical_query( array() ) );
	}

	public function test_header_values_are_trimmed_and_whitespace_is_folded() {
		list( $block, $names ) = SigV4Signer::canonical_headers(
			array(
				'X-Amz-Meta-Note' => "  a   b \t c  ",
				'Host'            => ' h ',
				'CONTENT-TYPE'    => 'text/plain',
			)
		);
		$this->assertSame( "content-type:text/plain\nhost:h\nx-amz-meta-note:a b c\n", $block );
		$this->assertSame( 'content-type;host;x-amz-meta-note', $names );
	}

	public function test_the_secret_never_appears_in_dumps_or_headers() {
		$signer  = $this->signer();
		$headers = $signer->sign( 'GET', '/k', array(), array( 'host' => 'h' ) );
		$this->assertStringNotContainsString( self::SECRET, print_r( $signer, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		$this->assertStringNotContainsString( self::SECRET, implode( ' ', $headers ) );
	}

	public function test_signing_is_deterministic_for_a_fixed_clock() {
		$a = $this->signer()->sign( 'GET', '/k', array( 'a' => 'b' ), array( 'host' => 'h' ) );
		$b = $this->signer()->sign( 'GET', '/k', array( 'a' => 'b' ), array( 'host' => 'h' ) );
		$this->assertSame( $a, $b );
	}
}
