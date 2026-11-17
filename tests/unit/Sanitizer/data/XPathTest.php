<?php

use enshrined\svgSanitize\data\XPath;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * XPath: default-namespace handling and the "exactly one root <svg>" rule.
 *
 * The documents are one-line strings kept next to the assertions; they are
 * too small to be worth a fixture file each.
 */
class XPathTest extends TestCase {

	const SVG_NS = 'http://www.w3.org/2000/svg';

	/**
	 * @param string $xml Well-formed XML.
	 * @return DOMDocument
	 */
	private function document( $xml ) {
		$document = new DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ), 'Test document is well-formed' );

		return $document;
	}

	public function test_is_a_domxpath() {
		$this->assertInstanceOf( DOMXPath::class, new XPath( $this->document( '<svg/>' ) ) );
	}

	public function test_default_namespace_prefix_is_svg() {
		$this->assertSame( 'svg', XPath::DEFAULT_NAMESPACE_PREFIX );
	}

	public function test_create_node_name_prefixes_the_name_when_root_has_default_namespace() {
		$xpath = new XPath( $this->document( '<svg xmlns="' . self::SVG_NS . '"/>' ) );

		$this->assertSame( 'svg:use', $xpath->createNodeName( 'use' ) );
	}

	public function test_create_node_name_returns_bare_name_when_root_has_no_namespace() {
		$xpath = new XPath( $this->document( '<svg/>' ) );

		$this->assertSame( 'use', $xpath->createNodeName( 'use' ) );
	}

	/**
	 * Declaring only prefixed namespaces leaves the root without a namespace.
	 */
	public function test_create_node_name_returns_bare_name_when_only_prefixed_namespaces_are_declared() {
		$xpath = new XPath( $this->document( '<svg xmlns:xlink="http://www.w3.org/1999/xlink"/>' ) );

		$this->assertSame( 'use', $xpath->createNodeName( 'use' ) );
	}

	/**
	 * Whatever the root's default namespace is, it is the one registered
	 * under the `svg` prefix; it does not have to be the SVG namespace.
	 */
	public function test_registers_any_default_namespace_under_the_svg_prefix() {
		$xpath = new XPath( $this->document( '<svg xmlns="http://example.com/not-svg"><use/></svg>' ) );

		$this->assertSame( 'svg:use', $xpath->createNodeName( 'use' ) );
		$this->assertSame( 1, $xpath->query( '//svg:use' )->length );
	}

	/**
	 * @dataProvider provide_documents_with_two_use_elements
	 */
	public function test_query_built_with_create_node_name_finds_elements( $xml ) {
		$xpath = new XPath( $this->document( $xml ) );

		$found = $xpath->query( '//' . $xpath->createNodeName( 'use' ) );

		$this->assertSame( 2, $found->length );
	}

	public function provide_documents_with_two_use_elements() {
		return array(
			'default SVG namespace' => array( '<svg xmlns="' . self::SVG_NS . '"><use/><g><use/></g></svg>' ),
			'no namespace'          => array( '<svg><use/><g><use/></g></svg>' ),
		);
	}

	/**
	 * In a namespaced document a bare name matches nothing, which is why
	 * callers must go through createNodeName().
	 */
	public function test_bare_name_query_finds_nothing_in_a_namespaced_document() {
		$xpath = new XPath( $this->document( '<svg xmlns="' . self::SVG_NS . '"><use/></svg>' ) );

		$this->assertSame( 0, $xpath->query( '//use' )->length );
	}

	/**
	 * Elements in another namespace are not matched by the `svg` prefix.
	 */
	public function test_prefixed_query_ignores_elements_in_another_namespace() {
		$xml   = '<svg xmlns="' . self::SVG_NS . '" xmlns:x="http://example.com/x"><use/><x:use/></svg>';
		$xpath = new XPath( $this->document( $xml ) );

		$this->assertSame( 1, $xpath->query( '//svg:use' )->length );
	}

	/**
	 * Only direct children of the document count as root elements.
	 */
	public function test_accepts_nested_svg_elements_inside_the_root_svg() {
		$xpath = new XPath( $this->document( '<svg xmlns="' . self::SVG_NS . '"><svg><svg/></svg></svg>' ) );

		$this->assertSame( 3, $xpath->query( '//svg:svg' )->length );
	}

	public function test_accepts_comments_and_processing_instructions_around_the_root() {
		$xml   = '<?xml version="1.0"?><!-- before --><?pi data?><svg xmlns="' . self::SVG_NS . '"/><!-- after -->';
		$xpath = new XPath( $this->document( $xml ) );

		$this->assertSame( 'svg:g', $xpath->createNodeName( 'g' ) );
	}

	/**
	 * The root is recognised by its local name, so a prefixed root is
	 * accepted and its namespace becomes the one behind the `svg` prefix.
	 */
	public function test_accepts_a_prefixed_root_svg_and_registers_its_namespace() {
		$xml   = '<x:svg xmlns:x="' . self::SVG_NS . '"><x:use/><use/></x:svg>';
		$xpath = new XPath( $this->document( $xml ) );

		$this->assertSame( 'svg:use', $xpath->createNodeName( 'use' ) );
		$this->assertSame( 1, $xpath->query( '//svg:use' )->length );
	}

	/**
	 * The namespace of a prefixed root is not checked either.
	 */
	public function test_accepts_a_prefixed_root_svg_in_a_foreign_namespace() {
		$xml   = '<x:svg xmlns:x="http://example.com/x"><x:use/></x:svg>';
		$xpath = new XPath( $this->document( $xml ) );

		$this->assertSame( 1, $xpath->query( '//svg:use' )->length );
	}

	/**
	 * @dataProvider provide_documents_without_a_root_svg
	 */
	public function test_throws_when_the_root_element_is_not_svg( $xml ) {
		$document = $this->document( $xml );

		$this->expectException( LogicException::class );
		$this->expectExceptionCode( 1570870568 );
		$this->expectExceptionMessage( 'Got 0 svg elements, expected exactly one' );

		new XPath( $document );
	}

	public function provide_documents_without_a_root_svg() {
		return array(
			'html root'                  => array( '<html><body/></html>' ),
			'svg nested in another root' => array( '<html><svg xmlns="' . self::SVG_NS . '"/></html>' ),
			'upper-case root'            => array( '<SVG xmlns="' . self::SVG_NS . '"/>' ),
		);
	}

	/**
	 * A DOMDocument that never loaded anything has no root element at all.
	 */
	public function test_throws_for_an_empty_document() {
		$this->expectException( LogicException::class );
		$this->expectExceptionCode( 1570870568 );
		$this->expectExceptionMessage( 'Got 0 svg elements, expected exactly one' );

		new XPath( new DOMDocument() );
	}
}
