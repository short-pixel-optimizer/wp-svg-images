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
 *                the libraries directory.
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
);
