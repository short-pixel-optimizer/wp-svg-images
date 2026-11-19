<?php
/**
 * Plugin Name: WP SVG Images e2e helper
 * Description: Test-only endpoints that put the site into a known state and report on it. Loaded by the e2e stack only.
 *
 * The endpoints need no login. They exist only where wp-config.php defines
 * WPSVG_E2E, which the configuration of the e2e site does, and that site is
 * reachable from the test network alone.
 */

if ( ! defined( 'WPSVG_E2E' ) || ! WPSVG_E2E ) {
	return;
}

/** Password of every seeded user. */
const WPSVG_E2E_PASSWORD = 'password';

/** Basename of the plugin under test. */
const WPSVG_E2E_PLUGIN = 'wp-svg-images/wp-svg-images.php';

// The site never reaches the internet. WordPress's own requests (update
// checks, for example) get an empty successful answer instead of failing
// with a warning that would end up in the page.
add_filter(
	'pre_http_request',
	static function ( $response, $arguments, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( in_array( $host, array( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'localhost', '127.0.0.1' ), true ) ) {
			return $response;
		}

		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// The promo banner shows only inside its campaign window. Here the window
// is open exactly when a test asked for it, so no test depends on the day
// it runs.
add_filter(
	'wpsvg/promo_banner/in_window',
	static function () {
		return (bool) get_option( 'wpsvg_e2e_campaign' );
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'wpsvg-e2e/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'wpsvg_e2e_reset',
			)
		);
		register_rest_route(
			'wpsvg-e2e/v1',
			'/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'wpsvg_e2e_state',
			)
		);
	}
);

/**
 * Puts the site into a known state.
 *
 * Body (JSON): `plugin` ("active" or "inactive", default "active"),
 * `settings` (role slug => 0, 1 or 2; null or missing deletes the option, as
 * on a fresh install), `activated` (true leaves the one-shot redirect flag
 * in place, as right after activation) and `campaign` (true makes the promo
 * banner's campaign run; it does not run otherwise).
 *
 * Deletes every attachment and post, the plugin's options, the promo
 * banner's dismissals and the dismissed notice of every user, makes sure one user per role exists with exactly
 * that role (`admin`, `editor`, `author`, `contributor` and `subscriber`)
 * and switches the block editor's welcome guide off for them.
 *
 * @param WP_REST_Request $request
 * @return array The state after the reset; see wpsvg_e2e_state().
 */
function wpsvg_e2e_reset( WP_REST_Request $request ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/user.php';

	foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	foreach ( array( 'administrator' => 'admin', 'editor' => 'editor', 'author' => 'author', 'contributor' => 'contributor', 'subscriber' => 'subscriber' ) as $role => $login ) {
		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			wp_insert_user(
				array(
					'user_login' => $login,
					'user_pass'  => WPSVG_E2E_PASSWORD,
					'user_email' => "$login@example.org",
					'role'       => $role,
				)
			);
		} elseif ( array( $role ) !== array_values( $user->roles ) ) {
			// A test may have changed the role through the Users screen.
			$user->set_role( $role );
		}
	}
	delete_metadata( 'user', 0, 'wpsvg_notice_dismissed', '', true );

	// Keep the block editor from opening its welcome guide and pattern chooser
	// over the canvas.
	global $wpdb;
	foreach ( get_users( array( 'fields' => 'ids' ) ) as $user_id ) {
		update_user_meta(
			$user_id,
			$wpdb->get_blog_prefix() . 'persisted_preferences',
			array(
				'core/edit-post' => array( 'welcomeGuide' => false ),
				'core'           => array(
					'welcomeGuide'             => false,
					'enableChoosePatternModal' => false,
				),
				'_modified'      => gmdate( 'c' ),
			)
		);
	}

	delete_option( 'wpsvg_settings' );
	delete_option( 'WPSVGActivated' );
	delete_option( 'wpsvg-promo-banner' );
	delete_option( 'wpsvg_e2e_campaign' );
	if ( $request->get_param( 'campaign' ) ) {
		update_option( 'wpsvg_e2e_campaign', 1 );
	}

	$settings = $request->get_param( 'settings' );
	if ( is_array( $settings ) ) {
		$option = array();
		foreach ( $settings as $role => $value ) {
			$option[ 'role_' . $role ] = (int) $value;
		}
		update_option( 'wpsvg_settings', $option );
	}

	$active = array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( WPSVG_E2E_PLUGIN ) ) );
	if ( 'inactive' !== $request->get_param( 'plugin' ) ) {
		$active[] = WPSVG_E2E_PLUGIN;
	}
	update_option( 'active_plugins', $active );

	if ( $request->get_param( 'activated' ) ) {
		update_option( 'WPSVGActivated', 1 );
	}

	return wpsvg_e2e_state();
}

/**
 * Reports what the tests cannot see in the browser.
 *
 * @return array `wp_version`, `php_version`, `plugin_active`, `settings`,
 *               `activation_flag`, `banner_dismissals` (campaign IDs) and
 *               `attachments` (ID, MIME type, file
 *               path relative to the uploads folder, stored content and
 *               metadata of each).
 */
function wpsvg_e2e_state() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$attachments = array();
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $attachment ) {
		$file          = get_attached_file( $attachment->ID );
		$attachments[] = array(
			'id'       => $attachment->ID,
			'mime'     => $attachment->post_mime_type,
			'file'     => get_post_meta( $attachment->ID, '_wp_attached_file', true ),
			'url'      => wp_get_attachment_url( $attachment->ID ),
			'content'  => is_readable( $file ) ? file_get_contents( $file ) : null,
			'metadata' => wp_get_attachment_metadata( $attachment->ID ),
			'author'   => get_userdata( $attachment->post_author ) ? get_userdata( $attachment->post_author )->user_login : null,
		);
	}

	return array(
		'wp_version'        => $GLOBALS['wp_version'],
		'php_version'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
		'plugin_active'     => is_plugin_active( WPSVG_E2E_PLUGIN ),
		'settings'          => get_option( 'wpsvg_settings', null ),
		'activation_flag'   => get_option( 'WPSVGActivated', null ),
		'banner_dismissals' => array_keys( (array) get_option( 'wpsvg-promo-banner', array() ) ),
		'attachments'       => $attachments,
	);
}
