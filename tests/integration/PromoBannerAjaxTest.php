<?php

/**
 * The banner's close button: the admin-ajax.php request
 * `wpsvg_promo_banner_dismiss`, as the banner's script sends it (the action
 * and a `nonce` field).
 *
 * Every test runs in its own PHP process, as in NoticeDismissalAjaxTest: the
 * simulated request fires `admin_init`, where the plugin sets the banner up,
 * declares a global function and opens an output buffer.
 */
class PromoBannerAjaxTest extends WP_Ajax_UnitTestCase {

	const ACTION   = 'wpsvg_promo_banner_dismiss';
	const OPTION   = 'wpsvg-promo-banner';
	const CAMPAIGN = 'black-friday-2026';

	/**
	 * Simulates the request and closes the buffer the plugin opens on
	 * `admin_init`.
	 *
	 * @param array $fields Posted fields besides the action.
	 * @return array|null The decoded JSON answer.
	 */
	private function request_dismissal( array $fields ) {
		$_POST = $fields;

		$level = ob_get_level();
		try {
			$this->_handleAjax( self::ACTION );
		} catch ( WPAjaxDieContinueException $exception ) {
			unset( $exception );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}

		return json_decode( $this->_last_response, true );
	}

	/**
	 * @param string $role Role slug.
	 */
	private function log_in_as( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_administrator_dismisses_the_campaign_for_the_site() {
		$this->log_in_as( 'administrator' );
		$before = time();

		$answer = $this->request_dismissal( array( 'nonce' => wp_create_nonce( self::ACTION ) ) );

		$this->assertSame( array( 'success' => true ), $answer );
		$stored = get_option( self::OPTION );
		$this->assertSame( array( self::CAMPAIGN ), array_keys( $stored ) );
		$this->assertGreaterThanOrEqual( $before, $stored[ self::CAMPAIGN ] );
	}

	/**
	 * The dismissal is stored while the campaign is not running too; the
	 * request does not check the window.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_dismissal_drops_the_earlier_campaigns() {
		$this->log_in_as( 'administrator' );
		update_option( self::OPTION, array( 'black-friday-2025' => 1764000000 ), false );

		$this->request_dismissal( array( 'nonce' => wp_create_nonce( self::ACTION ) ) );

		$this->assertSame( array( self::CAMPAIGN ), array_keys( get_option( self::OPTION ) ) );
	}

	/**
	 * Once one administrator closes the banner, another one does not see it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_banner_no_longer_shows_to_another_administrator_after_the_dismissal() {
		$this->log_in_as( 'administrator' );
		$this->request_dismissal( array( 'nonce' => wp_create_nonce( self::ACTION ) ) );

		$this->log_in_as( 'administrator' );
		add_filter( 'wpsvg/promo_banner/in_window', '__return_true', 20 );
		do_action( 'admin_menu' );
		set_current_screen( 'settings_page_wp-svg-images' );
		$banner = new ShortPixel\SVG\PromoBanner\PromoBanner(
			array(
				'id'        => self::CAMPAIGN,
				'prefix'    => WPSVG::PROMO_BANNER_PREFIX,
				'condition' => '__return_true',
			)
		);

		$this->assertTrue( $banner->isDismissed() );
		$this->assertFalse( $banner->shouldShow() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider provide_refused_requests
	 */
	public function test_refused_request_stores_nothing( $role, $nonce_action, $field ) {
		if ( null !== $role ) {
			$this->log_in_as( $role );
		}
		$fields = array();
		if ( null !== $nonce_action ) {
			$fields[ $field ] = '' === $nonce_action ? 'not-a-nonce' : wp_create_nonce( $nonce_action );
		}

		$answer = $this->request_dismissal( $fields );

		$this->assertSame( array( 'success' => false ), $answer );
		$this->assertFalse( get_option( self::OPTION ), 'Nothing is stored' );
	}

	public function provide_refused_requests() {
		return array(
			'wrong nonce'                  => array( 'administrator', '', 'nonce' ),
			'no nonce'                     => array( 'administrator', null, 'nonce' ),
			'nonce of the settings form'   => array( 'administrator', 'save_these_settings', 'nonce' ),
			'nonce of the settings notice'   => array( 'administrator', 'wpsvg_notice_dismissed', 'nonce' ),
			'editor'                       => array( 'editor', self::ACTION, 'nonce' ),
			'subscriber'                   => array( 'subscriber', self::ACTION, 'nonce' ),
			'visitor'                      => array( null, self::ACTION, 'nonce' ),
		);
	}

	/**
	 * admin-ajax.php answers a logged-out visitor through the `nopriv`
	 * action, which the banner does not register.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_logged_out_visitor_reaches_no_handler() {
		$this->request_dismissal( array() );

		$this->assertTrue( has_action( 'wp_ajax_' . self::ACTION ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_' . self::ACTION ) );
	}
}
