<?php

require_once __DIR__ . '/WPSVGTestCase.php';

/**
 * WPSVG::clean_animations(): removes animation elements that could turn an
 * attribute into a script URL, as documented on the method.
 */
class WPSVGCleanAnimationsTest extends WPSVGTestCase {

	const SVG_NS = 'xmlns="http://www.w3.org/2000/svg"';

	/**
	 * @param string $inner Markup inside the root element.
	 * @return string An svg element around $inner.
	 */
	private function svg( $inner ) {
		return '<svg ' . self::SVG_NS . '>' . $inner . '</svg>';
	}

	/**
	 * @dataProvider provide_markup_with_nothing_to_remove
	 */
	public function test_markup_with_nothing_to_remove_is_returned_unchanged( $markup ) {
		$this->assertSame( $markup, $this->plugin()->clean_animations( $markup ) );
	}

	public function provide_markup_with_nothing_to_remove() {
		return array(
			'no animation'                   => array( $this->svg( '<rect/>' ) ),
			'declaration and line breaks'    => array( "<?xml version=\"1.0\"?>\n<svg " . self::SVG_NS . ">\n<rect/>\n</svg>\n" ),
			'empty'                          => array( '' ),
			'only whitespace'                => array( " \n " ),
			'animateTransform on transform'  => array( $this->svg( '<rect><animateTransform attributeName="transform" type="rotate" values="0;90"/></rect>' ) ),
			'animateMotion without a target' => array( $this->svg( '<rect><animateMotion path="M0 0 L10 10"/></rect>' ) ),
		);
	}

	/**
	 * @dataProvider provide_markup_that_cannot_be_parsed
	 */
	public function test_returns_false_when_the_markup_cannot_be_parsed_or_has_no_root( $markup ) {
		$this->assertFalse( $this->plugin()->clean_animations( $markup ) );
	}

	public function provide_markup_that_cannot_be_parsed() {
		return array(
			'plain text'         => array( 'not xml' ),
			'unclosed element'   => array( '<svg ' . self::SVG_NS . '><rect></svg>' ),
			'only a declaration' => array( '<?xml version="1.0"?> ' ),
		);
	}

	/**
	 * @dataProvider provide_allowed_animations
	 */
	public function test_animation_with_an_allowed_target_and_safe_values_is_kept( $animation ) {
		$markup = $this->svg( '<rect>' . $animation . '</rect>' );

		$this->assertSame( $markup, $this->plugin()->clean_animations( $markup ) );
	}

	public function provide_allowed_animations() {
		return array(
			'animate on x'                          => array( '<animate attributeName="x" values="0;10"/>' ),
			'animate on fill'                       => array( '<animate attributeName="fill" from="red" to="blue"/>' ),
			'animate without a target'              => array( '<animate values="0;10"/>' ),
			'set on visibility'                     => array( '<set attributeName="visibility" to="hidden"/>' ),
			'animateColor on stop-color'            => array( '<animateColor attributeName="stop-color" values="red;blue"/>' ),
			'animateTransform on gradientTransform' => array( '<animateTransform attributeName="gradientTransform" type="scale" values="1;2"/>' ),
			'animateTransform on patternTransform'  => array( '<animateTransform attributeName="patternTransform" type="scale" by="2"/>' ),
			'target in upper case'                  => array( '<animate attributeName="FILL" values="red;blue"/>' ),
			'target with surrounding whitespace'    => array( '<animate attributeName=" opacity " values="0;1"/>' ),
		);
	}

	/**
	 * @dataProvider provide_unsafe_animations
	 */
	public function test_animation_with_an_unsafe_target_or_value_is_removed( $animation ) {
		$this->assertSame(
			$this->svg( '<rect></rect>' ),
			$this->plugin()->clean_animations( $this->svg( '<rect>' . $animation . '</rect>' ) )
		);
	}

	public function provide_unsafe_animations() {
		return array(
			'animate on href'                       => array( '<animate attributeName="href" values="javascript:alert(1)"/>' ),
			'set on xlink:href'                     => array( '<set attributeName="xlink:href" to="#a"/>' ),
			'set on style'                          => array( '<set attributeName="style" to="fill:red"/>' ),
			'animate with an empty target'          => array( '<animate attributeName="" values="0;1"/>' ),
			'animateColor on href'                  => array( '<animateColor attributeName="href" values="a;b"/>' ),
			'animateTransform on x'                 => array( '<animateTransform attributeName="x" values="0;1"/>' ),
			'animateMotion with a target'           => array( '<animateMotion attributeName="x" path="M0 0"/>' ),
			'javascript URL in values'              => array( '<animate attributeName="fill" values="red;javascript:alert(1)"/>' ),
			'javascript URL in upper case in to'    => array( '<set attributeName="fill" to="JavaScript:alert(1)"/>' ),
			'vbscript URL in from'                  => array( '<animate attributeName="fill" from="vbscript:x" to="red"/>' ),
			'data URL in by'                        => array( '<animate attributeName="fill" by="data:text/html,x"/>' ),
			'URL inside url() on an allowed target' => array( '<set attributeName="fill" to="url(javascript:alert(1))"/>' ),
			'URL split by a tab and a space'        => array( '<set attributeName="fill" to="java&#9;scri pt:alert(1)"/>' ),
			'URL with a character reference'        => array( '<set attributeName="fill" to="&#106;avascript:alert(1)"/>' ),
			'element name in upper case'            => array( '<SET attributeName="href" to="#a"/>' ),
			'element in another namespace'          => array( '<x:set xmlns:x="urn:example" attributeName="href" to="#a"/>' ),
		);
	}

	public function test_every_unsafe_animation_is_removed_and_the_rest_kept() {
		$markup = '<?xml version="1.0"?>' . $this->svg(
			'<g><set attributeName="href" to="#a"/><rect><animate attributeName="x" values="0;1"/><set attributeName="href" to="#b"/></rect></g>'
		);

		$this->assertSame(
			$this->svg( '<g><rect><animate attributeName="x" values="0;1"></animate></rect></g>' ),
			$this->plugin()->clean_animations( $markup ),
			'Re-serialized without the declaration and with explicit closing tags'
		);
	}

	/**
	 * Elements whose names only contain an animation name are not animations.
	 */
	public function test_elements_with_similar_names_are_left_alone() {
		$markup = $this->svg( '<animations attributeName="href"></animations><offset attributeName="href"></offset><x:animat xmlns:x="urn:example" attributeName="href"></x:animat>' );

		$this->assertSame( $markup, $this->plugin()->clean_animations( $markup ) );
	}

	public function test_unsafe_animations_are_found_at_any_depth_in_a_large_document() {
		$group  = '<g><path d="M0 0"></path><circle><animate attributeName="r" values="1;2"></animate><SET attributeName="href" to="#a"></SET></circle></g>';
		$kept   = '<g><path d="M0 0"></path><circle><animate attributeName="r" values="1;2"></animate></circle></g>';
		$nested = str_repeat( '<g>', 50 ) . '<animateMotion attributeName="href"></animateMotion><rect></rect>' . str_repeat( '</g>', 50 );

		$output = $this->plugin()->clean_animations( $this->svg( str_repeat( $group, 2000 ) . $nested ) );

		$this->assertSame( $this->svg( str_repeat( $kept, 2000 ) . str_repeat( '<g>', 50 ) . '<rect></rect>' . str_repeat( '</g>', 50 ) ), $output );
	}

	public function test_values_on_elements_that_are_not_animations_are_ignored() {
		$markup = $this->svg( '<filter><feColorMatrix values="javascript:x"/></filter>' );

		$this->assertSame( $markup, $this->plugin()->clean_animations( $markup ) );
	}
}
