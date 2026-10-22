<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\EntryWalker;
use OpenVault\Backup\Engine\Scan\ContentSelector;
use OpenVault\Backup\Restore\ExtractionPolicy;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FileFixtures.php';

/**
 * The decision for every archive entry: where it goes, and why it is skipped or rejected (hostile corpus included).
 *
 * @covers \OpenVault\Backup\Restore\ExtractionPolicy
 */
class ExtractionPolicyTest extends TestCase {

	const SITE = '/var/www/site';
	const WP   = '/var/www/site/wp';
	const WC   = '/var/www/site/wp/wp-content';

	/**
	 * Policy for the fake site (nothing touches the disk).
	 *
	 * @param array<string,bool> $contents Contents flags.
	 * @param bool               $server   Server configuration opt-in.
	 * @param array<string,string> $paths  Overrides for the selector.
	 * @return ExtractionPolicy
	 */
	private function policy( array $contents = array(), $server = false, array $paths = array() ) {
		if ( array() === $contents ) {
			foreach ( ContentSelector::FLAGS as $flag ) {
				$contents[ $flag ] = true;
			}
		}
		$p   = array_merge(
			array(
				'abspath'        => self::WP,
				'content_dir'    => self::WC,
				'plugins_dir'    => self::WC . '/plugins',
				'themes_dir'     => self::WC . '/themes',
				'uploads_dir'    => self::WC . '/uploads',
				'mu_plugins_dir' => self::WC . '/mu-plugins',
				'local_dir'      => self::WC . '/ovb-abc123',
				'config_file'    => self::WP . '/wp-config.php',
			),
			$paths
		);
		$sel = new ContentSelector( $p );
		return new ExtractionPolicy(
			$sel->roots( $contents ),
			ExtractionPolicy::protected_paths( self::WP, self::WC, $p['plugins_dir'], array( self::WC . '/plugins/openvault-backup' ), array( self::WC . '/ovb-abc123' ), array( self::WP . '/wp-config.php', self::SITE . '/wp-config.php' ), $server ),
			false
		);
	}

	/**
	 * Names that map to a destination.
	 *
	 * @return array<string,array{string,string,string,string}>
	 */
	public function writable_names() {
		return array(
			'upload'                     => array( 'content/uploads/2026/a.jpg', self::WC . '/uploads/2026/a.jpg', 'content/uploads', '2026/a.jpg' ),
			'plugin file'                => array( 'content/plugins/foo/foo.php', self::WC . '/plugins/foo/foo.php', 'content/plugins', 'foo/foo.php' ),
			'theme'                      => array( 'content/themes/t/style.css', self::WC . '/themes/t/style.css', 'content/themes', 't/style.css' ),
			'mu-plugin'                  => array( 'content/mu-plugins/x.php', self::WC . '/mu-plugins/x.php', 'content/mu-plugins', 'x.php' ),
			'other content'              => array( 'content/languages/de.mo', self::WC . '/languages/de.mo', 'content', 'languages/de.mo' ),
			'root file'                  => array( 'abspath/index.php', self::WP . '/index.php', 'abspath', 'index.php' ),
			'core folder'                => array( 'abspath/wp-admin/x.php', self::WP . '/wp-admin/x.php', 'abspath/wp-admin', 'x.php' ),
			'core deep'                  => array( 'abspath/wp-includes/a/b/c.php', self::WP . '/wp-includes/a/b/c.php', 'abspath/wp-includes', 'a/b/c.php' ),
			'unicode, space and emoji'   => array( 'content/uploads/日本語/😀 pic.png', self::WC . '/uploads/日本語/😀 pic.png', 'content/uploads', '日本語/😀 pic.png' ),
			'zero width joiner emoji'    => array( "content/uploads/\u{1F468}\u{200D}\u{1F469}.png", self::WC . "/uploads/\u{1F468}\u{200D}\u{1F469}.png", 'content/uploads', "\u{1F468}\u{200D}\u{1F469}.png" ),
			'similar to the plugin name' => array( 'content/plugins/openvault-backup-extra/x.php', self::WC . '/plugins/openvault-backup-extra/x.php', 'content/plugins', 'openvault-backup-extra/x.php' ),
			'similar to storage'         => array( 'content/ovbfoo/x', self::WC . '/ovbfoo/x', 'content', 'ovbfoo/x' ),
			'htaccess below wp-content'  => array( 'content/uploads/.htaccess', self::WC . '/uploads/.htaccess', 'content/uploads', '.htaccess' ),
			'php in uploads'             => array( 'content/uploads/2026/shell.php', self::WC . '/uploads/2026/shell.php', 'content/uploads', '2026/shell.php' ),
		);
	}

	/**
	 * @dataProvider writable_names
	 */
	public function test_a_normal_entry_maps_to_its_root( $name, $abs, $root, $rel ) {
		$verdict = $this->policy()->decide( $name );
		$this->assertSame( ExtractionPolicy::WRITE, $verdict['action'], $name );
		$this->assertSame( $abs, $verdict['abs'] );
		$this->assertSame( $root, $verdict['root'] );
		$this->assertSame( $rel, $verdict['rel'] );
		$this->assertSame( $name, $verdict['entry'] );
	}

	/**
	 * Hostile names and the reason they are refused.
	 *
	 * @return array<string,array{mixed,string}>
	 */
	public function hostile_names() {
		return array(
			'dot dot at the root'          => array( '../../x', ExtractionPolicy::R_NAME_UNSAFE ),
			'dot dot in the middle'        => array( 'content/uploads/a/../../b', ExtractionPolicy::R_NAME_UNSAFE ),
			'climbs out of uploads'        => array( 'content/uploads/../../outside.txt', ExtractionPolicy::R_NAME_UNSAFE ),
			'absolute'                     => array( '/etc/passwd', ExtractionPolicy::R_NAME_UNSAFE ),
			'drive letter'                 => array( 'C:\\x', ExtractionPolicy::R_NAME_UNSAFE ),
			'drive letter with slash'      => array( 'C:/x', ExtractionPolicy::R_NAME_UNSAFE ),
			'backslash dot dot'            => array( 'content/uploads/..\\x', ExtractionPolicy::R_NAME_UNSAFE ),
			'backslash separator'          => array( 'content\\uploads\\x', ExtractionPolicy::R_NAME_UNSAFE ),
			'NUL'                          => array( "content/uploads/a\0b.php", ExtractionPolicy::R_NAME_UNSAFE ),
			'newline'                      => array( "content/uploads/a\nb", ExtractionPolicy::R_NAME_UNSAFE ),
			'tab'                          => array( "content/uploads/a\tb", ExtractionPolicy::R_NAME_UNSAFE ),
			'DEL'                          => array( "content/uploads/x\x7f", ExtractionPolicy::R_NAME_UNSAFE ),
			'overlong'                     => array( 'content/uploads/' . str_repeat( 'a', 1100 ), ExtractionPolicy::R_NAME_LONG ),
			'right-to-left override'       => array( "content/uploads/\u{202E}gpj.php", ExtractionPolicy::R_NAME_UNSAFE ),
			'left-to-right isolate'        => array( "content/uploads/a\u{2066}b", ExtractionPolicy::R_NAME_UNSAFE ),
			'byte order mark'              => array( "content/uploads/\u{FEFF}a.php", ExtractionPolicy::R_NAME_UNSAFE ),
			'line separator'               => array( "content/uploads/a\u{2028}b", ExtractionPolicy::R_NAME_UNSAFE ),
			'trailing dot'                 => array( 'content/uploads/a.php.', ExtractionPolicy::R_NAME_UNSAFE ),
			'trailing space'               => array( 'content/uploads/a.php ', ExtractionPolicy::R_NAME_UNSAFE ),
			'folder with a trailing space' => array( 'content/uploads/dir /x.php', ExtractionPolicy::R_NAME_UNSAFE ),
			'empty segment'                => array( 'content//uploads/x', ExtractionPolicy::R_NAME_UNSAFE ),
			'dot segment'                  => array( 'content/uploads/./x', ExtractionPolicy::R_NAME_UNSAFE ),
			'invalid utf-8'                => array( "content/uploads/\xff.jpg", ExtractionPolicy::R_NAME_UNSAFE ),
			'empty'                        => array( '', ExtractionPolicy::R_NAME_UNSAFE ),
			'not a string'                 => array( null, ExtractionPolicy::R_NAME_UNSAFE ),
			'array'                        => array( array( 'content/uploads/x' ), ExtractionPolicy::R_NAME_UNSAFE ),
		);
	}

	/**
	 * @dataProvider hostile_names
	 */
	public function test_a_hostile_name_is_rejected( $name, $reason ) {
		$verdict = $this->policy()->decide( $name );
		$this->assertSame( ExtractionPolicy::REJECT, $verdict['action'], is_string( $name ) ? bin2hex( substr( $name, 0, 40 ) ) : gettype( $name ) );
		$this->assertSame( $reason, $verdict['reason'] );
		$this->assertSame( '', $verdict['abs'], 'a rejected entry has no destination' );
	}

	public function test_links_and_special_files_are_rejected_whatever_their_name() {
		$policy = $this->policy();
		foreach ( array( EntryWalker::TYPE_SYMLINK, EntryWalker::TYPE_HARDLINK ) as $type ) {
			$this->assertSame( ExtractionPolicy::R_LINK, $policy->decide( 'content/uploads/ok.txt', $type )['reason'], $type );
			$this->assertSame( ExtractionPolicy::REJECT, $policy->decide( 'content/uploads/ok.txt', $type )['action'] );
		}
		$this->assertSame( ExtractionPolicy::R_SPECIAL, $policy->decide( 'content/uploads/fifo', EntryWalker::TYPE_SPECIAL )['reason'] );
		$this->assertSame( ExtractionPolicy::REJECT, $policy->decide( '../x', EntryWalker::TYPE_SYMLINK )['action'] );
	}

	public function test_directories_and_the_database_entry_are_skipped() {
		$policy = $this->policy();
		$this->assertSame( array( ExtractionPolicy::SKIP, ExtractionPolicy::R_DIR ), $this->pair( $policy->decide( 'content/uploads/2026', EntryWalker::TYPE_DIR ) ) );
		$this->assertSame( array( ExtractionPolicy::SKIP, ExtractionPolicy::R_DIR ), $this->pair( $policy->decide( 'content/uploads/2026/', EntryWalker::TYPE_DIR ) ), 'a zip folder name ends with a slash' );
		$this->assertSame( array( ExtractionPolicy::REJECT, ExtractionPolicy::R_NAME_UNSAFE ), $this->pair( $policy->decide( 'content/uploads/../x/', EntryWalker::TYPE_DIR ) ), 'a hostile folder name is still refused' );
		$this->assertSame( array( ExtractionPolicy::SKIP, ExtractionPolicy::R_DB ), $this->pair( $policy->decide( 'db/db.sql' ) ) );
		$this->assertSame( array( ExtractionPolicy::SKIP, ExtractionPolicy::R_DB ), $this->pair( $policy->decide( 'db/other.sql' ) ) );
		$this->assertSame( array( ExtractionPolicy::SKIP, ExtractionPolicy::R_UNOWNED ), $this->pair( $policy->decide( 'dbx/file.txt' ) ), 'only the db/ folder is the database; any other name needs a root' );
	}

	/**
	 * Verdict as a pair.
	 *
	 * @param array<string,string> $verdict Verdict.
	 * @return string[]
	 */
	private function pair( array $verdict ) {
		return array( $verdict['action'], $verdict['reason'] );
	}

	public function test_names_no_root_owns_are_skipped() {
		$policy = $this->policy();
		foreach ( array( 'other/x.txt', 'abspath/deep/file.php', 'content', 'abspath', 'content/uploads', 'abspath/wp-admin', 'zzz' ) as $name ) {
			$this->assertSame( array( ExtractionPolicy::SKIP, ExtractionPolicy::R_UNOWNED ), $this->pair( $policy->decide( $name ) ), $name );
		}
		$only_uploads = $this->policy( array( 'uploads' => true ) );
		$this->assertSame( ExtractionPolicy::WRITE, $only_uploads->decide( 'content/uploads/a.jpg' )['action'] );
		foreach ( array( 'content/plugins/p/p.php', 'content/themes/t/s.css', 'abspath/index.php', 'content/other/x.txt', 'abspath/wp-admin/x.php' ) as $name ) {
			$this->assertSame( ExtractionPolicy::R_UNOWNED, $only_uploads->decide( $name )['reason'], 'a backup without that content never touches it: ' . $name );
		}
	}

	/**
	 * Protected paths: skipped, with the reason.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function protected_names() {
		return array(
			'plugin file'                  => array( 'content/plugins/openvault-backup/openvault-backup.php', ExtractionPolicy::R_PROTECTED_PLUGIN ),
			'plugin canary'                => array( 'content/plugins/openvault-backup/canary.php', ExtractionPolicy::R_PROTECTED_PLUGIN ),
			'plugin folder name as a file' => array( 'content/plugins/openvault-backup', ExtractionPolicy::R_PROTECTED_PLUGIN ),
			'plugin deep'                  => array( 'content/plugins/openvault-backup/includes/Restore/x.php', ExtractionPolicy::R_PROTECTED_PLUGIN ),
			'plugin, upper case'           => array( 'content/plugins/OpenVault-Backup/x.php', ExtractionPolicy::R_PROTECTED_PLUGIN ),
			'plugin, mixed case'           => array( 'content/plugins/OPENVAULT-backup/Lib/x.php', ExtractionPolicy::R_PROTECTED_PLUGIN ),
			'storage folder'               => array( 'content/ovb-abc123/backups/x/db.part01.zip', ExtractionPolicy::R_PROTECTED_STORAGE ),
			'storage folder, upper case'   => array( 'content/OVB-ABC123/backups/x', ExtractionPolicy::R_PROTECTED_STORAGE ),
			'another storage folder'       => array( 'content/ovb-xxxx/backups/x.zip', ExtractionPolicy::R_PROTECTED_STORAGE ),
			'wp-config'                    => array( 'abspath/wp-config.php', ExtractionPolicy::R_PROTECTED_CONFIG ),
			'wp-config, upper case'        => array( 'abspath/WP-CONFIG.PHP', ExtractionPolicy::R_PROTECTED_CONFIG ),
			'maintenance'                  => array( 'abspath/.maintenance', ExtractionPolicy::R_PROTECTED_MAINT ),
			'htaccess'                     => array( 'abspath/.htaccess', ExtractionPolicy::R_PROTECTED_SERVER ),
			'web.config'                   => array( 'abspath/web.config', ExtractionPolicy::R_PROTECTED_SERVER ),
			'php.ini'                      => array( 'abspath/php.ini', ExtractionPolicy::R_PROTECTED_SERVER ),
			'user ini'                     => array( 'abspath/.user.ini', ExtractionPolicy::R_PROTECTED_SERVER ),
			'htaccess, upper case'         => array( 'abspath/.HTACCESS', ExtractionPolicy::R_PROTECTED_SERVER ),
		);
	}

	/**
	 * @dataProvider protected_names
	 */
	public function test_protected_paths_are_skipped( $name, $reason ) {
		$verdict = $this->policy()->decide( $name );
		$this->assertSame( ExtractionPolicy::SKIP, $verdict['action'], $name );
		$this->assertSame( $reason, $verdict['reason'] );
	}

	public function test_server_configuration_files_can_be_opted_in_but_wp_config_never() {
		$policy = $this->policy( array(), true );
		foreach ( array( 'abspath/.htaccess', 'abspath/web.config', 'abspath/php.ini', 'abspath/.user.ini' ) as $name ) {
			$this->assertSame( ExtractionPolicy::WRITE, $policy->decide( $name )['action'], $name );
		}
		$this->assertSame( ExtractionPolicy::R_PROTECTED_CONFIG, $policy->decide( 'abspath/wp-config.php' )['reason'] );
		$this->assertSame( ExtractionPolicy::R_PROTECTED_MAINT, $policy->decide( 'abspath/.maintenance' )['reason'] );
		$this->assertSame( ExtractionPolicy::R_PROTECTED_PLUGIN, $policy->decide( 'content/plugins/openvault-backup/x.php' )['reason'] );
	}

	public function test_wp_config_one_folder_above_the_site_is_protected_too() {
		$policy  = $this->policy( array( 'wp_config' => true ), false, array( 'config_file' => self::SITE . '/wp-config.php' ) );
		$verdict = $policy->decide( 'abspath/wp-config.php' );
		$this->assertSame( ExtractionPolicy::SKIP, $verdict['action'] );
		$this->assertSame( ExtractionPolicy::R_PROTECTED_CONFIG, $verdict['reason'] );
	}

	public function test_a_relocated_folder_is_restored_where_it_lives_now() {
		$policy  = $this->policy(
			array(
				'uploads' => true,
				'plugins' => true,
			),
			false,
			array(
				'uploads_dir' => '/data/uploads',
				'plugins_dir' => '/srv/plugins',
			)
		);
		$verdict = $policy->decide( 'content/uploads/2026/a.jpg' );
		$this->assertSame( '/data/uploads/2026/a.jpg', $verdict['abs'] );
		$this->assertSame( '/data/uploads', $verdict['root_abs'] );
		$this->assertSame( '/srv/plugins/x/x.php', $policy->decide( 'content/plugins/x/x.php' )['abs'] );
	}

	public function test_the_longest_root_wins() {
		$policy = $this->policy();
		$this->assertSame( 'content/uploads', $policy->decide( 'content/uploads/x.txt' )['root'], 'not the other-content root that also covers it' );
		$this->assertSame( 'abspath/wp-admin', $policy->decide( 'abspath/wp-admin/a.php' )['root'] );
	}

	public function test_a_path_that_looks_like_a_root_prefix_is_not_owned_by_it() {
		$policy = $this->policy( array( 'uploads' => true ) );
		$this->assertSame( ExtractionPolicy::R_UNOWNED, $policy->decide( 'content/uploads2/a.jpg' )['reason'] );
		$this->assertSame( ExtractionPolicy::R_UNOWNED, $policy->decide( 'content/uploadsx' )['reason'] );
	}

	public function test_a_colon_is_only_refused_on_a_windows_host() {
		$sel   = new ContentSelector(
			array(
				'abspath'     => self::WP,
				'content_dir' => self::WC,
				'uploads_dir' => self::WC . '/uploads',
			)
		);
		$roots = $sel->roots( array( 'uploads' => true ) );
		$unix  = new ExtractionPolicy( $roots, array(), false );
		$win   = new ExtractionPolicy( $roots, array(), true );
		$this->assertSame( ExtractionPolicy::WRITE, $unix->decide( 'content/uploads/12:30.txt' )['action'] );
		$this->assertSame( ExtractionPolicy::R_NAME_UNSAFE, $win->decide( 'content/uploads/file.txt:stream' )['reason'] );
	}

	public function test_resolve_dir_maps_folders_and_refuses_protected_ones() {
		$policy = $this->policy();
		$dir    = $policy->resolve_dir( 'content/uploads/2026/05' );
		$this->assertSame( self::WC . '/uploads/2026/05', $dir['abs'] );
		$this->assertSame( '2026/05', $dir['rel'] );
		$this->assertSame( self::WC . '/uploads', $policy->resolve_dir( 'content/uploads' )['abs'], 'a root itself (created because it was missing)' );
		$this->assertNull( $policy->resolve_dir( 'content/plugins/openvault-backup/sub' ) );
		$this->assertNull( $policy->resolve_dir( 'content/ovb-abc123/x' ) );
		$this->assertNull( $policy->resolve_dir( 'content/uploads/../x' ) );
		$this->assertNull( $policy->resolve_dir( 'nowhere/x' ) );
	}

	public function test_the_self_test_passes_with_protection_and_fails_without() {
		$sel = new ContentSelector(
			array(
				'abspath'     => self::WP,
				'content_dir' => self::WC,
				'plugins_dir' => self::WC . '/plugins',
				'uploads_dir' => self::WC . '/uploads',
				'local_dir'   => self::WC . '/ovb-abc123',
				'config_file' => self::WP . '/wp-config.php',
			)
		);
		$all = array();
		foreach ( ContentSelector::FLAGS as $flag ) {
			$all[ $flag ] = true;
		}
		$roots = $sel->roots( $all );

		$policy = $this->policy();
		$probes = $policy->probe_entries( $roots );
		$this->assertContains( 'content/plugins/openvault-backup/x.php', $probes );
		$this->assertContains( 'abspath/wp-config.php', $probes );
		$this->assertContains( 'abspath/.maintenance', $probes );
		$this->assertContains( 'content/ovb-abc123/x.php', $probes );
		$this->assertSame( '', $policy->self_test( $probes ) );

		$unprotected = new ExtractionPolicy( $roots, array(), false );
		$this->assertNotSame( '', $unprotected->self_test( $probes ), 'without the protected list the self-test refuses to go on' );
	}

	public function test_safe_label_cleans_and_cuts_a_name_for_the_log() {
		$this->assertSame( 'a?b', ExtractionPolicy::safe_label( "a\nb" ) );
		$this->assertSame( 'a?b', ExtractionPolicy::safe_label( "a\u{202E}b" ) );
		$this->assertSame( 80, strlen( ExtractionPolicy::safe_label( str_repeat( 'x', 500 ) ) ) );
		$this->assertSame( 'ok.txt', ExtractionPolicy::safe_label( 'ok.txt' ) );
		$this->assertSame( 1, preg_match( '//u', ExtractionPolicy::safe_label( "caf\xe9\xff" ) ), 'invalid UTF-8 becomes plain text' );
		$this->assertStringNotContainsString( "\0", ExtractionPolicy::safe_label( "a\0b" ) );
	}

	public function test_every_reason_has_a_text() {
		$class = new \ReflectionClass( ExtractionPolicy::class );
		foreach ( $class->getConstants() as $name => $value ) {
			if ( 0 === strpos( $name, 'R_' ) ) {
				$this->assertNotSame( $value, ExtractionPolicy::label( $value ), $name . ' has a label' );
			}
		}
	}

	public function test_a_reason_that_means_a_hostile_archive_is_notable_and_a_normal_skip_is_not() {
		$this->assertContains( ExtractionPolicy::R_NAME_UNSAFE, ExtractionPolicy::NOTABLE );
		$this->assertContains( ExtractionPolicy::R_LINK, ExtractionPolicy::NOTABLE );
		$this->assertContains( ExtractionPolicy::R_DEST_SYMLINK, ExtractionPolicy::NOTABLE );
		$this->assertNotContains( ExtractionPolicy::R_PROTECTED_PLUGIN, ExtractionPolicy::NOTABLE, 'every backup holds the plugin folder: thousands of normal skips' );
		$this->assertNotContains( ExtractionPolicy::R_DIR, ExtractionPolicy::NOTABLE );
		$this->assertNotContains( ExtractionPolicy::R_DB, ExtractionPolicy::NOTABLE );
	}
}
