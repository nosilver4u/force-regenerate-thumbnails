<?php
/**
 * Main file/class for Force Regenerate Thumbnails.
 *
 * @link https://wordpress.org/plugins/force-regenerate-thumbnails/
 * @package ForceRegenerateThumbnails
 */

/*
Plugin Name: Force Regenerate Thumbnails
Plugin URI: https://wordpress.org/plugins/force-regenerate-thumbnails/
Description: Delete and REALLY force the regenerate thumbnail.
Version: 2.1.2
Requires at least: 4.9
Requires PHP: 7.2
Author: Exactly WWW
Author URI: http://ewww.io/about/
License: GPLv2
*/

/*
Debug URL
wp-admin/admin-ajax.php?action=regeneratethumbnail&id=
*/

/**
 * Force GD for Image handle (WordPress 3.5 or better)
 * Thanks (@nikcree)
 *
 * @since 1.5
 * @param array $editors A list of image editors within WordPress.
 */
function ms_image_editor_default_to_gd_fix( $editors ) {
	$gd_editor = 'WP_Image_Editor_GD';

	$editors = array_diff( $editors, array( $gd_editor ) );
	array_unshift( $editors, $gd_editor );

	return $editors;
}
if ( apply_filters( 'regenerate_thumbs_force_gd', false ) ) {
	add_filter( 'wp_image_editors', 'ms_image_editor_default_to_gd_fix' );
}

if ( ! function_exists( 'str_ends_with' ) ) {
	/**
	 * Polyfill for `str_ends_with()` function added in WP 5.9 or PHP 8.0.
	 *
	 * Performs a case-sensitive check indicating if
	 * the haystack ends with needle.
	 *
	 * @since 2.1.0
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to search for in the `$haystack`.
	 * @return bool True if `$haystack` ends with `$needle`, otherwise false.
	 */
	function str_ends_with( $haystack, $needle ) {
		if ( '' === $haystack && '' !== $needle ) {
			return false;
		}

		$len = strlen( $needle );

		return 0 === substr_compare( $haystack, $needle, -$len, $len );
	}
}

require_once( trailingslashit( __DIR__ ) . 'class-forceregeneratethumbnails.php' );

/**
 * Initialize plugin and return FRT object.
 *
 * @return object The one and only ForceRegenerateThumbnails instance.
 */
function force_regenerate_thumbnails() {
	global $force_regenerate_thumbnails;
	if ( ! is_object( $force_regenerate_thumbnails ) || ! is_a( $force_regenerate_thumbnails, 'ForceRegenerateThumbnails' ) ) {
		$force_regenerate_thumbnails = new ForceRegenerateThumbnails();
	}
	return $force_regenerate_thumbnails;
}
add_action( 'init', 'force_regenerate_thumbnails' );
