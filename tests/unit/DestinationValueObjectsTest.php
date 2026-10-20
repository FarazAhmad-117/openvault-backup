<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\TestResult;
use OpenVault\Backup\Destinations\UploadProgress;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Destinations\DestinationException
 * @covers \OpenVault\Backup\Destinations\TestResult
 * @covers \OpenVault\Backup\Destinations\UploadProgress
 */
class DestinationValueObjectsTest extends TestCase {

	public function test_exception_retry_classification() {
		$transient = DestinationException::transient( 'Timeout' );
		$permanent = DestinationException::permanent( 'Bad bucket' );
		$reconnect = DestinationException::needs_reconnect();

		$this->assertTrue( $transient->retryable() );
		$this->assertSame( DestinationException::REASON_TRANSIENT, $transient->reason() );
		$this->assertFalse( $permanent->retryable() );
		$this->assertSame( DestinationException::REASON_PERMANENT, $permanent->reason() );
		$this->assertFalse( $reconnect->retryable() );
		$this->assertSame( DestinationException::REASON_NEEDS_RECONNECT, $reconnect->reason() );
		$this->assertSame( DestinationsRepository::RECONNECT_MESSAGE, $reconnect->getMessage() );
		$this->assertInstanceOf( \RuntimeException::class, $permanent );

		$custom = new DestinationException( 'x', true );
		$this->assertTrue( $custom->retryable() );
		$this->assertFalse( ( new DestinationException( 'x' ) )->retryable(), 'permanent by default' );
	}

	public function test_exception_message_is_scrubbed() {
		$e = DestinationException::transient( 'Request failed: Authorization: Bearer abc123def456 and token=SECRETVALUE99' );
		$this->assertStringNotContainsString( 'abc123def456', $e->getMessage() );
		$this->assertStringNotContainsString( 'SECRETVALUE99', $e->getMessage() );

		$long = DestinationException::permanent( str_repeat( 'x ', 600 ) );
		$this->assertLessThanOrEqual( DestinationsRepository::ERROR_MAX, strlen( $long->getMessage() ) );
	}

	public function test_test_result() {
		$ok = TestResult::success( 'Works', array( 'free_bytes' => 5 ) );
		$this->assertTrue( $ok->ok() );
		$this->assertSame( 'Works', $ok->message() );
		$this->assertSame( array( 'free_bytes' => 5 ), $ok->details() );

		$bad = TestResult::failure( 'Denied: client_secret=TOPSECRET1234' );
		$this->assertFalse( $bad->ok() );
		$this->assertStringNotContainsString( 'TOPSECRET1234', $bad->message() );
		$this->assertSame( array(), $bad->details() );
	}

	public function test_upload_progress() {
		$session = array( 'o' => 5 );
		$partial = UploadProgress::partial( $session, 5 );
		$this->assertFalse( $partial->done() );
		$this->assertSame( 5, $partial->bytes_sent() );
		$this->assertSame( '', $partial->remote_ref() );
		$this->assertSame( $session, $partial->session() );

		$done = UploadProgress::finished( $session, 3, 'a/b.zip' );
		$this->assertTrue( $done->done() );
		$this->assertSame( 'a/b.zip', $done->remote_ref() );

		$this->assertSame( 0, UploadProgress::partial( array(), -4 )->bytes_sent(), 'never negative' );
	}

	public function test_finished_needs_a_reference() {
		$this->expectException( \InvalidArgumentException::class );
		UploadProgress::finished( array(), 0, '' );
	}
}
