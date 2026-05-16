<?php
namespace VideoOffloadVideoPress;

/**
 * Replaces local video references in post content with VideoPress embeds.
 */
class Content_Replacer {

	/**
	 * Find all posts/pages that reference the attachment and replace them with
	 * a VideoPress embed. Handles Gutenberg video blocks, <video> tags,
	 * [video] shortcodes, and bare URLs.
	 *
	 * @return array{replaced: int[], block: string}|\WP_Error
	 */
	public static function replace_in_content( int $attachment_id ) {
		$guid = get_post_meta( $attachment_id, Offloader::GUID_META, true );

		if ( ! $guid ) {
			return new \WP_Error( 'no_guid', 'No VideoPress GUID found. Offload the video first.' );
		}

		$attachment_url = wp_get_attachment_url( $attachment_id );
		$vp_block       = self::build_videopress_block( $attachment_id, $guid );

		global $wpdb;

		$posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash', 'auto-draft')
				 AND post_type NOT IN ('revision', 'attachment', 'nav_menu_item')
				 AND post_content LIKE %s",
				'%' . $wpdb->esc_like( $attachment_url ) . '%'
			)
		);

		$replaced = array();

		foreach ( $posts as $post ) {
			$new_content = self::swap_video( $post->post_content, $attachment_url, $guid, $vp_block );

			if ( $new_content === $post->post_content ) {
				continue;
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->posts,
				array( 'post_content' => $new_content ),
				array( 'ID' => $post->ID ),
				array( '%s' ),
				array( '%d' )
			);

			clean_post_cache( $post->ID );
			$replaced[] = (int) $post->ID;
		}

		return array(
			'replaced' => $replaced,
			'block'    => $vp_block,
		);
	}

	/**
	 * Return all posts that reference this attachment URL (for the UI preview).
	 *
	 * @return \stdClass[]
	 */
	public static function find_referencing_posts( int $attachment_id ): array {
		$attachment_url = wp_get_attachment_url( $attachment_id );

		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_status
				 FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash', 'auto-draft')
				 AND post_type NOT IN ('revision', 'attachment', 'nav_menu_item')
				 AND post_content LIKE %s
				 ORDER BY post_modified DESC",
				'%' . $wpdb->esc_like( $attachment_url ) . '%'
			)
		);
	}

	/**
	 * Build a self-closing wp:videopress/video block.
	 *
	 * Dimensions and duration come from WordPress attachment metadata (no
	 * extra API call needed). VideoPress embeds at 600 px wide; height is
	 * scaled from the video's native aspect ratio. Jetpack refreshes all
	 * other attributes (tracks, rating, privacy) the first time the block
	 * is opened in the editor.
	 */
	private static function build_videopress_block( int $attachment_id, string $guid ): string {
		$media_id = (int) ( get_post_meta( $attachment_id, Offloader::MEDIA_ID_META, true ) ?: $attachment_id );
		$title    = get_the_title( $attachment_id );

		// Pull video dimensions and length from WP attachment metadata.
		$meta     = wp_get_attachment_metadata( $attachment_id ) ?: array();
		$vid_w    = ! empty( $meta['width'] )  ? (int) $meta['width']  : 0;
		$vid_h    = ! empty( $meta['height'] ) ? (int) $meta['height'] : 0;
		$duration = ! empty( $meta['length'] ) ? (int) round( (float) $meta['length'] * 1000 ) : 0;

		// VideoPress player is always 600 px wide; height follows the video ratio.
		$embed_w     = 600;
		$embed_h     = ( $vid_w > 0 && $vid_h > 0 ) ? (int) round( $embed_w * $vid_h / $vid_w ) : 338;
		$video_ratio = ( $vid_w > 0 && $vid_h > 0 ) ? $vid_h / $vid_w * 100 : $embed_h / $embed_w * 100;

		// &amp; in the src matches how VideoPress serialises the URL inside cacheHtml.
		$embed_url = 'https://videopress.com/embed/' . rawurlencode( $guid )
			. '?cover=1&amp;preloadContent=metadata&amp;useAverageColor=1&amp;hd=0';

		$cache_html = '<iframe title="VideoPress Video Player"'
			. " aria-label='VideoPress Video Player'"
			. " width='{$embed_w}' height='{$embed_h}'"
			. " src='{$embed_url}'"
			. " frameborder='0' allowfullscreen data-resize-to-parent=\"true\""
			. " allow='clipboard-write'></iframe>"
			. "<script src='https://v0.wordpress.com/js/next/videopress-iframe.js'></script>";

		$attrs = array(
			'title'          => $title,
			'description'    => '',
			'id'             => $media_id,
			'guid'           => $guid,
			'cacheHtml'      => $cache_html,
			'videoRatio'     => $video_ratio,
			'tracks'         => array(),
			'privacySetting' => 2,
			'allowDownload'  => false,
			'rating'         => 'G',
			'isPrivate'      => false,
			'duration'       => $duration,
			'className'      => 'wp-block-videopress-video wp-block-embed is-type-video',
		);

		// JSON_HEX_TAG|HEX_AMP|HEX_QUOT reproduces the < / " / &
		// escaping that VideoPress itself uses when serialising block attributes.
		$attrs_json = wp_json_encode(
			$attrs,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
		) ?: '{}';

		return '<!-- wp:videopress/video ' . $attrs_json . ' /-->';
	}

	/**
	 * Perform all URL-pattern replacements on a single post's content string.
	 * Uses preg_replace_callback so the replacement is never processed for
	 * backreferences — safe with JSON braces and special characters.
	 */
	private static function swap_video( string $content, string $local_url, string $guid, string $vp_block ): string {
		$vp_shortcode = '[videopress ' . $guid . ']';

		// Gutenberg core video block → VideoPress block (callback avoids backreference processing).
		$content = preg_replace_callback(
			'/<!-- wp:video(?:\s[^>]*)? -->.*?<!-- \/wp:video -->/si',
			static function ( $matches ) use ( $vp_block, $local_url ) {
				// Only replace blocks that reference this attachment's local URL.
				if ( strpos( $matches[0], $local_url ) === false ) {
					return $matches[0];
				}
				return $vp_block;
			},
			$content
		);

		// <video src="local_url"> — local URL in the opening tag attributes.
		$escaped_url = preg_quote( $local_url, '/' );
		$content     = preg_replace(
			'/<video[^>]*\bsrc=["\']?' . $escaped_url . '["\']?[^>]*>.*?<\/video>/si',
			$vp_shortcode,
			$content
		);

		// <video><source src="local_url"></video> — local URL in a <source> child.
		$content = preg_replace(
			'/<video[^>]*>\s*<source[^>]*\bsrc=["\']?' . $escaped_url . '["\']?[^>]*>\s*<\/video>/si',
			$vp_shortcode,
			$content
		);

		// [video src="..."] shortcode.
		$content = preg_replace(
			'/\[video[^\]]*src=["\']?' . preg_quote( $local_url, '/' ) . '["\']?[^\]]*\]/i',
			$vp_shortcode,
			$content
		);

		// Bare URL on its own line (WordPress auto-embed).
		$content = str_replace( $local_url, $vp_shortcode, $content );

		return $content;
	}

	// -------------------------------------------------------------------------
	// AJAX handler
	// -------------------------------------------------------------------------

	public static function ajax_replace(): void {
		check_ajax_referer( 'vov_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( 'Invalid attachment ID.' );
		}

		$result = self::replace_in_content( $attachment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array(
			'replaced_posts' => $result['replaced'],
			'count'          => count( $result['replaced'] ),
		) );
	}
}
