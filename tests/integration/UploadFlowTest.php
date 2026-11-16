<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * Complete upload flows through WordPress: what ends up in the Media Library
 * when an SVG arrives through the media modal, the REST API or a sideload.
 *
 * Uploads that WordPress handles with wp_handle_upload() are run under the
 * mock action and forwarded to the real prefilter hook; see
 * WPSVGIntegrationTestCase::media_upload(). Sideloads need no such help.
 */
class UploadFlowTest extends WPSVGIntegrationTestCase {

	const SIZED_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="20" viewBox="0 0 300 200"><rect/></svg>';

	/**
	 * @param string $content Body of the mocked HTTP response.
	 * @return ArrayObject Requested URLs, filled as requests are made.
	 */
	private function mock_download( $content ) {
		$requested = new ArrayObject();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $content, $requested ) {
				$requested[] = $url;
				file_put_contents( $args['filename'], $content );

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'],
				);
			},
			10,
			3
		);

		return $requested;
	}

	/**
	 * @param string $content File content.
	 * @param string $name    Client file name.
	 * @return WP_REST_Response
	 */
	private function rest_multipart_upload( $content, $name = 'image.svg' ) {
		$this->forward_mock_uploads_to_the_upload_prefilter();
		$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$request->set_file_params( array( 'file' => $this->uploaded_file( $content, $name ) ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @param string $content Request body.
	 * @param string $name    File name of the Content-Disposition header.
	 * @return WP_REST_Response
	 */
	private function rest_raw_upload( $content, $name = 'image.svg' ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$request->set_header( 'Content-Type', 'image/svg+xml' );
		$request->set_header( 'Content-Disposition', 'attachment; filename=' . $name );
		$request->set_body( $content );

		return rest_get_server()->dispatch( $request );
	}

	/* Media modal: media_handle_upload(). */

	public function test_media_upload_from_a_sanitized_role_stores_a_sanitized_svg_attachment() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$id = $this->media_upload( self::DIRTY_SVG, 'logo.svg' );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( 'logo', get_post( $id )->post_title );
		$this->assertStringEndsWith( '/logo.svg', get_attached_file( $id ) );
		$this->assertStringEndsWith( '/logo.svg', wp_get_attachment_url( $id ) );
		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $id ) );
	}

	public function test_media_upload_from_an_unrestricted_role_stores_the_file_as_sent() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );

		$id = $this->media_upload( self::DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( self::DIRTY_SVG, $this->stored_content( $id ) );
	}

	/**
	 * @dataProvider provide_users_without_upload_permission
	 */
	public function test_media_upload_without_permission_is_refused_and_stores_nothing( $permissions, $role ) {
		$this->set_permissions( $permissions );
		$this->log_in_as( $role );
		$attachments_before = wp_count_posts( 'attachment' )->inherit;

		$result = $this->media_upload( self::DIRTY_SVG );

		$this->assertWPError( $result );
		$this->assertSame( 'upload_error', $result->get_error_code() );
		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $result->get_error_message() );
		$this->assertSame( $attachments_before, wp_count_posts( 'attachment' )->inherit );
	}

	public function provide_users_without_upload_permission() {
		return array(
			'role disabled'            => array( array( 'author' => 0 ), 'author' ),
			'role not in the settings' => array( array( 'author' => 1 ), 'editor' ),
			'no settings saved'        => array( null, 'administrator' ),
		);
	}

	public function test_media_upload_of_a_malformed_svg_is_refused_with_the_plugin_message() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$result = $this->media_upload( $this->svg( '<rect>' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'This SVG can not be sanitized!', $result->get_error_message() );
	}

	public function test_media_upload_of_a_malformed_svg_is_stored_for_an_unrestricted_role() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );
		$content = $this->svg( '<rect>' );

		list( $id, $warnings ) = $this->with_warnings_captured(
			function () use ( $content ) {
				return $this->media_upload( $content );
			}
		);

		$this->assertIsInt( $id );
		$this->assertSame( $content, $this->stored_content( $id ) );
		$this->assertStringContainsString( 'simplexml_load_file()', implode( "\n", $warnings ), 'Reading the dimensions raises PHP warnings' );
	}

	/**
	 * Pins the defect in WPSVG::wp_check_filetype_and_ext(): the plugin
	 * accepts SVG content that WordPress does not recognise by forcing the
	 * type for the file name, but only for a lower-case extension, so the
	 * same file is refused as `LOGO.SVG`. Which content WordPress recognises
	 * depends on the PHP version; a leading comment is unrecognised on all
	 * of them. When fixed, the upload is stored like the lower-case one.
	 *
	 * @group pinned
	 */
	public function test_media_upload_with_upper_case_extension_is_refused_pinned_for_deferred_fix() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );
		$content = '<!-- logo -->' . $this->svg( '<rect/>' );

		$lower_case = $this->media_upload( $content, 'logo.svg' );
		$upper_case = $this->media_upload( $content, 'LOGO.SVG' );

		$this->assertIsInt( $lower_case );
		$this->assertWPError( $upper_case );
		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $upper_case->get_error_message() );
	}

	public function test_media_upload_stores_the_dimensions_of_the_sanitized_file() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$id = $this->media_upload( self::SIZED_SVG );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertSame( 30.0, $metadata['width'] );
		$this->assertSame( 20.0, $metadata['height'] );
	}

	public function test_media_upload_of_other_file_types_is_not_affected() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$png = base64_decode( self::PNG_BASE64 );

		$id = $this->media_upload( $png, 'pixel.png' );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$this->assertSame( $png, $this->stored_content( $id ) );
		$metadata = wp_get_attachment_metadata( $id );
		$this->assertSame( 1, $metadata['width'] );
		$this->assertSame( 1, $metadata['height'] );
	}

	/**
	 * Pins the defect in WPSVG::wp_handle_upload_prefilter(): SVG content
	 * that PHP does not detect as SVG is accepted by its file name and stored
	 * in the Media Library with its script. When fixed, the stored file has
	 * no script and no handler (or the upload is refused).
	 *
	 * @group pinned
	 * @dataProvider provide_svg_content_that_is_not_detected_as_svg
	 */
	public function test_script_reaches_the_media_library_when_content_is_not_detected_as_svg_pinned_for_deferred_fix( $content ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		list( $id ) = $this->with_warnings_captured(
			function () use ( $content ) {
				return $this->media_upload( $content );
			}
		);

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertStringEndsWith( '.svg', get_attached_file( $id ) );
		$this->assertSame( $content, $this->stored_content( $id ) );
		$this->assertStringContainsString( '<script>', $this->stored_content( $id ) );
	}

	public function provide_svg_content_that_is_not_detected_as_svg() {
		return array(
			'leading line break' => array( "\n" . self::DIRTY_SVG ),
			'leading comment'    => array( '<!-- logo -->' . $this->svg( '<script>alert(1)</script>' ) ),
			'XHTML page'         => array( '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>x</title></head><body><script>alert(1)</script></body></html>' ),
		);
	}

	/**
	 * Pins the defect in WPSVG::wp_handle_upload_prefilter(): a gzipped SVG
	 * is stored without being sanitized. When fixed, the decoded stored file
	 * equals the sanitized SVG.
	 *
	 * @group pinned
	 */
	public function test_gzipped_svg_reaches_the_media_library_unsanitized_pinned_for_deferred_fix() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		list( $id ) = $this->with_warnings_captured(
			function () {
				return $this->media_upload( gzencode( self::DIRTY_SVG ), 'logo.svgz' );
			}
		);

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertStringEndsWith( '/logo.svgz', get_attached_file( $id ) );
		$this->assertSame( self::DIRTY_SVG, gzdecode( $this->stored_content( $id ) ) );
	}

	/**
	 * Pins the defect in WPSVG::wp_check_filetype_and_ext(): a PNG image
	 * named `logo.svg` is stored under the name WordPress proposed for its
	 * content, `logo.png`, but with the SVG type the plugin forced, and its
	 * real dimensions are replaced with zeros. When fixed, the attachment's
	 * file extension and MIME type agree (or the upload is refused).
	 *
	 * @group pinned
	 */
	public function test_png_named_svg_is_stored_as_png_file_with_svg_type_pinned_for_deferred_fix() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		list( $id ) = $this->with_warnings_captured(
			function () {
				return $this->media_upload( base64_decode( self::PNG_BASE64 ), 'logo.svg' );
			}
		);

		$this->assertIsInt( $id );
		$this->assertStringEndsWith( '/logo.png', get_attached_file( $id ) );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$metadata = wp_get_attachment_metadata( $id );
		$this->assertSame( 0, $metadata['width'] );
		$this->assertSame( 0, $metadata['height'] );
	}

	/* REST API: POST /wp/v2/media. */

	public function test_rest_multipart_upload_from_a_sanitized_role_stores_a_sanitized_svg() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$response = $this->rest_multipart_upload( self::DIRTY_SVG, 'rest.svg' );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'image/svg+xml', $data['mime_type'] );
		$this->assertSame( 'image', $data['media_type'] );
		$this->assertStringEndsWith( '/rest.svg', $data['source_url'] );
		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $data['id'] ) );
	}

	public function test_rest_multipart_upload_reports_the_dimensions() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$response = $this->rest_multipart_upload( self::SIZED_SVG );

		$this->assertSame( 201, $response->get_status() );
		$details = $response->get_data()['media_details'];
		$this->assertSame( 30.0, $details['width'] );
		$this->assertSame( 20.0, $details['height'] );
	}

	public function test_rest_multipart_upload_from_an_unrestricted_role_stores_the_file_as_sent() {
		$this->set_permissions( array( 'administrator' => 2 ) );
		$this->log_in_as( 'administrator' );

		$response = $this->rest_multipart_upload( self::DIRTY_SVG );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( self::DIRTY_SVG, $this->stored_content( $response->get_data()['id'] ) );
	}

	public function test_rest_multipart_upload_of_a_malformed_svg_is_refused_with_the_plugin_message() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$response = $this->rest_multipart_upload( $this->svg( '<rect>' ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'This SVG can not be sanitized!', $response->get_data()['message'] );
	}

	/**
	 * @dataProvider provide_rest_upload_kinds
	 */
	public function test_rest_upload_without_permission_is_refused( $kind ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'editor' );
		$attachments_before = wp_count_posts( 'attachment' )->inherit;

		$response = 'raw' === $kind ? $this->rest_raw_upload( self::DIRTY_SVG ) : $this->rest_multipart_upload( self::DIRTY_SVG );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $response->get_data()['message'] );
		$this->assertSame( $attachments_before, wp_count_posts( 'attachment' )->inherit );
	}

	public function provide_rest_upload_kinds() {
		return array(
			'multipart form' => array( 'multipart' ),
			'raw body'       => array( 'raw' ),
		);
	}

	/**
	 * Pins the defect in WPSVG::wp_handle_upload_prefilter(): a REST upload
	 * sent as raw request body is handled by wp_handle_sideload(), which the
	 * plugin does not filter, so a role limited to sanitized uploads stores
	 * the SVG with its script. When fixed, the stored file equals the
	 * sanitized SVG.
	 *
	 * @group pinned
	 */
	public function test_rest_raw_body_upload_is_stored_unsanitized_pinned_for_deferred_fix() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$response = $this->rest_raw_upload( self::DIRTY_SVG, 'raw.svg' );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'image/svg+xml', $data['mime_type'] );
		$this->assertStringEndsWith( '/raw.svg', $data['source_url'] );
		$this->assertSame( self::DIRTY_SVG, $this->stored_content( $data['id'] ) );
	}

	/* Sideloads. */

	public function test_sideload_without_permission_is_refused() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'editor' );

		$file = $this->uploaded_file( self::DIRTY_SVG );

		$result = wp_handle_sideload( $file, array( 'test_form' => false ) );

		$this->assertSame( array( 'error' => 'Sorry, you are not allowed to upload this file type.' ), $result );
	}

	public function test_sideloaded_svg_gets_its_dimensions() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$id = media_handle_sideload( $this->uploaded_file( self::SIZED_SVG ), 0 );

		$this->assertIsInt( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$this->assertSame( 30.0, $metadata['width'] );
		$this->assertSame( 20.0, $metadata['height'] );
	}

	/**
	 * Pins the defect in WPSVG::wp_handle_upload_prefilter(): sideloaded
	 * files fire `wp_handle_sideload_prefilter`, which the plugin does not
	 * hook, so they are stored unsanitized for a role limited to sanitized
	 * uploads. When fixed, the stored file equals the sanitized SVG.
	 *
	 * @group pinned
	 */
	public function test_sideloaded_svg_is_stored_unsanitized_pinned_for_deferred_fix() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$file = $this->uploaded_file( self::DIRTY_SVG, 'side.svg' );

		$result = wp_handle_sideload( $file, array( 'test_form' => false ) );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'image/svg+xml', $result['type'] );
		$this->assertSame( self::DIRTY_SVG, file_get_contents( $result['file'] ) );
	}

	/**
	 * Same defect through media_handle_sideload(), which importers use to
	 * create the attachment.
	 *
	 * @group pinned
	 */
	public function test_sideloaded_attachment_is_stored_unsanitized_pinned_for_deferred_fix() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$id = media_handle_sideload( $this->uploaded_file( self::DIRTY_SVG, 'side.svg' ), 0 );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( self::DIRTY_SVG, $this->stored_content( $id ) );
	}

	/**
	 * WordPress itself refuses SVG addresses in media_sideload_image(); the
	 * plugin does not add the extension to `image_sideload_extensions`.
	 */
	public function test_media_sideload_image_refuses_an_svg_address_without_a_request() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$requested = $this->mock_download( self::DIRTY_SVG );

		$result = media_sideload_image( 'http://example.org/remote.svg', 0, null, 'id' );

		$this->assertWPError( $result );
		$this->assertSame( 'image_sideload_failed', $result->get_error_code() );
		$this->assertCount( 0, $requested );
	}

	/**
	 * Same defect when a site allows the extension through the
	 * `image_sideload_extensions` filter: the downloaded SVG is stored
	 * unsanitized. When fixed, the stored file equals the sanitized SVG.
	 *
	 * @group pinned
	 */
	public function test_media_sideload_image_stores_a_downloaded_svg_unsanitized_pinned_for_deferred_fix() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$requested = $this->mock_download( self::DIRTY_SVG );
		add_filter(
			'image_sideload_extensions',
			static function ( $extensions ) {
				$extensions[] = 'svg';

				return $extensions;
			}
		);

		$id = media_sideload_image( 'http://example.org/remote.svg', 0, null, 'id' );

		$this->assertSame( array( 'http://example.org/remote.svg' ), $requested->getArrayCopy() );
		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( self::DIRTY_SVG, $this->stored_content( $id ) );
	}
}
