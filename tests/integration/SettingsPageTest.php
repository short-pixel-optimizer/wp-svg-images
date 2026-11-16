<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * The Settings > SVG images page: registration, what it shows for each role
 * and what a submitted form stores.
 *
 * ShortPixel Image Optimizer is marked as active on the plugin instance so
 * the page is rendered without the upsell box, which UpsellTest covers.
 */
class SettingsPageTest extends WPSVGIntegrationTestCase {

	const SANITIZED_LABEL = 'enable sanitized SVG upload';

	public function set_up() {
		parent::set_up();
		$this->plugin()->spio_installed = true;
		$this->plugin()->spio_active    = true;
	}

	public function tear_down() {
		// WordPress keeps roles in memory, beyond the database rollback.
		foreach ( array( 'wpsvg_uploader', 'wpsvg_reader', 'wpsvg_former_uploader', 'wpsvg_scripted' ) as $role ) {
			remove_role( $role );
		}
		$this->plugin()->spio_installed    = false;
		$this->plugin()->spio_active       = false;
		$this->plugin()->plugin_admin_page = null;
		$_POST                             = array();
		$_REQUEST                          = array();
		parent::tear_down();
	}

	/**
	 * Logs a user in, builds the admin menu and opens the settings screen
	 * when WordPress registered it for that user.
	 *
	 * @param string $role Role slug.
	 * @return string|false Hook suffix of the page; false when the user may
	 *                      not open it.
	 */
	private function open_admin_as( $role ) {
		$this->log_in_as( $role );
		do_action( 'admin_menu' );
		$hook = $this->plugin()->plugin_admin_page;
		set_current_screen( $hook ? $hook : 'dashboard' );

		return $hook;
	}

	/**
	 * @return string Output of the settings page for the current request.
	 */
	private function render_page() {
		ob_start();
		try {
			do_action( $this->plugin()->plugin_admin_page );
		} finally {
			$html = ob_get_clean();
		}

		return $html;
	}

	/**
	 * @param array $fields Posted fields besides `plugin_sent` and the nonce.
	 * @param mixed $nonce  Nonce value; null posts none, true a valid one.
	 * @return string Output of the settings page.
	 */
	private function submit( array $fields, $nonce = true ) {
		$_POST = array( 'plugin_sent' => '1' ) + $fields;
		if ( null !== $nonce ) {
			$_POST['settings_nonce'] = true === $nonce ? wp_create_nonce( 'save_these_settings' ) : $nonce;
		}
		$_REQUEST = $_POST;

		return $this->render_page();
	}

	/**
	 * @param string $html Page output.
	 * @param string $role Role slug.
	 * @return string|null Markup of the role's `<select>`, null when absent.
	 */
	private function select_for( $html, $role ) {
		if ( ! preg_match( '~<select name="role_' . preg_quote( $role, '~' ) . '" id="role_' . preg_quote( $role, '~' ) . '">.*?</select>~s', $html, $match ) ) {
			return null;
		}

		return $match[0];
	}

	/**
	 * @param string $html Page output.
	 * @param string $role Role slug.
	 * @return string[] Values of the options marked as selected.
	 */
	private function selected_values( $html, $role ) {
		$select = $this->select_for( $html, $role );
		$this->assertNotNull( $select, "The page has a select for $role" );
		preg_match_all( '~<option value="(-?\d+)" selected>~', $select, $matches );

		return $matches[1];
	}

	/* Registration. */

	public function test_page_is_registered_under_settings_for_users_who_manage_options() {
		global $submenu;
		$submenu = array();

		$hook = $this->open_admin_as( 'administrator' );

		$this->assertIsString( $hook );
		$this->assertSame(
			array( array( 'SVG images', 'manage_options', 'wp-svg-images.php', 'SVG images' ) ),
			array_values( $submenu['options-general.php'] )
		);
		$this->assertSame( 10, has_action( $hook, array( $this->plugin(), 'admin_options_page' ) ) );
	}

	public function test_page_is_not_registered_for_a_user_who_may_not_manage_options() {
		$hook = $this->open_admin_as( 'editor' );

		$this->assertFalse( $hook );
	}

	public function test_plugins_screen_gets_a_settings_link_once_the_menu_is_built() {
		$filter = 'plugin_action_links_wp-svg-images/wp-svg-images.php';
		$this->assertFalse( has_filter( $filter ) );

		$this->open_admin_as( 'administrator' );
		$links = apply_filters( $filter, array( 'deactivate' => 'Deactivate' ), 'wp-svg-images/wp-svg-images.php' );

		$this->assertSame( '<a href="options-general.php?page=wp-svg-images.php">Settings</a>', reset( $links ) );
		$this->assertSame( 'Deactivate', $links['deactivate'] );
	}

	/**
	 * @dataProvider provide_requests_the_page_ignores
	 */
	public function test_calling_the_page_callback_elsewhere_prints_and_saves_nothing( $role, $screen ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->open_admin_as( $role );
		set_current_screen( $screen );
		$_POST    = array(
			'plugin_sent'    => '1',
			'settings_nonce' => wp_create_nonce( 'save_these_settings' ),
			'role_author'    => '2',
		);
		$_REQUEST = $_POST;

		ob_start();
		$this->plugin()->admin_options_page();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
		$this->assertSame( array( 'role_author' => 1 ), get_option( 'wpsvg_settings' ) );
		$this->assertSame( array(), get_user_meta( get_current_user_id(), 'wpsvg_notice_dismissed' ) );
	}

	public function provide_requests_the_page_ignores() {
		return array(
			'administrator on another screen' => array( 'administrator', 'dashboard' ),
			'editor on the dashboard'         => array( 'editor', 'dashboard' ),
			'editor on the media screen'      => array( 'editor', 'upload' ),
		);
	}

	/* What the page shows. */

	public function test_page_lists_only_roles_that_can_upload_files() {
		add_role( 'wpsvg_uploader', 'Uploader', array( 'upload_files' => true ) );
		add_role( 'wpsvg_reader', 'Reader', array( 'read' => true ) );
		add_role( 'wpsvg_former_uploader', 'Former uploader', array( 'upload_files' => false ) );
		$this->open_admin_as( 'administrator' );

		$html = $this->render_page();

		foreach ( array( 'administrator', 'editor', 'author', 'wpsvg_uploader' ) as $role ) {
			$this->assertNotNull( $this->select_for( $html, $role ), "$role is listed" );
		}
		foreach ( array( 'contributor', 'subscriber', 'wpsvg_reader', 'wpsvg_former_uploader' ) as $role ) {
			$this->assertNull( $this->select_for( $html, $role ), "$role is not listed" );
		}
		$this->assertSame( 4, substr_count( $html, '<select ' ) );
	}

	public function test_page_form_posts_to_itself_with_a_valid_nonce() {
		$this->open_admin_as( 'administrator' );

		$html = $this->render_page();

		$this->assertStringContainsString( '<form method="post" action="' . admin_url( 'options-general.php?page=wp-svg-images.php' ) . '">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="plugin_sent" value="1">', $html );
		$this->assertSame( 1, preg_match( '~name="settings_nonce" value="([0-9a-f]+)"~', $html, $match ) );
		$this->assertSame( 1, wp_verify_nonce( $match[1], 'save_these_settings' ) );
		$this->assertStringNotContainsString( 'Settings saved.', $html );
		$this->assertStringNotContainsString( 'shortpixel-offer', $html );
	}

	/**
	 * What the page shows for a fresh install. Uploads are blocked in that
	 * state; UploadPermissionTest pins the contradiction.
	 */
	public function test_roles_without_a_saved_value_are_shown_as_sanitized() {
		$this->set_permissions( null );
		$this->open_admin_as( 'administrator' );

		$html = $this->render_page();

		foreach ( array( 'administrator', 'editor', 'author' ) as $role ) {
			$this->assertSame( array( '1' ), $this->selected_values( $html, $role ) );
		}
		$this->assertStringContainsString( self::SANITIZED_LABEL, $this->select_for( $html, 'author' ) );
	}

	/**
	 * @dataProvider provide_saved_values_and_selection
	 */
	public function test_saved_value_is_the_selected_option( $saved, $selected ) {
		$this->set_permissions(
			array(
				'author' => $saved,
				'editor' => 0,
			)
		);
		$this->open_admin_as( 'administrator' );

		$html = $this->render_page();

		$this->assertSame( $selected, $this->selected_values( $html, 'author' ) );
		$this->assertSame( array( '0' ), $this->selected_values( $html, 'editor' ) );
		$this->assertSame( array( '1' ), $this->selected_values( $html, 'administrator' ), 'A role missing from saved settings is shown as sanitized' );
	}

	public function provide_saved_values_and_selection() {
		return array(
			'disabled'             => array( 0, array( '0' ) ),
			'sanitized'            => array( 1, array( '1' ) ),
			'unrestricted'         => array( 2, array( '2' ) ),
			'stored as a string'   => array( '2', array( '2' ) ),
			'value outside 0 to 2' => array( 7, array() ),
		);
	}

	public function test_opening_the_page_dismisses_the_settings_notice_once() {
		$this->open_admin_as( 'administrator' );

		$this->render_page();
		$this->render_page();

		$this->assertSame( array( '1' ), get_user_meta( get_current_user_id(), 'wpsvg_notice_dismissed' ) );
	}

	/**
	 * Pins the defect in WPSVG::admin_options_page(): the role's display name
	 * is printed without escaping. When fixed, the markup arrives escaped.
	 *
	 * @group pinned
	 */
	public function test_role_name_is_printed_without_escaping_pinned_for_deferred_fix() {
		add_role( 'wpsvg_scripted', 'Up<script>alert(1)</script>', array( 'upload_files' => true ) );
		$this->open_admin_as( 'administrator' );

		$html = $this->render_page();

		$this->assertStringContainsString( 'Up<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( 'Up&lt;script&gt;', $html );
	}

	/* Saving. */

	public function test_submitting_the_form_replaces_the_stored_permissions() {
		$this->set_permissions(
			array(
				'administrator' => 2,
				'editor'        => 1,
				'author'        => 1,
			)
		);
		$this->open_admin_as( 'administrator' );

		$html = $this->submit(
			array(
				'role_administrator' => '1',
				'role_author'        => '0',
			)
		);

		$this->assertSame(
			array(
				'role_administrator' => 1,
				'role_author'        => 0,
			),
			get_option( 'wpsvg_settings' ),
			'Roles that were not posted are dropped'
		);
		$this->assertStringContainsString( '<div class="below-h2 updated"><p>Settings saved.</p></div>', $html );
		$this->assertSame( array( '1' ), $this->selected_values( $html, 'administrator' ) );
		$this->assertSame( array( '0' ), $this->selected_values( $html, 'author' ) );
		$this->assertSame( array( '1' ), $this->selected_values( $html, 'editor' ), 'The dropped role is shown as sanitized again' );
	}

	public function test_saved_permissions_apply_to_uploads_in_the_same_request() {
		$this->set_permissions( array( 'administrator' => 0 ) );
		$this->open_admin_as( 'administrator' );
		$this->assertArrayNotHasKey( 'svg', get_allowed_mime_types() );

		$this->submit( array( 'role_administrator' => '2' ) );

		$this->assertSame( array( 'role_administrator' => 2 ), $this->plugin()->settings );
		$this->assertSame( 'image/svg+xml', get_allowed_mime_types()['svg'] );
	}

	public function test_values_are_stored_as_integers_and_other_fields_are_ignored() {
		$this->open_admin_as( 'administrator' );

		$this->submit(
			array(
				'role_administrator' => '2',
				'role_editor'        => 'yes',
				'role_author'        => '1.9',
				'permission_author'  => '2',
				'submit'             => 'Save',
			)
		);

		$this->assertSame(
			array(
				'role_administrator' => 2,
				'role_editor'        => 0,
				'role_author'        => 1,
			),
			get_option( 'wpsvg_settings' )
		);
	}

	public function test_submitting_no_role_stores_an_empty_list() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->open_admin_as( 'administrator' );

		$html = $this->submit( array() );

		$this->assertSame( array(), get_option( 'wpsvg_settings' ) );
		$this->assertStringContainsString( 'Settings saved.', $html );
	}

	public function test_fields_without_the_sent_marker_are_not_saved() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->open_admin_as( 'administrator' );
		$_POST    = array(
			'settings_nonce' => wp_create_nonce( 'save_these_settings' ),
			'role_author'    => '2',
		);
		$_REQUEST = $_POST;

		$html = $this->render_page();

		$this->assertSame( array( 'role_author' => 1 ), get_option( 'wpsvg_settings' ) );
		$this->assertStringNotContainsString( 'Settings saved.', $html );
	}

	/**
	 * @dataProvider provide_unusable_nonces
	 */
	public function test_submission_without_a_valid_nonce_dies_and_saves_nothing( $nonce ) {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->open_admin_as( 'administrator' );
		$died = null;

		try {
			$this->submit( array( 'role_author' => '2' ), $nonce );
		} catch ( WPDieException $exception ) {
			$died = $exception->getMessage();
		}

		$this->assertSame( 'The link you followed has expired.', $died );
		$this->assertSame( array( 'role_author' => 1 ), get_option( 'wpsvg_settings' ) );
		$this->assertSame( array( 'role_author' => 1 ), $this->plugin()->settings );
	}

	public function provide_unusable_nonces() {
		return array(
			'no nonce'      => array( null ),
			'empty nonce'   => array( '' ),
			'made-up nonce' => array( '0123456789' ),
			'a plain word'  => array( 'other' ),
		);
	}

	public function test_nonce_of_another_user_is_refused() {
		$this->set_permissions( array( 'author' => 1 ) );
		$this->log_in_as( 'administrator' );
		$foreign_nonce = wp_create_nonce( 'save_these_settings' );
		$this->open_admin_as( 'administrator' );

		$this->expectException( WPDieException::class );
		try {
			$this->submit( array( 'role_author' => '2' ), $foreign_nonce );
		} finally {
			$this->assertSame( array( 'role_author' => 1 ), get_option( 'wpsvg_settings' ) );
		}
	}

	/**
	 * Pins the defect in WPSVG::admin_options_page(): every posted `role_*`
	 * field is stored, whether or not the role exists or can upload files,
	 * and values are not limited to 0, 1 and 2. When fixed, only the listed
	 * roles are stored, with values from 0 to 2.
	 *
	 * @group pinned
	 */
	public function test_unknown_roles_and_out_of_range_values_are_stored_pinned_for_deferred_fix() {
		$this->open_admin_as( 'administrator' );

		$this->submit(
			array(
				'role_author'      => '7',
				'role_editor'      => '-3',
				'role_ghost'       => '2',
				'role_subscriber'  => '2',
				'role_<b>bold</b>' => '1',
			)
		);

		$this->assertSame(
			array(
				'role_author'     => 7,
				'role_editor'     => -3,
				'role_ghost'      => 2,
				'role_subscriber' => 2,
				'role_bold'       => 1,
			),
			get_option( 'wpsvg_settings' )
		);
	}
}
