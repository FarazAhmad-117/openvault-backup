<?php
/**
 * Registers available providers; filter hook for third-party providers.
 *
 * Phase: MVP
 *
 * Purpose: Registers available providers; filter hook for third-party providers.
 *
 * Responsibilities:
 *  - register( type, factory ): a callable( settings, credentials, row ) returning a DestinationInterface, or the
 *    name of a class implementing it (constructed with ( settings, credentials ))
 *  - Built-in providers are registered here (local and s3; gdrive in a later step). The engine never
 *    special-cases a provider: it only asks create().
 *  - Filter "ovb_register_destinations" receives the map type => factory and may add, replace or remove entries
 *  - create( row ) is the ONLY place credentials are decrypted (DestinationsRepository::get_credentials()).
 *    Credentials that cannot be read raise DestinationException::needs_reconnect() so the caller can show
 *    "reconnect"; an unknown type raises a DestinationException with the reason "unknown_type".
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed translated strings or scrubbed errors; they are stored and shown through escaping output functions, never echoed as HTML here.

namespace OpenVault\Backup\Destinations;

use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Destinations\Local\LocalDestination;
use OpenVault\Backup\Destinations\S3\S3Destination;

defined( 'ABSPATH' ) || exit;

/**
 * Provider registry.
 */
class DestinationRegistry {

	/**
	 * Registered factories by type.
	 *
	 * @var array<string,callable|string>
	 */
	private $factories = array();

	/**
	 * Reads credentials: function( int $destination_id ): array{ok:bool,data:array,reason:?string}.
	 *
	 * @var callable|null
	 */
	private $credential_reader;

	/**
	 * Filter runner: function( array $factories ): array (null = WordPress apply_filters when it exists).
	 *
	 * @var callable|null
	 */
	private $filter;

	/**
	 * Constructor.
	 *
	 * @param callable|null $credential_reader Credential reader (defaults to DestinationsRepository::get_credentials()).
	 * @param callable|null $filter            Filter runner (defaults to the "ovb_register_destinations" filter).
	 * @param bool          $builtin           Register the built-in providers.
	 */
	public function __construct( ?callable $credential_reader = null, ?callable $filter = null, $builtin = true ) {
		$this->credential_reader = $credential_reader;
		$this->filter            = $filter;
		if ( $builtin ) {
			$this->register( 'local', LocalDestination::class );
			$this->register( 's3', S3Destination::class );
		}
	}

	/**
	 * Register (or replace) a provider.
	 *
	 * @param string          $type    Type slug (see DestinationsRepository::is_valid_type()).
	 * @param callable|string $factory Factory callable, or a class name implementing DestinationInterface.
	 * @return void
	 * @throws \InvalidArgumentException For an invalid type or factory.
	 */
	public function register( $type, $factory ) {
		if ( ! DestinationsRepository::is_valid_type( $type ) ) {
			throw new \InvalidArgumentException( 'Invalid destination type.' );
		}
		if ( ! self::is_valid_factory( $factory ) ) {
			throw new \InvalidArgumentException( 'Invalid destination factory.' );
		}
		$this->factories[ $type ] = $factory;
	}

	/**
	 * Type slugs of every available provider (after the filter).
	 *
	 * @return string[]
	 */
	public function types() {
		return array_keys( $this->factories() );
	}

	/**
	 * Whether a type is available.
	 *
	 * @param string $type Type slug.
	 * @return bool
	 */
	public function has( $type ) {
		return is_string( $type ) && isset( $this->factories()[ $type ] );
	}

	/**
	 * Build the provider of a destination row. Credentials are decrypted here and nowhere else.
	 *
	 * @param array<string,mixed> $row Row from DestinationsRepository (id, type, settings, status).
	 * @return DestinationInterface
	 * @throws DestinationException For an unknown type, unreadable credentials or a provider that cannot be built.
	 */
	public function create( array $row ) {
		$type      = isset( $row['type'] ) ? (string) $row['type'] : '';
		$id        = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$factories = $this->factories();

		if ( ! isset( $factories[ $type ] ) ) {
			throw new DestinationException( __( 'This destination type is not available. A plugin that provided it may have been deactivated.', 'openvault-backup' ), false, DestinationException::REASON_UNKNOWN_TYPE );
		}
		if ( isset( $row['status'] ) && DestinationsRepository::STATUS_NEEDS_RECONNECT === $row['status'] ) {
			throw DestinationException::needs_reconnect();
		}

		$credentials = $this->read_credentials( $id );
		$settings    = isset( $row['settings'] ) && is_array( $row['settings'] ) ? $row['settings'] : array();
		$factory     = $factories[ $type ];

		$destination = is_string( $factory ) && ! is_callable( $factory )
			? new $factory( $settings, $credentials )
			: call_user_func( $factory, $settings, $credentials, $row );

		if ( ! $destination instanceof DestinationInterface ) {
			throw DestinationException::permanent( __( 'The destination could not be created.', 'openvault-backup' ) );
		}
		return $destination;
	}

	/**
	 * The factory map after the filter; invalid entries are dropped.
	 *
	 * @return array<string,callable|string>
	 */
	private function factories() {
		$factories = $this->factories;

		if ( null !== $this->filter ) {
			$filtered = call_user_func( $this->filter, $factories );
		} elseif ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the storage providers. Add a type => factory entry to provide a new destination.
			 *
			 * @param array<string,callable|string> $factories Type slug => callable( settings, credentials, row ) or class name.
			 */
			$filtered = apply_filters( 'ovb_register_destinations', $factories );
		} else {
			$filtered = $factories;
		}

		$valid = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $type => $factory ) {
			if ( is_string( $type ) && DestinationsRepository::is_valid_type( $type ) && self::is_valid_factory( $factory ) ) {
				$valid[ $type ] = $factory;
			}
		}
		return $valid;
	}

	/**
	 * Decrypt the credentials of a destination.
	 *
	 * @param int $id Destination id.
	 * @return array<string,mixed>
	 * @throws DestinationException When they cannot be read.
	 */
	private function read_credentials( $id ) {
		$result = null === $this->credential_reader
			? ( new DestinationsRepository() )->get_credentials( $id )
			: call_user_func( $this->credential_reader, $id );

		if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
			if ( is_array( $result ) && isset( $result['reason'] ) && 'not_found' === $result['reason'] ) {
				throw DestinationException::permanent( __( 'The destination no longer exists.', 'openvault-backup' ) );
			}
			throw DestinationException::needs_reconnect();
		}
		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	}

	/**
	 * A factory is a callable or the name of a class that implements DestinationInterface.
	 *
	 * @param mixed $factory Factory.
	 * @return bool
	 */
	private static function is_valid_factory( $factory ) {
		if ( is_string( $factory ) && ! is_callable( $factory ) ) {
			return class_exists( $factory ) && is_subclass_of( $factory, DestinationInterface::class );
		}
		return is_callable( $factory );
	}
}
