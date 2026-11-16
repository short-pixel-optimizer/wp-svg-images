<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * The plugin next to an active ShortPixel Image Optimizer: the offer goes
 * away and SVG uploads work as without it.
 *
 * ShortPixel Image Optimizer has no API key here and the run is offline, so
 * it optimizes nothing; the tests check that it leaves SVG uploads alone.
 */
class ShortPixelActiveTest extends CompatTestCase {

	const SPIO = 'shortpixel-image-optimiser/wp-shortpixel.php';

	/**
	 * Addresses of the HTTP requests attempted during the test.
	 *
	 * @var ArrayObject
	 */
	private $requests;

	public function set_up() {
		parent::set_up();
		$requests       = new ArrayObject();
		$this->requests = $requests;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $requests ) {
				$requests[] = $url;

				return new WP_Error( 'wpsvg_offline', 'Tests run offline.' );
			},
			10,
			3
		);
	}

	public function tear_down() {
		$this->plugin()->spio_installed    = false;
		$this->plugin()->spio_active       = false;
		$this->plugin()->plugin_admin_page = null;
		parent::tear_down();
	}

	public function test_scenario_runs_shortpixel_image_optimizer_at_the_pinned_version() {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/plugins.json' ), true );

		$this->assertSame( 'shortpixel', $this->scenario() );
		$this->assertSame( array( self::SPIO, 'wp-svg-images/wp-svg-images.php' ), get_option( 'active_plugins' ) );
		$this->assertSame( $manifest['plugins']['shortpixel-image-optimiser'], SHORTPIXEL_IMAGE_OPTIMISER_VERSION );
		$this->assertTrue( is_plugin_active( self::SPIO ) );
		$this->assertSame( array( 'wp-svg-images/svg-sanitizer' ), $this->sanitizer_copies() );
	}

	public function test_detection_finds_the_plugin_active_and_loads_no_offer_assets() {
		$this->plugin()->upsell();

		$this->assertTrue( $this->plugin()->spio_installed );
		$this->assertTrue( $this->plugin()->spio_active );
		$this->assertFalse( wp_style_is( 'spio-upsell', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'spio-upsell', 'enqueued' ) );
	}

	public function test_settings_page_has_no_offer() {
		$this->log_in_as( 'administrator' );
		$this->plugin()->upsell();
		do_action( 'admin_menu' );
		set_current_screen( $this->plugin()->plugin_admin_page );

		ob_start();
		do_action( $this->plugin()->plugin_admin_page );
		$html = ob_get_clean();

		$this->assertStringContainsString( '<div class="wpsvg-wrapper">', $html );
		$this->assertStringContainsString( '<select name="role_administrator"', $html );
		$this->assertStringNotContainsString( 'shortpixel-offer', $html );
	}

	public function test_svg_upload_is_sanitized_and_measured_as_without_the_plugin() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		list( $id, $warnings ) = $this->with_warnings_captured(
			function () {
				return $this->media_upload( '<svg ' . self::SVG_NS . ' width="30" height="20" onload="alert(1)"><script>alert(1)</script><rect/></svg>' );
			}
		);

		$this->assertIsInt( $id );
		$this->assertSame( array(), $warnings );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( '<svg ' . self::SVG_NS . ' width="30" height="20"><rect></rect></svg>', $this->stored_content( $id ) );
		$metadata = wp_get_attachment_metadata( $id );
		$this->assertSame( 30.0, $metadata['width'] );
		$this->assertSame( 20.0, $metadata['height'] );
		$this->assertSame( array(), $this->requests->getArrayCopy(), 'No request is attempted for an SVG upload' );
	}

	public function test_media_library_data_and_image_block_are_unchanged() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$id = $this->media_upload( '<svg ' . self::SVG_NS . ' width="30" height="20"><rect/></svg>' );

		$data = wp_prepare_attachment_for_js( $id );
		$html = do_blocks( '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . wp_get_attachment_url( $id ) . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->' );

		$this->assertSame( wp_get_attachment_url( $id ), $data['sizes']['full']['url'] );
		$this->assertEquals( 30, $data['sizes']['full']['width'] );
		$this->assertStringContainsString( '<img width="30" height="20" src=', $html );
	}

	public function test_unrestricted_upload_is_stored_as_sent() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );

		$id = $this->media_upload( self::DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertSame( self::DIRTY_SVG, $this->stored_content( $id ) );
	}
}
