<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * The plugin next to the Gutenberg plugin, which replaces WordPress's own
 * image block with a newer one. The plugin adds width and height to the
 * block's `<img>` through `render_block`, whatever renders the block.
 *
 * The scenario `gutenberg-previous` runs an older Gutenberg release, the
 * newest that still supports the oldest WordPress of the matrix.
 */
class GutenbergTest extends CompatTestCase {

	/**
	 * Attachment ID of an SVG that declares 30 x 20.
	 *
	 * @var int
	 */
	private $attachment_id;

	public function set_up() {
		parent::set_up();
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );
		$this->attachment_id = $this->media_upload( '<svg ' . self::SVG_NS . ' width="30" height="20"><rect/></svg>' );
		$this->assertIsInt( $this->attachment_id );
	}

	/**
	 * @param string $attributes Extra block attributes, as JSON members with a trailing comma.
	 * @param string $figure     Extra classes of the `<figure>`.
	 * @param string $img        Extra attributes of the `<img>`, with a leading space.
	 * @param bool   $linked     Whether the image links to the file.
	 * @return string Serialized image block as the Gutenberg editor saves it.
	 */
	private function image_block( $attributes = '', $figure = '', $img = '', $linked = false ) {
		$id    = $this->attachment_id;
		$url   = wp_get_attachment_url( $id );
		$image = '<img src="' . $url . '" alt="" class="wp-image-' . $id . '"' . $img . '/>';
		if ( $linked ) {
			$image = '<a href="' . $url . '">' . $image . '</a>';
		}

		return '<!-- wp:image {' . $attributes . '"id":' . $id . ',"sizeSlug":"full","linkDestination":"' . ( $linked ? 'media' : 'none' ) . '"} --><figure class="wp-block-image size-full' . $figure . '">' . $image . '</figure><!-- /wp:image -->';
	}

	public function test_scenario_runs_the_gutenberg_plugin_at_the_pinned_version() {

		$this->assertContains( $this->scenario(), array( 'gutenberg', 'gutenberg-previous' ) );
		$this->assertSame( array( 'gutenberg/gutenberg.php', 'wp-svg-images/wp-svg-images.php' ), get_option( 'active_plugins' ) );
		$this->assertSame( $this->pinned_version( 'gutenberg' ), GUTENBERG_VERSION );
	}

	public function test_image_block_is_rendered_by_the_gutenberg_plugin() {
		$callback = WP_Block_Type_Registry::get_instance()->get_registered( 'core/image' )->render_callback;

		$this->assertIsString( $callback );
		$this->assertStringStartsWith( WP_PLUGIN_DIR . '/gutenberg/', ( new ReflectionFunction( $callback ) )->getFileName() );
	}

	/**
	 * @dataProvider provide_block_variants
	 */
	public function test_image_block_variant_gets_width_and_height( $attributes, $figure, $img, $linked ) {
		$html = do_blocks( $this->image_block( $attributes, $figure, $img, $linked ) );

		$this->assertSame( 1, substr_count( $html, '<img ' ) );
		$this->assertSame( 1, preg_match( '~<img [^>]*width="30" height="20" src="[^"]+\.svg"~', $html ), $html );
	}

	public function provide_block_variants() {
		return array(
			'plain'                  => array( '', '', '', false ),
			'linked to the file'     => array( '', '', '', true ),
			'expand on click'        => array( '"lightbox":{"enabled":true},', '', '', false ),
			'resized in the editor'  => array( '"width":"100px","height":"auto",', ' is-resized', ' style="width:100px;height:auto"', false ),
			'aspect ratio and scale' => array( '"aspectRatio":"1","scale":"cover",', '', ' style="aspect-ratio:1;object-fit:cover"', false ),
		);
	}

	public function test_dimensions_survive_the_content_filters() {
		$html = apply_filters( 'the_content', $this->image_block() );

		$this->assertSame( 1, preg_match( '~<img [^>]*width="30" height="20" src=~', $html ), $html );
		$this->assertSame( 1, substr_count( $html, ' width=' ) );
	}

	public function test_image_block_with_another_image_type_is_left_alone() {
		$png = $this->media_upload( base64_decode( self::PNG_BASE64 ), 'pixel.png' );
		$this->assertIsInt( $png );

		$html = do_blocks( '<!-- wp:image {"id":' . $png . ',"sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full"><img src="' . wp_get_attachment_url( $png ) . '" alt="" class="wp-image-' . $png . '"/></figure><!-- /wp:image -->' );

		$this->assertStringNotContainsString( 'width=', $html );
	}
}
