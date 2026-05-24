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
	const PROXY_META          = '_vov_proxy';
	const RETRY_FRESH_META    = '_vov_retried_fresh';
	const RETRY_CHECKSUM_META = '_vov_retried_no_checksum';
	const HAD_CHUNK_META      = '_vov_had_active_chunk';
	const STRIP_CHECKSUM_META = '_vov_strip_checksum';
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
			self::cleanup_retry_meta( $id );
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
	 * Retry a persistently-failing upload by creating a temporary proxy attachment.
	 *
	 * VideoPress's tus server keys sessions by "s-{site_id}-v-{attachment_id}".
	 * When that key accumulates broken server-side state, even a freshly-created
	 * session for that key gets 460 on the first PATCH. A proxy WP attachment gets
	 * a new post ID — and therefore a fresh Upload-Key that VideoPress has never
	 * seen — breaking the cycle.
	 *
	 * To avoid Jetpack's is_readable() false negatives on Atomic's cloud-backed
	 * filesystem, we bypass the normal REST endpoint entirely and drive the tus
	 * upload directly via anonymous subclasses of Uploader and Tus_Client that
	 * skip the is_readable() checks while still calling @fopen() for actual reads.
	 *
	 * @return array|\WP_Error  {guid, media_id} on success, WP_Error on failure.
	 */
	private static function offload_via_proxy( int $attachment_id ) {
		$original_file = get_attached_file( $attachment_id );
		if ( ! $original_file || ! file_exists( $original_file ) ) {
			return new \WP_Error( 'file_not_found', 'Original file not found for proxy upload.' );
		}

		$proxy_id = wp_insert_post( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => get_post_mime_type( $attachment_id ),
			'post_title'     => 'vov-proxy',
		) );

		if ( is_wp_error( $proxy_id ) || ! $proxy_id ) {
			return new \WP_Error( 'proxy_create_failed', 'Could not create proxy attachment for retry.' );
		}

		// Mark as proxy so it's hidden from the media library (Admin::hide_proxy_attachments).
		// Do NOT set _wp_attached_file — the upload path never calls get_attached_file() for
		// the proxy, and omitting it prevents WordPress from unlinking the original video file
		// if the proxy post is ever force-deleted.
		update_post_meta( $proxy_id, self::PROXY_META, '1' );

		$proxy_key = sprintf( 's-%d-v-%d', \Jetpack_Options::get_option( 'id' ), $proxy_id );

		// Strip Upload-Checksum from the tus session-creation POST so VideoPress
		// cannot match this upload to a cached (expired) session for the same file.
		$strip_checksum = static function ( $args, $url ) {
			if (
				strpos( $url, 'public-api.wordpress.com/rest/v1.1/video-uploads/' ) !== false
				&& ! empty( $args['headers']['Upload-Length'] )
			) {
				unset( $args['headers']['Upload-Checksum'] );
			}
			return $args;
		};
		add_filter( 'http_request_args', $strip_checksum, 998, 2 );

		$bump_timeout = static function ( $args, $url ) {
			if ( strpos( $url, 'public-api.wordpress.com' ) !== false ) {
				$args['timeout'] = 120;
			}
			return $args;
		};
		add_filter( 'http_request_args', $bump_timeout, 999, 2 );

		// Anonymous Uploader subclass: skip parent::__construct() (which calls is_readable()),
		// override get_file_path() and get_key() to use the proxy values, and override
		// get_client() to return a Tus_Client that also skips is_readable() in file().
		$uploader = new class( $proxy_id, $original_file, $proxy_key ) extends \Automattic\Jetpack\VideoPress\Uploader {
			private string $real_file;
			private string $proxy_key_str;

			public function __construct( int $proxy_id, string $real_file, string $proxy_key_str ) {
				$this->attachment_id   = $proxy_id;
				$this->real_file       = $real_file;
				$this->proxy_key_str   = $proxy_key_str;
			}

			public function get_file_path(): string {
				return $this->real_file;
			}

			public function get_key(): string {
				return $this->proxy_key_str;
			}

			public function get_client(): \VideoPressUploader\Tus_Client {
				if ( $this->client !== null ) {
					return $this->client;
				}
				$token   = $this->get_upload_token();
				$blog_id = (int) \Jetpack_Options::get_option( 'id' );
				$key     = $this->proxy_key_str;
				$this->client = new class( $key, $token, $blog_id ) extends \VideoPressUploader\Tus_Client {
					public function file( $file, $name = null ): self {
						if ( ! is_string( $file ) ) {
							throw new \InvalidArgumentException( '$file needs to be a string' );
						}
						if ( ! file_exists( $file ) ) {
							throw new \VideoPressUploader\Tus_Exception( 'Cannot read file: ' . $file );
						}
						// Skip is_readable() — false negative on Atomic cloud-backed filesystem.
						$this->file_path = $file;
						$this->file_name = ! empty( $name ) ? basename( $this->file_path ) : '';
						$this->file_size = filesize( $file );
						$this->add_metadata( 'filename', $this->file_name );
						return $this;
					}
				};
				return $this->client;
			}
		};

		// Each call to upload() sends one 5 MB chunk.
		$result = null;
		for ( $i = 0; $i < 600; $i++ ) {
			$status = $uploader->upload();
			if ( 'error' === ( $status['status'] ?? '' ) ) {
				$result = new \WP_Error(
					'proxy_upload_error',
					$status['error'] ?? 'Proxy upload failed',
					array( 'bytes_uploaded' => (int) ( $status['bytes_uploaded'] ?? -1 ) )
				);
				break;
			}
			if ( 'complete' === ( $status['status'] ?? '' ) ) {
				$details = $status['uploaded_details'] ?? array();
				$result  = array(
					'guid'     => (string) ( $details['guid'] ?? '' ),
					'media_id' => (int) ( $details['media_id'] ?? 0 ),
				);
				break;
			}
			// 'uploading' — continue to next chunk
		}

		remove_filter( 'http_request_args', $strip_checksum, 998 );
		remove_filter( 'http_request_args', $bump_timeout, 999 );

		// Clean up. No _wp_attached_file was set so WordPress won't unlink the video.
		VideoPress_API::clear_upload_cache( $proxy_id );
		wp_delete_post( $proxy_id, true );

		if ( null === $result ) {
			return new \WP_Error( 'proxy_timeout', 'Proxy upload timed out.' );
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
		$upload_key           = (string) ( get_post_meta( $attachment_id, self::UPLOAD_KEY_META, true ) ?: '' );
		$retried_fresh        = false;
		$retried_no_checksum  = false;
		$retried_random_key   = false;
		$strip_checksum       = false;
		$randomize_key        = false;

		// If the previous run left the attachment in an error state, clear any stale
		// tus session proactively so the first attempt starts fresh.
		$prev_status = get_post_meta( $attachment_id, self::STATUS_META, true );
		if ( self::STATUS_ERROR === $prev_status && $upload_key ) {
			VideoPress_API::clear_upload_cache( $attachment_id );
			delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
			$upload_key = '';
		}

		self::set_status( $attachment_id, self::STATUS_UPLOADING );

		$file           = get_attached_file( $attachment_id );
		$file_size      = $file ? (int) filesize( $file ) : 0;
		$max_bytes_seen = 0;
		$stall_count    = 0;

		// Hook into cURL to get real-time upload progress during the transfer.
		// No URL filter — the hook is only active during the upload loop and the
		// PATCH may go to a different domain than the CREATE.
		$curl_hook = static function ( $handle, $parsed_args, $url ) use ( $on_progress, $file_size ) {
			curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
			curl_setopt( $handle, CURLOPT_PROGRESSFUNCTION, static function ( $resource, $dl_total, $dl_done, $ul_total, $ul_done ) use ( $on_progress, $file_size ) {
				if ( $ul_done > 0 && $on_progress ) {
					$on_progress( (int) $ul_done, $file_size ?: (int) $ul_total );
				}
			} );
		};
		add_action( 'http_api_curl', $curl_hook, 10, 3 );

		// Also keep the http_response hook for the final offset confirmation.
		$progress_hook = static function ( $response, $parsed_args, $url ) use ( $on_progress, $file_size, &$max_bytes_seen ) {
			if (
				204 === wp_remote_retrieve_response_code( $response )
				&& strpos( $url, 'public-api.wordpress.com' ) !== false
			) {
				$offset = wp_remote_retrieve_header( $response, 'upload-offset' );
				if ( $offset !== '' ) {
					$max_bytes_seen = max( $max_bytes_seen, (int) $offset );
					if ( $on_progress ) {
						$on_progress( (int) $offset, $file_size );
					}
				}
			}
			return $response;
		};
		add_filter( 'http_response', $progress_hook, 10, 3 );

		$result = null;
		for ( $i = 0; $i < 600; $i++ ) {
			$result = VideoPress_API::upload_video( $attachment_id, $upload_key, $strip_checksum, $randomize_key );
			$strip_checksum = false;
			$randomize_key  = false;

			if ( is_wp_error( $result ) ) {
				$jetpack_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
				if ( $jetpack_guid ) {
					$result = array( 'guid' => $jetpack_guid, 'media_id' => 0 );
					break;
				}
				$err_data       = $result->get_error_data();
				$bytes_on_error = isset( $err_data['bytes_uploaded'] ) ? (int) $err_data['bytes_uploaded'] : 0;
				if ( $bytes_on_error < 0 && ! $retried_fresh ) {
					$upload_key    = '';
					$retried_fresh = true;
					delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
					VideoPress_API::clear_upload_cache( $attachment_id );
					sleep( 30 ); // Give VideoPress time to release the stale session.
					continue;
				}
				if ( $bytes_on_error < 0 && ! $retried_no_checksum ) {
					$upload_key          = '';
					$retried_no_checksum = true;
					$strip_checksum      = true;
					delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
					VideoPress_API::clear_upload_cache( $attachment_id );
					sleep( 30 );
					continue;
				}
				if ( $bytes_on_error < 0 && ! $retried_random_key ) {
					$upload_key         = '';
					$retried_random_key = true;
					$strip_checksum     = true;
					$randomize_key      = true;
					delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
					VideoPress_API::clear_upload_cache( $attachment_id );
					sleep( 30 );
					continue;
				}
				if ( ! $retried_fresh ) {
					$upload_key    = '';
					$retried_fresh = true;
					delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
					VideoPress_API::clear_upload_cache( $attachment_id );
					continue;
				}
				delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
				break;
			}

			if ( ! empty( $result['uploading'] ) ) {
				$upload_key = (string) ( $result['upload_key'] ?? $upload_key );
				if ( $upload_key ) {
					update_post_meta( $attachment_id, self::UPLOAD_KEY_META, $upload_key );
				}
				if ( $on_progress && isset( $result['bytes_uploaded'], $result['file_size'] ) ) {
					$on_progress( (int) $result['bytes_uploaded'], (int) $result['file_size'] );
				}
				if ( (int) ( $result['bytes_uploaded'] ?? 0 ) <= 0 && $max_bytes_seen <= 0 ) {
					$stall_count++;
					if ( $stall_count >= 10 ) {
						break;
					}
				} else {
					$stall_count = 0;
				}
				continue;
			}

			break;
		}

		remove_filter( 'http_response', $progress_hook, 10 );
		remove_action( 'http_api_curl', $curl_hook, 10 );

		// Success — got a GUID directly from the upload.
		if ( $result && ! is_wp_error( $result ) && ! empty( $result['guid'] ) ) {
			self::complete_upload( $attachment_id, $result );
			return $result;
		}

		// Upload failed, stalled, or timed out — try fallbacks.
		delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );

		$jetpack_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
		if ( $jetpack_guid ) {
			$fallback = array( 'guid' => $jetpack_guid, 'media_id' => 0 );
			self::complete_upload( $attachment_id, $fallback );
			return $fallback;
		}

		$proxy_result = self::offload_via_proxy( $attachment_id );
		if ( ! is_wp_error( $proxy_result ) && ! empty( $proxy_result['guid'] ) ) {
			self::complete_upload( $attachment_id, $proxy_result );
			return $proxy_result;
		}

		$late_guid = self::wait_for_jetpack_guid( $attachment_id, 6, 5 );
		if ( $late_guid ) {
			$fallback = array( 'guid' => $late_guid, 'media_id' => 0 );
			self::complete_upload( $attachment_id, $fallback );
			return $fallback;
		}

		$error_msg = is_wp_error( $result ) ? $result->get_error_message() : 'Upload did not complete.';
		self::set_status( $attachment_id, self::STATUS_ERROR, $error_msg );
		return is_wp_error( $result ) ? $result : new \WP_Error( 'upload_failed', $error_msg );
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

	private static function cleanup_retry_meta( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::RETRY_FRESH_META );
		delete_post_meta( $attachment_id, self::RETRY_CHECKSUM_META );
		delete_post_meta( $attachment_id, self::HAD_CHUNK_META );
		delete_post_meta( $attachment_id, self::STRIP_CHECKSUM_META );
	}

	private static function complete_upload( int $attachment_id, array $result ): void {
		delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
		delete_post_meta( $attachment_id, self::PROGRESS_META );
		update_post_meta( $attachment_id, self::GUID_META, sanitize_text_field( $result['guid'] ) );
		if ( ! empty( $result['media_id'] ) ) {
			update_post_meta( $attachment_id, self::MEDIA_ID_META, (int) $result['media_id'] );
		}
		self::store_source_url( $attachment_id, $result['guid'] );
		self::set_status( $attachment_id, self::STATUS_UPLOADED );
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
		if ( ! is_readable( $file ) ) {
			wp_send_json_error( 'Local file exists but is not readable by the web server. Check file permissions.' );
		}

		// Read persisted upload state from previous calls in this sequence.
		$upload_key          = (string) ( get_post_meta( $attachment_id, self::UPLOAD_KEY_META, true ) ?: '' );
		$retried_fresh       = (bool) get_post_meta( $attachment_id, self::RETRY_FRESH_META, true );
		$retried_no_checksum = (bool) get_post_meta( $attachment_id, self::RETRY_CHECKSUM_META, true );
		$had_active_chunk    = (bool) get_post_meta( $attachment_id, self::HAD_CHUNK_META, true );
		$strip_checksum      = (bool) get_post_meta( $attachment_id, self::STRIP_CHECKSUM_META, true );
		if ( $strip_checksum ) {
			delete_post_meta( $attachment_id, self::STRIP_CHECKSUM_META );
		}

		// Set uploading status on the very first call of a new sequence.
		if ( ! $upload_key && ! $had_active_chunk && ! $retried_fresh && ! $retried_no_checksum ) {
			self::set_status( $attachment_id, self::STATUS_UPLOADING );
		}

		// Capture real tus progress: VideoPress responds to each PATCH with Upload-Offset.
		// Writing to post_meta lets the concurrent vov_get_status poll read it in real time.
		$progress_hook = static function ( $response, $parsed_args, $url ) use ( $attachment_id, $file ) {
			if (
				204 === wp_remote_retrieve_response_code( $response )
				&& strpos( $url, 'public-api.wordpress.com' ) !== false
			) {
				$offset = wp_remote_retrieve_header( $response, 'upload-offset' );
				if ( $offset !== '' ) {
					update_post_meta( $attachment_id, self::PROGRESS_META, array(
						'bytes_uploaded' => (int) $offset,
						'file_size'      => (int) filesize( $file ),
					) );
				}
			}
			return $response;
		};
		add_filter( 'http_response', $progress_hook, 10, 3 );

		$result = VideoPress_API::upload_video( $attachment_id, $upload_key, $strip_checksum );

		remove_filter( 'http_response', $progress_hook, 10 );

		if ( is_wp_error( $result ) ) {
			// Jetpack may have written the GUID despite the error (e.g. 409 / 400 during finalization).
			$jetpack_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
			if ( $jetpack_guid ) {
				self::cleanup_retry_meta( $attachment_id );
				self::complete_upload( $attachment_id, array( 'guid' => $jetpack_guid, 'media_id' => 0 ) );
				wp_send_json_success( array( 'guid' => $jetpack_guid, 'status' => self::get_status( $attachment_id ) ) );
			}

			if ( $had_active_chunk ) {
				$err_data       = $result->get_error_data();
				$bytes_on_error = isset( $err_data['bytes_uploaded'] ) ? (int) $err_data['bytes_uploaded'] : 0;
				$progress_meta  = get_post_meta( $attachment_id, self::PROGRESS_META, true );
				$max_bytes_seen = isset( $progress_meta['bytes_uploaded'] ) ? (int) $progress_meta['bytes_uploaded'] : 0;

				// Session went dead at the start with no bytes transferred — safe to retry fresh.
				if ( $bytes_on_error < 0 && $max_bytes_seen === 0 && ! $retried_fresh ) {
					update_post_meta( $attachment_id, self::RETRY_FRESH_META, '1' );
					delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
					delete_post_meta( $attachment_id, self::HAD_CHUNK_META );
					VideoPress_API::clear_upload_cache( $attachment_id );
					wp_send_json_success( array( 'uploading' => true, 'bytes_uploaded' => 0, 'file_size' => (int) filesize( $file ) ) );
				}

				// Upload was mid-progress — VideoPress is likely still processing.
				$jetpack_guid = self::wait_for_jetpack_guid( $attachment_id, 10, 3 );
				if ( $jetpack_guid ) {
					self::cleanup_retry_meta( $attachment_id );
					self::complete_upload( $attachment_id, array( 'guid' => $jetpack_guid, 'media_id' => 0 ) );
					wp_send_json_success( array( 'guid' => $jetpack_guid, 'status' => self::get_status( $attachment_id ) ) );
				}

				// Re-check GUID — VideoPress may have finished just after our poll window.
				delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
				$late_guid = get_post_meta( $attachment_id, 'videopress_guid', true );
				if ( $late_guid ) {
					self::cleanup_retry_meta( $attachment_id );
					self::complete_upload( $attachment_id, array( 'guid' => $late_guid, 'media_id' => 0 ) );
					wp_send_json_success( array( 'guid' => $late_guid, 'status' => self::get_status( $attachment_id ) ) );
				}

				// Try a proxy attachment with a fresh Upload-Key.
				$proxy_result = self::offload_via_proxy( $attachment_id );
				if ( ! is_wp_error( $proxy_result ) && ! empty( $proxy_result['guid'] ) ) {
					self::cleanup_retry_meta( $attachment_id );
					self::complete_upload( $attachment_id, $proxy_result );
					wp_send_json_success( array( 'guid' => $proxy_result['guid'], 'status' => self::get_status( $attachment_id ) ) );
				}

				$error_msg = $max_bytes_seen > 0
					? 'The upload session ended near completion. VideoPress may still be processing — wait a minute, then refresh the page. If the video still shows as an error, click Retry.'
					: $result->get_error_message();
				self::cleanup_retry_meta( $attachment_id );
				self::set_status( $attachment_id, self::STATUS_ERROR, $error_msg );
				wp_send_json_error( $error_msg );
			}

			// No chunk activity yet — handle fresh-session failures.
			if ( ! $retried_fresh ) {
				update_post_meta( $attachment_id, self::RETRY_FRESH_META, '1' );
				delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
				VideoPress_API::clear_upload_cache( $attachment_id );
				wp_send_json_success( array( 'uploading' => true, 'bytes_uploaded' => 0, 'file_size' => (int) filesize( $file ) ) );
			}

			// VideoPress may be deduplicating by Upload-Checksum — retry without it.
			if ( ! $retried_no_checksum ) {
				update_post_meta( $attachment_id, self::RETRY_CHECKSUM_META, '1' );
				update_post_meta( $attachment_id, self::STRIP_CHECKSUM_META, '1' );
				delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
				VideoPress_API::clear_upload_cache( $attachment_id );
				wp_send_json_success( array( 'uploading' => true, 'bytes_uploaded' => 0, 'file_size' => (int) filesize( $file ) ) );
			}

			// Both retries exhausted — try a proxy attachment with a fresh Upload-Key.
			delete_post_meta( $attachment_id, self::UPLOAD_KEY_META );
			$proxy_result = self::offload_via_proxy( $attachment_id );
			if ( ! is_wp_error( $proxy_result ) && ! empty( $proxy_result['guid'] ) ) {
				self::cleanup_retry_meta( $attachment_id );
				self::complete_upload( $attachment_id, $proxy_result );
				wp_send_json_success( array( 'guid' => $proxy_result['guid'], 'status' => self::get_status( $attachment_id ) ) );
			}
			self::cleanup_retry_meta( $attachment_id );
			self::set_status( $attachment_id, self::STATUS_ERROR, $result->get_error_message() );
			wp_send_json_error( $result->get_error_message() );
		}

		if ( ! empty( $result['uploading'] ) ) {
			if ( ! $had_active_chunk ) {
				update_post_meta( $attachment_id, self::HAD_CHUNK_META, '1' );
			}
			$new_key = (string) ( $result['upload_key'] ?? $upload_key );
			if ( $new_key ) {
				update_post_meta( $attachment_id, self::UPLOAD_KEY_META, $new_key );
			}
			$bytes_now = (int) ( $result['bytes_uploaded'] ?? 0 );
			$file_size = (int) ( $result['file_size'] ?? 0 );
			if ( $file_size === 0 ) {
				$file_size = (int) filesize( $file );
			}
			// Jetpack may not return bytes_uploaded — estimate by accumulating chunks.
			if ( $bytes_now === 0 && $file_size > 0 ) {
				$prev       = get_post_meta( $attachment_id, self::PROGRESS_META, true );
				$prev_bytes = isset( $prev['bytes_uploaded'] ) ? (int) $prev['bytes_uploaded'] : 0;
				$bytes_now  = min( $prev_bytes + 5 * 1024 * 1024, $file_size - 1 );
			}
			update_post_meta( $attachment_id, self::PROGRESS_META, array(
				'bytes_uploaded' => $bytes_now,
				'file_size'      => $file_size,
			) );
			wp_send_json_success( array(
				'uploading'      => true,
				'bytes_uploaded' => $bytes_now,
				'file_size'      => $file_size,
			) );
		}

		// Upload complete.
		self::cleanup_retry_meta( $attachment_id );
		self::complete_upload( $attachment_id, $result );
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

		$status_data    = self::get_status( $attachment_id );
		$progress       = get_post_meta( $attachment_id, self::PROGRESS_META, true );
		$bytes_uploaded = isset( $progress['bytes_uploaded'] ) ? (int) $progress['bytes_uploaded'] : 0;
		$file_size      = isset( $progress['file_size'] )      ? (int) $progress['file_size']      : 0;

		// Ensure file_size is always populated so JS has a denominator.
		if ( $file_size === 0 && self::STATUS_UPLOADING === $status_data['status'] ) {
			$local_file = get_attached_file( $attachment_id );
			if ( $local_file && file_exists( $local_file ) ) {
				$file_size = (int) filesize( $local_file );
			}
		}

		wp_send_json_success( array_merge( $status_data, array(
			'bytes_uploaded' => $bytes_uploaded,
			'file_size'      => $file_size,
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
		self::cleanup_retry_meta( $local_id );
	}
}
