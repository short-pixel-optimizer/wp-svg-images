<?php

require_once __DIR__ . '/SanitizerTestCase.php';

use enshrined\svgSanitize\Sanitizer;

/**
 * Sanitizer against the fixture files in tests/fixtures/svg/: every clean
 * file comes out unchanged, every malicious file comes out harmless and
 * every malformed file is refused.
 *
 * A file added to one of the three directories is picked up automatically.
 */
class SanitizerCorpusTest extends SanitizerTestCase {

	/**
	 * Strings that must not appear in the output of any malicious fixture,
	 * compared in lower case.
	 */
	const FORBIDDEN = array(
		'<script',
		'javascript:',
		'onload',
		'onclick',
		'foreignobject',
		'<iframe',
		'<set',
		'<animate',
		'<font',
		'<!doctype',
		'<!entity',
		'<?',
		'system(',
		'data:text/html',
		'data:image/svg',
		'evil.example',
		'passwd',
		'alert(',
	);

	/**
	 * @param string $directory Sub-directory of tests/fixtures/svg/.
	 * @return array[] One case per file, keyed by file name.
	 */
	private function fixtures( $directory ) {
		$cases = array();
		foreach ( glob( WPSVG_TESTS_FIXTURES_DIR . "svg/$directory/*.svg" ) as $path ) {
			$cases[ basename( $path ) ] = array( $path );
		}

		return $cases;
	}

	/**
	 * @param string $xml
	 * @return string Canonical form with insignificant whitespace dropped.
	 */
	private function canonical( $xml ) {
		$document = new DOMDocument();
		$this->assertTrue( $document->loadXML( $xml, LIBXML_NOBLANKS ) );

		return $document->C14N();
	}

	public function provide_clean_fixtures() {
		return $this->fixtures( 'clean' );
	}

	public function provide_malicious_fixtures() {
		return $this->fixtures( 'malicious' );
	}

	public function provide_malformed_fixtures() {
		return $this->fixtures( 'malformed' );
	}

	/**
	 * Guards the three providers: an empty directory would make the tests
	 * below vanish instead of fail.
	 */
	public function test_every_fixture_directory_has_files() {
		$this->assertGreaterThanOrEqual( 8, count( $this->fixtures( 'clean' ) ) );
		$this->assertGreaterThanOrEqual( 24, count( $this->fixtures( 'malicious' ) ) );
		$this->assertGreaterThanOrEqual( 7, count( $this->fixtures( 'malformed' ) ) );
	}

	/**
	 * @dataProvider provide_clean_fixtures
	 */
	public function test_clean_fixture_is_returned_unchanged( $path ) {
		$input     = file_get_contents( $path );
		$sanitizer = new Sanitizer();

		$output = $sanitizer->sanitize( $input );

		$this->assertIsString( $output );
		$this->assertSame( array(), $sanitizer->getXmlIssues() );
		$this->assertSame( $this->canonical( $input ), $this->canonical( $output ) );
	}

	/**
	 * @dataProvider provide_malicious_fixtures
	 */
	public function test_malicious_fixture_is_made_harmless( $path ) {
		$sanitizer = $this->plugin_sanitizer();

		$output = $sanitizer->sanitize( file_get_contents( $path ) );

		$this->assertIsString( $output );
		$this->assertStringStartsWith( '<svg ' . self::SVG_NS, $output );
		foreach ( self::FORBIDDEN as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, strtolower( $output ), basename( $path ) );
		}

		// Sanitizing the result again finds nothing left to remove.
		$this->assertSame( $output, $sanitizer->sanitize( $output ) );
		$this->assertSame( array(), $sanitizer->getXmlIssues() );
	}

	/**
	 * @dataProvider provide_malformed_fixtures
	 */
	public function test_malformed_fixture_is_refused( $path ) {
		$this->assertFalse( ( new Sanitizer() )->sanitize( file_get_contents( $path ) ) );
	}
}
