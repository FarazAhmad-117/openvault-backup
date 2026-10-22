<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Support\Manifest;

/**
 * Shared fixtures of the restore tests (no WordPress, no database).
 */
final class RestoreFixtures {

	const UUID          = '3f2b8c1e-9a4d-4e6f-8b1a-2c3d4e5f6a7b';
	const MANIFEST_SIZE = 500;

	/**
	 * A manifest with one database part and two files parts.
	 *
	 * @param array<string,mixed> $override Fields to replace.
	 * @return Manifest
	 */
	public static function manifest( array $override = array() ) {
		return Manifest::create(
			array_merge(
				array(
					'uuid'           => self::UUID,
					'plugin_version' => '0.2.0',
					'site_url'       => 'https://example.com',
					'wp_version'     => '6.9.1',
					'php_version'    => '8.3.4',
					'table_prefix'   => 'wp_',
					'contents'       => array(
						'db'      => true,
						'uploads' => true,
					),
					'format'         => 'zip',
					'encrypted'      => false,
					'parts'          => array(
						self::part( 'db.part01.zip', 100, 'db', 0 ),
						self::part( 'files.part01.zip', 1000, 'files', 1 ),
						self::part( 'files.part02.zip', 2000, 'files', 2 ),
					),
					'created_at'     => '2026-03-04T05:06:07Z',
				),
				$override
			)
		);
	}

	/**
	 * A manifest part.
	 *
	 * @param string $name  File name.
	 * @param int    $size  Size.
	 * @param string $kind  db or files.
	 * @param int    $index Index.
	 * @return array{name:string,size:int,sha256:string,kind:string,index:int}
	 */
	public static function part( $name, $size, $kind, $index ) {
		return array(
			'name'   => $name,
			'size'   => $size,
			'sha256' => hash( 'sha256', $name ),
			'kind'   => $kind,
			'index'  => $index,
		);
	}

	/**
	 * A finished backup row.
	 *
	 * @param array<string,mixed> $override Fields to replace.
	 * @return array<string,mixed>
	 */
	public static function backup( array $override = array() ) {
		return array_merge(
			array(
				'id'           => 7,
				'uuid'         => self::UUID,
				'trigger_type' => 'manual',
				'created_at'   => '2026-03-04 05:06:07',
				'encrypted'    => false,
				'status'       => 'complete',
			),
			$override
		);
	}

	/**
	 * File rows of one destination (0 = local): the manifest and every part, all done.
	 *
	 * @param Manifest            $manifest       Manifest.
	 * @param int                 $destination_id Destination id.
	 * @param array<string,mixed> $override       Fields to replace in every row of the given file name: name => fields.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( Manifest $manifest, $destination_id, array $override = array() ) {
		$files   = array();
		$files[] = array( 'manifest.json', self::MANIFEST_SIZE, hash( 'sha256', 'manifest' ), 0 );
		foreach ( $manifest->get_parts() as $part ) {
			$files[] = array( $part['name'], $part['size'], $part['sha256'], $part['index'] + 1 );
		}

		$rows = array();
		foreach ( $files as $i => $file ) {
			$row    = array(
				'id'              => $destination_id * 100 + $i + 1,
				'backup_id'       => 7,
				'destination_id'  => $destination_id,
				'part_number'     => $file[3],
				'file_name'       => $file[0],
				'size'            => $file[1],
				'checksum_sha256' => $file[2],
				'remote_ref'      => $destination_id > 0 ? 'OpenVault-Backups/example.com/2026-03-04_050607_3f2b8c1e/' . $file[0] : '',
				'upload_status'   => 'done',
				'upload_session'  => '',
			);
			$rows[] = isset( $override[ $file[0] ] ) ? array_merge( $row, $override[ $file[0] ] ) : $row;
		}
		return $rows;
	}

	/**
	 * Files of a complete local copy (name => size).
	 *
	 * @param Manifest $manifest Manifest.
	 * @return array<string,int>
	 */
	public static function local_files( Manifest $manifest ) {
		$out = array( 'manifest.json' => self::MANIFEST_SIZE );
		foreach ( $manifest->get_parts() as $part ) {
			$out[ $part['name'] ] = $part['size'];
		}
		return $out;
	}
}
