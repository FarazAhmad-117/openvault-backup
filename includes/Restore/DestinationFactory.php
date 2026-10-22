<?php
/**
 * Builds the provider of a destination for the restore code.
 *
 * Phase: MVP
 *
 * Purpose: Turns a destination id into a DestinationInterface. Credentials are decrypted only inside
 * DestinationRegistry::create(), never here. A destination that no longer exists, whose credentials cannot be read
 * (needs a reconnect) or whose type is unavailable raises a DestinationException.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings or scrubbed errors; they are stored and shown through escaping output functions, never echoed as HTML here.

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Destinations\DestinationInterface;
use OpenVault\Backup\Destinations\DestinationRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Destination factory.
 */
class DestinationFactory {

	/**
	 * Destinations.
	 *
	 * @var DestinationsRepository
	 */
	private $destinations;

	/**
	 * Providers.
	 *
	 * @var DestinationRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param DestinationsRepository|null $destinations Destination rows.
	 * @param DestinationRegistry|null    $registry     Providers.
	 */
	public function __construct( ?DestinationsRepository $destinations = null, ?DestinationRegistry $registry = null ) {
		$this->destinations = null === $destinations ? new DestinationsRepository() : $destinations;
		$this->registry     = null === $registry ? new DestinationRegistry() : $registry;
	}

	/**
	 * Build the provider of a destination.
	 *
	 * @param int $destination_id Destination id (>= 1).
	 * @return DestinationInterface
	 * @throws DestinationException When it no longer exists, its credentials cannot be read or its type is unavailable.
	 */
	public function make( $destination_id ) {
		$row = $this->destinations->find( (int) $destination_id );
		if ( null === $row ) {
			throw DestinationException::permanent( __( 'The destination no longer exists.', 'openvault-backup' ) );
		}
		return $this->registry->create( $row );
	}
}
