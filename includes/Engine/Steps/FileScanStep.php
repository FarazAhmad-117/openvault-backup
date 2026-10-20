<?php
/**
 * Builds the file list from the selected folders.
 *
 * Phase: MVP
 *
 * Purpose: Builds file list from selected folders.
 *
 * Responsibilities:
 *  - Apply exclusion patterns and size cap (Scan\PathFilter: ovb_settings['excluded_paths'], the filter
 *    "ovb_backup_exclusions" key "paths", ovb_settings['max_file_size_mb'])
 *  - Write list to temp file (not memory): <work dir>/files.list, one record per line "<size>\t<mtime>\t<entry name>"
 *  - Resumable breadth-first walk. The folders still to visit are a queue in <work dir>/scan-queue.txt (one
 *    "<root index>\t<path below the root>" per line, consumed by byte offset), never memory or the job state.
 *    The cursor holds the queue and list sizes, so a run that was killed before saving is cut back first.
 *    A folder that does not finish inside the budget is resumed by skipping the entries already seen
 *    ("dir_skip"); a folder that changes between slices can in theory list a file twice or miss one.
 *  - Symlinks are never followed or listed (counted, never fatal); unreadable folders, files that are not regular
 *    files and names that cannot be stored safely are skipped with a warning (the first 20 are logged)
 *  - Skipped (next step at once) when no file contents are selected
 *  - Result: the state meta "files" holds { list, size, count, bytes, skipped, roots } for the archive step
 *
 * @package OpenVault\Backup
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Walks the site's folders and streams work files; a failure is reported to the log or as a step failure.

namespace OpenVault\Backup\Engine\Steps;

use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\Scan\AppendFile;
use OpenVault\Backup\Engine\Scan\ContentSelector;
use OpenVault\Backup\Engine\Scan\EntryName;
use OpenVault\Backup\Engine\Scan\LineReader;
use OpenVault\Backup\Engine\Scan\PathFilter;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Support\Logger;
use OpenVault\Backup\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * File scan step.
 */
class FileScanStep implements StepInterface {

	const LIST_FILE  = 'files.list';
	const QUEUE_FILE = 'scan-queue.txt';

	const ENTRY_CHECK_INTERVAL = 500;
	const LOGGED_WARNINGS      = 20;
	const FLUSH_BYTES          = 32768;

	const SKIP_KEYS = array( 'symlink', 'unreadable', 'oversize', 'excluded', 'badname' );

	/**
	 * Opens a folder: callable( string $absolute ): \Iterator|false. Injectable so tests can simulate unreadable folders.
	 *
	 * @var callable|null
	 */
	private $opener;

	/**
	 * Entries between two budget checks inside one folder.
	 *
	 * @var int
	 */
	private $interval;

	/**
	 * Selector override (tests); the real site is used when null.
	 *
	 * @var ContentSelector|null
	 */
	private $selector;

	/**
	 * Constructor.
	 *
	 * @param callable|null        $opener   Folder opener, defaults to a DirectoryIterator that returns false on failure.
	 * @param int                  $interval Entries between budget checks inside a folder.
	 * @param ContentSelector|null $selector Locations to scan, defaults to the running site.
	 */
	public function __construct( ?callable $opener = null, $interval = self::ENTRY_CHECK_INTERVAL, ?ContentSelector $selector = null ) {
		$this->opener   = $opener;
		$this->interval = max( 1, (int) $interval );
		$this->selector = $selector;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_FILE_SCAN;
	}

	/**
	 * Walk as many folders as the budget allows.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$backup   = $context->backup();
		$contents = isset( $backup['contents'] ) && is_array( $backup['contents'] ) ? $backup['contents'] : array();
		if ( ! ContentSelector::has_files( $contents ) ) {
			$context->log( Logger::INFO, __( 'No files are part of this backup, skipping the file scan.', 'openvault-backup' ) );
			return StepResult::next_step( $context->state() );
		}

		try {
			$selector = null === $this->selector ? ContentSelector::from_environment( Paths::local_dir() ) : $this->selector;
			return $this->scan( $context, $selector, $contents );
		} catch ( \RuntimeException $e ) {
			return StepResult::fail(
				sprintf(
					/* translators: %s: reason. */
					__( 'The file scan failed: %s', 'openvault-backup' ),
					$e->getMessage()
				),
				$context->state()
			);
		}
	}

	/**
	 * One slice of the scan.
	 *
	 * @param JobContext      $context  Context.
	 * @param ContentSelector $selector Locations.
	 * @param array<mixed>    $contents Contents flags.
	 * @return StepResult
	 * @throws \RuntimeException When a work file cannot be used.
	 */
	private function scan( JobContext $context, ContentSelector $selector, array $contents ) {
		$budget     = $context->budget();
		$queue_path = $context->work_dir() . '/' . self::QUEUE_FILE;
		$list_path  = $context->work_dir() . '/' . self::LIST_FILE;
		$queue      = new AppendFile( $queue_path );
		$listing    = new AppendFile( $list_path );
		$cursor     = self::read_cursor( $context->state()->get_cursor() );

		if ( null === $cursor ) {
			$queue->truncate_to( 0 );
			$listing->truncate_to( 0 );
			$cursor = $this->start( $context, $selector, $contents, $queue, $listing );
		} else {
			$queue->truncate_to( $cursor['queue_end'] );
			$listing->truncate_to( $cursor['list_end'] );
		}

		$filter = $this->filter( $context, $selector );
		$out    = array(
			'queue' => '',
			'list'  => '',
		);

		while ( true ) {
			if ( null === $cursor['dir'] ) {
				$next = $this->read_queue_line( $queue_path, $cursor['queue_offset'] );
				if ( null === $next ) {
					break;
				}
				$cursor['dir']          = $next[0];
				$cursor['queue_offset'] = $next[1];
				$cursor['dir_skip']     = 0;
			}

			$finished = $this->walk_dir( $context, $cursor, $filter, $queue, $listing, $out );
			$this->flush( $queue, $listing, $out );
			// The folders found in this one must be in the queue file before the next line is read.
			if ( $finished ) {
				$cursor['dir']      = null;
				$cursor['dir_skip'] = 0;
			}
			if ( ! $finished || $budget->exhausted() ) {
				return $this->save( $context, $cursor, $queue, $listing, $out );
			}
		}//end while

		$this->flush( $queue, $listing, $out );
		$cursor['queue_end'] = $queue->size();
		$cursor['list_end']  = $listing->size();
		$queue->close();
		$listing->close();
		return $this->finish( $context, $cursor, $queue_path );
	}

	/**
	 * First slice: select the roots and queue their folders.
	 *
	 * @param JobContext      $context  Context.
	 * @param ContentSelector $selector Locations.
	 * @param array<mixed>    $contents Contents flags.
	 * @param AppendFile      $queue    Queue (open, empty).
	 * @param AppendFile      $listing     List (open, empty).
	 * @return array<string,mixed> Cursor.
	 */
	private function start( JobContext $context, ContentSelector $selector, array $contents, AppendFile $queue, AppendFile $listing ) {
		$roots  = array();
		$cursor = array(
			'roots'        => array(),
			'queue_offset' => 0,
			'queue_end'    => 0,
			'list_end'     => 0,
			'dir'          => null,
			'dir_skip'     => 0,
			'files'        => 0,
			'bytes'        => 0,
			'skipped'      => array_fill_keys( self::SKIP_KEYS, 0 ),
			'warned'       => 0,
		);

		foreach ( $selector->roots( $contents ) as $root ) {
			$present = ContentSelector::TYPE_FILE === $root['type'] ? is_file( $root['abs'] ) : is_dir( $root['abs'] );
			if ( ! $present ) {
				$context->log( Logger::DEBUG, 'A selected location does not exist and is skipped.', array( 'entry' => $root['entry'] ) );
				continue;
			}
			$roots[] = $root;
		}

		$lines = '';
		$out   = '';
		foreach ( $roots as $index => $root ) {
			if ( ContentSelector::TYPE_FILE === $root['type'] ) {
				$size  = (int) @filesize( $root['abs'] );
				$mtime = (int) @filemtime( $root['abs'] );
				if ( is_readable( $root['abs'] ) ) {
					$out .= $size . "\t" . $mtime . "\t" . $root['entry'] . "\n";
					++$cursor['files'];
					$cursor['bytes'] += $size;
				} else {
					$this->warn( $context, $cursor, 'unreadable', __( 'A file cannot be read and is skipped.', 'openvault-backup' ), $root['entry'] );
				}
				continue;
			}
			$lines .= $index . "\t\n";
		}
		$queue->append( $lines );
		$listing->append( $out );

		$cursor['roots']     = $roots;
		$cursor['queue_end'] = $queue->size();
		$cursor['list_end']  = $listing->size();

		$context->log( Logger::INFO, __( 'File scan started.', 'openvault-backup' ), array( 'roots' => count( $roots ) ) );
		return $cursor;
	}

	/**
	 * Visit one folder (or the rest of it).
	 *
	 * @param JobContext          $context Context.
	 * @param array<string,mixed> $cursor  Cursor, updated.
	 * @param PathFilter          $filter  Filter.
	 * @param AppendFile          $queue   Queue.
	 * @param AppendFile          $listing    List.
	 * @param array<string,mixed> $out     Buffered lines: "queue" and "list", updated.
	 * @return bool True when the folder is finished, false when the budget ended the slice inside it.
	 */
	private function walk_dir( JobContext $context, array &$cursor, PathFilter $filter, AppendFile $queue, AppendFile $listing, array &$out ) {
		$parts = explode( "\t", (string) $cursor['dir'], 2 );
		$index = (int) $parts[0];
		$rel   = isset( $parts[1] ) ? $parts[1] : '';
		if ( ! isset( $cursor['roots'][ $index ] ) ) {
			return true;
		}
		$root      = $cursor['roots'][ $index ];
		$shallow   = ContentSelector::TYPE_SHALLOW === $root['type'];
		$absolute  = '' === $rel ? $root['abs'] : $root['abs'] . '/' . $rel;
		$dir_entry = EntryName::join( $root['entry'], $rel );
		if ( null === $dir_entry ) {
			return true;
		}

		$iterator = $this->open( $absolute );
		if ( false === $iterator ) {
			$this->warn( $context, $cursor, 'unreadable', __( 'A folder cannot be read and is skipped.', 'openvault-backup' ), $dir_entry );
			return true;
		}

		$skip = (int) $cursor['dir_skip'];
		$seen = 0;
		foreach ( $iterator as $item ) {
			if ( $item->isDot() ) {
				continue;
			}
			++$seen;
			if ( $seen <= $skip ) {
				continue;
			}
			$this->visit( $context, $cursor, $filter, $item, $absolute, $dir_entry, $index, $rel, $shallow, $out );

			if ( strlen( $out['queue'] ) + strlen( $out['list'] ) > self::FLUSH_BYTES ) {
				$this->flush( $queue, $listing, $out );
			}
			if ( 0 === $seen % $this->interval && $context->budget()->exhausted() ) {
				$cursor['dir_skip'] = $seen;
				return false;
			}
		}
		return true;
	}

	/**
	 * Decide about one directory entry and record it.
	 *
	 * @param JobContext          $context   Context.
	 * @param array<string,mixed> $cursor    Cursor, updated.
	 * @param PathFilter          $filter    Filter.
	 * @param \SplFileInfo        $item      Entry.
	 * @param string              $parent_path    Absolute path of the folder.
	 * @param string              $dir_entry Entry name of the folder.
	 * @param int                 $index     Root index.
	 * @param string              $rel       Path of the folder below its root.
	 * @param bool                $shallow   Whether only files are wanted.
	 * @param array<string,mixed> $out       Buffered lines, updated.
	 * @return void
	 */
	private function visit( JobContext $context, array &$cursor, PathFilter $filter, $item, $parent_path, $dir_entry, $index, $rel, $shallow, array &$out ) {
		$name     = $item->getFilename();
		$entry    = EntryName::join( $dir_entry, $name );
		$absolute = $parent_path . '/' . $name;
		if ( null === $entry ) {
			$this->warn( $context, $cursor, 'badname', __( 'A file or folder with a name that cannot be stored safely is skipped.', 'openvault-backup' ), $dir_entry );
			return;
		}

		$is_link = $item->isLink();
		$is_dir  = ! $is_link && $item->isDir();
		if ( $shallow && ( $is_dir || 'wp-config.php' === $name ) ) {
			return;
			// The folders below ABSPATH belong to other roots, and wp-config.php to the "wp_config" selection.
		}

		$size = 0;
		if ( ! $is_link && ! $is_dir ) {
			try {
				$size = (int) $item->getSize();
			} catch ( \RuntimeException $e ) {
				$this->warn( $context, $cursor, 'unreadable', __( 'A file cannot be read and is skipped.', 'openvault-backup' ), $entry );
				return;
			}
		}

		$verdict = $filter->decide( $absolute, $entry, $is_dir, $is_link, $size );
		if ( PathFilter::OTHER_ROOT === $verdict ) {
			return;
			// Archived by its own root (or deliberately not selected).
		}
		if ( PathFilter::INCLUDE_ENTRY !== $verdict ) {
			++$cursor['skipped'][ $verdict ];
			if ( PathFilter::BADNAME === $verdict ) {
				$this->warn_logged( $context, $cursor, __( 'A file or folder with a name that cannot be stored safely is skipped.', 'openvault-backup' ), $entry );
			} elseif ( PathFilter::SYMLINK === $verdict ) {
				$this->warn_logged( $context, $cursor, __( 'A symbolic link is not followed or archived.', 'openvault-backup' ), $entry );
			} elseif ( PathFilter::OVERSIZE === $verdict ) {
				$this->warn_logged( $context, $cursor, __( 'A file is larger than the size limit and is skipped.', 'openvault-backup' ), $entry );
			}
			return;
		}

		if ( $is_dir ) {
			$out['queue'] .= $index . "\t" . ( '' === $rel ? $name : $rel . '/' . $name ) . "\n";
			return;
		}
		if ( ! $item->isFile() || ! is_readable( $absolute ) ) {
			$this->warn( $context, $cursor, 'unreadable', __( 'A file cannot be read (or is not a regular file) and is skipped.', 'openvault-backup' ), $entry );
			return;
		}

		$out['list'] .= $size . "\t" . max( 0, (int) $item->getMTime() ) . "\t" . $entry . "\n";
		++$cursor['files'];
		$cursor['bytes'] += $size;
	}

	/**
	 * Count a skipped entry and log it (only the first LOGGED_WARNINGS).
	 *
	 * @param JobContext          $context Context.
	 * @param array<string,mixed> $cursor  Cursor, updated.
	 * @param string              $key     Key of the skipped counter.
	 * @param string              $message Message.
	 * @param string              $entry   Entry name.
	 * @return void
	 */
	private function warn( JobContext $context, array &$cursor, $key, $message, $entry ) {
		++$cursor['skipped'][ $key ];
		$this->warn_logged( $context, $cursor, $message, $entry );
	}

	/**
	 * Log a warning unless 20 were logged already.
	 *
	 * @param JobContext          $context Context.
	 * @param array<string,mixed> $cursor  Cursor, updated.
	 * @param string              $message Message.
	 * @param string              $entry   Entry name.
	 * @return void
	 */
	private function warn_logged( JobContext $context, array &$cursor, $message, $entry ) {
		if ( $cursor['warned'] < self::LOGGED_WARNINGS ) {
			$context->log( Logger::WARNING, $message, array( 'path' => $entry ) );
		}
		++$cursor['warned'];
	}

	/**
	 * Write buffered lines to the work files.
	 *
	 * @param AppendFile          $queue Queue.
	 * @param AppendFile          $listing  List.
	 * @param array<string,mixed> $out   Buffered lines, emptied.
	 * @return void
	 */
	private function flush( AppendFile $queue, AppendFile $listing, array &$out ) {
		$queue->append( $out['queue'] );
		$listing->append( $out['list'] );
		$out['queue'] = '';
		$out['list']  = '';
	}

	/**
	 * Save the cursor after a slice that is not finished.
	 *
	 * @param JobContext          $context Context.
	 * @param array<string,mixed> $cursor  Cursor.
	 * @param AppendFile          $queue   Queue.
	 * @param AppendFile          $listing    List.
	 * @param array<string,mixed> $out     Buffered lines.
	 * @return StepResult
	 */
	private function save( JobContext $context, array $cursor, AppendFile $queue, AppendFile $listing, array $out ) {
		$this->flush( $queue, $listing, $out );
		$cursor['queue_end'] = $queue->size();
		$cursor['list_end']  = $listing->size();
		$queue->close();
		$listing->close();
		return StepResult::continue_step( $context->state()->with_cursor( $cursor ) );
	}

	/**
	 * The scan is complete: remove the queue and hand the list to the archive step.
	 *
	 * @param JobContext          $context    Context.
	 * @param array<string,mixed> $cursor     Final cursor.
	 * @param string              $queue_path Queue file.
	 * @return StepResult
	 */
	private function finish( JobContext $context, array $cursor, $queue_path ) {
		@unlink( $queue_path );

		$meta          = $context->state()->get_meta();
		$meta['files'] = array(
			'list'    => self::LIST_FILE,
			'size'    => $cursor['list_end'],
			'count'   => $cursor['files'],
			'bytes'   => $cursor['bytes'],
			'skipped' => $cursor['skipped'],
			'roots'   => $cursor['roots'],
		);

		$context->log(
			Logger::INFO,
			__( 'File scan finished.', 'openvault-backup' ),
			array(
				'files'   => $cursor['files'],
				'bytes'   => $cursor['bytes'],
				'skipped' => $cursor['skipped'],
			)
		);
		if ( $cursor['warned'] > self::LOGGED_WARNINGS ) {
			$context->log( Logger::WARNING, __( 'More files were skipped than are listed one by one in this log.', 'openvault-backup' ), array( 'skipped' => $cursor['skipped'] ) );
		}
		return StepResult::next_step( $context->state()->with_cursor( array() )->with_meta( $meta ) );
	}

	/**
	 * Read the line at a queue offset.
	 *
	 * @param string $path   Queue file.
	 * @param int    $offset Byte offset.
	 * @return array{0:string,1:int}|null Line and the offset after it, null at the end.
	 * @throws \RuntimeException When the queue cannot be read.
	 */
	private function read_queue_line( $path, $offset ) {
		$reader = new LineReader( $path, $offset );
		$line   = $reader->next();
		$next   = $reader->offset();
		$reader->close();
		return null === $line ? null : array( $line, $next );
	}

	/**
	 * Open a folder.
	 *
	 * @param string $absolute Folder.
	 * @return \Iterator<mixed>|false
	 */
	private function open( $absolute ) {
		if ( null !== $this->opener ) {
			return call_user_func( $this->opener, $absolute );
		}
		try {
			return new \DirectoryIterator( $absolute );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Filter from the settings and the filter hook.
	 *
	 * @param JobContext      $context Context.
	 * @param ContentSelector $selector Locations.
	 * @return PathFilter
	 */
	private function filter( JobContext $context, ContentSelector $selector ) {
		$patterns = PathFilter::normalise_patterns( $context->setting( 'excluded_paths', array() ) );

		/** This filter is documented in includes/Engine/Steps/DatabaseExportStep.php. */
		$filtered = apply_filters( 'ovb_backup_exclusions', array( 'paths' => $patterns ) );
		if ( is_array( $filtered ) && isset( $filtered['paths'] ) ) {
			$patterns = PathFilter::normalise_patterns( $filtered['paths'] );
		}

		$local = $selector->local_dir();
		return new PathFilter( '' !== $local ? array( $local ) : array(), $patterns, PathFilter::max_bytes_from_setting( $context->setting( 'max_file_size_mb', 0 ) ), $selector->other_root_dirs() );
	}

	/**
	 * Validate and complete a stored cursor.
	 *
	 * @param array<mixed> $cursor Stored cursor (empty on the first slice).
	 * @return array<string,mixed>|null Null for the first slice.
	 */
	private static function read_cursor( array $cursor ) {
		if ( ! isset( $cursor['queue_end'], $cursor['roots'] ) || ! is_array( $cursor['roots'] ) ) {
			return null;
		}
		$roots = array();
		foreach ( $cursor['roots'] as $root ) {
			if ( is_array( $root ) && isset( $root['entry'], $root['abs'], $root['type'] ) && EntryName::is_safe( $root['entry'] ) && is_string( $root['abs'] ) ) {
				$roots[] = array(
					'entry' => (string) $root['entry'],
					'abs'   => $root['abs'],
					'type'  => (string) $root['type'],
				);
			}
		}

		$skipped = array();
		foreach ( self::SKIP_KEYS as $key ) {
			$skipped[ $key ] = isset( $cursor['skipped'][ $key ] ) ? (int) $cursor['skipped'][ $key ] : 0;
		}
		return array(
			'roots'        => $roots,
			'queue_offset' => isset( $cursor['queue_offset'] ) ? (int) $cursor['queue_offset'] : 0,
			'queue_end'    => (int) $cursor['queue_end'],
			'list_end'     => isset( $cursor['list_end'] ) ? (int) $cursor['list_end'] : 0,
			'dir'          => isset( $cursor['dir'] ) && is_string( $cursor['dir'] ) ? $cursor['dir'] : null,
			'dir_skip'     => isset( $cursor['dir_skip'] ) ? (int) $cursor['dir_skip'] : 0,
			'files'        => isset( $cursor['files'] ) ? (int) $cursor['files'] : 0,
			'bytes'        => isset( $cursor['bytes'] ) ? (int) $cursor['bytes'] : 0,
			'skipped'      => $skipped,
			'warned'       => isset( $cursor['warned'] ) ? (int) $cursor['warned'] : 0,
		);
	}
}
