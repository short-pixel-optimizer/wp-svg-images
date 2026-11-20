<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * The dismissible "new settings" notice: who sees it and on which screens.
 * NoticeDismissalAjaxTest covers the request that dismisses it.
 */
class AdminNoticeTest extends WPSVGIntegrationTestCase {

	/**
	 * @param string $screen Screen ID.
	 * @return string What the plugin prints on `admin_notices`.
	 */
	private function notice_on( $screen ) {
		set_current_screen( $screen );
		ob_start();
		try {
			$this->plugin()->admin_notices();
		} finally {
			$html = ob_get_clean();
		}

		return $html;
	}

	/**
	 * @dataProvider provide_screens_with_the_notice
	 */
	public function test_administrator_sees_the_notice_on_plugin_and_media_screens( $screen ) {
		$this->log_in_as( 'administrator' );

		$html = $this->notice_on( $screen );

		$this->assertStringContainsString( '<div class="wpsvg-notice notice notice-success is-dismissible">', $html );
	}

	public function provide_screens_with_the_notice() {
		return array(
			'Plugins'       => array( 'plugins' ),
			'Add Plugins'   => array( 'plugin-install' ),
			'Media Library' => array( 'upload' ),
			'Edit Media'    => array( 'attachment' ),
		);
	}

	/**
	 * @dataProvider provide_screens_without_the_notice
	 */
	public function test_notice_is_not_shown_on_other_screens( $screen ) {
		$this->log_in_as( 'administrator' );

		$this->assertSame( '', $this->notice_on( $screen ) );
	}

	public function provide_screens_without_the_notice() {
		return array(
			'Dashboard'        => array( 'dashboard' ),
			'Add Media File'   => array( 'media' ),
			'Posts'            => array( 'edit-post' ),
			'Edit Post'        => array( 'post' ),
			'General Settings' => array( 'options-general' ),
			'Network plugins'  => array( 'plugins-network' ),
		);
	}

	/**
	 * @dataProvider provide_roles_without_manage_options
	 */
	public function test_notice_is_not_shown_to_users_who_may_not_manage_options( $role ) {
		$this->log_in_as( $role );
		set_current_screen( 'upload' );

		ob_start();
		$result = $this->plugin()->admin_notices();
		$html   = ob_get_clean();

		$this->assertFalse( $result );
		$this->assertSame( '', $html );
	}

	public function provide_roles_without_manage_options() {
		return array(
			'editor'     => array( 'editor' ),
			'author'     => array( 'author' ),
			'subscriber' => array( 'subscriber' ),
		);
	}

	public function test_notice_is_part_of_the_admin_notices_wordpress_prints() {
		$this->log_in_as( 'administrator' );
		set_current_screen( 'plugins' );

		ob_start();
		do_action( 'admin_notices' );
		$html = ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'class="wpsvg-notice ' ) );
	}

	public function test_notice_links_to_the_settings_page() {
		$this->log_in_as( 'administrator' );
		$settings = admin_url( 'options-general.php?page=wp-svg-images.php' );

		$html = $this->notice_on( 'plugins' );

		$this->assertStringContainsString( '<a href="' . $settings . '">Settings page</a>', $html );
		$this->assertStringContainsString( '<a href="' . $settings . '" class="button button-primary">Go to Settings</a>', $html );
		$this->assertStringContainsString( '<a href="https://shortpixel.com/api-tools" target="_blank">ShortPixel</a>', $html );
	}

	public function test_notice_script_posts_the_dismissal_with_a_valid_nonce() {
		$this->log_in_as( 'administrator' );

		$html = $this->notice_on( 'plugins' );

		$this->assertSame( 1, preg_match( "~\\$\\.post\\( ajaxurl, \\{ action: 'wpsvg_notice_dismissed', _wpnonce: '([0-9a-f]+)' \\}\\);~", $html, $match ) );
		$this->assertSame( 1, wp_verify_nonce( $match[1], 'wpsvg_notice_dismissed' ) );
	}

	public function test_notice_is_gone_for_the_user_who_dismissed_it_only() {
		$other = $this->log_in_as( 'administrator' );
		$user  = $this->log_in_as( 'administrator' );
		add_user_meta( $user->ID, 'wpsvg_notice_dismissed', 1, true );

		$this->assertSame( '', $this->notice_on( 'plugins' ) );

		wp_set_current_user( $other->ID );
		$this->assertStringContainsString( 'wpsvg-notice', $this->notice_on( 'plugins' ) );
	}

	public function test_dismissal_is_offered_to_logged_in_users_only() {
		$this->assertSame( 10, has_action( 'wp_ajax_wpsvg_notice_dismissed', array( $this->plugin(), 'wpsvg_notice_dismissed' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_wpsvg_notice_dismissed' ) );
	}

	/**
	 * Outside an AJAX request the handler does nothing, even with a valid
	 * nonce.
	 */
	public function test_dismissal_handler_does_nothing_outside_an_ajax_request() {
		$user = $this->log_in_as( 'administrator' );
		$this->assertFalse( defined( 'DOING_AJAX' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wpsvg_notice_dismissed' );

		$this->plugin()->wpsvg_notice_dismissed();
		unset( $_REQUEST['_wpnonce'] );

		$this->assertSame( array(), get_user_meta( $user->ID, 'wpsvg_notice_dismissed' ) );
	}
}
