<?php

require_once __DIR__ . '/WPSVGTestCase.php';

/**
 * WPSVG::svg_dimensions() against the files in
 * tests/fixtures/svg/dimensions/; each file name says what its root element
 * declares.
 */
class WPSVGDimensionsTest extends WPSVGTestCase {

	/**
	 * @param string $name File name without extension.
	 * @return object The dimensions of that fixture.
	 */
	private function dimensions_of( $name ) {
		$path = WPSVG_TESTS_FIXTURES_DIR . "svg/dimensions/$name.svg";
		$this->assertFileExists( $path );

		return $this->plugin()->svg_dimensions( $path );
	}

	/**
	 * @param array[] $cases Fixture name => array( width, height ).
	 * @return array[] Data provider rows keyed by fixture name.
	 */
	private function cases( array $cases ) {
		$rows = array();
		foreach ( $cases as $name => $size ) {
			$rows[ $name ] = array( $name, $size[0], $size[1] );
		}

		return $rows;
	}

	public function test_returns_an_object_with_width_and_height() {
		$dimensions = $this->dimensions_of( 'width-and-height' );

		$this->assertIsObject( $dimensions );
		$this->assertSame( array( 'width', 'height' ), array_keys( get_object_vars( $dimensions ) ) );
	}

	/**
	 * @dataProvider provide_fixtures_sized_by_width_and_height
	 */
	public function test_reads_width_and_height_attributes_as_floats( $name, $width, $height ) {
		$dimensions = $this->dimensions_of( $name );

		$this->assertSame( $width, $dimensions->width );
		$this->assertSame( $height, $dimensions->height );
	}

	public function provide_fixtures_sized_by_width_and_height() {
		return $this->cases(
			array(
				'width-and-height'                 => array( 100.0, 50.0 ),
				'width-and-height-in-px'           => array( 100.0, 50.0 ),
				'width-in-em-and-exponent-height'  => array( 10.5, 100.0 ),
				'width-and-height-with-spaces'     => array( 100.0, 50.0 ),
				'width-height-and-viewbox'         => array( 100.0, 50.0 ),
				'negative-width'                   => array( -5.0, 10.0 ),
				'no-namespace'                     => array( 7.0, 8.0 ),
				'root-is-not-svg'                  => array( 7.0, 8.0 ),
				'declaration-and-doctype'          => array( 7.0, 8.0 ),
				'width-from-entity'                => array( 70.0, 8.0 ),
			)
		);
	}

	/**
	 * The viewBox is used when width or height is missing, zero or a
	 * percentage; it then replaces both values.
	 *
	 * @dataProvider provide_fixtures_sized_by_viewbox
	 */
	public function test_falls_back_to_the_viewbox_for_both_values( $name, $width, $height ) {
		$dimensions = $this->dimensions_of( $name );

		$this->assertSame( $width, $dimensions->width );
		$this->assertSame( $height, $dimensions->height );
	}

	public function provide_fixtures_sized_by_viewbox() {
		return $this->cases(
			array(
				'viewbox-only'                          => array( 300.0, 200.0 ),
				'viewbox-with-decimals'                 => array( 30.5, 20.25 ),
				'viewbox-with-newline'                  => array( 300.0, 200.0 ),
				'viewbox-with-comma-and-space'          => array( 300.0, 200.0 ),
				'width-only-with-viewbox'               => array( 300.0, 200.0 ),
				'percent-width-and-height-with-viewbox' => array( 300.0, 200.0 ),
				'percent-width-with-viewbox'            => array( 300.0, 200.0 ),
				'zero-width-with-viewbox'               => array( 300.0, 200.0 ),
			)
		);
	}

	/**
	 * @dataProvider provide_fixtures_without_a_usable_size
	 */
	public function test_returns_zero_for_what_cannot_be_determined( $name, $width, $height ) {
		$dimensions = $this->dimensions_of( $name );

		$this->assertSame( $width, $dimensions->width );
		$this->assertSame( $height, $dimensions->height );
	}

	public function provide_fixtures_without_a_usable_size() {
		return $this->cases(
			array(
				'no-size'                         => array( 0, 0 ),
				'width-only'                      => array( 0, 0 ),
				'viewbox-with-three-values'       => array( 0, 0 ),
				'viewbox-attribute-in-lower-case' => array( 0, 0 ),
				'width-and-height-not-numbers'    => array( 0.0, 0.0 ),
				'percent-width-without-viewbox'   => array( 0, 50.0 ),
				'percent-width-with-spaces'       => array( 0, 10.0 ),
			)
		);
	}

	/**
	 * Pins the defect described in the svg_dimensions() docblock: the
	 * viewBox is split on single spaces only, so these valid forms of
	 * `0 0 300 200` give zero or shifted values.
	 *
	 * Fixed when every fixture gives 300 x 200; this test then fails and
	 * the fixtures move to provide_fixtures_sized_by_viewbox().
	 *
	 * @group pinned
	 * @dataProvider provide_fixtures_with_a_viewbox_that_is_misread
	 */
	public function test_viewbox_with_other_separators_is_misread_pinned_for_deferred_fix( $name, $width, $height ) {
		$dimensions = $this->dimensions_of( $name );

		$this->assertSame( $width, $dimensions->width );
		$this->assertSame( $height, $dimensions->height );
	}

	public function provide_fixtures_with_a_viewbox_that_is_misread() {
		return $this->cases(
			array(
				'viewbox-with-commas'        => array( 0, 0 ),
				'viewbox-with-tabs'          => array( 0, 0 ),
				'viewbox-with-double-spaces' => array( 0.0, 0.0 ),
				'viewbox-with-leading-space' => array( 0.0, 300.0 ),
			)
		);
	}

	/**
	 * Pins a defect described in the svg_dimensions() docblock: gzipped
	 * content is not decoded, so an `.svgz` file has no dimensions (and PHP
	 * raises parser warnings).
	 *
	 * Fixed when 100 x 50 is returned; this test then fails and becomes a
	 * regression test for that.
	 *
	 * @group pinned
	 */
	public function test_gzipped_file_has_zero_dimensions_pinned_for_deferred_fix() {
		$path       = $this->temporary_file( 'sized.svgz', gzencode( '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="50"/>' ) );
		$plugin     = $this->plugin();
		$dimensions = null;

		$warnings = $this->warnings_raised_by(
			function () use ( $plugin, $path, &$dimensions ) {
				$dimensions = $plugin->svg_dimensions( $path );
			}
		);

		$this->assertSame( 0, $dimensions->width );
		$this->assertSame( 0, $dimensions->height );
		$this->assertNotEmpty( $warnings );
	}

	/**
	 * @dataProvider provide_unreadable_content
	 */
	public function test_returns_zeros_and_raises_warnings_for_a_file_that_cannot_be_parsed( $content ) {
		$path       = $this->temporary_file( 'unreadable.svg', $content );
		$plugin     = $this->plugin();
		$dimensions = null;

		$warnings = $this->warnings_raised_by(
			function () use ( $plugin, $path, &$dimensions ) {
				$dimensions = $plugin->svg_dimensions( $path );
			}
		);

		$this->assertSame( 0, $dimensions->width );
		$this->assertSame( 0, $dimensions->height );
		$this->assertNotEmpty( $warnings );
		$this->assertStringContainsString( 'simplexml_load_file', $warnings[0] );
	}

	public function provide_unreadable_content() {
		return array(
			'truncated xml' => array( '<svg xmlns="http://www.w3.org/2000/svg" width="7"' ),
			'plain text'    => array( 'not an svg' ),
			'empty file'    => array( '' ),
			'missing file'  => array( null ),
		);
	}

	/**
	 * With libxml internal errors enabled, which the sanitizer leaves on
	 * after it has run, the same failure is silent.
	 */
	public function test_unparsable_file_raises_no_warning_while_libxml_internal_errors_are_enabled() {
		$path       = $this->temporary_file( 'unreadable.svg', '<svg' );
		$plugin     = $this->plugin();
		$dimensions = null;
		libxml_use_internal_errors( true );

		$warnings = $this->warnings_raised_by(
			function () use ( $plugin, $path, &$dimensions ) {
				$dimensions = $plugin->svg_dimensions( $path );
			}
		);

		$this->assertSame( array(), $warnings );
		$this->assertSame( 0, $dimensions->width );
	}

	/**
	 * An entity that points to a local file is not read.
	 */
	public function test_does_not_load_external_entities() {
		$secret = $this->temporary_file( 'secret.txt', '4242' );
		$path   = $this->temporary_file(
			'xxe.svg',
			'<!DOCTYPE svg [<!ENTITY size SYSTEM "file://' . $secret . '">]><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><desc>&size;</desc></svg>'
		);

		$dimensions = $this->plugin()->svg_dimensions( $path );

		$this->assertSame( 1.0, $dimensions->width );
		$this->assertSame( 1.0, $dimensions->height );
	}
}
