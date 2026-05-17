<?php
namespace VideoOffloadVideoPress;

/**
 * Admin UI: media library column, attachment edit screen field, and bulk offload page.
 */
class Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );

		// Media library list view column.
		add_filter( 'manage_media_columns', array( self::class, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( self::class, 'render_media_column' ), 10, 2 );

		// Attachment edit screen (post.php — not the grid modal).
		add_filter( 'attachment_fields_to_edit', array( self::class, 'add_attachment_fields' ), 10, 2 );
	}

	public static function add_menu(): void {
		add_media_page(
			__( 'VideoPress Offload', 'video-offload-videopress' ),
			__( 'VideoPress Offload', 'video-offload-videopress' ),
			'upload_files',
			'video-offload-videopress',
			array( self::class, 'render_admin_page' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		$allowed_hooks = array( 'media_page_video-offload-videopress', 'upload.php', 'post.php', 'post-new.php' );

		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'vov-admin', VOV_PLUGIN_URL . 'assets/admin.css', array(), VOV_VERSION );
		wp_enqueue_script( 'vov-admin', VOV_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), VOV_VERSION, true );

		wp_localize_script( 'vov-admin', 'vovData', array(
			'nonce'   => wp_create_nonce( 'vov_nonce' ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'strings' => array(
				'offloading'    => __( 'Uploading to VideoPress…', 'video-offload-videopress' ),
				'replacing'     => __( 'Replacing in content…', 'video-offload-videopress' ),
				'deleting'      => __( 'Deleting local file…', 'video-offload-videopress' ),
				'confirmDelete' => __( 'Delete the local video file? This cannot be undone. Confirm VideoPress has the video before proceeding.', 'video-offload-videopress' ),
				'error'         => __( 'Error: ', 'video-offload-videopress' ),
				'done'          => __( 'Done! Reloading…', 'video-offload-videopress' ),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// Media library column
	// -------------------------------------------------------------------------

	public static function add_media_column( array $columns ): array {
		$columns['vov_status'] = __( 'VideoPress', 'video-offload-videopress' );
		return $columns;
	}

	public static function render_media_column( string $column_name, int $post_id ): void {
		if ( $column_name !== 'vov_status' ) {
			return;
		}

		$mime = get_post_mime_type( $post_id );

		// Not a video, or already natively on VideoPress — nothing to offload.
		if ( strpos( $mime, 'video/' ) !== 0 || 'video/videopress' === $mime ) {
			echo '&mdash;';
			return;
		}

		self::render_status_cell( $post_id, Offloader::get_status( $post_id ) );
	}

	// -------------------------------------------------------------------------
	// Attachment edit screen
	// -------------------------------------------------------------------------

	public static function add_attachment_fields( array $fields, \WP_Post $post ): array {
		// Skip non-videos and videos already natively on VideoPress.
		if ( strpos( $post->post_mime_type, 'video/' ) !== 0 || 'video/videopress' === $post->post_mime_type ) {
			return $fields;
		}

		ob_start();
		self::render_status_cell( $post->ID, Offloader::get_status( $post->ID ) );
		$html = ob_get_clean();

		$fields['vov_status'] = array(
			'label' => __( 'VideoPress Offload', 'video-offload-videopress' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $fields;
	}

	// -------------------------------------------------------------------------
	// Shared status cell HTML
	// -------------------------------------------------------------------------

	public static function render_status_cell( int $attachment_id, array $s ): void {
		$id      = esc_attr( $attachment_id );
		$status  = $s['status'];
		$guid    = $s['guid'];
		$deleted = $s['deleted'];

		$started  = (int) get_post_meta( $attachment_id, Offloader::UPLOAD_STARTED_META, true );
		$is_fresh = $started && $started > time() - 30 * MINUTE_IN_SECONDS;

		$extra = '';
		if ( Offloader::STATUS_UPLOADING === $status && $is_fresh ) {
			$extra .= ' data-auto-poll="1"';
		}
		if ( Offloader::STATUS_UPLOADED === $status && $guid ) {
			$last_verified = (int) get_post_meta( $attachment_id, Offloader::LAST_VERIFIED_META, true );
			$extra        .= ' data-verify-guid="1" data-last-verified="' . esc_attr( $last_verified ) . '"';
		}

		echo '<div class="vov-status-cell" data-attachment-id="' . $id . '"' . $extra . '>';

		switch ( $status ) {
			case Offloader::STATUS_UPLOADING:
				if ( $is_fresh ) {
					// Upload is in progress (e.g. user refreshed mid-upload).
					// JS will auto-poll this cell until complete.
					echo '<span class="vov-badge vov-badge--uploading">' . esc_html__( 'Uploading…', 'video-offload-videopress' ) . '</span>';
					echo '<span class="vov-uploading-msg"><span class="vov-spinner"></span>' . esc_html__( 'Offloading, please wait…', 'video-offload-videopress' ) . '</span>';
				} else {
					// Timestamp is old or missing — the previous attempt died.
					echo '<span class="vov-badge vov-badge--uploading">' . esc_html__( 'Stuck', 'video-offload-videopress' ) . '</span>';
					printf(
						'<button class="button button-small vov-btn-offload" data-id="%s">%s</button>',
						$id,
						esc_html__( 'Retry', 'video-offload-videopress' )
					);
				}
				break;

			case Offloader::STATUS_UPLOADED:
				echo '<span class="vov-badge vov-badge--uploaded">' . esc_html__( 'On VideoPress', 'video-offload-videopress' ) . '</span>';
				echo '<div class="vov-actions">';

				if ( $guid ) {
					printf(
						'<a href="https://videopress.com/v/%s" target="_blank" rel="noopener noreferrer" class="button button-small">%s</a>',
						esc_attr( $guid ),
						esc_html__( 'View on VideoPress', 'video-offload-videopress' )
					);
					printf(
						'<button class="button button-small vov-btn-replace" data-id="%s">%s</button>',
						$id,
						esc_html__( 'Replace in Content', 'video-offload-videopress' )
					);
				}

				if ( ! $deleted ) {
					printf(
						'<button class="button button-small button-link-delete vov-btn-delete" data-id="%s">%s</button>',
						$id,
						esc_html__( 'Delete Local File', 'video-offload-videopress' )
					);
				} else {
					echo '<span class="vov-local-deleted">' . esc_html__( 'Local file deleted', 'video-offload-videopress' ) . '</span>';
				}

				echo '</div>';
				break;

			case Offloader::STATUS_ERROR:
				echo '<span class="vov-badge vov-badge--error">' . esc_html__( 'Error', 'video-offload-videopress' ) . '</span>';
				if ( ! empty( $s['error'] ) ) {
					echo '<p class="vov-error-msg">' . esc_html( $s['error'] ) . '</p>';
				}
				printf(
					'<button class="button button-small vov-btn-offload" data-id="%s">%s</button>',
					$id,
					esc_html__( 'Retry', 'video-offload-videopress' )
				);
				break;

			default: // none / empty
				echo '<span class="vov-badge vov-badge--local">' . esc_html__( 'Local only', 'video-offload-videopress' ) . '</span>';
				printf(
					'<button class="button button-small vov-btn-offload" data-id="%s">%s</button>',
					$id,
					esc_html__( 'Offload to VideoPress', 'video-offload-videopress' )
				);
				break;
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Admin page
	// -------------------------------------------------------------------------

	/**
	 * Returns true only when Jetpack is connected AND the VideoPress module is active.
	 */
	public static function is_videopress_ready(): bool {
		return class_exists( '\Jetpack' )
			&& \Jetpack::is_connection_ready()
			&& \Jetpack::is_module_active( 'videopress' );
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'video-offload-videopress' ) );
		}

		// Clear any statuses stuck in "uploading" from a previous failed attempt.
		Offloader::reset_stuck_uploads();

		$is_connected  = class_exists( '\Jetpack' ) && \Jetpack::is_connection_ready();
		$vp_active     = $is_connected && \Jetpack::is_module_active( 'videopress' );
		$site_private  = (int) get_option( 'blog_public' ) === -1;
		$ready         = $is_connected && $vp_active && ! $site_private;
		$total         = $ready ? Offloader::count_local_videos() : 0;
		$videos        = $ready ? Offloader::get_local_videos( 0, 200 ) : array();
		?>
		<div class="wrap vov-wrap">
			<h1><?php esc_html_e( 'VideoPress Offload', 'video-offload-videopress' ); ?></h1>

			<?php if ( ! $is_connected ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'Jetpack is not connected to WordPress.com. Please connect Jetpack to use VideoPress.', 'video-offload-videopress' ); ?></p>
				</div>
			<?php elseif ( $site_private ) : ?>
				<div class="notice notice-error inline">
					<p>
					<?php echo wp_kses(
						sprintf(
							/* translators: %s: URL to Reading settings */
							__( '<strong>This site is set to private.</strong> VideoPress offloading requires WordPress.com to fetch videos from your site, which is not possible while the site is private. Set the site to public under <a href="%s">Settings → Reading</a>, run the offload, then set it back to private if needed.', 'video-offload-videopress' ),
							esc_url( admin_url( 'options-reading.php' ) )
						),
						array( 'strong' => array(), 'a' => array( 'href' => array() ) )
					); ?>
					</p>
				</div>
			<?php elseif ( ! $vp_active ) : ?>
				<div class="notice notice-warning inline">
					<p>
					<?php echo wp_kses(
						sprintf(
							/* translators: %s: URL to Jetpack performance settings */
							__( 'The VideoPress feature is not active. It is included in <strong>Premium, Business, and Commerce</strong> WordPress.com plans. Once your site is on a qualifying plan, activate it under <a href="%s">Jetpack → Performance</a>.', 'video-offload-videopress' ),
							esc_url( admin_url( 'admin.php?page=jetpack#/performance' ) )
						),
						array( 'strong' => array(), 'a' => array( 'href' => array() ) )
					); ?>
					</p>
				</div>
			<?php else : ?>

			<details class="vov-diagnostics">
				<summary><?php esc_html_e( 'Diagnostic info', 'video-offload-videopress' ); ?></summary>
				<ul>
					<li><?php echo esc_html( 'Blog ID: ' . VideoPress_API::get_blog_id() ); ?></li>
					<li><?php echo esc_html( 'User connected: ' . ( VideoPress_API::current_user_is_connected() ? 'yes' : 'no' ) ); ?></li>
					<li><?php echo esc_html( 'VideoPress module active: ' . ( \Jetpack::is_module_active( 'videopress' ) ? 'yes' : 'no' ) ); ?></li>
					<li>
						<?php
						$video_routes = VideoPress_API::find_videopress_routes();
						echo esc_html( 'Video-related REST routes: ' . ( $video_routes ? implode( ', ', $video_routes ) : 'none found' ) );
						?>
					</li>
				</ul>
			</details>

			<div class="vov-summary card">
				<?php if ( $total > 0 ) : ?>
					<p>
						<?php printf(
							/* translators: %d: number of videos */
							esc_html( _n( '%d video has not been offloaded to VideoPress yet.', '%d videos have not been offloaded to VideoPress yet.', $total, 'video-offload-videopress' ) ),
							(int) $total
						); ?>
					</p>
					<button class="button button-primary" id="vov-bulk-offload" data-total="<?php echo esc_attr( $total ); ?>">
						<?php esc_html_e( 'Offload All to VideoPress', 'video-offload-videopress' ); ?>
					</button>
					<div id="vov-bulk-progress" hidden>
						<progress id="vov-progress-bar" max="<?php echo esc_attr( $total ); ?>" value="0"></progress>
						<span id="vov-progress-text">0 / <?php echo esc_html( $total ); ?></span>
					</div>
				<?php else : ?>
					<p><?php esc_html_e( 'All videos in your media library have been offloaded to VideoPress.', 'video-offload-videopress' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $videos ) ) : ?>
			<table class="wp-list-table widefat fixed striped vov-table">
				<thead>
					<tr>
						<th class="column-title column-primary"><?php esc_html_e( 'Video', 'video-offload-videopress' ); ?></th>
						<th><?php esc_html_e( 'Type', 'video-offload-videopress' ); ?></th>
						<th><?php esc_html_e( 'File Size', 'video-offload-videopress' ); ?></th>
						<th><?php esc_html_e( 'Status', 'video-offload-videopress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $videos as $video ) :
						$file      = get_attached_file( $video->ID );
						$file_size = $file && file_exists( $file ) ? size_format( filesize( $file ) ) : '—';
						$mime      = get_post_mime_type( $video->ID );
					?>
					<tr id="vov-row-<?php echo esc_attr( $video->ID ); ?>">
						<td class="column-title column-primary">
							<strong><?php echo esc_html( $video->post_title ?: basename( $file ) ); ?></strong>
							<br><small class="vov-filename"><?php echo esc_html( basename( (string) $file ) ); ?></small>
						</td>
						<td><?php echo esc_html( $mime ); ?></td>
						<td><?php echo esc_html( $file_size ); ?></td>
						<td><?php self::render_status_cell( $video->ID, Offloader::get_status( $video->ID ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}
}
