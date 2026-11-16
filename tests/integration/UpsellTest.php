<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * The ShortPixel Image Optimizer offer: how the plugin detects that plugin,
 * when it loads the offer's assets and what the box on the settings page
 * shows.
 *
 * ShortPixel Image Optimizer is simulated through WordPress's list of
 * installed plugins and the `active_plugins` option; the `compat` suite uses
 * the real plugin.
 */
class UpsellTest extends WPSVGIntegrationTestCase {

	const SPIO = 'shortpixel-image-optimiser/wp-shortpixel.php';

	public function tear_down() {
		wp_dequeue_style( 'spio-upsell' );
		wp_deregister_style( 'spio-upsell' );
		wp_dequeue_script( 'spio-upsell' );
		wp_deregister_script( 'spio-upsell' );
		wp_cache_delete( 'plugins', 'plugins' );
		$this->plugin()->spio_installed    = false;
		$this->plugin()->spio_active       = false;
		$this->plugin()->plugin_admin_page = null;
		parent::tear_down();
	}

	/**
	 * @param bool $installed Whether WordPress lists the plugin as installed.
	 * @param bool $active    Whether the plugin is in `active_plugins`.
	 */
	private function simulate_spio( $installed, $active ) {
		$plugins = get_plugins();
		if ( $installed ) {
			$plugins[ self::SPIO ] = array(
				'Name'    => 'ShortPixel Image Optimizer',
				'Version' => '1.0',
			);
		}
		wp_cache_set( 'plugins', array( '' => $plugins ), 'plugins' );

		if ( $active ) {
			// The test bootstrap fixes `active_plugins` through this filter,
			// so the stored option is never read.
			add_filter(
				'pre_option_active_plugins',
				static function () {
					return array( 'wp-svg-images/wp-svg-images.php', UpsellTest::SPIO );
				},
				99
			);
		}
	}

	/**
	 * Runs the detection and renders the settings page as an administrator.
	 *
	 * @return string Page output.
	 */
	private function settings_page() {
		$this->log_in_as( 'administrator' );
		$this->plugin()->upsell();
		do_action( 'admin_menu' );
		set_current_screen( $this->plugin()->plugin_admin_page );

		ob_start();
		try {
			do_action( $this->plugin()->plugin_admin_page );
		} finally {
			$html = ob_get_clean();
		}

		return $html;
	}

	/**
	 * @param string $html  Page output.
	 * @param string $class First class of the link.
	 * @return array{href: string, classes: string[]}
	 */
	private function upsell_link( $html, $class ) {
		$this->assertSame( 1, preg_match( '~<a href="([^"]*)" class="(' . $class . '[^"]*)"~', $html, $match ), "The box has a $class link" );

		return array(
			'href'    => html_entity_decode( $match[1] ),
			'classes' => preg_split( '/\s+/', trim( $match[2] ) ),
		);
	}

	/* Detection and assets. */

	/**
	 * @dataProvider provide_spio_states
	 */
	public function test_detection_and_assets_follow_the_state_of_shortpixel_image_optimizer( $installed, $active, $offer ) {
		$this->simulate_spio( $installed, $active );

		$this->plugin()->upsell();

		$this->assertSame( $installed, $this->plugin()->spio_installed );
		$this->assertSame( $active, $this->plugin()->spio_active );
		$this->assertSame( $offer, wp_style_is( 'spio-upsell', 'enqueued' ) );
		$this->assertSame( $offer, wp_script_is( 'spio-upsell', 'enqueued' ) );
	}

	public function provide_spio_states() {
		return array(
			'not installed'                   => array( false, false, true ),
			'installed, not active'           => array( true, false, true ),
			'installed and active'            => array( true, true, false ),
			'active entry without the plugin' => array( false, true, true ),
		);
	}

	public function test_assets_come_from_the_plugin_folder_and_the_script_needs_jquery() {
		$this->simulate_spio( false, false );

		$this->plugin()->upsell();

		$base = WP_PLUGIN_URL . '/wp-svg-images/assets/';
		$this->assertSame( $base . 'css/spio-upsell.css', wp_styles()->registered['spio-upsell']->src );
		$this->assertSame( $base . 'js/spio-upsell.js', wp_scripts()->registered['spio-upsell']->src );
		$this->assertSame( array( 'jquery' ), wp_scripts()->registered['spio-upsell']->deps );
		$this->assertFileExists( WP_PLUGIN_DIR . '/wp-svg-images/assets/css/spio-upsell.css' );
		$this->assertFileExists( WP_PLUGIN_DIR . '/wp-svg-images/assets/js/spio-upsell.js' );
	}

	public function test_flags_are_false_until_the_detection_runs() {
		$this->simulate_spio( true, true );

		$fresh = new WPSVG();

		$this->assertFalse( $fresh->spio_installed );
		$this->assertFalse( $fresh->spio_active );
	}

	/* The box on the settings page. The partial is loaded with include_once,
	   so each rendering needs its own PHP process. */

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_page_offers_the_installation_when_the_plugin_is_not_installed() {
		$this->simulate_spio( false, false );

		$html = $this->settings_page();

		$this->assertSame( 1, substr_count( $html, '<div class="shortpixel-offer" data-plugin="shortpixel-image-optimiser">' ) );
		$install  = $this->upsell_link( $html, 'upsell-installer' );
		$activate = $this->upsell_link( $html, 'upsell-activate' );
		$this->assertNotContains( 'hidden', $install['classes'] );
		$this->assertContains( 'hidden', $activate['classes'] );
		$this->assertStringContainsString( 'data-alt-text="Installing ..."', $html );
		$this->assertStringContainsString( '<h4 class="upsell-activate-done hidden">', $html );
		$this->assertStringContainsString( 'src="' . WP_PLUGIN_URL . '/wp-svg-images/assets/images/shortpixel-image-optimiser.svg"', $html );
		$this->assertFileExists( WP_PLUGIN_DIR . '/wp-svg-images/assets/images/shortpixel-image-optimiser.svg' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_install_and_activate_links_carry_the_nonces_wordpress_expects() {
		$this->simulate_spio( false, false );

		$html = $this->settings_page();

		$install = $this->upsell_link( $html, 'upsell-installer' );
		$this->assertStringStartsWith( self_admin_url( 'update.php?action=install-plugin&plugin=shortpixel-image-optimiser&_wpnonce=' ), $install['href'] );
		parse_str( (string) wp_parse_url( $install['href'], PHP_URL_QUERY ), $install_query );
		$this->assertSame( 1, wp_verify_nonce( $install_query['_wpnonce'], 'install-plugin_shortpixel-image-optimiser' ) );

		$activate = $this->upsell_link( $html, 'upsell-activate' );
		$this->assertStringStartsWith( 'plugins.php?action=activate&plugin=' . rawurlencode( self::SPIO ) . '&', $activate['href'] );
		parse_str( (string) wp_parse_url( $activate['href'], PHP_URL_QUERY ), $activate_query );
		$this->assertSame( self::SPIO, $activate_query['plugin'] );
		$this->assertSame( 1, wp_verify_nonce( $activate_query['_wpnonce'], 'activate-plugin_' . self::SPIO ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_page_offers_the_activation_when_the_plugin_is_installed_but_inactive() {
		$this->simulate_spio( true, false );

		$html = $this->settings_page();

		$install  = $this->upsell_link( $html, 'upsell-installer' );
		$activate = $this->upsell_link( $html, 'upsell-activate' );
		$this->assertContains( 'hidden', $install['classes'] );
		$this->assertNotContains( 'hidden', $activate['classes'] );
	}

	public function test_settings_page_has_no_offer_when_the_plugin_is_active() {
		$this->simulate_spio( true, true );

		$html = $this->settings_page();

		$this->assertStringContainsString( '<div class="wpsvg-wrapper">', $html );
		$this->assertStringNotContainsString( 'shortpixel-offer', $html );
	}
}
