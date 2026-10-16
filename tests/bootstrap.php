<?php
/**
 * PHPUnit bootstrap for pure-logic unit tests (no WordPress loaded).
 *
 * @package OpenVault\Backup
 */

// Let plugin files pass their `defined( 'ABSPATH' ) || exit;` guard.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/phpstan-bootstrap.php';
