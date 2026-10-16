<?php
/**
 * PLAN STUB — no implementation yet.
 * Phase: MVP
 *
 * Purpose: Runs when the plugin is deleted (not deactivated).
 *
 * Responsibilities:
 *  - Ask/obey setting 'delete data on uninstall' (default: keep)
 *  - Drop custom tables, delete options, unschedule actions
 *  - NEVER delete remote backups; optionally delete local backup folder
 */

defined( 'ABSPATH' ) || exit;
