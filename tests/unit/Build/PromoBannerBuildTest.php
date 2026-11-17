<?php

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The shared modules as generated into build/shortpixel/: the files are read
 * as text, nothing is loaded.
 *
 * ShortPixel Image Optimizer bundles the same module as
 * `ShortPixel\PromoBanner` with `shortpixel/` hooks, and both plugins run on
 * one site, so nothing in this copy may keep the source names.
 */
class PromoBannerBuildTest extends TestCase {

	/**
	 * @param string $file Path relative to the plugin folder.
	 * @return string
	 */
	private function contents( $file ) {
		return (string) file_get_contents( WPSVG_TESTS_PLUGIN_DIR . $file );
	}

	/**
	 * @return string The bundled banner class.
	 */
	private function banner_source() {
		return $this->contents( 'build/shortpixel/promo-banner/src/PromoBanner.php' );
	}

	public function test_plugin_file_requires_the_generated_autoloader_once() {
		$this->assertSame( 1, substr_count( $this->contents( 'wp-svg-images.php' ), "require_once __DIR__ . '/build/shortpixel/autoload.php';" ) );
	}

	public function test_autoloader_and_loader_carry_the_plugins_namespace() {
		$this->assertStringContainsString( 'new ShortPixel\SVG\Build\PackageLoader()', $this->contents( 'build/shortpixel/autoload.php' ) );
		$this->assertStringContainsString( 'namespace ShortPixel\SVG\Build;', $this->contents( 'build/shortpixel/PackageLoader.php' ) );
	}

	/**
	 * The map the loader reads: only the module the plugin bundles.
	 */
	public function test_autoload_map_lists_the_promo_banner_only() {
		$map = json_decode( $this->contents( 'build/shortpixel/composer.json' ), true );

		$this->assertSame( array( 'ShortPixel\SVG\PromoBanner' => 'promo-banner/src' ), $map['autoload']['psr-4'] );
	}

	public function test_banner_class_carries_the_plugins_namespace_and_text_domain() {
		$source = $this->banner_source();

		$this->assertStringContainsString( 'namespace ShortPixel\SVG\PromoBanner;', $source );
		$this->assertStringNotContainsString( 'shortpixel-image-optimiser', $source, 'No string of the source text domain is left' );
		$this->assertSame( 10, preg_match_all( '/esc_(?:html|attr)__\(\'[^\']+\', \'wp-svg-images\'\)/', $source ) );
	}

	public function test_every_hook_of_the_banner_class_carries_the_plugins_prefix() {
		$source = $this->banner_source();

		$this->assertSame( 0, preg_match( '/(apply_filters|do_action|add_filter|add_action)\(\s*\'shortpixel\//', $source ) );
		foreach ( array( 'promo_banner/show', 'promo_banner/in_window', 'promo_banner/capability', 'promo_banner/url', 'settings/no_banner' ) as $hook ) {
			$this->assertStringContainsString( "apply_filters('wpsvg/" . $hook . "'", $source, $hook );
		}
	}

	/**
	 * @dataProvider provide_assets
	 */
	public function test_asset_the_banner_links_exists( $asset ) {
		$this->assertFileExists( WPSVG_TESTS_PLUGIN_DIR . 'build/shortpixel/promo-banner/assets/' . $asset );
	}

	public function provide_assets() {
		return array(
			'stylesheet'    => array( 'promo-banner.css' ),
			'script'        => array( 'promo-banner.js' ),
			'robot'         => array( 'img/robot.svg' ),
			'forever badge' => array( 'img/forever.png' ),
			'sale badge'    => array( 'img/black-friday-sale.png' ),
		);
	}

	/**
	 * uninstall.php runs without the plugin, so it repeats the prefix.
	 */
	public function test_uninstall_file_uses_the_prefix_of_the_plugin_class() {
		$this->assertSame( 1, preg_match( "/const PROMO_BANNER_PREFIX = '([a-z]+)';/", $this->contents( 'wp-svg-images.php' ), $match ) );

		$this->assertSame( 'wpsvg', $match[1] );
		$this->assertStringContainsString( "PromoBanner::uninstall( '" . $match[1] . "' );", $this->contents( 'uninstall.php' ) );
	}

	/**
	 * The build manifest and its local output are never shipped.
	 *
	 * @dataProvider provide_development_only_paths
	 */
	public function test_build_tooling_is_kept_out_of_the_release( $path ) {
		$this->assertContains( $path, array_map( 'trim', file( WPSVG_TESTS_PLUGIN_DIR . '.distignore' ) ) );
	}

	public function provide_development_only_paths() {
		return array(
			'manifest' => array( '/composer.json' ),
			'lock'     => array( '/composer.lock' ),
			'vendor'   => array( '/vendor' ),
		);
	}
}
