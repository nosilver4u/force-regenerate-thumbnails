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
Description: Delete and REALLY force the regeneration of thumbnails.
Version: 2.2.2
Requires at least: 6.5
Requires PHP: 7.4
Author: Exactly WWW
Author URI: http://ewww.io/about/
License: GPLv2
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

require_once trailingslashit( __DIR__ ) . 'class-forceregeneratethumbnails.php';

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

//
// --- WP-CLI Support Added Below ---
//

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Adds WP-CLI support for force regenerating thumbnails.
	 */
	class ForceRegenerateThumbnails_CLI extends WP_CLI_Command {

		/**
		 * The main ForceRegenerateThumbnails object.
		 *
		 * @var ForceRegenerateThumbnails
		 */
		private $frt;

		/**
		 * Constructor.
		 */
		public function __construct() {
			// Get the main plugin object to reuse its helper methods.
			$this->frt = force_regenerate_thumbnails();
		}

		/**
		 * Force regenerates thumbnails for attachments.
		 *
		 * Deletes all old thumbnails and regenerates them for all registered sizes.
		 * This is a command-line interface for the "Regenerate All Thumbnails"
		 * feature, which is useful for large media libraries.
		 *
		 * [--force]
		 * : Skip the confirmation prompt.
		 *
		 * ## EXAMPLES
		 *
		 *     # Regenerate all thumbnails, with a confirmation prompt
		 *     wp frt regenerate
		 *
		 *     # Force regenerate all thumbnails without confirmation
		 *     wp frt regenerate --force
		 */
		public function regenerate( $args, $assoc_args ) {
			WP_CLI::confirm( 'Are you sure you want to force regenerate all thumbnails? This will delete existing thumbnails and cannot be undone.', $assoc_args );

			global $wpdb;
			$attachment_ids = $wpdb->get_col(
				"SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type != 'image/svg+xml' AND (post_mime_type LIKE 'image/%' OR post_mime_type LIKE 'application/pdf') ORDER BY ID DESC"
			);

			if ( empty( $attachment_ids ) ) {
				WP_CLI::success( 'No attachments found to regenerate.' );
				return;
			}

			$count = count( $attachment_ids );
			WP_CLI::log( "Found {$count} attachments to process." );

			$progress  = \WP_CLI\Utils\make_progress_bar( 'Regenerating Thumbnails', $count );
			$successes = 0;
			$failures  = 0;

			foreach ( $attachment_ids as $id ) {
				$response = $this->process_single_image( $id );
				if ( is_wp_error( $response ) ) {
					WP_CLI::warning( "Failed to process attachment ID {$id} (" . get_the_title( $id ) . '): ' . $response->get_error_message() );
					$failures++;
				} else {
					$successes++;
				}
				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( "Finished regenerating thumbnails. Success: {$successes}, Failures: {$failures}." );
		}

		/**
		 * Processes a single attachment.
		 *
		 * This is a CLI-friendly adaptation of the ForceRegenerateThumbnails->ajax_process_image() method.
		 *
		 * @param int $id The attachment ID.
		 * @return true|\WP_Error True on success, WP_Error object on failure.
		 */
		private function process_single_image( $id ) {
			if ( apply_filters( 'regenerate_thumbs_skip_image', false, $id ) ) {
				return new WP_Error( 'skipped', 'Skipped by the "regenerate_thumbs_skip_image" filter.' );
			}

			$image = get_post( $id );

			if ( ! $image ) {
				return new WP_Error( 'invalid_id', 'Invalid attachment ID.' );
			}

			if ( 'attachment' !== $image->post_type || ( 'image/' !== substr( $image->post_mime_type, 0, 6 ) && 'application/pdf' !== $image->post_mime_type ) ) {
				return new WP_Error( 'invalid_mime_type', 'Not a supported image or PDF file.' );
			}

			if ( 'application/pdf' === $image->post_mime_type && ! extension_loaded( 'imagick' ) ) {
				return new WP_Error( 'imagick_required', 'The "imagick" PHP extension is required to process PDF files.' );
			}

			$meta           = wp_get_attachment_metadata( $image->ID );
			$image_fullpath = $this->frt->get_attachment_path( $image->ID, $meta );

			if ( empty( $image_fullpath ) || ! file_exists( $image_fullpath ) ) {
				return new WP_Error( 'file_not_found', 'The source file cannot be found.' );
			}

			// --- Start Deletion Logic (from ajax_process_image) ---
			$file_info = pathinfo( $image_fullpath );

			// Delete thumbnails based on metadata.
			if ( ! empty( $meta['sizes'] ) && is_iterable( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size_data ) {
					if ( empty( $size_data['file'] ) ) {
						continue;
					}
					$thumb_fullpath = trailingslashit( $file_info['dirname'] ) . wp_basename( $size_data['file'] );
					if ( apply_filters( 'regenerate_thumbs_weak', false, $thumb_fullpath ) ) {
						continue;
					}
					if ( $thumb_fullpath !== $image_fullpath && is_file( $thumb_fullpath ) ) {
						@unlink( $thumb_fullpath );
						if ( is_file( $thumb_fullpath . '.webp' ) ) {
							@unlink( $thumb_fullpath . '.webp' );
						}
					}
				}
			}

			// Hacky way to find and delete remaining thumbnails.
			$file_stem = $this->frt->remove_from_end( $file_info['filename'], '-scaled' ) . '-';
			$dir_path  = $file_info['dirname'];
			if ( is_dir( $dir_path ) && $dir = opendir( $dir_path ) ) { // phpcs:ignore
				while ( false !== ( $thumb = readdir( $dir ) ) ) {
					if ( 0 === strpos( $thumb, $file_stem ) && str_ends_with( $thumb, '.' . $file_info['extension'] ) ) {
						$thumb_fullpath = trailingslashit( $dir_path ) . $thumb;
						if ( apply_filters( 'regenerate_thumbs_weak', false, $thumb_fullpath ) ) {
							continue;
						}
						@unlink( $thumb_fullpath );
						if ( is_file( $thumb_fullpath . '.webp' ) ) {
							@unlink( $thumb_fullpath . '.webp' );
						}
					}
				}
				closedir( $dir );
			}
			// --- End Deletion Logic ---

			clearstatcache();

			// --- Start Regeneration Logic (from ajax_process_image) ---
			$original_path = '';
			if ( function_exists( 'wp_get_original_image_path' ) ) {
				$original_path = apply_filters( 'regenerate_thumbs_original_image', wp_get_original_image_path( $image->ID, true ) );
			}

			if ( empty( $original_path ) || ! is_file( $original_path ) ) {
				$regen_path = $image_fullpath;
			} elseif ( preg_match( '/e\d{10,}\./', $image_fullpath ) ) {
				$regen_path = $image_fullpath;
			} else {
				$regen_path = $original_path;
			}

			$new_meta = wp_generate_attachment_metadata( $image->ID, $regen_path );

			if ( is_wp_error( $new_meta ) ) {
				return $new_meta;
			}
			if ( empty( $new_meta ) ) {
				return new WP_Error( 'generation_failed', 'Thumbnail generation failed for an unknown reason.' );
			}
			if ( ! empty( $meta['original_image'] ) && ! empty( $original_path ) && is_file( $original_path ) && empty( $new_meta['original_image'] ) ) {
				$new_meta['original_image'] = $meta['original_image'];
			}

			wp_update_attachment_metadata( $image->ID, $new_meta );
			do_action( 'regenerate_thumbs_post_update', $image->ID, $regen_path );
			// --- End Regeneration Logic ---

			return true;
		}
	}

	WP_CLI::add_command( 'frt', 'ForceRegenerateThumbnails_CLI' );
}