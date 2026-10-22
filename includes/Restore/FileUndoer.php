<?php
/**
 * Undoes a files restore by replaying its journal backwards.
 *
 * Phase: MVP
 *
 * Purpose: One slice of the "file_undo" step. The journal (UndoJournal) is read from its end, line by line, so a journal of any size needs no
 * memory: the cursor holds the byte offset where the unprocessed part ends. For every line:
 *  - O  the saved original is copied back over the file (temp file in the same folder, SHA-256 of the saved copy checked first, rename);
 *  - C  the file that the restore created is removed (only a regular file, never a link or a folder);
 *  - D  the folder that the restore created is removed when it is empty (a folder that holds other files stays).
 * Every path goes through the same ExtractionPolicy and SafeWriter rules as the restore itself: protected paths are never touched, no symlink
 * is followed, folders below a root are walked component by component. A tampered journal therefore cannot reach outside the roots either.
 * A damaged or missing saved original is counted ("corrupt_saved") and that file is left as it is now.
 *
 * Cursor: pos (end of the unprocessed journal), co (bytes of the current saved original already copied back), rs files put back, rm created files
 * removed, rd created folders removed, kd folders kept (not empty), er originals that could not be put back, sk map reason => count, lg lines logged.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- File system calls of a restore report failures through their return value; a warning would end up in the log of the site.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed translated texts; they are escaped where they are displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Native file functions on purpose (see SafeWriter).

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Archive\ArchiveException;
use OpenVault\Backup\Engine\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * File undo.
 */
final class FileUndoer {

	const PERSIST_EVERY = 50;
	const MAX_LOGGED    = 50;

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
	 * Token of the temp file names.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Entries between two saved cursors.
	 *
	 * @var int
	 */
	private $persist_every;

	/**
	 * Constructor.
	 *
	 * @param ExtractionPolicy $policy        Policy (roots from the journal's contents flags).
	 * @param SafeWriter       $writer        Writer.
	 * @param UndoStore        $store         Undo store.
	 * @param string           $token         Hex token for the temp file names.
	 * @param int              $persist_every Lines between two saved cursors.
	 */
	public function __construct( ExtractionPolicy $policy, SafeWriter $writer, UndoStore $store, $token, $persist_every = self::PERSIST_EVERY ) {
		$this->policy        = $policy;
		$this->writer        = $writer;
		$this->store         = $store;
		$this->token         = (string) preg_replace( '/[^0-9a-f]/', '', strtolower( (string) $token ) );
		$this->persist_every = max( 1, (int) $persist_every );
	}

	/**
	 * A new cursor positioned at the end of the journal.
	 *
	 * @return array<string,mixed>
	 */
	public function new_cursor() {
		return array(
			'pos' => UndoJournal::complete_end( $this->store->journal_path() ),
			'co'  => 0,
			'rs'  => 0,
			'rm'  => 0,
			'rd'  => 0,
			'kd'  => 0,
			'er'  => 0,
			'sk'  => array(),
			'lg'  => 0,
		);
	}

	/**
	 * Sanitise a stored cursor.
	 *
	 * @param array<mixed> $cursor Stored cursor.
	 * @return array<string,mixed>
	 */
	public function normalise_cursor( array $cursor ) {
		$out = $this->new_cursor();
		foreach ( array( 'pos', 'co', 'rs', 'rm', 'rd', 'kd', 'er', 'lg' ) as $key ) {
			if ( isset( $cursor[ $key ] ) && is_numeric( $cursor[ $key ] ) ) {
				$out[ $key ] = max( 0, (int) $cursor[ $key ] );
			}
		}
		$out['pos'] = isset( $cursor['pos'] ) && is_numeric( $cursor['pos'] ) ? min( $out['pos'], UndoJournal::complete_end( $this->store->journal_path() ) ) : $out['pos'];
		$out['sk']  = array();
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
	 * @param array<mixed>  $cursor  Stored cursor.
	 * @param Budget        $budget  Budget.
	 * @param callable|null $persist function( array $cursor ): void.
	 * @return array{done:bool,cursor:array<string,mixed>,log:array<int,array{level:string,message:string,context:array<string,mixed>}>}
	 * @throws FileRestoreException When the undo must stop.
	 */
	public function undo_slice( array $cursor, Budget $budget, ?callable $persist = null ) {
		$c      = $this->normalise_cursor( $cursor );
		$log    = array();
		$done   = false;
		$worked = false;
		$since  = 0;

		$handle = @fopen( $this->store->journal_path(), 'rb' );
		if ( false === $handle ) {
			throw new FileRestoreException( 'journal', __( 'The undo journal cannot be read.', 'openvault-backup' ) );
		}
		try {
			while ( true ) {
				$found = $c['pos'] > 0 ? UndoJournal::line_before( $handle, $c['pos'] ) : null;
				if ( null === $found ) {
					$done = true;
					break;
				}
				if ( $worked && 0 === $c['co'] && $budget->exhausted() ) {
					break;
				}
				$worked = true;

				list( $line, $start ) = $found;
				$parsed               = UndoJournal::parse_line( $line );
				if ( null !== $parsed && in_array( $parsed['op'], array( 'O', 'C', 'D' ), true ) ) {
					if ( 'budget' === $this->apply( $parsed, $c, $budget, $log ) ) {
						break;
					}
				}
				$c['pos'] = $start;
				$c['co']  = 0;
				if ( ++$since >= $this->persist_every && null !== $persist ) {
					call_user_func( $persist, $c );
					$since = 0;
				}
			}//end while
		} finally {
			fclose( $handle );
		}//end try

		return array(
			'done'   => $done,
			'cursor' => $c,
			'log'    => $log,
		);
	}

	/**
	 * Replay one journal line.
	 *
	 * @param array{op:string,entry:string,size:int,sha256:string,reason:string}        $line   Parsed line.
	 * @param array<string,mixed>                                                       $c      Cursor (updated).
	 * @param Budget                                                                    $budget Budget.
	 * @param array<int,array{level:string,message:string,context:array<string,mixed>}> $log    Log (updated).
	 * @return string "done" or "budget".
	 * @throws FileRestoreException On a full disk.
	 * @throws \Throwable          What a copy throws other than a damaged saved original.
	 */
	private function apply( array $line, array &$c, Budget $budget, array &$log ) {
		if ( 'D' === $line['op'] ) {
			$this->remove_folder( $line['entry'], $c, $log );
			return 'done';
		}

		$verdict = $this->policy->decide( $line['entry'] );
		if ( ExtractionPolicy::WRITE !== $verdict['action'] ) {
			$this->note( $c, $log, $line['entry'], $verdict['reason'] );
			return 'done';
		}
		$rel      = $verdict['rel'];
		$segments = '' === $rel ? array() : explode( '/', $rel );
		$name     = '' === $rel ? basename( $verdict['abs'] ) : array_pop( $segments );

		if ( 'C' === $line['op'] ) {
			$this->remove_created( $line['entry'], $verdict, $segments, $name, $c, $log );
			return 'done';
		}
		return $this->put_back( $line, $verdict, $segments, $name, $c, $budget, $log );
	}

	/**
	 * O: copy the saved original back.
	 *
	 * @param array<string,mixed>  $line     Parsed line.
	 * @param array<string,string> $verdict  Policy verdict.
	 * @param string[]             $segments Folders below the root.
	 * @param string               $name     File name.
	 * @param array<string,mixed>  $c        Cursor.
	 * @param Budget               $budget   Budget.
	 * @param array<int,mixed>     $log      Log.
	 * @return string "done" or "budget".
	 * @throws \Throwable What a copy throws other than a damaged saved original (a full disk: FileRestoreException).
	 */
	private function put_back( array $line, array $verdict, array $segments, $name, array &$c, Budget $budget, array &$log ) {
		$saved = $this->store->saved_path( $line['entry'] );
		if ( is_link( $saved ) || ! is_file( $saved ) || (int) filesize( $saved ) !== (int) $line['size'] ) {
			++$c['er'];
			$this->note( $c, $log, $line['entry'], ExtractionPolicy::R_CORRUPT_SAVED );
			return 'done';
		}
		if ( 0 === $c['co'] && '' !== $line['sha256'] && hash_file( 'sha256', $saved ) !== $line['sha256'] ) {
			++$c['er'];
			$this->note( $c, $log, $line['entry'], ExtractionPolicy::R_CORRUPT_SAVED );
			return 'done';
		}

		$prepared = $this->writer->prepare_dir( $verdict['root_abs'], $verdict['root'], $segments, null );
		if ( ! $prepared['ok'] ) {
			$this->note( $c, $log, $line['entry'], $prepared['reason'] );
			return 'done';
		}
		$dir    = $prepared['dir'];
		$target = $dir . '/' . $name;
		$real   = realpath( $dir );
		$why    = false === $real ? '' : $this->policy->protected_reason( rtrim( str_replace( '\\', '/', $real ), '/' ) . '/' . $name );
		if ( '' !== $why ) {
			$this->note( $c, $log, $line['entry'], $why );
			return 'done';
		}
		$state = $this->writer->target_state( $target );
		if ( 'link' === $state || 'dir' === $state || 'other' === $state ) {
			$this->note( $c, $log, $line['entry'], 'link' === $state ? ExtractionPolicy::R_DEST_SYMLINK : ExtractionPolicy::R_DEST_IS_DIR );
			++$c['er'];
			return 'done';
		}

		$temp = SafeWriter::temp_path( $dir, $name, $this->token );
		$open = $this->writer->open_temp( $temp, (int) $c['co'] );
		if ( null === $open ) {
			++$c['er'];
			$this->note( $c, $log, $line['entry'], ExtractionPolicy::R_WRITE_FAILED );
			return 'done';
		}
		list( $handle, $start ) = $open;

		$source = @fopen( $saved, 'rb' );
		if ( false === $source ) {
			fclose( $handle );
			$this->writer->discard( $temp );
			++$c['er'];
			$this->note( $c, $log, $line['entry'], ExtractionPolicy::R_CORRUPT_SAVED );
			return 'done';
		}
		try {
			fseek( $source, $start );
			list( $finished, $position ) = $this->writer->copy_chunks(
				$handle,
				$start,
				(int) $line['size'],
				static function ( $max ) use ( $source ) {
					$data = fread( $source, $max );
					return false === $data ? '' : $data;
				},
				$budget
			);
		} catch ( ArchiveException $e ) {
			fclose( $handle );
			fclose( $source );
			$this->writer->discard( $temp );
			++$c['er'];
			$this->note( $c, $log, $line['entry'], ExtractionPolicy::R_CORRUPT_SAVED );
			return 'done';
		} catch ( \Throwable $e ) {
			fclose( $handle );
			fclose( $source );
			$this->writer->discard( $temp );
			throw $e;
		}//end try
		fclose( $handle );
		fclose( $source );

		if ( ! $finished ) {
			$c['co'] = $position;
			return 'budget';
		}
		$mtime = (int) filemtime( $saved );
		if ( ! $this->writer->commit( $temp, $target, $mtime ) ) {
			$this->writer->discard( $temp );
			++$c['er'];
			$this->note( $c, $log, $line['entry'], ExtractionPolicy::R_WRITE_FAILED );
			return 'done';
		}
		++$c['rs'];
		return 'done';
	}

	/**
	 * C: remove a file the restore created.
	 *
	 * @param string               $entry    Entry name.
	 * @param array<string,string> $verdict  Policy verdict.
	 * @param string[]             $segments Folders below the root.
	 * @param string               $name     File name.
	 * @param array<string,mixed>  $c        Cursor.
	 * @param array<int,mixed>     $log      Log.
	 * @return void
	 */
	private function remove_created( $entry, array $verdict, array $segments, $name, array &$c, array &$log ) {
		$walk = $this->writer->walk_existing( $verdict['root_abs'], $segments );
		if ( ! $walk['ok'] ) {
			$this->note( $c, $log, $entry, $walk['reason'] );
			return;
		}
		if ( ! $walk['exists'] ) {
			return;
		}
		$target = $walk['dir'] . '/' . $name;
		$state  = $this->writer->target_state( $target );
		if ( 'missing' === $state ) {
			return;
		}
		if ( 'file' !== $state ) {
			$this->note( $c, $log, $entry, 'link' === $state ? ExtractionPolicy::R_DEST_SYMLINK : ExtractionPolicy::R_DEST_IS_DIR );
			return;
		}
		if ( @unlink( $target ) ) {
			++$c['rm'];
		} else {
			++$c['er'];
			$this->note( $c, $log, $entry, ExtractionPolicy::R_WRITE_FAILED );
		}
	}

	/**
	 * D: remove a folder the restore created, when it is empty.
	 *
	 * @param string              $entry Entry name of the folder.
	 * @param array<string,mixed> $c  Cursor.
	 * @param array<int,mixed>    $log   Log.
	 * @return void
	 */
	private function remove_folder( $entry, array &$c, array &$log ) {
		$resolved = $this->policy->resolve_dir( $entry );
		if ( null === $resolved ) {
			$this->note( $c, $log, $entry, ExtractionPolicy::R_UNOWNED );
			return;
		}
		$segments = '' === $resolved['rel'] ? array() : explode( '/', $resolved['rel'] );
		$walk     = $this->writer->walk_existing( $resolved['root_abs'], $segments );
		if ( ! $walk['ok'] ) {
			$this->note( $c, $log, $entry, $walk['reason'] );
			return;
		}
		if ( ! $walk['exists'] ) {
			return;
		}
		if ( @rmdir( $walk['dir'] ) ) {
			++$c['rd'];
		} else {
			++$c['kd'];
		}
	}

	/**
	 * Count (and log, rate limited) a line that could not be replayed.
	 *
	 * @param array<string,mixed> $c      Cursor.
	 * @param array<int,mixed>    $log    Log.
	 * @param string              $entry  Entry name.
	 * @param string              $reason Reason.
	 * @return void
	 */
	private function note( array &$c, array &$log, $entry, $reason ) {
		$c['sk'][ $reason ] = ( isset( $c['sk'][ $reason ] ) ? (int) $c['sk'][ $reason ] : 0 ) + 1;
		if ( $c['lg'] < self::MAX_LOGGED ) {
			++$c['lg'];
			$log[] = array(
				'level'   => 'warning',
				'message' => __( 'A change of the restore could not be undone.', 'openvault-backup' ),
				'context' => array(
					'reason' => $reason,
					'name'   => ExtractionPolicy::safe_label( $entry ),
				),
			);
		}
	}
}
