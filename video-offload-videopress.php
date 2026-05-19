<?php
/**
 * Plugin Name: Video Offload for VideoPress
 * Description: Offloads locally-stored videos to VideoPress via Jetpack. Requires a Jetpack plan that includes VideoPress.
 * Version: 1.3.5
 * Requires Plugins: jetpack
 * License: GPL-2.0-or-later
 * Text Domain: video-offload-videopress
 */

namespace VideoOffloadVideoPress;

defined( 'ABSPATH' ) || exit;

define( 'VOV_VERSION', '1.3.5' );
define( 'VOV_PLUGIN_FILE', __FILE__ );
define( 'VOV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VOV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once VOV_PLUGIN_DIR . 'includes/class-videopress-api.php';
require_once VOV_PLUGIN_DIR . 'includes/class-offloader.php';
require_once VOV_PLUGIN_DIR . 'includes/class-content-replacer.php';
require_once VOV_PLUGIN_DIR . 'includes/class-admin.php';

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'Jetpack' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Video Offload for VideoPress requires Jetpack to be installed and connected to WordPress.com.', 'video-offload-videopress' )
				. '</p></div>';
		} );
		return;
	}

	if ( \Jetpack::is_connection_ready() && ! \Jetpack::is_module_active( 'videopress' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-warning"><p>'
				. wp_kses(
					__( 'Video Offload for VideoPress: the VideoPress feature is not active. It is available on WordPress.com Premium, Business, and Commerce plans, or on self-hosted sites with a Jetpack plan that includes VideoPress. Activate it under <a href="' . esc_url( admin_url( 'admin.php?page=jetpack#/performance' ) ) . '">Jetpack → Performance</a>.', 'video-offload-videopress' ),
					array( 'strong' => array(), 'a' => array( 'href' => array() ) )
				)
				. '</p></div>';
		} );
	}

	Admin::init();
} );

add_action( 'wp_ajax_vov_offload_video',   array( Offloader::class,        'ajax_offload' ) );
add_action( 'wp_ajax_vov_get_status',      array( Offloader::class,        'ajax_get_status' ) );
add_action( 'wp_ajax_vov_replace_content', array( Content_Replacer::class, 'ajax_replace' ) );
add_action( 'wp_ajax_vov_delete_local',    array( Offloader::class,        'ajax_delete_local' ) );
add_action( 'wp_ajax_vov_verify_guid',    array( Offloader::class,        'ajax_verify_guid' ) );
