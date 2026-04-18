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

// Register settings
add_action('admin_menu', function () {
    add_options_page(
        __('Post Forwarding', 'post-forwarder'),
        __('Post Forwarding', 'post-forwarder'),
        'manage_options',
        'post-forwarding',
        'post_forwarding_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('post_forwarding', 'post_forwarding_options', array(
        'sanitize_callback' => 'post_forwarding_sanitize_options'
    ));
});

// Sanitize options
function post_forwarding_sanitize_options($input) {
    $sanitized = array();
    
    if (isset($input['enabled'])) {
        $sanitized['enabled'] = (bool) $input['enabled'];
    }
    
    if (isset($input['post_status'])) {
        $sanitized['post_status'] = in_array($input['post_status'], array('publish', 'draft')) ? $input['post_status'] : 'draft';
    }
    
    if (isset($input['mappings'])) {
        // Validate JSON
        $mappings = json_decode($input['mappings'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($mappings)) {
            $sanitized['mappings'] = wp_json_encode($mappings);
        } else {
            add_settings_error('post_forwarding_options', 'invalid_json', __('Invalid JSON format in mappings.', 'post-forwarder'));
            $sanitized['mappings'] = '{}';
        }
    }
    
    return $sanitized;
}

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
            } else {
                $portal_url   = isset($mapping['url']) ? $mapping['url'] : '';
                $display_name = esc_html($portal_name . ($portal_url ? ' (' . wp_parse_url($portal_url, PHP_URL_HOST) . ')' : ''));
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

    // Handle LinkedIn OAuth callback.
    $linkedin_oauth_notice = '';
    if ( isset( $_GET['linkedin_oauth_callback'] ) ) {
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
                        $redirect_uri = admin_url( 'options-general.php?page=post-forwarding&linkedin_oauth_callback=1' );
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

    // Handle form submission for the new interface
    if (isset($_POST['submit_portals']) && isset($_POST['portals_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['portals_nonce'])), 'save_portals')) {
        $portals = array();
        $existing_mappings_for_save = isset($options['mappings']) ? $options['mappings'] : array();
        if (!is_array($existing_mappings_for_save)) {
            $mappings_json = is_string($existing_mappings_for_save) ? $existing_mappings_for_save : '{}';
            $existing_mappings_for_save = json_decode($mappings_json, true);
            if (!is_array($existing_mappings_for_save)) $existing_mappings_for_save = array();
        }

        if (isset($_POST['portals']) && is_array($_POST['portals'])) {
            $portals_raw = wp_unslash($_POST['portals']);

            foreach ($portals_raw as $index => $portal) {
                if (!is_array($portal) || empty($portal['key']) || empty($portal['name'])) {
                    continue;
                }
                $key  = sanitize_key($portal['key']);
                $type = isset($portal['type']) && $portal['type'] === 'linkedin' ? 'linkedin' : 'wordpress';

                if ($type === 'linkedin') {
                    $portals[$key] = array(
                        'type'          => 'linkedin',
                        'name'          => sanitize_text_field($portal['name']),
                        'client_id'     => sanitize_text_field(isset($portal['client_id'])     ? $portal['client_id']     : ''),
                        'client_secret' => sanitize_text_field(isset($portal['client_secret']) ? $portal['client_secret'] : ''),
                        'author_urn'    => sanitize_text_field(isset($portal['author_urn'])    ? $portal['author_urn']    : ''),
                    );
                    // Preserve OAuth tokens and derived fields — never overwrite via form submission.
                    foreach ( array( 'access_token', 'refresh_token', 'token_expires', 'refresh_token_expires', 'person_urn', 'last_error' ) as $token_field ) {
                        if ( isset( $existing_mappings_for_save[ $key ][ $token_field ] ) ) {
                            $portals[ $key ][ $token_field ] = $existing_mappings_for_save[ $key ][ $token_field ];
                        }
                    }
                } else {
                    if (empty($portal['url'])) {
                        continue;
                    }
                    $portals[$key] = array(
                        'type'     => 'wordpress',
                        'name'     => sanitize_text_field($portal['name']),
                        'url'      => esc_url_raw($portal['url']),
                        'user'     => sanitize_text_field(isset($portal['user'])     ? $portal['user']     : ''),
                        'password' => sanitize_text_field(isset($portal['password']) ? $portal['password'] : ''),
                    );
                }
            }
        }

        $options['mappings'] = wp_json_encode($portals);
        update_option('post_forwarding_options', $options);
        echo '<div class="notice notice-success"><p>' . esc_html__('Accounts saved successfully!', 'post-forwarder') . '</p></div>';
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
            </table>
            <?php submit_button(); ?>
        </form>

        <hr>

        <h2><?php esc_html_e('Portal Configuration', 'post-forwarder'); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('save_portals', 'portals_nonce'); ?>
            
            <div id="portals-container">
                <?php if ( $li_creds_from_constants ) : ?>
                <div style="background: #edf7ed; padding: 12px 15px; margin-bottom: 15px; border-left: 4px solid #00a32a;">
                    <p><strong>&#10003; <?php esc_html_e( 'LinkedIn app credentials loaded from PHP constants.', 'post-forwarder' ); ?></strong><br>
                    <?php esc_html_e( 'To create a LinkedIn account, set a Key and Name below, save, then click Connect with LinkedIn.', 'post-forwarder' ); ?></p>
                </div>
                <?php endif; ?>

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
                                <th><?php esc_html_e( 'User ID', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][user]" placeholder="1728" style="width: 100px;" /></td>
                            </tr>
                            <tr class="fields-wordpress">
                                <th><?php esc_html_e( 'App Password', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][password]" placeholder="xxxx-xxxx-xxxx-xxxx" style="width: 300px;" /></td>
                            </tr>
                            <?php if ( ! $li_creds_from_constants ) : ?>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Client ID', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][client_id]" placeholder="<?php esc_attr_e( 'LinkedIn App Client ID', 'post-forwarder' ); ?>" style="width: 300px;" /></td>
                            </tr>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Client Secret', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][client_secret]" placeholder="<?php esc_attr_e( 'LinkedIn App Client Secret', 'post-forwarder' ); ?>" style="width: 300px;" /></td>
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
                                <td><span style="color:#666;"><?php esc_html_e( 'Save the account first, then click Connect with LinkedIn.', 'post-forwarder' ); ?></span></td>
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
                        $is_linkedin  = ($mapping_type === 'linkedin');
                        $is_connected   = $is_linkedin
                            && ! empty( $mapping['access_token'] )
                            && ( ! isset( $mapping['token_expires'] ) || $mapping['token_expires'] > time() );
                        $has_credentials = $li_creds_from_constants || ! empty( $mapping['client_id'] );
                        $oauth_redirect  = admin_url( 'options-general.php?page=post-forwarding&linkedin_oauth_callback=1' );
                        $oauth_state     = $key . '|' . wp_create_nonce( 'linkedin_oauth_' . $key );
                        $oauth_url       = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query( array(
                            'response_type' => 'code',
                            'client_id'     => post_forwarder_linkedin_client_id( isset( $mapping['client_id'] ) ? $mapping['client_id'] : '' ),
                            'redirect_uri'  => $oauth_redirect,
                            'state'         => $oauth_state,
                            'scope'         => 'w_member_social openid profile',
                        ) );
                        ?>
                        <div class="portal-row" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;" data-type="<?php echo esc_attr($mapping_type); ?>">
                            <h4>
                                <?php
                                /* translators: %d: Account number */
                                echo esc_html(sprintf(__('Account #%d', 'post-forwarder'), $i + 1));
                                ?>
                                <?php if ($is_linkedin): ?>
                                    <span style="background:#0a66c2;color:#fff;font-size:11px;padding:2px 7px;border-radius:3px;margin-left:8px;font-weight:normal;">LinkedIn</span>
                                <?php endif; ?>
                            </h4>
                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e('Account Type', 'post-forwarder'); ?></th>
                                    <td>
                                        <select name="portals[<?php echo esc_attr($i); ?>][type]" class="portal-type-select">
                                            <option value="wordpress" <?php selected($mapping_type, 'wordpress'); ?>><?php esc_html_e('WordPress Portal', 'post-forwarder'); ?></option>
                                            <option value="linkedin"  <?php selected($mapping_type, 'linkedin');   ?>><?php esc_html_e('LinkedIn Account',  'post-forwarder'); ?></option>
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
                                <tr class="fields-wordpress" <?php echo $is_linkedin ? 'style="display:none;"' : ''; ?>>
                                    <th><?php esc_html_e('URL', 'post-forwarder'); ?></th>
                                    <td><input type="url" name="portals[<?php echo esc_attr($i); ?>][url]" value="<?php echo esc_attr(isset($mapping['url']) ? $mapping['url'] : ''); ?>" style="width: 400px;" /></td>
                                </tr>
                                <tr class="fields-wordpress" <?php echo $is_linkedin ? 'style="display:none;"' : ''; ?>>
                                    <th><?php esc_html_e('User ID', 'post-forwarder'); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][user]" value="<?php echo esc_attr(isset($mapping['user']) ? $mapping['user'] : ''); ?>" style="width: 100px;" /></td>
                                </tr>
                                <tr class="fields-wordpress" <?php echo $is_linkedin ? 'style="display:none;"' : ''; ?>>
                                    <th><?php esc_html_e('App Password', 'post-forwarder'); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][password]" value="<?php echo esc_attr(isset($mapping['password']) ? $mapping['password'] : ''); ?>" style="width: 300px;" /></td>
                                </tr>
                                <?php if ( ! $li_creds_from_constants ) : ?>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Client ID', 'post-forwarder' ); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr( $i ); ?>][client_id]" value="<?php echo esc_attr( isset( $mapping['client_id'] ) ? $mapping['client_id'] : '' ); ?>" style="width: 300px;" /></td>
                                </tr>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Client Secret', 'post-forwarder' ); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr( $i ); ?>][client_secret]" value="<?php echo esc_attr( isset( $mapping['client_secret'] ) ? $mapping['client_secret'] : '' ); ?>" style="width: 300px;" /></td>
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
                            </table>
                            <button type="button" class="button test-connection" style="margin-right: 8px;<?php echo $is_linkedin ? ' display:none;' : ''; ?>"><?php esc_html_e('Test Connection', 'post-forwarder'); ?></button>
                            <span class="connection-result" style="font-weight: 600;"></span>
                            <button type="button" class="button remove-portal" style="float: right;"><?php esc_html_e('Remove Account', 'post-forwarder'); ?></button>
                        </div>
                        <?php $i++; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="button" id="add-portal" class="button"><?php esc_html_e('Add Another Portal', 'post-forwarder'); ?></button>
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
                        <textarea name="post_forwarding_options[mappings]" rows="10" cols="70"><?php echo esc_textarea(wp_json_encode($mappings)); ?></textarea><br>
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

        // Toggle field visibility based on account type
        function updatePortalFields($row) {
            var type = $row.find('.portal-type-select').val();
            if (type === 'linkedin') {
                $row.find('.fields-wordpress').hide();
                $row.find('.fields-linkedin').show();
                $row.find('.test-connection').hide();
            } else {
                $row.find('.fields-wordpress').show();
                $row.find('.fields-linkedin').hide();
                $row.find('.test-connection').show();
            }
        }

        $(document).on('change', '.portal-type-select', function() {
            updatePortalFields($(this).closest('.portal-row'));
        });

        $('#add-portal').click(function() {
            var n = portalCount;
            var liCredFields = liCredsFromConstants ? '' :
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Client ID', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][client_id]" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Client Secret', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][client_secret]" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Author URN', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][author_urn]" placeholder="urn:li:person:XXXX or urn:li:organization:XXXX" style="width: 420px;" /></td></tr>';
            var newPortal =
                '<div class="portal-row" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;" data-type="wordpress">' +
                '<h4><?php echo esc_js( __( 'Account #', 'post-forwarder' ) ); ?>' + (n + 1) + '</h4>' +
                '<table class="form-table">' +
                '<tr><th><?php echo esc_js( __( 'Account Type', 'post-forwarder' ) ); ?></th><td>' +
                '<select name="portals[' + n + '][type]" class="portal-type-select">' +
                '<option value="wordpress"><?php echo esc_js( __( 'WordPress Portal', 'post-forwarder' ) ); ?></option>' +
                '<option value="linkedin"><?php echo esc_js( __( 'LinkedIn Account', 'post-forwarder' ) ); ?></option>' +
                '</select></td></tr>' +
                '<tr><th><?php echo esc_js( __( 'Account Key', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][key]" placeholder="<?php echo esc_js( __( 'e.g., portal1', 'post-forwarder' ) ); ?>" style="width: 200px;" /></td></tr>' +
                '<tr><th><?php echo esc_js( __( 'Account Name', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][name]" placeholder="<?php echo esc_js( __( 'e.g., My LinkedIn', 'post-forwarder' ) ); ?>" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-wordpress"><th><?php echo esc_js( __( 'URL', 'post-forwarder' ) ); ?></th><td><input type="url" name="portals[' + n + '][url]" placeholder="https://example.com" style="width: 400px;" /></td></tr>' +
                '<tr class="fields-wordpress"><th><?php echo esc_js( __( 'User ID', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][user]" placeholder="1728" style="width: 100px;" /></td></tr>' +
                '<tr class="fields-wordpress"><th><?php echo esc_js( __( 'App Password', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][password]" placeholder="xxxx-xxxx-xxxx-xxxx" style="width: 300px;" /></td></tr>' +
                liCredFields +
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Connection Status', 'post-forwarder' ) ); ?></th><td><span style="color:#666;"><?php echo esc_js( __( 'Save the account first, then click Connect with LinkedIn.', 'post-forwarder' ) ); ?></span></td></tr>' +
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

        $(document).on('click', '.test-connection', function() {
            var $btn = $(this);
            var $row = $btn.closest('.portal-row');
            var $result = $row.find('.connection-result');
            var url = $row.find('input[name$="[url]"]').val();
            var user = $row.find('input[name$="[user]"]').val();
            var password = $row.find('input[name$="[password]"]').val();

            if (!url || !user || !password) {
                $result.css('color', '#cc0000').text('<?php echo esc_js(__('Please fill in URL, User ID, and App Password first.', 'post-forwarder')); ?>');
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
        return false;
    }
    if (isset($mapping['token_expires']) && $mapping['token_expires'] <= time()) {
        return false; // Token expired
    }
    $author_urn = isset( $mapping['author_urn'] ) ? trim( $mapping['author_urn'] ) : '';

    // Fall back to the auto-fetched person URN when no explicit author is configured.
    if ( empty( $author_urn ) && ! empty( $mapping['person_urn'] ) ) {
        $author_urn = $mapping['person_urn'];
    }

    if ( empty( $author_urn ) ) {
        post_forwarder_log_error( 'LinkedIn post skipped: no author URN configured.' );
        return false;
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

    // Only attach the article card when the URL is publicly reachable.
    // LinkedIn's servers must be able to crawl the URL — local/dev URLs will cause a 424 error.
    $parsed_host = wp_parse_url( $post_url, PHP_URL_HOST );
    $is_public   = $parsed_host && ! in_array( $parsed_host, array( 'localhost', '127.0.0.1', '::1' ), true )
        && ! preg_match( '/\.(local|test|ddev\.site|lndo\.site|localhost)$/', $parsed_host );

    if ( $is_public ) {
        $body['content'] = array(
            'article' => array(
                'source'      => $post_url,
                'title'       => $post->post_title,
                'description' => $commentary,
            ),
        );
    } else {
        // Append the URL to the commentary so it's still visible.
        $body['commentary'] .= "\n\n" . $post_url;
    }

    $response = wp_remote_post('https://api.linkedin.com/rest/posts', array(
        'headers' => array(
            'Authorization'             => 'Bearer ' . $mapping['access_token'],
            'Content-Type'              => 'application/json',
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version'          => '202503',
        ),
        'body'    => wp_json_encode($body),
        'timeout' => 30,
    ));

    if ( is_wp_error( $response ) ) {
        post_forwarder_log_error( 'LinkedIn post failed (WP_Error): ' . $response->get_error_message() );
        return false;
    }

    $code      = wp_remote_retrieve_response_code( $response );
    $success   = ( $code >= 200 && $code < 300 );

    if ( ! $success ) {
        post_forwarder_log_error(
            'LinkedIn post failed (HTTP ' . $code . '): ' . wp_remote_retrieve_body( $response )
        );
    }

    return $success;
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
    $successful_portals = array();

    // Loop through each selected product and send to corresponding portal
    foreach ($xproducts as $xproduct) {
        if (!isset($mappings[$xproduct])) {
            continue;
        }

        $target      = $mappings[$xproduct];
        $target_type = isset($target['type']) ? $target['type'] : 'wordpress';

        // Route to LinkedIn if this is a LinkedIn account
        if ($target_type === 'linkedin') {
            $success = post_forwarder_forward_to_linkedin($post, $target);
            if ($success) {
                $forwarding_successful = true;
                $successful_portals[]  = $xproduct;
            }
            continue;
        }

        // WordPress portal forwarding
        // Determine the correct REST API endpoint based on post type
        if ($original_post_type === 'post') {
            $api_url = rtrim($target['url'], '/') . '/wp-json/wp/v2/posts';
        } else {
            // For custom post types, use the post type name in the endpoint
            $api_url = rtrim($target['url'], '/') . '/wp-json/wp/v2/' . $original_post_type;
        }
        
        $auth = base64_encode($target['user'] . ':' . $target['password']);

        // Try with term slugs first (better chance of matching existing terms)
        $success = post_forward_attempt_with_term_slugs($post, $api_url, $auth, $taxonomy_data, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $xproduct);
        
        if (!$success) {
            // If that fails, try with just tags as fallback
            $success = post_forward_attempt_with_fallback_tags($post, $api_url, $auth, $fallback_tags, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $xproduct);
        }
        
        if ($success) {
            $forwarding_successful = true;
            $successful_portals[] = $xproduct;
        }
    }

    // Set the "recently forwarded" flag ONLY after processing ALL portals
    if ($forwarding_successful) {
        set_transient($recent_forward_key, true, 300); // 5 minutes
    }

    // Clean up the transients at the end
    delete_transient($lock_key);
    delete_transient($processing_key);
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

    // Add taxonomies using term slugs
    foreach ($taxonomy_data as $taxonomy_name => $taxonomy_info) {
        if (!isset($taxonomy_info['terms']) || empty($taxonomy_info['terms'])) {
            continue;
        }
        
        // Extract slugs from terms
        $term_slugs = array();
        foreach ($taxonomy_info['terms'] as $term) {
            $term_slugs[] = $term['slug'];
        }
        
        // Map common taxonomies to their REST API fields
        if ($taxonomy_name === 'category') {
            $body['categories'] = $term_slugs;
        } elseif ($taxonomy_name === 'post_tag') {
            $body['tags'] = $term_slugs;
        } elseif ($taxonomy_name === 'custom-tag') {
            // Map custom-tag to tags
            if (isset($body['tags'])) {
                $body['tags'] = array_merge($body['tags'], $term_slugs);
            } else {
                $body['tags'] = $term_slugs;
            }
        } else {
            // Try to include other taxonomies as-is (they might exist on destination)
            $body[$taxonomy_name] = $term_slugs;
        }
    }

    // Add meta if there are any
    if (!empty($meta_flattened)) {
        $body['meta'] = $meta_flattened;
    }

    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Authorization' => 'Basic ' . $auth,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode($body),
        'timeout' => 30
    ));

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    // If custom post type endpoint returns 404, try with the posts endpoint
    if ($response_code === 404 && $original_post_type !== 'post') {
        $fallback_url = rtrim($target['url'], '/') . '/wp-json/wp/v2/posts';
        $body_with_type = $body;
        $body_with_type['type'] = $original_post_type;
        
        $response = wp_remote_post($fallback_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($body_with_type),
            'timeout' => 30
        ));

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $api_url = $fallback_url; // For logging
    }

    if ($response_code >= 200 && $response_code < 300) {
        if ($featured_image_url) {
            $created_post = json_decode($response_body, true);
            if (isset($created_post['id'])) {
                $remote_post_id = $created_post['id'];
                post_forwarder_set_featured_image($remote_post_id, $featured_image_url, $target, $original_post_type);
            }
        }
        return true;
    }

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

    // Only add tags as fallback
    if (!empty($fallback_tags)) {
        $body['tags'] = $fallback_tags;
    }

    // Add meta if there are any
    if (!empty($meta_flattened)) {
        $body['meta'] = $meta_flattened;
    }

    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Authorization' => 'Basic ' . $auth,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode($body),
        'timeout' => 30
    ));

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    // If custom post type endpoint returns 404, try with the posts endpoint
    if ($response_code === 404 && $original_post_type !== 'post') {
        $fallback_url = rtrim($target['url'], '/') . '/wp-json/wp/v2/posts';
        $body_with_type = $body;
        $body_with_type['type'] = $original_post_type;
        
        $response = wp_remote_post($fallback_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($body_with_type),
            'timeout' => 30
        ));

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $api_url = $fallback_url; // For logging
    }

    if ($response_code >= 200 && $response_code < 300) {
        if ($featured_image_url) {
            $created_post = json_decode($response_body, true);
            if (isset($created_post['id'])) {
                $remote_post_id = $created_post['id'];
                post_forwarder_set_featured_image($remote_post_id, $featured_image_url, $target, $original_post_type);
            }
        }
        return true;
    }

    return false;
}

add_action('save_post', 'post_forward_post', 20, 1);

// Plugin activation hook
register_activation_hook(__FILE__, function() {
    // Set default options
    $default_options = array(
        'enabled' => false,
        'post_status' => 'draft',
        'mappings' => '{}'
    );
    
    add_option('post_forwarding_options', $default_options);
});

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
});
