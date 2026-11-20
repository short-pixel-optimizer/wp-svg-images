<?php

/**
 * Proves the `integration` suite runs against real WordPress with the plugin
 * active and a working database.
 *
 * Used by `bin/test.sh --verify`, which expects exactly one test to execute
 * here.
 */
class EnvironmentCanaryTest extends WP_UnitTestCase {

	/**
	 * WordPress is the version bin/test.sh resolved, the plugin is active and
	 * has registered its upload gate, and a database write can be read back.
	 */
	public function test_integration_canary() {
		$expected_wp = getenv( 'WPSVG_EXPECTED_WP' );
		if ( 'nightly' === $expected_wp ) {
			$this->assertStringContainsString( '-', $GLOBALS['wp_version'], 'Nightly builds carry a pre-release suffix' );
		} else {
			$this->assertSame( $expected_wp, $GLOBALS['wp_version'], 'WordPress version' );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertTrue( is_plugin_active( 'wp-svg-images/wp-svg-images.php' ), 'Plugin is active' );

		$gate_registered = false;
		foreach ( $GLOBALS['wp_filter']['upload_mimes']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WPSVG && 'add_svg_mime' === $callback['function'][1] ) {
					$gate_registered = true;
				}
			}
		}
		$this->assertTrue( $gate_registered, 'WPSVG::add_svg_mime is hooked to upload_mimes' );

		global $wpdb;
		$value = wp_generate_password( 16, false );
		update_option( 'wpsvg_canary', $value );
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wpsvg_canary' ) );
		$this->assertSame( $value, $stored, 'Database round trip' );
	}
}
