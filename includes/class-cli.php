<?php
namespace VideoOffloadVideoPress;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage VideoPress offload from the command line.
 *
 * ## EXAMPLES
 *
 *     wp vov status
 *     wp vov offload
 *     wp vov offload --dry-run
 *     wp vov delete-local
 *     wp vov delete-local --dry-run
 */
class CLI {

	/**
	 * Show a summary of offload status across the media library.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vov status
	 *
	 * @when after_wp_load
	 */
	public function status( array $args, array $assoc_args ): void {
		$pending      = Offloader::count_local_videos();
		$with_local   = count( Offloader::get_offloaded_with_local() );
		$space_saved  = Offloader::get_space_saved();

		\WP_CLI\Utils\format_items( 'table', array(
			array( 'Category' => 'Not yet offloaded',        'Value' => $pending ),
			array( 'Category' => 'Offloaded, local remains', 'Value' => $with_local ),
			array( 'Category' => 'Space freed',              'Value' => $space_saved > 0 ? size_format( $space_saved, 2 ) : '0 B' ),
		), array( 'Category', 'Value' ) );
	}

	/**
	 * Offload local videos to VideoPress.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<attachment_id>]
	 * : Offload a single attachment by ID.
	 *
	 * [--dry-run]
	 * : List videos that would be offloaded without uploading anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vov offload
	 *     wp vov offload --id=123
	 *     wp vov offload --dry-run
	 *
	 * @when after_wp_load
	 */
	public function offload( array $args, array $assoc_args ): void {
		$dry_run       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$single_id     = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'id', 0 );

		if ( ! $dry_run ) {
			$this->preflight_check();
		}

		if ( $single_id ) {
			$post = get_post( $single_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				\WP_CLI::error( "Attachment ID {$single_id} not found." );
			}
			$videos = array( $post );
		} else {
			$videos = Offloader::get_local_videos( 0, -1 );
		}

		if ( empty( $videos ) ) {
			\WP_CLI::success( 'No videos to offload.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::line( sprintf( 'Found %d video(s) to offload:', count( $videos ) ) );
			foreach ( $videos as $video ) {
				$file  = get_attached_file( $video->ID );
				$size  = ( $file && file_exists( $file ) ) ? size_format( (int) filesize( $file ) ) : '?';
				\WP_CLI::line( sprintf( '  [%d] %s (%s)', $video->ID, $video->post_title ?: basename( (string) $file ), $size ) );
			}
			return;
		}

		$total   = count( $videos );
		$success = 0;
		$failed  = 0;
		$i       = 0;

		foreach ( $videos as $video ) {
			$i++;
			$file      = get_attached_file( $video->ID );
			$file_size = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
			$label     = sprintf( '[%d/%d] %s', $i, $total, $video->post_title ?: basename( (string) $file ) );

			$bar        = null;
			$last_bytes = 0;

			$result = Offloader::run_offload( $video->ID, function ( int $bytes_uploaded, int $fs ) use ( &$bar, &$last_bytes, $label, $file_size ) {
				if ( ! $bar && $fs > 0 ) {
					$bar = \WP_CLI\Utils\make_progress_bar( $label, $fs );
				}
				// Reset if bytes go backwards (new HTTP request started).
				if ( $bytes_uploaded < $last_bytes ) {
					$last_bytes = 0;
				}
				if ( $bar ) {
					$delta = $bytes_uploaded - $last_bytes;
					if ( $delta > 0 ) {
						$bar->tick( $delta );
						$last_bytes = $bytes_uploaded;
					}
				}
			} );

			if ( $bar ) {
				$bar->finish();
			}

			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( '[%d] %s — %s', $video->ID, $video->post_title, $result->get_error_message() ) );
				$failed++;
			} else {
				$success++;
			}
		}

		\WP_CLI::success( sprintf( 'Done. %d offloaded, %d failed.', $success, $failed ) );
	}

	/**
	 * Delete local files for videos already offloaded to VideoPress.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List files that would be deleted without removing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vov delete-local
	 *     wp vov delete-local --dry-run
	 *
	 * @when after_wp_load
	 */
	public function delete_local( array $args, array $assoc_args ): void {
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$ids = Offloader::get_offloaded_with_local();

		if ( empty( $ids ) ) {
			\WP_CLI::success( 'No local files to delete.' );
			return;
		}

		if ( $dry_run ) {
			$total_bytes = 0;
			\WP_CLI::line( sprintf( 'Found %d file(s) to delete:', count( $ids ) ) );
			foreach ( $ids as $id ) {
				$file  = get_attached_file( $id );
				$bytes = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
				$total_bytes += $bytes;
				$post  = get_post( $id );
				$title = $post ? $post->post_title : '';
				\WP_CLI::line( sprintf( '  [%d] %s (%s)', $id, $title, size_format( $bytes ) ) );
			}
			\WP_CLI::line( sprintf( 'Total: %s', size_format( $total_bytes, 2 ) ) );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting local files', count( $ids ) );
		$success  = 0;
		$failed   = 0;

		foreach ( $ids as $id ) {
			$result = Offloader::delete_local_file( $id );
			if ( is_wp_error( $result ) ) {
				$post = get_post( $id );
				\WP_CLI::warning( sprintf( '[%d] %s — %s', $id, $post ? $post->post_title : '', $result->get_error_message() ) );
				$failed++;
			} else {
				$success++;
			}
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( sprintf( 'Done. %d deleted, %d failed.', $success, $failed ) );
	}

	// -------------------------------------------------------------------------

	private function preflight_check(): void {
		if ( ! class_exists( '\Jetpack' ) || ! \Jetpack::is_module_active( 'videopress' ) ) {
			\WP_CLI::error( 'VideoPress is not active. It requires a Jetpack plan that includes VideoPress.' );
		}
		if ( ! VideoPress_API::is_connected() ) {
			\WP_CLI::error( 'The site is not connected to WordPress.com. Please set up Jetpack.' );
		}
		if ( (int) get_option( 'blog_public' ) === -1 ) {
			\WP_CLI::error( 'This site is set to private. VideoPress cannot fetch videos from a private site.' );
		}

		// The VideoPress REST endpoint requires a current user with upload permissions.
		// In CLI context get_current_user_id() returns 0, so find a connected user.
		if ( ! get_current_user_id() ) {
			$user_tokens = \Jetpack_Options::get_option( 'user_tokens', array() );
			if ( ! empty( $user_tokens ) ) {
				wp_set_current_user( (int) key( $user_tokens ) );
				\WP_CLI::debug( 'Set current user to ID ' . get_current_user_id() . ' for VideoPress API calls.', 'vov' );
			} else {
				\WP_CLI::warning( 'No connected WordPress.com user found. If the upload fails, try running with --user=<id> using an admin user connected to WordPress.com.' );
			}
		}
	}
}

\WP_CLI::add_command( 'vov', CLI::class );
