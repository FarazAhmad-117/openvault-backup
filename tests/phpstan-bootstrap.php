<?php
/**
 * Constants defined by the main plugin file, declared here so PHPStan knows them.
 *
 * @package OpenVault\Backup
 */

// phpcs:disable
define( 'WP_MEMORY_LIMIT', '40M' );
define( 'OVB_VERSION', '0.1.0' );
define( 'OVB_DB_VERSION', '1' );
define( 'OVB_FILE', __DIR__ . '/../openvault-backup.php' );
define( 'OVB_PATH', dirname( OVB_FILE ) . '/' );
define( 'OVB_URL', 'http://example.com/wp-content/plugins/openvault-backup/' );
