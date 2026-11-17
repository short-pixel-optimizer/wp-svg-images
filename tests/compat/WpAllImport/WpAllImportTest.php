<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * The plugin next to WP All Import, which asks through a filter for the MIME
 * type of imported images it could not identify.
 *
 * The tests import files the way an import with "use images currently
 * uploaded in wp-content/uploads/wpallimport/files/" does: the file waits in
 * that folder and PMXI_API::upload_image() turns it into an attachment. No
 * user is logged in, as in a scheduled import.
 *
 * The scenario `wp-all-import-previous` runs a release from before WP All
 * Import sanitized SVG files itself and before it shipped HTMLPurifier.
 */
class WpAllImportTest extends CompatTestCase {

	const SIZED_DIRTY_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="20" onload="alert(1)"><script>alert(1)</script><rect/></svg>';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );
	}

	/**
	 * Imports one file through WP All Import.
	 *
	 * @param string $name    File name in WP All Import's files folder.
	 * @param string $content File content.
	 * @param string $kind    `images` or `files`, WP All Import's two attachment kinds.
	 * @return int|false Attachment ID, false when WP All Import refuses the file.
	 */
	private function import( $name, $content, $kind = 'images' ) {
		$uploads = wp_upload_dir();
		$folder  = $uploads['basedir'] . '/' . PMXI_Plugin::FILES_DIRECTORY;
		wp_mkdir_p( $folder );
		file_put_contents( "$folder/$name", $content );
		$post_id = self::factory()->post->create();

		list( $result ) = $this->with_warnings_captured(
			static function () use ( $post_id, $name, $kind ) {
				return PMXI_API::upload_image( $post_id, $name, 'no', null, true, $name, $kind );
			}
		);

		return $result;
	}

	/* Loading. */

	public function test_scenario_runs_wp_all_import_at_the_pinned_version_with_its_tables() {
		global $wpdb;

		$this->assertContains( $this->scenario(), array( 'wp-all-import', 'wp-all-import-previous' ) );
		$this->assertSame( array( 'wp-all-import/plugin.php', 'wp-svg-images/wp-svg-images.php' ), get_option( 'active_plugins' ) );
		$this->assertSame( $this->pinned_version( 'wp-all-import' ), PMXI_VERSION );
		$this->assertSame( $wpdb->prefix . 'pmxi_images', $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}pmxi_images'" ) );
		$this->assertSame( 10, has_filter( 'wp_all_import_image_mime_type', array( $this->plugin(), 'wp_all_import_svgs' ) ) );
		$this->assertSame( array( 'wp-svg-images/svg-sanitizer' ), $this->sanitizer_copies(), 'WP All Import has no copy, or keeps it under another namespace' );
	}

	/* Imports. */

	public function test_imported_svg_image_becomes_an_svg_attachment() {
		$id = $this->import( 'logo.svg', self::SIZED_DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertStringEndsWith( '/logo.svg', get_attached_file( $id ) );
	}

	/**
	 * The "inactive" side of the comparison: WP All Import alone finds no
	 * type for an SVG image.
	 */
	public function test_imported_svg_image_has_no_type_without_this_plugins_filter() {
		remove_filter( 'wp_all_import_image_mime_type', array( $this->plugin(), 'wp_all_import_svgs' ), 10 );

		$id = $this->import( 'logo.svg', self::SIZED_DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertSame( '', get_post_mime_type( $id ) );
	}

	public function test_imported_svg_is_measured_by_this_plugin() {
		$id = $this->import( 'logo.svg', self::SIZED_DIRTY_SVG );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertSame( 30.0, $metadata['width'] );
		$this->assertSame( 20.0, $metadata['height'] );
	}

	/**
	 * The older WP All Import doesn't sanitize SVGs, so our filter has to.
	 *
	 * @dataProvider provide_attachment_kinds
	 */
	public function test_imported_svg_is_stored_sanitized( $kind ) {
		$id = $this->import( 'logo.svg', self::SIZED_DIRTY_SVG, $kind );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$stored = $this->stored_content( $id );
		$this->assertStringNotContainsString( 'script', $stored );
		$this->assertStringNotContainsString( 'onload', $stored );
		$this->assertStringContainsString( '<rect></rect>', $stored );
	}

	/**
	 * WP All Import or our filter deletes the file, so nothing is left to
	 * serve.
	 */
	public function test_imported_svg_that_cannot_be_sanitized_is_not_kept() {
		$xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></html>';

		$id = $this->import( 'page.svg', $xhtml, 'files' );

		$this->assertSame( array(), glob( wp_upload_dir()['path'] . '/page*.svg' ) );
		if ( false !== $id ) {
			$this->assertSame( '', get_post_mime_type( $id ) );
		}
	}

	public function provide_attachment_kinds() {
		return array(
			'image' => array( 'images' ),
			'file'  => array( 'files' ),
		);
	}

	/**
	 * @dataProvider provide_settings
	 */
	public function test_import_does_not_depend_on_the_per_role_settings( $permissions ) {
		$this->set_permissions( $permissions );
		$this->assertArrayNotHasKey( 'svg', get_allowed_mime_types() );

		$id = $this->import( 'logo.svg', self::SIZED_DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
	}

	public function provide_settings() {
		return array(
			'no settings saved'   => array( null ),
			'every role disabled' => array(
				array(
					'administrator' => 0,
					'editor'        => 0,
					'author'        => 0,
				),
			),
		);
	}

	/**
	 * WP All Import stores the file with a lower-case extension before it
	 * asks for the type, so the case-sensitive check pinned in the `unit`
	 * suite is not reached on this path.
	 */
	public function test_upper_case_extension_is_stored_in_lower_case_and_typed_as_svg() {
		$id = $this->import( 'LOGO.SVG', self::SIZED_DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertStringEndsWith( '/LOGO.svg', get_attached_file( $id ) );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
	}

	/**
	 * @dataProvider provide_other_files
	 */
	public function test_other_imported_files_keep_the_type_wp_all_import_found( $name, $content_base64, $kind, $type ) {
		$id = $this->import( $name, base64_decode( $content_base64 ), $kind );

		$this->assertIsInt( $id );
		$this->assertSame( $type, get_post_mime_type( $id ) );
	}

	public function provide_other_files() {
		return array(
			'PNG image' => array( 'pixel.png', self::PNG_BASE64, 'images', 'image/png' ),
			'text file' => array( 'notes.txt', base64_encode( 'hello' ), 'files', 'text/plain' ),
		);
	}

	public function test_gzipped_svg_is_refused_by_wp_all_import_as_an_image() {
		$attachments_before = wp_count_posts( 'attachment' )->inherit;

		$result = $this->import( 'logo.svgz', gzencode( self::SIZED_DIRTY_SVG ) );

		$this->assertFalse( $result );
		$this->assertSame( $attachments_before, wp_count_posts( 'attachment' )->inherit );
	}

	/**
	 * No WP All Import release sanitizes `.svgz`, so our filter does.
	 */
	public function test_gzipped_svg_imported_as_a_file_is_stored_sanitized() {
		$id = $this->import( 'logo.svgz', gzencode( self::SIZED_DIRTY_SVG ), 'files' );

		$this->assertIsInt( $id );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( $id ) );
		$this->assertSame( '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="20"><rect></rect></svg>', gzdecode( $this->stored_content( $id ) ) );
	}

	/* HTMLPurifier, which WP All Import ships. */

	/**
	 * The current WP All Import ships HTMLPurifier and the older release
	 * does not; a CDATA section is stored as text either way.
	 */
	public function test_cdata_upload_is_stored_as_text_whether_or_not_wp_all_import_ships_htmlpurifier() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$this->assertSame( ! $this->uses_previous_versions(), class_exists( 'HTMLPurifier' ) );
		if ( ! $this->uses_previous_versions() ) {
			$this->assertStringStartsWith( WP_PLUGIN_DIR . '/wp-all-import/', ( new ReflectionClass( 'HTMLPurifier' ) )->getFileName() );
		}

		$id = $this->media_upload( $this->svg( self::CDATA_STYLE . '<rect/>' ) );

		$this->assertIsInt( $id );
		$this->assertSame( $this->svg( '<style>rect{fill:red}</style><rect></rect>' ), $this->stored_content( $id ) );
	}
}
