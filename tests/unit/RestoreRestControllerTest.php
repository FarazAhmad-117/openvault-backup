<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test messages only.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Rest\RestoreRestController as R;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Rest\RestoreRestController
 */
class RestoreRestControllerTest extends TestCase {

	public function test_the_phrase_must_be_exactly_RESTORE() {
		$this->assertTrue( R::phrase_ok( 'RESTORE' ) );
		$lookalikes = array(
			'restore',
			'Restore',
			'RESTORE ',
			' RESTORE',
			"RESTORE\n",
			"RESTORE\0",
			'RESTOR',
			'RESTORES',
			'RESTORE RESTORE',
			'RESTORЕ',
			'ＲＥＳＴＯＲＥ',
			'RЕSTORE',
			"RES\u{200B}TORE",
			'',
			'0',
			null,
			true,
			1,
			array( 'RESTORE' ),
		);
		foreach ( $lookalikes as $bad ) {
			$this->assertFalse( R::phrase_ok( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_phrase_argument_type() {
		$this->assertTrue( R::validate_phrase_type( 'anything' ) );
		$this->assertFalse( R::validate_phrase_type( array() ) );
		$this->assertFalse( R::validate_phrase_type( str_repeat( 'x', 65 ) ) );
		$this->assertFalse( R::validate_phrase_type( 5 ) );
	}

	public function test_mode_whitelist() {
		foreach ( array( 'db', 'files', 'full' ) as $ok ) {
			$this->assertTrue( R::validate_mode( $ok ), $ok );
		}
		foreach ( array( 'undo', 'DB', 'Full', '', 'db ', null, 1, array( 'db' ), 'db;DROP', '../full' ) as $bad ) {
			$this->assertFalse( R::validate_mode( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_flags() {
		foreach ( array( true, false, 1, 0, '1', '0', 'true', 'false' ) as $ok ) {
			$this->assertTrue( R::validate_flag( $ok ), var_export( $ok, true ) );
		}
		foreach ( array( 'yes', 'on', 2, -1, null, array(), 'TRUE', '' ) as $bad ) {
			$this->assertFalse( R::validate_flag( $bad ), var_export( $bad, true ) );
		}
		$this->assertTrue( R::to_bool( 'true' ) );
		$this->assertTrue( R::to_bool( '1' ) );
		$this->assertFalse( R::to_bool( 'false' ) );
		$this->assertFalse( R::to_bool( 'yes' ) );
		$this->assertFalse( R::to_bool( null ) );
	}

	public function test_http_status_of_refusals() {
		foreach ( array( 'restore_missing', 'backup_missing' ) as $code ) {
			$this->assertSame( 404, R::status_for_code( $code ) );
		}
		foreach ( array( 'restore_running', 'backup_running', 'not_verified', 'already_undone', 'dismissed', 'snapshot_gone', 'journal_gone' ) as $code ) {
			$this->assertSame( 409, R::status_for_code( $code ), $code );
		}
		foreach ( array( 'save_failed', 'enqueue_failed', 'create_failed', 'start_failed' ) as $code ) {
			$this->assertSame( 500, R::status_for_code( $code ), $code );
		}
		foreach ( array( 'disk_space', 'prefix_mismatch', 'encrypted', 'multisite_unsupported', 'mode', 'unknown_code' ) as $code ) {
			$this->assertSame( 400, R::status_for_code( $code ), $code );
		}
	}

	public function test_the_confirm_word_is_a_constant_not_a_translation() {
		$this->assertSame( 'RESTORE', R::CONFIRM_PHRASE );
	}
}
