<?php

require_once __DIR__ . '/WPSVGTestCase.php';

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use enshrined\svgSanitize\data\AllowedAttributes;
use enshrined\svgSanitize\data\AllowedTags;
use enshrined\svgSanitize\Sanitizer;

/**
 * WPSVG: what happens when the plugin file loads, hook registration and the
 * small callbacks that need nothing but their arguments. File sanitization
 * is in WPSVGSanitizeTest and dimensions in WPSVGDimensionsTest.
 */
class WPSVGTest extends WPSVGTestCase {

	/* Loading the file. */

	public function test_loading_the_file_registers_an_activation_hook_for_the_plugin_file() {
		$this->assertIsArray( self::$activation_hook );
		$this->assertSame( realpath( WPSVG_TESTS_PLUGIN_DIR . 'wp-svg-images.php' ), realpath( self::$activation_hook[0] ) );
		$this->assertIsCallable( self::$activation_hook[1] );
	}

	public function test_activation_adds_the_one_shot_redirect_option() {
		Functions\expect( 'add_option' )->once()->with( 'WPSVGActivated', 1 );

		call_user_func( self::$activation_hook[1] );
	}

	/* Constructor. */

	public function test_constructor_creates_a_sanitizer_and_leaves_settings_unloaded() {
		$plugin = $this->plugin();

		$this->assertInstanceOf( Sanitizer::class, $plugin->sanitizer );
		$this->assertNull( $plugin->settings );
		$this->assertNull( $plugin->plugin_admin_page );
		$this->assertFalse( $plugin->spio_installed );
		$this->assertFalse( $plugin->spio_active );
	}

	/**
	 * The sanitizer is set to drop the XML declaration and to minify.
	 */
	public function test_constructor_configures_the_sanitizer_for_one_line_output_without_declaration() {
		$output = $this->plugin()->sanitizer->sanitize( "<?xml version=\"1.0\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\">\n  <rect/>\n</svg>\n" );

		$this->assertSame( '<svg xmlns="http://www.w3.org/2000/svg"><rect></rect></svg>', $output );
	}

	/**
	 * @dataProvider provide_registered_hooks
	 */
	public function test_constructor_registers_hook( $type, $hook, $method, $priority ) {
		$plugin   = $this->plugin();
		$callback = array( $plugin, $method );

		$this->assertIsCallable( $callback );
		$this->assertSame( $priority, 'action' === $type ? has_action( $hook, $callback ) : has_filter( $hook, $callback ) );
	}

	public function provide_registered_hooks() {
		$hooks = array(
			array( 'filter', 'wp_handle_upload_prefilter', 'wp_handle_upload_prefilter', 10 ),
			array( 'action', 'plugins_loaded', 'plugins_loaded', 10 ),
			array( 'action', 'admin_menu', 'plugin_menu_link', 10 ),
			array( 'action', 'init', 'plugin_init', 10 ),
			array( 'action', 'admin_init', 'add_svg_support', 10 ),
			array( 'action', 'admin_init', 'upsell', 10 ),
			array( 'action', 'admin_footer', 'fix_svg_thumbnail_size', 10 ),
			array( 'filter', 'upload_mimes', 'add_svg_mime', 10 ),
			array( 'filter', 'wp_check_filetype_and_ext', 'wp_check_filetype_and_ext', 100 ),
			array( 'filter', 'wp_generate_attachment_metadata', 'wp_generate_attachment_metadata', 10 ),
			array( 'filter', 'fl_module_upload_regex', 'fl_module_upload_regex', 10 ),
			array( 'filter', 'render_block', 'fix_missing_width_height_on_image_block', 10 ),
			array( 'action', 'admin_notices', 'admin_notices', 10 ),
			array( 'action', 'wp_ajax_wpsvg_notice_dismissed', 'wpsvg_notice_dismissed', 10 ),
			array( 'filter', 'wp_all_import_image_mime_type', 'wp_all_import_svgs', 10 ),
		);

		$cases = array();
		foreach ( $hooks as $hook ) {
			$cases[ "$hook[1] -> $hook[2]" ] = $hook;
		}

		return $cases;
	}

	/**
	 * @dataProvider provide_filters_with_several_arguments
	 */
	public function test_constructor_asks_for_every_argument_the_callback_uses( $hook, $priority, $accepted_arguments ) {
		Filters\expectAdded( $hook )->once()->with( Mockery::type( 'array' ), $priority, $accepted_arguments );

		$this->plugin();
	}

	public function provide_filters_with_several_arguments() {
		return array(
			'wp_check_filetype_and_ext'       => array( 'wp_check_filetype_and_ext', 100, 4 ),
			'wp_generate_attachment_metadata' => array( 'wp_generate_attachment_metadata', 10, 2 ),
			'fl_module_upload_regex'          => array( 'fl_module_upload_regex', 10, 4 ),
			'render_block'                    => array( 'render_block', 10, 2 ),
			'wp_all_import_image_mime_type'   => array( 'wp_all_import_image_mime_type', 10, 2 ),
		);
	}

	/**
	 * The preview filters belong to add_svg_support(), not to the constructor.
	 */
	public function test_constructor_does_not_register_the_admin_preview_filters() {
		$plugin = $this->plugin();

		$this->assertFalse( has_filter( 'final_output', array( $plugin, 'final_output' ) ) );
		$this->assertFalse( has_filter( 'wp_prepare_attachment_for_js', array( $plugin, 'wp_prepare_attachment_for_js' ) ) );
	}

	/* Small hook callbacks. */

	public function test_plugin_init_loads_the_settings_option() {
		$settings = array( 'role_administrator' => 2, 'role_editor' => 1 );
		Functions\expect( 'get_option' )->once()->with( 'wpsvg_settings' )->andReturn( $settings );
		$plugin = $this->plugin();

		$plugin->plugin_init();

		$this->assertSame( $settings, $plugin->settings );
	}

	public function test_plugin_init_stores_false_when_the_option_does_not_exist() {
		Functions\expect( 'get_option' )->once()->with( 'wpsvg_settings' )->andReturn( false );
		$plugin = $this->plugin();

		$plugin->plugin_init();

		$this->assertFalse( $plugin->settings );
	}

	public function test_plugins_loaded_loads_the_text_domain_from_the_languages_folder() {
		Functions\expect( 'load_plugin_textdomain' )
			->once()
			->with( 'wp-svg-images', false, basename( WPSVG_TESTS_PLUGIN_DIR ) . '/languages/' );

		$this->plugin()->plugins_loaded();
	}

	public function test_fix_svg_thumbnail_size_prints_one_style_element() {
		$this->expectOutputString( '<style>.attachment-info .thumbnail img[src$=".svg"],#postimagediv .inside img[src$=".svg"]{width:100%}</style>' );

		$this->plugin()->fix_svg_thumbnail_size();
	}

	/* filter_plugin_actions(). */

	public function test_filter_plugin_actions_puts_a_settings_link_first() {
		$links = $this->plugin()->filter_plugin_actions( array( 'deactivate' => '<a>Deactivate</a>' ), 'wp-svg-images/wp-svg-images.php' );

		$this->assertSame(
			array(
				0            => '<a href="options-general.php?page=wp-svg-images.php">Settings</a>',
				'deactivate' => '<a>Deactivate</a>',
			),
			$links
		);
	}

	public function test_filter_plugin_actions_works_on_an_empty_list() {
		$this->assertCount( 1, $this->plugin()->filter_plugin_actions( array(), '' ) );
	}

	public function test_filter_plugin_actions_translates_the_label_in_the_plugin_text_domain() {
		Functions\when( '__' )->alias(
			function ( $text, $domain ) {
				return 'Settings' === $text && 'wp-svg-images' === $domain ? 'Einstellungen' : "untranslated: $text";
			}
		);

		$links = $this->plugin()->filter_plugin_actions( array(), '' );

		$this->assertSame( '<a href="options-general.php?page=wp-svg-images.php">Einstellungen</a>', $links[0] );
	}

	/* wp_all_import_svgs(). */

	/**
	 * @dataProvider provide_undetected_mime_types
	 */
	public function test_wp_all_import_svgs_supplies_the_svg_type_when_none_was_detected( $mime_type ) {
		$plugin = $this->plugin();

		$this->assertSame( 'image/svg+xml', $plugin->wp_all_import_svgs( $mime_type, '/tmp/import/logo.svg' ) );
		$this->assertSame( 'image/svg+xml', $plugin->wp_all_import_svgs( $mime_type, '/tmp/import/logo.svgz' ) );
	}

	public function provide_undetected_mime_types() {
		return array(
			'empty string' => array( '' ),
			'null'         => array( null ),
			'false'        => array( false ),
			'string zero'  => array( '0' ),
		);
	}

	public function test_wp_all_import_svgs_keeps_a_detected_type() {
		$this->assertSame( 'image/png', $this->plugin()->wp_all_import_svgs( 'image/png', 'logo.svg' ) );
	}

	/**
	 * @dataProvider provide_imports_that_get_the_svg_type
	 */
	public function test_wp_all_import_svgs_sanitizes_the_imported_file( $mime_type, $name ) {
		$path = $this->temporary_file( $name, '<svg xmlns="http://www.w3.org/2000/svg" onload="a()"><script>x</script><rect/></svg>' );

		$this->assertSame( 'image/svg+xml', $this->plugin()->wp_all_import_svgs( $mime_type, $path ) );
		$this->assertSame( '<svg xmlns="http://www.w3.org/2000/svg"><rect></rect></svg>', file_get_contents( $path ) );
	}

	public function provide_imports_that_get_the_svg_type() {
		return array(
			'no type detected'     => array( '', 'logo.svg' ),
			'svg type detected'    => array( 'image/svg+xml', 'logo.svg' ),
			'svg type, other name' => array( 'image/svg+xml', 'logo.xml' ),
		);
	}

	public function test_wp_all_import_svgs_sanitizes_gzipped_content() {
		$path = $this->temporary_file( 'logo.svgz', gzencode( '<svg xmlns="http://www.w3.org/2000/svg"><script>x</script></svg>' ) );

		$this->assertSame( 'image/svg+xml', $this->plugin()->wp_all_import_svgs( '', $path ) );
		$this->assertSame( '<svg xmlns="http://www.w3.org/2000/svg"></svg>', gzdecode( file_get_contents( $path ) ) );
	}

	public function test_wp_all_import_svgs_deletes_a_file_that_cannot_be_sanitized_and_gives_no_type() {
		$path = $this->temporary_file( 'logo.svg', '<html xmlns="http://www.w3.org/1999/xhtml"><script>x</script></html>' );
		Functions\expect( 'wp_delete_file' )->once()->with( $path )->andReturnUsing( 'unlink' );

		$this->assertSame( '', $this->plugin()->wp_all_import_svgs( '', $path ) );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_wp_all_import_svgs_leaves_a_file_of_another_type_untouched() {
		$content = 'not an svg';
		$path    = $this->temporary_file( 'logo.svg', $content );

		$this->assertSame( 'image/png', $this->plugin()->wp_all_import_svgs( 'image/png', $path ) );
		$this->assertSame( $content, file_get_contents( $path ) );
	}

	/**
	 * @dataProvider provide_paths_that_do_not_end_in_an_svg_extension
	 */
	public function test_wp_all_import_svgs_leaves_other_files_alone( $path ) {
		$this->assertSame( '', $this->plugin()->wp_all_import_svgs( '', $path ) );
	}

	public function provide_paths_that_do_not_end_in_an_svg_extension() {
		return array(
			'png'                      => array( 'logo.png' ),
			'no dot'                   => array( 'svg' ),
			'svg in the middle'        => array( 'logo.svg.png' ),
			'query string after it'    => array( 'logo.svg?ver=1' ),
			'trailing space'           => array( 'logo.svg ' ),
			'empty path'               => array( '' ),
		);
	}

	/**
	 * Pins the defect described in the wp_all_import_svgs() docblock: the
	 * extension check is case-sensitive, so an upper-case extension gets no
	 * MIME type.
	 *
	 * Fixed when `image/svg+xml` is returned; this test then fails and the
	 * paths move to the test for supplied types.
	 *
	 * @group pinned
	 * @dataProvider provide_paths_with_an_upper_case_extension
	 */
	public function test_wp_all_import_svgs_ignores_upper_case_extension_pinned_for_deferred_fix( $path ) {
		$this->assertSame( '', $this->plugin()->wp_all_import_svgs( '', $path ) );
	}

	public function provide_paths_with_an_upper_case_extension() {
		return array(
			'SVG'  => array( 'logo.SVG' ),
			'Svgz' => array( 'logo.Svgz' ),
		);
	}

	/* fl_module_upload_regex(). */

	/**
	 * @dataProvider provide_svg_extensions
	 */
	public function test_fl_module_upload_regex_adds_svg_to_the_photo_pattern( $extension ) {
		$regex = array(
			'photo' => '#(jpe?g|png|gif|bmp)#i',
			'video' => '#(mp4|png|webm)#i',
		);

		$result = $this->plugin()->fl_module_upload_regex( $regex, 'photo', $extension, array() );

		$this->assertSame( '#(jpe?g|png|svgz?|gif|bmp)#i', $result['photo'] );
		$this->assertSame( $regex['video'], $result['video'] );
		$this->assertSame( 1, preg_match( $result['photo'], 'svg' ) );
		$this->assertSame( 1, preg_match( $result['photo'], 'svgz' ) );
	}

	public function provide_svg_extensions() {
		return array(
			'svg'  => array( 'svg' ),
			'svgz' => array( 'svgz' ),
		);
	}

	/**
	 * @dataProvider provide_other_extensions
	 */
	public function test_fl_module_upload_regex_leaves_the_patterns_alone_for_other_extensions( $extension ) {
		$regex = array( 'photo' => '#(jpe?g|png|gif|bmp)#i' );

		$this->assertSame( $regex, $this->plugin()->fl_module_upload_regex( $regex, 'photo', $extension, array() ) );
	}

	public function provide_other_extensions() {
		return array(
			'png'             => array( 'png' ),
			'empty'           => array( '' ),
			'upper-case SVG'  => array( 'SVG' ),
			'svg with a dot'  => array( '.svg' ),
		);
	}

	/**
	 * The extension is inserted after `|png|` only; a pattern where png is
	 * first or last is not changed.
	 */
	public function test_fl_module_upload_regex_needs_png_between_two_alternatives() {
		$regex = array( 'photo' => '#(png|jpe?g|gif)#i' );

		$this->assertSame( $regex, $this->plugin()->fl_module_upload_regex( $regex, 'photo', 'svg', array() ) );
	}

	/* final_output(). */

	public function test_final_output_adds_an_svg_branch_to_the_attachment_details_template() {
		$original = "<# } else if ( 'image' === data.type && data.sizes && data.sizes.full ) { #>";

		$output = $this->plugin()->final_output( "before $original after" );

		$this->assertStringStartsWith( "before <# } else if ( 'svg+xml' === data.subtype ) { #>", $output );
		$this->assertStringContainsString( '<img class="details-image" src="{{ data.url }}" draggable="false" />', $output );
		$this->assertStringEndsWith( "$original after", $output );
	}

	public function test_final_output_adds_an_svg_branch_to_the_attachment_grid_template() {
		$original = "<# } else if ( 'image' === data.type && data.sizes ) { #>";

		$output = $this->plugin()->final_output( "before $original after" );

		$this->assertStringStartsWith( "before <# } else if ( 'svg+xml' === data.subtype ) { #>", $output );
		$this->assertStringContainsString( '<div class="centered">', $output );
		$this->assertStringContainsString( '<img src="{{ data.url }}" class="thumbnail" draggable="false" />', $output );
		$this->assertStringEndsWith( "$original after", $output );
	}

	public function test_final_output_patches_both_templates_and_every_occurrence() {
		$details = "<# } else if ( 'image' === data.type && data.sizes && data.sizes.full ) { #>";
		$grid    = "<# } else if ( 'image' === data.type && data.sizes ) { #>";

		$output = $this->plugin()->final_output( $details . $grid . $details . $grid );

		$this->assertSame( 4, substr_count( $output, "'svg+xml' === data.subtype" ) );
		$this->assertSame( 2, substr_count( $output, 'details-image' ) );
		$this->assertSame( 2, substr_count( $output, 'class="thumbnail"' ) );
	}

	/**
	 * @dataProvider provide_content_without_the_template_conditions
	 */
	public function test_final_output_returns_other_content_unchanged( $content ) {
		$this->assertSame( $content, $this->plugin()->final_output( $content ) );
	}

	public function provide_content_without_the_template_conditions() {
		return array(
			'empty'                   => array( '' ),
			'ordinary page'           => array( '<html><body><p>Dashboard</p></body></html>' ),
			'condition spaced differently' => array( "<# } else if ('image' === data.type && data.sizes) { #>" ),
			'condition with double quotes' => array( '<# } else if ( "image" === data.type && data.sizes ) { #>' ),
		);
	}

	/* is_gzipped(). */

	/**
	 * @dataProvider provide_content_and_whether_it_is_gzipped
	 */
	public function test_is_gzipped( $content, $expected ) {
		$this->assertSame( $expected, $this->plugin()->is_gzipped( $content ) );
	}

	public function provide_content_and_whether_it_is_gzipped() {
		return array(
			'gzip data'                      => array( gzencode( '<svg/>' ), true ),
			'the three magic bytes alone'    => array( "\x1f\x8b\x08", true ),
			'empty string'                   => array( '', false ),
			'plain svg'                      => array( '<svg xmlns="http://www.w3.org/2000/svg"/>', false ),
			'only two of the magic bytes'    => array( "\x1f\x8b", false ),
			'another compression method'     => array( "\x1f\x8b\x00", false ),
			'magic bytes after a space'      => array( " \x1f\x8b\x08", false ),
			'magic bytes after a wide character' => array( "é\x1f\x8b\x08", false ),
			'zlib stream'                    => array( gzcompress( '<svg/>' ), false ),
			'raw deflate stream'             => array( gzdeflate( '<svg/>' ), false ),
		);
	}

	/* Whitelist classes. */

	public function test_allowed_tags_class_passes_the_library_list_through_its_filter() {
		Filters\expectApplied( 'WPSVG_setAllowedTags' )->once()->with( AllowedTags::getTags() )->andReturn( array( 'svg', 'custom' ) );

		$this->assertSame( array( 'svg', 'custom' ), WPSVG_allowedTags::getTags() );
	}

	public function test_allowed_tags_class_returns_the_library_list_when_nothing_filters_it() {
		$this->assertSame( AllowedTags::getTags(), WPSVG_allowedTags::getTags() );
	}

	public function test_allowed_attrs_class_passes_the_library_list_through_its_filter() {
		Filters\expectApplied( 'WPSVG_setAllowedAttrs' )->once()->with( AllowedAttributes::getAttributes() )->andReturn( array( 'xmlns', 'custom' ) );

		$this->assertSame( array( 'xmlns', 'custom' ), WPSVG_allowedAttrs::getAttributes() );
	}

	public function test_allowed_attrs_class_returns_the_library_list_when_nothing_filters_it() {
		$this->assertSame( AllowedAttributes::getAttributes(), WPSVG_allowedAttrs::getAttributes() );
	}

	public function test_whitelist_classes_extend_the_library_classes() {
		$this->assertInstanceOf( AllowedTags::class, new WPSVG_allowedTags() );
		$this->assertInstanceOf( AllowedAttributes::class, new WPSVG_allowedAttrs() );
	}
}
