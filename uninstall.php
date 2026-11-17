<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Deletes the promo banner's dismissals option `wpsvg-promo-banner` on the
 * current site; the banner module cannot hook the uninstall itself. The
 * prefix is WPSVG::PROMO_BANNER_PREFIX, repeated here because the plugin is
 * not loaded when it is deleted.
 *
 * @todo The plugin's own data stays in the database: the `wpsvg_settings`
 *       option, a pending `WPSVGActivated` option and the
 *       `wpsvg_notice_dismissed` user meta. Delete them here.
 * @todo On a network only the site the deletion runs on is cleaned. Delete
 *       the option on every site.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/build/shortpixel/autoload.php';

\ShortPixel\SVG\PromoBanner\PromoBanner::uninstall( 'wpsvg' );
