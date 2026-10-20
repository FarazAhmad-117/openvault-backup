<?php
/**
 * Rolls over to a new archive part at the size limit.
 *
 * Phase: MVP
 *
 * Purpose: Rolls over to a new archive part at the size limit.
 *
 * Responsibilities:
 *  - Part size limit from ovb_settings['part_size_mb']: default 250 MB, never below 10 MB, never above 4096 MB
 *  - should_roll(): decide before a file is added whether to start the next part. The load is the real compressed
 *    size for TAR.GZ and the uncompressed bytes added so far for ZIP (an estimate, ZIP compresses at the end of a slice).
 *    A part can exceed the limit by less than one file: the file that crosses the limit stays in the part, and a file
 *    that alone is as big as the limit gets a part of its own.
 *  - Part names: db.part01.<ext> (holds db/db.sql), files.part01.<ext>, files.part02.<ext> ...
 *
 * Pure: no WordPress, no filesystem.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Part splitter.
 */
final class Splitter {

	const DEFAULT_MB = 250;
	const MIN_MB     = 10;
	const MAX_MB     = 4096;

	const KIND_DB    = 'db';
	const KIND_FILES = 'files';

	/**
	 * Part size limit in bytes from the settings.
	 *
	 * @param array<string,mixed> $settings Settings (key part_size_mb).
	 * @return int
	 */
	public static function limit_bytes( array $settings ) {
		$mb = self::DEFAULT_MB;
		if ( isset( $settings['part_size_mb'] ) && is_numeric( $settings['part_size_mb'] ) ) {
			$mb = (int) $settings['part_size_mb'];
		}
		return max( self::MIN_MB, min( self::MAX_MB, $mb ) ) * 1048576;
	}

	/**
	 * Whether the next file must go into a new part.
	 *
	 * @param int $part_load  Load of the current part (see the class description).
	 * @param int $part_files Files already in the current part.
	 * @param int $next_size  Size of the file about to be added.
	 * @param int $limit      Limit in bytes.
	 * @return bool
	 */
	public static function should_roll( $part_load, $part_files, $next_size, $limit ) {
		if ( $part_files < 1 ) {
			return false;
		}
		return $part_load >= $limit || $next_size >= $limit;
	}

	/**
	 * File name of a part.
	 *
	 * @param string $kind      db or files.
	 * @param int    $index     Number within its kind, starting at 1.
	 * @param string $extension Extension without the dot ("zip", "tar.gz").
	 * @return string
	 * @throws \InvalidArgumentException For an unknown kind, an index below 1 or an unsafe extension.
	 */
	public static function part_name( $kind, $index, $extension ) {
		if ( ! in_array( $kind, array( self::KIND_DB, self::KIND_FILES ), true ) || $index < 1 || 1 !== preg_match( '/^[a-z0-9.]{1,12}\z/', (string) $extension ) ) {
			throw new \InvalidArgumentException( 'Invalid archive part.' );
		}
		return sprintf( '%s.part%02d.%s', $kind, (int) $index, $extension );
	}

	/**
	 * Kind and number of a part from its file name.
	 *
	 * @param string $name File name.
	 * @return array{kind:string,index:int}|null Null when the name is not one of ours.
	 */
	public static function parse_part_name( $name ) {
		if ( 1 !== preg_match( '/^(db|files)\.part(\d{2,})\.[a-z0-9.]{1,12}\z/', (string) $name, $found ) ) {
			return null;
		}
		return array(
			'kind'  => $found[1],
			'index' => (int) $found[2],
		);
	}
}
