<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test messages only; WordPress is not loaded in unit tests.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Admin\Pages\DestinationsPage;
use OpenVault\Backup\Admin\BackupRowPresenter;
use OpenVault\Backup\Destinations\DestinationPresenter as P;
use OpenVault\Backup\Destinations\S3\S3Presets;
use OpenVault\Backup\Engine\UploadSummary;
use OpenVault\Backup\Rest\DestinationsRestController as R;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Destinations\DestinationPresenter
 * @covers \OpenVault\Backup\Rest\DestinationsRestController
 */
class DestinationPresenterTest extends TestCase {

	private function row( array $over = array() ) {
		return array_merge(
			array(
				'id'              => 4,
				'type'            => 's3',
				'label'           => 'Offsite',
				'settings'        => array(
					'preset' => 'aws',
					'region' => 'eu-central-1',
					'bucket' => 'my-backups',
					'prefix' => 'sites/main',
				),
				'has_credentials' => true,
				'status'          => 'connected',
				'last_error'      => null,
				'last_tested_at'  => '2026-01-02 03:04:05',
				'created_at'      => '2026-01-01 00:00:00',
				'updated_at'      => '2026-01-01 00:00:00',
			),
			$over
		);
	}

	public function test_item_shape_and_details() {
		$item = P::item( $this->row() );
		$this->assertSame( 4, $item['id'] );
		$this->assertSame( 'Amazon S3', $item['preset_label'] );
		$this->assertSame( 'S3-compatible storage', $item['type_label'] );
		$this->assertSame( 'Connected', $item['status_label'] );
		$this->assertSame( '2026-01-02T03:04:05Z', $item['last_tested_at'] );
		$this->assertTrue( $item['has_credentials'] );
		$this->assertNull( $item['last_error'], 'no error is shown for a connected destination' );
		$this->assertSame( 'Bucket my-backups · s3.eu-central-1.amazonaws.com · Folder sites/main', $item['details'] );
		$this->assertSame( 'eu-central-1', $item['settings']['region'] );
	}

	public function test_no_credentials_ever_leave_the_presenter() {
		$secret = 'SuperSecretValue-1234567890';
		$item   = P::item(
			$this->row(
				array(
					'credentials' => 'ovb1:' . $secret,
					'secret_key'  => $secret,
					'access_key'  => 'AKIAEXAMPLE12345',
					'settings'    => array(
						'preset'     => 'aws',
						'region'     => 'eu-central-1',
						'bucket'     => 'my-backups',
						'secret_key' => $secret,
						'access_key' => 'AKIAEXAMPLE12345',
						'part'       => array( $secret ),
					),
				)
			)
		);
		$json   = json_encode( $item );
		$this->assertStringNotContainsString( $secret, $json );
		$this->assertStringNotContainsString( 'AKIA', $json );
		$this->assertArrayNotHasKey( 'credentials', $item );
		$this->assertStringNotContainsString( '"credentials"', $json );
		$this->assertArrayNotHasKey( 'secret_key', $item['settings'] );
		$this->assertArrayNotHasKey( 'access_key', $item['settings'] );
	}

	public function test_last_error_is_scrubbed_and_only_shown_when_relevant() {
		$bad = P::item(
			$this->row(
				array(
					'status'     => 'error',
					'last_error' => 'Denied. Authorization: Bearer abc123def456 secret_key=Hunter2Hunter2 AKIAABCDEFGHIJKLMNOP',
				)
			)
		);
		$this->assertStringNotContainsString( 'abc123def456', (string) $bad['last_error'] );
		$this->assertStringNotContainsString( 'Hunter2', (string) $bad['last_error'] );
		$this->assertStringNotContainsString( 'AKIAABCDEFGHIJKLMNOP', (string) $bad['last_error'] );
		$this->assertSame( 'Connection failed', $bad['status_label'] );

		$ok = P::item( $this->row( array( 'last_error' => 'old error' ) ) );
		$this->assertNull( $ok['last_error'] );
		$reconnect = P::item(
			$this->row(
				array(
					'status'     => 'needs_reconnect',
					'last_error' => 'Stored credentials can\'t be read.',
				)
			)
		);
		$this->assertSame( 'Needs reconnect', $reconnect['status_label'] );
		$this->assertNotNull( $reconnect['last_error'] );
	}

	public function test_local_and_odd_rows() {
		$local = P::item(
			$this->row(
				array(
					'type'            => 'local',
					'settings'        => array( 'path' => '' ),
					'has_credentials' => false,
				)
			)
		);
		$this->assertSame( 'Local folder', $local['type_label'] );
		$this->assertSame( '', $local['preset'] );
		$this->assertSame( 'The plugin backup folder on this server', $local['details'] );
		$this->assertSame(
			'/mnt/b',
			P::item(
				$this->row(
					array(
						'type'     => 'local',
						'settings' => array( 'path' => '/mnt/b' ),
					)
				)
			)['details']
		);

		$odd = P::item(
			$this->row(
				array(
					'type'     => 'gdrive',
					'settings' => array(),
				)
			)
		);
		$this->assertSame( 'gdrive', $odd['type_label'] );
		$this->assertSame( '', $odd['details'] );

		$broken = P::item(
			$this->row(
				array(
					'settings' => array(
						'preset' => 'aws',
						'bucket' => 'x',
					),
				)
			)
		);
		$this->assertStringContainsString( 'not valid', $broken['details'] );
		$this->assertSame( 'Not tested yet', P::status_label( 'whatever' ) );
		$this->assertNull( P::item( $this->row( array( 'last_tested_at' => null ) ) )['last_tested_at'] );
	}

	public function test_unusable_reasons_for_the_picker() {
		$this->assertSame( '', P::unusable_reason( P::item( $this->row() ) ) );
		$this->assertSame( '', P::unusable_reason( P::item( $this->row( array( 'status' => 'new' ) ) ) ) );
		$this->assertStringContainsString( 'no longer be read', P::unusable_reason( P::item( $this->row( array( 'status' => 'needs_reconnect' ) ) ) ) );
		$this->assertStringContainsString( 'test failed', P::unusable_reason( P::item( $this->row( array( 'status' => 'error' ) ) ) ) );
		$this->assertStringContainsString( 'not available', P::unusable_reason( P::item( $this->row() ), false ) );
	}

	public function test_history_line_uses_labels_not_ids() {
		$rows    = array(
			array(
				'destination_id' => 4,
				'upload_status'  => 'done',
			),
			array(
				'destination_id' => 4,
				'upload_status'  => 'done',
			),
			array(
				'destination_id' => 9,
				'upload_status'  => 'failed',
			),
			array(
				'destination_id' => 9,
				'upload_status'  => 'done',
			),
		);
		$summary = UploadSummary::summarise( $rows, array( 4 => 'Offsite' ) );
		$line    = BackupRowPresenter::destinations_line( $summary );
		$this->assertSame( 'Offsite: Uploaded; Destination 9: Failed (1 of 2 files)', $line );
	}

	public function test_rest_arg_validators() {
		$this->assertTrue( R::validate_type( 's3' ) );
		$this->assertTrue( R::validate_type( 'local' ) );
		foreach ( array( 'gdrive', 'S3', '', null, 5, array( 's3' ), 's3 ' ) as $type ) {
			$this->assertFalse( R::validate_type( $type ), var_export( $type, true ) );
		}
		$this->assertTrue( R::validate_label( 'Offsite' ) );
		$this->assertFalse( R::validate_label( '' ) );
		$this->assertFalse( R::validate_label( str_repeat( 'x', 101 ) ) );
		$this->assertFalse( R::validate_label( array( 'x' ) ) );
		$this->assertTrue( R::validate_map( array() ) );
		$this->assertTrue( R::validate_map( array( 'a' => 1 ) ) );
		$this->assertFalse( R::validate_map( array( 'x', 'y' ) ), 'a JSON list is not an object' );
		$this->assertFalse( R::validate_map( 'text' ) );
		$this->assertFalse( R::validate_map( null ) );
		$this->assertTrue(
			R::same_settings(
				array(
					'a' => 1,
					'b' => 2,
				),
				array(
					'b' => 2,
					'a' => 1,
				)
			)
		);
		$this->assertFalse( R::same_settings( array( 'a' => 1 ), array( 'a' => '1' ) ) );
	}

	public function test_preset_help_covers_every_preset_with_official_docs() {
		$help = DestinationsPage::preset_help();
		foreach ( S3Presets::all() as $key => $preset ) {
			$this->assertArrayHasKey( $key, $help );
			$this->assertNotSame( '', $help[ $key ]['endpoint'] );
			$this->assertNotEmpty( $help[ $key ]['needs'] );
			if ( 'custom' !== $key ) {
				$this->assertStringStartsWith( 'https://', $preset['docs'] );
			}
		}
	}
}
