<?php

require_once __DIR__ . '/SanitizerTestCase.php';

use enshrined\svgSanitize\data\AllowedAttributes;
use enshrined\svgSanitize\data\AllowedTags;
use enshrined\svgSanitize\data\AttributeInterface;
use enshrined\svgSanitize\data\TagInterface;
use enshrined\svgSanitize\Sanitizer;

/**
 * Sanitizer: options, input handling, output format, and the element,
 * attribute and node rules. Links are in SanitizerHrefTest, `<use>` handling
 * in SanitizerUseTest and the fixture files in SanitizerCorpusTest.
 */
class SanitizerTest extends SanitizerTestCase {

	/* Defaults and accessors. */

	public function test_default_whitelists_are_the_library_lists_in_lower_case() {
		$sanitizer = new Sanitizer();

		$this->assertSame( array_map( 'strtolower', AllowedTags::getTags() ), $sanitizer->getAllowedTags() );
		$this->assertSame( array_map( 'strtolower', AllowedAttributes::getAttributes() ), $sanitizer->getAllowedAttrs() );
	}

	public function test_default_xml_options_keep_empty_elements_expanded() {
		$this->assertSame( LIBXML_NOEMPTYTAG, ( new Sanitizer() )->getXMLOptions() );
	}

	public function test_no_issues_before_the_first_run() {
		$this->assertSame( array(), ( new Sanitizer() )->getXmlIssues() );
	}

	public function test_set_xml_options_is_returned_by_the_getter_and_used_for_the_output() {
		$sanitizer = $this->plugin_sanitizer();
		$sanitizer->setXMLOptions( 0 );

		$this->assertSame( 0, $sanitizer->getXMLOptions() );
		$this->assertSame( '<svg ' . self::SVG_NS . '><rect/></svg>', $sanitizer->sanitize( $this->svg( '<rect/>' ) ) );
	}

	public function test_set_allowed_tags_lower_cases_the_list_and_replaces_the_default() {
		$tags = new class() implements TagInterface {
			public static function getTags() {
				return array( 'svg', 'Custom', '#text' );
			}
		};

		$inner = $this->sanitize_inner(
			'<Custom/><custom/><rect/>',
			'',
			function ( Sanitizer $sanitizer ) use ( $tags ) {
				$sanitizer->setAllowedTags( $tags );
			}
		);

		$this->assertSame( array( 'svg', 'custom', '#text' ), $this->sanitizer->getAllowedTags() );
		$this->assertSame( '<Custom></Custom><custom></custom>', $inner );
		$this->assertSame( array( "Suspicious tag 'rect'" ), $this->issue_messages() );
	}

	public function test_set_allowed_attrs_lower_cases_the_list_and_replaces_the_default() {
		$attributes = new class() implements AttributeInterface {
			public static function getAttributes() {
				return array( 'xmlns', 'Custom' );
			}
		};

		$inner = $this->sanitize_inner(
			'<rect custom="1" CUSTOM="2" width="3"/>',
			'',
			function ( Sanitizer $sanitizer ) use ( $attributes ) {
				$sanitizer->setAllowedAttrs( $attributes );
			}
		);

		$this->assertSame( array( 'xmlns', 'custom' ), $this->sanitizer->getAllowedAttrs() );
		$this->assertSame( '<rect custom="1" CUSTOM="2"></rect>', $inner );
		$this->assertSame( array( "Suspicious attribute 'width'" ), $this->issue_messages() );
	}

	/* Output format. */

	public function test_default_output_has_the_xml_declaration_and_is_indented() {
		$output = ( new Sanitizer() )->sanitize( "<svg " . self::SVG_NS . " viewBox=\"0 0 1 1\"><rect width=\"1\" height=\"1\"/></svg>" );

		$this->assertSame(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. '<svg ' . self::SVG_NS . " viewBox=\"0 0 1 1\">\n"
			. "  <rect width=\"1\" height=\"1\"></rect>\n"
			. "</svg>\n",
			$output
		);
	}

	public function test_remove_xml_tag_drops_the_declaration() {
		$sanitizer = new Sanitizer();
		$sanitizer->removeXMLTag( true );

		$this->assertSame(
			'<svg ' . self::SVG_NS . ">\n  <rect></rect>\n</svg>",
			$sanitizer->sanitize( $this->svg( '<rect/>' ) )
		);
	}

	public function test_remove_xml_tag_without_argument_restores_the_declaration() {
		$sanitizer = new Sanitizer();
		$sanitizer->removeXMLTag( true );
		$sanitizer->removeXMLTag();

		$this->assertStringStartsWith( '<?xml version="1.0"', $sanitizer->sanitize( $this->svg( '<rect/>' ) ) );
	}

	public function test_minify_puts_the_document_on_one_line() {
		$sanitizer = new Sanitizer();
		$sanitizer->minify( true );

		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?> <svg ' . self::SVG_NS . '><g><rect></rect></g></svg> ',
			$sanitizer->sanitize( $this->svg( "\n  <g>\n    <rect/>\n  </g>\n" ) )
		);
	}

	/**
	 * Minifying is a blanket whitespace replacement on the serialised
	 * document, so text content and attribute values are affected too.
	 */
	public function test_minify_collapses_whitespace_inside_text_and_attribute_values() {
		$inner = $this->sanitize_inner( "<text>a   b\n\tc</text><rect fill=\"a  b\"/>" );

		$this->assertSame( '<text>a b c</text><rect fill="a b"></rect>', $inner );
	}

	public function test_minify_without_argument_restores_indented_output() {
		$sanitizer = new Sanitizer();
		$sanitizer->minify( true );
		$sanitizer->minify();
		$sanitizer->removeXMLTag( true );

		$this->assertSame( '<svg ' . self::SVG_NS . ">\n  <rect></rect>\n</svg>", $sanitizer->sanitize( $this->svg( '<rect/>' ) ) );
	}

	public function test_mixed_content_is_not_indented() {
		$sanitizer = new Sanitizer();
		$sanitizer->removeXMLTag( true );

		$this->assertSame(
			'<svg ' . self::SVG_NS . ">\n  <text>a <tspan>b</tspan> c</text>\n</svg>",
			$sanitizer->sanitize( $this->svg( '<text>a <tspan>b</tspan> c</text>' ) )
		);
	}

	public function test_text_keeps_non_ascii_characters_and_escapes_markup() {
		$inner = $this->sanitize_inner( '<text>café ☃ &amp; &lt;b&gt; &#169;</text><title>a &quot;q&quot;</title>' );

		$this->assertSame( '<text>café ☃ &amp; &lt;b&gt; ©</text><title>a "q"</title>', $inner );
	}

	public function test_a_byte_order_mark_before_the_document_is_accepted() {
		$output = $this->plugin_sanitizer()->sanitize( "\xEF\xBB\xBF" . $this->svg( '<rect/>' ) );

		$this->assertSame( '<svg ' . self::SVG_NS . '><rect></rect></svg>', $output );
	}

	public function test_a_document_without_a_namespace_is_sanitized_the_same_way() {
		$output = $this->plugin_sanitizer()->sanitize( '<svg onload="a()"><script/><rect/></svg>' );

		$this->assertSame( '<svg><rect></rect></svg>', $output );
	}

	/* Input handling. */

	/**
	 * @dataProvider provide_empty_inputs
	 */
	public function test_empty_input_returns_an_empty_string( $input ) {
		$this->assertSame( '', ( new Sanitizer() )->sanitize( $input ) );
	}

	public function provide_empty_inputs() {
		return array(
			'empty string' => array( '' ),
			'string zero'  => array( '0' ),
			'null'         => array( null ),
			'false'        => array( false ),
			'integer zero' => array( 0 ),
			'empty array'  => array( array() ),
		);
	}

	/**
	 * @dataProvider provide_input_that_is_not_well_formed
	 */
	public function test_input_that_is_not_well_formed_xml_returns_false( $input ) {
		$sanitizer = new Sanitizer();

		$this->assertFalse( $sanitizer->sanitize( $input ) );
		$this->assertSame( array(), $sanitizer->getXmlIssues() );
	}

	public function provide_input_that_is_not_well_formed() {
		return array(
			'whitespace only'            => array( ' ' ),
			'plain text'                 => array( 'plain text' ),
			'truncated tag'              => array( '<svg' ),
			'unclosed element'           => array( '<svg ' . self::SVG_NS . '><rect></svg>' ),
			'two root elements'          => array( '<svg ' . self::SVG_NS . '/><svg ' . self::SVG_NS . '/>' ),
			'newline before declaration' => array( "\n<?xml version=\"1.0\"?><svg " . self::SVG_NS . '/>' ),
			'NUL byte'                   => array( '<svg ' . self::SVG_NS . ">\x00</svg>" ),
			'undefined entity'           => array( '<svg ' . self::SVG_NS . '><text>&nbsp;</text></svg>' ),
			'short PHP tag'              => array( '<svg ' . self::SVG_NS . '><? echo 1; ?></svg>' ),
		);
	}

	/**
	 * Well-formed XML that is not an SVG is not a `false`: it throws.
	 */
	public function test_well_formed_xml_with_another_root_element_throws() {
		$sanitizer = new Sanitizer();

		$this->expectException( LogicException::class );
		$this->expectExceptionCode( 1570870568 );

		$sanitizer->sanitize( '<html><body/></html>' );
	}

	public function test_same_instance_gives_the_same_result_twice() {
		$sanitizer = $this->plugin_sanitizer();
		$input     = $this->svg( '<script/><rect onclick="a()"/>' );

		$first = $sanitizer->sanitize( $input );

		$this->assertSame( $first, $sanitizer->sanitize( $input ) );
		$this->assertCount( 2, $sanitizer->getXmlIssues() );
	}

	/* Issues. */

	/**
	 * Issues are listed from the end of the document to the start, each with
	 * the line of the node it is about.
	 */
	public function test_issues_carry_a_message_and_a_line_in_reverse_document_order() {
		$sanitizer = new Sanitizer();
		$sanitizer->sanitize( '<svg ' . self::SVG_NS . "\n onload=\"x\">\n<script/>\n<g>\n<rect onclick=\"y\"/>\n<!-- c -->\n</g>\n</svg>" );

		$this->assertSame(
			array(
				array( 'message' => "Suspicious attribute 'onload'", 'line' => 2 ),
				array( 'message' => "Suspicious node '#comment'", 'line' => 6 ),
				array( 'message' => "Suspicious attribute 'onclick'", 'line' => 5 ),
				array( 'message' => "Suspicious tag 'script'", 'line' => 3 ),
			),
			$sanitizer->getXmlIssues()
		);
	}

	public function test_issues_are_reset_by_the_next_run() {
		$sanitizer = new Sanitizer();
		$sanitizer->sanitize( $this->svg( '<script/>' ) );
		$this->assertCount( 1, $sanitizer->getXmlIssues() );

		$sanitizer->sanitize( $this->svg( '<rect/>' ) );

		$this->assertSame( array(), $sanitizer->getXmlIssues() );
	}

	public function test_issues_are_reset_by_a_run_that_returns_false() {
		$sanitizer = new Sanitizer();
		$sanitizer->sanitize( $this->svg( '<script/>' ) );

		$sanitizer->sanitize( '<svg' );

		$this->assertSame( array(), $sanitizer->getXmlIssues() );
	}

	/**
	 * Empty input returns before anything is reset.
	 */
	public function test_issues_of_the_previous_run_survive_an_empty_input() {
		$sanitizer = new Sanitizer();
		$sanitizer->sanitize( $this->svg( '<script/>' ) );

		$sanitizer->sanitize( '' );

		$this->assertCount( 1, $sanitizer->getXmlIssues() );
	}

	/* Elements. */

	/**
	 * @dataProvider provide_elements_that_are_removed
	 */
	public function test_removes_element_that_is_not_on_the_whitelist( $markup, $reported_name ) {
		$inner = $this->sanitize_inner( $markup . '<rect/>' );

		$this->assertSame( '<rect></rect>', $inner );
		$this->assertSame( array( "Suspicious tag '$reported_name'" ), $this->issue_messages() );
	}

	public function provide_elements_that_are_removed() {
		return array(
			'script'                         => array( '<script>alert(1)</script>', 'script' ),
			'script in upper case'           => array( '<SCRIPT>alert(1)</SCRIPT>', 'SCRIPT' ),
			'empty script'                   => array( '<script/>', 'script' ),
			'foreignObject and its content'  => array( '<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="javascript:1"/></body></foreignObject>', 'foreignObject' ),
			'iframe'                         => array( '<iframe src="https://example.org/"/>', 'iframe' ),
			'set'                            => array( '<set attributeName="href" to="javascript:1"/>', 'set' ),
			'animate'                        => array( '<animate attributeName="href" values="javascript:1"/>', 'animate' ),
			'handler'                        => array( '<handler type="text/javascript">alert(1)</handler>', 'handler' ),
			'unknown element'                => array( '<blink>x</blink>', 'blink' ),
			'allowed elements inside it too' => array( '<blink><rect/><circle/></blink>', 'blink' ),
		);
	}

	public function test_removes_a_disallowed_element_nested_in_allowed_ones() {
		$inner = $this->sanitize_inner( '<g><g><script>alert(1)</script><rect/></g></g>' );

		$this->assertSame( '<g><g><rect></rect></g></g>', $inner );
	}

	/**
	 * Names are compared in lower case and written back unchanged.
	 */
	public function test_keeps_an_allowed_element_whatever_its_case() {
		$inner = $this->sanitize_inner( '<Rect/><linearGradient/><LINEARGRADIENT/><feGaussianBlur/>' );

		$this->assertSame( '<Rect></Rect><linearGradient></linearGradient><LINEARGRADIENT></LINEARGRADIENT><feGaussianBlur></feGaussianBlur>', $inner );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/**
	 * The whitelist is compared with the prefixed name, so an SVG element
	 * written with a prefix is treated as unknown.
	 */
	public function test_removes_a_prefixed_element_even_in_the_svg_namespace() {
		$inner = $this->sanitize_inner( '<s:rect/><rect/>', 'xmlns:s="http://www.w3.org/2000/svg"' );

		$this->assertSame( '<rect></rect>', $inner );
		$this->assertSame( array( "Suspicious tag 's:rect'" ), $this->issue_messages() );
	}

	/**
	 * @dataProvider provide_font_elements
	 */
	public function test_font_element_is_removed_only_with_a_face_color_or_size_attribute( $markup, $expected_inner ) {
		$this->assertSame( $expected_inner, $this->sanitize_inner( $markup ) );
	}

	public function provide_font_elements() {
		return array(
			'face'             => array( '<font face="x"><rect/></font>', '' ),
			'color'            => array( '<font color="red"/>', '' ),
			'size'             => array( '<font size="1"/>', '' ),
			'SIZE'             => array( '<font SIZE="1"/>', '' ),
			'no attributes'    => array( '<font/>', '<font></font>' ),
			'other attributes' => array( '<font id="f" class="c"/>', '<font id="f" class="c"></font>' ),
		);
	}

	public function test_removed_font_element_is_reported_as_a_suspicious_tag() {
		$this->sanitize_inner( '<font face="x"/>' );

		$this->assertSame( array( "Suspicious tag 'font'" ), $this->issue_messages() );
	}

	/**
	 * Neither the content of `<style>` nor a `style` attribute is examined.
	 */
	public function test_style_element_and_style_attribute_are_kept_verbatim() {
		$markup = '<style>@import url(https://example.org/x.css); .a{background:url(javascript:alert(1))}</style>'
			. '<rect style="background:url(javascript:1)"></rect>';

		$this->assertSame( $markup, $this->sanitize_inner( $markup ) );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/* Attributes. */

	/**
	 * @dataProvider provide_event_handler_attributes
	 */
	public function test_removes_event_handler_attribute( $attribute ) {
		$inner = $this->sanitize_inner( "<rect $attribute=\"alert(1)\" width=\"1\"/>" );

		$this->assertSame( '<rect width="1"></rect>', $inner );
		$this->assertSame( array( "Suspicious attribute '$attribute'" ), $this->issue_messages() );
	}

	public function provide_event_handler_attributes() {
		return $this->keyed( array( 'onload', 'onclick', 'onmouseover', 'onerror', 'onbegin', 'onfocusin', 'ONLOAD', 'OnClick' ) );
	}

	public function test_removes_event_handler_from_the_root_element() {
		$output = $this->plugin_sanitizer()->sanitize( $this->svg( '<rect/>', 'onload="alert(1)" width="1"' ) );

		$this->assertSame( '<svg ' . self::SVG_NS . ' width="1"><rect></rect></svg>', $output );
	}

	public function test_removes_attribute_that_is_not_on_the_whitelist() {
		$inner = $this->sanitize_inner( '<rect formaction="x" srcdoc="y" unknown="z" width="1"/>' );

		$this->assertSame( '<rect width="1"></rect>', $inner );
		$this->assertCount( 3, $this->issue_messages() );
	}

	/**
	 * Some attributes that are valid in SVG are not on the whitelist either,
	 * for example the `target` of a link.
	 */
	public function test_removes_link_target_attribute() {
		$inner = $this->sanitize_inner( '<a href="https://example.org/" target="_blank"/>' );

		$this->assertSame( '<a href="https://example.org/"></a>', $inner );
		$this->assertSame( array( "Suspicious attribute 'target'" ), $this->issue_messages() );
	}

	/**
	 * Names are compared in lower case and written back unchanged.
	 */
	public function test_keeps_an_allowed_attribute_whatever_its_case() {
		$markup = '<rect viewBox="1" VIEWBOX="2" filterUnits="a" Width="3"></rect>';

		$this->assertSame( $markup, $this->sanitize_inner( $markup ) );
	}

	/**
	 * @dataProvider provide_aria_and_data_attributes
	 */
	public function test_keeps_aria_and_data_attributes_that_are_not_on_the_whitelist( $attribute ) {
		$markup = "<rect $attribute=\"javascript:alert(1)\"></rect>";

		$this->assertSame( $markup, $this->sanitize_inner( $markup ) );
		$this->assertSame( array(), $this->issue_messages() );
	}

	public function provide_aria_and_data_attributes() {
		return $this->keyed( array( 'aria-label', 'aria-anything', 'ARIA-LABEL', 'data-x', 'data-on-load', 'DATA-X', 'data-' ) );
	}

	public function test_does_not_keep_attributes_that_only_resemble_aria_or_data() {
		$inner = $this->sanitize_inner( '<rect aria="1" data="2" xdata-x="3" arialabel="4"/>' );

		$this->assertSame( '<rect></rect>', $inner );
	}

	/**
	 * Pins a defect in Sanitizer::cleanAttributesOnWhitelist(): attributes
	 * are removed by local name without their namespace, so a prefixed
	 * attribute that is not on the whitelist is reported as removed but is
	 * still in the output.
	 *
	 * Fixed when the attributes are gone from the output; this test then
	 * fails and becomes a regression test asserting `<a></a>` and `<rect>`
	 * without attributes.
	 *
	 * @group pinned
	 * @dataProvider provide_prefixed_attributes_that_are_not_on_the_whitelist
	 */
	public function test_prefixed_attribute_is_reported_but_not_removed_pinned_for_deferred_fix( $root_attributes, $markup, $reported ) {
		$inner = $this->sanitize_inner( $markup, $root_attributes );

		$this->assertSame( $markup, $inner );
		$this->assertSame( array( "Suspicious attribute '$reported'" ), $this->issue_messages() );
	}

	public function provide_prefixed_attributes_that_are_not_on_the_whitelist() {
		return array(
			'xlink:actuate'                    => array( self::XLINK_NS, '<a xlink:actuate="onLoad"></a>', 'actuate' ),
			'event handler in a foreign namespace' => array( 'xmlns:x="http://example.org/x"', '<rect x:onload="alert(1)"></rect>', 'onload' ),
		);
	}

	/* Nodes that are not elements or text. */

	public function test_removes_comments() {
		$output = $this->plugin_sanitizer()->sanitize( '<!-- before -->' . $this->svg( '<!-- inside --><g><!-- deeper --><rect/></g>' ) . '<!-- after -->' );

		$this->assertSame( '<svg ' . self::SVG_NS . '><g><rect></rect></g></svg>', $output );
		$this->assertSame( array_fill( 0, 4, "Suspicious node '#comment'" ), $this->issue_messages() );
	}

	public function test_removes_processing_instructions() {
		$output = $this->plugin_sanitizer()->sanitize(
			'<?xml-stylesheet type="text/xsl" href="https://example.org/x.xsl"?>' . $this->svg( '<?target data?><rect/>' )
		);

		$this->assertSame( '<svg ' . self::SVG_NS . '><rect></rect></svg>', $output );
		$this->assertSame( array( "Suspicious node 'target'", "Suspicious node 'xml-stylesheet'" ), $this->issue_messages() );
	}

	/**
	 * The document type is reported under the name it declares, on line -1.
	 */
	public function test_removes_the_document_type_declaration() {
		$sanitizer = $this->plugin_sanitizer();
		$output    = $sanitizer->sanitize(
			'<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . "\n" . $this->svg( '<rect/>' )
		);

		$this->assertSame( '<svg ' . self::SVG_NS . '><rect></rect></svg>', $output );
		$this->assertSame( array( array( 'message' => "Suspicious node 'svg'", 'line' => -1 ) ), $sanitizer->getXmlIssues() );
	}

	/**
	 * Entities are never expanded: the reference is removed and the external
	 * file is not read.
	 */
	public function test_removes_a_reference_to_an_external_entity_without_loading_it() {
		$sanitizer = $this->plugin_sanitizer();
		$output    = $sanitizer->sanitize(
			'<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>' . $this->svg( '<text>&xxe;</text>' )
		);

		$this->assertSame( '<svg ' . self::SVG_NS . '><text></text></svg>', $output );
		$this->assertSame( array( "Suspicious node 'xxe'", "Suspicious node 'svg'" ), $this->issue_messages() );
	}

	public function test_removes_a_reference_to_an_internal_entity_that_holds_markup() {
		$output = $this->plugin_sanitizer()->sanitize(
			'<!DOCTYPE svg [<!ENTITY payload "<script>alert(1)</script>">]>' . $this->svg( '<g>&payload;</g>' )
		);

		$this->assertSame( '<svg ' . self::SVG_NS . '><g></g></svg>', $output );
	}

	public function test_does_not_apply_attribute_defaults_declared_in_the_document_type() {
		$output = $this->plugin_sanitizer()->sanitize(
			'<!DOCTYPE svg [<!ATTLIST svg onload CDATA "alert(1)">]>' . $this->svg( '<rect/>' )
		);

		$this->assertSame( '<svg ' . self::SVG_NS . '><rect></rect></svg>', $output );
	}

	/**
	 * Entities that multiply each other are never expanded into the output.
	 * Whether libxml rejects the document or the reference is just removed
	 * depends on its limits, so only the absence of the expansion is checked.
	 */
	public function test_does_not_expand_entities_that_multiply_each_other() {
		$output = $this->plugin_sanitizer()->sanitize(
			'<!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;">]>'
			. $this->svg( '<text>&c;</text>' )
		);

		$this->assertStringNotContainsString( 'aaaaaaaaaa', (string) $output );
		$this->assertStringNotContainsString( '&c;', (string) $output );
	}

	/**
	 * Pins a defect in Sanitizer::sanitize(): an entity reference inside an
	 * attribute value is kept while the DOCTYPE that declares the entity is
	 * removed, so the output is not well-formed and cannot be read back.
	 *
	 * Fixed when the output no longer contains the reference; this test then
	 * fails and becomes a regression test asserting the output parses.
	 *
	 * @group pinned
	 */
	public function test_entity_reference_in_attribute_makes_output_malformed_pinned_for_deferred_fix() {
		$sanitizer = $this->plugin_sanitizer();
		$output    = $sanitizer->sanitize(
			'<!DOCTYPE svg [<!ENTITY x "10">]>' . $this->svg( '<rect width="&x;"/>' )
		);

		$this->assertSame( '<svg ' . self::SVG_NS . '><rect width="&x;"></rect></svg>', $output );
		$this->assertFalse( $sanitizer->sanitize( $output ) );
	}

	/* PHP tags. */

	/**
	 * @dataProvider provide_php_tags_on_one_line
	 */
	public function test_strips_php_tag_on_one_line_without_reporting_it( $tag ) {
		$inner = $this->sanitize_inner( $tag . '<rect/>' );

		$this->assertSame( '<rect></rect>', $inner );
		$this->assertSame( array(), $this->issue_messages() );
	}

	public function provide_php_tags_on_one_line() {
		return array(
			'php'            => array( '<?php echo 1; ?>' ),
			'PHP'            => array( '<?PHP echo 1; ?>' ),
			'echo tag'       => array( '<?= 1 ?>' ),
			'several'        => array( '<?php a(); ?><?= 2 ?><?php b(); ?>' ),
		);
	}

	/**
	 * The pattern does not cross line breaks; the tag is still well-formed
	 * XML (a processing instruction) and is removed as such.
	 */
	public function test_removes_php_tag_spanning_several_lines_as_a_processing_instruction() {
		$inner = $this->sanitize_inner( "<?php\necho 1;\n?><rect/>" );

		$this->assertSame( '<rect></rect>', $inner );
		$this->assertSame( array( "Suspicious node 'php'" ), $this->issue_messages() );
	}

	/**
	 * A PHP tag inside an attribute value would not be well-formed XML;
	 * stripping it first lets the document through with an empty value.
	 */
	public function test_strips_php_tag_inside_an_attribute_value() {
		$inner = $this->sanitize_inner( '<rect fill="<?php echo 1; ?>"/>' );

		$this->assertSame( '<rect fill=""></rect>', $inner );
	}

	/**
	 * Stripping can join the two halves of an element name; the result goes
	 * through the whitelist like any other element.
	 */
	public function test_element_assembled_by_stripping_php_tags_is_still_removed() {
		$inner = $this->sanitize_inner( '<scr<?php x ?>ipt>alert(1)</scr<?php x ?>ipt><rect/>' );

		$this->assertSame( '<rect></rect>', $inner );
		$this->assertSame( array( "Suspicious tag 'script'" ), $this->issue_messages() );
	}

	/* CDATA. */

	/**
	 * Pins the defect described in the Sanitizer class docblock: a CDATA
	 * section is handed to HTMLPurifier, which is not shipped with the
	 * library, so sanitize() ends with an Error.
	 *
	 * Fixed when sanitize() returns a string for this document; this test
	 * then fails and becomes a regression test for the returned content.
	 *
	 * @group pinned
	 */
	public function test_cdata_section_throws_class_not_found_error_pinned_for_deferred_fix() {
		$this->assertFalse( class_exists( 'HTMLPurifier' ), 'HTMLPurifier is not part of the test environment' );
		$sanitizer = new Sanitizer();

		try {
			$sanitizer->sanitize( $this->svg( '<style><![CDATA[ .a { fill: red; } ]]></style>' ) );
			$this->fail( 'Expected an Error' );
		} catch ( Error $error ) {
			$this->assertStringContainsString( 'HTMLPurifier', $error->getMessage() );
			$this->assertStringContainsString( 'not found', $error->getMessage() );
		}
	}

	/* Prefixed root. */

	/**
	 * Pins a defect in Sanitizer::sanitize(): a prefixed root `<x:svg>`
	 * passes the root check and is then removed by the whitelist, so the
	 * method returns an XML declaration and no SVG at all, even when the
	 * declaration is switched off.
	 *
	 * Fixed when the result is false or contains the root element; this
	 * test then fails and becomes a regression test for that result.
	 *
	 * @group pinned
	 */
	public function test_prefixed_root_returns_only_an_xml_declaration_pinned_for_deferred_fix() {
		$sanitizer = $this->plugin_sanitizer();

		$output = $sanitizer->sanitize( '<x:svg xmlns:x="http://www.w3.org/2000/svg"><x:rect/></x:svg>' );

		$this->assertSame( '<?xml version="1.0"?> ', $output );
		$this->assertSame( array( "Suspicious tag 'x:svg'" ), $this->issue_messages() );
	}

	/* Remote references. */

	public function test_remote_references_are_kept_by_default() {
		$markup = '<rect fill="url(\'https://example.org/x\')"></rect>';

		$this->assertSame( $markup, $this->sanitize_inner( $markup ) );
	}

	/**
	 * @dataProvider provide_remote_url_values
	 */
	public function test_remove_remote_references_removes_attribute_with_a_remote_url( $value ) {
		$inner = $this->sanitize_inner(
			'<rect fill="' . htmlspecialchars( $value ) . '" width="1"/>',
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->removeRemoteReferences( true );
			}
		);

		$this->assertSame( '<rect width="1"></rect>', $inner );
		$this->assertSame( array( "Suspicious attribute 'fill'" ), $this->issue_messages() );
	}

	public function provide_remote_url_values() {
		return $this->keyed(
			array(
				"url('http://example.org/x')",
				'url("https://example.org/x")',
				"url('//example.org/x')",
				"url('ftp://example.org/x')",
				"url('file:///etc/passwd')",
				"URL('HTTP://example.org/x')",
				"url( ' http://example.org/x ' )",
			)
		);
	}

	/**
	 * @dataProvider provide_values_that_are_not_remote_urls
	 */
	public function test_remove_remote_references_keeps_other_values( $value ) {
		$markup = '<rect fill="' . $value . '"></rect>';
		$inner  = $this->sanitize_inner(
			$markup,
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->removeRemoteReferences( true );
			}
		);

		$this->assertSame( $markup, $inner );
	}

	public function provide_values_that_are_not_remote_urls() {
		return $this->keyed(
			array(
				"url('#local')",
				"url('/local.svg#a')",
				'url(#local)',
				'https://example.org/x',
				'red',
			)
		);
	}

	/**
	 * Only `url(...)` attribute values are covered by the option; links are
	 * governed by the href rules, which allow http and https.
	 */
	public function test_remove_remote_references_does_not_apply_to_href() {
		$markup = '<image href="https://example.org/t.png"></image>';
		$inner  = $this->sanitize_inner(
			$markup,
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->removeRemoteReferences( true );
			}
		);

		$this->assertSame( $markup, $inner );
	}

	public function test_remove_remote_references_without_argument_switches_it_off() {
		$markup = '<rect fill="url(\'https://example.org/x\')"></rect>';
		$inner  = $this->sanitize_inner(
			$markup,
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->removeRemoteReferences( true );
				$sanitizer->removeRemoteReferences();
			}
		);

		$this->assertSame( $markup, $inner );
	}

	/**
	 * Pins a defect in Sanitizer::hasRemoteReference(): the pattern requires
	 * quotes inside `url()`, so the unquoted form is not seen as remote.
	 *
	 * Fixed when the attribute is removed; this test then fails and the
	 * value moves to provide_remote_url_values().
	 *
	 * @group pinned
	 */
	public function test_unquoted_remote_url_is_kept_pinned_for_deferred_fix() {
		$markup = '<rect fill="url(https://example.org/x)"></rect>';
		$inner  = $this->sanitize_inner(
			$markup,
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->removeRemoteReferences( true );
			}
		);

		$this->assertSame( $markup, $inner );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/* Global state. */

	/**
	 * Pins a defect in Sanitizer::setUpBefore(): libxml's internal error
	 * handling is switched on and never restored, so it stays on after
	 * sanitize() returns.
	 *
	 * Fixed when the setting is back to what it was before the call; this
	 * test then fails and becomes a regression test asserting false.
	 *
	 * @group pinned
	 */
	public function test_libxml_internal_errors_stay_enabled_after_sanitize_pinned_for_deferred_fix() {
		libxml_use_internal_errors( false );

		( new Sanitizer() )->sanitize( $this->svg( '<rect/>' ) );

		$this->assertTrue( libxml_use_internal_errors() );
	}
}
