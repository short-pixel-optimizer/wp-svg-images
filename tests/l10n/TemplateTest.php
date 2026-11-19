<?php

require_once __DIR__ . '/TranslatableStrings.php';

/**
 * The translation template languages/wp-svg-images.pot against the plugin's sources:
 * every translatable string has an entry, and every entry is still in use.
 *
 * No WordPress function is needed here; the files are read as text.
 */
class TemplateTest extends WP_UnitTestCase {

	const DOMAIN = 'wp-svg-images';

	/** Translation template, relative to the plugin folder. */
	const TEMPLATE = 'languages/wp-svg-images.pot';

	/** The bundled promo banner, whose strings the build gives the plugin's text domain. */
	const BANNER = 'build/shortpixel/promo-banner/src/PromoBanner.php';

	/** PHP files of the plugin that contain translatable strings. */
	const SOURCES = array( 'wp-svg-images.php', 'upsell.php', self::BANNER );

	/**
	 * @return string Plugin folder with trailing slash.
	 */
	private function plugin_dir() {
		return WP_PLUGIN_DIR . '/wp-svg-images/';
	}

	/**
	 * @return array[] Every translation call in the plugin, with its `file`.
	 */
	private function calls() {
		$calls = array();
		foreach ( self::SOURCES as $file ) {
			foreach ( TranslatableStrings::in_file( $this->plugin_dir() . $file ) as $call ) {
				$calls[] = $call + array( 'file' => $file );
			}
		}

		return $calls;
	}

	/**
	 * @return string[] Distinct translatable strings of the plugin's code.
	 */
	private function code_strings() {
		return array_values( array_unique( array_column( $this->calls(), 'text' ) ) );
	}

	/**
	 * @return string[] Message IDs of the template.
	 */
	private function template_strings() {
		return TranslatableStrings::in_template( $this->plugin_dir() . self::TEMPLATE );
	}

	/**
	 * @param string $header Header name.
	 * @return string Value of that header in the plugin file.
	 */
	private function plugin_header( $header ) {
		$headers = get_file_data( $this->plugin_dir() . 'wp-svg-images.php', array( 'value' => $header ) );

		return $headers['value'];
	}

	/* The extractor itself. */

	public function test_extractor_finds_translation_calls_and_skips_look_alikes() {
		$file = tempnam( get_temp_dir(), 'wp-svg-images' );
		file_put_contents(
			$file,
			'<?php
			echo __( \'Plain\', \'wp-svg-images\' );
			esc_html_e( "Double \"quoted\"", "wp-svg-images" );
			printf( esc_html__( \'It\\\'s %s\', \'wp-svg-images\' ), __( \'nested\', \'other\' ) );
			_x( \'Post\', \'verb\', \'wp-svg-images\' );
			__( $variable, \'wp-svg-images\' );
			__( \'No domain\' );
			$object->__( \'method\', \'wp-svg-images\' );
			// __( \'comment\', \'wp-svg-images\' );
			function __() {}'
		);

		$calls = TranslatableStrings::in_file( $file );
		unlink( $file );

		$this->assertSame(
			array(
				array( '__', 'Plain', 'wp-svg-images' ),
				array( 'esc_html_e', 'Double "quoted"', 'wp-svg-images' ),
				array( 'esc_html__', "It's %s", 'wp-svg-images' ),
				array( '__', 'nested', 'other' ),
				array( '_x', 'Post', 'wp-svg-images' ),
				array( '__', null, 'wp-svg-images' ),
				array( '__', 'No domain', null ),
			),
			array_map(
				static function ( $call ) {
					return array( $call['function'], $call['text'], $call['domain'] );
				},
				$calls
			)
		);
	}

	public function test_extractor_reads_template_entries_including_wrapped_and_escaped_ones() {
		$file = tempnam( get_temp_dir(), 'wpsvg' );
		file_put_contents( $file, "msgid \"\"\nmsgstr \"\"\n\"Header: x\\n\"\n\n#: a.php:1\nmsgid \"One\"\nmsgstr \"\"\n\nmsgid \"\"\n\"Two \"\n\"lines with \\\"quotes\\\"\"\nmsgstr \"\"\n" );

		$ids = TranslatableStrings::in_template( $file );
		unlink( $file );

		$this->assertSame( array( 'One', 'Two lines with "quotes"' ), $ids );
	}

	/* The plugin's strings. */

	public function test_plugin_has_translatable_strings_in_every_source_file() {
		$files = array_count_values( array_column( $this->calls(), 'file' ) );

		$this->assertGreaterThan( 15, $files['wp-svg-images.php'] );
		$this->assertGreaterThan( 3, $files['upsell.php'] );
		$this->assertSame( 10, $files[ self::BANNER ] );
	}

	public function test_every_translation_call_uses_a_plain_string_and_the_plugins_text_domain() {
		$wrong = array();
		foreach ( $this->calls() as $call ) {
			if ( null === $call['text'] || self::DOMAIN !== $call['domain'] ) {
				$wrong[] = $call['file'] . ':' . $call['line'] . ' ' . $call['function'] . '()';
			}
		}

		$this->assertSame( array(), $wrong );
	}

	/**
	 * wordpress.org builds language packs only for strings whose domain is
	 * the plugin's slug, which is also its folder name.
	 */
	public function test_text_domain_header_names_the_domain_the_code_uses() {
		$this->assertSame( self::DOMAIN, basename( $this->plugin_dir() ), 'The domain is the plugin slug' );
		$this->assertSame( self::DOMAIN, $this->plugin_header( 'Text Domain' ) );
		$this->assertSame( '/languages', $this->plugin_header( 'Domain Path' ) );
		$this->assertFileExists( $this->plugin_dir() . self::TEMPLATE );
		// WP-CLI ends every header line with an escaped line break.
		$this->assertMatchesRegularExpression( '/^"X-Domain: wp-svg-images(\\\\n)?"$/m', file_get_contents( $this->plugin_dir() . self::TEMPLATE ) );
	}

	public function test_template_lists_no_entry_twice() {
		$template = $this->template_strings();

		$this->assertSame( array(), array_values( array_diff_assoc( $template, array_unique( $template ) ) ) );
	}

	public function test_template_has_the_plugin_description_from_the_header() {
		$this->assertContains( $this->plugin_header( 'Description' ), $this->template_strings() );
	}

	public function test_every_string_of_the_code_is_in_the_template() {
		$this->assertSame( array(), array_values( array_diff( $this->code_strings(), $this->template_strings() ) ) );
	}

	/**
	 * Besides the code, the template holds the plugin headers WP-CLI
	 * extracts, spelled as in the header so their translations are used.
	 */
	public function test_every_entry_of_the_template_is_in_use() {
		$headers = array();
		foreach ( array( 'Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI' ) as $header ) {
			$headers[] = $this->plugin_header( $header );
		}

		$unused = array_values( array_diff( $this->template_strings(), array_merge( $this->code_strings(), $headers ) ) );

		$this->assertSame( 'WP SVG Images', $this->plugin_header( 'Plugin Name' ) );
		$this->assertContains( 'WP SVG Images', $this->template_strings() );
		$this->assertSame( array(), $unused );
	}

	/**
	 * Translators need to know what each placeholder stands for; the
	 * comments come from `translators:` comments in the code.
	 */
	public function test_every_entry_with_placeholders_has_a_translators_comment() {
		$entries = preg_split( '/\n\s*\n/', file_get_contents( $this->plugin_dir() . self::TEMPLATE ) );

		$without_comment = array();
		foreach ( $entries as $entry ) {
			if ( false !== strpos( $entry, '#, php-format' ) && false === strpos( $entry, '#. translators:' ) ) {
				$without_comment[] = $entry;
			}
		}

		$this->assertGreaterThan( 0, substr_count( implode( "\n", $entries ), '#, php-format' ) );
		$this->assertSame( array(), $without_comment );
	}

	/* Release metadata that has to stay in step. */

	public function test_plugin_version_equals_the_stable_tag_of_the_readme() {
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)\s*$/mi', file_get_contents( $this->plugin_dir() . 'readme.txt' ), $match ) );

		$this->assertSame( $this->plugin_header( 'Version' ), $match[1] );
	}
}
