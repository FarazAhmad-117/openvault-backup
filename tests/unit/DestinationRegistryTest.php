<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- A small fake provider lives next to its test.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\DestinationInterface;
use OpenVault\Backup\Destinations\DestinationRegistry;
use OpenVault\Backup\Destinations\Local\LocalDestination;
use OpenVault\Backup\Destinations\TestResult;
use OpenVault\Backup\Destinations\UploadProgress;
use OpenVault\Backup\Engine\Budget;
use PHPUnit\Framework\TestCase;

/**
 * Provider used by the tests; keeps the settings and credentials it was built with.
 */
class OvbFakeDestination implements DestinationInterface {
	public $settings;
	public $credentials;
	public function __construct( array $settings = array(), array $credentials = array() ) {
		$this->settings    = $settings;
		$this->credentials = $credentials;
	}
	public function id() {
		return 'fake';
	}
	public function label() {
		return 'Fake';
	}
	public function test_connection() {
		return TestResult::success( 'ok' );
	}
	public function begin_upload( $remote_path, $size, array $options = array() ) {
		return array();
	}
	public function upload_chunk( array $session, $local_file, Budget $budget ) {
		return UploadProgress::finished( $session, 0, 'ref' );
	}
	public function abort_upload( array $session ) {
	}
	public function download( $remote_ref, callable $sink ) {
	}
	public function delete( $remote_ref ) {
	}
	public function list( $remote_prefix = '', $limit = 10000 ) {
		return array();
	}
	public function quota() {
		return null;
	}
	public function remote_root() {
		return 'OpenVault-Backups';
	}
}

/**
 * @covers \OpenVault\Backup\Destinations\DestinationRegistry
 */
class DestinationRegistryTest extends TestCase {

	private function ok_reader( array $data = array() ) {
		return static function () use ( $data ) {
			return array(
				'ok'     => true,
				'data'   => $data,
				'reason' => null,
			);
		};
	}

	private function row( $type = 'local', array $extra = array() ) {
		return array_merge(
			array(
				'id'       => 7,
				'type'     => $type,
				'settings' => array( 'path' => '' ),
				'status'   => 'connected',
			),
			$extra
		);
	}

	private function identity() {
		return static function ( $factories ) {
			return $factories;
		};
	}

	public function test_local_is_built_in() {
		$registry = new DestinationRegistry( $this->ok_reader(), $this->identity() );
		$this->assertSame( array( 'local', 's3' ), $registry->types() );
		$this->assertTrue( $registry->has( 's3' ) );
		$this->assertTrue( $registry->has( 'local' ) );
		$this->assertFalse( $registry->has( 'gdrive' ) );
		$this->assertInstanceOf( LocalDestination::class, $registry->create( $this->row() ) );
	}

	public function test_register_and_create_with_a_class_name() {
		$registry = new DestinationRegistry( $this->ok_reader( array( 'key' => 'v' ) ), $this->identity(), false );
		$this->assertSame( array(), $registry->types() );

		$registry->register( 'fake', OvbFakeDestination::class );
		$made = $registry->create( $this->row( 'fake', array( 'settings' => array( 'a' => 1 ) ) ) );
		$this->assertInstanceOf( OvbFakeDestination::class, $made );
		$this->assertSame( array( 'a' => 1 ), $made->settings );
		$this->assertSame( array( 'key' => 'v' ), $made->credentials );
	}

	public function test_register_with_a_callable_receives_settings_credentials_and_row() {
		$seen     = array();
		$registry = new DestinationRegistry( $this->ok_reader( array( 'k' => 's' ) ), $this->identity(), false );
		$registry->register(
			'fake',
			static function ( $settings, $credentials, $row ) use ( &$seen ) {
				$seen = array( $settings, $credentials, $row['id'] );
				return new OvbFakeDestination( $settings, $credentials );
			}
		);
		$registry->create( $this->row( 'fake', array( 'settings' => array( 'b' => 2 ) ) ) );
		$this->assertSame( array( array( 'b' => 2 ), array( 'k' => 's' ), 7 ), $seen );
	}

	public function test_invalid_registrations_are_refused() {
		$registry = new DestinationRegistry( null, $this->identity(), false );
		foreach ( array( 'Bad Type', '', 'UPPER', str_repeat( 'a', 21 ), '../x' ) as $type ) {
			try {
				$registry->register( $type, OvbFakeDestination::class );
				$this->fail( 'accepted ' . $type );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertTrue( true );
			}
		}
		foreach ( array( 'No\\Such\\Class', \stdClass::class, 42, null ) as $factory ) {
			try {
				$registry->register( 'ok', $factory );
				$this->fail( 'accepted a bad factory' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertTrue( true );
			}
		}
	}

	public function test_unknown_type_is_a_typed_failure() {
		$registry = new DestinationRegistry( $this->ok_reader(), $this->identity() );
		try {
			$registry->create( $this->row( 'dropbox' ) );
			$this->fail( 'created an unknown type' );
		} catch ( DestinationException $e ) {
			$this->assertSame( DestinationException::REASON_UNKNOWN_TYPE, $e->reason() );
			$this->assertFalse( $e->retryable() );
		}
	}

	public function test_unreadable_credentials_need_a_reconnect() {
		$reader   = static function () {
			return array(
				'ok'     => false,
				'data'   => array(),
				'reason' => 'wrong_key',
			);
		};
		$registry = new DestinationRegistry( $reader, $this->identity() );
		try {
			$registry->create( $this->row() );
			$this->fail( 'created with unreadable credentials' );
		} catch ( DestinationException $e ) {
			$this->assertSame( DestinationException::REASON_NEEDS_RECONNECT, $e->reason() );
			$this->assertFalse( $e->retryable() );
		}
	}

	public function test_a_destination_that_vanished_is_permanent_not_a_reconnect() {
		$reader   = static function () {
			return array(
				'ok'     => false,
				'data'   => array(),
				'reason' => 'not_found',
			);
		};
		$registry = new DestinationRegistry( $reader, $this->identity() );
		try {
			$registry->create( $this->row() );
			$this->fail( 'created a missing destination' );
		} catch ( DestinationException $e ) {
			$this->assertSame( DestinationException::REASON_PERMANENT, $e->reason() );
		}
	}

	public function test_a_needs_reconnect_row_never_decrypts_anything() {
		$calls    = 0;
		$reader   = static function () use ( &$calls ) {
			++$calls;
			return array(
				'ok'     => true,
				'data'   => array(),
				'reason' => null,
			);
		};
		$registry = new DestinationRegistry( $reader, $this->identity() );
		try {
			$registry->create( $this->row( 'local', array( 'status' => 'needs_reconnect' ) ) );
			$this->fail( 'created a destination that needs a reconnect' );
		} catch ( DestinationException $e ) {
			$this->assertSame( DestinationException::REASON_NEEDS_RECONNECT, $e->reason() );
		}
		$this->assertSame( 0, $calls );
	}

	public function test_credentials_are_read_only_when_creating() {
		$calls    = 0;
		$reader   = static function () use ( &$calls ) {
			++$calls;
			return array(
				'ok'     => true,
				'data'   => array(),
				'reason' => null,
			);
		};
		$registry = new DestinationRegistry( $reader, $this->identity() );
		$registry->types();
		$registry->has( 'local' );
		$this->assertSame( 0, $calls );
		$registry->create( $this->row() );
		$this->assertSame( 1, $calls );
	}

	public function test_the_filter_can_add_replace_and_remove_providers() {
		$filter   = static function ( $factories ) {
			unset( $factories['local'] );
			$factories['fake']     = OvbFakeDestination::class;
			$factories['broken']   = 'No\\Such\\Class';
			$factories['BAD TYPE'] = OvbFakeDestination::class;
			$factories[0]          = OvbFakeDestination::class;
			return $factories;
		};
		$registry = new DestinationRegistry( $this->ok_reader(), $filter );

		$this->assertSame( array( 's3', 'fake' ), $registry->types(), 'invalid entries are dropped' );
		$this->assertInstanceOf( OvbFakeDestination::class, $registry->create( $this->row( 'fake' ) ) );
		$this->expectException( DestinationException::class );
		$registry->create( $this->row( 'local' ) );
	}

	public function test_a_filter_that_returns_junk_leaves_no_providers() {
		$registry = new DestinationRegistry(
			$this->ok_reader(),
			static function () {
				return 'not an array';
			}
		);
		$this->assertSame( array(), $registry->types() );
	}

	public function test_a_factory_that_returns_something_else_fails() {
		$registry = new DestinationRegistry( $this->ok_reader(), $this->identity(), false );
		$registry->register(
			'fake',
			static function () {
				return new \stdClass();
			}
		);
		$this->expectException( DestinationException::class );
		$registry->create( $this->row( 'fake' ) );
	}
}
