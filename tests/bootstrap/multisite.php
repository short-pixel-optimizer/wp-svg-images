<?php
/**
 * Bootstrap for the `multisite` suite: real WordPress installed as a network
 * through the WordPress PHPUnit library, with the plugin network-activated.
 *
 * Expects WP_TESTS_DIR (library), WP_CORE_DIR (core) and WPSVG_SUITE (database
 * name suffix) in the environment; bin/test.sh provides them.
 * tests/phpunit/multisite.xml defines WP_TESTS_MULTISITE, which makes the
 * library install a network.
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

/*
 * No promo campaign runs during the tests: the banner (build/shortpixel/
 * promo-banner) shows only inside its date window, so without this the
 * settings page would depend on the day the suite runs. A test about the
 * banner opens the window with a later `__return_true` on the same filter,
 * or removes this one.
 */
tests_add_filter( 'wpsvg/promo_banner/in_window', '__return_false' );

// Network activation is the `active_sitewide_plugins` network option; answer
// it through its filter so WordPress loads the plugin for every site, like
// after "Network Activate". The per-site list stays empty.
tests_add_filter(
	'pre_site_option_active_sitewide_plugins',
	static function () {
		return array( 'wp-svg-images/wp-svg-images.php' => 1 );
	}
);

require $wpsvg_tests_lib . '/includes/bootstrap.php';
