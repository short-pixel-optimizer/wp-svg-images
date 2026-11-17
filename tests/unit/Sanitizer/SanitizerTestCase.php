<?php

use enshrined\svgSanitize\Sanitizer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Shared helpers for the Sanitizer test classes.
 *
 * The helpers configure the sanitizer the way the plugin does (no XML
 * declaration, minified), which makes the output one predictable line.
 */
abstract class SanitizerTestCase extends TestCase {

	const SVG_NS   = 'xmlns="http://www.w3.org/2000/svg"';
	const XLINK_NS = 'xmlns:xlink="http://www.w3.org/1999/xlink"';

	/**
	 * The sanitizer used by the last sanitize_inner() / plugin_sanitizer() call.
	 *
	 * @var Sanitizer
	 */
	protected $sanitizer;

	/**
	 * The sanitizer leaves libxml's internal error handling switched on (see
	 * the pinned test in SanitizerTest); undo that so tests stay independent.
	 */
	protected function tear_down() {
		libxml_clear_errors();
		libxml_use_internal_errors( false );
		parent::tear_down();
	}

	/**
	 * @return Sanitizer A new sanitizer configured like the plugin's.
	 */
	protected function plugin_sanitizer() {
		$this->sanitizer = new Sanitizer();
		$this->sanitizer->removeXMLTag( true );
		$this->sanitizer->minify( true );

		return $this->sanitizer;
	}

	/**
	 * @param string $inner           Markup inside the root element.
	 * @param string $root_attributes Extra attributes for the root element.
	 * @return string An SVG document in the SVG namespace.
	 */
	protected function svg( $inner, $root_attributes = '' ) {
		$attributes = '' === $root_attributes ? self::SVG_NS : self::SVG_NS . ' ' . $root_attributes;

		return "<svg $attributes>$inner</svg>";
	}

	/**
	 * Sanitizes `<svg>$inner</svg>` with the plugin's configuration and
	 * returns what is left inside the root element.
	 *
	 * @param string        $inner           Markup inside the root element.
	 * @param string        $root_attributes Extra attributes for the root element.
	 * @param callable|null $configure       Receives the sanitizer before it runs.
	 * @return string
	 */
	protected function sanitize_inner( $inner, $root_attributes = '', $configure = null ) {
		$sanitizer = $this->plugin_sanitizer();
		if ( $configure ) {
			$configure( $sanitizer );
		}

		$output = $sanitizer->sanitize( $this->svg( $inner, $root_attributes ) );

		$this->assertIsString( $output );
		$this->assertSame( 1, preg_match( '~^<svg[^>]*>(.*)</svg>$~s', $output, $match ), "Output is one svg element: $output" );

		return $match[1];
	}

	/**
	 * @return string[] Messages of the issues reported by the last run.
	 */
	protected function issue_messages() {
		return array_column( $this->sanitizer->getXmlIssues(), 'message' );
	}

	/**
	 * Turns a list of values into a data provider keyed by the values.
	 *
	 * @param string[] $values
	 * @return array[]
	 */
	protected function keyed( array $values ) {
		$cases = array();
		foreach ( $values as $value ) {
			$cases[ '' === $value ? '(empty)' : $value ] = array( $value );
		}

		return $cases;
	}
}
