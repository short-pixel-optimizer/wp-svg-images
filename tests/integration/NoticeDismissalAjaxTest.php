<?php

/**
 * The admin-ajax.php request that dismisses the settings notice.
 *
 * Every test runs in its own PHP process for two reasons: the handler checks
 * the `DOING_AJAX` constant, which admin-ajax.php defines and which cannot be
 * removed again, and the simulated request fires `admin_init`, where the
 * plugin declares a global function and opens an output buffer.
 */
class NoticeDismissalAjaxTest extends WP_Ajax_UnitTestCase {

	/**
	 * Simulates the request and closes the buffer the plugin opens on
	 * `admin_init`.
	 *
	 * @param string|null $nonce Value of `_wpnonce`; null sends none.
	 * @return string|null Message the request died with, null when it ran to
	 *                     the end, which admin-ajax.php answers with "0".
	 */
	private function request_dismissal( $nonce ) {
		define( 'DOING_AJAX', true );
		$_POST = array();
		if ( null !== $nonce ) {
			$_POST['_wpnonce'] = $nonce;
		}

		$level = ob_get_level();
		$died  = null;
		try {
			$this->_handleAjax( 'wpsvg_notice_dismissed' );
		} catch ( WPAjaxDieStopException $exception ) {
			$died = $exception->getMessage();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}

		return $died;
	}

	/**
	 * @param string $role Role slug.
	 * @return int ID of the new current user.
	 */
	private function log_in_as( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_request_with_a_valid_nonce_stores_the_dismissal_for_the_current_user() {
		$other_id = $this->log_in_as( 'administrator' );
		$user_id  = $this->log_in_as( 'administrator' );

		$died = $this->request_dismissal( wp_create_nonce( 'wpsvg_notice_dismissed' ) );

		$this->assertNull( $died );
		$this->assertSame( '', $this->_last_response, 'The handler prints nothing' );
		$this->assertSame( array( '1' ), get_user_meta( $user_id, 'wpsvg_notice_dismissed' ) );
		$this->assertSame( array(), get_user_meta( $other_id, 'wpsvg_notice_dismissed' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_repeated_request_keeps_a_single_dismissal() {
		$user_id = $this->log_in_as( 'administrator' );
		$this->request_dismissal( wp_create_nonce( 'wpsvg_notice_dismissed' ) );

		// A second simulated request would fire `admin_init` again.
		do_action( 'wp_ajax_wpsvg_notice_dismissed' );

		$this->assertSame( array( '1' ), get_user_meta( $user_id, 'wpsvg_notice_dismissed' ) );
	}

	/**
	 * The notice is shown to administrators only, but any logged-in user may
	 * store the dismissal for their own account.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_user_who_never_sees_the_notice_can_dismiss_it_too() {
		$user_id = $this->log_in_as( 'subscriber' );

		$died = $this->request_dismissal( wp_create_nonce( 'wpsvg_notice_dismissed' ) );

		$this->assertNull( $died );
		$this->assertSame( array( '1' ), get_user_meta( $user_id, 'wpsvg_notice_dismissed' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_request_without_a_nonce_is_refused() {
		$user_id = $this->log_in_as( 'administrator' );

		$died = $this->request_dismissal( null );

		$this->assertSame( '-1', $died );
		$this->assertSame( array(), get_user_meta( $user_id, 'wpsvg_notice_dismissed' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_request_with_a_nonce_for_another_action_is_refused() {
		$user_id = $this->log_in_as( 'administrator' );

		$died = $this->request_dismissal( wp_create_nonce( 'save_these_settings' ) );

		$this->assertSame( '-1', $died );
		$this->assertSame( array(), get_user_meta( $user_id, 'wpsvg_notice_dismissed' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_request_with_the_nonce_of_another_user_is_refused() {
		$this->log_in_as( 'administrator' );
		$foreign_nonce = wp_create_nonce( 'wpsvg_notice_dismissed' );
		$user_id       = $this->log_in_as( 'administrator' );

		$died = $this->request_dismissal( $foreign_nonce );

		$this->assertSame( '-1', $died );
		$this->assertSame( array(), get_user_meta( $user_id, 'wpsvg_notice_dismissed' ) );
	}
}
