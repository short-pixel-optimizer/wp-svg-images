<?php

use enshrined\svgSanitize\data\XPath;
use enshrined\svgSanitize\ElementReference\Resolver;
use enshrined\svgSanitize\ElementReference\Subject;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Resolver: builds the reference graph of a document and lists the elements
 * to remove.
 *
 * Documents are written as the inner markup of an `<svg>` root and kept next
 * to the assertions; each one is a handful of elements.
 */
class ResolverTest extends TestCase {

	const SVG   = 'xmlns="http://www.w3.org/2000/svg"';
	const XLINK = 'xmlns:xlink="http://www.w3.org/1999/xlink"';

	/**
	 * @var DOMDocument
	 */
	private $document;

	/**
	 * Parses `<svg $root_attributes>$inner</svg>` and returns a Resolver
	 * that has already collected it.
	 *
	 * @param string $inner           Markup inside the root element.
	 * @param int    $limit           Nesting limit.
	 * @param string $root_attributes Attributes of the root element.
	 * @return Resolver
	 */
	private function collected( $inner, $limit = 15, $root_attributes = null ) {
		$resolver = $this->resolver( $inner, $limit, $root_attributes );
		$resolver->collect();

		return $resolver;
	}

	/**
	 * Same as collected(), without calling collect().
	 *
	 * @return Resolver
	 */
	private function resolver( $inner, $limit = 15, $root_attributes = null ) {
		if ( null === $root_attributes ) {
			$root_attributes = self::SVG . ' ' . self::XLINK;
		}

		$this->document = new DOMDocument();
		$this->assertTrue( $this->document->loadXML( "<svg $root_attributes>$inner</svg>" ), 'Test document is well-formed' );

		return new Resolver( new XPath( $this->document ), $limit );
	}

	/**
	 * @param string $id
	 * @return DOMElement The last element with that id, in document order.
	 */
	private function element( $id ) {
		$found = null;
		foreach ( $this->document->getElementsByTagName( '*' ) as $element ) {
			if ( $element->getAttribute( 'id' ) === $id ) {
				$found = $element;
			}
		}
		$this->assertNotNull( $found, "Element #$id exists" );

		return $found;
	}

	/**
	 * @param Resolver $resolver
	 * @param string   $id
	 * @return Subject
	 */
	private function subject( Resolver $resolver, $id ) {
		$subjects = $resolver->findByElementId( $id );
		$this->assertCount( 1, $subjects, "One subject for #$id" );

		return reset( $subjects );
	}

	/**
	 * Asserts the removal list holds exactly these elements, in this order.
	 *
	 * @param DOMElement[] $expected
	 * @param Resolver     $resolver
	 */
	private function assertElementsToRemove( array $expected, Resolver $resolver ) {
		$actual = array_values( $resolver->getElementsToRemove() );

		$this->assertCount( count( $expected ), $actual );
		foreach ( $expected as $index => $element ) {
			$this->assertSame( $element, $actual[ $index ], "Entry $index" );
		}
	}

	/**
	 * @param string $id
	 * @return DOMElement The first `<use>` inside the element with that id.
	 */
	private function use_in( $id ) {
		$use = $this->element( $id )->getElementsByTagName( 'use' )->item( 0 );
		$this->assertNotNull( $use, "#$id contains a use element" );

		return $use;
	}

	public function test_knows_nothing_before_collect() {
		$resolver = $this->resolver( '<g id="a"/>' );

		$this->assertSame( array(), $resolver->findByElementId( 'a' ) );
		$this->assertNull( $resolver->findByElement( $this->element( 'a' ) ) );
		$this->assertSame( array(), $resolver->getElementsToRemove() );
	}

	public function test_collects_every_element_with_an_id_as_a_subject() {
		$resolver = $this->collected( '<g id="a"><rect id="b"/></g><circle/>' );

		$this->assertSame( $this->element( 'a' ), $this->subject( $resolver, 'a' )->getElement() );
		$this->assertSame( $this->element( 'b' ), $this->subject( $resolver, 'b' )->getElement() );
	}

	public function test_find_by_element_id_returns_subjects_keyed_by_id() {
		$resolver = $this->collected( '<g id="a"/><g id="b"/>' );

		$this->assertSame( array( 'b' ), array_keys( $resolver->findByElementId( 'b' ) ) );
	}

	public function test_find_by_element_id_returns_an_empty_array_for_an_unknown_id() {
		$resolver = $this->collected( '<g id="a"/>' );

		$this->assertSame( array(), $resolver->findByElementId( 'missing' ) );
	}

	/**
	 * Ids are compared as strings, exactly.
	 */
	public function test_find_by_element_id_is_case_sensitive() {
		$resolver = $this->collected( '<g id="a"/>' );

		$this->assertSame( array(), $resolver->findByElementId( 'A' ) );
	}

	/**
	 * Browsers resolve `#a` to the first element with that id.
	 */
	public function test_only_the_first_element_is_kept_when_an_id_is_used_twice() {
		$resolver = $this->collected( '<g id="a"/><rect id="a"/>' );

		$this->assertSame( 'g', $this->subject( $resolver, 'a' )->getElement()->nodeName );
	}

	public function test_find_by_element_returns_the_subject_of_that_element() {
		$resolver = $this->collected( '<g id="a"/><g id="b"/>' );

		$this->assertSame( $this->subject( $resolver, 'b' ), $resolver->findByElement( $this->element( 'b' ) ) );
	}

	public function test_find_by_element_returns_null_for_an_element_without_an_id() {
		$resolver = $this->collected( '<g id="a"><rect/></g>' );
		$rect     = $this->element( 'a' )->firstChild;

		$this->assertNull( $resolver->findByElement( $rect ) );
	}

	public function test_find_by_element_with_children_returns_the_subject_that_contains_the_element() {
		$resolver = $this->collected( '<g id="a"><g><rect/></g></g>' );
		$rect     = $this->element( 'a' )->firstChild->firstChild;

		$this->assertSame( $this->subject( $resolver, 'a' ), $resolver->findByElement( $rect, true ) );
	}

	/**
	 * Subjects are searched in document order, so the outermost identified
	 * ancestor wins, even over the element's own subject.
	 */
	public function test_find_by_element_with_children_prefers_the_outermost_subject() {
		$resolver = $this->collected( '<g id="outer"><g id="inner"/></g>' );
		$inner    = $this->element( 'inner' );

		$this->assertSame( $this->subject( $resolver, 'outer' ), $resolver->findByElement( $inner, true ) );
		$this->assertSame( $this->subject( $resolver, 'inner' ), $resolver->findByElement( $inner ) );
	}

	/**
	 * @dataProvider provide_documents_where_a_uses_b_once
	 */
	public function test_records_a_use_element_as_a_reference_in_both_directions( $inner, $root_attributes ) {
		$resolver = $this->collected( $inner, 15, $root_attributes );

		$this->assertSame( 1, $this->subject( $resolver, 'a' )->countUse() );
		$this->assertSame( 0, $this->subject( $resolver, 'a' )->countUsedIn() );
		$this->assertSame( 1, $this->subject( $resolver, 'b' )->countUsedIn() );
		$this->assertSame( 0, $this->subject( $resolver, 'b' )->countUse() );
		$this->assertSame( array(), $resolver->getElementsToRemove() );
	}

	public function provide_documents_where_a_uses_b_once() {
		$both = self::SVG . ' ' . self::XLINK;

		return array(
			'href'                               => array( '<g id="a"><use href="#b"/></g><rect id="b"/>', $both ),
			'xlink:href'                         => array( '<g id="a"><use xlink:href="#b"/></g><rect id="b"/>', $both ),
			'href wins over xlink:href'          => array( '<g id="a"><use href="#b" xlink:href="#a"/></g><rect id="b"/>', $both ),
			'target defined before the use'      => array( '<rect id="b"/><g id="a"><use href="#b"/></g>', $both ),
			'href, xlink prefix never declared'  => array( '<g id="a"><use href="#b"/></g><rect id="b"/>', self::SVG ),
			'document without a namespace'       => array( '<g id="a"><use href="#b"/></g><rect id="b"/>', self::XLINK ),
			'xlink:href without default namespace' => array( '<g id="a"><use xlink:href="#b"/></g><rect id="b"/>', self::XLINK ),
			'other use elements are ignored'     => array( '<g id="a"><use href="#b"/><use href="#missing"/><use href="other.svg#b"/><use/></g><rect id="b"/>', $both ),
			'use nested deeper than a direct child' => array( '<g id="a"><g><use href="#b"/></g></g><rect id="b"/>', $both ),
			'the use element itself carries the id' => array( '<use id="a" href="#b"/><rect id="b"/>', $both ),
			'href with surrounding whitespace'   => array( '<g id="a"><use href=" #b "/></g><rect id="b"/>', $both ),
			'xlink prefix declared on the use'   => array( '<g id="a"><use ' . self::XLINK . ' xlink:href="#b"/></g><rect id="b"/>', self::SVG ),
			'XLink namespace under another prefix' => array( '<g id="a"><use xmlns:x="http://www.w3.org/1999/xlink" x:href="#b"/></g><rect id="b"/>', self::SVG ),
			'a use without href next to a valid one' => array( '<g id="a"><use/><use href="#b"/></g><rect id="b"/>', self::SVG ),
		);
	}

	public function test_counts_every_use_of_the_same_target() {
		$resolver = $this->collected( '<g id="a"><use href="#b"/><use xlink:href="#b"/><use href="#b"/></g><rect id="b"/>' );

		$this->assertSame( 3, $this->subject( $resolver, 'a' )->countUse() );
		$this->assertSame( 3, $this->subject( $resolver, 'b' )->countUsedIn() );
	}

	/**
	 * @dataProvider provide_use_elements_that_are_not_recorded
	 */
	public function test_does_not_record_a_reference( $inner ) {
		$resolver = $this->collected( $inner, 15, self::SVG . ' ' . self::XLINK . ' xmlns:x="http://example.com/x"' );

		$this->assertSame( 0, $this->subject( $resolver, 'a' )->countUse() );
		$this->assertSame( 0, $this->subject( $resolver, 'b' )->countUsedIn() );
	}

	public function provide_use_elements_that_are_not_recorded() {
		return array(
			'use whose parent has no id'              => array( '<g id="a"/><g><use href="#b"/></g><rect id="b"/>' ),
			'use element in another namespace'        => array( '<g id="a"><x:use href="#b"/></g><rect id="b"/>' ),
			'href without a leading hash'             => array( '<g id="a"><use href="b"/></g><rect id="b"/>' ),
			'href to another file'                    => array( '<g id="a"><use href="other.svg#b"/></g><rect id="b"/>' ),
			'href in another case'                    => array( '<g id="a"><use href="#B"/></g><rect id="b"/>' ),
			'reference from an element other than use' => array( '<g id="a"><a href="#b"/></g><rect id="b"/>' ),
		);
	}

	public function test_use_element_referencing_itself_is_listed_for_removal() {
		$resolver = $this->collected( '<use id="a" href="#a"/>' );

		$this->assertElementsToRemove( array( $this->element( 'a' ) ), $resolver );
	}

	/**
	 * A `<use>` inside the element it points to is removed, not recorded.
	 */
	public function test_use_referencing_its_parent_is_listed_for_removal() {
		$resolver = $this->collected( '<g id="a"><use href="#a"/></g>' );

		$this->assertElementsToRemove( array( $this->use_in( 'a' ) ), $resolver );
		$this->assertSame( 0, $this->subject( $resolver, 'a' )->countUse() );
	}

	/**
	 * For a loop the element that is used by the first subject of the loop is
	 * listed under its id, then the first subject's `<use>` elements, and
	 * its references are cleared.
	 */
	public function test_loop_of_two_lists_the_second_element_and_the_first_use_for_removal() {
		$resolver = $this->collected( '<g id="a"><use href="#b"/></g><g id="b"><use href="#a"/></g>' );

		$this->assertSame( array( 'b', 0 ), array_keys( $resolver->getElementsToRemove() ) );
		$this->assertElementsToRemove( array( $this->element( 'b' ), $this->use_in( 'a' ) ), $resolver );
		$this->assertSame( 0, $this->subject( $resolver, 'a' )->countUse() );
		$this->assertFalse( $this->subject( $resolver, 'b' )->hasInfiniteLoop() );
	}

	public function test_loop_of_three_lists_the_second_element_and_the_first_use_for_removal() {
		$resolver = $this->collected(
			'<g id="a"><use href="#b"/></g><g id="b"><use href="#c"/></g><g id="c"><use href="#a"/></g>'
		);

		$this->assertElementsToRemove( array( $this->element( 'b' ), $this->use_in( 'a' ) ), $resolver );
		$this->assertFalse( $this->subject( $resolver, 'c' )->hasInfiniteLoop() );
	}

	/**
	 * a → b → c → d is four subjects deep.
	 */
	public function test_chain_within_the_nesting_limit_lists_nothing_for_removal() {
		$resolver = $this->collected(
			'<g id="a"><use href="#b"/></g><g id="b"><use href="#c"/></g><g id="c"><use href="#d"/></g><rect id="d"/>',
			4
		);

		$this->assertSame( array(), $resolver->getElementsToRemove() );
		$this->assertSame( 1, $this->subject( $resolver, 'a' )->countUse() );
	}

	/**
	 * With a limit of 3 the fourth element is where the limit is exceeded:
	 * it's listed first, then what the first subject uses and its `<use>`
	 * elements.
	 */
	public function test_chain_beyond_the_nesting_limit_lists_the_offending_element_and_the_first_reference() {
		$resolver = $this->collected(
			'<g id="a"><use href="#b"/></g><g id="b"><use href="#c"/></g><g id="c"><use href="#d"/></g><rect id="d"/>',
			3
		);

		$this->assertElementsToRemove( array( $this->element( 'd' ), $this->element( 'b' ), $this->use_in( 'a' ) ), $resolver );
		$this->assertSame( 0, $this->subject( $resolver, 'a' )->countUse() );
	}

	/**
	 * Pins a defect in Resolver::determineInvalidSubjects(): its in_array()
	 * check is not strict and any two DOMElement objects compare equal, so
	 * after the first entry in the removal list every remaining subject is
	 * skipped. Here the loop between c and d is never looked at: d is not
	 * listed and c still reports its loop. The sanitizer still removes it
	 * (see SanitizerUseTest).
	 *
	 * Fixed when d is listed as well and c no longer has a loop; this test
	 * then fails and becomes a regression test asserting `b` and `d`.
	 *
	 * @group pinned
	 */
	public function test_second_loop_is_not_detected_pinned_for_deferred_fix() {
		$resolver = $this->collected(
			'<g id="a"><use href="#b"/></g><g id="b"><use href="#a"/></g>'
			. '<g id="c"><use href="#d"/></g><g id="d"><use href="#c"/></g>'
		);

		$this->assertElementsToRemove( array( $this->element( 'b' ), $this->use_in( 'a' ) ), $resolver );
		$this->assertTrue( $this->subject( $resolver, 'c' )->hasInfiniteLoop() );
	}
}
