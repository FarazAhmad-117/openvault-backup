<?php
/**
 * Error raised while exporting the database.
 *
 * Phase: MVP
 *
 * Purpose: One exception type for every expected export failure (database error, file write error, unsafe
 * identifier). The message is shown to the user, so it never contains row data, credentials or queries.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Dump;

defined( 'ABSPATH' ) || exit;

/**
 * Export failure with a message that is safe to show and log.
 */
class DumpException extends \RuntimeException {
}
