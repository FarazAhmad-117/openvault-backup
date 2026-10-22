<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Restore\RestorePreviewPresenter as P;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Restore\RestorePreviewPresenter
 */
class RestorePreviewPresenterTest extends TestCase {

	private function backup() {
		return array(
			'id'           => 5,
			'trigger_type' => 'pre-restore',
			'format'       => 'zip',
			'total_size'   => 4096,
			'created_at'   => '2026-02-03 04:05:06',
			'contents'     => array(
				'db'      => true,
				'uploads' => true,
				'core'    => true,
			),
		);
	}

	private function result( array $over = array() ) {
		return array_merge(
			array(
				'ok'           => true,
				'errors'       => array(),
				'warnings'     => array(
					array(
						'code'    => 'plugin_protected',
						'message' => 'The folder of OpenVault Backup is never overwritten.',
					),
				),
				'requires_ack' => array(),
				'plan'         => array(
					'mode'           => 'full',
					'summary'        => array(
						'restores_db'    => true,
						'restores_files' => true,
						'db_bytes'       => 100,
						'files_bytes'    => 900,
						'site_url'       => 'http://canary.example/secret',
					),
					'chosen_source'  => 4,
					'download_bytes' => 1000,
					'extract_bytes'  => 3000,
					'snapshot_bytes' => 100,
					'journal_bytes'  => 1800,
					'sources'        => array(),
				),
				'disk'         => array(
					'free'    => 10000000,
					'needed'  => 5000000,
					'checked' => true,
				),
			),
			$over
		);
	}

	public function test_facts_for_the_page() {
		$out = P::build( $this->backup(), $this->result(), array( 4 => 'My S3' ), array() );
		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'Safety snapshot before restore', $out['backup']['trigger_label'] );
		$this->assertSame( '2026-02-03T04:05:06Z', $out['backup']['created_at'] );
		$this->assertTrue( $out['touches']['db'] );
		$this->assertTrue( $out['touches']['files'] );
		$this->assertSame(
			array(
				'kind'  => 'destination',
				'label' => 'My S3',
			),
			$out['source']
		);
		$this->assertSame( 1000, $out['sizes']['download'] );
		$this->assertSame( 1800, $out['sizes']['journal'] );
		$this->assertSame( 10000000, $out['disk']['free'] );
		$this->assertTrue( $out['options']['server_config_choice'], 'a backup with core files offers the server config choice' );
		$this->assertFalse( $out['options']['url_mismatch'] );
		$this->assertSame( 'plugin_protected', $out['warnings'][0]['code'] );
		$this->assertCount( 4, $out['protected'] );
		$this->assertStringContainsString( 'never overwritten', $out['protected'][0] );
	}

	public function test_local_source_and_db_only() {
		$result                                      = $this->result();
		$result['plan']['chosen_source']             = 0;
		$result['plan']['summary']['restores_files'] = false;
		$out = P::build( $this->backup(), $result, array(), array() );
		$this->assertSame( 'local', $out['source']['kind'] );
		$this->assertFalse( $out['touches']['files'] );
		$this->assertFalse( $out['options']['server_config_choice'] );
		$this->assertCount( 1, $out['protected'] );
	}

	public function test_acknowledgements_and_errors() {
		$out = P::build(
			$this->backup(),
			$this->result(
				array(
					'ok'           => false,
					'errors'       => array(
						array(
							'code'    => 'disk_space',
							'message' => 'Not enough free disk space: 1 MB free, 5 MB needed.',
						),
					),
					'requires_ack' => array(
						array(
							'code'    => 'url_mismatch',
							'message' => 'This backup was made on http://old.example.',
						),
					),
				)
			),
			array(),
			array()
		);
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'disk_space', $out['errors'][0]['code'] );
		$this->assertTrue( $out['options']['url_mismatch'] );
		$this->assertSame( 'url_mismatch', $out['requires_ack'][0]['code'] );
	}

	public function test_a_refusal_without_a_plan_is_still_a_valid_payload() {
		$out = P::build(
			$this->backup(),
			array(
				'ok'           => false,
				'errors'       => array(
					array(
						'code'    => 'manifest',
						'message' => 'The manifest is damaged.',
					),
				),
				'warnings'     => array(),
				'requires_ack' => array(),
				'plan'         => null,
			),
			array(),
			array()
		);
		$this->assertNull( $out['source'] );
		$this->assertFalse( $out['touches']['db'] );
		$this->assertSame( 0, $out['sizes']['download'] );
		$this->assertNull( $out['disk']['free'] );
	}

	public function test_no_paths_secrets_or_internal_plan_data_leak() {
		$result                    = $this->result();
		$result['errors']          = array(
			array(
				'code'    => 'local_dir',
				'message' => 'Cannot write /var/www/html/wp-content/ovb-abc/backups token=SuperSecret123 here',
			),
		);
		$result['plan']['sources'] = array( array( 'files' => array( array( 'ref' => '/remote/secret/ref' ) ) ) );
		$out                       = P::build( $this->backup(), $result, array(), array( '/var/www/html' ) );
		$json                      = json_encode( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
		$this->assertStringNotContainsString( '/var/www/html', $json );
		$this->assertStringContainsString( '[site]', $json );
		$this->assertStringNotContainsString( 'SuperSecret123', $json );
		$this->assertStringNotContainsString( '/remote/secret/ref', $json );
		$this->assertStringNotContainsString( 'canary.example', $json, 'the plan summary is not passed through' );
	}
}
