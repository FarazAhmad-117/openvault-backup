<?php
/**
 * Maps the "contents" selection of a backup to the folders and files to archive.
 *
 * Phase: MVP
 *
 * Purpose: Pure: every location is injected (see from_environment() for the real ones), so the mapping is unit tested.
 *
 * Contents flags (keys of ovb_backups.contents, all boolean; "db" is handled by the database export):
 *  - plugins        WP_PLUGIN_DIR
 *  - themes         the theme root
 *  - uploads        the uploads folder (the whole network uploads folder on multisite)
 *  - mu_plugins     WPMU_PLUGIN_DIR
 *  - other_content  the rest of wp-content: everything except plugins, themes, uploads, mu-plugins and the plugin's own
 *                   local backup folder
 *  - core           WordPress core: wp-admin, wp-includes and the files in the ABSPATH folder (not wp-content, not wp-config.php)
 *  - wp_config      wp-config.php (also when WordPress keeps it one folder above ABSPATH)
 *
 * A root is array{ entry, abs, type } where type is "dir" (walk recursively), "shallow" (only the files directly in
 * the folder) or "file". "entry" is the archive-internal name prefix (see EntryName): "abspath/..." for paths below
 * ABSPATH and "content/..." for paths below WP_CONTENT_DIR. A plugins, themes, uploads or mu-plugins folder that is
 * not inside WP_CONTENT_DIR is stored under its default name ("content/plugins" ...) so a restore can put it back.
 * Roots never overlap: a path belongs to exactly one root, so it is archived once.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Engine\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Content selector.
 */
final class ContentSelector {

	const FLAGS = array( 'plugins', 'themes', 'uploads', 'mu_plugins', 'other_content', 'core', 'wp_config' );

	const TYPE_DIR     = 'dir';
	const TYPE_SHALLOW = 'shallow';
	const TYPE_FILE    = 'file';

	/**
	 * Locations (normalised, no trailing slash).
	 *
	 * @var array<string,string>
	 */
	private $paths;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $paths Keys: abspath, content_dir, plugins_dir, themes_dir, uploads_dir, mu_plugins_dir,
	 *                                    local_dir (the plugin's own folder, never archived) and config_file ('' when none).
	 */
	public function __construct( array $paths ) {
		$defaults    = array(
			'abspath'        => '',
			'content_dir'    => '',
			'plugins_dir'    => '',
			'themes_dir'     => '',
			'uploads_dir'    => '',
			'mu_plugins_dir' => '',
			'local_dir'      => '',
			'config_file'    => '',
		);
		$this->paths = array();
		foreach ( $defaults as $key => $default_value ) {
			$this->paths[ $key ] = isset( $paths[ $key ] ) ? EntryName::normalise_path( $paths[ $key ] ) : $default_value;
		}
	}

	/**
	 * Selector for the running WordPress site.
	 *
	 * @param string $local_dir Absolute path of the plugin's local backup folder.
	 * @return self
	 */
	public static function from_environment( $local_dir ) {
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		if ( is_multisite() ) {
			// Sub-sites store files in uploads/sites/<id>; the network backup takes the whole uploads folder.
			$base = (string) preg_replace( '#[\\\\/]sites[\\\\/]\d+$#', '', $base );
		}

		$abspath = EntryName::normalise_path( ABSPATH );
		$config  = '';
		if ( file_exists( $abspath . '/wp-config.php' ) ) {
			$config = $abspath . '/wp-config.php';
		} elseif ( file_exists( dirname( $abspath ) . '/wp-config.php' ) && ! file_exists( dirname( $abspath ) . '/wp-settings.php' ) ) {
			$config = dirname( $abspath ) . '/wp-config.php';
		}

		return new self(
			array(
				'abspath'        => $abspath,
				'content_dir'    => WP_CONTENT_DIR,
				'plugins_dir'    => WP_PLUGIN_DIR,
				'themes_dir'     => get_theme_root(),
				'uploads_dir'    => $base,
				'mu_plugins_dir' => WPMU_PLUGIN_DIR,
				'local_dir'      => $local_dir,
				'config_file'    => $config,
			)
		);
	}

	/**
	 * Whether the selection holds anything this class maps (the database is not one of them).
	 *
	 * @param array<mixed> $contents Contents flags.
	 * @return bool
	 */
	public static function has_files( array $contents ) {
		foreach ( self::FLAGS as $flag ) {
			if ( ! empty( $contents[ $flag ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Roots for a selection, in a stable order.
	 *
	 * @param array<mixed> $contents Contents flags.
	 * @return array<int,array{entry:string,abs:string,type:string}>
	 */
	public function roots( array $contents ) {
		$p     = $this->paths;
		$roots = array();

		$named = array(
			'plugins'    => array( 'plugins_dir', 'plugins' ),
			'themes'     => array( 'themes_dir', 'themes' ),
			'uploads'    => array( 'uploads_dir', 'uploads' ),
			'mu_plugins' => array( 'mu_plugins_dir', 'mu-plugins' ),
		);
		foreach ( $named as $flag => $info ) {
			if ( ! empty( $contents[ $flag ] ) && '' !== $p[ $info[0] ] ) {
				$roots[] = $this->dir_root( $this->entry_for( $p[ $info[0] ], $info[1] ), $p[ $info[0] ] );
			}
		}

		if ( ! empty( $contents['other_content'] ) && '' !== $p['content_dir'] ) {
			$roots[] = $this->dir_root( 'content', $p['content_dir'] );
		}

		if ( ! empty( $contents['core'] ) && '' !== $p['abspath'] ) {
			$roots[] = array(
				'entry' => 'abspath',
				'abs'   => $p['abspath'],
				'type'  => self::TYPE_SHALLOW,
			);
			foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
				$roots[] = $this->dir_root( 'abspath/' . $dir, $p['abspath'] . '/' . $dir );
			}
		}

		if ( ! empty( $contents['wp_config'] ) && '' !== $p['config_file'] ) {
			$roots[] = array(
				'entry' => 'abspath/wp-config.php',
				'abs'   => $p['config_file'],
				'type'  => self::TYPE_FILE,
			);
		}

		return $this->without_duplicates( $roots );
	}

	/**
	 * The plugin's own local backup folder (never archived, whatever else is selected).
	 *
	 * @return string Normalised path, '' when unknown.
	 */
	public function local_dir() {
		return $this->paths['local_dir'];
	}

	/**
	 * Folders that belong to the plugins, themes, uploads and mu-plugins flags. A walk of the rest of wp-content
	 * ("other_content") must not enter them: they are archived (or not) by their own flag.
	 *
	 * @return string[]
	 */
	public function other_root_dirs() {
		$dirs = array();
		foreach ( array( 'plugins_dir', 'themes_dir', 'uploads_dir', 'mu_plugins_dir' ) as $key ) {
			if ( '' !== $this->paths[ $key ] ) {
				$dirs[] = $this->paths[ $key ];
			}
		}
		return array_values( array_unique( $dirs ) );
	}

	/**
	 * Absolute path of an entry name given the roots (longest matching root wins).
	 *
	 * @param array<int,array{entry:string,abs:string,type:string}> $roots Roots.
	 * @param string                                                $entry Entry name.
	 * @return string|null Null for an unsafe name or a name no root owns.
	 */
	public static function resolve( array $roots, $entry ) {
		if ( ! EntryName::is_safe( $entry ) ) {
			return null;
		}
		$best = null;
		foreach ( $roots as $root ) {
			$prefix = $root['entry'];
			if ( $entry === $prefix || 0 === strpos( $entry, $prefix . '/' ) ) {
				if ( null === $best || strlen( $prefix ) > strlen( $best['entry'] ) ) {
					$best = $root;
				}
			}
		}
		if ( null === $best ) {
			return null;
		}
		return $entry === $best['entry'] ? $best['abs'] : $best['abs'] . substr( $entry, strlen( $best['entry'] ) );
	}

	/**
	 * Directory root.
	 *
	 * @param string $entry Entry prefix.
	 * @param string $abs   Absolute folder.
	 * @return array{entry:string,abs:string,type:string}
	 */
	private function dir_root( $entry, $abs ) {
		return array(
			'entry' => $entry,
			'abs'   => $abs,
			'type'  => self::TYPE_DIR,
		);
	}

	/**
	 * Entry prefix of a named folder: its place below wp-content, or the default name when it lives elsewhere.
	 *
	 * @param string $abs     Absolute folder.
	 * @param string $default_name Default folder name below wp-content.
	 * @return string
	 */
	private function entry_for( $abs, $default_name ) {
		$content = $this->paths['content_dir'];
		if ( '' !== $content && 0 === strpos( $abs, $content . '/' ) ) {
			$entry = 'content/' . substr( $abs, strlen( $content ) + 1 );
			if ( EntryName::is_safe( $entry ) ) {
				return $entry;
			}
		}
		return 'content/' . $default_name;
	}

	/**
	 * Drop roots with the same entry and roots that lie inside a recursive root that already covers them.
	 *
	 * @param array<int,array{entry:string,abs:string,type:string}> $roots Roots.
	 * @return array<int,array{entry:string,abs:string,type:string}>
	 */
	private function without_duplicates( array $roots ) {
		$out = array();
		foreach ( $roots as $root ) {
			$covered = false;
			foreach ( $out as $kept ) {
				if ( $kept['entry'] === $root['entry'] || $kept['abs'] === $root['abs'] ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				$out[] = $root;
			}
		}
		return $out;
	}
}
