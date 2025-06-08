<?php
/**
 * WP-CLI command for Force Regenerate Thumbnails.
 *
 * @package ForceRegenerateThumbnails
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Regenerate thumbnails and delete old ones via WP-CLI.
 */
class Force_Regenerate_Thumbnails_CLI {

	/**
	 * Regenerate thumbnails for all images or specific IDs.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated list of attachment IDs to process. If omitted, all images will be processed.
	 *
	 * [--resume]
	 * : Resume from the last interrupted image (if any).
	 *
	 * [--start-over]
	 * : Start over and clear any previous resume point.
	 *
	 * ## EXAMPLES
	 *
	 *     wp force-regenerate-thumbnails
	 *     wp force-regenerate-thumbnails --ids=123,456
	 *     wp force-regenerate-thumbnails --resume
	 *     wp force-regenerate-thumbnails --start-over
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @throws Exception If an error occurs during regeneration.
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		$force = force_regenerate_thumbnails();

		if ( isset( $assoc_args['start-over'] ) ) {
			delete_option( 'frt_last_regenerated' );
			WP_CLI::success( __( 'Resume point cleared. Starting over.', 'force-regenerate-thumbnails' ) );
		}

		$ids = array();
		if ( ! empty( $assoc_args['ids'] ) ) {
			$ids = array_map( 'intval', explode( ',', $assoc_args['ids'] ) );
		} else {
			$resume_position = 0;
			if ( isset( $assoc_args['resume'] ) ) {
				$resume_position = (int) get_option( 'frt_last_regenerated', 0 );
			}
			if ( $resume_position ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared below.
				$query = $wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE ID < %d AND post_type = 'attachment' AND post_mime_type LIKE %s ORDER BY ID DESC",
					$resume_position,
					'%image%'
				);
				$ids = $wpdb->get_col( $query );
			} else {
				$query = $wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE %s ORDER BY ID DESC",
					'%image%'
				);
				$ids = $wpdb->get_col( $query );
			}
		}

		if ( empty( $ids ) ) {
			WP_CLI::warning( __( 'No images found to process.', 'force-regenerate-thumbnails' ) );
			return;
		}

		$total   = count( $ids );
		$success = 0;
		$fail    = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( __( 'Regenerating thumbnails', 'force-regenerate-thumbnails' ), $total );

		foreach ( $ids as $id ) {
			try {
				// Mimic AJAX handler logic.
				$image = get_post( $id );
				// translators: %d: Media ID.
				if ( is_null( $image ) || 'attachment' !== $image->post_type || ( 'image/' !== substr( $image->post_mime_type, 0, 6 ) && 'application/pdf' !== $image->post_mime_type ) ) {
					throw new Exception( sprintf( __( 'Invalid media ID: %d', 'force-regenerate-thumbnails' ), $id ) );
				}
				if ( 'application/pdf' === $image->post_mime_type && ! extension_loaded( 'imagick' ) ) {
					throw new Exception( __( 'The imagick extension is required for PDF files.', 'force-regenerate-thumbnails' ) );
				}
				// translators: %d: SVG attachment ID.
				if ( 'image/svg+xml' === $image->post_mime_type ) {
					update_option( 'frt_last_regenerated', $id );
					WP_CLI::log( sprintf( __( 'Skipped SVG: %d', 'force-regenerate-thumbnails' ), $id ) );
					$progress->tick();
					continue;
				}

				$meta          = wp_get_attachment_metadata( $id );
				$image_fullpath = $force->get_attachment_path( $id, $meta );
				if ( empty( $image_fullpath ) || false === realpath( $image_fullpath ) ) {
					throw new Exception( __( 'The original image file cannot be found.', 'force-regenerate-thumbnails' ) );
				}

				// Delete old thumbnails (mimic plugin logic).
				$file_info = pathinfo( $image_fullpath );
				if ( ! empty( $meta['sizes'] ) && is_iterable( $meta['sizes'] ) ) {
					foreach ( $meta['sizes'] as $size_data ) {
						if ( empty( $size_data['file'] ) ) {
							continue;
						}
						$thumb_fullpath = trailingslashit( $file_info['dirname'] ) . wp_basename( $size_data['file'] );
						if ( $thumb_fullpath !== $image_fullpath && is_file( $thumb_fullpath ) ) {
							@unlink( $thumb_fullpath );
							if ( is_file( $thumb_fullpath . '.webp' ) ) {
								@unlink( $thumb_fullpath . '.webp' );
							}
						}
					}
				}

				// Regenerate thumbnails.
				if ( function_exists( 'wp_get_original_image_path' ) ) {
					$original_path = apply_filters( 'regenerate_thumbs_original_image', wp_get_original_image_path( $id, true ) );
				}
				if ( empty( $original_path ) || ! is_file( $original_path ) ) {
					$regen_path = $image_fullpath;
				} elseif ( preg_match( '/e\d{10,}\./', $image_fullpath ) ) {
					$regen_path = $image_fullpath;
				} else {
					$regen_path = $original_path;
				}

				$metadata = wp_generate_attachment_metadata( $id, $regen_path );
				if ( is_wp_error( $metadata ) ) {
					throw new Exception( $metadata->get_error_message() );
				}
				if ( empty( $metadata ) ) {
					throw new Exception( __( 'Unknown failure.', 'force-regenerate-thumbnails' ) );
				}
				wp_update_attachment_metadata( $id, $metadata );
				update_option( 'frt_last_regenerated', $id );
				++$success;
			} catch ( Exception $e ) {
				++$fail;
				update_option( 'frt_last_regenerated', $id );
				WP_CLI::warning( $e->getMessage() );
			}
			$progress->tick();
		}
		$progress->finish();
		// translators: 1: Success count, 2: Failure count.
		WP_CLI::success( sprintf( __( 'Done. Success: %1$d, Failures: %2$d', 'force-regenerate-thumbnails' ), $success, $fail ) );
	}
}

WP_CLI::add_command( 'force-regenerate-thumbnails', 'Force_Regenerate_Thumbnails_CLI' );
