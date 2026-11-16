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
 * - `activate`:  optional; plugins whose activation routine runs after the
 *                plugin files are loaded, for plugins that create database
 *                tables on activation.
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
	'beaver-builder' => array(
		'tests'     => 'BeaverBuilder',
		'plugins'   => array( 'beaver-builder-lite-version/fl-builder.php', 'wp-svg-images/wp-svg-images.php' ),
		'libraries' => array(),
	),
);
