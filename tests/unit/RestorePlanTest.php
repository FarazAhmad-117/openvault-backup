<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Restore\RestorePlan;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RestoreFixtures.php';

/**
 * @covers \OpenVault\Backup\Restore\RestorePlan
 */
class RestorePlanTest extends TestCase {

	private function plan( array $rows, array $local, $mode = 'full', array $tried = array(), $manifest = null ) {
		$manifest = null === $manifest ? RestoreFixtures::manifest() : $manifest;
		return RestorePlan::build( RestoreFixtures::backup(), $rows, $manifest, $mode, $local, $tried );
	}

	private function names( array $files ) {
		return array_column( $files, 'name' );
	}

	public function test_local_only_copy_is_used_in_place() {
		$m    = RestoreFixtures::manifest();
		$plan = $this->plan( RestoreFixtures::rows( $m, 0 ), RestoreFixtures::local_files( $m ) );

		$this->assertSame( 0, $plan->chosen_source() );
		$this->assertTrue( $plan->is_local() );
		$this->assertSame( 0, $plan->download_bytes() );
		$this->assertSame( array(), $plan->fallback_sources() );
		$this->assertSame( array( 'manifest.json', 'db.part01.zip', 'files.part01.zip', 'files.part02.zip' ), $this->names( $plan->files() ) );
		$this->assertSame( 'manifest', $plan->files()[0]['kind'] );
		$this->assertSame( '', $plan->files()[1]['ref'] );
	}

	public function test_remote_only_copy_is_chosen_when_the_local_files_are_gone() {
		$m    = RestoreFixtures::manifest();
		$rows = array_merge( RestoreFixtures::rows( $m, 0 ), RestoreFixtures::rows( $m, 5 ) );
		$plan = $this->plan( $rows, array() );

		$this->assertSame( 5, $plan->chosen_source() );
		$this->assertFalse( $plan->is_local() );
		$this->assertSame( 3100 + RestoreFixtures::MANIFEST_SIZE, $plan->download_bytes() );
		$files = $plan->files();
		$this->assertSame( 'OpenVault-Backups/example.com/2026-03-04_050607_3f2b8c1e/manifest.json', $files[0]['ref'] );
		$this->assertSame( 'manifest_missing', $plan->sources()[0]['reason'] );
	}

	public function test_local_copy_is_preferred_and_destinations_are_fallbacks_in_id_order() {
		$m    = RestoreFixtures::manifest();
		$rows = array_merge( RestoreFixtures::rows( $m, 9 ), RestoreFixtures::rows( $m, 0 ), RestoreFixtures::rows( $m, 5 ) );
		$plan = $this->plan( $rows, RestoreFixtures::local_files( $m ) );

		$this->assertSame( 0, $plan->chosen_source() );
		$this->assertSame( array( 5, 9 ), $plan->fallback_sources() );
		$this->assertSame( array( 0, 5, 9 ), array_column( $plan->sources(), 'id' ) );
	}

	public function test_a_destination_with_an_incomplete_copy_is_ignored() {
		$m    = RestoreFixtures::manifest();
		$rows = array_merge(
			RestoreFixtures::rows( $m, 5, array( 'files.part02.zip' => array( 'upload_status' => 'uploading' ) ) ),
			RestoreFixtures::rows( $m, 6, array( 'manifest.json' => array( 'upload_status' => 'pending' ) ) ),
			RestoreFixtures::rows( $m, 7, array( 'db.part01.zip' => array( 'remote_ref' => '' ) ) ),
			RestoreFixtures::rows( $m, 8, array( 'files.part01.zip' => array( 'size' => 5 ) ) ),
			RestoreFixtures::rows( $m, 9 )
		);
		$plan = $this->plan( $rows, array() );

		$this->assertSame( 9, $plan->chosen_source() );
		$this->assertSame( array( 'part_missing', 'manifest_missing', 'part_missing', 'part_size' ), array_slice( array_column( $plan->sources(), 'reason' ), 1, 4 ) );
	}

	public function test_a_missing_manifest_or_part_or_wrong_size_makes_the_local_copy_incomplete() {
		$m = RestoreFixtures::manifest();

		$no_manifest = RestoreFixtures::local_files( $m );
		unset( $no_manifest['manifest.json'] );
		$no_part = RestoreFixtures::local_files( $m );
		unset( $no_part['files.part02.zip'] );
		$short                  = RestoreFixtures::local_files( $m );
		$short['db.part01.zip'] = 99;

		$this->assertSame( 'manifest_missing', $this->plan( array(), $no_manifest )->sources()[0]['reason'] );
		$this->assertSame( 'part_missing', $this->plan( array(), $no_part )->sources()[0]['reason'] );
		$this->assertSame( 'part_size', $this->plan( array(), $short )->sources()[0]['reason'] );
		$this->assertNull( $this->plan( array(), $short )->chosen_source() );
	}

	public function test_a_tried_source_is_skipped_so_the_next_one_is_used() {
		$m    = RestoreFixtures::manifest();
		$rows = array_merge( RestoreFixtures::rows( $m, 0 ), RestoreFixtures::rows( $m, 5 ) );
		$plan = $this->plan( $rows, RestoreFixtures::local_files( $m ), 'full', array( 0 ) );

		$this->assertSame( 5, $plan->chosen_source() );
		$this->assertTrue( $plan->sources()[0]['tried'] );
		$this->assertSame( array(), $plan->fallback_sources() );

		$this->assertNull( $this->plan( $rows, RestoreFixtures::local_files( $m ), 'full', array( 0, 5 ) )->chosen_source() );
	}

	public function test_mode_decides_which_parts_are_needed() {
		$m    = RestoreFixtures::manifest();
		$rows = RestoreFixtures::rows( $m, 0 );
		$loc  = RestoreFixtures::local_files( $m );

		$db    = $this->plan( $rows, $loc, 'db' );
		$files = $this->plan( $rows, $loc, 'files' );
		$full  = $this->plan( $rows, $loc, 'full' );

		$this->assertSame( array( 'db.part01.zip' ), array_column( $db->parts(), 'name' ) );
		$this->assertSame( array( 'files.part01.zip', 'files.part02.zip' ), array_column( $files->parts(), 'name' ) );
		$this->assertSame( array( 'db.part01.zip', 'files.part01.zip', 'files.part02.zip' ), array_column( $full->parts(), 'name' ) );
		$this->assertSame( array( true, false ), array( $db->restores_db(), $db->restores_files() ) );
		$this->assertSame( array( false, true ), array( $files->restores_db(), $files->restores_files() ) );
		$this->assertSame( array( true, true ), array( $full->restores_db(), $full->restores_files() ) );
		$this->assertSame( array( 'manifest.json', 'db.part01.zip' ), $this->names( $db->files() ) );
	}

	public function test_a_mode_the_backup_cannot_serve_has_no_parts() {
		$files_only = RestoreFixtures::manifest( array( 'parts' => array( RestoreFixtures::part( 'files.part01.zip', 10, 'files', 0 ) ) ) );
		$db_only    = RestoreFixtures::manifest( array( 'parts' => array( RestoreFixtures::part( 'db.part01.zip', 10, 'db', 0 ) ) ) );
		$empty      = RestoreFixtures::manifest( array( 'parts' => array() ) );

		$this->assertFalse( $this->plan( array(), array(), 'db', array(), $files_only )->has_parts_for_mode() );
		$this->assertTrue( $this->plan( array(), array(), 'files', array(), $files_only )->has_parts_for_mode() );
		$this->assertTrue( $this->plan( array(), array(), 'full', array(), $files_only )->has_parts_for_mode() );
		$this->assertFalse( $this->plan( array(), array(), 'files', array(), $db_only )->has_parts_for_mode() );
		$this->assertFalse( $this->plan( array(), array(), 'full', array(), $empty )->has_parts_for_mode() );
		$this->assertFalse( $this->plan( array(), array(), 'full', array(), $files_only )->restores_db() );
	}

	public function test_estimates_follow_the_documented_factors() {
		$m    = RestoreFixtures::manifest();
		$rows = array_merge( RestoreFixtures::rows( $m, 0 ), RestoreFixtures::rows( $m, 5 ) );

		$remote = $this->plan( $rows, array(), 'full' );
		// db 100 x 5 + files 3000 x 2.
		$this->assertSame( 6500, $remote->extract_bytes() );
		// The safety snapshot is a database backup: the database parts only.
		$this->assertSame( 100, $remote->snapshot_bytes() );
		// The journal estimate is the files parts x 2; it is inside the extract estimate (a file is new or overwritten, never both).
		$this->assertSame( 6000, $remote->journal_bytes() );
		$this->assertSame( 3100 + 500 + 6500 + 100 + 1000, $remote->required_free_bytes( 1000 ) );

		$files = $this->plan( $rows, array(), 'files' );
		$this->assertSame( 0, $files->snapshot_bytes(), 'a files-only restore takes no database snapshot' );
		$this->assertSame( 6000, $files->journal_bytes() );
		$this->assertSame( 0, $this->plan( $rows, array(), 'db' )->journal_bytes() );

		$db = $this->plan( $rows, array(), 'db' );
		$this->assertSame( 100 + 500, $db->download_bytes() );
		$this->assertSame( 500, $db->extract_bytes() );

		$store = RestoreFixtures::manifest( array( 'format' => 'zip-store' ) );
		$this->assertSame( 3100, $this->plan( array(), array(), 'full', array(), $store )->extract_bytes() );

		$local = $this->plan( RestoreFixtures::rows( $m, 0 ), RestoreFixtures::local_files( $m ), 'full' );
		$this->assertSame( 0, $local->download_bytes() );
		$this->assertSame( 6500 + 100, $local->required_free_bytes() );
	}

	public function test_summary_holds_the_facts_for_the_page() {
		$m       = RestoreFixtures::manifest();
		$summary = $this->plan( RestoreFixtures::rows( $m, 0 ), RestoreFixtures::local_files( $m ), 'files' )->summary();

		$this->assertSame( 7, $summary['backup_id'] );
		$this->assertSame( RestoreFixtures::UUID, $summary['uuid'] );
		$this->assertSame( '2026-03-04T05:06:07Z', $summary['created_at'] );
		$this->assertSame( 'manual', $summary['trigger'] );
		$this->assertSame( 'zip', $summary['format'] );
		$this->assertSame( array( 1, 2, 100, 3000 ), array( $summary['db_parts'], $summary['files_parts'], $summary['db_bytes'], $summary['files_bytes'] ) );
		$this->assertSame( array( 2, 3000 ), array( $summary['needed_parts'], $summary['needed_bytes'] ) );
		$this->assertSame(
			array(
				'db'      => true,
				'uploads' => true,
			),
			$summary['contents']
		);
		$this->assertSame( 'wp_', $summary['table_prefix'] );
		$this->assertSame( 'https://example.com', $summary['site_url'] );
		$this->assertFalse( $summary['encrypted'] );
	}

	public function test_to_array_has_no_file_names_or_paths_of_sources() {
		$m     = RestoreFixtures::manifest();
		$rows  = array_merge( RestoreFixtures::rows( $m, 0 ), RestoreFixtures::rows( $m, 5 ) );
		$array = $this->plan( $rows, array(), 'full' )->to_array();

		$this->assertSame( 5, $array['chosen_source'] );
		$this->assertSame( 4, $array['files_to_verify'] );
		$this->assertStringNotContainsString( 'OpenVault-Backups', (string) json_encode( $array ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_an_unknown_mode_is_rejected() {
		$this->expectException( \InvalidArgumentException::class );
		$this->plan( array(), array(), 'everything' );
	}
}
