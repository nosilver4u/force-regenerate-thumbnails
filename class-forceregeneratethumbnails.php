<?php
/**
 * Main class for Force Regenerate Thumbnails.
 *
 * @link https://wordpress.org/plugins/force-regenerate-thumbnails/
 * @package ForceRegenerateThumbnails
 */

// TODO: include updates from forks.
// TODO: improve wording for bulk page.
// TODO: add lots of action hooks.
// TODO: figure out why a failure dumps JS code?

/**
 * Force Regenerate Thumbnails
 *
 * @since 1.0
 */
class ForceRegenerateThumbnails {

	/**
	 * Register ID of management page, or false if the user has no permissions.
	 *
	 * @access public
	 * @var string|bool $menu_id
	 * @since 1.0
	 */
	public $menu_id;

	/**
	 * User capability required to use this plugin.
	 *
	 * @access public
	 * @var string $capability
	 * @since 1.0
	 */
	public $capability;

	/**
	 * Version of the plugin.
	 *
	 * @access public
	 * @var float VERSION
	 * @since 2.1.0
	 */
	const VERSION = 206.02;

	/**
	 * Plugin initialization
	 *
	 * @access public
	 * @since 1.0
	 */
	function __construct() {

		add_action( 'admin_menu', array( &$this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueues' ) );
		add_action( 'wp_ajax_regeneratethumbnail', array( &$this, 'ajax_process_image' ) );
		add_filter( 'media_row_actions', array( &$this, 'add_media_row_action' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( &$this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( &$this, 'bulk_action_handler' ), 10, 3 );

		// Allow people to change what capability is required to use this plugin.
		$this->capability = apply_filters( 'regenerate_thumbs_cap', 'manage_options' );
	}

	/**
	 * Register the management page
	 *
	 * @access public
	 * @since 1.0
	 */
	function add_admin_menu() {
		$this->menu_id = add_management_page(
			__( 'Force Regenerate Thumbnails', 'force-regenerate-thumbnails' ),
			__( 'Force Regenerate Thumbnails', 'force-regenerate-thumbnails' ),
			$this->capability,
			'force-regenerate-thumbnails',
			array( &$this, 'force_regenerate_interface' )
		);
	}

	/**
	 * Enqueue the needed Javascript and CSS
	 *
	 * @param string $hook_suffix The hook suffix for the current admin page.
	 * @access public
	 * @since 1.0
	 */
	function admin_enqueues( $hook_suffix ) {

		if ( $hook_suffix !== $this->menu_id ) {
			return;
		}

		wp_enqueue_style( 'jquery-ui-regenthumbs', plugins_url( 'jquery-ui/redmond/jquery-ui-1.7.2.custom.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_style( 'force-regen-style', plugins_url( 'assets/style.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'force-regen-script', plugins_url( 'assets/regen.js', __FILE__ ), array( 'jquery-ui-progressbar' ), self::VERSION );
		// If the button was clicked.
		if ( ! empty( $_POST['force-regenerate-thumbnails'] ) || ! empty( $_REQUEST['ids'] ) ) {

			// Capability check.
			if ( ! current_user_can( $this->capability ) ) {
				wp_die( esc_html__( 'Access denied.', 'force-regenerate-thumbnails' ) );
			}

			// Form nonce check.
			check_admin_referer( 'force-regenerate-thumbnails' );

			// Create the list of image IDs.
			$images = array();
			if (
				! empty( $_REQUEST['ids'] ) &&
				(
					preg_match( '/^[\d,]+$/', sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ), $request_ids ) ||
					is_numeric( sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) )
				)
			) {
				if ( is_numeric( sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) ) ) {
					$images[] = (int) $_REQUEST['ids'];
				} else {
					$images = explode( ',', $request_ids[0] );
					array_walk( $images, 'intval' );
				}
				// $images = array_map( 'intval', explode( ',', trim( $_REQUEST['ids'], ',' ) ) );
				$ids = implode( ',', $images );
			} else {

				// Directly querying the database is normally frowned upon, but all
				// of the API functions will return the full post objects which will
				// suck up lots of memory. This is best, just not as future proof.
				$images = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND (post_mime_type LIKE '%%image%%' OR post_mime_type LIKE '%%pdf%%') ORDER BY ID DESC" );
				if ( empty( $images ) ) {
					echo '<p>' . esc_html__( 'You do not appear to have uploaded any images yet.', 'force-regenerate-thumbnails' ) . '</p></div>';
					return;
				}

				$ids = implode( ',', $images );
			}

			$count = count( $images );

			wp_localize_script(
				'force-regen-script',
				'regen_thumbs',
				array(
					'_wpnonce'      => wp_create_nonce( 'force-regenerate-attachment' ),
					'image_ids'     => $images,
					'image_count'   => $count,
					'stopping'      => esc_html__( 'Stopping...', 'force-regenerate-thumbnails' ),
					'unknown_error' => esc_html__( 'Unknown error occured.', 'force-regenerate-thumbnails' ),
				)
			);
		}
	}

	/**
	 * Add a "Force Regenerate Thumbnails" link to the media row actions
	 *
	 * @param array   $actions A list of existing Media row actions.
	 * @param WP_Post $post The current attachment/post object.
	 * @return array
	 * @access public
	 * @since 1.0
	 */
	function add_media_row_action( $actions, $post ) {
		// TODO: get current URI for the goback param.
		if ( 'image/' === substr( $post->post_mime_type, 0, 6 ) && current_user_can( $this->capability ) ) {
			$url = wp_nonce_url( admin_url( 'tools.php?page=force-regenerate-thumbnails&goback=1&ids=' . $post->ID ), 'force-regenerate-thumbnails' );

			$actions['regenerate_thumbnails'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr( __( 'Regenerate the thumbnails for this single image', 'force-regenerate-thumbnails' ) ) . '">' . __( 'Force Regenerate Thumbnails', 'force-regenerate-thumbnails' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Add "Force Regenerate Thumbnails" to the Bulk Actions media dropdown
	 *
	 * @param array $actions Bulk actions list.
	 * @return array
	 * @access public
	 * @since 1.0
	 */
	function add_bulk_actions( $actions ) {

		$delete = false;
		if ( ! empty( $actions['delete'] ) ) {
			$delete = $actions['delete'];
			unset( $actions['delete'] );
		}

		$actions['bulk_force_regenerate_thumbnails'] = __( 'Force Regenerate Thumbnails', 'force-regenerate-thumbnails' );

		if ( $delete ) {
			$actions['delete'] = $delete;
		}

		return $actions;
	}

	/**
	 * Add new items to the Bulk Actions using Javascript
	 *
	 * @access public
	 * @since 1.0
	 */
	function add_bulk_actions_via_javascript() {

		if ( ! current_user_can( $this->capability ) ) {
			return;
		}
		?>
		<script type="text/javascript">
			jQuery(document).ready(function($){
				$('select[name^="action"] option:last-child').before('<option value="bulk_force_regenerate_thumbnails"><?php echo esc_attr( __( 'Force Regenerate Thumbnails', 'force-regenerate-thumbnails' ) ); ?></option>');
			});
		</script>
		<?php
	}

	/**
	 * Handles the bulk actions POST
	 *
	 * @access public
	 * @since 1.0
	 *
	 * @param string $redirect_to The URL from whence we came.
	 * @param string $doaction The action requested.
	 * @param array  $post_ids An array of attachment ID numbers.
	 * @return string The URL to go back to when we are done handling the action.
	 */
	function bulk_action_handler( $redirect_to, $doaction, $post_ids ) {
		if ( empty( $doaction ) || 'bulk_force_regenerate_thumbnails' !== $doaction ) {
			return $redirect_to;
		}

		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return $redirect_to;
		}

		check_admin_referer( 'bulk-media' );
		$ids = implode( ',', array_map( 'intval', $post_ids ) );

		return add_query_arg(
			array(
				'_wpnonce' => wp_create_nonce( 'force-regenerate-thumbnails' ),
				'page'     => 'force-regenerate-thumbnails',
				'ids'      => $ids,
				'goback'   => esc_url( wp_get_referer() ),
			),
			admin_url( 'tools.php' )
		);
	}


	/**
	 * The user interface plus thumbnail regenerator
	 *
	 * @access public
	 * @since 1.0
	 */
	function force_regenerate_interface() {

		global $wpdb;
		$retry_url = wp_nonce_url(
			admin_url( 'tools.php?page=force-regenerate-thumbnails' ),
			'force-regenerate-thumbnails'
		);
		?>

<div id="frt-message" class="notice notice-success is-dismissible" style="display:none">
	<p><strong><?php esc_html_e( 'All done!', 'force-regenerate-thumbnails' ); ?></strong>
		<?php if ( ! empty( $_GET['goback'] ) ) : ?>
			<a href=<?php echo '"' . esc_url( sanitize_url( wp_unslash( $_GET['goback'] ) ) ) . '"'; ?>><?php esc_html_e( 'Go back to the previous page.', 'force-regenerate-thumbnails' ); ?></a>
		<?php endif; ?>
	</p>
	<a id="frt-retry-images" style="display:none" href="<?php echo esc_url( $retry_url ); ?>" class="button-secondary"><?php esc_html_e( 'Retry Failed Images', 'force-regenerate-thumbnails' ); ?></a>
</div>

<div class="wrap regenthumbs">
	<h2><?php esc_html_e( 'Force Regenerate Thumbnails', 'force-regenerate-thumbnails' ); ?></h2>

		<?php
		// If the button was clicked.
		if ( ! empty( $_POST['force-regenerate-thumbnails'] ) || ! empty( $_REQUEST['ids'] ) ) {

			// Capability check.
			if ( ! current_user_can( $this->capability ) ) {
				wp_die( esc_html__( 'Access denied.', 'force-regenerate-thumbnails' ) );
			}

			// Form nonce check.
			check_admin_referer( 'force-regenerate-thumbnails' );

			?>

	<p><?php esc_html_e( 'Please be patient while the thumbnails are regenerated. You will be notified via this page when the regenerating is completed.', 'force-regenerate-thumbnails' ); ?></p>

	<noscript><p><em><?php esc_html_e( 'You must enable Javascript in order to proceed!', 'force-regenerate-thumbnails' ); ?></em></p></noscript>

	<div id="regenthumbs-bar" style="position:relative;height:25px;">
		<div id="regenthumbs-bar-percent" style="position:absolute;left:50%;top:50%;width:300px;margin-left:-150px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
	</div>

	<p><input type="button" class="button hide-if-no-js" name="regenthumbs-stop" id="regenthumbs-stop" value="<?php esc_html_e( 'Abort Process', 'force-regenerate-thumbnails' ); ?>" /></p>

	<h3 class="title"><?php esc_html_e( 'Process Information', 'force-regenerate-thumbnails' ); ?></h3>

	<p>
			<?php
			/* translators: %d: the total number of images */
			printf( esc_html__( 'Total: %s', 'force-regenerate-thumbnails' ), '<span id="regenthumbs-debug-totalcount">0</span>' );
			echo '<br>';
			/* translators: %d: the number of successfully regenerated images */
			printf( esc_html__( 'Success: %s', 'force-regenerate-thumbnails' ), '<span id="regenthumbs-debug-successcount">0</span>' );
			echo '<br>';
			/* translators: %d: the number of regeneration failures */
			printf( esc_html__( 'Failure: %s', 'force-regenerate-thumbnails' ), '<span id="regenthumbs-debug-failurecount">0</span>' );
			?>
	</p>

	<ol id="regenthumbs-debuglist">
		<li style="display:none"></li>
	</ol>

			<?php
		} else {
			// No button click? Display the form.
			?>
	<form method="post" action="">
			<?php wp_nonce_field( 'force-regenerate-thumbnails' ); ?>

		<h3><?php esc_html_e( 'All Thumbnails', 'force-regenerate-thumbnails' ); ?></h3>

		<p><?php esc_html_e( 'Pressing the follow button, you can regenerate thumbnails for all images that you have uploaded to your blog.', 'force-regenerate-thumbnails' ); ?></p>

		<p>
			<noscript><p><em><?php esc_html_e( 'You must enable Javascript in order to proceed!', 'force-regenerate-thumbnails' ); ?></em></p></noscript>
			<input type="submit" class="button-primary hide-if-no-js" name="force-regenerate-thumbnails" id="force-regenerate-thumbnails" value="<?php esc_attr_e( 'Regenerate All Thumbnails', 'force-regenerate-thumbnails' ); ?>" />
		</p>

		</br>
		<h3><?php esc_html_e( 'Specific Thumbnails', 'force-regenerate-thumbnails' ); ?></h3>

			<?php /* translators: %s: Media Library (link) */ ?>
		<p><?php printf( esc_html__( 'You may regenerate thumbnails for specific images from the %s in List mode.', 'force-regenerate-thumbnails' ), '<a href="' . esc_url( admin_url( 'upload.php?mode=list' ) ) . '">' . esc_html__( 'Media Library', 'force-regenerate-thumbnails' ) . '</a>' ); ?></p>
	</form>
			<?php
		} // End if button
		?>
</div>

		<?php
	}


	/**
	 * Process a single image ID (this is an AJAX handler)
	 *
	 * @access public
	 * @since 1.0
	 * @throws Exception Any time we find an image we can't handle: permissions, corruption, doesn't exist, etc.
	 */
	function ajax_process_image() {
		if ( empty( $_REQUEST['id'] ) ) {
			$this->ob_clean();
			wp_die( wp_json_encode( array( 'error' => esc_html__( 'No attachment ID submitted.', 'force-regenerate-thumbnails' ) ) ) );
		}

		// No timeout limit.
		if ( $this->stl_check() ) {
			set_time_limit( 0 );
		}

		// Don't break the JSON result.
		error_reporting( 0 );
		$id = (int) $_REQUEST['id'];

		try {
			if ( ! current_user_can( $this->capability ) ) {
				throw new Exception( esc_html__( 'Your user account does not have permission to regenerate images.', 'force-regenerate-thumbnails' ) );
			}
			if ( empty( $_REQUEST['frt_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST['frt_wpnonce'] ), 'force-regenerate-attachment' ) ) {
				throw new Exception( esc_html__( 'Access token has expired, please reload the page.', 'force-regenerate-thumbnails' ) );
			}

			header( 'Content-type: application/json' );
			$image = get_post( $id );

			if ( is_null( $image ) ) {
				/* translators: %d: attachment ID number */
				throw new Exception( sprintf( esc_html__( 'Failed: %d is an invalid image ID.', 'force-regenerate-thumbnails' ), (int) $id ) );
			}

			if ( 'attachment' !== $image->post_type || 'image/' !== substr( $image->post_mime_type, 0, 6 ) ) {
				/* translators: %d: attachment ID number */
				throw new Exception( sprintf( esc_html__( 'Failed: %d is an invalid image ID.', 'force-regenerate-thumbnails' ), (int) $id ) );
			}

			$upload_dir = wp_get_upload_dir();
			$meta       = wp_get_attachment_metadata( $image->ID );

			// Get full-size image.
			$image_fullpath = $this->get_attachment_path( $image->ID, $meta );

			$debug_1 = $image_fullpath;
			$debug_2 = '';
			$debug_3 = '';
			$debug_4 = '';

			// Didn't get a valid image path.
			if ( empty( $image_fullpath ) || false === realpath( $image_fullpath ) ) {
				throw new Exception( esc_html__( 'The originally uploaded image file cannot be found.', 'force-regenerate-thumbnails' ) );
			}

			$thumb_deleted    = array();
			$thumb_error      = array();
			$thumb_regenerate = array();

			// Hack to find thumbnail.
			$file_info = pathinfo( $image_fullpath );
			$file_stem = $this->remove_from_end( $file_info['filename'], '-scaled' ) . '-';

			/**
			 * Try delete all thumbnails
			 */
			if ( ! empty( $meta['sizes'] ) && is_iterable( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size_data ) {
					if ( empty( $size_data['file'] ) ) {
						continue;
					}
					$thumb_fullpath = trailingslashit( $file_info['dirname'] ) . wp_basename( $size_data['file'] );
					if ( is_file( $thumb_fullpath ) ) {
						unlink( $thumb_fullpath );
						if ( is_file( $thumb_fullpath . '.webp' ) ) {
							unlink( $thumb_fullpath . '.webp' );
						}
						clearstatcache();
						if ( ! is_file( $thumb_fullpath ) ) {
							$thumb_deleted[] = sprintf( '%dx%d', $size_data['width'], $size_data['height'] );
						} else {
							$thumb_error[] = sprintf( '%dx%d', $size_data['width'], $size_data['height'] );
						}
					}
				}
			}

			$files = array();
			$path  = opendir( $file_info['dirname'] );

			if ( false !== $path ) {
				$thumb = readdir( $path );
				while ( false !== $thumb ) {
					if ( 0 === strpos( $thumb, $file_stem ) && str_ends_with( $thumb, $file_info['extension'] ) ) {
						$files[] = $thumb;
					}
					$thumb = readdir( $path );
				}
				closedir( $path );
				sort( $files );
			}

			foreach ( $files as $thumb ) {
				$thumb_fullpath = trailingslashit( $file_info['dirname'] ) . $thumb;

				$thumb_info  = pathinfo( $thumb_fullpath );
				$valid_thumb = explode( $file_stem, $thumb_info['filename'] );
				if ( '' === $valid_thumb[0] ) {
					$dimension_thumb = explode( 'x', $valid_thumb[1] );
					if ( 2 === count( $dimension_thumb ) ) {
						if ( is_numeric( $dimension_thumb[0] ) && is_numeric( $dimension_thumb[1] ) ) {
							unlink( $thumb_fullpath );
							if ( is_file( $thumb_fullpath . '.webp' ) ) {
								unlink( $thumb_fullpath . '.webp' );
							}
							clearstatcache();
							if ( ! is_file( $thumb_fullpath ) ) {
								$thumb_deleted[] = sprintf( '%dx%d', $dimension_thumb[0], $dimension_thumb[1] );
							} else {
								$thumb_error[] = sprintf( '%dx%d', $dimension_thumb[0], $dimension_thumb[1] );
							}
						}
					}
				}
			}

			/**
			 * Regenerate all thumbnails
			 */
			$original_path = apply_filters( 'regenerate_thumbnails_original_image', wp_get_original_image_path( $image->ID, true ) );
			if ( empty( $original_path ) || ! is_file( $original_path ) ) {
				$original_path = $image_fullpath;
			}

			$metadata = wp_generate_attachment_metadata( $image->ID, $original_path );
			if ( is_wp_error( $metadata ) ) {
				throw new Exception( esc_html( $metadata->get_error_message() ) );
			}
			if ( empty( $metadata ) ) {
				throw new Exception( esc_html__( 'Unknown failure.', 'force-regenerate-thumbnails' ) );
			}
			wp_update_attachment_metadata( $image->ID, $metadata );

			/**
			 * Verify results (deleted, errors, success)
			 */
			$files = array();
			$path  = opendir( $file_info['dirname'] );
			if ( false !== $path ) {
				$thumb = readdir( $path );
				while ( false !== $thumb ) {
					/* if ( ! ( strrpos( $thumb, $file_info['filename'] ) === false ) ) { */
					if ( 0 === strpos( $thumb, $file_stem ) && str_ends_with( $thumb, $file_info['extension'] ) ) {
						$files[] = $thumb;
					}
					$thumb = readdir( $path );
				}
				closedir( $path );
				sort( $files );
			}
			if ( ! empty( $metadata['sizes'] ) && is_iterable( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_data ) {
					if ( empty( $size_data['file'] ) ) {
						continue;
					}
					if ( in_array( $size_data['file'], $files, true ) ) {
						continue;
					}
					$thumb_regenerate[] = sprintf( '%dx%d', $size_data['width'], $size_data['height'] );
				}
			}

			foreach ( $files as $thumb ) {
				$thumb_fullpath = trailingslashit( $file_info['dirname'] ) . $thumb;
				$thumb_info     = pathinfo( $thumb_fullpath );
				$valid_thumb    = explode( $file_stem, $thumb_info['filename'] );
				if ( '' === $valid_thumb[0] ) {
					$dimension_thumb = explode( 'x', $valid_thumb[1] );
					if ( 2 === count( $dimension_thumb ) ) {
						if ( is_numeric( $dimension_thumb[0] ) && is_numeric( $dimension_thumb[1] ) ) {
							$thumb_regenerate[] = sprintf( '%dx%d', $dimension_thumb[0], $dimension_thumb[1] );
						}
					}
				}
			}

			// Remove success if has in error list.
			foreach ( $thumb_regenerate as $key => $regenerate ) {
				if ( in_array( $regenerate, $thumb_error, true ) ) {
					unset( $thumb_regenerate[ $key ] );
				}
			}

			// Remove deleted if has in success list, so that we only show those that were no longer registered.
			foreach ( $thumb_deleted as $key => $deleted ) {
				if ( in_array( $deleted, $thumb_regenerate, true ) ) {
					unset( $thumb_deleted[ $key ] );
				}
			}

			/**
			 * Display results
			 */
			ob_start(); // To suppress any error output.
			$message = '<strong>' .
				sprintf(
					/* translators: 1: attachment title 2: attachment ID number */
					esc_html__( '%1$s (ID %2$d)', 'force-regenerate-thumbnails' ),
					esc_html( get_the_title( $id ) ),
					(int) $image->ID
				) .
				'</strong>';
			$message .= '<br><br>';
			/* translators: %s: the path to the uploads directory */
			$message .= '<code>' . sprintf( esc_html__( 'Upload Directory: %s', 'force-regenerate-thumbnails' ), esc_html( $upload_dir['basedir'] ) ) . '</code><br>';
			/* translators: %s: the base URL of the uploads directory */
			$message .= '<code>' . sprintf( esc_html__( 'Upload URL: %s', 'force-regenerate-thumbnails' ), esc_html( $upload_dir['baseurl'] ) ) . '</code><br>';
			/* translators: %s: the full path of the image */
			$message .= '<code>' . sprintf( esc_html__( 'Image: %s', 'force-regenerate-thumbnails' ), esc_html( $debug_1 ) ) . '</code><br>';
			if ( $debug_2 ) {
				/* translators: %s: debug info, if populated */
				$message .= '<code>' . sprintf( esc_html__( 'Image Debug 2: %s', 'force-regenerate-thumbnails' ), esc_html( $debug_2 ) ) . '</code><br>';
			}
			if ( $debug_3 ) {
				/* translators: %s: debug info, if populated */
				$message .= '<code>' . sprintf( esc_html__( 'Image Debug 3: %s', 'force-regenerate-thumbnails' ), esc_html( $debug_3 ) ) . '</code><br>';
			}
			if ( $debug_4 ) {
				/* translators: %s: debug info, if populated */
				$message .= '<code>' . sprintf( esc_html__( 'Image Debug 4: %s', 'force-regenerate-thumbnails' ), esc_html( $debug_4 ) ) . '</code><br>';
			}

			$message .= '<br>';

			if ( count( $thumb_deleted ) > 0 ) {
				/* translators: %s: list of deleted thumbs */
				$message .= sprintf( esc_html__( 'Deleted: %s', 'force-regenerate-thumbnails' ), esc_html( implode( ', ', $thumb_deleted ) ) ) . '<br>';
			}
			if ( count( $thumb_error ) > 0 ) {
				/* translators: %s: an error message (translated elsewhere) */
				$message .= '<strong><span style="color: #dd3d36;">' . sprintf( esc_html__( 'Deleted error: %s', 'force-regenerate-thumbnails' ), esc_html( implode( ', ', $thumb_error ) ) ) . '</span></strong><br>';
				/* translators: %s: the path to the uploads directory */
				$message .= '<span style="color: #dd3d36;">' . sprintf( esc_html__( 'Please ensure the folder has write permissions (chmod 755): %s', 'force-regenerate-thumbnails' ), esc_html( $upload_dir['basedir'] ) ) . '</span><br>';
			}
			if ( count( $thumb_regenerate ) > 0 ) {
				/* translators: %s: list of regenerated thumbs/sizes */
				$message .= sprintf( esc_html__( 'Regenerate: %s', 'force-regenerate-thumbnails' ), esc_html( implode( ', ', $thumb_regenerate ) ) ) . '<br>';
				if ( count( $thumb_error ) <= 0 ) {
					/* translators: %s: time elapsed */
					$message .= sprintf( esc_html__( 'Successfully regenerated in %s seconds', 'force-regenerate-thumbnails' ), esc_html( timer_stop() ) ) . '<br>';
				}
			}

			$message = $this->remove_from_end( $message, '<br>' );

			$this->ob_clean();
			if ( count( $thumb_error ) > 0 ) {
				die( wp_json_encode( array( 'error' => '<div id="message" class="error fade"><p>' . $message . '</p></div>' ) ) );
			} else {
				die( wp_json_encode( array( 'success' => '<div id="message" class="updated fade"><p>' . $message . '</p></div>' ) ) );
			}
		} catch ( Exception $e ) {
			$this->die_json_failure_msg( $id, '<b><span style="color: #DD3D36;">' . $e->getMessage() . '</span></b>' );
		}
		exit;
	}

	/**
	 * Retrieves the path of an attachment via the ID number.
	 *
	 * @param int   $id The attachment ID number.
	 * @param array $meta The attachment metadata.
	 * @return string The full path to the image.
	 */
	function get_attachment_path( $id, $meta ) {

		// Retrieve the location of the WordPress upload folder.
		$upload_dir  = wp_upload_dir( null, false, true );
		$upload_path = trailingslashit( $upload_dir['basedir'] );

		// Get the file path from postmeta.
		$file               = get_post_meta( $id, '_wp_attached_file', true );
		$file_path          = ( 0 !== strpos( $file, '/' ) && ! preg_match( '|^.:\\\|', $file ) ? $upload_path . $file : $file );
		$filtered_file_path = apply_filters( 'get_attached_file', $file_path, $id );
		if (
			(
				! $this->stream_wrapped( $filtered_file_path ) ||
				$this->stream_wrapper_exists()
			)
			&& is_file( $filtered_file_path )
		) {
			return $filtered_file_path;
		}
		if (
			(
				! $this->stream_wrapped( $file_path ) ||
				$this->stream_wrapper_exists()
			)
			&& is_file( $file_path )
		) {
			return $file_path;
		}
		if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
			$file_path = $meta['file'];
			if ( $this->stream_wrapped( $file_path ) && ! $this->stream_wrapper_exists() ) {
				return '';
			}
			if ( is_file( $file_path ) ) {
				return $file_path;
			}
			$file_path = trailingslashit( $upload_path ) . $file_path;
			if ( is_file( $file_path ) ) {
				return $file_path;
			}
			$upload_path = trailingslashit( WP_CONTENT_DIR ) . 'uploads/';
			$file_path   = $upload_path . $meta['file'];
			if ( is_file( $file_path ) ) {
				return $file_path;
			}
		}
		return '';
	}

	/**
	 * Checks the existence of a cloud storage stream wrapper.
	 *
	 * @return bool True if a supported stream wrapper is found, false otherwise.
	 */
	function stream_wrapper_exists() {
		$wrappers = stream_get_wrappers();
		if ( ! is_iterable( $wrappers ) ) {
			return false;
		}
		foreach ( $wrappers as $wrapper ) {
			if ( strpos( $wrapper, 's3' ) === 0 ) {
				return true;
			}
			if ( strpos( $wrapper, 'gs' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks the filename for an S3 or GCS stream wrapper.
	 *
	 * @param string $filename The filename to be searched.
	 * @return bool True if a stream wrapper is found, false otherwise.
	 */
	function stream_wrapped( $filename ) {
		if ( false !== strpos( $filename, '://' ) ) {
			if ( strpos( $filename, 's3' ) === 0 ) {
				return true;
			}
			if ( strpos( $filename, 'gs' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Trims the given 'needle' from the end of the 'haystack'.
	 *
	 * @param string $haystack The string to be modified if it contains needle.
	 * @param string $needle The string to remove if it is at the end of the haystack.
	 * @return string The haystack with needle removed from the end.
	 */
	function remove_from_end( $haystack, $needle ) {
		$needle_length = strlen( $needle );
		if ( substr( $haystack, -$needle_length ) === $needle ) {
			return substr( $haystack, 0, -$needle_length );
		}
		return $haystack;
	}

	/**
	 * Checks if a function is disabled or does not exist.
	 *
	 * @param string $function The name of a function to test.
	 * @param bool   $debug Whether to output debugging.
	 * @return bool True if the function is available, False if not.
	 */
	function function_exists( $function, $debug = false ) {
		if ( extension_loaded( 'suhosin' ) && function_exists( 'ini_get' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			$suhosin_disabled = @ini_get( 'suhosin.executor.func.blacklist' );
			if ( ! empty( $suhosin_disabled ) ) {
				$suhosin_disabled = explode( ',', $suhosin_disabled );
				$suhosin_disabled = array_map( 'trim', $suhosin_disabled );
				$suhosin_disabled = array_map( 'strtolower', $suhosin_disabled );
				if ( function_exists( $function ) && ! in_array( $function, $suhosin_disabled, true ) ) {
					return true;
				}
				return false;
			}
		}
		return \function_exists( $function );
	}

	/**
	 * Find out if set_time_limit() is allowed.
	 */
	function stl_check() {
		if ( defined( 'FTR_DISABLE_STL' ) && FTR_DISABLE_STL ) {
			// set_time_limit() disabled by user.
			return false;
		}
		if ( function_exists( 'wp_is_ini_value_changeable' ) && ! wp_is_ini_value_changeable( 'max_execution_time' ) ) {
			// max_execution_time is not configurable.
			return false;
		}
		return $this->function_exists( 'set_time_limit' );
	}

	/**
	 * Clear output buffers without throwing a fit.
	 */
	function ob_clean() {
		if ( ob_get_length() ) {
			ob_end_clean();
		}
	}

	/**
	 * Helper to make a JSON failure message
	 *
	 * @param integer $id An attachment ID.
	 * @param string  $message An error message.
	 * @access public
	 * @since 1.8
	 */
	function die_json_failure_msg( $id, $message ) {
		$this->ob_clean();
		die(
			wp_json_encode(
				array(
					/* translators: %d: the attachment ID number */
					'error' => sprintf( esc_html__( '(ID %d)', 'force-regenerate-thumbnails' ), $id ) . '<br />' . $message,
				)
			)
		);
	}
}
