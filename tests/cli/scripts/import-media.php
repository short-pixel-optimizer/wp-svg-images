<?php
/**
 * Imports one local file into the Media Library the way `wp media import`
 * does: a copy of the file goes through media_handle_sideload().
 *
 * Run with `wp eval-file import-media.php <path>`. `wp media import` itself
 * refuses to start without the GD or Imagick extension, which the test image
 * does not have; this script is the part of that command the plugin is
 * involved in. Prints one JSON object: `id` on success, `error` otherwise.
 */

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$wpsvg_source = $args[0];
$wpsvg_copy   = wp_tempnam( $wpsvg_source );
copy( $wpsvg_source, $wpsvg_copy );

$wpsvg_result = media_handle_sideload(
	array(
		'tmp_name' => $wpsvg_copy,
		'name'     => basename( $wpsvg_source ),
	),
	0
);

if ( is_wp_error( $wpsvg_result ) ) {
	if ( file_exists( $wpsvg_copy ) ) {
		unlink( $wpsvg_copy );
	}
	echo wp_json_encode( array( 'error' => $wpsvg_result->get_error_message() ) );
} else {
	echo wp_json_encode( array( 'id' => $wpsvg_result ) );
}
