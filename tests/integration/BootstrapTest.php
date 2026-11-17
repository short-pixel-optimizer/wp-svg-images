<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * The plugin as WordPress loads it: one instance, its hooks, activation and
 * the one-shot redirect to the settings page.
 */
class BootstrapTest extends WPSVGIntegrationTestCase {

	const PLUGIN = 'wp-svg-images/wp-svg-images.php';

	/**
	 * Calls add_svg_support() and closes the output buffer it opens.
	 *
	 * The method declares a global function, so it can run only once per
	 * PHP process; every test that uses this helper runs in its own process.
	 *
	 * @return array{location: string|null, buffer_opened: bool}
	 */
	private function run_add_svg_support() {
		$location = null;
		add_filter(
			'wp_redirect',
			function ( $target ) use ( &$location ) {
				$location = $target;
				return false; // Stops WordPress before it sends the header.
			}
		);

		$level = ob_get_level();
		$this->plugin()->add_svg_support();
		$buffer_opened = ob_get_level() === $level + 1;
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}

		return array(
			'location'      => $location,
			'buffer_opened' => $buffer_opened,
		);
	}

	public function test_wordpress_created_exactly_one_plugin_instance() {
		$this->assertCount( 1, $this->plugin_instances() );
	}

	/**
	 * @dataProvider provide_registered_hooks
	 */
	public function test_hook_is_registered_in_wordpress( $hook, $method, $priority ) {
		$this->assertSame( $priority, has_filter( $hook, array( $this->plugin(), $method ) ) );
	}

	public function provide_registered_hooks() {
		$hooks = array(
			array( 'wp_handle_upload_prefilter', 'wp_handle_upload_prefilter', 10 ),
			array( 'wp_handle_sideload_prefilter', 'wp_handle_upload_prefilter', 10 ),
			array( 'plugins_loaded', 'plugins_loaded', 10 ),
			array( 'admin_menu', 'plugin_menu_link', 10 ),
			array( 'init', 'plugin_init', 10 ),
			array( 'admin_init', 'add_svg_support', 10 ),
			array( 'admin_init', 'upsell', 10 ),
			array( 'admin_init', 'promo_banner', 10 ),
			array( 'admin_footer', 'fix_svg_thumbnail_size', 10 ),
			array( 'upload_mimes', 'add_svg_mime', 10 ),
			array( 'wp_check_filetype_and_ext', 'wp_check_filetype_and_ext', 100 ),
			array( 'wp_generate_attachment_metadata', 'wp_generate_attachment_metadata', 10 ),
			array( 'fl_module_upload_regex', 'fl_module_upload_regex', 10 ),
			array( 'render_block', 'fix_missing_width_height_on_image_block', 10 ),
			array( 'admin_notices', 'admin_notices', 10 ),
			array( 'wp_ajax_wpsvg_notice_dismissed', 'wpsvg_notice_dismissed', 10 ),
			array( 'wp_all_import_image_mime_type', 'wp_all_import_svgs', 10 ),
		);

		$cases = array();
		foreach ( $hooks as $hook ) {
			$cases[ "$hook[0] -> $hook[1]" ] = $hook;
		}

		return $cases;
	}

	public function test_settings_are_false_on_a_site_that_never_saved_them() {
		$this->assertFalse( get_option( 'wpsvg_settings' ) );
		$this->assertFalse( $this->plugin()->settings );
	}

	public function test_settings_follow_the_option_after_init_runs_again() {
		update_option( 'wpsvg_settings', array( 'role_editor' => 1 ) );
		$this->assertFalse( $this->plugin()->settings, 'Not read until init' );

		$this->plugin()->plugin_init();

		$this->assertSame( array( 'role_editor' => 1 ), $this->plugin()->settings );
	}

	/* Activation. */

	public function test_activation_hook_is_registered_for_the_plugin() {
		$this->assertTrue( has_action( 'activate_' . self::PLUGIN ) );
	}

	public function test_activation_stores_the_redirect_flag() {
		$this->assertFalse( get_option( 'WPSVGActivated' ) );

		do_action( 'activate_' . self::PLUGIN );

		$this->assertEquals( 1, get_option( 'WPSVGActivated' ) );
	}

	/**
	 * Activation does not create the permissions: no role may upload SVGs
	 * until the settings page is saved.
	 */
	public function test_activation_does_not_create_the_settings_option() {
		do_action( 'activate_' . self::PLUGIN );

		$this->assertFalse( get_option( 'wpsvg_settings' ) );
	}

	/* The one-shot redirect and the admin output buffer. */

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_first_admin_request_after_activation_redirects_to_the_settings_page_once() {
		update_option( 'WPSVGActivated', 1 );
		set_current_screen( 'dashboard' );

		$result = $this->run_add_svg_support();

		$this->assertSame( admin_url( 'options-general.php?page=wp-svg-images.php' ), $result['location'] );
		$this->assertFalse( get_option( 'WPSVGActivated' ), 'The flag is deleted' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_request_without_the_flag_does_not_redirect() {
		set_current_screen( 'dashboard' );

		$result = $this->run_add_svg_support();

		$this->assertNull( $result['location'] );
	}

	/**
	 * `admin_init` also fires outside the dashboard (admin-post.php without
	 * is_admin() is not one of them, but direct calls are): the flag is kept
	 * for the next real admin request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_request_outside_the_admin_keeps_the_flag() {
		update_option( 'WPSVGActivated', 1 );
		$this->assertFalse( is_admin() );

		$result = $this->run_add_svg_support();

		$this->assertNull( $result['location'] );
		$this->assertEquals( 1, get_option( 'WPSVGActivated' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_init_opens_an_output_buffer_and_registers_the_preview_filters() {
		$plugin = $this->plugin();
		$this->assertFalse( has_filter( 'final_output', array( $plugin, 'final_output' ) ) );
		$this->assertFalse( function_exists( 'svg_thumbs' ) );

		$result = $this->run_add_svg_support();

		$this->assertTrue( $result['buffer_opened'] );
		$this->assertTrue( function_exists( 'svg_thumbs' ) );
		$this->assertSame( 10, has_filter( 'final_output', array( $plugin, 'final_output' ) ) );
		$this->assertSame( 10, has_filter( 'wp_prepare_attachment_for_js', array( $plugin, 'wp_prepare_attachment_for_js' ) ) );
	}
}
