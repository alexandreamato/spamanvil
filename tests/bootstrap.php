<?php
/**
 * Bootstrap for the "integration" test suite.
 *
 * Loads the WordPress PHPUnit test library and the plugin, so tests can run
 * against a real WordPress + MySQL install. Requires bin/install-wp-tests.sh
 * to have been run first (CI does this automatically).
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tmp_dir   = getenv( 'TMPDIR' ) ? rtrim( getenv( 'TMPDIR' ), '/\\' ) : sys_get_temp_dir();
	$_tests_dir = $_tmp_dir . '/wordpress-tests-lib';
}

// Forward compatibility with the Yoast PHPUnit polyfills required by the WP test suite.
$_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills_path );
} elseif ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && file_exists( dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php — have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load the plugin under test (it lives in the spamanvil/ subfolder of the repo).
 */
function _spamanvil_manually_load_plugin() {
	require dirname( __DIR__ ) . '/spamanvil/spamanvil.php';
}
tests_add_filter( 'muplugins_loaded', '_spamanvil_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
