<?php
namespace VideoOffloadVideoPress;

/**
 * Core offload logic: upload videos to VideoPress and manage local files.
 */
class Offloader {

	const STATUS_META         = '_vov_status';
	const GUID_META           = '_vov_guid';
	const MEDIA_ID_META       = '_vov_media_id';
	const SOURCE_URL_META     = '_vov_source_url';
	const UPLOAD_KEY_META     = '_vov_upload_key';
	const ERROR_META          = '_vov_error';
	const DELETED_META        = '_vov_local_deleted';
	const PROGRESS_META       = '_vov_progress';
	const UPLOAD_STARTED_META = '_vov_upload_started';
	const LAST_VERIFIED_META  = '_vov_last_verified';
	const SAVED_BYTES_OPTION  = 'vov_space_saved_bytes';

	const STATUS_NONE      = 'none';
	const STATUS_UPLOADING = 'uploading';
	const STATUS_UPLOADED  = 'uploaded';
	const STATUS_ERROR     = 'error';

	const VIDEO_MIME_TYPES = array(
		'video/mp4',
		'video/webm',
		'video/ogg',
		'video/quicktime',
		'video/x-msvideo',
		'video/mpeg',
		'video/3gpp',
		'video/x-ms-wmv',
		'video/x-flv',
	);

	/**
	 * Query video attachments that haven't been successfully offloaded yet.
	 * Excludes videos already on VideoPress via Jetpack's native `videopress_guid` meta.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_local_videos( int $offset = 0, int $per_page = 100 ): array {
		return get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::VIDEO_MIME_TYPES,
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				// Not already offloaded by this plugin.
				array(
					'relation' => 'OR',
					array(
						'key'     => self::STATUS_META,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::STATUS_META,
						'value'   => self::STATUS_UPLOADED,
						'compare' => '!=',
					),
				),
				// Not already on VideoPress via Jetpack's own module.
				array(
					'key'     => 'videopress_guid',
					'compare' => 'NOT EXISTS',
				),
			),
		) );
	}

	public static function count_local_videos(): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm_vov ON p.ID = pm_vov.post_id AND pm_vov.meta_key = '_vov_status'
			 LEFT JOIN {$wpdb->postmeta} pm_vp  ON p.ID = pm_vp.post_id  AND pm_vp.meta_key  = 'videopress_guid'
			 WHERE p.post_type = 'attachment'
			 AND p.post_status = 'inherit'
			 AND p.post_mime_type LIKE 'video/%'
			 AND (pm_vov.meta_value IS NULL OR pm_vov.meta_value != 'uploaded')
			 AND pm_vp.meta_value IS NULL"
		);
	}

	/**
	 * Reset attachments stuck in "uploading" back to "none" so they show a retry
	 * button. Skips any upload that started within the last 30 minutes — those
	 * are still running in the background after a page refresh.
	 */
	public static function reset_stuck_uploads(): void {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			self::STATUS_META,
			self::STATUS_UPLOADING
		) );

		if ( ! $ids ) {
			return;
		}

		$threshold = time() - 30 * MINUTE_IN_SECONDS;

		foreach ( $ids as $id ) {
			$id      = (int) $id;
			$started = (int) get_post_meta( $id, self::UPLOAD_STARTED_META, true );
			if ( $started && $started > $threshold ) {
				continue; // Still fresh — upload is likely still running.
			}
			update_post_meta( $id, self::STATUS_META, self::STATUS_NONE );
			delete_post_meta( $id, self::UPLOAD_STARTED_META );
		}
	}

	/**
	 * Offload a single attachment to VideoPress.
	 *
	 * Pass $upload_key on continuation calls for large files that upload in chunks.
	 *
	 * @return array|\WP_Error Array with 'guid' on completion, 'uploading' => true on progress.
	 */
	public static function offload( int $attachment_id, string $upload_key = '' ) {
		// If Jetpack already processed this attachment, just sync our status.
		// Only check on the first call (no upload_key yet).
		if ( ! $upload_key ) {
			$jetpack_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
			$mime         = get_post_mime_type( $attachment_id );

			if ( $jetpack_guid || 'video/videopress' === $mime ) {
				$guid = $jetpack_guid ?: get_post_meta( $attachment_id, self::GUID_META, true );
				if ( $guid ) {
					update_post_meta( $attachment_id, self::GUID_META, sanitize_text_field( $guid ) );
					self::store_source_url( $attachment_id, $guid );
				}
				update_post_meta( $attachment_id, self::STATUS_META, self::STATUS_UPLOADED );
				delete_post_meta( $attachment_id, self::ERROR_META );
				return array( 'guid' => $guid ?? '', 'media_id' => 0 );
			}

			$file = get_attached_file( $attachment_id );

			if ( ! $file || ! file_exists( $file ) ) {
				self::set_status( $attachment_id, self::STATUS_ERROR, 'Local file not found on the server.' );
				return new \WP_Error( 'file_not_found', 'Local file not found on the server.' );
			}

			self::set_status( $attachment_id, self::STATUS_UPLOADING );
		}

		$result = VideoPress_API::upload_video( $attachment_id, $upload_key );

		if ( is_wp_error( $result ) ) {
			self::set_status( $attachment_id, self::STATUS_ERROR, $result->get_error_message() );
			return $result;
		}

		// Chunked upload still in progress — return progress to the caller.
		if ( ! empty( $result['uploading'] ) ) {
			return $result;
		}

		update_post_meta( $attachment_id, self::GUID_META, sanitize_text_field( $result['guid'] ) );
		if ( ! empty( $result['media_id'] ) ) {
			update_post_meta( $attachment_id, self::MEDIA_ID_META, (int) $result['media_id'] );
		}
		self::store_source_url( $attachment_id, $result['guid'] );
		self::set_status( $attachment_id, self::STATUS_UPLOADED );

		return $result;
	}

	/**
	 * Delete the local attachment (files + post record) for an already-uploaded attachment.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete_local_file( int $attachment_id ) {
		if ( get_post_meta( $attachment_id, self::STATUS_META, true ) !== self::STATUS_UPLOADED ) {
			return new \WP_Error( 'not_uploaded', 'The video must be successfully uploaded to VideoPress before deleting the local file.' );
		}

		// Capture file size before the attachment post and its meta are removed.
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$bytes = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
		if ( ! $bytes ) {
			$file  = get_attached_file( $attachment_id );
			$bytes = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
		}
		if ( $bytes > 0 ) {
			update_option( self::SAVED_BYTES_OPTION, self::get_space_saved() + $bytes );
		}

		wp_delete_attachment( $attachment_id, true );

		return true;
	}

	public static function get_space_saved(): int {
		return (int) get_option( self::SAVED_BYTES_OPTION, 0 );
	}

	/**
	 * Return IDs of offloaded attachments whose local file still exists on disk.
	 *
	 * @return int[]
	 */
	public static function get_offloaded_with_local(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			self::STATUS_META,
			self::STATUS_UPLOADED
		) );

		$result = array();
		foreach ( array_map( 'intval', $ids ?: array() ) as $id ) {
			$file = get_attached_file( $id );
			if ( $file && file_exists( $file ) ) {
				$result[] = $id;
			}
		}
		return $result;
	}

	/**
	 * Synchronous full-upload loop — handles chunked transfers. Used by WP-CLI.
	 *
	 * @param callable|null $on_progress Called on each chunk: fn( int $bytes_uploaded, int $file_size )
	 * @return array|\WP_Error
	 */
	public static function run_offload( int $attachment_id, ?callable $on_progress = null ) {
		$upload_key = (string) ( get_post_meta( $attachment_id, self::UPLOAD_KEY_META, true ) ?: '' );

		self::set_status( $attachment_id, self::STATUS_UPLOADING );

		$result = null;
		for ( $i = 0; $i < 120; $i++ ) {
			$result = VideoPress_API::upload_video( $attachment_id, $upload_key );

			if ( is_wp_error( $result ) ) {
				$jetpack_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
				if ( $jetpack_guid ) {
					$result = array( 'guid' => $jetpack_guid, 'media_id' => 0 );
					break;
				}
				delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
				self::set_status( $attachment_id, self::STATUS_ERROR, $result->get_error_message() );
				return $result;
			}

			if ( ! empty( $result['uploading'] ) ) {
				$upload_key = (string) ( $result['upload_key'] ?? $upload_key );
				if ( $upload_key ) {
					update_post_meta( $attachment_id, self::UPLOAD_KEY_META, $upload_key );
				}
				if ( $on_progress && isset( $result['bytes_uploaded'], $result['file_size'] ) ) {
					$on_progress( (int) $result['bytes_uploaded'], (int) $result['file_size'] );
				}
				continue;
			}

			break;
		}

		if ( $result && ! empty( $result['uploading'] ) ) {
			self::set_status( $attachment_id, self::STATUS_ERROR, 'Upload timed out.' );
			return new \WP_Error( 'timeout', 'Upload timed out.' );
		}

		delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
		update_post_meta( $attachment_id, self::GUID_META, sanitize_text_field( $result['guid'] ) );
		if ( ! empty( $result['media_id'] ) ) {
			update_post_meta( $attachment_id, self::MEDIA_ID_META, (int) $result['media_id'] );
		}
		self::store_source_url( $attachment_id, $result['guid'] );
		self::set_status( $attachment_id, self::STATUS_UPLOADED );

		return $result;
	}

	public static function get_status( int $attachment_id ): array {
		$status = get_post_meta( $attachment_id, self::STATUS_META, true ) ?: self::STATUS_NONE;
		$guid   = get_post_meta( $attachment_id, self::GUID_META, true );

		// If Jetpack has already processed this attachment (MIME type changed to
		// video/videopress, or Jetpack wrote its own videopress_guid meta), treat
		// it as uploaded regardless of what our own status meta says.
		if ( self::STATUS_UPLOADED !== $status ) {
			$jetpack_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
			$mime         = get_post_mime_type( $attachment_id );

			if ( $jetpack_guid || 'video/videopress' === $mime ) {
				$status = self::STATUS_UPLOADED;
				$guid   = $guid ?: $jetpack_guid;
				update_post_meta( $attachment_id, self::STATUS_META, self::STATUS_UPLOADED );
				if ( $guid ) {
					update_post_meta( $attachment_id, self::GUID_META, sanitize_text_field( $guid ) );
				}
			}
		}

		return array(
			'status'  => $status,
			'guid'    => $guid,
			'error'   => get_post_meta( $attachment_id, self::ERROR_META, true ),
			'deleted' => (bool) get_post_meta( $attachment_id, self::DELETED_META, true ),
		);
	}

	/**
	 * Build and persist the VideoPress CDN source URL for an attachment.
	 * Called immediately after we know the GUID, while the local file still exists.
	 */
	private static function store_source_url( int $attachment_id, string $guid ): void {
		// Skip if already stored.
		if ( get_post_meta( $attachment_id, self::SOURCE_URL_META, true ) ) {
			return;
		}

		$filename = '';

		// Try _wp_attached_file first (most reliable on Atomic).
		$rel_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $rel_path ) {
			$filename = basename( (string) $rel_path );
		}

		// Fall back to get_attached_file() while local file still exists.
		if ( ! $filename ) {
			$file = get_attached_file( $attachment_id );
			if ( $file ) {
				$filename = basename( $file );
			}
		}

		// Last resort: parse from the attachment URL.
		if ( ! $filename ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			}
		}

		if ( $filename ) {
			$source_url = 'https://videos.files.wordpress.com/' . $guid . '/' . $filename;
			update_post_meta( $attachment_id, self::SOURCE_URL_META, $source_url );
		}
	}

	/**
	 * Poll post meta until Jetpack writes videopress_guid or we run out of attempts.
	 *
	 * @return string GUID on success, empty string on timeout.
	 */
	private static function wait_for_jetpack_guid( int $attachment_id, int $attempts, int $interval_seconds ): string {
		for ( $i = 0; $i < $attempts; $i++ ) {
			sleep( $interval_seconds );
			$guid = get_post_meta( $attachment_id, 'videopress_guid', true );
			if ( $guid ) {
				return (string) $guid;
			}
		}
		return '';
	}

	private static function set_status( int $attachment_id, string $status, string $error = '' ): void {
		update_post_meta( $attachment_id, self::STATUS_META, $status );
		if ( self::STATUS_UPLOADING === $status ) {
			update_post_meta( $attachment_id, self::UPLOAD_STARTED_META, time() );
		} else {
			delete_post_meta( $attachment_id, self::UPLOAD_STARTED_META );
		}
		if ( $error ) {
			update_post_meta( $attachment_id, self::ERROR_META, $error );
		} else {
			delete_post_meta( $attachment_id, self::ERROR_META );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public static function ajax_offload(): void {
		check_ajax_referer( 'vov_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( 'Invalid attachment ID.' );
		}

		// Keep PHP running even if the Atomic proxy closes the HTTP connection mid-upload.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		if ( ! class_exists( '\Jetpack' ) || ! \Jetpack::is_module_active( 'videopress' ) ) {
			wp_send_json_error( 'VideoPress is not active. It requires a WordPress.com Premium, Business, or Commerce plan, or a Jetpack plan that includes VideoPress.' );
		}

		if ( ! VideoPress_API::is_connected() ) {
			wp_send_json_error( 'The site is not connected to WordPress.com. Please set up Jetpack.' );
		}

		if ( ! VideoPress_API::current_user_is_connected() ) {
			wp_send_json_error( 'Your WordPress.com account is not connected. Go to Jetpack → Dashboard and connect your user account.' );
		}

		if ( (int) get_option( 'blog_public' ) === -1 ) {
			wp_send_json_error( 'This site is set to private. VideoPress cannot fetch videos from a private site. Set the site to public under Settings → Reading, run the offload, then set it back to private.' );
		}

		// Fast path: Jetpack already processed this attachment.
		$jetpack_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
		$mime         = get_post_mime_type( $attachment_id );

		if ( $jetpack_guid || 'video/videopress' === $mime ) {
			$result = self::offload( $attachment_id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}
			wp_send_json_success( array(
				'guid'   => $result['guid'],
				'status' => self::get_status( $attachment_id ),
			) );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			wp_send_json_error( 'Local file not found on the server.' );
		}

		// Resume an existing upload session if one exists, otherwise start fresh.
		$upload_key       = (string) ( get_post_meta( $attachment_id, self::UPLOAD_KEY_META, true ) ?: '' );
		$retried_fresh    = false;
		$had_active_chunk = false; // True once VideoPress starts a chunked session.
		$result           = null;

		self::set_status( $attachment_id, self::STATUS_UPLOADING );

		for ( $i = 0; $i < 120; $i++ ) {
			$result = VideoPress_API::upload_video( $attachment_id, $upload_key );

			if ( is_wp_error( $result ) ) {
				// Jetpack may have written the GUID despite the error (e.g. 409 / 400 during finalization).
				$jetpack_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
				if ( $jetpack_guid ) {
					delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
					$result = array( 'guid' => $jetpack_guid, 'media_id' => 0 );
					break;
				}

				// If we were already mid-upload when the error arrived, VideoPress is
				// likely still processing. Retrying would create a duplicate video.
				// Poll for the Jetpack GUID instead (up to ~30 s).
				if ( $had_active_chunk ) {
					$jetpack_guid = self::wait_for_jetpack_guid( $attachment_id, 10, 3 );
					if ( $jetpack_guid ) {
						delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
						$result = array( 'guid' => $jetpack_guid, 'media_id' => 0 );
						break;
					}
					// Gave up waiting — report the original error.
					delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
					self::set_status( $attachment_id, self::STATUS_ERROR, $result->get_error_message() );
					wp_send_json_error( $result->get_error_message() );
				}

				// First-attempt failure (no prior chunk activity): clear any stale key
				// and retry once with a fresh session (handles 460 "session expired").
				if ( ! $retried_fresh ) {
					$upload_key    = '';
					$retried_fresh = true;
					delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
					sleep( 2 );
					continue;
				}

				delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
				self::set_status( $attachment_id, self::STATUS_ERROR, $result->get_error_message() );
				wp_send_json_error( $result->get_error_message() );
			}

			if ( ! empty( $result['uploading'] ) ) {
				$had_active_chunk = true;
				$upload_key       = (string) ( $result['upload_key'] ?? $upload_key );
				if ( $upload_key ) {
					update_post_meta( $attachment_id, self::UPLOAD_KEY_META, $upload_key );
				}
				update_post_meta( $attachment_id, self::PROGRESS_META, array(
					'bytes_uploaded' => (int) $result['bytes_uploaded'],
					'file_size'      => (int) $result['file_size'],
				) );
				continue;
			}

			break; // Upload complete.
		}

		if ( $result && ! empty( $result['uploading'] ) ) {
			$jetpack_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
			if ( $jetpack_guid ) {
				$result = array( 'guid' => $jetpack_guid, 'media_id' => 0 );
			} else {
				self::set_status( $attachment_id, self::STATUS_ERROR, 'Upload timed out. The video may still be processing — try refreshing the page.' );
				wp_send_json_error( 'Upload timed out. The video may still be processing — try refreshing the page.' );
			}
		}

		delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
		delete_post_meta( $attachment_id, self::PROGRESS_META );
		update_post_meta( $attachment_id, self::GUID_META, sanitize_text_field( $result['guid'] ) );
		if ( ! empty( $result['media_id'] ) ) {
			update_post_meta( $attachment_id, self::MEDIA_ID_META, (int) $result['media_id'] );
		}
		self::store_source_url( $attachment_id, $result['guid'] );
		self::set_status( $attachment_id, self::STATUS_UPLOADED );
		delete_post_meta( $attachment_id, self::ERROR_META );

		wp_send_json_success( array(
			'guid'   => $result['guid'],
			'status' => self::get_status( $attachment_id ),
		) );
	}

	public static function ajax_get_status(): void {
		check_ajax_referer( 'vov_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( 'Invalid attachment ID.' );
		}

		$status_data = self::get_status( $attachment_id );
		$progress    = get_post_meta( $attachment_id, self::PROGRESS_META, true );

		wp_send_json_success( array_merge( $status_data, array(
			'bytes_uploaded' => isset( $progress['bytes_uploaded'] ) ? (int) $progress['bytes_uploaded'] : 0,
			'file_size'      => isset( $progress['file_size'] ) ? (int) $progress['file_size'] : 0,
		) ) );
	}

	public static function ajax_verify_guid(): void {
		check_ajax_referer( 'vov_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( 'Invalid attachment ID.' );
		}

		$guid = (string) get_post_meta( $attachment_id, self::GUID_META, true );
		if ( ! $guid ) {
			wp_send_json_error( 'No GUID found.' );
		}

		$exists = VideoPress_API::verify_guid( $guid );

		if ( $exists ) {
			update_post_meta( $attachment_id, self::LAST_VERIFIED_META, time() );
		} else {
			// VideoPress video was deleted — reset local status so the Offload button reappears.
			delete_post_meta( $attachment_id, self::STATUS_META );
			delete_post_meta( $attachment_id, self::GUID_META );
			delete_post_meta( $attachment_id, self::MEDIA_ID_META );
			delete_post_meta( $attachment_id, self::LAST_VERIFIED_META );
			// Intentionally keep SOURCE_URL_META and DELETED_META as historical references.
		}

		wp_send_json_success( array( 'exists' => $exists ) );
	}

	public static function ajax_delete_local(): void {
		check_ajax_referer( 'vov_nonce', 'nonce' );

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		$result        = self::delete_local_file( $attachment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'deleted' => true ) );
	}

	/**
	 * When a video/videopress attachment is permanently deleted, reset the paired
	 * local attachment so it can be offloaded again.
	 */
	public static function on_vp_attachment_deleted( int $attachment_id ): void {
		if ( 'video/videopress' !== get_post_mime_type( $attachment_id ) ) {
			return;
		}

		$local_posts = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( array(
				'key'   => self::MEDIA_ID_META,
				'value' => $attachment_id,
				'type'  => 'NUMERIC',
			) ),
		) );

		if ( ! $local_posts ) {
			return;
		}

		$local_id = $local_posts[0];

		delete_post_meta( $local_id, self::STATUS_META );
		delete_post_meta( $local_id, self::GUID_META );
		delete_post_meta( $local_id, self::MEDIA_ID_META );
		delete_post_meta( $local_id, self::LAST_VERIFIED_META );
		delete_post_meta( $local_id, self::ERROR_META );
		delete_post_meta( $local_id, self::UPLOAD_STARTED_META );
		delete_post_meta( $local_id, self::UPLOAD_KEY_META );
		delete_post_meta( $local_id, self::PROGRESS_META );
		delete_post_meta( $local_id, self::SOURCE_URL_META );
	}
}
