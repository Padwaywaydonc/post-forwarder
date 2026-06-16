<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

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

    // Build commentary: use excerpt or strip post content (LinkedIn max 3000 chars).
    $commentary = ! empty( $post->post_excerpt )
        ? $post->post_excerpt
        : wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 );

    // Append hashtags from WordPress post tags.
    $tags       = get_the_tags( $post->ID );
    $hashtags   = '';
    if ( $tags && ! is_wp_error( $tags ) ) {
        $hash_parts = array();
        foreach ( $tags as $tag ) {
            // Convert tag slug to CamelCase hashtag (e.g. "my-tag" → "#MyTag").
            $word  = str_replace( array( '-', '_', ' ' ), ' ', $tag->slug );
            $camel = str_replace( ' ', '', ucwords( $word ) );
            $hash_parts[] = '#' . $camel;
        }
        if ( $hash_parts ) {
            $hashtags = "\n\n" . implode( ' ', $hash_parts );
        }
    }

    // Truncate commentary so the full text (commentary + hashtags) fits in 3000 chars.
    $max_commentary = 3000 - strlen( $hashtags );
    if ( strlen( $commentary ) > $max_commentary ) {
        $commentary = mb_substr( $commentary, 0, $max_commentary - 1 ) . '…';
    }
    $commentary .= $hashtags;

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
