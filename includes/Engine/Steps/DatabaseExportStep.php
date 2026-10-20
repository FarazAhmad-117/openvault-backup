<?php
/**
 * Chunked pure-PHP SQL export.
 *
 * Phase: MVP
 *
 * Purpose: Chunked pure-PHP SQL export (mysqldump if available & allowed: not used yet, see below).
 *
 * Responsibilities:
 *  - Table-by-table, N rows per batch (500 to start, adaptive between 1 and 2,000, see Dump\BatchSizer)
 *  - Respect excluded tables and rows (Dump\ExclusionRules): the plugin's own tables, its options, transients,
 *    ovb_settings['excluded_tables'] and the filter "ovb_backup_exclusions"
 *  - Write CREATE TABLE + INSERTs, handle binary/serialized data safely (binary and non-UTF-8 values as 0x<hex>)
 *  - Resumable: the cursor (table, key or offset, rows done, file byte offset) is saved after every slice; a
 *    resumed run first cuts db.sql back to the saved offset, so output of a killed run is never kept
 *  - Output: <work dir>/db.sql in the dump format described in docs/plan/04-backup-engine.md; when finished the
 *    state meta "db" holds { file, size, sha256, tables, rows } for the archive step
 *  - Skipped (next step at once) when contents['db'] is false
 *
 * Pure PHP only for now: mysqldump is never used (it cannot be resumed and needs exec and credentials on a command line).
 * The dump is not one database transaction: a live site can change between slices (documented in the plan).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Steps;

use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\Dump\BatchSizer;
use OpenVault\Backup\Engine\Dump\DumpException;
use OpenVault\Backup\Engine\Dump\ExclusionRules;
use OpenVault\Backup\Engine\Dump\SqlDumpWriter;
use OpenVault\Backup\Engine\Dump\SqlValueFormatter;
use OpenVault\Backup\Engine\Dump\TableInspector;
use OpenVault\Backup\Engine\Dump\TableList;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Database export step.
 */
class DatabaseExportStep implements StepInterface {

	const FILE_NAME = 'db.sql';

	/**
	 * Structure reader.
	 *
	 * @var TableInspector|null
	 */
	private $inspector;

	/**
	 * Constructor.
	 *
	 * @param TableInspector|null $inspector Structure reader (defaults to one on the global $wpdb).
	 */
	public function __construct( ?TableInspector $inspector = null ) {
		$this->inspector = $inspector;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id() {
		return JobState::STEP_DB_EXPORT;
	}

	/**
	 * Export as many rows as the budget allows.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ) {
		$backup   = $context->backup();
		$contents = isset( $backup['contents'] ) && is_array( $backup['contents'] ) ? $backup['contents'] : array();
		if ( empty( $contents['db'] ) ) {
			$context->log( Logger::INFO, __( 'The database is not part of this backup, skipping the database export.', 'openvault-backup' ) );
			return StepResult::next_step( $context->state() );
		}

		global $wpdb;
		$session = $this->begin_session( $wpdb );
		try {
			return $this->export( $context, $wpdb );
		} catch ( DumpException $e ) {
			return StepResult::fail(
				sprintf(
					/* translators: %s: reason. */
					__( 'The database export failed: %s', 'openvault-backup' ),
					$e->getMessage()
				),
				$context->state()
			);
		} finally {
			$this->end_session( $wpdb, $session );
		}
	}

	/**
	 * One slice of the export.
	 *
	 * @param JobContext $context Context.
	 * @param \wpdb      $db      Database.
	 * @return StepResult
	 * @throws DumpException On a database or file error.
	 */
	private function export( JobContext $context, $db ) {
		$budget = $context->budget();
		$path   = $context->work_dir() . '/' . self::FILE_NAME;
		$writer = new SqlDumpWriter( $path );
		$rules  = $this->rules( $context, $db );
		$cursor = self::read_cursor( $context->state()->get_cursor() );

		if ( null === $cursor ) {
			list( $cursor, $tables ) = $this->start( $context, $db, $rules, $writer );
		} else {
			$writer->resume( $cursor['file_offset'] );
			if ( $cursor['complete'] ) {
				return $this->finish( $context, $writer, $cursor, $path );
			}
			$tables = $this->tables_for( $cursor, $db, $rules );
		}

		$index = isset( $cursor['tables'] ) ? $cursor['table_index'] : TableList::position( $tables, $cursor['table'] );
		if ( null !== $cursor['strategy'] && ! isset( $cursor['tables'] ) && ( ! isset( $tables[ $index ] ) || $tables[ $index ] !== $cursor['table'] ) ) {
			// The table in progress was dropped while the export ran: its partial output stays in the file, move on.
			$context->log( Logger::WARNING, __( 'A table was removed while the backup was running and is skipped.', 'openvault-backup' ), array( 'table' => $cursor['table'] ) );
			$cursor = $this->reset_table( $cursor );
		}

		$schema = null;
		$count  = count( $tables );
		while ( $index < $count ) {
			$table = $tables[ $index ];

			if ( null === $cursor['strategy'] ) {
				$schema = $this->start_table( $writer, $table, $cursor );
				if ( null === $schema ) {
					$context->log( Logger::WARNING, __( 'A table was removed while the backup was running and is skipped.', 'openvault-backup' ), array( 'table' => $table ) );
					$cursor = $this->next_table( $cursor, $tables, $index, false );
					++$index;
					continue;
				}
			} elseif ( null === $schema ) {
				$described = $this->inspector( $db )->describe( $table );
				if ( null === $described ) {
					$context->log( Logger::WARNING, __( 'A table was removed while the backup was running and is skipped.', 'openvault-backup' ), array( 'table' => $table ) );
					$cursor = $this->next_table( $cursor, $tables, $index, false );
					++$index;
					continue;
				}
				$schema = self::prepare_schema( $described );
			}

			$cursor['table']       = $table;
			$cursor['table_index'] = $index;
			$finished              = $this->dump_batch( $db, $writer, $table, $cursor, $schema, $rules, $budget );

			if ( $finished ) {
				$context->log(
					Logger::INFO,
					__( 'Table exported.', 'openvault-backup' ),
					array(
						'table' => $table,
						'rows'  => $cursor['table_rows'],
					)
				);
				$cursor = $this->next_table( $cursor, $tables, $index, true );
				++$index;
				$schema = null;
			}

			if ( $budget->exhausted() ) {
				break;
			}
		}//end while

		if ( $index >= $count ) {
			$cursor['complete'] = true;
			$cursor['table']    = '';
			if ( $budget->exhausted() ) {
				// Footer and checksum are the next slice's job.
				$cursor['table_index'] = $index;
				return $this->save( $context, $writer, $cursor );
			}
			return $this->finish( $context, $writer, $cursor, $path );
		}

		return $this->save( $context, $writer, $cursor );
	}

	/**
	 * First slice: find the tables, create the file and write the header.
	 *
	 * @param JobContext     $context Context.
	 * @param \wpdb          $db      Database.
	 * @param ExclusionRules $rules   Exclusions.
	 * @param SqlDumpWriter  $writer  Writer (not yet open).
	 * @return array{0:array<string,mixed>,1:string[]} Cursor and table list.
	 * @throws DumpException On a database or file error.
	 */
	private function start( JobContext $context, $db, ExclusionRules $rules, SqlDumpWriter $writer ) {
		$charset = $this->connection_charset( $db );
		$tables  = TableList::select( $this->inspector( $db )->list_tables(), $db->base_prefix, $rules );

		$writer->begin();
		$writer->write_header(
			array(
				'table-prefix'   => $db->base_prefix,
				'charset'        => $charset,
				'plugin-version' => defined( 'OVB_VERSION' ) ? OVB_VERSION : '',
				'wp-version'     => get_bloginfo( 'version' ),
				'site-url'       => get_option( 'siteurl' ),
				'created-at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'tables'         => count( $tables ),
			),
			$charset
		);

		$cursor = array(
			'tables_total' => count( $tables ),
			'tables_done'  => 0,
			'table_index'  => 0,
			'table'        => isset( $tables[0] ) ? $tables[0] : '',
			'strategy'     => null,
			'last_key'     => null,
			'offset'       => 0,
			'table_rows'   => 0,
			'rows_done'    => 0,
			'batch'        => 0,
			'file_offset'  => $writer->bytes(),
			'complete'     => array() === $tables,
		);
		if ( TableList::fits_in_cursor( $tables ) ) {
			$cursor['tables'] = $tables;
		}

		$context->log( Logger::INFO, __( 'Database export started.', 'openvault-backup' ), array( 'tables' => count( $tables ) ) );
		return array( $cursor, $tables );
	}

	/**
	 * Write DROP + CREATE for a table and choose how to page it.
	 *
	 * @param SqlDumpWriter       $writer Writer.
	 * @param string              $table  Table.
	 * @param array<string,mixed> $cursor Cursor, updated.
	 * @return array<string,mixed>|null Schema, or null when the table no longer exists.
	 * @throws DumpException On a database or file error.
	 */
	private function start_table( SqlDumpWriter $writer, $table, array &$cursor ) {
		$inspector = $this->inspector();
		$described = $inspector->describe( $table );
		$create    = null === $described ? null : $inspector->create_statement( $table );
		if ( null === $described || null === $create ) {
			return null;
		}

		$writer->write_table( $table, $create );

		$cursor['strategy']   = TableInspector::choose_strategy( $described['columns'], $described['indexes'] );
		$cursor['last_key']   = null;
		$cursor['offset']     = 0;
		$cursor['table_rows'] = 0;
		$cursor['batch']      = BatchSizer::initial( TableInspector::has_heavy_columns( $described['columns'] ) );
		return self::prepare_schema( $described );
	}

	/**
	 * What the batch loop needs to know about a table's columns.
	 *
	 * @param array<string,mixed> $described Result of TableInspector::describe().
	 * @return array{names:string[],meta:array<int,array{type:string,binary:bool}>,list:bool,key_index:array<string,int>}
	 */
	private static function prepare_schema( array $described ) {
		$columns = TableInspector::selectable_columns( $described['columns'] );
		$names   = array();
		$meta    = array();
		$indexes = array();
		foreach ( $columns as $position => $column ) {
			$names[]                    = $column['name'];
			$indexes[ $column['name'] ] = $position;
			$meta[]                     = array(
				'type'   => $column['type'],
				'binary' => $column['binary'],
			);
		}
		return array(
			'names'     => $names,
			'meta'      => $meta,
			'list'      => TableInspector::needs_column_list( $described['columns'] ),
			'key_index' => $indexes,
		);
	}

	/**
	 * Export one batch of rows.
	 *
	 * @param \wpdb               $db     Database.
	 * @param SqlDumpWriter       $writer Writer.
	 * @param string              $table  Table.
	 * @param array<string,mixed> $cursor Cursor, updated.
	 * @param array<string,mixed> $schema Result of prepare_schema().
	 * @param ExclusionRules      $rules  Exclusions.
	 * @param Budget              $budget Budget (read for the memory check).
	 * @return bool True when the table has no more rows.
	 * @throws DumpException On a database or file error.
	 */
	private function dump_batch( $db, SqlDumpWriter $writer, $table, array &$cursor, array $schema, ExclusionRules $rules, Budget $budget ) {
		$limit = max( BatchSizer::MIN, (int) $cursor['batch'] );
		$sql   = $this->select_sql( $table, $cursor, $schema, $rules, $limit );

		$rows = $db->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifiers cannot be bound; every identifier is quoted by SqlValueFormatter::identifier() and the only literal is a validated integer.
		if ( ! is_array( $rows ) || '' !== $db->last_error ) {
			throw $this->db_failure( $table, $db ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Text holds only a table name and a scrubbed database error; it is escaped where displayed.
		}

		$count  = count( $rows );
		$tuples = array();
		$bytes  = 0;
		foreach ( $rows as $row ) {
			$tuple    = SqlValueFormatter::format_row( $row, $schema['meta'] );
			$bytes   += strlen( $tuple ) + 1;
			$tuples[] = $tuple;
		}
		$memory_tight = ! $budget->memory_ok();
		// Sampled while the rows and their text are in memory, the heaviest moment.

		if ( $count > 0 ) {
			$writer->write_rows( $table, $schema['list'] ? $schema['names'] : null, $tuples );
		}

		$strategy = $cursor['strategy'];
		if ( $count > 0 && is_array( $strategy ) && TableInspector::STRATEGY_KEYSET === $strategy['type'] ) {
			$last = $rows[ $count - 1 ][ $schema['key_index'][ $strategy['key'] ] ];
			if ( ! is_string( $last ) || 1 !== preg_match( '/^-?\d+$/', $last ) ) {
				throw $this->error( sprintf( 'Table "%s" has a key that cannot be used for paging.', $table ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Text holds only a table name and a scrubbed database error; it is escaped where displayed.
			}
			$cursor['last_key'] = $last;
		}

		$cursor['offset']     += $count;
		$cursor['table_rows'] += $count;
		$cursor['rows_done']  += $count;
		$cursor['file_offset'] = $writer->bytes();
		$cursor['batch']       = BatchSizer::next( $limit, $bytes, $memory_tight );

		unset( $rows, $tuples );
		$db->flush();

		return $count < $limit;
	}

	/**
	 * The SELECT of one batch.
	 *
	 * @param string              $table  Table.
	 * @param array<string,mixed> $cursor Cursor.
	 * @param array<string,mixed> $schema Schema.
	 * @param ExclusionRules      $rules  Exclusions.
	 * @param int                 $limit  Rows.
	 * @return string
	 * @throws DumpException For an unsafe name or key.
	 */
	private function select_sql( $table, array $cursor, array $schema, ExclusionRules $rules, $limit ) {
		$parts = array();
		foreach ( $schema['names'] as $position => $name ) {
			$quoted = SqlValueFormatter::identifier( $name );
			// FLOAT is printed with only 6 significant digits, which does not read back as the same value; "+ 0e0" widens it to a DOUBLE, printed exactly.
			$base = SqlValueFormatter::base_type( $schema['meta'][ $position ]['type'] );
			if ( 'float' === $base ) {
				$quoted .= ' + 0e0';
			} elseif ( 'bit' === $base ) {
				$quoted .= ' + 0';
				// A number, so the value does not depend on how the driver returns BIT.
			}
			$parts[] = $quoted;
		}
		$columns  = implode( ',', $parts );
		$strategy = is_array( $cursor['strategy'] ) ? $cursor['strategy'] : array(
			'type'  => TableInspector::STRATEGY_OFFSET,
			'order' => array(),
		);

		$where  = array();
		$filter = $rules->row_filter( $table );
		if ( '' !== $filter ) {
			$where[] = $filter;
		}

		$sql = 'SELECT ' . $columns . ' FROM ' . SqlValueFormatter::identifier( $table );

		if ( TableInspector::STRATEGY_KEYSET === $strategy['type'] ) {
			$key = SqlValueFormatter::identifier( $strategy['key'] );
			if ( null !== $cursor['last_key'] ) {
				if ( 1 !== preg_match( '/^-?\d+$/', (string) $cursor['last_key'] ) ) {
					throw new DumpException( 'The saved paging position is not a number.' );
				}
				$where[] = $key . ' > ' . $cursor['last_key'];
			}
			return $sql . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY ' . $key . ' ASC LIMIT ' . (int) $limit;
		}

		$order = '';
		if ( ! empty( $strategy['order'] ) ) {
			$order = ' ORDER BY ' . implode( ',', array_map( array( SqlValueFormatter::class, 'identifier' ), $strategy['order'] ) );
		}
		return $sql . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . $order . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $cursor['offset'];
	}

	/**
	 * Footer, checksum and result for the next step.
	 *
	 * @param JobContext          $context Context.
	 * @param SqlDumpWriter       $writer  Writer.
	 * @param array<string,mixed> $cursor  Final cursor.
	 * @param string              $path    Dump file.
	 * @return StepResult
	 * @throws DumpException On a file error.
	 */
	private function finish( JobContext $context, SqlDumpWriter $writer, array $cursor, $path ) {
		$writer->write_footer();
		$size = $writer->bytes();
		$writer->close();

		$hash = is_file( $path ) ? hash_file( 'sha256', $path ) : false;
		if ( false === $hash ) {
			throw new DumpException( 'The checksum of the database dump could not be calculated.' );
		}

		$meta       = $context->state()->get_meta();
		$meta['db'] = array(
			'file'   => self::FILE_NAME,
			'size'   => $size,
			'sha256' => $hash,
			'tables' => $cursor['tables_done'],
			'rows'   => $cursor['rows_done'],
		);

		$context->log(
			Logger::INFO,
			__( 'Database export finished.', 'openvault-backup' ),
			array(
				'tables' => $cursor['tables_done'],
				'rows'   => $cursor['rows_done'],
				'bytes'  => $size,
			)
		);
		return StepResult::next_step( $context->state()->with_meta( $meta ) );
	}

	/**
	 * Flush the file and return a continue result with the cursor.
	 *
	 * @param JobContext          $context Context.
	 * @param SqlDumpWriter       $writer  Writer.
	 * @param array<string,mixed> $cursor  Cursor.
	 * @return StepResult
	 */
	private function save( JobContext $context, SqlDumpWriter $writer, array $cursor ) {
		$writer->flush();
		$cursor['file_offset'] = $writer->bytes();
		$writer->close();
		return StepResult::continue_step( $context->state()->with_cursor( $cursor ) );
	}

	/**
	 * Cursor after a table is finished (or skipped).
	 *
	 * @param array<string,mixed> $cursor Cursor.
	 * @param string[]            $tables Table list.
	 * @param int                 $index  Index of the table that ended.
	 * @param bool                $dumped Whether it was dumped (counts as done) or skipped.
	 * @return array<string,mixed>
	 */
	private function next_table( array $cursor, array $tables, $index, $dumped ) {
		if ( $dumped ) {
			++$cursor['tables_done'];
		}
		$cursor                = $this->reset_table( $cursor );
		$cursor['table']       = isset( $tables[ $index + 1 ] ) ? $tables[ $index + 1 ] : '';
		$cursor['table_index'] = $index + 1;
		return $cursor;
	}

	/**
	 * Clear the progress inside a table.
	 *
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array<string,mixed>
	 */
	private function reset_table( array $cursor ) {
		$cursor['strategy']   = null;
		$cursor['last_key']   = null;
		$cursor['offset']     = 0;
		$cursor['table_rows'] = 0;
		$cursor['batch']      = 0;
		return $cursor;
	}

	/**
	 * The table list: from the cursor when it was stored there, otherwise recomputed (and sorted again).
	 *
	 * @param array<string,mixed> $cursor Cursor.
	 * @param \wpdb               $db     Database.
	 * @param ExclusionRules      $rules  Exclusions.
	 * @return string[]
	 * @throws DumpException On a database error.
	 */
	private function tables_for( array $cursor, $db, ExclusionRules $rules ) {
		if ( isset( $cursor['tables'] ) ) {
			return array_map( 'strval', $cursor['tables'] );
		}
		return TableList::select( $this->inspector( $db )->list_tables(), $db->base_prefix, $rules );
	}

	/**
	 * Exclusion rules from the settings and the filter.
	 *
	 * @param JobContext $context Context.
	 * @param \wpdb      $db      Database.
	 * @return ExclusionRules
	 */
	private function rules( JobContext $context, $db ) {
		$patterns = ExclusionRules::normalise_patterns( $context->setting( 'excluded_tables', array() ) );

		/**
		 * Filters what a backup leaves out. Return the array with its keys kept.
		 *
		 * @param array<string,mixed> $exclusions Key "tables": list of exact table names or "*" globs.
		 */
		$filtered = apply_filters( 'ovb_backup_exclusions', array( 'tables' => $patterns ) );
		if ( is_array( $filtered ) && isset( $filtered['tables'] ) ) {
			$patterns = ExclusionRules::normalise_patterns( $filtered['tables'] );
		}
		return new ExclusionRules( $db->base_prefix, is_multisite(), $patterns );
	}

	/**
	 * Validate and complete a stored cursor.
	 *
	 * @param array<mixed> $cursor Stored cursor (empty on the first slice).
	 * @return array<string,mixed>|null Null for the first slice.
	 */
	private static function read_cursor( array $cursor ) {
		if ( ! isset( $cursor['file_offset'] ) ) {
			return null;
		}
		$clean = array(
			'tables_total' => isset( $cursor['tables_total'] ) ? (int) $cursor['tables_total'] : 0,
			'tables_done'  => isset( $cursor['tables_done'] ) ? (int) $cursor['tables_done'] : 0,
			'table_index'  => isset( $cursor['table_index'] ) ? (int) $cursor['table_index'] : 0,
			'table'        => isset( $cursor['table'] ) ? (string) $cursor['table'] : '',
			'strategy'     => isset( $cursor['strategy'] ) && is_array( $cursor['strategy'] ) ? $cursor['strategy'] : null,
			'last_key'     => isset( $cursor['last_key'] ) ? (string) $cursor['last_key'] : null,
			'offset'       => isset( $cursor['offset'] ) ? (int) $cursor['offset'] : 0,
			'table_rows'   => isset( $cursor['table_rows'] ) ? (int) $cursor['table_rows'] : 0,
			'rows_done'    => isset( $cursor['rows_done'] ) ? (int) $cursor['rows_done'] : 0,
			'batch'        => isset( $cursor['batch'] ) ? (int) $cursor['batch'] : 0,
			'file_offset'  => (int) $cursor['file_offset'],
			'complete'     => ! empty( $cursor['complete'] ),
		);
		if ( isset( $cursor['tables'] ) && is_array( $cursor['tables'] ) ) {
			$clean['tables'] = $cursor['tables'];
		}
		return $clean;
	}

	/**
	 * Structure reader.
	 *
	 * @param \wpdb|null $db Database for a reader that is created on demand.
	 * @return TableInspector
	 */
	private function inspector( $db = null ) {
		if ( null === $this->inspector ) {
			$this->inspector = new TableInspector( $db );
		}
		return $this->inspector;
	}

	/**
	 * Error for a failed query: table name and the scrubbed database error, never a query or a row.
	 *
	 * @param string $table Table.
	 * @param \wpdb  $db    Database.
	 * @return DumpException
	 */
	private function db_failure( $table, $db ) {
		$error = DestinationsRepository::scrub_error( (string) $db->last_error );
		return $this->error( sprintf( 'Table "%1$s" could not be read: %2$s', $table, substr( $error, 0, 200 ) ) );
	}

	/**
	 * Build an export error (the text is escaped where it is displayed).
	 *
	 * @param string $message Message without secrets.
	 * @return DumpException
	 */
	private function error( $message ) {
		return new DumpException( $message );
	}

	/**
	 * Prepare the connection: utf8mb4 and UTC, so values do not depend on the site's session settings.
	 * TIMESTAMP columns are shown in the session time zone; the dump sets UTC again when it is imported.
	 *
	 * @param \wpdb $db Database.
	 * @return array{charset:bool,time_zone:string|null} What to put back afterwards.
	 */
	private function begin_session( $db ) {
		$saved = array(
			'charset'   => $this->use_utf8mb4( $db ),
			'time_zone' => null,
		);
		$zone  = $db->get_var( 'SELECT @@session.time_zone' );
		if ( is_string( $zone ) && '' !== $zone && false !== $db->query( "SET time_zone = '+00:00'" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Session setting, fixed statement.
			$saved['time_zone'] = $zone;
		}
		return $saved;
	}

	/**
	 * Put the connection settings back.
	 *
	 * @param \wpdb                                     $db    Database.
	 * @param array{charset:bool,time_zone:string|null} $saved Result of begin_session().
	 * @return void
	 */
	private function end_session( $db, array $saved ) {
		if ( $saved['charset'] ) {
			$this->restore_charset( $db );
		}
		if ( null !== $saved['time_zone'] ) {
			$db->query( $db->prepare( 'SET time_zone = %s', $saved['time_zone'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Session setting built with prepare().
		}
	}

	/**
	 * Read over a utf8mb4 connection so characters outside the Basic Multilingual Plane (emoji) survive on
	 * sites whose WordPress connection still uses the 3-byte "utf8".
	 *
	 * @param \wpdb $db Database.
	 * @return bool True when the connection was switched (and must be switched back).
	 */
	private function use_utf8mb4( $db ) {
		if ( 'utf8mb4' === strtolower( (string) $db->charset ) || ! $db->has_cap( 'utf8mb4' ) ) {
			return false;
		}
		return false !== $db->query( "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Session setting, fixed statement.
	}

	/**
	 * Put the connection character set back to what WordPress configured.
	 *
	 * @param \wpdb $db Database.
	 * @return void
	 */
	private function restore_charset( $db ) {
		if ( '' === (string) $db->charset ) {
			return;
		}
		$sql = $db->prepare( 'SET NAMES %s', $db->charset );
		if ( '' !== (string) $db->collate ) {
			$sql .= $db->prepare( ' COLLATE %s', $db->collate );
		}
		$db->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Session setting built with prepare().
	}

	/**
	 * Character set of the connection the rows are read over (written to the dump header).
	 *
	 * @param \wpdb $db Database.
	 * @return string
	 */
	private function connection_charset( $db ) {
		$charset = $db->get_var( 'SELECT @@character_set_connection' );
		return is_string( $charset ) && '' !== $charset ? SqlDumpWriter::charset_name( $charset ) : 'utf8mb4';
	}
}
