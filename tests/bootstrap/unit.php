<?php
/**
 * Bootstrap for the `unit` suite: Composer autoloader only, no WordPress.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

/** Absolute path of the plugin root, with trailing slash. */
define( 'WPSVG_TESTS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

/** Absolute path of the shared fixtures directory, with trailing slash. */
define( 'WPSVG_TESTS_FIXTURES_DIR', dirname( __DIR__ ) . '/fixtures/' );

/*
 * The bundled sanitizer has no autoloader. Load it in the same order as
 * wp-svg-images.php does, so its classes can be tested without the plugin.
 */
foreach (
	array(
		'data/AttributeInterface.php',
		'data/TagInterface.php',
		'data/AllowedAttributes.php',
		'data/AllowedTags.php',
		'data/XPath.php',
		'ElementReference/Resolver.php',
		'ElementReference/Subject.php',
		'ElementReference/Usage.php',
		'Exceptions/NestingException.php',
		'Exceptions/RegexException.php',
		'Helper.php',
		'Sanitizer.php',
	) as $wpsvg_tests_file
) {
	require_once WPSVG_TESTS_PLUGIN_DIR . 'svg-sanitizer/' . $wpsvg_tests_file;
}
unset( $wpsvg_tests_file );
