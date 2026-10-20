<?php
/**
 * Adds files to the archive in batches via the chosen archiver.
 *
 * Phase: MVP
 *
 * Purpose: Adds files to archive in batches via chosen Archiver.
 *
 * Responsibilities:
 *  - Split into parts at configured size (Archive\Splitter, ovb_settings['part_size_mb'], default 250 MB)
 *  - Write manifest.json
 *  - Parts and manifest go into the final backup folder <local dir>/backups/<YYYY-MM-DD_HHMMSS>_<uuid8>/, not the
 *    work folder, so the cleanup step never removes them. The db part (db.part01.<ext>, entry "db/db.sql") comes first,
 *    then files.part01.<ext>, files.part02.<ext> ... built by walking <work dir>/files.list from a byte offset.
 *  - Resumable: the cursor holds the phase (db, files, finalize), the list offset, the part number, the files and
 *    load of the open part, the part size at the end of the last slice (begin_part() cuts the file back to it) and the
 *    position inside a file that was stopped half way (TAR.GZ only). Every finished part is saved at once as an
 *    ovb_backup_files row (destination 0, upload_status "done", sha256, size), so the cursor stays small.
 *  - A file that vanished, shrank, grew or became unreadable between the scan and now never aborts the backup (see
 *    the archivers). A part is read back and its entries counted before it counts as finished.
 *  - Finish: manifest.json (Support\Manifest) plus its own ovb_backup_files row, the backup row total_size (sum of
 *    the parts), the state meta "archive" for the later steps.
 *  - TAR.BZ2 is a later phase and is refused with a clear message.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Writes the backup folder and streams work files; failures become a failed step with a clear message.

namespace OpenVault\Backup\Engine\Steps;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Archive\ArchiverInterface;
use OpenVault\Backup\Archive\CapabilityDetector;
use OpenVault\Backup\Archive\Splitter;
use OpenVault\Backup\Archive\TarGzArchiver;
use OpenVault\Backup\Archive\ZipArchiver;
use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\Scan\ContentSelector;
use OpenVault\Backup\Engine\Scan\EntryName;
use OpenVault\Backup\Engine\Scan\LineReader;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Manifest;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Archive step.
 */
class ArchiveStep implements StepInterface {

	const MANIFEST_FILE   = 'manifest.json';
	const LOGGED_WARNINGS = 20;

	/**
	 * Backup file rows.
	 *
	 * @var BackupFilesRepository
	 */
	private $files;

	/**
	 * Backups.
	 *
	 * @var BackupsRepository
	 */
	private $backups;

	/**
	 * Constructor.
	 *
	 * @param BackupFilesRepository|null $files   File rows (defaults to the real table).
	 * @param BackupsRepository|null     $backups Backups (defaults to the real table).
	 */
	public function __construct( ?BackupFilesRepository $files = null, ?BackupsRepository $backups = null ) {
		$this->files   = null === $files ? new BackupFilesRepository() : $files;
		$this->backups = null === $backups ? new BackupsRepository() : $backups;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_ARCHIVE;
	}

	/**
	 * Archive as many files as the budget allows.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$backup   = $context->backup();
		$format   = isset( $backup['format'] ) ? (string) $backup['format'] : '';
		$archiver = self::archiver_for( $format );
		if ( null === $archiver ) {
			return StepResult::fail(
				CapabilityDetector::TAR_BZ2 === $format
					? __( 'TAR.BZ2 archives are planned for a later release. Choose ZIP or TAR.GZ for this backup.', 'openvault-backup' )
					: __( 'The archive format of this backup is not supported.', 'openvault-backup' ),
				$context->state()
			);
		}

		$meta  = $context->state()->get_meta();
		$db    = isset( $meta['db'] ) && is_array( $meta['db'] ) && isset( $meta['db']['file'], $meta['db']['size'] ) && 'db.sql' === $meta['db']['file'] ? $meta['db'] : null;
		$files = isset( $meta['files'] ) && is_array( $meta['files'] ) && isset( $meta['files']['list'], $meta['files']['roots'] ) && 'files.list' === $meta['files']['list'] ? $meta['files'] : null;
		if ( null === $db && null === $files ) {
			return StepResult::fail( __( 'There is nothing to archive: neither a database dump nor a file list was produced.', 'openvault-backup' ), $context->state() );
		}

		try {
			return $this->archive( $context, $archiver, $db, $files );
		} catch ( ArchiveException $e ) {
			return $this->failure( $context, $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			return $this->failure( $context, $e->getMessage() );
		}
	}

	/**
	 * The archiver of a format.
	 *
	 * @param string $format Format id.
	 * @return ArchiverInterface|null Null for tar.bz2 (a later phase) and unknown formats.
	 */
	public static function archiver_for( $format ) {
		switch ( $format ) {
			case CapabilityDetector::ZIP:
				return new ZipArchiver( false );
			case CapabilityDetector::ZIP_STORE:
				return new ZipArchiver( true );
			case CapabilityDetector::TAR_GZ:
				return new TarGzArchiver();
		}
		return null;
	}

	/**
	 * Read one record of files.list.
	 *
	 * @param string $line Line without the line break: "<size>\t<mtime>\t<entry name>".
	 * @return array{size:int,mtime:int,entry:string}|null Null for a damaged record.
	 */
	public static function parse_record( $line ) {
		$parts = explode( "\t", (string) $line, 3 );
		if ( 3 !== count( $parts ) || 1 !== preg_match( '/^\d{1,19}\z/', $parts[0] ) || 1 !== preg_match( '/^\d{1,19}\z/', $parts[1] ) || ! EntryName::is_safe( $parts[2] ) ) {
			return null;
		}
		return array(
			'size'  => (int) $parts[0],
			'mtime' => (int) $parts[1],
			'entry' => $parts[2],
		);
	}

	/**
	 * Manifest part list from the stored file rows (the local rows of one backup).
	 *
	 * @param array<int,array<string,mixed>> $rows Rows of BackupFilesRepository.
	 * @return array<int,array{name:string,size:int,sha256:string,kind:string,index:int}> Db part first, then files parts in order.
	 */
	public static function manifest_parts( array $rows ) {
		$parts = array();
		foreach ( $rows as $row ) {
			$parsed = Splitter::parse_part_name( (string) $row['file_name'] );
			if ( null === $parsed || 'done' !== $row['upload_status'] ) {
				continue;
			}
			$parts[] = array(
				'name'   => (string) $row['file_name'],
				'size'   => (int) $row['size'],
				'sha256' => (string) $row['checksum_sha256'],
				'kind'   => $parsed['kind'],
				'index'  => $parsed['index'],
			);
		}
		usort(
			$parts,
			static function ( $a, $b ) {
				if ( $a['kind'] !== $b['kind'] ) {
					return 'db' === $a['kind'] ? -1 : 1;
				}
				return $a['index'] - $b['index'];
			}
		);
		return $parts;
	}

	/**
	 * Safe contents flags for the manifest: boolean values under keys the manifest accepts.
	 *
	 * @param array<mixed> $contents Contents of the backup row.
	 * @return array<string,bool>
	 */
	public static function manifest_contents( array $contents ) {
		$out = array();
		foreach ( $contents as $key => $flag ) {
			if ( is_string( $key ) && 1 === preg_match( '/^[a-z0-9_]{1,40}\z/', $key ) ) {
				$out[ $key ] = (bool) $flag;
			}
		}
		return array_slice( $out, 0, 50, true );
	}

	/**
	 * One slice.
	 *
	 * @param JobContext               $context  Context.
	 * @param ArchiverInterface        $archiver Archiver.
	 * @param array<string,mixed>|null $db       Meta "db" of the export step, or null.
	 * @param array<string,mixed>|null $files    Meta "files" of the scan step, or null.
	 * @return StepResult
	 * @throws ArchiveException When an archive cannot be written.
	 * @throws \RuntimeException When a work file cannot be read.
	 */
	private function archive( JobContext $context, ArchiverInterface $archiver, $db, $files ) {
		$backup = $context->backup();
		$folder = Paths::backup_dir( (string) $backup['created_at'], (string) $backup['uuid'] );
		$cursor = self::read_cursor( $context->state()->get_cursor() );
		if ( null === $cursor ) {
			$cursor = $this->start( $context, null !== $db, null !== $files );
		}

		$run = array(
			'archiver' => $archiver,
			'folder'   => $folder,
			'open'     => false,
			'limit'    => Splitter::limit_bytes( $context->settings() ),
		);

		if ( 'db' === $cursor['phase'] ) {
			$result = $this->archive_db( $context, $cursor, $run, $db );
			if ( null !== $result ) {
				return $result;
			}
		}
		if ( 'files' === $cursor['phase'] ) {
			$result = $this->archive_files( $context, $cursor, $run, $files );
			if ( null !== $result ) {
				return $result;
			}
		}
		return $this->finalise( $context, $cursor, $run );
	}

	/**
	 * Starting cursor.
	 *
	 * @param JobContext $context   Context.
	 * @param bool       $has_db    Whether a dump exists.
	 * @param bool       $has_files Whether a file list exists.
	 * @return array<string,mixed>
	 */
	private function start( JobContext $context, $has_db, $has_files ) {
		$context->log( Logger::INFO, __( 'Archiving started.', 'openvault-backup' ), array( 'format' => $context->backup()['format'] ) );
		return array(
			'phase'          => $has_db ? 'db' : ( $has_files ? 'files' : 'finalize' ),
			'list_offset'    => 0,
			'part_kind'      => $has_db ? Splitter::KIND_DB : Splitter::KIND_FILES,
			'part_index'     => 1,
			'part_files'     => 0,
			'part_bytes'     => 0,
			'slice_end_size' => 0,
			'file_pos'       => 0,
			'parts_done'     => 0,
			'files_done'     => 0,
			'bytes_done'     => 0,
			'skipped'        => 0,
			'warned'         => 0,
		);
	}

	/**
	 * The database part: one file, "db/db.sql".
	 *
	 * @param JobContext               $context Context.
	 * @param array<string,mixed>      $cursor  Cursor, updated.
	 * @param array<string,mixed>      $run     Run data (archiver, folder, open, limit), updated.
	 * @param array<string,mixed>|null $db      Meta "db".
	 * @return StepResult|null A result to return now, or null when the phase is done.
	 * @throws ArchiveException When the part cannot be written.
	 */
	private function archive_db( JobContext $context, array &$cursor, array &$run, $db ) {
		if ( null === $db ) {
			$cursor['phase'] = 'files';
			return null;
		}
		$archiver = $run['archiver'];
		$path     = $context->work_dir() . '/' . $db['file'];
		$size     = (int) $db['size'];
		if ( ! is_file( $path ) || filesize( $path ) !== $size ) {
			throw new ArchiveException( 'The database dump in the work folder is missing or changed.' );
		}

		$this->open_part( $cursor, $run );
		$position = $archiver->add_file( $path, EntryName::DB_ENTRY, $size, (int) filemtime( $path ), (int) $cursor['file_pos'], $context->budget() );
		if ( $position < 0 ) {
			throw new ArchiveException( 'The database dump could not be read.' );
		}
		if ( $position < $size ) {
			$cursor['file_pos'] = $position;
			return $this->save( $context, $cursor, $run );
		}

		$cursor['file_pos']   = 0;
		$cursor['part_files'] = 1;
		$this->close_part( $context, $cursor, $run );
		$cursor['phase']       = 'files';
		$cursor['part_kind']   = Splitter::KIND_FILES;
		$cursor['part_index']  = 1;
		$cursor['list_offset'] = 0;

		return $context->budget()->exhausted() ? $this->save( $context, $cursor, $run ) : null;
	}

	/**
	 * The file parts: walk files.list from the saved offset.
	 *
	 * @param JobContext               $context Context.
	 * @param array<string,mixed>      $cursor  Cursor, updated.
	 * @param array<string,mixed>      $run     Run data, updated.
	 * @param array<string,mixed>|null $files   Meta "files".
	 * @return StepResult|null A result to return now, or null when the phase is done.
	 * @throws ArchiveException When a part cannot be written.
	 * @throws \RuntimeException When the file list cannot be read.
	 */
	private function archive_files( JobContext $context, array &$cursor, array &$run, $files ) {
		if ( null === $files ) {
			$cursor['phase'] = 'finalize';
			return null;
		}
		$archiver = $run['archiver'];
		$budget   = $context->budget();
		$roots    = is_array( $files['roots'] ) ? $files['roots'] : array();
		$list     = $context->work_dir() . '/' . $files['list'];
		$reader   = new LineReader( $list, (int) $cursor['list_offset'] );

		while ( true ) {
			$start = $reader->offset();
			$line  = $reader->next();
			if ( null === $line ) {
				break;
			}
			$record = self::parse_record( $line );
			$path   = null === $record ? null : ContentSelector::resolve( $roots, $record['entry'] );
			if ( null === $record || null === $path ) {
				$this->note( $context, $cursor, __( 'A record of the file list is damaged and is skipped.', 'openvault-backup' ), '' );
				$cursor['list_offset'] = $reader->offset();
				continue;
			}

			$resume_at = (int) $cursor['file_pos'];
			if ( 0 === $resume_at ) {
				$load = $run['open'] ? $archiver->size_so_far() : (int) $cursor['part_bytes'];
				if ( Splitter::should_roll( $load, (int) $cursor['part_files'], $record['size'], $run['limit'] ) ) {
					$this->open_part( $cursor, $run );
					$this->close_part( $context, $cursor, $run );
					++$cursor['part_index'];
				}
			}
			$this->open_part( $cursor, $run );

			$position = $archiver->add_file( $path, $record['entry'], $record['size'], $record['mtime'], $resume_at, $budget );
			$this->log_warnings( $context, $cursor, $archiver );
			if ( $position >= 0 && $position < $record['size'] ) {
				$cursor['file_pos']    = $position;
				$cursor['list_offset'] = $start;
				return $this->save( $context, $cursor, $run );
			}

			$cursor['file_pos']    = 0;
			$cursor['list_offset'] = $reader->offset();
			if ( $position < 0 ) {
				++$cursor['skipped'];
			} else {
				++$cursor['part_files'];
				++$cursor['files_done'];
				$cursor['bytes_done'] += $record['size'];
			}
			if ( $budget->exhausted() ) {
				return $this->save( $context, $cursor, $run );
			}
		}//end while
		$reader->close();

		if ( $cursor['part_files'] > 0 ) {
			// The part may be closed (saved at a slice end) or open: open_part() reopens it at the saved size.
			$this->open_part( $cursor, $run );
			$this->close_part( $context, $cursor, $run );
		} else {
			$this->discard_part( $cursor, $run );
		}
		$cursor['phase'] = 'finalize';
		return null;
	}

	/**
	 * Manifest, rows, totals and the result for the later steps.
	 *
	 * @param JobContext          $context Context.
	 * @param array<string,mixed> $cursor  Cursor.
	 * @param array<string,mixed> $run     Run data.
	 * @return StepResult
	 * @throws ArchiveException When the manifest cannot be written.
	 */
	private function finalise( JobContext $context, array $cursor, array $run ) {
		$backup    = $context->backup();
		$backup_id = (int) $backup['id'];
		$parts     = self::manifest_parts( $this->files->for_backup_and_destination( $backup_id, 0 ) );
		if ( array() === $parts ) {
			throw new ArchiveException( 'No archive part was produced.' );
		}

		global $wpdb;
		$site_url = (string) get_option( 'siteurl' );
		$manifest = Manifest::create(
			array(
				'uuid'           => (string) $backup['uuid'],
				'plugin_version' => defined( 'OVB_VERSION' ) ? OVB_VERSION : '0',
				'site_url'       => '' !== $site_url ? $site_url : (string) home_url(),
				'wp_version'     => (string) get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
				'table_prefix'   => (string) $wpdb->base_prefix,
				'contents'       => self::manifest_contents( is_array( $backup['contents'] ) ? $backup['contents'] : array() ),
				'format'         => (string) $backup['format'],
				'encrypted'      => false,
				'parts'          => $parts,
				'created_at'     => str_replace( ' ', 'T', (string) $backup['created_at'] ) . 'Z',
			)
		);
		$json     = $manifest->to_json();
		$path     = $run['folder'] . '/' . self::MANIFEST_FILE;
		$temp     = $path . '.tmp';
		if ( strlen( $json ) !== file_put_contents( $temp, $json ) || ! rename( $temp, $path ) ) {
			@unlink( $temp );
			throw new ArchiveException( 'The manifest could not be written.' );
		}
		$this->record_file( $backup_id, self::MANIFEST_FILE, 0, strlen( $json ), hash( 'sha256', $json ) );

		$total = $manifest->total_size();
		$this->backups->set_total_size( $backup_id, $total );

		$meta            = $context->state()->get_meta();
		$meta['archive'] = array(
			'folder'     => basename( $run['folder'] ),
			'format'     => (string) $backup['format'],
			'parts'      => count( $parts ),
			'files'      => $cursor['files_done'],
			'bytes'      => $cursor['bytes_done'],
			'skipped'    => $cursor['skipped'],
			'warnings'   => $cursor['warned'],
			'total_size' => $total,
		);

		$context->log(
			Logger::INFO,
			__( 'Archiving finished.', 'openvault-backup' ),
			array(
				'parts'      => count( $parts ),
				'files'      => $cursor['files_done'],
				'total_size' => $total,
			)
		);
		return StepResult::next_step( $context->state()->with_cursor( array() )->with_meta( $meta ) );
	}

	/**
	 * Open the current part (a new one, or continue at the saved size) unless it is open.
	 *
	 * @param array<string,mixed> $cursor Cursor.
	 * @param array<string,mixed> $run    Run data, updated.
	 * @return void
	 * @throws ArchiveException When the part cannot be opened.
	 */
	private function open_part( array $cursor, array &$run ) {
		if ( $run['open'] ) {
			return;
		}
		$archiver = $run['archiver'];
		$name     = Splitter::part_name( $cursor['part_kind'], (int) $cursor['part_index'], $archiver->extension() );
		$archiver->begin_part( $run['folder'] . '/' . $name, (int) $cursor['slice_end_size'], (int) $cursor['part_bytes'], (int) $cursor['part_files'] );
		$run['open'] = true;
	}

	/**
	 * Finish, check and record the open part and reset the part counters.
	 *
	 * @param JobContext          $context Context.
	 * @param array<string,mixed> $cursor  Cursor, updated.
	 * @param array<string,mixed> $run     Run data, updated.
	 * @return void
	 * @throws ArchiveException When the part is unreadable.
	 */
	private function close_part( JobContext $context, array &$cursor, array &$run ) {
		$archiver = $run['archiver'];
		$name     = Splitter::part_name( $cursor['part_kind'], (int) $cursor['part_index'], $archiver->extension() );
		$done     = $archiver->finish_part();
		$this->log_warnings( $context, $cursor, $archiver );

		$problem = $archiver->verify_part( $done['path'], (int) $cursor['part_files'] );
		if ( '' !== $problem ) {
			throw new ArchiveException( $name . ': ' . $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Part name we generated and a fixed text; escaped where displayed.
		}

		$this->record_file( (int) $context->backup_id(), $name, (int) $cursor['parts_done'] + 1, $done['size'], $done['sha256'] );
		$context->log(
			Logger::INFO,
			__( 'Archive part finished.', 'openvault-backup' ),
			array(
				'part'  => $name,
				'size'  => $done['size'],
				'files' => $cursor['part_files'],
			)
		);

		++$cursor['parts_done'];
		$cursor['part_files']     = 0;
		$cursor['part_bytes']     = 0;
		$cursor['slice_end_size'] = 0;
		$run['open']              = false;
	}

	/**
	 * Remove a part that received no file (every file of its range was skipped), open or not.
	 *
	 * @param array<string,mixed> $cursor Cursor.
	 * @param array<string,mixed> $run    Run data, updated.
	 * @return void
	 */
	private function discard_part( array &$cursor, array &$run ) {
		$archiver = $run['archiver'];
		$name     = Splitter::part_name( $cursor['part_kind'], (int) $cursor['part_index'], $archiver->extension() );
		$archiver->end_slice();
		@unlink( $run['folder'] . '/' . $name );
		$cursor['slice_end_size'] = 0;
		$cursor['part_bytes']     = 0;
		$run['open']              = false;
	}

	/**
	 * Store (or update) the row of a local file.
	 *
	 * @param int    $backup_id Backup id.
	 * @param string $name      File name.
	 * @param int    $number    Part number (0 for the manifest).
	 * @param int    $size      Size.
	 * @param string $sha256    Checksum.
	 * @return void
	 * @throws ArchiveException When the row cannot be stored.
	 */
	private function record_file( $backup_id, $name, $number, $size, $sha256 ) {
		foreach ( $this->files->for_backup_and_destination( $backup_id, 0 ) as $row ) {
			if ( $name === $row['file_name'] ) {
				// A run that was killed after this row was written and replays the part: replace, never duplicate.
				if ( ! $this->files->update_upload(
					$row['id'],
					'done',
					array(
						'size'            => $size,
						'checksum_sha256' => $sha256,
					)
				) ) {
					throw new ArchiveException( 'The part could not be recorded in the database.' );
				}
				return;
			}
		}
		$id = $this->files->add(
			array(
				'backup_id'       => $backup_id,
				'destination_id'  => 0,
				'part_number'     => $number,
				'file_name'       => $name,
				'size'            => $size,
				'checksum_sha256' => $sha256,
				'upload_status'   => 'done',
			)
		);
		if ( $id < 1 ) {
			throw new ArchiveException( 'The part could not be recorded in the database.' );
		}
	}

	/**
	 * Save the cursor after a slice that is not finished.
	 *
	 * @param JobContext          $context Context.
	 * @param array<string,mixed> $cursor  Cursor.
	 * @param array<string,mixed> $run     Run data.
	 * @return StepResult
	 * @throws ArchiveException When the part cannot be closed.
	 */
	private function save( JobContext $context, array $cursor, array $run ) {
		if ( $run['open'] ) {
			$archiver                 = $run['archiver'];
			$cursor['slice_end_size'] = $archiver->end_slice();
			$cursor['part_bytes']     = $archiver->size_so_far();
			$this->log_warnings( $context, $cursor, $archiver );
		}
		return StepResult::continue_step( $context->state()->with_cursor( $cursor ) );
	}

	/**
	 * Log what the archiver reports (the first 20 per backup) and count it.
	 *
	 * @param JobContext          $context  Context.
	 * @param array<string,mixed> $cursor   Cursor, updated.
	 * @param ArchiverInterface   $archiver Archiver.
	 * @return void
	 */
	private function log_warnings( JobContext $context, array &$cursor, ArchiverInterface $archiver ) {
		foreach ( $archiver->take_warnings() as $detail ) {
			$this->note( $context, $cursor, __( 'A file changed or disappeared while it was archived.', 'openvault-backup' ), $detail );
		}
	}

	/**
	 * Count a warning and log it while fewer than 20 were logged.
	 *
	 * @param JobContext          $context Context.
	 * @param array<string,mixed> $cursor  Cursor, updated.
	 * @param string              $message Message (English, no placeholders).
	 * @param string              $detail  Detail such as the entry name.
	 * @return void
	 */
	private function note( JobContext $context, array &$cursor, $message, $detail ) {
		if ( $cursor['warned'] < self::LOGGED_WARNINGS ) {
			$context->log( Logger::WARNING, $message, '' === $detail ? array() : array( 'detail' => $detail ) );
		}
		++$cursor['warned'];
	}

	/**
	 * Failed step result with a clear message.
	 *
	 * @param JobContext $context Context.
	 * @param string     $message Reason.
	 * @return StepResult
	 */
	private function failure( JobContext $context, $message ) {
		return StepResult::fail(
			sprintf(
				/* translators: %s: reason. */
				__( 'Archiving failed: %s', 'openvault-backup' ),
				$message
			),
			$context->state()
		);
	}

	/**
	 * Validate and complete a stored cursor.
	 *
	 * @param array<mixed> $cursor Stored cursor (empty on the first slice).
	 * @return array<string,mixed>|null Null for the first slice.
	 */
	private static function read_cursor( array $cursor ) {
		if ( ! isset( $cursor['phase'] ) || ! in_array( $cursor['phase'], array( 'db', 'files', 'finalize' ), true ) ) {
			return null;
		}
		$int = static function ( $key ) use ( $cursor ) {
			return isset( $cursor[ $key ] ) ? max( 0, (int) $cursor[ $key ] ) : 0;
		};
		return array(
			'phase'          => $cursor['phase'],
			'list_offset'    => $int( 'list_offset' ),
			'part_kind'      => isset( $cursor['part_kind'] ) && Splitter::KIND_DB === $cursor['part_kind'] ? Splitter::KIND_DB : Splitter::KIND_FILES,
			'part_index'     => max( 1, $int( 'part_index' ) ),
			'part_files'     => $int( 'part_files' ),
			'part_bytes'     => $int( 'part_bytes' ),
			'slice_end_size' => $int( 'slice_end_size' ),
			'file_pos'       => $int( 'file_pos' ),
			'parts_done'     => $int( 'parts_done' ),
			'files_done'     => $int( 'files_done' ),
			'bytes_done'     => $int( 'bytes_done' ),
			'skipped'        => $int( 'skipped' ),
			'warned'         => $int( 'warned' ),
		);
	}
}
