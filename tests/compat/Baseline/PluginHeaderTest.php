<?php

require_once dirname( __DIR__ ) . '/CompatTestCase.php';

/**
 * Git Updater: the plugin has no runtime code for it, only the two headers
 * Git Updater reads to find the update source.
 */
class PluginHeaderTest extends CompatTestCase {

	public function test_plugin_file_carries_the_headers_git_updater_reads() {
		$headers = get_file_data(
			WP_PLUGIN_DIR . '/wp-svg-images/wp-svg-images.php',
			array(
				'repository' => 'GitHub Plugin URI',
				'branch'     => 'Primary Branch',
			)
		);

		$this->assertSame( 'https://github.com/short-pixel-optimizer/wp-svg-images', $headers['repository'] );
		$this->assertSame( 'main', $headers['branch'] );
	}

	/**
	 * Git Updater registers its headers through `extra_plugin_headers`;
	 * WordPress then returns them with the plugin data.
	 */
	public function test_wordpress_returns_the_headers_once_they_are_registered() {
		add_filter(
			'extra_plugin_headers',
			static function ( $headers ) {
				return array_merge( $headers, array( 'GitHub Plugin URI', 'Primary Branch' ) );
			}
		);

		$data = get_plugin_data( WP_PLUGIN_DIR . '/wp-svg-images/wp-svg-images.php', false, false );

		$this->assertSame( 'https://github.com/short-pixel-optimizer/wp-svg-images', $data['GitHub Plugin URI'] );
		$this->assertSame( 'main', $data['Primary Branch'] );
		$this->assertSame( 'WP SVG Images', $data['Name'] );
	}
}
