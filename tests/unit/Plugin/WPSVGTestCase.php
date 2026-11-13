<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Loads the plugin file without WordPress and gives each test a fresh WPSVG.
 *
 * WordPress functions are provided by Brain Monkey. The plugin file creates
 * an instance and registers its activation hook as soon as it is loaded, so
 * it is loaded here, once, with Brain Monkey already set up.
 */
abstract class WPSVGTestCase extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Arguments the plugin file passed to register_activation_hook() when it
	 * was loaded: the plugin file and the callback.
	 *
	 * @var array|null
	 */
	protected static $activation_hook;

	/**
	 * Files and directories to delete after the test.
	 *
	 * @var string[]
	 */
	private $temporary_paths = array();

	protected function set_up() {
		parent::set_up();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		if ( ! class_exists( 'WPSVG', false ) ) {
			if ( ! defined( 'ABSPATH' ) ) {
				define( 'ABSPATH', WPSVG_TESTS_PLUGIN_DIR );
			}
			Functions\when( 'register_activation_hook' )->alias(
				function ( $file, $callback ) {
					self::$activation_hook = array( $file, $callback );
				}
			);
			require_once WPSVG_TESTS_PLUGIN_DIR . 'wp-svg-images.php';
		}
	}

	protected function tear_down() {
		foreach ( array_reverse( $this->temporary_paths ) as $path ) {
			if ( is_dir( $path ) ) {
				rmdir( $path );
			} elseif ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		// The sanitizer leaves libxml's internal error handling switched on.
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * @return WPSVG A new instance; its hooks are registered with Brain Monkey.
	 */
	protected function plugin() {
		return new WPSVG();
	}

	/**
	 * Writes a file in a temporary directory that is removed after the test.
	 *
	 * @param string      $name    File name.
	 * @param string|null $content Content, or null to only reserve the path.
	 * @return string Absolute path.
	 */
	protected function temporary_file( $name, $content ) {
		$directory = sys_get_temp_dir() . '/wpsvg-' . uniqid( '', true );
		mkdir( $directory );
		$path = $directory . '/' . $name;
		if ( null !== $content ) {
			file_put_contents( $path, $content );
		}

		$this->temporary_paths[] = $directory;
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Runs $callback and returns the PHP warnings it raised.
	 *
	 * @param callable $callback
	 * @return string[] Warning messages.
	 */
	protected function warnings_raised_by( callable $callback ) {
		$warnings = array();
		set_error_handler(
			function ( $number, $message ) use ( &$warnings ) {
				$warnings[] = $message;
				return true;
			},
			E_WARNING
		);
		try {
			$callback();
		} finally {
			restore_error_handler();
		}

		return $warnings;
	}
}
