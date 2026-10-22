<?php
/**
 * Restores the files of the file parts of a backup: safe, resumable, journaled.
 *
 * Phase: MVP
 *
 * Purpose: One slice of the "file_restore" step. It walks the file parts in order (Archive\EntryWalker), asks Restore\ExtractionPolicy what to do with
 * every entry and lets Restore\SafeWriter do it. Free of WordPress (the step supplies the real policy and store), so it is unit tested on real archives.
 *
 * Semantics: files that exist in the backup are OVERWRITTEN, missing ones are ADDED. Nothing on the site is deleted (files that exist now but not in
 * the backup stay: safe, but leftovers of later installs remain). Only the roots the backup contains are touched. Protected paths (the plugin's own
 * folder, its storage folder, wp-config.php, .maintenance, server configuration files) and anything unsafe are skipped and counted by reason.
 *
 * Per file that is written:
 *  1. The destination folders are walked (no symlink is followed); each folder that has to be created is written to the journal first ("D").
 *  2. The body is streamed in 256 KB chunks into "<name>.ovbtmp<token>" next to the target. When the Budget is used up the slice stops in the middle
 *     of the file and the cursor remembers how many bytes are in the temp file ("o"); the next slice reads the part again, skips the entries
 *     that are done, skips those bytes and goes on.
 *  3. When the temp file is complete: an existing target is copied to the undo store first (streamed, SHA-256) and the journal gets "O"; a new
 *     target gets "C". The journal line is flushed BEFORE the file is renamed over the target (write-ahead).
 *  4. rename() replaces the target atomically; the modification time of the archive is kept.
 *
 * Resume cost: a tar.gz part cannot seek, so a slice that starts in the middle of a part decompresses the entries before it again (counted, not
 * written). Entries that were completed in a slice that died before it saved its cursor are simply done again: same content, and the journal keeps
 * the FIRST saved original (see UndoJournal).
 *
 * Cursor (small): p part index, e entries done in the part, o bytes of the entry in progress that are in its temp file, fl files written, by bytes
 * written, cr created, ow overwritten, sk map reason => count, ef failures in a row, lg lines logged, sj skip lines journaled.
 *
 * Errors: a single entry that cannot be written is counted (write_failed, dest_symlink ...) and the restore goes on. FileRestoreException (disk
 * full, journal not writable, 25 write failures in a row, a part that changed) and ArchiveException (a damaged part) stop it.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated texts; they are escaped where they are displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streams are closed explicitly.

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Archive\EntryWalker;
use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * File restorer.
 */
final class FileRestorer {

	const PERSIST_EVERY = 50;
	const MAX_LOGGED    = 50;
	const MAX_FAILURES  = 25;
	const STRING_CHUNK  = 262144;

	/**
	 * Policy.
	 *
	 * @var ExtractionPolicy
	 */
	private $policy;

	/**
	 * Writer.
	 *
	 * @var SafeWriter
	 */
	private $writer;

	/**
	 * Undo store.
	 *
	 * @var UndoStore
	 */
	private $store;

	/**
	 * Called when a part is entered for the first time: function( array $part, int $index ): string ('' = fine, else the reason to stop).
	 *
	 * @var callable|null
	 */
	private $part_check;

	/**
	 * Entries between two saved cursors.
	 *
	 * @var int
	 */
	private $persist_every;

	/**
	 * Token of the temp file names.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param ExtractionPolicy $policy        Policy.
	 * @param SafeWriter       $writer        Writer.
	 * @param UndoStore        $store         Undo store (its journal must be open).
	 * @param string           $token         Hex token for the temp file names (the same in every slice, so a killed run's temp file is found again).
	 * @param callable|null    $part_check    Part check hook.
	 * @param int              $persist_every Entries between two saved cursors.
	 */
	public function __construct( ExtractionPolicy $policy, SafeWriter $writer, UndoStore $store, $token, ?callable $part_check = null, $persist_every = self::PERSIST_EVERY ) {
		$this->policy        = $policy;
		$this->writer        = $writer;
		$this->store         = $store;
		$this->token         = (string) preg_replace( '/[^0-9a-f]/', '', strtolower( (string) $token ) );
		$this->part_check    = $part_check;
		$this->persist_every = max( 1, (int) $persist_every );
	}

	/**
	 * A new cursor.
	 *
	 * @return array<string,mixed>
	 */
	public static function new_cursor() {
		return array(
			'p'  => 0,
			'e'  => 0,
			'o'  => 0,
			'fl' => 0,
			'by' => 0,
			'cr' => 0,
			'ow' => 0,
			'sk' => array(),
			'ef' => 0,
			'lg' => 0,
			'sj' => 0,
		);
	}

	/**
	 * Sanitise a stored cursor.
	 *
	 * @param array<mixed> $cursor Stored cursor.
	 * @return array<string,mixed>
	 */
	public static function normalise_cursor( array $cursor ) {
		$out = self::new_cursor();
		foreach ( array( 'p', 'e', 'o', 'fl', 'by', 'cr', 'ow', 'ef', 'lg', 'sj' ) as $key ) {
			if ( isset( $cursor[ $key ] ) && is_numeric( $cursor[ $key ] ) ) {
				$out[ $key ] = max( 0, (int) $cursor[ $key ] );
			}
		}
		if ( isset( $cursor['sk'] ) && is_array( $cursor['sk'] ) ) {
			foreach ( $cursor['sk'] as $reason => $count ) {
				if ( is_string( $reason ) && 1 === preg_match( '/^[a-z_]{1,30}\z/', $reason ) && is_numeric( $count ) ) {
					$out['sk'][ $reason ] = max( 0, (int) $count );
				}
			}
		}
		return $out;
	}

	/**
	 * Run one slice.
	 *
	 * @param array<int,array{kind:string,path:string,name:string}> $parts   File parts in order (kind "zip" or "tar.gz", absolute path).
	 * @param array<mixed>                                          $cursor  Stored cursor (empty to start).
	 * @param Budget                                                $budget  Budget.
	 * @param callable|null                                         $persist function( array $cursor ): void, called every few entries.
	 * @return array{done:bool,cursor:array<string,mixed>,log:array<int,array{level:string,message:string,context:array<string,mixed>}>}
	 * @throws FileRestoreException When the restore must stop (a damaged part counts as one: reason "archive").
	 */
	public function restore_slice( array $parts, array $cursor, Budget $budget, ?callable $persist = null ) {
		$c      = self::normalise_cursor( $cursor );
		$log    = array();
		$walker = null;
		$worked = false;
		$since  = 0;
		$done   = false;

		try {
			while ( true ) {
				if ( $c['p'] >= count( $parts ) ) {
					$done = true;
					break;
				}
				$part = $parts[ $c['p'] ];

				if ( null === $walker ) {
					if ( 0 === $c['e'] && 0 === $c['o'] && null !== $this->part_check ) {
						$why = (string) call_user_func( $this->part_check, $part, $c['p'] );
						if ( '' !== $why ) {
							throw new FileRestoreException( 'part', $why );
						}
					}
					$walker = EntryWalker::open( $part['kind'], $part['path'] );
					$walker->skip_entries( $c['e'] );
				}

				if ( $worked && 0 === $c['o'] && $budget->exhausted() ) {
					break;
				}

				$entry = $walker->next();
				if ( null === $entry ) {
					$walker->close();
					$walker = null;
					++$c['p'];
					$c['e'] = 0;
					$c['o'] = 0;
					continue;
				}
				$worked = true;

				if ( 'budget' === $this->process( $walker, $entry, $c, $budget, $log ) ) {
					break;
				}
				++$c['e'];
				$c['o'] = 0;
				if ( ++$since >= $this->persist_every && null !== $persist ) {
					call_user_func( $persist, $c );
					$since = 0;
				}
			}//end while
		} catch ( ArchiveException $e ) {
			throw new FileRestoreException( 'archive', __( 'A part of the backup is damaged or cannot be read.', 'openvault-backup' ) );
		} finally {
			if ( null !== $walker ) {
				$walker->close();
			}
		}//end try

		return array(
			'done'   => $done,
			'cursor' => $c,
			'log'    => $log,
		);
	}

	/**
	 * Handle one entry.
	 *
	 * @param EntryWalker                                                               $walker Walker positioned at the body of $entry.
	 * @param array{name:string,size:int,type:string,mtime:int}                         $entry  Entry.
	 * @param array<string,mixed>                                                       $c      Cursor (updated).
	 * @param Budget                                                                    $budget Budget.
	 * @param array<int,array{level:string,message:string,context:array<string,mixed>}> $log    Log lines (updated).
	 * @return string "done" or "budget" (the entry is only partly written; the cursor says how far).
	 * @throws FileRestoreException When the restore must stop.
	 * @throws \Throwable          Whatever the copy throws (a damaged part: ArchiveException); the temp file is removed first.
	 */
	private function process( EntryWalker $walker, array $entry, array &$c, Budget $budget, array &$log ) {
		$verdict = $this->policy->decide( $entry['name'], $entry['type'] );
		if ( ExtractionPolicy::WRITE !== $verdict['action'] ) {
			$this->skip( $walker, $entry, $verdict['reason'], $c, $log );
			return 'done';
		}

		$rel      = $verdict['rel'];
		$segments = '' === $rel ? array() : explode( '/', $rel );
		$name     = '' === $rel ? basename( $verdict['abs'] ) : array_pop( $segments );

		$store    = $this->store;
		$prepared = $this->writer->prepare_dir(
			$verdict['root_abs'],
			$verdict['root'],
			$segments,
			static function ( $dir_entry ) use ( $store ) {
				return $store->append( UndoJournal::folder( $dir_entry ) );
			}
		);
		if ( ! $prepared['ok'] ) {
			$this->skip( $walker, $entry, $prepared['reason'], $c, $log );
			return 'done';
		}

		$dir    = $prepared['dir'];
		$target = $dir . '/' . $name;
		$real   = $this->real_dir( $dir );
		$why    = '' === $real ? '' : $this->policy->protected_reason( $real . '/' . $name );
		if ( '' !== $why ) {
			$this->skip( $walker, $entry, $why, $c, $log );
			return 'done';
		}
		$state = $this->writer->target_state( $target );
		if ( 'link' === $state || 'dir' === $state || 'other' === $state ) {
			$this->skip( $walker, $entry, 'link' === $state ? ExtractionPolicy::R_DEST_SYMLINK : ( 'dir' === $state ? ExtractionPolicy::R_DEST_IS_DIR : ExtractionPolicy::R_WRITE_FAILED ), $c, $log );
			return 'done';
		}

		if ( 0 === $c['o'] ) {
			$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not available on restricted hosts.
			if ( false !== $free && (float) $entry['size'] > (float) $free ) {
				throw new FileRestoreException( 'disk_full', __( 'A file is larger than the free disk space.', 'openvault-backup' ) );
			}
		}

		$temp = SafeWriter::temp_path( $dir, $name, $this->token );
		$open = $this->writer->open_temp( $temp, (int) $c['o'] );
		if ( null === $open ) {
			$this->skip( $walker, $entry, ExtractionPolicy::R_WRITE_FAILED, $c, $log );
			return 'done';
		}
		list( $handle, $start ) = $open;

		try {
			if ( $start > 0 ) {
				$this->drop( $walker, $start );
			}
			list( $finished, $position ) = $this->writer->copy_chunks(
				$handle,
				$start,
				$entry['size'],
				static function ( $max ) use ( $walker ) {
					return $walker->read( $max );
				},
				$budget
			);
		} catch ( \Throwable $e ) {
			fclose( $handle );
			$this->writer->discard( $temp );
			throw $e;
		}
		fclose( $handle );

		if ( ! $finished ) {
			$c['o'] = $position;
			return 'budget';
		}

		try {
			$placed = $this->finish_file( $entry, $verdict, $target, $temp, $c );
		} catch ( \Throwable $e ) {
			$this->writer->discard( $temp );
			throw $e;
		}
		if ( $placed ) {
			return 'done';
		}
		// The file could not be put in place.
		$this->writer->discard( $temp );
		$this->count( $c, ExtractionPolicy::R_WRITE_FAILED );
		++$c['ef'];
		$this->log_notable( $entry, ExtractionPolicy::R_WRITE_FAILED, $c, $log );
		$this->check_failures( $c );
		return 'done';
	}

	/**
	 * The temp file is complete: save the original, journal, rename.
	 *
	 * @param array{name:string,size:int,type:string,mtime:int} $entry   Entry.
	 * @param array<string,string>                              $verdict Verdict.
	 * @param string                                            $target  Target path.
	 * @param string                                            $temp    Temp path.
	 * @param array<string,mixed>                               $c       Cursor (updated).
	 * @return bool False when the file could not be put in place (the temp file is still there).
	 * @throws FileRestoreException When the journal or the undo copy cannot be written.
	 */
	private function finish_file( array $entry, array $verdict, $target, $temp, array &$c ) {
		$state = $this->writer->target_state( $target );
		if ( 'missing' !== $state && 'file' !== $state ) {
			return false;
		}

		if ( 'file' === $state ) {
			$saved = $this->writer->save_original( $target, $this->store->saved_path( $verdict['entry'] ) );
			if ( null === $saved ) {
				return false;
			}
			$line = UndoJournal::overwritten( $verdict['entry'], $saved['size'], $saved['sha256'] );
		} else {
			$line = UndoJournal::created( $verdict['entry'] );
		}
		if ( ! $this->store->append( $line ) ) {
			throw new FileRestoreException( 'journal', __( 'The undo journal could not be written.', 'openvault-backup' ) );
		}

		if ( ! $this->writer->commit( $temp, $target, (int) $entry['mtime'] ) ) {
			return false;
		}
		++$c['fl'];
		$c['by'] += (int) $entry['size'];
		$c['ef']  = 0;
		if ( 'file' === $state ) {
			++$c['ow'];
		} else {
			++$c['cr'];
		}
		return true;
	}

	/**
	 * Count and report a skipped entry and drop its body.
	 *
	 * @param EntryWalker                                                               $walker Walker.
	 * @param array{name:string,size:int,type:string,mtime:int}                         $entry  Entry.
	 * @param string                                                                    $reason Reason code.
	 * @param array<string,mixed>                                                       $c      Cursor (updated).
	 * @param array<int,array{level:string,message:string,context:array<string,mixed>}> $log    Log lines (updated).
	 * @return void
	 * @throws ArchiveException When the body cannot be skipped.
	 */
	private function skip( EntryWalker $walker, array $entry, $reason, array &$c, array &$log ) {
		$walker->skip_body();
		$this->count( $c, $reason );
		if ( ExtractionPolicy::R_WRITE_FAILED === $reason ) {
			++$c['ef'];
			$this->check_failures( $c );
		}
		if ( ExtractionPolicy::R_DIR !== $reason && ExtractionPolicy::R_DB !== $reason && $c['sj'] < UndoJournal::MAX_SKIPS ) {
			++$c['sj'];
			$this->store->append( UndoJournal::skipped( $entry['name'], $reason ) );
		}
		$this->log_notable( $entry, $reason, $c, $log );
	}

	/**
	 * Add a reason to the counters.
	 *
	 * @param array<string,mixed> $c      Cursor.
	 * @param string              $reason Reason.
	 * @return void
	 */
	private function count( array &$c, $reason ) {
		$c['sk'][ $reason ] = ( isset( $c['sk'][ $reason ] ) ? (int) $c['sk'][ $reason ] : 0 ) + 1;
	}

	/**
	 * Log an entry that is not a normal skip (rate limited; the name is cut and cleaned).
	 *
	 * @param array{name:string,size:int,type:string,mtime:int}                         $entry  Entry.
	 * @param string                                                                    $reason Reason.
	 * @param array<string,mixed>                                                       $c      Cursor (updated).
	 * @param array<int,array{level:string,message:string,context:array<string,mixed>}> $log    Log lines (updated).
	 * @return void
	 */
	private function log_notable( array $entry, $reason, array &$c, array &$log ) {
		if ( ! in_array( $reason, ExtractionPolicy::NOTABLE, true ) || $c['lg'] >= self::MAX_LOGGED ) {
			return;
		}
		++$c['lg'];
		$log[] = array(
			'level'   => 'warning',
			'message' => __( 'A file of the backup was not restored.', 'openvault-backup' ),
			'context' => array(
				'entry_index' => (int) $c['e'] + 1,
				'part'        => (int) $c['p'] + 1,
				'reason'      => $reason,
				'name'        => ExtractionPolicy::safe_label( $entry['name'] ),
			),
		);
	}

	/**
	 * Stop when too many writes failed in a row (the destination is not writable).
	 *
	 * @param array<string,mixed> $c Cursor.
	 * @return void
	 * @throws FileRestoreException When the limit is reached.
	 */
	private function check_failures( array $c ) {
		if ( $c['ef'] >= self::MAX_FAILURES ) {
			throw new FileRestoreException( 'unwritable', __( 'Files could not be written many times in a row. Check the permissions of the wp-content folder.', 'openvault-backup' ) );
		}
	}

	/**
	 * Read and drop the first bytes of the entry body (a resumed copy).
	 *
	 * @param EntryWalker $walker Walker.
	 * @param int         $bytes  Bytes.
	 * @return void
	 * @throws ArchiveException When the body ends early.
	 */
	private function drop( EntryWalker $walker, $bytes ) {
		while ( $bytes > 0 ) {
			$data = $walker->read( min( self::STRING_CHUNK, $bytes ) );
			if ( '' === $data ) {
				throw new ArchiveException( 'A file ends before its recorded size.' );
			}
			$bytes -= strlen( $data );
		}
	}

	/**
	 * Real path of a folder ('' when unknown).
	 *
	 * @param string $dir Folder.
	 * @return string
	 */
	private function real_dir( $dir ) {
		$real = realpath( $dir );
		return false === $real ? '' : rtrim( str_replace( '\\', '/', $real ), '/' );
	}
}
