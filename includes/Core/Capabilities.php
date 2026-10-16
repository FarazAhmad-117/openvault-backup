<?php
/**
 * Single place for permission checks.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Map to manage_options (manage_network_options on multisite)
 *  - Helper: current_user_can_manage_backups()
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Capability checks for every page, AJAX and REST action.
 */
class Capabilities {

	/**
	 * Capability a user needs to manage backups.
	 *
	 * @return string
	 */
	public static function required() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Whether the current user may manage backups.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage_backups() {
		return current_user_can( self::required() );
	}
}
