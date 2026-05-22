<?php
namespace VideoOffloadVideoPress;

/**
 * Handles VideoPress uploads via Jetpack's local REST endpoint:
 *   POST /videopress/v1/upload/{attachment_id}
 *
 * This endpoint is registered by Jetpack's VideoPress module and is designed
 * exactly for offloading an existing media library attachment to VideoPress.
 * Running it via rest_do_request means all WPCOM auth is handled internally.
 */
class VideoPress_API {

	const UPLOAD_ROUTE = '/videopress/v1/upload/';

	public static function get_blog_id(): int {
		return (int) \Jetpack_Options::get_option( 'id' );
	}

	/**
	 * Terminate the VideoPress tus session for an attachment and delete the local
	 * WP transient so the next upload creates a genuinely fresh session.
	 *
	 * Jetpack keys the transient as "s-{site_id}-v-{attachment_id}". When VideoPress
	 * receives a new upload POST for the same Upload-Key while an existing session
	 * is still alive server-side, it returns the old (expired) location URL. PATCHing
	 * that URL gets 460. Sending DELETE first tells VideoPress to discard the session,
	 * so the next POST gets a truly new location.
	 */
	public static function clear_upload_cache( int $attachment_id ): void {
		$key  = sprintf( 's-%d-v-%d', self::get_blog_id(), $attachment_id );
		$data = get_transient( $key );

		if ( is_array( $data ) && ! empty( $data['location'] ) ) {
			$headers = array( 'Tus-Resumable' => '1.0.0' );
			if ( ! empty( $data['token_for_key'] ) ) {
				$headers['x-videopress-upload-token'] = $data['token_for_key'];
			}
			// Fire-and-forget: if VideoPress doesn't support DELETE we just move on.
			wp_remote_request(
				$data['location'],
				array(
					'method'  => 'DELETE',
					'headers' => $headers,
					'timeout' => 10,
				)
			);
		}

		delete_transient( $key );
	}

	public static function is_connected(): bool {
		return self::get_blog_id() > 0 && \Jetpack::is_connection_ready();
	}

	public static function current_user_is_connected(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$connection = \Jetpack::connection();
		if ( method_exists( $connection, 'is_user_connected' ) ) {
			return $connection->is_user_connected( get_current_user_id() );
		}
		return \Jetpack::is_user_connected( get_current_user_id() );
	}

	/**
	 * Check whether a VideoPress GUID still exists on WordPress.com.
	 *
	 * Returns false only on a definitive 404 (video was deleted).
	 * Returns true on network errors or any non-404 response to avoid
	 * false-positive status resets.
	 */
	public static function verify_guid( string $guid ): bool {
		$response = wp_remote_get(
			'https://public-api.wordpress.com/rest/v1.1/videos/' . rawurlencode( $guid ),
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return true; // Network error — assume the video still exists.
		}

		return (int) wp_remote_retrieve_response_code( $response ) !== 404;
	}

	/**
	 * Find registered REST routes that mention "video" — used for diagnostics.
	 */
	public static function find_videopress_routes(): array {
		$found = array();
		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			if ( stripos( $route, 'video' ) !== false ) {
				$found[] = $route;
			}
		}
		return $found;
	}

	/**
	 * Offload an existing media library attachment to VideoPress.
	 *
	 * For large files VideoPress uses a chunked upload: the first call returns
	 * an array with 'uploading' => true and an 'upload_key'. Pass that key on
	 * subsequent calls until a 'guid' is returned.
	 *
	 * @return array{guid: string, media_id: int}|array{uploading: true, upload_key: string, bytes_uploaded: int, file_size: int}|\WP_Error
	 */
	public static function upload_video( int $attachment_id, string $upload_key = '', bool $strip_checksum = false ) {
		if ( ! self::is_connected() ) {
			return new \WP_Error( 'no_connection', __( 'Jetpack is not connected to WordPress.com.', 'video-offload-videopress' ) );
		}

		$route  = self::UPLOAD_ROUTE . $attachment_id;
		$server = rest_get_server();

		// Verify the route pattern exists before dispatching.
		$route_exists = false;
		foreach ( array_keys( $server->get_routes() ) as $registered ) {
			if ( preg_match( '@^' . $registered . '$@i', $route ) ) {
				$route_exists = true;
				break;
			}
		}

		if ( ! $route_exists ) {
			$video_routes = self::find_videopress_routes();
			return new \WP_Error(
				'no_upload_route',
				sprintf(
					'Upload route not found (%s). Available video routes: %s',
					$route,
					$video_routes ? implode( ', ', $video_routes ) : 'none'
				)
			);
		}

		$request = new \WP_REST_Request( 'POST', $route );
		if ( $upload_key ) {
			$request->set_param( 'upload_key', $upload_key );
		}

		// Increase HTTP timeout for VideoPress tus requests — the default 25 s can
		// be too short on Atomic where outbound proxies may introduce latency.
		$bump_timeout = static function ( $args, $url ) {
			if ( strpos( $url, 'public-api.wordpress.com' ) !== false ) {
				$args['timeout'] = 120;
			}
			return $args;
		};
		add_filter( 'http_request_args', $bump_timeout, 999, 2 );

		// Optionally strip the Upload-Checksum header from the tus session-creation
		// POST so VideoPress cannot match this upload to a stale cached session for
		// the same file content. The create request is identified by Upload-Length.
		$strip_fn = null;
		if ( $strip_checksum ) {
			$strip_fn = static function ( $args, $url ) {
				if (
					strpos( $url, 'public-api.wordpress.com/rest/v1.1/video-uploads/' ) !== false
					&& ! empty( $args['headers']['Upload-Length'] )
				) {
					unset( $args['headers']['Upload-Checksum'] );
				}
				return $args;
			};
			add_filter( 'http_request_args', $strip_fn, 998, 2 );
		}

		$response = rest_do_request( $request );
		remove_filter( 'http_request_args', $bump_timeout, 999 );
		if ( $strip_fn ) {
			remove_filter( 'http_request_args', $strip_fn, 998 );
		}

		if ( $response->is_error() ) {
			$error   = $response->as_error();
			$message = $error->get_error_message();
			if (
				400 === $response->get_status()
				&& false !== stripos( $message, 'attachment_id' )
			) {
				$file_path = get_attached_file( $attachment_id );
				if ( $file_path && file_exists( $file_path ) ) {
					$message = sprintf( 'VideoPress could not read the local file (path: %s). Check file permissions.', $file_path );
				} else {
					$message = sprintf( 'VideoPress could not find the local file (path: %s).', $file_path ?: 'unknown' );
				}
			} else {
				$message = sprintf( '[%d] %s', $response->get_status(), $message );
			}
			return new \WP_Error( $error->get_error_code(), $message );
		}

		$data = $response->get_data();

		// The response shape may vary by Jetpack version — try known field names.
		$guid     = $data['uploaded_details']['guid']
			?? $data['uploaded_video_guid']
			?? $data['videopress_guid']
			?? $data['guid']
			?? $data['VideoGUID']
			?? $data['media']['videopress_guid']
			?? null;

		$media_id = (int) ( $data['uploaded_details']['media_id']
			?? $data['uploaded_post_id']
			?? $data['media_id']
			?? $data['ID']
			?? $data['VideoID']
			?? 0 );

		// No GUID yet — upload is still in progress or VideoPress returned an error.
		if ( ! $guid ) {
			if ( isset( $data['status'] ) && 'uploading' === $data['status'] ) {
				return array(
					'uploading'      => true,
					'upload_key'     => (string) ( $data['upload_key'] ?? '' ),
					'bytes_uploaded' => (int) ( $data['bytes_uploaded'] ?? 0 ),
					'file_size'      => (int) ( $data['file_size'] ?? 0 ),
				);
			}

			// VideoPress returned an explicit error (e.g. 409 conflict, 460 session expired).
			if ( isset( $data['status'] ) && 'error' === $data['status'] ) {
				$vp_msg = trim( (string) ( $data['error'] ?? '' ) );
				error_log( 'VOV VideoPress error response: ' . wp_json_encode( $data ) );
				return new \WP_Error(
					'videopress_error',
					( $vp_msg ?: 'VideoPress upload failed' ) . ' | Full response: ' . wp_json_encode( $data ),
					array( 'bytes_uploaded' => (int) ( $data['bytes_uploaded'] ?? 0 ) )
				);
			}

			return new \WP_Error(
				'no_guid',
				'VideoPress did not return a GUID. Response: ' . wp_json_encode( $data )
			);
		}

		return array(
			'guid'     => $guid,
			'media_id' => $media_id,
		);
	}
}
