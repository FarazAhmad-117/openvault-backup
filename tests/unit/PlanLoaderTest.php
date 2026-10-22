<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real folders in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Restore\PlanLoader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RestoreFixtures.php';

/**
 * @covers \OpenVault\Backup\Restore\PlanLoader
 */
class PlanLoaderTest extends TestCase {

	public function test_a_valid_manifest_of_the_same_backup_is_accepted() {
		$result = PlanLoader::parse( RestoreFixtures::manifest()->to_json(), RestoreFixtures::UUID );

		$this->assertNotNull( $result['manifest'] );
		$this->assertSame( '', $result['error'] );
		$this->assertFalse( $result['newer'] );
	}

	public function test_the_uuid_comparison_ignores_case() {
		$this->assertNotNull( PlanLoader::parse( RestoreFixtures::manifest()->to_json(), strtoupper( RestoreFixtures::UUID ) )['manifest'] );
	}

	public function test_a_manifest_of_another_backup_is_refused() {
		$result = PlanLoader::parse( RestoreFixtures::manifest()->to_json(), '11111111-2222-4333-8444-555555555555' );

		$this->assertNull( $result['manifest'] );
		$this->assertStringContainsString( 'different backup', $result['error'] );
	}

	/**
	 * @dataProvider damaged
	 */
	public function test_damaged_input_is_refused_without_echoing_it( $json ) {
		$result = PlanLoader::parse( $json, RestoreFixtures::UUID );

		$this->assertNull( $result['manifest'] );
		$this->assertFalse( $result['newer'] );
		$this->assertStringContainsString( 'damaged or was not created by OpenVault Backup', $result['error'] );
		$this->assertStringNotContainsString( 'evil', $result['error'] );
	}

	/**
	 * JSON of the fixture manifest with some fields replaced.
	 *
	 * @param array<string,mixed> $override Fields.
	 * @return string
	 */
	private static function manifest_json( array $override ) {
		$fields = json_decode( RestoreFixtures::manifest()->to_json(), true );
		return (string) json_encode( array_merge( $fields, $override ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function damaged() {
		$bad_part = array(
			'name'   => '../evil.zip',
			'size'   => 1,
			'sha256' => str_repeat( 'a', 64 ),
			'kind'   => 'db',
			'index'  => 0,
		);
		return array(
			'empty'               => array( '' ),
			'not json'            => array( 'evil <script>' ),
			'a list'              => array( '[1,2,3]' ),
			'another generator'   => array( self::manifest_json( array( 'generator' => 'evil' ) ) ),
			'traversal in a part' => array( self::manifest_json( array( 'parts' => array( $bad_part ) ) ) ),
			'older schema'        => array( self::manifest_json( array( 'schema' => 0 ) ) ),
			'oversized'           => array( str_repeat( 'x', 6 * 1048576 ) ),
		);
	}

	public function test_a_manifest_of_a_newer_schema_means_a_newer_plugin() {
		$result = PlanLoader::parse(
			self::manifest_json(
				array(
					'schema'          => 2,
					'brand_new_field' => true,
				)
			),
			RestoreFixtures::UUID
		);

		$this->assertNull( $result['manifest'] );
		$this->assertTrue( $result['newer'] );
		$this->assertStringContainsString( 'newer version of OpenVault Backup', $result['error'] );
	}

	public function test_a_foreign_document_with_a_high_schema_is_not_called_newer() {
		$json   = (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
			array(
				'generator' => 'someone-else',
				'schema'    => 9,
			)
		);
		$result = PlanLoader::parse( $json, RestoreFixtures::UUID );

		$this->assertFalse( $result['newer'] );
	}

	public function test_list_files_returns_regular_files_only() {
		$dir = sys_get_temp_dir() . '/ovb-list-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/sub', 0777, true );
		file_put_contents( $dir . '/a.zip', 'abc' );
		file_put_contents( $dir . '/manifest.json', '{}' );
		file_put_contents( $dir . '/sub/inner.zip', 'zzz' );
		$linked = @symlink( $dir . '/a.zip', $dir . '/link.zip' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Symlinks may be unavailable.

		$list = PlanLoader::list_files( $dir );
		CleanupStep::remove_tree( $dir );

		$this->assertSame(
			array(
				'a.zip'         => 3,
				'manifest.json' => 2,
			),
			$list,
			$linked ? 'links and folders are left out' : 'folders are left out'
		);
		$this->assertSame( array(), PlanLoader::list_files( $dir . '-missing' ) );
	}
}
