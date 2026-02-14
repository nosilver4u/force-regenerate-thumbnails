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
	 * [--start-over]
	 * : Start over and clear any previous resume point.
	 *
	 * ## EXAMPLES
	 *
	 *     wp force-regenerate-thumbnails
	 *     wp force-regenerate-thumbnails --ids=123,456
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
			$resume_position = (int) get_option( 'frt_last_regenerated', PHP_INT_MAX );
			if ( $resume_position < 1 ) {
				$resume_position = PHP_INT_MAX;
			}
			if ( extension_loaded( 'imagick' ) ) {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT ID FROM ' . $wpdb->posts . ' WHERE ID < %d AND post_type = %s AND (post_mime_type LIKE %s OR post_mime_type LIKE %s) ORDER BY ID DESC',
						$resume_position,
						'attachment',
						'%image%',
						'%pdf%'
					)
				);
			} else {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT ID FROM ' . $wpdb->posts . ' WHERE ID < %d AND post_type = %s AND post_mime_type LIKE %s ORDER BY ID DESC',
						$resume_position,
						'attachment',
						'%image%'
					)
				);
			}
		}

		if ( empty( $ids ) ) {
			delete_option( 'frt_last_regenerated' );
			WP_CLI::warning( __( 'No images found to process.', 'force-regenerate-thumbnails' ) );
			return;
		}

		$total   = count( $ids );
		$success = 0;
		$fail    = 0;
		// translators: %d: number of images.
		WP_CLI::log( sprintf( __( 'Regenerating thumbnails for %d media items:', 'force-regenerate-thumbnails' ), $total ) );
		$progress = \WP_CLI\Utils\make_progress_bar( __( 'Regenerating thumbnails', 'force-regenerate-thumbnails' ), $total );

		foreach ( $ids as $id ) {
			try {
				// Mimic AJAX handler logic.
				if ( apply_filters( 'regenerate_thumbs_skip_image', false, $id ) ) {
					update_option( 'frt_last_regenerated', $id );
					/* translators: %d: attachment ID number */
					WP_CLI::log( sprintf( __( 'Skipped: %d', 'force-regenerate-thumbnails' ), (int) $id ) );
					$progress->tick();
					continue;
				}

				$image = get_post( $id );
				if ( is_null( $image ) || 'attachment' !== $image->post_type || ( ! str_starts_with( $image->post_mime_type, 'image/' ) && 'application/pdf' !== $image->post_mime_type ) ) {
					// translators: %d: The attachment ID that was invalid or not found.
					throw new Exception( sprintf( __( 'Failed: %d is an invalid media ID.', 'force-regenerate-thumbnails' ), $id ) );
				}
				if ( 'application/pdf' === $image->post_mime_type && ! extension_loaded( 'imagick' ) ) {
					throw new Exception( __( 'Failed: The imagick extension is required for PDF files.', 'force-regenerate-thumbnails' ) );
				}
				// translators: %d: SVG attachment ID.
				if ( 'image/svg+xml' === $image->post_mime_type ) {
					update_option( 'frt_last_regenerated', $id );
					// translators: %d: SVG attachment ID.
					WP_CLI::log( sprintf( __( 'Skipped: %d is a SVG', 'force-regenerate-thumbnails' ), $id ) );
					$progress->tick();
					continue;
				}

				$meta           = wp_get_attachment_metadata( $id );
				$image_fullpath = $force->get_attachment_path( $id, $meta );
				if ( empty( $image_fullpath ) || false === realpath( $image_fullpath ) ) {
					throw new Exception( __( 'The originally uploaded image file cannot be found.', 'force-regenerate-thumbnails' ) );
				}

				// Delete old thumbnails via metadata.
				$file_info = pathinfo( $image_fullpath );
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
							do_action( 'regenerate_thumbs_pre_delete', $thumb_fullpath );
							unlink( $thumb_fullpath );
							if ( is_file( $thumb_fullpath . '.webp' ) ) {
								unlink( $thumb_fullpath . '.webp' );
							}
							clearstatcache();
							do_action( 'regenerate_thumbs_post_delete', $thumb_fullpath );
						}
					}
				}

				// Workaround to find thumbnails not in metadata.
				$file_stem = $force->remove_from_end( $file_info['filename'], '-scaled' ) . '-';

				$files = array();
				$path  = opendir( $file_info['dirname'] );

				if ( false !== $path ) {
					$thumb = readdir( $path );
					while ( false !== $thumb ) {
						if ( str_starts_with( $thumb, $file_stem ) && str_ends_with( $thumb, $file_info['extension'] ) ) {
							$files[] = $thumb;
						}
						$thumb = readdir( $path );
					}
					closedir( $path );
					sort( $files );
				}

				foreach ( $files as $thumb ) {
					$thumb_fullpath = trailingslashit( $file_info['dirname'] ) . $thumb;
					if ( apply_filters( 'regenerate_thumbs_weak', false, $thumb_fullpath ) ) {
						continue;
					}

					$thumb_info  = pathinfo( $thumb_fullpath );
					$valid_thumb = explode( $file_stem, $thumb_info['filename'] );
					// This ensures we only target files that start with the original filename, but are also longer than the original.
					// Otherwise, we might delete the original image, since the while() does not preclude the original.
					if ( '' === $valid_thumb[0] && ! empty( $valid_thumb[1] ) ) {
						// Further, if the thumbnail name appendage has 'scaled-' in it, we need to remove it for the dimension check coming up.
						if ( 0 === strpos( $valid_thumb[1], 'scaled-' ) ) {
							$valid_thumb[1] = str_replace( 'scaled-', '', $valid_thumb[1] );
						}
						$dimension_thumb = explode( 'x', $valid_thumb[1] );
						if ( 2 === count( $dimension_thumb ) ) {
							// Thus we only remove files with an appendage like '-150x150' or '-150x150-scaled'.
							if ( is_numeric( $dimension_thumb[0] ) && is_numeric( $dimension_thumb[1] ) ) {
								do_action( 'regenerate_thumbs_pre_delete', $thumb_fullpath );
								unlink( $thumb_fullpath );
								if ( is_file( $thumb_fullpath . '.webp' ) ) {
									unlink( $thumb_fullpath . '.webp' );
								}
								clearstatcache();
								do_action( 'regenerate_thumbs_post_delete', $thumb_fullpath );
							}
						}
					}
				}

				// Regenerate thumbnails.
				if ( function_exists( 'wp_get_original_image_path' ) ) {
					$original_path = apply_filters( 'regenerate_thumbs_original_image', wp_get_original_image_path( $id, true ) );
				}
				if ( empty( $original_path ) || ! is_file( $original_path ) ) {
					$regen_path    = $image_fullpath;
					$original_path = $image_fullpath;
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

				if ( ! empty( $meta['original_image'] ) && is_file( $original_path ) && empty( $metadata['original_image'] ) ) {
					$metadata['original_image'] = $meta['original_image'];
				}
				wp_update_attachment_metadata( $id, $metadata );
				do_action( 'regenerate_thumbs_post_update', $image->ID, $regen_path );
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
		delete_option( 'frt_last_regenerated' );
		// translators: 1: Success count, 2: Failure count.
		WP_CLI::success( sprintf( __( 'Done. Success: %1$d, Failures: %2$d', 'force-regenerate-thumbnails' ), $success, $fail ) );
	}
}

WP_CLI::add_command( 'force-regenerate-thumbnails', 'Force_Regenerate_Thumbnails_CLI' );
