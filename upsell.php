<?php
/**
 * ShortPixel Image Optimizer upsell box shown on the settings page.
 *
 * Included from WPSVG::admin_options_page() when the plugin is not installed
 * or not active; `$this` is the WPSVG instance and its $spio_installed flag
 * decides whether the "Install now" or the "Activate" link is visible. The
 * links are followed by assets/js/spio-upsell.js through AJAX GET requests.
 *
 * @todo assets/js/spio-upsell.js switches the box to its next state on any
 *       successful HTTP response, and the installer page answers 200 even
 *       when the install fails, so a failed install still shows "Activate".
 *       Check the response content before changing state.
 */
?>
<section>

	<?php $plugin = 'shortpixel-image-optimiser' ?>
	<?php $path = 'shortpixel-image-optimiser/wp-shortpixel.php' ?>
	<div class="shortpixel-offer" data-plugin="<?php echo $plugin ?>">
		<div class="img-wrapper">
			<img src="<?php echo plugins_url( '/assets/images/' . $plugin . '.svg', __FILE__ ) ?>" alt="ShortPixel" width="40" height="40" loading="lazy">
		</div>
		<h4 class="grey">
			<?php esc_html_e( 'ShortPixel Image Optimizer', 'wpsvg' ) ?>
		</h4>
		<h3 class="red ucase">
			<?php esc_html_e( 'Is your website slow?', 'wpsvg' ) ?>
		</h3>
		<br>
		<h3 class="cyan ucase">
			<?php esc_html_e( 'Optimize all images automatically', 'wpsvg' ) ?>
		</h3>
		<p class="button-wrapper">
			<a href="<?php echo wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . $plugin ), 'install-plugin_' . $plugin ) ?>" class="upsell-installer <?php echo $this->spio_installed ? 'hidden' : '' ?>" data-alt-text="<?php echo esc_attr__( 'Installing ...', 'wpsvg' ) ?>">
				<?php esc_html_e( 'Install now', 'wpsvg' ) ?>
			</a>
			<a href="<?php echo 'plugins.php?action=activate&plugin=' . urlencode( $path ) . '&plugin_status=all&paged=1&s&_wpnonce=' . urlencode( wp_create_nonce( 'activate-plugin_' . $path ) ) ?>" class="upsell-activate <?php echo $this->spio_installed ? ( $this->spio_active ? '' : '' ) : 'hidden' ?>">
				<?php esc_html_e( 'Activate', 'wpsvg' ) ?>
			</a>
		</p>
		<h4 class="upsell-activate-done hidden">
			<?php esc_html_e( 'Shortpixel activated!', 'wpsvg' ) ?>
		</h4>
	</div>

</section>