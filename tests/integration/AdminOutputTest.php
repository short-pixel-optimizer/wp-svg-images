<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * What the plugin adds to admin pages: the output buffer that rewrites the
 * Media Library templates and the thumbnail style in the footer.
 */
class AdminOutputTest extends WPSVGIntegrationTestCase {

	const DETAILS_CONDITION = '<# } else if ( \'image\' === data.type && data.sizes && data.sizes.full ) { #>';
	const GRID_CONDITION    = '<# } else if ( \'image\' === data.type && data.sizes ) { #>';

	/**
	 * @return string The Media Library templates this WordPress version prints.
	 */
	private function media_templates() {
		require_once ABSPATH . WPINC . '/media-template.php';
		ob_start();
		wp_print_media_templates();

		return ob_get_clean();
	}

	public function test_wordpress_prints_the_attachment_templates_the_plugin_targets() {
		$templates = $this->media_templates();

		$this->assertStringContainsString( '<script type="text/html" id="tmpl-attachment">', $templates );
		$this->assertStringContainsString( '<script type="text/html" id="tmpl-attachment-details-two-column">', $templates );
	}

	/**
	 * Pins the defect in WPSVG::final_output(): the two template conditions
	 * it looks for are not in WordPress's media templates, so the templates
	 * leave the method unchanged. When fixed, the method changes the
	 * templates (or it and the output buffer are removed).
	 *
	 * @group pinned
	 */
	public function test_real_media_templates_are_not_changed_pinned_for_deferred_fix() {
		$templates = $this->media_templates();

		$result = $this->plugin()->final_output( $templates );

		$this->assertSame( 0, substr_count( $templates, self::DETAILS_CONDITION ) );
		$this->assertSame( 0, substr_count( $templates, self::GRID_CONDITION ) );
		$this->assertSame( $templates, $result );
		$this->assertStringNotContainsString( "'svg+xml' === data.subtype", $result );
	}

	/**
	 * What the templates use instead: both show `data.sizes.full`, which
	 * WordPress fills from the dimensions the plugin stores on upload.
	 */
	public function test_real_templates_preview_an_svg_through_its_full_size() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );
		$id = $this->media_upload( '<svg ' . self::SVG_NS . ' width="30" height="20"><rect/></svg>' );

		$templates = $this->media_templates();
		$data      = wp_prepare_attachment_for_js( $id );

		$this->assertStringContainsString( '<# } else if ( data.sizes && data.sizes.full ) { #>', $templates );
		$this->assertStringContainsString( '<img class="details-image" src="{{ data.sizes.full.url }}"', $templates );
		$this->assertSame( wp_get_attachment_url( $id ), $data['sizes']['full']['url'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_output_passes_through_the_final_output_filter_when_the_buffer_closes() {
		$seen = null;
		add_filter(
			'final_output',
			static function ( $content ) use ( &$seen ) {
				$seen = $content;

				return $content;
			},
			5
		);
		$page = '<html>' . self::DETAILS_CONDITION . self::GRID_CONDITION . '</html>';

		ob_start();
		$level = ob_get_level();
		$this->plugin()->add_svg_support();
		$this->assertSame( $level + 1, ob_get_level() );
		echo $page;
		ob_end_flush();
		$output = ob_get_clean();

		$this->assertSame( $page, $seen, 'The whole response reaches the filter' );
		$this->assertSame( 2, substr_count( $output, "<# } else if ( 'svg+xml' === data.subtype ) { #>" ) );
		$this->assertStringContainsString( '<img class="details-image" src="{{ data.url }}" draggable="false" />', $output );
		$this->assertStringContainsString( '<img src="{{ data.url }}" class="thumbnail" draggable="false" />', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_real_media_templates_leave_the_admin_buffer_as_they_entered() {
		$templates = $this->media_templates();

		ob_start();
		$this->plugin()->add_svg_support();
		echo $templates;
		ob_end_flush();
		$output = ob_get_clean();

		$this->assertSame( $templates, $output );
	}

	public function test_admin_footer_gets_the_thumbnail_style() {
		$this->assertSame( 10, has_action( 'admin_footer', array( $this->plugin(), 'fix_svg_thumbnail_size' ) ) );

		ob_start();
		$this->plugin()->fix_svg_thumbnail_size();
		$html = ob_get_clean();

		$this->assertSame( '<style>.attachment-info .thumbnail img[src$=".svg"],#postimagediv .inside img[src$=".svg"]{width:100%}</style>', $html );
	}
}
