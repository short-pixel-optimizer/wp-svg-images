<?php
/**
 * Scenarios of the `compat` suite.
 *
 * WordPress loads plugins once per PHP process, so every combination of
 * active third parties is its own PHPUnit run. Each scenario names:
 *
 * - `tests`:     folder below tests/compat/ whose tests run in it;
 * - `plugins`:   active plugins in the order WordPress loads them, as
 *                basenames relative to the plugins directory;
 * - `libraries`: files to load before WordPress, relative to
 *                the libraries directory;
 * - `activate`:  optional; plugins whose activation routine runs at the
 *                start of `init`, for plugins that create database tables
 *                on activation;
 * - `versions`:  optional; `previous` runs the older version of each third
 *                party (`previous_plugins` in the manifest), chosen because
 *                the part this plugin relies on differs there;
 * - `wordpress_up_to`: optional; newest WordPress branch (`6.9`) an older
 *                third-party version is run on. Later versions skip the
 *                scenario with that reason.
 *
 * Versions are pinned in tests/compat/plugins.json.
 */

return array(
	'baseline'       => array(
		'tests'     => 'Baseline',
		'plugins'   => array( 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
	),
	'safe-svg-first' => array(
		'tests'     => 'SafeSvg',
		'plugins'   => array( 'safe-svg/safe-svg.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
	),
	'safe-svg-last'  => array(
		'tests'     => 'SafeSvg',
		'plugins'   => array( 'wp-svg-images/wp-svg-images.php', 'safe-svg/safe-svg.php' ),
		'libraries' => array(),
	),
	'htmlpurifier'   => array(
		'tests'     => 'HtmlPurifier',
		'plugins'   => array( 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array( 'htmlpurifier/library/HTMLPurifier.auto.php' ),
	),
	'wp-all-import'  => array(
		'tests'     => 'WpAllImport',
		'plugins'   => array( 'wp-all-import/plugin.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
		'activate'  => array( 'wp-all-import/plugin.php' ),
	),
	'shortpixel'     => array(
		'tests'     => 'ShortPixel',
		'plugins'   => array( 'shortpixel-image-optimiser/wp-shortpixel.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
		'activate'  => array( 'shortpixel-image-optimiser/wp-shortpixel.php' ),
	),
	'gutenberg'      => array(
		'tests'     => 'Gutenberg',
		'plugins'   => array( 'gutenberg/gutenberg.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
	),
	'safe-svg-previous'       => array(
		'tests'     => 'SafeSvg',
		'plugins'   => array( 'safe-svg/safe-svg.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
		'versions'  => 'previous',
	),
	'wp-all-import-previous'  => array(
		'tests'     => 'WpAllImport',
		'plugins'   => array( 'wp-all-import/plugin.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
		'activate'  => array( 'wp-all-import/plugin.php' ),
		'versions'  => 'previous',
	),
	'beaver-builder-previous' => array(
		'tests'     => 'BeaverBuilder',
		'plugins'   => array( 'beaver-builder-lite-version/fl-builder.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
		'versions'  => 'previous',
		// This release loads its translations too early for later WordPress
		// versions, which report that on every request.
		'wordpress_up_to' => '6.4',
	),
	'gutenberg-previous'      => array(
		'tests'     => 'Gutenberg',
		'plugins'   => array( 'gutenberg/gutenberg.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
		'versions'  => 'previous',
	),
	'beaver-builder' => array(
		'tests'     => 'BeaverBuilder',
		'plugins'   => array( 'beaver-builder-lite-version/fl-builder.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
	),
);
