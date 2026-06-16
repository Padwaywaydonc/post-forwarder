<?php
/**
 * Plugin Name: Post Forwarder
 * Plugin URI: https://github.com/Padwaywaydonc/post-forwarder
 * Description: Forwards posts to other WordPress sites via REST API with taxonomy mapping and featured image support.
 * Version: 3.0.1
 * Author: Sylwester Ulatowski
 * Author email: sylwesterulatowski@gmail.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: post-forwarder
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'POST_FORWARDER_VERSION', '3.0.0' );
define( 'POST_FORWARDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'POST_FORWARDER_URL', plugin_dir_url( __FILE__ ) );

// Create languages directory if it doesn't exist
if ( ! file_exists( POST_FORWARDER_DIR . 'languages' ) ) {
    wp_mkdir_p( POST_FORWARDER_DIR . 'languages' );
}

// Load includes in dependency order
require_once POST_FORWARDER_DIR . 'includes/helpers.php';
require_once POST_FORWARDER_DIR . 'includes/scheduler.php';
require_once POST_FORWARDER_DIR . 'includes/review-notice.php';
require_once POST_FORWARDER_DIR . 'includes/metabox.php';
require_once POST_FORWARDER_DIR . 'includes/forwarding/linkedin.php';
require_once POST_FORWARDER_DIR . 'includes/forwarding/x.php';
require_once POST_FORWARDER_DIR . 'includes/forwarding/wordpress.php';
require_once POST_FORWARDER_DIR . 'admin/handlers.php';
require_once POST_FORWARDER_DIR . 'admin/calendar.php';
require_once POST_FORWARDER_DIR . 'admin/settings.php';

// ── Hook registrations ────────────────────────────────────────────────────────

// Review notice
add_action( 'admin_notices', 'post_forwarder_review_notice' );
add_action( 'wp_ajax_post_forwarder_dismiss_review', 'post_forwarder_dismiss_review' );

// Display per-portal forwarding results on the post edit screen after publishing.
add_action( 'admin_notices', function () {
    $screen = get_current_screen();
    if ( ! $screen || 'post' !== $screen->base ) {
        return;
    }
    $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
    if ( ! $post_id ) {
        return;
    }
    $key     = 'pf_forward_results_' . get_current_user_id() . '_' . $post_id;
    $results = get_transient( $key );
    if ( ! $results || ! is_array( $results ) ) {
        return;
    }
    delete_transient( $key );

    $has_success = false;
    $has_failure = false;
    foreach ( $results as $r ) {
        if ( $r['success'] ) { $has_success = true; } else { $has_failure = true; }
    }
    $notice_type = ( $has_failure && ! $has_success ) ? 'error' : ( $has_failure ? 'warning' : 'success' );

    echo '<div class="notice notice-' . esc_attr( $notice_type ) . ' is-dismissible">';
    echo '<p><strong>' . esc_html__( 'Post Forwarder results:', 'post-forwarder' ) . '</strong></p>';
    echo '<ul style="margin:2px 0 6px 18px;list-style:disc;">';
    foreach ( $results as $r ) {
        $color = $r['success'] ? '#00a32a' : '#cc0000';
        $icon  = $r['success'] ? '✓' : '✗';
        $badge = '';
        if ( isset( $r['type'] ) ) {
            $badge_colors = array( 'linkedin' => '#0a66c2', 'x' => '#000', 'wordpress' => '#3858e9' );
            $badge_labels = array( 'linkedin' => 'LI', 'x' => 'X', 'wordpress' => 'WP' );
            if ( isset( $badge_colors[ $r['type'] ] ) ) {
                $badge = ' <span style="background:' . esc_attr( $badge_colors[ $r['type'] ] ) . ';color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;">' . esc_html( $badge_labels[ $r['type'] ] ) . '</span>';
            }
        }
        echo '<li>';
        echo '<span style="color:' . esc_attr( $color ) . ';font-weight:700;">' . esc_html( $icon ) . '</span> ';
        echo '<strong>' . esc_html( $r['name'] ) . '</strong>' . $badge . ': '; // phpcs:ignore
        echo esc_html( $r['message'] );
        echo '</li>';
    }
    echo '</ul></div>';
} );

// Custom cron interval.
add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['pf_every_minute'] ) ) {
        $schedules['pf_every_minute'] = array(
            'interval' => 60,
            'display'  => 'Every Minute (Post Forwarder)',
        );
    }
    return $schedules;
} );

// Execute scheduled items (WP-Cron path).
add_action( 'post_forwarder_run_schedule', 'post_forwarder_execute_schedule' );

// Self-trigger: run schedule on any page request when items are due.
// Works even on zero-traffic sites — any visit (bot, health check, admin) counts.
add_action( 'init', 'post_forwarder_maybe_run_schedule' );

// Proactively refresh X tokens before they expire.
add_action( 'post_forwarder_refresh_x_tokens', 'post_forwarder_refresh_x_tokens' );

// Admin menu
add_action( 'admin_menu', function () {
    add_menu_page(
        __( 'Post Forwarder', 'post-forwarder' ),
        __( 'Post Forwarder', 'post-forwarder' ),
        'manage_options',
        'post-forwarder',
        'post_forwarder_calendar_page',
        'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>' ),
        30
    );
    add_submenu_page(
        'post-forwarder',
        __( 'Calendar', 'post-forwarder' ),
        __( 'Calendar', 'post-forwarder' ),
        'manage_options',
        'post-forwarder',
        'post_forwarder_calendar_page'
    );
    add_submenu_page(
        'post-forwarder',
        __( 'Settings', 'post-forwarder' ),
        __( 'Settings', 'post-forwarder' ),
        'manage_options',
        'post-forwarder-settings',
        'post_forwarder_settings_page'
    );
} );

// Register settings
add_action( 'admin_init', 'post_forwarder_register_settings' );

// Handle the portals form submission (PRG pattern)
add_action( 'admin_init', 'post_forwarder_handle_portals_save' );

// REST API endpoints
add_action( 'rest_api_init', 'post_forwarder_register_results_rest_route' );
add_action( 'rest_api_init', 'post_forwarder_register_schedule_rest_routes' );

// Enqueue Gutenberg save-listener
add_action( 'admin_enqueue_scripts', 'post_forwarder_enqueue_scripts' );

// Add meta box to post editor
add_action( 'add_meta_boxes', function () {
    $post_types = get_post_types( array( 'public' => true ), 'names' );
    foreach ( $post_types as $post_type ) {
        add_meta_box(
            'post_forwarding_meta_box',
            __( 'Post Forwarder', 'post-forwarder' ),
            'post_forwarder_meta_box_callback',
            $post_type,
            'side',
            'default'
        );
    }
} );

// Save meta box data
add_action( 'save_post', function ( $post_id ) {
    if ( ! isset( $_POST['post_forwarding_meta_box_nonce'] ) ) {
        return;
    }

    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['post_forwarding_meta_box_nonce'] ) ), 'post_forwarding_meta_box' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Clear existing product meta
    delete_post_meta( $post_id, 'product' );

    if ( isset( $_POST['post_forwarding_product'] ) && is_array( $_POST['post_forwarding_product'] ) ) {
        $products = array_map( 'sanitize_text_field', wp_unslash( $_POST['post_forwarding_product'] ) );
        foreach ( $products as $product ) {
            if ( ! empty( $product ) ) {
                add_post_meta( $post_id, 'product', $product );
            }
        }
    }
}, 5, 1 );

// Forward post on save_post
add_action( 'save_post', 'post_forwarder_forward_post', 20, 1 );

// AJAX handlers
add_action( 'wp_ajax_post_forwarder_test_connection', 'post_forwarder_test_connection_callback' );
add_action( 'wp_ajax_pf_add_channel', 'post_forwarder_ajax_add_channel' );

// Ensure the schedule table exists on every load if it hasn't been created yet.
add_action( 'plugins_loaded', function () {
    if ( get_option( 'pf_schedule_db_version' ) !== '1.0' ) {
        post_forwarder_create_schedule_table();
    }
    if ( ! wp_next_scheduled( 'post_forwarder_run_schedule' ) ) {
        wp_schedule_event( time(), 'pf_every_minute', 'post_forwarder_run_schedule' );
    }
    if ( ! wp_next_scheduled( 'post_forwarder_refresh_x_tokens' ) ) {
        wp_schedule_event( time(), 'hourly', 'post_forwarder_refresh_x_tokens' );
    }
} );

// Plugin activation hook
register_activation_hook( __FILE__, function () {
    add_option( 'post_forwarding_options', array(
        'enabled'     => false,
        'post_status' => 'draft',
        'mappings'    => '{}',
    ) );
    post_forwarder_create_schedule_table();
    if ( ! wp_next_scheduled( 'post_forwarder_run_schedule' ) ) {
        wp_schedule_event( time(), 'pf_every_minute', 'post_forwarder_run_schedule' );
    }
    if ( ! wp_next_scheduled( 'post_forwarder_refresh_x_tokens' ) ) {
        wp_schedule_event( time(), 'hourly', 'post_forwarder_refresh_x_tokens' );
    }
} );

// Plugin deactivation hook
register_deactivation_hook( __FILE__, function () {
    // Use WordPress option deletion instead of direct database queries
    $option_names = array();

    // Get all transient options for this plugin
    $transient_patterns = array(
        'post_forwarding_processing_',
        'post_forwarding_lock_',
        'post_forwarded_'
    );

    // Since we can't use direct database queries, we'll let WordPress handle cleanup
    // The transients will expire naturally based on their timeout values

    // Alternative: Clean up known transients if we have post IDs
    // This is more compliant but less comprehensive
    $recent_posts = get_posts( array(
        'numberposts' => 100,
        'post_status' => array( 'publish', 'draft', 'private' ),
        'fields' => 'ids'
    ) );

    foreach ( $recent_posts as $post_id ) {
        delete_transient( 'post_forwarding_processing_' . $post_id );
        delete_transient( 'post_forwarding_lock_' . $post_id );
        delete_transient( 'post_forwarded_' . $post_id );
    }

    wp_clear_scheduled_hook( 'post_forwarder_run_schedule' );
    wp_clear_scheduled_hook( 'post_forwarder_refresh_x_tokens' );
} );
