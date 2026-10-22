<?php
/**
 * The rollback journal of a files restore: line format, parser and backwards reader (pure).
 *
 * Phase: MVP
 *
 * Purpose: A files restore overwrites existing files and creates new ones. Before it touches a file it writes ONE LINE here (write-ahead), so a
 * crash can never lose an original and Undo knows what to put back. The journal is a text file inside the plugin's protected storage folder
 * ("<local dir>/undo/restore-<id>/journal.log"), append-only, UTF-8, one action per line, columns separated by a TAB (names cannot contain a tab
 * or a newline: Engine\Scan\EntryName refuses control characters).
 *
 * Lines (the name is the archive entry name, for example "content/uploads/2026/a.jpg"; the copy of an overwritten original is stored under
 * "<undo dir>/files/<entry>"):
 *  - H  1  <restore id>  <unix time>  <json of the contents flags of the backup>      header, first line; the flags give the roots again at undo time
 *  - O  <entry>  <size>  <sha256>       an existing file is about to be overwritten; its original was copied first (size and SHA-256 of the copy)
 *  - C  <entry>                         a file is about to be created (it did not exist)
 *  - D  <entry>                         a folder is about to be created
 *  - S  <url-encoded name>  <reason>    an entry was skipped (informational; only the first lines are written)
 *
 * Replay (Undo) reads the journal BACKWARDS: O restores the saved original, C removes the created file, D removes the created folder if it is
 * empty. Backwards order removes files before the folder that was created for them. Duplicate lines (a slice that ran again after a crash) are
 * harmless because a saved original is never replaced by a later copy ("the first copy is the original"): a file with [O][O] is restored twice
 * from the same copy, a file with [C][O] ends up removed (the C line is replayed last).
 *
 * A line is complete only when it ends in a newline: a fragment at the end of the file (a crash while writing) is ignored.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Restore;

use OpenVault\Backup\Engine\Scan\EntryName;

defined( 'ABSPATH' ) || exit;

/**
 * Undo journal format.
 */
final class UndoJournal {

	const VERSION    = 1;
	const MAX_LINE   = 8192;
	const MAX_SKIPS  = 500;
	const BLOCK_SIZE = 4096;

	/**
	 * The header line (without newline).
	 *
	 * @param int                 $restore_id Restore id.
	 * @param int                 $time       Unix time.
	 * @param array<string,mixed> $contents   Contents flags of the backup.
	 * @return string
	 */
	public static function header( $restore_id, $time, array $contents ) {
		$flags = array();
		foreach ( $contents as $key => $value ) {
			if ( true === $value && is_string( $key ) && 1 === preg_match( '/^[a-z_]{1,20}\z/', $key ) ) {
				$flags[ $key ] = true;
			}
		}
		return 'H' . "\t" . self::VERSION . "\t" . (int) $restore_id . "\t" . (int) $time . "\t" . self::flags_json( $flags );
	}

	/**
	 * Line of an overwritten file.
	 *
	 * @param string $entry  Entry name.
	 * @param int    $size   Size of the saved original.
	 * @param string $sha256 SHA-256 of the saved original ('' when unknown).
	 * @return string
	 * @throws \InvalidArgumentException For an unsafe name.
	 */
	public static function overwritten( $entry, $size, $sha256 ) {
		self::assert_name( $entry );
		$sha256 = 1 === preg_match( '/^[0-9a-f]{64}\z/', (string) $sha256 ) ? (string) $sha256 : '';
		return 'O' . "\t" . $entry . "\t" . max( 0, (int) $size ) . "\t" . $sha256;
	}

	/**
	 * Line of a created file.
	 *
	 * @param string $entry Entry name.
	 * @return string
	 * @throws \InvalidArgumentException For an unsafe name.
	 */
	public static function created( $entry ) {
		self::assert_name( $entry );
		return 'C' . "\t" . $entry;
	}

	/**
	 * Line of a created folder.
	 *
	 * @param string $entry Entry name of the folder.
	 * @return string
	 * @throws \InvalidArgumentException For an unsafe name.
	 */
	public static function folder( $entry ) {
		self::assert_name( $entry );
		return 'D' . "\t" . $entry;
	}

	/**
	 * Line of a skipped entry. The name comes from an archive and is not trusted: it is cut and URL-encoded, so a tab or newline in it can never
	 * break the format.
	 *
	 * @param mixed  $name   Entry name from the archive.
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function skipped( $name, $reason ) {
		$clean = rawurlencode( substr( (string) $name, 0, 200 ) );
		$why   = (string) preg_replace( '/[^a-z_]/', '', (string) $reason );
		return 'S' . "\t" . $clean . "\t" . $why;
	}

	/**
	 * Parse one complete line (without newline).
	 *
	 * @param string $line Line.
	 * @return array{op:string,entry:string,size:int,sha256:string,reason:string}|null Null for a malformed or unknown line.
	 */
	public static function parse_line( $line ) {
		if ( ! is_string( $line ) || '' === $line || strlen( $line ) > self::MAX_LINE ) {
			return null;
		}
		$parts = explode( "\t", $line );
		$op    = $parts[0];
		$blank = array(
			'op'     => $op,
			'entry'  => '',
			'size'   => 0,
			'sha256' => '',
			'reason' => '',
		);

		if ( 'O' === $op && 4 === count( $parts ) && EntryName::is_safe( $parts[1] ) && 1 === preg_match( '/^[0-9]{1,18}\z/', $parts[2] ) && ( '' === $parts[3] || 1 === preg_match( '/^[0-9a-f]{64}\z/', $parts[3] ) ) ) {
			return array_merge(
				$blank,
				array(
					'entry'  => $parts[1],
					'size'   => (int) $parts[2],
					'sha256' => $parts[3],
				)
			);
		}
		if ( ( 'C' === $op || 'D' === $op ) && 2 === count( $parts ) && EntryName::is_safe( $parts[1] ) ) {
			return array_merge( $blank, array( 'entry' => $parts[1] ) );
		}
		if ( 'S' === $op && 3 === count( $parts ) ) {
			return array_merge( $blank, array( 'reason' => $parts[2] ) );
		}
		if ( 'H' === $op && 5 === count( $parts ) && (string) self::VERSION === $parts[1] ) {
			return $blank;
		}
		return null;
	}

	/**
	 * The contents flags of a header line.
	 *
	 * @param string $line Header line (without newline).
	 * @return array<string,bool>|null Null when it is not a valid header.
	 */
	public static function header_contents( $line ) {
		$parts = explode( "\t", (string) $line );
		if ( 5 !== count( $parts ) || 'H' !== $parts[0] || (string) self::VERSION !== $parts[1] ) {
			return null;
		}
		$data = json_decode( $parts[4], true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( true === $value && is_string( $key ) && 1 === preg_match( '/^[a-z_]{1,20}\z/', $key ) ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	/**
	 * Offset just after the last complete line of a file (a trailing fragment without newline is excluded). 0 when there is no complete line.
	 *
	 * @param string $path Journal file.
	 * @return int
	 */
	public static function complete_end( $path ) {
		clearstatcache( true, $path );
		$size = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( $size < 1 ) {
			return 0;
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Own journal, read backwards in blocks.
		if ( false === $handle ) {
			return 0;
		}
		$pos = $size;
		while ( $pos > 0 ) {
			$from = max( 0, $pos - self::BLOCK_SIZE );
			fseek( $handle, $from );
			$block = (string) fread( $handle, $pos - $from ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Own journal.
			$at    = strrpos( $block, "\n" );
			if ( false !== $at ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Own journal.
				return $from + $at + 1;
			}
			$pos = $from;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Own journal.
		return 0;
	}

	/**
	 * The complete line that ends just before offset $end (which must follow a newline), and where it starts.
	 *
	 * @param resource $handle Open journal.
	 * @param int      $end    Offset after a newline (the end of the unprocessed part).
	 * @return array{0:string,1:int}|null Line text and its start offset; null when there is nothing before $end. A line longer than MAX_LINE comes back as ''.
	 */
	public static function line_before( $handle, $end ) {
		if ( $end < 1 ) {
			return null;
		}
		// The line ends at $end - 1 (the newline). Search backwards for the newline that ends the line before it.
		$pos  = $end - 1;
		$buf  = '';
		$long = false;
		while ( $pos > 0 ) {
			$from = max( 0, $pos - self::BLOCK_SIZE );
			fseek( $handle, $from );
			$block = (string) fread( $handle, $pos - $from ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Own journal.
			$at    = strrpos( $block, "\n" );
			if ( false !== $at ) {
				$tail  = substr( $block, $at + 1 );
				$start = $from + $at + 1;
				return array( $long || strlen( $buf ) + strlen( $tail ) > self::MAX_LINE ? '' : $tail . $buf, $start );
			}
			if ( ! $long ) {
				$buf = $block . $buf;
				if ( strlen( $buf ) > self::MAX_LINE ) {
					// Not a journal line: keep looking for where it starts, but stop collecting it.
					$long = true;
					$buf  = '';
				}
			}
			$pos = $from;
		}
		return array( $long || strlen( $buf ) > self::MAX_LINE ? '' : $buf, 0 );
	}

	/**
	 * Whether a name may be written to the journal. Pure.
	 *
	 * @param mixed $entry Name.
	 * @return void
	 * @throws \InvalidArgumentException When it is not a safe entry name.
	 */
	private static function assert_name( $entry ) {
		if ( ! EntryName::is_safe( $entry ) ) {
			throw new \InvalidArgumentException( 'Unsafe name for the undo journal.' );
		}
	}

	/**
	 * JSON of a small flag map (works without WordPress, for the unit tests).
	 *
	 * @param array<string,bool> $flags Flags.
	 * @return string
	 */
	private static function flags_json( array $flags ) {
		$json = json_encode( (object) $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Works without WordPress loaded (unit tests); a fixed flag map.
		return false === $json ? '{}' : $json;
	}
}
