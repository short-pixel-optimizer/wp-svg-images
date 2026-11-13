<?php

use enshrined\svgSanitize\data\AllowedTags;
use enshrined\svgSanitize\data\TagInterface;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The library's default element whitelist.
 *
 * The sanitizer lower-cases this list and compares element names
 * case-insensitively, so the tests that look for a name do the same.
 */
class AllowedTagsTest extends TestCase {

	/**
	 * The whitelist as the sanitizer uses it: lower-cased.
	 *
	 * @return string[]
	 */
	private function lowercased_tags() {
		return array_map( 'strtolower', AllowedTags::getTags() );
	}

	public function test_implements_tag_interface() {
		$this->assertInstanceOf( TagInterface::class, new AllowedTags() );
	}

	public function test_returns_a_list_of_non_empty_strings() {
		$tags = AllowedTags::getTags();

		$this->assertIsArray( $tags );
		$this->assertNotEmpty( $tags );
		$this->assertSame( array_values( $tags ), $tags, 'Keys are sequential' );

		foreach ( $tags as $tag ) {
			$this->assertIsString( $tag );
			$this->assertNotSame( '', $tag );
			$this->assertSame( trim( $tag ), $tag, "No surrounding whitespace in '$tag'" );
		}
	}

	public function test_returns_the_same_list_on_every_call() {
		$this->assertSame( AllowedTags::getTags(), AllowedTags::getTags() );
	}

	/**
	 * @dataProvider provide_tags_every_svg_needs
	 */
	public function test_allows_tag( $tag ) {
		$this->assertContains( $tag, $this->lowercased_tags() );
	}

	public function provide_tags_every_svg_needs() {
		$tags = array(
			'svg',
			'g',
			'defs',
			'symbol',
			'use',
			'path',
			'rect',
			'circle',
			'ellipse',
			'line',
			'polyline',
			'polygon',
			'text',
			'tspan',
			'textpath',
			'title',
			'desc',
			'metadata',
			'image',
			'a',
			'style',
			'lineargradient',
			'radialgradient',
			'stop',
			'clippath',
			'mask',
			'pattern',
			'marker',
			'filter',
			'fegaussianblur',
			'animatetransform',
			'animatemotion',
			'font',
		);

		return array_combine( $tags, array_map( function ( $tag ) {
			return array( $tag );
		}, $tags ) );
	}

	/**
	 * The text node pseudo-tag is part of the list; without it the sanitizer
	 * would have no entry for character data.
	 */
	public function test_allows_text_nodes() {
		$this->assertContains( '#text', AllowedTags::getTags() );
	}

	/**
	 * Filter primitives are listed in their camelCase SVG spelling; they only
	 * match because the sanitizer lower-cases both sides.
	 */
	public function test_lists_filter_primitives_in_camel_case() {
		$tags = AllowedTags::getTags();

		$this->assertContains( 'feGaussianBlur', $tags );
		$this->assertNotContains( 'fegaussianblur', $tags );
	}

	/**
	 * @dataProvider provide_tags_that_can_run_script_or_embed_content
	 */
	public function test_does_not_allow_tag( $tag ) {
		$this->assertNotContains( $tag, $this->lowercased_tags() );
	}

	public function provide_tags_that_can_run_script_or_embed_content() {
		$tags = array(
			'script',
			'foreignobject',
			'iframe',
			'object',
			'embed',
			'handler',
			'listener',
			'animate',
			'set',
			'audio',
			'video',
			'canvas',
			'form',
			'input',
			'link',
			'meta',
			'base',
			'html',
			'body',
			'#comment',
			'#cdata-section',
		);

		return array_combine( $tags, array_map( function ( $tag ) {
			return array( $tag );
		}, $tags ) );
	}

	/**
	 * `font` and `image` are listed twice (once as HTML, once as SVG) and
	 * nothing else is. Harmless for the sanitizer, which only tests membership.
	 */
	public function test_only_font_and_image_are_listed_twice() {
		$counts     = array_count_values( $this->lowercased_tags() );
		$duplicates = array_keys( array_filter( $counts, function ( $count ) {
			return $count > 1;
		} ) );
		sort( $duplicates );

		$this->assertSame( array( 'font', 'image' ), $duplicates );
	}
}
