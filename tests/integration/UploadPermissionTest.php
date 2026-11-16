<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * Who may upload SVGs: the `upload_mimes` gate and the file type WordPress
 * settles on, both through WordPress' own functions.
 */
class UploadPermissionTest extends WPSVGIntegrationTestCase {

	/**
	 * @param string $content  File content.
	 * @param string $filename Client file name.
	 * @return array `ext`, `type` and `proper_filename` as WordPress decides.
	 */
	private function filetype_of( $content, $filename ) {
		return wp_check_filetype_and_ext( $this->temporary_file( $content ), $filename );
	}

	/* upload_mimes. */

	/**
	 * @dataProvider provide_permission_values
	 */
	public function test_svg_is_an_allowed_type_only_for_a_non_zero_permission( $value, $allowed ) {
		$this->set_permissions( array( 'editor' => $value ) );
		$this->log_in_as( 'editor' );

		$mimes = get_allowed_mime_types();

		$this->assertSame( $allowed, isset( $mimes['svg'] ) );
		$this->assertSame( $allowed, isset( $mimes['svgz'] ) );
	}

	public function provide_permission_values() {
		return array(
			'0 disabled'              => array( 0, false ),
			'1 sanitized'             => array( 1, true ),
			'2 unrestricted'          => array( 2, true ),
			'numeric string 1'        => array( '1', true ),
			'numeric string 0'        => array( '0', false ),
			'value outside the range' => array( 7, true ),
			'text'                    => array( 'yes', false ),
		);
	}

	public function test_both_extensions_map_to_the_svg_mime_type() {
		$this->set_permissions( array( 'editor' => 1 ) );
		$this->log_in_as( 'editor' );

		$mimes = get_allowed_mime_types();

		$this->assertSame( 'image/svg+xml', $mimes['svg'] );
		$this->assertSame( 'image/svg+xml', $mimes['svgz'] );
	}

	public function test_other_allowed_types_are_kept() {
		$this->log_in_as( 'editor' );
		$before = get_allowed_mime_types();
		$this->set_permissions( array( 'editor' => 1 ) );

		$after = get_allowed_mime_types();

		unset( $after['svg'], $after['svgz'] );
		$this->assertSame( $before, $after );
	}

	/**
	 * @dataProvider provide_roles
	 */
	public function test_every_role_follows_its_own_setting( $role ) {
		$this->set_permissions( array( $role => 1 ) );
		$this->log_in_as( $role );

		$this->assertArrayHasKey( 'svg', get_allowed_mime_types() );
	}

	public function provide_roles() {
		return array(
			'administrator' => array( 'administrator' ),
			'editor'        => array( 'editor' ),
			'author'        => array( 'author' ),
			'contributor'   => array( 'contributor' ),
			'subscriber'    => array( 'subscriber' ),
		);
	}

	public function test_a_role_without_a_saved_value_is_not_allowed() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'editor' );

		$this->assertArrayNotHasKey( 'svg', get_allowed_mime_types() );
	}

	public function test_logged_out_request_is_not_allowed() {
		$this->set_permissions( array( 'administrator' => 2, 'editor' => 1 ) );
		wp_set_current_user( 0 );

		$this->assertArrayNotHasKey( 'svg', get_allowed_mime_types() );
	}

	/**
	 * @dataProvider provide_permissions_for_a_user_with_two_roles
	 */
	public function test_user_with_two_roles_is_allowed_when_any_role_is( $permissions, $allowed ) {
		$this->set_permissions( $permissions );
		$this->log_in_as( 'subscriber', array( 'author' ) );

		$this->assertSame( $allowed, isset( get_allowed_mime_types()['svg'] ) );
	}

	public function provide_permissions_for_a_user_with_two_roles() {
		return array(
			'first role only'  => array( array( 'subscriber' => 1, 'author' => 0 ), true ),
			'second role only' => array( array( 'subscriber' => 0, 'author' => 1 ), true ),
			'neither'          => array( array( 'subscriber' => 0, 'author' => 0 ), false ),
			'role not listed'  => array( array( 'author' => 2 ), true ),
		);
	}

	/**
	 * Pins the defect described in the admin_options_page() docblock: with
	 * no saved settings the settings page shows "enable sanitized SVG
	 * upload" for every role, but the gate treats a missing value as
	 * disabled, so not even an administrator can upload an SVG.
	 *
	 * Fixed when the administrator is allowed on a fresh install (or the
	 * page shows "disable"); this test then fails and becomes a regression
	 * test for the chosen default.
	 *
	 * @group pinned
	 */
	public function test_fresh_install_does_not_allow_svg_for_administrator_pinned_for_deferred_fix() {
		$this->set_permissions( null );
		$this->log_in_as( 'administrator' );

		$this->assertArrayNotHasKey( 'svg', get_allowed_mime_types() );
	}

	/* wp_check_filetype_and_ext. */

	/**
	 * WordPress alone rejects most of these contents for the `.svg` name;
	 * the plugin decides by file name only and restores the SVG type.
	 *
	 * @dataProvider provide_content_for_an_svg_file_name
	 */
	public function test_file_named_svg_is_typed_as_svg_whatever_its_content( $content ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$result = $this->filetype_of( $content, 'logo.svg' );

		$this->assertSame( 'svg', $result['ext'] );
		$this->assertSame( 'image/svg+xml', $result['type'] );
		$this->assertFalse( $result['proper_filename'] );
	}

	public function provide_content_for_an_svg_file_name() {
		return array(
			'svg with xml declaration' => array( self::DIRTY_SVG ),
			'svg without declaration'  => array( '<svg ' . self::SVG_NS . '/>' ),
			'svg after a comment'      => array( "<!-- note -->\n<svg " . self::SVG_NS . '/>' ),
			'html'                     => array( '<html><body>x</body></html>' ),
			'plain text'               => array( 'just text' ),
			'gzip data'                => array( gzencode( '<svg ' . self::SVG_NS . '/>' ) ),
			'empty file'               => array( '' ),
		);
	}

	/**
	 * WordPress recognises the image and proposes the matching file name;
	 * the plugin then overrides extension and type but not that name, so the
	 * three values no longer agree.
	 */
	public function test_png_image_named_svg_is_typed_as_svg_but_keeps_the_png_name_wordpress_proposed() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$result = $this->filetype_of( base64_decode( self::PNG_BASE64 ), 'logo.svg' );

		$this->assertSame( 'svg', $result['ext'] );
		$this->assertSame( 'image/svg+xml', $result['type'] );
		$this->assertSame( 'logo.png', $result['proper_filename'] );
	}

	public function test_file_named_svgz_is_typed_as_svg_with_the_svgz_extension() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$result = $this->filetype_of( gzencode( '<svg ' . self::SVG_NS . '/>' ), 'logo.svgz' );

		$this->assertSame( 'svgz', $result['ext'] );
		$this->assertSame( 'image/svg+xml', $result['type'] );
	}

	/**
	 * @dataProvider provide_users_who_may_not_upload_svg
	 */
	public function test_file_named_svg_is_rejected_for_a_user_without_permission( $permissions, $role ) {
		$this->set_permissions( $permissions );
		if ( $role ) {
			$this->log_in_as( $role );
		} else {
			wp_set_current_user( 0 );
		}

		$result = $this->filetype_of( self::DIRTY_SVG, 'logo.svg' );

		$this->assertFalse( $result['ext'] );
		$this->assertFalse( $result['type'] );
	}

	public function provide_users_who_may_not_upload_svg() {
		return array(
			'role disabled'     => array( array( 'author' => 0 ), 'author' ),
			'role not saved'    => array( array( 'editor' => 1 ), 'author' ),
			'fresh install'     => array( null, 'administrator' ),
			'logged out'        => array( array( 'author' => 1, 'administrator' => 2 ), null ),
		);
	}

	/**
	 * @dataProvider provide_other_file_names
	 */
	public function test_other_file_names_are_left_to_wordpress( $content, $filename, $expected_ext, $expected_type ) {
		$this->set_permissions( array( 'author' => 2 ) );
		$this->log_in_as( 'author' );

		$result = $this->filetype_of( $content, $filename );

		$this->assertSame( $expected_ext, $result['ext'] );
		$this->assertSame( $expected_type, $result['type'] );
	}

	public function provide_other_file_names() {
		return array(
			'png'                       => array( base64_decode( self::PNG_BASE64 ), 'photo.png', 'png', 'image/png' ),
			'svg content named png'     => array( self::DIRTY_SVG, 'photo.png', false, false ),
			'svg in the middle'         => array( self::DIRTY_SVG, 'logo.svg.php', false, false ),
			'no extension'              => array( self::DIRTY_SVG, 'svg', false, false ),
		);
	}

	/**
	 * WordPress matches extensions without regard to case, so an upper-case
	 * extension works as long as WordPress recognises the content itself.
	 */
	public function test_upper_case_extension_is_accepted_when_wordpress_recognises_the_content() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$result = $this->filetype_of( self::DIRTY_SVG, 'LOGO.SVG' );

		$this->assertSame( 'SVG', $result['ext'], 'WordPress keeps the case it matched' );
		$this->assertSame( 'image/svg+xml', $result['type'] );
	}

	/**
	 * Pins the defect described in the wp_check_filetype_and_ext()
	 * docblock: the plugin's extension check is case-sensitive. Content that
	 * WordPress does not recognise as SVG by itself is accepted as
	 * `logo.svg` (see above) but rejected as `LOGO.SVG`.
	 *
	 * Fixed when the upper-case name is typed as SVG too; this test then
	 * fails and becomes a regression test for that.
	 *
	 * @group pinned
	 */
	public function test_upper_case_extension_is_not_restored_pinned_for_deferred_fix() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$content = "<!-- note -->\n<svg " . self::SVG_NS . '/>';

		$result = $this->filetype_of( $content, 'LOGO.SVG' );

		$this->assertFalse( $result['ext'] );
		$this->assertFalse( $result['type'] );
	}
}
