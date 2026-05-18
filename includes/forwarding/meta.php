<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

/**
 * Shared image + message prep for Facebook/Instagram forwarding.
 * Returns array( 'message', 'img_url', 'post_url', 'is_public' ).
 */
function post_forwarder_meta_prepare( $post, $fallback_image_url ) {
    $post_url  = get_permalink( $post->ID );
    $body_text = ! empty( $post->post_excerpt )
        ? $post->post_excerpt
        : wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 );
    $message   = ! empty( $post->post_title )
        ? $post->post_title . "\n\n" . $body_text
        : $body_text;

    $parsed_host = wp_parse_url( $post_url, PHP_URL_HOST );
    $is_public   = $parsed_host
        && ! in_array( $parsed_host, array( 'localhost', '127.0.0.1', '::1' ), true )
        && ! preg_match( '/\.(local|test|ddev\.site|lndo\.site|localhost)$/', $parsed_host );

    $img_url  = null;
    $thumb_id = get_post_thumbnail_id( $post->ID );
    if ( $thumb_id ) {
        $src     = wp_get_attachment_image_src( $thumb_id, 'full' );
        $img_url = $src ? $src[0] : null;
    }
    if ( ! $img_url && $fallback_image_url ) {
        $img_url = $fallback_image_url;
    }

    return compact( 'message', 'img_url', 'post_url', 'is_public' );
}

// Facebook Page post forwarding.
function post_forwarder_forward_to_facebook( $post, $mapping, $fallback_image_url = null ) {
    if ( empty( $mapping['access_token'] ) || empty( $mapping['page_id'] ) ) {
        return array( 'success' => false, 'message' => __( 'Not connected — please authorize with Facebook first.', 'post-forwarder' ) );
    }

    $page_token = $mapping['access_token'];
    $page_id    = $mapping['page_id'];
    $d          = post_forwarder_meta_prepare( $post, $fallback_image_url );
    $message    = $d['message'];
    $img_url    = $d['img_url'];
    $post_url   = $d['post_url'];
    $is_public  = $d['is_public'];

    $fb_photo_id = null;
    if ( $img_url ) {
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

    $fb_body = array( 'access_token' => $page_token );
    if ( $fb_photo_id ) {
        $fb_body['message']                       = $message . ( ! $is_public ? "\n\n" . $post_url : '' );
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
        return array( 'success' => false, 'message' => $fb_resp->get_error_message() );
    }

    $fb_code = wp_remote_retrieve_response_code( $fb_resp );
    if ( $fb_code >= 200 && $fb_code < 300 ) {
        post_forwarder_log_error( 'Facebook post succeeded (HTTP ' . $fb_code . ')' );
        return array( 'success' => true, 'message' => __( 'Posted successfully.', 'post-forwarder' ) );
    }

    $fb_err = json_decode( wp_remote_retrieve_body( $fb_resp ), true );
    $fb_msg = ( is_array( $fb_err ) && ! empty( $fb_err['error']['message'] ) ) ? $fb_err['error']['message'] : 'HTTP ' . $fb_code;
    post_forwarder_log_error( 'Facebook post failed (' . $fb_code . '): ' . wp_remote_retrieve_body( $fb_resp ) );
    return array( 'success' => false, 'message' => $fb_msg );
}

// Instagram post forwarding.
function post_forwarder_forward_to_instagram( $post, $mapping, $fallback_image_url = null ) {
    if ( empty( $mapping['access_token'] ) || empty( $mapping['instagram_account_id'] ) ) {
        return array( 'success' => false, 'message' => __( 'Not connected — please authorize with Instagram first.', 'post-forwarder' ) );
    }

    $page_token = $mapping['access_token'];
    $ig_id      = $mapping['instagram_account_id'];
    $d          = post_forwarder_meta_prepare( $post, $fallback_image_url );

    if ( ! $d['img_url'] ) {
        return array( 'success' => false, 'message' => __( 'No featured image — Instagram requires an image.', 'post-forwarder' ) );
    }
    if ( ! $d['is_public'] ) {
        return array( 'success' => false, 'message' => __( 'Image URL not publicly accessible — Instagram must be able to download it.', 'post-forwarder' ) );
    }

    $caption = $d['message'] . "\n\n" . $d['post_url'];

    $ig_container_resp = wp_remote_post( "https://graph.facebook.com/v21.0/{$ig_id}/media", array(
        'body'    => array(
            'image_url'    => $d['img_url'],
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
        return array( 'success' => false, 'message' => $ig_msg );
    }

    $ig_creation_id = json_decode( wp_remote_retrieve_body( $ig_container_resp ), true )['id'] ?? '';

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
        return array( 'success' => false, 'message' => __( 'Publish step failed — check debug log.', 'post-forwarder' ) );
    }

    post_forwarder_log_error( 'Instagram post succeeded.' );
    return array( 'success' => true, 'message' => __( 'Posted successfully.', 'post-forwarder' ) );
}

// Meta (Facebook + Instagram) combined — kept for backward compatibility with existing portals.
function post_forwarder_forward_to_meta( $post, $mapping, $fallback_image_url = null ) {
    if ( empty( $mapping['access_token'] ) || empty( $mapping['page_id'] ) ) {
        return array( 'success' => false, 'message' => __( 'Not connected — please authorize with Meta first.', 'post-forwarder' ) );
    }

    $results = array();

    if ( ! empty( $mapping['post_to_facebook'] ) ) {
        $results['facebook'] = post_forwarder_forward_to_facebook( $post, $mapping, $fallback_image_url );
        $results['facebook']['message'] = 'Facebook: ' . $results['facebook']['message'];
    }

    if ( ! empty( $mapping['post_to_instagram'] ) && ! empty( $mapping['instagram_account_id'] ) ) {
        $results['instagram'] = post_forwarder_forward_to_instagram( $post, $mapping, $fallback_image_url );
        $results['instagram']['message'] = 'Instagram: ' . $results['instagram']['message'];
    }

    if ( empty( $results ) ) {
        return array( 'success' => false, 'message' => __( 'No platforms selected for Meta portal.', 'post-forwarder' ) );
    }

    $any_success = in_array( true, array_column( $results, 'success' ), true );
    $combined    = implode( ' | ', array_column( $results, 'message' ) );

    return array( 'success' => $any_success, 'message' => $combined );
}
