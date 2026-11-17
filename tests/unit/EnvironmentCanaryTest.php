<?php

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Proves the `unit` suite runs on the requested runtime.
 *
 * Used by `bin/test.sh --verify`, which expects exactly one test to execute
 * here. Setting WPSVG_CANARY_FAIL=1 makes it fail on purpose, which --verify
 * uses to prove that failures reach the runner's exit code.
 */
class EnvironmentCanaryTest extends TestCase {

	/**
	 * The PHP version matches the one bin/test.sh selected, the extensions the
	 * plugin relies on are loaded, and the bundled sanitizer is present.
	 */
	public function test_unit_canary() {
		if ( '1' === getenv( 'WPSVG_CANARY_FAIL' ) ) {
			$this->fail( 'Deliberate failure requested through WPSVG_CANARY_FAIL.' );
		}

		$this->assertSame( getenv( 'WPSVG_EXPECTED_PHP' ), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'PHP version' );

		foreach ( array( 'fileinfo', 'dom', 'libxml', 'simplexml', 'zlib' ) as $extension ) {
			$this->assertTrue( extension_loaded( $extension ), "Extension $extension is loaded" );
		}

		$this->assertFileExists( WPSVG_TESTS_PLUGIN_DIR . 'wp-svg-images.php' );
		$this->assertFileExists( WPSVG_TESTS_PLUGIN_DIR . 'svg-sanitizer/Sanitizer.php' );
	}
}
