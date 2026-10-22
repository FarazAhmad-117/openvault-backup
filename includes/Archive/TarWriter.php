<?php
/**
 * Streaming TAR format (POSIX ustar with PAX extended headers).
 *
 * Phase: MVP
 *
 * Purpose: Builds TAR headers as strings, so an archiver can stream a file in chunks, stop inside it and continue
 * in the next run. PharData cannot do that (it needs the whole archive in one run and holds file handles open).
 *
 * Format facts:
 *  - 512 byte blocks. A header holds name, mode, uid, gid, size, mtime, checksum, type, magic "ustar", version "00"
 *    and the name prefix. Mode is 0644, uid and gid 0, user and group names empty (nothing about the server leaks).
 *  - A name of up to 100 bytes goes into the name field; a longer ASCII name is split at a "/" into the 155 byte
 *    prefix field and the 100 byte name field when that fits; anything else (longer, or not plain ASCII) is stored in a
 *    PAX extended header ("path" record) in front of the entry, and the ustar name field holds a shortened copy.
 *  - A size or mtime above 8 GiB - 1 (the 11 octal digit limit) is stored in a PAX record as well.
 *  - The body is padded with zeros to a multiple of 512; the archive ends with two zero blocks.
 *
 * parse_header() and parse_pax() read the same format back (used by the part check and the tests).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Archive;

use OpenVault\Backup\Engine\Scan\EntryName;

defined( 'ABSPATH' ) || exit;

/**
 * TAR format helper.
 */
final class TarWriter {

	const BLOCK     = 512;
	const MAX_OCTAL = 8589934591;

	/**
	 * Header (and, when needed, a PAX header) of a regular file.
	 *
	 * @param string $name  Entry name (see EntryName).
	 * @param int    $size  Size of the body in bytes.
	 * @param int    $mtime Modification time (unix).
	 * @return string One or more 512 byte blocks.
	 */
	public static function entry_header( $name, $size, $mtime ) {
		$size   = max( 0, (int) $size );
		$mtime  = max( 0, (int) $mtime );
		$pax    = array();
		$prefix = '';
		$short  = $name;

		if ( 1 === preg_match( '/[^\x20-\x7e]/', $name ) ) {
			$pax['path'] = $name;
			$short       = substr( (string) preg_replace( '/[^\x20-\x7e]/', '_', $name ), 0, 100 );
		} elseif ( strlen( $name ) > 100 ) {
			$split = self::split_name( $name );
			if ( null === $split ) {
				$pax['path'] = $name;
				$short       = substr( $name, 0, 100 );
			} else {
				list( $prefix, $short ) = $split;
			}
		}

		$size_field = $size;
		if ( $size > self::MAX_OCTAL ) {
			$pax['size'] = (string) $size;
			$size_field  = 0;
		}
		$mtime_field = $mtime;
		if ( $mtime > self::MAX_OCTAL ) {
			$pax['mtime'] = (string) $mtime;
			$mtime_field  = 0;
		}

		$out = '';
		if ( $pax ) {
			$out = self::pax_blocks( $pax, $short );
		}
		return $out . self::ustar_block( $short, $prefix, $size_field, $mtime_field, '0', 0644 );
	}

	/**
	 * Padding that follows a body of $size bytes.
	 *
	 * @param int $size Body size.
	 * @return int Zero bytes to write (0 to 511).
	 */
	public static function padding( $size ) {
		return (int) ( ( self::BLOCK - ( (int) $size % self::BLOCK ) ) % self::BLOCK );
	}

	/**
	 * The end of the archive: two zero blocks.
	 *
	 * @return string
	 */
	public static function end_blocks() {
		return str_repeat( "\0", self::BLOCK * 2 );
	}

	/**
	 * Read one 512 byte header block.
	 *
	 * @param string $block Block.
	 * @return array{name:string,size:int,type:string}|null Null when the checksum is wrong or the block is not a header.
	 */
	public static function parse_header( $block ) {
		if ( strlen( $block ) !== self::BLOCK ) {
			return null;
		}
		$stored = octdec( trim( substr( $block, 148, 8 ), "\0 " ) );
		$check  = substr( $block, 0, 148 ) . '        ' . substr( $block, 156 );
		if ( array_sum( unpack( 'C*', $check ) ) !== (int) $stored ) {
			return null;
		}
		$name   = rtrim( substr( $block, 0, 100 ), "\0" );
		$prefix = rtrim( substr( $block, 345, 155 ), "\0" );
		return array(
			'name' => '' === $prefix ? $name : $prefix . '/' . $name,
			'size' => self::parse_size_field( substr( $block, 124, 12 ) ),
			'type' => substr( $block, 156, 1 ),
		);
	}

	/**
	 * Read the 12 byte size field: octal text, or (GNU tar, sizes of 8 GiB and more) a big-endian number after a 0x80 marker byte.
	 *
	 * @param string $field Field.
	 * @return int Size, never negative (a value that does not fit in an int becomes PHP_INT_MAX).
	 */
	private static function parse_size_field( $field ) {
		if ( '' !== $field && 0 !== ( ord( $field[0] ) & 0x80 ) ) {
			$value = ord( $field[0] ) & 0x7f;
			for ( $i = 1, $n = strlen( $field ); $i < $n; $i++ ) {
				if ( $value > ( PHP_INT_MAX >> 8 ) ) {
					return PHP_INT_MAX;
				}
				$value = ( $value << 8 ) | ord( $field[ $i ] );
			}
			return $value;
		}
		$digits = trim( $field, "\0 " );
		return 1 === preg_match( '/^[0-7]+\z/', $digits ) ? (int) octdec( $digits ) : 0;
	}

	/**
	 * Read the records of a PAX extended header body.
	 *
	 * @param string $data Body (without padding).
	 * @return array<string,string>
	 */
	public static function parse_pax( $data ) {
		$records = array();
		$offset  = 0;
		$length  = strlen( $data );
		while ( $offset < $length ) {
			$space = strpos( $data, ' ', $offset );
			if ( false === $space ) {
				break;
			}
			$record_length = (int) substr( $data, $offset, $space - $offset );
			if ( $record_length < 1 || $offset + $record_length > $length ) {
				break;
			}
			$record = substr( $data, $space + 1, $offset + $record_length - $space - 2 );
			$equals = strpos( $record, '=' );
			if ( false !== $equals ) {
				$records[ substr( $record, 0, $equals ) ] = substr( $record, $equals + 1 );
			}
			$offset += $record_length;
		}
		return $records;
	}

	/**
	 * Split a long ASCII name into prefix (up to 155 bytes) and name (up to 100 bytes) at a "/".
	 *
	 * @param string $name Name longer than 100 bytes.
	 * @return array{0:string,1:string}|null
	 */
	private static function split_name( $name ) {
		$position = strpos( $name, '/', max( 0, strlen( $name ) - 101 ) );
		if ( false === $position || $position < 1 || $position > 155 || strlen( $name ) - $position - 1 < 1 ) {
			return null;
		}
		return array( substr( $name, 0, $position ), substr( $name, $position + 1 ) );
	}

	/**
	 * PAX extended header: its own header block, the records, padding.
	 *
	 * @param array<string,string> $records Key => value.
	 * @param string               $name    Name of the entry (for a readable header name).
	 * @return string
	 */
	private static function pax_blocks( array $records, $name ) {
		$body = '';
		foreach ( $records as $key => $value ) {
			$text  = ' ' . $key . '=' . $value . "\n";
			$count = strlen( $text );
			$total = $count + strlen( (string) $count );
			if ( strlen( (string) $total ) > strlen( (string) $count ) ) {
				$total = $count + strlen( (string) $total );
			}
			$body .= $total . $text;
		}
		$header_name = 'PaxHeaders.0/' . substr( (string) preg_replace( '/[^A-Za-z0-9._-]/', '_', EntryName::basename( $name ) ), 0, 60 );
		return self::ustar_block( $header_name, '', strlen( $body ), 0, 'x', 0644 ) . $body . str_repeat( "\0", self::padding( strlen( $body ) ) );
	}

	/**
	 * One ustar header block.
	 *
	 * @param string $name   Name field (at most 100 bytes).
	 * @param string $prefix Prefix field (at most 155 bytes).
	 * @param int    $size   Size field value.
	 * @param int    $mtime  Mtime field value.
	 * @param string $type   Type flag.
	 * @param int    $mode   Mode.
	 * @return string
	 */
	private static function ustar_block( $name, $prefix, $size, $mtime, $type, $mode ) {
		$block = str_pad( substr( $name, 0, 100 ), 100, "\0" )
			. sprintf( '%07o', $mode ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%011o', $size ) . "\0"
			. sprintf( '%011o', $mtime ) . "\0"
			. '        '
			. $type
			. str_repeat( "\0", 100 )
			. "ustar\0" . '00'
			. str_repeat( "\0", 32 )
			. str_repeat( "\0", 32 )
			. str_repeat( "\0", 16 )
			. str_pad( substr( $prefix, 0, 155 ), 155, "\0" )
			. str_repeat( "\0", 12 );

		$sum = array_sum( unpack( 'C*', $block ) );
		return substr( $block, 0, 148 ) . sprintf( '%06o', $sum ) . "\0 " . substr( $block, 156 );
	}
}
