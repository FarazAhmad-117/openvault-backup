<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Dump\TableInspector;
use PHPUnit\Framework\TestCase;

/**
 * Strategy selection and metadata parsing: pure, no database.
 *
 * @covers \OpenVault\Backup\Engine\Dump\TableInspector
 */
class TableInspectorTest extends TestCase {

	private function col( $field, $type, $nullable = 'NO', $extra = '' ) {
		return array(
			'Field' => $field,
			'Type'  => $type,
			'Null'  => $nullable,
			'Key'   => '',
			'Extra' => $extra,
		);
	}

	private function idx( $name, $non_unique, $seq, $column ) {
		return array(
			'Key_name'     => $name,
			'Non_unique'   => (string) $non_unique,
			'Seq_in_index' => (string) $seq,
			'Column_name'  => $column,
		);
	}

	private function strategy( array $columns, array $indexes ) {
		return TableInspector::choose_strategy(
			TableInspector::normalise_columns( $columns ),
			TableInspector::normalise_indexes( $indexes )
		);
	}

	public function test_single_integer_primary_key_is_keyset() {
		$s = $this->strategy(
			array( $this->col( 'ID', 'bigint(20) unsigned', 'NO', 'auto_increment' ), $this->col( 'post_title', 'text' ) ),
			array( $this->idx( 'PRIMARY', 0, 1, 'ID' ), $this->idx( 'post_name', 1, 1, 'post_title' ) )
		);
		$this->assertSame(
			array(
				'type' => 'keyset',
				'key'  => 'ID',
			),
			$s
		);
	}

	public function test_every_integer_width_counts() {
		foreach ( array( 'tinyint(4)', 'smallint(6)', 'mediumint(9)', 'int(11)', 'int unsigned', 'integer', 'bigint(20)' ) as $type ) {
			$s = $this->strategy( array( $this->col( 'id', $type ) ), array( $this->idx( 'PRIMARY', 0, 1, 'id' ) ) );
			$this->assertSame( 'keyset', $s['type'], $type );
		}
	}

	public function test_composite_primary_key_is_ordered_offset() {
		$s = $this->strategy(
			array( $this->col( 'object_id', 'bigint(20) unsigned' ), $this->col( 'term_taxonomy_id', 'bigint(20) unsigned' ), $this->col( 'term_order', 'int(11)' ) ),
			array( $this->idx( 'PRIMARY', 0, 2, 'term_taxonomy_id' ), $this->idx( 'PRIMARY', 0, 1, 'object_id' ), $this->idx( 'term_taxonomy_id', 1, 1, 'term_taxonomy_id' ) )
		);
		$this->assertSame(
			array(
				'type'  => 'offset',
				'order' => array( 'object_id', 'term_taxonomy_id' ),
			),
			$s,
			'index columns are ordered by Seq_in_index, not by row order'
		);
	}

	public function test_string_primary_key_is_ordered_offset() {
		$s = $this->strategy( array( $this->col( 'code', 'varchar(20)' ) ), array( $this->idx( 'PRIMARY', 0, 1, 'code' ) ) );
		$this->assertSame( 'offset', $s['type'] );
		$this->assertSame( array( 'code' ), $s['order'] );
	}

	public function test_no_key_at_all_is_unordered_offset() {
		$s = $this->strategy( array( $this->col( 'a', 'int(11)', 'YES' ), $this->col( 'b', 'varchar(20)', 'YES' ) ), array() );
		$this->assertSame(
			array(
				'type'  => 'offset',
				'order' => array(),
			),
			$s
		);
	}

	public function test_non_unique_integer_index_is_not_a_paging_key() {
		$s = $this->strategy( array( $this->col( 'a', 'int(11)' ) ), array( $this->idx( 'a', 1, 1, 'a' ) ) );
		$this->assertSame( 'offset', $s['type'] );
		$this->assertSame( array(), $s['order'] );
	}

	public function test_unique_not_null_integer_key_without_primary_is_keyset() {
		$s = $this->strategy(
			array( $this->col( 'u', 'int(11)', 'NO' ), $this->col( 'w', 'text', 'YES' ) ),
			array( $this->idx( 'u_unique', 0, 1, 'u' ) )
		);
		$this->assertSame(
			array(
				'type' => 'keyset',
				'key'  => 'u',
			),
			$s
		);
	}

	public function test_unique_nullable_integer_key_is_not_used() {
		$s = $this->strategy(
			array( $this->col( 'u', 'int(11)', 'YES' ) ),
			array( $this->idx( 'u_unique', 0, 1, 'u' ) )
		);
		$this->assertSame( 'offset', $s['type'] );
	}

	public function test_composite_unique_key_is_not_used_for_keyset() {
		$s = $this->strategy(
			array( $this->col( 'a', 'int(11)' ), $this->col( 'b', 'int(11)' ) ),
			array( $this->idx( 'ab', 0, 1, 'a' ), $this->idx( 'ab', 0, 2, 'b' ) )
		);
		$this->assertSame( 'offset', $s['type'] );
	}

	public function test_composite_primary_key_with_a_unique_integer_key_prefers_the_unique_key() {
		$s = $this->strategy(
			array( $this->col( 'a', 'int(11)' ), $this->col( 'b', 'int(11)' ), $this->col( 'u', 'bigint(20)' ) ),
			array( $this->idx( 'PRIMARY', 0, 1, 'a' ), $this->idx( 'PRIMARY', 0, 2, 'b' ), $this->idx( 'u', 0, 1, 'u' ) )
		);
		$this->assertSame(
			array(
				'type' => 'keyset',
				'key'  => 'u',
			),
			$s
		);
	}

	public function test_string_primary_key_with_a_unique_integer_key_prefers_the_integer() {
		$s = $this->strategy(
			array( $this->col( 'code', 'varchar(20)' ), $this->col( 'n', 'int(11)' ) ),
			array( $this->idx( 'PRIMARY', 0, 1, 'code' ), $this->idx( 'n', 0, 1, 'n' ) )
		);
		$this->assertSame( 'n', $s['key'] );
	}

	public function test_generated_integer_primary_key_is_not_used() {
		$s = $this->strategy(
			array( $this->col( 'id', 'int(11)', 'NO', 'STORED GENERATED' ) ),
			array( $this->idx( 'PRIMARY', 0, 1, 'id' ) )
		);
		$this->assertSame( 'offset', $s['type'] );
	}

	public function test_decimal_and_float_keys_are_not_keyset() {
		foreach ( array( 'decimal(10,2)', 'double', 'float', 'varchar(10)', 'datetime', 'bit(8)' ) as $type ) {
			$s = $this->strategy( array( $this->col( 'k', $type ) ), array( $this->idx( 'PRIMARY', 0, 1, 'k' ) ) );
			$this->assertSame( 'offset', $s['type'], $type );
		}
	}

	public function test_index_on_a_missing_column_is_ignored() {
		$s = $this->strategy( array( $this->col( 'a', 'int(11)' ) ), array( $this->idx( 'PRIMARY', 0, 1, 'ghost' ) ) );
		$this->assertSame( 'offset', $s['type'] );
	}

	public function test_normalise_columns() {
		$cols = TableInspector::normalise_columns(
			array(
				$this->col( 'id', 'BIGINT(20) UNSIGNED', 'NO', 'auto_increment' ),
				$this->col( 'data', 'LONGBLOB', 'YES' ),
				$this->col( 'g1', 'int(11)', 'YES', 'VIRTUAL GENERATED' ),
				$this->col( 'g2', 'int(11)', 'YES', 'STORED GENERATED' ),
				$this->col( 'g3', 'int(11)', 'YES', 'PERSISTENT' ),
				$this->col( 'd', 'datetime', 'NO', 'DEFAULT_GENERATED' ),
				$this->col( 'ts', 'timestamp', 'NO', 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP' ),
				$this->col( 'hidden', 'int(11)', 'YES', 'INVISIBLE' ),
				array( 'nonsense' => 1 ),
			)
		);
		$this->assertCount( 8, $cols );
		$this->assertSame( 'bigint(20) unsigned', $cols[0]['type'] );
		$this->assertFalse( $cols[0]['null'] );
		$this->assertTrue( $cols[1]['null'] );
		$this->assertTrue( $cols[1]['binary'] );
		$this->assertFalse( $cols[0]['binary'] );
		$this->assertTrue( $cols[2]['generated'] );
		$this->assertTrue( $cols[3]['generated'] );
		$this->assertTrue( $cols[4]['generated'] );
		$this->assertFalse( $cols[5]['generated'], 'a default expression is insertable' );
		$this->assertFalse( $cols[6]['generated'] );
		$this->assertTrue( $cols[7]['invisible'] );
		$this->assertFalse( $cols[7]['generated'] );
	}

	public function test_selectable_columns_and_column_list() {
		$cols = TableInspector::normalise_columns(
			array(
				$this->col( 'a', 'int(11)' ),
				$this->col( 'g', 'int(11)', 'YES', 'VIRTUAL GENERATED' ),
				$this->col( 'b', 'text' ),
			)
		);
		$this->assertSame( array( 'a', 'b' ), array_column( TableInspector::selectable_columns( $cols ), 'name' ) );
		$this->assertTrue( TableInspector::needs_column_list( $cols ) );

		$plain = TableInspector::normalise_columns( array( $this->col( 'a', 'int(11)' ), $this->col( 'b', 'text' ) ) );
		$this->assertFalse( TableInspector::needs_column_list( $plain ) );
		$this->assertSame( array( 'a', 'b' ), array_column( TableInspector::selectable_columns( $plain ), 'name' ) );

		$invisible = TableInspector::normalise_columns( array( $this->col( 'a', 'int(11)' ), $this->col( 'h', 'int(11)', 'YES', 'INVISIBLE' ) ) );
		$this->assertTrue( TableInspector::needs_column_list( $invisible ) );
		$this->assertSame( array( 'a', 'h' ), array_column( TableInspector::selectable_columns( $invisible ), 'name' ), 'invisible columns are still exported' );
	}

	public function test_normalise_indexes_groups_and_orders_columns() {
		$idx = TableInspector::normalise_indexes(
			array(
				$this->idx( 'PRIMARY', 0, 2, 'b' ),
				$this->idx( 'PRIMARY', 0, 1, 'a' ),
				$this->idx( 'k', 1, 1, 'c' ),
				array(
					'Key_name'     => 'functional',
					'Non_unique'   => '1',
					'Seq_in_index' => '1',
					'Column_name'  => null,
				),
				array( 'Key_name' => 'broken' ),
			)
		);
		$this->assertCount( 2, $idx );
		$this->assertSame(
			array(
				'name'    => 'PRIMARY',
				'unique'  => true,
				'columns' => array( 'a', 'b' ),
			),
			$idx[0]
		);
		$this->assertFalse( $idx[1]['unique'] );
	}

	public function test_heavy_column_detection() {
		$heavy = array( 'text', 'longtext', 'mediumtext', 'tinytext', 'blob', 'longblob', 'json', 'varchar(4096)', 'varchar(65535)', 'varbinary(5000)' );
		foreach ( $heavy as $type ) {
			$cols = TableInspector::normalise_columns( array( $this->col( 'id', 'int(11)' ), $this->col( 'x', $type ) ) );
			$this->assertTrue( TableInspector::has_heavy_columns( $cols ), $type );
		}
		foreach ( array( 'int(11)', 'varchar(255)', 'varchar(4095)', 'datetime', 'decimal(10,2)', 'enum(\'a\')', 'bit(8)', 'char(20)', 'binary(16)' ) as $type ) {
			$cols = TableInspector::normalise_columns( array( $this->col( 'x', $type ) ) );
			$this->assertFalse( TableInspector::has_heavy_columns( $cols ), $type );
		}
	}
}
