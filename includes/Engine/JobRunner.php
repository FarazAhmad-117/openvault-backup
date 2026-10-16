<?php
/**
 * PLAN STUB — no implementation yet.
 * Phase: MVP
 *
 * Purpose: Resumable step-based job executor (see docs/plan/04-backup-engine.md).
 *
 * Responsibilities:
 *  - Acquire lock, load JobState, run current step until time/memory budget hit
 *  - Persist progress, reschedule continuation via Action Scheduler
 *  - Handle failure, retries, final status, notifications
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;
