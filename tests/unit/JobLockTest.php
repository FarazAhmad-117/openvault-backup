<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The in-memory fake store belongs to this test only.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobLock;
use OpenVault\Backup\Engine\LockStore;
use PHPUnit\Framework\TestCase;

/**
 * In-memory LockStore with the same atomic semantics as the options table.
 */
class FakeLockStore implements LockStore {

	public $rows = array();

	public function insert( $name, $value ) {
		if ( isset( $this->rows[ $name ] ) ) {
			return false;
		}
		$this->rows[ $name ] = $value;
		return true;
	}

	public function read( $name ) {
		return isset( $this->rows[ $name ] ) ? $this->rows[ $name ] : null;
	}

	public function delete_if( $name, $value ) {
		if ( isset( $this->rows[ $name ] ) && $this->rows[ $name ] === $value ) {
			unset( $this->rows[ $name ] );
			return true;
		}
		return false;
	}

	public function update_if( $name, $old, $replacement ) {
		if ( isset( $this->rows[ $name ] ) && $this->rows[ $name ] === $old && $old !== $replacement ) {
			$this->rows[ $name ] = $replacement;
			return true;
		}
		return false;
	}
}

/**
 * @covers \OpenVault\Backup\Engine\JobLock
 */
class JobLockTest extends TestCase {

	private $time = 1700000000;

	private function lock( FakeLockStore $store, $id = 7 ) {
		return new JobLock(
			$id,
			$store,
			function () {
				return $this->time;
			}
		);
	}

	public function test_option_name() {
		$this->assertSame( 'ovb_lock_42', JobLock::option_name( 42 ) );
	}

	public function test_acquire_and_release() {
		$store = new FakeLockStore();
		$lock  = $this->lock( $store );

		$this->assertFalse( $lock->is_held() );
		$this->assertTrue( $lock->acquire( 120 ) );
		$this->assertTrue( $lock->is_held() );
		$this->assertArrayHasKey( 'ovb_lock_7', $store->rows );
		$this->assertTrue( $lock->release() );
		$this->assertFalse( $lock->is_held() );
		$this->assertSame( array(), $store->rows );
	}

	public function test_second_caller_is_refused_while_the_lock_is_fresh() {
		$store = new FakeLockStore();
		$a     = $this->lock( $store );
		$b     = $this->lock( $store );

		$this->assertTrue( $a->acquire( 120 ) );
		$this->assertFalse( $b->acquire( 120 ) );
		$this->assertFalse( $b->is_held() );

		$this->time += 119;
		$this->assertFalse( $b->acquire( 120 ), 'still fresh one second before the TTL' );
	}

	public function test_locks_of_different_backups_are_independent() {
		$store = new FakeLockStore();
		$this->assertTrue( $this->lock( $store, 1 )->acquire( 120 ) );
		$this->assertTrue( $this->lock( $store, 2 )->acquire( 120 ) );
	}

	public function test_stale_lock_is_taken_over() {
		$store = new FakeLockStore();
		$a     = $this->lock( $store );
		$b     = $this->lock( $store );

		$this->assertTrue( $a->acquire( 120 ) );
		$this->time += 120;
		$this->assertTrue( $b->acquire( 120 ), 'a lock exactly TTL old is stale' );
		$this->assertTrue( $b->is_held() );
		$this->assertTrue( JobLock::parse( $store->rows['ovb_lock_7'] )['time'] === $this->time );
	}

	public function test_old_owner_cannot_release_or_refresh_after_takeover() {
		$store = new FakeLockStore();
		$a     = $this->lock( $store );
		$b     = $this->lock( $store );

		$a->acquire( 120 );
		$this->time += 500;
		$b->acquire( 120 );

		$this->assertFalse( $a->refresh(), 'the old owner lost the lock' );
		$this->assertFalse( $a->release(), 'and must not delete the new owner\'s lock' );
		$this->assertTrue( $b->is_held() );
		$this->assertArrayHasKey( 'ovb_lock_7', $store->rows );
		$this->assertTrue( $b->release() );
	}

	public function test_exactly_one_of_two_stale_takers_wins() {
		$store = new FakeLockStore();
		$this->assertTrue( $this->lock( $store )->acquire( 120 ) );
		$this->time += 1000;

		// Both read the stale value before either acts: model that with a store whose read() is stale-then-live.
		$racing = new class( $store ) extends FakeLockStore {
			public $inner;
			public $stale;
			public function __construct( $inner ) {
				$this->inner = $inner;
				$this->stale = $inner->rows['ovb_lock_7'];
			}
			public function insert( $name, $value ) {
				return $this->inner->insert( $name, $value );
			}
			public function read( $name ) {
				return $this->stale; // Always the old value, like a caller that read before the other one acted.
			}
			public function delete_if( $name, $value ) {
				return $this->inner->delete_if( $name, $value );
			}
			public function update_if( $name, $old, $replacement ) {
				return $this->inner->update_if( $name, $old, $replacement );
			}
		};

		$a = new JobLock(
			7,
			$racing,
			function () {
				return $this->time;
			}
		);
		$b = new JobLock(
			7,
			$racing,
			function () {
				return $this->time;
			}
		);

		$won = array( $a->acquire( 120 ), $b->acquire( 120 ) );
		$this->assertSame( 1, count( array_filter( $won ) ), 'exactly one caller takes over' );
	}

	public function test_refresh_extends_the_lock() {
		$store = new FakeLockStore();
		$a     = $this->lock( $store );
		$b     = $this->lock( $store );

		$a->acquire( 120 );
		$this->time += 100;
		$this->assertTrue( $a->refresh() );
		$this->time += 100; // 200 s after acquiring, but only 100 s after the refresh.
		$this->assertFalse( $b->acquire( 120 ) );
	}

	public function test_refresh_in_the_same_second_still_works() {
		$store = new FakeLockStore();
		$a     = $this->lock( $store );
		$a->acquire( 120 );
		$this->assertTrue( $a->refresh() );
		$this->assertTrue( $a->is_held() );
	}

	public function test_refresh_without_holding_fails() {
		$this->assertFalse( $this->lock( new FakeLockStore() )->refresh() );
		$this->assertFalse( $this->lock( new FakeLockStore() )->release() );
	}

	public function test_malformed_lock_value_counts_as_stale() {
		$store                     = new FakeLockStore();
		$store->rows['ovb_lock_7'] = 'garbage';
		$this->assertTrue( $this->lock( $store )->acquire( 120 ) );
	}

	public function test_is_locked_does_not_acquire() {
		$store = new FakeLockStore();
		$a     = $this->lock( $store );
		$b     = $this->lock( $store );

		$this->assertFalse( $b->is_locked( 120 ) );
		$a->acquire( 120 );
		$this->assertTrue( $b->is_locked( 120 ) );
		$this->time += 500;
		$this->assertFalse( $b->is_locked( 120 ), 'a stale lock is not a lock' );
		$this->assertFalse( $b->is_held() );
	}

	public function test_parse_and_is_stale() {
		$this->assertSame(
			array(
				'time'  => 1700000000,
				'token' => 'abcdef0123456789',
			),
			JobLock::parse( '1700000000:abcdef0123456789' )
		);
		$this->assertSame( 0, JobLock::parse( null )['time'] );
		$this->assertSame( 0, JobLock::parse( '1700000000:xyz' )['time'] );
		$this->assertTrue( JobLock::is_stale( null, 1700000000, 120 ) );
		$this->assertTrue( JobLock::is_stale( '1700000000:abcdef0123456789', 1700000120, 120 ) );
		$this->assertFalse( JobLock::is_stale( '1700000000:abcdef0123456789', 1700000119, 120 ) );
	}
	public function test_option_names_for_backup_ids_and_the_restore_lock() {
		$this->assertSame( 'ovb_lock_7', JobLock::option_name( 7 ) );
		$this->assertSame( 'ovb_lock_7', JobLock::option_name( '7' ) );
		$this->assertSame( 'ovb_lock_restore', JobLock::option_name( 'restore' ) );
		$this->assertSame( 'ovb_lock_restore_start', JobLock::option_name( 'restore_start' ) );
	}

	/**
	 * @dataProvider bad_lock_names
	 */
	public function test_bad_lock_names_are_refused( $name ) {
		$this->expectException( \InvalidArgumentException::class );
		JobLock::option_name( $name );
	}

	public function bad_lock_names() {
		return array(
			'upper case' => array( 'Restore' ),
			'dash'       => array( 're-store' ),
			'sql'        => array( "x' OR 1=1 --" ),
			'percent'    => array( 'a%' ),
			'too long'   => array( str_repeat( 'a', 40 ) ),
			'empty'      => array( '' ),
			'digit lead' => array( '1a' ),
		);
	}

	public function test_the_restore_lock_excludes_a_second_holder_and_not_a_backup_lock() {
		$store   = new FakeLockStore();
		$restore = $this->lock( $store, 'restore' );
		$other   = $this->lock( $store, 'restore' );
		$backup  = $this->lock( $store, 7 );

		$this->assertTrue( $restore->acquire( 120 ) );
		$this->assertFalse( $other->acquire( 120 ) );
		$this->assertTrue( $backup->acquire( 120 ) );
		$this->assertArrayHasKey( 'ovb_lock_restore', $store->rows );
		$this->assertArrayHasKey( 'ovb_lock_7', $store->rows );

		$restore->release();
		$this->assertTrue( $other->acquire( 120 ) );
	}
}
