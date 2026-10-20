<?php
/**
 * LockStore on top of the wp_options table.
 *
 * Phase: MVP
 *
 * Purpose: add_option() is not a safe mutex (it uses INSERT ... ON DUPLICATE KEY UPDATE after a cache
 * check), so this store talks to the table directly. The unique index on option_name makes
 * INSERT IGNORE atomic, and conditional DELETE/UPDATE give compare-and-swap semantics.
 * Rows use autoload "no" and the object cache entries are dropped after every write.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Options-table lock store.
 */
class OptionsLockStore implements LockStore {

	/**
	 * Create the entry if it does not exist.
	 *
	 * @param string $name  Option name.
	 * @param string $value Value.
	 * @return bool
	 */
	public function insert( $name, $value ) {
		global $wpdb;

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $name, $value, 'no' )
		);
		$this->forget( $name );

		return 1 === $rows;
	}

	/**
	 * Read the value straight from the database (never from the cache).
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	public function read( $name ) {
		global $wpdb;

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name )
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Delete if the value matches.
	 *
	 * @param string $name  Option name.
	 * @param string $value Expected value.
	 * @return bool
	 */
	public function delete_if( $name, $value ) {
		global $wpdb;

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $value )
		);
		$this->forget( $name );

		return 1 === $rows;
	}

	/**
	 * Update if the value matches.
	 *
	 * @param string $name Option name.
	 * @param string $old  Expected value.
	 * @param string $replacement New value.
	 * @return bool
	 */
	public function update_if( $name, $old, $replacement ) {
		global $wpdb;

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $replacement, $name, $old )
		);
		$this->forget( $name );

		return 1 === $rows;
	}

	/**
	 * Remove every job lock (used on deactivation). Returns the number of rows removed.
	 *
	 * @return int
	 */
	public function clear_all() {
		global $wpdb;

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( JobLock::OPTION_PREFIX ) . '%' )
		);
		wp_cache_delete( 'alloptions', 'options' );

		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Drop cached copies so get_option() never serves a stale lock.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	private function forget( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
