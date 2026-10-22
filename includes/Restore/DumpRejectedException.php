<?php
/**
 * A line of a database dump that the restore refuses to run.
 *
 * Phase: MVP
 *
 * Purpose: Thrown by DumpValidator. The message is one of a few fixed texts: it never contains the line, a value or a name from
 * the dump, so it can be shown and logged as it is. The caller adds the line number.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Rejected dump line.
 */
final class DumpRejectedException extends \RuntimeException {
}
