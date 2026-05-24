<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

// Forward post after import
function post_forwarder_forward_post($post_id) {
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


        // Route to X (Twitter) if this is an X account
        if ( $target_type === 'x' ) {
            $result = post_forwarder_forward_to_x( $post, $target, $xproduct, $featured_image_url );
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
        $success = post_forwarder_attempt_with_term_slugs($post, $api_url, $auth, $taxonomy_data, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $xproduct);

        if (!$success) {
            // If that fails, try with just tags as fallback
            $success = post_forwarder_attempt_with_fallback_tags($post, $api_url, $auth, $fallback_tags, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $xproduct);
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
function post_forwarder_attempt_with_term_slugs($post, $api_url, $auth, $taxonomy_data, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $product_key) {
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
function post_forwarder_attempt_with_fallback_tags($post, $api_url, $auth, $fallback_tags, $meta_flattened, $options, $original_post_type, $target, $featured_image_url, $product_key) {
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
