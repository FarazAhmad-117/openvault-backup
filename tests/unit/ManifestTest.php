<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Support\Manifest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Support\Manifest
 */
class ManifestTest extends TestCase {

	const UUID = '3f2b8c1e-9a4d-4e6f-8b1a-2c3d4e5f6a7b';

	private function sha( $seed = 'a' ) {
		return hash( 'sha256', $seed );
	}

	private function fields( array $override = array() ) {
		return array_merge(
			array(
				'uuid'           => self::UUID,
				'plugin_version' => '0.2.0',
				'site_url'       => 'https://example.com/blog',
				'wp_version'     => '6.9.1',
				'php_version'    => '8.3.4',
				'table_prefix'   => 'wp_',
				'contents'       => array(
					'database' => true,
					'plugins'  => true,
					'themes'   => false,
					'uploads'  => true,
				),
				'format'         => 'zip',
				'encrypted'      => false,
				'parts'          => array(
					array(
						'name'   => 'site-db.sql.zip',
						'size'   => 1234,
						'sha256' => $this->sha( 'one' ),
						'kind'   => 'db',
						'index'  => 0,
					),
					array(
						'name'   => 'site-uploads-1.zip',
						'size'   => 99,
						'sha256' => $this->sha( 'two' ),
						'kind'   => 'files',
						'index'  => 1,
					),
				),
				'created_at'     => '2026-10-16T12:30:45Z',
			),
			$override
		);
	}

	private function enc( $value ) {
		return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	private function json_with( array $changes ) {
		$data = json_decode( Manifest::create( $this->fields() )->to_json(), true );
		foreach ( $changes as $key => $value ) {
			if ( null === $value ) {
				unset( $data[ $key ] );
			} else {
				$data[ $key ] = $value;
			}
		}
		return $this->enc( $data );
	}

	private function assertRejected( $json, $message = '' ) {
		try {
			Manifest::from_json( $json );
			$this->fail( 'Expected rejection. ' . $message );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public function test_create_and_getters() {
		$m = Manifest::create( $this->fields() );
		$this->assertSame( 1, $m->get_schema() );
		$this->assertSame( 'openvault-backup', $m->get_generator() );
		$this->assertSame( self::UUID, $m->get_uuid() );
		$this->assertSame( '0.2.0', $m->get_plugin_version() );
		$this->assertSame( 'https://example.com/blog', $m->get_site_url() );
		$this->assertSame( '6.9.1', $m->get_wp_version() );
		$this->assertSame( '8.3.4', $m->get_php_version() );
		$this->assertSame( 'wp_', $m->get_table_prefix() );
		$this->assertSame( 'zip', $m->get_format() );
		$this->assertFalse( $m->is_encrypted() );
		$this->assertSame( '2026-10-16T12:30:45Z', $m->get_created_at() );
		$this->assertTrue( $m->get_contents()['database'] );
		$this->assertSame( array( 'site-db.sql.zip', 'site-uploads-1.zip' ), $m->part_names() );
		$this->assertSame( 1333, $m->total_size() );
		$this->assertCount( 2, $m->get_parts() );
	}

	public function test_round_trip() {
		$m    = Manifest::create( $this->fields() );
		$copy = Manifest::from_json( $m->to_json() );
		$this->assertEquals( $m, $copy );
		$this->assertSame( $m->to_json(), $copy->to_json() );
	}

	public function test_output_is_stable_pretty_and_unescaped_slashes() {
		$json = Manifest::create( $this->fields() )->to_json();
		$this->assertSame( $json, Manifest::create( $this->fields() )->to_json() );
		$this->assertStringContainsString( "\n", $json );
		$this->assertStringContainsString( 'https://example.com/blog', $json );
		$this->assertSame(
			array( 'schema', 'generator', 'uuid', 'plugin_version', 'site_url', 'wp_version', 'php_version', 'table_prefix', 'contents', 'format', 'encrypted', 'parts', 'created_at' ),
			array_keys( json_decode( $json, true ) )
		);
		$part = json_decode( $json, true )['parts'][0];
		$this->assertSame( array( 'name', 'size', 'sha256', 'kind', 'index' ), array_keys( $part ) );
	}

	public function test_create_generates_uuid_and_created_at_when_missing() {
		$fields = $this->fields();
		unset( $fields['uuid'], $fields['created_at'] );
		$m = Manifest::create( $fields );
		$this->assertTrue( Manifest::is_valid_uuid( $m->get_uuid() ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $m->get_created_at() );
	}

	public function test_create_validates() {
		$this->expectException( \InvalidArgumentException::class );
		Manifest::create( $this->fields( array( 'format' => 'rar' ) ) );
	}

	public function test_all_formats_accepted() {
		foreach ( array( 'zip', 'zip-store', 'tar.gz', 'tar.bz2' ) as $format ) {
			$this->assertSame( $format, Manifest::create( $this->fields( array( 'format' => $format ) ) )->get_format() );
		}
	}

	public function test_sha256_is_normalized_to_lowercase() {
		$parts              = $this->fields()['parts'];
		$parts[0]['sha256'] = strtoupper( $this->sha( 'one' ) );
		$m                  = Manifest::create( $this->fields( array( 'parts' => $parts ) ) );
		$this->assertSame( $this->sha( 'one' ), $m->get_parts()[0]['sha256'] );
	}

	public function test_uuid_generator() {
		$seen = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$uuid = Manifest::generate_uuid();
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid );
			$this->assertTrue( Manifest::is_valid_uuid( $uuid ) );
			$seen[ $uuid ] = true;
		}
		$this->assertCount( 200, $seen );
	}

	public function test_is_valid_uuid() {
		$this->assertTrue( Manifest::is_valid_uuid( self::UUID ) );
		$this->assertFalse( Manifest::is_valid_uuid( '3f2b8c1e-9a4d-1e6f-8b1a-2c3d4e5f6a7b' ) ); // Version 1.
		$this->assertFalse( Manifest::is_valid_uuid( '3f2b8c1e-9a4d-4e6f-0b1a-2c3d4e5f6a7b' ) ); // Bad variant.
		$this->assertFalse( Manifest::is_valid_uuid( 'not-a-uuid' ) );
		$this->assertFalse( Manifest::is_valid_uuid( '' ) );
		$this->assertFalse( Manifest::is_valid_uuid( self::UUID . "\n" ) );
	}

	public function test_rejects_malformed_json() {
		foreach ( array( '', 'nope', '{', '[]', '"x"', '12', 'null' ) as $bad ) {
			$this->assertRejected( $bad, $bad );
		}
	}

	public function test_rejects_wrong_generator() {
		$this->assertRejected( $this->json_with( array( 'generator' => 'other-plugin' ) ) );
		$this->assertRejected( $this->json_with( array( 'generator' => null ) ) );
	}

	public function test_rejects_unsupported_schema() {
		$this->assertRejected( $this->json_with( array( 'schema' => 2 ) ) );
		$this->assertRejected( $this->json_with( array( 'schema' => '1' ) ) );
		$this->assertRejected( $this->json_with( array( 'schema' => null ) ) );
	}

	public function test_rejects_bad_uuid() {
		$this->assertRejected( $this->json_with( array( 'uuid' => 'abc' ) ) );
		$this->assertRejected( $this->json_with( array( 'uuid' => null ) ) );
		$this->assertRejected( $this->json_with( array( 'uuid' => '../../etc/passwd' ) ) );
	}

	public function test_rejects_bad_sha256() {
		$base = $this->fields()['parts'];
		foreach ( array( 'zz', str_repeat( 'g', 64 ), str_repeat( 'a', 63 ), str_repeat( 'a', 65 ), '', 5 ) as $bad ) {
			$parts              = $base;
			$parts[0]['sha256'] = $bad;
			$this->assertRejected( $this->json_with( array( 'parts' => $parts ) ), 'sha ' . $this->enc( $bad ) );
		}
	}

	public function test_rejects_negative_or_non_int_size() {
		$base = $this->fields()['parts'];
		foreach ( array( -1, '5', 1.5, null, true ) as $bad ) {
			$parts            = $base;
			$parts[0]['size'] = $bad;
			$this->assertRejected( $this->json_with( array( 'parts' => $parts ) ), 'size ' . $this->enc( $bad ) );
		}
	}

	public function test_rejects_unsafe_part_names() {
		$names = array( '../evil', 'a/b.zip', '..\\x', "bad\0name.zip", 'a..b.zip', "tab\there.zip", "nl\nx.zip", 'back\\slash.zip', '', "del\x7f.zip", '/abs.zip', '..' );
		$base  = $this->fields()['parts'];
		foreach ( $names as $name ) {
			$parts            = $base;
			$parts[0]['name'] = $name;
			$this->assertRejected( $this->json_with( array( 'parts' => $parts ) ), 'name ' . $this->enc( $name ) );
		}
	}

	public function test_create_rejects_unsafe_part_name() {
		$parts            = $this->fields()['parts'];
		$parts[0]['name'] = '../evil';
		$this->expectException( \InvalidArgumentException::class );
		Manifest::create( $this->fields( array( 'parts' => $parts ) ) );
	}

	public function test_rejects_duplicate_part_names() {
		$parts            = $this->fields()['parts'];
		$parts[1]['name'] = $parts[0]['name'];
		$this->assertRejected( $this->json_with( array( 'parts' => $parts ) ) );
	}

	public function test_rejects_bad_kind_and_index() {
		$base          = $this->fields()['parts'];
		$p1            = $base;
		$p1[0]['kind'] = 'script';
		$this->assertRejected( $this->json_with( array( 'parts' => $p1 ) ) );
		$p2             = $base;
		$p2[0]['index'] = -1;
		$this->assertRejected( $this->json_with( array( 'parts' => $p2 ) ) );
		$p3 = $base;
		unset( $p3[0]['kind'] );
		$this->assertRejected( $this->json_with( array( 'parts' => $p3 ) ) );
	}

	public function test_rejects_parts_that_are_not_a_list() {
		$parts = array( 'x' => $this->fields()['parts'][0] );
		$this->assertRejected( $this->json_with( array( 'parts' => $parts ) ) );
		$this->assertRejected( $this->json_with( array( 'parts' => 'x' ) ) );
	}

	public function test_rejects_too_many_parts() {
		$parts = array();
		for ( $i = 0; $i < 10001; $i++ ) {
			$parts[] = array(
				'name'   => 'p' . $i . '.zip',
				'size'   => 1,
				'sha256' => str_repeat( 'a', 64 ),
				'kind'   => 'files',
				'index'  => $i,
			);
		}
		$this->assertRejected( $this->json_with( array( 'parts' => $parts ) ) );
	}

	public function test_accepts_exactly_max_parts() {
		$parts = array();
		for ( $i = 0; $i < 10000; $i++ ) {
			$parts[] = array(
				'name'   => 'p' . $i . '.zip',
				'size'   => 1,
				'sha256' => str_repeat( 'a', 64 ),
				'kind'   => 'files',
				'index'  => $i,
			);
		}
		$m = Manifest::from_json( $this->json_with( array( 'parts' => $parts ) ) );
		$this->assertCount( 10000, $m->get_parts() );
		$this->assertSame( 10000, $m->total_size() );
	}

	public function test_rejects_oversized_input() {
		$json = $this->json_with( array( 'site_url' => 'https://example.com/' ) );
		$pad  = $json . str_repeat( ' ', 5 * 1024 * 1024 );
		$this->assertRejected( $pad );
	}

	public function test_rejects_bad_created_at() {
		foreach ( array( '2026-10-16', '2026-10-16 12:30:45', '2026-13-45T25:61:61Z', '2026-10-16T12:30:45+02:00', 'yesterday', '' ) as $bad ) {
			$this->assertRejected( $this->json_with( array( 'created_at' => $bad ) ), $bad );
		}
	}

	public function test_rejects_bad_basic_fields() {
		$this->assertRejected( $this->json_with( array( 'encrypted' => 'yes' ) ) );
		$this->assertRejected( $this->json_with( array( 'format' => 'rar' ) ) );
		$this->assertRejected( $this->json_with( array( 'table_prefix' => 'wp-;DROP' ) ) );
		$this->assertRejected( $this->json_with( array( 'contents' => array( 'database' => 'yes' ) ) ) );
		$this->assertRejected( $this->json_with( array( 'contents' => 'all' ) ) );
		$this->assertRejected( $this->json_with( array( 'plugin_version' => null ) ) );
		$this->assertRejected( $this->json_with( array( 'site_url' => array( 'x' ) ) ) );
		$this->assertRejected( $this->json_with( array( 'php_version' => "8.3\n" ) ) );
	}

	public function test_unknown_extra_keys_ignored_on_parse() {
		$data                      = json_decode( Manifest::create( $this->fields() )->to_json(), true );
		$data['future']            = array( 'anything' => 1 );
		$data['parts'][0]['extra'] = 'x';
		$m                         = Manifest::from_json( $this->enc( $data ) );
		$this->assertArrayNotHasKey( 'future', json_decode( $m->to_json(), true ) );
		$this->assertArrayNotHasKey( 'extra', $m->get_parts()[0] );
	}

	public function test_error_messages_do_not_echo_untrusted_content() {
		$parts            = $this->fields()['parts'];
		$parts[0]['name'] = '../SECRET-PATH-MARKER';
		try {
			Manifest::from_json( $this->json_with( array( 'parts' => $parts ) ) );
			$this->fail( 'Expected rejection.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringNotContainsString( 'SECRET-PATH-MARKER', $e->getMessage() );
		}
	}
}
