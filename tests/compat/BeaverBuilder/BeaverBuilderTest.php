<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * The plugin next to Beaver Builder, whose photo, video and audio modules
 * check the extension of uploaded files against a list this plugin extends.
 *
 * Beaver Builder reads the module type from the `fl_upload_type` field of the
 * upload request and does its check on `wp_handle_upload_prefilter`.
 */
class BeaverBuilderTest extends CompatTestCase {

	public function tear_down() {
		unset( $_POST['fl_upload_type'] );
		parent::tear_down();
	}

	/**
	 * Runs Beaver Builder's own check on a file name.
	 *
	 * @param string|null $module `fl_upload_type` of the request; null sends none.
	 * @param string      $name   Client file name.
	 * @return string|null The error Beaver Builder sets, null when it accepts the file.
	 */
	private function beaver_builder_check( $module, $name ) {
		unset( $_POST['fl_upload_type'] );
		if ( null !== $module ) {
			$_POST['fl_upload_type'] = $module;
		}
		$file = FLBuilder::wp_handle_upload_prefilter_filter(
			array(
				'name'     => $name,
				'tmp_name' => $this->temporary_file( '' ),
			)
		);

		return isset( $file['error'] ) ? $file['error'] : null;
	}

	/* Loading. */

	public function test_scenario_runs_beaver_builder_at_the_pinned_version() {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/plugins.json' ), true );

		$this->assertSame( 'beaver-builder', $this->scenario() );
		$this->assertSame( array( 'beaver-builder-lite-version/fl-builder.php', 'wp-svg-images/wp-svg-images.php' ), get_option( 'active_plugins' ) );
		$this->assertSame( $manifest['plugins']['beaver-builder-lite-version'], FL_BUILDER_VERSION );
		$this->assertSame( array( 'wp-svg-images/svg-sanitizer' ), $this->sanitizer_copies() );
	}

	public function test_both_plugins_filter_uploads_and_this_plugin_extends_the_regex() {
		$this->assertSame( 10, has_filter( 'wp_handle_upload_prefilter', 'FLBuilder::wp_handle_upload_prefilter_filter' ) );
		$this->assertSame( 10, has_filter( 'wp_handle_upload_prefilter', array( $this->plugin(), 'wp_handle_upload_prefilter' ) ) );
		$this->assertSame( 10, has_filter( 'fl_module_upload_regex', array( $this->plugin(), 'fl_module_upload_regex' ) ) );
	}

	/**
	 * The plugin patches the photo pattern by replacing the text `|png|`, so
	 * Beaver Builder's own pattern has to contain it.
	 */
	public function test_beaver_builders_photo_pattern_has_the_text_this_plugin_replaces() {
		$own   = null;
		$final = null;
		add_filter(
			'fl_module_upload_regex',
			static function ( $regex ) use ( &$own ) {
				$own = $regex;

				return $regex;
			},
			1
		);
		add_filter(
			'fl_module_upload_regex',
			static function ( $regex ) use ( &$final ) {
				$final = $regex;

				return $regex;
			},
			99
		);

		$this->beaver_builder_check( 'photo', 'logo.svg' );

		$this->assertStringContainsString( '|png|', $own['photo'] );
		$this->assertStringNotContainsString( 'svg', $own['photo'] );
		$this->assertSame( str_replace( '|png|', '|png|svgz?|', $own['photo'] ), $final['photo'] );
		unset( $own['photo'], $final['photo'] );
		$this->assertSame( $own, $final, 'The other patterns are untouched' );
	}

	/* Beaver Builder's check. */

	/**
	 * @dataProvider provide_modules_and_files
	 */
	public function test_beaver_builder_accepts_svg_in_the_photo_module_only( $module, $name, $accepted ) {
		$error = $this->beaver_builder_check( $module, $name );

		if ( $accepted ) {
			$this->assertNull( $error );
		} else {
			$this->assertSame( "The uploaded file is not a valid $module extension.", $error );
		}
	}

	public function provide_modules_and_files() {
		return array(
			'photo: svg'            => array( 'photo', 'logo.svg', true ),
			'photo: svgz'           => array( 'photo', 'logo.svgz', true ),
			'photo: png'            => array( 'photo', 'pixel.png', true ),
			'photo: text file'      => array( 'photo', 'notes.txt', false ),
			'video: svg'            => array( 'video', 'logo.svg', false ),
			'video: svgz'           => array( 'video', 'logo.svgz', false ),
			'audio: svg'            => array( 'audio', 'logo.svg', false ),
			'no module type: svg'   => array( null, 'logo.svg', true ),
			'no module type: other' => array( null, 'notes.txt', true ),
		);
	}

	/**
	 * The "inactive" side of the comparison: Beaver Builder alone refuses SVG.
	 *
	 * @dataProvider provide_svg_names
	 */
	public function test_beaver_builder_refuses_svg_in_the_photo_module_without_this_plugins_filter( $name ) {
		remove_filter( 'fl_module_upload_regex', array( $this->plugin(), 'fl_module_upload_regex' ), 10 );

		$this->assertSame( 'The uploaded file is not a valid photo extension.', $this->beaver_builder_check( 'photo', $name ) );
	}

	public function provide_svg_names() {
		return array(
			'svg'  => array( 'logo.svg' ),
			'svgz' => array( 'logo.svgz' ),
		);
	}

	/**
	 * Pins the defect in WPSVG::fl_module_upload_regex(): the extension is
	 * compared case-sensitively, so an upper-case SVG extension is refused
	 * in the photo module, unlike an upper-case PNG extension. When fixed,
	 * Beaver Builder accepts the file.
	 *
	 * @group pinned
	 * @dataProvider provide_upper_case_svg_names
	 */
	public function test_upper_case_svg_extension_is_refused_in_the_photo_module_pinned_for_deferred_fix( $name ) {
		$this->assertNull( $this->beaver_builder_check( 'photo', 'PIXEL.PNG' ), 'Beaver Builder itself ignores case' );

		$this->assertSame( 'The uploaded file is not a valid photo extension.', $this->beaver_builder_check( 'photo', $name ) );
	}

	public function provide_upper_case_svg_names() {
		return array(
			'SVG'  => array( 'LOGO.SVG' ),
			'Svg'  => array( 'Logo.Svg' ),
			'SVGZ' => array( 'LOGO.SVGZ' ),
		);
	}

	/* Complete uploads from a module. */

	public function test_photo_module_upload_is_sanitized_and_stored() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$_POST['fl_upload_type'] = 'photo';

		$id = $this->media_upload( self::DIRTY_SVG, 'logo.svg' );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $id ) );
	}

	public function test_photo_module_upload_still_needs_this_plugins_permission() {
		$this->set_permissions( array( 'author' => 0 ) );
		$this->log_in_as( 'author' );
		$_POST['fl_upload_type'] = 'photo';

		$result = $this->media_upload( self::DIRTY_SVG, 'logo.svg' );

		$this->assertWPError( $result );
		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $result->get_error_message() );
	}

	public function test_video_module_upload_of_an_svg_is_refused_by_beaver_builder() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$_POST['fl_upload_type'] = 'video';

		$result = $this->media_upload( self::DIRTY_SVG, 'logo.svg' );

		$this->assertWPError( $result );
		$this->assertSame( 'The uploaded file is not a valid video extension.', $result->get_error_message() );
	}
}
