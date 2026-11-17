<?php

use ShortPixel\SVG\PromoBanner\PromoBanner;

/**
 * The promo-banner module as bundled in build/shortpixel/promo-banner: when
 * a banner shows, what it prints and where it stores a dismissal.
 *
 * Every banner here uses its own prefix, so its option and AJAX action never
 * touch the plugin's own banner, which PromoBannerTest covers. The test
 * bootstrap closes the campaign window for every test; it is removed here
 * because these tests drive the window through each banner's `start` and
 * `end`.
 *
 * The SHORTPIXEL_NO_BANNER constant is not covered: a constant cannot be
 * removed again, so defining it would silence every later test.
 */
class PromoBannerModuleTest extends WP_UnitTestCase {

	const PREFIX = 'wpsvgtest';

	public function set_up() {
		parent::set_up();
		remove_filter( 'wpsvg/promo_banner/in_window', '__return_false' );
	}

	public function tear_down() {
		PromoBanner::uninstall( self::PREFIX );
		parent::tear_down();
	}

	/**
	 * @param array $args Overrides.
	 * @return PromoBanner A banner whose window contains now, on every screen.
	 */
	private function banner( array $args = array() ) {
		return new PromoBanner(
			array_merge(
				array(
					'id'         => 'test-campaign',
					'start'      => time() - HOUR_IN_SECONDS,
					'end'        => time() + HOUR_IN_SECONDS,
					'prefix'     => self::PREFIX,
					'assets_url' => 'https://example.org/assets/',
					'condition'  => '__return_true',
				),
				$args
			)
		);
	}

	/**
	 * @param string $role Role slug.
	 */
	private function log_in_as( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	public function test_class_is_loaded_from_the_build_folder_of_the_plugin() {
		$file = ( new ReflectionClass( PromoBanner::class ) )->getFileName();

		$this->assertSame( realpath( WP_PLUGIN_DIR . '/wp-svg-images/build/shortpixel/promo-banner/src/PromoBanner.php' ), realpath( $file ) );
		$this->assertTrue( class_exists( 'ShortPixel\SVG\Build\PackageLoader', false ) );
	}

	public function test_banner_shows_for_an_administrator_inside_the_window() {
		$this->log_in_as( 'administrator' );

		$this->assertTrue( $this->banner()->shouldShow() );
	}

	public function test_banner_never_shows_without_a_campaign_id() {
		$this->log_in_as( 'administrator' );

		$this->assertFalse( $this->banner( array( 'id' => '' ) )->shouldShow() );
	}

	public function test_banner_does_not_show_before_or_after_the_window() {
		$this->log_in_as( 'administrator' );

		$this->assertFalse( $this->banner( array( 'start' => time() + 60, 'end' => time() + 120 ) )->shouldShow(), 'Not before the start' );
		$this->assertFalse( $this->banner( array( 'start' => time() - 120, 'end' => time() - 60 ) )->shouldShow(), 'Not after the end' );
	}

	public function test_window_bounds_are_inclusive() {
		$banner = $this->banner( array( 'start' => 1000, 'end' => 2000 ) );

		$this->assertFalse( $banner->isInWindow( 999 ) );
		$this->assertTrue( $banner->isInWindow( 1000 ) );
		$this->assertTrue( $banner->isInWindow( 2000 ) );
		$this->assertFalse( $banner->isInWindow( 2001 ) );
	}

	public function test_in_window_filter_replaces_the_time_check() {
		$this->log_in_as( 'administrator' );
		$banner = $this->banner( array( 'start' => time() + 60, 'end' => time() + 120 ) );
		add_filter( 'wpsvg/promo_banner/in_window', '__return_true' );

		$this->assertTrue( $banner->shouldShow(), 'A campaign can be previewed before it starts' );
	}

	public function test_banner_does_not_show_to_a_user_without_the_capability() {
		$this->log_in_as( 'editor' );

		$this->assertFalse( $this->banner()->shouldShow() );
	}

	public function test_capability_filter_changes_who_sees_the_banner() {
		$this->log_in_as( 'editor' );
		add_filter(
			'wpsvg/promo_banner/capability',
			static function () {
				return 'edit_pages';
			}
		);

		$this->assertTrue( $this->banner()->shouldShow() );
	}

	public function test_banner_does_not_show_when_the_screen_condition_is_false() {
		$this->log_in_as( 'administrator' );

		$this->assertFalse( $this->banner( array( 'condition' => '__return_false' ) )->shouldShow() );
	}

	public function test_banner_shows_on_a_listed_screen_without_a_condition() {
		$this->log_in_as( 'administrator' );
		$banner = $this->banner( array( 'condition' => null, 'screens' => array( 'plugins' ) ) );

		set_current_screen( 'plugins' );
		$on_listed = $banner->shouldShow();
		set_current_screen( 'dashboard' );

		$this->assertTrue( $on_listed );
		$this->assertFalse( $banner->shouldShow() );
	}

	public function test_banner_does_not_show_once_the_campaign_is_dismissed() {
		$this->log_in_as( 'administrator' );
		$banner = $this->banner();
		update_option( $banner->getOptionName(), array( 'test-campaign' => time() ), false );

		$this->assertTrue( $banner->isDismissed() );
		$this->assertFalse( $banner->shouldShow() );
	}

	public function test_dismissal_of_another_campaign_does_not_hide_this_one() {
		$this->log_in_as( 'administrator' );
		$banner = $this->banner();
		update_option( $banner->getOptionName(), array( 'older-campaign' => time() ), false );

		$this->assertFalse( $banner->isDismissed() );
		$this->assertTrue( $banner->shouldShow() );
	}

	/**
	 * @dataProvider provide_switches
	 */
	public function test_filter_switches_the_banner_off( $filter, $callback ) {
		$this->log_in_as( 'administrator' );
		add_filter( $filter, $callback );

		$this->assertFalse( $this->banner()->shouldShow() );
	}

	public function provide_switches() {
		return array(
			'the plugin switch'    => array( 'wpsvg/settings/no_banner', '__return_true' ),
			'the last-word filter' => array( 'wpsvg/promo_banner/show', '__return_false' ),
		);
	}

	/**
	 * The switches of ShortPixel Image Optimizer carry its own prefix.
	 */
	public function test_filters_of_another_shortpixel_plugin_do_not_reach_this_banner() {
		$this->log_in_as( 'administrator' );
		add_filter( 'shortpixel/settings/no_banner', '__return_true' );
		add_filter( 'shortpixel/promo_banner/show', '__return_false' );

		$this->assertTrue( $this->banner()->shouldShow() );
	}

	public function test_option_is_named_after_the_prefix_and_uninstall_deletes_it() {
		$banner = $this->banner();
		$this->assertSame( self::PREFIX . '-promo-banner', $banner->getOptionName() );
		update_option( $banner->getOptionName(), array( 'test-campaign' => time() ), false );

		PromoBanner::uninstall( self::PREFIX );

		$this->assertFalse( get_option( $banner->getOptionName() ) );
	}

	public function test_markup_escapes_the_claim_link() {
		$markup = $this->banner( array( 'url' => 'javascript:alert(1)' ) )->getMarkup();

		$this->assertStringNotContainsString( 'javascript:', $markup );
	}

	public function test_url_filter_changes_the_claim_link() {
		add_filter(
			'wpsvg/promo_banner/url',
			static function () {
				return 'https://example.org/offer';
			}
		);

		$this->assertStringContainsString( '<a class="shortpixel-bf__btn" href="https://example.org/offer" target="_blank" rel="noopener">', $this->banner()->getMarkup() );
	}

	public function test_markup_carries_the_dismiss_action_and_a_valid_nonce() {
		$this->log_in_as( 'administrator' );
		$banner = $this->banner();

		$markup = $banner->getMarkup();

		$this->assertStringContainsString( 'data-action="' . self::PREFIX . '_promo_banner_dismiss"', $markup );
		$this->assertSame( 1, preg_match( '/data-nonce="([^"]+)"/', $markup, $match ) );
		$this->assertNotFalse( wp_verify_nonce( $match[1], $banner->getAjaxAction() ) );
	}

	public function test_markup_links_the_images_under_the_assets_url() {
		$markup = $this->banner()->getMarkup();

		foreach ( array( 'robot.svg', 'forever.png', 'black-friday-sale.png' ) as $image ) {
			$this->assertStringContainsString( 'src="https://example.org/assets/img/' . $image . '"', $markup );
		}
	}

	public function test_end_label_is_the_utc_day_of_the_end_unless_a_day_is_given() {
		$end = gmmktime( 7, 59, 59, 12, 1, 2026 );

		$this->assertStringContainsString( 'Offer ends <strong>December 1, 2026</strong>', $this->banner( array( 'end' => $end ) )->getMarkup() );
		$this->assertStringContainsString( 'Offer ends <strong>November 30, 2026</strong>', $this->banner( array( 'end' => $end, 'ends_on' => '2026-11-30' ) )->getMarkup() );
	}

	public function test_banner_printed_on_admin_notices_is_a_notice() {
		$this->assertStringStartsWith( '<div class="notice shortpixel-bf" role="region"', $this->banner()->getMarkup() );
		$this->assertStringStartsWith( '<div class="shortpixel-bf" role="region"', $this->banner( array( 'hook' => false ) )->getMarkup() );
	}
}
