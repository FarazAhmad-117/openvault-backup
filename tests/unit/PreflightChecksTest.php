<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Steps\PreflightChecks;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Steps\PreflightChecks
 */
class PreflightChecksTest extends TestCase {

	const MB = 1048576;

	private function healthy() {
		return array(
			'local_dir_error'    => '',
			'local_dir_writable' => true,
			'work_dir_ok'        => true,
			'free_bytes'         => 500 * self::MB,
			'min_free_bytes'     => 100 * self::MB,
			'format'             => 'zip',
			'format_available'   => true,
			'format_reason'      => '',
			'contents'           => array(
				'db'      => true,
				'uploads' => true,
			),
		);
	}

	private function codes( array $findings, $level = null ) {
		$out = array();
		foreach ( $findings as $finding ) {
			if ( null === $level || $level === $finding['level'] ) {
				$out[] = $finding['code'];
			}
		}
		return $out;
	}

	public function test_healthy_environment_has_no_findings() {
		$this->assertSame( array(), PreflightChecks::evaluate( $this->healthy() ) );
	}

	public function test_no_facts_means_nothing_to_report() {
		$this->assertSame( array(), PreflightChecks::evaluate( array() ) );
	}

	public function test_not_enough_disk_space_has_an_actionable_message() {
		$facts               = $this->healthy();
		$facts['free_bytes'] = 40 * self::MB;
		$findings            = PreflightChecks::evaluate( $facts );

		$this->assertSame( array( 'disk_space' ), $this->codes( $findings, 'error' ) );
		$this->assertSame( 'Not enough free disk space: 40.0 MB free, 100.0 MB needed.', $findings[0]['message'] );
	}

	public function test_exactly_the_minimum_is_enough() {
		$facts               = $this->healthy();
		$facts['free_bytes'] = 100 * self::MB;
		$this->assertSame( array(), PreflightChecks::evaluate( $facts ) );
	}

	public function test_unknown_free_space_warns_but_does_not_fail() {
		$facts               = $this->healthy();
		$facts['free_bytes'] = null;
		$findings            = PreflightChecks::evaluate( $facts );

		$this->assertSame( array( 'disk_unknown' ), $this->codes( $findings, 'warning' ) );
		$this->assertSame( array(), PreflightChecks::errors( $findings ) );
	}

	public function test_minimum_of_zero_turns_the_check_off() {
		$facts                   = $this->healthy();
		$facts['free_bytes']     = 1;
		$facts['min_free_bytes'] = 0;
		$this->assertSame( array(), PreflightChecks::evaluate( $facts ) );
	}

	public function test_unwritable_folders_are_errors() {
		$facts                       = $this->healthy();
		$facts['local_dir_writable'] = false;
		$facts['work_dir_ok']        = false;
		$this->assertSame( array( 'local_dir_not_writable', 'work_dir' ), $this->codes( PreflightChecks::evaluate( $facts ), 'error' ) );
	}

	public function test_local_dir_error_text_is_passed_through() {
		$facts                    = $this->healthy();
		$facts['local_dir_error'] = 'The local backup folder could not be created.';
		$findings                 = PreflightChecks::evaluate( $facts );

		$this->assertSame( array( 'local_dir' ), $this->codes( $findings, 'error' ) );
		$this->assertSame( 'The local backup folder could not be created.', $findings[0]['message'] );
	}

	public function test_unavailable_format_names_the_reason() {
		$facts                     = $this->healthy();
		$facts['format']           = 'zip';
		$facts['format_available'] = false;
		$facts['format_reason']    = 'The PHP zip extension is not installed.';
		$findings                  = PreflightChecks::evaluate( $facts );

		$this->assertSame( array( 'format_unavailable' ), $this->codes( $findings, 'error' ) );
		$this->assertStringContainsString( '"zip"', $findings[0]['message'] );
		$this->assertStringContainsString( 'zip extension is not installed', $findings[0]['message'] );
	}

	public function test_contents_rules() {
		$facts             = $this->healthy();
		$facts['contents'] = array(
			'db'      => false,
			'uploads' => false,
		);
		$this->assertSame( array( 'contents_empty' ), $this->codes( PreflightChecks::evaluate( $facts ), 'error' ) );

		$facts['contents'] = array();
		$findings          = PreflightChecks::evaluate( $facts );
		$this->assertSame( array( 'contents_default' ), $this->codes( $findings, 'info' ) );
		$this->assertSame( array(), PreflightChecks::errors( $findings ) );
	}

	public function test_several_errors_are_all_reported() {
		$facts                     = $this->healthy();
		$facts['free_bytes']       = 1;
		$facts['format_available'] = false;
		$this->assertSame( array( 'disk_space', 'format_unavailable' ), $this->codes( PreflightChecks::errors( PreflightChecks::evaluate( $facts ) ) ) );
	}

	/**
	 * @dataProvider settings_provider
	 */
	public function test_min_free_bytes_from_settings( $settings, $expected_mb ) {
		$this->assertSame( $expected_mb * self::MB, PreflightChecks::min_free_bytes( $settings ) );
	}

	public function settings_provider() {
		return array(
			'default'       => array( array(), 100 ),
			'configured'    => array( array( 'min_free_space_mb' => 250 ), 250 ),
			'numeric text'  => array( array( 'min_free_space_mb' => '50' ), 50 ),
			'zero disables' => array( array( 'min_free_space_mb' => 0 ), 0 ),
			'negative'      => array( array( 'min_free_space_mb' => -5 ), 100 ),
			'garbage'       => array( array( 'min_free_space_mb' => 'lots' ), 100 ),
		);
	}
}
