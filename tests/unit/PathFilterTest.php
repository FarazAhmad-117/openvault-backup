<?php
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Engine\Scan\PathFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenVault\Backup\Engine\Scan\PathFilter
 */
class PathFilterTest extends TestCase {

	const LOCAL = '/var/www/wp-content/ovb-secret';

	private function filter( $patterns = array(), $max = 0 ) {
		return new PathFilter( array( self::LOCAL ), $patterns, $max );
	}

	private function decide( PathFilter $filter, $entry, $is_dir = false, $is_link = false, $size = 10, $absolute = null ) {
		return $filter->decide( null === $absolute ? '/var/www/' . $entry : $absolute, $entry, $is_dir, $is_link, $size );
	}

	public function test_plain_entries_are_included() {
		$filter = $this->filter();
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $filter, 'content/uploads/a.jpg' ) );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $filter, 'content/uploads', true ) );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $filter, 'content/node_modules/x.js' ), 'node_modules is not excluded by default' );
	}

	public function test_the_local_backup_folder_is_always_excluded() {
		$filter = $this->filter();
		$this->assertSame( PathFilter::EXCLUDED, $filter->decide( self::LOCAL, 'content/ovb-secret', true, false, 0 ) );
		$this->assertSame( PathFilter::EXCLUDED, $filter->decide( self::LOCAL . '/', 'content/ovb-secret', true, false, 0 ), 'trailing slash' );
		$this->assertSame( PathFilter::EXCLUDED, $filter->decide( str_replace( '/', '\\', self::LOCAL ), 'content/ovb-secret', true, false, 0 ), 'backslashes' );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $filter->decide( self::LOCAL . '-other', 'content/ovb-secret-other', true, false, 0 ), 'a sibling with the same prefix is not excluded' );
	}

	public function test_folders_of_other_roots_are_skipped_without_being_counted_as_excluded() {
		$filter = new PathFilter( array( self::LOCAL ), array(), 0, array( '/var/www/wp-content/plugins/' ) );
		$this->assertSame( PathFilter::OTHER_ROOT, $filter->decide( '/var/www/wp-content/plugins', 'content/plugins', true, false, 0 ) );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $filter->decide( '/var/www/wp-content/plugins', 'content/plugins', false, false, 5 ), 'a file with that name is an ordinary file' );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $filter->decide( '/var/www/wp-content/plugins-x', 'content/plugins-x', true, false, 0 ) );
	}

	public function test_git_folders_are_always_excluded() {
		$filter = $this->filter();
		$this->assertSame( PathFilter::EXCLUDED, $this->decide( $filter, 'content/plugins/x/.git', true ) );
		$this->assertSame( PathFilter::EXCLUDED, $this->decide( $filter, 'content/plugins/x/.git' ), 'a .git file (submodule)' );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $filter, 'content/plugins/x/.gitignore' ) );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $filter, 'content/plugins/x/.github', true ) );
	}

	public function test_symlinks_are_never_archived() {
		$filter = $this->filter();
		$this->assertSame( PathFilter::SYMLINK, $this->decide( $filter, 'content/uploads/link', false, true ) );
		$this->assertSame( PathFilter::SYMLINK, $this->decide( $filter, 'content/uploads/linkdir', false, true ), 'link to a folder (is_dir is false for links)' );
		$this->assertSame( PathFilter::SYMLINK, $this->decide( $filter, 'content/uploads/linkdir', true, true ), 'even if a probe says directory' );
	}

	public function test_unsafe_names_are_rejected() {
		$filter = $this->filter();
		foreach ( array( "content/a\nb", "content/a\tb", 'content/../x', '/abs', 'content\\x', "content/a\xff" ) as $entry ) {
			$this->assertSame( PathFilter::BADNAME, $this->decide( $filter, $entry ), (string) $entry );
		}
	}

	public function test_size_cap() {
		$filter = $this->filter( array(), 1000 );
		$this->assertSame( PathFilter::OVERSIZE, $this->decide( $filter, 'content/big.bin', false, false, 1001 ) );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $filter, 'content/ok.bin', false, false, 1000 ) );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $filter, 'content/dir', true, false, 99999 ), 'folders have no size cap' );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $this->filter(), 'content/huge.bin', false, false, PHP_INT_MAX ), '0 = unlimited' );
	}

	public function test_max_bytes_from_setting() {
		$this->assertSame( 0, PathFilter::max_bytes_from_setting( 0 ) );
		$this->assertSame( 0, PathFilter::max_bytes_from_setting( -5 ) );
		$this->assertSame( 0, PathFilter::max_bytes_from_setting( 'abc' ) );
		$this->assertSame( 0, PathFilter::max_bytes_from_setting( null ) );
		$this->assertSame( 50 * 1048576, PathFilter::max_bytes_from_setting( '50' ) );
		$this->assertSame( 1048576 * 1048576, PathFilter::max_bytes_from_setting( 99999999 ), 'clamped to 1 TiB' );
	}

	/**
	 * @dataProvider pattern_cases
	 */
	public function test_patterns( $pattern, $entry, $is_dir, $expected ) {
		$filter = $this->filter( array( $pattern ) );
		$this->assertSame( $expected ? PathFilter::EXCLUDED : PathFilter::INCLUDE_ENTRY, $this->decide( $filter, $entry, $is_dir ), $pattern . ' vs ' . $entry );
	}

	public function pattern_cases() {
		return array(
			'extension anywhere'      => array( '*.log', 'content/uploads/a/b/debug.log', false, true ),
			'extension no match'      => array( '*.log', 'content/uploads/a/b/debug.txt', false, false ),
			'extension in the name'   => array( '*.log', 'content/uploads/blog/post', false, false ),
			'rooted glob file'        => array( 'content/uploads/cache/*', 'content/uploads/cache/x.tmp', false, true ),
			'rooted glob nested'      => array( 'content/uploads/cache/*', 'content/uploads/cache/a/b.tmp', false, true ),
			'rooted glob folder'      => array( 'content/uploads/cache/*', 'content/uploads/cache', true, true ),
			'rooted glob sibling'     => array( 'content/uploads/cache/*', 'content/uploads/cache2/x.tmp', false, false ),
			'leading slash dropped'   => array( '/content/uploads/cache/*', 'content/uploads/cache/x', false, true ),
			'node_modules anywhere'   => array( '*/node_modules/*', 'content/plugins/x/node_modules/y/z.js', false, true ),
			'node_modules folder'     => array( '*/node_modules/*', 'content/plugins/x/node_modules', true, true ),
			'node_modules bare name'  => array( 'node_modules', 'content/plugins/x/node_modules', true, true ),
			'node_modules bare other' => array( 'node_modules', 'content/plugins/x/node_modules_old', true, false ),
			'question mark'           => array( 'content/uploads/202?', 'content/uploads/2026', true, true ),
			'question mark no slash'  => array( 'content?uploads', 'content/uploads', true, false ),
			'regex chars are literal' => array( 'a+b(1).txt', 'content/a+b(1).txt', false, true ),
			'regex chars no regex'    => array( 'a+b(1).txt', 'content/aab1.txt', false, false ),
			'dot is literal'          => array( 'a.txt', 'content/abtxt', false, false ),
			'unicode'                 => array( "caf\xc3\xa9*", "content/caf\xc3\xa9.txt", false, true ),
			'case sensitive'          => array( '*.LOG', 'content/a.log', false, false ),
		);
	}

	public function test_pattern_validation() {
		$raw = array(
			'*.log',
			'  spaced  ',
			'*',
			'**',
			'?',
			'/',
			'',
			'../../etc/passwd',
			'a/../b',
			'..',
			"nul\0byte",
			"new\nline",
			"bad\xff",
			str_repeat( 'a', 256 ),
			42,
			null,
			array( 'x' ),
			'dir\\sub\\*.php',
			'*.log',
			'dots..ok',
		);
		$this->assertSame( array( '*.log', 'spaced', 'dir/sub/*.php', 'dots..ok' ), PathFilter::normalise_patterns( $raw ) );
		$this->assertSame( array(), PathFilter::normalise_patterns( 'not a list' ) );
		$this->assertSame( array(), PathFilter::normalise_patterns( null ) );
	}

	public function test_pattern_count_is_capped() {
		$raw = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$raw[] = 'p' . $i . '*';
		}
		$this->assertCount( PathFilter::MAX_PATTERNS, PathFilter::normalise_patterns( $raw ) );
	}

	public function test_a_hostile_pattern_cannot_break_the_regex() {
		$filter = $this->filter( array( '(', '[', '*(?<=', '\\E.*', '/e', 'a|b' ) );
		$this->assertSame( PathFilter::EXCLUDED, $this->decide( $filter, 'content/a|b' ) );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $filter, 'content/a' ) );
		$this->assertSame( PathFilter::INCLUDE_ENTRY, $this->decide( $filter, 'content/b' ) );
	}
}
