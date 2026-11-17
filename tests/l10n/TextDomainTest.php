<?php

require_once dirname( __DIR__ ) . '/integration/WPSVGIntegrationTestCase.php';

/**
 * Loading translations: where WordPress looks for the plugin's translation
 * file on a site in another language, and what a translation changes.
 *
 * The plugin folder is read-only in the test stack, so the translation file
 * WordPress asks for is answered with one the test generates elsewhere.
 */
class TextDomainTest extends WPSVGIntegrationTestCase {

	const LOCALE = 'xx_XX';

	/**
	 * Translation files WordPress asked for, as "domain path".
	 *
	 * @var ArrayObject
	 */
	private $requested;

	/**
	 * Generated translation files to delete after the test.
	 *
	 * @var string[]
	 */
	private $generated_files = array();

	public function tear_down() {
		unload_textdomain( 'wpsvg', true );
		// WordPress remembers the folder a translation was loaded from, which
		// here is the temporary folder of the generated file.
		$GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry();
		array_map( 'unlink', array_filter( $this->generated_files, 'file_exists' ) );
		$this->generated_files = array();
		$this->plugin()->spio_installed    = false;
		$this->plugin()->spio_active       = false;
		$this->plugin()->plugin_admin_page = null;
		parent::tear_down();
	}

	/**
	 * @return string The file WordPress has to look for.
	 */
	private function expected_file() {
		return WP_PLUGIN_DIR . '/wp-svg-images/languages/wpsvg-' . self::LOCALE . '.mo';
	}

	/**
	 * Switches the site to the test locale and makes the plugin load its
	 * text domain again, as on a request to a site in that language.
	 *
	 * @param array $translations Original => translation; empty installs no
	 *                            translation file.
	 */
	private function use_test_locale( array $translations = array() ) {
		$replacement = null;
		if ( $translations ) {
			require_once ABSPATH . WPINC . '/pomo/mo.php';
			$mo = new MO();
			foreach ( $translations as $original => $translation ) {
				$mo->add_entry(
					array(
						'singular'     => $original,
						'translations' => array( $translation ),
					)
				);
			}
			// WordPress picks the file reader by extension.
			$replacement = $this->temporary_file( '' ) . '.mo';
			$mo->export_to_file( $replacement );
			$this->generated_files[] = $replacement;
		}

		$requested       = new ArrayObject();
		$this->requested = $requested;
		$expected        = $this->expected_file();
		add_filter(
			'locale',
			static function () {
				return TextDomainTest::LOCALE;
			}
		);
		add_filter(
			'load_textdomain_mofile',
			static function ( $file, $domain ) use ( $requested, $expected, $replacement ) {
				$requested[] = "$domain $file";

				return ( $file === $expected && null !== $replacement ) ? $replacement : $file;
			},
			10,
			2
		);

		unload_textdomain( 'wpsvg', true );
		$this->plugin()->plugins_loaded();
	}

	public function test_plugin_loads_its_text_domain_on_plugins_loaded() {
		$this->assertSame( 10, has_action( 'plugins_loaded', array( $this->plugin(), 'plugins_loaded' ) ) );
	}

	public function test_wordpress_looks_for_the_translation_in_the_plugins_languages_folder() {
		$this->use_test_locale();

		$text = __( 'Settings saved.', 'wpsvg' );

		$this->assertContains( 'wpsvg ' . $this->expected_file(), $this->requested->getArrayCopy() );
		$this->assertSame( 'Settings saved.', $text, 'Without a file the original text is used' );
	}

	public function test_plugin_ships_the_template_but_no_translation() {
		$files = array_map( 'basename', glob( WP_PLUGIN_DIR . '/wp-svg-images/languages/*' ) );

		$this->assertSame( array( 'wpsvg.pot' ), $files );
	}

	public function test_translation_file_translates_the_plugins_strings() {
		$this->use_test_locale(
			array(
				'Settings saved.' => 'Saved in xx.',
				'SVG images'      => 'SVG pictures xx',
			)
		);

		$this->assertSame( 'Saved in xx.', __( 'Settings saved.', 'wpsvg' ) );
		$this->assertSame( 'SVG pictures xx', __( 'SVG images', 'wpsvg' ) );
		$this->assertSame( 'Save', __( 'Save', 'wpsvg' ), 'A string without translation stays as it is' );
		$this->assertTrue( is_textdomain_loaded( 'wpsvg' ) );
	}

	public function test_translation_does_not_leak_into_other_text_domains() {
		$this->use_test_locale( array( 'Settings' => 'Settings xx' ) );

		$this->assertSame( 'Settings xx', __( 'Settings', 'wpsvg' ) );
		$this->assertSame( 'Settings', __( 'Settings', 'default' ) );
	}

	public function test_settings_page_and_notice_are_printed_in_the_site_language() {
		$this->use_test_locale(
			array(
				'SVG images'                  => 'SVG pictures xx',
				'Security notice'             => 'Security notice xx',
				'enable sanitized SVG upload' => 'sanitized xx',
				'Save'                        => 'Save xx',
				'Go to Settings'              => 'To the settings xx',
			)
		);
		$this->plugin()->spio_installed = true;
		$this->plugin()->spio_active    = true;
		$this->log_in_as( 'administrator' );
		do_action( 'admin_menu' );

		set_current_screen( 'plugins' );
		ob_start();
		$this->plugin()->admin_notices();
		$notice = ob_get_clean();
		set_current_screen( $this->plugin()->plugin_admin_page );
		ob_start();
		do_action( $this->plugin()->plugin_admin_page );
		$page = ob_get_clean();

		$this->assertStringContainsString( 'class="button button-primary">To the settings xx</a>', $notice );
		$this->assertStringContainsString( '<h2>SVG pictures xx</h2>', $page );
		$this->assertStringContainsString( '<h3>Security notice xx</h3>', $page );
		$this->assertStringContainsString( 'sanitized xx', $page );
		$this->assertStringContainsString( 'value="Save xx"', $page );
	}

	public function test_upload_error_is_reported_in_the_site_language() {
		$this->use_test_locale( array( 'This SVG cannot be sanitized!' => 'Not sanitizable xx' ) );
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$result = $this->media_upload( $this->svg( '<rect>' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'Not sanitizable xx', $result->get_error_message() );
	}
}
