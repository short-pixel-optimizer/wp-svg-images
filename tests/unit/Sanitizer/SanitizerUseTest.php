<?php

require_once __DIR__ . '/SanitizerTestCase.php';

use enshrined\svgSanitize\Sanitizer;

/**
 * Sanitizer: `<use>` elements — external targets, the use threshold, the
 * nesting limit and reference loops.
 *
 * Larger documents are generated here; see chain() and bomb().
 */
class SanitizerUseTest extends SanitizerTestCase {

	/**
	 * `s1` uses `s2`, which uses `s3`, and so on down to a `<rect>`.
	 *
	 * @param int $length Number of identified elements.
	 * @return string
	 */
	private function chain( $length ) {
		$markup = '';
		for ( $i = 1; $i < $length; $i++ ) {
			$markup .= '<g id="s' . $i . '"><use href="#s' . ( $i + 1 ) . '"/></g>';
		}

		return $markup . '<rect id="s' . $length . '"/>';
	}

	/**
	 * Three groups that each use the next one $per_level times, a final
	 * `<rect>`, and one `<use>` of the first group: rendering that last
	 * element draws $per_level ^ 3 rectangles.
	 *
	 * @param int    $per_level References in each group.
	 * @param string $before    Markup placed before the references of each group.
	 * @param bool   $wrap      Whether to wrap the references in a `<g>`.
	 * @return string
	 */
	private function bomb( $per_level, $before = '', $wrap = false ) {
		$markup = '';
		for ( $i = 1; $i <= 3; $i++ ) {
			$uses = str_repeat( '<use href="#b' . ( $i + 1 ) . '"/>', $per_level );
			if ( $wrap ) {
				$uses = "<g>$uses</g>";
			}
			$markup .= '<g id="b' . $i . '">' . $before . $uses . '</g>';
		}

		return $markup . '<rect id="b4"/><use href="#b1"/>';
	}

	/* Targets. */

	public function test_keeps_use_with_a_local_target() {
		$markup = '<rect id="r"></rect><use href="#r"></use><use xlink:href="#r"></use><g><use href="#r"></use></g>';

		$this->assertSame( $markup, $this->sanitize_inner( $markup, self::XLINK_NS ) );
		$this->assertSame( array(), $this->issue_messages() );
	}

	public function test_keeps_use_without_a_target() {
		$markup = '<use></use><use href=""></use><use href="#"></use><use href="#missing"></use>';

		$this->assertSame( $markup, $this->sanitize_inner( $markup, self::XLINK_NS ) );
	}

	/**
	 * @dataProvider provide_use_elements_with_an_external_target
	 */
	public function test_removes_use_with_an_external_target( $use ) {
		$inner = $this->sanitize_inner( $use . '<rect/>', self::XLINK_NS );

		$this->assertSame( '<rect></rect>', $inner );
		$this->assertSame( array( "Suspicious 'use'" ), $this->issue_messages() );
	}

	public function provide_use_elements_with_an_external_target() {
		return array(
			'https'                => array( '<use href="https://example.org/sprite.svg#icon"/>' ),
			'http in xlink:href'   => array( '<use xlink:href="http://example.org/sprite.svg#icon"/>' ),
			'absolute path'        => array( '<use href="/sprite.svg#icon"/>' ),
			'protocol-relative'    => array( '<use href="//example.org/sprite.svg#icon"/>' ),
			'data image'           => array( '<use href="data:image/png;base64,AAAA"/>' ),
		);
	}

	public function test_use_rules_apply_whatever_the_case_of_the_element_name() {
		$inner = $this->sanitize_inner( '<USE href="https://example.org/sprite.svg#icon"/><rect/>' );

		$this->assertSame( '<rect></rect>', $inner );
		$this->assertSame( array( "Suspicious 'USE'" ), $this->issue_messages() );
	}

	/**
	 * A relative file name fails the href rule first: the attribute goes and
	 * the then harmless element stays.
	 */
	public function test_use_with_a_relative_file_target_loses_only_its_href() {
		$inner = $this->sanitize_inner( '<use href="sprite.svg#icon"/>' );

		$this->assertSame( '<use></use>', $inner );
		$this->assertSame( array( "Suspicious attribute 'href'" ), $this->issue_messages() );
	}

	/* Use threshold. */

	/**
	 * `a` contains two references; a `<use>` of `a` is removed once the
	 * threshold is 2 or less. The references inside `a` point to `b`, which
	 * contains none, so they stay.
	 */
	public function test_removes_use_whose_target_reaches_the_threshold() {
		$inner = $this->sanitize_inner(
			'<g id="a"><use href="#b"/><use href="#b"/></g><rect id="b"/><use href="#a"/><use href="#b"/>',
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->useThreshold( 2 );
			}
		);

		$this->assertSame( '<g id="a"><use href="#b"></use><use href="#b"></use></g><rect id="b"></rect><use href="#b"></use>', $inner );
		$this->assertSame( array( "Suspicious 'use'" ), $this->issue_messages() );
	}

	public function test_keeps_use_whose_target_stays_below_the_threshold() {
		$markup = '<g id="a"><use href="#b"></use><use href="#b"></use></g><rect id="b"></rect><use href="#a"></use>';

		$inner = $this->sanitize_inner(
			$markup,
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->useThreshold( 3 );
			}
		);

		$this->assertSame( $markup, $inner );
	}

	/**
	 * @dataProvider provide_thresholds_that_disable_the_check
	 */
	public function test_threshold_of_zero_or_less_disables_the_check( $threshold ) {
		$markup = '<g id="a"><use href="#b"></use><use href="#b"></use></g><rect id="b"></rect><use href="#a"></use>';

		$inner = $this->sanitize_inner(
			$markup,
			'',
			function ( Sanitizer $sanitizer ) use ( $threshold ) {
				$sanitizer->useThreshold( $threshold );
			}
		);

		$this->assertSame( $markup, $inner );
	}

	public function provide_thresholds_that_disable_the_check() {
		return array(
			'zero'           => array( 0 ),
			'negative'       => array( -1 ),
			'not a number'   => array( 'many' ),
		);
	}

	public function test_threshold_is_cast_to_int() {
		$inner = $this->sanitize_inner(
			'<g id="a"><use href="#b"/><use href="#b"/></g><rect id="b"/><use href="#a"/>',
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->useThreshold( '2' );
			}
		);

		$this->assertStringNotContainsString( '<use href="#a">', $inner );
	}

	/**
	 * 10 x 10 x 10 expansions reach the default threshold of 1000: the
	 * `<use>` that starts the expansion is removed, the 30 inside stay.
	 */
	public function test_default_threshold_removes_use_that_expands_to_a_thousand_elements() {
		$inner = $this->sanitize_inner( $this->bomb( 10 ) );

		$this->assertStringNotContainsString( '<use href="#b1">', $inner );
		$this->assertSame( 30, substr_count( $inner, '<use ' ) );
		$this->assertSame( array( "Suspicious 'use'" ), $this->issue_messages() );
	}

	/**
	 * 9 x 9 x 9 = 729 expansions stay below it.
	 */
	public function test_default_threshold_keeps_use_that_expands_to_fewer_elements() {
		$inner = $this->sanitize_inner( $this->bomb( 9 ) );

		$this->assertStringContainsString( '<use href="#b1">', $inner );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/**
	 * Pins a defect in Resolver::processReferences(): references are only
	 * recorded for `<use>` elements that are direct children of an
	 * identified element. Wrapping them in a `<g>` hides them, and the same
	 * 1000-fold expansion as above passes untouched.
	 *
	 * Fixed when the `<use>` of `b1` is removed; this test then fails and
	 * becomes a regression test asserting that.
	 *
	 * @group pinned
	 */
	public function test_expansion_through_wrapped_use_elements_is_not_detected_pinned_for_deferred_fix() {
		$inner = $this->sanitize_inner( $this->bomb( 10, '', true ) );

		$this->assertStringContainsString( '<use href="#b1">', $inner );
		$this->assertSame( 31, substr_count( $inner, '<use ' ) );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/**
	 * Pins a defect in Resolver::processReferences(): its query uses the
	 * `xlink` prefix without registering it. One `<use>` without `href` in
	 * a document that does not declare the prefix makes the query fail, PHP
	 * raises a warning per affected element, no reference is recorded and
	 * the same 1000-fold expansion as above passes untouched.
	 *
	 * Fixed when no warning is raised and the `<use>` of `b1` is removed;
	 * this test then fails and becomes a regression test asserting that.
	 *
	 * @group pinned
	 */
	public function test_expansion_is_not_detected_when_the_xlink_prefix_is_not_declared_pinned_for_deferred_fix() {
		$warnings = array();
		set_error_handler(
			function ( $number, $message ) use ( &$warnings ) {
				$warnings[] = $message;
				return true;
			},
			E_WARNING
		);
		try {
			$inner = $this->sanitize_inner( $this->bomb( 10, '<use/>' ) );
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 3, $warnings );
		$this->assertStringContainsString( 'foreach', $warnings[0] );
		$this->assertStringContainsString( '<use href="#b1">', $inner );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/* Nesting limit. */

	public function test_default_nesting_limit_accepts_fifteen_levels() {
		$inner = $this->sanitize_inner( $this->chain( 15 ) . '<use href="#s1"/>' );

		$this->assertSame( 15, substr_count( $inner, '<use ' ) );
		$this->assertSame( array(), $this->issue_messages() );
	}

	public function test_default_nesting_limit_removes_the_use_elements_at_sixteen_levels() {
		$inner = $this->sanitize_inner( $this->chain( 16 ) . '<use href="#s1"/>' );

		$this->assertSame( 0, substr_count( $inner, '<use' ) );
		$this->assertSame( array_fill( 0, 16, "Invalid 'use'" ), $this->issue_messages() );
	}

	public function test_set_use_nesting_limit_lowers_the_limit() {
		$inner = $this->sanitize_inner(
			$this->chain( 4 ),
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->setUseNestingLimit( 3 );
			}
		);

		$this->assertSame( '<g id="s1"></g><g id="s2"></g><g id="s3"></g><rect id="s4"></rect>', $inner );
	}

	public function test_set_use_nesting_limit_is_cast_to_int() {
		$inner = $this->sanitize_inner(
			$this->chain( 4 ),
			'',
			function ( Sanitizer $sanitizer ) {
				$sanitizer->setUseNestingLimit( '4' );
			}
		);

		$this->assertSame( 3, substr_count( $inner, '<use ' ) );
	}

	/* Loops. */

	public function test_removes_the_use_elements_of_a_reference_loop() {
		$inner = $this->sanitize_inner( '<g id="a"><use href="#b"/></g><g id="b"><use href="#a"/></g>' );

		$this->assertSame( '<g id="a"></g><g id="b"></g>', $inner );
		$this->assertSame( array( "Invalid 'use'", "Invalid 'use'" ), $this->issue_messages() );
	}

	public function test_removes_the_use_elements_of_two_separate_loops() {
		$inner = $this->sanitize_inner(
			'<g id="a"><use href="#b"/></g><g id="b"><use href="#a"/></g>'
			. '<g id="c"><use href="#d"/></g><g id="d"><use href="#c"/></g>'
		);

		$this->assertSame( '<g id="a"></g><g id="b"></g><g id="c"></g><g id="d"></g>', $inner );
	}

	/**
	 * Pins a defect in Sanitizer::startClean(): its in_array() check against
	 * the removal list is not strict and any two DOMElement objects compare
	 * equal. One loop anywhere in the document therefore removes every
	 * `<use>`, including valid ones that have nothing to do with the loop.
	 *
	 * Fixed when the two `<use>` of `r` are kept while the loop is still
	 * broken; this test then fails and becomes a regression test for that.
	 *
	 * @group pinned
	 */
	public function test_one_loop_removes_every_use_in_the_document_pinned_for_deferred_fix() {
		$inner = $this->sanitize_inner(
			'<g id="a"><use href="#b"/></g><g id="b"><use href="#a"/></g>'
			. '<rect id="r"/><use href="#r"/><g><use href="#r"/></g>'
		);

		$this->assertSame( '<g id="a"></g><g id="b"></g><rect id="r"></rect><g></g>', $inner );
		$this->assertSame( array_fill( 0, 4, "Invalid 'use'" ), $this->issue_messages() );
	}

	/**
	 * Pins a defect in Resolver::determineInvalidSubjects(): a `<use>` that
	 * references itself is recognised but nothing is listed for removal, so
	 * the element stays in the output.
	 *
	 * Fixed when the element is removed; this test then fails and becomes a
	 * regression test asserting `<rect></rect>`.
	 *
	 * @group pinned
	 */
	public function test_use_referencing_itself_is_kept_pinned_for_deferred_fix() {
		$inner = $this->sanitize_inner( '<use id="a" href="#a"/><rect/>' );

		$this->assertSame( '<use id="a" href="#a"></use><rect></rect>', $inner );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/**
	 * Pins a defect in Resolver::processReferences(): a `<use>` that
	 * references its own parent makes Subject::addUse() throw, and nothing
	 * between there and the caller of sanitize() catches the exception.
	 *
	 * Fixed when sanitize() returns for this document; this test then fails
	 * and becomes a regression test asserting the `<use>` is removed.
	 *
	 * @group pinned
	 */
	public function test_use_referencing_its_parent_throws_logic_exception_pinned_for_deferred_fix() {
		$sanitizer = $this->plugin_sanitizer();

		$this->expectException( LogicException::class );
		$this->expectExceptionCode( 1570713416 );
		$this->expectExceptionMessage( 'Cannot add self usage' );

		$sanitizer->sanitize( $this->svg( '<g id="a"><use href="#a"/></g>' ) );
	}
}
