<?php
/**
 * Bootstrap for the `unit` suite: Composer autoloader only, no WordPress.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

/** Absolute path of the plugin root, with trailing slash. */
define( 'WPSVG_TESTS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

/** Absolute path of the shared fixtures directory, with trailing slash. */
define( 'WPSVG_TESTS_FIXTURES_DIR', dirname( __DIR__ ) . '/fixtures/' );
