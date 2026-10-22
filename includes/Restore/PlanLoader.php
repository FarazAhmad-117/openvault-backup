<?php
/**
 * Loads the manifest of a backup and builds its RestorePlan from the real site.
 *
 * Phase: MVP
 *
 * Purpose: The thin, impure side of RestorePlan: it reads the file rows, looks at the local backup folder, finds a valid
 * manifest and calls RestorePlan::build(). The manifest is UNTRUSTED input (Manifest::from_json() is strict) and must
 * describe the same backup (uuid) as the database row.
 *
 * Where the manifest comes from, in this order: a copy in the restore work folder (written by an earlier slice), the local
 * backup folder, then each destination that holds a finished manifest.json (ascending destination id). Sources in the "tried"
 * list are skipped. A manifest read from a destination is saved into the work folder so later slices do not download it again.
 *
 * The result is { plan, manifest, error, newer }: a manifest that cannot be read leaves plan and manifest null and sets a
 * message; "newer" is true when a manifest is valid JSON of a newer schema (the backup was made by a newer plugin).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Backups\BackupFiles;
use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Destinations\DestinationException;
use OpenVault\Backup\Support\Manifest;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Plan loader.
 */
class PlanLoader {

	/**
	 * File rows.
	 *
	 * @var BackupFilesRepository
	 */
	private $files;

	/**
	 * Destination provider factory: function( int $destination_id ): DestinationInterface.
	 *
	 * @var callable
	 */
	private $destinations;

	/**
	 * Constructor.
	 *
	 * @param BackupFilesRepository|null $files        File rows.
	 * @param callable|null              $destinations Provider factory (defaults to DestinationFactory::make()).
	 */
	public function __construct( ?BackupFilesRepository $files = null, ?callable $destinations = null ) {
		$this->files        = null === $files ? new BackupFilesRepository() : $files;
		$this->destinations = null === $destinations ? array( new DestinationFactory(), 'make' ) : $destinations;
	}

	/**
	 * Find the manifest and build the plan.
	 *
	 * @param array<string,mixed> $backup   Backup row.
	 * @param string              $mode     full, db or files.
	 * @param int[]               $tried    Source ids that must not be used.
	 * @param string|null         $work_dir Restore work folder (null = no work folder yet).
	 * @return array{plan:RestorePlan|null,manifest:Manifest|null,error:string,newer:bool}
	 */
	public function load( array $backup, $mode, array $tried = array(), $work_dir = null ) {
		$rows  = $this->files->for_backup( (int) $backup['id'] );
		$local = $this->local_folder( $backup );

		$found = $this->find_manifest( $backup, $rows, $local, $tried, $work_dir );
		if ( null === $found['manifest'] ) {
			return array(
				'plan'     => null,
				'manifest' => null,
				'error'    => $found['error'],
				'newer'    => $found['newer'],
			);
		}

		try {
			$plan = RestorePlan::build( $backup, $rows, $found['manifest'], $mode, null === $local ? array() : self::list_files( $local ), $tried );
		} catch ( \InvalidArgumentException $e ) {
			return array(
				'plan'     => null,
				'manifest' => $found['manifest'],
				'error'    => __( 'The restore mode is not valid.', 'openvault-backup' ),
				'newer'    => false,
			);
		}

		return array(
			'plan'     => $plan,
			'manifest' => $found['manifest'],
			'error'    => '',
			'newer'    => false,
		);
	}

	/**
	 * Real path of the local backup folder, or null when it does not exist.
	 *
	 * @param array<string,mixed> $backup Backup row.
	 * @return string|null
	 */
	public function local_folder( array $backup ) {
		try {
			return BackupFiles::resolve_folder( Paths::backups_dir(), (string) $backup['created_at'], (string) $backup['uuid'] );
		} catch ( \RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * Parse an untrusted manifest.json and check it belongs to the backup.
	 *
	 * @param string $json JSON text.
	 * @param string $uuid Uuid of the backup row.
	 * @return array{manifest:Manifest|null,error:string,newer:bool}
	 */
	public static function parse( $json, $uuid ) {
		try {
			$manifest = Manifest::from_json( $json );
		} catch ( \InvalidArgumentException $e ) {
			$data  = is_string( $json ) && strlen( $json ) <= Manifest::MAX_BYTES ? json_decode( $json, true, 16 ) : null;
			$newer = is_array( $data ) && isset( $data['generator'], $data['schema'] ) && Manifest::GENERATOR === $data['generator'] && is_int( $data['schema'] ) && $data['schema'] > Manifest::SCHEMA;
			return array(
				'manifest' => null,
				'error'    => $newer
					? __( 'This backup was created by a newer version of OpenVault Backup. Update the plugin first, then restore it.', 'openvault-backup' )
					: __( 'The manifest of this backup is damaged or was not created by OpenVault Backup.', 'openvault-backup' ),
				'newer'    => $newer,
			);
		}

		if ( strtolower( (string) $uuid ) !== $manifest->get_uuid() ) {
			return array(
				'manifest' => null,
				'error'    => __( 'The manifest belongs to a different backup.', 'openvault-backup' ),
				'newer'    => false,
			);
		}
		return array(
			'manifest' => $manifest,
			'error'    => '',
			'newer'    => false,
		);
	}

	/**
	 * Regular files (no links) directly inside a folder, by name and size.
	 *
	 * @param string $folder Folder.
	 * @return array<string,int>
	 */
	public static function list_files( $folder ) {
		$out   = array();
		$names = @scandir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A folder that cannot be listed counts as empty.
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			$path = $folder . '/' . $name;
			if ( '.' !== $name && '..' !== $name && ! is_link( $path ) && is_file( $path ) ) {
				$out[ $name ] = (int) filesize( $path );
			}
		}
		return $out;
	}

	/**
	 * Find a valid manifest.
	 *
	 * @param array<string,mixed>            $backup   Backup row.
	 * @param array<int,array<string,mixed>> $rows     File rows.
	 * @param string|null                    $local    Local folder.
	 * @param int[]                          $tried    Skipped sources.
	 * @param string|null                    $work_dir Work folder.
	 * @return array{manifest:Manifest|null,error:string,newer:bool}
	 */
	private function find_manifest( array $backup, array $rows, $local, array $tried, $work_dir ) {
		$error = '';
		$uuid  = (string) $backup['uuid'];

		$candidates = array();
		if ( null !== $work_dir ) {
			$candidates[] = array( 'work', RestoreWorkDir::join( $work_dir, RestorePlan::MANIFEST_FILE ) );
		}
		if ( null !== $local && ! in_array( RestorePlan::SOURCE_LOCAL, $tried, true ) ) {
			$candidates[] = array( 'local', $local . '/' . RestorePlan::MANIFEST_FILE );
		}
		$destinations = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['destination_id'];
			if ( $id > 0 && RestorePlan::MANIFEST_FILE === $row['file_name'] && 'done' === $row['upload_status'] && '' !== $row['remote_ref'] && ! in_array( $id, $tried, true ) ) {
				$destinations[ $id ] = (string) $row['remote_ref'];
			}
		}
		ksort( $destinations );
		foreach ( $destinations as $id => $ref ) {
			$candidates[] = array( $id, $ref );
		}

		foreach ( $candidates as $candidate ) {
			$json = 'work' === $candidate[0] || 'local' === $candidate[0] ? self::read_local( $candidate[1] ) : $this->read_remote( (int) $candidate[0], $candidate[1] );
			if ( null === $json ) {
				continue;
			}
			$parsed = self::parse( $json, $uuid );
			if ( null === $parsed['manifest'] && $parsed['newer'] ) {
				// A newer plugin wrote this backup: no other copy of its manifest can be trusted to describe it either.
				return array(
					'manifest' => null,
					'error'    => $parsed['error'],
					'newer'    => true,
				);
			}
			if ( null === $parsed['manifest'] ) {
				$error = '' === $error ? $parsed['error'] : $error;
				continue;
			}
			if ( null !== $work_dir && is_int( $candidate[0] ) ) {
				self::save_copy( $work_dir, $json );
			}
			return array(
				'manifest' => $parsed['manifest'],
				'error'    => '',
				'newer'    => false,
			);
		}//end foreach

		return array(
			'manifest' => null,
			'error'    => '' !== $error ? $error : __( 'The manifest of this backup could not be found. Neither the local folder nor a destination has it.', 'openvault-backup' ),
			'newer'    => false,
		);
	}

	/**
	 * Read a small local file (never a link, never more than the manifest limit).
	 *
	 * @param string $path File.
	 * @return string|null
	 */
	private static function read_local( $path ) {
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > Manifest::MAX_BYTES ) {
			return null;
		}
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin, size checked above.
		return false === $json ? null : $json;
	}

	/**
	 * Download a manifest into memory (capped at the manifest size limit).
	 *
	 * @param int    $destination_id Destination id.
	 * @param string $ref            Remote reference.
	 * @return string|null Null when it cannot be read.
	 */
	private function read_remote( $destination_id, $ref ) {
		$buffer = '';
		try {
			$destination = call_user_func( $this->destinations, $destination_id );
			$destination->download(
				$ref,
				static function ( $chunk ) use ( &$buffer ) {
					$buffer .= $chunk;
					return strlen( $buffer ) <= Manifest::MAX_BYTES;
				}
			);
		} catch ( DestinationException $e ) {
			return null;
		}
		return strlen( $buffer ) > Manifest::MAX_BYTES ? null : $buffer;
	}

	/**
	 * Keep a downloaded manifest in the work folder (atomic write).
	 *
	 * @param string $work_dir Work folder.
	 * @param string $json     Manifest JSON.
	 * @return void
	 */
	private static function save_copy( $work_dir, $json ) {
		if ( ! is_dir( $work_dir ) ) {
			return;
		}
		$path = RestoreWorkDir::join( $work_dir, RestorePlan::MANIFEST_FILE );
		$temp = $path . '.tmp';
		if ( strlen( $json ) === file_put_contents( $temp, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small file in the plugin work folder.
			rename( $temp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same folder, atomic replace.
		} else {
			@unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Partial temp file of the plugin.
		}
	}
}
