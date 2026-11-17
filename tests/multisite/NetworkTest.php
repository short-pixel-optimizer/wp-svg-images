<?php

require_once dirname( __DIR__ ) . '/integration/WPSVGIntegrationTestCase.php';

/**
 * The plugin network-activated on a WordPress network: where its settings
 * and flags live, and who may upload SVG on which site.
 */
class NetworkTest extends WPSVGIntegrationTestCase {

	const BASENAME = 'wp-svg-images/wp-svg-images.php';

	/**
	 * ID of a second site, created for each test.
	 *
	 * @var int
	 */
	private $second_site;

	public function set_up() {
		parent::set_up();
		$this->second_site = self::factory()->blog->create();
	}

	public function tear_down() {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		parent::tear_down();
	}

	/**
	 * Saves permissions on a site without touching the plugin instance.
	 *
	 * @param int   $site_id
	 * @param array $permissions Role slug => 0, 1 or 2.
	 */
	private function save_permissions_on( $site_id, array $permissions ) {
		$settings = array();
		foreach ( $permissions as $role => $value ) {
			$settings[ 'role_' . $role ] = $value;
		}
		update_blog_option( $site_id, 'wpsvg_settings', $settings );
	}

	/**
	 * Starts what a request to a site starts with: that site is current, the
	 * plugin has read its settings and the user is logged in.
	 *
	 * @param int $site_id
	 * @param int $user_id
	 */
	private function request_on( $site_id, $user_id ) {
		switch_to_blog( $site_id );
		$this->plugin()->plugin_init();
		wp_set_current_user( $user_id );
	}

	/**
	 * @param string $role    Role on the main site.
	 * @param array  $on_site Site ID => role on that site.
	 * @return int User ID.
	 */
	private function user_with_roles( $role, array $on_site = array() ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		foreach ( $on_site as $site_id => $site_role ) {
			add_user_to_blog( $site_id, $user_id, $site_role );
		}

		return $user_id;
	}

	/* Loading. */

	public function test_plugin_is_network_activated_and_loaded_once() {
		$this->assertTrue( is_multisite() );
		$this->assertTrue( is_plugin_active_for_network( self::BASENAME ) );
		$this->assertSame( array(), get_option( 'active_plugins' ), 'No site activates it on its own' );
		$this->assertCount( 1, $this->plugin_instances() );
	}

	public function test_plugin_has_a_settings_page_per_site_and_none_for_the_network() {
		$this->log_in_as( 'administrator' );

		do_action( 'admin_menu' );

		$this->assertIsString( $this->plugin()->plugin_admin_page );
		$this->assertFalse( has_action( 'network_admin_menu' ) );
		$this->plugin()->plugin_admin_page = null;
	}

	/* Settings per site. */

	public function test_each_site_keeps_its_own_settings() {
		$this->save_permissions_on( get_main_site_id(), array( 'author' => 1 ) );
		$this->save_permissions_on( $this->second_site, array( 'author' => 2 ) );

		$this->assertSame( array( 'role_author' => 1 ), get_blog_option( get_main_site_id(), 'wpsvg_settings' ) );
		$this->assertSame( array( 'role_author' => 2 ), get_blog_option( $this->second_site, 'wpsvg_settings' ) );
		$this->assertFalse( get_site_option( 'wpsvg_settings' ), 'Nothing is stored for the network' );
	}

	public function test_request_on_a_site_applies_that_sites_permission() {
		$this->save_permissions_on( get_main_site_id(), array( 'author' => 1 ) );
		$this->save_permissions_on( $this->second_site, array( 'author' => 2 ) );
		$user_id = $this->user_with_roles( 'author', array( $this->second_site => 'author' ) );

		$this->request_on( get_main_site_id(), $user_id );
		$on_main = $this->media_upload( self::DIRTY_SVG );
		restore_current_blog();
		$this->request_on( $this->second_site, $user_id );
		$on_second = $this->media_upload( self::DIRTY_SVG );

		$this->assertIsInt( $on_second );
		$this->assertSame( self::DIRTY_SVG, $this->stored_content( $on_second ), 'Unrestricted on the second site' );
		$this->assertStringContainsString( '/sites/' . $this->second_site . '/', get_attached_file( $on_second ) );
		restore_current_blog();
		$this->assertIsInt( $on_main );
		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $on_main ), 'Sanitized on the main site' );
	}

	/**
	 * A new site has no settings, and uploads are blocked without them: the
	 * fresh-install defect pinned in the `integration` suite, once per site.
	 */
	public function test_new_site_has_no_settings_and_allows_no_svg_upload() {
		$this->save_permissions_on( get_main_site_id(), array( 'administrator' => 2 ) );
		$user_id = $this->user_with_roles( 'administrator', array( $this->second_site => 'administrator' ) );

		$this->request_on( $this->second_site, $user_id );
		$result = $this->media_upload( self::DIRTY_SVG );

		$this->assertFalse( get_option( 'wpsvg_settings' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $result->get_error_message() );
	}

	public function test_role_is_judged_per_site() {
		$this->save_permissions_on( get_main_site_id(), array( 'author' => 1 ) );
		$this->save_permissions_on( $this->second_site, array( 'author' => 1 ) );
		$user_id = $this->user_with_roles( 'author', array( $this->second_site => 'subscriber' ) );

		$this->request_on( get_main_site_id(), $user_id );
		$allowed_on_main = isset( get_allowed_mime_types()['svg'] );
		restore_current_blog();
		$this->request_on( $this->second_site, $user_id );
		$allowed_on_second = isset( get_allowed_mime_types()['svg'] );

		$this->assertTrue( $allowed_on_main, 'Author on the main site' );
		$this->assertFalse( $allowed_on_second, 'Subscriber on the second site' );
	}

	public function test_user_who_is_not_a_member_of_a_site_gets_no_permission_there() {
		$this->save_permissions_on( $this->second_site, array( 'author' => 1 ) );
		$user_id = $this->user_with_roles( 'author' );

		$this->request_on( $this->second_site, $user_id );

		$this->assertSame( array(), wp_get_current_user()->roles );
		$this->assertArrayNotHasKey( 'svg', get_allowed_mime_types() );
	}

	/**
	 * Pins the defect in WPSVG::plugin_init(): the settings are read once
	 * per request, so after switch_to_blog() the plugin still applies the
	 * permissions of the site the request started on. Here the second site
	 * disables authors, yet the upload to it is accepted. When fixed, the
	 * upload is refused.
	 *
	 * @group pinned
	 */
	public function test_switching_sites_keeps_the_first_sites_permissions_pinned_for_deferred_fix() {
		$this->save_permissions_on( get_main_site_id(), array( 'author' => 2 ) );
		$this->save_permissions_on( $this->second_site, array( 'author' => 0 ) );
		$user_id = $this->user_with_roles( 'author', array( $this->second_site => 'author' ) );
		$this->request_on( get_main_site_id(), $user_id );

		switch_to_blog( $this->second_site );
		$result = $this->media_upload( self::DIRTY_SVG );

		$this->assertSame( array( 'role_author' => 0 ), get_option( 'wpsvg_settings' ), 'The current site disables authors' );
		$this->assertSame( array( 'role_author' => 2 ), $this->plugin()->settings, 'The plugin still holds the main site\'s settings' );
		$this->assertIsInt( $result );
		$this->assertStringContainsString( '/sites/' . $this->second_site . '/', get_attached_file( $result ) );
		$this->assertSame( self::DIRTY_SVG, $this->stored_content( $result ) );
	}

	/* Super admins. */

	/**
	 * Pins the defect in WPSVG::add_svg_mime(): only roles are checked, so a
	 * super admin, who can do everything on every site, cannot upload SVG on
	 * a site where they have no role. When fixed, the upload is stored.
	 *
	 * @group pinned
	 */
	public function test_super_admin_without_a_role_on_a_site_cannot_upload_svg_pinned_for_deferred_fix() {
		$this->save_permissions_on( $this->second_site, array( 'administrator' => 2 ) );
		$user_id = $this->user_with_roles( 'subscriber' );
		grant_super_admin( $user_id );

		$this->request_on( $this->second_site, $user_id );
		$result = $this->media_upload( self::DIRTY_SVG );

		$this->assertSame( array(), wp_get_current_user()->roles );
		$this->assertTrue( current_user_can( 'upload_files' ) );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'Sorry, you are not allowed to upload this file type.', $result->get_error_message() );
	}

	public function test_super_admin_with_the_administrator_role_on_a_site_uploads_like_an_administrator() {
		$this->save_permissions_on( $this->second_site, array( 'administrator' => 1 ) );
		$user_id = $this->user_with_roles( 'subscriber', array( $this->second_site => 'administrator' ) );
		grant_super_admin( $user_id );

		$this->request_on( $this->second_site, $user_id );
		$result = $this->media_upload( self::DIRTY_SVG );

		$this->assertIsInt( $result );
		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $result ) );
	}

	/* The network's own upload rules. */

	public function test_network_file_type_list_does_not_include_svg_by_default() {
		$this->assertNotContains( 'svg', explode( ' ', get_site_option( 'upload_filetypes' ) ) );
		$this->assertSame( 10, has_filter( 'upload_mimes', 'check_upload_mimes' ) );
	}

	public function test_svg_upload_does_not_depend_on_the_network_file_type_list() {
		update_site_option( 'upload_filetypes', 'jpg png' );
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'author' );

		$mimes  = get_allowed_mime_types();
		$result = $this->media_upload( self::DIRTY_SVG );

		$this->assertArrayNotHasKey( 'gif', $mimes, 'The network list is in force' );
		$this->assertSame( 'image/svg+xml', $mimes['svg'] );
		$this->assertSame( 'image/svg+xml', $mimes['svgz'] );
		$this->assertIsInt( $result );
		$this->assertSame( self::SANITIZED_SVG, $this->stored_content( $result ) );
	}

	/* Activation and leftovers. */

	public function test_network_activation_flags_only_the_site_of_the_request() {
		do_action( 'activate_' . self::BASENAME, true );

		$this->assertEquals( 1, get_option( 'WPSVGActivated' ) );
		$this->assertFalse( get_blog_option( $this->second_site, 'WPSVGActivated' ) );
		$this->assertFalse( get_site_option( 'WPSVGActivated' ) );
	}

	public function test_dismissing_the_notice_counts_on_every_site() {
		$user_id = $this->user_with_roles( 'administrator', array( $this->second_site => 'administrator' ) );
		$this->request_on( get_main_site_id(), $user_id );
		add_user_meta( $user_id, 'wpsvg_notice_dismissed', 1, true );
		restore_current_blog();

		$this->request_on( $this->second_site, $user_id );
		set_current_screen( 'plugins' );
		ob_start();
		$this->plugin()->admin_notices();
		$html = ob_get_clean();

		$this->assertSame( '', $html, 'User meta is shared by all sites of the network' );
	}

	public function test_deleting_a_site_removes_its_settings_with_its_tables() {
		global $wpdb;
		$this->save_permissions_on( $this->second_site, array( 'author' => 1 ) );
		$options_table = $wpdb->get_blog_prefix( $this->second_site ) . 'options';

		wpmu_delete_blog( $this->second_site, true );

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $options_table ) ) );
	}
}
