<?php
/**
 * Outcome of one DestinationInterface::upload_chunk() call.
 *
 * Phase: MVP
 *
 * Purpose: Carries the new (opaque, JSON-safe) session back to the upload step, which stores it after every
 * call so a killed run resumes from the last saved point.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Upload progress.
 */
final class UploadProgress {

	/**
	 * Session to store.
	 *
	 * @var array<string,mixed>
	 */
	private $session;

	/**
	 * Bytes sent by this call.
	 *
	 * @var int
	 */
	private $bytes_sent;

	/**
	 * Whether the whole file is stored.
	 *
	 * @var bool
	 */
	private $done;

	/**
	 * Remote reference (set when done).
	 *
	 * @var string
	 */
	private $remote_ref;

	/**
	 * Use the factories.
	 *
	 * @param array<string,mixed> $session    Session.
	 * @param int                 $bytes_sent Bytes sent by this call.
	 * @param bool                $done       Finished.
	 * @param string              $remote_ref Remote reference.
	 */
	private function __construct( array $session, $bytes_sent, $done, $remote_ref ) {
		$this->session    = $session;
		$this->bytes_sent = max( 0, (int) $bytes_sent );
		$this->done       = $done;
		$this->remote_ref = $remote_ref;
	}

	/**
	 * More to send.
	 *
	 * @param array<string,mixed> $session    New session.
	 * @param int                 $bytes_sent Bytes sent by this call.
	 * @return self
	 */
	public static function partial( array $session, $bytes_sent ) {
		return new self( $session, $bytes_sent, false, '' );
	}

	/**
	 * The file is stored.
	 *
	 * @param array<string,mixed> $session    Final session.
	 * @param int                 $bytes_sent Bytes sent by this call.
	 * @param string              $remote_ref Reference used for download and delete (not empty).
	 * @return self
	 * @throws \InvalidArgumentException For an empty reference.
	 */
	public static function finished( array $session, $bytes_sent, $remote_ref ) {
		if ( ! is_string( $remote_ref ) || '' === $remote_ref ) {
			throw new \InvalidArgumentException( 'A finished upload needs a remote reference.' );
		}
		return new self( $session, $bytes_sent, true, $remote_ref );
	}

	/**
	 * Session.
	 *
	 * @return array<string,mixed>
	 */
	public function session() {
		return $this->session;
	}

	/**
	 * Bytes sent by this call.
	 *
	 * @return int
	 */
	public function bytes_sent() {
		return $this->bytes_sent;
	}

	/**
	 * Whether the file is stored.
	 *
	 * @return bool
	 */
	public function done() {
		return $this->done;
	}

	/**
	 * Remote reference ('' until done).
	 *
	 * @return string
	 */
	public function remote_ref() {
		return $this->remote_ref;
	}
}
