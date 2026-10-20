<?php
/**
 * Detects which archive formats this server supports.
 *
 * Phase: MVP
 *
 * Purpose: Detects which formats this server supports (docs/plan/05-archive-formats.md).
 *
 * Responsibilities:
 *  - Feeds UI so only available formats are shown with explanations
 *  - Feeds preflight so a job never starts with a format the server cannot write
 *  - Probes (extension_loaded, class_exists) are injectable, so every combination is unit-testable
 *
 * Requirements: zip and zip-store need the ZipArchive class; tar.gz needs only the zlib extension (the archive is
 * written by Archive\TarWriter + deflate_add(), PharData is not used); tar.bz2 (Phase 2, hidden in the UI for now)
 * needs PharData and the bz2 extension.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Format capability detection.
 */
class CapabilityDetector {

	const ZIP       = 'zip';
	const ZIP_STORE = 'zip-store';
	const TAR_GZ    = 'tar.gz';
	const TAR_BZ2   = 'tar.bz2';

	const IDS = array( self::ZIP, self::ZIP_STORE, self::TAR_GZ, self::TAR_BZ2 );

	/**
	 * Probe: extension_loaded().
	 *
	 * @var callable
	 */
	private $extension_loaded;

	/**
	 * Probe: class_exists().
	 *
	 * @var callable
	 */
	private $class_exists;

	/**
	 * Constructor.
	 *
	 * @param callable|null $extension_loaded Called with an extension name, returns bool. Defaults to extension_loaded().
	 * @param callable|null $class_exists     Called with a class name, returns bool. Defaults to class_exists().
	 */
	public function __construct( ?callable $extension_loaded = null, ?callable $class_exists = null ) {
		$this->extension_loaded = null === $extension_loaded ? 'extension_loaded' : $extension_loaded;
		$this->class_exists     = null === $class_exists ? 'class_exists' : $class_exists;
	}

	/**
	 * All formats with their availability.
	 *
	 * @return array<string,array{id:string,label:string,available:bool,best_for:string,reason_unavailable:string,phase:string,phase2:bool}>
	 */
	public function formats() {
		$zip_reason = $this->has_class( 'ZipArchive' ) ? '' : __( 'The PHP zip extension is not installed.', 'openvault-backup' );

		$tar_reason = '';
		if ( ! $this->has_class( 'PharData' ) ) {
			$tar_reason = __( 'The PHP phar extension is not installed.', 'openvault-backup' );
		}

		$gz_reason = $this->has_extension( 'zlib' ) ? '' : __( 'The PHP zlib extension is not installed.', 'openvault-backup' );

		$bz2_reason = $tar_reason;
		if ( '' === $bz2_reason && ! $this->has_extension( 'bz2' ) ) {
			$bz2_reason = __( 'The PHP bz2 extension is not installed.', 'openvault-backup' );
		}

		return array(
			self::ZIP       => $this->entry( self::ZIP, __( 'ZIP (compressed)', 'openvault-backup' ), __( 'Most sites. Opens everywhere and any single file can be extracted. Slightly larger than TAR.GZ.', 'openvault-backup' ), $zip_reason, 'mvp' ),
			self::ZIP_STORE => $this->entry( self::ZIP_STORE, __( 'ZIP (store, no compression)', 'openvault-backup' ), __( 'Sites that are mostly images, video or other files that are already compressed. Fastest and uses almost no CPU, but the archive is bigger.', 'openvault-backup' ), $zip_reason, 'mvp' ),
			self::TAR_GZ    => $this->entry( self::TAR_GZ, __( 'TAR.GZ', 'openvault-backup' ), __( 'Smaller archives that stream well. You cannot extract a single file without reading the archive.', 'openvault-backup' ), $gz_reason, 'mvp' ),
			self::TAR_BZ2   => $this->entry( self::TAR_BZ2, __( 'TAR.BZ2', 'openvault-backup' ), __( 'Smallest archives, but slow. Planned for a later release.', 'openvault-backup' ), $bz2_reason, 'phase2' ),
		);
	}

	/**
	 * Whether a format can be written on this server.
	 *
	 * @param string $id Format id (zip, zip-store, tar.gz, tar.bz2).
	 * @return bool
	 */
	public function is_available( $id ) {
		$formats = $this->formats();
		return is_string( $id ) && isset( $formats[ $id ] ) && $formats[ $id ]['available'];
	}

	/**
	 * Why a format cannot be used ('' when it can, or when the id is unknown).
	 *
	 * @param string $id Format id.
	 * @return string
	 */
	public function reason_unavailable( $id ) {
		$formats = $this->formats();
		return is_string( $id ) && isset( $formats[ $id ] ) ? $formats[ $id ]['reason_unavailable'] : '';
	}

	/**
	 * Best default: ZIP if available, else TAR.GZ, else null (no archive format works on this server).
	 *
	 * @return string|null
	 */
	public function default_format() {
		foreach ( array( self::ZIP, self::TAR_GZ ) as $id ) {
			if ( $this->is_available( $id ) ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Build one entry.
	 *
	 * @param string $id       Id.
	 * @param string $label    Label.
	 * @param string $best_for Short text.
	 * @param string $reason   Reason it is unavailable ('' = available).
	 * @param string $phase    mvp or phase2.
	 * @return array{id:string,label:string,available:bool,best_for:string,reason_unavailable:string,phase:string,phase2:bool}
	 */
	private function entry( $id, $label, $best_for, $reason, $phase ) {
		return array(
			'id'                 => $id,
			'label'              => $label,
			'available'          => '' === $reason,
			'best_for'           => $best_for,
			'reason_unavailable' => $reason,
			'phase'              => $phase,
			'phase2'             => 'phase2' === $phase,
		);
	}

	/**
	 * Probe a class.
	 *
	 * @param string $name Class.
	 * @return bool
	 */
	private function has_class( $name ) {
		return (bool) call_user_func( $this->class_exists, $name, false );
	}

	/**
	 * Probe an extension.
	 *
	 * @param string $name Extension.
	 * @return bool
	 */
	private function has_extension( $name ) {
		return (bool) call_user_func( $this->extension_loaded, $name );
	}
}
