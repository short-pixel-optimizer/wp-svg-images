<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * The plugin next to Safe SVG, which ships its own copy of the sanitizer
 * library and handles SVG uploads itself.
 *
 * Runs in two scenarios that differ in load order only: `safe-svg-first`
 * (what WordPress does by default, plugins load alphabetically) and
 * `safe-svg-last`. Whichever plugin loads first supplies the sanitizer
 * classes for both, and several results below follow from that.
 */
class SafeSvgTest extends CompatTestCase {

	const SAFE_SVG = 'safe-svg/safe-svg.php';

	/** What Safe SVG makes of DIRTY_SVG. */
	const SAFE_SVG_OUTPUT = '<?xml version="1.0" encoding="UTF-8"?> <svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"></rect></svg> ';

	const SIZED_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="20" viewBox="0 0 300 200"><rect/></svg>';

	/**
	 * @return bool Whether Safe SVG loaded before this plugin.
	 */
	private function safe_svg_loaded_first() {
		return 'safe-svg-first' === $this->scenario();
	}

	/* Loading. */

	public function test_both_plugins_are_active_in_the_scenarios_order() {
		$expected = $this->safe_svg_loaded_first()
			? array( self::SAFE_SVG, 'wp-svg-images/wp-svg-images.php' )
			: array( 'wp-svg-images/wp-svg-images.php', self::SAFE_SVG );

		$this->assertContains( $this->scenario(), array( 'safe-svg-first', 'safe-svg-last' ) );
		$this->assertSame( $expected, get_option( 'active_plugins' ) );
		$this->assertTrue( class_exists( 'SafeSvg\safe_svg', false ) );
		$this->assertCount( 1, $this->plugin_instances() );
	}

	public function test_safe_svg_runs_at_the_pinned_version() {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/plugins.json' ), true );

		$this->assertSame( $manifest['plugins']['safe-svg'], get_plugins()[ self::SAFE_SVG ]['Version'] );
	}

	public function test_first_loaded_plugin_supplies_every_sanitizer_class() {
		$expected = $this->safe_svg_loaded_first() ? 'safe-svg/vendor/enshrined/svg-sanitize/src' : 'wp-svg-images/svg-sanitizer';

		$this->assertSame( array( $expected ), $this->sanitizer_copies(), 'Classes of the two copies are never mixed' );
		$this->assertTrue( is_subclass_of( 'WPSVG_allowedTags', 'enshrined\svgSanitize\data\AllowedTags' ) );
		$this->assertTrue( is_subclass_of( 'WPSVG_allowedAttrs', 'enshrined\svgSanitize\data\AllowedAttributes' ) );
	}

	public function test_this_plugins_sanitizer_works_with_either_copy_and_honours_its_filters() {
		$plain    = $this->temporary_file( self::DIRTY_SVG );
		$filtered = $this->temporary_file( self::DIRTY_SVG );

		$this->assertTrue( $this->plugin()->sanitize( $plain ) );
		add_filter(
			'WPSVG_setAllowedTags',
			static function ( $tags ) {
				$tags[] = 'script';

				return $tags;
			}
		);
		$this->assertTrue( $this->plugin()->sanitize( $filtered ) );

		$this->assertSame( self::SANITIZED_SVG, file_get_contents( $plain ) );
		$this->assertSame( $this->svg( '<script>alert(1)</script><rect width="1" height="1"></rect>' ), file_get_contents( $filtered ) );
	}

	/* Uploads: Safe SVG has the last word. */

	public function test_sanitized_upload_is_stored_in_safe_svgs_format() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$id = $this->media_upload( self::DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( self::SAFE_SVG_OUTPUT, $this->stored_content( $id ) );
	}

	public function test_unrestricted_role_gets_its_upload_sanitized_by_safe_svg() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );

		$id = $this->media_upload( self::DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertSame( self::SAFE_SVG_OUTPUT, $this->stored_content( $id ) );
	}

	public function test_element_allowed_through_this_plugins_filter_is_removed_by_safe_svg() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		add_filter(
			'WPSVG_setAllowedTags',
			static function ( $tags ) {
				$tags[] = 'script';

				return $tags;
			}
		);

		$id = $this->media_upload( self::DIRTY_SVG );

		$this->assertSame( self::SAFE_SVG_OUTPUT, $this->stored_content( $id ) );
	}

	/**
	 * Pins the defect in WPSVG::wp_handle_upload_prefilter(): Safe SVG
	 * allows the SVG type for every user who can upload files, and this
	 * plugin's prefilter checks no permission, so a role that is disabled in
	 * this plugin's settings uploads SVGs. When fixed, the upload is refused.
	 *
	 * @group pinned
	 * @dataProvider provide_settings_that_disable_the_author
	 */
	public function test_role_disabled_in_this_plugin_uploads_svg_through_safe_svg_pinned_for_deferred_fix( $permissions ) {
		$this->set_permissions( $permissions );
		$this->log_in_as( 'author' );
		$this->assertArrayNotHasKey( 'svg', get_allowed_mime_types(), 'This plugin does not allow the type' );

		$id = $this->media_upload( self::DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( self::SAFE_SVG_OUTPUT, $this->stored_content( $id ) );
	}

	public function provide_settings_that_disable_the_author() {
		return array(
			'role disabled'            => array( array( 'author' => 0 ) ),
			'role not in the settings' => array( array( 'administrator' => 1 ) ),
			'no settings saved'        => array( null ),
		);
	}

	public function test_safe_svgs_own_role_setting_refuses_a_role_enabled_in_this_plugin() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		update_option( 'safe_svg_upload_roles', array( 'administrator' ) );

		$result = $this->media_upload( self::DIRTY_SVG );

		$this->assertWPError( $result );
		$this->assertSame( 'Sorry, you are not allowed to upload SVG files.', $result->get_error_message() );
	}

	public function test_user_who_cannot_upload_files_is_refused_by_safe_svg() {
		$this->set_permissions( array( 'subscriber' => 1 ) );
		$this->log_in_as( 'subscriber' );

		$result = $this->media_upload( self::DIRTY_SVG );

		$this->assertWPError( $result );
		$this->assertSame( 'Sorry, you are not allowed to upload SVG files.', $result->get_error_message() );
	}

	/* Safe SVG covers gaps that are pinned for this plugin alone. */

	public function test_sideloaded_svg_is_sanitized_by_safe_svg() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$file = $this->uploaded_file( self::DIRTY_SVG, 'side.svg' );

		$result = wp_handle_sideload( $file, array( 'test_form' => false ) );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( self::SAFE_SVG_OUTPUT, file_get_contents( $result['file'] ) );
	}

	public function test_gzipped_svg_is_sanitized_by_safe_svg() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$id = $this->quiet_media_upload( gzencode( self::DIRTY_SVG ), 'image.svgz' );

		$this->assertIsInt( $id );
		$this->assertSame( self::SAFE_SVG_OUTPUT, gzdecode( $this->stored_content( $id ) ) );
	}

	public function test_svg_content_this_plugin_does_not_detect_is_refused_by_safe_svg() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$result = $this->quiet_media_upload( "\n" . self::DIRTY_SVG );

		$this->assertWPError( $result );
		$this->assertSame( "Sorry, this file couldn't be sanitized so for security reasons wasn't uploaded", $result->get_error_message() );
	}

	public function test_malformed_svg_is_refused_by_whichever_plugin_filters_first() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$result = $this->quiet_media_upload( $this->svg( '<rect>' ) );

		$this->assertWPError( $result );
		$this->assertSame(
			$this->safe_svg_loaded_first() ? 'This SVG can not be sanitized!' : "Sorry, this file couldn't be sanitized so for security reasons wasn't uploaded",
			$result->get_error_message(),
			'The plugin that filters last sets the message'
		);
	}

	/* Results that depend on which sanitizer copy is loaded. */

	/**
	 * With Safe SVG's copy of the library the upload works; with the bundled
	 * copy it ends in the Error or LogicException that the `integration`
	 * suite pins.
	 *
	 * @dataProvider provide_svg_the_bundled_sanitizer_fails_on
	 */
	public function test_uploads_that_break_the_bundled_sanitizer_work_with_safe_svgs_copy( $inner, $stored_inner, $throwable ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		if ( ! $this->safe_svg_loaded_first() ) {
			$this->expectException( $throwable );
		}
		$id = $this->media_upload( $this->svg( $inner ) );

		$this->assertIsInt( $id );
		$this->assertSame( '<?xml version="1.0" encoding="UTF-8"?> ' . $this->svg( $stored_inner ) . ' ', $this->stored_content( $id ) );
	}

	public function provide_svg_the_bundled_sanitizer_fails_on() {
		return array(
			'CDATA section in a style element'   => array( self::CDATA_STYLE . '<rect/>', '<style>rect{fill:red}</style><rect></rect>', Error::class ),
			'use element referencing its parent' => array( '<g id="a"><use href="#a"/></g>', '<g id="a"></g>', LogicException::class ),
		);
	}

	/* Attachments. */

	/**
	 * Both plugins filter the metadata at the same priority; the one loaded
	 * last decides. This plugin stores the root `width` and `height`, Safe
	 * SVG the `viewBox` size.
	 */
	public function test_stored_dimensions_come_from_the_plugin_that_loaded_last() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$id = $this->media_upload( self::SIZED_SVG );

		$metadata = wp_get_attachment_metadata( $id );
		if ( $this->safe_svg_loaded_first() ) {
			$this->assertEquals( 30, $metadata['width'] );
			$this->assertEquals( 20, $metadata['height'] );
		} else {
			$this->assertEquals( 300, $metadata['width'] );
			$this->assertEquals( 200, $metadata['height'] );
		}
		$this->assertArrayHasKey( 'thumbnail', $metadata['sizes'], 'Safe SVG adds the image sizes in both orders' );
	}

	public function test_image_block_gets_this_plugins_dimensions_in_both_orders() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$id = $this->media_upload( self::SIZED_SVG );

		$html = do_blocks( '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . wp_get_attachment_url( $id ) . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->' );

		$this->assertStringContainsString( '<img width="30" height="20" src=', $html );
	}

	public function test_media_library_data_has_a_full_size_with_the_file_address() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$id = $this->media_upload( self::SIZED_SVG );

		$data = wp_prepare_attachment_for_js( $id );

		$this->assertSame( 'svg+xml', $data['subtype'] );
		$this->assertSame( wp_get_attachment_url( $id ), $data['sizes']['full']['url'] );
	}
}
