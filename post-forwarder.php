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
    echo '<strong>' . esc_html__('Select Portals to Forward:', 'post-forwarder') . '</strong>';
    echo '</div>';
    
    if (!empty($mappings) && is_array($mappings)) {
        foreach ($mappings as $product_key => $mapping) {
            $portal_name = isset($mapping['name']) ? $mapping['name'] : $product_key;
            $portal_url = isset($mapping['url']) ? $mapping['url'] : '';
            $display_name = $portal_name . ($portal_url ? ' (' . wp_parse_url($portal_url, PHP_URL_HOST) . ')' : '');
            
            $is_selected = in_array($product_key, $selected_products);
            
            echo '<div style="margin-bottom: 8px;">';
            echo '<label style="display: flex; align-items: center;">';
            echo '<input type="checkbox" name="post_forwarding_product[]" value="' . esc_attr($product_key) . '"' . ($is_selected ? ' checked' : '') . ' style="margin-right: 8px;">';
            echo '<span>' . esc_html($display_name) . '</span>';
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
    
    $options = get_option('post_forwarding_options', array());
    
    // Handle form submission for the new interface
    if (isset($_POST['submit_portals']) && isset($_POST['portals_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['portals_nonce'])), 'save_portals')) {
        $portals = array();
        
        if (isset($_POST['portals']) && is_array($_POST['portals'])) {
            // Properly sanitize the entire portals array
            $portals_raw = map_deep(wp_unslash($_POST['portals']), 'sanitize_text_field');
            
            foreach ($portals_raw as $index => $portal) {
                if (is_array($portal) && !empty($portal['key']) && !empty($portal['name']) && !empty($portal['url'])) {
                    $key = sanitize_text_field($portal['key']);
                    $portals[$key] = array(
                        'name' => sanitize_text_field($portal['name']),
                        'url' => esc_url_raw($portal['url']),
                        'user' => sanitize_text_field($portal['user']),
                        'password' => sanitize_text_field($portal['password'])
                    );
                }
            }
        }
        
        $options['mappings'] = wp_json_encode($portals);
        update_option('post_forwarding_options', $options);
        echo '<div class="notice notice-success"><p>' . esc_html__('Portals saved successfully!', 'post-forwarder') . '</p></div>';
    }
    
    // Parse existing mappings
    $mappings_json = isset($options['mappings']) ? $options['mappings'] : '{}';
    $mappings = json_decode($mappings_json, true);
    if (!is_array($mappings)) $mappings = array();
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
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
                <div style="background: #f9f9f9; padding: 15px; margin-bottom: 15px; border-left: 4px solid #0073aa;">
                    <p><strong><?php esc_html_e('How it works:', 'post-forwarder'); ?></strong></p>
                    <ul>
                        <li><strong><?php esc_html_e('Portal Key:', 'post-forwarder'); ?></strong> <?php esc_html_e('A unique identifier (like "portal1") - this is what you\'ll select when forwarding posts', 'post-forwarder'); ?></li>
                        <li><strong><?php esc_html_e('Portal Name:', 'post-forwarder'); ?></strong> <?php esc_html_e('A friendly display name that appears in the interface', 'post-forwarder'); ?></li>
                        <li><strong><?php esc_html_e('URL:', 'post-forwarder'); ?></strong> <?php esc_html_e('The destination WordPress site URL', 'post-forwarder'); ?></li>
                        <li><strong><?php esc_html_e('User ID & App Password:', 'post-forwarder'); ?></strong> <?php esc_html_e('WordPress user ID and application password for API access', 'post-forwarder'); ?></li>
                    </ul>
                </div>

                <?php if (empty($mappings)): ?>
                    <div class="portal-row" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;">
                        <h4><?php esc_html_e('Portal #1', 'post-forwarder'); ?></h4>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e('Portal Key', 'post-forwarder'); ?></th>
                                <td><input type="text" name="portals[0][key]" placeholder="<?php esc_attr_e('e.g., portal1', 'post-forwarder'); ?>" style="width: 200px;" /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Portal Name', 'post-forwarder'); ?></th>
                                <td><input type="text" name="portals[0][name]" placeholder="<?php esc_attr_e('e.g., Example Portal', 'post-forwarder'); ?>" style="width: 300px;" /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('URL', 'post-forwarder'); ?></th>
                                <td><input type="url" name="portals[0][url]" placeholder="https://example.com" style="width: 400px;" /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('User ID', 'post-forwarder'); ?></th>
                                <td><input type="text" name="portals[0][user]" placeholder="1728" style="width: 100px;" /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('App Password', 'post-forwarder'); ?></th>
                                <td><input type="text" name="portals[0][password]" placeholder="xxxx-xxxx-xxxx-xxxx" style="width: 300px;" /></td>
                            </tr>
                        </table>
                        <button type="button" class="button remove-portal"><?php esc_html_e('Remove Portal', 'post-forwarder'); ?></button>
                    </div>
                <?php else: ?>
                    <?php $i = 0; foreach ($mappings as $key => $mapping): ?>
                        <div class="portal-row" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;">
                            <h4>
                                <?php 
                                /* translators: %d: Portal number */
                                echo esc_html(sprintf(__('Portal #%d', 'post-forwarder'), $i + 1)); 
                                ?>
                            </h4>
                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e('Portal Key', 'post-forwarder'); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][key]" value="<?php echo esc_attr($key); ?>" style="width: 200px;" /></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('Portal Name', 'post-forwarder'); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][name]" value="<?php echo esc_attr($mapping['name']); ?>" style="width: 300px;" /></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('URL', 'post-forwarder'); ?></th>
                                    <td><input type="url" name="portals[<?php echo esc_attr($i); ?>][url]" value="<?php echo esc_attr($mapping['url']); ?>" style="width: 400px;" /></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('User ID', 'post-forwarder'); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][user]" value="<?php echo esc_attr($mapping['user']); ?>" style="width: 100px;" /></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('App Password', 'post-forwarder'); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][password]" value="<?php echo esc_attr($mapping['password']); ?>" style="width: 300px;" /></td>
                                </tr>
                            </table>
                            <button type="button" class="button remove-portal"><?php esc_html_e('Remove Portal', 'post-forwarder'); ?></button>
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
                        <textarea name="post_forwarding_options[mappings]" rows="10" cols="70"><?php echo esc_textarea($mappings_json); ?></textarea><br>
                        <small><?php esc_html_e('Advanced users can edit the JSON directly. Use the form above for easier configuration.', 'post-forwarder'); ?></small>
                    </td>
                </tr>
            </table>
            <?php submit_button(esc_html__('Save JSON', 'post-forwarder')); ?>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var portalCount = <?php echo count($mappings); ?>;
        
        $('#add-portal').click(function() {
            var newPortal = '<div class="portal-row" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;">' +
                '<h4><?php echo esc_js(__('Portal #', 'post-forwarder')); ?>' + (portalCount + 1) + '</h4>' +
                '<table class="form-table">' +
                '<tr><th><?php echo esc_js(__('Portal Key', 'post-forwarder')); ?></th><td><input type="text" name="portals[' + portalCount + '][key]" placeholder="<?php echo esc_js(__('e.g., portal1', 'post-forwarder')); ?>" style="width: 200px;" /></td></tr>' +
                '<tr><th><?php echo esc_js(__('Portal Name', 'post-forwarder')); ?></th><td><input type="text" name="portals[' + portalCount + '][name]" placeholder="<?php echo esc_js(__('e.g., Example Portal', 'post-forwarder')); ?>" style="width: 300px;" /></td></tr>' +
                '<tr><th><?php echo esc_js(__('URL', 'post-forwarder')); ?></th><td><input type="url" name="portals[' + portalCount + '][url]" placeholder="https://example.com" style="width: 400px;" /></td></tr>' +
                '<tr><th><?php echo esc_js(__('User ID', 'post-forwarder')); ?></th><td><input type="text" name="portals[' + portalCount + '][user]" placeholder="1728" style="width: 100px;" /></td></tr>' +
                '<tr><th><?php echo esc_js(__('App Password', 'post-forwarder')); ?></th><td><input type="text" name="portals[' + portalCount + '][password]" placeholder="xxxx-xxxx-xxxx-xxxx" style="width: 300px;" /></td></tr>' +
                '</table>' +
                '<button type="button" class="button remove-portal"><?php echo esc_js(__('Remove Portal', 'post-forwarder')); ?></button>' +
                '</div>';
            
            $('#portals-container').append(newPortal);
            portalCount++;
        });
        
        $(document).on('click', '.remove-portal', function() {
            $(this).closest('.portal-row').remove();
        });
    });
    </script>
    <?php
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

        $target = $mappings[$xproduct];
        
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
