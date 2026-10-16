<?php
/*
	Plugin Name:		WP SVG Images
	Plugin URI:		https://shortpixel.com/
	Description:		Full SVG Media support in WordPress
	Version:	  	4.5
	Author:		  	ShortPixel
	Author URI:		https://shortpixel.com/
 	GitHub Plugin URI:	https://github.com/short-pixel-optimizer/wp-svg-images
	Primary Branch: 	main
	Text Domain:		wpsvg
	Domain Path:		/languages
*/

defined( 'ABSPATH' ) ||	exit;

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
	require_once( 'svg-sanitizer/Helper.php' );
	require_once( 'svg-sanitizer/Sanitizer.php' );
}

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
	 */
	class WPSVG{
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
		 * Creates the sanitizer and registers every action and filter used
		 * by the plugin.
		 */
		function __construct(){
			$this->sanitizer = new enshrined\svgSanitize\Sanitizer();
			$this->sanitizer->removeXMLTag( true );
			$this->sanitizer->minify( true );

			add_filter( 'wp_handle_upload_prefilter', array( $this, 'wp_handle_upload_prefilter' ) );

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
			add_filter( 'wp_all_import_image_mime_type', array( $this, 'wp_all_import_svgs' ), 10, 2 );
		}

		/**
		 * Supplies the SVG MIME type to WP All Import when it could not detect one.
		 *
		 * Hooked to `wp_all_import_image_mime_type`. Returns `image/svg+xml`
		 * only when `$mime_type` is empty and the path ends in `.svg` or `.svgz`;
		 * otherwise returns `$mime_type` unchanged. Upload permissions are not
		 * checked here.
		 *
		 * @todo The extension check is case-sensitive, so `logo.SVG` keeps an
		 *       empty MIME type. Compare against strtolower( $image_filepath ).
		 *
		 * @param string $mime_type      MIME type detected by WP All Import.
		 * @param string $image_filepath Path of the imported image.
		 * @return string
		 */
		function wp_all_import_svgs( $mime_type, $image_filepath ){
			if( empty( $mime_type ) && ( substr( $image_filepath, -4 ) == '.svg' || substr( $image_filepath, -5 ) == '.svgz' ) ){
				return 'image/svg+xml';
			}
			return $mime_type;
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
						<p><?php printf( esc_html__( 'Hey, WP SVG images has some new settings! Activate SVG sanitization and manage permissions by going to the new %s', 'wpsvg' ), '<a href="' . admin_url('options-general.php?page=wp-svg-images.php') . '">' . esc_html__( 'Settings page', 'wpsvg' ) . '</a>' ) ?></p>
						<p><small style="opacity:.9"><?php printf( esc_html__( 'WP SVG images in now a part of the %s family.', 'wpsvg' ), '<a href="https://shortpixel.com/api-tools" target="_blank">ShortPixel</a>' ) ?></small></p>
						<p><a href="<?php echo admin_url('options-general.php?page=wp-svg-images.php') ?>" class="button button-primary"><?php esc_html_e( 'Go to Settings', 'wpsvg' ) ?></a></p>
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
		 * Sanitizes SVG uploads in place before WordPress moves them.
		 *
		 * Hooked to `wp_handle_upload_prefilter`, so it runs for uploads
		 * handled by wp_handle_upload() only. The file type is sniffed from the
		 * temporary file content with mime_content_type(); the client-supplied
		 * type and the file name are ignored. When the content is
		 * `image/svg+xml` or `image/svg`, the file is rewritten by sanitize()
		 * unless one of the current user's roles is set to unrestricted (2).
		 * Upload permission itself is enforced by add_svg_mime(), not here.
		 *
		 * Sets `$file['error']` when `tmp_name` is missing or not a string
		 * (for every upload, SVG or not), and when sanitization fails.
		 *
		 * @todo Gzipped files are sniffed as `application/gzip`, so `.svgz`
		 *       uploads are never sanitized even though sanitize() handles
		 *       gzip. SVG files whose content is not sniffed as SVG (for
		 *       example a file that starts with an XML comment, sniffed as
		 *       `text/plain`) are not sanitized either, while
		 *       wp_check_filetype_and_ext() still accepts them as
		 *       `image/svg+xml` by file name. Decide by the extension that
		 *       WordPress will store (and by gzip magic bytes) rather than by
		 *       sniffed type alone.
		 * @todo Uploads that go through wp_handle_sideload() (REST API raw-body
		 *       uploads, media_sideload_image(), importers) fire
		 *       `wp_handle_sideload_prefilter` instead and are stored
		 *       unsanitized. Hook the same callback to that filter.
		 * @todo Exceptions and errors thrown by the sanitizer (see sanitize())
		 *       are not caught and abort the upload request with a fatal error
		 *       instead of setting `$file['error']`.
		 * @todo The error message "Temp file is not be set!" is ungrammatical
		 *       and missing from languages/wpsvg.pot.
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
				$file['error'] = __( 'Temp file is not be set!', 'wpsvg' );
				return $file; 
			}

			$file_type = mime_content_type($file['tmp_name']);

			$svgTypes = ['image/svg+xml', 'image/svg'];

			if( in_array($file_type, $svgTypes) )
			{
				$user = wp_get_current_user();
				$roles = (array)$user->roles;
				$unrestricted = false;
				foreach( $roles as $role ){
					if( isset( $this->settings[ 'role_' . esc_attr( $role ) ] ) && intval( $this->settings[ 'role_' . esc_attr( $role ) ] ) === 2 ){
						$unrestricted = true;
						break;
					}
				}

				if( ! $unrestricted && ! $this->sanitize( $file['tmp_name'] ) ){
					$file['error'] = __( 'This SVG can not be sanitized!', 'wpsvg' );
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
		 * call. Disallowed elements and attributes are removed and the file is
		 * kept; the method fails only when the content cannot be decoded or
		 * parsed as XML. An empty file is "sanitized" to an empty string and
		 * reported as success.
		 *
		 * @todo An SVG containing a CDATA section (for example inside
		 *       `<style>`) ends in a fatal "Class HTMLPurifier not found" error
		 *       because the bundled sanitizer uses HTMLPurifier, which the
		 *       plugin does not ship. Bundle HTMLPurifier or replace the CDATA
		 *       handling.
		 * @todo Well-formed XML whose root element is not `<svg>` makes the
		 *       sanitizer throw a LogicException, which propagates to the
		 *       caller. Catch Throwable here and return false.
		 *
		 * @param string $file Absolute path of the file to sanitize.
		 * @return bool True when the file was rewritten, false when it could
		 *              not be decoded or parsed.
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

			$clean_svg_code = $this->sanitizer->sanitize( $svg_code );

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
		 * Loads the `wpsvg` text domain from the plugin's `languages` folder.
		 *
		 * Hooked to `plugins_loaded`.
		 *
		 * @return void
		 */
		function plugins_loaded(){
			load_plugin_textdomain( 'wpsvg', FALSE, basename( __DIR__ ) . '/languages/' );
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
				__( 'SVG images', 'wpsvg' ),
				__( 'SVG images', 'wpsvg' ),
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
			array_unshift( $links, '<a href="options-general.php?page=' . basename( __FILE__ ) . '">' . __( 'Settings', 'wpsvg' ) . '</a>' );
			return $links;
		}

		/**
		 * Loads the per-role permissions from the `wpsvg_settings` option into
		 * $settings.
		 *
		 * Hooked to `init`. Until it runs, every permission check treats all
		 * roles as disabled.
		 *
		 * @return void
		 */
		function plugin_init(){
			$this->settings = get_option('wpsvg_settings');
		}

		/**
		 * Renders the settings page and saves the per-role permissions.
		 *
		 * Visiting the page dismisses the settings notice for the current user.
		 * On POST with `plugin_sent` and a valid `save_these_settings` nonce
		 * (field `settings_nonce`), replaces the whole `wpsvg_settings` option
		 * with every posted `role_*` field cast to int. Lists only roles that
		 * have the `upload_files` capability; a role without a saved value is
		 * shown as "enable sanitized SVG upload". Includes upsell.php when
		 * ShortPixel Image Optimizer is not installed or not active.
		 *
		 * @todo A role without a saved value is displayed as "enable sanitized
		 *       SVG upload", but add_svg_mime() and wp_check_filetype_and_ext()
		 *       treat a missing value as disabled, so a fresh install blocks
		 *       SVG uploads until the form is saved. Apply the same default
		 *       (1) in the permission checks, or show "disable" here.
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
				<h2><?php _e( 'SVG images', 'wpsvg' ) ?></h2>

				<?php if( $show_update_notice ) echo '<div class="below-h2 updated"><p>' . __( 'Settings saved.', 'wpsvg' ) . '</p></div>'; ?>

				<div class="postbox" style="margin-top:10px">
					<div class="inside" style="padding-bottom:2px">
						<h3><?php esc_html_e( 'Security notice', 'wpsvg' ) ?></h3>
						<p><?php printf( esc_html__( 'Every SVG image is an XML file, which can contain %1$smalicious code%2$s, that can lead to %1$sXSS or Injection attacks%2$s.%3$sBelow you can decide which user roles (with upload capability) will be able to upload SVG images and also if their uploads will be sanitized.', 'wpsvg' ), '<strong>', '</strong>', '<br>' ) ?></p>
						<p><?php printf( esc_html__( 'Sometimes you may need to upload some special SVG that includes your JavaScript code or other elements that are normally removed during sanitization - in that case, you can temporarily %1$senable unrestricted SVG upload%2$s if you are sure about your SVG code. Otherwise, it is recommended to use only the %1$senable sanitized SVG upload%2$s option.','wpsvg' ), '<code>', '</code>' ) ?> <a href="https://shortpixel.com/knowledge-base/article/524-about-sanitizing-svgs" target="_blank"><span class="dashicons dashicons-editor-help" style="text-decoration:none"></span><?php esc_html_e( 'Read more', 'wpsvg' ) ?></a></p>
						<p class="notice notice-large notice-warning"><?php printf( esc_html__( 'You should %1$senable unrestricted SVG upload%2$s permanently only if you are absolutely sure that all users within this user role are responsible and experienced enough to detect malicious SVG code by themselves.', 'wpsvg' ), '<code>', '</code>' ) ?></p>
					</div>
				</div>

				<div class="wpsvg-wrapper">
					<section style="flex-grow:1;padding:18px;border:1px solid #ccc">
						<form method="post" action="<?php echo admin_url( 'options-general.php?page=' . basename( __FILE__ ) ) ?>">
							<input type="hidden" name="plugin_sent" value="1">
							<?php wp_nonce_field( 'save_these_settings', 'settings_nonce' ) ?>

							<table class="form-table"><?php
								$options = array(
									1 => esc_html__( 'enable sanitized SVG upload', 'wpsvg' ),
									2 => esc_html__( 'enable unrestricted SVG upload', 'wpsvg' ),
									0 => esc_html__( 'disable SVG upload', 'wpsvg' ),
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
												<a href="https://shortpixel.com/knowledge-base/article/524-about-sanitizing-svgs" target="_blank" aria-label="<?php esc_attr_e( 'Read more', 'wpsvg' ) ?>"><span class="dashicons dashicons-editor-help" style="text-decoration:none"></span></a>
											</td>
										</tr><?php
									}
								} ?>
							</table>

							<p class="submit"><input type="submit" class="button button-primary button-large" value="<?php _e( 'Save', 'wpsvg' ) ?>"></p>
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
		 * inserted.
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
		 * regex does not contain `|png|`.
		 *
		 * @param array  $regex Regexes keyed by module type.
		 * @param string $type  Upload type (unused).
		 * @param string $ext   Lower-case file extension.
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
		 * `image/svg+xml` attachments; other metadata keys are kept.
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
		 * WordPress detected. Other files and users are left unchanged.
		 *
		 * @todo The extension check is case-sensitive, so `logo.SVG` is not
		 *       recognised. Compare against strtolower( $filename ).
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
				if( isset( $this->settings[ 'role_' . esc_attr( $role ) ] ) && intval( $this->settings[ 'role_' . esc_attr( $role ) ] ) ){
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
		 * non-zero permission (sanitized or unrestricted). Users with no
		 * matching role, including logged-out requests and WP-CLI without
		 * `--user`, get the list unchanged.
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
				if( isset( $this->settings[ 'role_' . esc_attr( $role ) ] ) && intval( $this->settings[ 'role_' . esc_attr( $role ) ] ) ){
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
		 * Hooked to `wp_prepare_attachment_for_js`. Applies only when the MIME
		 * type is `image/svg+xml` and `sizes` is empty. Dimensions are read from
		 * the attached file, or from the attachment URL when the file does not
		 * exist locally (offloaded media), which makes a remote request
		 * through libxml. Orientation is `landscape` when width > height and
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
		 * exists, both are taken from its third and fourth values. Returns
		 * zeros when the file cannot be read or parsed.
		 *
		 * @todo The `viewBox` is split on single spaces only, so a viewBox
		 *       using commas or repeated whitespace (both valid) yields wrong
		 *       or zero dimensions. Split on preg_split( '/[\s,]+/', trim( ... ) ).
		 *
		 * @param string $svg Path or URL of the SVG file.
		 * @return object{width: float|int, height: float|int}
		 */
		function svg_dimensions( $svg ){
			$svg = simplexml_load_file( $svg );
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
