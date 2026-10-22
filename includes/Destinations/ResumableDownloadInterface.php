<?php
/**
 * Optional capability of a destination: download a file starting at a byte offset.
 *
 * Phase: MVP
 *
 * Purpose: Lets a restore resume a half-downloaded part instead of starting it again. The restore fetch step uses it when
 * the destination implements it and otherwise falls back to DestinationInterface::download(), skipping the bytes it already has
 * (correct, but it re-reads them from the provider).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Download from an offset.
 */
interface ResumableDownloadInterface {

	/**
	 * Stream a stored file in bounded chunks, starting at $offset.
	 *
	 * @param string   $remote_ref Reference returned by upload_chunk().
	 * @param int      $offset     First byte to send (0 = the whole file). An offset at or past the end sends nothing.
	 * @param callable $sink       Called with each chunk (string); returning false stops the download.
	 * @return void
	 * @throws DestinationException On failure.
	 */
	public function download_from( $remote_ref, $offset, callable $sink );
}
