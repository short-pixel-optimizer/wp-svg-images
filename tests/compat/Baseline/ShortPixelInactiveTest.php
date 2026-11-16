<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * The real ShortPixel Image Optimizer installed but not active: the plugin
 * detects it and offers its activation. The `integration` suite covers the
 * plugin not being installed at all, the `shortpixel` scenario its being
 * active.
 */
class ShortPixelInactiveTest extends CompatTestCase {

	const SPIO = 'shortpixel-image-optimiser/wp-shortpixel.php';

	public function tear_down() {
		wp_dequeue_style( 'spio-upsell' );
		wp_deregister_style( 'spio-upsell' );
		wp_dequeue_script( 'spio-upsell' );
		wp_deregister_script( 'spio-upsell' );
		$this->plugin()->spio_installed    = false;
		$this->plugin()->spio_active       = false;
		$this->plugin()->plugin_admin_page = null;
		parent::tear_down();
	}

	public function test_real_plugin_is_installed_at_the_pinned_version_and_not_active() {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/plugins.json' ), true );

		$this->assertSame( $manifest['plugins']['shortpixel-image-optimiser'], get_plugins()[ self::SPIO ]['Version'] );
		$this->assertFalse( is_plugin_active( self::SPIO ) );
	}

	public function test_detection_finds_the_plugin_installed_but_inactive_and_loads_the_offer_assets() {
		$this->plugin()->upsell();

		$this->assertTrue( $this->plugin()->spio_installed );
		$this->assertFalse( $this->plugin()->spio_active );
		$this->assertTrue( wp_style_is( 'spio-upsell', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'spio-upsell', 'enqueued' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_page_offers_to_activate_the_installed_plugin() {
		$this->log_in_as( 'administrator' );
		$this->plugin()->upsell();
		do_action( 'admin_menu' );
		set_current_screen( $this->plugin()->plugin_admin_page );

		ob_start();
		do_action( $this->plugin()->plugin_admin_page );
		$html = ob_get_clean();

		$this->assertSame( 1, preg_match( '~<a href="[^"]*" class="upsell-installer ([^"]*)"~', $html, $install ) );
		$this->assertSame( 1, preg_match( '~<a href="([^"]*)" class="upsell-activate ([^"]*)"~', $html, $activate ) );
		$this->assertStringContainsString( 'hidden', $install[1] );
		$this->assertStringNotContainsString( 'hidden', $activate[2] );
		parse_str( (string) wp_parse_url( html_entity_decode( $activate[1] ), PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( $query['plugin'], get_plugins(), 'The link activates a plugin WordPress knows' );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], 'activate-plugin_' . $query['plugin'] ) );
	}
}
