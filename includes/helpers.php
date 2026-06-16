<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

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
 * Return the OAuth relay URL (trailing slash stripped).
 * Override with the POST_FORWARDER_RELAY_URL constant if you fork the relay.
 *
 * @return string
 */
function post_forwarder_relay_url() {
    if ( defined( 'POST_FORWARDER_RELAY_URL' ) && POST_FORWARDER_RELAY_URL ) {
        return rtrim( POST_FORWARDER_RELAY_URL, '/' );
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
