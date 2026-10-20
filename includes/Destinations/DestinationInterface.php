<?php
/**
 * Contract every storage provider implements.
 *
 * Phase: MVP
 *
 * Purpose: Contract every storage provider implements.
 *
 * Responsibilities:
 *  - test_connection, upload (chunked, resumable), download (streamed), delete, list (for re-indexing), quota
 *  - Resumable by design: begin_upload() returns an opaque session (a JSON-safe array, stored in
 *    ovb_backup_files.upload_session after EVERY upload_chunk() call). upload_chunk() sends as much of the
 *    local file as the Budget allows, reading it in bounded chunks and never as a whole, and returns the
 *    new session. A run that is killed resumes from the last stored session.
 *  - A session may hold an upload id or session URL but never an access token, key or password.
 *  - Failures are DestinationException; retryable() tells the upload step whether to back off or give up.
 *    Messages must not contain secrets or absolute server paths.
 *  - Remote paths are relative, built by Destinations\RemotePath: OpenVault-Backups/<site-host>/<folder>/<file>.
 *    A destination must treat them as untrusted (no "..", no symlink escapes).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Destinations;

use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * A storage provider.
 */
interface DestinationInterface {

	/**
	 * Provider type slug (local, s3, gdrive ...).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Translatable provider name.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Check that the destination can be used (writes, reads back and removes a probe where that makes sense).
	 * Never throws.
	 *
	 * @return TestResult
	 */
	public function test_connection();

	/**
	 * Prepare the upload of one file.
	 *
	 * @param string              $remote_path Relative remote path (see RemotePath).
	 * @param int                 $size        Size of the local file in bytes.
	 * @param array<string,mixed> $options     Optional: sha256 (hex digest the stored file must match).
	 * @return array<string,mixed> Opaque, JSON-safe session.
	 * @throws DestinationException When the upload cannot be started.
	 */
	public function begin_upload( $remote_path, $size, array $options = array() );

	/**
	 * Send as much of the file as the budget allows (at least one chunk).
	 *
	 * @param array<string,mixed> $session    Session from begin_upload() or the previous call.
	 * @param string              $local_file Absolute path of the file being uploaded.
	 * @param Budget              $budget     Stop when exhausted.
	 * @return UploadProgress New session, bytes sent by this call, done flag and the remote reference once done.
	 * @throws DestinationException On failure.
	 */
	public function upload_chunk( array $session, $local_file, Budget $budget );

	/**
	 * Throw away a partial upload. Best effort, never throws.
	 *
	 * @param array<string,mixed> $session Session.
	 * @return void
	 */
	public function abort_upload( array $session );

	/**
	 * Stream a stored file in bounded chunks.
	 *
	 * @param string   $remote_ref Reference returned by upload_chunk().
	 * @param callable $sink       Called with each chunk (string); returning false stops the download.
	 * @return void
	 * @throws DestinationException On failure.
	 */
	public function download( $remote_ref, callable $sink );

	/**
	 * Delete a stored file (or a whole backup folder).
	 *
	 * @param string $remote_ref Reference returned by upload_chunk() or a folder below remote_root().
	 * @return void
	 * @throws DestinationException On failure.
	 */
	public function delete( $remote_ref );

	/**
	 * List stored files below a prefix, lazily (for re-indexing backups on a fresh install).
	 *
	 * @param string $remote_prefix Relative prefix ('' = everything below remote_root()).
	 * @param int    $limit         Most entries to return.
	 * @return iterable<int,array{ref:string,name:string,size:int,modified:int}>
	 * @throws DestinationException On failure.
	 */
	public function list( $remote_prefix = '', $limit = 10000 );

	/**
	 * Free and total space in bytes, or null when the provider cannot tell.
	 *
	 * @return array{free:int,total:int}|null
	 */
	public function quota();

	/**
	 * Top folder of all backups below the destination's base (normally RemotePath::ROOT).
	 *
	 * @return string
	 */
	public function remote_root();
}
