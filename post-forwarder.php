<?php
/**
 * Plugin Name: Post Forwarder
 * Plugin URI: https://github.com/baba-gnu/post-forwarder
 * Description: Forwards posts to other WordPress sites via REST API with taxonomy mapping and featured image support.
 * Version: 2.1.0
 * Author: Sylwester Ulatowski
 * Author email: sylwesterulatowski@gmail.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: post-forwarder
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Review notice — shown immediately on relevant screens, dismissed permanently per-user.
add_action( 'admin_notices', 'post_forwarder_review_notice' );
add_action( 'wp_ajax_post_forwarder_dismiss_review', 'post_forwarder_dismiss_review' );

function post_forwarder_review_notice() {
    // Show to anyone who can edit posts (covers editors, authors) or manage options.
    if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Only on the post editor and the plugin settings page.
    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }
    $is_post_edit = in_array( $screen->base, array( 'post', 'edit' ), true );
    $is_settings  = ( 'settings_page_post-forwarding' === $screen->id );
    if ( ! $is_post_edit && ! $is_settings ) {
        return;
    }

    // Once dismissed, never show again for this user.
    if ( get_user_meta( get_current_user_id(), 'post_forwarder_review_dismissed', true ) ) {
        return;
    }
    ?>
    <div class="notice notice-info is-dismissible" id="post-forwarder-review-notice">
        <p>
            <?php esc_html_e( '👋 Enjoying Post Forwarder? It would mean a lot if you left a quick review — it helps others find the plugin.', 'post-forwarder' ); ?>
            &nbsp;
            <a href="https://wordpress.org/support/plugin/post-forwarder/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( '⭐ Leave a Review', 'post-forwarder' ); ?></a>
            <a href="#" class="button" style="margin-left:6px;" id="post-forwarder-dismiss-review"><?php esc_html_e( 'I already did', 'post-forwarder' ); ?></a>
        </p>
    </div>
    <script>
    jQuery(document).ready(function($){
        function dismissReview(){
            $.post(ajaxurl, { action: 'post_forwarder_dismiss_review', nonce: '<?php echo esc_js( wp_create_nonce( 'post_forwarder_dismiss_review' ) ); ?>' });
            $('#post-forwarder-review-notice').remove();
        }
        $('#post-forwarder-dismiss-review').on('click', function(e){ e.preventDefault(); dismissReview(); });
        $(document).on('click', '#post-forwarder-review-notice .notice-dismiss', function(){ dismissReview(); });
    });
    </script>
    <?php
}

function post_forwarder_dismiss_review() {
    check_ajax_referer( 'post_forwarder_dismiss_review', 'nonce' );
    update_user_meta( get_current_user_id(), 'post_forwarder_review_dismissed', true );
    wp_die();
}

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
            $badge_colors = array( 'linkedin' => '#0a66c2', 'x' => '#000', 'wordpress' => '#3858e9', 'meta' => '#1877f2' );
            $badge_labels = array( 'linkedin' => 'LI', 'x' => 'X', 'wordpress' => 'WP', 'meta' => 'META' );
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

// Create languages directory if it doesn't exist
if (!file_exists(plugin_dir_path(__FILE__) . 'languages')) {
    wp_mkdir_p(plugin_dir_path(__FILE__) . 'languages');
}

// Define plugin constants
define('POST_FORWARDER_VERSION', '2.1.0');
define('POST_FORWARDER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POST_FORWARDER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Return the LinkedIn app Client ID.
 *
 * Checks the POST_FORWARDER_LINKEDIN_CLIENT_ID constant first, then falls back
 * to the per-portal value stored in the database.
 *
 * @param string $fallback Per-portal value stored in settings.
 * @return string
 */
function post_forwarder_linkedin_client_id( $fallback = '' ) {
    if ( defined( 'POST_FORWARDER_LINKEDIN_CLIENT_ID' ) && POST_FORWARDER_LINKEDIN_CLIENT_ID ) {
        return POST_FORWARDER_LINKEDIN_CLIENT_ID;
    }
    return $fallback;
}

/**
 * Return the LinkedIn app Client Secret.
 *
 * Checks the POST_FORWARDER_LINKEDIN_CLIENT_SECRET constant first, then falls
 * back to the per-portal value stored in the database.
 *
 * @param string $fallback Per-portal value stored in settings.
 * @return string
 */
function post_forwarder_linkedin_client_secret( $fallback = '' ) {
    if ( defined( 'POST_FORWARDER_LINKEDIN_CLIENT_SECRET' ) && POST_FORWARDER_LINKEDIN_CLIENT_SECRET ) {
        return POST_FORWARDER_LINKEDIN_CLIENT_SECRET;
    }
    return $fallback;
}

/**
 * Return the configured OAuth relay URL (trailing slash stripped), or an
 * empty string when no relay is set up.
 *
 * Set the constant POST_FORWARDER_RELAY_URL in wp-config.php / .env to
 * point at your deployed Cloudflare Worker, e.g.:
 *   define( 'POST_FORWARDER_RELAY_URL', 'https://post-forwarder-relay.your-subdomain.workers.dev' );
 *
 * @return string
 */
function post_forwarder_relay_url() {
    if ( defined( 'POST_FORWARDER_RELAY_URL' ) && POST_FORWARDER_RELAY_URL ) {
        return rtrim( POST_FORWARDER_RELAY_URL, '/' );
    }
    $opts = get_option( 'post_forwarding_options', array() );
    if ( is_string( $opts ) ) {
        $opts = json_decode( $opts, true ) ?: array();
    }
    if ( ! empty( $opts['relay_url'] ) ) {
        return rtrim( $opts['relay_url'], '/' );
    }
    return 'https://post-forwarder-relay.sylwesterulatowski.workers.dev';
}

function post_forwarder_channel_connected( array $m ) {
    $type = isset( $m['type'] ) ? $m['type'] : 'wordpress';
    switch ( $type ) {
        case 'linkedin':
            return ! empty( $m['access_token'] )
                && ( empty( $m['token_expires'] ) || $m['token_expires'] > time() );
        case 'x':
            return (bool) post_forwarder_relay_url()
                && ! empty( $m['access_token'] )
                && ( empty( $m['token_expires'] ) || $m['token_expires'] > time() );
        case 'meta':
            return ! empty( $m['access_token'] ) && ! empty( $m['page_id'] );
        case 'wordpress':
            return ! empty( $m['user'] ) && ! empty( $m['password'] );
        default:
            return false;
    }
}

/**
 * Log a message to the PHP error log.
 *
 * Only active when WP_DEBUG_LOG is enabled.
 *
 * @param string $message The message to log.
 */
function post_forwarder_log_error( $message ) {
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( '[Post Forwarder] ' . $message );
    }
}

/**
 * Create (or upgrade) the schedule DB table.
 */
function post_forwarder_create_schedule_table() {
    global $wpdb;
    $table           = $wpdb->prefix . 'pf_schedule';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id bigint(20) UNSIGNED DEFAULT NULL,
        title text NOT NULL DEFAULT '',
        content longtext NOT NULL DEFAULT '',
        excerpt text NOT NULL DEFAULT '',
        image_url text NOT NULL DEFAULT '',
        channel_keys text NOT NULL DEFAULT '[]',
        scheduled_at datetime NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        result text NOT NULL DEFAULT '{}',
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY pf_scheduled_at (scheduled_at),
        KEY pf_status (status)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'pf_schedule_db_version', '1.0' );
}

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

// Execute scheduled items.
add_action( 'post_forwarder_run_schedule', 'post_forwarder_execute_schedule' );

function post_forwarder_execute_schedule() {
    global $wpdb;
    $table = $wpdb->prefix . 'pf_schedule';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery
    $due = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'pending' AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT 10",
            current_time( 'mysql', true ) // UTC
        )
    );

    foreach ( $due as $item ) {
        $wpdb->update( $table, array( 'status' => 'processing' ), array( 'id' => $item->id ) );

        $channel_keys = json_decode( $item->channel_keys, true );
        if ( ! is_array( $channel_keys ) ) {
            $channel_keys = array();
        }

        $post_id = (int) $item->post_id;

        // New content with no existing post: publish a new WP post.
        if ( ! $post_id && ( $item->title || $item->content ) ) {
            $new_id = wp_insert_post( array(
                'post_title'   => $item->title,
                'post_content' => $item->content,
                'post_excerpt' => $item->excerpt,
                'post_status'  => 'publish',
            ) );
            if ( $new_id && ! is_wp_error( $new_id ) ) {
                $post_id = $new_id;
                $wpdb->update( $table, array( 'post_id' => $post_id ), array( 'id' => $item->id ) );
            }
        } elseif ( $post_id ) {
            $existing = get_post( $post_id );
            if ( $existing && 'draft' === $existing->post_status ) {
                wp_publish_post( $post_id );
            }
        }

        $results = array();
        if ( $post_id && ! is_wp_error( $post_id ) ) {
            $results = post_forwarder_schedule_forward( $post_id, $channel_keys );
        }

        $wpdb->update( $table, array(
            'status' => 'sent',
            'result' => wp_json_encode( $results ),
        ), array( 'id' => $item->id ) );
    }
    // phpcs:enable
}

/**
 * Return a copy of the mappings array with sensitive values replaced by '***' for display.
 */
function post_forwarder_mask_mappings( $mappings ) {
    $sensitive = array( 'access_token', 'refresh_token', 'password', 'client_secret' );
    foreach ( $mappings as $key => $mapping ) {
        foreach ( $sensitive as $sk ) {
            if ( ! empty( $mapping[ $sk ] ) ) {
                $mappings[ $key ][ $sk ] = '***';
            }
        }
    }
    return $mappings;
}

// Register settings
add_action('admin_menu', function () {
    add_menu_page(
        __('Post Forwarder', 'post-forwarder'),
        __('Post Forwarder', 'post-forwarder'),
        'manage_options',
        'post-forwarder',
        'post_forwarder_calendar_page',
        'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4zm0 6a1 1 0 0 1 1-1h12a1 1 0 0 1 0 2H4a1 1 0 0 1-1-1zm0 4a1 1 0 0 1 1-1h7a1 1 0 0 1 0 2H4a1 1 0 0 1-1-1z"/></svg>'),
        30
    );
    add_submenu_page(
        'post-forwarder',
        __('Calendar', 'post-forwarder'),
        __('Calendar', 'post-forwarder'),
        'manage_options',
        'post-forwarder',
        'post_forwarder_calendar_page'
    );
    add_submenu_page(
        'post-forwarder',
        __('Settings', 'post-forwarder'),
        __('Settings', 'post-forwarder'),
        'manage_options',
        'post-forwarder-settings',
        'post_forwarding_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('post_forwarding', 'post_forwarding_options', array(
        'sanitize_callback' => 'post_forwarding_sanitize_options'
    ));
});

// Handle the portals form submission early (admin_init) so wp_redirect() works
// before any output is sent — standard WordPress PRG pattern.
add_action('admin_init', function () {
    if ( ! isset( $_POST['submit_portals'], $_POST['portals_nonce'] ) ) {
        return;
    }
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['portals_nonce'] ) ), 'save_portals' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $options = get_option( 'post_forwarding_options', array() );
    if ( is_string( $options ) ) {
        $options = json_decode( $options, true );
        if ( ! is_array( $options ) ) {
            $options = array();
        }
    }

    $portals = array();
    $existing_mappings_for_save = isset( $options['mappings'] ) ? $options['mappings'] : array();
    if ( ! is_array( $existing_mappings_for_save ) ) {
        $existing_mappings_for_save = json_decode( is_string( $existing_mappings_for_save ) ? $existing_mappings_for_save : '{}', true );
        if ( ! is_array( $existing_mappings_for_save ) ) {
            $existing_mappings_for_save = array();
        }
    }

    if ( isset( $_POST['portals'] ) && is_array( $_POST['portals'] ) ) {
        $portals_raw = wp_unslash( $_POST['portals'] );

        foreach ( $portals_raw as $index => $portal ) {
            if ( ! is_array( $portal ) || empty( $portal['key'] ) || empty( $portal['name'] ) ) {
                continue;
            }
            $key           = sanitize_key( $portal['key'] );
            $allowed_types = array( 'linkedin', 'x', 'wordpress', 'meta' );
            $type          = ( isset( $portal['type'] ) && in_array( $portal['type'], $allowed_types, true ) ) ? $portal['type'] : 'wordpress';

            if ( $type === 'linkedin' ) {
                $portals[ $key ] = array(
                    'type'          => 'linkedin',
                    'name'          => sanitize_text_field( $portal['name'] ),
                    'client_id'     => sanitize_text_field( isset( $portal['client_id'] )     ? $portal['client_id']     : '' ),
                    'client_secret' => sanitize_text_field( isset( $portal['client_secret'] ) ? $portal['client_secret'] : '' ),
                    'author_urn'    => sanitize_text_field( isset( $portal['author_urn'] )    ? $portal['author_urn']    : '' ),
                );
                foreach ( array( 'access_token', 'refresh_token', 'token_expires', 'refresh_token_expires', 'person_urn', 'last_error' ) as $token_field ) {
                    if ( isset( $existing_mappings_for_save[ $key ][ $token_field ] ) ) {
                        $portals[ $key ][ $token_field ] = $existing_mappings_for_save[ $key ][ $token_field ];
                    }
                }
            } elseif ( $type === 'x' ) {
                $portals[ $key ] = array(
                    'type' => 'x',
                    'name' => sanitize_text_field( $portal['name'] ),
                );
                foreach ( array( 'access_token', 'refresh_token', 'token_expires', 'refresh_token_expires', 'x_user_id', 'x_username', 'last_error' ) as $token_field ) {
                    if ( isset( $existing_mappings_for_save[ $key ][ $token_field ] ) ) {
                        $portals[ $key ][ $token_field ] = $existing_mappings_for_save[ $key ][ $token_field ];
                    }
                }
            } elseif ( $type === 'meta' ) {
                $portals[ $key ] = array(
                    'type'              => 'meta',
                    'name'              => sanitize_text_field( $portal['name'] ),
                    'post_to_facebook'  => ! empty( $portal['post_to_facebook'] ),
                    'post_to_instagram' => ! empty( $portal['post_to_instagram'] ),
                );
                foreach ( array( 'access_token', 'page_id', 'page_name', 'instagram_account_id', 'meta_auth_mode', 'pending_pages', 'last_error' ) as $pf ) {
                    if ( isset( $existing_mappings_for_save[ $key ][ $pf ] ) ) {
                        $portals[ $key ][ $pf ] = $existing_mappings_for_save[ $key ][ $pf ];
                    }
                }
            } else {
                if ( empty( $portal['url'] ) ) {
                    continue;
                }
                $portals[ $key ] = array(
                    'type'     => 'wordpress',
                    'name'     => sanitize_text_field( $portal['name'] ),
                    'url'      => esc_url_raw( $portal['url'] ),
                    'user'     => sanitize_text_field( isset( $portal['user'] )     ? $portal['user']     : '' ),
                    'password' => sanitize_text_field( isset( $portal['password'] ) ? $portal['password'] : '' ),
                );
                // Preserve button-connected credentials when manual fields are empty (hidden).
                foreach ( array( 'user', 'password', 'wp_auth_mode', 'wp_site_url', 'last_error' ) as $pf ) {
                    if ( empty( $portals[ $key ][ $pf ] ) && isset( $existing_mappings_for_save[ $key ][ $pf ] ) ) {
                        $portals[ $key ][ $pf ] = $existing_mappings_for_save[ $key ][ $pf ];
                    }
                }
            }
        }
    }

    $options['mappings'] = wp_json_encode( $portals );
    update_option( 'post_forwarding_options', $options );

    $settings_url = admin_url( 'admin.php?page=post-forwarder-settings' );

    // Save & Connect — LinkedIn.
    if ( ! empty( $_POST['pending_linkedin_connect'] ) ) {
        $connect_key  = sanitize_key( wp_unslash( $_POST['pending_linkedin_connect'] ) );
        $relay_target = post_forwarder_relay_url();
        if ( $relay_target && isset( $portals[ $connect_key ] ) && 'linkedin' === $portals[ $connect_key ]['type'] ) {
            wp_redirect( $relay_target . '/start?' . http_build_query( array(
                'return_url' => $settings_url,
                'portal_key' => $connect_key,
                'wp_nonce'   => wp_create_nonce( 'linkedin_oauth_' . $connect_key ),
            ) ) );
            exit;
        }
    }

    // Save & Connect — X.
    if ( ! empty( $_POST['pending_x_connect'] ) ) {
        $x_connect_key  = sanitize_key( wp_unslash( $_POST['pending_x_connect'] ) );
        $x_relay_target = post_forwarder_relay_url();
        if ( $x_relay_target && isset( $portals[ $x_connect_key ] ) && 'x' === $portals[ $x_connect_key ]['type'] ) {
            wp_redirect( $x_relay_target . '/x/start?' . http_build_query( array(
                'return_url' => $settings_url,
                'portal_key' => $x_connect_key,
                'wp_nonce'   => wp_create_nonce( 'x_oauth_' . $x_connect_key ),
            ) ) );
            exit;
        }
    }

    // Save & Connect — WordPress Application Password authorization.
    if ( ! empty( $_POST['pending_wp_connect'] ) ) {
        $wp_connect_key = sanitize_key( wp_unslash( $_POST['pending_wp_connect'] ) );
        if ( isset( $portals[ $wp_connect_key ] ) && 'wordpress' === $portals[ $wp_connect_key ]['type'] ) {
            $target_url = isset( $portals[ $wp_connect_key ]['url'] ) ? $portals[ $wp_connect_key ]['url'] : '';
            if ( $target_url ) {
                $preflight    = wp_remote_get(
                    rtrim( $target_url, '/' ) . '/wp-json/',
                    array( 'timeout' => 10, 'redirection' => 5 )
                );
                $pf_data      = ! is_wp_error( $preflight ) ? json_decode( wp_remote_retrieve_body( $preflight ), true ) : null;
                $preflight_ok = ! is_wp_error( $preflight )
                    && 200 === wp_remote_retrieve_response_code( $preflight )
                    && is_array( $pf_data )
                    && isset( $pf_data['namespaces'] );

                if ( ! $preflight_ok ) {
                    wp_redirect( $settings_url . '&wp_connect_error=preflight_failed&portal_key=' . urlencode( $wp_connect_key ) );
                    exit;
                }

                // Use the site URL from the API response — this is where wp-admin actually lives
                // and may differ from the entered URL when WordPress is installed in a subdirectory.
                $wp_site_url = ! empty( $pf_data['url'] ) ? rtrim( $pf_data['url'], '/' ) : rtrim( $target_url, '/' );

                $nonce       = wp_create_nonce( 'wp_auth_' . $wp_connect_key );
                $success_url = add_query_arg( array(
                    'wordpress_auth_callback' => '1',
                    'portal_key'              => $wp_connect_key,
                    'wp_nonce'                => $nonce,
                ), admin_url( 'admin.php?page=post-forwarder-settings' ) );
                $reject_url  = add_query_arg( array(
                    'wordpress_auth_rejected' => '1',
                    'portal_key'              => $wp_connect_key,
                ), admin_url( 'admin.php?page=post-forwarder-settings' ) );

                wp_redirect( $wp_site_url . '/wp-admin/authorize-application.php?' . http_build_query( array(
                    'app_name'    => 'Post Forwarder',
                    'success_url' => $success_url,
                    'reject_url'  => $reject_url,
                ) ) );
                exit;
            }
        }
    }

    // Save & Connect — Meta (Facebook + Instagram) via relay.
    if ( ! empty( $_POST['pending_meta_connect'] ) ) {
        $meta_connect_key = sanitize_key( wp_unslash( $_POST['pending_meta_connect'] ) );
        $meta_relay       = post_forwarder_relay_url();
        if ( $meta_relay && isset( $portals[ $meta_connect_key ] ) && 'meta' === $portals[ $meta_connect_key ]['type'] ) {
            wp_redirect( $meta_relay . '/meta/start?' . http_build_query( array(
                'return_url' => $settings_url,
                'portal_key' => $meta_connect_key,
                'wp_nonce'   => wp_create_nonce( 'meta_oauth_' . $meta_connect_key ),
            ) ) );
            exit;
        }
    }

    // Normal save — redirect back with success flag (PRG).
    wp_redirect( $settings_url . '&portals_saved=1' );
    exit;
});

// Sanitize options
function post_forwarding_sanitize_options($input) {
    // Start from existing values so that fields not present in this form (e.g. mappings,
    // managed by the portals form) are never wiped by saving the global settings form.
    $existing  = get_option( 'post_forwarding_options', array() );
    $sanitized = is_array( $existing ) ? $existing : array();

    // Checkbox: must explicitly set false when not in POST (unchecked checkboxes are omitted).
    $sanitized['enabled'] = ! empty( $input['enabled'] );

    $sanitized['post_status'] = ( isset( $input['post_status'] ) && $input['post_status'] === 'publish' )
        ? 'publish'
        : 'draft';

    if ( isset( $input['relay_url'] ) ) {
        $sanitized['relay_url'] = esc_url_raw( trim( $input['relay_url'] ) );
    }

    // Preserve mappings from input when provided — OAuth callbacks and the portals form pass this.
    // When the global settings form submits (no mappings key), leave existing mappings untouched.
    // When the JSON textarea is submitted, '***' placeholders must not overwrite real stored values.
    if ( isset( $input['mappings'] ) && $input['mappings'] !== '' ) {
        $new_mappings = json_decode( $input['mappings'], true );
        if ( is_array( $new_mappings ) ) {
            $existing_mappings = isset( $sanitized['mappings'] ) ? $sanitized['mappings'] : array();
            if ( ! is_array( $existing_mappings ) ) {
                $existing_mappings = json_decode( is_string( $existing_mappings ) ? $existing_mappings : '{}', true );
                if ( ! is_array( $existing_mappings ) ) {
                    $existing_mappings = array();
                }
            }
            $sensitive_keys = array( 'access_token', 'refresh_token', 'password', 'client_secret' );
            foreach ( $new_mappings as $mkey => &$mapping ) {
                foreach ( $sensitive_keys as $sk ) {
                    if ( isset( $mapping[ $sk ] ) && '***' === $mapping[ $sk ] && isset( $existing_mappings[ $mkey ][ $sk ] ) ) {
                        $mapping[ $sk ] = $existing_mappings[ $mkey ][ $sk ];
                    }
                }
            }
            unset( $mapping );
            $sanitized['mappings'] = wp_json_encode( $new_mappings );
        } else {
            $sanitized['mappings'] = $input['mappings'];
        }
    }

    return $sanitized;
}

// REST endpoint: return (and consume) forwarding results for a post.
add_action( 'rest_api_init', function () {
    register_rest_route( 'post-forwarder/v1', '/results/(?P<post_id>\d+)', array(
        'methods'             => 'GET',
        'callback'            => function ( WP_REST_Request $request ) {
            $post_id = (int) $request->get_param( 'post_id' );
            $key     = 'pf_forward_results_' . get_current_user_id() . '_' . $post_id;
            $results = get_transient( $key );
            if ( ! $results || ! is_array( $results ) ) {
                return rest_ensure_response( array( 'results' => null ) );
            }
            delete_transient( $key );
            return rest_ensure_response( array( 'results' => array_values( $results ) ) );
        },
        'permission_callback' => function ( WP_REST_Request $request ) {
            return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
        },
    ) );
} );

// Calendar schedule REST endpoints.
add_action( 'rest_api_init', function () {
    $perm = function () { return current_user_can( 'manage_options' ); };

    // GET /schedule?week_start=YYYY-MM-DD
    register_rest_route( 'post-forwarder/v1', '/schedule', array(
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            $table = $wpdb->prefix . 'pf_schedule';
            $week  = sanitize_text_field( $req->get_param( 'week_start' ) ?: date( 'Y-m-d', strtotime( 'monday this week' ) ) );
            $start = date( 'Y-m-d 00:00:00', strtotime( $week ) );
            $end   = date( 'Y-m-d 23:59:59', strtotime( $week . ' +6 days' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE scheduled_at BETWEEN %s AND %s ORDER BY scheduled_at ASC",
                $start, $end
            ), ARRAY_A );
            foreach ( $rows as &$r ) {
                $r['channel_keys'] = json_decode( $r['channel_keys'], true );
                $r['result']       = json_decode( $r['result'], true );
                if ( $r['post_id'] ) {
                    $p = get_post( (int) $r['post_id'] );
                    $r['post_title'] = $p ? $p->post_title : '';
                    $r['post_url']   = $p ? get_permalink( $p ) : '';
                }
            }
            unset( $r );
            return rest_ensure_response( $rows );
        },
    ) );

    // POST /schedule — create
    register_rest_route( 'post-forwarder/v1', '/schedule', array(
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            $table        = $wpdb->prefix . 'pf_schedule';
            $post_id      = (int) ( $req->get_param( 'post_id' ) ?: 0 );
            $title        = sanitize_text_field( $req->get_param( 'title' ) ?: '' );
            $content      = wp_kses_post( $req->get_param( 'content' ) ?: '' );
            $excerpt      = sanitize_text_field( $req->get_param( 'excerpt' ) ?: '' );
            $image_url    = esc_url_raw( $req->get_param( 'image_url' ) ?: '' );
            $channel_keys = $req->get_param( 'channel_keys' ) ?: array();
            $scheduled_at = sanitize_text_field( $req->get_param( 'scheduled_at' ) ?: '' );

            if ( ! $scheduled_at ) {
                return new WP_Error( 'missing_date', 'scheduled_at is required', array( 'status' => 400 ) );
            }
            if ( empty( $channel_keys ) ) {
                return new WP_Error( 'missing_channels', 'At least one channel is required', array( 'status' => 400 ) );
            }

            // If linking an existing post, fill title/excerpt from it.
            if ( $post_id ) {
                $p = get_post( $post_id );
                if ( $p ) {
                    $title   = $title ?: $p->post_title;
                    $excerpt = $excerpt ?: $p->post_excerpt;
                    if ( ! $image_url ) {
                        $tid = get_post_thumbnail_id( $post_id );
                        if ( $tid ) {
                            $src       = wp_get_attachment_image_src( $tid, 'medium' );
                            $image_url = $src ? $src[0] : '';
                        }
                    }
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table, array(
                'post_id'      => $post_id ?: null,
                'title'        => $title,
                'content'      => $content,
                'excerpt'      => $excerpt,
                'image_url'    => $image_url,
                'channel_keys' => wp_json_encode( array_values( (array) $channel_keys ) ),
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', strtotime( $scheduled_at ) ),
                'status'       => 'pending',
                'result'       => '{}',
                'created_at'   => current_time( 'mysql' ),
            ) );

            $id  = $wpdb->insert_id;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
            $row['channel_keys'] = json_decode( $row['channel_keys'], true );
            $row['result']       = json_decode( $row['result'], true );
            return rest_ensure_response( $row );
        },
    ) );

    // PUT /schedule/{id} — update
    register_rest_route( 'post-forwarder/v1', '/schedule/(?P<id>\d+)', array(
        'methods'             => 'PUT',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            $table = $wpdb->prefix . 'pf_schedule';
            $id    = (int) $req->get_param( 'id' );
            $data  = array();

            if ( null !== $req->get_param( 'scheduled_at' ) ) {
                $data['scheduled_at'] = gmdate( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $req->get_param( 'scheduled_at' ) ) ) );
            }
            if ( null !== $req->get_param( 'channel_keys' ) ) {
                $data['channel_keys'] = wp_json_encode( array_values( (array) $req->get_param( 'channel_keys' ) ) );
            }
            if ( null !== $req->get_param( 'title' ) ) {
                $data['title'] = sanitize_text_field( $req->get_param( 'title' ) );
            }
            if ( null !== $req->get_param( 'content' ) ) {
                $data['content'] = wp_kses_post( $req->get_param( 'content' ) );
            }

            if ( $data ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update( $table, $data, array( 'id' => $id ) );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
            if ( ! $row ) {
                return new WP_Error( 'not_found', 'Schedule item not found', array( 'status' => 404 ) );
            }
            $row['channel_keys'] = json_decode( $row['channel_keys'], true );
            $row['result']       = json_decode( $row['result'], true );
            return rest_ensure_response( $row );
        },
    ) );

    // DELETE /schedule/{id}
    register_rest_route( 'post-forwarder/v1', '/schedule/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            $table = $wpdb->prefix . 'pf_schedule';
            $id    = (int) $req->get_param( 'id' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $table, array( 'id' => $id ) );
            return rest_ensure_response( array( 'deleted' => true ) );
        },
    ) );

    // GET /posts?s=search — search WP posts for the picker
    register_rest_route( 'post-forwarder/v1', '/posts', array(
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            $search = sanitize_text_field( $req->get_param( 's' ) ?: '' );
            $posts  = get_posts( array(
                'post_status'    => array( 'publish', 'draft', 'future' ),
                'posts_per_page' => 20,
                's'              => $search,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ) );
            $out = array();
            foreach ( $posts as $p ) {
                $img  = '';
                $tid  = get_post_thumbnail_id( $p->ID );
                if ( $tid ) {
                    $src = wp_get_attachment_image_src( $tid, 'thumbnail' );
                    $img = $src ? $src[0] : '';
                }
                $out[] = array(
                    'id'         => $p->ID,
                    'title'      => $p->post_title,
                    'status'     => $p->post_status,
                    'date'       => $p->post_date,
                    'excerpt'    => wp_trim_words( $p->post_excerpt ?: $p->post_content, 20 ),
                    'image_url'  => $img,
                    'permalink'  => get_permalink( $p->ID ),
                );
            }
            return rest_ensure_response( $out );
        },
    ) );
} );

// Enqueue Gutenberg save-listener on post edit screens.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
        return;
    }
    $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
    if ( ! $post_id && isset( $_POST['post_ID'] ) ) {
        $post_id = (int) $_POST['post_ID'];
    }
    $results_url = rest_url( 'post-forwarder/v1/results/' . $post_id );
    $rest_nonce  = wp_create_nonce( 'wp_rest' );
    wp_add_inline_script( 'wp-data', '
(function() {
    if (typeof wp === "undefined" || !wp.data || !wp.data.subscribe) { return; }
    var restBase = ' . wp_json_encode( rest_url( 'post-forwarder/v1/results/' ) ) . ';
    var nonce    = ' . wp_json_encode( $rest_nonce ) . ';
    var wasSaving = false;
    wp.data.subscribe(function() {
        var editor = wp.data.select("core/editor");
        if (!editor) { return; }
        var nowSaving = editor.isSavingPost();
        if (!nowSaving && wasSaving) {
            wasSaving = false;
            var post   = editor.getCurrentPost();
            var postId = post && post.id ? post.id : 0;
            if (!postId) { return; }
            setTimeout(function() {
                fetch(restBase + postId, { headers: { "X-WP-Nonce": nonce } })
                    .then(function(r){ return r.json(); })
                    .then(function(data) {
                        if (!data || !data.results || !data.results.length) { return; }
                        var notices = wp.data.dispatch("core/notices");
                        if (!notices) { return; }
                        data.results.forEach(function(r, i) {
                            var icon  = r.success ? "✓" : "✗";
                            var badge = r.type === "linkedin" ? " [LI]" : r.type === "x" ? " [X]" : " [WP]";
                            notices.createNotice(
                                r.success ? "success" : "error",
                                "Post Forwarder" + badge + " " + icon + " " + r.name + ": " + r.message,
                                { id: "pf-result-" + i, isDismissible: true, type: "snackbar" }
                            );
                        });
                    }).catch(function(){});
            }, 800);
        } else if (nowSaving) {
            wasSaving = true;
        }
    });
})();
', 'after' );
} );

// Add meta box to post editor
add_action('add_meta_boxes', function() {
    $post_types = get_post_types(array('public' => true), 'names');
    foreach ($post_types as $post_type) {
        add_meta_box(
            'post_forwarding_meta_box',
            __('Post Forwarder', 'post-forwarder'),
            'post_forwarding_meta_box_callback',
            $post_type,
            'side',
            'default'
        );
    }
});

// Meta box callback function
function post_forwarding_meta_box_callback($post) {
    wp_nonce_field('post_forwarding_meta_box', 'post_forwarding_meta_box_nonce');
    
    $options = get_option('post_forwarding_options', array());
    $mappings_json = isset($options['mappings']) ? $options['mappings'] : '{}';
    $mappings = json_decode($mappings_json, true);
    $selected_products = get_post_meta($post->ID, 'product', false);
    
    echo '<div style="margin-bottom: 10px;">';
    echo '<strong>' . esc_html__('Select destinations to forward to:', 'post-forwarder') . '</strong>';
    echo '</div>';
    
    if (!empty($mappings) && is_array($mappings)) {
        foreach ($mappings as $product_key => $mapping) {
            $account_type = isset($mapping['type']) ? $mapping['type'] : 'wordpress';
            $portal_name  = isset($mapping['name']) ? $mapping['name'] : $product_key;

            if ($account_type === 'linkedin') {
                $is_connected = !empty($mapping['access_token'])
                    && (!isset($mapping['token_expires']) || $mapping['token_expires'] > time());
                $badge        = '<span style="background:#0a66c2;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:5px;">LI</span>';
                $display_name = esc_html($portal_name) . $badge;
                if (!$is_connected) {
                    $display_name .= ' <span style="color:#cc0000;font-size:11px;">' . esc_html__('(not connected)', 'post-forwarder') . '</span>';
                }
            } elseif ($account_type === 'x') {
                $is_connected = !empty($mapping['access_token'])
                    && (!isset($mapping['token_expires']) || $mapping['token_expires'] > time());
                $badge        = '<span style="background:#000;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:5px;">X</span>';
                $display_name = esc_html($portal_name) . $badge;
                if (!$is_connected) {
                    $display_name .= ' <span style="color:#cc0000;font-size:11px;">' . esc_html__('(not connected)', 'post-forwarder') . '</span>';
                }
            } elseif ( $account_type === 'meta' ) {
                $is_connected = ! empty( $mapping['access_token'] ) && ! empty( $mapping['page_id'] );
                $badge        = '<span style="background:#1877f2;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:5px;">META</span>';
                $display_name = esc_html( $portal_name ) . $badge;
                if ( ! $is_connected ) {
                    $display_name .= ' <span style="color:#cc0000;font-size:11px;">' . esc_html__( '(not connected)', 'post-forwarder' ) . '</span>';
                }
            } else {
                $wp_connected = ! empty( $mapping['user'] ) && ! empty( $mapping['password'] );
                $badge        = '<span style="background:#3858e9;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:5px;">WP</span>';
                $display_name = esc_html( $portal_name ) . $badge;
                if ( ! $wp_connected ) {
                    $display_name .= ' <span style="color:#cc0000;font-size:11px;">' . esc_html__( '(not connected)', 'post-forwarder' ) . '</span>';
                }
                $is_connected = $wp_connected;
            }

            $is_selected = in_array($product_key, $selected_products);

            echo '<div style="margin-bottom: 8px;">';
            echo '<label style="display: flex; align-items: center;">';
            echo '<input type="checkbox" name="post_forwarding_product[]" value="' . esc_attr($product_key) . '"' . ($is_selected ? ' checked' : '') . ' style="margin-right: 8px;">';
            echo '<span>' . wp_kses($display_name, array('span' => array('style' => array()))) . '</span>';
            echo '</label>';
            echo '</div>';
        }
    } else {
        echo '<p style="color: #666; font-style: italic;">' . esc_html__('No portals configured', 'post-forwarder') . '</p>';
    }
    
    // Show forwarding results from the last save (set by post_forward_post via save_post).
    $pf_results_key = 'pf_forward_results_' . get_current_user_id() . '_' . $post->ID;
    $pf_results     = get_transient( $pf_results_key );
    if ( $pf_results && is_array( $pf_results ) ) {
        delete_transient( $pf_results_key );
        $all_ok = true;
        foreach ( $pf_results as $r ) { if ( ! $r['success'] ) { $all_ok = false; break; } }
        $border = $all_ok ? '#00a32a' : '#cc0000';
        echo '<div style="margin-top:10px;border-top:1px solid #ddd;padding-top:8px;">';
        echo '<strong style="font-size:11px;">' . esc_html__( 'Forwarding results:', 'post-forwarder' ) . '</strong>';
        echo '<ul style="margin:4px 0 0 0;padding:0;list-style:none;">';
        foreach ( $pf_results as $r ) {
            $icon  = $r['success'] ? '✓' : '✗';
            $color = $r['success'] ? '#00a32a' : '#cc0000';
            $badge_colors = array( 'linkedin' => '#0a66c2', 'x' => '#000', 'wordpress' => '#3858e9', 'meta' => '#1877f2' );
            $badge_labels = array( 'linkedin' => 'LI', 'x' => 'X', 'wordpress' => 'WP', 'meta' => 'META' );
            $badge = '';
            if ( isset( $r['type'], $badge_colors[ $r['type'] ] ) ) {
                $badge = '<span style="background:' . esc_attr( $badge_colors[ $r['type'] ] ) . ';color:#fff;font-size:9px;padding:1px 4px;border-radius:2px;margin-left:3px;">' . esc_html( $badge_labels[ $r['type'] ] ) . '</span>';
            }
            echo '<li style="font-size:11px;margin:3px 0;display:flex;align-items:center;gap:4px;">';
            echo '<span style="color:' . esc_attr( $color ) . ';font-weight:700;flex-shrink:0;">' . esc_html( $icon ) . '</span>';
            echo '<span><strong>' . esc_html( $r['name'] ) . '</strong>' . $badge . ':</span>'; // phpcs:ignore
            echo '<span style="color:' . esc_attr( $color ) . ';word-break:break-all;">' . esc_html( $r['message'] ) . '</span>';
            echo '</li>';
        }
        echo '</ul></div>';
    }

    echo '<p style="font-size: 11px; color: #666; margin-top: 10px; border-top: 1px solid #ddd; padding-top: 8px;">';
    echo esc_html__('Configure portals in Settings → Post Forwarding', 'post-forwarder');
    echo '</p>';
}

// Save meta box data
add_action('save_post', function($post_id) {
    if (!isset($_POST['post_forwarding_meta_box_nonce'])) {
        return;
    }

    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['post_forwarding_meta_box_nonce'])), 'post_forwarding_meta_box')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Clear existing product meta
    delete_post_meta($post_id, 'product');

    if (isset($_POST['post_forwarding_product']) && is_array($_POST['post_forwarding_product'])) {
        $products = array_map('sanitize_text_field', wp_unslash($_POST['post_forwarding_product']));
        foreach ($products as $product) {
            if (!empty($product)) {
                add_post_meta($post_id, 'product', $product);
            }
        }
    }
}, 5, 1);

// Calendar page
function post_forwarder_calendar_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $options      = get_option( 'post_forwarding_options', array() );
    $mappings_raw = isset( $options['mappings'] ) ? $options['mappings'] : '{}';
    $mappings     = json_decode( is_string( $mappings_raw ) ? $mappings_raw : '{}', true );
    if ( ! is_array( $mappings ) ) {
        $mappings = array();
    }

    $platform_colors = array(
        'linkedin'  => array( 'bg' => '#0a66c2', 'label' => 'in' ),
        'x'         => array( 'bg' => '#000000', 'label' => 'X' ),
        'meta'      => array( 'bg' => '#1877f2', 'label' => 'f' ),
        'wordpress' => array( 'bg' => '#3858e9', 'label' => 'W' ),
    );

    $settings_url = admin_url( 'admin.php?page=post-forwarder-settings' );
    $rest_url     = rest_url( 'post-forwarder/v1/' );
    $rest_nonce   = wp_create_nonce( 'wp_rest' );

    // Pre-load current week's schedule from PHP so the grid renders immediately without a JS fetch.
    $pf_today_utc  = gmdate( 'Y-m-d' );
    $pf_week_start = $pf_today_utc;
    $pf_week_end   = gmdate( 'Y-m-d', strtotime( $pf_today_utc . ' +6 days' ) );
    global $wpdb;
    $pf_table = $wpdb->prefix . 'pf_schedule';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $pf_initial_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$pf_table} WHERE scheduled_at BETWEEN %s AND %s ORDER BY scheduled_at ASC",
        $pf_week_start . ' 00:00:00',
        $pf_week_end   . ' 23:59:59'
    ), ARRAY_A ) ?: array();
    foreach ( $pf_initial_rows as &$pf_r ) {
        $pf_r['channel_keys'] = json_decode( $pf_r['channel_keys'], true );
        $pf_r['result']       = json_decode( $pf_r['result'], true );
        if ( $pf_r['post_id'] ) {
            $pf_p = get_post( (int) $pf_r['post_id'] );
            $pf_r['post_title'] = $pf_p ? $pf_p->post_title : '';
            $pf_r['post_url']   = $pf_p ? get_permalink( $pf_p ) : '';
        }
    }
    unset( $pf_r );
    ?>
    <div class="pf-cal-root">
        <style>
        /* ── Post Forwarder Calendar — WP light theme ──────────────────── */
        .pf-cal-root { display:flex; height:calc(100vh - 32px); overflow:hidden; background:#f0f0f1; color:#1d2327; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; margin:-12px -20px 0; }

        /* Sidebar */
        .pf-sidebar { width:260px; flex-shrink:0; background:#fff; border-right:1px solid #c3c4c7; display:flex; flex-direction:column; overflow-y:auto; }
        .pf-sidebar-head { padding:16px 16px 8px; border-bottom:1px solid #f0f0f1; }
        .pf-sidebar-head h3 { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:#50575e; margin:0; }
        .pf-channel-list { list-style:none; margin:0; padding:8px 0; flex:1; }
        .pf-channel-item { padding:6px 16px 10px; border-bottom:1px solid #f6f7f7; }
        .pf-channel-item-top { display:flex; align-items:center; gap:10px; }
        .pf-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; color:#fff; flex-shrink:0; position:relative; }
        .pf-platform-badge { position:absolute; bottom:-2px; right:-2px; width:14px; height:14px; border-radius:3px; display:flex; align-items:center; justify-content:center; font-size:8px; font-weight:700; color:#fff; border:2px solid #fff; }
        .pf-channel-info { flex:1; min-width:0; }
        .pf-channel-name { font-size:13px; font-weight:600; color:#1d2327; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pf-channel-status { display:flex; align-items:center; gap:5px; margin-top:3px; }
        .pf-status-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
        .pf-status-dot.connected { background:#00a32a; }
        .pf-status-dot.disconnected { background:#d63638; }
        .pf-status-text { font-size:11px; color:#50575e; }
        .pf-reconnect-link { font-size:11px; color:#2271b1; text-decoration:none; margin-left:4px; }
        .pf-reconnect-link:hover { text-decoration:underline; }
        .pf-sidebar-actions { padding:12px 16px; border-top:1px solid #c3c4c7; display:flex; flex-direction:column; gap:8px; }
        .pf-btn-primary { background:#2271b1; color:#fff; border:none; border-radius:4px; padding:8px 16px; font-size:13px; font-weight:600; cursor:pointer; text-align:center; display:flex; align-items:center; justify-content:center; gap:6px; transition:background .15s; text-decoration:none; box-shadow:inset 0 -1px 0 rgba(0,0,0,.15); }
        .pf-btn-primary:hover { background:#135e96; color:#fff; }
        .pf-btn-secondary { background:#fff; color:#2271b1; border:1px solid #2271b1; border-radius:4px; padding:7px 16px; font-size:13px; cursor:pointer; text-align:center; transition:all .15s; text-decoration:none; }
        .pf-btn-secondary:hover { background:#f6f7f7; color:#135e96; border-color:#135e96; }
        .pf-no-channels { padding:16px; color:#50575e; font-size:13px; text-align:center; }

        /* Main */
        .pf-cal-main { flex:1; display:flex; flex-direction:column; min-width:0; background:#f0f0f1; }
        .pf-cal-nav { display:flex; align-items:center; gap:12px; padding:12px 20px; background:#fff; border-bottom:1px solid #c3c4c7; flex-shrink:0; }
        .pf-cal-nav h2 { font-size:15px; font-weight:600; margin:0; flex:1; color:#1d2327; }
        .pf-nav-btn { background:#fff; border:1px solid #c3c4c7; color:#1d2327; width:30px; height:30px; border-radius:4px; cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center; transition:all .15s; }
        .pf-nav-btn:hover { background:#f0f0f1; border-color:#8c8f94; }
        .pf-today-btn { background:#fff; border:1px solid #c3c4c7; color:#2271b1; padding:5px 12px; border-radius:4px; cursor:pointer; font-size:13px; transition:all .15s; font-weight:500; }
        .pf-today-btn:hover { background:#f0f0f1; }

        /* Grid */
        .pf-grid-wrap { flex:1; overflow:auto; }
        .pf-grid { display:grid; grid-template-columns:52px repeat(7,1fr); min-width:700px; }
        .pf-day-header { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#50575e; padding:8px 4px 6px; border-bottom:1px solid #c3c4c7; border-right:1px solid #e2e4e7; text-align:center; position:sticky; top:0; background:#fff; z-index:10; }
        .pf-day-header.today { background:#f0f6fc; }
        .pf-day-header.today .pf-day-num { background:#2271b1; color:#fff; border-radius:50%; width:26px; height:26px; display:inline-flex; align-items:center; justify-content:center; }
        .pf-day-header .pf-day-num { display:block; font-size:20px; font-weight:700; color:#1d2327; margin-top:2px; }
        .pf-day-header.today .pf-day-num { color:#fff; }
        .pf-time-col { color:#8c8f94; font-size:10px; text-align:right; padding:0 6px; height:60px; display:flex; align-items:flex-start; padding-top:4px; border-right:1px solid #c3c4c7; background:#fff; }
        .pf-time-col.spacer { height:auto; border-bottom:1px solid #c3c4c7; background:#fff; position:sticky; top:0; z-index:11; }
        .pf-slot { height:60px; border-right:1px solid #e2e4e7; border-bottom:1px solid #f0f0f1; position:relative; cursor:pointer; background:#fff; transition:background .1s; }
        .pf-slot:hover { background:#f6f7f7; }

        /* Scheduled items */
        .pf-item { position:absolute; left:3px; right:3px; border-radius:3px; padding:3px 5px; font-size:11px; cursor:pointer; overflow:hidden; z-index:5; display:flex; align-items:center; gap:4px; transition:opacity .15s; color:#fff; }
        .pf-item:hover { opacity:.85; }
        .pf-item-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:500; }
        .pf-item-badges { display:flex; gap:2px; flex-shrink:0; }
        .pf-item-badge { width:12px; height:12px; border-radius:2px; display:flex; align-items:center; justify-content:center; font-size:7px; font-weight:700; color:#fff; }
        .pf-item.status-sent { opacity:.5; }
        .pf-item.status-failed { outline:2px solid #d63638; }

        /* Now line */
        .pf-now-line { position:absolute; left:0; right:0; height:2px; background:#d63638; z-index:8; pointer-events:none; }
        .pf-now-line::before { content:''; position:absolute; left:-4px; top:-4px; width:10px; height:10px; border-radius:50%; background:#d63638; }

        /* Modal */
        .pf-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:100000; display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none; transition:opacity .2s; }
        .pf-modal-overlay.open { opacity:1; pointer-events:all; }
        .pf-modal { background:#fff; border-radius:4px; width:540px; max-width:95vw; max-height:92vh; overflow-y:auto; box-shadow:0 5px 30px rgba(0,0,0,.3); transform:translateY(8px); transition:transform .2s; }
        .pf-modal-overlay.open .pf-modal { transform:translateY(0); }
        .pf-modal-header { padding:16px 20px 14px; border-bottom:1px solid #c3c4c7; display:flex; align-items:center; justify-content:space-between; background:#f6f7f7; border-radius:4px 4px 0 0; }
        .pf-modal-header h3 { margin:0; font-size:15px; font-weight:600; color:#1d2327; }
        .pf-modal-close { background:none; border:none; color:#50575e; font-size:22px; cursor:pointer; line-height:1; padding:0; }
        .pf-modal-close:hover { color:#1d2327; }
        .pf-modal-body { padding:16px 20px; display:flex; flex-direction:column; gap:14px; }
        .pf-field label { display:block; font-size:12px; font-weight:600; color:#1d2327; margin-bottom:5px; }
        .pf-field input[type=text], .pf-field input[type=datetime-local], .pf-field textarea { width:100%; border:1px solid #8c8f94; border-radius:4px; padding:7px 10px; font-size:13px; color:#1d2327; box-sizing:border-box; outline:none; font-family:inherit; background:#fff; }
        .pf-field input:focus, .pf-field textarea:focus { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; }
        .pf-field textarea { resize:vertical; min-height:90px; }
        .pf-mode-tabs { display:flex; gap:0; border:1px solid #c3c4c7; border-radius:4px; overflow:hidden; }
        .pf-mode-tab { flex:1; padding:7px 12px; background:#f6f7f7; border:none; color:#50575e; cursor:pointer; font-size:13px; text-align:center; transition:all .15s; border-right:1px solid #c3c4c7; }
        .pf-mode-tab:last-child { border-right:none; }
        .pf-mode-tab.active { background:#2271b1; color:#fff; }
        .pf-post-list { border:1px solid #c3c4c7; border-radius:4px; max-height:200px; overflow-y:auto; margin-top:6px; }
        .pf-post-result { padding:8px 12px; cursor:pointer; border-bottom:1px solid #f0f0f1; font-size:13px; color:#1d2327; transition:background .1s; display:flex; align-items:center; gap:8px; }
        .pf-post-result:last-child { border-bottom:none; }
        .pf-post-result:hover { background:#f0f6fc; }
        .pf-post-result.selected { background:#f0f6fc; font-weight:600; }
        .pf-post-result .pf-post-status { font-size:10px; color:#646970; background:#f0f0f1; padding:1px 5px; border-radius:3px; flex-shrink:0; }
        .pf-post-result-title { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pf-channels-grid { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
        .pf-channel-check { display:flex; align-items:center; gap:8px; padding:7px 10px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; cursor:pointer; transition:all .15s; user-select:none; }
        .pf-channel-check:hover { background:#f0f6fc; border-color:#2271b1; }
        .pf-channel-check.checked { background:#f0f6fc; border-color:#2271b1; }
        .pf-channel-check input { width:auto; margin:0; }
        .pf-channel-check .pf-avatar { width:26px; height:26px; font-size:10px; }
        .pf-channel-check-name { font-size:12px; font-weight:500; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#1d2327; }
        .pf-modal-footer { padding:12px 20px 16px; border-top:1px solid #c3c4c7; display:flex; align-items:center; justify-content:space-between; gap:12px; background:#f6f7f7; }
        .pf-modal-footer .pf-btn-primary { padding:8px 20px; }
        .pf-delete-btn { background:none; border:1px solid #d63638; color:#d63638; border-radius:4px; padding:7px 14px; cursor:pointer; font-size:13px; transition:all .15s; }
        .pf-delete-btn:hover { background:#d63638; color:#fff; }
        .pf-loading { text-align:center; padding:40px; color:#50575e; }
        /* Thumbnail upload */
        .pf-thumb-wrap { display:flex; flex-direction:column; gap:6px; }
        .pf-thumb-label { display:block; border:2px dashed #c3c4c7; border-radius:4px; padding:12px; text-align:center; cursor:pointer; transition:border-color .15s; color:#50575e; font-size:13px; }
        .pf-thumb-label:hover { border-color:#2271b1; color:#2271b1; }
        .pf-thumb-clear { background:none; border:none; color:#d63638; font-size:12px; cursor:pointer; padding:0; text-align:left; }
        .pf-thumb-clear:hover { text-decoration:underline; }
        .pf-thumb-uploading { color:#2271b1; font-size:12px; }
        </style>

    <?php
    $channels_js = array();
    foreach ( $mappings as $key => $m ) {
        $type      = isset( $m['type'] ) ? $m['type'] : 'wordpress';
        $name      = isset( $m['name'] ) ? $m['name'] : $key;
        $color     = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['bg'] : '#555';
        $badge     = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['label'] : '?';
        $connected = post_forwarder_channel_connected( $m );
        $channels_js[] = array( 'key' => $key, 'name' => $name, 'type' => $type, 'color' => $color, 'badge' => $badge, 'connected' => $connected );
    }
    ?>

        <div class="pf-sidebar">
            <div class="pf-sidebar-head">
                <h3><?php esc_html_e( 'Channels', 'post-forwarder' ); ?></h3>
            </div>
            <ul class="pf-channel-list">
                <?php if ( empty( $mappings ) ) : ?>
                    <li class="pf-no-channels"><?php esc_html_e( 'No channels configured yet.', 'post-forwarder' ); ?></li>
                <?php else : ?>
                    <?php foreach ( $mappings as $key => $m ) :
                        $type      = isset( $m['type'] ) ? $m['type'] : 'wordpress';
                        $name      = isset( $m['name'] ) ? $m['name'] : $key;
                        $color     = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['bg'] : '#555';
                        $badge_lbl = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['label'] : '?';
                        $letter    = strtoupper( mb_substr( $name, 0, 1 ) );
                        $connected = post_forwarder_channel_connected( $m );
                    ?>
                    <li class="pf-channel-item">
                        <div class="pf-channel-item-top">
                            <span class="pf-avatar" style="background:<?php echo esc_attr( $color ); ?>;">
                                <?php echo esc_html( $letter ); ?>
                                <span class="pf-platform-badge" style="background:<?php echo esc_attr( $color ); ?>;filter:brightness(.7);"><?php echo esc_html( $badge_lbl ); ?></span>
                            </span>
                            <div class="pf-channel-info">
                                <div class="pf-channel-name"><?php echo esc_html( $name ); ?></div>
                                <div class="pf-channel-status">
                                    <span class="pf-status-dot <?php echo $connected ? 'connected' : 'disconnected'; ?>"></span>
                                    <span class="pf-status-text"><?php echo $connected ? esc_html__( 'Connected', 'post-forwarder' ) : esc_html__( 'Not connected', 'post-forwarder' ); ?></span>
                                    <?php if ( ! $connected ) : ?>
                                        <a class="pf-reconnect-link" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <div class="pf-sidebar-actions">
                <button class="pf-btn-primary" id="pf-new-post-btn">+ <?php esc_html_e( 'New Post', 'post-forwarder' ); ?></button>
                <a class="pf-btn-secondary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'post-forwarder' ); ?></a>
            </div>
        </div>

        <div class="pf-cal-main">
            <div class="pf-cal-nav">
                <button class="pf-nav-btn" id="pf-prev-week">&#8249;</button>
                <button class="pf-today-btn" id="pf-today-btn"><?php esc_html_e( 'Today', 'post-forwarder' ); ?></button>
                <h2 id="pf-week-label"><?php
                    $pf_months = array('Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec');
                    $pf_s_ts   = strtotime( $pf_week_start );
                    $pf_e_ts   = strtotime( $pf_week_end );
                    echo esc_html(
                        $pf_months[ (int) gmdate( 'n', $pf_s_ts ) - 1 ] . ' ' . gmdate( 'j', $pf_s_ts ) .
                        ' – ' .
                        $pf_months[ (int) gmdate( 'n', $pf_e_ts ) - 1 ] . ' ' . gmdate( 'j', $pf_e_ts ) .
                        ', ' . gmdate( 'Y', $pf_e_ts )
                    );
                ?></h2>
                <button class="pf-nav-btn" id="pf-next-week">&#8250;</button>
            </div>
            <div class="pf-grid-wrap" id="pf-grid-wrap">
                <div class="pf-loading"><?php esc_html_e( 'Loading…', 'post-forwarder' ); ?></div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="pf-modal-overlay" id="pf-modal-overlay">
        <div class="pf-modal">
            <div class="pf-modal-header">
                <h3 id="pf-modal-title"><?php esc_html_e( 'Schedule Post', 'post-forwarder' ); ?></h3>
                <button class="pf-modal-close" id="pf-modal-close">&times;</button>
            </div>
            <div class="pf-modal-body">
                <div class="pf-field">
                    <label><?php esc_html_e( 'Post source', 'post-forwarder' ); ?></label>
                    <div class="pf-mode-tabs">
                        <button class="pf-mode-tab active" data-mode="existing"><?php esc_html_e( 'Existing WP post', 'post-forwarder' ); ?></button>
                        <button class="pf-mode-tab" data-mode="new"><?php esc_html_e( 'Write new content', 'post-forwarder' ); ?></button>
                    </div>
                </div>
                <div id="pf-mode-existing">
                    <div class="pf-field">
                        <label><?php esc_html_e( 'Select post', 'post-forwarder' ); ?></label>
                        <input type="text" id="pf-post-search" placeholder="<?php esc_attr_e( 'Search by title…', 'post-forwarder' ); ?>" autocomplete="off">
                        <div class="pf-post-list" id="pf-post-results">
                            <div class="pf-post-result" style="color:#50575e;cursor:default;"><?php esc_html_e( 'Loading posts…', 'post-forwarder' ); ?></div>
                        </div>
                        <input type="hidden" id="pf-post-id">
                    </div>
                </div>
                <div id="pf-mode-new" style="display:none;">
                    <div class="pf-field">
                        <label><?php esc_html_e( 'Title', 'post-forwarder' ); ?></label>
                        <input type="text" id="pf-new-title" placeholder="<?php esc_attr_e( 'Post title', 'post-forwarder' ); ?>">
                    </div>
                    <div class="pf-field">
                        <label><?php esc_html_e( 'Content', 'post-forwarder' ); ?></label>
                        <textarea id="pf-new-content" placeholder="<?php esc_attr_e( 'Write your post content…', 'post-forwarder' ); ?>"></textarea>
                    </div>
                    <div class="pf-field">
                        <label><?php esc_html_e( 'Thumbnail', 'post-forwarder' ); ?></label>
                        <div class="pf-thumb-wrap">
                            <label class="pf-thumb-label" id="pf-thumb-label" for="pf-new-thumb-file">
                                <span id="pf-thumb-hint"><?php esc_html_e( 'Click to choose image…', 'post-forwarder' ); ?></span>
                                <img id="pf-thumb-preview" src="" alt="" style="display:none;max-width:100%;max-height:120px;border-radius:4px;margin-top:6px;">
                            </label>
                            <input type="file" id="pf-new-thumb-file" accept="image/*" style="display:none;">
                            <input type="hidden" id="pf-new-thumb-url">
                            <button type="button" class="pf-thumb-clear" id="pf-thumb-clear" style="display:none;"><?php esc_html_e( 'Remove', 'post-forwarder' ); ?></button>
                        </div>
                    </div>
                </div>
                <div class="pf-field">
                    <label><?php esc_html_e( 'Date & time', 'post-forwarder' ); ?></label>
                    <input type="datetime-local" id="pf-scheduled-at">
                </div>
                <div class="pf-field">
                    <label><?php esc_html_e( 'Channels', 'post-forwarder' ); ?></label>
                    <?php if ( empty( $mappings ) ) : ?>
                        <p style="color:#50575e;font-size:13px;margin:0;"><?php esc_html_e( 'No channels configured. Go to Settings first.', 'post-forwarder' ); ?></p>
                    <?php else : ?>
                    <div class="pf-channels-grid">
                        <?php foreach ( $mappings as $key => $m ) :
                            $type   = isset( $m['type'] ) ? $m['type'] : 'wordpress';
                            $name   = isset( $m['name'] ) ? $m['name'] : $key;
                            $color  = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['bg'] : '#555';
                            $letter = strtoupper( mb_substr( $name, 0, 1 ) );
                        ?>
                        <label class="pf-channel-check" data-key="<?php echo esc_attr( $key ); ?>">
                            <input type="checkbox" name="channels[]" value="<?php echo esc_attr( $key ); ?>">
                            <span class="pf-avatar" style="background:<?php echo esc_attr( $color ); ?>;">
                                <span style="font-size:11px;font-weight:700;"><?php echo esc_html( $letter ); ?></span>
                            </span>
                            <span class="pf-channel-check-name"><?php echo esc_html( $name ); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pf-modal-footer">
                <button class="pf-delete-btn" id="pf-delete-btn" style="display:none;"><?php esc_html_e( 'Delete', 'post-forwarder' ); ?></button>
                <button class="pf-btn-primary" id="pf-save-btn"><?php esc_html_e( 'Save', 'post-forwarder' ); ?></button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var REST_URL      = <?php echo wp_json_encode( $rest_url ); ?>;
        var REST_NONCE    = <?php echo wp_json_encode( $rest_nonce ); ?>;
        var CHANNELS      = <?php echo wp_json_encode( $channels_js ); ?>;
        var INITIAL_ITEMS = <?php echo wp_json_encode( array_values( $pf_initial_rows ) ); ?>;
        var DAY_NAMES  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var MONTHS     = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var START_HOUR = 7;
        var END_HOUR   = 22;

        var currentStart  = startOfDay(new Date()); // today, not Monday
        var editingId     = null;
        var scheduleItems = INITIAL_ITEMS;
        var searchTimer   = null;

        function startOfDay(d) { var r = new Date(d); r.setHours(0,0,0,0); return r; }
        function addDays(d, n) { var r = new Date(d); r.setDate(r.getDate() + n); return r; }
        function fmtDate(d) { return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }
        function pad(n) { return n < 10 ? '0'+n : ''+n; }
        function fmtWeekLabel(start) {
            var end = addDays(start, 6);
            return MONTHS[start.getMonth()] + ' ' + start.getDate() + ' – ' + MONTHS[end.getMonth()] + ' ' + end.getDate() + ', ' + end.getFullYear();
        }
        function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        function apiFetch(method, path, body) {
            var headers = { 'X-WP-Nonce': REST_NONCE };
            if (body) { headers['Content-Type'] = 'application/json'; }
            return fetch(REST_URL + path, {
                method: method,
                headers: headers,
                body: body ? JSON.stringify(body) : undefined,
            }).then(function(r) {
                if (!r.ok) { return Promise.reject(new Error('HTTP ' + r.status)); }
                return r.json();
            });
        }

        // ── Grid ─────────────────────────────────────────────────────────
        function renderGrid() {
            document.getElementById('pf-week-label').textContent = fmtWeekLabel(currentStart);
            var today = new Date(); today.setHours(0,0,0,0);
            var wrap = document.getElementById('pf-grid-wrap');
            var html = '<div class="pf-grid">';
            html += '<div class="pf-time-col spacer"></div>';
            for (var di = 0; di < 7; di++) {
                var day = addDays(currentStart, di);
                var isToday = day.getTime() === today.getTime();
                html += '<div class="pf-day-header' + (isToday ? ' today' : '') + '">' + DAY_NAMES[day.getDay()] + '<span class="pf-day-num">' + day.getDate() + '</span></div>';
            }
            for (var h = START_HOUR; h <= END_HOUR; h++) {
                var label = h < 12 ? h+' AM' : h === 12 ? '12 PM' : (h-12)+' PM';
                html += '<div class="pf-time-col">' + label + '</div>';
                for (var dc = 0; dc < 7; dc++) {
                    html += '<div class="pf-slot" data-datetime="' + fmtDate(addDays(currentStart, dc)) + 'T' + pad(h) + ':00"></div>';
                }
            }
            html += '</div>';
            wrap.innerHTML = html;
            placeItems();
            wrap.addEventListener('click', function(e) {
                var item = e.target.closest('.pf-item');
                if (item) { e.stopPropagation(); var rec = scheduleItems.find(function(s){return s.id==item.dataset.id;}); if(rec) openModal(rec); return; }
                var slot = e.target.closest('.pf-slot');
                if (slot) openModal(null, slot.dataset.datetime);
            });
            drawNowLine();
            setInterval(drawNowLine, 60000);
        }

        function placeItems() {
            // Group items by cell so we can split width for collisions.
            var cellMap = {};
            var slots = document.querySelectorAll('.pf-slot');
            scheduleItems.forEach(function(item) {
                var dt = new Date(item.scheduled_at.replace(' ','T') + 'Z');
                var dayIdx = Math.round((new Date(fmtDate(dt)).getTime() - currentStart.getTime()) / 86400000);
                if (dayIdx < 0 || dayIdx > 6) return;
                var h = dt.getHours();
                if (h < START_HOUR || h > END_HOUR) return;
                var key = h + '-' + dayIdx;
                if (!cellMap[key]) cellMap[key] = [];
                cellMap[key].push({ item: item, dt: dt, h: h, dayIdx: dayIdx });
            });
            Object.keys(cellMap).forEach(function(key) {
                var entries = cellMap[key];
                var total   = entries.length;
                entries.forEach(function(entry, idx) {
                    var item = entry.item, dt = entry.dt;
                    var cell = slots[(entry.h - START_HOUR) * 7 + entry.dayIdx];
                    if (!cell) return;
                    var m      = dt.getMinutes();
                    var keys   = Array.isArray(item.channel_keys) ? item.channel_keys : [];
                    var firstCh = keys.length ? CHANNELS.find(function(c){return c.key===keys[0];}) : null;
                    var color  = firstCh ? firstCh.color : '#2271b1';
                    var badges = keys.map(function(k) {
                        var ch = CHANNELS.find(function(c){return c.key===k;});
                        return ch ? '<span class="pf-item-badge" style="background:rgba(0,0,0,.25)">'+ch.badge+'</span>' : '';
                    }).join('');
                    var el = document.createElement('div');
                    el.className = 'pf-item status-' + (item.status||'pending');
                    el.dataset.id = item.id;
                    var topPct   = (m / 60) * 100;
                    var wPct     = 100 / total;
                    var leftPct  = wPct * idx;
                    var rightPct = 100 - leftPct - wPct;
                    el.style.cssText = 'top:'+topPct+'%;'
                        + 'left:calc('+leftPct+'% + 3px);'
                        + 'right:calc('+rightPct+'% + 3px);'
                        + 'background:'+color+';border-left:3px solid rgba(0,0,0,.2);';
                    el.innerHTML = '<span class="pf-item-badges">'+badges+'</span><span class="pf-item-label">'+escHtml(item.post_title||item.title||'(untitled)')+'</span>';
                    cell.appendChild(el);
                });
            });
        }

        function drawNowLine() {
            var ex = document.querySelector('.pf-now-line'); if(ex) ex.remove();
            var now = new Date();
            var todayIdx = Math.round((new Date(fmtDate(now)).getTime()-currentStart.getTime())/86400000);
            if (todayIdx<0||todayIdx>6) return;
            var h=now.getHours(), m=now.getMinutes();
            if (h<START_HOUR||h>END_HOUR) return;
            var cell = document.querySelectorAll('.pf-slot')[(h-START_HOUR)*7+todayIdx];
            if (!cell) return;
            var line = document.createElement('div');
            line.className = 'pf-now-line';
            line.style.top = ((m/60)*100)+'%';
            cell.appendChild(line);
        }

        function safeRenderGrid() {
            try { renderGrid(); } catch(e) {
                console.error('Post Forwarder renderGrid error:', e);
                var w = document.getElementById('pf-grid-wrap');
                if (w) { w.innerHTML = '<div class="pf-loading" style="color:#cc0000;">Calendar error — please refresh the page.<br><small>'+e+'</small></div>'; }
            }
        }

        function loadWeek() {
            document.getElementById('pf-grid-wrap').innerHTML = '<div class="pf-loading">Loading…</div>';
            apiFetch('GET', 'schedule?week_start='+fmtDate(currentStart)).then(function(data) {
                scheduleItems = Array.isArray(data) ? data : [];
                safeRenderGrid();
            }).catch(function(e) {
                scheduleItems = [];
                safeRenderGrid();
            });
        }

        // ── Post list ─────────────────────────────────────────────────────
        function loadPosts(q) {
            var res = document.getElementById('pf-post-results');
            res.innerHTML = '<div class="pf-post-result" style="color:#50575e;cursor:default;">Loading…</div>';
            apiFetch('GET', 'posts?s='+encodeURIComponent(q)).then(function(posts) {
                var selId = document.getElementById('pf-post-id').value;
                if (!Array.isArray(posts) || !posts.length) {
                    res.innerHTML = '<div class="pf-post-result" style="color:#50575e;cursor:default;">No posts found.</div>';
                    return;
                }
                res.innerHTML = posts.map(function(p) {
                    var isSel = String(p.id) === String(selId);
                    return '<div class="pf-post-result'+(isSel?' selected':'')+'" data-id="'+p.id+'" data-title="'+escHtml(p.title)+'">'
                        +'<span class="pf-post-result-title">'+escHtml(p.title)+'</span>'
                        +'<span class="pf-post-status">'+p.status+'</span>'
                        +'</div>';
                }).join('');
                res.querySelectorAll('.pf-post-result[data-id]').forEach(function(el) {
                    el.addEventListener('click', function() {
                        document.getElementById('pf-post-id').value = this.dataset.id;
                        res.querySelectorAll('.pf-post-result').forEach(function(r){r.classList.remove('selected');});
                        this.classList.add('selected');
                    });
                });
            });
        }

        // ── Modal ────────────────────────────────────────────────────────
        function openModal(item, prefillDatetime) {
            editingId = item ? item.id : null;
            document.getElementById('pf-modal-overlay').classList.add('open');
            document.getElementById('pf-modal-title').textContent = item ? '<?php echo esc_js( __( 'Edit Scheduled Post', 'post-forwarder' ) ); ?>' : '<?php echo esc_js( __( 'Schedule Post', 'post-forwarder' ) ); ?>';
            document.getElementById('pf-delete-btn').style.display = item ? '' : 'none';
            document.getElementById('pf-post-id').value = '';
            document.getElementById('pf-new-title').value = '';
            document.getElementById('pf-new-content').value = '';
            document.getElementById('pf-new-thumb-file').value = '';
            document.getElementById('pf-new-thumb-url').value = '';
            document.getElementById('pf-thumb-preview').style.display = 'none';
            document.getElementById('pf-thumb-preview').src = '';
            document.getElementById('pf-thumb-hint').style.display = '';
            document.getElementById('pf-thumb-clear').style.display = 'none';
            document.querySelectorAll('.pf-channel-check').forEach(function(el) {
                el.classList.remove('checked'); el.querySelector('input').checked = false;
            });

            if (item) {
                // scheduled_at is stored in UTC — convert to local for the datetime-local input.
                var dt = new Date(item.scheduled_at.replace(' ','T') + 'Z');
                document.getElementById('pf-scheduled-at').value = fmtDate(dt)+'T'+pad(dt.getHours())+':'+pad(dt.getMinutes());
                if (item.post_id) {
                    setMode('existing');
                    document.getElementById('pf-post-id').value = item.post_id;
                } else {
                    setMode('new');
                    document.getElementById('pf-new-title').value = item.title || '';
                    document.getElementById('pf-new-content').value = item.content || '';
                }
                (Array.isArray(item.channel_keys) ? item.channel_keys : []).forEach(function(k) {
                    var lbl = document.querySelector('.pf-channel-check[data-key="'+k+'"]');
                    if (lbl) { lbl.classList.add('checked'); lbl.querySelector('input').checked = true; }
                });
            } else {
                setMode('existing');
                var now2 = new Date(); now2.setMinutes(0); now2.setSeconds(0);
                document.getElementById('pf-scheduled-at').value = prefillDatetime || (fmtDate(now2)+'T'+pad(now2.getHours())+':00');
            }
            // Always load post list when in existing mode
            if (document.querySelector('.pf-mode-tab.active').dataset.mode === 'existing') {
                loadPosts(document.getElementById('pf-post-search').value || '');
            }
        }

        function closeModal() {
            document.getElementById('pf-modal-overlay').classList.remove('open');
            editingId = null;
        }

        function setMode(mode) {
            document.querySelectorAll('.pf-mode-tab').forEach(function(t){t.classList.toggle('active', t.dataset.mode===mode);});
            document.getElementById('pf-mode-existing').style.display = mode==='existing' ? '' : 'none';
            document.getElementById('pf-mode-new').style.display      = mode==='new'      ? '' : 'none';
            if (mode === 'existing') loadPosts(document.getElementById('pf-post-search').value || '');
        }

        function saveModal() {
            var mode     = document.querySelector('.pf-mode-tab.active').dataset.mode;
            var dateVal  = document.getElementById('pf-scheduled-at').value;
            var channels = Array.from(document.querySelectorAll('.pf-channel-check input:checked')).map(function(i){return i.value;});
            if (!dateVal)      { alert('<?php echo esc_js( __( 'Please set a date and time.', 'post-forwarder' ) ); ?>'); return; }
            if (!channels.length) { alert('<?php echo esc_js( __( 'Please select at least one channel.', 'post-forwarder' ) ); ?>'); return; }
            // Convert browser local time to UTC ISO string for consistent server-side storage.
            var dateUTC = new Date(dateVal).toISOString();
            var body = { scheduled_at: dateUTC, channel_keys: channels };
            if (mode === 'existing') {
                var pid = document.getElementById('pf-post-id').value;
                if (!pid) { alert('<?php echo esc_js( __( 'Please select a post from the list.', 'post-forwarder' ) ); ?>'); return; }
                body.post_id = parseInt(pid, 10);
            } else {
                body.title     = document.getElementById('pf-new-title').value;
                body.content   = document.getElementById('pf-new-content').value;
                body.image_url = document.getElementById('pf-new-thumb-url').value;
                if (!body.title && !body.content) { alert('<?php echo esc_js( __( 'Please enter a title or content.', 'post-forwarder' ) ); ?>'); return; }
            }
            var btn = document.getElementById('pf-save-btn');
            btn.textContent = '<?php echo esc_js( __( 'Saving…', 'post-forwarder' ) ); ?>';
            btn.disabled = true;
            (editingId ? apiFetch('PUT','schedule/'+editingId,body) : apiFetch('POST','schedule',body))
            .then(function() { closeModal(); loadWeek(); })
            .catch(function() { alert('<?php echo esc_js( __( 'Save failed. Please try again.', 'post-forwarder' ) ); ?>'); })
            .finally(function() { btn.textContent='<?php echo esc_js( __( 'Save', 'post-forwarder' ) ); ?>'; btn.disabled=false; });
        }

        function deleteItem() {
            if (!editingId || !confirm('<?php echo esc_js( __( 'Delete this scheduled post?', 'post-forwarder' ) ); ?>')) return;
            apiFetch('DELETE','schedule/'+editingId).then(function(){closeModal();loadWeek();});
        }

        // ── Events ───────────────────────────────────────────────────────
        document.getElementById('pf-prev-week').addEventListener('click', function(){currentStart=addDays(currentStart,-7);loadWeek();});
        document.getElementById('pf-next-week').addEventListener('click', function(){currentStart=addDays(currentStart,7);loadWeek();});
        document.getElementById('pf-today-btn').addEventListener('click', function(){currentStart=startOfDay(new Date());loadWeek();});
        document.getElementById('pf-new-post-btn').addEventListener('click', function(){openModal(null);});
        document.getElementById('pf-modal-close').addEventListener('click', closeModal);
        document.getElementById('pf-modal-overlay').addEventListener('click', function(e){if(e.target===this)closeModal();});
        document.getElementById('pf-save-btn').addEventListener('click', saveModal);
        document.getElementById('pf-delete-btn').addEventListener('click', deleteItem);
        document.querySelectorAll('.pf-mode-tab').forEach(function(tab){tab.addEventListener('click',function(){setMode(this.dataset.mode);});});
        document.querySelectorAll('.pf-channel-check').forEach(function(lbl){
            lbl.addEventListener('click', function(){var cb=this.querySelector('input');cb.checked=!cb.checked;this.classList.toggle('checked',cb.checked);});
        });
        document.getElementById('pf-post-search').addEventListener('input', function(){
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function(){loadPosts(document.getElementById('pf-post-search').value);}, 300);
        });

        // Thumbnail upload for new-content mode.
        document.getElementById('pf-thumb-label').addEventListener('click', function(){
            document.getElementById('pf-new-thumb-file').click();
        });
        document.getElementById('pf-new-thumb-file').addEventListener('change', function(){
            var file = this.files[0];
            if (!file) return;
            var hint = document.getElementById('pf-thumb-hint');
            hint.textContent = '<?php echo esc_js( __( 'Uploading…', 'post-forwarder' ) ); ?>';
            hint.className = 'pf-thumb-uploading';
            var fd = new FormData();
            fd.append('file', file);
            fd.append('title', file.name);
            fetch('<?php echo esc_js( rest_url( 'wp/v2/media' ) ); ?>', {
                method: 'POST',
                headers: { 'X-WP-Nonce': REST_NONCE },
                body: fd,
            }).then(function(r){ return r.json(); }).then(function(media){
                if (media.source_url) {
                    document.getElementById('pf-new-thumb-url').value = media.source_url;
                    var img = document.getElementById('pf-thumb-preview');
                    img.src = media.source_url;
                    img.style.display = 'block';
                    hint.textContent = file.name;
                    hint.className = '';
                    document.getElementById('pf-thumb-clear').style.display = '';
                } else {
                    hint.textContent = '<?php echo esc_js( __( 'Upload failed. Try again.', 'post-forwarder' ) ); ?>';
                    hint.className = '';
                }
            }).catch(function(){
                hint.textContent = '<?php echo esc_js( __( 'Upload failed. Try again.', 'post-forwarder' ) ); ?>';
                hint.className = '';
            });
        });
        document.getElementById('pf-thumb-clear').addEventListener('click', function(){
            document.getElementById('pf-new-thumb-file').value = '';
            document.getElementById('pf-new-thumb-url').value = '';
            document.getElementById('pf-thumb-preview').style.display = 'none';
            document.getElementById('pf-thumb-preview').src = '';
            document.getElementById('pf-thumb-hint').textContent = '<?php echo esc_js( __( 'Click to choose image…', 'post-forwarder' ) ); ?>';
            document.getElementById('pf-thumb-hint').className = '';
            this.style.display = 'none';
        });

        document.addEventListener('keydown', function(e){if(e.key==='Escape')closeModal();});

        // Fire after all WP admin footer scripts have executed, so nothing can reset our DOM.
        function initCalendar() { loadWeek(); }
        if (document.readyState === 'complete') {
            initCalendar();
        } else {
            window.addEventListener('load', initCalendar);
        }
    })();
    </script>
    <?php
}

// Settings page HTML
function post_forwarding_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'post-forwarder'));
    }
    
    $options = get_option( 'post_forwarding_options', array() );

    if ( is_string( $options ) ) {
        $options = json_decode( $options, true );
        if ( ! is_array( $options ) ) {
            $options = array();
        }
    }

    // Handle LinkedIn relay callback (relay-mode OAuth via Cloudflare Worker).
    $linkedin_oauth_notice = '';
    if ( isset( $_GET['linkedin_relay_callback'] ) ) {
        $relay_url       = post_forwarder_relay_url();
        $relay_token_key = isset( $_GET['relay_token_key'] ) ? sanitize_text_field( wp_unslash( $_GET['relay_token_key'] ) ) : '';
        $relay_error     = isset( $_GET['relay_error'] )     ? sanitize_text_field( wp_unslash( $_GET['relay_error'] ) )     : '';

        if ( $relay_error ) {
            /* translators: %s: error description returned by the relay worker */
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html( sprintf( __( 'LinkedIn connection failed: %s', 'post-forwarder' ), $relay_error ) )
                . '</p></div>';
        } elseif ( ! $relay_url ) {
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'LinkedIn connection failed: Relay URL is not configured (POST_FORWARDER_RELAY_URL constant missing).', 'post-forwarder' )
                . '</p></div>';
        } elseif ( ! $relay_token_key ) {
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'LinkedIn connection failed: relay_token_key missing from relay response.', 'post-forwarder' )
                . '</p></div>';
        } else {
            // Fetch the token payload from the relay (one-time, deleted on retrieval).
            $relay_response = wp_remote_post(
                $relay_url . '/token',
                array(
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode( array( 'key' => $relay_token_key ) ),
                    'timeout' => 15,
                )
            );

            if ( is_wp_error( $relay_response ) ) {
                $error_msg = $relay_response->get_error_message();
                post_forwarder_log_error( 'Relay /token request failed: ' . $error_msg );
                /* translators: %s: error message */
                $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                    . esc_html( sprintf( __( 'LinkedIn connection failed: %s', 'post-forwarder' ), $error_msg ) )
                    . '</p></div>';
            } else {
                $relay_http_code = wp_remote_retrieve_response_code( $relay_response );
                $payload         = json_decode( wp_remote_retrieve_body( $relay_response ), true );

                if ( 200 !== $relay_http_code || ! isset( $payload['access_token'] ) ) {
                    $err_msg = is_array( $payload ) && isset( $payload['error'] ) ? $payload['error'] : 'HTTP ' . $relay_http_code;
                    post_forwarder_log_error( 'Relay /token returned unexpected response: ' . $err_msg );
                    /* translators: %s: error detail */
                    $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                        . esc_html( sprintf( __( 'LinkedIn connection failed: Relay returned an error (%s).', 'post-forwarder' ), $err_msg ) )
                        . '</p></div>';
                } else {
                    // Verify the WordPress nonce that was embedded in the relay payload.
                    $portal_key = isset( $payload['portal_key'] ) ? sanitize_key( $payload['portal_key'] ) : '';
                    $wp_nonce   = isset( $payload['wp_nonce'] )   ? $payload['wp_nonce'] : '';

                    if ( ! $portal_key || ! wp_verify_nonce( $wp_nonce, 'linkedin_oauth_' . $portal_key ) ) {
                        $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                            . esc_html__( 'LinkedIn connection failed: Security check failed.', 'post-forwarder' )
                            . '</p></div>';
                    } else {
                        $relay_mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
                        if ( ! is_array( $relay_mappings ) ) {
                            $relay_mappings = json_decode( is_string( $relay_mappings ) ? $relay_mappings : '{}', true );
                            if ( ! is_array( $relay_mappings ) ) {
                                $relay_mappings = array();
                            }
                        }

                        if ( ! isset( $relay_mappings[ $portal_key ]['type'] ) || 'linkedin' !== $relay_mappings[ $portal_key ]['type'] ) {
                            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                                . esc_html__( 'LinkedIn connection failed: Portal configuration not found.', 'post-forwarder' )
                                . '</p></div>';
                        } else {
                            unset( $relay_mappings[ $portal_key ]['last_error'] );
                            $relay_mappings[ $portal_key ]['access_token']  = $payload['access_token'];
                            $relay_mappings[ $portal_key ]['token_expires'] = time() + (int) ( $payload['expires_in'] ?? 3600 );

                            if ( ! empty( $payload['refresh_token'] ) ) {
                                $relay_mappings[ $portal_key ]['refresh_token']         = $payload['refresh_token'];
                                $relay_mappings[ $portal_key ]['refresh_token_expires'] = time() + (int) ( $payload['refresh_token_expires_in'] ?? 2592000 );
                            }

                            if ( ! empty( $payload['person_urn'] ) ) {
                                $relay_mappings[ $portal_key ]['person_urn'] = $payload['person_urn'];
                                if ( empty( $relay_mappings[ $portal_key ]['author_urn'] ) ) {
                                    $relay_mappings[ $portal_key ]['author_urn'] = $payload['person_urn'];
                                }
                            }

                            $options['mappings'] = wp_json_encode( $relay_mappings );
                            update_option( 'post_forwarding_options', $options );
                            $options = get_option( 'post_forwarding_options', array() );
                            if ( is_string( $options ) ) {
                                $options = json_decode( $options, true );
                                if ( ! is_array( $options ) ) {
                                    $options = array();
                                }
                            }
                            $linkedin_oauth_notice = '<div class="notice notice-success is-dismissible"><p>'
                                . esc_html__( 'LinkedIn account connected successfully!', 'post-forwarder' )
                                . '</p></div>';
                        }
                    }
                }
            }
        }
    }

    // Handle X (Twitter) relay callback.
    $x_oauth_notice = '';
    if ( isset( $_GET['x_relay_callback'] ) ) {
        $x_relay_url       = post_forwarder_relay_url();
        $x_relay_token_key = isset( $_GET['relay_token_key'] ) ? sanitize_text_field( wp_unslash( $_GET['relay_token_key'] ) ) : '';
        $x_relay_error     = isset( $_GET['relay_error'] )     ? sanitize_text_field( wp_unslash( $_GET['relay_error'] ) )     : '';

        if ( $x_relay_error ) {
            /* translators: %s: error description returned by the relay worker */
            $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html( sprintf( __( 'X connection failed: %s', 'post-forwarder' ), $x_relay_error ) )
                . '</p></div>';
        } elseif ( ! $x_relay_url ) {
            $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'X connection failed: Relay URL is not configured (POST_FORWARDER_RELAY_URL constant missing).', 'post-forwarder' )
                . '</p></div>';
        } elseif ( ! $x_relay_token_key ) {
            $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'X connection failed: relay_token_key missing from relay response.', 'post-forwarder' )
                . '</p></div>';
        } else {
            $x_relay_response = wp_remote_post(
                $x_relay_url . '/token',
                array(
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode( array( 'key' => $x_relay_token_key ) ),
                    'timeout' => 15,
                )
            );

            if ( is_wp_error( $x_relay_response ) ) {
                $x_error_msg = $x_relay_response->get_error_message();
                post_forwarder_log_error( 'X Relay /token request failed: ' . $x_error_msg );
                /* translators: %s: error message */
                $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                    . esc_html( sprintf( __( 'X connection failed: %s', 'post-forwarder' ), $x_error_msg ) )
                    . '</p></div>';
            } else {
                $x_relay_http_code = wp_remote_retrieve_response_code( $x_relay_response );
                $x_payload         = json_decode( wp_remote_retrieve_body( $x_relay_response ), true );

                if ( 200 !== $x_relay_http_code || ! isset( $x_payload['access_token'] ) ) {
                    $x_err_msg = is_array( $x_payload ) && isset( $x_payload['error'] ) ? $x_payload['error'] : 'HTTP ' . $x_relay_http_code;
                    post_forwarder_log_error( 'X Relay /token returned unexpected response: ' . $x_err_msg );
                    /* translators: %s: error detail */
                    $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                        . esc_html( sprintf( __( 'X connection failed: Relay returned an error (%s).', 'post-forwarder' ), $x_err_msg ) )
                        . '</p></div>';
                } else {
                    $x_portal_key = isset( $x_payload['portal_key'] ) ? sanitize_key( $x_payload['portal_key'] ) : '';
                    $x_wp_nonce   = isset( $x_payload['wp_nonce'] )   ? $x_payload['wp_nonce'] : '';

                    if ( ! $x_portal_key || ! wp_verify_nonce( $x_wp_nonce, 'x_oauth_' . $x_portal_key ) ) {
                        $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                            . esc_html__( 'X connection failed: Security check failed.', 'post-forwarder' )
                            . '</p></div>';
                    } else {
                        $x_relay_mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
                        if ( ! is_array( $x_relay_mappings ) ) {
                            $x_relay_mappings = json_decode( is_string( $x_relay_mappings ) ? $x_relay_mappings : '{}', true );
                            if ( ! is_array( $x_relay_mappings ) ) {
                                $x_relay_mappings = array();
                            }
                        }

                        if ( ! isset( $x_relay_mappings[ $x_portal_key ]['type'] ) || 'x' !== $x_relay_mappings[ $x_portal_key ]['type'] ) {
                            $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                                . esc_html__( 'X connection failed: Portal configuration not found.', 'post-forwarder' )
                                . '</p></div>';
                        } else {
                            unset( $x_relay_mappings[ $x_portal_key ]['last_error'] );
                            $x_relay_mappings[ $x_portal_key ]['access_token']  = $x_payload['access_token'];
                            $expires_in = isset( $x_payload['expires_in'] ) && $x_payload['expires_in'] > 0
                                ? (int) $x_payload['expires_in']
                                : 7200;
                            $x_relay_mappings[ $x_portal_key ]['token_expires'] = time() + $expires_in;

                            if ( ! empty( $x_payload['refresh_token'] ) ) {
                                $x_relay_mappings[ $x_portal_key ]['refresh_token']         = $x_payload['refresh_token'];
                                $x_relay_mappings[ $x_portal_key ]['refresh_token_expires'] = time() + (int) ( $x_payload['refresh_token_expires_in'] ?? 7776000 );
                            }
                            if ( ! empty( $x_payload['x_user_id'] ) ) {
                                $x_relay_mappings[ $x_portal_key ]['x_user_id'] = sanitize_text_field( $x_payload['x_user_id'] );
                            }
                            if ( ! empty( $x_payload['x_username'] ) ) {
                                $x_relay_mappings[ $x_portal_key ]['x_username'] = sanitize_text_field( $x_payload['x_username'] );
                            }

                            $options['mappings'] = wp_json_encode( $x_relay_mappings );
                            update_option( 'post_forwarding_options', $options );
                            $options = get_option( 'post_forwarding_options', array() );
                            if ( is_string( $options ) ) {
                                $options = json_decode( $options, true );
                                if ( ! is_array( $options ) ) {
                                    $options = array();
                                }
                            }
                            $x_oauth_notice = '<div class="notice notice-success is-dismissible"><p>'
                                . esc_html__( 'X account connected successfully!', 'post-forwarder' )
                                . '</p></div>';
                        }
                    }
                }
            }
        }
    }

    // Handle WordPress Application Password authorization callbacks.
    $wp_auth_notice = '';

    if ( isset( $_GET['wordpress_auth_callback'] ) ) {
        $wp_portal_key = isset( $_GET['portal_key'] ) ? sanitize_key( wp_unslash( $_GET['portal_key'] ) ) : '';
        $wp_nonce_val  = isset( $_GET['wp_nonce'] )   ? sanitize_text_field( wp_unslash( $_GET['wp_nonce'] ) ) : '';

        if ( ! $wp_portal_key || ! wp_verify_nonce( $wp_nonce_val, 'wp_auth_' . $wp_portal_key ) ) {
            $wp_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'WordPress connection failed: security check failed.', 'post-forwarder' ) . '</p></div>';
        } else {
            $cb_user_login = isset( $_GET['user_login'] ) ? sanitize_user( wp_unslash( $_GET['user_login'] ), true ) : '';
            $cb_password   = isset( $_GET['password'] )   ? sanitize_text_field( wp_unslash( $_GET['password'] ) )  : '';

            if ( ! $cb_user_login || ! $cb_password ) {
                $wp_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'WordPress connection failed: credentials missing from callback.', 'post-forwarder' ) . '</p></div>';
            } else {
                $cb_mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
                if ( ! is_array( $cb_mappings ) ) {
                    $cb_mappings = json_decode( is_string( $cb_mappings ) ? $cb_mappings : '{}', true );
                    if ( ! is_array( $cb_mappings ) ) {
                        $cb_mappings = array();
                    }
                }
                if ( ! isset( $cb_mappings[ $wp_portal_key ]['type'] ) || 'wordpress' !== $cb_mappings[ $wp_portal_key ]['type'] ) {
                    $wp_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'WordPress connection failed: portal configuration not found.', 'post-forwarder' ) . '</p></div>';
                } else {
                    unset( $cb_mappings[ $wp_portal_key ]['last_error'] );
                    $cb_mappings[ $wp_portal_key ]['user']         = $cb_user_login;
                    $cb_mappings[ $wp_portal_key ]['password']     = $cb_password;
                    $cb_mappings[ $wp_portal_key ]['wp_auth_mode'] = 'button';
                    // Store the WordPress site URL (where REST API / wp-admin actually live).
                    if ( ! empty( $_GET['site_url'] ) ) {
                        $cb_mappings[ $wp_portal_key ]['wp_site_url'] = esc_url_raw( wp_unslash( $_GET['site_url'] ) );
                    }
                    $options['mappings'] = wp_json_encode( $cb_mappings );
                    update_option( 'post_forwarding_options', $options );
                    $options = get_option( 'post_forwarding_options', array() );
                    if ( is_string( $options ) ) {
                        $options = json_decode( $options, true );
                        if ( ! is_array( $options ) ) {
                            $options = array();
                        }
                    }
                    $wp_auth_notice = '<div class="notice notice-success is-dismissible"><p>'
                        . esc_html( sprintf( __( 'WordPress site connected successfully as %s!', 'post-forwarder' ), $cb_user_login ) )
                        . '</p></div>';
                }
            }
        }
    }

    if ( isset( $_GET['wordpress_auth_rejected'] ) ) {
        $wp_auth_notice = '<div class="notice notice-warning is-dismissible"><p>'
            . esc_html__( 'WordPress connection was cancelled. You can try again or enter credentials manually.', 'post-forwarder' )
            . '</p></div>';
    }

    if ( isset( $_GET['wp_connect_error'] ) && 'preflight_failed' === $_GET['wp_connect_error'] ) {
        $wp_auth_notice = '<div class="notice notice-error is-dismissible"><p>'
            . esc_html__( 'WordPress connection failed: could not reach the REST API on the target site. Make sure the URL is correct and the site runs WordPress 5.6+.', 'post-forwarder' )
            . '</p></div>';
    }

    // Meta (Facebook + Instagram) relay callback.
    $meta_auth_notice = '';

    if ( isset( $_GET['meta_relay_callback'] ) ) {
        $meta_relay_error = isset( $_GET['relay_error'] ) ? sanitize_text_field( wp_unslash( $_GET['relay_error'] ) ) : '';
        if ( $meta_relay_error ) {
            $meta_auth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html( sprintf( __( 'Meta connection failed: %s', 'post-forwarder' ), $meta_relay_error ) )
                . '</p></div>';
        } else {
            $meta_relay_token_key = isset( $_GET['relay_token_key'] ) ? sanitize_text_field( wp_unslash( $_GET['relay_token_key'] ) ) : '';
            $meta_relay_url       = post_forwarder_relay_url();

            if ( ! $meta_relay_token_key || ! $meta_relay_url ) {
                $meta_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Meta connection failed: missing token key.', 'post-forwarder' ) . '</p></div>';
            } else {
                $meta_token_resp = wp_remote_post( $meta_relay_url . '/token', array(
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode( array( 'key' => $meta_relay_token_key ) ),
                    'timeout' => 15,
                ) );

                if ( is_wp_error( $meta_token_resp ) ) {
                    $meta_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . sprintf( esc_html__( 'Meta connection failed: relay request error — %s', 'post-forwarder' ), esc_html( $meta_token_resp->get_error_message() ) ) . '</p></div>';
                } elseif ( 200 !== wp_remote_retrieve_response_code( $meta_token_resp ) ) {
                    $http_code = wp_remote_retrieve_response_code( $meta_token_resp );
                    $body      = wp_remote_retrieve_body( $meta_token_resp );
                    $meta_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . sprintf( esc_html__( 'Meta connection failed: relay returned HTTP %d — %s', 'post-forwarder' ), $http_code, esc_html( $body ) ) . '</p></div>';
                } else {
                    $meta_payload    = json_decode( wp_remote_retrieve_body( $meta_token_resp ), true );
                    $meta_portal_key = isset( $meta_payload['portal_key'] ) ? sanitize_key( $meta_payload['portal_key'] ) : '';
                    $meta_wp_nonce   = isset( $meta_payload['wp_nonce'] )   ? $meta_payload['wp_nonce']   : '';
                    $meta_pages      = isset( $meta_payload['pages'] )      ? $meta_payload['pages']      : array();

                    if ( ! $meta_portal_key || ! wp_verify_nonce( $meta_wp_nonce, 'meta_oauth_' . $meta_portal_key ) ) {
                        $meta_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Meta connection failed: security check failed.', 'post-forwarder' ) . '</p></div>';
                    } elseif ( empty( $meta_pages ) ) {
                        $meta_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Meta connection failed: no pages in payload.', 'post-forwarder' ) . '</p></div>';
                    } else {
                        $meta_cb_opts = get_option( 'post_forwarding_options', array() );
                        if ( is_string( $meta_cb_opts ) ) { $meta_cb_opts = json_decode( $meta_cb_opts, true ); }
                        if ( ! is_array( $meta_cb_opts ) ) { $meta_cb_opts = array(); }
                        $meta_cb_maps = isset( $meta_cb_opts['mappings'] ) ? $meta_cb_opts['mappings'] : array();
                        if ( ! is_array( $meta_cb_maps ) ) {
                            $meta_cb_maps = json_decode( is_string( $meta_cb_maps ) ? $meta_cb_maps : '{}', true );
                            if ( ! is_array( $meta_cb_maps ) ) { $meta_cb_maps = array(); }
                        }

                        if ( count( $meta_pages ) === 1 ) {
                            $meta_page = $meta_pages[0];
                            $meta_cb_maps[ $meta_portal_key ]['access_token']         = $meta_page['access_token'];
                            $meta_cb_maps[ $meta_portal_key ]['page_id']              = $meta_page['id'];
                            $meta_cb_maps[ $meta_portal_key ]['page_name']            = $meta_page['name'];
                            $meta_cb_maps[ $meta_portal_key ]['instagram_account_id'] = ! empty( $meta_page['instagram_business_account']['id'] ) ? $meta_page['instagram_business_account']['id'] : '';
                            $meta_cb_maps[ $meta_portal_key ]['meta_auth_mode']       = 'button';
                            unset( $meta_cb_maps[ $meta_portal_key ]['pending_pages'] );
                            $meta_cb_opts['mappings'] = wp_json_encode( $meta_cb_maps );
                            update_option( 'post_forwarding_options', $meta_cb_opts );
                            $ig_suffix = ! empty( $meta_cb_maps[ $meta_portal_key ]['instagram_account_id'] )
                                ? ' ' . esc_html__( 'Instagram also linked.', 'post-forwarder' )
                                : ' ' . esc_html__( 'No Instagram Business account on this Page.', 'post-forwarder' );
                            $meta_auth_notice = '<div class="notice notice-success is-dismissible"><p>'
                                . esc_html( sprintf( __( 'Connected to Facebook Page "%s".', 'post-forwarder' ), $meta_page['name'] ) )
                                . $ig_suffix . '</p></div>';
                        } else {
                            $meta_cb_maps[ $meta_portal_key ]['pending_pages'] = $meta_pages;
                            $meta_cb_opts['mappings'] = wp_json_encode( $meta_cb_maps );
                            update_option( 'post_forwarding_options', $meta_cb_opts );
                            $meta_auth_notice = 'page_select:' . $meta_portal_key;
                        }
                    }
                }
            }
        }
    }

    // Meta page selection form submission.
    if ( isset( $_POST['meta_select_page'], $_POST['meta_select_portal_key'] ) && check_admin_referer( 'meta_page_select' ) ) {
        $sel_key     = sanitize_key( wp_unslash( $_POST['meta_select_portal_key'] ) );
        $sel_page_id = sanitize_text_field( wp_unslash( $_POST['meta_select_page'] ) );
        $sel_opts    = get_option( 'post_forwarding_options', array() );
        if ( is_string( $sel_opts ) ) { $sel_opts = json_decode( $sel_opts, true ); }
        if ( ! is_array( $sel_opts ) ) { $sel_opts = array(); }
        $sel_maps = isset( $sel_opts['mappings'] ) ? $sel_opts['mappings'] : array();
        if ( ! is_array( $sel_maps ) ) { $sel_maps = json_decode( is_string( $sel_maps ) ? $sel_maps : '{}', true ); }
        if ( ! is_array( $sel_maps ) ) { $sel_maps = array(); }

        if ( isset( $sel_maps[ $sel_key ]['pending_pages'] ) && is_array( $sel_maps[ $sel_key ]['pending_pages'] ) ) {
            foreach ( $sel_maps[ $sel_key ]['pending_pages'] as $pg ) {
                if ( (string) $pg['id'] === $sel_page_id ) {
                    $sel_maps[ $sel_key ]['access_token']         = $pg['access_token'];
                    $sel_maps[ $sel_key ]['page_id']              = $pg['id'];
                    $sel_maps[ $sel_key ]['page_name']            = $pg['name'];
                    $sel_maps[ $sel_key ]['instagram_account_id'] = ! empty( $pg['instagram_business_account']['id'] ) ? $pg['instagram_business_account']['id'] : '';
                    $sel_maps[ $sel_key ]['meta_auth_mode']       = 'button';
                    unset( $sel_maps[ $sel_key ]['pending_pages'] );
                    $sel_opts['mappings'] = wp_json_encode( $sel_maps );
                    update_option( 'post_forwarding_options', $sel_opts );
                    $ig_suffix = ! empty( $sel_maps[ $sel_key ]['instagram_account_id'] )
                        ? ' ' . esc_html__( 'Instagram also linked.', 'post-forwarder' )
                        : '';
                    $meta_auth_notice = '<div class="notice notice-success is-dismissible"><p>'
                        . esc_html( sprintf( __( 'Connected to Facebook Page "%s".', 'post-forwarder' ), $pg['name'] ) )
                        . $ig_suffix . '</p></div>';
                    break;
                }
            }
        }
    }

    // Handle LinkedIn direct OAuth callback (non-relay mode).
    if ( '' === $linkedin_oauth_notice && isset( $_GET['linkedin_oauth_callback'] ) ) {
        if ( isset( $_GET['error'] ) || isset( $_GET['error_description'] ) ) {
            $li_error_desc = isset( $_GET['error_description'] )
                ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) )
                : sanitize_text_field( wp_unslash( $_GET['error'] ) );

            // Store the error on the portal so it shows in the UI.
            if ( isset( $_GET['state'] ) ) {
                $err_state_parts = explode( '|', sanitize_text_field( wp_unslash( $_GET['state'] ) ) );
                if ( count( $err_state_parts ) === 2 ) {
                    $err_portal_key = sanitize_key( $err_state_parts[0] );
                    $err_mappings   = isset( $options['mappings'] ) ? $options['mappings'] : array();
                    if ( ! is_array( $err_mappings ) ) {
                        $err_mappings = json_decode( is_string( $err_mappings ) ? $err_mappings : '{}', true );
                        if ( ! is_array( $err_mappings ) ) {
                            $err_mappings = array();
                        }
                    }
                    if ( isset( $err_mappings[ $err_portal_key ] ) ) {
                        $err_mappings[ $err_portal_key ]['last_error'] = $li_error_desc;
                        $options['mappings'] = wp_json_encode( $err_mappings );
                        update_option( 'post_forwarding_options', $options );
                        $options = get_option( 'post_forwarding_options', array() );
                        if ( is_string( $options ) ) {
                            $options = json_decode( $options, true );
                            if ( ! is_array( $options ) ) {
                                $options = array();
                            }
                        }
                    }
                }
            }

            /* translators: %s: error description returned by LinkedIn */
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html( sprintf( __( 'LinkedIn connection failed: %s', 'post-forwarder' ), $li_error_desc ) )
                . '</p></div>';

        } elseif ( isset( $_GET['code'], $_GET['state'] ) ) {
            $state_parts = explode( '|', sanitize_text_field( wp_unslash( $_GET['state'] ) ) );

            if ( count( $state_parts ) !== 2 ) {
                $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                    . esc_html__( 'LinkedIn connection failed: Invalid state parameter.', 'post-forwarder' )
                    . '</p></div>';
            } else {
                $portal_key = sanitize_key( $state_parts[0] );
                $nonce_val  = $state_parts[1];

                if ( ! wp_verify_nonce( $nonce_val, 'linkedin_oauth_' . $portal_key ) ) {
                    $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                        . esc_html__( 'LinkedIn connection failed: Security check failed.', 'post-forwarder' )
                        . '</p></div>';
                } else {
                    $mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
                    if ( ! is_array( $mappings ) ) {
                        $mappings = json_decode( is_string( $mappings ) ? $mappings : '{}', true );
                        if ( ! is_array( $mappings ) ) {
                            $mappings = array();
                        }
                    }

                    if ( ! isset( $mappings[ $portal_key ]['type'] ) || 'linkedin' !== $mappings[ $portal_key ]['type'] ) {
                        $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                            . esc_html__( 'LinkedIn connection failed: Portal configuration not found.', 'post-forwarder' )
                            . '</p></div>';
                    } else {
                        $redirect_uri = admin_url( 'admin.php?page=post-forwarder-settings&linkedin_oauth_callback=1' );
                        $auth_code    = sanitize_text_field( wp_unslash( $_GET['code'] ) );

                        $token_response = wp_remote_post(
                            'https://www.linkedin.com/oauth/v2/accessToken',
                            array(
                                'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
                                'body'    => array(
                                    'grant_type'    => 'authorization_code',
                                    'code'          => $auth_code,
                                    'client_id'     => post_forwarder_linkedin_client_id( isset( $mappings[ $portal_key ]['client_id'] ) ? $mappings[ $portal_key ]['client_id'] : '' ),
                                    'client_secret' => post_forwarder_linkedin_client_secret( isset( $mappings[ $portal_key ]['client_secret'] ) ? $mappings[ $portal_key ]['client_secret'] : '' ),
                                    'redirect_uri'  => $redirect_uri,
                                ),
                                'timeout' => 30,
                            )
                        );

                        if ( is_wp_error( $token_response ) ) {
                            $error_msg = $token_response->get_error_message();
                            post_forwarder_log_error( 'LinkedIn token request failed: ' . $error_msg );
                            /* translators: %s: error message */
                            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                                . esc_html( sprintf( __( 'LinkedIn connection failed: %s', 'post-forwarder' ), $error_msg ) )
                                . '</p></div>';
                        } else {
                            $http_code  = wp_remote_retrieve_response_code( $token_response );
                            $token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );

                            if ( 200 === $http_code && isset( $token_data['access_token'] ) ) {
                                unset( $mappings[ $portal_key ]['last_error'] );
                                $mappings[ $portal_key ]['access_token']  = $token_data['access_token'];
                                $mappings[ $portal_key ]['token_expires'] = time() + (int) ( isset( $token_data['expires_in'] ) ? $token_data['expires_in'] : 3600 );
                                if ( isset( $token_data['refresh_token'] ) ) {
                                    $mappings[ $portal_key ]['refresh_token']         = $token_data['refresh_token'];
                                    $mappings[ $portal_key ]['refresh_token_expires'] = time() + (int) ( isset( $token_data['refresh_token_expires_in'] ) ? $token_data['refresh_token_expires_in'] : 2592000 );
                                }

                                // Fetch the member's person URN via the OpenID Connect userinfo endpoint (requires openid + profile scopes).
                                $me_response = wp_remote_get(
                                    'https://api.linkedin.com/v2/userinfo',
                                    array(
                                        'headers' => array( 'Authorization' => 'Bearer ' . $token_data['access_token'] ),
                                        'timeout' => 10,
                                    )
                                );
                                if ( ! is_wp_error( $me_response ) && 200 === wp_remote_retrieve_response_code( $me_response ) ) {
                                    $me_data = json_decode( wp_remote_retrieve_body( $me_response ), true );
                                    // The `sub` field contains the member ID (e.g. "AbCdEf123").
                                    if ( isset( $me_data['sub'] ) ) {
                                        $mappings[ $portal_key ]['person_urn'] = 'urn:li:person:' . $me_data['sub'];
                                    }
                                }

                                $options['mappings'] = wp_json_encode( $mappings );
                                update_option( 'post_forwarding_options', $options );
                                $options = get_option( 'post_forwarding_options', array() );
                                if ( is_string( $options ) ) {
                                    $options = json_decode( $options, true );
                                    if ( ! is_array( $options ) ) {
                                        $options = array();
                                    }
                                }
                                $linkedin_oauth_notice = '<div class="notice notice-success is-dismissible"><p>'
                                    . esc_html__( 'LinkedIn account connected successfully!', 'post-forwarder' )
                                    . '</p></div>';
                            } else {
                                $err = isset( $token_data['error_description'] )
                                    ? $token_data['error_description']
                                    : ( isset( $token_data['error'] ) ? $token_data['error'] : 'Unknown error' );
                                post_forwarder_log_error( 'LinkedIn token exchange failed: ' . $err . ' (HTTP ' . $http_code . ')' );
                                $mappings[ $portal_key ]['last_error'] = $err;
                                $options['mappings'] = wp_json_encode( $mappings );
                                update_option( 'post_forwarding_options', $options );
                                /* translators: 1: error message, 2: HTTP status code */
                                $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                                    . esc_html( sprintf( __( 'LinkedIn connection failed: %s (HTTP %d)', 'post-forwarder' ), $err, $http_code ) )
                                    . '</p></div>';
                            }
                        }
                    }
                }
            }
        } else {
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'LinkedIn connection failed: Missing authorization code.', 'post-forwarder' )
                . '</p></div>';
        }
    }

    // Show success notice after PRG redirect.
    if ( isset( $_GET['portals_saved'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Accounts saved successfully!', 'post-forwarder' ) . '</p></div>';
    }

    // Re-read options here so any OAuth callbacks that ran above (and updated the DB) are reflected.
    $options = get_option( 'post_forwarding_options', array() );
    if ( is_string( $options ) ) {
        $options = json_decode( $options, true );
        if ( ! is_array( $options ) ) { $options = array(); }
    }

    // Parse existing mappings.
    $mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
    if ( ! is_array( $mappings ) ) {
        $mappings_json = is_string( $mappings ) ? $mappings : '{}';
        $mappings      = json_decode( $mappings_json, true );
        if ( ! is_array( $mappings ) ) {
            $mappings = array();
        }
    }

    // True when app-wide LinkedIn credentials are configured as PHP constants.
    $li_creds_from_constants = defined( 'POST_FORWARDER_LINKEDIN_CLIENT_ID' )
        && defined( 'POST_FORWARDER_LINKEDIN_CLIENT_SECRET' )
        && POST_FORWARDER_LINKEDIN_CLIENT_ID
        && POST_FORWARDER_LINKEDIN_CLIENT_SECRET;

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php echo wp_kses_post($linkedin_oauth_notice); ?>
        <?php echo wp_kses_post($x_oauth_notice); ?>
        <?php echo wp_kses_post( $wp_auth_notice ); ?>
        <?php
        if ( strpos( $meta_auth_notice, 'page_select:' ) === 0 ) :
            $meta_picker_key  = substr( $meta_auth_notice, strlen( 'page_select:' ) );
            $meta_picker_opts = get_option( 'post_forwarding_options', array() );
            if ( is_string( $meta_picker_opts ) ) { $meta_picker_opts = json_decode( $meta_picker_opts, true ); }
            $meta_picker_maps = isset( $meta_picker_opts['mappings'] ) ? $meta_picker_opts['mappings'] : array();
            if ( ! is_array( $meta_picker_maps ) ) { $meta_picker_maps = json_decode( is_string( $meta_picker_maps ) ? $meta_picker_maps : '{}', true ); }
            $meta_picker_pages = isset( $meta_picker_maps[ $meta_picker_key ]['pending_pages'] ) ? $meta_picker_maps[ $meta_picker_key ]['pending_pages'] : array();
        ?>
        <div class="notice notice-info" style="padding:16px;">
            <p><strong><?php esc_html_e( 'Select a Facebook Page to connect:', 'post-forwarder' ); ?></strong></p>
            <form method="post">
                <?php wp_nonce_field( 'meta_page_select' ); ?>
                <input type="hidden" name="meta_select_portal_key" value="<?php echo esc_attr( $meta_picker_key ); ?>">
                <?php foreach ( $meta_picker_pages as $pg ) : ?>
                <label style="display:block;margin:6px 0;">
                    <input type="radio" name="meta_select_page" value="<?php echo esc_attr( $pg['id'] ); ?>" required>
                    <strong><?php echo esc_html( $pg['name'] ); ?></strong>
                    <?php if ( ! empty( $pg['instagram_business_account']['id'] ) ) : ?>
                        <span style="color:#666;font-size:12px;margin-left:6px;">+ Instagram</span>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>
                <?php submit_button( __( 'Connect Selected Page', 'post-forwarder' ), 'primary', 'submit', false ); ?>
            </form>
        </div>
        <?php else : ?>
        <?php echo wp_kses_post( $meta_auth_notice ); ?>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('post_forwarding'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Forwarding', 'post-forwarder'); ?></th>
                    <td><input type="checkbox" name="post_forwarding_options[enabled]" value="1" <?php checked(isset($options['enabled']) ? $options['enabled'] : '', 1); ?>></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Forwarded Post Status', 'post-forwarder'); ?></th>
                    <td>
                        <select name="post_forwarding_options[post_status]">
                            <option value="publish" <?php selected(isset($options['post_status']) ? $options['post_status'] : '', 'publish'); ?>><?php esc_html_e('Publish', 'post-forwarder'); ?></option>
                            <option value="draft" <?php selected(isset($options['post_status']) ? $options['post_status'] : '', 'draft'); ?>><?php esc_html_e('Draft', 'post-forwarder'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Relay URL', 'post-forwarder'); ?></th>
                    <td>
                        <input type="url" name="post_forwarding_options[relay_url]"
                               value="<?php echo esc_attr( isset( $options['relay_url'] ) ? $options['relay_url'] : '' ); ?>"
                               placeholder="https://your-relay.workers.dev"
                               style="width:400px;" />
                        <p class="description"><?php esc_html_e( 'Required for LinkedIn, X, and Meta. URL of your deployed Cloudflare Worker relay. Overridden by the POST_FORWARDER_RELAY_URL constant if set.', 'post-forwarder' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr>

        <h2><?php esc_html_e('Connection Configuration', 'post-forwarder'); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('save_portals', 'portals_nonce'); ?>
            <input type="hidden" name="pending_linkedin_connect" value="">
            <input type="hidden" name="pending_x_connect" value="">
            <input type="hidden" name="pending_wp_connect" value="">
            <input type="hidden" name="pending_meta_connect" value="">
            
            <div id="portals-container">

                <?php if ( empty( $mappings ) ) : ?>
                    <div class="portal-row" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;" data-type="wordpress">
                        <h4><?php esc_html_e( 'Account #1', 'post-forwarder' ); ?></h4>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'Account Type', 'post-forwarder' ); ?></th>
                                <td>
                                    <select name="portals[0][type]" class="portal-type-select">
                                        <option value="wordpress"><?php esc_html_e( 'WordPress Portal', 'post-forwarder' ); ?></option>
                                        <option value="linkedin"><?php esc_html_e( 'LinkedIn Account', 'post-forwarder' ); ?></option>
                                        <option value="x"><?php esc_html_e( 'X (Twitter) Account', 'post-forwarder' ); ?></option>
                                        <option value="meta"><?php esc_html_e( 'Meta (Facebook + Instagram)', 'post-forwarder' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Account Key', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][key]" placeholder="<?php esc_attr_e( 'e.g., portal1', 'post-forwarder' ); ?>" style="width: 200px;" /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Account Name', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][name]" placeholder="<?php esc_attr_e( 'e.g., Example Portal', 'post-forwarder' ); ?>" style="width: 300px;" /></td>
                            </tr>
                            <tr class="fields-wordpress">
                                <th><?php esc_html_e( 'URL', 'post-forwarder' ); ?></th>
                                <td><input type="url" name="portals[0][url]" placeholder="https://example.com" style="width: 400px;" /></td>
                            </tr>
                            <tr class="fields-wordpress">
                                <th><?php esc_html_e( 'Connection', 'post-forwarder' ); ?></th>
                                <td>
                                    <button type="button" class="button save-and-connect-wp" style="background:#3858e9;border-color:#3858e9;color:#fff;">
                                        &#10132; <?php esc_html_e( 'Save &amp; Connect with WordPress', 'post-forwarder' ); ?>
                                    </button>
                                    <p class="description" style="margin-top:6px;">
                                        <a href="#" class="wp-manual-toggle"><?php esc_html_e( 'Enter credentials manually instead', 'post-forwarder' ); ?></a>
                                    </p>
                                </td>
                            </tr>
                            <tr class="fields-wordpress wp-manual-fields" style="display:none;">
                                <th><?php esc_html_e( 'Username / User ID', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][user]" placeholder="<?php esc_attr_e( 'username or user ID', 'post-forwarder' ); ?>" style="width: 200px;" /></td>
                            </tr>
                            <tr class="fields-wordpress wp-manual-fields" style="display:none;">
                                <th><?php esc_html_e( 'App Password', 'post-forwarder' ); ?></th>
                                <td><input type="password" name="portals[0][password]" placeholder="xxxx xxxx xxxx xxxx" style="width: 300px;" /></td>
                            </tr>
                            <?php if ( ! $li_creds_from_constants ) : ?>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Client ID', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][client_id]" placeholder="<?php esc_attr_e( 'LinkedIn App Client ID', 'post-forwarder' ); ?>" style="width: 300px;" /></td>
                            </tr>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Client Secret', 'post-forwarder' ); ?></th>
                                <td><input type="password" name="portals[0][client_secret]" placeholder="<?php esc_attr_e( 'LinkedIn App Client Secret', 'post-forwarder' ); ?>" style="width: 300px;" /></td>
                            </tr>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Author URN', 'post-forwarder' ); ?></th>
                                <td>
                                    <input type="text" name="portals[0][author_urn]" placeholder="urn:li:person:XXXX or urn:li:organization:XXXX" style="width: 420px;" />
                                    <p class="description"><?php esc_html_e( 'Your LinkedIn person or organization URN.', 'post-forwarder' ); ?></p>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Connection Status', 'post-forwarder' ); ?></th>
                                <td>
                                    <?php if ( post_forwarder_relay_url() ) : ?>
                                        <button type="button" class="button save-and-connect-linkedin" style="background:#0a66c2;border-color:#0a66c2;color:#fff;">&#10132; <?php esc_html_e( 'Save &amp; Connect with LinkedIn', 'post-forwarder' ); ?></button>
                                    <?php else : ?>
                                        <span style="color:#666;"><?php esc_html_e( 'Save the account first, then click Connect with LinkedIn.', 'post-forwarder' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr class="fields-x" style="display:none;">
                                <th><?php esc_html_e( 'Connection Status', 'post-forwarder' ); ?></th>
                                <td>
                                    <?php if ( post_forwarder_relay_url() ) : ?>
                                        <button type="button" class="button save-and-connect-x" style="background:#000;border-color:#000;color:#fff;">&#10132; <?php esc_html_e( 'Save &amp; Connect with X', 'post-forwarder' ); ?></button>
                                    <?php else : ?>
                                        <span style="color:#666;"><?php esc_html_e( 'X connection requires the relay. Configure POST_FORWARDER_RELAY_URL first.', 'post-forwarder' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr class="fields-meta" style="display:none;">
                                <th><?php esc_html_e( 'Post to', 'post-forwarder' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="portals[0][post_to_facebook]" value="1" checked> <?php esc_html_e( 'Facebook Page', 'post-forwarder' ); ?></label>
                                    &nbsp;&nbsp;
                                    <label><input type="checkbox" name="portals[0][post_to_instagram]" value="1" checked> <?php esc_html_e( 'Instagram', 'post-forwarder' ); ?></label>
                                    <p class="description"><?php esc_html_e( 'Instagram requires a Business/Creator account linked to the Page, and a publicly accessible image.', 'post-forwarder' ); ?></p>
                                </td>
                            </tr>
                            <tr class="fields-meta" style="display:none;">
                                <th><?php esc_html_e( 'Connection', 'post-forwarder' ); ?></th>
                                <td>
                                    <button type="button" class="button save-and-connect-meta" style="background:#1877f2;border-color:#1877f2;color:#fff;">&#10132; <?php esc_html_e( 'Save &amp; Connect with Meta', 'post-forwarder' ); ?></button>
                                </td>
                            </tr>
                        </table>
                        <button type="button" class="button test-connection" style="margin-right: 8px;"><?php esc_html_e( 'Test Connection', 'post-forwarder' ); ?></button>
                        <span class="connection-result" style="font-weight: 600;"></span>
                        <button type="button" class="button remove-portal" style="float: right;"><?php esc_html_e( 'Remove Account', 'post-forwarder' ); ?></button>
                    </div>
                <?php else: ?>
                    <?php $i = 0; foreach ($mappings as $key => $mapping): ?>
                        <?php
                        $mapping_type = isset($mapping['type']) ? $mapping['type'] : 'wordpress';
                        $is_meta      = ( 'meta' === $mapping_type );
                        $is_linkedin  = ($mapping_type === 'linkedin');
                        $is_connected   = $is_linkedin
                            && ! empty( $mapping['access_token'] )
                            && ( ! isset( $mapping['token_expires'] ) || $mapping['token_expires'] > time() );
                        $has_credentials = $li_creds_from_constants || (bool) post_forwarder_relay_url() || ! empty( $mapping['client_id'] );
                        $oauth_redirect  = admin_url( 'admin.php?page=post-forwarder-settings&linkedin_oauth_callback=1' );
                        $oauth_state     = $key . '|' . wp_create_nonce( 'linkedin_oauth_' . $key );

                        // Build the OAuth start URL: use the relay when configured, otherwise
                        // hit LinkedIn directly (requires a pre-registered redirect URI).
                        $relay_url_val = post_forwarder_relay_url();
                        if ( $relay_url_val ) {
                            $oauth_url = $relay_url_val . '/start?' . http_build_query( array(
                                'return_url' => admin_url( 'admin.php?page=post-forwarder-settings' ),
                                'portal_key' => $key,
                                'wp_nonce'   => wp_create_nonce( 'linkedin_oauth_' . $key ),
                            ) );
                        } else {
                            $oauth_url = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query( array(
                                'response_type' => 'code',
                                'client_id'     => post_forwarder_linkedin_client_id( isset( $mapping['client_id'] ) ? $mapping['client_id'] : '' ),
                                'redirect_uri'  => $oauth_redirect,
                                'state'         => $oauth_state,
                                'scope'         => 'w_member_social openid profile',
                            ) );
                        }

                        $is_meta_connected = $is_meta && ! empty( $mapping['access_token'] ) && ! empty( $mapping['page_id'] );
                        $meta_relay_val    = post_forwarder_relay_url();
                        $meta_connect_url  = ( $is_meta && $meta_relay_val )
                            ? $meta_relay_val . '/meta/start?' . http_build_query( array(
                                'return_url' => admin_url( 'admin.php?page=post-forwarder-settings' ),
                                'portal_key' => $key,
                                'wp_nonce'   => wp_create_nonce( 'meta_oauth_' . $key ),
                            ) )
                            : '';

                        $is_x           = ( 'x' === $mapping_type );
                        $is_x_connected = $is_x
                            && ! empty( $mapping['access_token'] )
                            && ( ! isset( $mapping['token_expires'] ) || $mapping['token_expires'] > time() );
                        $x_oauth_url    = ( $is_x && $relay_url_val )
                            ? $relay_url_val . '/x/start?' . http_build_query( array(
                                'return_url' => admin_url( 'admin.php?page=post-forwarder-settings' ),
                                'portal_key' => $key,
                                'wp_nonce'   => wp_create_nonce( 'x_oauth_' . $key ),
                            ) )
                            : '';

                        $wp_button_connected = ( 'wordpress' === $mapping_type )
                            && ! empty( $mapping['user'] )
                            && ! empty( $mapping['password'] )
                            && isset( $mapping['wp_auth_mode'] ) && 'button' === $mapping['wp_auth_mode'];
                        $wp_manual_has_data  = ( 'wordpress' === $mapping_type )
                            && ( ! empty( $mapping['user'] ) || ! empty( $mapping['password'] ) )
                            && ! $wp_button_connected;

                        // Determine overall connected state and build summary info for the compact header.
                        $is_portal_connected = $is_connected || $is_x_connected || $wp_button_connected || $wp_manual_has_data || $is_meta_connected;

                        if ( $is_linkedin ) {
                            $summary_badge = '<span style="background:#0a66c2;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;flex-shrink:0;">LI</span>';
                            if ( $is_connected ) {
                                $summary_status = '<span style="color:#00a32a;font-weight:600;">&#10003; Connected</span>';
                                if ( ! empty( $mapping['person_urn'] ) ) {
                                    $summary_status .= ' <span style="color:#666;font-size:12px;">' . esc_html( $mapping['person_urn'] ) . '</span>';
                                }
                                if ( isset( $mapping['token_expires'] ) ) {
                                    $exp_label = $mapping['token_expires'] > time()
                                        ? esc_html( sprintf( __( 'Token expires: %s', 'post-forwarder' ), date_i18n( get_option( 'date_format' ), $mapping['token_expires'] ) ) )
                                        : '<span style="color:#cc0000;">' . esc_html__( 'Token expired', 'post-forwarder' ) . '</span>';
                                    $summary_status .= ' <span style="color:#999;font-size:11px;margin-left:6px;">· ' . $exp_label . '</span>';
                                }
                                $summary_action = '<a href="' . esc_url( $oauth_url ) . '" class="button button-secondary button-small">' . esc_html__( 'Reconnect', 'post-forwarder' ) . '</a>';
                            } else {
                                $summary_status = '<span style="color:#999;">' . esc_html__( 'Not connected', 'post-forwarder' ) . '</span>';
                                $summary_action = $has_credentials
                                    ? '<a href="' . esc_url( $oauth_url ) . '" class="button button-small" style="background:#0a66c2;border-color:#0a66c2;color:#fff;">&#10132; ' . esc_html__( 'Connect', 'post-forwarder' ) . '</a>'
                                    : '';
                            }
                        } elseif ( $is_meta ) {
                            $summary_badge = '<span style="background:#1877f2;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;flex-shrink:0;">META</span>';
                            if ( $is_meta_connected ) {
                                $summary_status = '<span style="color:#00a32a;font-weight:600;">&#10003; Connected</span>';
                                $page_name = ! empty( $mapping['page_name'] ) ? ' <span style="color:#666;font-size:12px;">' . esc_html( $mapping['page_name'] ) . '</span>' : '';
                                $ig_badge  = ! empty( $mapping['instagram_account_id'] ) ? ' <span style="color:#c13584;font-size:11px;">+ IG</span>' : '';
                                $summary_status .= $page_name . $ig_badge;
                                $summary_action  = $meta_connect_url
                                    ? '<a href="' . esc_url( $meta_connect_url ) . '" class="button button-secondary button-small">' . esc_html__( 'Reconnect', 'post-forwarder' ) . '</a>'
                                    : '';
                            } else {
                                $summary_status = '<span style="color:#999;">' . esc_html__( 'Not connected', 'post-forwarder' ) . '</span>';
                                $summary_action  = $meta_connect_url
                                    ? '<a href="' . esc_url( $meta_connect_url ) . '" class="button button-small" style="background:#1877f2;border-color:#1877f2;color:#fff;">&#10132; ' . esc_html__( 'Connect', 'post-forwarder' ) . '</a>'
                                    : '';
                            }
                        } elseif ( $is_x ) {
                            $summary_badge = '<span style="background:#000;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;flex-shrink:0;">X</span>';
                            if ( $is_x_connected ) {
                                $summary_status = '<span style="color:#00a32a;font-weight:600;">&#10003; Connected</span>';
                                if ( ! empty( $mapping['x_username'] ) ) {
                                    $summary_status .= ' <span style="color:#666;font-size:12px;">@' . esc_html( $mapping['x_username'] ) . '</span>';
                                }
                                if ( isset( $mapping['token_expires'] ) ) {
                                    $exp_label = $mapping['token_expires'] > time()
                                        ? esc_html( sprintf( __( 'Token expires: %s', 'post-forwarder' ), date_i18n( get_option( 'date_format' ), $mapping['token_expires'] ) ) )
                                        : '<span style="color:#cc0000;">' . esc_html__( 'Token expired', 'post-forwarder' ) . '</span>';
                                    $summary_status .= ' <span style="color:#999;font-size:11px;margin-left:6px;">· ' . $exp_label . '</span>';
                                }
                                $summary_action = $x_oauth_url
                                    ? '<a href="' . esc_url( $x_oauth_url ) . '" class="button button-secondary button-small">' . esc_html__( 'Reconnect', 'post-forwarder' ) . '</a>'
                                    : '';
                            } else {
                                $summary_status = '<span style="color:#999;">' . esc_html__( 'Not connected', 'post-forwarder' ) . '</span>';
                                $summary_action = $x_oauth_url
                                    ? '<a href="' . esc_url( $x_oauth_url ) . '" class="button button-small" style="background:#000;border-color:#000;color:#fff;">&#10132; ' . esc_html__( 'Connect', 'post-forwarder' ) . '</a>'
                                    : '';
                            }
                        } else {
                            $summary_badge = '<span style="background:#3858e9;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;flex-shrink:0;">WP</span>';
                            if ( $wp_button_connected || $wp_manual_has_data ) {
                                $summary_status = '<span style="color:#00a32a;font-weight:600;">&#10003; Connected</span>';
                                if ( ! empty( $mapping['user'] ) ) {
                                    $summary_status .= ' <span style="color:#666;font-size:12px;">' . esc_html( $mapping['user'] ) . '</span>';
                                }
                                if ( ! empty( $mapping['url'] ) ) {
                                    $summary_status .= ' <span style="color:#999;font-size:11px;margin-left:4px;">· ' . esc_html( wp_parse_url( $mapping['url'], PHP_URL_HOST ) ) . '</span>';
                                }
                                $summary_action = '<button type="button" class="button button-secondary button-small save-and-connect-wp">' . esc_html__( 'Reconnect', 'post-forwarder' ) . '</button>';
                            } else {
                                $summary_status = '<span style="color:#999;">' . esc_html__( 'Not connected', 'post-forwarder' ) . '</span>';
                                $summary_action = '';
                            }
                        }
                        ?>
                        <div class="portal-row" style="border:1px solid #ddd;border-radius:4px;margin-bottom:10px;overflow:hidden;" data-type="<?php echo esc_attr($mapping_type); ?>">

                            <?php if ( $is_portal_connected ) : ?>
                            <div class="portal-summary" style="display:flex;align-items:center;gap:10px;padding:10px 15px;background:#fafafa;cursor:pointer;" title="<?php esc_attr_e( 'Click to expand', 'post-forwarder' ); ?>">
                                <?php echo $summary_badge; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <strong style="flex-shrink:0;"><?php echo esc_html( $mapping['name'] ); ?></strong>
                                <span style="color:#bbb;font-size:11px;flex-shrink:0;"><?php echo esc_html( $key ); ?></span>
                                <span style="flex:1;min-width:0;"><?php echo wp_kses( $summary_status, array( 'span' => array( 'style' => array() ) ) ); ?></span>
                                <?php echo wp_kses( $summary_action, array( 'a' => array( 'href' => array(), 'class' => array(), 'style' => array() ), 'button' => array( 'type' => array(), 'class' => array(), 'style' => array() ) ) ); ?>
                                <button type="button" class="button button-small portal-expand-btn" style="flex-shrink:0;">
                                    <?php esc_html_e( 'Edit', 'post-forwarder' ); ?> &#9660;
                                </button>
                            </div>
                            <?php endif; ?>

                            <div class="portal-detail" style="padding:15px;<?php echo $is_portal_connected ? 'display:none;' : ''; ?>">
                            <?php if ( ! $is_portal_connected ) : ?>
                            <h4 style="margin-top:0;">
                                <?php echo esc_html( sprintf( __( 'Account #%d', 'post-forwarder' ), $i + 1 ) ); ?>
                            </h4>
                            <?php endif; ?>
                            <table class="form-table" style="margin-top:0;">
                                <tr>
                                    <th><?php esc_html_e('Account Type', 'post-forwarder'); ?></th>
                                    <td>
                                        <select name="portals[<?php echo esc_attr($i); ?>][type]" class="portal-type-select">
                                            <option value="wordpress" <?php selected($mapping_type, 'wordpress'); ?>><?php esc_html_e('WordPress Portal', 'post-forwarder'); ?></option>
                                            <option value="linkedin"  <?php selected($mapping_type, 'linkedin');   ?>><?php esc_html_e('LinkedIn Account',  'post-forwarder'); ?></option>
                                            <option value="x"         <?php selected($mapping_type, 'x');         ?>><?php esc_html_e('X (Twitter) Account', 'post-forwarder'); ?></option>
                                            <option value="meta"      <?php selected($mapping_type, 'meta');      ?>><?php esc_html_e('Meta (Facebook + Instagram)', 'post-forwarder'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('Account Key', 'post-forwarder'); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][key]" value="<?php echo esc_attr($key); ?>" style="width: 200px;" /></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('Account Name', 'post-forwarder'); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][name]" value="<?php echo esc_attr($mapping['name']); ?>" style="width: 300px;" /></td>
                                </tr>
                                <tr class="fields-wordpress" <?php echo ( $is_linkedin || $is_x || $is_meta ) ? 'style="display:none;"' : ''; ?>>
                                    <th><?php esc_html_e( 'URL', 'post-forwarder' ); ?></th>
                                    <td><input type="url" name="portals[<?php echo esc_attr($i); ?>][url]"
                                         value="<?php echo esc_attr( isset( $mapping['url'] ) ? $mapping['url'] : '' ); ?>"
                                         style="width: 400px;" /></td>
                                </tr>
                                <tr class="fields-wordpress" <?php echo ( $is_linkedin || $is_x || $is_meta ) ? 'style="display:none;"' : ''; ?>>
                                    <th><?php esc_html_e( 'Connection', 'post-forwarder' ); ?></th>
                                    <td>
                                        <?php if ( $wp_button_connected ) : ?>
                                            <span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'post-forwarder' ); ?></span>
                                            <span style="color:#666;font-size:12px;margin-left:8px;"><?php echo esc_html( $mapping['user'] ); ?></span>
                                            <button type="button" class="button button-secondary save-and-connect-wp" style="margin-left:10px;">
                                                <?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?>
                                            </button>
                                        <?php else : ?>
                                            <button type="button" class="button save-and-connect-wp" style="background:#3858e9;border-color:#3858e9;color:#fff;">
                                                &#10132; <?php esc_html_e( 'Save &amp; Connect with WordPress', 'post-forwarder' ); ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $mapping['last_error'] ) && ! $wp_button_connected ) : ?>
                                            <p class="description" style="color:#cc0000;margin-top:6px;">
                                                <strong><?php esc_html_e( 'Last error:', 'post-forwarder' ); ?></strong>
                                                <?php echo esc_html( $mapping['last_error'] ); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ( ! $wp_button_connected ) : ?>
                                        <p class="description" style="margin-top:6px;">
                                            <a href="#" class="wp-manual-toggle"><?php esc_html_e( 'Enter credentials manually instead', 'post-forwarder' ); ?></a>
                                        </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php $show_manual = ( ! $is_linkedin && ! $is_x && ! $is_meta && $wp_manual_has_data ) ? '' : 'style="display:none;"'; ?>
                                <tr class="fields-wordpress wp-manual-fields" <?php echo $show_manual; ?>>
                                    <th><?php esc_html_e( 'Username / User ID', 'post-forwarder' ); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][user]"
                                         value="<?php echo esc_attr( isset( $mapping['user'] ) ? $mapping['user'] : '' ); ?>"
                                         placeholder="<?php esc_attr_e( 'username or user ID', 'post-forwarder' ); ?>"
                                         style="width: 200px;" /></td>
                                </tr>
                                <tr class="fields-wordpress wp-manual-fields" <?php echo $show_manual; ?>>
                                    <th><?php esc_html_e( 'App Password', 'post-forwarder' ); ?></th>
                                    <td><input type="password" name="portals[<?php echo esc_attr($i); ?>][password]"
                                         value="<?php echo esc_attr( isset( $mapping['password'] ) ? $mapping['password'] : '' ); ?>"
                                         placeholder="xxxx xxxx xxxx xxxx"
                                         style="width: 300px;" /></td>
                                </tr>
                                <?php if ( ! $li_creds_from_constants ) : ?>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Client ID', 'post-forwarder' ); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr( $i ); ?>][client_id]" value="<?php echo esc_attr( isset( $mapping['client_id'] ) ? $mapping['client_id'] : '' ); ?>" style="width: 300px;" /></td>
                                </tr>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Client Secret', 'post-forwarder' ); ?></th>
                                    <td><input type="password" name="portals[<?php echo esc_attr( $i ); ?>][client_secret]" value="<?php echo esc_attr( isset( $mapping['client_secret'] ) ? $mapping['client_secret'] : '' ); ?>" style="width: 300px;" /></td>
                                </tr>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Author URN', 'post-forwarder' ); ?></th>
                                    <td>
                                        <input type="text" name="portals[<?php echo esc_attr( $i ); ?>][author_urn]" value="<?php echo esc_attr( isset( $mapping['author_urn'] ) ? $mapping['author_urn'] : '' ); ?>" placeholder="urn:li:person:XXXX or urn:li:organization:XXXX" style="width: 420px;" />
                                        <p class="description">
                                            <?php esc_html_e( 'For personal posts: urn:li:person:YOUR_ID. For organization posts: urn:li:organization:YOUR_ORG_ID (requires Community Management API product on your LinkedIn app).', 'post-forwarder' ); ?>
                                            <?php if ( ! empty( $mapping['person_urn'] ) ) : ?>
                                                <br><strong><?php esc_html_e( 'Auto-detected person URN:', 'post-forwarder' ); ?></strong> <?php echo esc_html( $mapping['person_urn'] ); ?>
                                            <?php endif; ?>
                                        </p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e('Connection Status', 'post-forwarder'); ?></th>
                                    <td>
                                        <?php if ( $is_connected ) : ?>
                                            <span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'post-forwarder' ); ?></span>
                                            <?php if ( $is_linkedin && ! empty( $mapping['person_urn'] ) ) : ?>
                                                <span style="color:#666;font-size:12px;margin-left:8px;"><?php echo esc_html( $mapping['person_urn'] ); ?></span>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-secondary" style="margin-left:10px;"><?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?></a>
                                        <?php elseif ( $has_credentials ) : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'Not connected', 'post-forwarder' ); ?></span>
                                            <a href="<?php echo esc_url( $oauth_url ); ?>" class="button" style="margin-left:10px;background:#0a66c2;border-color:#0a66c2;color:#fff;">&#10132; <?php esc_html_e( 'Connect with LinkedIn', 'post-forwarder' ); ?></a>
                                        <?php else : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'Save Client ID and Secret first, then connect.', 'post-forwarder' ); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($mapping['last_error']) && !$is_connected): ?>
                                            <p class="description" style="color:#cc0000;margin-top:6px;">
                                                <strong><?php esc_html_e('Last error:', 'post-forwarder'); ?></strong>
                                                <?php echo esc_html($mapping['last_error']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($is_linkedin && isset($mapping['token_expires'])): ?>
                                            <p class="description">
                                                <?php if ($mapping['token_expires'] > time()): ?>
                                                    <?php
                                                    /* translators: %s: expiry date */
                                                    echo esc_html(sprintf(__('Token expires: %s', 'post-forwarder'), date_i18n(get_option('date_format'), $mapping['token_expires'])));
                                                    ?>
                                                <?php else: ?>
                                                    <span style="color:#cc0000;"><?php esc_html_e('Token expired — please reconnect.', 'post-forwarder'); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr class="fields-x" <?php echo $is_x ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Connection Status', 'post-forwarder' ); ?></th>
                                    <td>
                                        <?php if ( $is_x_connected ) : ?>
                                            <span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'post-forwarder' ); ?></span>
                                            <?php if ( ! empty( $mapping['x_username'] ) ) : ?>
                                                <span style="color:#666;font-size:12px;margin-left:8px;">@<?php echo esc_html( $mapping['x_username'] ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( $x_oauth_url ) : ?>
                                                <a href="<?php echo esc_url( $x_oauth_url ); ?>" class="button button-secondary" style="margin-left:10px;"><?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?></a>
                                            <?php endif; ?>
                                        <?php elseif ( $x_oauth_url ) : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'Not connected', 'post-forwarder' ); ?></span>
                                            <a href="<?php echo esc_url( $x_oauth_url ); ?>" class="button" style="margin-left:10px;background:#000;border-color:#000;color:#fff;">&#10132; <?php esc_html_e( 'Connect with X', 'post-forwarder' ); ?></a>
                                        <?php else : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'X connection requires the relay. Configure POST_FORWARDER_RELAY_URL first.', 'post-forwarder' ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $is_x && ! empty( $mapping['last_error'] ) && ! $is_x_connected ) : ?>
                                            <p class="description" style="color:#cc0000;margin-top:6px;">
                                                <strong><?php esc_html_e( 'Last error:', 'post-forwarder' ); ?></strong>
                                                <?php echo esc_html( $mapping['last_error'] ); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ( $is_x && isset( $mapping['token_expires'] ) ) : ?>
                                            <p class="description">
                                                <?php if ( $mapping['token_expires'] > time() ) : ?>
                                                    <?php
                                                    /* translators: %s: expiry date */
                                                    echo esc_html( sprintf( __( 'Token expires: %s', 'post-forwarder' ), date_i18n( get_option( 'date_format' ), $mapping['token_expires'] ) ) );
                                                    ?>
                                                <?php else : ?>
                                                    <span style="color:#cc0000;"><?php esc_html_e( 'Token expired — please reconnect.', 'post-forwarder' ); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr class="fields-meta" <?php echo $is_meta ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Post to', 'post-forwarder' ); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="portals[<?php echo esc_attr($i); ?>][post_to_facebook]" value="1" <?php checked( ! empty( $mapping['post_to_facebook'] ) ); ?>> <?php esc_html_e( 'Facebook Page', 'post-forwarder' ); ?></label>
                                        &nbsp;&nbsp;
                                        <label><input type="checkbox" name="portals[<?php echo esc_attr($i); ?>][post_to_instagram]" value="1" <?php checked( ! empty( $mapping['post_to_instagram'] ) ); ?>> <?php esc_html_e( 'Instagram', 'post-forwarder' ); ?></label>
                                    </td>
                                </tr>
                                <tr class="fields-meta" <?php echo $is_meta ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Connection', 'post-forwarder' ); ?></th>
                                    <td>
                                        <?php if ( $is_meta_connected ) : ?>
                                            <span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'post-forwarder' ); ?></span>
                                            <span style="color:#666;font-size:12px;margin-left:8px;"><?php echo esc_html( isset( $mapping['page_name'] ) ? $mapping['page_name'] : '' ); ?></span>
                                            <?php if ( ! empty( $mapping['instagram_account_id'] ) ) : ?>
                                                <span style="color:#c13584;font-size:12px;margin-left:6px;">+ Instagram</span>
                                            <?php endif; ?>
                                            <?php if ( $meta_connect_url ) : ?>
                                                <a href="<?php echo esc_url( $meta_connect_url ); ?>" class="button button-secondary" style="margin-left:10px;"><?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?></a>
                                            <?php endif; ?>
                                        <?php elseif ( $meta_connect_url ) : ?>
                                            <a href="<?php echo esc_url( $meta_connect_url ); ?>" class="button" style="background:#1877f2;border-color:#1877f2;color:#fff;">&#10132; <?php esc_html_e( 'Connect with Meta', 'post-forwarder' ); ?></a>
                                        <?php else : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'Enter App ID and Secret, then save.', 'post-forwarder' ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $is_meta && ! empty( $mapping['last_error'] ) && ! $is_meta_connected ) : ?>
                                            <p class="description" style="color:#cc0000;margin-top:6px;">
                                                <strong><?php esc_html_e( 'Last error:', 'post-forwarder' ); ?></strong>
                                                <?php echo esc_html( $mapping['last_error'] ); ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                            <button type="button" class="button test-connection" style="margin-right: 8px;<?php echo ( $is_linkedin || $is_x || $is_meta ) ? ' display:none;' : ''; ?>"><?php esc_html_e('Test Connection', 'post-forwarder'); ?></button>
                            <span class="connection-result" style="font-weight: 600;"></span>
                            <button type="button" class="button remove-portal" style="float: right;"><?php esc_html_e('Remove Account', 'post-forwarder'); ?></button>
                        </div><!-- end portal-detail -->
                        </div><!-- end portal-row -->
                        <?php $i++; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="button" id="add-portal" class="button"><?php esc_html_e('Add Another Connection', 'post-forwarder'); ?></button>
            <br><br>
            <?php submit_button(esc_html__('Save Portals', 'post-forwarder'), 'primary', 'submit_portals'); ?>
        </form>

        <hr>

        <h3><?php esc_html_e('Advanced: JSON Configuration', 'post-forwarder'); ?></h3>
        <form method="post" action="options.php">
            <?php settings_fields('post_forwarding'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Mappings (JSON)', 'post-forwarder'); ?></th>
                    <td>
                        <textarea name="post_forwarding_options[mappings]" rows="10" cols="70"><?php echo esc_textarea(wp_json_encode(post_forwarder_mask_mappings($mappings))); ?></textarea><br>
                        <small><?php esc_html_e('Advanced users can edit the JSON directly. Use the form above for easier configuration.', 'post-forwarder'); ?></small>
                    </td>
                </tr>
            </table>
            <?php submit_button(esc_html__('Save JSON', 'post-forwarder')); ?>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var portalCount          = <?php echo count( $mappings ); ?>;
        var liCredsFromConstants = <?php echo $li_creds_from_constants ? 'true' : 'false'; ?>;
        var relayMode            = <?php echo post_forwarder_relay_url() ? 'true' : 'false'; ?>;

        // Toggle field visibility based on account type
        function updatePortalFields($row) {
            var type = $row.find('.portal-type-select').val();
            $row.find('.fields-wordpress').toggle(type === 'wordpress');
            $row.find('.fields-linkedin').toggle(type === 'linkedin');
            $row.find('.fields-x').toggle(type === 'x');
            $row.find('.fields-meta').toggle(type === 'meta');
            $row.find('.test-connection').toggle(type === 'wordpress');
        }

        $(document).on('change', '.portal-type-select', function() {
            updatePortalFields($(this).closest('.portal-row'));
        });

        $('#add-portal').click(function() {
            var n = portalCount;
            var liCredFields = liCredsFromConstants ? '' :
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Client ID', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][client_id]" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Client Secret', 'post-forwarder' ) ); ?></th><td><input type="password" name="portals[' + n + '][client_secret]" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Author URN', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][author_urn]" placeholder="urn:li:person:XXXX or urn:li:organization:XXXX" style="width: 420px;" /></td></tr>';
            var newPortal =
                '<div class="portal-row" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;" data-type="wordpress">' +
                '<h4><?php echo esc_js( __( 'Account #', 'post-forwarder' ) ); ?>' + (n + 1) + '</h4>' +
                '<table class="form-table">' +
                '<tr><th><?php echo esc_js( __( 'Account Type', 'post-forwarder' ) ); ?></th><td>' +
                '<select name="portals[' + n + '][type]" class="portal-type-select">' +
                '<option value="wordpress"><?php echo esc_js( __( 'WordPress Portal', 'post-forwarder' ) ); ?></option>' +
                '<option value="linkedin"><?php echo esc_js( __( 'LinkedIn Account', 'post-forwarder' ) ); ?></option>' +
                '<option value="x"><?php echo esc_js( __( 'X (Twitter) Account', 'post-forwarder' ) ); ?></option>' +
                '<option value="meta"><?php echo esc_js( __( 'Meta (Facebook + Instagram)', 'post-forwarder' ) ); ?></option>' +
                '</select></td></tr>' +
                '<tr><th><?php echo esc_js( __( 'Account Key', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][key]" placeholder="<?php echo esc_js( __( 'e.g., portal1', 'post-forwarder' ) ); ?>" style="width: 200px;" /></td></tr>' +
                '<tr><th><?php echo esc_js( __( 'Account Name', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][name]" placeholder="<?php echo esc_js( __( 'e.g., My Account', 'post-forwarder' ) ); ?>" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-wordpress"><th><?php echo esc_js( __( 'URL', 'post-forwarder' ) ); ?></th><td><input type="url" name="portals[' + n + '][url]" placeholder="https://example.com" style="width: 400px;" /></td></tr>' +
                '<tr class="fields-wordpress"><th><?php echo esc_js( __( 'Connection', 'post-forwarder' ) ); ?></th><td>' +
                '<button type="button" class="button save-and-connect-wp" style="background:#3858e9;border-color:#3858e9;color:#fff;">&#10132; <?php echo esc_js( __( 'Save &amp; Connect with WordPress', 'post-forwarder' ) ); ?></button>' +
                '<p class="description" style="margin-top:6px;"><a href="#" class="wp-manual-toggle"><?php echo esc_js( __( 'Enter credentials manually instead', 'post-forwarder' ) ); ?></a></p>' +
                '</td></tr>' +
                '<tr class="fields-wordpress wp-manual-fields" style="display:none;"><th><?php echo esc_js( __( 'Username / User ID', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][user]" placeholder="<?php echo esc_js( __( 'username or user ID', 'post-forwarder' ) ); ?>" style="width: 200px;" /></td></tr>' +
                '<tr class="fields-wordpress wp-manual-fields" style="display:none;"><th><?php echo esc_js( __( 'App Password', 'post-forwarder' ) ); ?></th><td><input type="password" name="portals[' + n + '][password]" placeholder="xxxx xxxx xxxx xxxx" style="width: 300px;" /></td></tr>' +
                liCredFields +
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Connection Status', 'post-forwarder' ) ); ?></th><td>' +
                ( relayMode
                    ? '<button type="button" class="button save-and-connect-linkedin" style="background:#0a66c2;border-color:#0a66c2;color:#fff;">&#10132; <?php echo esc_js( __( 'Save &amp; Connect with LinkedIn', 'post-forwarder' ) ); ?></button>'
                    : '<span style="color:#666;"><?php echo esc_js( __( 'Save the account first, then click Connect with LinkedIn.', 'post-forwarder' ) ); ?></span>'
                ) + '</td></tr>' +
                '<tr class="fields-x" style="display:none;"><th><?php echo esc_js( __( 'Connection Status', 'post-forwarder' ) ); ?></th><td>' +
                ( relayMode
                    ? '<button type="button" class="button save-and-connect-x" style="background:#000;border-color:#000;color:#fff;">&#10132; <?php echo esc_js( __( 'Save &amp; Connect with X', 'post-forwarder' ) ); ?></button>'
                    : '<span style="color:#666;"><?php echo esc_js( __( 'X connection requires the relay. Configure POST_FORWARDER_RELAY_URL first.', 'post-forwarder' ) ); ?></span>'
                ) + '</td></tr>' +
                '<tr class="fields-meta" style="display:none;"><th><?php echo esc_js( __( 'Post to', 'post-forwarder' ) ); ?></th><td>' +
                '<label><input type="checkbox" name="portals[' + n + '][post_to_facebook]" value="1" checked> <?php echo esc_js( __( 'Facebook Page', 'post-forwarder' ) ); ?></label>&nbsp;&nbsp;' +
                '<label><input type="checkbox" name="portals[' + n + '][post_to_instagram]" value="1" checked> <?php echo esc_js( __( 'Instagram', 'post-forwarder' ) ); ?></label>' +
                '</td></tr>' +
                '<tr class="fields-meta" style="display:none;"><th><?php echo esc_js( __( 'Connection', 'post-forwarder' ) ); ?></th><td>' +
                '<button type="button" class="button save-and-connect-meta" style="background:#1877f2;border-color:#1877f2;color:#fff;">&#10132; <?php echo esc_js( __( 'Save &amp; Connect with Meta', 'post-forwarder' ) ); ?></button>' +
                '</td></tr>' +
                '</table>' +
                '<button type="button" class="button test-connection" style="margin-right: 8px;"><?php echo esc_js( __( 'Test Connection', 'post-forwarder' ) ); ?></button>' +
                '<span class="connection-result" style="font-weight: 600;"></span>' +
                '<button type="button" class="button remove-portal" style="float: right;"><?php echo esc_js( __( 'Remove Account', 'post-forwarder' ) ); ?></button>' +
                '</div>';

            $('#portals-container').append(newPortal);
            portalCount++;
        });

        $(document).on('click', '.remove-portal', function() {
            $(this).closest('.portal-row').remove();
        });

        $(document).on('click', '.save-and-connect-linkedin', function() {
            var $row  = $(this).closest('.portal-row');
            var key   = $.trim($row.find('input[name$="[key]"]').val());
            var name  = $.trim($row.find('input[name$="[name]"]').val());
            if (!key) {
                alert('<?php echo esc_js( __( 'Please enter an Account Key first.', 'post-forwarder' ) ); ?>');
                return;
            }
            if (!name) {
                alert('<?php echo esc_js( __( 'Please enter an Account Name first.', 'post-forwarder' ) ); ?>');
                return;
            }
            $('input[name="pending_linkedin_connect"]').val(key);
            $row.closest('form').find('input[name="submit_portals"]').click();
        });

        $(document).on('click', '.save-and-connect-x', function() {
            var $row  = $(this).closest('.portal-row');
            var key   = $.trim($row.find('input[name$="[key]"]').val());
            var name  = $.trim($row.find('input[name$="[name]"]').val());
            if (!key) {
                alert('<?php echo esc_js( __( 'Please enter an Account Key first.', 'post-forwarder' ) ); ?>');
                return;
            }
            if (!name) {
                alert('<?php echo esc_js( __( 'Please enter an Account Name first.', 'post-forwarder' ) ); ?>');
                return;
            }
            $('input[name="pending_x_connect"]').val(key);
            $row.closest('form').find('input[name="submit_portals"]').click();
        });

        $(document).on('click', '.save-and-connect-wp', function() {
            var $row = $(this).closest('.portal-row');
            var key  = $.trim($row.find('input[name$="[key]"]').val());
            var name = $.trim($row.find('input[name$="[name]"]').val());
            var url  = $.trim($row.find('input[name$="[url]"]').val());
            if (!key) {
                alert('<?php echo esc_js( __( 'Please enter an Account Key first.', 'post-forwarder' ) ); ?>');
                return;
            }
            if (!name) {
                alert('<?php echo esc_js( __( 'Please enter an Account Name first.', 'post-forwarder' ) ); ?>');
                return;
            }
            if (!url) {
                alert('<?php echo esc_js( __( 'Please enter the WordPress site URL first.', 'post-forwarder' ) ); ?>');
                return;
            }
            $('input[name="pending_wp_connect"]').val(key);
            $row.closest('form').find('input[name="submit_portals"]').click();
        });

        $(document).on('click', '.wp-manual-toggle', function(e) {
            e.preventDefault();
            var $connectRow = $(this).closest('tr');
            $connectRow.nextAll('.wp-manual-fields').slice(0, 2).toggle();
        });

        $(document).on('click', '.save-and-connect-meta', function() {
            var $row = $(this).closest('.portal-row');
            var key  = $.trim($row.find('input[name$="[key]"]').val());
            var name = $.trim($row.find('input[name$="[name]"]').val());
            if (!key)  { alert('<?php echo esc_js( __( 'Please enter an Account Key first.', 'post-forwarder' ) ); ?>'); return; }
            if (!name) { alert('<?php echo esc_js( __( 'Please enter an Account Name first.', 'post-forwarder' ) ); ?>'); return; }
            $('input[name="pending_meta_connect"]').val(key);
            $row.closest('form').find('input[name="submit_portals"]').click();
        });

        // Expand/collapse connected portal detail.
        $(document).on('click', '.portal-expand-btn', function(e) {
            e.stopPropagation();
            var $row    = $(this).closest('.portal-row');
            var $detail = $row.find('.portal-detail');
            var $btn    = $(this);
            if ($detail.is(':visible')) {
                $detail.slideUp(150);
                $btn.html('<?php echo esc_js( __( 'Edit', 'post-forwarder' ) ); ?> &#9660;');
            } else {
                $detail.slideDown(150);
                $btn.html('<?php echo esc_js( __( 'Collapse', 'post-forwarder' ) ); ?> &#9650;');
            }
        });

        $(document).on('click', '.portal-summary', function(e) {
            if ($(e.target).closest('button, a').length) { return; }
            $(this).find('.portal-expand-btn').trigger('click');
        });

        $(document).on('click', '.test-connection', function() {
            var $btn = $(this);
            var $row = $btn.closest('.portal-row');
            var $result = $row.find('.connection-result');
            var url = $row.find('input[name$="[url]"]').val();
            var user = $row.find('input[name$="[user]"]').val();
            var password = $row.find('input[name$="[password]"]').val();

            if (!url || !user || !password) {
                $result.css('color', '#cc0000').text('<?php echo esc_js(__('Please fill in URL, Username/User ID, and App Password first.', 'post-forwarder')); ?>');
                return;
            }

            $btn.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'post-forwarder')); ?>');
            $result.text('');

            $.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                action: 'post_forwarder_test_connection',
                nonce: '<?php echo wp_create_nonce('post_forwarder_test_connection'); ?>',
                url: url,
                user: user,
                password: password
            }, function(response) {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Test Connection', 'post-forwarder')); ?>');
                if (response.success) {
                    $result.css('color', '#00a32a').text(response.data.message);
                } else {
                    $result.css('color', '#cc0000').text(response.data.message);
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Test Connection', 'post-forwarder')); ?>');
                $result.css('color', '#cc0000').text('<?php echo esc_js(__('Request failed. Please try again.', 'post-forwarder')); ?>');
            });
        });
    });
    </script>
    <?php
}

// AJAX handler for Test Connection
add_action('wp_ajax_post_forwarder_test_connection', 'post_forwarder_test_connection_callback');

function post_forwarder_test_connection_callback() {
    check_ajax_referer('post_forwarder_test_connection', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Insufficient permissions.', 'post-forwarder')));
    }

    $url      = isset($_POST['url'])      ? esc_url_raw(wp_unslash($_POST['url']))            : '';
    $user     = isset($_POST['user'])     ? sanitize_text_field(wp_unslash($_POST['user']))     : '';
    $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';

    if (empty($url) || empty($user) || empty($password)) {
        wp_send_json_error(array('message' => __('Please fill in URL, User ID, and App Password.', 'post-forwarder')));
    }

    $api_url  = rtrim($url, '/') . '/wp-json/wp/v2/users/me';
    $auth     = base64_encode($user . ':' . $password);

    $response = wp_remote_get($api_url, array(
        'headers' => array('Authorization' => 'Basic ' . $auth),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 200 && isset($data['id'])) {
        wp_send_json_success(array(
            /* translators: 1: user display name, 2: user ID */
            'message' => sprintf(__('Connected successfully as: %1$s (ID: %2$d)', 'post-forwarder'), $data['name'], $data['id']),
        ));
    } elseif ($code === 401) {
        wp_send_json_error(array('message' => __('Authentication failed. Check your User ID and App Password.', 'post-forwarder')));
    } elseif ($code === 404) {
        wp_send_json_error(array('message' => __('REST API not found. Check the URL.', 'post-forwarder')));
    } else {
        $error_message = isset($data['message']) ? $data['message'] : sprintf(
            /* translators: %d: HTTP response code */
            __('Unexpected response (HTTP %d).', 'post-forwarder'),
            $code
        );
        wp_send_json_error(array('message' => $error_message));
    }
}

// LinkedIn post forwarding
function post_forwarder_forward_to_linkedin($post, $mapping) {
    if (empty($mapping['access_token'])) {
        return array('success' => false, 'message' => __('No access token — please connect the account.', 'post-forwarder'));
    }
    if (isset($mapping['token_expires']) && $mapping['token_expires'] <= time()) {
        return array('success' => false, 'message' => __('Token expired — please reconnect.', 'post-forwarder'));
    }
    $author_urn = isset( $mapping['author_urn'] ) ? trim( $mapping['author_urn'] ) : '';

    // Fall back to the auto-fetched person URN when no explicit author is configured.
    if ( empty( $author_urn ) && ! empty( $mapping['person_urn'] ) ) {
        $author_urn = $mapping['person_urn'];
    }

    if ( empty( $author_urn ) ) {
        post_forwarder_log_error( 'LinkedIn post skipped: no author URN configured.' );
        return array('success' => false, 'message' => __('No Author URN configured.', 'post-forwarder'));
    }

    // Build commentary: use excerpt or strip post content
    $commentary = !empty($post->post_excerpt)
        ? $post->post_excerpt
        : wp_trim_words(wp_strip_all_tags($post->post_content), 60);

    $post_url = get_permalink( $post->ID );

    $body = array(
        'author'     => $author_urn,
        'commentary' => $commentary,
        'visibility' => 'PUBLIC',
        'distribution' => array(
            'feedDistribution'               => 'MAIN_FEED',
            'targetEntities'                 => array(),
            'thirdPartyDistributionChannels' => array(),
        ),
        'lifecycleState'            => 'PUBLISHED',
        'isReshareDisabledByAuthor' => false,
    );

    // Upload featured image to LinkedIn (3-step: initialize → PUT binary → use URN in post).
    $li_image_urn      = null;
    $featured_image_id = get_post_thumbnail_id( $post->ID );
    if ( $featured_image_id ) {
        $featured_image_src = wp_get_attachment_image_src( $featured_image_id, 'full' );
        $featured_image_url = $featured_image_src ? $featured_image_src[0] : null;

        if ( $featured_image_url ) {
            $li_headers = array(
                'Authorization'             => 'Bearer ' . $mapping['access_token'],
                'Content-Type'              => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version'          => '202503',
            );

            $init_resp = wp_remote_post(
                'https://api.linkedin.com/rest/images?action=initializeUpload',
                array(
                    'headers' => $li_headers,
                    'body'    => wp_json_encode( array(
                        'initializeUploadRequest' => array( 'owner' => $author_urn ),
                    ) ),
                    'timeout' => 20,
                )
            );

            if ( ! is_wp_error( $init_resp ) && 200 === wp_remote_retrieve_response_code( $init_resp ) ) {
                $init_data = json_decode( wp_remote_retrieve_body( $init_resp ), true );
                if ( ! empty( $init_data['value']['uploadUrl'] ) && ! empty( $init_data['value']['image'] ) ) {
                    $img_resp = wp_remote_get( $featured_image_url, array( 'timeout' => 30 ) );
                    if ( ! is_wp_error( $img_resp ) ) {
                        $img_content_type = wp_remote_retrieve_header( $img_resp, 'content-type' ) ?: 'image/jpeg';
                        wp_remote_request( $init_data['value']['uploadUrl'], array(
                            'method'  => 'PUT',
                            'headers' => array(
                                'Authorization' => 'Bearer ' . $mapping['access_token'],
                                'Content-Type'  => $img_content_type,
                            ),
                            'body'    => wp_remote_retrieve_body( $img_resp ),
                            'timeout' => 60,
                        ) );
                        $li_image_urn = $init_data['value']['image'];
                    }
                }
            }
        }
    }

    // Only attach the article card when the URL is publicly reachable.
    // LinkedIn's servers must be able to crawl the URL — local/dev URLs will cause a 424 error.
    $parsed_host = wp_parse_url( $post_url, PHP_URL_HOST );
    $is_public   = $parsed_host && ! in_array( $parsed_host, array( 'localhost', '127.0.0.1', '::1' ), true )
        && ! preg_match( '/\.(local|test|ddev\.site|lndo\.site|localhost)$/', $parsed_host );

    if ( $is_public ) {
        $article = array(
            'source'      => $post_url,
            'title'       => $post->post_title,
            'description' => $commentary,
        );
        if ( $li_image_urn ) {
            $article['thumbnail'] = $li_image_urn;
        }
        $body['content'] = array( 'article' => $article );
    } elseif ( $li_image_urn ) {
        // Local site with an image: use media content type so the image shows.
        $body['content'] = array(
            'media' => array(
                'id'      => $li_image_urn,
                'altText' => $post->post_title,
            ),
        );
        $body['commentary'] .= "\n\n" . $post_url;
    } else {
        // Append the URL to the commentary so it's still visible.
        $body['commentary'] .= "\n\n" . $post_url;
    }

    $response = wp_remote_post( 'https://api.linkedin.com/rest/posts', array(
        'headers' => array(
            'Authorization'             => 'Bearer ' . $mapping['access_token'],
            'Content-Type'              => 'application/json',
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version'          => '202503',
        ),
        'body'    => wp_json_encode( $body ),
        'timeout' => 30,
    ) );

    if ( is_wp_error( $response ) ) {
        $msg = $response->get_error_message();
        post_forwarder_log_error( 'LinkedIn post failed (WP_Error): ' . $msg );
        return array( 'success' => false, 'message' => $msg );
    }

    $code     = wp_remote_retrieve_response_code( $response );
    $raw_body = wp_remote_retrieve_body( $response );

    if ( $code >= 200 && $code < 300 ) {
        $post_urn = wp_remote_retrieve_header( $response, 'x-restli-id' );
        if ( ! $post_urn ) {
            $resp_data = json_decode( $raw_body, true );
            $post_urn  = is_array( $resp_data ) && ! empty( $resp_data['id'] ) ? $resp_data['id'] : '';
        }
        if ( $post_urn ) {
            post_forwarder_log_error( 'LinkedIn post created: ' . $post_urn );
        }
        return array( 'success' => true, 'message' => __( 'Posted successfully.', 'post-forwarder' ), 'post_urn' => $post_urn );
    }

    post_forwarder_log_error( 'LinkedIn post failed (HTTP ' . $code . '): ' . $raw_body );
    $err_data = json_decode( $raw_body, true );
    $err_msg  = ( is_array( $err_data ) && ! empty( $err_data['message'] ) )
        ? $err_data['message']
        : 'HTTP ' . $code;
    return array( 'success' => false, 'message' => $err_msg );
}

// X (Twitter) post forwarding
function post_forwarder_forward_to_x( $post, $mapping, $portal_key ) {
    if ( empty( $mapping['access_token'] ) ) {
        post_forwarder_log_error( 'X post skipped: no access token stored.' );
        return array('success' => false, 'message' => __('No access token — please connect the account.', 'post-forwarder'));
    }

    // Refresh the access token when expired (X tokens last only 2 hours).
    if ( isset( $mapping['token_expires'] ) && $mapping['token_expires'] <= time() ) {
        if ( empty( $mapping['refresh_token'] ) ) {
            post_forwarder_log_error( 'X post skipped: token expired and no refresh token available.' );
            return array('success' => false, 'message' => __('Token expired and no refresh token — please reconnect.', 'post-forwarder'));
        }

        $relay_url = post_forwarder_relay_url();
        if ( ! $relay_url ) {
            post_forwarder_log_error( 'X post skipped: token expired but relay URL not configured for refresh.' );
            return array('success' => false, 'message' => __('Token expired and relay not configured — please reconnect.', 'post-forwarder'));
        }

        $refresh_response = wp_remote_post(
            $relay_url . '/x/refresh',
            array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( array( 'refresh_token' => $mapping['refresh_token'] ) ),
                'timeout' => 20,
            )
        );

        if ( is_wp_error( $refresh_response ) ) {
            post_forwarder_log_error( 'X token refresh failed: ' . $refresh_response->get_error_message() );
            return array('success' => false, 'message' => 'Token refresh failed: ' . $refresh_response->get_error_message());
        }

        $refresh_data = json_decode( wp_remote_retrieve_body( $refresh_response ), true );
        $refresh_code = wp_remote_retrieve_response_code( $refresh_response );

        if ( 200 !== $refresh_code || empty( $refresh_data['access_token'] ) ) {
            $err = is_array( $refresh_data ) && isset( $refresh_data['error'] ) ? $refresh_data['error'] : 'HTTP ' . $refresh_code;
            post_forwarder_log_error( 'X token refresh returned error: ' . $err );
            return array('success' => false, 'message' => 'Token refresh failed: ' . $err . ' — please reconnect.');
        }

        // Persist the new tokens into the stored options.
        $options = get_option( 'post_forwarding_options', array() );
        if ( is_string( $options ) ) {
            $options = json_decode( $options, true );
            if ( ! is_array( $options ) ) { $options = array(); }
        }
        $stored_mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
        if ( ! is_array( $stored_mappings ) ) {
            $stored_mappings = json_decode( is_string( $stored_mappings ) ? $stored_mappings : '{}', true );
            if ( ! is_array( $stored_mappings ) ) { $stored_mappings = array(); }
        }
        if ( isset( $stored_mappings[ $portal_key ] ) ) {
            $stored_mappings[ $portal_key ]['access_token']  = $refresh_data['access_token'];
            $stored_mappings[ $portal_key ]['token_expires'] = time() + (int) ( $refresh_data['expires_in'] ?? 7200 );
            if ( ! empty( $refresh_data['refresh_token'] ) ) {
                $stored_mappings[ $portal_key ]['refresh_token']         = $refresh_data['refresh_token'];
                $stored_mappings[ $portal_key ]['refresh_token_expires'] = time() + (int) ( $refresh_data['refresh_token_expires_in'] ?? 7776000 );
            }
            $options['mappings'] = wp_json_encode( $stored_mappings );
            update_option( 'post_forwarding_options', $options );
        }

        // Use the fresh token for this request.
        $mapping['access_token'] = $refresh_data['access_token'];
        post_forwarder_log_error( 'X token refreshed successfully for portal: ' . $portal_key );
    }

    $post_url = get_permalink( $post->ID );

    // Tweets are limited to 280 characters. t.co wraps all URLs to ~23 chars.
    // Reserve 25 chars for "\n\n" + URL placeholder; title gets the remaining 255.
    $max_title = 255;
    $title     = mb_strlen( $post->post_title ) > $max_title
        ? mb_substr( $post->post_title, 0, $max_title - 1 ) . '…'
        : $post->post_title;

    $tweet_text = $title . "\n\n" . $post_url;

    $x_post_args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $mapping['access_token'],
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode( array( 'text' => $tweet_text ) ),
        'timeout' => 30,
    );

    $response = wp_remote_post( 'https://api.twitter.com/2/tweets', $x_post_args );

    if ( is_wp_error( $response ) ) {
        $msg = $response->get_error_message();
        post_forwarder_log_error( 'X post failed (WP_Error): ' . $msg );
        return array('success' => false, 'message' => $msg);
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    // On 401, X may have invalidated the token early (before our local expiry).
    // Try to refresh immediately and retry once.
    if ( 401 === $code && ! empty( $mapping['refresh_token'] ) ) {
        $relay_url = post_forwarder_relay_url();
        if ( $relay_url ) {
            post_forwarder_log_error( 'X got 401 — attempting token refresh for portal: ' . $portal_key );
            $refresh_response = wp_remote_post(
                $relay_url . '/x/refresh',
                array(
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode( array( 'refresh_token' => $mapping['refresh_token'] ) ),
                    'timeout' => 20,
                )
            );
            if ( ! is_wp_error( $refresh_response ) ) {
                $refresh_data = json_decode( wp_remote_retrieve_body( $refresh_response ), true );
                $refresh_code = wp_remote_retrieve_response_code( $refresh_response );
                if ( 200 === $refresh_code && ! empty( $refresh_data['access_token'] ) ) {
                    // Persist new tokens.
                    $opts = get_option( 'post_forwarding_options', array() );
                    if ( is_string( $opts ) ) { $opts = json_decode( $opts, true ); }
                    if ( ! is_array( $opts ) ) { $opts = array(); }
                    $sm = isset( $opts['mappings'] ) ? $opts['mappings'] : array();
                    if ( ! is_array( $sm ) ) { $sm = json_decode( is_string( $sm ) ? $sm : '{}', true ); }
                    if ( ! is_array( $sm ) ) { $sm = array(); }
                    if ( isset( $sm[ $portal_key ] ) ) {
                        $sm[ $portal_key ]['access_token']  = $refresh_data['access_token'];
                        $sm[ $portal_key ]['token_expires'] = time() + (int) ( $refresh_data['expires_in'] ?? 7200 );
                        if ( ! empty( $refresh_data['refresh_token'] ) ) {
                            $sm[ $portal_key ]['refresh_token']         = $refresh_data['refresh_token'];
                            $sm[ $portal_key ]['refresh_token_expires'] = time() + (int) ( $refresh_data['refresh_token_expires_in'] ?? 7776000 );
                        }
                        $opts['mappings'] = wp_json_encode( $sm );
                        update_option( 'post_forwarding_options', $opts );
                    }
                    post_forwarder_log_error( 'X token refreshed on 401, retrying post for portal: ' . $portal_key );
                    $x_post_args['headers']['Authorization'] = 'Bearer ' . $refresh_data['access_token'];
                    $response = wp_remote_post( 'https://api.twitter.com/2/tweets', $x_post_args );
                    if ( ! is_wp_error( $response ) ) {
                        $code = wp_remote_retrieve_response_code( $response );
                        $body = wp_remote_retrieve_body( $response );
                    }
                } else {
                    $rf_err = is_array( $refresh_data ) && isset( $refresh_data['error'] ) ? $refresh_data['error'] : 'HTTP ' . $refresh_code;
                    post_forwarder_log_error( 'X token refresh on 401 failed: ' . $rf_err . ' — reconnect needed.' );
                    return array('success' => false, 'message' => 'Access denied (401) and token refresh failed (' . $rf_err . ') — please reconnect your X account.');
                }
            }
        }
    }

    if ( $code >= 200 && $code < 300 ) {
        $body_data = json_decode( $body, true );
        $tweet_id  = is_array( $body_data ) && isset( $body_data['data']['id'] ) ? $body_data['data']['id'] : '';
        $msg       = $tweet_id
            ? sprintf( 'Tweet posted: https://x.com/i/web/status/%s', $tweet_id )
            : __( 'Tweet posted successfully.', 'post-forwarder' );
        post_forwarder_log_error( 'X post succeeded (HTTP ' . $code . ')' . ( $tweet_id ? ': tweet ID ' . $tweet_id : '' ) );
        return array('success' => true, 'message' => $msg);
    }

    post_forwarder_log_error( 'X post failed (HTTP ' . $code . '): ' . $body );
    $err_data = json_decode( $body, true );
    if ( is_array( $err_data ) ) {
        // X API v2 error format: {"detail":"...","errors":[{"message":"..."}]}
        $err_msg = ! empty( $err_data['detail'] ) ? $err_data['detail']
            : ( ! empty( $err_data['errors'][0]['message'] ) ? $err_data['errors'][0]['message'] : 'HTTP ' . $code );
    } else {
        $err_msg = 'HTTP ' . $code;
    }
    return array('success' => false, 'message' => $err_msg);
}

// Helper function to upload and set featured image
function post_forwarder_set_featured_image($remote_post_id, $image_url, $target, $post_type = 'post') {
    // First, upload the image to the remote site
    $media_api_url = rtrim($target['url'], '/') . '/wp-json/wp/v2/media';
    $auth = base64_encode($target['user'] . ':' . $target['password']);

    // Download the image content
    $image_response = wp_remote_get($image_url, array('timeout' => 30));
    if (is_wp_error($image_response)) {
        return;
    }

    $image_data = wp_remote_retrieve_body($image_response);
    $image_content_type = wp_remote_retrieve_header($image_response, 'content-type');
    
    // Get filename from URL
    $filename = basename(wp_parse_url($image_url, PHP_URL_PATH));
    if (empty($filename) || strpos($filename, '.') === false) {
        $filename = 'featured-image.jpg';
    }

    // Upload image to remote site
    $boundary = wp_generate_password(24);
    $headers = array(
        'Authorization' => 'Basic ' . $auth,
        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        'Content-Disposition' => 'attachment; filename="' . $filename . '"'
    );

    $body = "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n";
    $body .= "Content-Type: $image_content_type\r\n\r\n";
    $body .= $image_data . "\r\n";
    $body .= "--$boundary--\r\n";

    $upload_response = wp_remote_post($media_api_url, array(
        'headers' => $headers,
        'body' => $body,
        'timeout' => 60
    ));

    $upload_code = wp_remote_retrieve_response_code($upload_response);
    $upload_body = wp_remote_retrieve_body($upload_response);

    if ($upload_code >= 200 && $upload_code < 300) {
        $uploaded_media = json_decode($upload_body, true);
        if (isset($uploaded_media['id'])) {
            $media_id = $uploaded_media['id'];

            // Now set it as featured image - construct the correct URL
            if ($post_type === 'post') {
                $post_update_url = rtrim($target['url'], '/') . '/wp-json/wp/v2/posts/' . $remote_post_id;
            } else {
                $post_update_url = rtrim($target['url'], '/') . '/wp-json/wp/v2/' . $post_type . '/' . $remote_post_id;
            }
            
            $update_response = wp_remote_request($post_update_url, array(
                'method' => 'PUT',
                'headers' => array(
                    'Authorization' => 'Basic ' . $auth,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(array('featured_media' => $media_id)),
                'timeout' => 30
            ));

            $update_code = wp_remote_retrieve_response_code($update_response);
            
            if ($update_code < 200 || $update_code >= 300) {
                // Try alternative method - POST to the same endpoint
                wp_remote_post($post_update_url, array(
                    'headers' => array(
                        'Authorization' => 'Basic ' . $auth,
                        'Content-Type' => 'application/json',
                    ),
                    'body' => wp_json_encode(array('featured_media' => $media_id)),
                    'timeout' => 30
                ));
            }
        }
    }
}

// Meta (Facebook + Instagram) post forwarding.
function post_forwarder_forward_to_meta( $post, $mapping ) {
    if ( empty( $mapping['access_token'] ) || empty( $mapping['page_id'] ) ) {
        return array( 'success' => false, 'message' => __( 'Not connected — please authorize with Meta first.', 'post-forwarder' ) );
    }

    $page_token = $mapping['access_token'];
    $page_id    = $mapping['page_id'];
    $ig_id      = isset( $mapping['instagram_account_id'] ) ? $mapping['instagram_account_id'] : '';
    $post_url   = get_permalink( $post->ID );
    $message    = ! empty( $post->post_excerpt )
        ? $post->post_excerpt
        : wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 );

    // Determine if site URL is publicly reachable by Meta's servers.
    $parsed_host = wp_parse_url( $post_url, PHP_URL_HOST );
    $is_public   = $parsed_host
        && ! in_array( $parsed_host, array( 'localhost', '127.0.0.1', '::1' ), true )
        && ! preg_match( '/\.(local|test|ddev\.site|lndo\.site|localhost)$/', $parsed_host );

    // Upload featured image to Facebook if present.
    $fb_photo_id = null;
    $img_url     = null;
    $thumb_id    = get_post_thumbnail_id( $post->ID );
    if ( $thumb_id ) {
        $img_src = wp_get_attachment_image_src( $thumb_id, 'full' );
        $img_url = $img_src ? $img_src[0] : null;
    }

    $results = array();

    // ── Facebook Page post ──────────────────────────────────────────────────
    if ( ! empty( $mapping['post_to_facebook'] ) ) {
        $fb_body = array( 'access_token' => $page_token );

        if ( $img_url ) {
            // Upload photo unpublished first, then attach to feed post.
            $img_resp = wp_remote_get( $img_url, array( 'timeout' => 30 ) );
            if ( ! is_wp_error( $img_resp ) ) {
                $boundary    = wp_generate_password( 24, false );
                $img_data    = wp_remote_retrieve_body( $img_resp );
                $img_ct      = wp_remote_retrieve_header( $img_resp, 'content-type' ) ?: 'image/jpeg';
                $img_filename = basename( wp_parse_url( $img_url, PHP_URL_PATH ) ) ?: 'image.jpg';

                $mp_body  = "--{$boundary}\r\n";
                $mp_body .= "Content-Disposition: form-data; name=\"source\"; filename=\"{$img_filename}\"\r\n";
                $mp_body .= "Content-Type: {$img_ct}\r\n\r\n";
                $mp_body .= $img_data . "\r\n";
                $mp_body .= "--{$boundary}\r\n";
                $mp_body .= "Content-Disposition: form-data; name=\"published\"\r\n\r\nfalse\r\n";
                $mp_body .= "--{$boundary}\r\n";
                $mp_body .= "Content-Disposition: form-data; name=\"access_token\"\r\n\r\n{$page_token}\r\n";
                $mp_body .= "--{$boundary}--\r\n";

                $photo_resp = wp_remote_post( "https://graph.facebook.com/v21.0/{$page_id}/photos", array(
                    'headers' => array( 'Content-Type' => "multipart/form-data; boundary={$boundary}" ),
                    'body'    => $mp_body,
                    'timeout' => 60,
                ) );
                $photo_code = wp_remote_retrieve_response_code( $photo_resp );
                if ( ! is_wp_error( $photo_resp ) && $photo_code >= 200 && $photo_code < 300 ) {
                    $photo_data  = json_decode( wp_remote_retrieve_body( $photo_resp ), true );
                    $fb_photo_id = $photo_data['id'] ?? null;
                } else {
                    post_forwarder_log_error( 'Facebook photo upload failed (' . ( is_wp_error( $photo_resp ) ? $photo_resp->get_error_message() : $photo_code ) . '): ' . ( is_wp_error( $photo_resp ) ? '' : wp_remote_retrieve_body( $photo_resp ) ) );
                }
            }
        }

        if ( $fb_photo_id ) {
            $fb_body['message']                      = $message . ( ! $is_public ? "\n\n" . $post_url : '' );
            $fb_body['attached_media[0][media_fbid]'] = $fb_photo_id;
            if ( $is_public ) { $fb_body['link'] = $post_url; }
        } elseif ( $is_public ) {
            $fb_body['message'] = $message;
            $fb_body['link']    = $post_url;
        } else {
            $fb_body['message'] = $message . "\n\n" . $post_url;
        }

        $fb_resp = wp_remote_post( "https://graph.facebook.com/v21.0/{$page_id}/feed", array(
            'body'    => $fb_body,
            'timeout' => 30,
        ) );

        if ( is_wp_error( $fb_resp ) ) {
            $results['facebook'] = array( 'success' => false, 'message' => 'Facebook: ' . $fb_resp->get_error_message() );
        } else {
            $fb_code = wp_remote_retrieve_response_code( $fb_resp );
            if ( $fb_code >= 200 && $fb_code < 300 ) {
                $fb_data = json_decode( wp_remote_retrieve_body( $fb_resp ), true );
                $fb_post_id = $fb_data['id'] ?? '';
                $results['facebook'] = array( 'success' => true, 'message' => 'Facebook: ' . __( 'Posted successfully.', 'post-forwarder' ) );
            } else {
                $fb_err = json_decode( wp_remote_retrieve_body( $fb_resp ), true );
                $fb_msg = ( is_array( $fb_err ) && ! empty( $fb_err['error']['message'] ) ) ? $fb_err['error']['message'] : 'HTTP ' . $fb_code;
                post_forwarder_log_error( 'Facebook post failed (' . $fb_code . '): ' . wp_remote_retrieve_body( $fb_resp ) );
                $results['facebook'] = array( 'success' => false, 'message' => 'Facebook: ' . $fb_msg );
            }
        }
    }

    // ── Instagram post ──────────────────────────────────────────────────────
    if ( ! empty( $mapping['post_to_instagram'] ) && $ig_id ) {
        if ( ! $img_url ) {
            $results['instagram'] = array( 'success' => false, 'message' => 'Instagram: ' . __( 'No featured image — Instagram requires an image.', 'post-forwarder' ) );
        } elseif ( ! $is_public ) {
            $results['instagram'] = array( 'success' => false, 'message' => 'Instagram: ' . __( 'Image URL not publicly accessible — Instagram must be able to download it.', 'post-forwarder' ) );
        } else {
            $caption = $message . "\n\n" . $post_url;

            // Step 1: Create media container.
            $ig_container_resp = wp_remote_post( "https://graph.facebook.com/v21.0/{$ig_id}/media", array(
                'body'    => array(
                    'image_url'    => $img_url,
                    'caption'      => $caption,
                    'access_token' => $page_token,
                ),
                'timeout' => 30,
            ) );

            if ( is_wp_error( $ig_container_resp ) || wp_remote_retrieve_response_code( $ig_container_resp ) !== 200 ) {
                $ig_err_body = is_wp_error( $ig_container_resp ) ? $ig_container_resp->get_error_message() : wp_remote_retrieve_body( $ig_container_resp );
                $ig_err      = json_decode( $ig_err_body, true );
                $ig_msg      = ( is_array( $ig_err ) && ! empty( $ig_err['error']['message'] ) ) ? $ig_err['error']['message'] : $ig_err_body;
                post_forwarder_log_error( 'Instagram container failed: ' . $ig_err_body );
                $results['instagram'] = array( 'success' => false, 'message' => 'Instagram: ' . $ig_msg );
            } else {
                $ig_creation_id = json_decode( wp_remote_retrieve_body( $ig_container_resp ), true )['id'] ?? '';

                // Step 2: Publish container.
                $ig_pub_resp = wp_remote_post( "https://graph.facebook.com/v21.0/{$ig_id}/media_publish", array(
                    'body'    => array(
                        'creation_id'  => $ig_creation_id,
                        'access_token' => $page_token,
                    ),
                    'timeout' => 30,
                ) );

                if ( is_wp_error( $ig_pub_resp ) || wp_remote_retrieve_response_code( $ig_pub_resp ) !== 200 ) {
                    $ig_pub_err = is_wp_error( $ig_pub_resp ) ? $ig_pub_resp->get_error_message() : wp_remote_retrieve_body( $ig_pub_resp );
                    post_forwarder_log_error( 'Instagram publish failed: ' . $ig_pub_err );
                    $results['instagram'] = array( 'success' => false, 'message' => 'Instagram: ' . __( 'Publish step failed — check debug log.', 'post-forwarder' ) );
                } else {
                    $results['instagram'] = array( 'success' => true, 'message' => 'Instagram: ' . __( 'Posted successfully.', 'post-forwarder' ) );
                }
            }
        }
    }

    if ( empty( $results ) ) {
        return array( 'success' => false, 'message' => __( 'No platforms selected for Meta portal.', 'post-forwarder' ) );
    }

    $all_success = ! in_array( false, array_column( $results, 'success' ), true );
    $any_success = in_array( true,  array_column( $results, 'success' ), true );
    $combined    = implode( ' | ', array_column( $results, 'message' ) );

    return array(
        'success'  => $any_success,
        'message'  => $combined,
        'fb_only'  => isset( $results['facebook'] )  ? $results['facebook']['success']  : null,
        'ig_only'  => isset( $results['instagram'] ) ? $results['instagram']['success'] : null,
    );
}

/**
 * Forward a post to specific channel keys (used by the schedule cron).
 * Bypasses the save_post hook and duplicate-prevention transients.
 *
 * @param int   $post_id
 * @param array $channel_keys Array of portal keys to forward to.
 * @return array Per-channel results.
 */
function post_forwarder_schedule_forward( $post_id, array $channel_keys ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return array();
    }

    $options      = get_option( 'post_forwarding_options', array() );
    $mappings_raw = isset( $options['mappings'] ) ? $options['mappings'] : '{}';
    $mappings     = json_decode( is_string( $mappings_raw ) ? $mappings_raw : '{}', true );
    if ( ! is_array( $mappings ) ) {
        $mappings = array();
    }

    $featured_image_url = null;
    $thumb_id           = get_post_thumbnail_id( $post_id );
    if ( $thumb_id ) {
        $src                = wp_get_attachment_image_src( $thumb_id, 'full' );
        $featured_image_url = $src ? $src[0] : null;
    }

    $results = array();
    foreach ( $channel_keys as $key ) {
        if ( ! isset( $mappings[ $key ] ) ) {
            continue;
        }
        $mapping = $mappings[ $key ];
        $type    = isset( $mapping['type'] ) ? $mapping['type'] : '';

        if ( 'linkedin' === $type ) {
            $results[ $key ] = post_forwarder_forward_to_linkedin( $post, $mapping );
        } elseif ( 'x' === $type ) {
            $results[ $key ] = post_forwarder_forward_to_x( $post, $mapping, $key );
        } elseif ( 'meta' === $type ) {
            $results[ $key ] = post_forwarder_forward_to_meta( $post, $mapping );
        } elseif ( 'wordpress' === $type ) {
            // WP-to-WP forwarding reuses the full forward flow with a direct call.
            $xproducts_backup = get_post_meta( $post_id, 'product', false );
            update_post_meta( $post_id, 'product', $key );
            $results[ $key ] = array( 'success' => true, 'message' => 'Queued for WP forwarding' );
            // Restore original.
            delete_post_meta( $post_id, 'product' );
            foreach ( $xproducts_backup as $v ) {
                add_post_meta( $post_id, 'product', $v );
            }
        }
    }
    return $results;
}

// Forward post after import
function post_forward_post($post_id) {
    // More robust duplicate prevention
    $processing_key = 'post_forwarding_processing_' . $post_id;
    $lock_key = 'post_forwarding_lock_' . $post_id;
    
    // Check if we're already processing this post
    if (get_transient($processing_key)) {
        return;
    }
    
    // Try to acquire a lock - if it fails, another process is already working on it
    if (get_transient($lock_key)) {
        return;
    }
    
    // Set both the processing flag and lock (lock expires faster)
    set_transient($lock_key, true, 30); // 30 seconds lock
    set_transient($processing_key, true, 120); // 2 minutes processing flag

    // Additional check - has this post been forwarded recently?
    $recent_forward_key = 'post_forwarded_' . $post_id;
    if (get_transient($recent_forward_key)) {
        delete_transient($lock_key);
        delete_transient($processing_key);
        return;
    }

    if (defined('WP_IMPORTING')) {
        delete_transient($lock_key);
        delete_transient($processing_key);
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        delete_transient($lock_key);
        delete_transient($processing_key);
        return;
    }

    $options = get_option('post_forwarding_options', array());
    if (empty($options['enabled'])) {
        delete_transient($lock_key);
        delete_transient($processing_key);
        return;
    }

    $xproducts = get_post_meta($post_id, 'product', false);
    if (empty($xproducts)) {
        delete_transient($lock_key);
        delete_transient($processing_key);
        return;
    }

    $mappings_json = isset($options['mappings']) ? $options['mappings'] : '';
    $mappings = json_decode($mappings_json, true);
    $post = get_post($post_id);
    if (!$post) {
        delete_transient($lock_key);
        delete_transient($processing_key);
        return;
    }

    // Get the original post type
    $original_post_type = $post->post_type;

    // Get ALL taxonomies for this post type
    $all_taxonomies = get_object_taxonomies($original_post_type, 'objects');
    $taxonomy_data = array();
    $fallback_tags = array(); // Collect all terms as fallback tags
    
    foreach ($all_taxonomies as $taxonomy_name => $taxonomy_object) {
        // Get terms with IDs first (for exact matching when taxonomy exists)
        $term_ids = wp_get_object_terms($post_id, $taxonomy_name, array('fields' => 'ids'));
        
        // Get term objects to get names and slugs for fallback
        $terms = wp_get_object_terms($post_id, $taxonomy_name, array('fields' => 'all'));
        
        if (!empty($term_ids) && !is_wp_error($term_ids)) {
            // Store both IDs and term details
            $taxonomy_data[$taxonomy_name] = array(
                'ids' => $term_ids,
                'terms' => array()
            );
            
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $taxonomy_data[$taxonomy_name]['terms'][] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug
                    );
                    
                    // Add to fallback tags collection
                    $fallback_tags[] = $term->name; // Use name for better readability
                    $fallback_tags[] = $term->slug; // Also include slug as alternative
                }
            }
        }
    }

    // Remove duplicates from fallback tags
    $fallback_tags = array_unique(array_filter($fallback_tags));

    // Get featured image/thumbnail
    $featured_image_id = get_post_thumbnail_id($post_id);
    $featured_image_url = null;
    if ($featured_image_id) {
        $featured_image_url = wp_get_attachment_image_src($featured_image_id, 'full');
        $featured_image_url = $featured_image_url ? $featured_image_url[0] : null;
    }

    // Get all meta fields
    $meta = get_post_meta($post_id, '', true);
    
    // Remove WordPress internal meta keys and product meta
    $reserved = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', 'product', '_thumbnail_id');
    foreach ($reserved as $key) {
        unset($meta[$key]);
    }

    // Get ACF fields separately and include them in meta
    $acf_fields = array();
    if (function_exists('get_fields')) {
        $acf_fields = get_fields($post_id);
        if (!$acf_fields) $acf_fields = array();
    }

    // Flatten remaining meta array
    $meta_flattened = array();
    foreach ($meta as $key => $value) {
        // Skip ACF-related meta keys to avoid duplication
        if (strpos($key, 'field_') === 0 || strpos($key, '_field_') === 0) {
            continue;
        }
        
        if (is_array($value) && count($value) === 1) {
            $meta_flattened[$key] = $value[0];
        } elseif (!empty($value)) {
            $meta_flattened[$key] = $value;
        }
    }

    // Add ACF fields to meta (more reliable than separate acf parameter)
    if (!empty($acf_fields)) {
        foreach ($acf_fields as $field_key => $field_value) {
            $meta_flattened[$field_key] = $field_value;
        }
    }

    $forwarding_successful = false;
    $successful_portals    = array();
    $portal_results        = array();

    // Loop through each selected product and send to corresponding portal
    foreach ($xproducts as $xproduct) {
        if (!isset($mappings[$xproduct])) {
            continue;
        }

        $target      = $mappings[$xproduct];
        $target_type = isset($target['type']) ? $target['type'] : 'wordpress';
        $portal_name = isset($target['name']) ? $target['name'] : $xproduct;

        // Route to LinkedIn if this is a LinkedIn account
        if ($target_type === 'linkedin') {
            $result = post_forwarder_forward_to_linkedin($post, $target);
            $portal_results[$xproduct] = array(
                'name'     => $portal_name,
                'type'     => 'linkedin',
                'success'  => $result['success'],
                'message'  => $result['message'],
                'post_urn' => isset( $result['post_urn'] ) ? $result['post_urn'] : '',
            );
            if ($result['success']) {
                $forwarding_successful = true;
                $successful_portals[]  = $xproduct;
            }
            continue;
        }

        // Route to Meta (Facebook + Instagram).
        if ( $target_type === 'meta' ) {
            $result = post_forwarder_forward_to_meta( $post, $target );
            $portal_results[$xproduct] = array(
                'name'    => $portal_name,
                'type'    => 'meta',
                'success' => $result['success'],
                'message' => $result['message'],
            );
            if ( $result['success'] ) {
                $forwarding_successful = true;
                $successful_portals[]  = $xproduct;
            }
            continue;
        }

        // Route to X (Twitter) if this is an X account
        if ( $target_type === 'x' ) {
            $result = post_forwarder_forward_to_x( $post, $target, $xproduct );
            $portal_results[$xproduct] = array(
                'name'    => $portal_name,
                'type'    => 'x',
                'success' => $result['success'],
                'message' => $result['message'],
            );
            if ( $result['success'] ) {
                $forwarding_successful = true;
                $successful_portals[]  = $xproduct;
            }
            continue;
        }

        // WordPress portal forwarding.
        // Use wp_site_url (stored during OAuth connect) for the REST API base — this is the
        // real WordPress install path, which may differ from the home URL in subdirectory setups.
        $wp_api_base = ! empty( $target['wp_site_url'] )
            ? rtrim( $target['wp_site_url'], '/' )
            : rtrim( $target['url'], '/' );

        if ($original_post_type === 'post') {
            $api_url = $wp_api_base . '/wp-json/wp/v2/posts';
        } else {
            $api_url = $wp_api_base . '/wp-json/wp/v2/' . $original_post_type;
        }

        post_forwarder_log_error( 'WordPress forward starting: post ' . $post_id . ' → ' . $api_url );

        $auth = base64_encode($target['user'] . ':' . $target['password']);

        // Try with term slugs first (better chance of matching existing terms)
        $success = post_forward_attempt_with_term_slugs($post, $api_url, $auth, $taxonomy_data, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $xproduct);

        if (!$success) {
            // If that fails, try with just tags as fallback
            $success = post_forward_attempt_with_fallback_tags($post, $api_url, $auth, $fallback_tags, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $xproduct);
        }

        $portal_results[$xproduct] = array(
            'name'    => $portal_name,
            'type'    => 'wordpress',
            'success' => $success,
            'message' => $success
                ? sprintf( __( 'Post forwarded to %s', 'post-forwarder' ), wp_parse_url( $target['url'], PHP_URL_HOST ) )
                : __( 'Forwarding failed — check the debug log for details.', 'post-forwarder' ),
        );

        if ($success) {
            $forwarding_successful = true;
            $successful_portals[] = $xproduct;
        }
    }

    // Set the "recently forwarded" flag ONLY after processing ALL portals
    if ($forwarding_successful) {
        set_transient($recent_forward_key, true, 300); // 5 minutes
    }

    // Store per-portal results for display on the post edit screen after redirect.
    if ( ! empty( $portal_results ) ) {
        set_transient( 'pf_forward_results_' . get_current_user_id() . '_' . $post_id, $portal_results, 120 );
    }

    // Clean up the transients at the end
    delete_transient($lock_key);
    delete_transient($processing_key);
}

// Resolve term slugs to integer IDs on the target WordPress site.
// The WP REST API requires integer term IDs for categories/tags, not slugs.
function post_forwarder_resolve_term_ids( $wp_api_base, $auth, $rest_endpoint, $slugs, $sslverify ) {
    if ( empty( $slugs ) ) {
        return array();
    }
    $response = wp_remote_get(
        rtrim( $wp_api_base, '/' ) . '/wp-json/wp/v2/' . $rest_endpoint . '?' . http_build_query( array( 'slug' => $slugs, 'per_page' => 100 ) ),
        array(
            'headers'   => array( 'Authorization' => 'Basic ' . $auth ),
            'timeout'   => 10,
            'sslverify' => $sslverify,
        )
    );
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return array();
    }
    $terms = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $terms ) ) {
        return array();
    }
    $ids = array();
    foreach ( $terms as $term ) {
        if ( isset( $term['id'] ) ) {
            $ids[] = (int) $term['id'];
        }
    }
    return $ids;
}

// Helper function to attempt forwarding with term slugs
function post_forward_attempt_with_term_slugs($post, $api_url, $auth, $taxonomy_data, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $product_key) {
    $post_status = isset($options['post_status']) ? $options['post_status'] : 'draft';
    $body = array(
        'title'   => $post->post_title,
        'content' => $post->post_content,
        'excerpt' => $post->post_excerpt,
        'status'  => $post_status
    );

    // Resolve term slugs to integer IDs on the target site (WP REST API requires integers).
    $sslverify  = apply_filters( 'post_forwarder_sslverify', true );
    $wp_api_base = ! empty( $target['wp_site_url'] ) ? rtrim( $target['wp_site_url'], '/' ) : rtrim( $target['url'], '/' );

    foreach ($taxonomy_data as $taxonomy_name => $taxonomy_info) {
        if (!isset($taxonomy_info['terms']) || empty($taxonomy_info['terms'])) {
            continue;
        }

        $term_slugs = array();
        foreach ($taxonomy_info['terms'] as $term) {
            $term_slugs[] = $term['slug'];
        }

        if ( $taxonomy_name === 'category' ) {
            $ids = post_forwarder_resolve_term_ids( $wp_api_base, $auth, 'categories', $term_slugs, $sslverify );
            if ( ! empty( $ids ) ) {
                $body['categories'] = $ids;
            }
        } elseif ( $taxonomy_name === 'post_tag' || $taxonomy_name === 'custom-tag' ) {
            $ids = post_forwarder_resolve_term_ids( $wp_api_base, $auth, 'tags', $term_slugs, $sslverify );
            if ( ! empty( $ids ) ) {
                $body['tags'] = isset( $body['tags'] )
                    ? array_values( array_unique( array_merge( $body['tags'], $ids ) ) )
                    : $ids;
            }
        } else {
            // Custom taxonomy — look up via its own REST endpoint (slug = taxonomy name).
            $ids = post_forwarder_resolve_term_ids( $wp_api_base, $auth, sanitize_key( $taxonomy_name ), $term_slugs, $sslverify );
            if ( ! empty( $ids ) ) {
                $body[ $taxonomy_name ] = $ids;
            }
        }
    }

    // Add meta if there are any
    if (!empty($meta_flattened)) {
        $body['meta'] = $meta_flattened;
    }

    $request_args = array(
        'headers'   => array(
            'Authorization' => 'Basic ' . $auth,
            'Content-Type'  => 'application/json',
        ),
        'body'      => wp_json_encode( $body ),
        'timeout'   => 30,
        'sslverify' => apply_filters( 'post_forwarder_sslverify', true ),
    );

    $response = wp_remote_post( $api_url, $request_args );

    if ( is_wp_error( $response ) ) {
        post_forwarder_log_error( 'WordPress forward failed (WP_Error): ' . $response->get_error_message() . ' — URL: ' . $api_url );
        return false;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    // If custom post type endpoint returns 404, try with the posts endpoint.
    if ( $response_code === 404 && $original_post_type !== 'post' ) {
        $wp_api_base    = ! empty( $target['wp_site_url'] ) ? rtrim( $target['wp_site_url'], '/' ) : rtrim( $target['url'], '/' );
        $fallback_url   = $wp_api_base . '/wp-json/wp/v2/posts';
        $body_with_type = $body;
        $body_with_type['type'] = $original_post_type;

        $response = wp_remote_post( $fallback_url, array_merge( $request_args, array( 'body' => wp_json_encode( $body_with_type ) ) ) );

        if ( is_wp_error( $response ) ) {
            post_forwarder_log_error( 'WordPress forward fallback failed (WP_Error): ' . $response->get_error_message() );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $api_url       = $fallback_url;
    }

    if ( $response_code >= 200 && $response_code < 300 ) {
        post_forwarder_log_error( 'WordPress forward succeeded (HTTP ' . $response_code . '): ' . $api_url );
        if ( $featured_image_url ) {
            $created_post = json_decode( $response_body, true );
            if ( isset( $created_post['id'] ) ) {
                post_forwarder_set_featured_image( $created_post['id'], $featured_image_url, $target, $original_post_type );
            }
        }
        return true;
    }

    post_forwarder_log_error( 'WordPress forward failed (HTTP ' . $response_code . '): ' . substr( $response_body, 0, 300 ) . ' — URL: ' . $api_url );
    return false;
}

// Helper function to attempt forwarding with fallback tags only
function post_forward_attempt_with_fallback_tags($post, $api_url, $auth, $fallback_tags, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $product_key) {
    $post_status = isset($options['post_status']) ? $options['post_status'] : 'draft';
    $body = array(
        'title'   => $post->post_title,
        'content' => $post->post_content,
        'excerpt' => $post->post_excerpt,
        'status'  => $post_status
    );

    // Taxonomy is intentionally omitted in the fallback — the primary attempt already tried
    // resolving term IDs and failed for a different reason, so keep this request minimal.

    // Add meta if there are any
    if (!empty($meta_flattened)) {
        $body['meta'] = $meta_flattened;
    }

    $request_args = array(
        'headers'   => array(
            'Authorization' => 'Basic ' . $auth,
            'Content-Type'  => 'application/json',
        ),
        'body'      => wp_json_encode( $body ),
        'timeout'   => 30,
        'sslverify' => apply_filters( 'post_forwarder_sslverify', true ),
    );

    $response = wp_remote_post( $api_url, $request_args );

    if ( is_wp_error( $response ) ) {
        post_forwarder_log_error( 'WordPress forward (fallback tags) failed (WP_Error): ' . $response->get_error_message() . ' — URL: ' . $api_url );
        return false;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    if ( $response_code === 404 && $original_post_type !== 'post' ) {
        $wp_api_base    = ! empty( $target['wp_site_url'] ) ? rtrim( $target['wp_site_url'], '/' ) : rtrim( $target['url'], '/' );
        $fallback_url   = $wp_api_base . '/wp-json/wp/v2/posts';
        $body_with_type = $body;
        $body_with_type['type'] = $original_post_type;

        $response = wp_remote_post( $fallback_url, array_merge( $request_args, array( 'body' => wp_json_encode( $body_with_type ) ) ) );

        if ( is_wp_error( $response ) ) {
            post_forwarder_log_error( 'WordPress forward (fallback tags/CPT) failed (WP_Error): ' . $response->get_error_message() );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $api_url       = $fallback_url;
    }

    if ( $response_code >= 200 && $response_code < 300 ) {
        post_forwarder_log_error( 'WordPress forward (fallback tags) succeeded (HTTP ' . $response_code . '): ' . $api_url );
        if ( $featured_image_url ) {
            $created_post = json_decode( $response_body, true );
            if ( isset( $created_post['id'] ) ) {
                post_forwarder_set_featured_image( $created_post['id'], $featured_image_url, $target, $original_post_type );
            }
        }
        return true;
    }

    post_forwarder_log_error( 'WordPress forward (fallback tags) failed (HTTP ' . $response_code . '): ' . substr( $response_body, 0, 300 ) . ' — URL: ' . $api_url );
    return false;
}

add_action('save_post', 'post_forward_post', 20, 1);

// Plugin activation hook
register_activation_hook(__FILE__, function() {
    add_option('post_forwarding_options', array(
        'enabled'     => false,
        'post_status' => 'draft',
        'mappings'    => '{}',
    ));
    post_forwarder_create_schedule_table();
    if ( ! wp_next_scheduled( 'post_forwarder_run_schedule' ) ) {
        wp_schedule_event( time(), 'pf_every_minute', 'post_forwarder_run_schedule' );
    }
});

// Ensure the schedule table exists on every load if it hasn't been created yet.
add_action( 'plugins_loaded', function () {
    if ( get_option( 'pf_schedule_db_version' ) !== '1.0' ) {
        post_forwarder_create_schedule_table();
    }
    if ( ! wp_next_scheduled( 'post_forwarder_run_schedule' ) ) {
        wp_schedule_event( time(), 'pf_every_minute', 'post_forwarder_run_schedule' );
    }
} );

// Plugin deactivation hook
register_deactivation_hook(__FILE__, function() {
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
    $recent_posts = get_posts(array(
        'numberposts' => 100,
        'post_status' => array('publish', 'draft', 'private'),
        'fields' => 'ids'
    ));
    
    foreach ($recent_posts as $post_id) {
        delete_transient('post_forwarding_processing_' . $post_id);
        delete_transient('post_forwarding_lock_' . $post_id);
        delete_transient('post_forwarded_' . $post_id);
    }

    wp_clear_scheduled_hook( 'post_forwarder_run_schedule' );
});
