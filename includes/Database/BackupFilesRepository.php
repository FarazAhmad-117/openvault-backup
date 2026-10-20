<?php
/**
 * CRUD for {prefix}ovb_backup_files (one row per archive part per destination).
 *
 * Phase: MVP
 *
 * Purpose: CRUD for {prefix}ovb_backup_files (one row per archive part per destination).
 *
 * Responsibilities:
 *  - Track remote id/path, size, checksum, upload status
 *  - Find all parts of a backup for download/restore
 *
 * destination_id 0 means the local folder. File names are validated like manifest part names:
 * a plain name with no separators, no "..", no control characters.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for backup file rows.
 */
class BackupFilesRepository {

	const UPLOAD_STATUSES = array( 'pending', 'uploading', 'done', 'failed' );

	const COLUMNS = 'id, backup_id, destination_id, part_number, file_name, size, checksum_sha256, remote_ref, upload_status, upload_session, created_at, updated_at';

	/**
	 * Full table name (prefix aware).
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'ovb_backup_files';
	}

	/**
	 * A safe plain file name: non-empty, at most 255 bytes, no "/", "\", "..", or control characters.
	 *
	 * @param mixed $name Name.
	 * @return bool
	 */
	public static function validate_file_name( $name ) {
		return is_string( $name )
			&& '' !== $name
			&& strlen( $name ) <= 255
			&& false === strpos( $name, '/' )
			&& false === strpos( $name, '\\' )
			&& false === strpos( $name, '..' )
			&& 1 !== preg_match( '/[\x00-\x1f\x7f]/', $name );
	}

	/**
	 * Validate and normalise a new file row.
	 *
	 * @param array<string,mixed> $input Fields: backup_id (required, >= 1), file_name (required), destination_id,
	 *                                   part_number, size, checksum_sha256, remote_ref, upload_status, upload_session.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When a value is invalid.
	 */
	public static function normalise( array $input ) {
		$backup_id = isset( $input['backup_id'] ) ? Util::non_negative_int( $input['backup_id'], 'backup_id' ) : 0;
		if ( $backup_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid backup_id.' );
		}
		if ( ! isset( $input['file_name'] ) || ! self::validate_file_name( $input['file_name'] ) ) {
			throw new \InvalidArgumentException( 'Invalid file_name.' );
		}

		$out = array(
			'backup_id'       => $backup_id,
			'destination_id'  => isset( $input['destination_id'] ) ? Util::non_negative_int( $input['destination_id'], 'destination_id' ) : 0,
			'part_number'     => isset( $input['part_number'] ) ? Util::non_negative_int( $input['part_number'], 'part_number' ) : 0,
			'file_name'       => $input['file_name'],
			'size'            => isset( $input['size'] ) ? Util::non_negative_int( $input['size'], 'size' ) : 0,
			'checksum_sha256' => self::normalise_checksum( isset( $input['checksum_sha256'] ) ? $input['checksum_sha256'] : '' ),
			'remote_ref'      => self::optional_text( $input, 'remote_ref' ),
			'upload_status'   => self::normalise_upload_status( isset( $input['upload_status'] ) ? $input['upload_status'] : 'pending' ),
			'upload_session'  => self::optional_text( $input, 'upload_session' ),
		);

		return $out;
	}

	/**
	 * A SHA-256 checksum: 64 hex characters (lower-cased) or an empty string.
	 *
	 * @param mixed $checksum Checksum.
	 * @return string
	 * @throws \InvalidArgumentException When malformed.
	 */
	public static function normalise_checksum( $checksum ) {
		if ( '' === $checksum || null === $checksum ) {
			return '';
		}
		if ( ! is_string( $checksum ) || 1 !== preg_match( '/^[0-9a-fA-F]{64}\z/', $checksum ) ) {
			throw new \InvalidArgumentException( 'Invalid checksum_sha256.' );
		}
		return strtolower( $checksum );
	}

	/**
	 * Validate an upload status.
	 *
	 * @param mixed $status Status.
	 * @return string
	 * @throws \InvalidArgumentException When unknown.
	 */
	public static function normalise_upload_status( $status ) {
		$ok = Util::one_of( $status, self::UPLOAD_STATUSES );
		if ( null === $ok ) {
			throw new \InvalidArgumentException( 'Invalid upload_status.' );
		}
		return $ok;
	}

	/**
	 * Insert a file row.
	 *
	 * @param array<string,mixed> $fields See normalise().
	 * @return int New id, 0 on database failure.
	 * @throws \InvalidArgumentException When a value is invalid.
	 */
	public function add( array $fields ) {
		global $wpdb;

		$data               = self::normalise( $fields );
		$now                = Util::now();
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			$data,
			self::formats( $data )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Find one row.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every row of a backup (all destinations), ordered by destination then part.
	 *
	 * @param int $backup_id Backup id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_backup( $backup_id ) {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE backup_id = %d ORDER BY destination_id ASC, part_number ASC, id ASC", (int) $backup_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Rows of a backup at one destination (0 = local), ordered by part.
	 *
	 * @param int $backup_id      Backup id.
	 * @param int $destination_id Destination id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_backup_and_destination( $backup_id, $destination_id ) {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE backup_id = %d AND destination_id = %d ORDER BY part_number ASC, id ASC", (int) $backup_id, (int) $destination_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Update the upload status of a row.
	 *
	 * @param int                 $id     Row id.
	 * @param string              $status pending, uploading, done or failed.
	 * @param array<string,mixed> $extra  Optional remote_ref, upload_session, size, checksum_sha256.
	 * @return bool False when the row does not exist or the query failed.
	 * @throws \InvalidArgumentException For an unknown status or invalid extra value.
	 */
	public function update_upload( $id, $status, array $extra = array() ) {
		global $wpdb;

		$data = array( 'upload_status' => self::normalise_upload_status( $status ) );
		foreach ( array( 'remote_ref', 'upload_session' ) as $key ) {
			if ( array_key_exists( $key, $extra ) ) {
				$data[ $key ] = self::optional_text( $extra, $key );
			}
		}
		if ( array_key_exists( 'size', $extra ) ) {
			$data['size'] = Util::non_negative_int( $extra['size'], 'size' );
		}
		if ( array_key_exists( 'checksum_sha256', $extra ) ) {
			$data['checksum_sha256'] = self::normalise_checksum( $extra['checksum_sha256'] );
		}
		$data['updated_at'] = Util::now();

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			$data,
			array( 'id' => (int) $id ),
			self::formats( $data ),
			array( '%d' )
		);
		if ( false === $result ) {
			return false;
		}

		return null !== $this->find( $id );
	}

	/**
	 * The next row to upload for a backup and destination: a row already "uploading" (so an
	 * interrupted upload resumes first), otherwise the lowest-numbered "pending" row.
	 *
	 * @param int $backup_id      Backup id.
	 * @param int $destination_id Destination id.
	 * @return array<string,mixed>|null
	 */
	public function next_pending( $backup_id, $destination_id ) {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . " FROM {$table} WHERE backup_id = %d AND destination_id = %d AND upload_status IN (%s,%s) ORDER BY (upload_status = %s) DESC, part_number ASC, id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Constant column list and whitelisted fragments only; values are bound.
				(int) $backup_id,
				(int) $destination_id,
				'pending',
				'uploading',
				'uploading'
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Delete every row of a backup.
	 *
	 * @param int $backup_id Backup id.
	 * @return int Rows deleted.
	 */
	public function delete_for_backup( $backup_id ) {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table(),
			array( 'backup_id' => (int) $backup_id ),
			array( '%d' )
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Totals for a backup. "bytes" adds up every row, so a part stored at two destinations counts twice.
	 *
	 * @param int $backup_id Backup id.
	 * @return array{rows:int,bytes:int,by_status:array<string,int>}
	 */
	public function totals_for_backup( $backup_id ) {
		global $wpdb;

		$table  = $this->table();
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT upload_status, COUNT(*) AS total, COALESCE(SUM(size), 0) AS bytes FROM {$table} WHERE backup_id = %d GROUP BY upload_status", (int) $backup_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$totals = array(
			'rows'      => 0,
			'bytes'     => 0,
			'by_status' => array_fill_keys( self::UPLOAD_STATUSES, 0 ),
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$totals['rows']  += (int) $row['total'];
			$totals['bytes'] += (int) $row['bytes'];
			if ( isset( $totals['by_status'][ $row['upload_status'] ] ) ) {
				$totals['by_status'][ $row['upload_status'] ] = (int) $row['total'];
			}
		}

		return $totals;
	}

	/**
	 * Convert a raw row into a typed array.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ) {
		return array(
			'id'              => (int) $row['id'],
			'backup_id'       => (int) $row['backup_id'],
			'destination_id'  => (int) $row['destination_id'],
			'part_number'     => (int) $row['part_number'],
			'file_name'       => (string) $row['file_name'],
			'size'            => (int) $row['size'],
			'checksum_sha256' => (string) $row['checksum_sha256'],
			'remote_ref'      => isset( $row['remote_ref'] ) ? (string) $row['remote_ref'] : '',
			'upload_status'   => (string) $row['upload_status'],
			'upload_session'  => isset( $row['upload_session'] ) ? (string) $row['upload_session'] : '',
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		);
	}

	/**
	 * An optional free-text field (remote id, upload session): scalar, no NUL bytes, at most 65535 bytes.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $key   Key.
	 * @return string
	 * @throws \InvalidArgumentException For non-scalar input.
	 */
	private static function optional_text( array $input, $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			return '';
		}
		if ( ! is_scalar( $input[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Invalid ' . $key . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed field name.
		}
		return substr( str_replace( "\0", '', (string) $input[ $key ] ), 0, 65535 );
	}

	/**
	 * Placeholder formats for a column => value map.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string[]
	 */
	private static function formats( array $data ) {
		$ints    = array( 'backup_id', 'destination_id', 'part_number', 'size' );
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = in_array( $column, $ints, true ) ? '%d' : '%s';
		}
		return $formats;
	}
}
