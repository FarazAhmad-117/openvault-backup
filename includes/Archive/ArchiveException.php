<?php
/**
 * Error while writing or checking an archive part.
 *
 * Phase: MVP
 *
 * Purpose: Thrown by archivers; ArchiveStep turns it into a failed step with the message. Messages never hold
 * file contents or secrets.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Archive error.
 */
final class ArchiveException extends \RuntimeException {
}
