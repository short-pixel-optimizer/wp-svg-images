<?php
/**
 * Bootstrap for the `compat` suite: real WordPress with the plugin and the
 * third parties of one scenario active, loaded in the scenario's order.
 *
 * Expects WP_TESTS_DIR, WP_CORE_DIR, WPSVG_SUITE and WPSVG_COMPAT_SCENARIO in
 * the environment; bin/test.sh provides them. Third-party plugins come from
 * tests/.cache/compat/, filled by tests/bin/setup.sh.
 *
 * A third-party plugin whose header or readme.txt asks for a newer WordPress or PHP than
 * the one running is not loaded. The scenario's tests are then skipped with
 * WPSVG_COMPAT_SKIP_REASON as the message; see CompatTestCase.
 */

$wpsvg_tests_lib = getenv( 'WP_TESTS_DIR' );
if ( ! $wpsvg_tests_lib || ! is_file( $wpsvg_tests_lib . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in WP_TESTS_DIR; run the suite through bin/test.sh.\n" );
	exit( 1 );
}

$wpsvg_scenarios     = require dirname( __DIR__ ) . '/compat/scenarios.php';
$wpsvg_scenario_name = (string) getenv( 'WPSVG_COMPAT_SCENARIO' );
if ( ! isset( $wpsvg_scenarios[ $wpsvg_scenario_name ] ) ) {
	fwrite( STDERR, "Unknown compat scenario '$wpsvg_scenario_name'; run the suite through bin/test.sh.\n" );
	exit( 1 );
}
$wpsvg_scenario = $wpsvg_scenarios[ $wpsvg_scenario_name ];

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

/** Absolute path of the shared fixtures directory, with trailing slash. */
define( 'WPSVG_TESTS_FIXTURES_DIR', dirname( __DIR__ ) . '/fixtures/' );

/** Name of the running scenario, a key of tests/compat/scenarios.php. */
define( 'WPSVG_COMPAT_SCENARIO', $wpsvg_scenario_name );

/** Whether the scenario runs the older versions of the third parties. */
define( 'WPSVG_COMPAT_PREVIOUS_VERSIONS', isset( $wpsvg_scenario['versions'] ) && 'previous' === $wpsvg_scenario['versions'] );

// Where compose.yml mounts what setup.sh downloaded.
$wpsvg_plugins_dir   = WPSVG_COMPAT_PREVIOUS_VERSIONS ? '/opt/compat-plugins-previous' : '/opt/compat-plugins';
$wpsvg_libraries_dir = '/opt/compat-libraries';

/**
 * Reads one header of a plugin, from its main file or, like WordPress does
 * for the requirement headers, from the readme.txt next to it.
 *
 * @param string $file   Plugin main file.
 * @param string $header Header name.
 * @return string Empty when the header is missing in both files.
 */
function wpsvg_compat_plugin_header( $file, $header ) {
	foreach ( array( $file, dirname( $file ) . '/readme.txt' ) as $source ) {
		$head = is_file( $source ) ? (string) file_get_contents( $source, false, null, 0, 8192 ) : '';
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $head, $match ) && '' !== trim( $match[1] ) ) {
			return trim( $match[1] );
		}
	}

	return '';
}

$wpsvg_skip_reason = '';
foreach ( $wpsvg_scenario['plugins'] as $wpsvg_basename ) {
	$wpsvg_file = $wpsvg_plugins_dir . '/' . $wpsvg_basename;
	if ( ! is_file( $wpsvg_file ) ) {
		fwrite( STDERR, "Plugin $wpsvg_basename is missing in $wpsvg_plugins_dir; run the suite through bin/test.sh.\n" );
		exit( 1 );
	}
	if ( 0 === strpos( $wpsvg_basename, 'wp-svg-images/' ) ) {
		continue;
	}
	$wpsvg_needs_php = wpsvg_compat_plugin_header( $wpsvg_file, 'Requires PHP' );
	$wpsvg_needs_wp  = wpsvg_compat_plugin_header( $wpsvg_file, 'Requires at least' );
	$wpsvg_label     = dirname( $wpsvg_basename ) . ' ' . wpsvg_compat_plugin_header( $wpsvg_file, 'Version' );
	require rtrim( getenv( 'WP_CORE_DIR' ), '/' ) . '/wp-includes/version.php';
	$wpsvg_wp_up_to  = isset( $wpsvg_scenario['wordpress_up_to'] ) ? $wpsvg_scenario['wordpress_up_to'] : '';
	if ( '' !== $wpsvg_wp_up_to && version_compare( preg_replace( '/-.*$/', '', $wp_version ), $wpsvg_wp_up_to . '.999', '>' ) ) {
		$wpsvg_skip_reason = "$wpsvg_label is run up to WordPress $wpsvg_wp_up_to only, this is WordPress $wp_version";
	} elseif ( '' !== $wpsvg_needs_php && version_compare( PHP_VERSION, $wpsvg_needs_php, '<' ) ) {
		$wpsvg_skip_reason = "$wpsvg_label requires PHP $wpsvg_needs_php, this is PHP " . PHP_VERSION;
	} elseif ( '' !== $wpsvg_needs_wp && version_compare( preg_replace( '/-.*$/', '', $wp_version ), $wpsvg_needs_wp, '<' ) ) {
		$wpsvg_skip_reason = "$wpsvg_label requires WordPress $wpsvg_needs_wp, this is WordPress $wp_version";
	}
}
foreach ( $wpsvg_scenario['libraries'] as $wpsvg_library ) {
	if ( ! is_file( $wpsvg_libraries_dir . '/' . $wpsvg_library ) ) {
		fwrite( STDERR, "Library file $wpsvg_library is missing in $wpsvg_libraries_dir; run the suite through bin/test.sh.\n" );
		exit( 1 );
	}
}

/** Why the scenario cannot run here; empty when it can. */
define( 'WPSVG_COMPAT_SKIP_REASON', $wpsvg_skip_reason );
if ( '' !== $wpsvg_skip_reason ) {
	// PHPUnit's JUnit report keeps no skip message, so say it here as well.
	fwrite( STDERR, "Scenario $wpsvg_scenario_name is skipped: $wpsvg_skip_reason\n" );
}

/** Active plugins of the scenario, in load order. */
define(
	'WPSVG_COMPAT_ACTIVE_PLUGINS',
	implode( ',', '' === $wpsvg_skip_reason ? $wpsvg_scenario['plugins'] : array( 'wp-svg-images/wp-svg-images.php' ) )
);

if ( '' === $wpsvg_skip_reason ) {
	foreach ( $wpsvg_scenario['libraries'] as $wpsvg_library ) {
		require_once $wpsvg_libraries_dir . '/' . $wpsvg_library;
	}
}

require $wpsvg_tests_lib . '/includes/functions.php';

/*
 * No promo campaign runs during the tests: the banner (build/shortpixel/
 * promo-banner) shows only inside its date window, so without this the
 * settings page would depend on the day the suite runs. A test about the
 * banner opens the window with a later `__return_true` on the same filter,
 * or removes this one.
 */
tests_add_filter( 'wpsvg/promo_banner/in_window', '__return_false' );

// Activate through the active_plugins option so WordPress loads the plugins
// itself, in this order, like on a real site.
$GLOBALS['wp_tests_options'] = array(
	'active_plugins' => explode( ',', WPSVG_COMPAT_ACTIVE_PLUGINS ),
);

// Activating through the option runs no activation routine. Scenarios that
// name plugins under `activate` get it run at the start of `init`, before any
// other callback: late enough for translations, as on a real activation
// request, and early enough for those plugins to find their tables.
if ( '' === $wpsvg_skip_reason && ! empty( $wpsvg_scenario['activate'] ) ) {
	tests_add_filter(
		'init',
		static function () use ( $wpsvg_scenario ) {
			foreach ( $wpsvg_scenario['activate'] as $basename ) {
				do_action( 'activate_' . $basename );
			}
		},
		-PHP_INT_MAX
	);
}

require $wpsvg_tests_lib . '/includes/bootstrap.php';
