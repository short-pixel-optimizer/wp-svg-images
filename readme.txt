=== WP SVG Images ===
Contributors: shortpixel, kubiq
Donate link: https://www.paypal.me/resizeImage
Tags: svg, svg support, svg upload, sanitization
Requires at least: 3.0.1
Requires PHP: 5.6.40
Tested up to: 6.6
Stable tag: 4.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add SVG support to your WP website. Securely upload SVG files, automatic sanitization, Media Library preview.

== Description ==

**Securely upload SVG files to your Media Library. Uploaded SVG files are automatically sanitized.**

SVG stands for [Scalable Vector Graphics](https://en.wikipedia.org/wiki/Scalable_Vector_Graphics) and is probably the most efficient way to display images. 
WP SVG Images Plugin is an **easy-to-use and lightweight plugin** that allows you to upload SVG files to your media library safely and easily.

= Features =
* Support for SVG uploads to your Media Library.
* Sanitize uploaded SVG files. Malicious SVG/XML files are rejected from upload.
* Admin configurable SVG support for different user roles. Ability to disable SVG upload for different user roles.
* Different user roles can upload and/or sanitize the uploaded SVG images.
* SVG preview in Media Library.

= Support =
24/7 SVG support offered by <a href="https://shortpixel.com">ShortPixel</a> [here](https://shortpixel.com/contact) or [here](https://wordpress.org/support/plugin/wp-svg-images/).


= Recommended plugins =
This plugin is supported & maintained by [ShortPixel](https://shortpixel.com/).
Other popular plugins by ShortPixel: 
[FastPixel Caching](https://wordpress.org/plugins/fastpixel-website-accelerator/) - WP Optimization made easy
[ShortPixel Image Optimizer](https://wordpress.org/plugins/shortpixel-image-optimiser/) - Image optimization & compression for all the images on your website, including WebP delivery – ShortPixel Image Optimizer.
[ShortPixel Adaptive Images](https://wordpress.org/plugins/shortpixel-adaptive-images/) - On-the-fly image optimization & CDN delivery.
[Enable Media Replace](https://wordpress.org/plugins/enable-media-replace/) - Easily replace images or files in Media Library.
[reGenerate Thumbnails Advanced](https://wordpress.org/plugins/regenerate-thumbnails-advanced/) - Easily regenerate thumbnails.
[Resize Image After Upload](https://wordpress.org/plugins/resize-image-after-upload/) - Automatically resize each uploaded image.


## Hooks for developers

#### WPSVG_setAllowedTags
Allows you to specify more tags that will be not removed during sanitization

`add_filter( 'WPSVG_setAllowedTags', 'my_custom_allowed_svg_tags', 10, 1 );
function my_custom_allowed_svg_tags( $tags ){
	$tags[] = 'path';
	return $tags;
}`

#### WPSVG_setAllowedAttrs
Allows you to specify more attributes that will be not removed during sanitization

`add_filter( 'WPSVG_setAllowedAttrs', 'my_custom_allowed_svg_attributes', 10, 1 );
function my_custom_allowed_svg_attributes( $attributes ){
	$attributes[] = 'fill';
	return $attributes;
}`


== Installation ==

1. Upload `wp-svg-images` directory to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress


== Changelog ==
= 4.3 =
Release date: June 20th, 2024
* Fix: Patched a Stored Cross-Site Scripting vulnerability found by Colin Xu and responsibly disclosed by the WordFence team;
* Compat: Added compatibity with WP All Import plugin;
* Compat: Tested with the latest versions of WordPress (6.6) and PHP (8.3).

= 4.2 =
Release date: April 6th, 2023
* Compat: Tested on WordPress 6.2;
* Compat: Updated SVG sanitizer scripts;
* Tweak: Skip percentage width and height when reading SVG dimensions.

= 4.1 =
Release date: August 1st, 2022
* Tweak: auto hide the settings notice after visiting the settings page;
* Compat: tested the compatibility with WordPress 6.0;
* Fix: the settings link and button from the notice now work fine on all WordPress installs.

= 4.0 =
Release date: March 29th, 2022
* New: joined the ShortPixel family;
* New: added SVG sanitization;
* New: added settings page where you can enable/disable SVG upload per user role;
* Compat: deprecated `WP_SVG_FOR_EVERYONE`.

= 3.7 =
* tested on WordPress 5.9

= 3.6 =
* fix typo in last update

= 3.5 =
* tested on WordPress 5.8
* fix missing width and height for core/image block

= 3.4 =
* allow SVG uploads only for administrators and editors

= 3.3 =
* tested on WordPress 5.7

= 3.2 =
* tested on WordPress 5.4

= 3.1 =
* tested on WordPress 5.3

= 3.0 =
* added support for Beaver Builder media uploader

= 2.9 =
* tested on WordPress 5.0

= 2.8 =
* fix SVG size as featured image

= 2.7 =
* earlier upload_mimes filter init fix

= 2.6 =
* svg sizing css removed because of many conflicts

= 2.5 =
* fixed svg icon size in plugins updating listing

= 2.4 =
* SVN commit problem

= 2.3 =
* fixed svg icon size in plugins listing

= 2.2 =
* convert svg width and height to float number [PX]

= 2.1 =
* added svg width and height metadata

= 2.0 =
* added svgz support
* fixed svg thumbnails

= 1.4 =
* repair count() error

= 1.3 =
* 4.9 compatibility

= 1.2 =
* added size calculation fix for wp_get_attachment_image_src

= 1.1 =
* fix for WP4.7.1 bug

= 1.0 =
* First version
