<?php
/**
 * Reads table structure for the database export.
 *
 * Phase: MVP
 *
 * Purpose: Finds the tables, their columns and indexes, and decides how a table is paged.
 *
 * Responsibilities:
 *  - list_tables(), describe(), create_statement(): thin reads through $wpdb (SHOW FULL TABLES,
 *    SHOW FULL COLUMNS, SHOW INDEX, SHOW CREATE TABLE)
 *  - Pure helpers (static, unit tested without a database): normalise_columns(), normalise_indexes(),
 *    choose_strategy(), has_heavy_columns(), selectable_columns()
 *
 * Paging strategies, best first:
 *  1. keyset: a single-column integer PRIMARY KEY, or a single-column UNIQUE NOT NULL integer key:
 *     WHERE key > last ORDER BY key LIMIT n (constant cost per batch, stable while rows are added)
 *  2. offset ordered: the full PRIMARY KEY (composite or non-integer): ORDER BY pk LIMIT n OFFSET m
 *  3. offset unordered: no usable key. LIMIT n OFFSET m without ORDER BY. This relies on the storage engine
 *     returning rows in the same order every time, which InnoDB and MyISAM do for an idle table; rows written
 *     during the export may be missed or doubled. Documented risk for key-less tables.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Dump;

defined( 'ABSPATH' ) || exit;

/**
 * Table structure reader.
 */
class TableInspector {

	const STRATEGY_KEYSET = 'keyset';
	const STRATEGY_OFFSET = 'offset';

	/**
	 * Types whose rows can be very large.
	 */
	const HEAVY_TYPES = array( 'tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'json', 'geometry' );

	/**
	 * Character columns this wide count as heavy as well.
	 */
	const HEAVY_LENGTH = 4096;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db Database (defaults to the global $wpdb).
	 */
	public function __construct( $db = null ) {
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->db = $db;
	}

	/**
	 * Every table and view of the database.
	 *
	 * @return array<int,array{0:string,1:string}> Name and type ("BASE TABLE", "VIEW", ...).
	 * @throws DumpException On a database error.
	 */
	public function list_tables() {
		$rows = $this->db->get_results( 'SHOW FULL TABLES', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the schema; there is nothing to cache or prepare.
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) {
			throw new DumpException( 'The list of database tables could not be read.' );
		}
		$tables = array();
		foreach ( $rows as $row ) {
			$tables[] = array( (string) $row[0], isset( $row[1] ) ? (string) $row[1] : 'BASE TABLE' );
		}
		return $tables;
	}

	/**
	 * Columns and indexes of a table.
	 *
	 * @param string $table Table name.
	 * @return array{columns:array<int,array<string,mixed>>,indexes:array<int,array<string,mixed>>}|null Null when the table no longer exists.
	 * @throws DumpException On any other database error.
	 */
	public function describe( $table ) {
		$name    = SqlValueFormatter::identifier( $table );
		$columns = $this->db->get_results( 'SHOW FULL COLUMNS FROM ' . $name, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Schema read; the identifier is quoted by SqlValueFormatter::identifier().
		if ( $this->table_missing() ) {
			return null;
		}
		if ( ! is_array( $columns ) || array() === $columns ) {
			throw $this->structure_error( $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Text holds only a table name and a scrubbed database error; it is escaped where displayed.
		}
		$indexes = $this->db->get_results( 'SHOW INDEX FROM ' . $name, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Schema read; the identifier is quoted by SqlValueFormatter::identifier().
		if ( ! is_array( $indexes ) ) {
			throw $this->structure_error( $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Text holds only a table name and a scrubbed database error; it is escaped where displayed.
		}

		return array(
			'columns' => self::normalise_columns( $columns ),
			'indexes' => self::normalise_indexes( $indexes ),
		);
	}

	/**
	 * The CREATE TABLE statement as the server reports it.
	 *
	 * @param string $table Table name.
	 * @return string|null Null when the table no longer exists.
	 * @throws DumpException On any other database error.
	 */
	public function create_statement( $table ) {
		$row = $this->db->get_row( 'SHOW CREATE TABLE ' . SqlValueFormatter::identifier( $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Schema read; the identifier is quoted by SqlValueFormatter::identifier().
		if ( $this->table_missing() ) {
			return null;
		}
		if ( ! is_array( $row ) || ! isset( $row[1] ) || '' === (string) $row[1] ) {
			throw $this->structure_error( $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Text holds only a table name and a scrubbed database error; it is escaped where displayed.
		}
		return (string) $row[1];
	}

	/**
	 * Convert SHOW FULL COLUMNS rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows (keys Field, Type, Null, Key, Default, Extra, ...).
	 * @return array<int,array{name:string,type:string,null:bool,binary:bool,generated:bool,invisible:bool}>
	 */
	public static function normalise_columns( array $rows ) {
		$columns = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['Field'], $row['Type'] ) ) {
				continue;
			}
			$extra     = isset( $row['Extra'] ) ? (string) $row['Extra'] : '';
			$columns[] = array(
				'name'      => (string) $row['Field'],
				'type'      => strtolower( (string) $row['Type'] ),
				'null'      => isset( $row['Null'] ) && 'YES' === strtoupper( (string) $row['Null'] ),
				'binary'    => SqlValueFormatter::is_binary_type( (string) $row['Type'] ),
				// VIRTUAL / STORED GENERATED (MySQL), VIRTUAL / PERSISTENT (older MariaDB). "DEFAULT_GENERATED" is insertable.
				'generated' => 1 === preg_match( '/\b(VIRTUAL|STORED|PERSISTENT)\b/i', $extra ),
				'invisible' => 1 === preg_match( '/\bINVISIBLE\b/i', $extra ),
			);
		}
		return $columns;
	}

	/**
	 * Convert SHOW INDEX rows: one entry per index with its columns in order.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows (keys Key_name, Non_unique, Seq_in_index, Column_name).
	 * @return array<int,array{name:string,unique:bool,columns:string[]}>
	 */
	public static function normalise_indexes( array $rows ) {
		$grouped = array();
		foreach ( $rows as $row ) {
			// isset() is false for a missing key and for NULL (a functional index has no plain column).
			if ( ! isset( $row['Key_name'], $row['Column_name'] ) ) {
				continue;
			}
			$name = (string) $row['Key_name'];
			if ( ! isset( $grouped[ $name ] ) ) {
				$grouped[ $name ] = array(
					'name'    => $name,
					'unique'  => isset( $row['Non_unique'] ) && '0' === (string) $row['Non_unique'],
					'columns' => array(),
				);
			}
			$grouped[ $name ]['columns'][ isset( $row['Seq_in_index'] ) ? (int) $row['Seq_in_index'] : count( $grouped[ $name ]['columns'] ) + 1 ] = (string) $row['Column_name'];
		}

		$indexes = array();
		foreach ( $grouped as $index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
			$indexes[]        = $index;
		}
		return $indexes;
	}

	/**
	 * Choose how to page through a table.
	 *
	 * @param array<int,array<string,mixed>> $columns Result of normalise_columns().
	 * @param array<int,array<string,mixed>> $indexes Result of normalise_indexes().
	 * @return array{type:string,key?:string,order?:string[]} keyset with "key", or offset with "order" (empty = unordered).
	 */
	public static function choose_strategy( array $columns, array $indexes ) {
		$by_name = array();
		foreach ( $columns as $column ) {
			$by_name[ $column['name'] ] = $column;
		}

		$primary = null;
		foreach ( $indexes as $index ) {
			if ( 'PRIMARY' === $index['name'] ) {
				$primary = $index;
			}
		}

		if ( null !== $primary && 1 === count( $primary['columns'] ) && self::is_keyset_column( $by_name, $primary['columns'][0], false ) ) {
			return array(
				'type' => self::STRATEGY_KEYSET,
				'key'  => $primary['columns'][0],
			);
		}
		foreach ( $indexes as $index ) {
			if ( 'PRIMARY' !== $index['name'] && $index['unique'] && 1 === count( $index['columns'] ) && self::is_keyset_column( $by_name, $index['columns'][0], true ) ) {
				return array(
					'type' => self::STRATEGY_KEYSET,
					'key'  => $index['columns'][0],
				);
			}
		}

		return array(
			'type'  => self::STRATEGY_OFFSET,
			'order' => null === $primary ? array() : $primary['columns'],
		);
	}

	/**
	 * Whether a table has columns that can make a row very large.
	 *
	 * @param array<int,array<string,mixed>> $columns Result of normalise_columns().
	 * @return bool
	 */
	public static function has_heavy_columns( array $columns ) {
		foreach ( $columns as $column ) {
			$base = SqlValueFormatter::base_type( $column['type'] );
			if ( in_array( $base, self::HEAVY_TYPES, true ) ) {
				return true;
			}
			if ( in_array( $base, array( 'varchar', 'varbinary' ), true ) && 1 === preg_match( '/\((\d+)\)/', $column['type'], $found ) && (int) $found[1] >= self::HEAVY_LENGTH ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The columns that are read and written: everything except generated columns (the server computes them).
	 * Invisible columns are included.
	 *
	 * @param array<int,array<string,mixed>> $columns Result of normalise_columns().
	 * @return array<int,array<string,mixed>>
	 */
	public static function selectable_columns( array $columns ) {
		return array_values(
			array_filter(
				$columns,
				static function ( $column ) {
					return ! $column['generated'];
				}
			)
		);
	}

	/**
	 * Whether the INSERT statements need an explicit column list (a generated or invisible column exists, so
	 * "VALUES (...)" without names would not line up).
	 *
	 * @param array<int,array<string,mixed>> $columns Result of normalise_columns().
	 * @return bool
	 */
	public static function needs_column_list( array $columns ) {
		foreach ( $columns as $column ) {
			if ( $column['generated'] || $column['invisible'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a type is one of the integer types.
	 *
	 * @param string $type Column type.
	 * @return bool
	 */
	public static function is_integer_type( $type ) {
		return in_array( SqlValueFormatter::base_type( $type ), array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint' ), true );
	}

	/**
	 * Whether a column can drive keyset paging.
	 *
	 * @param array<string,array<string,mixed>> $by_name  Columns by name.
	 * @param string                            $name     Column.
	 * @param bool                              $not_null Require NOT NULL (always true for a primary key).
	 * @return bool
	 */
	private static function is_keyset_column( array $by_name, $name, $not_null ) {
		if ( ! isset( $by_name[ $name ] ) ) {
			return false;
		}
		$column = $by_name[ $name ];
		if ( $column['generated'] || ! self::is_integer_type( $column['type'] ) ) {
			return false;
		}
		return ! ( $not_null && $column['null'] );
	}

	/**
	 * Whether the last query failed because the table does not exist (it was dropped while the export ran).
	 *
	 * @return bool
	 */
	private function table_missing() {
		$error = (string) $this->db->last_error;
		return '' !== $error && false !== stripos( $error, "doesn't exist" );
	}

	/**
	 * Error for a failed structure read (table name only, no query and no data).
	 *
	 * @param string $table Table name.
	 * @return DumpException
	 */
	private function structure_error( $table ) {
		return new DumpException( sprintf( 'The structure of table "%s" could not be read.', $table ) );
	}
}
