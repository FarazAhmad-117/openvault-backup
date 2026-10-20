<?php
/**
 * Storage primitive behind JobLock.
 *
 * Phase: MVP
 *
 * Purpose: Four atomic operations a lock needs. The production implementation is OptionsLockStore
 * (wp_options); unit tests use an in-memory fake.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic key/value operations.
 */
interface LockStore {

	/**
	 * Create the entry only if it does not exist.
	 *
	 * @param string $name  Key.
	 * @param string $value Value.
	 * @return bool True when this call created it (exactly one concurrent caller can win).
	 */
	public function insert( $name, $value );

	/**
	 * Read the current value.
	 *
	 * @param string $name Key.
	 * @return string|null Null when missing.
	 */
	public function read( $name );

	/**
	 * Delete the entry only if it still holds $value.
	 *
	 * @param string $name  Key.
	 * @param string $value Expected value.
	 * @return bool True when this call deleted it.
	 */
	public function delete_if( $name, $value );

	/**
	 * Replace the value only if it still equals $old.
	 *
	 * @param string $name Key.
	 * @param string $old  Expected value.
	 * @param string $replacement New value.
	 * @return bool True when this call changed it.
	 */
	public function update_if( $name, $old, $replacement );
}
