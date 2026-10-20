<?php
/**
 * Deactivation logic.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Unschedule all pending backup actions (this includes the recurring stalled-job scan)
 *  - Release any job locks; leave data intact
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Core;

use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Engine\OptionsLockStore;

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

		// A job that was running cannot continue while the plugin is off, so its lock is released.
		( new OptionsLockStore() )->clear_all();

		// Forget the "recovery action is scheduled" check so reactivation schedules it again.
		delete_option( JobRunner::OPTION_RECOVERY_CHECKED );
	}
}
