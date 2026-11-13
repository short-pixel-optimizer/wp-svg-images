<?php

use enshrined\svgSanitize\data\AllowedAttributes;
use enshrined\svgSanitize\data\AttributeInterface;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The library's default attribute whitelist.
 *
 * The sanitizer lower-cases this list and compares attribute names
 * case-insensitively, so the tests that look for a name do the same.
 */
class AllowedAttributesTest extends TestCase {

	/**
	 * The whitelist as the sanitizer uses it: lower-cased.
	 *
	 * @return string[]
	 */
	private function lowercased_attributes() {
		return array_map( 'strtolower', AllowedAttributes::getAttributes() );
	}

	public function test_implements_attribute_interface() {
		$this->assertInstanceOf( AttributeInterface::class, new AllowedAttributes() );
	}

	public function test_returns_a_list_of_non_empty_strings() {
		$attributes = AllowedAttributes::getAttributes();

		$this->assertIsArray( $attributes );
		$this->assertNotEmpty( $attributes );
		$this->assertSame( array_values( $attributes ), $attributes, 'Keys are sequential' );

		foreach ( $attributes as $attribute ) {
			$this->assertIsString( $attribute );
			$this->assertNotSame( '', $attribute );
			$this->assertSame( trim( $attribute ), $attribute, "No surrounding whitespace in '$attribute'" );
		}
	}

	public function test_returns_the_same_list_on_every_call() {
		$this->assertSame( AllowedAttributes::getAttributes(), AllowedAttributes::getAttributes() );
	}

	/**
	 * `filterUnits` is the only entry not written in lower case; it matches
	 * because the sanitizer lower-cases the list and the attribute name.
	 */
	public function test_filter_units_is_the_only_entry_not_in_lower_case() {
		$attributes = AllowedAttributes::getAttributes();

		$this->assertSame(
			array( 'filterUnits' ),
			array_values( array_diff( $attributes, $this->lowercased_attributes() ) )
		);
		$this->assertContains( 'filterunits', $this->lowercased_attributes() );
	}

	/**
	 * @dataProvider provide_attributes_every_svg_needs
	 */
	public function test_allows_attribute( $attribute ) {
		$this->assertContains( $attribute, $this->lowercased_attributes() );
	}

	public function provide_attributes_every_svg_needs() {
		$attributes = array(
			'xmlns',
			'xmlns:xlink',
			'viewbox',
			'width',
			'height',
			'id',
			'class',
			'style',
			'd',
			'fill',
			'stroke',
			'transform',
			'href',
			'xlink:href',
			'xlink:title',
			'xml:id',
			'xml:space',
		);

		return array_combine( $attributes, array_map( function ( $attribute ) {
			return array( $attribute );
		}, $attributes ) );
	}

	/**
	 * No entry starts with `on`, so no event handler attribute is whitelisted.
	 */
	public function test_does_not_allow_any_event_handler_attribute() {
		$handlers = array_filter( $this->lowercased_attributes(), function ( $attribute ) {
			return 0 === strpos( $attribute, 'on' );
		} );

		$this->assertSame( array(), array_values( $handlers ) );
	}

	/**
	 * @dataProvider provide_attributes_that_can_run_script_or_submit_data
	 */
	public function test_does_not_allow_attribute( $attribute ) {
		$this->assertNotContains( $attribute, $this->lowercased_attributes() );
	}

	public function provide_attributes_that_can_run_script_or_submit_data() {
		$attributes = array(
			'onload',
			'onclick',
			'onerror',
			'onmouseover',
			'onbegin',
			'formaction',
			'srcdoc',
			'xlink:actuate',
			'xlink:show',
			'xml:base',
			'ping',
		);

		return array_combine( $attributes, array_map( function ( $attribute ) {
			return array( $attribute );
		}, $attributes ) );
	}

	/**
	 * `aria-*` and `data-*` attributes are kept by a rule in the sanitizer, not
	 * by this list.
	 */
	public function test_lists_no_aria_or_data_attributes() {
		$matches = preg_grep( '/^(aria|data)-/', $this->lowercased_attributes() );

		$this->assertSame( array(), array_values( $matches ) );
	}

	/**
	 * The HTML, SVG and MathML sections overlap, so some names are listed more
	 * than once. Harmless for the sanitizer, which only tests membership.
	 */
	public function test_lists_some_attributes_more_than_once() {
		$attributes = AllowedAttributes::getAttributes();

		$this->assertGreaterThan( count( array_unique( $attributes ) ), count( $attributes ) );
		$this->assertGreaterThan( 1, array_count_values( $attributes )['xmlns'] );
	}
}
