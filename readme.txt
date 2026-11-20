=== WP SVG Images ===
Contributors: shortpixel, kubiq
Donate link: https://www.paypal.me/resizeImage
Tags: svg, svg support, svg upload, sanitization
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 7.2
Stable tag: 4.6
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

= 4.6 =

🔒 The Security & Sanitization Update

Release Date: November 20, 2026

🔒 Security

* Sanitization by File Type: Every file accepted as an SVG (`.svg` or `.svgz`) is now sanitized based on its extension, no matter what the content detection reports. Previously, a file starting with a comment or a line break, as well as any `.svgz` file, could skip the sanitization. First reported by [@kta1kri](https://github.com/kta1kri) and also safely disclosed by Suresh Pantha via the WPScan team.
* SVG Animations: Animation elements can no longer change a link target or another URL attribute into a script, preventing a potential stored cross-site scripting issue in Safari and other WebKit browsers, safely disclosed by Pierre Rudloff via the WPScan team.
* All Upload Paths Covered: SVG files added through the REST API, sideloads (for example images imported from a URL) and WP-CLI imports are now sanitized as well.
* WP All Import: SVG and SVGZ files imported with WP All Import are now always sanitized, and a file that cannot be sanitized is no longer kept.
* Sanitizer Library Update: Updated the bundled SVG sanitizer to version 1.1.1, which brings several security fixes and much faster processing of large files.

⚙️ Improvements

* Works Right After Activation: Sanitized SVG uploads are now available as soon as the plugin is activated, for the roles that can upload files, exactly as the settings page shows. Before, the settings had to be saved once.
* Clear Upload Errors: A file that cannot be sanitized (for example a broken file or a web page renamed to `.svg`) is now refused with a clear message, instead of ending the upload with an error.
* Faster Uploads of Large SVGs: Checking large SVG files with many elements is now much faster, especially on PHP versions older than 8.3.
* Minimum Requirements: The plugin now declares its minimum requirements (WordPress 5.0 and PHP 7.4), so WordPress will no longer install or update it on older, unsupported versions.
* Translations: The plugin now uses its WordPress.org slug as text domain, so the translations from translate.wordpress.org are loaded automatically. Several texts were also corrected.

🛠️ Fixes

* SVGZ Dimensions: Compressed SVG files (`.svgz`) now get their correct width and height, and no longer trigger PHP warnings on upload.
* CDATA Sections: SVG files that contain CDATA sections (for example inline styles) are now sanitized correctly.

We highly recommend updating to this version to keep your site safe and secure! 💪🔐

= 4.5 =

🧩 The Compatibility Update

Release Date: May 11, 2026

🛠️ Fixes

* PHP 8.4+ Deprecation Fix: Resolved a deprecation warning to ensure smooth compatibility with PHP 8.4 and newer versions.
* MIME Type Validation: Added extra validation to prevent errors when uploaded files are sent with incorrect MIME types.

Update now for improved compatibility and more reliable uploads! 🚀

= 4.4 =

🔒 Security Update

Release Date: January 23, 2025

🛠️ Fixes

* Security Patch: Resolved another potential Cross-Site Scripting (XSS) vulnerability, responsibly disclosed by the amazing team at WordFence and discovered by Ryan Zegar.

We highly recommend updating to this version to ensure your site remains safe and secure. 💪🔐

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
