<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * The plugin on a site where HTMLPurifier is loaded, as happens when another
 * plugin ships it. The bundled sanitizer hands CDATA sections to that
 * library; without it the same uploads end in an Error (see BaselineTest).
 */
class HtmlPurifierTest extends CompatTestCase {

	public function set_up() {
		parent::set_up();
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );
	}

	public function test_scenario_loads_htmlpurifier_at_the_pinned_version_next_to_the_bundled_sanitizer() {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/plugins.json' ), true );

		$this->assertSame( 'htmlpurifier', $this->scenario() );
		$this->assertTrue( class_exists( 'HTMLPurifier' ), 'The library is available through its autoloader' );
		$this->assertSame( $manifest['libraries']['htmlpurifier']['version'], HTMLPurifier::VERSION );
		$this->assertSame( array( 'wp-svg-images/svg-sanitizer' ), $this->sanitizer_copies() );
	}

	public function test_svg_without_cdata_is_sanitized_as_without_the_library() {
		$id = $this->media_upload( self::DIRTY_SVG );

		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $id ) );
	}

	public function test_cdata_style_upload_is_stored_with_its_rule_as_plain_text() {
		list( $id, $warnings ) = $this->with_warnings_captured(
			function () {
				return $this->media_upload( $this->svg( self::CDATA_STYLE . '<rect/>' ) );
			}
		);

		$this->assertIsInt( $id );
		$this->assertSame( $this->svg( '<style>rect{fill:red}</style><rect></rect>' ), $this->stored_content( $id ) );
		$this->assertSame( array(), $warnings );
	}

	public function test_markup_inside_a_cdata_section_is_removed() {
		$id = $this->media_upload( $this->svg( '<style><![CDATA[</style><script>alert(1)</script><style>rect{fill:red}]]></style><rect/>' ) );

		$this->assertIsInt( $id );
		$stored = $this->stored_content( $id );
		$this->assertStringNotContainsString( 'script', $stored );
		$this->assertStringNotContainsString( 'alert', $stored );
		$this->assertStringEndsWith( '<rect></rect></svg>', $stored );
	}

	public function test_cdata_inside_a_script_element_goes_with_the_element() {
		$id = $this->media_upload( $this->svg( '<script><![CDATA[alert(1)]]></script><rect/>' ) );

		$this->assertSame( $this->svg( '<rect></rect>' ), $this->stored_content( $id ) );
	}

	/**
	 * Pins the defect in Sanitizer::cleanUnsafeNodes(): CDATA content comes
	 * out encoded twice, which breaks style rules that use `>` and shows
	 * entities in text. When fixed, the characters are encoded once.
	 *
	 * @group pinned
	 * @dataProvider provide_cdata_with_special_characters
	 */
	public function test_special_characters_in_cdata_are_encoded_twice_pinned_for_deferred_fix( $markup, $stored_markup, $once_encoded ) {
		$id = $this->media_upload( $this->svg( $markup ) );

		$this->assertIsInt( $id );
		$stored = $this->stored_content( $id );
		$this->assertSame( $this->svg( $stored_markup ), $stored );
		$this->assertStringNotContainsString( $once_encoded, $stored );
	}

	public function provide_cdata_with_special_characters() {
		return array(
			'child selector in a style rule' => array(
				'<style><![CDATA[g > rect{fill:red}]]></style>',
				'<style>g &amp;gt; rect{fill:red}</style>',
				'<style>g &gt; rect',
			),
			'comparison in a text element'   => array(
				'<text><![CDATA[a < b & c]]></text>',
				'<text>a &amp;lt; b &amp;amp; c</text>',
				'<text>a &lt; b &amp; c',
			),
		);
	}
}
