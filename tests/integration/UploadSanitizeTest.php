<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * The upload prefilter: which uploaded files are sanitized in place before
 * WordPress stores them, and which uploads are refused.
 *
 * WordPress applies `wp_handle_upload_prefilter` inside wp_handle_upload(),
 * which only accepts real HTTP uploads; the tests apply the filter the same
 * way on a temporary file.
 */
class UploadSanitizeTest extends WPSVGIntegrationTestCase {

	/**
	 * @param array $file One entry of `$_FILES`.
	 * @return array The entry after the prefilter.
	 */
	private function prefilter( array $file ) {
		return apply_filters( 'wp_handle_upload_prefilter', $file );
	}

	/* Sanitized or not. */

	public function test_svg_from_a_role_with_sanitized_permission_is_rewritten_in_place() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$file = $this->uploaded_file( self::DIRTY_SVG );

		$result = $this->prefilter( $file );

		$this->assertSame( $file, $result, 'The entry itself is unchanged' );
		$this->assertSame( self::SANITIZED_SVG, file_get_contents( $file['tmp_name'] ) );
	}

	public function test_svg_from_a_role_with_unrestricted_permission_is_left_untouched() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );
		$file = $this->uploaded_file( self::DIRTY_SVG );

		$result = $this->prefilter( $file );

		$this->assertSame( $file, $result );
		$this->assertSame( self::DIRTY_SVG, file_get_contents( $file['tmp_name'] ) );
	}

	/**
	 * @dataProvider provide_roles_for_a_user_with_two_roles
	 */
	public function test_one_unrestricted_role_is_enough_to_skip_sanitization( $permissions, $sanitized ) {
		$this->set_permissions( $permissions );
		$this->log_in_as( 'subscriber', array( 'author' ) );
		$file = $this->uploaded_file( self::DIRTY_SVG );

		$this->prefilter( $file );

		$this->assertSame( $sanitized ? self::SANITIZED_SVG : self::DIRTY_SVG, file_get_contents( $file['tmp_name'] ) );
	}

	public function provide_roles_for_a_user_with_two_roles() {
		return array(
			'both sanitized'            => array( array( 'subscriber' => 1, 'author' => 1 ), true ),
			'first unrestricted'        => array( array( 'subscriber' => 2, 'author' => 1 ), false ),
			'second unrestricted'       => array( array( 'subscriber' => 1, 'author' => 2 ), false ),
			'unrestricted and disabled' => array( array( 'subscriber' => 0, 'author' => 2 ), false ),
		);
	}

	/**
	 * The prefilter does not check permission: it sanitizes for everyone who
	 * is not unrestricted. Refusing the upload is the job of `upload_mimes`.
	 *
	 * @dataProvider provide_users_without_upload_permission
	 */
	public function test_svg_is_sanitized_even_for_a_user_who_may_not_upload_svg( $permissions, $role ) {
		$this->set_permissions( $permissions );
		if ( $role ) {
			$this->log_in_as( $role );
		} else {
			wp_set_current_user( 0 );
		}
		$file = $this->uploaded_file( self::DIRTY_SVG );

		$result = $this->prefilter( $file );

		$this->assertSame( 0, $result['error'] );
		$this->assertSame( self::SANITIZED_SVG, file_get_contents( $file['tmp_name'] ) );
	}

	public function provide_users_without_upload_permission() {
		return array(
			'role disabled' => array( array( 'author' => 0 ), 'author' ),
			'fresh install' => array( null, 'administrator' ),
			'logged out'    => array( array( 'administrator' => 2 ), null ),
		);
	}

	/**
	 * The decision comes from the file content; the client's file name and
	 * MIME type are ignored.
	 */
	public function test_svg_content_is_sanitized_whatever_name_and_type_the_client_sends() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$file = $this->uploaded_file( self::DIRTY_SVG, 'photo.png', 'image/png' );

		$this->prefilter( $file );

		$this->assertSame( self::SANITIZED_SVG, file_get_contents( $file['tmp_name'] ) );
	}

	/**
	 * @dataProvider provide_content_that_is_not_svg
	 */
	public function test_other_content_is_left_untouched_even_when_named_svg( $content ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$file = $this->uploaded_file( $content, 'image.svg', 'image/svg+xml' );

		$result = $this->prefilter( $file );

		$this->assertSame( 0, $result['error'] );
		$this->assertSame( $content, file_get_contents( $file['tmp_name'] ) );
	}

	public function provide_content_that_is_not_svg() {
		return array(
			'png image'  => array( base64_decode( self::PNG_BASE64 ) ),
			'plain text' => array( 'just text' ),
			'empty file' => array( '' ),
		);
	}

	/* Refused uploads. */

	public function test_svg_that_is_not_well_formed_is_refused_and_kept_as_it_is() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$content = $this->svg( '<rect>' );
		$file    = $this->uploaded_file( $content );

		$result = $this->prefilter( $file );

		$this->assertSame( 'This SVG can not be sanitized!', $result['error'] );
		$this->assertSame( $content, file_get_contents( $file['tmp_name'] ) );
	}

	public function test_svg_that_is_not_well_formed_is_accepted_from_an_unrestricted_role() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );
		$file = $this->uploaded_file( $this->svg( '<rect>' ) );

		$this->assertSame( 0, $this->prefilter( $file )['error'] );
	}

	/**
	 * Applies to every upload, not only to SVGs.
	 *
	 * @dataProvider provide_entries_without_a_usable_temporary_file
	 */
	public function test_upload_without_a_temporary_file_name_is_refused( $file ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$result = $this->prefilter( $file );

		$this->assertSame( 'Temp file is not be set!', $result['error'] );
	}

	public function provide_entries_without_a_usable_temporary_file() {
		return array(
			'tmp_name missing'      => array( array( 'name' => 'photo.png', 'type' => 'image/png', 'error' => 0, 'size' => 1 ) ),
			'tmp_name is null'      => array( array( 'name' => 'photo.png', 'tmp_name' => null, 'error' => 0 ) ),
			'several files at once' => array( array( 'name' => array( 'a.svg', 'b.svg' ), 'tmp_name' => array( '/tmp/a', '/tmp/b' ), 'error' => array( 0, 0 ) ) ),
		);
	}

	/* Pinned defects. */

	/**
	 * Pins a defect described in the wp_handle_upload_prefilter() docblock:
	 * only content that PHP's file-type detection reports as SVG is
	 * sanitized. A few leading characters are enough to make an SVG, or an
	 * XHTML page, be reported as HTML or XML: the script stays in the file,
	 * while wp_check_filetype_and_ext() still accepts the `.svg` name (see
	 * UploadPermissionTest).
	 *
	 * Fixed when the script is gone from the file or the upload is refused;
	 * this test then fails and becomes a regression test for that.
	 *
	 * @group pinned
	 * @dataProvider provide_scripted_content_not_detected_as_svg
	 */
	public function test_script_survives_when_content_is_not_detected_as_svg_pinned_for_deferred_fix( $content ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$file = $this->uploaded_file( $content );

		$result = $this->prefilter( $file );

		$this->assertSame( 0, $result['error'] );
		$this->assertSame( $content, file_get_contents( $file['tmp_name'] ) );
		$this->assertStringContainsString( '<script>alert(1)</script>', $content );
	}

	public function provide_scripted_content_not_detected_as_svg() {
		$svg = $this->svg_with_script();

		return array(
			'svg after a line break'       => array( "\n" . $svg ),
			'svg after spaces'             => array( '   ' . $svg ),
			'svg after a comment'          => array( "<!-- note -->\n" . $svg ),
			'svg after a long comment'     => array( "<?xml version=\"1.0\"?>\n<!-- " . str_repeat( 'x', 5000 ) . " -->\n" . $svg ),
			'xhtml page'                   => array( '<html xmlns="http://www.w3.org/1999/xhtml"><head><script>alert(1)</script></head></html>' ),
			'xhtml page with declaration'  => array( '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></html>' ),
		);
	}

	/**
	 * @return string
	 */
	private function svg_with_script() {
		return '<svg ' . self::SVG_NS . '><script>alert(1)</script></svg>';
	}

	/**
	 * Pins a defect described in the wp_handle_upload_prefilter() docblock:
	 * gzipped content is reported as `application/gzip`, so an `.svgz`
	 * upload is never sanitized although sanitize() can handle gzip.
	 *
	 * Fixed when the gzipped file is sanitized; this test then fails and
	 * becomes a regression test for that.
	 *
	 * @group pinned
	 */
	public function test_gzipped_svg_is_not_sanitized_pinned_for_deferred_fix() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$content = gzencode( self::DIRTY_SVG );
		$file    = $this->uploaded_file( $content, 'image.svgz' );

		$result = $this->prefilter( $file );

		$this->assertSame( 0, $result['error'] );
		$this->assertSame( $content, file_get_contents( $file['tmp_name'] ) );
	}

	/**
	 * Pins a defect described in the wp_handle_upload_prefilter() docblock:
	 * what the sanitizer throws is not caught, so the upload request ends
	 * with an uncaught exception or error instead of a refused upload.
	 *
	 * Fixed when the prefilter returns with `error` set; this test then
	 * fails and becomes a regression test for the error message.
	 *
	 * @group pinned
	 * @dataProvider provide_svg_that_makes_the_sanitizer_throw
	 */
	public function test_sanitizer_failure_escapes_the_upload_request_pinned_for_deferred_fix( $content, $expected_class ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$file = $this->uploaded_file( $content );

		try {
			$this->prefilter( $file );
			$this->fail( "Expected $expected_class" );
		} catch ( Throwable $thrown ) {
			$this->assertInstanceOf( $expected_class, $thrown );
			$this->assertSame( $content, file_get_contents( $file['tmp_name'] ) );
		}
	}

	public function provide_svg_that_makes_the_sanitizer_throw() {
		return array(
			'CDATA section in a style element' => array( $this->svg( '<style><![CDATA[ .a { fill: red; } ]]></style>' ), 'Error' ),
			'use referencing its own parent'   => array( $this->svg( '<g id="a"><use href="#a"/></g>' ), 'LogicException' ),
			'svg nested in another root'       => array( '<?xml version="1.0"?><x><svg ' . self::SVG_NS . '/></x>', 'LogicException' ),
		);
	}
}
