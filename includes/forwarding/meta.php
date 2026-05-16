<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

// Meta (Facebook + Instagram) post forwarding.
function post_forwarder_forward_to_meta( $post, $mapping, $fallback_image_url = null ) {
    if ( empty( $mapping['access_token'] ) || empty( $mapping['page_id'] ) ) {
        return array( 'success' => false, 'message' => __( 'Not connected — please authorize with Meta first.', 'post-forwarder' ) );
    }

    $page_token = $mapping['access_token'];
    $page_id    = $mapping['page_id'];
    $ig_id      = isset( $mapping['instagram_account_id'] ) ? $mapping['instagram_account_id'] : '';
    $post_url   = get_permalink( $post->ID );
    $body_text  = ! empty( $post->post_excerpt )
        ? $post->post_excerpt
        : wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 );
    $message    = ! empty( $post->post_title )
        ? $post->post_title . "\n\n" . $body_text
        : $body_text;

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
    // Fall back to the URL stored in the schedule row (for calendar-created posts).
    if ( ! $img_url && $fallback_image_url ) {
        $img_url = $fallback_image_url;
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
