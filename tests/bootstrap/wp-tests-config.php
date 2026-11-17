<?php
/**
 * WordPress test configuration, read by the WordPress PHPUnit library through
 * WP_TESTS_CONFIG_FILE_PATH. Every value comes from the environment set by
 * tests/docker/compose.yml; each suite gets its own database `wpsvg_<suite>`.
 */

define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ), '/' ) . '/' );

define( 'DB_NAME', 'wpsvg_' . getenv( 'WPSVG_SUITE' ) );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WP SVG Images Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// The `compat` suite has its own plugins directory, so the third-party
// plugins it needs never show up in the other suites, and its own uploads
// directory, because some of those plugins create folders there.
if ( 'compat' === getenv( 'WPSVG_SUITE' ) ) {
	define( 'UPLOADS', 'wp-content/uploads-compat' );
	// Scenarios with `versions` => `previous` run the older third-party
	// versions, which have a plugins directory of their own.
	$wpsvg_compat_scenarios = require dirname( __DIR__ ) . '/compat/scenarios.php';
	$wpsvg_compat_scenario  = (string) getenv( 'WPSVG_COMPAT_SCENARIO' );
	$wpsvg_compat_previous  = isset( $wpsvg_compat_scenarios[ $wpsvg_compat_scenario ]['versions'] )
		&& 'previous' === $wpsvg_compat_scenarios[ $wpsvg_compat_scenario ]['versions'];
	define( 'WP_PLUGIN_DIR', $wpsvg_compat_previous ? '/opt/compat-plugins-previous' : '/opt/compat-plugins' );
	define( 'WP_PLUGIN_URL', 'http://' . WP_TESTS_DOMAIN . '/wp-content/plugins' );
}
