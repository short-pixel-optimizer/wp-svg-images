<?php

/**
 * Shared helpers for the integration tests: the plugin instance WordPress
 * created, per-role permissions, users and upload-like temporary files.
 */
abstract class WPSVGIntegrationTestCase extends WP_UnitTestCase {

	const SVG_NS = 'xmlns="http://www.w3.org/2000/svg"';

	/** An SVG that sanitization changes: a handler, a script and a shape. */
	const DIRTY_SVG = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><rect width="1" height="1"/></svg>';

	/** What the plugin makes of DIRTY_SVG. */
	const SANITIZED_SVG = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"></rect></svg>';

	/** A one-pixel PNG, base64-encoded. */
	const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/**
	 * Temporary files to delete after the test.
	 *
	 * @var string[]
	 */
	private $temporary_files = array();

	public function set_up() {
		parent::set_up();
		// The plugin reads its settings on `init`, which ran before the test
		// and before the database was reset.
		$this->plugin()->plugin_init();
	}

	public function tear_down() {
		foreach ( $this->temporary_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->temporary_files = array();

		// The sanitizer leaves libxml's internal error handling switched on.
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		parent::tear_down();
	}

	/**
	 * @return WPSVG[] Every instance hooked to `upload_mimes`.
	 */
	protected function plugin_instances() {
		$instances = array();
		foreach ( $GLOBALS['wp_filter']['upload_mimes']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WPSVG ) {
					$instances[] = $callback['function'][0];
				}
			}
		}

		return $instances;
	}

	/**
	 * @return WPSVG The instance the plugin file created when WordPress loaded it.
	 */
	protected function plugin() {
		$instances = $this->plugin_instances();
		$this->assertNotEmpty( $instances, 'The plugin is hooked to upload_mimes' );

		return $instances[0];
	}

	/**
	 * Saves the per-role permissions and makes the plugin read them again.
	 *
	 * @param array|null $permissions Role slug => 0, 1 or 2; null deletes the
	 *                                option, as on a fresh install.
	 */
	protected function set_permissions( $permissions ) {
		if ( null === $permissions ) {
			delete_option( 'wpsvg_settings' );
		} else {
			$settings = array();
			foreach ( $permissions as $role => $value ) {
				$settings[ 'role_' . $role ] = $value;
			}
			update_option( 'wpsvg_settings', $settings );
		}
		$this->plugin()->plugin_init();
	}

	/**
	 * Creates a user and makes it the current user.
	 *
	 * @param string   $role        Role slug.
	 * @param string[] $extra_roles More roles to add to the same user.
	 * @return WP_User
	 */
	protected function log_in_as( $role, array $extra_roles = array() ) {
		$user = self::factory()->user->create_and_get( array( 'role' => $role ) );
		foreach ( $extra_roles as $extra_role ) {
			$user->add_role( $extra_role );
		}
		wp_set_current_user( $user->ID );

		return $user;
	}

	/**
	 * Writes content to a temporary file that is deleted after the test.
	 *
	 * @param string $content
	 * @return string Absolute path.
	 */
	protected function temporary_file( $content ) {
		$path = tempnam( get_temp_dir(), 'wpsvg' );
		file_put_contents( $path, $content );
		$this->temporary_files[] = $path;

		return $path;
	}

	/**
	 * Builds one entry of `$_FILES` for content written to a temporary file.
	 *
	 * @param string $content File content.
	 * @param string $name    Client file name.
	 * @param string $type    Client-supplied MIME type.
	 * @return array
	 */
	protected function uploaded_file( $content, $name = 'image.svg', $type = 'image/svg+xml' ) {
		return array(
			'name'     => $name,
			'type'     => $type,
			'tmp_name' => $this->temporary_file( $content ),
			'error'    => 0,
			'size'     => strlen( $content ),
		);
	}

	/**
	 * @param string $inner Markup inside the root element.
	 * @return string An SVG document without XML declaration.
	 */
	protected function svg( $inner ) {
		return '<svg ' . self::SVG_NS . ">$inner</svg>";
	}
}
