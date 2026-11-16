<?php

require_once dirname( __DIR__ ) . '/integration/WPSVGIntegrationTestCase.php';

/**
 * Base of the `compat` tests: the integration helpers plus the scenario the
 * run was started for.
 *
 * When a third-party plugin of the scenario does not support the running
 * WordPress or PHP version, the bootstrap does not load it and every test is
 * skipped with that reason.
 */
abstract class CompatTestCase extends WPSVGIntegrationTestCase {

	/** A style element whose rule is wrapped in a CDATA section. */
	const CDATA_STYLE = '<style><![CDATA[rect{fill:red}]]></style>';

	public function set_up() {
		parent::set_up();
		if ( '' !== WPSVG_COMPAT_SKIP_REASON ) {
			$this->markTestSkipped( 'Scenario ' . WPSVG_COMPAT_SCENARIO . ': ' . WPSVG_COMPAT_SKIP_REASON );
		}
	}

	/**
	 * @return string Name of the running scenario.
	 */
	protected function scenario() {
		return WPSVG_COMPAT_SCENARIO;
	}

	/**
	 * @return string[] Active plugins in the order WordPress loaded them.
	 */
	protected function active_plugins() {
		return explode( ',', WPSVG_COMPAT_ACTIVE_PLUGINS );
	}

	/**
	 * @param string $class Class name.
	 * @return string Path of the file that declares the class, relative to
	 *                the plugins directory when it is inside it.
	 */
	protected function file_of_class( $class ) {
		$file = ( new ReflectionClass( $class ) )->getFileName();

		return 0 === strpos( $file, WP_PLUGIN_DIR . '/' ) ? substr( $file, strlen( WP_PLUGIN_DIR ) + 1 ) : $file;
	}

	/**
	 * @return string[] Folders, relative to the plugins directory, that the
	 *                  loaded classes and interfaces of the sanitizer library
	 *                  come from. More than one entry means mixed copies.
	 */
	protected function sanitizer_copies() {
		$copies = array();
		foreach ( array_merge( get_declared_classes(), get_declared_interfaces() ) as $name ) {
			if ( 0 !== strpos( $name, 'enshrined\\svgSanitize\\' ) ) {
				continue;
			}
			$relative = substr( $name, strlen( 'enshrined\\svgSanitize\\' ) );
			$file     = $this->file_of_class( $name );
			$copies[] = substr( $file, 0, -strlen( '/' . str_replace( '\\', '/', $relative ) . '.php' ) );
		}
		$copies = array_values( array_unique( $copies ) );
		sort( $copies );

		return $copies;
	}

	/**
	 * Uploads through the media modal path and collects PHP warnings.
	 *
	 * @param string $content File content.
	 * @param string $name    Client file name.
	 * @return int|WP_Error Attachment ID.
	 */
	protected function quiet_media_upload( $content, $name = 'image.svg' ) {
		list( $result ) = $this->with_warnings_captured(
			function () use ( $content, $name ) {
				return $this->media_upload( $content, $name );
			}
		);

		return $result;
	}
}
