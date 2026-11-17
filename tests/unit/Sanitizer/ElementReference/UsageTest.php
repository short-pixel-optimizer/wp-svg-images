<?php

use enshrined\svgSanitize\ElementReference\Subject;
use enshrined\svgSanitize\ElementReference\Usage;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Usage: a Subject and a counter.
 */
class UsageTest extends TestCase {

	/**
	 * @return Subject
	 */
	private function subject() {
		$document = new DOMDocument();

		return new Subject( $document->createElement( 'g' ), 15 );
	}

	public function test_returns_the_subject_it_was_given() {
		$subject = $this->subject();

		$this->assertSame( $subject, ( new Usage( $subject ) )->getSubject() );
	}

	public function test_count_defaults_to_one() {
		$this->assertSame( 1, ( new Usage( $this->subject() ) )->getCount() );
	}

	/**
	 * @dataProvider provide_initial_counts
	 */
	public function test_initial_count_is_cast_to_int( $given, $expected ) {
		$this->assertSame( $expected, ( new Usage( $this->subject(), $given ) )->getCount() );
	}

	public function provide_initial_counts() {
		return array(
			'integer'        => array( 5, 5 ),
			'zero'           => array( 0, 0 ),
			'negative'       => array( -2, -2 ),
			'numeric string' => array( '3', 3 ),
			'float'          => array( 2.9, 2 ),
		);
	}

	public function test_increment_adds_one_by_default() {
		$usage = new Usage( $this->subject() );

		$usage->increment();
		$usage->increment();

		$this->assertSame( 3, $usage->getCount() );
	}

	/**
	 * @dataProvider provide_increments
	 */
	public function test_increment_adds_the_given_amount_cast_to_int( $by, $expected ) {
		$usage = new Usage( $this->subject(), 10 );

		$usage->increment( $by );

		$this->assertSame( $expected, $usage->getCount() );
	}

	public function provide_increments() {
		return array(
			'integer'        => array( 4, 14 ),
			'zero'           => array( 0, 10 ),
			'negative'       => array( -3, 7 ),
			'numeric string' => array( '2', 12 ),
			'float'          => array( 1.9, 11 ),
		);
	}
}
