<?php

use enshrined\svgSanitize\ElementReference\Subject;
use enshrined\svgSanitize\Exceptions\NestingException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Subject: one identified element and its `<use>` references in both
 * directions. Graphs are built by hand here; ResolverTest builds them from
 * documents.
 */
class SubjectTest extends TestCase {

	/**
	 * @var DOMDocument
	 */
	private $document;

	protected function set_up() {
		parent::set_up();
		$this->document = new DOMDocument();
	}

	/**
	 * @param string $id    Value of the element's `id` attribute.
	 * @param int    $limit Nesting limit.
	 * @return Subject
	 */
	private function subject( $id, $limit = 15 ) {
		$element = $this->document->createElement( 'g' );
		$element->setAttribute( 'id', $id );

		return new Subject( $element, $limit );
	}

	/**
	 * Builds subjects `s1` to `s<length>` where each one uses the next.
	 *
	 * @param int $length Number of subjects.
	 * @param int $limit  Nesting limit of every subject.
	 * @return Subject[] Zero-based list.
	 */
	private function chain( $length, $limit ) {
		$subjects = array();
		for ( $i = 1; $i <= $length; $i++ ) {
			$subjects[] = $this->subject( "s$i", $limit );
		}
		for ( $i = 0; $i < $length - 1; $i++ ) {
			$subjects[ $i ]->addUse( $subjects[ $i + 1 ] );
		}

		return $subjects;
	}

	public function test_returns_its_element_and_the_element_id() {
		$element = $this->document->createElement( 'g' );
		$element->setAttribute( 'id', 'shape' );

		$subject = new Subject( $element, 15 );

		$this->assertSame( $element, $subject->getElement() );
		$this->assertSame( 'shape', $subject->getElementId() );
	}

	public function test_element_id_is_an_empty_string_without_an_id_attribute() {
		$subject = new Subject( $this->document->createElement( 'g' ), 15 );

		$this->assertSame( '', $subject->getElementId() );
	}

	public function test_new_subject_has_no_references() {
		$subject = $this->subject( 'a' );

		$this->assertSame( 0, $subject->countUse() );
		$this->assertSame( 0, $subject->countUse( true ) );
		$this->assertSame( 0, $subject->countUsedIn() );
		$this->assertFalse( $subject->hasInfiniteLoop() );
		$this->assertSame( array(), $subject->clearInternalAndGetAffectedElements() );
	}

	public function test_add_use_rejects_the_subject_itself() {
		$subject = $this->subject( 'a' );

		$this->expectException( LogicException::class );
		$this->expectExceptionCode( 1570713416 );
		$this->expectExceptionMessage( 'Cannot add self usage' );

		$subject->addUse( $subject );
	}

	public function test_add_used_in_rejects_the_subject_itself() {
		$subject = $this->subject( 'a' );

		$this->expectException( LogicException::class );
		$this->expectExceptionCode( 1570713417 );
		$this->expectExceptionMessage( 'Cannot add self as usage' );

		$subject->addUsedIn( $subject );
	}

	public function test_count_use_counts_each_reference() {
		$a = $this->subject( 'a' );
		$a->addUse( $this->subject( 'b' ) );
		$a->addUse( $this->subject( 'c' ) );

		$this->assertSame( 2, $a->countUse() );
	}

	public function test_count_use_counts_repeated_references_to_the_same_subject() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$a->addUse( $b );
		$a->addUse( $b );
		$a->addUse( $b );

		$this->assertSame( 3, $a->countUse() );
	}

	/**
	 * a uses b twice and b uses c three times: a expands to 2 x 3 elements.
	 * Accumulated, each b counts as itself plus its three c: 2 x (1 + 3).
	 */
	public function test_count_use_multiplies_along_a_chain() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$c = $this->subject( 'c' );
		$a->addUse( $b );
		$a->addUse( $b );
		$b->addUse( $c );
		$b->addUse( $c );
		$b->addUse( $c );

		$this->assertSame( 6, $a->countUse() );
		$this->assertSame( 8, $a->countUse( true ) );
		$this->assertSame( 3, $b->countUse() );
		$this->assertSame( 0, $c->countUse() );
	}

	/**
	 * a uses b and c, which both use d.
	 */
	public function test_count_use_adds_up_the_branches_of_a_diamond() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$c = $this->subject( 'c' );
		$d = $this->subject( 'd' );
		$a->addUse( $b );
		$a->addUse( $c );
		$b->addUse( $d );
		$c->addUse( $d );

		$this->assertSame( 2, $a->countUse() );
		$this->assertSame( 4, $a->countUse( true ) );
	}

	/**
	 * References are stored by id: a second subject with the same id is
	 * counted as one more reference to the first.
	 */
	public function test_add_use_treats_another_subject_with_the_same_id_as_a_repeat() {
		$a      = $this->subject( 'a' );
		$first  = $this->subject( 'twin' );
		$second = $this->subject( 'twin' );
		$a->addUse( $first );
		$a->addUse( $second );

		$this->assertSame( 2, $a->countUse() );
		$this->assertSame( array( 'twin' => $first->getElement() ), $a->clearInternalAndGetAffectedElements() );
	}

	public function test_add_use_does_not_record_the_opposite_direction() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$a->addUse( $b );

		$this->assertSame( 0, $b->countUsedIn() );
		$this->assertSame( 0, $a->countUsedIn() );
	}

	/**
	 * c is used three times in b, and b twice in a: c ends up in 3 x 2 places.
	 */
	public function test_count_used_in_multiplies_along_a_chain() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$c = $this->subject( 'c' );
		$b->addUsedIn( $a );
		$b->addUsedIn( $a );
		$c->addUsedIn( $b );
		$c->addUsedIn( $b );
		$c->addUsedIn( $b );

		$this->assertSame( 6, $c->countUsedIn() );
		$this->assertSame( 2, $b->countUsedIn() );
		$this->assertSame( 0, $a->countUsedIn() );
	}

	public function test_has_no_infinite_loop_in_a_chain() {
		$chain = $this->chain( 3, 15 );

		$this->assertFalse( $chain[0]->hasInfiniteLoop() );
	}

	/**
	 * Reaching the same subject along two different paths is not a loop.
	 */
	public function test_has_no_infinite_loop_in_a_diamond() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$c = $this->subject( 'c' );
		$d = $this->subject( 'd' );
		$a->addUse( $b );
		$a->addUse( $c );
		$b->addUse( $d );
		$c->addUse( $d );

		$this->assertFalse( $a->hasInfiniteLoop() );
	}

	public function test_has_infinite_loop_when_two_subjects_use_each_other() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$a->addUse( $b );
		$b->addUse( $a );

		$this->assertTrue( $a->hasInfiniteLoop() );
		$this->assertTrue( $b->hasInfiniteLoop() );
	}

	/**
	 * a is not part of the loop between b and c, but leads into it.
	 */
	public function test_has_infinite_loop_when_a_reference_leads_into_a_loop() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$c = $this->subject( 'c' );
		$a->addUse( $b );
		$b->addUse( $c );
		$c->addUse( $b );

		$this->assertTrue( $a->hasInfiniteLoop() );
	}

	/**
	 * A chain may hold exactly as many subjects as the limit.
	 */
	public function test_chain_as_long_as_the_nesting_limit_is_accepted() {
		$chain = $this->chain( 3, 3 );

		$this->assertFalse( $chain[0]->hasInfiniteLoop() );
	}

	/**
	 * One subject more than the limit throws, and the exception carries the
	 * element of the subject where the limit was exceeded.
	 */
	public function test_chain_longer_than_the_nesting_limit_throws() {
		$chain = $this->chain( 4, 3 );

		try {
			$chain[0]->hasInfiniteLoop();
			$this->fail( 'Expected a NestingException' );
		} catch ( NestingException $exception ) {
			$this->assertSame( 'Nesting level too high, aborting', $exception->getMessage() );
			$this->assertSame( 1570713498, $exception->getCode() );
			$this->assertSame( $chain[3]->getElement(), $exception->getElement() );
		}
	}

	/**
	 * Starting further down the same chain stays within the limit.
	 */
	public function test_nesting_is_counted_from_the_subject_asked() {
		$chain = $this->chain( 4, 3 );

		$this->assertFalse( $chain[1]->hasInfiniteLoop() );
	}

	public function test_nesting_limit_of_zero_throws_for_any_subject() {
		$subject = $this->subject( 'a', 0 );

		try {
			$subject->hasInfiniteLoop();
			$this->fail( 'Expected a NestingException' );
		} catch ( NestingException $exception ) {
			$this->assertSame( $subject->getElement(), $exception->getElement() );
		}
	}

	/**
	 * The limit is checked before the loop: closing a loop of two needs a
	 * third step, so a limit of 2 throws where a limit of 3 reports the loop.
	 */
	public function test_loop_as_long_as_the_nesting_limit_throws_instead_of_being_reported() {
		$a = $this->subject( 'a', 2 );
		$b = $this->subject( 'b', 2 );
		$a->addUse( $b );
		$b->addUse( $a );

		$this->expectException( NestingException::class );

		$a->hasInfiniteLoop();
	}

	public function test_loop_shorter_than_the_nesting_limit_is_reported() {
		$a = $this->subject( 'a', 3 );
		$b = $this->subject( 'b', 3 );
		$a->addUse( $b );
		$b->addUse( $a );

		$this->assertTrue( $a->hasInfiniteLoop() );
	}

	public function test_clear_returns_the_used_elements_by_id_and_forgets_all_references() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$c = $this->subject( 'c' );
		$a->addUse( $b );
		$a->addUse( $c );
		$a->addUse( $c );
		$a->addUsedIn( $c );

		$elements = $a->clearInternalAndGetAffectedElements();

		$this->assertSame( array( 'b' => $b->getElement(), 'c' => $c->getElement() ), $elements );
		$this->assertSame( 0, $a->countUse() );
		$this->assertSame( 0, $a->countUsedIn() );
		$this->assertSame( array(), $a->clearInternalAndGetAffectedElements() );
	}

	/**
	 * Elements that use the subject are not "affected": only the used ones
	 * are returned.
	 */
	public function test_clear_does_not_return_the_elements_the_subject_is_used_in() {
		$a = $this->subject( 'a' );
		$a->addUsedIn( $this->subject( 'b' ) );

		$this->assertSame( array(), $a->clearInternalAndGetAffectedElements() );
	}

	public function test_clearing_one_subject_breaks_the_loop_for_the_other() {
		$a = $this->subject( 'a' );
		$b = $this->subject( 'b' );
		$a->addUse( $b );
		$b->addUse( $a );

		$a->clearInternalAndGetAffectedElements();

		$this->assertFalse( $a->hasInfiniteLoop() );
		$this->assertFalse( $b->hasInfiniteLoop() );
	}
}
