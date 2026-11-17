<?php

/**
 * The plugin on the command line: activation and removal through WP-CLI, and
 * media imports, where there is no current user unless `--user` names one.
 *
 * Imports run tests/cli/scripts/import-media.php, which does what
 * `wp media import` does with a local file; see that script for the reason.
 */
class CliTest extends CliTestCase {

	/**
	 * @param string      $path Absolute path of the file to import.
	 * @param string|null $user Login for `--user`; null runs without a user.
	 * @return array{id?: int, error?: string, stderr: string}
	 */
	private function import( $path, $user = null ) {
		$arguments = array( 'eval-file', __DIR__ . '/scripts/import-media.php', $path );
		if ( null !== $user ) {
			$arguments[] = "--user=$user";
		}
		$result = $this->wp( ...$arguments );
		$this->assertSame( 0, $result['code'], $result['stderr'] );
		$decoded = json_decode( $result['stdout'], true );
		$this->assertIsArray( $decoded, $result['stdout'] );

		return $decoded + array( 'stderr' => $result['stderr'] );
	}

	/**
	 * @return string[] Files below the uploads folder, relative to it.
	 */
	private function uploaded_files() {
		if ( ! is_dir( $this->uploads_dir() ) ) {
			return array();
		}
		$files = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->uploads_dir(), FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$files[] = substr( $file->getPathname(), strlen( $this->uploads_dir() ) + 1 );
		}
		sort( $files );

		return $files;
	}

	/* Activation. */

	public function test_wp_cli_sees_the_plugin_installed_and_inactive_on_a_new_site() {
		$this->assertSame( 'inactive', $this->wp_ok( 'plugin', 'get', self::PLUGIN, '--field=status' ) );
		$this->assertSame( 'WP SVG Images', $this->wp_ok( 'plugin', 'get', self::PLUGIN, '--field=title' ) );
	}

	public function test_activation_stores_the_redirect_flag_and_no_settings() {
		$output = $this->wp_ok( 'plugin', 'activate', self::PLUGIN );

		$this->assertStringContainsString( 'Success: Activated 1 of 1 plugins.', $output );
		$this->assertSame( 'active', $this->wp_ok( 'plugin', 'get', self::PLUGIN, '--field=status' ) );
		$this->assertSame( '1', $this->wp_ok( 'option', 'get', 'WPSVGActivated' ) );
		$missing = $this->wp( 'option', 'get', 'wpsvg_settings' );
		$this->assertSame( 1, $missing['code'] );
		$this->assertStringContainsString( "Could not get 'wpsvg_settings' option", $missing['stderr'] );
	}

	/**
	 * WP-CLI does not fire `admin_init`, so nothing consumes the flag: it
	 * waits for the first request to the dashboard.
	 */
	public function test_commands_run_cleanly_with_the_plugin_active_and_leave_the_redirect_flag() {
		$this->activate_plugin( array( 'administrator' => 1 ) );

		$state = json_decode( $this->wp_ok( 'eval', 'echo wp_json_encode( array( did_action( "admin_init" ), is_admin(), function_exists( "svg_thumbs" ), get_current_user_id() ) );' ), true );

		$this->assertSame( array( 0, false, false, 0 ), $state );
		$this->assertSame( '1', $this->wp_ok( 'option', 'get', 'WPSVGActivated' ) );
	}

	/* Imports. */

	public function test_import_without_a_user_is_refused_whatever_the_settings() {
		$this->activate_plugin(
			array(
				'administrator' => 2,
				'editor'        => 2,
				'author'        => 2,
			)
		);

		$result = $this->import( $this->file_to_import( self::DIRTY_SVG ) );

		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $result['error'] );
		$this->assertSame( 0, $this->attachment_count() );
		$this->assertSame( array(), $this->uploaded_files() );
	}

	/**
	 * @dataProvider provide_users_who_may_not_import
	 */
	public function test_import_is_refused_for_a_user_without_permission( $permissions, $role ) {
		$this->activate_plugin( $permissions );
		$this->create_user( 'someone', $role );

		$result = $this->import( $this->file_to_import( self::DIRTY_SVG ), 'someone' );

		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $result['error'] );
		$this->assertSame( 0, $this->attachment_count() );
		$this->assertSame( array(), $this->uploaded_files() );
	}

	public function provide_users_who_may_not_import() {
		return array(
			'role disabled'                        => array( array( 'editor' => 0 ), 'editor' ),
			'role not in the settings'             => array( array( 'author' => 1 ), 'editor' ),
			'contributor before a settings save'   => array( null, 'contributor' ),
		);
	}

	public function test_import_before_a_settings_save_stores_a_sanitized_svg() {
		$this->activate_plugin( null );

		$result = $this->import( $this->file_to_import( self::DIRTY_SVG ), 'admin' );

		$this->assertSame( '', $result['stderr'] );
		$this->assertSame( 'image/svg+xml', $this->wp_ok( 'post', 'get', (string) $result['id'], '--field=post_mime_type' ) );
		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $result['id'] ) );
	}

	public function test_import_as_an_unrestricted_user_stores_the_file_as_it_is() {
		$this->activate_plugin( array( 'administrator' => 2 ) );

		$result = $this->import( $this->file_to_import( self::DIRTY_SVG ), 'admin' );

		$this->assertSame( '', $result['stderr'] );
		$this->assertSame( 'image/svg+xml', $this->wp_ok( 'post', 'get', (string) $result['id'], '--field=post_mime_type' ) );
		$this->assertSame( self::DIRTY_SVG, $this->stored_content( $result['id'] ) );
		$metadata = json_decode( $this->wp_ok( 'post', 'meta', 'get', (string) $result['id'], '_wp_attachment_metadata', '--format=json' ), true );
		$this->assertEquals( 30, $metadata['width'] );
		$this->assertEquals( 20, $metadata['height'] );
	}

	/**
	 * Imports are sideloads, which go through the same prefilter as uploads.
	 */
	public function test_import_as_a_sanitized_user_stores_a_sanitized_svg() {
		$this->activate_plugin( array( 'author' => 1 ) );
		$this->create_user( 'anna', 'author' );

		$result = $this->import( $this->file_to_import( self::DIRTY_SVG ), 'anna' );

		$this->assertSame( '', $result['stderr'] );
		$this->assertSame( 'image/svg+xml', $this->wp_ok( 'post', 'get', (string) $result['id'], '--field=post_mime_type' ) );
		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $result['id'] ) );
	}

	/**
	 * Pins the defect in WPSVG::svg_dimensions(): the gzipped SVG is stored
	 * sanitized, but its dimensions cannot be read and are stored as zeros.
	 * Nothing is printed only because the sanitizer, which ran first, left
	 * libxml's internal error handling switched on. When fixed, the
	 * dimensions are 30 x 20.
	 *
	 * @group pinned
	 */
	public function test_gzipped_import_stores_no_dimensions_pinned_for_deferred_fix() {
		$this->activate_plugin( array( 'author' => 1 ) );
		$this->create_user( 'anna', 'author' );

		$result = $this->import( $this->file_to_import( gzencode( self::DIRTY_SVG ), 'logo.svgz' ), 'anna' );

		$this->assertSame( self::SANITIZED_SVG, gzdecode( $this->stored_content( $result['id'] ) ), 'The file itself is sanitized' );
		$this->assertSame( '', $result['stderr'] );
		$metadata = json_decode( $this->wp_ok( 'post', 'meta', 'get', (string) $result['id'], '_wp_attachment_metadata', '--format=json' ), true );
		$this->assertSame( 0, $metadata['width'] );
		$this->assertSame( 0, $metadata['height'] );
	}

	/**
	 * The "inactive" side of the comparison: WordPress alone imports no SVG.
	 */
	public function test_import_is_refused_while_the_plugin_is_inactive() {
		$result = $this->import( $this->file_to_import( self::DIRTY_SVG ), 'admin' );

		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $result['error'] );
	}

	/* Deactivation and removal. */

	public function test_deactivation_keeps_settings_and_stops_imports() {
		$this->activate_plugin( array( 'administrator' => 2 ) );

		$this->wp_ok( 'plugin', 'deactivate', self::PLUGIN );
		$result = $this->import( $this->file_to_import( self::DIRTY_SVG ), 'admin' );

		$this->assertSame( array( 'role_administrator' => 2 ), json_decode( $this->wp_ok( 'option', 'get', 'wpsvg_settings', '--format=json' ), true ) );
		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $result['error'] );
	}

	/**
	 * Pins the defect documented on the WPSVG class: there is no uninstall
	 * routine, so `wp plugin uninstall` leaves the plugin's options behind.
	 * `--skip-delete` runs the uninstall without deleting the plugin files,
	 * which are read-only here. When fixed, both options are gone.
	 *
	 * @group pinned
	 */
	public function test_uninstall_leaves_the_options_behind_pinned_for_deferred_fix() {
		$this->activate_plugin( array( 'administrator' => 2 ) );
		$this->wp_ok( 'plugin', 'deactivate', self::PLUGIN );

		$output = $this->wp_ok( 'plugin', 'uninstall', self::PLUGIN, '--skip-delete' );

		$this->assertStringContainsString( 'Success: Uninstalled 1 of 1 plugins.', $output );
		$this->assertSame( array( 'role_administrator' => 2 ), json_decode( $this->wp_ok( 'option', 'get', 'wpsvg_settings', '--format=json' ), true ) );
		$this->assertSame( '1', $this->wp_ok( 'option', 'get', 'WPSVGActivated' ) );
	}
}
