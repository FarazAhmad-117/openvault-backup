<?php
/**
 * Deactivation logic.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Unschedule all pending backup actions
 *  - Release any job locks; leave data intact (no locks exist yet, added with the engine)
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the plugin is deactivated. Never deletes data.
 */
class Deactivator {

	/**
	 * Action Scheduler group used by every OpenVault action.
	 */
	const AS_GROUP = 'openvault-backup';

	/**
	 * Deactivate.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
			as_unschedule_all_actions( '', array(), self::AS_GROUP );
		}
	}
}
