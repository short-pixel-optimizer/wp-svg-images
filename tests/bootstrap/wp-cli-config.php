<?php
/**
 * wp-config.php of the site the `cli` suite drives with WP-CLI. It lives
 * outside the cached WordPress copy and reaches WP-CLI through the
 * WP_CONFIG_PATH environment variable, so the other suites never see it.
 *
 * The site has its own database (`wpsvg_cli`) and its own uploads folder.
 */

define( 'DB_NAME', 'wpsvg_' . getenv( 'WPSVG_SUITE' ) );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wpcli_';

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'UPLOADS', 'wp-content/uploads-cli' );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );

foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $wpsvg_key ) {
	define( $wpsvg_key, 'wpsvg-cli-tests-' . $wpsvg_key );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ), '/' ) . '/' );
}

require_once ABSPATH . 'wp-settings.php';
