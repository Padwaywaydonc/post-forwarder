<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

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

/**
 * Proactively refresh X access tokens that expire within the next 30 minutes.
 * Runs hourly via WP-Cron so tokens are always valid when a scheduled post fires.
 */
function post_forwarder_refresh_x_tokens() {
    $relay = post_forwarder_relay_url();
    if ( ! $relay ) {
        return;
    }

    $options = get_option( 'post_forwarding_options', array() );
    if ( is_string( $options ) ) {
        $options = json_decode( $options, true ) ?: array();
    }
    $mappings_raw = isset( $options['mappings'] ) ? $options['mappings'] : array();
    $mappings = is_array( $mappings_raw )
        ? $mappings_raw
        : ( json_decode( is_string( $mappings_raw ) ? $mappings_raw : '{}', true ) ?: array() );

    $updated = false;
    foreach ( $mappings as $key => &$m ) {
        if ( ( isset( $m['type'] ) ? $m['type'] : '' ) !== 'x' ) {
            continue;
        }
        if ( empty( $m['access_token'] ) || empty( $m['refresh_token'] ) ) {
            continue;
        }
        // Refresh if the token expires within 30 minutes.
        $expires = isset( $m['token_expires'] ) ? (int) $m['token_expires'] : 0;
        if ( $expires > time() + 1800 ) {
            continue;
        }

        $resp = wp_remote_post(
            $relay . '/x/refresh',
            array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( array( 'refresh_token' => $m['refresh_token'] ) ),
                'timeout' => 20,
            )
        );

        if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
            post_forwarder_log_error( 'Proactive X token refresh failed for portal "' . $key . '": ' . ( is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_body( $resp ) ) );
            continue;
        }

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $data['access_token'] ) ) {
            continue;
        }

        $m['access_token']  = $data['access_token'];
        $m['token_expires'] = time() + (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 7200 );
        if ( ! empty( $data['refresh_token'] ) ) {
            $m['refresh_token'] = $data['refresh_token'];
        }
        $updated = true;
        post_forwarder_log_error( 'Proactive X token refresh succeeded for portal "' . $key . '".' );
    }
    unset( $m );

    if ( $updated ) {
        $options['mappings'] = wp_json_encode( $mappings );
        update_option( 'post_forwarding_options', $options );
    }
}
