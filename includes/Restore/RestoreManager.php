<?php
/**
 * PLAN STUB — no implementation yet.
 * Phase: MVP
 *
 * Purpose: Orchestrates restore as a resumable job (see docs/plan/08-restore.md).
 *
 * Responsibilities:
 *  - Fetch -> verify checksum -> safety snapshot -> maintenance on -> restore DB/files -> maintenance off
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;
