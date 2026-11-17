<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * SVG attachments after the upload: the dimensions stored in the attachment
 * metadata, the data the Media Library receives and the image block markup.
 */
class AttachmentTest extends WPSVGIntegrationTestCase {

	const NS = 'xmlns="http://www.w3.org/2000/svg"';

	public function set_up() {
		parent::set_up();
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );
	}

	/**
	 * @param string $root_attributes Attributes of the root element.
	 * @param string $name            File name.
	 * @return int Attachment ID.
	 */
	private function svg_attachment( $root_attributes = 'width="30" height="20"', $name = 'image.svg' ) {
		$attributes = trim( self::NS . ' ' . $root_attributes );
		$id         = $this->media_upload( "<svg $attributes><rect/></svg>", $name );
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * @return int Attachment ID of a gzipped SVG that declares 30 x 20.
	 */
	private function gzipped_svg_attachment() {
		list( $id ) = $this->with_warnings_captured(
			function () {
				return $this->media_upload( gzencode( '<svg ' . self::NS . ' width="30" height="20"><rect/></svg>' ), 'image.svgz' );
			}
		);
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * @return int Attachment ID of a one-pixel PNG.
	 */
	private function png_attachment() {
		$id = $this->media_upload( base64_decode( self::PNG_BASE64 ), 'pixel.png' );
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * @param int    $id         Attachment ID.
	 * @param string $attributes Extra attributes of the `<img>` element.
	 * @return string Serialized `core/image` block as the editor saves it.
	 */
	private function image_block( $id, $attributes = '' ) {
		$url = wp_get_attachment_url( $id );

		return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . $url . '" alt="" ' . $attributes . 'class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
	}

	/* Attachment metadata. */

	/**
	 * @dataProvider provide_root_attributes_and_dimensions
	 */
	public function test_upload_stores_width_and_height_in_the_attachment_metadata( $root_attributes, $width, $height ) {
		$id = $this->svg_attachment( $root_attributes );

		$metadata = wp_get_attachment_metadata( $id );

		$this->assertSame( $width, $metadata['width'] );
		$this->assertSame( $height, $metadata['height'] );
	}

	public function provide_root_attributes_and_dimensions() {
		return array(
			'width and height'            => array( 'width="30" height="20"', 30.0, 20.0 ),
			'units are dropped'           => array( 'width="30px" height="2.5em"', 30.0, 2.5 ),
			'viewBox only'                => array( 'viewBox="0 0 300 200"', 300.0, 200.0 ),
			'width and height win'        => array( 'width="30" height="20" viewBox="0 0 300 200"', 30.0, 20.0 ),
			'percentages fall back'       => array( 'width="100%" height="100%" viewBox="0 0 300 200"', 300.0, 200.0 ),
			'neither size nor viewBox'    => array( '', 0, 0 ),
			'percentages without viewBox' => array( 'width="100%" height="100%"', 0, 0 ),
		);
	}

	public function test_regenerating_the_metadata_keeps_the_other_keys() {
		$id = $this->svg_attachment();
		add_filter(
			'wp_generate_attachment_metadata',
			static function ( $metadata ) {
				$metadata['from_another_plugin'] = 'kept';

				return $metadata;
			},
			5
		);

		$metadata = wp_generate_attachment_metadata( $id, get_attached_file( $id ) );

		$this->assertSame( 'kept', $metadata['from_another_plugin'] );
		$this->assertSame( 30.0, $metadata['width'] );
		$this->assertSame( 20.0, $metadata['height'] );
	}

	public function test_metadata_of_other_attachments_is_left_alone() {
		$id = $this->png_attachment();

		$metadata = wp_generate_attachment_metadata( $id, get_attached_file( $id ) );

		$this->assertSame( 1, $metadata['width'] );
		$this->assertSame( 1, $metadata['height'] );
		$this->assertArrayHasKey( 'image_meta', $metadata );
	}

	public function test_metadata_is_zero_with_php_warnings_when_the_file_is_gone() {
		$id   = $this->svg_attachment();
		$path = get_attached_file( $id );
		unlink( $path );

		list( $metadata, $warnings ) = $this->with_warnings_captured(
			static function () use ( $id, $path ) {
				return wp_generate_attachment_metadata( $id, $path );
			}
		);

		$this->assertSame( 0, $metadata['width'] );
		$this->assertSame( 0, $metadata['height'] );
		$this->assertStringContainsString( 'simplexml_load_file()', implode( "\n", $warnings ) );
	}

	/**
	 * Pins the defect in WPSVG::svg_dimensions(): a gzipped SVG is not
	 * decoded, so its attachment gets zero dimensions. When fixed, the
	 * metadata holds the 30 x 20 the file declares.
	 *
	 * @group pinned
	 */
	public function test_gzipped_svg_attachment_gets_zero_dimensions_pinned_for_deferred_fix() {
		$id = $this->gzipped_svg_attachment();

		$metadata = wp_get_attachment_metadata( $id );

		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( 0, $metadata['width'] );
		$this->assertSame( 0, $metadata['height'] );
	}

	/**
	 * With the stored dimensions, WordPress itself can print the image.
	 */
	public function test_wordpress_image_functions_use_the_stored_dimensions() {
		$id = $this->svg_attachment();

		$source = wp_get_attachment_image_src( $id, 'full' );
		$markup = wp_get_attachment_image( $id, 'full' );

		$this->assertSame( wp_get_attachment_url( $id ), $source[0] );
		$this->assertEquals( 30, $source[1] );
		$this->assertEquals( 20, $source[2] );
		$this->assertStringContainsString( 'width="30" height="20"', $markup );
	}

	/* Media Library data: wp_prepare_attachment_for_js(). */

	/**
	 * @param int $id Attachment ID.
	 * @return array The plugin's result for WordPress's own Media Library data.
	 */
	private function prepared_by_the_plugin( $id ) {
		$response = wp_prepare_attachment_for_js( $id );

		return $this->plugin()->wp_prepare_attachment_for_js( $response, get_post( $id ), wp_get_attachment_metadata( $id ) );
	}

	public function test_media_library_data_built_by_wordpress_from_the_metadata_is_kept() {
		$id       = $this->svg_attachment();
		$response = wp_prepare_attachment_for_js( $id );
		$this->assertSame( 'svg+xml', $response['subtype'] );
		$this->assertEquals( 30, $response['sizes']['full']['width'], 'WordPress builds the full size from the stored dimensions' );

		$result = $this->plugin()->wp_prepare_attachment_for_js( $response, get_post( $id ), wp_get_attachment_metadata( $id ) );

		$this->assertSame( $response, $result );
	}

	/**
	 * @dataProvider provide_dimensions_and_orientation
	 */
	public function test_media_library_data_gets_a_full_size_when_the_attachment_has_no_metadata( $root_attributes, $width, $height, $orientation ) {
		$id = $this->svg_attachment( $root_attributes );
		delete_post_meta( $id, '_wp_attachment_metadata' );
		$this->assertArrayNotHasKey( 'sizes', wp_prepare_attachment_for_js( $id ), 'WordPress alone offers no size' );

		$result = $this->prepared_by_the_plugin( $id );

		$this->assertSame(
			array(
				'full' => array(
					'url'         => wp_get_attachment_url( $id ),
					'width'       => $width,
					'height'      => $height,
					'orientation' => $orientation,
				),
			),
			$result['sizes']
		);
	}

	public function provide_dimensions_and_orientation() {
		return array(
			'wide'                      => array( 'width="30" height="20"', 30.0, 20.0, 'landscape' ),
			'tall'                      => array( 'width="20" height="30"', 20.0, 30.0, 'portrait' ),
			'square counts as portrait' => array( 'width="20" height="20"', 20.0, 20.0, 'portrait' ),
			'from the viewBox'          => array( 'viewBox="0 0 300 200"', 300.0, 200.0, 'landscape' ),
			'no dimensions'             => array( '', 0, 0, 'portrait' ),
		);
	}

	public function test_media_library_data_of_other_attachments_is_left_alone() {
		$id = $this->png_attachment();
		delete_post_meta( $id, '_wp_attachment_metadata' );
		$response = wp_prepare_attachment_for_js( $id );

		$result = $this->plugin()->wp_prepare_attachment_for_js( $response, get_post( $id ), array() );

		$this->assertSame( $response, $result );
	}

	/**
	 * A file that is not on this server (offloaded media) is read from the
	 * attachment address instead.
	 */
	public function test_media_library_data_reads_the_dimensions_from_the_address_when_the_file_is_not_local() {
		$id = $this->svg_attachment();
		delete_post_meta( $id, '_wp_attachment_metadata' );
		unlink( get_attached_file( $id ) );
		$elsewhere = $this->temporary_file( '<svg ' . self::NS . ' width="64" height="48"/>' );
		add_filter(
			'wp_get_attachment_url',
			static function () use ( $elsewhere ) {
				return $elsewhere;
			}
		);

		$result = $this->prepared_by_the_plugin( $id );

		$this->assertSame( $elsewhere, $result['sizes']['full']['url'] );
		$this->assertSame( 64.0, $result['sizes']['full']['width'] );
		$this->assertSame( 48.0, $result['sizes']['full']['height'] );
	}

	/**
	 * The callback is registered on `admin_init` only, so WordPress applies
	 * it in admin requests (the Media Library and its AJAX calls) and not on
	 * the front end or in REST requests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wordpress_applies_the_full_size_after_admin_init_only() {
		$id = $this->svg_attachment();
		delete_post_meta( $id, '_wp_attachment_metadata' );
		$this->assertArrayNotHasKey( 'sizes', wp_prepare_attachment_for_js( $id ) );

		$level = ob_get_level();
		$this->plugin()->add_svg_support();
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$response = wp_prepare_attachment_for_js( $id );

		$this->assertSame( 30.0, $response['sizes']['full']['width'] );
		$this->assertSame( 20.0, $response['sizes']['full']['height'] );
	}

	/* Image block: render_block. */

	public function test_image_block_with_an_svg_gets_width_and_height() {
		$id = $this->svg_attachment();

		$html = do_blocks( $this->image_block( $id ) );

		$this->assertSame(
			'<figure class="wp-block-image size-full"><img width="30" height="20" src="' . wp_get_attachment_url( $id ) . '" alt="" class="wp-image-' . $id . '"/></figure>',
			$html
		);
	}

	public function test_image_block_dimensions_come_from_the_file_not_from_the_metadata() {
		$id = $this->svg_attachment();
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 999,
				'height' => 999,
			)
		);

		$html = do_blocks( $this->image_block( $id ) );

		$this->assertStringContainsString( '<img width="30" height="20" ', $html );
	}

	public function test_image_block_dimensions_survive_the_content_filters() {
		$id = $this->svg_attachment( 'viewBox="0 0 300 200"' );

		$html = apply_filters( 'the_content', $this->image_block( $id ) );

		$this->assertStringContainsString( 'width="300" height="200"', $html );
		$this->assertSame( 1, substr_count( $html, 'width=' ) );
	}

	/**
	 * @dataProvider provide_img_attributes_that_stop_the_block_filter
	 */
	public function test_image_block_that_already_mentions_a_dimension_is_left_alone( $attributes ) {
		$id    = $this->svg_attachment();
		$block = $this->image_block( $id, $attributes );

		$html = do_blocks( $block );

		$this->assertStringNotContainsString( 'width="30"', $html );
		$this->assertStringNotContainsString( 'height="20"', $html );
		$this->assertStringContainsString( trim( $attributes ), $html );
	}

	public function provide_img_attributes_that_stop_the_block_filter() {
		return array(
			'width attribute'  => array( 'width="100" ' ),
			'height attribute' => array( 'height="50" ' ),
			'both attributes'  => array( 'width="100" height="50" ' ),
		);
	}

	public function test_image_block_with_another_image_type_is_left_alone() {
		$id = $this->png_attachment();

		$html = do_blocks( $this->image_block( $id ) );

		$this->assertStringNotContainsString( 'width=', $html );
		$this->assertStringNotContainsString( 'height=', $html );
	}

	/**
	 * @dataProvider provide_blocks_the_filter_ignores
	 */
	public function test_other_blocks_are_left_alone( $template ) {
		$id    = $this->svg_attachment();
		$url   = wp_get_attachment_url( $id );
		$block = str_replace( array( '{id}', '{url}' ), array( $id, $url ), $template );

		$html = do_blocks( $block );

		$this->assertStringContainsString( '<img src=', $html );
		$this->assertStringNotContainsString( 'width=', $html );
	}

	public function provide_blocks_the_filter_ignores() {
		return array(
			'image block without id'      => array( '<!-- wp:image --><figure class="wp-block-image"><img src="{url}" alt=""/></figure><!-- /wp:image -->' ),
			'image block with unknown id' => array( '<!-- wp:image {"id":999999} --><figure class="wp-block-image"><img src="{url}" alt=""/></figure><!-- /wp:image -->' ),
			'HTML block with the image'   => array( '<!-- wp:html --><img src="{url}" alt="" class="wp-image-{id}"/><!-- /wp:html -->' ),
			'markup outside any block'    => array( '<p><img src="{url}" alt="" class="wp-image-{id}"/></p>' ),
		);
	}

	/**
	 * Pins the defect in WPSVG::fix_missing_width_height_on_image_block():
	 * the text `width=` or `height=` anywhere in the block, not only as an
	 * attribute of the image, stops the dimensions from being added. When
	 * fixed, the image gets `width="30" height="20"`.
	 *
	 * @group pinned
	 * @dataProvider provide_block_text_that_mentions_a_dimension
	 */
	public function test_text_mentioning_a_dimension_leaves_the_image_without_dimensions_pinned_for_deferred_fix( $alt, $caption ) {
		$id    = $this->svg_attachment();
		$block = '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . wp_get_attachment_url( $id ) . '" alt="' . $alt . '" class="wp-image-' . $id . '"/><figcaption class="wp-element-caption">' . $caption . '</figcaption></figure><!-- /wp:image -->';

		$html = do_blocks( $block );

		$this->assertStringNotContainsString( 'width="30"', $html );
		$this->assertStringNotContainsString( 'height="20"', $html );
	}

	public function provide_block_text_that_mentions_a_dimension() {
		return array(
			'caption'          => array( '', 'Max height=20' ),
			'alternative text' => array( 'Logo, width=30', 'Our logo' ),
		);
	}

	/**
	 * Pins the defect in WPSVG::fix_missing_width_height_on_image_block():
	 * when the dimensions cannot be read, the image gets `width="0"
	 * height="0"`, which hides it. When fixed, the block is left without
	 * those attributes (or gets usable ones).
	 *
	 * @group pinned
	 * @dataProvider provide_attachments_without_readable_dimensions
	 */
	public function test_image_block_gets_zero_width_and_height_when_dimensions_are_unknown_pinned_for_deferred_fix( $kind ) {
		$id = 'gzipped' === $kind ? $this->gzipped_svg_attachment() : $this->svg_attachment( '' );

		list( $html ) = $this->with_warnings_captured(
			function () use ( $id ) {
				return do_blocks( $this->image_block( $id ) );
			}
		);

		$this->assertStringContainsString( '<img width="0" height="0" src=', $html );
	}

	public function provide_attachments_without_readable_dimensions() {
		return array(
			'SVG without size and viewBox' => array( 'plain' ),
			'gzipped SVG'                  => array( 'gzipped' ),
		);
	}
}
