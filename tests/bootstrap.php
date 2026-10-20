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

// Minimal stand-ins for WordPress i18n helpers so pure classes that return translatable text can be unit tested.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}
