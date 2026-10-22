<?php
/**
 * What a restore needs and where it can get it (pure value object and builder, no WordPress, no disk access).
 *
 * Phase: MVP
 *
 * Purpose: Turns a backup row, its ovb_backup_files rows and its parsed manifest into a plan: which parts the chosen mode
 * needs, which sources hold a complete copy, which source is used, how much has to be downloaded and extracted, and the
 * facts the restore page shows before the user confirms.
 *
 * Modes and the parts they need (manifest part kinds):
 *  - full:  every part (database and files). A backup that has only one kind restores only that kind.
 *  - db:    the "db" parts.
 *  - files: the "files" and "other" parts.
 *
 * Sources and their preference (documented rule, see docs/plan/08-restore.md):
 *  1. The local copy (destination id 0): it is complete when every needed part and manifest.json exist in the local backup
 *     folder with the size the manifest records. Nothing is copied: the restore reads and verifies it in place.
 *  2. Then each destination in ascending destination id: it is complete when every needed part and manifest.json have a
 *     file row with upload_status "done" and a remote reference. A destination that holds only some files is ignored.
 *  A source that already failed verification or download (the "tried" list) is skipped, so a damaged local copy falls back
 *  to the next source once.
 *
 * Estimates (documented, deliberately generous):
 *  - download: the bytes of the needed files, only when the chosen source is not the local folder.
 *  - extract: database parts x 5 (SQL text compresses about 5:1), files parts x 2 (uploads are mostly incompressible,
 *    code compresses about 4:1), x 1 for the "zip-store" format.
 *  - snapshot: the safety snapshot is a backup of the database: the size of the needed database parts (0 for a files-only restore).
 *  - files: written straight into the site (no copy in the work folder): the files parts x 2 (x 1 for zip-store) as an upper bound. The undo
 *    journal's copies of overwritten files are covered by the same number (a file is either new or overwritten, never both), see journal_bytes().
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Support\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Restore plan.
 */
final class RestorePlan {

	const MODES = array( 'full', 'db', 'files' );

	const MANIFEST_FILE = 'manifest.json';

	const SOURCE_LOCAL = 0;

	const DB_FACTOR    = 5;
	const FILES_FACTOR = 2;

	/**
	 * Mode.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Facts about the backup.
	 *
	 * @var array<string,mixed>
	 */
	private $summary;

	/**
	 * Needed parts (manifest part arrays), database first.
	 *
	 * @var array<int,array{name:string,size:int,sha256:string,kind:string,index:int}>
	 */
	private $parts;

	/**
	 * Candidate sources by id: complete flag, reason, and the file list.
	 *
	 * @var array<int,array{id:int,complete:bool,tried:bool,reason:string,files:array<int,array<string,mixed>>}>
	 */
	private $sources;

	/**
	 * Id of the chosen source.
	 *
	 * @var int|null
	 */
	private $chosen;

	/**
	 * Use build().
	 *
	 * @param string                         $mode    Mode.
	 * @param array<string,mixed>            $summary Summary.
	 * @param array<int,array<string,mixed>> $parts   Needed parts.
	 * @param array<int,array<string,mixed>> $sources Sources.
	 * @param int|null                       $chosen  Chosen source id.
	 */
	private function __construct( $mode, array $summary, array $parts, array $sources, $chosen ) {
		$this->mode    = $mode;
		$this->summary = $summary;
		$this->parts   = $parts;
		$this->sources = $sources;
		$this->chosen  = $chosen;
	}

	/**
	 * Build the plan.
	 *
	 * @param array<string,mixed>            $backup      Backup row (BackupsRepository): id, uuid, trigger_type, created_at, encrypted.
	 * @param array<int,array<string,mixed>> $rows        Every file row of the backup (all destinations).
	 * @param Manifest                       $manifest    Parsed manifest.
	 * @param string                         $mode        full, db or files.
	 * @param array<string,int>              $local_files File name => size of the regular files that exist in the local backup folder.
	 * @param int[]                          $tried       Source ids that must not be used (they failed already).
	 * @return self
	 * @throws \InvalidArgumentException For an unknown mode.
	 */
	public static function build( array $backup, array $rows, Manifest $manifest, $mode, array $local_files, array $tried = array() ) {
		if ( ! is_string( $mode ) || ! in_array( $mode, self::MODES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown restore mode.' );
		}

		$parts = self::needed_parts( $manifest, $mode );

		$sources   = array();
		$sources[] = self::local_source( $parts, $rows, $local_files, in_array( self::SOURCE_LOCAL, $tried, true ) );

		$destinations = array();
		foreach ( $rows as $row ) {
			if ( (int) $row['destination_id'] > 0 ) {
				$destinations[ (int) $row['destination_id'] ] = true;
			}
		}
		ksort( $destinations );
		foreach ( array_keys( $destinations ) as $destination_id ) {
			$sources[] = self::remote_source( $destination_id, $parts, $rows, in_array( $destination_id, $tried, true ) );
		}

		$chosen = null;
		foreach ( $sources as $source ) {
			if ( $source['complete'] && ! $source['tried'] ) {
				$chosen = $source['id'];
				break;
			}
		}

		return new self( $mode, self::summarise( $backup, $manifest, $mode, $parts ), $parts, $sources, $chosen );
	}

	/**
	 * Mode.
	 *
	 * @return string
	 */
	public function mode() {
		return $this->mode;
	}

	/**
	 * Whether the mode restores the database (the backup has a database part and the mode asks for it).
	 *
	 * @return bool
	 */
	public function restores_db() {
		return $this->summary['restores_db'];
	}

	/**
	 * Whether the mode restores files.
	 *
	 * @return bool
	 */
	public function restores_files() {
		return $this->summary['restores_files'];
	}

	/**
	 * Whether the backup has what the mode asks for. A "db" restore of a backup without a database part (or a "files" restore
	 * of one without files) cannot work; a "full" restore needs at least one part.
	 *
	 * @return bool
	 */
	public function has_parts_for_mode() {
		if ( 'db' === $this->mode ) {
			return $this->summary['db_parts'] > 0;
		}
		if ( 'files' === $this->mode ) {
			return $this->summary['files_parts'] > 0;
		}
		return array() !== $this->parts;
	}

	/**
	 * Parts the mode needs.
	 *
	 * @return array<int,array{name:string,size:int,sha256:string,kind:string,index:int}>
	 */
	public function parts() {
		return $this->parts;
	}

	/**
	 * Candidate sources in preference order.
	 *
	 * @return array<int,array{id:int,complete:bool,tried:bool,reason:string,files:array<int,array<string,mixed>>}>
	 */
	public function sources() {
		return $this->sources;
	}

	/**
	 * The source the restore reads from, or null when no complete copy is left.
	 *
	 * @return int|null 0 = the local backup folder, otherwise a destination id.
	 */
	public function chosen_source() {
		return $this->chosen;
	}

	/**
	 * Whether the chosen source is the local backup folder (read in place, nothing is copied).
	 *
	 * @return bool
	 */
	public function is_local() {
		return self::SOURCE_LOCAL === $this->chosen;
	}

	/**
	 * Ids of the sources a restore may still use besides the chosen one, in preference order.
	 *
	 * @return int[]
	 */
	public function fallback_sources() {
		$ids = array();
		foreach ( $this->sources as $source ) {
			if ( $source['complete'] && ! $source['tried'] && $source['id'] !== $this->chosen ) {
				$ids[] = $source['id'];
			}
		}
		return $ids;
	}

	/**
	 * Files to have at hand for a source: manifest.json first, then the needed parts in order.
	 *
	 * Each entry: name, size, sha256 ('' when unknown), kind ('manifest' or the part kind), ref (remote reference, '' for the
	 * local folder) and row_sha256 (checksum stored in the database row, '' when none).
	 *
	 * @param int|null $source_id Source id (null = the chosen source).
	 * @return array<int,array<string,mixed>>
	 */
	public function files( $source_id = null ) {
		$source_id = null === $source_id ? $this->chosen : $source_id;
		foreach ( $this->sources as $source ) {
			if ( $source['id'] === $source_id ) {
				return $source['files'];
			}
		}
		return array();
	}

	/**
	 * Bytes to download from a destination (0 when the chosen source is the local folder or there is none).
	 *
	 * @return int
	 */
	public function download_bytes() {
		if ( null === $this->chosen || self::SOURCE_LOCAL === $this->chosen ) {
			return 0;
		}
		return $this->parts_bytes() + $this->manifest_bytes();
	}

	/**
	 * Estimated bytes the extracted data takes (see the class comment for the factors).
	 *
	 * @return int
	 */
	public function extract_bytes() {
		$store = 'zip-store' === $this->summary['format'];
		$total = 0;
		foreach ( $this->parts as $part ) {
			$factor = $store ? 1 : ( 'db' === $part['kind'] ? self::DB_FACTOR : self::FILES_FACTOR );
			$total += $part['size'] * $factor;
		}
		return $total;
	}

	/**
	 * Estimated size of the safety snapshot: the database parts when the mode restores the database (the snapshot is a database backup), 0 for
	 * a files-only restore (the undo journal is its recoverable state, see journal_bytes()).
	 *
	 * @return int
	 */
	public function snapshot_bytes() {
		if ( ! $this->summary['restores_db'] ) {
			return 0;
		}
		$total = 0;
		foreach ( $this->parts as $part ) {
			if ( 'db' === $part['kind'] ) {
				$total += $part['size'];
			}
		}
		return $total;
	}

	/**
	 * Upper bound of the undo journal's copies of overwritten files. Not known up front (it depends on which files exist now), so it is the
	 * uncompressed estimate of the files parts: every file is either NEW (it grows the site) or OVERWRITTEN (its original is copied), never both,
	 * so this one number covers the growth of the site and the journal together. It is already part of extract_bytes(); it is shown separately for
	 * the restore page.
	 *
	 * @return int
	 */
	public function journal_bytes() {
		if ( ! $this->summary['restores_files'] ) {
			return 0;
		}
		$store = 'zip-store' === $this->summary['format'];
		$total = 0;
		foreach ( $this->parts as $part ) {
			if ( 'db' !== $part['kind'] ) {
				$total += $part['size'] * ( $store ? 1 : self::FILES_FACTOR );
			}
		}
		return $total;
	}

	/**
	 * Free space the restore needs: download + extract + safety snapshot + a margin.
	 *
	 * @param int $margin Extra bytes to keep free.
	 * @return int
	 */
	public function required_free_bytes( $margin = 0 ) {
		return $this->download_bytes() + $this->extract_bytes() + $this->snapshot_bytes() + max( 0, (int) $margin );
	}

	/**
	 * Facts for the restore page ("what will be restored") and for the preflight.
	 *
	 * @return array<string,mixed>
	 */
	public function summary() {
		return $this->summary;
	}

	/**
	 * Plain array form (for the UI and the REST API later).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		$sources = array();
		foreach ( $this->sources as $source ) {
			$sources[] = array(
				'id'       => $source['id'],
				'complete' => $source['complete'],
				'tried'    => $source['tried'],
				'reason'   => $source['reason'],
			);
		}
		return array(
			'mode'            => $this->mode,
			'summary'         => $this->summary,
			'sources'         => $sources,
			'chosen_source'   => $this->chosen,
			'fallbacks'       => $this->fallback_sources(),
			'download_bytes'  => $this->download_bytes(),
			'extract_bytes'   => $this->extract_bytes(),
			'snapshot_bytes'  => $this->snapshot_bytes(),
			'journal_bytes'   => $this->journal_bytes(),
			'files_to_verify' => count( $this->files() ),
		);
	}

	/**
	 * Parts a mode needs, database first.
	 *
	 * @param Manifest $manifest Manifest.
	 * @param string   $mode     Mode.
	 * @return array<int,array{name:string,size:int,sha256:string,kind:string,index:int}>
	 */
	private static function needed_parts( Manifest $manifest, $mode ) {
		$out = array();
		foreach ( $manifest->get_parts() as $part ) {
			$is_db = 'db' === $part['kind'];
			if ( 'full' === $mode || ( 'db' === $mode && $is_db ) || ( 'files' === $mode && ! $is_db ) ) {
				$out[] = $part;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['kind'] !== $b['kind'] ) {
					return 'db' === $a['kind'] ? -1 : 1;
				}
				return $a['index'] - $b['index'];
			}
		);
		return $out;
	}

	/**
	 * The local copy.
	 *
	 * @param array<int,array<string,mixed>> $parts       Needed parts.
	 * @param array<int,array<string,mixed>> $rows        All file rows.
	 * @param array<string,int>              $local_files Files on disk.
	 * @param bool                           $tried       Already failed.
	 * @return array{id:int,complete:bool,tried:bool,reason:string,files:array<int,array<string,mixed>>}
	 */
	private static function local_source( array $parts, array $rows, array $local_files, $tried ) {
		$by_name = self::rows_by_name( $rows, self::SOURCE_LOCAL );
		$reason  = '';
		$files   = array();

		if ( ! isset( $local_files[ self::MANIFEST_FILE ] ) ) {
			$reason = 'manifest_missing';
		}
		$files[] = self::file_entry( self::MANIFEST_FILE, isset( $local_files[ self::MANIFEST_FILE ] ) ? (int) $local_files[ self::MANIFEST_FILE ] : 0, isset( $by_name[ self::MANIFEST_FILE ]['checksum_sha256'] ) ? $by_name[ self::MANIFEST_FILE ]['checksum_sha256'] : '', 'manifest', '', $by_name );

		foreach ( $parts as $part ) {
			if ( '' === $reason ) {
				if ( ! isset( $local_files[ $part['name'] ] ) ) {
					$reason = 'part_missing';
				} elseif ( (int) $local_files[ $part['name'] ] !== $part['size'] ) {
					$reason = 'part_size';
				}
			}
			$files[] = self::file_entry( $part['name'], $part['size'], $part['sha256'], $part['kind'], '', $by_name );
		}

		return array(
			'id'       => self::SOURCE_LOCAL,
			'complete' => '' === $reason,
			'tried'    => (bool) $tried,
			'reason'   => $reason,
			'files'    => $files,
		);
	}

	/**
	 * A destination copy.
	 *
	 * @param int                            $destination_id Destination id.
	 * @param array<int,array<string,mixed>> $parts          Needed parts.
	 * @param array<int,array<string,mixed>> $rows           All file rows.
	 * @param bool                           $tried          Already failed.
	 * @return array{id:int,complete:bool,tried:bool,reason:string,files:array<int,array<string,mixed>>}
	 */
	private static function remote_source( $destination_id, array $parts, array $rows, $tried ) {
		$by_name = self::rows_by_name( $rows, $destination_id );
		$reason  = '';
		$wanted  = array( array( self::MANIFEST_FILE, 'manifest', 0, '' ) );
		foreach ( $parts as $part ) {
			$wanted[] = array( $part['name'], $part['kind'], $part['size'], $part['sha256'] );
		}

		$files = array();
		foreach ( $wanted as $item ) {
			$row = isset( $by_name[ $item[0] ] ) ? $by_name[ $item[0] ] : null;
			if ( '' === $reason ) {
				if ( null === $row || 'done' !== $row['upload_status'] || '' === (string) $row['remote_ref'] ) {
					$reason = self::MANIFEST_FILE === $item[0] ? 'manifest_missing' : 'part_missing';
				} elseif ( 'manifest' !== $item[1] && (int) $row['size'] !== (int) $item[2] ) {
					$reason = 'part_size';
				}
			}
			$size    = 'manifest' === $item[1] ? ( null === $row ? 0 : (int) $row['size'] ) : (int) $item[2];
			$sha     = 'manifest' === $item[1] ? ( null === $row ? '' : (string) $row['checksum_sha256'] ) : (string) $item[3];
			$files[] = self::file_entry( $item[0], $size, $sha, $item[1], null === $row ? '' : (string) $row['remote_ref'], $by_name );
		}

		return array(
			'id'       => (int) $destination_id,
			'complete' => '' === $reason,
			'tried'    => (bool) $tried,
			'reason'   => $reason,
			'files'    => $files,
		);
	}

	/**
	 * One file entry.
	 *
	 * @param string                            $name    File name.
	 * @param int                               $size    Expected size.
	 * @param string                            $sha256  Expected checksum ('' when unknown).
	 * @param string                            $kind    manifest, db, files or other.
	 * @param string                            $ref     Remote reference ('' for the local folder).
	 * @param array<string,array<string,mixed>> $by_name Rows of the source by file name.
	 * @return array<string,mixed>
	 */
	private static function file_entry( $name, $size, $sha256, $kind, $ref, array $by_name ) {
		return array(
			'name'       => $name,
			'size'       => (int) $size,
			'sha256'     => (string) $sha256,
			'kind'       => $kind,
			'ref'        => $ref,
			'row_sha256' => isset( $by_name[ $name ]['checksum_sha256'] ) ? (string) $by_name[ $name ]['checksum_sha256'] : '',
		);
	}

	/**
	 * Rows of one destination by file name.
	 *
	 * @param array<int,array<string,mixed>> $rows           Rows.
	 * @param int                            $destination_id Destination id.
	 * @return array<string,array<string,mixed>>
	 */
	private static function rows_by_name( array $rows, $destination_id ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( (int) $row['destination_id'] === (int) $destination_id ) {
				$out[ (string) $row['file_name'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Facts about the backup and what the mode restores.
	 *
	 * @param array<string,mixed>            $backup   Backup row.
	 * @param Manifest                       $manifest Manifest.
	 * @param string                         $mode     Mode.
	 * @param array<int,array<string,mixed>> $parts    Needed parts.
	 * @return array<string,mixed>
	 */
	private static function summarise( array $backup, Manifest $manifest, $mode, array $parts ) {
		$db_parts    = 0;
		$files_parts = 0;
		$db_bytes    = 0;
		$files_bytes = 0;
		foreach ( $manifest->get_parts() as $part ) {
			if ( 'db' === $part['kind'] ) {
				++$db_parts;
				$db_bytes += $part['size'];
			} else {
				++$files_parts;
				$files_bytes += $part['size'];
			}
		}

		return array(
			'backup_id'      => isset( $backup['id'] ) ? (int) $backup['id'] : 0,
			'uuid'           => $manifest->get_uuid(),
			'created_at'     => $manifest->get_created_at(),
			'trigger'        => isset( $backup['trigger_type'] ) ? (string) $backup['trigger_type'] : '',
			'format'         => $manifest->get_format(),
			'encrypted'      => $manifest->is_encrypted(),
			'plugin_version' => $manifest->get_plugin_version(),
			'wp_version'     => $manifest->get_wp_version(),
			'php_version'    => $manifest->get_php_version(),
			'site_url'       => $manifest->get_site_url(),
			'table_prefix'   => $manifest->get_table_prefix(),
			'contents'       => $manifest->get_contents(),
			'mode'           => $mode,
			'db_parts'       => $db_parts,
			'files_parts'    => $files_parts,
			'db_bytes'       => $db_bytes,
			'files_bytes'    => $files_bytes,
			'restores_db'    => 'files' !== $mode && $db_parts > 0,
			'restores_files' => 'db' !== $mode && $files_parts > 0,
			'needed_parts'   => count( $parts ),
			'needed_bytes'   => (int) array_sum( array_column( $parts, 'size' ) ),
		);
	}

	/**
	 * Bytes of the needed parts.
	 *
	 * @return int
	 */
	private function parts_bytes() {
		return (int) array_sum( array_column( $this->parts, 'size' ) );
	}

	/**
	 * Bytes of manifest.json (as recorded for the chosen source).
	 *
	 * @return int
	 */
	private function manifest_bytes() {
		foreach ( $this->files() as $file ) {
			if ( 'manifest' === $file['kind'] ) {
				return (int) $file['size'];
			}
		}
		return 0;
	}
}
