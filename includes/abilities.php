<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

/**
 * WordPress Abilities API integration (WordPress 6.9+).
 *
 * Registers abilities so AI assistants and other tools (for example through the
 * WordPress MCP adapter or the REST API) can list Post Forwarder channels and
 * forward or schedule posts. Every ability requires the manage_options capability,
 * because forwarding publishes content to the site owner's external accounts.
 */

/**
 * Channels (portals) as a safe list — never includes tokens, passwords or secrets.
 *
 * @return array<int, array{key: string, name: string, type: string, connected: bool}>
 */
function post_forwarder_get_channels_for_api() {
    $options  = get_option( 'post_forwarding_options', array() );
    $raw      = is_array( $options ) && isset( $options['mappings'] ) ? $options['mappings'] : '{}';
    $mappings = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
    $channels = array();
    foreach ( (array) $mappings as $key => $mapping ) {
        $type = isset( $mapping['type'] ) ? $mapping['type'] : 'wordpress';
        if ( ! in_array( $type, array( 'wordpress', 'linkedin', 'x', 'meta' ), true ) ) {
            continue;
        }
        $channels[] = array(
            'key'       => (string) $key,
            'name'      => isset( $mapping['name'] ) ? (string) $mapping['name'] : (string) $key,
            'type'      => $type,
            'connected' => (bool) post_forwarder_channel_connected( $mapping ),
        );
    }
    return $channels;
}

/**
 * Reject channel keys that are not configured.
 *
 * @param array $keys Requested channel keys.
 * @return true|WP_Error
 */
function post_forwarder_validate_channel_keys( array $keys ) {
    $known   = wp_list_pluck( post_forwarder_get_channels_for_api(), 'key' );
    $unknown = array_diff( $keys, $known );
    if ( $unknown ) {
        return new WP_Error(
            'post_forwarder_unknown_channel',
            sprintf(
                /* translators: 1: unknown channel keys, 2: available channel keys */
                __( 'Unknown channel(s): %1$s. Available: %2$s.', 'post-forwarder' ),
                implode( ', ', $unknown ),
                implode( ', ', $known )
            )
        );
    }
    return true;
}

function post_forwarder_register_ability_category() {
    wp_register_ability_category( 'post-forwarder', array(
        'label'       => __( 'Post Forwarder', 'post-forwarder' ),
        'description' => __( 'Forward and schedule WordPress posts to other WordPress sites, LinkedIn and X.', 'post-forwarder' ),
    ) );
}

function post_forwarder_register_abilities() {
    $can_manage = static function () {
        return current_user_can( 'manage_options' );
    };
    // `public` is new in WordPress 7.1; `show_in_rest` covers 6.9 and 7.0.
    $exposed = array( 'public' => true, 'show_in_rest' => true );

    $channel_list_schema = array(
        'type'  => 'array',
        'items' => array(
            'type'       => 'object',
            'properties' => array(
                'key'       => array( 'type' => 'string', 'description' => 'Channel key to pass to other abilities.' ),
                'name'      => array( 'type' => 'string' ),
                'type'      => array( 'type' => 'string', 'enum' => array( 'wordpress', 'linkedin', 'x', 'meta' ) ),
                'connected' => array( 'type' => 'boolean', 'description' => 'False means the account must be reconnected in Post Forwarder settings.' ),
            ),
        ),
    );
    $channels_input = array(
        'type'        => 'array',
        'items'       => array( 'type' => 'string' ),
        'minItems'    => 1,
        'description' => 'Channel keys from post-forwarder/list-channels.',
    );
    $results_schema = array(
        'type'                 => 'object',
        'description'          => 'Result per channel key.',
        'additionalProperties' => array(
            'type'       => 'object',
            'properties' => array(
                'success' => array( 'type' => 'boolean' ),
                'message' => array( 'type' => 'string' ),
            ),
        ),
    );

    wp_register_ability( 'post-forwarder/list-channels', array(
        'label'               => __( 'List forwarding channels', 'post-forwarder' ),
        'description'         => __( 'Lists the WordPress sites, LinkedIn accounts and X accounts this site can forward posts to, with their keys and whether each is currently connected.', 'post-forwarder' ),
        'category'            => 'post-forwarder',
        'output_schema'       => $channel_list_schema,
        'execute_callback'    => static function () {
            return post_forwarder_get_channels_for_api();
        },
        'permission_callback' => $can_manage,
        'meta'                => array_merge( $exposed, array(
            'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
        ) ),
    ) );

    wp_register_ability( 'post-forwarder/forward-post', array(
        'label'               => __( 'Forward a post now', 'post-forwarder' ),
        'description'         => __( 'Immediately forwards a published post to the chosen channels (other WordPress sites, LinkedIn, X). This publishes content on those external accounts; X charges per post.', 'post-forwarder' ),
        'category'            => 'post-forwarder',
        'input_schema'        => array(
            'type'                 => 'object',
            'required'             => array( 'post_id', 'channels' ),
            'additionalProperties' => false,
            'properties'           => array(
                'post_id'  => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of a published post.' ),
                'channels' => $channels_input,
            ),
        ),
        'output_schema'       => $results_schema,
        'execute_callback'    => static function ( $input ) {
            $post_id  = (int) $input['post_id'];
            $channels = array_values( array_map( 'sanitize_key', (array) $input['channels'] ) );
            $post     = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'post_forwarder_invalid_post', __( 'Post not found.', 'post-forwarder' ) );
            }
            if ( 'publish' !== $post->post_status ) {
                return new WP_Error( 'post_forwarder_not_published', __( 'Only published posts can be forwarded now. Use post-forwarder/schedule-post for later.', 'post-forwarder' ) );
            }
            $valid = post_forwarder_validate_channel_keys( $channels );
            if ( is_wp_error( $valid ) ) {
                return $valid;
            }
            $results = array();
            foreach ( post_forwarder_schedule_forward( $post_id, $channels ) as $key => $r ) {
                $results[ $key ] = array(
                    'success' => ! empty( $r['success'] ),
                    'message' => isset( $r['message'] ) ? (string) $r['message'] : '',
                );
            }
            return $results;
        },
        'permission_callback' => $can_manage,
        'meta'                => array_merge( $exposed, array(
            'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
        ) ),
    ) );

    wp_register_ability( 'post-forwarder/schedule-post', array(
        'label'               => __( 'Schedule a post', 'post-forwarder' ),
        'description'         => __( 'Adds a post to the Post Forwarder calendar so it is forwarded to the chosen channels at a given date and time.', 'post-forwarder' ),
        'category'            => 'post-forwarder',
        'input_schema'        => array(
            'type'                 => 'object',
            'required'             => array( 'post_id', 'channels', 'scheduled_at' ),
            'additionalProperties' => false,
            'properties'           => array(
                'post_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
                'channels'     => $channels_input,
                'scheduled_at' => array( 'type' => 'string', 'description' => 'When to forward, as ISO 8601 date-time including a timezone, e.g. 2026-11-20T09:00:00+01:00.' ),
            ),
        ),
        'output_schema'       => array(
            'type'       => 'object',
            'properties' => array(
                'id'           => array( 'type' => 'integer', 'description' => 'Calendar item ID.' ),
                'scheduled_at' => array( 'type' => 'string', 'description' => 'Stored time in UTC.' ),
                'channels'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
            ),
        ),
        'execute_callback'    => static function ( $input ) {
            $channels = array_values( array_map( 'sanitize_key', (array) $input['channels'] ) );
            $valid    = post_forwarder_validate_channel_keys( $channels );
            if ( is_wp_error( $valid ) ) {
                return $valid;
            }
            $row = post_forwarder_create_schedule_item( array(
                'post_id'      => (int) $input['post_id'],
                'channel_keys' => $channels,
                'scheduled_at' => (string) $input['scheduled_at'],
            ) );
            if ( is_wp_error( $row ) ) {
                return $row;
            }
            return array(
                'id'           => (int) $row['id'],
                'scheduled_at' => (string) $row['scheduled_at'],
                'channels'     => (array) $row['channel_keys'],
            );
        },
        'permission_callback' => $can_manage,
        'meta'                => array_merge( $exposed, array(
            'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
        ) ),
    ) );
}

if ( function_exists( 'wp_register_ability' ) ) {
    add_action( 'wp_abilities_api_categories_init', 'post_forwarder_register_ability_category' );
    add_action( 'wp_abilities_api_init', 'post_forwarder_register_abilities' );
}
