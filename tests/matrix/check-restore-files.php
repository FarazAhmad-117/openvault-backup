<?php
/**
 * Files restore and undo checks (Milestone 5, step 5c), included by check-plugin.php inside WordPress.
 *
 * A fixture tree (uploads with nested, unicode, space and emoji names, an empty file, a 25 MB random file and a 120 character name; a fake plugin, theme and
 * mu-plugin; other wp-content; a canary file inside this plugin's own folder) is backed up for real, WRECKED (files changed, deleted and added, a folder
 * replaced by a symlink that points out of the site), restored through RestoreManager / RestoreRunner with a tiny budget over many slices for every archive
 * format and for the modes "files" and "full", compared file by file (sha256) with what it was, and then UNDONE: the site must be exactly what it was just
 * before the restore (the database too for "full"). Also: protected paths (the plugin's own folder, wp-config.php, .htaccess, the storage folder), the
 * journal, kills in the middle of a file, hand-made hostile archives that go through a real backup row, manifest and checksums (zip-slip names, absolute names,
 * links, protected paths: nothing outside the roots is written), a full disk, a damaged part, cancel rules, multisite and encrypted backups, maintenance mode,
 * dismissing the undo data. Everything created here is removed again. Uses ovb_check() and the helpers of check-plugin.php and check-restore-db.php.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable

use OpenVault\Backup\Archive\CapabilityDetector;
use OpenVault\Backup\Archive\EntryWalker;
use OpenVault\Backup\Archive\TarWriter;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\RestoresRepository;
use OpenVault\Backup\Engine\Budget;
use OpenVault\Backup\Engine\JobContext;
use OpenVault\Backup\Engine\JobRunner;
use OpenVault\Backup\Engine\JobState;
use OpenVault\Backup\Engine\StepResult;
use OpenVault\Backup\Engine\Steps\CleanupStep;
use OpenVault\Backup\Engine\Steps\StepInterface;
use OpenVault\Backup\Restore\ExtractionPolicy;
use OpenVault\Backup\Restore\MaintenanceMode;
use OpenVault\Backup\Restore\RestoreManager;
use OpenVault\Backup\Restore\RestoreMeta;
use OpenVault\Backup\Restore\RestoreRunner;
use OpenVault\Backup\Restore\RestoreWorkDir;
use OpenVault\Backup\Restore\SafeWriter;
use OpenVault\Backup\Restore\Steps\FileRestoreStep;
use OpenVault\Backup\Restore\UndoJournal;
use OpenVault\Backup\Restore\UndoStore;
use OpenVault\Backup\Support\Manifest;
use OpenVault\Backup\Support\Paths;

if ( ! class_exists( 'OvbKillFilesRepo' ) ) {
	/** A repository whose save_state() "dies" at chosen counts during the apply phase of the file restore: the file was written, its position was not stored. */
	class OvbKillFilesRepo extends OvbKillRepo {
		public function save_state( $id, JobState $state ) {
			$cursor = $state->get_cursor();
			if ( JobState::STEP_FILE_RESTORE === $state->get_step() && isset( $cursor['ph'] ) && 'a' === $cursor['ph'] ) {
				++$this->count;
				if ( in_array( $this->count, $this->kill_at, true ) ) {
					$this->killed = true;
					++$this->kills;
					throw new \RuntimeException( 'simulated kill' );
				}
			}
			return RestoresRepository::save_state( $id, $state );
		}
	}

	/** Runs the real step; when the repository "killed" the process, the dead run's result is thrown away, garbage is left in the temp files and the job resumes from what was stored. */
	class OvbKillFilesStep implements StepInterface {
		private $inner;
		private $repo;
		public $discards = 0;
		public function __construct( StepInterface $inner, OvbKillFilesRepo $repo ) {
			$this->inner = $inner;
			$this->repo  = $repo;
		}
		public function id() {
			return $this->inner->id();
		}
		public function run( JobContext $context ) {
			try {
				$result = $this->inner->run( $context );
			} catch ( \RuntimeException $e ) {
				if ( ! $this->repo->killed ) {
					throw $e;
				}
				$result = null;
			}
			$dead = false;
			if ( $this->repo->killed ) {
				$this->repo->killed = false;
				$dead               = true;
			} elseif ( null !== $result && $this->discards < 3 && isset( $result->state()->get_cursor()['o'] ) && $result->state()->get_cursor()['o'] > 0 ) {
				// The process died in the middle of a file, after the slice's work and before its cursor was saved: the partial temp file stays.
				++$this->discards;
				$dead = true;
			}
			if ( $dead ) {
				foreach ( ovb_r6_temps() as $temp ) {
					file_put_contents( $temp, 'GARBAGE FROM A DEAD RUN', FILE_APPEND );
				}
				return StepResult::wait( 1, $this->repo->load_state( RestoreMeta::restore_id( $context->state()->get_meta() ) ) );
			}
			return $result;
		}
	}

	/** A writer whose disk fills up after a number of chunks. */
	class OvbFullDiskWriter extends SafeWriter {
		public $calls = 0;
		public $limit = 30;
		public function write_chunk( $handle, $data ) {
			return ++$this->calls > $this->limit ? false : parent::write_chunk( $handle, $data );
		}
	}
}

/** Real backup of file contents (and optionally the database) through the engine. */
function ovb_r6_backup( array $contents, $format, $note ) {
	$repo = new BackupsRepository();
	$id   = $repo->create( array( 'note' => $note, 'contents' => $contents, 'format' => $format ) );
	$run  = new JobRunner( null, null, static function () {
		return new Budget( 600, PHP_INT_MAX );
	} );
	$run->start( $id, array() );
	for ( $i = 0; $i < 600; $i++ ) {
		ovb_clear_pending( $id );
		$out = $run->run( $id );
		if ( ! in_array( $out, array( 'continued', 'waiting' ), true ) ) {
			break;
		}
	}
	return $id;
}

/** Every file below a folder: relative path => sha256 (folders "dir", symlinks "link:<target>"). */
function ovb_r6_tree( $dir, array $skip_top = array(), $prefix = '' ) {
	$out = array();
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( '.' === $name || '..' === $name || ( '' === $prefix && in_array( $name, $skip_top, true ) ) ) {
			continue;
		}
		$path = $dir . '/' . $name;
		$rel  = '' === $prefix ? $name : $prefix . '/' . $name;
		if ( is_link( $path ) ) {
			$out[ $rel ] = 'link:' . readlink( $path );
		} elseif ( is_dir( $path ) ) {
			$out[ $rel ] = 'dir';
			$out         = array_merge( $out, ovb_r6_tree( $path, $skip_top, $rel ) );
		} else {
			$out[ $rel ] = hash_file( 'sha256', $path );
		}
	}
	ksort( $out );
	return $out;
}

/** The files of the site that a restore can touch: all of wp-content (without the storage folder and volatile files) and the files in the WordPress folder. */
function ovb_r6_state() {
	$local = basename( Paths::local_dir() );
	$state = array();
	foreach ( ovb_r6_tree( WP_CONTENT_DIR, array( $local, 'debug.log', 'upgrade', 'cache' ) ) as $k => $v ) {
		$state[ 'c/' . $k ] = $v;
	}
	$abs = rtrim( ABSPATH, '/' );
	foreach ( (array) scandir( $abs ) as $name ) {
		if ( '.' !== $name && '..' !== $name && is_file( $abs . '/' . $name ) && ! is_link( $abs . '/' . $name ) ) {
			$state[ 'a/' . $name ] = hash_file( 'sha256', $abs . '/' . $name );
		}
	}
	ksort( $state );
	return $state;
}

function ovb_r6_diff( array $a, array $b ) {
	$names = array();
	foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
		if ( ! isset( $a[ $k ] ) || ! isset( $b[ $k ] ) || $a[ $k ] !== $b[ $k ] ) {
			$names[] = $k;
		}
	}
	return $names;
}

function ovb_r6_temps() {
	$found = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
	foreach ( $it as $file ) {
		if ( false !== strpos( $file->getFilename(), '.ovbtmp' ) && ! $file->isLink() ) {
			$found[] = $file->getPathname();
		}
	}
	foreach ( (array) scandir( rtrim( ABSPATH, '/' ) ) as $name ) {
		if ( false !== strpos( $name, '.ovbtmp' ) ) {
			$found[] = rtrim( ABSPATH, '/' ) . '/' . $name;
		}
	}
	return array_values( array_unique( $found ) );
}

function ovb_r6_journal( $restore_id ) {
	return UndoStore::for_restore( $restore_id );
}

function ovb_r6_fixture( $escape_dir ) {
	$u = WP_CONTENT_DIR . '/uploads/ovbfs';
	$w = static function ( $path, $data ) {
		ovb_arc_put( $path, $data );
	};
	$w( $u . '/2026/05/photo.jpg', random_bytes( 300000 ) );
	$w( $u . '/2026/05/doc.txt', str_repeat( "Lorem ipsum dolor sit amet\n", 800 ) );
	$w( $u . '/日本語/😀 emoji name.txt', "unicode \u{1F600} content" );
	$w( $u . '/with space/a b.txt', 'space' );
	$w( $u . '/empty.txt', '' );
	ovb_arc_random_file( $u . '/big-25mb.bin', 25 );
	$w( $u . '/' . str_repeat( 'long-name-', 12 ) . '.txt', 'a file with a name of 120 characters' );
	$w( $u . '/script.php', '<?php // a PHP file in uploads is restored like any other file' );
	$w( WP_CONTENT_DIR . '/plugins/ovbfs-plugin/ovbfs-plugin.php', "<?php\n/* Plugin Name: OVB FS fixture */\n" );
	$w( WP_CONTENT_DIR . '/plugins/ovbfs-plugin/includes/a.php', '<?php // a' );
	$w( WP_CONTENT_DIR . '/themes/ovbfs-theme/style.css', "/*\nTheme Name: OVB FS\n*/" );
	$w( WP_CONTENT_DIR . '/themes/ovbfs-theme/functions.php', '<?php // functions' );
	$w( WP_CONTENT_DIR . '/mu-plugins/ovbfs-mu.php', '<?php // ovbfs mu' );
	$w( WP_CONTENT_DIR . '/ovbfs-other/data.txt', 'other content' );
	$w( WP_CONTENT_DIR . '/plugins/openvault-backup/ovbfs-canary.php', '<?php // canary-original' );
	ovb_arc_mkdir( $escape_dir );
	file_put_contents( $escape_dir . '/keep.txt', 'outside the site' );
}

function ovb_r6_remove_fixture() {
	foreach ( array( '/uploads/ovbfs', '/plugins/ovbfs-plugin', '/themes/ovbfs-theme', '/ovbfs-other' ) as $dir ) {
		CleanupStep::remove_tree( WP_CONTENT_DIR . $dir );
	}
	foreach ( (array) glob( WP_CONTENT_DIR . '/uploads/ovbfs-*' ) as $left ) {
		CleanupStep::remove_tree( $left );
	}
	@unlink( WP_CONTENT_DIR . '/mu-plugins/ovbfs-mu.php' );
	@unlink( WP_CONTENT_DIR . '/plugins/openvault-backup/ovbfs-canary.php' );
}

function ovb_r6_tar_block( $name, $type, $size = 0, $link = '' ) {
	$block = str_pad( substr( $name, 0, 100 ), 100, "\0" ) . sprintf( '%07o', 0644 ) . "\0" . sprintf( '%07o', 0 ) . "\0" . sprintf( '%07o', 0 ) . "\0" . sprintf( '%011o', $size ) . "\0" . sprintf( '%011o', 1700000000 ) . "\0" . '        ' . $type . str_pad( substr( $link, 0, 100 ), 100, "\0" ) . "ustar\0" . '00' . str_repeat( "\0", 32 ) . str_repeat( "\0", 32 ) . str_repeat( "\0", 16 ) . str_repeat( "\0", 155 ) . str_repeat( "\0", 12 );
	$sum   = array_sum( unpack( 'C*', $block ) );
	return substr( $block, 0, 148 ) . sprintf( '%06o', $sum ) . "\0 " . substr( $block, 156 );
}

/** Replace ALL file parts of a backup by one part holding $entries (name, body, type, link) and keep the manifest and the rows consistent, as an attacker with write access to the bucket would. */
function ovb_r6_repack_files( $backup_id, $format, array $entries, $declare_huge = false ) {
	global $wpdb;
	$folder        = ovb_r5_folder( $backup_id );
	$manifest_path = $folder . '/manifest.json';
	$data          = json_decode( file_get_contents( $manifest_path ), true );
	$keep          = null;
	$kept_parts    = array();
	foreach ( $data['parts'] as $part ) {
		if ( 'db' === $part['kind'] ) {
			$kept_parts[] = $part;
		} elseif ( null === $keep ) {
			$keep = $part;
		} else {
			@unlink( $folder . '/' . $part['name'] );
			$wpdb->delete( $wpdb->prefix . 'ovb_backup_files', array( 'backup_id' => $backup_id, 'file_name' => $part['name'] ) );
		}
	}
	$path = $folder . '/' . $keep['name'];
	@unlink( $path );
	if ( 'zip' === $format ) {
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		foreach ( $entries as $e ) {
			$type = isset( $e[2] ) ? $e[2] : 'file';
			$zip->addFromString( $e[0], 'symlink' === $type ? $e[3] : $e[1] );
			if ( 'symlink' === $type ) {
				$zip->setExternalAttributesIndex( $zip->count() - 1, ZipArchive::OPSYS_UNIX, 0120777 << 16 );
			}
		}
		$zip->close();
	} else {
		$tar = '';
		foreach ( $entries as $e ) {
			$type = isset( $e[2] ) ? $e[2] : 'file';
			if ( 'file' === $type ) {
				$tar .= TarWriter::entry_header( $e[0], strlen( $e[1] ), 1700000000 ) . $e[1] . str_repeat( "\0", TarWriter::padding( strlen( $e[1] ) ) );
			} else {
				$flags = array( 'symlink' => '2', 'hardlink' => '1', 'fifo' => '6' );
				$tar  .= ovb_r6_tar_block( $e[0], $flags[ $type ], 0, isset( $e[3] ) ? $e[3] : '' );
			}
		}
		if ( $declare_huge ) {
			$tar .= ovb_r6_tar_block( 'content/uploads/ovbfs-huge.bin', '0', TarWriter::MAX_OCTAL ) . str_repeat( 'a', 1000 );
		}
		file_put_contents( $path, gzencode( $tar ) . ( $declare_huge ? '' : gzencode( TarWriter::end_blocks() ) ) );
	}
	clearstatcache();
	$keep['size']   = filesize( $path );
	$keep['sha256'] = hash_file( 'sha256', $path );
	$kept_parts[]   = $keep;
	$data['parts']  = array_values( $kept_parts );
	file_put_contents( $manifest_path, wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
	clearstatcache();
	$table = $wpdb->prefix . 'ovb_backup_files';
	$wpdb->update( $table, array( 'size' => filesize( $path ), 'checksum_sha256' => hash_file( 'sha256', $path ) ), array( 'backup_id' => $backup_id, 'destination_id' => 0, 'file_name' => $keep['name'] ) );
	$wpdb->update( $table, array( 'size' => filesize( $manifest_path ), 'checksum_sha256' => hash_file( 'sha256', $manifest_path ) ), array( 'backup_id' => $backup_id, 'destination_id' => 0, 'file_name' => 'manifest.json' ) );
}

function ovb_r6_listing( $dir, $only = '' ) {
	$names = is_dir( $dir ) ? (array) scandir( $dir ) : array();
	if ( '' !== $only ) {
		$names = array_filter( $names, static function ( $n ) use ( $only ) {
			return 1 === preg_match( $only, $n );
		} );
	}
	$names = array_values( $names );
	sort( $names );
	return $names;
}

$r6_backups   = array();
$r6_restores  = array();
$r6_errors    = array();
$r6_prev_err  = set_error_handler( static function ( $no, $str, $file ) use ( &$r6_errors ) {
	if ( ! ( error_reporting() & $no ) ) {
		return false; // Silenced with @ on purpose.
	}
	if ( false !== strpos( (string) $file, 'openvault-backup' ) ) {
		$r6_errors[] = $str . ' @ ' . basename( (string) $file );
		return true;
	}
	return false;
} );
$r6_settings  = get_option( 'ovb_settings' );
$r6_active    = get_option( 'active_plugins' );
$r6_prefix    = $wpdb->prefix;
$r6_escape    = '/tmp/ovb-escape';
$r6_originals = array();
foreach ( array( 'wp-config.php', '.htaccess', 'readme.html', 'license.txt' ) as $r6_name ) {
	$r6_path = rtrim( ABSPATH, '/' ) . '/' . $r6_name;
	if ( is_file( $r6_path ) ) {
		$r6_originals[ $r6_name ] = file_get_contents( $r6_path );
	}
}
$r6_created_htaccess = false;
if ( ! isset( $r6_originals['.htaccess'] ) ) {
	// The matrix sets the permalinks before Apache runs, so there may be no .htaccess: make one for the test and remove it at the end.
	$r6_originals['.htaccess']  = "# BEGIN WordPress\n# END WordPress\n";
	file_put_contents( rtrim( ABSPATH, '/' ) . '/.htaccess', $r6_originals['.htaccess'] );
	$r6_created_htaccess = true;
}
$r6_mysqli   = null;
$r6_kill_filter = null;
$r6_disk_filter = null;

try {
	$settings                      = is_array( $r6_settings ) ? $r6_settings : array();
	$settings['min_free_space_mb'] = 0;
	$settings['part_size_mb']      = 10;
	$settings['max_file_size_mb']  = 0;
	$settings['excluded_paths']    = array(
		'content/plugins/akismet/*',
		'content/plugins/hello.php',
		'content/themes/twenty*',
		'content/plugins/openvault-backup/*/*',
		'abspath/wp-admin/*',
		'abspath/wp-includes/*',
		'*.log',
		'content/upgrade/*',
		'content/cache/*',
		'content/languages/*',
	);
	update_option( 'ovb_settings', $settings );

	$detector = new CapabilityDetector();
	$r6_formats = array();
	foreach ( array( 'zip', 'tar.gz' ) as $f ) {
		if ( $detector->is_available( $f ) ) {
			$r6_formats[] = $f;
		}
	}
	ovb_check( 'restore files: at least one archive format is available', array() !== $r6_formats );
	if ( array() === $r6_formats ) {
		throw new RuntimeException( 'no archive format' );
	}
	$main_format = $r6_formats[0];

	// ---------------------------------------------------------------- 20a. refusals that need no restore at all
	$strict = new RestoreManager();
	if ( is_multisite() ) {
		$backup_id    = ovb_r6_backup( array( 'db' => true ), $main_format, 'ovbfs multisite probe' );
		$r6_backups[] = $backup_id;
		foreach ( array( 'files', 'full' ) as $mode ) {
			$res = $strict->start( $backup_id, $mode, array( 'user_id' => 1 ) );
			ovb_check( "restore files: on a multisite network mode $mode is refused with a clear message and creates nothing", false === $res['ok'] && 0 === $res['restore_id'] && in_array( 'multisite_unsupported', array_column( $res['errors'], 'code' ), true ) && false !== strpos( implode( ' ', array_column( $res['errors'], 'message' ) ), 'multisite network is not supported yet' ), wp_json_encode( $res['errors'] ) );
		}
		ovb_check( 'restore files: the wreck, restore and undo checks are SKIPPED on multisite (a restore of a network is refused, asserted above)', true );
		throw new OvbR5Done();
	}

	$main_contents = array( 'plugins' => true, 'themes' => true, 'uploads' => true, 'mu_plugins' => true, 'other_content' => true, 'core' => true, 'wp_config' => true );
	$rrepo         = new RestoresRepository();
	$brepo         = new BackupsRepository();
	$clock         = 0.0;
	$tiny          = static function () use ( &$clock ) {
		return new Budget( 3, PHP_INT_MAX, static function () use ( &$clock ) {
			$clock += 1.0;
			return $clock;
		} );
	};
	$big           = static function () {
		return new Budget( 600, PHP_INT_MAX );
	};
	$rrunner       = new RestoreRunner( null, null, null, $tiny );
	$rmgr          = new RestoreManager( null, null, null, null, $rrunner );
	$frunner       = new RestoreRunner( null, null, null, $big );
	$fmgr          = new RestoreManager( null, null, null, null, $frunner );
	$local_dir     = Paths::local_dir();

	ovb_check( 'restore files: the protection self-test of the real site passes (with and without the server configuration opt-in)', '' === ExtractionPolicy::site_self_test( false ) && '' === ExtractionPolicy::site_self_test( true ) );
	ovb_check( 'restore files: the real site has the plugin folder and the storage folder among the probes', in_array( 'content/plugins/openvault-backup/x.php', ( function () use ( $local_dir ) {
		$all = array_fill_keys( OpenVault\Backup\Engine\Scan\ContentSelector::FLAGS, true );
		$p   = ExtractionPolicy::from_environment( $all, $local_dir );
		return $p->probe_entries( OpenVault\Backup\Engine\Scan\ContentSelector::from_environment( $local_dir )->roots( $all ) );
	} )(), true ) );

	// ---------------------------------------------------------------- 20b. the fixture and a first real backup
	ovb_r6_fixture( $r6_escape );
	$wp_config_path = rtrim( ABSPATH, '/' ) . '/wp-config.php';
	$htaccess_path  = rtrim( ABSPATH, '/' ) . '/.htaccess';
	$orig_state     = ovb_r6_state();
	ovb_check( 'restore files: the fixture exists (a 25 MB file, unicode and emoji names, an empty file, a long name, a canary in the plugin folder)', is_file( WP_CONTENT_DIR . '/uploads/ovbfs/big-25mb.bin' ) && 26214400 === filesize( WP_CONTENT_DIR . '/uploads/ovbfs/big-25mb.bin' ) && is_file( WP_CONTENT_DIR . '/uploads/ovbfs/日本語/😀 emoji name.txt' ) && 0 === filesize( WP_CONTENT_DIR . '/uploads/ovbfs/empty.txt' ) && is_file( WP_CONTENT_DIR . '/plugins/openvault-backup/ovbfs-canary.php' ) );

	$first_id     = ovb_r6_backup( $main_contents, $main_format, 'ovbfs probe' );
	$r6_backups[] = $first_id;
	$first        = $brepo->find( $first_id );
	ovb_check( 'restore files: a real files backup exists with several parts (the 25 MB file is alone in its part)', 'complete' === $first['status'] && count( ( new OpenVault\Backup\Database\BackupFilesRepository() )->for_backup( $first_id ) ) >= 4, $first['status'] );

	// Refusals on a real backup.
	$enc_path = ovb_r5_folder( $first_id ) . '/manifest.json';
	$orig_man = ovb_rs_edit_manifest( $enc_path, static function ( $m ) {
		$m['encrypted'] = true;
		return $m;
	} );
	$wpdb->update( $r6_prefix . 'ovb_backup_files', array( 'checksum_sha256' => hash_file( 'sha256', $enc_path ), 'size' => filesize( $enc_path ) ), array( 'backup_id' => $first_id, 'file_name' => 'manifest.json' ) );
	$res = $strict->start( $first_id, 'files', array( 'user_id' => 1 ) );
	ovb_check( 'restore files: an encrypted backup is still refused for mode files', false === $res['ok'] && in_array( 'encrypted', array_column( $res['errors'], 'code' ), true ), wp_json_encode( $res['errors'] ) );
	file_put_contents( $enc_path, $orig_man );
	clearstatcache();
	$wpdb->update( $r6_prefix . 'ovb_backup_files', array( 'checksum_sha256' => hash_file( 'sha256', $enc_path ), 'size' => filesize( $enc_path ) ), array( 'backup_id' => $first_id, 'file_name' => 'manifest.json' ) );
	$res = $strict->start( $first_id, 'undo', array( 'user_id' => 1 ) );
	ovb_check( 'restore files: "undo" is not a mode a user can start', false === $res['ok'] && 'mode' === $res['errors'][0]['code'] );
	$res = $strict->start( $first_id, 'files', array( 'user_id' => 1 ) );
	ovb_check( 'restore files: mode files is accepted by the strict (real) preflight and warns that wp-config.php, the server files and the plugin folder are not restored', true === $res['ok'] && $res['restore_id'] > 0 && array( 'wp_config_skipped', 'server_config_skipped', 'plugin_protected' ) === array_values( array_intersect( array( 'wp_config_skipped', 'server_config_skipped', 'plugin_protected' ), array_column( $res['warnings'], 'code' ) ) ), wp_json_encode( $res ) );
	if ( $res['restore_id'] > 0 ) {
		$r6_restores[] = $res['restore_id'];
		$plan_info = $res['plan'];
		ovb_check( 'restore files: the plan has no safety snapshot for mode files and a journal estimate', 0 === $plan_info['snapshot_bytes'] && $plan_info['journal_bytes'] > 0 && $plan_info['extract_bytes'] >= $plan_info['journal_bytes'], wp_json_encode( $plan_info ) );
		ovb_check( 'restore files: that job can be cancelled again before it was confirmed', $strict->cancel( $res['restore_id'] ) && 'cancelled' === $rrepo->find( $res['restore_id'] )['status'] );
	}

	// ---------------------------------------------------------------- scenario runner
	$r6_wreck = static function () use ( $wp_config_path, $htaccess_path, $r6_escape ) {
		$u = WP_CONTENT_DIR . '/uploads/ovbfs';
		file_put_contents( $u . '/2026/05/doc.txt', 'WRECKED' );                       // changed
		file_put_contents( $u . '/with space/a b.txt', 'WRECKED TOO' );               // changed (then hidden behind a symlink below)
		CleanupStep::remove_tree( $u . '/2026/05' );                                 // deleted with its folder
		CleanupStep::remove_tree( $u . '/日本語' );                                    // a folder replaced by a symlink out of the site
		symlink( $r6_escape, $u . '/日本語' );
		unlink( $u . '/big-25mb.bin' );                                              // deleted
		file_put_contents( $u . '/script.php', '<?php // wrecked' );
		file_put_contents( $u . '/new-after-backup.txt', 'added after the backup' ); // added
		file_put_contents( WP_CONTENT_DIR . '/plugins/ovbfs-plugin/added-later.php', '<?php // added later' );
		file_put_contents( WP_CONTENT_DIR . '/plugins/ovbfs-plugin/includes/a.php', '<?php // WRECKED' );
		CleanupStep::remove_tree( WP_CONTENT_DIR . '/themes/ovbfs-theme' );
		file_put_contents( WP_CONTENT_DIR . '/mu-plugins/ovbfs-mu.php', '<?php // WRECKED' );
		file_put_contents( WP_CONTENT_DIR . '/ovbfs-other/data.txt', 'WRECKED' );
		file_put_contents( WP_CONTENT_DIR . '/plugins/openvault-backup/ovbfs-canary.php', '<?php // canary-wrecked' );
		file_put_contents( rtrim( ABSPATH, '/' ) . '/readme.html', 'WRECKED README' );
		@unlink( rtrim( ABSPATH, '/' ) . '/license.txt' );
		file_put_contents( $wp_config_path, "\n// ovbfs-config-marker\n", FILE_APPEND );
		file_put_contents( $htaccess_path, "\n# ovbfs-htaccess-marker\n", FILE_APPEND );
	};

	$r6_expected_back = static function ( array $orig, array $now, $skip_prefixes ) {
		$bad = array();
		foreach ( $orig as $path => $hash ) {
			$skipped = false;
			foreach ( $skip_prefixes as $prefix ) {
				if ( 0 === strpos( $path, $prefix ) ) {
					$skipped = true;
				}
			}
			if ( ! $skipped && ( ! isset( $now[ $path ] ) || $now[ $path ] !== $hash ) ) {
				$bad[] = $path;
			}
		}
		return $bad;
	};

	$r6_run_scenario = function ( $fmt, $mode ) use ( &$r6_backups, &$r6_restores, $main_contents, $r6_wreck, $r6_expected_back, $rrunner, $rmgr, $frunner, $fmgr, $rrepo, $brepo, $r6_escape, $wp_config_path, $htaccess_path, $r6_prefix, &$r6_mysqli, $wpdb ) {
		$tag = "restore $mode ($fmt):";
		global $r6_marker_post;

		// Database fixture for mode full.
		$contents = $main_contents;
		if ( 'full' === $mode ) {
			$contents['db'] = true;
			update_option( 'ovbfs_marker', 'at-backup-time', false );
			$r6_marker_post = wp_insert_post( array( 'post_title' => 'ovbfs-marker-post', 'post_content' => 'at backup time', 'post_status' => 'publish' ) );
		}
		$snaps_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r6_prefix}ovb_backups WHERE trigger_type = 'pre-restore'" );
		$source_state = ovb_r6_state();
		$digests_src  = 'full' === $mode ? ovb_r5_digests( $r6_mysqli ) : array();
		$bid          = ovb_r6_backup( $contents, $fmt, "ovbfs source $fmt $mode" );
		$r6_backups[] = $bid;
		$brow         = $brepo->find( $bid );
		ovb_check( "$tag the source backup is complete in the requested format and holds the file parts" . ( 'full' === $mode ? ' and the database' : '' ), 'complete' === $brow['status'] && $fmt === $brow['format'], $brow['status'] );

		// The archive really contains the protected entries (they must be skipped by the restore, not missing from the backup).
		$entries = array();
		foreach ( ( new OpenVault\Backup\Database\BackupFilesRepository() )->for_backup( $bid ) as $file ) {
			$kind = OpenVault\Backup\Archive\ArchiveReader::kind_for( $file['file_name'] );
			if ( '' === $kind || 0 === strpos( $file['file_name'], 'db.' ) ) {
				continue;
			}
			$w = EntryWalker::open( $kind, ovb_r5_folder( $bid ) . '/' . $file['file_name'] );
			while ( null !== ( $e = $w->next() ) ) {
				$entries[] = $e['name'];
				$w->skip_body();
			}
			$w->close();
		}
		ovb_check( "$tag the backup holds entries for wp-config.php, .htaccess, the canary in this plugin's own folder and the 25 MB file", in_array( 'abspath/wp-config.php', $entries, true ) && in_array( 'abspath/.htaccess', $entries, true ) && in_array( 'content/plugins/openvault-backup/ovbfs-canary.php', $entries, true ) && in_array( 'content/uploads/ovbfs/big-25mb.bin', $entries, true ), 'entries: ' . count( $entries ) );

		// WRECK.
		$r6_wreck();
		if ( 'full' === $mode ) {
			update_option( 'ovbfs_marker', 'wrecked-value', false );
			wp_delete_post( $r6_marker_post, true );
			$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$r6_prefix}ovbfs_extra` (id INT NOT NULL, v VARCHAR(20) NULL, PRIMARY KEY (id)) ENGINE=InnoDB" );
			$wpdb->query( "INSERT IGNORE INTO `{$r6_prefix}ovbfs_extra` VALUES (1, 'made after backup')" );
			wp_cache_flush();
		}
		$wrecked_state   = ovb_r6_state();
		$wrecked_digests = 'full' === $mode ? ovb_r5_digests( $r6_mysqli ) : array();
		$wrecked_options = 'full' === $mode ? ovb_r5_option_rows() : array();
		ovb_check( "$tag the site is really wrecked (files changed, deleted and added, a folder replaced by a symlink)", $wrecked_state !== $source_state && ! is_file( WP_CONTENT_DIR . '/uploads/ovbfs/big-25mb.bin' ) && is_link( WP_CONTENT_DIR . '/uploads/ovbfs/日本語' ) );
		$local_before = ovb_r6_tree( Paths::local_dir() . '/backups' );

		// Start, verify, confirm. The tiny budget forces many slices.
		$start = $rmgr->start( $bid, $mode, array( 'user_id' => 1 ) );
		ovb_check( "$tag start() accepts the restore", true === $start['ok'] && $start['restore_id'] > 0, wp_json_encode( $start['errors'] ) . wp_json_encode( $start['requires_ack'] ) );
		$rid           = $start['restore_id'];
		$r6_restores[] = $rid;
		$out1          = ovb_r5_drive( $rrunner, $rid );
		$row           = $rrepo->find( $rid );
		ovb_check( "$tag the job parks at verified and has changed nothing so far", 'verified' === end( $out1 ) && 'verified' === $row['status'] && $wrecked_state === ovb_r6_state() && ! ( new MaintenanceMode() )->is_active() && ! UndoStore::for_restore( $rid )->exists(), wp_json_encode( array_count_values( $out1 ) ) );
		$meta1 = $rrepo->load_state( $rid )->get_meta();
		ovb_check( "$tag the job knows what it changes (files" . ( 'full' === $mode ? ' and database' : ' only' ) . ')', RestoreMeta::does_files( $meta1 ) && RestoreMeta::does_db( $meta1 ) === ( 'full' === $mode ), wp_json_encode( $meta1 ) );

		$obs  = array( 'apply_seen' => 0, 'maintenance' => null, 'cancel_refused' => null, 'refusal' => '', 'max_cursor' => 0, 'max_state' => 0, 'setup_seen' => false, 'steps' => array(), 'gate_front' => null );
		$each = static function ( $out, $i, $state ) use ( &$obs, $rmgr, $rid ) {
			if ( null === $state ) {
				return;
			}
			$cursor = $state->get_cursor();
			$obs['max_cursor'] = max( $obs['max_cursor'], strlen( wp_json_encode( $cursor ) ) );
			$obs['max_state']  = max( $obs['max_state'], strlen( $state->to_json() ) );
			$obs['steps'][ $state->get_step() ] = true;
			if ( 'file_restore' === $state->get_step() && isset( $cursor['ph'] ) && 'a' === $cursor['ph'] ) {
				++$obs['apply_seen'];
				if ( 1 === $obs['apply_seen'] ) {
					$obs['maintenance']    = ( new MaintenanceMode() )->is_active();
					$obs['cancel_refused'] = false === $rmgr->cancel( $rid );
					$obs['refusal']        = $rmgr->cancel_refusal( $rid );
					$obs['gate_front']     = MaintenanceMode::decide( MaintenanceMode::facts_from_request( array( 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/' ), array(), false, false ) );
				}
			}
		};
		$confirm = $rmgr->confirm( $rid );
		ovb_check( "$tag confirm() accepts the verified restore", true === $confirm['ok'] && RestoreMeta::confirmed( $rrepo->load_state( $rid )->get_meta() ), wp_json_encode( $confirm ) );
		$out2 = ovb_r5_drive( $rrunner, $rid, $each );
		$row  = $rrepo->find( $rid );
		$st   = $rrepo->load_state( $rid );
		$snap_id = (int) $row['snapshot_backup_id'];
		if ( $snap_id > 0 ) {
			$r6_backups[] = $snap_id;
		}
		ovb_check( sprintf( "$tag the job completes over %d slices", count( $out2 ) ), 'complete' === $row['status'] && 'complete' === end( $out2 ) && count( $out2 ) > 25 && ! in_array( 'aborted', $out2, true ), wp_json_encode( array_count_values( $out2 ) ) . ' ' . $row['status'] . ' ' . (string) $row['error'] );
		ovb_check( "$tag the stored cursor stays tiny (max {$obs['max_cursor']} bytes) and so does the state ({$obs['max_state']} bytes)", $obs['max_cursor'] < 500 && $obs['max_state'] < 4000 );
		ovb_check( "$tag the file steps ran as separate steps of the job and " . ( 'full' === $mode ? 'the database restore and the safety snapshot ran first' : 'no safety snapshot was taken (the undo journal is the recoverable state)' ), isset( $obs['steps']['file_restore'] ) && ( 'full' === $mode ? isset( $obs['steps']['db_restore'], $obs['steps']['snapshot'] ) && $snap_id > 0 : ( ! isset( $obs['steps']['snapshot'] ) && ! isset( $obs['steps']['db_restore'] ) ) && 0 === $snap_id && $snaps_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r6_prefix}ovb_backups WHERE trigger_type = 'pre-restore'" ) ), wp_json_encode( $obs['steps'] ) . ' snapshot=' . $snap_id );
		ovb_check( "$tag maintenance mode was on while files were written, the front end was blocked, and cancel was refused with a message that names Undo restore", true === $obs['maintenance'] && MaintenanceMode::DECISION_BLOCK === $obs['gate_front'] && true === $obs['cancel_refused'] && false !== strpos( (string) $obs['refusal'], 'Undo restore' ), wp_json_encode( $obs ) );

		// The result.
		$after_state = ovb_r6_state();
		$meta        = $st->get_meta();
		$skip_hidden = array( 'c/uploads/ovbfs/日本語', 'c/plugins/openvault-backup/', 'a/wp-config.php', 'a/.htaccess' );
		$bad         = $r6_expected_back( $source_state, $after_state, $skip_hidden );
		ovb_check( "$tag every backed-up file is byte identical (sha256) to the original, except the protected ones and those behind the symlink", array() === $bad, implode( ',', array_slice( $bad, 0, 5 ) ) );
		ovb_check( "$tag the 25 MB file, the unicode and emoji names, the empty file and the long name are back", $source_state['c/uploads/ovbfs/big-25mb.bin'] === $after_state['c/uploads/ovbfs/big-25mb.bin'] && $source_state['c/uploads/ovbfs/2026/05/photo.jpg'] === $after_state['c/uploads/ovbfs/2026/05/photo.jpg'] && '' !== $after_state['c/uploads/ovbfs/empty.txt'] && is_file( WP_CONTENT_DIR . '/uploads/ovbfs/' . str_repeat( 'long-name-', 12 ) . '.txt' ) );
		ovb_check( "$tag the wrecked files, the deleted folder tree, the theme and the core files are back", 'other content' === file_get_contents( WP_CONTENT_DIR . '/ovbfs-other/data.txt' ) && is_file( WP_CONTENT_DIR . '/themes/ovbfs-theme/style.css' ) && '<?php // a' === file_get_contents( WP_CONTENT_DIR . '/plugins/ovbfs-plugin/includes/a.php' ) && is_file( rtrim( ABSPATH, '/' ) . '/license.txt' ) && 'WRECKED README' !== file_get_contents( rtrim( ABSPATH, '/' ) . '/readme.html' ) );
		ovb_check( "$tag files added after the backup are untouched (nothing is deleted by a restore)", 'added after the backup' === file_get_contents( WP_CONTENT_DIR . '/uploads/ovbfs/new-after-backup.txt' ) && '<?php // added later' === file_get_contents( WP_CONTENT_DIR . '/plugins/ovbfs-plugin/added-later.php' ) );
		ovb_check( "$tag the symlinked folder is refused and reported: the target received NOTHING, the link is still there, the file behind the first symlink was not written", array( 'keep.txt' ) === array_values( array_diff( ovb_r6_listing( $r6_escape ), array( '.', '..' ) ) ) && 'outside the site' === file_get_contents( $r6_escape . '/keep.txt' ) && is_link( WP_CONTENT_DIR . '/uploads/ovbfs/日本語' ) && readlink( WP_CONTENT_DIR . '/uploads/ovbfs/日本語' ) === $r6_escape && ! empty( $meta['files']['skipped']['dest_symlink'] ), wp_json_encode( isset( $meta['files'] ) ? $meta['files'] : null ) );
		ovb_check( "$tag the plugin's own folder is untouched: the canary the wreck changed is NOT restored, although the backup holds it", '<?php // canary-wrecked' === file_get_contents( WP_CONTENT_DIR . '/plugins/openvault-backup/ovbfs-canary.php' ) && ! empty( $meta['files']['skipped']['protected_plugin'] ), wp_json_encode( isset( $meta['files']['skipped'] ) ? $meta['files']['skipped'] : null ) );
		ovb_check( "$tag wp-config.php and .htaccess are untouched although the backup holds them (the markers added after the backup are still there), and the result counts them", false !== strpos( file_get_contents( $wp_config_path ), 'ovbfs-config-marker' ) && false !== strpos( file_get_contents( $htaccess_path ), 'ovbfs-htaccess-marker' ) && 1 === $meta['files']['skipped']['protected_config'] && 1 === $meta['files']['skipped']['protected_server'], wp_json_encode( $meta['files']['skipped'] ) );
		$local_after = ovb_r6_tree( Paths::local_dir() . '/backups' );
		$local_bad   = array();
		foreach ( $local_before as $k => $v ) {
			if ( ! isset( $local_after[ $k ] ) || $local_after[ $k ] !== $v ) {
				$local_bad[] = $k;
			}
		}
		ovb_check( "$tag the storage folder 'backups' is untouched (" . ( 'full' === $mode ? 'the safety snapshot is a new backup next to them' : 'nothing new either' ) . ") and the temp files are gone", array() === $local_bad && ( 'full' === $mode || $local_before === $local_after ) && array() === ovb_r6_temps(), implode( ',', array_merge( $local_bad, ovb_r6_temps() ) ) );
		ovb_check( "$tag the result summary holds the counts (restored, created, overwritten, bytes, skipped by reason) and whether a journal exists", isset( $meta['result']['files'] ) && $meta['result']['files']['restored'] > 20 && $meta['result']['files']['overwritten'] > 5 && $meta['result']['files']['created'] > 3 && $meta['result']['files']['bytes'] > 25000000 && true === $meta['result']['journal'] && $meta['result']['mode'] === $mode, wp_json_encode( isset( $meta['result'] ) ? $meta['result'] : null ) );
		ovb_check( "$tag maintenance mode is off, the lock is released, nothing is queued, the work folder is gone", ! ( new MaintenanceMode() )->is_active() && null === ovb_rs_lock_value() && 0 === ovb_pending_count( RestoreRunner::ACTION, array( (int) $rid ) ) && ! file_exists( ovb_rs_work( $rid ) ) );

		// The journal.
		$store = UndoStore::for_restore( $rid );
		$lines = $store->exists() ? explode( "\n", rtrim( file_get_contents( $store->journal_path() ), "\n" ) ) : array();
		$ops   = array_count_values( array_map( static function ( $l ) {
			return substr( $l, 0, 1 );
		}, $lines ) );
		ovb_check( "$tag the journal exists in the protected storage folder, starts with a header and matches the actions (O = overwritten, C = created, D = folders)", $store->exists() && 0 === strpos( $lines[0], "H\t1\t$rid\t" ) && ( isset( $ops['O'] ) ? $ops['O'] : 0 ) === $meta['files']['overwritten'] && ( isset( $ops['C'] ) ? $ops['C'] : 0 ) === $meta['files']['created'] && ! empty( $ops['D'] ), wp_json_encode( $ops ) . ' ' . wp_json_encode( $meta['files'] ) );
		$bad_lines = 0;
		$bad_saved = 0;
		foreach ( $lines as $line ) {
			$p = UndoJournal::parse_line( $line );
			if ( null === $p ) {
				++$bad_lines;
			} elseif ( 'O' === $p['op'] ) {
				$saved = $store->saved_path( $p['entry'] );
				if ( ! is_file( $saved ) || filesize( $saved ) !== $p['size'] || hash_file( 'sha256', $saved ) !== $p['sha256'] ) {
					++$bad_saved;
				}
			}
		}
		ovb_check( "$tag every journal line parses and every saved original matches its size and sha256", 0 === $bad_lines && 0 === $bad_saved, "bad_lines=$bad_lines bad_saved=$bad_saved" );
		ovb_check( "$tag the undo folder is inside the protected storage folder with guard files and the storage folder is still never archived", 0 === strpos( $store->dir(), Paths::local_dir() . '/undo/' ) && is_file( Paths::local_dir() . '/undo/index.php' ) && is_file( Paths::local_dir() . '/undo/.htaccess' ) );

		if ( 'full' === $mode ) {
			$digests_after = ovb_r5_digests( $r6_mysqli );
			$bad_tables    = ovb_r5_diff( $digests_src, $digests_after );
			$bad_tables    = array_values( array_diff( $bad_tables, array( $r6_prefix . 'ovbfs_extra' ) ) );
			ovb_check( "$tag the database is back to the backup (every table identical, the marker option and the post), the table made after the backup stays", array() === $bad_tables && 'at-backup-time' === get_option( 'ovbfs_marker' ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'ovbfs-marker-post'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$r6_prefix}ovbfs_extra`" ), implode( ',', $bad_tables ) );
			ovb_check( "$tag the safety snapshot is a locked database backup of the pre-restore state and this plugin is still active", $snap_id > 0 && true === $brepo->find( $snap_id )['locked'] && 'complete' === $brepo->find( $snap_id )['status'] && in_array( plugin_basename( OVB_FILE ), (array) get_option( 'active_plugins' ), true ) );
		}

		// UNDO.
		$can = $fmgr->can_undo( $rid );
		ovb_check( "$tag can_undo() says yes and what an undo does", true === $can['ok'] && $can['undo_files'] && $can['undo_db'] === ( 'full' === $mode ), wp_json_encode( $can ) );
		$undo = $rmgr->undo( $rid, array( 'user_id' => 1 ) );
		ovb_check( "$tag undo() starts a new job of mode undo", true === $undo['ok'] && $undo['restore_id'] > $rid, wp_json_encode( $undo ) );
		$uid           = $undo['restore_id'];
		$r6_restores[] = $uid;
		ovb_check( "$tag a second undo cannot start while the first one runs", false === $rmgr->undo( $rid )['ok'] && 'restore_running' === $rmgr->undo( $rid )['errors'][0]['code'] );
		$uobs = array( 'maint' => null, 'cancel' => null );
		$ueach = static function ( $out, $i, $state ) use ( &$uobs, $rmgr, $uid ) {
			if ( null !== $state && 'file_undo' === $state->get_step() && isset( $state->get_cursor()['ph'] ) && 'a' === $state->get_cursor()['ph'] && null === $uobs['maint'] ) {
				$uobs['maint']  = ( new MaintenanceMode() )->is_active();
				$uobs['cancel'] = false === $rmgr->cancel( $uid );
			}
		};
		$uout = ovb_r5_drive( $rrunner, $uid, $ueach );
		$urow = $rrepo->find( $uid );
		ovb_check( sprintf( "$tag the undo completes over %d slices; maintenance was on during it and its cancel was refused", count( $uout ) ), 'complete' === $urow['status'] && count( $uout ) > 5 && true === $uobs['maint'] && true === $uobs['cancel'], $urow['status'] . ' ' . (string) $urow['error'] . ' ' . wp_json_encode( $uobs ) );
		$ustate = $rrepo->load_state( $uid )->get_meta();
		$final  = ovb_r6_state();
		$bad    = ovb_r6_diff( $wrecked_state, $final );
		ovb_check( "$tag after the undo the site is EXACTLY what it was just before the restore (every file, byte for byte; created files and folders are gone)", array() === $bad, implode( ',', array_slice( $bad, 0, 8 ) ) );
		ovb_check( "$tag the undo result counts what was put back and removed, the original is marked undone and the journal stays until it is dismissed", isset( $ustate['undo_files'] ) && $ustate['undo_files']['restored'] === $meta['files']['overwritten'] && $ustate['undo_files']['removed'] === $meta['files']['created'] && 0 === $ustate['undo_files']['errors'] && $ustate['result']['undo_of'] === $rid && RestoreMeta::undone_by( $rrepo->load_state( $rid )->get_meta() ) === $uid && UndoStore::for_restore( $rid )->exists(), wp_json_encode( isset( $ustate['undo_files'] ) ? $ustate['undo_files'] : null ) );
		ovb_check( "$tag the symlink and what it points to are exactly as before, the target is still empty of restore files", is_link( WP_CONTENT_DIR . '/uploads/ovbfs/日本語' ) && array( 'keep.txt' ) === array_values( array_diff( ovb_r6_listing( $r6_escape ), array( '.', '..' ) ) ) );
		ovb_check( "$tag maintenance mode is off after the undo and no job is left", ! ( new MaintenanceMode() )->is_active() && null === ovb_rs_lock_value() && array() === ovb_r6_temps() );
		if ( 'full' === $mode ) {
			$bad_tables = ovb_r5_diff( $wrecked_digests, ovb_r5_digests( $r6_mysqli ) );
			$bad_opts   = ovb_r5_diff( $wrecked_options, ovb_r5_option_rows() );
			ovb_check( "$tag the undo restored the database of just before the restore from the safety snapshot (every table, the options, the wrecked marker value, the table made after the backup)", array() === $bad_tables && array() === $bad_opts && 'wrecked-value' === get_option( 'ovbfs_marker' ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$r6_prefix}ovbfs_extra`" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'ovbfs-marker-post'" ), implode( ',', $bad_tables ) . ' | ' . implode( ',', $bad_opts ) );
			ovb_check( "$tag this plugin is still active and its job rows survived both database restores", in_array( plugin_basename( OVB_FILE ), (array) get_option( 'active_plugins' ), true ) && 'complete' === $rrepo->find( $rid )['status'] && 'complete' === $rrepo->find( $uid )['status'] );
		}
		$again = $fmgr->undo( $rid );
		ovb_check( "$tag a restore cannot be undone twice", false === $again['ok'] && 'already_undone' === $again['errors'][0]['code'], wp_json_encode( $again['errors'] ) );
		$can2 = $fmgr->can_undo( $uid );
		ovb_check( "$tag an undo job itself cannot be undone", false === $can2['ok'] && 'not_undoable' === $can2['code'] );

		// Dismiss: the journal goes, the restore can no longer be undone.
		$dismiss = $fmgr->dismiss_undo( $rid );
		ovb_check( "$tag dismiss_undo() deletes the journal and the saved copies", true === $dismiss['ok'] && ! file_exists( UndoStore::build( Paths::local_dir(), $rid ) ) && is_dir( Paths::local_dir() . '/undo' ), wp_json_encode( $dismiss ) );
		ovb_check( "$tag after that the restore cannot be undone any more and dismissing again is harmless", 'already_undone' === $fmgr->can_undo( $rid )['code'] && true === $fmgr->dismiss_undo( $rid )['ok'] );

		// Back to the wrecked-free state for the next scenario.
		ovb_r6_remove_fixture();
		@unlink( WP_CONTENT_DIR . '/uploads/ovbfs-x' );
		foreach ( array( 'wp-config.php', '.htaccess', 'readme.html', 'license.txt' ) as $name ) {
			global $r6_originals;
			if ( isset( $r6_originals[ $name ] ) ) {
				file_put_contents( rtrim( ABSPATH, '/' ) . '/' . $name, $r6_originals[ $name ] );
			}
		}
		if ( 'full' === $mode ) {
			wp_delete_post( $r6_marker_post, true );
			delete_option( 'ovbfs_marker' );
			$wpdb->query( "DROP TABLE IF EXISTS `{$r6_prefix}ovbfs_extra`" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_title = 'ovbfs-marker-post'" );
		}
		wp_cache_flush();
		return $bid;
	};

	$GLOBALS['r6_originals'] = $r6_originals;
	$r6_mysqli = ovb_open_mysqli( $wpdb, DB_NAME );
	if ( null === $r6_mysqli ) {
		throw new RuntimeException( 'no second connection' );
	}
	$err = array();
	ovb_exec( $r6_mysqli, "SET sql_mode=''", $err );
	ovb_exec( $r6_mysqli, "SET time_zone = '+00:00'", $err );

	// ---------------------------------------------------------------- 20c. wreck, restore, undo for every format and mode
	$last_backup = 0;
	foreach ( $r6_formats as $fmt ) {
		foreach ( array( 'files', 'full' ) as $mode ) {
			ovb_r6_remove_fixture();
			ovb_r6_fixture( $r6_escape );
			$last_backup = $r6_run_scenario( $fmt, $mode );
		}
	}
	CleanupStep::remove_tree( $r6_escape );

	// ---------------------------------------------------------------- 20d. kills in the middle of the file restore
	ovb_r6_remove_fixture();
	ovb_r6_fixture( $r6_escape );
	$kill_source    = ovb_r6_state();
	$kill_bid       = ovb_r6_backup( $main_contents, $main_format, 'ovbfs kill source' );
	$r6_backups[]   = $kill_bid;
	$r6_wreck();
	$kill_wrecked   = ovb_r6_state();
	$kill_repo      = new OvbKillFilesRepo();
	$kill_repo->kill_at = array( 10, 22, 36 );
	$kill_step      = new OvbKillFilesStep( new FileRestoreStep( null, null, $kill_repo, null, null, 1 ), $kill_repo );
	$r6_kill_filter = static function ( $steps ) use ( $kill_step ) {
		$steps['file_restore'] = $kill_step;
		return $steps;
	};
	add_filter( 'ovb_restore_steps', $r6_kill_filter );
	$kstart = $rmgr->start( $kill_bid, 'files', array( 'user_id' => 1 ) );
	$kid    = $kstart['restore_id'];
	$r6_restores[] = $kid;
	ovb_r5_drive( $rrunner, $kid );
	$rmgr->confirm( $kid );
	$kout = ovb_r5_drive( $rrunner, $kid );
	remove_filter( 'ovb_restore_steps', $r6_kill_filter );
	$krow = $rrepo->find( $kid );
	ovb_check( sprintf( 'restore files kill: the file restore was "killed" %d times between files and %d times in the middle of a file (garbage left in the temp files) and the job still completed', $kill_repo->kills, $kill_step->discards ), 3 === $kill_repo->kills && 3 === $kill_step->discards && 'complete' === $krow['status'], $krow['status'] . ' ' . (string) $krow['error'] . ' kills=' . $kill_repo->kills . ' count=' . $kill_repo->count . ' ' . wp_json_encode( array_count_values( $kout ) ) );
	$kafter = ovb_r6_state();
	ovb_check( 'restore files kill: no temp file is left and every backed-up file is byte identical to the backup (nothing corrupted by the dead runs)', array() === ovb_r6_temps() && array() === $r6_expected_back( $kill_source, $kafter, array( 'c/uploads/ovbfs/日本語', 'c/plugins/openvault-backup/', 'a/wp-config.php', 'a/.htaccess' ) ), implode( ',', array_merge( ovb_r6_temps(), array_slice( $r6_expected_back( $kill_source, $kafter, array( 'c/uploads/ovbfs/日本語', 'c/plugins/openvault-backup/', 'a/wp-config.php', 'a/.htaccess' ) ), 0, 5 ) ) ) );
	$kundo = $rmgr->undo( $kid, array( 'user_id' => 1 ) );
	$r6_restores[] = $kundo['restore_id'];
	ovb_r5_drive( $rrunner, $kundo['restore_id'] );
	$kbad = ovb_r6_diff( $kill_wrecked, ovb_r6_state() );
	ovb_check( 'restore files kill: the undo after the killed runs still gives back EXACTLY the pre-restore site (the first saved original was kept, no journal damage)', true === $kundo['ok'] && 'complete' === $rrepo->find( $kundo['restore_id'] )['status'] && array() === $kbad, ( $kundo['ok'] ? $rrepo->find( $kundo['restore_id'] )['status'] . ' ' . (string) $rrepo->find( $kundo['restore_id'] )['error'] : wp_json_encode( $kundo['errors'] ) ) . ' ' . implode( ',', array_slice( $kbad, 0, 6 ) ) );

	// ---------------------------------------------------------------- 20e. a files restore with the server configuration opt-in
	$wreck_cfg = static function () use ( $htaccess_path, $wp_config_path ) {
		file_put_contents( $htaccess_path, "\n# ovbfs-htaccess-marker-2\n", FILE_APPEND );
		file_put_contents( $wp_config_path, "\n// ovbfs-config-marker-2\n", FILE_APPEND );
	};
	foreach ( array( $htaccess_path => $r6_originals['.htaccess'], $wp_config_path => $r6_originals['wp-config.php'] ) as $p => $content ) {
		file_put_contents( $p, $content );
	}
	ovb_r6_remove_fixture();
	ovb_r6_fixture( $r6_escape );
	$cfg_bid      = ovb_r6_backup( array( 'core' => true, 'wp_config' => true, 'mu_plugins' => true ), $main_format, 'ovbfs server config' );
	$r6_backups[] = $cfg_bid;
	$wreck_cfg();
	$res = $fmgr->start( $cfg_bid, 'files', array( 'user_id' => 1, 'restore_server_config' => true ) );
	ovb_check( 'restore files server config: the opt-in is accepted, remembered in the job meta and the "server files are skipped" warning is gone', true === $res['ok'] && RestoreMeta::server_config( $rrepo->load_state( $res['restore_id'] )->get_meta() ) && ! in_array( 'server_config_skipped', array_column( $res['warnings'], 'code' ), true ) && in_array( 'wp_config_skipped', array_column( $res['warnings'], 'code' ), true ), wp_json_encode( $res ) );
	$cid           = $res['restore_id'];
	$r6_restores[] = $cid;
	ovb_r5_drive( $frunner, $cid );
	$fmgr->confirm( $cid );
	ovb_r5_drive( $frunner, $cid );
	$crow = $rrepo->find( $cid );
	ovb_check( 'restore files server config: with the opt-in the .htaccess of the backup comes back, but wp-config.php is still never restored', 'complete' === $crow['status'] && $r6_originals['.htaccess'] === file_get_contents( $htaccess_path ) && false !== strpos( file_get_contents( $wp_config_path ), 'ovbfs-config-marker-2' ), $crow['status'] . ' ' . (string) $crow['error'] );
	foreach ( array( $htaccess_path => $r6_originals['.htaccess'], $wp_config_path => $r6_originals['wp-config.php'] ) as $p => $content ) {
		file_put_contents( $p, $content );
	}

	// ---------------------------------------------------------------- 20f. hostile archives through a real backup row, manifest and checksums
	foreach ( $r6_formats as $fmt ) {
		$tag = "restore hostile ($fmt):";
		ovb_r6_remove_fixture();
		ovb_r6_fixture( $r6_escape );
		file_put_contents( $r6_escape . '/victim.txt', 'precious' );
		$hostile_bid  = ovb_r6_backup( $main_contents, $fmt, "ovbfs hostile $fmt" );
		$r6_backups[] = $hostile_bid;
		$localname    = basename( Paths::local_dir() );
		$entries      = array(
			array( 'content/uploads/../../ovbfs-zipslip.txt', 'ZIPSLIP', 'file' ),
			array( 'content/uploads/a/../../../../tmp/ovb-zipslip2.txt', 'ZIPSLIP', 'file' ),
			array( '/tmp/ovb-abs-evil.txt', 'ABSOLUTE', 'file' ),
			array( 'C:\\ovb-drive.txt', 'DRIVE', 'file' ),
			array( 'content/uploads/..\\ovb-back.txt', 'BACKSLASH', 'file' ),
			array( 'content/uploads/ovbfs-linkentry', '', 'symlink', $r6_escape ),
			array( 'content/uploads/ovbfs-link/pwn.txt', 'THROUGH A LINK', 'file' ),
			array( 'content/uploads/ovbfs-linkfile.txt', 'OVERWRITE THROUGH A LINK', 'file' ),
			array( 'content/uploads/ovbfs-ok.txt', 'fine', 'file' ),
			array( 'content/plugins/openvault-backup/ovbfs-canary.php', 'EVIL', 'file' ),
			array( 'content/plugins/OpenVault-Backup/ovbfs-case.php', 'EVIL', 'file' ),
			array( 'content/' . $localname . '/backups/evil.zip', 'EVIL', 'file' ),
			array( 'content/ovb-other/backups/evil.zip', 'EVIL', 'file' ),
			array( 'abspath/wp-config.php', 'EVIL CONFIG', 'file' ),
			array( 'abspath/.htaccess', 'EVIL', 'file' ),
			array( 'abspath/.maintenance', '<?php $upgrading = time();', 'file' ),
			array( "content/uploads/\u{202E}gpj.php", 'RTL', 'file' ),
			array( 'content/uploads/ovbfs-trailing.', 'DOT', 'file' ),
			array( 'content/uploads/ovbfs-in-the-way/x.txt', 'X', 'file' ),
			array( 'content/uploads/ovbfs-dir-in-the-way', 'X', 'file' ),
			array( 'elsewhere/x.txt', 'UNOWNED', 'file' ),
		);
		if ( 'zip' !== $fmt ) {
			$entries[] = array( "content/uploads/ovbfs-nul\0byte.php", 'NUL', 'file' );
			$entries[] = array( 'content/uploads/ovbfs-pipe', '', 'fifo' );
			$entries[] = array( 'content/uploads/ovbfs-hard', '', 'hardlink', 'content/uploads/ovbfs/empty.txt' );
		}
		ovb_r6_repack_files( $hostile_bid, $fmt, $entries );

		// Traps in the destination tree.
		$u = WP_CONTENT_DIR . '/uploads';
		symlink( $r6_escape, $u . '/ovbfs-link' );
		symlink( $r6_escape . '/victim.txt', $u . '/ovbfs-linkfile.txt' );
		file_put_contents( $u . '/ovbfs-in-the-way', 'a file' );
		mkdir( $u . '/ovbfs-dir-in-the-way' );

		$before_state = ovb_r6_state();
		$before_tmp   = ovb_r6_listing( '/tmp', '/ovb|evil|zipslip|escape|pwn/i' );
		$before_abs   = ovb_r6_listing( rtrim( ABSPATH, '/' ) );
		$before_up    = ovb_r6_listing( dirname( rtrim( ABSPATH, '/' ) ) );
		$before_content = ovb_r6_listing( WP_CONTENT_DIR );
		$before_escape  = ovb_r6_listing( $r6_escape );

		$hs = $fmgr->start( $hostile_bid, 'files', array( 'user_id' => 1 ) );
		ovb_check( "$tag the preflight accepts the backup (the checksums and the manifest are consistent: only the content is hostile)", true === $hs['ok'] && $hs['restore_id'] > 0, wp_json_encode( $hs['errors'] ) );
		$hid           = $hs['restore_id'];
		$r6_restores[] = $hid;
		ovb_r5_drive( $frunner, $hid );
		$fmgr->confirm( $hid );
		ovb_r5_drive( $frunner, $hid );
		$hrow  = $rrepo->find( $hid );
		$hmeta = $rrepo->load_state( $hid )->get_meta();
		$sk    = isset( $hmeta['files']['skipped'] ) ? $hmeta['files']['skipped'] : array();
		ovb_check( "$tag the job completes (a hostile entry is skipped, it does not fail the restore)", 'complete' === $hrow['status'], $hrow['status'] . ' ' . (string) $hrow['error'] );

		$after_state = ovb_r6_state();
		$added       = array_keys( array_diff_key( $after_state, $before_state ) );
		$changed     = array();
		foreach ( $before_state as $path => $hash ) {
			if ( isset( $after_state[ $path ] ) && $after_state[ $path ] !== $hash ) {
				$changed[] = $path;
			}
		}
		ovb_check( "$tag the only new file in wp-content and the WordPress folder is the harmless one, and nothing that existed was changed", array( 'c/uploads/ovbfs-ok.txt' ) === $added && array() === $changed && 'fine' === file_get_contents( WP_CONTENT_DIR . '/uploads/ovbfs-ok.txt' ), wp_json_encode( array( $added, $changed ) ) );
		ovb_check( "$tag nothing was written outside the roots: /tmp, the WordPress folder, its parent and wp-content have no new entry and the symlink target holds only what it held", $before_tmp === ovb_r6_listing( '/tmp', '/ovb|evil|zipslip|escape|pwn/i' ) && $before_abs === ovb_r6_listing( rtrim( ABSPATH, '/' ) ) && $before_up === ovb_r6_listing( dirname( rtrim( ABSPATH, '/' ) ) ) && array_values( array_diff( ovb_r6_listing( WP_CONTENT_DIR ), $before_content ) ) === array() && $before_escape === ovb_r6_listing( $r6_escape ) && 'precious' === file_get_contents( $r6_escape . '/victim.txt' ), wp_json_encode( array( array_diff( ovb_r6_listing( '/tmp', '/ovb|evil|zipslip|escape|pwn/i' ), $before_tmp ), array_diff( ovb_r6_listing( rtrim( ABSPATH, '/' ) ), $before_abs ) ) ) );
		foreach ( array( '/tmp/ovb-abs-evil.txt', '/tmp/ovb-zipslip2.txt', '/ovb-drive.txt', rtrim( ABSPATH, '/' ) . '/ovbfs-zipslip.txt', WP_CONTENT_DIR . '/ovbfs-zipslip.txt', WP_CONTENT_DIR . '/ovb-other', WP_CONTENT_DIR . '/' . $localname . '/backups/evil.zip', WP_CONTENT_DIR . '/plugins/OpenVault-Backup/ovbfs-case.php', rtrim( ABSPATH, '/' ) . '/.maintenance' ) as $evil ) {
			if ( ! file_exists( $evil ) ) {
				continue;
			}
			ovb_check( "$tag a hostile destination was written: $evil", false );
		}
		ovb_check( "$tag the canary in the plugin folder, wp-config.php and .htaccess are what they were", '<?php // canary-original' === file_get_contents( WP_CONTENT_DIR . '/plugins/openvault-backup/ovbfs-canary.php' ) && $r6_originals['wp-config.php'] === file_get_contents( $wp_config_path ) && $r6_originals['.htaccess'] === file_get_contents( $htaccess_path ) );
		ovb_check( "$tag the counters report what was skipped and why (unsafe names, links, protected plugin, storage, config, server, maintenance, symlinked destinations, a file or folder in the way, unowned)", ( 'zip' === $fmt ? 7 : 8 ) === ( isset( $sk['name_unsafe'] ) ? $sk['name_unsafe'] : 0 ) && ( 'zip' === $fmt ? 1 : 2 ) === ( isset( $sk['link'] ) ? $sk['link'] : 0 ) && 2 === ( isset( $sk['protected_plugin'] ) ? $sk['protected_plugin'] : 0 ) - 0 && 2 === ( isset( $sk['protected_storage'] ) ? $sk['protected_storage'] : 0 ) && 1 === ( isset( $sk['protected_config'] ) ? $sk['protected_config'] : 0 ) && 1 === ( isset( $sk['protected_server'] ) ? $sk['protected_server'] : 0 ) && 1 === ( isset( $sk['protected_maintenance'] ) ? $sk['protected_maintenance'] : 0 ) && 2 === ( isset( $sk['dest_symlink'] ) ? $sk['dest_symlink'] : 0 ) && 1 === ( isset( $sk['dest_not_dir'] ) ? $sk['dest_not_dir'] : 0 ) && 1 === ( isset( $sk['dest_is_dir'] ) ? $sk['dest_is_dir'] : 0 ) && 1 === ( isset( $sk['unowned'] ) ? $sk['unowned'] : 0 ), wp_json_encode( $sk ) );
		$log_blob = implode( "\n", (array) $wpdb->get_col( $wpdb->prepare( "SELECT CONCAT(message, ' ', COALESCE(context, '')) FROM {$r6_prefix}ovb_logs WHERE backup_id = %d", $hostile_bid ) ) );
		ovb_check( "$tag the log names skipped entries by index and a cleaned, cut name only (no control character, no right-to-left override), and holds no file content", false === strpos( $log_blob, 'EVIL' ) && 1 !== preg_match( '/\\\\u202e|\x{202E}/u', $log_blob ) && false !== strpos( $log_blob, 'entry_index' ), substr( $log_blob, 0, 200 ) );

		$can  = $fmgr->can_undo( $hid );
		$undo = $rmgr->undo( $hid, array( 'user_id' => 1 ) );
		if ( $undo['restore_id'] > 0 ) {
			$r6_restores[] = $undo['restore_id'];
			ovb_r5_drive( $rrunner, $undo['restore_id'] );
		}
		$bad = ovb_r6_diff( $before_state, ovb_r6_state() );
		ovb_check( "$tag the undo of the hostile restore removes the one file it created and the site is exactly as before", true === $can['ok'] && true === $undo['ok'] && array() === $bad && ! file_exists( WP_CONTENT_DIR . '/uploads/ovbfs-ok.txt' ), wp_json_encode( $undo['errors'] ) . ' ' . implode( ',', array_slice( $bad, 0, 5 ) ) );

		// Remove the traps.
		@unlink( $u . '/ovbfs-link' );
		@unlink( $u . '/ovbfs-linkfile.txt' );
		@unlink( $u . '/ovbfs-in-the-way' );
		@rmdir( $u . '/ovbfs-dir-in-the-way' );
	}

	// A part whose entry declares 8 GiB but is cut off: the job fails clearly, maintenance mode stays on, and Undo puts the earlier file back.
	$tag = 'restore damaged part:';
	ovb_r6_remove_fixture();
	ovb_r6_fixture( $r6_escape );
	if ( in_array( 'tar.gz', $r6_formats, true ) ) {
		$dmg_bid      = ovb_r6_backup( $main_contents, 'tar.gz', 'ovbfs damaged' );
		$r6_backups[] = $dmg_bid;
		ovb_r6_repack_files( $dmg_bid, 'tar.gz', array( array( 'content/uploads/ovbfs-first.txt', 'the first file was restored', 'file' ) ), true );
		$before_state = ovb_r6_state();
		$d            = $fmgr->start( $dmg_bid, 'files', array( 'user_id' => 1 ) );
		$did          = $d['restore_id'];
		$r6_restores[] = $did;
		ovb_r5_drive( $frunner, $did );
		$fmgr->confirm( $did );
		ovb_r5_drive( $frunner, $did );
		$drow = $rrepo->find( $did );
		ovb_check( "$tag the job fails with a clear message that says it stopped part way and points to Undo restore", 'failed' === $drow['status'] && false !== strpos( (string) $drow['error'], 'part way' ) && false !== strpos( (string) $drow['error'], 'Undo restore' ) && ( false !== strpos( (string) $drow['error'], 'damaged' ) || false !== strpos( (string) $drow['error'], 'larger than the free disk space' ) ), (string) $drow['status'] . ' ' . (string) $drow['error'] );
		ovb_check( "$tag the file before the damage was written, no temp file is left and maintenance mode stays on (it expires on its own)", 'the first file was restored' === file_get_contents( WP_CONTENT_DIR . '/uploads/ovbfs-first.txt' ) && array() === ovb_r6_temps() && ( new MaintenanceMode() )->is_active() );
		$can = $fmgr->can_undo( $did );
		ovb_check( "$tag a restore that failed after it started can be undone", true === $can['ok'] && $can['undo_files'] && ! $can['undo_db'], wp_json_encode( $can ) );
		$undo = $rmgr->undo( $did, array( 'user_id' => 1 ) );
		$r6_restores[] = $undo['restore_id'];
		ovb_r5_drive( $rrunner, $undo['restore_id'] );
		ovb_check( "$tag the undo completes, the site is exactly as before and maintenance mode is off", true === $undo['ok'] && 'complete' === $rrepo->find( $undo['restore_id'] )['status'] && array() === ovb_r6_diff( $before_state, ovb_r6_state() ) && ! ( new MaintenanceMode() )->is_active(), wp_json_encode( $undo['errors'] ) . ' ' . (string) $rrepo->find( $undo['restore_id'] )['error'] );
	} else {
		ovb_check( "$tag SKIPPED (tar.gz is not available here)", true );
	}

	// ---------------------------------------------------------------- 20g. a full disk, simulated by an injected writer
	$tag = 'restore full disk:';
	ovb_r6_remove_fixture();
	ovb_r6_fixture( $r6_escape );
	$disk_bid     = ovb_r6_backup( $main_contents, $main_format, 'ovbfs disk' );
	$r6_backups[] = $disk_bid;
	$r6_wreck();
	$disk_before  = ovb_r6_state();
	$disk_filter  = static function ( $steps ) {
		$steps['file_restore'] = new FileRestoreStep( null, null, null, null, new OvbFullDiskWriter(), 5 );
		return $steps;
	};
	add_filter( 'ovb_restore_steps', $disk_filter );
	$d             = $fmgr->start( $disk_bid, 'files', array( 'user_id' => 1 ) );
	$did           = $d['restore_id'];
	$r6_restores[] = $did;
	ovb_r5_drive( $frunner, $did );
	$fmgr->confirm( $did );
	ovb_r5_drive( $frunner, $did );
	remove_filter( 'ovb_restore_steps', $disk_filter );
	$drow = $rrepo->find( $did );
	ovb_check( "$tag the restore fails with an actionable message (disk may be full, stopped part way, Undo restore)", 'failed' === $drow['status'] && false !== strpos( (string) $drow['error'], 'disk may be full' ) && false !== strpos( (string) $drow['error'], 'Undo restore' ) && false !== strpos( (string) $drow['error'], 'part way' ), $drow['status'] . ' ' . (string) $drow['error'] );
	ovb_check( "$tag the half written file was removed and some files were already replaced", array() === ovb_r6_temps() && $disk_before !== ovb_r6_state() );
	$can  = $fmgr->can_undo( $did );
	$undo = $rmgr->undo( $did, array( 'user_id' => 1 ) );
	$r6_restores[] = $undo['restore_id'];
	ovb_r5_drive( $rrunner, $undo['restore_id'] );
	$bad = ovb_r6_diff( $disk_before, ovb_r6_state() );
	ovb_check( "$tag Undo puts the partly restored site back EXACTLY and maintenance mode ends", true === $can['ok'] && 'complete' === $rrepo->find( $undo['restore_id'] )['status'] && array() === $bad && ! ( new MaintenanceMode() )->is_active(), implode( ',', array_slice( $bad, 0, 5 ) ) . ' ' . wp_json_encode( $undo['errors'] ) );

	// ---------------------------------------------------------------- 20h. cancel rules
	$tag = 'restore cancel:';
	$cid1 = $fmgr->start( $disk_bid, 'files', array( 'user_id' => 1 ) )['restore_id'];
	$r6_restores[] = $cid1;
	ovb_r5_drive( $frunner, $cid1 );
	ovb_check( "$tag a verified files restore can be cancelled; its work folder is removed and no journal was ever created", true === $fmgr->cancel( $cid1 ) && 'cancelled' === $rrepo->find( $cid1 )['status'] && ! file_exists( ovb_rs_work( $cid1 ) ) && ! UndoStore::for_restore( $cid1 )->exists() );
	$cid2 = $fmgr->start( $disk_bid, 'files', array( 'user_id' => 1 ) )['restore_id'];
	$r6_restores[] = $cid2;
	ovb_r5_drive( $frunner, $cid2 );
	$fmgr->confirm( $cid2 );
	// Before the first write: the job sits at file_restore, setup phase, maintenance mode switched on by a setup that died.
	( new MaintenanceMode() )->enable( $cid2 );
	$s2 = $rrepo->load_state( $cid2 );
	$rrepo->save_state( $cid2, $s2->with_step( 'file_restore', array( 'ph' => 's' ) ) );
	ovb_check( "$tag a files restore can be cancelled before its first write (setup phase), and that switches maintenance mode off again", '' === $fmgr->cancel_refusal( $cid2 ) && true === $fmgr->cancel( $cid2 ) && 'cancelled' === $rrepo->find( $cid2 )['status'] && ! ( new MaintenanceMode() )->is_active(), $fmgr->cancel_refusal( $cid2 ) );
	$cid3 = $fmgr->start( $disk_bid, 'files', array( 'user_id' => 1 ) )['restore_id'];
	$r6_restores[] = $cid3;
	ovb_r5_drive( $frunner, $cid3 );
	$fmgr->confirm( $cid3 );
	$s3 = $rrepo->load_state( $cid3 );
	$rrepo->save_state( $cid3, $s3->with_step( 'file_restore', array( 'ph' => 'a', 'p' => 0, 'e' => 3 ) ) );
	ovb_check( "$tag once the apply phase began the restore cannot be cancelled and the message names Undo restore", false === $fmgr->cancel( $cid3 ) && false !== strpos( $fmgr->cancel_refusal( $cid3 ), 'Undo restore' ) );
	$rrepo->update_status( $cid3, 'failed', array( 'error' => 'test' ) );
	ovb_check( "$tag a restore that never changed anything and failed cannot be undone ('nothing to undo')", 'nothing_to_undo' === $fmgr->can_undo( $cid3 )['code'] );
	ovb_check( "$tag a cancelled restore, a missing restore and an unfinished restore cannot be undone", 'not_finished' === $fmgr->can_undo( $cid1 )['code'] && 'restore_missing' === $fmgr->can_undo( 2147000001 )['code'] );
	ovb_check( "$tag dismissing the undo data of a cancelled or missing restore is refused", false === $fmgr->dismiss_undo( $cid1 )['ok'] && false === $fmgr->dismiss_undo( 2147000001 )['ok'] );

	// ---------------------------------------------------------------- 20i. an undo whose journal or snapshot is gone is refused
	$tag = 'restore undo eligibility:';
	ovb_r6_remove_fixture();
	ovb_r6_fixture( $r6_escape );
	$eli_bid       = ovb_r6_backup( $main_contents, $main_format, 'ovbfs eligibility' );
	$r6_backups[]  = $eli_bid;
	$r6_wreck();
	$e             = $fmgr->start( $eli_bid, 'files', array( 'user_id' => 1 ) );
	$eid           = $e['restore_id'];
	$r6_restores[] = $eid;
	ovb_r5_drive( $frunner, $eid );
	$fmgr->confirm( $eid );
	ovb_r5_drive( $frunner, $eid );
	ovb_check( "$tag the restore completed", 'complete' === $rrepo->find( $eid )['status'], (string) $rrepo->find( $eid )['error'] );
	CleanupStep::remove_tree( UndoStore::build( Paths::local_dir(), $eid ) );
	$gone = $fmgr->undo( $eid );
	ovb_check( "$tag with the journal gone the undo is refused with a clear message and nothing is created", false === $gone['ok'] && 'journal_gone' === $gone['errors'][0]['code'] && 0 === $gone['restore_id'], wp_json_encode( $gone['errors'] ) );
	ovb_r6_remove_fixture();

	ovb_check( 'restore files: no PHP warning, notice or deprecation came from the plugin during all of the above', array() === $r6_errors, implode( ' | ', array_slice( $r6_errors, 0, 5 ) ) );
} catch ( OvbR5Done $done ) {
	unset( $done );
} catch ( \Throwable $e ) {
	ovb_check( 'restore files: no exception while running the files restore checks', false, get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	restore_error_handler();
	foreach ( array( $r6_kill_filter, $r6_disk_filter ) as $filter ) {
		if ( null !== $filter ) {
			remove_filter( 'ovb_restore_steps', $filter );
		}
	}
	if ( isset( $disk_filter ) ) {
		remove_filter( 'ovb_restore_steps', $disk_filter );
	}
	( new MaintenanceMode() )->disable();
	ovb_r6_remove_fixture();
	CleanupStep::remove_tree( '/tmp/ovb-escape' );
	foreach ( $r6_originals as $name => $content ) {
		file_put_contents( rtrim( ABSPATH, '/' ) . '/' . $name, $content );
	}
	if ( $r6_created_htaccess ) {
		@unlink( rtrim( ABSPATH, '/' ) . '/.htaccess' );
	}
	foreach ( array_unique( $r6_restores ) as $cleanup_restore ) {
		as_unschedule_all_actions( RestoreRunner::ACTION, array( (int) $cleanup_restore ), RestoreRunner::GROUP );
		RestoreWorkDir::remove( $cleanup_restore );
	}
	$wpdb->query( "DELETE FROM {$r6_prefix}ovb_restores" );
	foreach ( (array) $wpdb->get_col( "SELECT id FROM {$r6_prefix}ovb_backups WHERE trigger_type = 'pre-restore'" ) as $snap_cleanup ) {
		$r6_backups[] = (int) $snap_cleanup;
	}
	foreach ( array_unique( $r6_backups ) as $cleanup_backup ) {
		ovb_r5_cleanup_backup( $cleanup_backup );
	}
	foreach ( (array) glob( Paths::tmp_dir() . '/restore-[0-9]*' ) as $leftover ) {
		CleanupStep::remove_tree( $leftover );
	}
	CleanupStep::remove_tree( Paths::local_dir() . '/undo' );
	$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_title = 'ovbfs-marker-post'" );
	delete_option( 'ovbfs_marker' );
	$wpdb->query( "DROP TABLE IF EXISTS `{$r6_prefix}ovbfs_extra`" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ovb\\_lock\\_%'" );
	update_option( 'active_plugins', $r6_active );
	update_option( 'ovb_settings', $r6_settings );
	delete_option( 'rewrite_rules' );
	wp_cache_flush();
	if ( null !== $r6_mysqli ) {
		$r6_mysqli->close();
	}
}
