<?php
/**
 * Builds/parses manifest.json stored next to each backup.
 *
 * Phase: MVP
 *
 * Responsibilities:
 *  - Plugin version, site URL, WP/PHP versions, table prefix, contents, parts, checksums, format, encrypted flag
 *  - A manifest read from disk or an uploaded archive is UNTRUSTED input: from_json() rejects anything
 *    that is not ours (wrong generator/schema/uuid, bad checksums, unsafe part names, oversized input).
 *    Error messages never echo the untrusted content.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Manifest.
 */
final class Manifest {

	const SCHEMA    = 1;
	const GENERATOR = 'openvault-backup';

	const FORMATS    = array( 'zip', 'zip-store', 'tar.gz', 'tar.bz2' );
	const PART_KINDS = array( 'db', 'files', 'other' );

	const MAX_BYTES = 5242880;
	const MAX_PARTS = 10000;

	const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';

	/**
	 * UUID.
	 *
	 * @var string
	 */
	private $uuid = '';

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $plugin_version = '';

	/**
	 * Site URL.
	 *
	 * @var string
	 */
	private $site_url = '';

	/**
	 * WordPress version.
	 *
	 * @var string
	 */
	private $wp_version = '';

	/**
	 * PHP version.
	 *
	 * @var string
	 */
	private $php_version = '';

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	private $table_prefix = '';

	/**
	 * Contents flags.
	 *
	 * @var array<string,bool>
	 */
	private $contents = array();

	/**
	 * Archive format.
	 *
	 * @var string
	 */
	private $format = '';

	/**
	 * Encrypted flag.
	 *
	 * @var bool
	 */
	private $encrypted = false;

	/**
	 * Parts.
	 *
	 * @var array<int,array{name:string,size:int,sha256:string,kind:string,index:int}>
	 */
	private $parts = array();

	/**
	 * Creation time (UTC).
	 *
	 * @var string
	 */
	private $created_at = '';

	/**
	 * Use the factories.
	 */
	private function __construct() {
	}

	/**
	 * Build a manifest from trusted fields. `uuid` and `created_at` default to fresh values.
	 *
	 * @param array<string,mixed> $fields Fields.
	 * @return self
	 * @throws \InvalidArgumentException When a field is invalid.
	 */
	public static function create( array $fields ) {
		if ( ! isset( $fields['uuid'] ) ) {
			$fields['uuid'] = self::generate_uuid();
		}
		if ( ! isset( $fields['created_at'] ) ) {
			$fields['created_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		}
		return self::from_array( $fields, false );
	}

	/**
	 * Parse an UNTRUSTED manifest.json.
	 *
	 * @param string $json JSON.
	 * @return self
	 * @throws \InvalidArgumentException When the document is not a valid OpenVault manifest.
	 */
	public static function from_json( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw new \InvalidArgumentException( 'Manifest is empty.' );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'Manifest is too large.' );
		}

		$data = json_decode( $json, true, 16 );
		if ( ! is_array( $data ) || array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			throw new \InvalidArgumentException( 'Manifest is not a valid JSON object.' );
		}

		return self::from_array( $data, true );
	}

	/**
	 * RFC 4122 version 4 UUID.
	 *
	 * @return string
	 */
	public static function generate_uuid() {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * Whether a string is a version 4 UUID.
	 *
	 * @param mixed $uuid Value.
	 * @return bool
	 */
	public static function is_valid_uuid( $uuid ) {
		return is_string( $uuid ) && 1 === preg_match( self::UUID_PATTERN, $uuid );
	}

	/**
	 * Pretty JSON with a stable key order.
	 *
	 * @return string
	 * @throws \InvalidArgumentException If encoding fails (cannot happen for validated data).
	 */
	public function to_json() {
		$json = json_encode( $this->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Works without WordPress loaded (unit tests, standalone restore).
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'Manifest cannot be encoded.' );
		}
		return $json;
	}

	/**
	 * Array form in stable key order.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'schema'         => self::SCHEMA,
			'generator'      => self::GENERATOR,
			'uuid'           => $this->uuid,
			'plugin_version' => $this->plugin_version,
			'site_url'       => $this->site_url,
			'wp_version'     => $this->wp_version,
			'php_version'    => $this->php_version,
			'table_prefix'   => $this->table_prefix,
			'contents'       => $this->contents,
			'format'         => $this->format,
			'encrypted'      => $this->encrypted,
			'parts'          => $this->parts,
			'created_at'     => $this->created_at,
		);
	}

	/**
	 * Schema.
	 *
	 * @return int
	 */
	public function get_schema() {
		return self::SCHEMA;
	}

	/**
	 * Generator.
	 *
	 * @return string
	 */
	public function get_generator() {
		return self::GENERATOR;
	}

	/**
	 * UUID.
	 *
	 * @return string
	 */
	public function get_uuid() {
		return $this->uuid;
	}

	/**
	 * Plugin version.
	 *
	 * @return string
	 */
	public function get_plugin_version() {
		return $this->plugin_version;
	}

	/**
	 * Site URL.
	 *
	 * @return string
	 */
	public function get_site_url() {
		return $this->site_url;
	}

	/**
	 * WordPress version.
	 *
	 * @return string
	 */
	public function get_wp_version() {
		return $this->wp_version;
	}

	/**
	 * PHP version.
	 *
	 * @return string
	 */
	public function get_php_version() {
		return $this->php_version;
	}

	/**
	 * Table prefix.
	 *
	 * @return string
	 */
	public function get_table_prefix() {
		return $this->table_prefix;
	}

	/**
	 * Contents flags.
	 *
	 * @return array<string,bool>
	 */
	public function get_contents() {
		return $this->contents;
	}

	/**
	 * Format.
	 *
	 * @return string
	 */
	public function get_format() {
		return $this->format;
	}

	/**
	 * Whether the archives are encrypted.
	 *
	 * @return bool
	 */
	public function is_encrypted() {
		return $this->encrypted;
	}

	/**
	 * Parts.
	 *
	 * @return array<int,array{name:string,size:int,sha256:string,kind:string,index:int}>
	 */
	public function get_parts() {
		return $this->parts;
	}

	/**
	 * Creation time (UTC, Y-m-d\TH:i:s\Z).
	 *
	 * @return string
	 */
	public function get_created_at() {
		return $this->created_at;
	}

	/**
	 * File names of all parts.
	 *
	 * @return string[]
	 */
	public function part_names() {
		return array_column( $this->parts, 'name' );
	}

	/**
	 * Sum of all part sizes in bytes.
	 *
	 * @return int
	 */
	public function total_size() {
		return (int) array_sum( array_column( $this->parts, 'size' ) );
	}

	/**
	 * Validate and build.
	 *
	 * @param array<string,mixed> $data   Fields.
	 * @param bool                $strict True for untrusted input: generator and schema are mandatory.
	 * @return self
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function from_array( array $data, $strict ) {
		if ( $strict || array_key_exists( 'generator', $data ) ) {
			if ( ! isset( $data['generator'] ) || self::GENERATOR !== $data['generator'] ) {
				throw new \InvalidArgumentException( 'Manifest was not created by OpenVault Backup.' );
			}
		}
		if ( $strict || array_key_exists( 'schema', $data ) ) {
			if ( ! isset( $data['schema'] ) || ! is_int( $data['schema'] ) || self::SCHEMA !== $data['schema'] ) {
				throw new \InvalidArgumentException( 'Unsupported manifest schema.' );
			}
		}

		$m = new self();
		if ( ! isset( $data['uuid'] ) || ! self::is_valid_uuid( $data['uuid'] ) ) {
			throw new \InvalidArgumentException( 'Manifest uuid is missing or invalid.' );
		}
		$m->uuid = strtolower( $data['uuid'] );

		$m->plugin_version = self::text( $data, 'plugin_version', 64 );
		$m->site_url       = self::text( $data, 'site_url', 2048 );
		$m->wp_version     = self::text( $data, 'wp_version', 64 );
		$m->php_version    = self::text( $data, 'php_version', 64 );

		$prefix = self::text( $data, 'table_prefix', 64 );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+\z/', $prefix ) ) {
			throw new \InvalidArgumentException( 'Manifest table_prefix is invalid.' );
		}
		$m->table_prefix = $prefix;

		$m->contents = self::contents( $data );

		if ( ! isset( $data['format'] ) || ! is_string( $data['format'] ) || ! in_array( $data['format'], self::FORMATS, true ) ) {
			throw new \InvalidArgumentException( 'Manifest format is not supported.' );
		}
		$m->format = $data['format'];

		if ( ! isset( $data['encrypted'] ) || ! is_bool( $data['encrypted'] ) ) {
			throw new \InvalidArgumentException( 'Manifest encrypted flag is invalid.' );
		}
		$m->encrypted = $data['encrypted'];

		$m->parts = self::parts( $data );

		$created = self::text( $data, 'created_at', 20 );
		if ( ! self::is_valid_timestamp( $created ) ) {
			throw new \InvalidArgumentException( 'Manifest created_at is invalid.' );
		}
		$m->created_at = $created;

		return $m;
	}

	/**
	 * Required printable string field.
	 *
	 * @param array<string,mixed> $data Fields.
	 * @param string              $key  Key.
	 * @param int                 $max  Maximum length in bytes.
	 * @return string
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function text( array $data, $key, $max ) {
		if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || '' === $data[ $key ] || strlen( $data[ $key ] ) > $max || 1 === preg_match( '/[\x00-\x1f\x7f]/', $data[ $key ] ) ) {
			throw new \InvalidArgumentException( 'A manifest text field is missing or invalid.' );
		}
		return $data[ $key ];
	}

	/**
	 * Contents flags.
	 *
	 * @param array<string,mixed> $data Fields.
	 * @return array<string,bool>
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function contents( array $data ) {
		if ( ! isset( $data['contents'] ) || ! is_array( $data['contents'] ) || count( $data['contents'] ) > 50 ) {
			throw new \InvalidArgumentException( 'Manifest contents are invalid.' );
		}
		$out = array();
		foreach ( $data['contents'] as $key => $flag ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_]{1,40}\z/', $key ) || ! is_bool( $flag ) ) {
				throw new \InvalidArgumentException( 'Manifest contents are invalid.' );
			}
			$out[ $key ] = $flag;
		}
		return $out;
	}

	/**
	 * Parts list.
	 *
	 * @param array<string,mixed> $data Fields.
	 * @return array<int,array{name:string,size:int,sha256:string,kind:string,index:int}>
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function parts( array $data ) {
		if ( ! isset( $data['parts'] ) || ! is_array( $data['parts'] ) ) {
			throw new \InvalidArgumentException( 'Manifest parts are invalid.' );
		}
		$count = count( $data['parts'] );
		if ( $count > self::MAX_PARTS ) {
			throw new \InvalidArgumentException( 'Manifest has too many parts.' );
		}
		if ( $count > 0 && array_keys( $data['parts'] ) !== range( 0, $count - 1 ) ) {
			throw new \InvalidArgumentException( 'Manifest parts must be a list.' );
		}

		$out   = array();
		$names = array();
		foreach ( $data['parts'] as $part ) {
			if ( ! is_array( $part ) ) {
				throw new \InvalidArgumentException( 'Manifest part is invalid.' );
			}
			$name = isset( $part['name'] ) ? $part['name'] : null;
			if ( ! self::is_safe_part_name( $name ) ) {
				throw new \InvalidArgumentException( 'Manifest part name is unsafe or invalid.' );
			}
			if ( isset( $names[ $name ] ) ) {
				throw new \InvalidArgumentException( 'Manifest part names must be unique.' );
			}
			$names[ $name ] = true;

			if ( ! isset( $part['size'] ) || ! is_int( $part['size'] ) || $part['size'] < 0 ) {
				throw new \InvalidArgumentException( 'Manifest part size is invalid.' );
			}
			if ( ! isset( $part['sha256'] ) || ! is_string( $part['sha256'] ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/i', $part['sha256'] ) ) {
				throw new \InvalidArgumentException( 'Manifest part checksum is invalid.' );
			}
			if ( ! isset( $part['kind'] ) || ! is_string( $part['kind'] ) || ! in_array( $part['kind'], self::PART_KINDS, true ) ) {
				throw new \InvalidArgumentException( 'Manifest part kind is invalid.' );
			}
			if ( ! isset( $part['index'] ) || ! is_int( $part['index'] ) || $part['index'] < 0 ) {
				throw new \InvalidArgumentException( 'Manifest part index is invalid.' );
			}

			$out[] = array(
				'name'   => $name,
				'size'   => $part['size'],
				'sha256' => strtolower( $part['sha256'] ),
				'kind'   => $part['kind'],
				'index'  => $part['index'],
			);
		}//end foreach
		return $out;
	}

	/**
	 * A part name is a plain file name: no separators, no "..", no control characters.
	 *
	 * @param mixed $name Name.
	 * @return bool
	 */
	private static function is_safe_part_name( $name ) {
		return is_string( $name )
			&& '' !== $name
			&& strlen( $name ) <= 255
			&& false === strpos( $name, '/' )
			&& false === strpos( $name, '\\' )
			&& false === strpos( $name, '..' )
			&& 1 !== preg_match( '/[\x00-\x1f\x7f]/', $name );
	}

	/**
	 * Strict UTC timestamp check (Y-m-d\TH:i:s\Z).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_valid_timestamp( $value ) {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value ) ) {
			return false;
		}
		$date = \DateTime::createFromFormat( 'Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value;
	}
}
