<?php

use enshrined\svgSanitize\Exceptions\NestingException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * NestingException: an Exception that also carries a DOM element.
 */
class NestingExceptionTest extends TestCase {

	public function test_is_an_exception() {
		$this->assertInstanceOf( Exception::class, new NestingException() );
	}

	public function test_defaults_are_empty() {
		$exception = new NestingException();

		$this->assertSame( '', $exception->getMessage() );
		$this->assertSame( 0, $exception->getCode() );
		$this->assertNull( $exception->getPrevious() );
		$this->assertNull( $exception->getElement() );
	}

	public function test_carries_message_code_previous_and_element() {
		$document = new DOMDocument();
		$element  = $document->createElement( 'use' );
		$previous = new RuntimeException( 'earlier' );

		$exception = new NestingException( 'too deep', 42, $previous, $element );

		$this->assertSame( 'too deep', $exception->getMessage() );
		$this->assertSame( 42, $exception->getCode() );
		$this->assertSame( $previous, $exception->getPrevious() );
		$this->assertSame( $element, $exception->getElement() );
	}

	public function test_element_can_be_given_without_a_previous_exception() {
		$document = new DOMDocument();
		$element  = $document->createElement( 'use' );

		$exception = new NestingException( 'too deep', 42, null, $element );

		$this->assertNull( $exception->getPrevious() );
		$this->assertSame( $element, $exception->getElement() );
	}
}
