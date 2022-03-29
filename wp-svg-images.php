<?php
/*
	Plugin Name:	WP SVG Images
	Plugin URI:		https://shortpixel.com/
	Description:	Full SVG Media support in WordPress
	Version:		4.0
	Author:			ShortPixel
	Author URI:		https://shortpixel.com/
    GitHub Plugin URI: https://github.com/short-pixel-optimizer/wp-svg-images
	Text Domain:	wpsvg
	Domain Path:	/languages
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

class WPSVG_allowedTags extends \enshrined\svgSanitize\data\AllowedTags{
	public static function getTags(){
		return apply_filters( 'WPSVG_setAllowedTags', parent::getTags() );
	}
}

class WPSVG_allowedAttrs extends \enshrined\svgSanitize\data\AllowedAttributes{
	public static function getAttributes(){
		return apply_filters( 'WPSVG_setAllowedAttrs', parent::getAttributes() );
	}
}

if( ! class_exists('WPSVG') ){
	class WPSVG{
		var $plugin_admin_page;
		var $settings;
		var $sanitizer;
		var $spio_installed = false;
		var $spio_active = false;

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
		}

		function upsell(){
			$plugins = get_plugins();
			$this->spio_installed = isset( $plugins['shortpixel-image-optimiser/wp-shortpixel.php'] );
			$this->spio_active = is_plugin_active('shortpixel-image-optimiser/wp-shortpixel.php');

			if( ! $this->spio_installed || ! $this->spio_active ){
				wp_enqueue_style( 'spio-upsell', plugins_url( '/assets/css/spio-upsell.css', __FILE__ ) );
				wp_enqueue_script( 'spio-upsell', plugins_url( '/assets/js/spio-upsell.js', __FILE__ ), array('jquery') );
			}
		}

		function wpsvg_notice_dismissed(){
			if( defined('DOING_AJAX') && DOING_AJAX && check_ajax_referer('wpsvg_notice_dismissed') ){
				add_user_meta( get_current_user_id(), 'wpsvg_notice_dismissed', 1, true );
			}
		}

		function admin_notices(){
			if( ! get_user_meta( get_current_user_id(), 'wpsvg_notice_dismissed' ) ){
				if( function_exists('get_current_screen') && isset( get_current_screen()->id ) && in_array( get_current_screen()->id, array( 'plugins', 'plugin-install', 'upload', 'attachment' ) ) ){ ?>
					<div class="wpsvg-notice notice notice-success is-dismissible">
						<p><?php printf( esc_html__( 'Hey, WP SVG images has some new settings! Activate SVG sanitization and manage permissions by going to the new %s', 'wpsvg' ), '<a href="/wp-admin/options-general.php?page=wp-svg-images.php">' . esc_html__( 'Settings page', 'wpsvg' ) . '</a>' ) ?></p>
						<p><small style="opacity:.9"><?php printf( esc_html__( 'WP SVG images in now a part of the %s family.', 'wpsvg' ), '<a href="https://shortpixel.com/api-tools" target="_blank">ShortPixel</a>' ) ?></small></p>
						<p><a href="/wp-admin/options-general.php?page=wp-svg-images.php" class="button button-primary"><?php esc_html_e( 'Go to Settings', 'wpsvg' ) ?></a></p>
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

		function wp_handle_upload_prefilter( $file ){
			if( $file['type'] === 'image/svg+xml' ){
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

		function is_gzipped( $svg_code ){
			if( function_exists('mb_strpos') ){
				return 0 === mb_strpos( $svg_code, "\x1f" . "\x8b" . "\x08" );
			}else{
				return 0 === strpos( $svg_code, "\x1f" . "\x8b" . "\x08" );
			}
		}

		function plugins_loaded(){
			load_plugin_textdomain( 'wpsvg', FALSE, basename( __DIR__ ) . '/languages/' );
		}

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

		function filter_plugin_actions( $links, $file ){
			array_unshift( $links, '<a href="options-general.php?page=' . basename( __FILE__ ) . '">' . __( 'Settings', 'wpsvg' ) . '</a>' );
			return $links;
		}

		function plugin_init(){
			$this->settings = get_option('wpsvg_settings');
		}

		function admin_options_page(){
			global $wp_roles;

			if( get_current_screen()->id != $this->plugin_admin_page ) return;

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

		function fl_module_upload_regex( $regex, $type, $ext, $file ){
			if( $ext == 'svg' || $ext == 'svgz' ){
				$regex['photo'] = str_replace( '|png|', '|png|svgz?|', $regex['photo'] );
			}
			return $regex;
		}

		function fix_svg_thumbnail_size(){
			echo '<style>.attachment-info .thumbnail img[src$=".svg"],#postimagediv .inside img[src$=".svg"]{width:100%}</style>';
		}

		function wp_generate_attachment_metadata( $metadata, $attachment_id ){
			if( get_post_mime_type( $attachment_id ) == 'image/svg+xml' ){
				$svg_path = get_attached_file( $attachment_id );
				$dimensions = $this->svg_dimensions( $svg_path );
				$metadata['width'] = $dimensions->width;
				$metadata['height'] = $dimensions->height;
			}
			return $metadata;
		}

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

		public function add_svg_support(){
			if( is_admin() && get_option('WPSVGActivated') ){
				delete_option( 'WPSVGActivated' );
				wp_redirect( admin_url( 'options-general.php?page=' . basename( __FILE__ ) ) );
			}

			function svg_thumbs( $content ){
				return apply_filters( 'final_output', $content );
			}

			ob_start( 'svg_thumbs' );

			add_filter( 'final_output', array( $this, 'final_output' ) );
			add_filter( 'wp_prepare_attachment_for_js', array( $this, 'wp_prepare_attachment_for_js' ), 10, 3 );
		}

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

		function svg_dimensions( $svg ){
			$svg = simplexml_load_file( $svg );
			$width = 0;
			$height = 0;
			if( $svg ){
				$attributes = $svg->attributes();
				if( isset( $attributes->width, $attributes->height ) ){
					$width = floatval( $attributes->width );
					$height = floatval( $attributes->height );
				}elseif( isset( $attributes->viewBox ) ){
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

	register_activation_hook( __FILE__, function(){
		add_option( 'WPSVGActivated', 1 );
	});

	new WPSVG();
}
