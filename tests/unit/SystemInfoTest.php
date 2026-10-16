<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Support\SystemInfo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Support\SystemInfo
 */
class SystemInfoTest extends TestCase {

	/**
	 * @dataProvider bytes_provider
	 */
	public function test_format_bytes( $input, $expected ) {
		$this->assertSame( $expected, SystemInfo::format_bytes( $input ) );
	}

	public function bytes_provider() {
		return array(
			'zero'      => array( 0, '0 B' ),
			'bytes'     => array( 1023, '1023 B' ),
			'kilobytes' => array( 1536, '1.5 KB' ),
			'megabytes' => array( 5 * 1024 * 1024, '5.0 MB' ),
			'gigabytes' => array( 3 * 1024 * 1024 * 1024, '3.0 GB' ),
			'negative'  => array( -10, '0 B' ),
		);
	}

	public function test_as_text_renders_sections_and_rows() {
		$text = SystemInfo::as_text(
			array(
				'Server' => array( 'PHP version' => '7.4.33' ),
				'Limits' => array(
					'memory_limit'       => '128M',
					'max_execution_time' => '30',
				),
			)
		);

		$this->assertSame(
			"### Server ###\nPHP version: 7.4.33\n\n### Limits ###\nmemory_limit: 128M\nmax_execution_time: 30\n",
			$text
		);
	}

	public function test_as_text_of_nothing_is_a_single_newline() {
		$this->assertSame( "\n", SystemInfo::as_text( array() ) );
	}
}
