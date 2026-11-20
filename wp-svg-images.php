<?php
/*
	Plugin Name:		WP SVG Images
	Plugin URI:		https://shortpixel.com/
	Description:		Full SVG Media support in WordPress
	Version:	  	4.6
	Author:		  	ShortPixel
	Author URI:		https://shortpixel.com/
 	GitHub Plugin URI:	https://github.com/short-pixel-optimizer/wp-svg-images
	Primary Branch: 	main
	Text Domain:		wp-svg-images
	Domain Path:		/languages
	Requires at least:	5.0
	Requires PHP:		7.4
*/

defined( 'ABSPATH' ) ||	exit;

/*
 * Load the bundled sanitizer unless another copy of the library is already
 * available. class_exists() also asks registered autoloaders, so a plugin
 * that loads earlier and ships the library through Composer (Safe SVG, for
 * example) supplies every sanitizer class and the bundled files are not read
 * at all. A plugin that loads later uses the bundled copy instead. The two
 * cases behave differently wherever the copies differ: an older copy lacks
 * the fixes of the bundled release and can throw where the bundled one
 * returns false, which WPSVG::sanitize() treats the same way.
 */
if( ! class_exists('enshrined\svgSanitize\Sanitizer') ){
	require_once( 'svg-sanitizer/data/AttributeInterface.php' );
	require_once( 'svg-sanitizer/data/TagInterface.php' );
	require_once( 'svg-sanitizer/data/AllowedAttributes.php' );
	require_once( 'svg-sanitizer/data/AllowedTags.php' );
	require_once( 'svg-sanitizer/data/XPath.php' );
	require_once( 'svg-sanitizer/ElementReference/Resolver.php' );
	require_once( 'svg-sanitizer/ElementReference/Subject.php' );
	require_once( 'svg-sanitizer/ElementReference/Usage.php' );
	require_once( 'svg-sanitizer/Exceptions/NestingException.php' );
	require_once( 'svg-sanitizer/Exceptions/RegexException.php' );
	require_once( 'svg-sanitizer/Helper.php' );
	require_once( 'svg-sanitizer/Sanitizer.php' );
}

/*
 * Shared ShortPixel modules. build/shortpixel/ is generated from the modules
 * repository by `composer update` (composer.json: namespace `ShortPixel\SVG\`,
 * hook prefix `wpsvg/`, this plugin's text domain), so this copy never shares
 * a class, hook or option with the copy another ShortPixel plugin bundles.
 * autoload.php registers the autoloader for them; nothing is loaded until
 * used. Only the promo banner is bundled.
 */
require_once __DIR__ . '/build/shortpixel/autoload.php';

/**
 * Tag whitelist used by the plugin's sanitizer, extensible through a filter.
 *
 * Declared unconditionally: a second copy of this file (for example the plugin
 * installed twice under different folder names) triggers a fatal
 * "Cannot declare class" error before the `class_exists( 'WPSVG' )` guard below
 * is reached.
 */
class WPSVG_allowedTags extends \enshrined\svgSanitize\data\AllowedTags{
	/**
	 * Returns the library's default tag whitelist passed through the
	 * `WPSVG_setAllowedTags` filter.
	 *
	 * Tag names are compared case-insensitively by the sanitizer, which
	 * lower-cases this list when it is applied.
	 *
	 * @return array List of allowed element names.
	 */
	public static function getTags(){
		return apply_filters( 'WPSVG_setAllowedTags', parent::getTags() );
	}
}

/**
 * Attribute whitelist used by the plugin's sanitizer, extensible through a filter.
 */
class WPSVG_allowedAttrs extends \enshrined\svgSanitize\data\AllowedAttributes{
	/**
	 * Returns the library's default attribute whitelist passed through the
	 * `WPSVG_setAllowedAttrs` filter.
	 *
	 * Attribute names are compared case-insensitively by the sanitizer.
	 * Attributes starting with `aria-` or `data-` are always kept, whatever
	 * this list contains.
	 *
	 * @return array List of allowed attribute names.
	 */
	public static function getAttributes(){
		return apply_filters( 'WPSVG_setAllowedAttrs', parent::getAttributes() );
	}
}

if( ! class_exists('WPSVG') ){
	/**
	 * Main plugin class: SVG upload permissions, sanitization, dimensions and
	 * Media Library previews.
	 *
	 * One instance is created when this file loads and every behaviour is
	 * attached to WordPress hooks in the constructor. No reference to the
	 * instance is kept in a global; it is reachable only through the
	 * registered callbacks.
	 *
	 * Permissions are stored per role in the `wpsvg_settings` option as
	 * `role_<slug>` => int, where 0 disables SVG upload, 1 allows sanitized
	 * upload and 2 allows unrestricted (unsanitized) upload.
	 *
	 * On a network the plugin has no network-wide settings: each site has its
	 * own settings page and option. The activation hook stores its redirect
	 * flag on the site the activation request ran on only, so after a network
	 * activation only that site's administrators are redirected.
	 *
	 * The campaign banner of the bundled `promo-banner` module is set up on
	 * `admin_init` (promo_banner()) and printed on the settings page. Its
	 * dismissals are stored per site in the `wpsvg-promo-banner` option
	 * (campaign id => dismissal time), which uninstall.php deletes.
	 *
	 * @todo uninstall.php deletes only the promo banner's option, so deleting
	 *       the plugin leaves the `wpsvg_settings` option, a pending
	 *       `WPSVGActivated` option and the `wpsvg_notice_dismissed` user
	 *       meta in the database. Delete them in uninstall.php as well.
	 */
	class WPSVG{
		/**
		 * Prefix of the promo banner: names its dismissals option
		 * (`wpsvg-promo-banner`), its AJAX action and its asset handle.
		 */
		const PROMO_BANNER_PREFIX = 'wpsvg';

		/**
		 * Hook suffix of the settings page, as returned by add_submenu_page().
		 *
		 * @var string|false|null
		 */
		var $plugin_admin_page;

		/**
		 * Per-role upload permissions loaded from the `wpsvg_settings` option
		 * on `init`. Null before `init`, false when the option does not exist.
		 *
		 * @var array|false|null
		 */
		var $settings;

		/**
		 * Sanitizer configured to drop the XML declaration and minify output.
		 *
		 * @var \enshrined\svgSanitize\Sanitizer
		 */
		var $sanitizer;

		/**
		 * Whether ShortPixel Image Optimizer is installed. Set on `admin_init`.
		 *
		 * @var bool
		 */
		var $spio_installed = false;

		/**
		 * Whether ShortPixel Image Optimizer is active. Set on `admin_init`.
		 *
		 * @var bool
		 */
		var $spio_active = false;

		/**
		 * The current campaign banner. Null until `admin_init`, so also on
		 * every front-end request.
		 *
		 * @var \ShortPixel\SVG\PromoBanner\PromoBanner|null
		 */
		var $promo_banner;

		/**
		 * Creates the sanitizer and registers every action and filter used
		 * by the plugin.
		 */
		function __construct(){
			$this->sanitizer = new enshrined\svgSanitize\Sanitizer();
			$this->sanitizer->removeXMLTag( true );
			$this->sanitizer->minify( true );

			add_filter( 'wp_handle_upload_prefilter', array( $this, 'wp_handle_upload_prefilter' ) );
			add_filter( 'wp_handle_sideload_prefilter', array( $this, 'wp_handle_upload_prefilter' ) ); // REST raw-body uploads

			add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
			add_action( 'admin_menu', array( $this, 'plugin_menu_link' ) );
			add_action( 'init', array( $this, 'plugin_init' ) );

			add_action( 'admin_init', array( $this, 'add_svg_support' ) );
			add_action( 'admin_footer', array( $this, 'fix_svg_thumbnail_size' ) );
			add_filter( 'upload_mimes', array( $this, 'add_svg_mime' ) );
			add_filter( 'wp_check_filetype_and_ext', array( $this, 'wp_check_filetype_and_ext' ), 100, 4 );
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'wp_generate_attachment_metadata' ), 10, 2 );
			add_filter( 'fl_module_upload_regex', array( $this, 'fl_module_upload_regex' ), 10, 4 );
			add_filter( 'render_block', array( $this, 'fix_missing_width_height_on_image_block' ), 10, 2 );

			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'wp_ajax_wpsvg_notice_dismissed', array( $this, 'wpsvg_notice_dismissed' ) );

			add_action( 'admin_init', array( $this, 'upsell' ) );
			add_action( 'admin_init', array( $this, 'promo_banner' ) );
			add_filter( 'wp_all_import_image_mime_type', array( $this, 'wp_all_import_svgs' ), 10, 2 );
		}

		/**
		 * Gives WP All Import imports the SVG type and sanitizes them.
		 *
		 * WP All Import can't detect a type for SVGs, so `.svg` and `.svgz`
		 * files without one get `image/svg+xml`. Any file that ends up with that
		 * type is sanitized first; if that fails, the file is deleted (as WP All
		 * Import does itself) and no type is returned. Role settings don't apply
		 * here: every import is sanitized. Paths that aren't files are skipped.
		 * WP All Import applies the filter to its own copy of the imported
		 * file, in the uploads folder, right before it creates the attachment.
		 *
		 * @todo After a file is deleted here WP All Import still creates the
		 *       attachment, with an empty MIME type and the path of the
		 *       deleted file. Current releases sanitize `.svg` files
		 *       themselves and skip them first, so this happens with older
		 *       releases and with `.svgz`. The filter cannot stop the
		 *       attachment; delete it on `wp_all_import_add_attachment` when
		 *       its file is missing.
		 * @todo The extension check is case-sensitive, so `logo.SVG` keeps an
		 *       empty MIME type. Compare against strtolower( $image_filepath ).
		 *
		 * @param string $mime_type      MIME type detected by WP All Import.
		 * @param string $image_filepath Path of the imported image.
		 * @return string
		 */
		function wp_all_import_svgs( $mime_type, $image_filepath ){
			if( empty( $mime_type ) && ( substr( $image_filepath, -4 ) == '.svg' || substr( $image_filepath, -5 ) == '.svgz' ) ){
				$svg_type = 'image/svg+xml';
			}elseif( $mime_type === 'image/svg+xml' ){
				$svg_type = $mime_type;
			}else{
				return $mime_type;
			}

			if( is_file( $image_filepath ) && ! $this->sanitize( $image_filepath ) ){
				wp_delete_file( $image_filepath );
				return '';
			}
			return $svg_type;
		}

		/**
		 * Detects ShortPixel Image Optimizer and enqueues the upsell assets.
		 *
		 * Hooked to `admin_init`. Sets $spio_installed and $spio_active, and
		 * when the plugin is not installed or not active enqueues
		 * `spio-upsell.css` and `spio-upsell.js` on every admin page, not only
		 * on the settings page where the upsell box is rendered.
		 *
		 * @return void
		 */
		function upsell(){
			$plugins = get_plugins();
			$this->spio_installed = isset( $plugins['shortpixel-image-optimiser/wp-shortpixel.php'] );
			$this->spio_active = is_plugin_active('shortpixel-image-optimiser/wp-shortpixel.php');

			if( ! $this->spio_installed || ! $this->spio_active ){
				wp_enqueue_style( 'spio-upsell', plugins_url( '/assets/css/spio-upsell.css', __FILE__ ) );
				wp_enqueue_script( 'spio-upsell', plugins_url( '/assets/js/spio-upsell.js', __FILE__ ), array('jquery') );
			}
		}

		/**
		 * Creates and registers the campaign banner of the `promo-banner`
		 * module bundled in build/shortpixel/.
		 *
		 * Hooked to `admin_init`, so it runs on admin pages and on
		 * admin-ajax.php requests, where the banner's close button posts its
		 * dismissal (`wp_ajax_wpsvg_promo_banner_dismiss`). The banner shows
		 * on the plugin's settings page only, inside the campaign window, to
		 * users with `manage_options`, until one of them dismisses it for the
		 * whole site. The module's class docblock lists every condition and
		 * filter; its hooks carry this plugin's prefix, for example
		 * `wpsvg/promo_banner/show` and `wpsvg/settings/no_banner`. Its
		 * stylesheet and script are enqueued only where it shows.
		 *
		 * With `hook` false the module prints nothing by itself:
		 * admin_options_page() prints the banner right under its heading, so
		 * WordPress does not move it like an admin notice.
		 *
		 * @return void
		 */
		function promo_banner(){
			$plugin = $this;
			$this->promo_banner = new \ShortPixel\SVG\PromoBanner\PromoBanner( array(
				'id'         => 'black-friday-2026',
				'start'      => strtotime( '2026-11-23 00:00:00 UTC' ),
				'end'        => strtotime( '2026-12-01 07:59:59 UTC' ), // 23:59:59 US Pacific on the 30th
				'ends_on'    => '2026-11-30',                           // the day the banner prints
				'url'        => 'https://shortpixel.com/pricing?utm_source=plugin&utm_medium=wpsvg&utm_campaign=top_banner_bf2026',
				'prefix'     => self::PROMO_BANNER_PREFIX,
				'assets_url' => plugins_url( '/build/shortpixel/promo-banner/assets/', __FILE__ ),
				'version'    => '1.0',
				'screens'    => array(),
				'condition'  => function() use ( $plugin ){
					$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
					return $screen && $plugin->plugin_admin_page && $screen->id == $plugin->plugin_admin_page;
				},
				'capability' => 'manage_options',
				'hook'       => false,
			) );
			$this->promo_banner->register();
		}

		/**
		 * AJAX handler that permanently dismisses the settings notice for the
		 * current user.
		 *
		 * Hooked to `wp_ajax_wpsvg_notice_dismissed`. Requires a valid
		 * `wpsvg_notice_dismissed` nonce in `_wpnonce`; an invalid nonce ends
		 * the request with check_ajax_referer()'s -1 response. On success adds
		 * the `wpsvg_notice_dismissed` user meta (once) and returns without
		 * output, so admin-ajax.php answers "0".
		 *
		 * @return void
		 */
		function wpsvg_notice_dismissed(){
			if( defined('DOING_AJAX') && DOING_AJAX && check_ajax_referer('wpsvg_notice_dismissed') ){
				add_user_meta( get_current_user_id(), 'wpsvg_notice_dismissed', 1, true );
			}
		}

		/**
		 * Prints the dismissible "new settings" notice.
		 *
		 * Hooked to `admin_notices`. Shown only to users with `manage_options`
		 * who have not dismissed it, and only on the `plugins`,
		 * `plugin-install`, `upload` and `attachment` screens. Includes an
		 * inline script that posts the dismissal to admin-ajax.php.
		 *
		 * @return false|void False when the user lacks `manage_options`.
		 */
		function admin_notices(){
			if (! current_user_can('manage_options')) {
				 return false;
			}
			if( ! get_user_meta( get_current_user_id(), 'wpsvg_notice_dismissed' ) ){
				if( function_exists('get_current_screen') && isset( get_current_screen()->id ) && in_array( get_current_screen()->id, array( 'plugins', 'plugin-install', 'upload', 'attachment' ) ) ){ ?>
					<div class="wpsvg-notice notice notice-success is-dismissible">
						<p><?php printf( /* translators: %s: link to the plugin settings page, with the text "Settings page". */ esc_html__( 'Hey, WP SVG Images has some new settings! Activate SVG sanitization and manage permissions by going to the new %s.', 'wp-svg-images' ), '<a href="' . admin_url('options-general.php?page=wp-svg-images.php') . '">' . /* translators: link text that completes the sentence "...by going to the new %s." */ esc_html__( 'Settings page', 'wp-svg-images' ) . '</a>' ) ?></p>
						<p><small style="opacity:.9"><?php printf( /* translators: %s: link to the ShortPixel website, with the text "ShortPixel". */ esc_html__( 'WP SVG Images is now a part of the %s family.', 'wp-svg-images' ), '<a href="https://shortpixel.com/api-tools" target="_blank">ShortPixel</a>' ) ?></small></p>
						<p><a href="<?php echo admin_url('options-general.php?page=wp-svg-images.php') ?>" class="button button-primary"><?php /* translators: button label. */ esc_html_e( 'Go to Settings', 'wp-svg-images' ) ?></a></p>
					</div>
					<script>
					jQuery(document).ready(function($){
						$(document).on('click', '.wpsvg-notice .notice-dismiss', function(){
							$.post( ajaxurl, { action: 'wpsvg_notice_dismissed', _wpnonce: '<?php echo wp_create_nonce('wpsvg_notice_dismissed') ?>' });
						});
					});
					</script><?php
				}
			}
		}

		/**
		 * Sanitizes SVG uploads and sideloads in place before WordPress moves
		 * them.
		 *
		 * Hooked to `wp_handle_upload_prefilter` and
		 * `wp_handle_sideload_prefilter`, so it runs for files handled by
		 * wp_handle_upload() and by wp_handle_sideload() (REST API raw-body
		 * uploads, media_handle_sideload(), media_sideload_image(), WP-CLI
		 * imports). A file is treated as SVG when its name ends in `.svg` or
		 * `.svgz`, in any case, or when mime_content_type() reports its
		 * content as `image/svg+xml` or `image/svg`; the client-supplied type
		 * is ignored. Such a file is rewritten by sanitize() unless one of the
		 * current user's roles is set to unrestricted (2), so content that is
		 * not XML, such as a PNG image or plain text named `.svg`, is refused.
		 * Upload permission itself is enforced by add_svg_mime(), not here.
		 *
		 * Another active SVG plugin keeps its own rules. Safe SVG, for
		 * example, sanitizes every SVG upload and sideload a second time, so
		 * an unrestricted role's file is sanitized as well and elements
		 * allowed through `WPSVG_setAllowedTags` are removed again.
		 *
		 * Sets `$file['error']` when `tmp_name` is missing or not a string
		 * (for every upload, SVG or not), and when sanitization fails.
		 *
		 * @todo No permission is checked here, so when another plugin allows
		 *       the SVG type (Safe SVG does, for every user who can upload
		 *       files), a role set to "disable SVG upload" uploads SVGs.
		 *       Set `$file['error']` for SVG files from users without a
		 *       non-zero permission.
		 *
		 * @param array $file Single entry of `$_FILES` (`name`, `type`,
		 *                    `tmp_name`, `error`, `size`).
		 * @return array The same array, with `error` set when the upload must
		 *               be rejected.
		 */
		function wp_handle_upload_prefilter( $file ){

			// Security:  form data can be faked, including the filetype. Check tmpfile.
			if ( ! isset($file['tmp_name']) || ! is_string($file['tmp_name']))
			{
				$file['error'] = __( 'Temp file is not set!', 'wp-svg-images' );
				return $file; 
			}

			$file_type = mime_content_type($file['tmp_name']);

			$svgTypes = ['image/svg+xml', 'image/svg'];

			// Files are accepted and served as SVG by extension, so sanitize by extension too, not only by sniffed type.
			$is_svg_name = isset( $file['name'] ) && is_string( $file['name'] ) && preg_match( '/\.svgz?$/i', $file['name'] );

			if( $is_svg_name || in_array($file_type, $svgTypes) )
			{
				$user = wp_get_current_user();
				$roles = (array)$user->roles;
				$unrestricted = false;
				foreach( $roles as $role ){
					if( $this->role_permission( $role ) === 2 ){
						$unrestricted = true;
						break;
					}
				}

				if( ! $unrestricted && ! $this->sanitize( $file['tmp_name'] ) ){
					$file['error'] = __( 'This SVG cannot be sanitized!', 'wp-svg-images' );
				}
			}
			return $file;
		}

		/**
		 * Sanitizes an SVG or gzipped SVG file and overwrites it with the result.
		 *
		 * Gzipped content (detected by is_gzipped()) is decoded, sanitized and
		 * re-encoded. The tag and attribute whitelists are re-read through the
		 * `WPSVG_setAllowedTags` and `WPSVG_setAllowedAttrs` filters on every
		 * call. The sanitizer output then goes through clean_animations().
		 * Disallowed elements and attributes are removed and the file is kept;
		 * the method fails when the content cannot be decoded, when the
		 * sanitizer refuses it (see Sanitizer::sanitize()) or when it throws,
		 * which older copies of the library can do. A failed file is left as
		 * it was.
		 * An empty file is "sanitized" to an empty string and reported as
		 * success. Gzip content that cannot be decoded also raises a PHP
		 * warning from gzdecode().
		 *
		 * @todo A path that cannot be read raises a PHP warning, then an empty
		 *       file is written to that path and success is reported. Return
		 *       false when file_get_contents() fails.
		 *
		 * @param string $file Absolute path of the file to sanitize.
		 * @return bool True when the file was rewritten, false when it could
		 *              not be decoded, parsed or sanitized or has no root
		 *              element left.
		 */
		function sanitize( $file ){
			$svg_code = file_get_contents( $file );
			if( $is_zipped = $this->is_gzipped( $svg_code ) ){
				$svg_code = gzdecode( $svg_code );

				if( $svg_code === false ){
					return false;
				}
			}

			$this->sanitizer->setAllowedTags( new WPSVG_allowedTags() );
			$this->sanitizer->setAllowedAttrs( new WPSVG_allowedAttrs() );

			try{
				$clean_svg_code = $this->sanitizer->sanitize( $svg_code );
			}catch( \Throwable $e ){
				return false;
			}

			if( $clean_svg_code !== false ){
				$clean_svg_code = $this->clean_animations( $clean_svg_code );
			}

			if( $clean_svg_code === false ){
				return false;
			}

			if( $is_zipped ){
				$clean_svg_code = gzencode( $clean_svg_code );
			}

			file_put_contents( $file, $clean_svg_code );

			return true;
		}

		/**
		 * Removes animation elements that could turn an attribute into a
		 * script URL.
		 *
		 * Animating `href` or another URL attribute to a `javascript:` URL
		 * runs script in WebKit. Called by sanitize() on the sanitizer output;
		 * the check lives here rather than in the bundled library because
		 * another plugin may have loaded its own copy of that library, which
		 * is then used instead.
		 *
		 * Elements are matched by local name, ignoring case and namespace:
		 *
		 * - `animate`, `set` and `animateColor` are removed unless their
		 *   `attributeName` is one of a fixed list of geometry and
		 *   presentation attributes (`x`, `width`, `d`, `fill`, `opacity`,
		 *   `transform` and similar);
		 * - `animateTransform` is removed unless it targets `transform`,
		 *   `gradientTransform` or `patternTransform`;
		 * - `animateMotion` is removed whenever it has an `attributeName`.
		 *
		 * `attributeName` is compared ignoring case and surrounding
		 * whitespace; an element without it is not removed for its target.
		 * Any of these elements is also removed when its `values`, `from`,
		 * `to` or `by` attribute contains `javascript:`, `vbscript:` or
		 * `data:`, ignoring case, spaces and control characters, whatever
		 * the target.
		 *
		 * The bundled library does part of this before the markup gets here:
		 * it removes an `attributeName` that contains `href` and any of these
		 * attributes whose value starts with a script or non-image `data:`
		 * URL. Such an element arrives without the attribute and is kept,
		 * harmless, often with no attributes left. The checks here still
		 * cover the other targets, a script URL later in a list of values
		 * (`0;javascript:...`) and older copies of the library.
		 *
		 * The default tag whitelist allows `animateColor`, `animateMotion`
		 * and `animateTransform`; `animate` and `set` reach this method only
		 * when a site adds them through the `WPSVG_setAllowedTags` filter.
		 *
		 * @param string $svg_code Sanitized SVG markup.
		 * @return string|false The markup unchanged when it is empty, only
		 *                      whitespace, or has nothing to remove; the
		 *                      root element serialized again, without an
		 *                      XML declaration and with explicit closing
		 *                      tags, when something was removed; false when
		 *                      it cannot be parsed as XML or has no root
		 *                      element.
		 */
		function clean_animations( $svg_code ){
			if( trim( $svg_code ) === '' ) return $svg_code;

			$presentation = array( 'x', 'y', 'width', 'height', 'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2', 'dx', 'dy', 'd', 'points',
				'offset', 'rotate', 'startoffset', 'viewbox', 'fx', 'fy', 'stddeviation', 'opacity', 'fill', 'fill-opacity', 'stroke',
				'stroke-width', 'stroke-opacity', 'stroke-dasharray', 'stroke-dashoffset', 'stop-color', 'stop-opacity',
				'flood-color', 'flood-opacity', 'lighting-color', 'color', 'visibility', 'display', 'font-size', 'transform' );
			$allowed = array(
				'animate' => $presentation, 'set' => $presentation, 'animatecolor' => $presentation,
				'animatetransform' => array( 'transform', 'gradienttransform', 'patterntransform' ),
				'animatemotion' => array(), // never needs attributeName
			);

			$dom = new DOMDocument();
			$internal_errors = libxml_use_internal_errors( true );
			$loaded = $dom->loadXML( $svg_code, LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $internal_errors );
			if( ! $loaded || ! $dom->documentElement ) return false;

			// One query for the animation elements only. Its result is a fixed list: a live list
			// such as getElementsByTagName( '*' ) is searched from the start at every step before PHP 8.3.
			$xpath = new DOMXPath( $dom );
			$animations = $xpath->query( "//*[contains( '|" . implode( '|', array_keys( $allowed ) ) . "|', concat( '|', translate( local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' ), '|' ) )]" );

			$unsafe = array();
			foreach( $animations as $element ){
				$name = strtolower( $element->localName );
				if( ! isset( $allowed[ $name ] ) ) continue;
				foreach( $element->attributes as $attribute ){
					$attr_name = strtolower( $attribute->localName );
					$attr_value = strtolower( $attribute->value );
					$bad_target = $attr_name === 'attributename' && ! in_array( trim( $attr_value ), $allowed[ $name ], true );
					// Defense in depth: no script or data URLs in the animated values, whatever the target.
					$bad_value = in_array( $attr_name, array( 'values', 'from', 'to', 'by' ), true )
						&& preg_match( '/(?:javascript|vbscript|data):/', preg_replace( '/[\x00-\x20]+/', '', $attr_value ) );
					if( $bad_target || $bad_value ){
						$unsafe[] = $element;
						break;
					}
				}
			}
			if( ! $unsafe ) return $svg_code;

			foreach( $unsafe as $element ){
				$element->parentNode->removeChild( $element );
			}
			return $dom->saveXML( $dom->documentElement, LIBXML_NOEMPTYTAG );
		}

		/**
		 * Tells whether a string starts with the gzip/deflate magic bytes
		 * (1F 8B 08).
		 *
		 * @param string $svg_code Raw file content.
		 * @return bool
		 */
		function is_gzipped( $svg_code ){
			if( function_exists('mb_strpos') ){
				return 0 === mb_strpos( $svg_code, "\x1f" . "\x8b" . "\x08" );
			}else{
				return 0 === strpos( $svg_code, "\x1f" . "\x8b" . "\x08" );
			}
		}

		/**
		 * Loads the `wp-svg-images` text domain, with the plugin's `languages`
		 * folder as a fallback location.
		 *
		 * Hooked to `plugins_loaded`. From WordPress 6.7 the call only records
		 * the folder and the file is loaded on the first translated string;
		 * before 6.7 it is loaded at once. The text domain equals the plugin's
		 * wordpress.org slug, so language packs from translate.wordpress.org
		 * apply. WordPress uses `wp-svg-images-<locale>.mo` from
		 * `WP_LANG_DIR/plugins` (where language packs are installed) when it
		 * exists, and otherwise looks in the plugin's `languages` folder. The
		 * plugin ships the template `languages/wp-svg-images.pot` only.
		 *
		 * @return void
		 */
		function plugins_loaded(){
			load_plugin_textdomain( 'wp-svg-images', FALSE, basename( __DIR__ ) . '/languages/' );
		}

		/**
		 * Registers the Settings > SVG images page and the "Settings" link on
		 * the Plugins screen.
		 *
		 * Hooked to `admin_menu`. The page slug is `wp-svg-images.php` and
		 * requires `manage_options`.
		 *
		 * @return void
		 */
		function plugin_menu_link(){
			$this->plugin_admin_page = add_submenu_page(
				'options-general.php',
				__( 'SVG images', 'wp-svg-images' ),
				__( 'SVG images', 'wp-svg-images' ),
				'manage_options',
				basename( __FILE__ ),
				array( $this, 'admin_options_page' )
			);
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'filter_plugin_actions' ), 10, 2 );
		}

		/**
		 * Prepends a "Settings" link to the plugin's row on the Plugins screen.
		 *
		 * Hooked to `plugin_action_links_<basename>`.
		 *
		 * @param array  $links Action links.
		 * @param string $file  Plugin basename (unused).
		 * @return array
		 */
		function filter_plugin_actions( $links, $file ){
			array_unshift( $links, '<a href="options-general.php?page=' . basename( __FILE__ ) . '">' . __( 'Settings', 'wp-svg-images' ) . '</a>' );
			return $links;
		}

		/**
		 * Loads the per-role permissions from the `wpsvg_settings` option into
		 * $settings.
		 *
		 * Hooked to `init`. Until it runs, every permission check treats all
		 * roles as disabled. On a network every site has its own option, and
		 * a new site uses the role_permission() defaults until it is saved.
		 *
		 * @todo The settings are read once per request, so after
		 *       switch_to_blog() the permission checks still use the settings
		 *       of the site the request started on, and code that uploads to
		 *       another site in the same request is judged by the wrong
		 *       site's permissions. Read the option when it is needed, or
		 *       reload it on the `switch_blog` action.
		 *
		 * @return void
		 */
		function plugin_init(){
			$this->settings = get_option('wpsvg_settings');
		}

		/**
		 * Gets a role's SVG permission: 0 disabled, 1 sanitized, 2 unrestricted.
		 *
		 * Until the settings are saved once, roles that can upload files get 1,
		 * which matches what the settings page shows. Before `init` it's 0.
		 *
		 * @param string $role Role slug.
		 * @return int
		 */
		function role_permission( $role ){
			if( is_array( $this->settings ) ){
				$key = 'role_' . esc_attr( $role );
				return isset( $this->settings[ $key ] ) ? intval( $this->settings[ $key ] ) : 0;
			}
			if( $this->settings === null ){
				return 0;
			}
			$role_object = get_role( $role );
			return ( $role_object && $role_object->has_cap( 'upload_files' ) ) ? 1 : 0;
		}

		/**
		 * Renders the settings page and saves the per-role permissions.
		 *
		 * Visiting the page dismisses the settings notice for the current user.
		 * On POST with `plugin_sent` and a valid `save_these_settings` nonce
		 * (field `settings_nonce`), replaces the whole `wpsvg_settings` option
		 * with every posted `role_*` field cast to int; an invalid nonce ends
		 * the request in wp_die(). The method itself checks no capability:
		 * WordPress registers it for users with `manage_options` only, and it
		 * returns at once on any other screen. Lists only roles that
		 * have the `upload_files` capability; a role without a saved value is
		 * shown as "enable sanitized SVG upload". Includes upsell.php when
		 * ShortPixel Image Optimizer is not installed or not active. Prints
		 * the campaign banner (`.shortpixel-bf`, see promo_banner()) under
		 * the heading when one shows.
		 *
		 * @todo Once settings are saved, a role added later still shows as
		 *       "sanitized" but role_permission() treats it as disabled. Show
		 *       "disable" for it instead.
		 * @todo Posted values are saved for any `role_*` key, including roles
		 *       that do not exist or lack `upload_files`, and are not limited
		 *       to 0, 1 and 2. Whitelist the keys against $wp_roles and clamp
		 *       the values.
		 * @todo The role display name is printed without escaping. Wrap it in
		 *       esc_html( translate_user_role( ... ) ).
		 *
		 * @return void
		 */
		function admin_options_page(){
			global $wp_roles;

			if( get_current_screen()->id != $this->plugin_admin_page ) return;

			add_user_meta( get_current_user_id(), 'wpsvg_notice_dismissed', 1, true );

			$show_update_notice = false;
			if( isset( $_POST['plugin_sent'] ) ){
				if( check_admin_referer( 'save_these_settings', 'settings_nonce' ) ){
					$this->settings = array();
					foreach( $_POST as $key => $value ){
						if( substr( $key, 0, 5 ) == 'role_' ){
							$this->settings[ sanitize_text_field( $key ) ] = intval( $value );
						}
					}
					update_option( 'wpsvg_settings', $this->settings );
					$show_update_notice = true;
				}
			} ?>
			<div class="wrap">
				<h2><?php _e( 'SVG images', 'wp-svg-images' ) ?></h2>

				<?php if( is_object( $this->promo_banner ) ) $this->promo_banner->render(); ?>

				<?php if( $show_update_notice ) echo '<div class="below-h2 updated"><p>' . __( 'Settings saved.', 'wp-svg-images' ) . '</p></div>'; ?>

				<div class="postbox" style="margin-top:10px">
					<div class="inside" style="padding-bottom:2px">
						<h3><?php esc_html_e( 'Security notice', 'wp-svg-images' ) ?></h3>
						<p><?php printf( /* translators: %1$s: opening <strong> tag, %2$s: closing </strong> tag, %3$s: line break. */ esc_html__( 'Every SVG image is an XML file, which can contain %1$smalicious code%2$s, that can lead to %1$sXSS or injection attacks%2$s.%3$sBelow you can decide which user roles (with upload capability) will be able to upload SVG images and also if their uploads will be sanitized.', 'wp-svg-images' ), '<strong>', '</strong>', '<br>' ) ?></p>
						<p><?php printf( /* translators: %1$s: opening <code> tag, %2$s: closing </code> tag. The text between them is an option name and must match its translation. */ esc_html__( 'Sometimes you may need to upload some special SVG that includes your JavaScript code or other elements that are normally removed during sanitization - in that case, you can temporarily %1$senable unrestricted SVG upload%2$s if you are sure about your SVG code. Otherwise, it is recommended to use only the %1$senable sanitized SVG upload%2$s option.','wp-svg-images' ), '<code>', '</code>' ) ?> <a href="https://shortpixel.com/knowledge-base/article/524-about-sanitizing-svgs" target="_blank"><span class="dashicons dashicons-editor-help" style="text-decoration:none"></span><?php /* translators: link to an article about SVG sanitization. */ esc_html_e( 'Read more', 'wp-svg-images' ) ?></a></p>
						<p class="notice notice-large notice-warning"><?php printf( /* translators: %1$s: opening <code> tag, %2$s: closing </code> tag. The text between them is an option name and must match its translation. */ esc_html__( 'You should %1$senable unrestricted SVG upload%2$s permanently only if you are absolutely sure that all users within this user role are responsible and experienced enough to detect malicious SVG code by themselves.', 'wp-svg-images' ), '<code>', '</code>' ) ?></p>
					</div>
				</div>

				<div class="wpsvg-wrapper">
					<section style="flex-grow:1;padding:18px;border:1px solid #ccc">
						<form method="post" action="<?php echo admin_url( 'options-general.php?page=' . basename( __FILE__ ) ) ?>">
							<input type="hidden" name="plugin_sent" value="1">
							<?php wp_nonce_field( 'save_these_settings', 'settings_nonce' ) ?>

							<table class="form-table"><?php
								$options = array(
									1 => esc_html__( 'enable sanitized SVG upload', 'wp-svg-images' ),
									2 => esc_html__( 'enable unrestricted SVG upload', 'wp-svg-images' ),
									0 => esc_html__( 'disable SVG upload', 'wp-svg-images' ),
								);
								foreach( $wp_roles->roles as $role_slug => $role ){
									$role_slug = esc_attr( $role_slug );
									if( isset( $role['capabilities'], $role['capabilities']['upload_files'] ) && $role['capabilities']['upload_files'] ){ ?>
										<tr>
											<th>
												<label for="role_<?php echo $role_slug ?>">
													<?php echo $role['name'] ?>
												</label>
											</th>
											<td>
												<select name="role_<?php echo $role_slug ?>" id="role_<?php echo $role_slug ?>"><?php
													foreach( $options as $value => $text ){
														$maybe_selected = '';
														if( isset( $this->settings[ 'role_' . $role_slug ] ) ){
															if( intval( $this->settings[ 'role_' . $role_slug ] ) === $value ){
																$maybe_selected = 'selected';
															}
														}else{
															if( $value === 1 ){
																$maybe_selected = 'selected';
															}
														} ?>
														<option value="<?php echo $value ?>" <?php echo $maybe_selected ?>>
															<?php echo $text ?>
														</option><?php
													} ?>
												</select>
												<a href="https://shortpixel.com/knowledge-base/article/524-about-sanitizing-svgs" target="_blank" aria-label="<?php esc_attr_e( 'Read more', 'wp-svg-images' ) ?>"><span class="dashicons dashicons-editor-help" style="text-decoration:none"></span></a>
											</td>
										</tr><?php
									}
								} ?>
							</table>

							<p class="submit"><input type="submit" class="button button-primary button-large" value="<?php _e( 'Save', 'wp-svg-images' ) ?>"></p>
						</form>
					</section>
					<?php if( ! $this->spio_installed || ! $this->spio_active ) include_once('upsell.php') ?>
				</div>
			</div><?php
		}

		/**
		 * Adds width and height to `core/image` blocks that show an SVG
		 * attachment.
		 *
		 * Hooked to `render_block`. Acts only when the rendered HTML contains
		 * neither `width=` nor `height=` and the block's `id` attribute points
		 * to an `image/svg+xml` attachment. Dimensions come from
		 * svg_dimensions() and are inserted into every `<img ` in the block.
		 * When the dimensions cannot be read, `width="0" height="0"` is
		 * inserted. The stored attachment metadata is not used.
		 *
		 * @todo The check for existing dimensions searches the whole block
		 *       HTML for the text `width=` or `height=`, so a caption or
		 *       alternative text containing either (for example "Max
		 *       height=20") leaves the image without dimensions. Look at the
		 *       attributes of the `<img>` element only.
		 * @todo An SVG without readable dimensions (no size and no `viewBox`,
		 *       and every `.svgz` file) gets `width="0" height="0"`, which
		 *       hides the image on the page. Leave the block unchanged when
		 *       either value is zero.
		 *
		 * @param string $block_content Rendered block HTML.
		 * @param array  $block         Parsed block (`blockName`, `attrs`, ...).
		 * @return string
		 */
		function fix_missing_width_height_on_image_block( $block_content, $block ){
			if( $block['blockName'] === 'core/image' ){
				if( strpos( $block_content, 'width=' ) === false && strpos( $block_content, 'height=' ) === false ){
					if( isset( $block['attrs'], $block['attrs']['id'] ) && get_post_mime_type( $block['attrs']['id'] ) == 'image/svg+xml' ){
						$svg_path = get_attached_file( $block['attrs']['id'] );
						$dimensions = $this->svg_dimensions( $svg_path );
						$block_content = str_replace( '<img ', '<img width="' . $dimensions->width . '" height="' . $dimensions->height . '" ', $block_content );
					}
				}
			}
			return $block_content;
		}

		/**
		 * Lets the Beaver Builder photo module accept `.svg` and `.svgz` files.
		 *
		 * Hooked to `fl_module_upload_regex`. For those extensions, replaces
		 * `|png|` with `|png|svgz?|` in `$regex['photo']`; other regexes and
		 * extensions are returned unchanged. Has no effect when the photo
		 * regex does not contain `|png|`. Beaver Builder only decides whether
		 * the extension suits the module; the upload still passes this
		 * plugin's permission gate and sanitization.
		 *
		 * @todo Beaver Builder passes the extension as uploaded and matches
		 *       its own list case-insensitively, but the comparison here is
		 *       case-sensitive, so `LOGO.SVG` is refused in the photo module
		 *       while `LOGO.PNG` is accepted. Compare against
		 *       strtolower( $ext ).
		 *
		 * @param array  $regex Regexes keyed by module type.
		 * @param string $type  Upload type (unused).
		 * @param string $ext   File extension as uploaded, in any case.
		 * @param array  $file  Uploaded file (unused).
		 * @return array
		 */
		function fl_module_upload_regex( $regex, $type, $ext, $file ){
			if( $ext == 'svg' || $ext == 'svgz' ){
				$regex['photo'] = str_replace( '|png|', '|png|svgz?|', $regex['photo'] );
			}
			return $regex;
		}

		/**
		 * Prints CSS that stretches SVG previews in the attachment details and
		 * featured image boxes to full width.
		 *
		 * Hooked to `admin_footer`, on every admin page.
		 *
		 * @return void
		 */
		function fix_svg_thumbnail_size(){
			echo '<style>.attachment-info .thumbnail img[src$=".svg"],#postimagediv .inside img[src$=".svg"]{width:100%}</style>';
		}

		/**
		 * Stores the SVG's width and height in the attachment metadata.
		 *
		 * Hooked to `wp_generate_attachment_metadata`. Applies only to
		 * `image/svg+xml` attachments; other metadata keys are kept. A plugin
		 * that filters the metadata later can replace the values: Safe SVG,
		 * when it loads after this plugin, stores the `viewBox` size instead.
		 *
		 * @param array $metadata      Attachment metadata.
		 * @param int   $attachment_id Attachment post ID.
		 * @return array
		 */
		function wp_generate_attachment_metadata( $metadata, $attachment_id ){
			if( get_post_mime_type( $attachment_id ) == 'image/svg+xml' ){
				$svg_path = get_attached_file( $attachment_id );
				$dimensions = $this->svg_dimensions( $svg_path );
				$metadata['width'] = $dimensions->width;
				$metadata['height'] = $dimensions->height;
			}
			return $metadata;
		}

		/**
		 * Forces the SVG type for `.svg` and `.svgz` file names when the
		 * current user may upload SVGs.
		 *
		 * Hooked to `wp_check_filetype_and_ext` at priority 100. When any of
		 * the current user's roles has a non-zero permission, a file name
		 * ending in `.svg` or `.svgz` gets `ext` set accordingly and `type`
		 * set to `image/svg+xml`, regardless of the file content and of what
		 * WordPress detected. `proper_filename` is not touched, so it can still
		 * hold a name WordPress proposed for the detected content (`logo.png`
		 * for a PNG image named `logo.svg`). Other files and users are left
		 * unchanged.
		 *
		 * @todo The extension check is case-sensitive, so `logo.SVG` is not
		 *       recognised. Compare against strtolower( $filename ).
		 * @todo An image of another type named `logo.svg` (for example a PNG)
		 *       keeps the `proper_filename` WordPress proposed, so when an
		 *       unrestricted role uploads it (other roles have it refused by
		 *       wp_handle_upload_prefilter()) it is stored as `logo.png` with
		 *       the type `image/svg+xml` and zero dimensions. Leave the detected data unchanged when WordPress
		 *       proposes another file name, or clear `proper_filename`
		 *       together with forcing the type.
		 *
		 * @param array  $filetype_ext_data `ext`, `type` and `proper_filename`.
		 * @param string $file              Full path to the file (unused).
		 * @param string $filename          Original file name.
		 * @param array  $mimes             Allowed MIME types (unused).
		 * @return array
		 */
		function wp_check_filetype_and_ext( $filetype_ext_data, $file, $filename, $mimes ){
			$user = wp_get_current_user();
			$roles = (array)$user->roles;
			foreach( $roles as $role ){
				if( $this->role_permission( $role ) ){
					if( substr( $filename, -4 ) == '.svg' ){
						$filetype_ext_data['ext'] = 'svg';
						$filetype_ext_data['type'] = 'image/svg+xml';
					}elseif( substr( $filename, -5 ) == '.svgz' ){
						$filetype_ext_data['ext'] = 'svgz';
						$filetype_ext_data['type'] = 'image/svg+xml';
					}
					break;
				}
			}
			return $filetype_ext_data;
		}

		/**
		 * Handles the post-activation redirect and installs the admin output
		 * filter that adds SVG previews to Media Library templates.
		 *
		 * Hooked to `admin_init`, so it also runs for admin-ajax.php and
		 * admin-post.php requests. When the `WPSVGActivated` option exists it
		 * is deleted and a redirect header to the settings page is sent. It
		 * then declares the global function svg_thumbs(), starts an output
		 * buffer that passes the whole admin response through the
		 * `final_output` filter, and registers final_output() and
		 * wp_prepare_attachment_for_js().
		 *
		 * @todo The redirect is not followed by `exit`, so the rest of the
		 *       admin request still runs and renders. Add `exit;` after
		 *       wp_redirect().
		 * @todo svg_thumbs() is declared as a global function inside this
		 *       method, so a second call in the same request ends in a fatal
		 *       "Cannot redeclare svg_thumbs()" error, and any other plugin
		 *       defining svg_thumbs() collides with it. Use a method callback
		 *       for ob_start().
		 *
		 * @return void
		 */
		public function add_svg_support(){
			if( is_admin() && get_option('WPSVGActivated') ){
				delete_option( 'WPSVGActivated' );
				wp_redirect( admin_url( 'options-general.php?page=' . basename( __FILE__ ) ) );
			}

			/**
			 * Output buffer callback that applies the `final_output` filter to
			 * the buffered admin response.
			 *
			 * @param string $content Buffered output.
			 * @return string
			 */
			function svg_thumbs( $content ){
				return apply_filters( 'final_output', $content );
			}

			ob_start( 'svg_thumbs' );

			add_filter( 'final_output', array( $this, 'final_output' ) );
			add_filter( 'wp_prepare_attachment_for_js', array( $this, 'wp_prepare_attachment_for_js' ), 10, 3 );
		}

		/**
		 * Adds an SVG branch to the Media Library's attachment details and
		 * attachment grid Backbone templates.
		 *
		 * Hooked to the plugin's own `final_output` filter. Inserts an
		 * `'svg+xml' === data.subtype` case before the two template conditions
		 * that render image previews, so SVGs show their file URL as preview.
		 * Content without those exact template strings is returned unchanged,
		 * which happens when WordPress changes the template markup.
		 *
		 * @todo Neither template condition exists in the media templates of
		 *       the WordPress versions the plugin is tested with, so this
		 *       method changes nothing there and the admin-wide output buffer
		 *       only costs memory. WordPress shows the preview from
		 *       `data.sizes.full`, which the stored dimensions provide. Remove
		 *       the buffer and this method, or match the current markup.
		 *
		 * @param string $content Full admin page HTML.
		 * @return string
		 */
		function final_output( $content ){
			$content = str_replace(
				'<# } else if ( \'image\' === data.type && data.sizes && data.sizes.full ) { #>',
				'<# } else if ( \'svg+xml\' === data.subtype ) { #>
					<img class="details-image" src="{{ data.url }}" draggable="false" />
				<# } else if ( \'image\' === data.type && data.sizes && data.sizes.full ) { #>',
				$content
			);

			$content = str_replace(
				'<# } else if ( \'image\' === data.type && data.sizes ) { #>',
				'<# } else if ( \'svg+xml\' === data.subtype ) { #>
					<div class="centered">
						<img src="{{ data.url }}" class="thumbnail" draggable="false" />
					</div>
				<# } else if ( \'image\' === data.type && data.sizes ) { #>',
				$content
			);

			return $content;
		}

		/**
		 * Allows the `svg` and `svgz` extensions for users who may upload SVGs.
		 *
		 * Hooked to `upload_mimes`. This is the permission gate: the
		 * extensions are added when any of the current user's roles has a
		 * non-zero permission (see role_permission()). Users with no
		 * matching role, including logged-out requests and WP-CLI without
		 * `--user`, get the list unchanged. On a network the extensions are
		 * added after WordPress has reduced the list to the network's
		 * "Upload file types" setting, so SVG uploads do not depend on that
		 * setting.
		 *
		 * @todo A super admin browsing a subsite where they have no role gets
		 *       no SVG permission, because only roles are checked. Decide
		 *       whether super admins are treated as administrators.
		 *
		 * @param array $mimes Allowed extensions => MIME types.
		 * @return array
		 */
		public function add_svg_mime( $mimes = array() ){
			$user = wp_get_current_user();
			$roles = (array)$user->roles;
			foreach( $roles as $role ){
				if( $this->role_permission( $role ) ){
					$mimes['svg'] = 'image/svg+xml';
					$mimes['svgz'] = 'image/svg+xml';
					break;
				}
			}
			return $mimes;
		}

		/**
		 * Gives SVG attachments a `full` size entry for the Media Library.
		 *
		 * Hooked to `wp_prepare_attachment_for_js` by add_svg_support(), so
		 * only in admin requests. Applies only when the MIME type is
		 * `image/svg+xml` and `sizes` is empty; WordPress already builds the
		 * `full` size from the width and height in the attachment metadata, so
		 * this covers attachments without metadata. Dimensions are read from
		 * the attached file, or from the attachment URL when the file does not
		 * exist locally (offloaded media), which makes a remote request
		 * through file_get_contents(). Orientation is `landscape` when width > height and
		 * `portrait` otherwise, including for square images.
		 *
		 * @param array    $response   Attachment data for JS.
		 * @param \WP_Post $attachment Attachment post.
		 * @param array    $meta       Attachment metadata (unused).
		 * @return array
		 */
		function wp_prepare_attachment_for_js( $response, $attachment, $meta ){
			if( $response['mime'] == 'image/svg+xml' && empty( $response['sizes'] ) ){
				$svg_path = get_attached_file( $attachment->ID );
				if( ! file_exists( $svg_path ) ){
					$svg_path = $response['url'];
				}
				$dimensions = $this->svg_dimensions( $svg_path );
				$response['sizes'] = array(
					'full' => array(
						'url' => $response['url'],
						'width' => $dimensions->width,
						'height' => $dimensions->height,
						'orientation' => $dimensions->width > $dimensions->height ? 'landscape' : 'portrait'
					)
				);
			}
			return $response;
		}

		/**
		 * Reads the intrinsic width and height of an SVG.
		 *
		 * Uses the root `width` and `height` attributes when both are present
		 * and not percentages, parsed with floatval() so units are dropped
		 * (`10em` gives 10). When either value is still zero and a `viewBox`
		 * exists, both are taken from its third and fourth values. Gzipped
		 * content (detected by is_gzipped()) is decoded first, so an `.svgz`
		 * file is read like any other.
		 *
		 * Returns zeros when the file cannot be read, decoded or parsed. A
		 * PHP warning is then raised by file_get_contents() or gzdecode(),
		 * and by simplexml_load_string() unless libxml internal errors are
		 * enabled: the bundled sanitizer restores that setting when it is
		 * done, an older copy of the library leaves it enabled, which hides
		 * the parser's warnings for the rest of the request. An empty file
		 * gives zeros without a warning. The root element does not have to
		 * be `<svg>`, a negative value is returned as it is, and a `width`
		 * without a `height` (or the reverse) is ignored.
		 *
		 * @todo The `viewBox` is split on single spaces only, so a viewBox
		 *       using commas or repeated whitespace (both valid) yields wrong
		 *       or zero dimensions. Split on preg_split( '/[\s,]+/', trim( ... ) ).
		 *
		 * @param string $svg Path or URL of the SVG file.
		 * @return object{width: float|int, height: float|int}
		 */
		function svg_dimensions( $svg ){
			$svg_code = file_get_contents( $svg );
			if( $svg_code !== false && $this->is_gzipped( $svg_code ) ){
				$svg_code = gzdecode( $svg_code );
			}
			$svg = $svg_code === false ? false : simplexml_load_string( $svg_code );
			$width = 0;
			$height = 0;
			if( $svg ){
				$attributes = $svg->attributes();
				if( isset( $attributes->width, $attributes->height ) ){
					if( substr( trim( $attributes->width ), -1 ) != '%' ){
						$width = floatval( $attributes->width );
					}
					if( substr( trim( $attributes->height ), -1 ) != '%' ){
						$height = floatval( $attributes->height );
					}
				}
				if( ( ! $width || ! $height ) && isset( $attributes->viewBox ) ){
					$sizes = explode( ' ', $attributes->viewBox );
					if( isset( $sizes[2], $sizes[3] ) ){
						$width = floatval( $sizes[2] );
						$height = floatval( $sizes[3] );
					}
				}
			}
			return (object)array( 'width' => $width, 'height' => $height );
		}
	}

	// Flag picked up by WPSVG::add_svg_support() to redirect to the settings page once.
	register_activation_hook( __FILE__, function(){
		add_option( 'WPSVGActivated', 1 );
	});

	new WPSVG();
}
