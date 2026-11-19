<?php

require_once __DIR__ . '/SanitizerTestCase.php';

use enshrined\svgSanitize\Sanitizer;

/**
 * Sanitizer: the checks the plugin relies on beyond the whitelists. What is
 * refused before or right after parsing, and what is removed so that the
 * output stays safe when a browser animates it or a page inlines it.
 *
 * sanitize() never throws: input it cannot process returns false and the
 * reason is the only issue reported.
 */
class SanitizerSafetyChecksTest extends SanitizerTestCase {

	/**
	 * @param string $input Document to sanitize.
	 * @return string[] Issue messages after the document was refused.
	 */
	private function issues_of_refused( $input ) {
		$this->assertFalse( $this->plugin_sanitizer()->sanitize( $input ) );

		return $this->issue_messages();
	}

	/**
	 * @param string $document A document in UTF-8.
	 * @param string $encoding `UTF-16LE` or `UTF-16BE`.
	 * @return string The document in that encoding, after its byte order mark.
	 */
	private function utf16( $document, $encoding ) {
		$encoded = '';
		foreach ( str_split( $document ) as $character ) {
			$encoded .= 'UTF-16LE' === $encoding ? $character . "\x00" : "\x00" . $character;
		}

		return ( 'UTF-16LE' === $encoding ? "\xFF\xFE" : "\xFE\xFF" ) . $encoded;
	}

	/* Refused before parsing. */

	/**
	 * @dataProvider provide_input_that_ends_inside_a_tag
	 */
	public function test_input_that_ends_inside_a_tag_returns_false( $input ) {
		$this->assertNotEmpty( $this->issues_of_refused( $input ) );
	}

	public function provide_input_that_ends_inside_a_tag() {
		return array(
			'a single opening bracket' => array( '<' ),
			'unclosed start tag'       => array( '<svg ' . self::SVG_NS . '><rect' ),
			'unclosed attribute value' => array( '<svg ' . self::SVG_NS . '><rect width="1' ),
			'unclosed comment'         => array( '<svg ' . self::SVG_NS . '><!-- note' ),
			'unclosed cdata section'   => array( '<svg ' . self::SVG_NS . '><![CDATA[ text' ),
			'unclosed declaration'     => array( '<?xml version="1.0"' ),
			'unclosed doctype'         => array( '<!DOCTYPE svg [' ),
		);
	}

	/**
	 * @dataProvider provide_input_that_is_empty_once_stripped
	 */
	public function test_input_with_nothing_left_after_stripping_returns_false( $input ) {
		$this->assertSame( array( 'Document is empty' ), $this->issues_of_refused( $input ) );
	}

	public function provide_input_that_is_empty_once_stripped() {
		return array(
			'only a document type'    => array( '<!DOCTYPE svg>' ),
			'only a php tag'          => array( '<?php echo 1; ?>' ),
			'php tag and white space' => array( " <?= 1 ?>\n" ),
		);
	}

	/**
	 * Removing one document type can join the text around it into another
	 * one, which is refused instead of being stripped again.
	 */
	public function test_document_type_hidden_inside_another_one_returns_false() {
		$input = '<!<!DOCTYPE x>DOCTYPE svg [<!ENTITY e "v">]>' . $this->svg( '<rect/>' );

		$this->assertSame( array( 'Nested DOCTYPE declaration' ), $this->issues_of_refused( $input ) );
	}

	public function test_element_with_as_many_attributes_as_the_limit_is_accepted() {
		$attributes = '';
		for ( $number = 1; $number <= Sanitizer::MAX_ATTRIBUTES_PER_ELEMENT; $number++ ) {
			$attributes .= " data-a$number=\"1\"";
		}

		$inner = $this->sanitize_inner( "<rect$attributes/>" );

		$this->assertSame( Sanitizer::MAX_ATTRIBUTES_PER_ELEMENT, substr_count( $inner, ' data-a' ) );
	}

	public function test_element_with_more_attributes_than_the_limit_returns_false() {
		$attributes = '';
		for ( $number = 0; $number <= Sanitizer::MAX_ATTRIBUTES_PER_ELEMENT; $number++ ) {
			$attributes .= " data-a$number=\"1\"";
		}

		$this->assertSame(
			array( 'Too many attributes on one element' ),
			$this->issues_of_refused( $this->svg( "<rect$attributes/>" ) )
		);
	}

	/**
	 * Equals signs in text, comments and attribute values are not attributes.
	 */
	public function test_attribute_limit_counts_attributes_only() {
		$many = str_repeat( 'a=1 ', Sanitizer::MAX_ATTRIBUTES_PER_ELEMENT + 1 );

		$inner = $this->sanitize_inner( "<!-- $many --><text data-note=\"$many\">$many</text>" );

		$this->assertStringStartsWith( '<text data-note="a=1 a=1 ', $inner );
	}

	/* Encodings. */

	/**
	 * @dataProvider provide_accepted_encodings
	 */
	public function test_document_in_an_ascii_compatible_encoding_is_sanitized( $encoding ) {
		$output = $this->plugin_sanitizer()->sanitize(
			'<?xml version="1.0" encoding="' . $encoding . '"?>' . $this->svg( '<rect onclick="a()"/>' )
		);

		$this->assertSame( $this->svg( '<rect></rect>' ), $output );
	}

	public function provide_accepted_encodings() {
		return $this->keyed( array( 'UTF-8', 'utf-8', 'US-ASCII', 'ISO-8859-1', 'ISO-8859-15', 'windows-1252' ) );
	}

	/**
	 * The markup of these documents cannot be read before parsing, so they
	 * are refused: an encoding that is not ASCII-compatible, an unknown one,
	 * and a declaration that does not match the bytes.
	 *
	 * @dataProvider provide_refused_encodings
	 */
	public function test_document_in_an_encoding_that_cannot_be_checked_returns_false( $input ) {
		$this->assertSame( array( 'Unsupported document encoding' ), $this->issues_of_refused( $input ) );
	}

	public function provide_refused_encodings() {
		$svg = $this->svg( '<rect/>' );

		return array(
			'UTF-7 declared'            => array( '<?xml version="1.0" encoding="UTF-7"?>' . $svg ),
			'unknown encoding declared' => array( '<?xml version="1.0" encoding="x-made-up"?>' . $svg ),
			'UTF-16 declared in ASCII'  => array( '<?xml version="1.0" encoding="UTF-16"?>' . $svg ),
			'EBCDIC declaration'        => array( "\x4C\x6F\xA7\x94\x93\x40" ),
			'UTF-8 declared in UTF-16'  => array( $this->utf16( '<?xml version="1.0" encoding="UTF-8"?>' . $svg, 'UTF-16LE' ) ),
		);
	}

	/**
	 * @dataProvider provide_utf16_encodings
	 */
	public function test_utf16_document_is_sanitized_and_written_as_utf8( $encoding ) {
		$input = $this->utf16( '<?xml version="1.0" encoding="UTF-16"?>' . $this->svg( '<script>a()</script><rect/>' ), $encoding );

		$this->assertSame( $this->svg( '<rect></rect>' ), $this->plugin_sanitizer()->sanitize( $input ) );
	}

	/**
	 * The document type cannot be stripped from these bytes, so the parser
	 * would apply its entities: the document is refused before it is parsed.
	 *
	 * @dataProvider provide_utf16_encodings
	 */
	public function test_utf16_document_with_an_internal_document_type_returns_false( $encoding ) {
		$input = $this->utf16(
			'<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE svg [<!ENTITY e "<script>a()</script>">]>' . $this->svg( '<g>&e;</g>' ),
			$encoding
		);

		$this->assertSame( array( 'Internal DTD subset is not allowed' ), $this->issues_of_refused( $input ) );
	}

	public function provide_utf16_encodings() {
		return $this->keyed( array( 'UTF-16LE', 'UTF-16BE' ) );
	}

	/* Refused after parsing. */

	/**
	 * An `<svg>` root in another namespace is not SVG for an XML reader, but
	 * becomes SVG when a page inlines the output.
	 */
	public function test_svg_root_in_another_namespace_returns_false() {
		$this->assertSame(
			array( 'Root element is not in the SVG namespace' ),
			$this->issues_of_refused( '<svg xmlns="http://example.org/ns"><rect/></svg>' )
		);
	}

	/* Animations. */

	/**
	 * An animation cannot be pointed at a link target: the `attributeName`
	 * is removed and the element is left with nothing to animate.
	 *
	 * @dataProvider provide_animation_targets_that_are_link_targets
	 */
	public function test_animation_loses_an_attribute_name_that_targets_a_link( $target ) {
		$inner = $this->sanitize_inner(
			'<a href="#top"><animateTransform attributeName="' . $target . '" values="0;1"/><rect/></a>',
			self::XLINK_NS
		);

		$this->assertSame( '<a href="#top"><animateTransform values="0;1"></animateTransform><rect></rect></a>', $inner );
		$this->assertSame( array( "Suspicious attribute 'attributeName'" ), $this->issue_messages() );
	}

	public function provide_animation_targets_that_are_link_targets() {
		return $this->keyed( array( 'href', 'xlink:href', 'HREF', 'XLink:HrEf', ' href ' ) );
	}

	public function test_animation_keeps_any_other_attribute_name() {
		$markup = '<rect><animateTransform attributeName="transform" type="rotate" values="0;90"></animateTransform></rect>';

		$this->assertSame( $markup, $this->sanitize_inner( $markup ) );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/* Script and document addresses outside href. */

	/**
	 * Any attribute that could hold an address loses a value that starts
	 * with a script scheme or a `data:` address that is not an image.
	 *
	 * @dataProvider provide_unsafe_addresses
	 */
	public function test_removes_attribute_whose_value_starts_with_an_unsafe_address( $value ) {
		$inner = $this->sanitize_inner( '<rect fill="' . $value . '" width="1"/><animateTransform values="' . $value . '"/>' );

		$this->assertSame( '<rect width="1"></rect><animateTransform></animateTransform>', $inner );
		$this->assertSame( array( "Suspicious attribute 'values'", "Suspicious attribute 'fill'" ), $this->issue_messages() );
	}

	public function provide_unsafe_addresses() {
		return array(
			'javascript'               => array( 'javascript:alert(1)' ),
			'vbscript'                 => array( 'vbscript:msgbox(1)' ),
			'mixed case'               => array( 'JaVaScRiPt:alert(1)' ),
			'leading white space'      => array( '  javascript:alert(1)' ),
			'html document'            => array( 'data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;' ),
			'svg document'             => array( 'data:image-svg,x' ),
		);
	}

	/**
	 * A browser drops tabs and line breaks from an address. Without
	 * minifying the value is removed; with it, as in the plugin, the
	 * character is a space by the time the value is checked, and a scheme
	 * with a space in it is not a scheme.
	 *
	 * @dataProvider provide_schemes_split_by_white_space
	 */
	public function test_scheme_split_by_a_tab_or_line_break_is_removed_or_made_harmless( $value ) {
		$sanitizer = new Sanitizer();
		$sanitizer->removeXMLTag( true );

		$this->assertStringNotContainsString( 'script', $sanitizer->sanitize( $this->svg( '<rect fill="' . $value . '"/>' ) ) );
		$this->assertSame( '<rect fill="java script:alert(1)"></rect>', $this->sanitize_inner( '<rect fill="' . $value . '"/>' ) );
	}

	public function provide_schemes_split_by_white_space() {
		return array(
			'tab'        => array( 'java&#9;script:alert(1)' ),
			'line break' => array( 'java&#10;script:alert(1)' ),
		);
	}

	/**
	 * @dataProvider provide_values_that_are_not_unsafe_addresses
	 */
	public function test_keeps_attribute_whose_value_is_not_an_unsafe_address( $value ) {
		$markup = '<rect fill="' . $value . '"></rect>';

		$this->assertSame( $markup, $this->sanitize_inner( $markup ) );
	}

	public function provide_values_that_are_not_unsafe_addresses() {
		return array(
			'colour'                      => array( '#fff' ),
			'local reference'             => array( 'url(#gradient)' ),
			'image data'                  => array( 'data:image/png;base64,iVBORw0KGgo=' ),
			'scheme later in the value'   => array( '0;javascript:alert(1)' ),
			'word that only starts alike' => array( 'javascripted' ),
		);
	}

	/**
	 * Values that are never read as addresses are not checked.
	 *
	 * @dataProvider provide_inert_attributes
	 */
	public function test_keeps_script_address_in_an_attribute_that_never_holds_an_address( $attribute ) {
		$markup = '<rect ' . $attribute . '="javascript:alert(1)"></rect>';

		$this->assertSame( $markup, $this->sanitize_inner( $markup ) );
	}

	public function provide_inert_attributes() {
		return $this->keyed( array( 'id', 'class', 'role', 'data-note', 'aria-label' ) );
	}

	/* Spellings that only an HTML parser reads. */

	public function test_removes_id_written_in_another_letter_case() {
		$inner = $this->sanitize_inner( '<rect ID="a" width="1"/><circle Id="b"/><path id="c"/>' );

		$this->assertSame( '<rect width="1"></rect><circle></circle><path id="c"></path>', $inner );
		$this->assertSame( array( "Suspicious attribute 'Id'", "Suspicious attribute 'ID'" ), $this->issue_messages() );
	}

	public function test_removes_use_in_another_namespace() {
		$inner = $this->sanitize_inner( '<symbol id="a"><rect/></symbol><use xmlns="" href="#a"/><use href="#a"/>' );

		$this->assertSame( '<symbol id="a"><rect></rect></symbol><use href="#a"></use>', $inner );
	}

	/* Duplicate ids. */

	/**
	 * A browser resolves a reference to the first element with that id, so
	 * later elements lose it and the limits measure the element that is
	 * drawn.
	 */
	public function test_later_elements_lose_an_id_that_a_use_references() {
		$inner = $this->sanitize_inner( '<rect id="a"/><circle id="a"/><g id="a"><path/></g><use href="#a"/>' );

		$this->assertSame( '<rect id="a"></rect><circle></circle><g><path></path></g><use href="#a"></use>', $inner );
		$this->assertSame( array( "Duplicate id 'a'", "Duplicate id 'a'" ), $this->issue_messages() );
	}

	/**
	 * Style rules apply to every element with the id, so ids no `<use>`
	 * references stay.
	 */
	public function test_duplicate_ids_stay_when_no_use_references_them() {
		$markup = '<rect id="a"></rect><circle id="a"></circle><path id="b"></path><use href="#b"></use>';

		$this->assertSame( $markup, $this->sanitize_inner( $markup ) );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/* The list of issues. */

	public function test_issues_beyond_the_limit_are_counted_in_one_closing_record() {
		$sanitizer = $this->plugin_sanitizer();

		$output = $sanitizer->sanitize( $this->svg( str_repeat( '<script/>', Sanitizer::MAX_XML_ISSUES + 5 ) . '<rect/>' ) );

		$this->assertSame( $this->svg( '<rect></rect>' ), $output );
		$issues = $sanitizer->getXmlIssues();
		$this->assertCount( Sanitizer::MAX_XML_ISSUES + 1, $issues );
		$this->assertSame( "Suspicious tag 'script'", $issues[0]['message'] );
		$this->assertSame(
			array(
				'message' => '5 more issues not listed',
				'line'    => 0,
			),
			end( $issues )
		);
	}

	public function test_issue_count_starts_again_with_the_next_run() {
		$sanitizer = $this->plugin_sanitizer();
		$sanitizer->sanitize( $this->svg( str_repeat( '<script/>', Sanitizer::MAX_XML_ISSUES + 5 ) ) );

		$sanitizer->sanitize( $this->svg( '<script/>' ) );

		$this->assertSame( array( "Suspicious tag 'script'" ), $this->issue_messages() );
	}

	/* Global state. */

	/**
	 * @dataProvider provide_refused_input_of_each_kind
	 */
	public function test_libxml_internal_errors_setting_is_restored_after_a_refusal( $input ) {
		libxml_use_internal_errors( false );

		$this->assertFalse( ( new Sanitizer() )->sanitize( $input ) );

		$this->assertFalse( libxml_use_internal_errors() );
		$this->assertSame( array(), libxml_get_errors() );
	}

	public function provide_refused_input_of_each_kind() {
		return array(
			'refused before parsing' => array( '<!DOCTYPE svg>' ),
			'not well formed'        => array( '<svg' ),
			'another root element'   => array( '<html/>' ),
		);
	}
}
