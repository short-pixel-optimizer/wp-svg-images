<?php
/**
 * Bootstrap for the `cli` suite: Composer autoloader only. The tests start
 * WP-CLI as a separate process for every command; WordPress is never loaded
 * into the PHPUnit process.
 *
 * Expects WP_CORE_DIR, WPSVG_SUITE and the WP_TESTS_DB_* variables in the
 * environment; bin/test.sh provides them.
 */

if ( ! getenv( 'WP_CORE_DIR' ) || ! is_file( rtrim( getenv( 'WP_CORE_DIR' ), '/' ) . '/wp-settings.php' ) ) {
	fwrite( STDERR, "WordPress not found in WP_CORE_DIR; run the suite through bin/test.sh.\n" );
	exit( 1 );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/cli/CliTestCase.php';
