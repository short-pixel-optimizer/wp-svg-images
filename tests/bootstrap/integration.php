<?php
/**
 * Bootstrap for the `integration` suite: real WordPress through the WordPress
 * PHPUnit library, with the plugin active the same way a site activates it.
 *
 * Expects WP_TESTS_DIR (library), WP_CORE_DIR (core) and WPSVG_SUITE (database
 * name suffix) in the environment; bin/test.sh provides them.
 */

$wpsvg_tests_lib = getenv( 'WP_TESTS_DIR' );
if ( ! $wpsvg_tests_lib || ! is_file( $wpsvg_tests_lib . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in WP_TESTS_DIR; run the suite through bin/test.sh.\n" );
	exit( 1 );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

/** Absolute path of the shared fixtures directory, with trailing slash. */
define( 'WPSVG_TESTS_FIXTURES_DIR', dirname( __DIR__ ) . '/fixtures/' );

require $wpsvg_tests_lib . '/includes/functions.php';

// Activate through the active_plugins option so WordPress loads the plugin
// from wp-content/plugins like on a real site and is_plugin_active() is true.
$GLOBALS['wp_tests_options'] = array(
	'active_plugins' => array( 'wp-svg-images/wp-svg-images.php' ),
);

require $wpsvg_tests_lib . '/includes/bootstrap.php';
