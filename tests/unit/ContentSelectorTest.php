<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Scan\ContentSelector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Scan\ContentSelector
 */
class ContentSelectorTest extends TestCase {

	private function selector( array $override = array() ) {
		return new ContentSelector(
			array_merge(
				array(
					'abspath'        => '/var/www/html/',
					'content_dir'    => '/var/www/html/wp-content',
					'plugins_dir'    => '/var/www/html/wp-content/plugins',
					'themes_dir'     => '/var/www/html/wp-content/themes',
					'uploads_dir'    => '/var/www/html/wp-content/uploads',
					'mu_plugins_dir' => '/var/www/html/wp-content/mu-plugins',
					'local_dir'      => '/var/www/html/wp-content/ovb-secret',
					'config_file'    => '/var/www/html/wp-config.php',
				),
				$override
			)
		);
	}

	private function entries( array $roots ) {
		return array_column( $roots, 'entry' );
	}

	public function test_nothing_selected() {
		$this->assertSame( array(), $this->selector()->roots( array() ) );
		$this->assertSame( array(), $this->selector()->roots( array( 'db' => true ) ) );
		$this->assertSame(
			array(),
			$this->selector()->roots(
				array(
					'plugins' => false,
					'uploads' => 0,
				)
			)
		);
		$this->assertFalse( ContentSelector::has_files( array( 'db' => true ) ) );
		$this->assertTrue(
			ContentSelector::has_files(
				array(
					'db'     => true,
					'themes' => true,
				)
			)
		);
	}

	public function test_named_folders() {
		$roots = $this->selector()->roots(
			array(
				'plugins'    => true,
				'themes'     => true,
				'uploads'    => true,
				'mu_plugins' => true,
			)
		);
		$this->assertSame( array( 'content/plugins', 'content/themes', 'content/uploads', 'content/mu-plugins' ), $this->entries( $roots ) );
		$this->assertSame( '/var/www/html/wp-content/plugins', $roots[0]['abs'] );
		$this->assertSame( ContentSelector::TYPE_DIR, $roots[0]['type'] );
	}

	public function test_core_is_shallow_root_plus_two_folders() {
		$roots = $this->selector()->roots( array( 'core' => true ) );
		$this->assertSame( array( 'abspath', 'abspath/wp-admin', 'abspath/wp-includes' ), $this->entries( $roots ) );
		$this->assertSame( ContentSelector::TYPE_SHALLOW, $roots[0]['type'] );
		$this->assertSame( '/var/www/html', $roots[0]['abs'], 'trailing slash of ABSPATH is dropped' );
		$this->assertSame( '/var/www/html/wp-admin', $roots[1]['abs'] );
	}

	public function test_wp_config_is_one_file_root() {
		$roots = $this->selector()->roots( array( 'wp_config' => true ) );
		$this->assertSame(
			array(
				array(
					'entry' => 'abspath/wp-config.php',
					'abs'   => '/var/www/html/wp-config.php',
					'type'  => 'file',
				),
			),
			$roots
		);
		$this->assertSame( '/var/www/html/../wp-config.php', $this->selector( array( 'config_file' => '/var/www/html/../wp-config.php' ) )->roots( array( 'wp_config' => true ) )[0]['abs'], 'the real path is the caller\'s job; the entry name never changes' );
		$this->assertSame( array(), $this->selector( array( 'config_file' => '' ) )->roots( array( 'wp_config' => true ) ), 'no wp-config.php found' );
	}

	public function test_other_content_leaves_the_named_folders_to_their_own_flags() {
		$selector = $this->selector();
		$this->assertSame( array( 'content' ), $this->entries( $selector->roots( array( 'other_content' => true ) ) ) );
		$this->assertSame(
			array(
				'/var/www/html/wp-content/plugins',
				'/var/www/html/wp-content/themes',
				'/var/www/html/wp-content/uploads',
				'/var/www/html/wp-content/mu-plugins',
			),
			$selector->other_root_dirs()
		);
	}

	public function test_the_local_folder_is_known_and_never_a_root() {
		$selector = $this->selector();
		$this->assertSame( '/var/www/html/wp-content/ovb-secret', $selector->local_dir() );
		$roots = $selector->roots(
			array(
				'plugins'       => true,
				'themes'        => true,
				'uploads'       => true,
				'mu_plugins'    => true,
				'other_content' => true,
				'core'          => true,
				'wp_config'     => true,
			)
		);
		$this->assertNotContains( '/var/www/html/wp-content/ovb-secret', array_column( $roots, 'abs' ) );
		$this->assertSame( '', $this->selector( array( 'local_dir' => '' ) )->local_dir() );
	}

	public function test_a_relocated_folder_keeps_its_default_name() {
		$selector = $this->selector(
			array(
				'plugins_dir' => '/srv/shared/plugins',
				'uploads_dir' => '/var/www/html/wp-content/uploads/sites/../x',
			)
		);
		$roots    = $selector->roots(
			array(
				'plugins' => true,
				'uploads' => true,
			)
		);
		$this->assertSame( 'content/plugins', $roots[0]['entry'] );
		$this->assertSame( '/srv/shared/plugins', $roots[0]['abs'] );
		$this->assertSame( 'content/plugins', $roots[0]['entry'] );
		$this->assertSame( 'content/uploads', $roots[1]['entry'], 'an unsafe relative path falls back to the default name' );
	}

	public function test_a_custom_folder_below_content_keeps_its_place() {
		$selector = $this->selector( array( 'themes_dir' => '/var/www/html/wp-content/custom/themes' ) );
		$this->assertSame( 'content/custom/themes', $selector->roots( array( 'themes' => true ) )[0]['entry'] );
	}

	public function test_roots_never_overlap() {
		$roots   = $this->selector()->roots(
			array(
				'plugins'       => true,
				'themes'        => true,
				'uploads'       => true,
				'mu_plugins'    => true,
				'other_content' => true,
				'core'          => true,
				'wp_config'     => true,
			)
		);
		$entries = $this->entries( $roots );
		$this->assertSame( array_values( array_unique( $entries ) ), $entries );
		$abs = array_column( $roots, 'abs' );
		$this->assertSame( array_values( array_unique( $abs ) ), $abs );

		$same = $this->selector( array( 'themes_dir' => '/var/www/html/wp-content/plugins' ) )->roots(
			array(
				'plugins' => true,
				'themes'  => true,
			)
		);
		$this->assertCount( 1, $same, 'two flags that point at one folder produce one root' );
	}

	public function test_resolve_maps_an_entry_to_its_root_and_the_longest_root_wins() {
		$selector = $this->selector();
		$roots    = $selector->roots(
			array(
				'plugins'       => true,
				'other_content' => true,
				'core'          => true,
				'wp_config'     => true,
			)
		);
		$this->assertSame( '/var/www/html/wp-content/plugins/x/y.php', ContentSelector::resolve( $roots, 'content/plugins/x/y.php' ) );
		$this->assertSame( '/var/www/html/wp-content/object-cache.php', ContentSelector::resolve( $roots, 'content/object-cache.php' ) );
		$this->assertSame( '/var/www/html/wp-content/plugins-extra/a.txt', ContentSelector::resolve( $roots, 'content/plugins-extra/a.txt' ), 'a name that only starts like another root belongs to the content root' );
		$this->assertSame( '/var/www/html/index.php', ContentSelector::resolve( $roots, 'abspath/index.php' ) );
		$this->assertSame( '/var/www/html/wp-includes/a/b.php', ContentSelector::resolve( $roots, 'abspath/wp-includes/a/b.php' ) );
		$this->assertSame( '/var/www/html/wp-config.php', ContentSelector::resolve( $roots, 'abspath/wp-config.php' ) );
		$this->assertNull( ContentSelector::resolve( $roots, 'other/file' ), 'no root owns it' );
		$this->assertNull( ContentSelector::resolve( $roots, 'content/../etc/passwd' ), 'traversal' );
		$this->assertNull( ContentSelector::resolve( $roots, '/content/a' ) );
		$this->assertNull( ContentSelector::resolve( $roots, "content/a\nb" ) );
	}
}
