<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Tests run without WordPress.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Dump\ExclusionRules;
use OpenVault\Backup\Engine\Dump\TableList;
use OpenVault\Backup\Engine\JobState;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Dump\TableList
 */
class TableListTest extends TestCase {

	private function base( $name ) {
		return array( $name, 'BASE TABLE' );
	}

	public function test_select_keeps_base_tables_with_the_prefix_sorted_by_name() {
		$rules  = new ExclusionRules( 'wp_' );
		$tables = array(
			$this->base( 'wp_users' ),
			$this->base( 'wp_posts' ),
			array( 'wp_a_view', 'VIEW' ),
			array( 'wp_sys_view', 'SYSTEM VIEW' ),
			$this->base( 'other_app_table' ),
			$this->base( 'WP_upper' ),
			$this->base( 'wp_ovb_backups' ),
			$this->base( 'wp_ovb_logs' ),
			$this->base( 'wp_ovbtest_kept' ),
			$this->base( 'wp_Posts2' ),
			$this->base( 'wp_posts' ),
			array( 'wp_lower_type', 'base table' ),
		);
		$this->assertSame(
			array( 'wp_Posts2', 'wp_lower_type', 'wp_ovbtest_kept', 'wp_posts', 'wp_users' ),
			TableList::select( $tables, 'wp_', $rules )
		);
	}

	public function test_select_applies_user_exclusions() {
		$rules  = new ExclusionRules( 'wp_', false, array( 'wp_actionscheduler_*', 'wp_users' ) );
		$tables = array( $this->base( 'wp_actionscheduler_logs' ), $this->base( 'wp_users' ), $this->base( 'wp_posts' ) );
		$this->assertSame( array( 'wp_posts' ), TableList::select( $tables, 'wp_', $rules ) );
	}

	public function test_select_never_includes_other_applications_tables() {
		$rules = new ExclusionRules( 'wp_' );
		$this->assertSame( array(), TableList::select( array( $this->base( 'wordpress_x' ), $this->base( 'laravel_users' ) ), 'wp_', $rules ) );
	}

	public function test_select_ignores_malformed_rows() {
		$rules = new ExclusionRules( 'wp_' );
		$this->assertSame( array( 'wp_a' ), TableList::select( array( 'junk', array(), array( 'wp_b' ), $this->base( 'wp_a' ) ), 'wp_', $rules ) );
	}

	public function test_sort_is_a_plain_byte_comparison() {
		$rules = new ExclusionRules( 'wp_' );
		$names = array( 'wp_b', 'wp_B', 'wp_a', 'wp_10', 'wp_9', 'wp__x', 'wp_é' );
		$rows  = array_map( array( $this, 'base' ), $names );
		$this->assertSame( array( 'wp_10', 'wp_9', 'wp_B', 'wp__x', 'wp_a', 'wp_b', 'wp_é' ), TableList::select( $rows, 'wp_', $rules ) );
	}

	public function test_position_finds_tables_and_the_next_one_when_missing() {
		$names = array( 'wp_a', 'wp_c', 'wp_e' );
		$this->assertSame( 0, TableList::position( $names, '' ) );
		$this->assertSame( 0, TableList::position( $names, 'wp_a' ) );
		$this->assertSame( 1, TableList::position( $names, 'wp_c' ) );
		$this->assertSame( 1, TableList::position( $names, 'wp_b' ), 'dropped table: the next one' );
		$this->assertSame( 2, TableList::position( $names, 'wp_d' ) );
		$this->assertSame( 3, TableList::position( $names, 'wp_f' ), 'past the end' );
		$this->assertSame( 0, TableList::position( $names, 'a' ) );
		$this->assertSame( 0, TableList::position( array(), 'wp_a' ) );
	}

	public function test_position_survives_new_tables_appearing_before_the_current_one() {
		$before = array( 'wp_b', 'wp_d', 'wp_f' );
		$this->assertSame( 'wp_d', $before[ TableList::position( $before, 'wp_d' ) ] );
		$after = array( 'wp_a', 'wp_b', 'wp_c', 'wp_d', 'wp_f' );
		$this->assertSame( 'wp_d', $after[ TableList::position( $after, 'wp_d' ) ] );
	}

	private function make_names( $count, $length ) {
		$names = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$names[] = str_pad( 'wp_t' . $i . '_', $length, 'x' );
		}
		return $names;
	}

	private function cursor_for( array $names ) {
		$cursor = array(
			'tables_total' => count( $names ),
			'tables_done'  => 123456,
			'table_index'  => 4999,
			'table'        => str_repeat( 'n', 64 ),
			'strategy'     => array(
				'type'  => 'offset',
				'order' => array( 'object_id', 'term_taxonomy_id' ),
			),
			'last_key'     => '-18446744073709551615',
			'offset'       => 99999999999,
			'table_rows'   => 99999999999,
			'rows_done'    => 99999999999999,
			'batch'        => 2000,
			'file_offset'  => 99999999999999,
			'complete'     => false,
		);
		if ( TableList::fits_in_cursor( $names ) ) {
			$cursor['tables'] = $names;
		}
		return $cursor;
	}

	public function test_a_small_list_is_stored_and_round_trips_through_job_state() {
		$names = $this->make_names( 300, 40 );
		$this->assertTrue( TableList::fits_in_cursor( $names ) );

		$cursor = $this->cursor_for( $names );
		$state  = JobState::new_state( JobState::STEP_DB_EXPORT )->with_cursor( $cursor );
		$back   = JobState::from_json( $state->to_json() );
		$this->assertSame( $cursor, $back->get_cursor() );
		$this->assertSame( $names, $back->get_cursor()['tables'] );
		$this->assertLessThanOrEqual( JobState::MAX_CURSOR_BYTES, strlen( json_encode( $cursor ) ) );
	}

	public function test_five_thousand_tables_never_overflow_the_cursor() {
		foreach ( array( 5, 40, 64 ) as $length ) {
			$names  = $this->make_names( 5000, $length );
			$cursor = $this->cursor_for( $names );
			$size   = strlen( json_encode( $cursor ) );
			$this->assertLessThanOrEqual( JobState::MAX_CURSOR_BYTES, $size, "name length $length gave $size bytes" );

			// JobState accepts it and it survives a round trip.
			$state = JobState::new_state( JobState::STEP_DB_EXPORT )->with_cursor( $cursor );
			$this->assertSame( $cursor, JobState::from_json( $state->to_json() )->get_cursor() );
		}
	}

	public function test_a_list_that_is_too_big_is_not_stored() {
		$names = $this->make_names( 5000, 64 );
		$this->assertFalse( TableList::fits_in_cursor( $names ), '5,000 tables of 64 characters are about 335 KB' );
		$this->assertArrayNotHasKey( 'tables', $this->cursor_for( $names ) );
		$this->assertTrue( TableList::fits_in_cursor( array() ) );
	}

	public function test_the_stored_list_limit_leaves_room_in_the_cursor() {
		$this->assertLessThanOrEqual( JobState::MAX_CURSOR_BYTES / 2, TableList::CURSOR_LIST_MAX_BYTES );
		// The largest list that is still stored: build one just under the limit.
		$names = array();
		$size  = 2;
		while ( true ) {
			$name = 'wp_table_' . count( $names ) . '_' . str_repeat( 'x', 30 );
			if ( $size + strlen( $name ) + 3 > TableList::CURSOR_LIST_MAX_BYTES ) {
				break;
			}
			$names[] = $name;
			$size   += strlen( $name ) + 3;
		}
		$this->assertTrue( TableList::fits_in_cursor( $names ) );
		$cursor = $this->cursor_for( $names );
		$this->assertLessThanOrEqual( JobState::MAX_CURSOR_BYTES, strlen( json_encode( $cursor ) ) );
		JobState::new_state( JobState::STEP_DB_EXPORT )->with_cursor( $cursor );
	}

	public function test_invalid_utf8_in_a_name_is_not_stored_in_the_cursor() {
		$this->assertFalse( TableList::fits_in_cursor( array( "wp_caf\xe9" ) ) );
	}
}
