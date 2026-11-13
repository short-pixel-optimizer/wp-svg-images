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
