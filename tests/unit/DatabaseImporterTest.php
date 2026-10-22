<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The doubles belong to this test only.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Real files in the system temp directory, WordPress is not loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Restore\DatabaseImporter;
use OpenVault\Backup\Restore\DumpValidator;
use OpenVault\Backup\Restore\ImportException;
use OpenVault\Backup\Restore\SqlExecutor;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RestoreDoubles.php';

/**
 * A kill that happens right after a statement ran (before the importer could store its position).
 */
class SimulatedKill extends \RuntimeException {
}

/**
 * An in-memory "database": it understands just enough of the dump's statements to keep per-table row lists.
 */
class FakeDatabase implements SqlExecutor {

	/** @var array<string,array<int,string>> */
	public $tables = array();
	/** @var string[] */
	public $log = array();
	/** @var int|null Kill after this many executed statements (counting from now). */
	public $kill_after = null;
	/** @var int|null Fail the statement with this number (1-based) and this errno. */
	public $fail_at    = null;
	public $fail_errno = 1062;
	public $errno      = 0;
	public $count      = 0;

	public function execute( $sql ) {
		++$this->count;
		if ( null !== $this->fail_at && $this->count === $this->fail_at ) {
			$this->errno = $this->fail_errno;
			return false;
		}
		$this->log[] = $sql;
		if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m ) ) {
			unset( $this->tables[ $m[1] ] );
		} elseif ( 1 === preg_match( '/^CREATE TABLE `([^`]+)`/', $sql, $m ) ) {
			$this->tables[ $m[1] ] = array();
		} elseif ( 1 === preg_match( '/^INSERT INTO `([^`]+)`/', $sql, $m ) ) {
			if ( ! isset( $this->tables[ $m[1] ] ) ) {
				$this->errno = 1146;
				return false;
			}
			foreach ( explode( '),(', trim( substr( $sql, strpos( $sql, 'VALUES ' ) + 7 ), '();' ) ) as $tuple ) {
				$this->tables[ $m[1] ][] = $tuple;
			}
		} elseif ( 1 === preg_match( '/^RENAME TABLE `([^`]+)` TO `([^`]+)`, `([^`]+)` TO `([^`]+)`/', $sql, $m ) ) {
			if ( ! isset( $this->tables[ $m[1] ], $this->tables[ $m[3] ] ) ) {
				$this->errno = 1146;
				return false;
			}
			$old = $this->tables[ $m[1] ];
			$new = $this->tables[ $m[3] ];
			unset( $this->tables[ $m[1] ], $this->tables[ $m[3] ] );
			$this->tables[ $m[2] ] = $old;
			$this->tables[ $m[4] ] = $new;
		} elseif ( 1 === preg_match( '/^RENAME TABLE `([^`]+)` TO `([^`]+)`$/', $sql, $m ) ) {
			$this->tables[ $m[2] ] = $this->tables[ $m[1] ];
			unset( $this->tables[ $m[1] ] );
		}
		if ( null !== $this->kill_after && --$this->kill_after <= 0 ) {
			$this->kill_after = null;
			throw new SimulatedKill( 'killed' );
		}
		return true;
	}

	public function errno() {
		return $this->errno;
	}
}

/**
 * @covers \OpenVault\Backup\Restore\DatabaseImporter
 * @covers \OpenVault\Backup\Restore\ImportException
 */
class DatabaseImporterTest extends TestCase {

	/** @var string[] */
	private $dirs = array();

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			CleanupStep::remove_tree( $dir );
		}
		parent::tearDown();
	}

	private function file( $content ) {
		$dir = sys_get_temp_dir() . '/ovb-imp-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		$this->dirs[] = $dir;
		file_put_contents( $dir . '/db.sql', $content );
		return $dir . '/db.sql';
	}

	/**
	 * A dump like the writer makes it: wp_a (3 INSERTs), wp_actionscheduler_actions (skipped), wp_b, wp_options (2 INSERTs), wp_z.
	 *
	 * @return string
	 */
	private function dump( array $overrides = array() ) {
		$lines = array(
			'-- ovb-dump: 1',
			'-- table-prefix: wp_',
			'-- charset: utf8mb4',
			'SET NAMES utf8mb4;',
			'SET FOREIGN_KEY_CHECKS=0;',
			'SET UNIQUE_CHECKS=0;',
			"SET sql_mode='NO_AUTO_VALUE_ON_ZERO';",
			"SET time_zone='+00:00';",
		);
		foreach ( array(
			'wp_a'                       => 3,
			'wp_actionscheduler_actions' => 2,
			'wp_b'                       => 4,
			'wp_options'                 => 2,
			'wp_z'                       => 1,
		) as $table => $inserts ) {
			$lines[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
			$lines[] = 'CREATE TABLE `' . $table . '` ( `id` int NOT NULL ) ENGINE=InnoDB;';
			for ( $i = 1; $i <= $inserts; $i++ ) {
				$lines[] = 'INSERT INTO `' . $table . '` VALUES (' . $i . '),(' . ( $i + 100 ) . ');';
			}
		}
		$lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
		$lines[] = 'SET UNIQUE_CHECKS=1;';
		$lines[] = '-- ovb-dump-end';
		foreach ( $overrides as $index => $line ) {
			if ( null === $line ) {
				unset( $lines[ $index ] );
			} else {
				$lines[ $index ] = $line;
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	private function importer( FakeDatabase $db, &$events = null ) {
		$events = array();
		return new DatabaseImporter(
			new DumpValidator( 'wp_' ),
			$db,
			'wp_',
			static function () use ( &$events, $db ) {
				$events[] = 'before:' . implode( ',', array_keys( $db->tables ) );
			},
			static function () use ( &$events, $db ) {
				$events[] = 'after:' . implode( ',', array_keys( $db->tables ) );
			}
		);
	}

	private function budget( $reads ) {
		return StepDriver::budget( $reads );
	}

	/**
	 * Run the importer to the end (slice after slice), the way the step does, without any failure.
	 *
	 * @return array{cursor:array<string,mixed>,slices:int}
	 */
	private function run_all( DatabaseImporter $importer, $path, $reads, array &$saved ) {
		$cursor = DatabaseImporter::new_import_cursor();
		for ( $slice = 1; $slice <= 500; $slice++ ) {
			$result = $importer->import_slice(
				$path,
				$cursor,
				$this->budget( $reads ),
				static function ( array $c ) use ( &$saved ) {
					$saved = $c;
				}
			);
			$cursor = $result['cursor'];
			if ( $result['done'] ) {
				return array(
					'cursor' => $cursor,
					'slices' => $slice,
				);
			}
		}
		$this->fail( 'import did not finish' );
	}

	private static function expected_rows() {
		return array(
			'wp_a'       => 6,
			'wp_b'       => 8,
			'wp_options' => 4,
			'wp_z'       => 2,
		);
	}

	public function test_a_whole_dump_in_one_slice() {
		$db    = new FakeDatabase();
		$saved = array();
		$run   = $this->run_all( $this->importer( $db, $events ), $this->file( $this->dump() ), 100000, $saved );

		$this->assertSame( 1, $run['slices'] );
		$counts = array_map( 'count', $db->tables );
		ksort( $counts );
		$this->assertSame( self::expected_rows(), $counts, 'every table has exactly its rows; the Action Scheduler table was left alone' );
		$this->assertArrayNotHasKey( 'wp_actionscheduler_actions', $db->tables );
		$this->assertArrayNotHasKey( 'wp_options_ovbnew', $db->tables, 'the shadow table was swapped in' );
		$this->assertArrayNotHasKey( 'wp_options_ovbold', $db->tables );
		$this->assertSame( 5, $run['cursor']['td'] );
		$this->assertSame( 1, $run['cursor']['sk'] );
		$this->assertSame( 20, $run['cursor']['rw'], 'rows counted (the skipped table is not counted)' );
		$this->assertTrue( $run['cursor']['cl'] );
		$this->assertSame( '', $run['cursor']['tb'] );
		$this->assertTrue( $run['cursor']['en'] );
	}

	public function test_the_prologue_prepares_the_session_before_any_statement() {
		$db    = new FakeDatabase();
		$saved = array();
		$this->run_all( $this->importer( $db ), $this->file( $this->dump() ), 100000, $saved );
		$this->assertSame( "SET sql_mode='NO_AUTO_VALUE_ON_ZERO'", $db->log[0] );
		$this->assertSame( 'SET FOREIGN_KEY_CHECKS=0', $db->log[1] );
		$this->assertSame( 'SET UNIQUE_CHECKS=0', $db->log[2] );
		$sets = array_slice( $db->log, 3, 5 );
		$this->assertSame( array( 'SET NAMES utf8mb4;', 'SET FOREIGN_KEY_CHECKS=0;', 'SET UNIQUE_CHECKS=0;', "SET sql_mode='NO_AUTO_VALUE_ON_ZERO';", "SET time_zone='+00:00';" ), $sets );
	}

	public function test_the_options_table_goes_through_a_shadow_table_and_one_atomic_rename() {
		$db                       = new FakeDatabase();
		$saved                    = array();
		$db->tables['wp_options'] = array( 'OLD1', 'OLD2' );
		$this->run_all( $this->importer( $db, $events ), $this->file( $this->dump() ), 100000, $saved );

		$sql = implode( "\n", $db->log );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_options_ovbnew`;', $sql );
		$this->assertStringContainsString( 'CREATE TABLE `wp_options_ovbnew` (', $sql );
		$this->assertStringContainsString( 'INSERT INTO `wp_options_ovbnew` VALUES (1),(101);', $sql );
		$this->assertStringNotContainsString( 'DROP TABLE IF EXISTS `wp_options`;', $sql, 'the live options table is never dropped' );
		$this->assertStringContainsString( 'RENAME TABLE `wp_options` TO `wp_options_ovbold`, `wp_options_ovbnew` TO `wp_options`', $sql );
		$this->assertSame( 4, count( $db->tables['wp_options'] ), 'the new rows, not OLD1/OLD2' );

		$this->assertCount( 2, $events );
		$this->assertStringStartsWith( 'before:', $events[0] );
		$this->assertStringContainsString( 'wp_options_ovbnew', $events[0], 'before the swap the shadow table is complete' );
		$this->assertStringStartsWith( 'after:', $events[1] );
		$this->assertStringNotContainsString( 'wp_options_ovbnew', $events[1] );
		$this->assertStringNotContainsString( 'wp_options_ovbold', $events[1], 'the old table is gone before the settings are written back' );

		$swap_at = array_search( 'RENAME TABLE `wp_options` TO `wp_options_ovbold`, `wp_options_ovbnew` TO `wp_options`', $db->log, true );
		$next    = array_search( 'DROP TABLE IF EXISTS `wp_z`;', $db->log, true );
		$this->assertLessThan( $next, $swap_at, 'the swap happens before the next table starts' );
	}

	public function test_shadow_statements_are_built_from_the_validated_line() {
		$this->assertSame( 'DROP TABLE IF EXISTS `wp_options_ovbnew`;', DatabaseImporter::shadow_statement( 'DROP TABLE IF EXISTS `wp_options`;', 'drop', 'wp_options', 'wp_options_ovbnew' ) );
		$this->assertSame( 'CREATE TABLE `wp_options_ovbnew` ( `a` int ) ENGINE=InnoDB;', DatabaseImporter::shadow_statement( 'CREATE TABLE `wp_options` ( `a` int ) ENGINE=InnoDB;', 'create', 'wp_options', 'wp_options_ovbnew' ) );
		$this->assertSame( "INSERT INTO `wp_options_ovbnew` (`a`) VALUES ('wp_options');", DatabaseImporter::shadow_statement( "INSERT INTO `wp_options` (`a`) VALUES ('wp_options');", 'insert', 'wp_options', 'wp_options_ovbnew' ) );
		$this->assertSame( 'wp_options_ovbnew', DatabaseImporter::shadow_name( 'wp_options' ) );
		$this->assertNull( DatabaseImporter::shadow_name( 'wp_' . str_repeat( 'a', 55 ) ) );
	}

	public function test_skipped_tables() {
		$this->assertTrue( DatabaseImporter::is_skipped_table( 'wp_actionscheduler_actions', 'wp_' ) );
		$this->assertTrue( DatabaseImporter::is_skipped_table( 'WP_ActionScheduler_logs', 'wp_' ) );
		$this->assertFalse( DatabaseImporter::is_skipped_table( 'wp_posts', 'wp_' ) );
		$this->assertFalse( DatabaseImporter::is_skipped_table( 'wp_actionschedule', 'wp_' ) );
	}

	public function test_many_small_slices_do_the_same_work_as_one_big_slice() {
		$whole = new FakeDatabase();
		$saved = array();
		$this->run_all( $this->importer( $whole ), $this->file( $this->dump() ), 100000, $saved );

		$sliced = new FakeDatabase();
		$run    = $this->run_all( $this->importer( $sliced ), $this->file( $this->dump() ), 3, $saved );
		$this->assertGreaterThan( 5, $run['slices'] );
		$this->assertSame( $whole->tables, $sliced->tables );

		$executed = array_values(
			array_filter(
				$sliced->log,
				static function ( $sql ) {
					return 0 !== strpos( $sql, 'SET ' );
				}
			)
		);
		$expected = array_values(
			array_filter(
				$whole->log,
				static function ( $sql ) {
					return 0 !== strpos( $sql, 'SET ' );
				}
			)
		);
		$this->assertSame( $expected, $executed, 'a clean stop never repeats or skips a statement' );
	}

	public function test_a_slice_marks_itself_unclean_before_it_runs_anything_and_ends_clean() {
		$db     = new FakeDatabase();
		$stored = array();
		$first  = null;
		$result = $this->importer( $db )->import_slice(
			$this->file( $this->dump() ),
			DatabaseImporter::new_import_cursor(),
			$this->budget( 4 ),
			static function ( array $c ) use ( &$stored, &$first, $db ) {
				if ( null === $first ) {
					$first = array( $c, count( $db->log ) );
				}
				$stored = $c;
			}
		);
		$this->assertFalse( $first[0]['cl'] );
		$this->assertSame( 8, $first[1], 'only the session statements (3 of the importer, 5 of the dump header) had run when the first position was stored' );
		$this->assertFalse( $stored['cl'], 'every position stored during the slice says "unclean"' );
		$this->assertFalse( $result['done'] );
		$this->assertTrue( $result['cursor']['cl'], 'the slice that stops on its budget returns a clean cursor' );
		$this->assertGreaterThan( $stored['off'] - 1, $result['cursor']['off'] );
	}

	/**
	 * The proof of the resume rule: kill the process after statement N (before its position could be stored, the stored one is the
	 * one of statement N-1) for every N, resume, and compare with a run that was never killed.
	 */
	public function test_a_kill_after_any_statement_never_duplicates_a_row() {
		$clean = new FakeDatabase();
		$saved = array();
		$this->run_all( $this->importer( $clean ), $this->file( $this->dump() ), 100000, $saved );
		$total = count( $clean->log );
		$path  = $this->file( $this->dump() );

		for ( $kill = 1; $kill <= $total + 2; $kill++ ) {
			foreach ( array( 100000, 4 ) as $reads ) {
				$db             = new FakeDatabase();
				$stored         = DatabaseImporter::new_import_cursor();
				$db->kill_after = $kill;
				$importer       = $this->importer( $db );
				$persist        = static function ( array $c ) use ( &$stored ) {
					$stored = $c;
				};
				$cursor         = $stored;
				$finished       = false;
				for ( $round = 0; $round < 200 && ! $finished; $round++ ) {
					try {
						$result = $importer->import_slice( $path, $stored, $this->budget( $reads ), $persist );
						$stored = $result['cursor'];
						if ( $result['done'] ) {
							$finished = true;
						}
					} catch ( SimulatedKill $e ) {
						// The process died: only what $persist stored survives.
						continue;
					}
				}
				$this->assertTrue( $finished, "kill after statement $kill did not finish" );
				$counts = array_map( 'count', $db->tables );
				ksort( $counts );
				$this->assertSame( self::expected_rows(), $counts, "kill after statement $kill (slice size $reads): a table has duplicate or missing rows" );
				$this->assertSame( $clean->tables, $db->tables, "kill after statement $kill: the content differs from a clean run" );
				unset( $cursor );
			}
		}
	}

	public function test_resuming_an_unclean_cursor_restarts_the_table_in_progress() {
		$cursor = array_merge(
			DatabaseImporter::new_import_cursor(),
			array(
				'off' => 5000,
				'ln'  => 40,
				'tb'  => 'wp_b',
				'to'  => 3000,
				'tl'  => 25,
				'cr'  => true,
				'cl'  => false,
				'rt'  => 7,
				'sc'  => 4,
				'td'  => 2,
			)
		);
		$point  = DatabaseImporter::resume_point( $cursor );
		$this->assertSame( 3000, $point['off'] );
		$this->assertSame( 25, $point['ln'] );
		$this->assertSame( '', $point['tb'] );
		$this->assertFalse( $point['cr'] );
		$this->assertSame( 0, $point['rt'] );
		$this->assertSame( 0, $point['sc'] );
		$this->assertSame( 2, $point['td'], 'finished tables stay counted' );
		$this->assertFalse( $point['cl'] );
	}

	public function test_resuming_a_clean_cursor_continues_where_it_stopped() {
		$cursor = array_merge(
			DatabaseImporter::new_import_cursor(),
			array(
				'off' => 5000,
				'ln'  => 40,
				'tb'  => 'wp_b',
				'to'  => 3000,
				'tl'  => 25,
				'cr'  => true,
				'cl'  => true,
				'rt'  => 7,
				'sc'  => 4,
			)
		);
		$point  = DatabaseImporter::resume_point( $cursor );
		$this->assertSame( 5000, $point['off'] );
		$this->assertSame( 40, $point['ln'] );
		$this->assertSame( 'wp_b', $point['tb'] );
		$this->assertTrue( $point['cr'] );
		$this->assertSame( 7, $point['rt'] );
	}

	public function test_an_unclean_cursor_between_tables_is_kept_and_a_broken_cursor_is_normalised() {
		$point = DatabaseImporter::resume_point(
			array(
				'off' => 100,
				'ln'  => 3,
				'tb'  => '',
				'cl'  => false,
			)
		);
		$this->assertSame( 100, $point['off'] );
		$this->assertSame( 3, $point['ln'] );

		$point = DatabaseImporter::resume_point(
			array(
				'off' => -5,
				'ln'  => 'x',
				'tb'  => array(),
				'cl'  => 'yes',
				'to'  => -1,
			)
		);
		$this->assertSame( 0, $point['off'] );
		$this->assertSame( 0, $point['ln'] );
		$this->assertSame( '', $point['tb'] );
		$this->assertFalse( $point['cl'] );
		$this->assertSame( DatabaseImporter::resume_point( array() )['off'], 0 );
	}

	public function test_the_stored_cursor_stays_far_below_the_limit() {
		$db     = new FakeDatabase();
		$saved  = array();
		$max    = 0;
		$cursor = DatabaseImporter::new_import_cursor();
		$result = $this->importer( $db )->import_slice(
			$this->file( $this->dump() ),
			$cursor,
			$this->budget( 100000 ),
			static function ( array $c ) use ( &$max ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
				$max = max( $max, strlen( json_encode( $c ) ) );
			}
		);
		$this->assertTrue( $result['done'] );
		$this->assertLessThan( 400, $max );
		$this->assertLessThan( 65536, $max );
	}

	public function test_a_database_error_names_the_line_the_table_and_the_error_number_only() {
		$db             = new FakeDatabase();
		$db->fail_at    = 22;
		$db->fail_errno = 1062;
		$secret         = 'SECRET-ROW-DATA';
		$path           = $this->file( str_replace( 'VALUES (1),(101)', "VALUES ('{$secret}'),(101)", $this->dump() ) );
		try {
			$this->importer( $db )->import_slice(
				$path,
				DatabaseImporter::new_import_cursor(),
				$this->budget( 100000 ),
				static function () {
				}
			);
			$this->fail( 'no exception' );
		} catch ( ImportException $e ) {
			$this->assertSame( 1062, $e->errno() );
			$this->assertGreaterThan( 8, $e->line_number() );
			$this->assertStringStartsWith( 'wp_', $e->table() );
			$this->assertStringNotContainsString( $secret, $e->getMessage() );
			$this->assertStringContainsString( '1062', $e->getMessage() );
			$this->assertFalse( $e->is_transient() );
		}
	}

	public function test_a_lost_connection_is_a_transient_error() {
		$db             = new FakeDatabase();
		$db->fail_at    = 14;
		$db->fail_errno = 2006;
		try {
			$this->importer( $db )->import_slice(
				$this->file( $this->dump() ),
				DatabaseImporter::new_import_cursor(),
				$this->budget( 100000 ),
				static function () {
				}
			);
			$this->fail( 'no exception' );
		} catch ( ImportException $e ) {
			$this->assertTrue( $e->is_transient() );
		}
	}

	public function test_validate_counts_and_accepts_a_good_dump() {
		$importer = $this->importer( new FakeDatabase() );
		$result   = $importer->validate_slice( $this->file( $this->dump() ), DatabaseImporter::new_validate_cursor(), $this->budget( 100000 ) );
		$this->assertTrue( $result['done'] );
		$c = $result['cursor'];
		$this->assertSame( 5, $c['tables'] );
		$this->assertSame( 24, $c['rows'] );
		$this->assertTrue( $c['prefix_ok'] );
		$this->assertTrue( $c['en'] );
		$this->assertGreaterThan( 20, $c['maxlen'] );
	}

	public function test_validate_in_many_slices_gives_the_same_result() {
		$importer = $this->importer( new FakeDatabase() );
		$path     = $this->file( $this->dump() );
		$whole    = $importer->validate_slice( $path, DatabaseImporter::new_validate_cursor(), $this->budget( 100000 ) )['cursor'];
		$cursor   = DatabaseImporter::new_validate_cursor();
		$slices   = 0;
		do {
			$result = $importer->validate_slice( $path, $cursor, $this->budget( 5 ) );
			$cursor = $result['cursor'];
			++$slices;
		} while ( ! $result['done'] && $slices < 200 );
		$this->assertGreaterThan( 3, $slices );
		$this->assertSame( $whole, $cursor );
	}

	public function test_validate_executes_nothing_and_refuses_a_hostile_line_at_its_line_number() {
		$db       = new FakeDatabase();
		$importer = $this->importer( $db );
		$cases    = array(
			'a stacked statement' => 'INSERT INTO `wp_b` VALUES (1); DROP TABLE `wp_users`;',
			'CREATE USER'         => "CREATE USER 'evil'@'%' IDENTIFIED BY 'x';",
			'a table outside'     => 'DROP TABLE IF EXISTS `mysql_user`;',
			'a plugin table'      => 'DROP TABLE IF EXISTS `wp_ovb_backups`;',
			'INTO OUTFILE'        => 'CREATE TABLE `wp_b` ( `a` int ) ENGINE=InnoDB INTO OUTFILE \'/tmp/x\';',
			'a comment'           => 'INSERT INTO `wp_b` VALUES (1) -- x;',
			'GRANT'               => "GRANT ALL ON *.* TO 'x'@'%';",
		);
		foreach ( $cases as $label => $bad ) {
			// Replace the INSERT on line 20 (the third statement of wp_b) with the hostile line.
			$lines           = explode( "\n", $this->dump() );
			$index           = array_search( 'DROP TABLE IF EXISTS `wp_b`;', $lines, true ) + 3;
			$lines[ $index ] = $bad;
			$path            = $this->file( implode( "\n", $lines ) );
			try {
				$importer->validate_slice( $path, DatabaseImporter::new_validate_cursor(), $this->budget( 100000 ) );
				$this->fail( "accepted: $label" );
			} catch ( ImportException $e ) {
				$this->assertSame( $index + 1, $e->line_number(), $label );
				$this->assertStringContainsString( 'line ' . ( $index + 1 ), $e->getMessage(), $label );
				$this->assertStringNotContainsString( 'evil', $e->getMessage() );
				$this->assertStringNotContainsString( 'DROP TABLE `wp_users`', $e->getMessage() );
			}
		}
		$this->assertSame( array(), $db->log, 'validation never executes a statement' );
	}

	public function test_validate_refuses_an_incomplete_or_foreign_dump() {
		$importer = $this->importer( new FakeDatabase() );
		$cases    = array(
			'no end marker'      => str_replace( "-- ovb-dump-end\n", '', $this->dump() ),
			'cut in a statement' => substr( $this->dump(), 0, 700 ),
			'another prefix'     => str_replace( '-- table-prefix: wp_', '-- table-prefix: other_', $this->dump() ),
			'no prefix header'   => str_replace( "-- table-prefix: wp_\n", '', $this->dump() ),
			'data after the end' => $this->dump() . "DROP TABLE IF EXISTS `wp_users`;\n",
			'empty'              => '',
			'no tables'          => "-- ovb-dump: 1\n-- table-prefix: wp_\n-- ovb-dump-end\n",
		);
		foreach ( $cases as $label => $content ) {
			try {
				$importer->validate_slice( $this->file( $content ), DatabaseImporter::new_validate_cursor(), $this->budget( 100000 ) );
				$this->fail( "accepted: $label" );
			} catch ( ImportException $e ) {
				$this->assertNotSame( '', $e->getMessage(), $label );
			}
		}
	}

	public function test_validate_and_import_refuse_statements_in_the_wrong_order() {
		$importer = $this->importer( new FakeDatabase() );
		$lines    = explode( "\n", $this->dump() );
		$drop     = array_search( 'DROP TABLE IF EXISTS `wp_b`;', $lines, true );

		$cases = array(
			'INSERT before CREATE'      => array( $drop + 1, 'INSERT INTO `wp_b` VALUES (1);' ),
			'INSERT into another table' => array( $drop + 2, 'INSERT INTO `wp_z` VALUES (1);' ),
			'CREATE of another table'   => array( $drop + 1, 'CREATE TABLE `wp_z` ( `id` int ) ENGINE=InnoDB;' ),
			'CREATE twice'              => array( $drop + 2, 'CREATE TABLE `wp_b` ( `id` int ) ENGINE=InnoDB;' ),
		);
		foreach ( $cases as $label => $case ) {
			$copy             = $lines;
			$copy[ $case[0] ] = $case[1];
			$path             = $this->file( implode( "\n", $copy ) );
			foreach ( array( 'validate', 'import' ) as $pass ) {
				try {
					if ( 'validate' === $pass ) {
						$importer->validate_slice( $path, DatabaseImporter::new_validate_cursor(), $this->budget( 100000 ) );
					} else {
						$db = new FakeDatabase();
						$this->importer( $db )->import_slice(
							$path,
							DatabaseImporter::new_import_cursor(),
							$this->budget( 100000 ),
							static function () {
							}
						);
					}
					$this->fail( "$pass accepted: $label" );
				} catch ( ImportException $e ) {
					$this->assertStringContainsString( 'order', $e->getMessage(), "$pass $label" );
				}
			}
		}
	}

	public function test_import_refuses_a_hostile_line_without_running_it() {
		$db              = new FakeDatabase();
		$lines           = explode( "\n", $this->dump() );
		$index           = array_search( 'DROP TABLE IF EXISTS `wp_z`;', $lines, true ) + 2;
		$lines[ $index ] = 'INSERT INTO `wp_z` VALUES (1); DROP TABLE `wp_users`;';
		try {
			$this->importer( $db )->import_slice(
				$this->file( implode( "\n", $lines ) ),
				DatabaseImporter::new_import_cursor(),
				$this->budget( 100000 ),
				static function () {
				}
			);
			$this->fail( 'accepted' );
		} catch ( ImportException $e ) {
			$this->assertSame( $index + 1, $e->line_number() );
		}
		foreach ( $db->log as $sql ) {
			$this->assertStringNotContainsString( 'wp_users', $sql );
		}
	}

	public function test_a_line_without_a_line_break_at_the_end_is_refused_unless_it_is_a_comment() {
		$importer = $this->importer( new FakeDatabase() );
		try {
			$importer->validate_slice( $this->file( rtrim( $this->dump(), "\n" ) . "\nSET UNIQUE_CHECKS=1;" ), DatabaseImporter::new_validate_cursor(), $this->budget( 100000 ) );
			$this->fail( 'accepted' );
		} catch ( ImportException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		$ok = $importer->validate_slice( $this->file( rtrim( $this->dump(), "\n" ) ), DatabaseImporter::new_validate_cursor(), $this->budget( 100000 ) );
		$this->assertTrue( $ok['done'], 'the end marker without a final line break is fine' );
	}

	public function test_a_line_longer_than_the_cap_is_refused_without_reading_it_whole() {
		$importer = $this->importer( new FakeDatabase() );
		$lines    = explode( "\n", $this->dump() );
		$lines[9] = "INSERT INTO `wp_a` VALUES ('" . str_repeat( 'x', DumpValidator::MAX_LINE_BYTES + 10 ) . "');";
		try {
			$importer->validate_slice( $this->file( implode( "\n", $lines ) ), DatabaseImporter::new_validate_cursor(), $this->budget( 100000 ) );
			$this->fail( 'accepted' );
		} catch ( ImportException $e ) {
			$this->assertSame( 10, $e->line_number() );
			$this->assertStringContainsString( 'too long', $e->getMessage() );
		}
	}

	public function test_a_missing_file_is_an_import_error() {
		$this->expectException( ImportException::class );
		$this->importer( new FakeDatabase() )->validate_slice( sys_get_temp_dir() . '/ovb-nope-' . bin2hex( random_bytes( 4 ) ), DatabaseImporter::new_validate_cursor(), $this->budget( 10 ) );
	}
}
