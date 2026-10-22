<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise real folders in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Restore\PlanLoader;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestorePlan;
use OpenVault\Backup\Restore\Steps\VerifyStep;
use OpenVault\Backup\Support\Manifest;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RestoreFixtures.php';
require_once __DIR__ . '/RestoreDoubles.php';

/**
 * @covers \OpenVault\Backup\Restore\Steps\VerifyStep
 */
class VerifyStepTest extends TestCase {

	/**
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * @var MemoryLogger
	 */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = new MemoryLogger();
	}

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			CleanupStep::remove_tree( $dir );
		}
		parent::tearDown();
	}

	private function dir() {
		$dir = sys_get_temp_dir() . '/ovb-verify-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * Real files in a folder: three parts and a manifest that describes them.
	 *
	 * @return array{dir:string,manifest:Manifest,rows:array<int,array<string,mixed>>,json:string}
	 */
	private function backup( $destination_id = 0, $dir = null ) {
		$dir   = null === $dir ? $this->dir() : $dir;
		$parts = array();
		$index = 0;
		foreach ( array(
			'db.part01.zip'    => array( 'db', 4096 ),
			'files.part01.zip' => array( 'files', 20000 ),
			'files.part02.zip' => array( 'files', 5000 ),
		) as $name => $spec ) {
			$data = random_bytes( $spec[1] );
			file_put_contents( $dir . '/' . $name, $data );
			$parts[] = array(
				'name'   => $name,
				'size'   => $spec[1],
				'sha256' => hash( 'sha256', $data ),
				'kind'   => $spec[0],
				'index'  => $index++,
			);
		}
		$manifest = RestoreFixtures::manifest( array( 'parts' => $parts ) );
		$json     = $manifest->to_json();
		file_put_contents( $dir . '/manifest.json', $json );

		return array(
			'dir'      => $dir,
			'manifest' => $manifest,
			'json'     => $json,
			'rows'     => RestoreFixtures::rows(
				$manifest,
				$destination_id,
				array(
					'manifest.json' => array(
						'size'            => strlen( $json ),
						'checksum_sha256' => hash( 'sha256', $json ),
					),
				)
			),
		);
	}

	private function local_plan( array $backup, array $tried = array(), array $claimed = null ) {
		$files = null === $claimed ? PlanLoader::list_files( $backup['dir'] ) : $claimed;
		return RestorePlan::build( RestoreFixtures::backup(), $backup['rows'], $backup['manifest'], 'full', $files, $tried );
	}

	private function state( array $cursor = array() ) {
		return JobState::new_state( 'verify' )->with_meta( RestoreMeta::make( 0, 'full' ) )->with_cursor( $cursor );
	}

	private function run_step( FakePlanLoader $loader, $work_dir = '/nonexistent', $state = null ) {
		return ( new VerifyStep( $loader ) )->run( new JobContext( RestoreFixtures::backup(), null === $state ? $this->state() : $state, StepDriver::budget( 50 ), $this->logger, $work_dir, array() ) );
	}

	private function flip_a_byte( $path ) {
		$data     = file_get_contents( $path );
		$data[10] = chr( ord( $data[10] ) ^ 1 );
		file_put_contents( $path, $data );
	}

	public function test_a_good_local_copy_is_verified_in_place() {
		$backup = $this->backup();
		$plan   = $this->local_plan( $backup );
		$this->assertTrue( $plan->is_local() );

		$result = $this->run_step( new FakePlanLoader( $plan, $backup['dir'] ) );

		$this->assertTrue( $result->is_next() );
		$meta = $result->state()->get_meta();
		$this->assertSame( 0, $meta['verified']['source'] );
		$this->assertSame( 4, $meta['verified']['files'] );
		$this->assertSame( 29096, $meta['verified']['bytes'] );
		$this->assertSame( array(), $result->state()->get_cursor() );
		$this->assertSame( 'verify', $result->state()->get_step() );
	}

	public function test_a_good_downloaded_copy_is_verified_in_the_work_folder() {
		$backup = $this->backup( 5 );
		$plan   = RestorePlan::build( RestoreFixtures::backup(), $backup['rows'], $backup['manifest'], 'full', array() );
		$this->assertSame( 5, $plan->chosen_source() );

		$result = $this->run_step( new FakePlanLoader( $plan, null ), $backup['dir'] );

		$this->assertTrue( $result->is_next() );
		$this->assertSame( 5, $result->state()->get_meta()['verified']['source'] );
	}

	public function test_many_slices_with_a_tiny_cursor() {
		$backup = $this->backup();
		$loader = new FakePlanLoader( $this->local_plan( $backup ), $backup['dir'] );

		$out = StepDriver::drive( new VerifyStep( $loader ), $this->state(), RestoreFixtures::backup(), '/nonexistent', 2, $this->logger );

		$this->assertSame( StepResult::KIND_NEXT, $out['kind'] );
		$this->assertGreaterThan( 1, $out['slices'] );
		$this->assertLessThan( 40, $out['max_cursor'] );
	}

	public function test_a_corrupt_part_fails_naming_the_part_and_what_to_do() {
		$backup = $this->backup();
		$this->flip_a_byte( $backup['dir'] . '/files.part01.zip' );

		$result = $this->run_step( new FakePlanLoader( $this->local_plan( $backup ), $backup['dir'] ) );

		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'files.part01.zip', $result->message() );
		$this->assertStringContainsString( 'checksum does not match', $result->message() );
		$this->assertStringContainsString( 'No other complete copy is left', $result->message() );
		$this->assertStringNotContainsString( $backup['dir'], $result->message(), 'no server path' );
		$this->assertSame( array( 0 ), RestoreMeta::tried( $result->state()->get_meta() ) );
	}

	public function test_a_corrupt_local_copy_goes_back_to_fetch_with_the_next_source() {
		$local         = $this->backup( 0 );
		$rows          = array_merge(
			$local['rows'],
			RestoreFixtures::rows(
				$local['manifest'],
				5,
				array(
					'manifest.json' => array(
						'size'            => strlen( $local['json'] ),
						'checksum_sha256' => hash( 'sha256', $local['json'] ),
					),
				)
			)
		);
		$local['rows'] = $rows;
		$this->flip_a_byte( $local['dir'] . '/db.part01.zip' );
		$first  = $this->local_plan( $local );
		$second = $this->local_plan( $local, array( 0 ) );
		$this->assertSame( 0, $first->chosen_source() );
		$this->assertSame( 5, $second->chosen_source() );
		$loader = new FakePlanLoader( $first, $local['dir'] );

		$result = $this->run_step( $loader );

		$this->assertTrue( $result->is_next(), 'the step ends with a jump' );
		$this->assertSame( 'fetch', $result->state()->get_step() );
		$this->assertSame( array( 0 ), RestoreMeta::tried( $result->state()->get_meta() ) );
		$this->assertArrayNotHasKey( 'verified', $result->state()->get_meta() );
		$warnings = array_filter(
			$this->logger->lines,
			static function ( $l ) {
				return 'warning' === $l[0];
			}
		);
		$this->assertNotEmpty( $warnings );
	}

	public function test_every_kind_of_damage_is_caught() {
		$cases = array(
			'a flipped byte'     => array( 'files.part02.zip', 'flip', 'checksum does not match' ),
			'a truncated part'   => array( 'db.part01.zip', 'truncate', 'wrong size' ),
			'a missing part'     => array( 'files.part01.zip', 'delete', 'missing' ),
			'a garbage manifest' => array( 'manifest.json', 'garbage', 'manifest is not valid' ),
		);
		foreach ( $cases as $label => $case ) {
			$backup = $this->backup();
			$claim  = PlanLoader::list_files( $backup['dir'] );
			$path   = $backup['dir'] . '/' . $case[0];
			if ( 'flip' === $case[1] ) {
				$this->flip_a_byte( $path );
			} elseif ( 'truncate' === $case[1] ) {
				file_put_contents( $path, substr( file_get_contents( $path ), 0, 100 ) );
			} elseif ( 'delete' === $case[1] ) {
				unlink( $path );
			} else {
				file_put_contents( $path, str_repeat( 'x', filesize( $path ) ) );
			}

			// The plan believes what the database says (sizes of the original files), the disk disagrees.
			$result = $this->run_step( new FakePlanLoader( $this->local_plan( $backup, array(), $claim ), $backup['dir'] ) );

			$this->assertTrue( $result->is_fail(), $label );
			$this->assertStringContainsString( $case[0], $result->message(), $label );
			$this->assertStringContainsString( $case[2], $result->message(), $label );
		}
	}

	public function test_a_manifest_of_another_backup_or_changed_after_the_backup_is_refused() {
		$backup = $this->backup();
		$other  = RestoreFixtures::manifest( array( 'uuid' => '11111111-2222-4333-8444-555555555555' ) )->to_json();
		$file   = array(
			'name'       => 'manifest.json',
			'size'       => strlen( $other ),
			'sha256'     => hash( 'sha256', $other ),
			'kind'       => 'manifest',
			'row_sha256' => '',
		);
		file_put_contents( $backup['dir'] . '/manifest.json', $other );
		$this->assertStringContainsString( 'not valid', VerifyStep::check_file( $file, $backup['dir'] . '/manifest.json', RestoreFixtures::UUID ) );

		$changed = str_replace( '2026-03-04', '2026-03-05', $backup['json'] );
		file_put_contents( $backup['dir'] . '/manifest.json', $changed );
		$file['size']   = strlen( $changed );
		$file['sha256'] = hash( 'sha256', $backup['json'] );
		$this->assertStringContainsString( 'changed after', VerifyStep::check_file( $file, $backup['dir'] . '/manifest.json', RestoreFixtures::UUID ) );

		$file['sha256'] = hash( 'sha256', $changed );
		$this->assertSame( '', VerifyStep::check_file( $file, $backup['dir'] . '/manifest.json', RestoreFixtures::UUID ) );
	}

	public function test_a_symlinked_part_is_not_accepted() {
		$backup = $this->backup();
		$victim = $this->dir();
		copy( $backup['dir'] . '/db.part01.zip', $victim . '/real' );
		unlink( $backup['dir'] . '/db.part01.zip' );
		if ( ! @symlink( $victim . '/real', $backup['dir'] . '/db.part01.zip' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Symlinks may be unavailable.
			$this->markTestSkipped( 'Symlinks are not available here.' );
		}
		$file = array(
			'name'       => 'db.part01.zip',
			'size'       => 4096,
			'sha256'     => hash_file( 'sha256', $victim . '/real' ),
			'kind'       => 'db',
			'row_sha256' => '',
		);

		$this->assertStringContainsString( 'missing', VerifyStep::check_file( $file, $backup['dir'] . '/db.part01.zip', RestoreFixtures::UUID ) );
	}

	public function test_disagreeing_checksums_are_a_damaged_record() {
		$backup = $this->backup();
		$file   = array(
			'name'       => 'db.part01.zip',
			'size'       => 4096,
			'sha256'     => hash_file( 'sha256', $backup['dir'] . '/db.part01.zip' ),
			'kind'       => 'db',
			'row_sha256' => str_repeat( 'a', 64 ),
		);

		$this->assertStringContainsString( 'disagree', VerifyStep::check_file( $file, $backup['dir'] . '/db.part01.zip', RestoreFixtures::UUID ) );

		$file['row_sha256'] = '';
		$this->assertSame( '', VerifyStep::check_file( $file, $backup['dir'] . '/db.part01.zip', RestoreFixtures::UUID ) );
	}

	public function test_a_vanished_local_folder_is_a_damaged_source() {
		$backup = $this->backup();

		$result = $this->run_step( new FakePlanLoader( $this->local_plan( $backup ), null ) );

		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'local copy of the backup is gone', $result->message() );
	}

	public function test_a_plan_that_cannot_be_built_fails_with_its_message() {
		$result = $this->run_step( new FakePlanLoader( null ) );

		$this->assertTrue( $result->is_fail() );
		$this->assertSame( 'No plan.', $result->message() );
	}

	public function test_no_source_left_fails() {
		$backup = $this->backup();
		$result = $this->run_step( new FakePlanLoader( $this->local_plan( $backup, array( 0 ) ), $backup['dir'] ) );

		$this->assertTrue( $result->is_fail() );
		$this->assertStringContainsString( 'No complete copy', $result->message() );
	}
}
