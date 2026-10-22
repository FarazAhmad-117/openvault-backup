<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged -- These helpers work on real files and links in the system temp directory without WordPress loaded.
namespace OpenVault\Backup\Tests\Unit;

use OpenVault\Backup\Archive\TarWriter;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\Scan\ContentSelector;
use OpenVault\Backup\Restore\ExtractionPolicy;
use OpenVault\Backup\Restore\FsProbe;

/**
 * Shared fixtures of the file restore tests: temp sites, hand-made archives (also hostile ones), policies and budgets.
 */
final class FileFixtures {

	/**
	 * A fresh temp folder.
	 *
	 * @return string
	 */
	public static function tmp() {
		$dir = sys_get_temp_dir() . '/ovb-ft-' . bin2hex( random_bytes( 5 ) );
		mkdir( $dir, 0777, true );
		return $dir;
	}

	/**
	 * JSON of a value (the tests run without WordPress).
	 *
	 * @param array<mixed> $value Value.
	 * @return string
	 */
	public static function json( array $value ) {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Plain PHP in a test.
	}

	/**
	 * Remove a tree without following links.
	 *
	 * @param string $dir Folder.
	 * @return void
	 */
	public static function rrmdir( $dir ) {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			@unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				self::rrmdir( $dir . '/' . $name );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Every file below a folder: relative path => sha256 (links are listed as "link:<target>", folders as "dir").
	 *
	 * @param string $dir Folder.
	 * @return array<string,string>
	 */
	public static function snapshot( $dir, $prefix = '' ) {
		$out = array();
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			$rel  = '' === $prefix ? $name : $prefix . '/' . $name;
			if ( is_link( $path ) ) {
				$out[ $rel ] = 'link:' . readlink( $path );
			} elseif ( is_dir( $path ) ) {
				$out[ $rel ] = 'dir';
				$out         = array_merge( $out, self::snapshot( $path, $rel ) );
			} else {
				$out[ $rel ] = hash_file( 'sha256', $path );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * A raw 512-byte tar header with any type flag (the writer of the plugin only makes regular files).
	 *
	 * @param string $name Name (at most 100 bytes).
	 * @param string $type Type flag.
	 * @param int    $size Size field.
	 * @param string $link Link name.
	 * @return string
	 */
	public static function tar_block( $name, $type, $size = 0, $link = '' ) {
		$block = str_pad( substr( $name, 0, 100 ), 100, "\0" )
			. sprintf( '%07o', 0644 ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%07o', 0 ) . "\0"
			. sprintf( '%011o', $size ) . "\0"
			. sprintf( '%011o', 1700000000 ) . "\0"
			. '        '
			. $type
			. str_pad( substr( $link, 0, 100 ), 100, "\0" )
			. "ustar\0" . '00'
			. str_repeat( "\0", 32 )
			. str_repeat( "\0", 32 )
			. str_repeat( "\0", 16 )
			. str_repeat( "\0", 155 )
			. str_repeat( "\0", 12 );
		$sum   = array_sum( unpack( 'C*', $block ) );
		return substr( $block, 0, 148 ) . sprintf( '%06o', $sum ) . "\0 " . substr( $block, 156 );
	}

	/**
	 * Tar bytes of entries. An entry is array( name, body (string|null), type (file|dir|symlink|hardlink|fifo), link target, mtime ).
	 *
	 * @param array<int,array<int,mixed>> $entries Entries.
	 * @return string Plain tar bytes without the end blocks.
	 */
	public static function tar_bytes( array $entries ) {
		$out = '';
		foreach ( $entries as $e ) {
			$name  = $e[0];
			$body  = isset( $e[1] ) ? (string) $e[1] : '';
			$type  = isset( $e[2] ) ? $e[2] : 'file';
			$link  = isset( $e[3] ) ? $e[3] : '';
			$mtime = isset( $e[4] ) ? (int) $e[4] : 1700000000;
			if ( 'file' === $type ) {
				$out .= TarWriter::entry_header( $name, strlen( $body ), $mtime ) . $body . str_repeat( "\0", TarWriter::padding( strlen( $body ) ) );
			} else {
				$flags = array(
					'dir'      => '5',
					'symlink'  => '2',
					'hardlink' => '1',
					'fifo'     => '6',
				);
				$out  .= self::tar_block( $name, $flags[ $type ], 0, $link );
			}
		}
		return $out;
	}

	/**
	 * Write a tar.gz of entries; every entry becomes its own gzip member (like the real writer).
	 *
	 * @param string                      $path    Output file.
	 * @param array<int,array<int,mixed>> $entries Entries (see tar_bytes()).
	 * @return void
	 */
	public static function tar_gz( $path, array $entries ) {
		$data = '';
		foreach ( $entries as $entry ) {
			$data .= gzencode( self::tar_bytes( array( $entry ) ) );
		}
		$data .= gzencode( TarWriter::end_blocks() );
		file_put_contents( $path, $data );
	}

	/**
	 * Write a zip of entries (entry as for tar_bytes(); a symlink entry stores its target as the body and a Unix symlink mode).
	 *
	 * @param string                      $path    Output file.
	 * @param array<int,array<int,mixed>> $entries Entries.
	 * @return void
	 */
	public static function zip( $path, array $entries ) {
		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( $entries as $e ) {
			$type = isset( $e[2] ) ? $e[2] : 'file';
			if ( 'dir' === $type ) {
				$zip->addEmptyDir( rtrim( $e[0], '/' ) );
				continue;
			}
			$zip->addFromString( $e[0], 'symlink' === $type ? (string) $e[3] : ( isset( $e[1] ) ? (string) $e[1] : '' ) );
			$index = $zip->count() - 1;
			if ( 'symlink' === $type ) {
				$zip->setExternalAttributesIndex( $index, \ZipArchive::OPSYS_UNIX, 0120777 << 16 );
			} elseif ( 'file' === $type ) {
				$zip->setExternalAttributesIndex( $index, \ZipArchive::OPSYS_UNIX, 0100644 << 16 );
			}
			if ( isset( $e[4] ) && method_exists( $zip, 'setMtimeIndex' ) ) {
				$zip->setMtimeIndex( $index, (int) $e[4] );
			}
		}
		$zip->close();
	}

	/**
	 * A budget that is used up after $reads clock reads (0 reads left = exhausted at the second check), like the tiny budgets of the matrix.
	 *
	 * @param int $seconds Seconds of budget (the clock moves one second per read).
	 * @return Budget
	 */
	public static function tiny_budget( $seconds = 2 ) {
		$clock = 0.0;
		return new Budget(
			$seconds,
			PHP_INT_MAX,
			static function () use ( &$clock ) {
				$clock += 1.0;
				return $clock;
			}
		);
	}

	/**
	 * A budget that is never used up.
	 *
	 * @return Budget
	 */
	public static function big_budget() {
		return new Budget( 3600, PHP_INT_MAX );
	}

	/**
	 * Roots and protected paths of a temp site: <site>/wp (the WordPress folder) with wp-content/{plugins,themes,uploads,mu-plugins}, the
	 * plugin's own folder wp-content/plugins/openvault-backup and the storage folder wp-content/ovb-test.
	 *
	 * @param string              $site     Site folder.
	 * @param array<string,bool>  $contents Contents flags (default: everything).
	 * @param bool                $server   Restore the server configuration files too.
	 * @return ExtractionPolicy
	 */
	public static function policy( $site, array $contents = array(), $server = false ) {
		if ( array() === $contents ) {
			foreach ( ContentSelector::FLAGS as $flag ) {
				$contents[ $flag ] = true;
			}
		}
		$abs     = $site . '/wp';
		$content = $abs . '/wp-content';
		$sel     = new ContentSelector(
			array(
				'abspath'        => $abs,
				'content_dir'    => $content,
				'plugins_dir'    => $content . '/plugins',
				'themes_dir'     => $content . '/themes',
				'uploads_dir'    => $content . '/uploads',
				'mu_plugins_dir' => $content . '/mu-plugins',
				'local_dir'      => $content . '/ovb-test',
				'config_file'    => $abs . '/wp-config.php',
			)
		);
		return new ExtractionPolicy(
			$sel->roots( $contents ),
			ExtractionPolicy::protected_paths(
				$abs,
				$content,
				$content . '/plugins',
				array( $content . '/plugins/openvault-backup' ),
				array( $content . '/ovb-test' ),
				array( $abs . '/wp-config.php' ),
				$server
			),
			false
		);
	}

	/**
	 * A probe that says chosen paths are symlinks (without creating any).
	 *
	 * @param string[] $links Paths that count as symlinks.
	 * @return FsProbe
	 */
	public static function probe_with_links( array $links ) {
		return new class( $links ) extends FsProbe {
			/**
			 * Links.
			 *
			 * @var string[]
			 */
			private $links;

			/**
			 * Constructor.
			 *
			 * @param string[] $links Links.
			 */
			public function __construct( array $links ) {
				$this->links = $links;
			}

			/**
			 * Whether the path is a pretended link.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function is_link( $path ) {
				return in_array( $path, $this->links, true ) || parent::is_link( $path );
			}
		};
	}
}
