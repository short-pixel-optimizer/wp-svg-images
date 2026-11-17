<?php

use enshrined\svgSanitize\Helper;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Helper: href lookup, same-document reference parsing and containment.
 */
class HelperTest extends TestCase {

	const XLINK_NS = 'http://www.w3.org/1999/xlink';

	/**
	 * @param string $xml Well-formed XML.
	 * @return DOMElement The document's root element.
	 */
	private function root( $xml ) {
		$document = new DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ), 'Test document is well-formed' );

		return $document->documentElement;
	}

	/**
	 * @dataProvider provide_elements_and_their_href
	 */
	public function test_get_element_href( $xml, $expected ) {
		$this->assertSame( $expected, Helper::getElementHref( $this->root( $xml ) ) );
	}

	public function provide_elements_and_their_href() {
		$xlink = 'xmlns:xlink="' . self::XLINK_NS . '"';

		return array(
			'plain href'                         => array( '<use href="#a"/>', '#a' ),
			'xlink:href'                         => array( "<use $xlink xlink:href=\"#b\"/>", '#b' ),
			'plain href wins over xlink:href'    => array( "<use $xlink xlink:href=\"#b\" href=\"#a\"/>", '#a' ),
			'empty plain href wins over xlink'   => array( "<use $xlink xlink:href=\"#b\" href=\"\"/>", '' ),
			'empty xlink:href'                   => array( "<use $xlink xlink:href=\"\"/>", '' ),
			'no href at all'                     => array( '<use id="a"/>', null ),
			'external target is returned as is'  => array( '<use href="https://example.com/a.svg#a"/>', 'https://example.com/a.svg#a' ),
			'whitespace is kept'                 => array( '<use href=" #a "/>', ' #a ' ),
			'XLink namespace under another prefix' => array( '<use xmlns:x="' . self::XLINK_NS . '" x:href="#c"/>', '#c' ),
			'xlink prefix bound to another namespace' => array( '<use xmlns:xlink="http://example.com/not-xlink" xlink:href="#d"/>', null ),
			'element in the SVG namespace'       => array( '<use xmlns="http://www.w3.org/2000/svg" href="#e"/>', '#e' ),
			'attribute name is case-sensitive'   => array( '<use HREF="#f"/>', null ),
		);
	}

	/**
	 * @dataProvider provide_hrefs_and_their_id_reference
	 */
	public function test_extract_id_reference_from_href( $href, $expected ) {
		$this->assertSame( $expected, Helper::extractIdReferenceFromHref( $href ) );
	}

	public function provide_hrefs_and_their_id_reference() {
		return array(
			'fragment'                        => array( '#foo', 'foo' ),
			'bare hash'                       => array( '#', '' ),
			'only the first hash is removed'  => array( '##foo', '#foo' ),
			'rest of the value is kept as is' => array( '#foo bar', 'foo bar' ),
			'no hash'                         => array( 'foo', null ),
			'empty string'                    => array( '', null ),
			'url with a fragment'             => array( 'https://example.com/a.svg#foo', null ),
			'leading whitespace'              => array( ' #foo', null ),
			'null'                            => array( null, null ),
			'integer'                         => array( 5, null ),
			'array'                           => array( array( '#foo' ), null ),
		);
	}

	public function test_element_is_contained_in_itself() {
		$root = $this->root( '<svg><g/></svg>' );

		$this->assertTrue( Helper::isElementContainedIn( $root, $root ) );
	}

	public function test_element_is_contained_in_its_parent() {
		$root = $this->root( '<svg><g/></svg>' );

		$this->assertTrue( Helper::isElementContainedIn( $root->firstChild, $root ) );
	}

	/**
	 * Text and comment nodes between the levels are stepped over.
	 */
	public function test_element_is_contained_in_a_distant_ancestor() {
		$root = $this->root( '<svg>text<!-- c --><g> <g><rect/></g></g><g><circle id="deep"/></g></svg>' );
		$deep = $root->getElementsByTagName( 'circle' )->item( 0 );

		$this->assertTrue( Helper::isElementContainedIn( $deep, $root ) );
	}

	public function test_parent_is_not_contained_in_its_child() {
		$root = $this->root( '<svg><g/></svg>' );

		$this->assertFalse( Helper::isElementContainedIn( $root, $root->firstChild ) );
	}

	public function test_element_is_not_contained_in_its_sibling() {
		$root = $this->root( '<svg><g><rect/></g><g/></svg>' );
		$rect = $root->firstChild->firstChild;

		$this->assertFalse( Helper::isElementContainedIn( $rect, $root->lastChild ) );
	}

	/**
	 * Containment is identity of nodes, not likeness: the same markup in
	 * another document does not count.
	 */
	public function test_element_is_not_contained_in_a_lookalike_from_another_document() {
		$root  = $this->root( '<svg><g/></svg>' );
		$other = $this->root( '<svg><g/></svg>' );

		$this->assertFalse( Helper::isElementContainedIn( $root->firstChild, $other ) );
	}
}
