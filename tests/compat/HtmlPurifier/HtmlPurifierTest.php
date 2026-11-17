<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * The plugin on a site where HTMLPurifier is loaded, as happens when another
 * plugin ships it. The bundled sanitizer does not use that library, so
 * CDATA sections are stored as text exactly as without it (see
 * BaselineTest).
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

	/**
	 * Markup inside a CDATA section stays text: every `<` is escaped, so no
	 * element can start in the stored file.
	 */
	public function test_markup_inside_a_cdata_section_is_stored_as_escaped_text() {
		$id = $this->media_upload( $this->svg( '<style><![CDATA[</style><script>alert(1)</script><style>rect{fill:red}]]></style><rect/>' ) );

		$this->assertIsInt( $id );
		$this->assertSame(
			$this->svg( '<style>&lt;/style&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;style&gt;rect{fill:red}</style><rect></rect>' ),
			$this->stored_content( $id )
		);
	}

	public function test_cdata_inside_a_script_element_goes_with_the_element() {
		$id = $this->media_upload( $this->svg( '<script><![CDATA[alert(1)]]></script><rect/>' ) );

		$this->assertSame( $this->svg( '<rect></rect>' ), $this->stored_content( $id ) );
	}

	/**
	 * @dataProvider provide_cdata_with_special_characters
	 */
	public function test_special_characters_in_cdata_are_encoded_once( $markup, $stored_markup ) {
		$id = $this->media_upload( $this->svg( $markup ) );

		$this->assertIsInt( $id );
		$this->assertSame( $this->svg( $stored_markup ), $this->stored_content( $id ) );
	}

	public function provide_cdata_with_special_characters() {
		return array(
			'child selector in a style rule' => array(
				'<style><![CDATA[g > rect{fill:red}]]></style>',
				'<style>g &gt; rect{fill:red}</style>',
			),
			'comparison in a text element'   => array(
				'<text><![CDATA[a < b & c]]></text>',
				'<text>a &lt; b &amp; c</text>',
			),
		);
	}
}
