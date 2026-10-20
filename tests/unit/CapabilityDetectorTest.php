<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\CapabilityDetector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Archive\CapabilityDetector
 */
class CapabilityDetectorTest extends TestCase {

	/**
	 * Detector with fake probes.
	 *
	 * @param string[] $extensions Loaded extensions.
	 * @param string[] $classes    Existing classes.
	 * @return CapabilityDetector
	 */
	private function detector( array $extensions, array $classes ) {
		return new CapabilityDetector(
			static function ( $name ) use ( $extensions ) {
				return in_array( $name, $extensions, true );
			},
			static function ( $name ) use ( $classes ) {
				return in_array( $name, $classes, true );
			}
		);
	}

	/**
	 * @dataProvider combinations
	 */
	public function test_every_probe_combination( $zip_class, $phar_class, $zlib, $bz2, $expected ) {
		$classes    = array_merge( $zip_class ? array( 'ZipArchive' ) : array(), $phar_class ? array( 'PharData' ) : array() );
		$extensions = array_merge( $zlib ? array( 'zlib' ) : array(), $bz2 ? array( 'bz2' ) : array() );
		$detector   = $this->detector( $extensions, $classes );

		foreach ( $expected as $id => $available ) {
			$this->assertSame( $available, $detector->is_available( $id ), $id );
			$this->assertSame( $available, $detector->formats()[ $id ]['available'], $id );
			$this->assertSame( $available, '' === $detector->reason_unavailable( $id ), $id . ' reason is empty exactly when available' );
		}
	}

	public function combinations() {
		$all  = array(
			'zip'       => true,
			'zip-store' => true,
			'tar.gz'    => true,
			'tar.bz2'   => true,
		);
		$none = array_fill_keys( array_keys( $all ), false );

		return array(
			'everything'                               => array( true, true, true, true, $all ),
			'nothing'                                  => array( false, false, false, false, $none ),
			'no zip'                                   => array(
				false,
				true,
				true,
				true,
				array_merge(
					$all,
					array(
						'zip'       => false,
						'zip-store' => false,
					)
				),
			),
			'no phar'                                  => array(
				true,
				false,
				true,
				true,
				array_merge(
					$all,
					array(
						'tar.bz2' => false,
					)
				),
			),
			'no zlib'                                  => array( true, true, false, true, array_merge( $all, array( 'tar.gz' => false ) ) ),
			'no bz2'                                   => array( true, true, true, false, array_merge( $all, array( 'tar.bz2' => false ) ) ),
			'zlib without phar (tar.gz needs no phar)' => array(
				true,
				false,
				true,
				false,
				array(
					'zip'       => true,
					'zip-store' => true,
					'tar.gz'    => true,
					'tar.bz2'   => false,
				),
			),
			'only tar.gz'                              => array(
				false,
				true,
				true,
				false,
				array(
					'zip'       => false,
					'zip-store' => false,
					'tar.gz'    => true,
					'tar.bz2'   => false,
				),
			),
		);
	}

	public function test_default_format_prefers_zip_then_tar_gz_then_null() {
		$this->assertSame( 'zip', $this->detector( array( 'zlib' ), array( 'ZipArchive', 'PharData' ) )->default_format() );
		$this->assertSame( 'tar.gz', $this->detector( array( 'zlib' ), array() )->default_format() );
		$this->assertNull( $this->detector( array( 'bz2' ), array( 'PharData' ) )->default_format(), 'tar.bz2 alone is never the default' );
		$this->assertNull( $this->detector( array(), array() )->default_format() );
	}

	public function test_reasons_are_specific() {
		$none = $this->detector( array(), array() );
		$this->assertStringContainsString( 'zip extension', $none->reason_unavailable( 'zip' ) );
		$this->assertStringContainsString( 'zip extension', $none->reason_unavailable( 'zip-store' ) );
		$this->assertStringContainsString( 'zlib extension', $none->reason_unavailable( 'tar.gz' ) );
		$this->assertStringContainsString( 'phar extension', $none->reason_unavailable( 'tar.bz2' ) );

		$phar_only = $this->detector( array(), array( 'PharData' ) );
		$this->assertStringContainsString( 'zlib extension', $phar_only->reason_unavailable( 'tar.gz' ) );
		$this->assertStringContainsString( 'bz2 extension', $phar_only->reason_unavailable( 'tar.bz2' ) );

		$zlib_only = $this->detector( array( 'zlib' ), array() );
		$this->assertSame( '', $zlib_only->reason_unavailable( 'tar.gz' ), 'tar.gz is written without PharData' );
	}

	public function test_formats_have_the_documented_shape() {
		$formats = $this->detector( array( 'zlib', 'bz2' ), array( 'ZipArchive', 'PharData' ) )->formats();

		$this->assertSame( array( 'zip', 'zip-store', 'tar.gz', 'tar.bz2' ), array_keys( $formats ) );
		foreach ( $formats as $id => $format ) {
			$this->assertSame( $id, $format['id'] );
			$this->assertIsBool( $format['available'] );
			$this->assertNotSame( '', $format['label'] );
			$this->assertNotSame( '', $format['best_for'] );
			$this->assertContains( $format['phase'], array( 'mvp', 'phase2' ) );
			$this->assertSame( 'phase2' === $format['phase'], $format['phase2'] );
		}
		$this->assertSame( 'phase2', $formats['tar.bz2']['phase'] );
		$this->assertTrue( $formats['tar.bz2']['available'], 'reported available when the extensions exist, the UI hides phase 2' );
		$this->assertSame( 'mvp', $formats['zip']['phase'] );
		$this->assertSame( 'mvp', $formats['tar.gz']['phase'] );
	}

	public function test_unknown_ids_are_not_available() {
		$detector = $this->detector( array( 'zlib', 'bz2' ), array( 'ZipArchive', 'PharData' ) );
		$this->assertFalse( $detector->is_available( '7z' ) );
		$this->assertFalse( $detector->is_available( '' ) );
		$this->assertFalse( $detector->is_available( null ) );
		$this->assertSame( '', $detector->reason_unavailable( '7z' ) );
	}

	public function test_real_server_detector_works() {
		$detector = new CapabilityDetector();
		$this->assertSame( class_exists( 'ZipArchive' ), $detector->is_available( 'zip' ) );
		$this->assertSame( extension_loaded( 'zlib' ), $detector->is_available( 'tar.gz' ) );
	}
}
