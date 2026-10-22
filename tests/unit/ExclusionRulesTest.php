<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Dump\ExclusionRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Dump\ExclusionRules
 */
class ExclusionRulesTest extends TestCase {

	public function test_plugin_tables_are_always_excluded() {
		$rules = new ExclusionRules( 'wp_' );
		foreach ( array( 'wp_ovb_backups', 'wp_ovb_backup_files', 'wp_ovb_destinations', 'wp_ovb_schedules', 'wp_ovb_logs', 'wp_ovb_restores', 'wp_ovb_future_table' ) as $table ) {
			$this->assertTrue( $rules->is_excluded_table( $table ), $table );
		}
		foreach ( array( 'wp_posts', 'wp_options', 'wp_ovbtest_x', 'wp_ovbx', 'wp_2_ovb_logs', 'other_ovb_logs', 'wp_my_ovb_table' ) as $table ) {
			$this->assertFalse( $rules->is_excluded_table( $table ), $table );
		}
	}

	public function test_multisite_plugin_tables_of_every_site() {
		$rules = new ExclusionRules( 'wp_', true );
		foreach ( array( 'wp_ovb_logs', 'wp_2_ovb_logs', 'wp_15_ovb_backups' ) as $table ) {
			$this->assertTrue( $rules->is_excluded_table( $table ), $table );
		}
		$this->assertFalse( $rules->is_excluded_table( 'wp_2_posts' ) );
		$this->assertFalse( $rules->is_excluded_table( 'wp_x_ovb_logs' ) );
	}

	public function test_prefix_with_regex_characters_is_literal() {
		$rules = new ExclusionRules( 'w.p+_' );
		$this->assertTrue( $rules->is_excluded_table( 'w.p+_ovb_logs' ) );
		$this->assertFalse( $rules->is_excluded_table( 'wxpp_ovb_logs' ) );
	}

	public function test_user_patterns_exact_and_glob() {
		$rules = new ExclusionRules( 'wp_', false, array( 'wp_cache_table', 'wp_actionscheduler_*', 'wp_*_log' ) );
		$this->assertTrue( $rules->is_excluded_table( 'wp_cache_table' ) );
		$this->assertFalse( $rules->is_excluded_table( 'wp_cache_table2' ) );
		$this->assertFalse( $rules->is_excluded_table( 'xwp_cache_table' ) );
		$this->assertTrue( $rules->is_excluded_table( 'wp_actionscheduler_actions' ) );
		$this->assertTrue( $rules->is_excluded_table( 'wp_actionscheduler_' ) );
		$this->assertFalse( $rules->is_excluded_table( 'wp_actionschedulerx' ) );
		$this->assertTrue( $rules->is_excluded_table( 'wp_a_b_log' ) );
		$this->assertFalse( $rules->is_excluded_table( 'wp_a_b_logs' ) );
	}

	public function test_patterns_are_case_sensitive_and_anchored() {
		$rules = new ExclusionRules( 'wp_', false, array( 'wp_Foo' ) );
		$this->assertTrue( $rules->is_excluded_table( 'wp_Foo' ) );
		$this->assertFalse( $rules->is_excluded_table( 'wp_foo' ) );
		$this->assertFalse( $rules->is_excluded_table( "wp_Foo\nbar" ) );
	}

	public function test_dollar_in_a_pattern_is_literal() {
		$rules = new ExclusionRules( 'wp_', false, array( 'wp_a$b' ) );
		$this->assertTrue( $rules->is_excluded_table( 'wp_a$b' ) );
		$this->assertFalse( $rules->is_excluded_table( 'wp_a' ) );
	}

	public function weird_patterns() {
		return array(
			'not an array'     => array( 'wp_*', array() ),
			'null'             => array( null, array() ),
			'bare star'        => array( array( '*' ), array() ),
			'only stars'       => array( array( '***' ), array() ),
			'empty'            => array( array( '', '   ' ), array() ),
			'non strings'      => array( array( 1, null, array( 'x' ), true, 1.5 ), array() ),
			'spaces'           => array( array( 'wp posts' ), array() ),
			'quotes'           => array( array( "wp_'x", 'wp_"x', 'wp_`x' ), array() ),
			'sql'              => array( array( 'a;DROP TABLE x', 'a--b', 'a/*b' ), array() ),
			'regex characters' => array( array( 'wp_.+', 'wp_(a|b)', 'wp_[a-z]', 'wp_a?', 'wp_^', '\\d' ), array() ),
			'path'             => array( array( '../wp_x', 'a/b' ), array() ),
			'newline inside'   => array( array( "wp_a\nb" ), array() ),
			'too long'         => array( array( str_repeat( 'a', 65 ) ), array() ),
			'max length ok'    => array( array( str_repeat( 'a', 64 ) ), array( str_repeat( 'a', 64 ) ) ),
			'trimmed'          => array( array( '  wp_x  ' ), array( 'wp_x' ) ),
			'duplicates'       => array( array( 'wp_x', 'wp_x', ' wp_x' ), array( 'wp_x' ) ),
			'valid mix'        => array( array( 'wp_a*', '*_b', 'bad one', 'wp_$c' ), array( 'wp_a*', '*_b', 'wp_$c' ) ),
			'unicode letters'  => array( array( 'wp_é' ), array() ),
		);
	}

	/**
	 * @dataProvider weird_patterns
	 */
	public function test_normalise_patterns( $raw, $expected ) {
		$this->assertSame( $expected, ExclusionRules::normalise_patterns( $raw ) );
	}

	public function test_pattern_count_is_capped() {
		$many = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$many[] = 'wp_t' . $i;
		}
		$this->assertCount( ExclusionRules::MAX_PATTERNS, ExclusionRules::normalise_patterns( $many ) );
	}

	public function test_a_bare_star_does_not_exclude_everything() {
		$rules = new ExclusionRules( 'wp_', false, array( '*' ) );
		$this->assertFalse( $rules->is_excluded_table( 'wp_posts' ) );
	}

	public function test_row_filter_for_the_options_table() {
		$rules  = new ExclusionRules( 'wp_' );
		$filter = $rules->row_filter( 'wp_options' );
		$this->assertSame(
			"LEFT(`option_name`, 4) <> 'ovb_' AND LEFT(`option_name`, 11) <> '_transient_' AND LEFT(`option_name`, 16) <> '_site_transient_'",
			$filter
		);
		$this->assertSame( '', $rules->row_filter( 'wp_posts' ) );
		$this->assertSame( '', $rules->row_filter( 'wp_2_options' ) );
		$this->assertSame( '', $rules->row_filter( 'wp_sitemeta' ) );
		$this->assertSame( '', $rules->row_filter( 'wp_options_backup' ) );
	}

	public function test_row_filter_prefix_lengths_match_the_prefixes() {
		foreach ( ExclusionRules::EXCLUDED_ROW_PREFIXES as $prefix ) {
			$rules = new ExclusionRules( 'wp_' );
			$this->assertStringContainsString( 'LEFT(`option_name`, ' . strlen( $prefix ) . ") <> '" . $prefix . "'", $rules->row_filter( 'wp_options' ) );
		}
	}

	public function test_row_filter_on_multisite() {
		$rules = new ExclusionRules( 'wp_', true );
		$this->assertNotSame( '', $rules->row_filter( 'wp_options' ) );
		$this->assertNotSame( '', $rules->row_filter( 'wp_7_options' ) );
		$this->assertStringContainsString( '`meta_key`', $rules->row_filter( 'wp_sitemeta' ) );
		$this->assertSame( '', $rules->row_filter( 'wp_7_sitemeta' ) );
		$this->assertSame( '', $rules->row_filter( 'wp_x_options' ) );
	}

	public function test_row_filter_with_a_hostile_prefix_is_still_not_a_filter_for_other_tables() {
		$rules = new ExclusionRules( "wp'_" );
		$this->assertSame( '', $rules->row_filter( 'wp_options' ) );
		$this->assertNotSame( '', $rules->row_filter( "wp'_options" ) );
	}
}
