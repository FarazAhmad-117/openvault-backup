<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Database\BackupFilesRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Database\BackupFilesRepository
 */
class BackupFilesRepositoryTest extends TestCase {

	/**
	 * @dataProvider good_names
	 */
	public function test_valid_file_names( $name ) {
		$this->assertTrue( BackupFilesRepository::validate_file_name( $name ) );
	}

	public function good_names() {
		return array(
			array( 'manifest.json' ),
			array( 'site-2026-01-01-db.sql.zip' ),
			array( 'a' ),
			array( 'name with spaces.tar.gz' ),
			array( 'weird\'quote.zip' ),
			array( str_repeat( 'a', 255 ) ),
			array( 'file.name.part1' ),
		);
	}

	/**
	 * @dataProvider bad_names
	 */
	public function test_invalid_file_names( $name ) {
		$this->assertFalse( BackupFilesRepository::validate_file_name( $name ) );
	}

	public function bad_names() {
		return array(
			'empty'          => array( '' ),
			'null'           => array( null ),
			'array'          => array( array( 'a.zip' ) ),
			'int'            => array( 5 ),
			'slash'          => array( 'dir/file.zip' ),
			'backslash'      => array( 'dir\\file.zip' ),
			'traversal'      => array( '../etc/passwd' ),
			'dots inside'    => array( 'a..b.zip' ),
			'only dots'      => array( '..' ),
			'nul byte'       => array( "a\0.zip" ),
			'newline'        => array( "a\n.zip" ),
			'tab'            => array( "a\t.zip" ),
			'delete char'    => array( "a\x7f.zip" ),
			'too long'       => array( str_repeat( 'a', 256 ) ),
			'absolute posix' => array( '/etc/passwd' ),
			'windows drive'  => array( 'C:\\x.zip' ),
		);
	}

	public function test_normalise_defaults_and_lowercases_checksum() {
		$row = BackupFilesRepository::normalise(
			array(
				'backup_id'       => '3',
				'file_name'       => 'a.zip',
				'checksum_sha256' => strtoupper( str_repeat( 'ab', 32 ) ),
			)
		);

		$this->assertSame( 3, $row['backup_id'] );
		$this->assertSame( 0, $row['destination_id'] );
		$this->assertSame( 0, $row['part_number'] );
		$this->assertSame( 0, $row['size'] );
		$this->assertSame( str_repeat( 'ab', 32 ), $row['checksum_sha256'] );
		$this->assertSame( 'pending', $row['upload_status'] );
		$this->assertSame( '', $row['remote_ref'] );
		$this->assertSame( '', $row['upload_session'] );
	}

	public function test_empty_checksum_allowed() {
		$this->assertSame( '', BackupFilesRepository::normalise_checksum( '' ) );
		$this->assertSame( '', BackupFilesRepository::normalise_checksum( null ) );
	}

	/**
	 * @dataProvider invalid_rows
	 */
	public function test_invalid_rows_throw( array $input ) {
		$this->expectException( \InvalidArgumentException::class );
		BackupFilesRepository::normalise( $input );
	}

	public function invalid_rows() {
		$ok = array(
			'backup_id' => 1,
			'file_name' => 'a.zip',
		);
		return array(
			'missing backup'   => array( array( 'file_name' => 'a.zip' ) ),
			'zero backup'      => array( array_merge( $ok, array( 'backup_id' => 0 ) ) ),
			'sql backup'       => array( array_merge( $ok, array( 'backup_id' => '1 OR 1=1' ) ) ),
			'missing name'     => array( array( 'backup_id' => 1 ) ),
			'traversal name'   => array( array_merge( $ok, array( 'file_name' => '../x' ) ) ),
			'short checksum'   => array( array_merge( $ok, array( 'checksum_sha256' => 'abc' ) ) ),
			'non-hex checksum' => array( array_merge( $ok, array( 'checksum_sha256' => str_repeat( 'g', 64 ) ) ) ),
			'long checksum'    => array( array_merge( $ok, array( 'checksum_sha256' => str_repeat( 'a', 65 ) ) ) ),
			'checksum newline' => array( array_merge( $ok, array( 'checksum_sha256' => str_repeat( 'a', 64 ) . "\n" ) ) ),
			'bad status'       => array( array_merge( $ok, array( 'upload_status' => 'uploaded' ) ) ),
			'negative size'    => array( array_merge( $ok, array( 'size' => -1 ) ) ),
			'negative part'    => array( array_merge( $ok, array( 'part_number' => -1 ) ) ),
			'negative dest'    => array( array_merge( $ok, array( 'destination_id' => -1 ) ) ),
			'array remote ref' => array( array_merge( $ok, array( 'remote_ref' => array( 'x' ) ) ) ),
		);
	}

	public function test_upload_status_whitelist() {
		foreach ( array( 'pending', 'uploading', 'done', 'failed' ) as $status ) {
			$this->assertSame( $status, BackupFilesRepository::normalise_upload_status( $status ) );
		}
		$this->expectException( \InvalidArgumentException::class );
		BackupFilesRepository::normalise_upload_status( "done'; --" );
	}

	public function test_remote_ref_nul_bytes_removed_and_limited() {
		$row = BackupFilesRepository::normalise(
			array(
				'backup_id'      => 1,
				'file_name'      => 'a.zip',
				'remote_ref'     => "a\0b",
				'upload_session' => str_repeat( 'x', 70000 ),
			)
		);
		$this->assertSame( 'ab', $row['remote_ref'] );
		$this->assertSame( 65535, strlen( $row['upload_session'] ) );
	}

	public function test_hydrate_types() {
		$row = BackupFilesRepository::hydrate(
			array(
				'id'              => '1',
				'backup_id'       => '2',
				'destination_id'  => '0',
				'part_number'     => '1',
				'file_name'       => 'a.zip',
				'size'            => '99',
				'checksum_sha256' => '',
				'remote_ref'      => null,
				'upload_status'   => 'pending',
				'upload_session'  => null,
				'created_at'      => '2026-01-01 00:00:00',
				'updated_at'      => '2026-01-01 00:00:00',
			)
		);
		$this->assertSame( 99, $row['size'] );
		$this->assertSame( '', $row['remote_ref'] );
		$this->assertSame( 0, $row['destination_id'] );
	}
}
