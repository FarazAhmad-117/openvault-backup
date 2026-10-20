<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test messages only.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\CapabilityDetector;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Rest\RestController as R;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Rest\RestController
 */
class RestControllerTest extends TestCase {

	private function formats( $zip = true, $gz = true ) {
		$detector = new CapabilityDetector(
			static function ( $name ) use ( $gz ) {
				return $gz || 'zlib' !== $name;
			},
			static function ( $name ) use ( $zip ) {
				return 'ZipArchive' === $name ? $zip : true;
			}
		);
		return $detector->formats();
	}

	public function test_validate_id() {
		foreach ( array( 1, '1', '42', 2147483647 ) as $ok ) {
			$this->assertTrue( R::validate_id( $ok ), var_export( $ok, true ) );
		}
		foreach ( array( 0, '0', -1, '-1', '1.5', 'abc', '', null, '1 OR 1=1', '99999999999', array(), '01' ) as $bad ) {
			$this->assertFalse( R::validate_id( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_contents_validation() {
		$this->assertTrue( R::validate_contents( array( 'db' => true ) ) );
		$this->assertTrue(
			R::validate_contents(
				array(
					'db'      => true,
					'uploads' => false,
					'core'    => 'true',
					'themes'  => '1',
				)
			)
		);
		$this->assertFalse( R::validate_contents( array() ), 'empty' );
		$this->assertFalse( R::validate_contents( array( 'db' => false ) ), 'nothing selected' );
		$this->assertFalse(
			R::validate_contents(
				array(
					'db'   => true,
					'evil' => true,
				)
			),
			'unknown flag'
		);
		$this->assertFalse( R::validate_contents( array( 'db' => 'yes' ) ), 'not a boolean' );
		$this->assertFalse( R::validate_contents( array( 'db' => array( 'x' ) ) ), 'nested' );
		$this->assertFalse( R::validate_contents( array( true ) ), 'list' );
		$this->assertFalse( R::validate_contents( '{"db":true}' ), 'string' );
		$this->assertFalse( R::validate_contents( null ) );
		$this->assertFalse( R::validate_contents( array( '../../x' => true ) ) );
	}

	public function test_contents_sanitising_gives_full_boolean_map() {
		$clean = R::sanitize_contents(
			array(
				'db'      => '1',
				'core'    => 'true',
				'plugins' => 'false',
				'evil'    => true,
			)
		);
		$this->assertSame( array_keys( R::DEFAULT_CONTENTS ), array_keys( $clean ) );
		$this->assertTrue( $clean['db'] );
		$this->assertTrue( $clean['core'] );
		$this->assertFalse( $clean['plugins'] );
		$this->assertFalse( $clean['uploads'] );
		$this->assertArrayNotHasKey( 'evil', $clean );
		foreach ( $clean as $flag ) {
			$this->assertIsBool( $flag );
		}
	}

	public function test_default_contents_match_the_documented_defaults() {
		$this->assertTrue( R::DEFAULT_CONTENTS['db'] );
		$this->assertTrue( R::DEFAULT_CONTENTS['other_content'] );
		$this->assertFalse( R::DEFAULT_CONTENTS['core'] );
		$this->assertFalse( R::DEFAULT_CONTENTS['wp_config'] );
	}

	public function test_format_whitelist() {
		$formats = $this->formats();
		$this->assertTrue( R::validate_format( 'zip', $formats ) );
		$this->assertTrue( R::validate_format( 'zip-store', $formats ) );
		$this->assertTrue( R::validate_format( 'tar.gz', $formats ) );
		$this->assertFalse( R::validate_format( 'tar.bz2', $formats ), 'phase 2 format is never accepted' );
		$this->assertFalse( R::validate_format( '7z', $formats ) );
		$this->assertFalse( R::validate_format( '', $formats ) );
		$this->assertFalse( R::validate_format( null, $formats ) );
		$this->assertFalse( R::validate_format( array( 'zip' ), $formats ) );
		$this->assertFalse( R::validate_format( 'ZIP', $formats ), 'case sensitive' );
	}

	public function test_format_must_be_available_on_this_server() {
		$no_zip = $this->formats( false, true );
		$this->assertFalse( R::validate_format( 'zip', $no_zip ) );
		$this->assertFalse( R::validate_format( 'zip-store', $no_zip ) );
		$this->assertTrue( R::validate_format( 'tar.gz', $no_zip ) );
		$none = $this->formats( false, false );
		$this->assertFalse( R::validate_format( 'tar.gz', $none ) );
	}

	public function test_note_limits() {
		$this->assertTrue( R::validate_note( '' ) );
		$this->assertTrue( R::validate_note( 'before the theme update' ) );
		$this->assertTrue( R::validate_note( str_repeat( 'a', R::NOTE_MAX ) ) );
		$this->assertFalse( R::validate_note( str_repeat( 'a', R::NOTE_MAX + 1 ) ) );
		$this->assertFalse( R::validate_note( str_repeat( 'a', 100000 ) ) );
		$this->assertFalse( R::validate_note( array( 'x' ) ) );
		$this->assertFalse( R::validate_note( null ) );
		$this->assertFalse( R::validate_note( 123 ) );
	}

	public function test_hide_paths() {
		$roots = array( '/var/www/html/wp-content/ovb-secret1234', '/var/www/html/wp-content', '/var/www/html/' );
		$this->assertSame( 'Cannot write [site]/tmp/x', R::hide_paths( 'Cannot write /var/www/html/wp-content/ovb-secret1234/tmp/x', $roots ) );
		$this->assertSame( 'Missing [site]/plugins/a', R::hide_paths( 'Missing /var/www/html/wp-content/plugins/a', $roots ) );
		$this->assertSame( 'Win [site]\\x', R::hide_paths( 'Win C:\\wp\\x', array( 'C:/wp' ) ) );
		$this->assertSame( 'plain text', R::hide_paths( 'plain text', $roots ) );
		$this->assertSame( 'unchanged /', R::hide_paths( 'unchanged /', array( '/', '', 'x' ) ) );
	}

	public function test_iso_time() {
		$this->assertSame( '2026-01-02T03:04:05Z', R::iso_time( '2026-01-02 03:04:05' ) );
		$this->assertNull( R::iso_time( null ) );
		$this->assertNull( R::iso_time( 'yesterday' ) );
		$this->assertNull( R::iso_time( "2026-01-02 03:04:05\n" ) );
	}

	private function row( $status, array $extra = array() ) {
		return array_merge(
			array(
				'id'          => 7,
				'status'      => $status,
				'started_at'  => '2026-01-02 03:00:00',
				'finished_at' => null,
				'total_size'  => 0,
				'format'      => 'zip',
				'locked'      => false,
				'note'        => '',
			),
			$extra
		);
	}

	public function test_payload_for_a_running_backup() {
		$state = JobState::new_state( JobState::STEP_DB_EXPORT, 1767322800 )->with_cursor(
			array(
				'tables_total' => 4,
				'tables_done'  => 2,
			),
			1767322800
		);
		$data  = R::payload( $this->row( 'running' ), $state, array(), 1767322860 );
		$this->assertSame( 7, $data['id'] );
		$this->assertSame( 'running', $data['status'] );
		$this->assertFalse( $data['finished'] );
		$this->assertSame( 'db_export', $data['step'] );
		$this->assertSame( 'Exporting the database', $data['step_label'] );
		$this->assertGreaterThan( 3, $data['percent'] );
		$this->assertLessThan( 25, $data['percent'] );
		$this->assertSame( 60, $data['elapsed_seconds'] );
		$this->assertSame( array(), $data['parts'] );
		$this->assertNull( $data['error'] );
		$this->assertSame( '2 of 4 database tables', $data['detail'] );
	}

	public function test_payload_for_a_queued_backup_without_state() {
		$data = R::payload( $this->row( 'queued', array( 'started_at' => null ) ), null, array(), 1767322860 );
		$this->assertSame( 0, $data['percent'] );
		$this->assertSame( 'Waiting to start', $data['step_label'] );
		$this->assertSame( 0, $data['elapsed_seconds'] );
	}

	public function test_payload_for_a_complete_backup_lists_parts_with_download_links() {
		$files = array(
			array(
				'id'              => 11,
				'file_name'       => 'db.part01.zip',
				'size'            => 10,
				'checksum_sha256' => str_repeat( 'a', 64 ),
			),
			array(
				'id'              => 12,
				'file_name'       => 'files.part01.zip',
				'size'            => 20,
				'checksum_sha256' => str_repeat( 'b', 64 ),
			),
			array(
				'id'              => 13,
				'file_name'       => 'manifest.json',
				'size'            => 5,
				'checksum_sha256' => str_repeat( 'c', 64 ),
			),
		);
		$row   = $this->row(
			'complete',
			array(
				'finished_at' => '2026-01-02 03:01:30',
				'total_size'  => 35,
			)
		);
		$state = JobState::new_state( JobState::STEP_DONE, 1767322800 )->with_meta( array( 'archive' => array( 'warnings' => 3 ) ) );
		$data  = R::payload(
			$row,
			$state,
			$files,
			1800000000,
			static function ( $backup, $file ) {
				return "https://example.test/dl?b=$backup&f=$file";
			}
		);

		$this->assertTrue( $data['finished'] );
		$this->assertSame( 100, $data['percent'] );
		$this->assertSame( 'done', $data['step'] );
		$this->assertSame( 90, $data['elapsed_seconds'], 'elapsed stops at the finish time' );
		$this->assertNull( $data['eta_seconds'] );
		$this->assertSame( 35, $data['total_size'] );
		$this->assertSame( 3, $data['warnings'] );
		$this->assertSame( array( 'db', 'files', 'manifest' ), array_column( $data['parts'], 'kind' ) );
		$this->assertSame( 'https://example.test/dl?b=7&f=12', $data['parts'][1]['download_url'] );
		$this->assertSame( '2026-01-02T03:01:30Z', $data['finished_at'] );
	}

	public function test_payload_of_a_failed_backup_hides_paths_and_has_no_parts() {
		$state = JobState::new_state( JobState::STEP_ARCHIVE, 1 )->mark_failed( 'Cannot write /srv/site/wp-content/ovb-abc/backups/x', 2 );
		$files = array(
			array(
				'id'              => 1,
				'file_name'       => 'a.zip',
				'size'            => 1,
				'checksum_sha256' => '',
			),
		);
		$data  = R::payload( $this->row( 'failed', array( 'finished_at' => '2026-01-02 03:00:10' ) ), $state, $files, 5, null, array( '/srv/site/wp-content', '/srv/site/' ) );
		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( array(), $data['parts'] );
		$this->assertSame( 'Cannot write [site]/ovb-abc/backups/x', $data['error'] );
		$this->assertSame( 0, $data['percent'] );
	}

	public function test_destinations_argument_validation() {
		$this->assertTrue( R::validate_destinations( array() ) );
		$this->assertTrue( R::validate_destinations( array( 1, '2', 3 ) ) );
		$this->assertTrue( R::validate_destinations( range( 1, 10 ) ) );
		foreach ( array( range( 1, 11 ), array( 0 ), array( -3 ), array( '1x' ), array( '01' ), array( 1.5 ), array( null ), array( array( 1 ) ), array( true ), 'all', 5, null, array( '99999999999' ), array( '1 OR 1=1' ) ) as $bad ) {
			$this->assertFalse( R::validate_destinations( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_destinations_are_sanitised_to_unique_integers() {
		$this->assertSame( array( 3, 1, 2 ), R::sanitize_destinations( array( '3', 1, 3, '2', 1 ) ) );
		$this->assertSame( array(), R::sanitize_destinations( 'x' ) );
		$this->assertSame( array(), R::sanitize_destinations( array( 0, -1 ) ) );
	}

	public function test_payload_carries_the_destination_summary() {
		$state   = JobState::new_state( JobState::STEP_UPLOAD, 1 );
		$summary = array(
			array(
				'id'          => 4,
				'label'       => 'Disk',
				'status'      => 'done',
				'files_done'  => 3,
				'files_total' => 3,
				'error'       => '',
			),
		);
		$data    = R::payload( $this->row( 'uploading' ), $state, array(), 5, null, array(), $summary );
		$this->assertSame( $summary, $data['destinations'] );
		$this->assertSame( array(), R::payload( $this->row( 'uploading' ), $state, array(), 5 )['destinations'] );
	}

	public function test_payload_never_exposes_state_internals() {
		$state = JobState::new_state( JobState::STEP_ARCHIVE, 1 )->with_cursor(
			array(
				'phase'       => 'files',
				'list_offset' => 5,
			),
			2
		);
		$json  = json_encode( R::payload( $this->row( 'running' ), $state, array(), 10 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringNotContainsString( 'list_offset', $json );
		$this->assertStringNotContainsString( 'uuid', $json );
	}
}
