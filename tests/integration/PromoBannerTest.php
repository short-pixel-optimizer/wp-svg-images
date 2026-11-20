<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

use ShortPixel\SVG\PromoBanner\PromoBanner;

/**
 * The plugin's own campaign banner: WPSVG::promo_banner() and its place on
 * the settings page.
 *
 * The test bootstrap closes the campaign window for every test;
 * open_window() opens it through the same filter, so nothing here depends on
 * the day the suite runs. The real dates are checked with explicit
 * timestamps. The module's own conditions are covered by
 * PromoBannerModuleTest, the dismiss request by PromoBannerAjaxTest.
 *
 * ShortPixel Image Optimizer is marked as active on the plugin instance so
 * the settings page is rendered without the upsell box.
 */
class PromoBannerTest extends WPSVGIntegrationTestCase {

	const OPTION   = 'wpsvg-promo-banner';
	const ACTION   = 'wpsvg_promo_banner_dismiss';
	const HANDLE   = 'wpsvg_promo_banner';
	const CAMPAIGN = 'black-friday-2026';
	const LINK     = 'https://shortpixel.com/pricing?utm_source=plugin&utm_medium=wpsvg&utm_campaign=top_banner_bf2026';

	public function set_up() {
		parent::set_up();
		$this->plugin()->spio_installed = true;
		$this->plugin()->spio_active    = true;
	}

	public function tear_down() {
		$this->plugin()->spio_installed    = false;
		$this->plugin()->spio_active       = false;
		$this->plugin()->plugin_admin_page = null;
		$this->plugin()->promo_banner      = null;
		unset( $GLOBALS['wp_styles'], $GLOBALS['wp_scripts'] );
		parent::tear_down();
	}

	/**
	 * Starts an admin request: the user is logged in, the menu is built, the
	 * screen is set and the plugin has set its banner up.
	 *
	 * @param string|null $screen Screen ID; null for the settings page.
	 * @param string      $role   Role slug.
	 * @return PromoBanner The plugin's banner.
	 */
	private function admin_request( $screen = null, $role = 'administrator' ) {
		$this->log_in_as( $role );
		do_action( 'admin_menu' );
		set_current_screen( null === $screen ? $this->settings_screen() : $screen );
		$this->plugin()->promo_banner();

		return $this->plugin()->promo_banner;
	}

	/**
	 * @return string Screen ID of the settings page, once the menu is built.
	 */
	private function settings_screen() {
		return $this->plugin()->plugin_admin_page;
	}

	/**
	 * The campaign is running; the bootstrap says it is not, at priority 10.
	 */
	private function open_window() {
		add_filter( 'wpsvg/promo_banner/in_window', '__return_true', 20 );
	}

	/**
	 * @param PromoBanner $banner
	 * @return array Its configuration.
	 */
	private function configuration( PromoBanner $banner ) {
		$property = new ReflectionProperty( PromoBanner::class, 'args' );
		$property->setAccessible( true );

		return $property->getValue( $banner );
	}

	/**
	 * @param PromoBanner|null $banner
	 * @return string What the banner prints.
	 */
	private function printed( $banner ) {
		ob_start();
		$banner->render();

		return ob_get_clean();
	}

	/**
	 * @return string Output of the settings page for the current request.
	 */
	private function settings_page() {
		ob_start();
		try {
			do_action( $this->plugin()->plugin_admin_page );
		} finally {
			$html = ob_get_clean();
		}

		return $html;
	}

	/**
	 * @return string URL of the bundled module's assets folder.
	 */
	private function assets_url() {
		return plugins_url( '/build/shortpixel/promo-banner/assets/', WP_PLUGIN_DIR . '/wp-svg-images/wp-svg-images.php' );
	}

	/* Set-up. */

	public function test_prefix_names_the_dismissals_option() {
		$this->assertSame( 'wpsvg', WPSVG::PROMO_BANNER_PREFIX );
		$this->assertSame( self::OPTION, PromoBanner::optionNameFor( WPSVG::PROMO_BANNER_PREFIX ) );
	}

	public function test_plugin_has_no_banner_before_admin_init() {
		$this->assertNull( $this->plugin()->promo_banner );
		$this->assertFalse( has_action( 'wp_ajax_' . self::ACTION ) );
	}

	public function test_admin_init_registers_the_banner() {
		$banner = $this->admin_request( 'dashboard' );

		$this->assertInstanceOf( PromoBanner::class, $banner );
		$this->assertSame( self::ACTION, $banner->getAjaxAction() );
		$this->assertSame( self::OPTION, $banner->getOptionName() );
		$this->assertSame( 10, has_action( 'wp_ajax_' . self::ACTION, array( $banner, 'ajaxDismiss' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $banner, 'enqueue' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( $banner, 'render' ) ), 'The settings page prints it; it is not an admin notice' );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_' . self::ACTION ) );
	}

	public function test_campaign_configuration() {
		$configuration = $this->configuration( $this->admin_request() );

		$this->assertSame( self::CAMPAIGN, $configuration['id'] );
		$this->assertSame( gmmktime( 0, 0, 0, 11, 23, 2026 ), $configuration['start'] );
		$this->assertSame( gmmktime( 7, 59, 59, 12, 1, 2026 ), $configuration['end'], '23:59:59 US Pacific on 30 November' );
		$this->assertSame( '2026-11-30', $configuration['ends_on'] );
		$this->assertSame( self::LINK, $configuration['url'] );
		$this->assertSame( 'wpsvg', $configuration['prefix'] );
		$this->assertSame( $this->assets_url(), $configuration['assets_url'] );
		$this->assertSame( '1.0', $configuration['version'] );
		$this->assertSame( 'manage_options', $configuration['capability'] );
		$this->assertSame( array(), $configuration['screens'], 'Screens are matched by the condition only' );
		$this->assertFalse( $configuration['hook'] );
	}

	public function test_campaign_window_bounds() {
		$banner = $this->admin_request();
		$start  = gmmktime( 0, 0, 0, 11, 23, 2026 );
		$end    = gmmktime( 7, 59, 59, 12, 1, 2026 );

		$this->assertFalse( $banner->isInWindow( $start - 1 ) );
		$this->assertTrue( $banner->isInWindow( $start ) );
		$this->assertTrue( $banner->isInWindow( $end ) );
		$this->assertFalse( $banner->isInWindow( $end + 1 ) );
	}

	/* Where and to whom it shows. */

	/**
	 * @dataProvider provide_screens
	 */
	public function test_banner_shows_only_on_the_settings_page( $screen, $shown ) {
		$banner = $this->admin_request( $screen );
		$this->open_window();

		$this->assertSame( $shown, $banner->shouldShow() );
		$this->assertSame( $shown, '' !== $this->printed( $banner ) );
	}

	public function provide_screens() {
		return array(
			'settings page'         => array( null, true ),
			'dashboard'             => array( 'dashboard', false ),
			'plugins'               => array( 'plugins', false ),
			'media library'         => array( 'upload', false ),
			'general settings'      => array( 'options-general', false ),
			'another settings page' => array( 'settings_page_another-plugin', false ),
		);
	}

	public function test_banner_prints_nothing_outside_the_window() {
		$banner = $this->admin_request();

		$this->assertFalse( $banner->shouldShow() );
		$this->assertSame( '', $this->printed( $banner ) );
	}

	/**
	 * On admin-ajax.php the menu is not built, so no screen is the settings
	 * page there.
	 */
	public function test_banner_does_not_show_when_the_settings_page_is_not_registered() {
		$this->log_in_as( 'administrator' );
		set_current_screen( 'settings_page_wp-svg-images' );
		$this->plugin()->promo_banner();
		$this->open_window();

		$this->assertNull( $this->plugin()->plugin_admin_page );
		$this->assertFalse( $this->plugin()->promo_banner->shouldShow() );
	}

	/**
	 * @dataProvider provide_users_without_manage_options
	 */
	public function test_banner_is_not_shown_without_manage_options( $role ) {
		$banner = $this->admin_request();
		$this->open_window();
		wp_set_current_user( null === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );

		$this->assertSame( '', $this->printed( $banner ) );
	}

	public function provide_users_without_manage_options() {
		return array(
			'editor'     => array( 'editor' ),
			'subscriber' => array( 'subscriber' ),
			'visitor'    => array( null ),
		);
	}

	public function test_banner_is_not_shown_once_the_campaign_is_dismissed() {
		$banner = $this->admin_request();
		$this->open_window();
		update_option( self::OPTION, array( self::CAMPAIGN => time() ), false );

		$this->assertSame( '', $this->printed( $banner ) );
	}

	public function test_dismissal_of_an_earlier_campaign_does_not_hide_this_one() {
		$banner = $this->admin_request();
		$this->open_window();
		update_option( self::OPTION, array( 'black-friday-2025' => time() ), false );

		$this->assertNotSame( '', $this->printed( $banner ) );
	}

	/**
	 * @dataProvider provide_switches
	 */
	public function test_banner_is_switched_off_by_a_filter( $filter, $callback ) {
		$banner = $this->admin_request();
		$this->open_window();
		add_filter( $filter, $callback );

		$this->assertSame( '', $this->printed( $banner ) );
	}

	public function provide_switches() {
		return array(
			'the plugin switch'    => array( 'wpsvg/settings/no_banner', '__return_true' ),
			'the last-word filter' => array( 'wpsvg/promo_banner/show', '__return_false' ),
		);
	}

	/* Markup and assets. */

	public function test_printed_markup() {
		$banner = $this->admin_request();
		$this->open_window();

		$html = $this->printed( $banner );

		$this->assertStringStartsWith( '<div class="shortpixel-bf" role="region" aria-label="ShortPixel Black Friday offer"', $html, 'No `notice` class: WordPress does not move it' );
		$this->assertStringContainsString( 'data-action="' . self::ACTION . '"', $html );
		$this->assertStringContainsString( 'data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"', $html );
		$this->assertSame( 1, preg_match( '/data-nonce="([^"]+)"/', $html, $match ) );
		$this->assertNotFalse( wp_verify_nonce( $match[1], self::ACTION ) );
		$this->assertStringContainsString( 'href="' . esc_url( self::LINK ) . '" target="_blank" rel="noopener">Claim Deal<svg', $html );
		$this->assertStringContainsString( 'Offer ends <strong>November 30, 2026</strong>', $html, 'The day of `ends_on`, not the UTC day of `end`' );
		$this->assertStringContainsString( '<strong>25% OFF</strong> any yearly plan for life', $html );
		$this->assertStringContainsString( '<strong>2x</strong> One-Time Credits', $html );
		$this->assertStringContainsString( 'src="' . $this->assets_url() . 'img/robot.svg"', $html );
		$this->assertStringContainsString( '<button type="button" class="shortpixel-bf__close" aria-label="Dismiss">', $html );
	}

	public function test_assets_are_enqueued_where_the_banner_shows() {
		$banner = $this->admin_request();
		$this->open_window();
		$GLOBALS['wp_styles']  = new WP_Styles();
		$GLOBALS['wp_scripts'] = new WP_Scripts();

		$banner->enqueue();

		$this->assertTrue( wp_style_is( self::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
		$style  = wp_styles()->registered[ self::HANDLE ];
		$script = wp_scripts()->registered[ self::HANDLE ];
		$this->assertSame( array( $this->assets_url() . 'promo-banner.css', '1.0' ), array( $style->src, $style->ver ) );
		$this->assertSame( array( $this->assets_url() . 'promo-banner.js', '1.0', array() ), array( $script->src, $script->ver, $script->deps ) );
		$this->assertSame( 1, $script->extra['group'], 'In the footer' );
	}

	/**
	 * @dataProvider provide_requests_without_a_banner
	 */
	public function test_assets_are_not_enqueued_where_the_banner_does_not_show( $screen, $open ) {
		$banner = $this->admin_request( $screen );
		if ( $open ) {
			$this->open_window();
		}
		$GLOBALS['wp_styles']  = new WP_Styles();
		$GLOBALS['wp_scripts'] = new WP_Scripts();

		$banner->enqueue();

		$this->assertFalse( wp_style_is( self::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	public function provide_requests_without_a_banner() {
		return array(
			'another screen, window open'  => array( 'dashboard', true ),
			'settings page, window closed' => array( null, false ),
		);
	}

	/* The settings page. */

	public function test_settings_page_prints_the_banner_under_its_heading() {
		$this->admin_request();
		$this->open_window();

		$html = $this->settings_page();

		$this->assertSame( 1, substr_count( $html, 'class="shortpixel-bf"' ) );
		$heading = strpos( $html, '<h2>SVG images</h2>' );
		$banner  = strpos( $html, '<div class="shortpixel-bf"' );
		$notice  = strpos( $html, '<div class="postbox"' );
		$this->assertNotFalse( $heading );
		$this->assertNotFalse( $notice );
		$this->assertTrue( $heading < $banner && $banner < $notice, 'After the heading, before the security notice' );
	}

	public function test_settings_page_prints_no_banner_outside_the_window() {
		$this->admin_request();

		$html = $this->settings_page();

		$this->assertStringNotContainsString( 'shortpixel-bf', $html );
		$this->assertStringContainsString( '<h2>SVG images</h2>', $html );
	}

	/**
	 * The page is also rendered when `admin_init` did not set the banner up.
	 */
	public function test_settings_page_renders_without_a_banner_object() {
		$this->log_in_as( 'administrator' );
		do_action( 'admin_menu' );
		set_current_screen( $this->settings_screen() );
		$this->open_window();
		$this->assertNull( $this->plugin()->promo_banner );

		$html = $this->settings_page();

		$this->assertStringNotContainsString( 'shortpixel-bf', $html );
		$this->assertStringContainsString( '<h3>Security notice</h3>', $html );
	}
}
