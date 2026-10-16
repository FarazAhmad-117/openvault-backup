<?php
/**
 * PLAN STUB — no implementation yet.
 * Phase: MVP
 *
 * Purpose: Serializable state of a running job.
 *
 * Responsibilities:
 *  - Current step, cursor (table/row offset, file index, upload chunk offset)
 *  - Stored in ovb_backups.state JSON
 */

namespace OpenVault\Backup\Engine;

defined( 'ABSPATH' ) || exit;
