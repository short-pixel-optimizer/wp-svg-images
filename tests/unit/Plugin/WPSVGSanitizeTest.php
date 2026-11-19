<?php

require_once __DIR__ . '/WPSVGTestCase.php';

use Brain\Monkey\Filters;
use enshrined\svgSanitize\Sanitizer;

/**
 * WPSVG::sanitize(): sanitizes a file in place, plain or gzipped.
 *
 * Files are written to a temporary directory by each test.
 */
class WPSVGSanitizeTest extends WPSVGTestCase {

	const SVG_NS = 'xmlns="http://www.w3.org/2000/svg"';

	const DIRTY = "<?xml version=\"1.0\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\" onload=\"a()\">\n  <script>x</script>\n  <rect/>\n</svg>\n";

	const CLEANED = '<svg xmlns="http://www.w3.org/2000/svg"><rect></rect></svg>';

	public function test_rewrites_the_file_with_the_sanitized_one_line_svg() {
		$path = $this->temporary_file( 'dirty.svg', self::DIRTY );

		$this->assertTrue( $this->plugin()->sanitize( $path ) );
		$this->assertSame( self::CLEANED, file_get_contents( $path ) );
	}

	/**
	 * A file with nothing to remove is rewritten too, in the same format.
	 */
	public function test_rewrites_a_clean_file_in_the_same_format() {
		$path = $this->temporary_file( 'clean.svg', "<svg " . self::SVG_NS . ">\n  <rect/>\n</svg>\n" );

		$this->assertTrue( $this->plugin()->sanitize( $path ) );
		$this->assertSame( self::CLEANED, file_get_contents( $path ) );
	}

	/**
	 * The decision is made on the content, not on the file name.
	 */
	public function test_gzipped_content_is_decoded_sanitized_and_gzipped_again() {
		$path   = $this->temporary_file( 'dirty.svgz', gzencode( self::DIRTY ) );
		$plugin = $this->plugin();

		$this->assertTrue( $plugin->sanitize( $path ) );

		$written = file_get_contents( $path );
		$this->assertTrue( $plugin->is_gzipped( $written ) );
		$this->assertSame( self::CLEANED, gzdecode( $written ) );
	}

	public function test_plain_content_in_a_file_named_svgz_stays_plain() {
		$path = $this->temporary_file( 'plain.svgz', self::DIRTY );

		$this->assertTrue( $this->plugin()->sanitize( $path ) );
		$this->assertSame( self::CLEANED, file_get_contents( $path ) );
	}

	/**
	 * @dataProvider provide_content_that_is_not_well_formed
	 */
	public function test_returns_false_and_keeps_the_file_when_the_content_is_not_well_formed( $content ) {
		$path = $this->temporary_file( 'broken.svg', $content );

		$this->assertFalse( $this->plugin()->sanitize( $path ) );
		$this->assertSame( $content, file_get_contents( $path ) );
	}

	public function provide_content_that_is_not_well_formed() {
		return array(
			'truncated'            => array( '<svg' ),
			'plain text'           => array( 'not an svg' ),
			'unclosed element'     => array( '<svg ' . self::SVG_NS . '><rect></svg>' ),
			'gzip of broken xml'   => array( gzencode( '<svg' ) ),
		);
	}

	/**
	 * gzdecode() also raises a PHP warning for data it cannot decode.
	 */
	public function test_returns_false_and_keeps_the_file_when_gzip_content_cannot_be_decoded() {
		$content = "\x1f\x8b\x08 this is not a gzip stream";
		$path    = $this->temporary_file( 'corrupt.svgz', $content );
		$plugin  = $this->plugin();
		$result  = null;

		$warnings = $this->warnings_raised_by(
			function () use ( $plugin, $path, &$result ) {
				$result = $plugin->sanitize( $path );
			}
		);

		$this->assertFalse( $result );
		$this->assertSame( $content, file_get_contents( $path ) );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'gzdecode', $warnings[0] );
	}

	public function test_empty_file_is_reported_as_sanitized_and_stays_empty() {
		$path = $this->temporary_file( 'empty.svg', '' );

		$this->assertTrue( $this->plugin()->sanitize( $path ) );
		$this->assertSame( '', file_get_contents( $path ) );
	}

	/**
	 * Both whitelists are read through their filters on every call.
	 */
	public function test_whitelist_filters_are_applied_on_every_call() {
		Filters\expectApplied( 'WPSVG_setAllowedTags' )->twice()->andReturnFirstArg();
		Filters\expectApplied( 'WPSVG_setAllowedAttrs' )->twice()->andReturnFirstArg();
		$plugin = $this->plugin();

		$plugin->sanitize( $this->temporary_file( 'one.svg', self::DIRTY ) );
		$plugin->sanitize( $this->temporary_file( 'two.svg', self::DIRTY ) );
	}

	public function test_tag_added_through_the_filter_is_kept() {
		Filters\expectApplied( 'WPSVG_setAllowedTags' )->once()->andReturnUsing(
			function ( $tags ) {
				$tags[] = 'Custom';
				return $tags;
			}
		);
		$path = $this->temporary_file( 'custom.svg', '<svg ' . self::SVG_NS . '><custom/><other/></svg>' );

		$this->plugin()->sanitize( $path );

		$this->assertSame( '<svg ' . self::SVG_NS . '><custom></custom></svg>', file_get_contents( $path ) );
	}

	public function test_attribute_added_through_the_filter_is_kept() {
		Filters\expectApplied( 'WPSVG_setAllowedAttrs' )->once()->andReturnUsing(
			function ( $attributes ) {
				$attributes[] = 'Custom';
				return $attributes;
			}
		);
		$path = $this->temporary_file( 'custom.svg', '<svg ' . self::SVG_NS . '><rect custom="1" other="2"/></svg>' );

		$this->plugin()->sanitize( $path );

		$this->assertSame( '<svg ' . self::SVG_NS . '><rect custom="1"></rect></svg>', file_get_contents( $path ) );
	}

	public function test_tag_removed_through_the_filter_is_stripped() {
		Filters\expectApplied( 'WPSVG_setAllowedTags' )->once()->andReturn( array( 'svg', '#text' ) );
		$path = $this->temporary_file( 'strict.svg', '<svg ' . self::SVG_NS . '><rect/></svg>' );

		$this->plugin()->sanitize( $path );

		$this->assertSame( '<svg ' . self::SVG_NS . '></svg>', file_get_contents( $path ) );
	}

	/**
	 * Older copies of the library, loaded by other plugins, can throw.
	 *
	 * @dataProvider provide_throwables
	 */
	public function test_returns_false_and_keeps_the_file_when_the_sanitizer_throws( $throwable ) {
		$content           = '<svg ' . self::SVG_NS . '><rect/></svg>';
		$path              = $this->temporary_file( 'throws.svg', $content );
		$plugin            = $this->plugin();
		$plugin->sanitizer = new class( $throwable ) extends Sanitizer {
			private $throwable;

			public function __construct( $throwable ) {
				parent::__construct();
				$this->throwable = $throwable;
			}

			public function sanitize( $dirty ) {
				throw $this->throwable;
			}
		};

		$this->assertFalse( $plugin->sanitize( $path ) );
		$this->assertSame( $content, file_get_contents( $path ) );
	}

	public function provide_throwables() {
		return array(
			'exception' => array( new LogicException( 'Cannot add self usage' ) ),
			'error'     => array( new TypeError( 'type' ) ),
		);
	}

	/**
	 * @dataProvider provide_well_formed_xml_without_an_svg_root
	 */
	public function test_returns_false_and_keeps_the_file_when_the_root_is_not_svg( $content ) {
		$path = $this->temporary_file( 'page.svg', $content );

		$this->assertFalse( $this->plugin()->sanitize( $path ) );
		$this->assertSame( $content, file_get_contents( $path ) );
	}

	public function provide_well_formed_xml_without_an_svg_root() {
		return array(
			'html root'            => array( '<html><body/></html>' ),
			'xhtml page'           => array( '<html xmlns="http://www.w3.org/1999/xhtml"><script>x</script></html>' ),
			'svg in another root'  => array( '<x><svg ' . self::SVG_NS . '/></x>' ),
		);
	}

	public function test_use_referencing_its_own_parent_is_removed() {
		$path = $this->temporary_file( 'loop.svg', '<svg ' . self::SVG_NS . '><g id="a"><use href="#a"/></g></svg>' );

		$this->assertTrue( $this->plugin()->sanitize( $path ) );
		$this->assertSame( '<svg ' . self::SVG_NS . '><g id="a"></g></svg>', file_get_contents( $path ) );
	}

	public function test_cdata_section_is_written_as_text_escaped_once() {
		$path = $this->temporary_file( 'cdata.svg', '<svg ' . self::SVG_NS . '><style><![CDATA[ g > .a { fill: red; } ]]></style></svg>' );

		$this->assertTrue( $this->plugin()->sanitize( $path ) );
		$this->assertSame( '<svg ' . self::SVG_NS . '><style> g &gt; .a { fill: red; } </style></svg>', file_get_contents( $path ) );
	}

	/**
	 * The bundled sanitizer refuses a prefixed root (see SanitizerTest).
	 */
	public function test_returns_false_and_keeps_the_file_when_the_root_is_written_with_a_prefix() {
		$content = '<s:svg xmlns:s="http://www.w3.org/2000/svg"><s:rect/></s:svg>';
		$path    = $this->temporary_file( 'prefixed.svg', $content );

		$this->assertFalse( $this->plugin()->sanitize( $path ) );
		$this->assertSame( $content, file_get_contents( $path ) );
	}

	public function test_unsafe_animation_is_removed_from_the_written_file() {
		$path = $this->temporary_file( 'animated.svg', '<svg ' . self::SVG_NS . '><rect><animateTransform attributeName="x" values="0;1"/></rect></svg>' );

		$this->assertTrue( $this->plugin()->sanitize( $path ) );
		$this->assertSame( '<svg ' . self::SVG_NS . '><rect></rect></svg>', file_get_contents( $path ) );
	}

	public function test_gzipped_content_has_unsafe_animations_removed() {
		$path = $this->temporary_file( 'animated.svgz', gzencode( '<svg ' . self::SVG_NS . '><rect><animateTransform attributeName="x" values="0;1"/></rect></svg>' ) );

		$this->assertTrue( $this->plugin()->sanitize( $path ) );
		$this->assertSame( '<svg ' . self::SVG_NS . '><rect></rect></svg>', gzdecode( file_get_contents( $path ) ) );
	}

	/**
	 * Pins a defect described in the sanitize() docblock: a path that cannot
	 * be read raises a warning, then an empty file is created there and
	 * success is reported.
	 *
	 * Fixed when sanitize() returns false and creates nothing; this test
	 * then fails and becomes a regression test for that.
	 *
	 * @group pinned
	 */
	public function test_missing_file_is_created_empty_and_reported_as_sanitized_pinned_for_deferred_fix() {
		$path   = $this->temporary_file( 'missing.svg', null );
		$plugin = $this->plugin();
		$result = null;

		$warnings = $this->warnings_raised_by(
			function () use ( $plugin, $path, &$result ) {
				$result = $plugin->sanitize( $path );
			}
		);

		$this->assertTrue( $result );
		$this->assertSame( '', file_get_contents( $path ) );
		// The test tooling wraps file access and adds a warning of its own.
		$this->assertNotEmpty( $warnings );
		$this->assertStringContainsString( 'file_get_contents', end( $warnings ) );
	}
}
