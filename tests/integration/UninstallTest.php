<?php

require_once __DIR__ . '/WPSVGIntegrationTestCase.php';

/**
 * What the plugin leaves in the database when it is deactivated and when it
 * is deleted.
 */
class UninstallTest extends WPSVGIntegrationTestCase {

	const BASENAME = 'wp-svg-images/wp-svg-images.php';

	/**
	 * @return int ID of a user who dismissed the notice, after settings were saved.
	 */
	private function leave_plugin_data() {
		$this->set_permissions( array( 'author' => 1 ) );
		update_option( 'WPSVGActivated', 1 );
		$user = $this->log_in_as( 'administrator' );
		add_user_meta( $user->ID, 'wpsvg_notice_dismissed', 1, true );

		return $user->ID;
	}

	public function test_deactivation_keeps_the_settings() {
		$user_id = $this->leave_plugin_data();
		// The test bootstrap fixes `active_plugins` through this filter; the
		// stored option takes over for this test.
		remove_all_filters( 'pre_option_active_plugins' );
		update_option( 'active_plugins', array( self::BASENAME ) );
		$this->assertTrue( is_plugin_active( self::BASENAME ) );

		deactivate_plugins( self::BASENAME );

		$this->assertFalse( is_plugin_active( self::BASENAME ) );
		$this->assertSame( array( 'role_author' => 1 ), get_option( 'wpsvg_settings' ) );
		$this->assertSame( array( '1' ), get_user_meta( $user_id, 'wpsvg_notice_dismissed' ) );
	}

	public function test_plugin_registers_no_deactivation_routine() {
		$this->assertFalse( has_action( 'deactivate_' . self::BASENAME ) );
	}

	/**
	 * Deleting the plugin removes the promo banner's dismissals.
	 *
	 * WordPress includes uninstall.php once per PHP process, so the test
	 * runs in its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_deletes_the_promo_banner_dismissals() {
		update_option( 'wpsvg-promo-banner', array( 'black-friday-2026' => time() ), false );
		$this->assertTrue( is_uninstallable_plugin( self::BASENAME ) );

		uninstall_plugin( self::BASENAME );

		$this->assertFalse( get_option( 'wpsvg-promo-banner' ) );
	}

	/**
	 * Pins the defect documented in uninstall.php: the uninstall routine
	 * deletes only the promo banner's option, so deleting the plugin leaves
	 * its own options and user meta behind. When fixed, the uninstall
	 * removes all three.
	 *
	 * @group pinned
	 */
	public function test_uninstall_leaves_options_and_user_meta_behind_pinned_for_deferred_fix() {
		$user_id = $this->leave_plugin_data();

		$this->assertTrue( is_uninstallable_plugin( self::BASENAME ) );
		uninstall_plugin( self::BASENAME );

		$this->assertSame( array( 'role_author' => 1 ), get_option( 'wpsvg_settings' ) );
		$this->assertEquals( 1, get_option( 'WPSVGActivated' ) );
		$this->assertSame( array( '1' ), get_user_meta( $user_id, 'wpsvg_notice_dismissed' ) );
	}
}
