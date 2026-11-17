<?php
/**
 * wp-config.php of the site the `e2e` suite opens in a browser. The stack
 * mounts it one level above the cached WordPress copy, where WordPress also
 * looks for it, so that copy stays without a configuration file.
 *
 * The site has its own database (`wpsvg_e2e`), its own uploads folder and a
 * must-use plugins folder with the test helper.
 */

define( 'DB_NAME', 'wpsvg_e2e' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wpe2e_';

define( 'WP_HOME', getenv( 'WPSVG_E2E_BASE_URL' ) );
define( 'WP_SITEURL', getenv( 'WPSVG_E2E_BASE_URL' ) );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'SCRIPT_DEBUG', false );
define( 'UPLOADS', 'wp-content/uploads-e2e' );
define( 'WPMU_PLUGIN_DIR', '/opt/e2e-mu-plugins' );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'DISABLE_WP_CRON', true );

/** Switches the helper endpoints of the test-only must-use plugin on. */
define( 'WPSVG_E2E', true );

foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $wpsvg_key ) {
	define( $wpsvg_key, 'wpsvg-e2e-tests-' . $wpsvg_key );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/opt/wp/' );
}

require_once ABSPATH . 'wp-settings.php';
