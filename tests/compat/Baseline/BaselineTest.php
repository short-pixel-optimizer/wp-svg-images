<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * The plugin alone, with the third parties of the other scenarios installed
 * but not active. This is the "inactive" side of every compat comparison;
 * the `integration` suite covers the plugin's own behaviour in depth.
 */
class BaselineTest extends CompatTestCase {

	public function test_scenario_runs_with_only_this_plugin_active() {
		$this->assertSame( 'baseline', $this->scenario() );
		$this->assertSame( array( 'wp-svg-images/wp-svg-images.php' ), get_option( 'active_plugins' ) );
		$this->assertSame( 'wp-svg-images/wp-svg-images.php', $this->file_of_class( 'WPSVG' ) );
		$this->assertCount( 1, $this->plugin_instances() );
	}

	public function test_third_party_plugins_are_installed_at_the_pinned_versions_but_not_loaded() {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/plugins.json' ), true );
		$plugins  = get_plugins();

		$this->assertSame( $manifest['plugins']['safe-svg'], $plugins['safe-svg/safe-svg.php']['Version'] );
		$this->assertFalse( is_plugin_active( 'safe-svg/safe-svg.php' ) );
		$this->assertFalse( class_exists( 'SafeSvg\safe_svg', false ) );
		$this->assertFalse( class_exists( 'HTMLPurifier' ) );
	}

	public function test_sanitizer_classes_come_from_the_bundled_copy() {
		$this->assertSame( array( 'wp-svg-images/svg-sanitizer' ), $this->sanitizer_copies() );
	}

	public function test_sanitized_upload_is_stored_in_this_plugins_format() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$id = $this->media_upload( self::DIRTY_SVG );

		$this->assertIsInt( $id );
		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $id ) );
	}

	public function test_role_disabled_in_the_settings_cannot_upload() {
		$this->set_permissions( array( 'author' => 0 ) );
		$this->log_in_as( 'author' );

		$result = $this->media_upload( self::DIRTY_SVG );

		$this->assertWPError( $result );
		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $result->get_error_message() );
	}

	/**
	 * Without HTMLPurifier a CDATA section is stored as text; the
	 * `htmlpurifier` scenario checks that the library changes nothing.
	 */
	public function test_cdata_upload_is_stored_as_text_without_htmlpurifier() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
		$this->assertFalse( class_exists( 'HTMLPurifier' ) );

		$id = $this->media_upload( $this->svg( self::CDATA_STYLE . '<rect/>' ) );

		$this->assertIsInt( $id );
		$this->assertSame( $this->svg( '<style>rect{fill:red}</style><rect></rect>' ), $this->stored_content( $id ) );
	}
}
