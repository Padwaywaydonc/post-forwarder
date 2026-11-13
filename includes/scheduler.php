<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

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

/**
 * Called on every page request. If items are due, runs the schedule either:
 *  - after the response is sent (PHP-FPM + fastcgi_finish_request — truly non-blocking), or
 *  - via an async non-blocking loopback to wp-cron.php (fallback).
 *
 * A 55-second transient lock prevents this from running more than once per minute,
 * so the overhead per request is just one transient GET on cache (sub-millisecond).
 */
function post_forwarder_maybe_run_schedule() {
    // Skip during cron, AJAX, REST, and WP-CLI to avoid recursion.
    if (
        ( defined( 'DOING_CRON' )  && DOING_CRON )  ||
        ( defined( 'DOING_AJAX' )  && DOING_AJAX )  ||
        ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
        ( defined( 'WP_CLI' )      && WP_CLI )
    ) {
        return;
    }

    // Already ran within the last minute.
    if ( get_transient( 'pf_run_lock' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'pf_schedule';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $due = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND scheduled_at <= %s LIMIT 1",
        current_time( 'mysql', true )
    ) );

    if ( ! $due ) {
        return;
    }

    set_transient( 'pf_run_lock', 1, 55 );

    add_action( 'shutdown', static function () {
        // On PHP-FPM: send the response to the browser first, then process.
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        }
        post_forwarder_execute_schedule();
    } );
}

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
                // Attach the uploaded thumbnail to the new post.
                if ( ! empty( $item->image_url ) ) {
                    $att_id = attachment_url_to_postid( $item->image_url );
                    if ( $att_id ) {
                        set_post_thumbnail( $post_id, $att_id );
                    }
                }
            }
        } elseif ( $post_id ) {
            $existing = get_post( $post_id );
            if ( $existing && 'draft' === $existing->post_status ) {
                wp_publish_post( $post_id );
            }
        }

        $results = array();
        if ( $post_id && ! is_wp_error( $post_id ) ) {
            $fallback_image_url = ! empty( $item->image_url ) ? $item->image_url : '';
            $results = post_forwarder_schedule_forward( $post_id, $channel_keys, $fallback_image_url );
        }

        $wpdb->update( $table, array(
            'status' => 'sent',
            'result' => wp_json_encode( $results ),
        ), array( 'id' => $item->id ) );
    }
    // phpcs:enable
}

/**
 * Create a calendar schedule item. Shared by the calendar REST endpoint and the
 * Abilities API so both validate and store items the same way.
 *
 * @param array $args {
 *     @type int    $post_id      Existing post to forward (0 for a calendar-only item).
 *     @type string $title        Title (defaults to the post's title).
 *     @type string $content      Content for calendar-only items.
 *     @type string $excerpt      Excerpt (defaults to the post's excerpt).
 *     @type string $image_url    Image URL (defaults to the post's featured image).
 *     @type array  $channel_keys Portal keys to forward to. Required.
 *     @type string $scheduled_at Date/time; without a timezone it is read as UTC. Required.
 * }
 * @return array|WP_Error The stored row, or an error.
 */
function post_forwarder_create_schedule_item( array $args ) {
    global $wpdb;
    $table        = $wpdb->prefix . 'pf_schedule';
    $post_id      = (int) ( $args['post_id'] ?? 0 );
    $title        = sanitize_text_field( $args['title'] ?? '' );
    $content      = wp_kses_post( $args['content'] ?? '' );
    $excerpt      = sanitize_text_field( $args['excerpt'] ?? '' );
    $image_url    = esc_url_raw( $args['image_url'] ?? '' );
    $channel_keys = array_values( array_map( 'sanitize_key', (array) ( $args['channel_keys'] ?? array() ) ) );
    $scheduled_at = sanitize_text_field( $args['scheduled_at'] ?? '' );

    if ( ! $scheduled_at ) {
        return new WP_Error( 'missing_date', 'scheduled_at is required', array( 'status' => 400 ) );
    }
    $timestamp = strtotime( $scheduled_at );
    if ( false === $timestamp ) {
        return new WP_Error( 'invalid_date', 'scheduled_at is not a valid date/time', array( 'status' => 400 ) );
    }
    if ( empty( $channel_keys ) ) {
        return new WP_Error( 'missing_channels', 'At least one channel is required', array( 'status' => 400 ) );
    }

    // If linking an existing post, fill title/excerpt/image from it.
    if ( $post_id ) {
        $p = get_post( $post_id );
        if ( ! $p ) {
            return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
        }
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

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert( $table, array(
        'post_id'      => $post_id ?: null,
        'title'        => $title,
        'content'      => $content,
        'excerpt'      => $excerpt,
        'image_url'    => $image_url,
        'channel_keys' => wp_json_encode( $channel_keys ),
        'scheduled_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
        'status'       => 'pending',
        'result'       => '{}',
        'created_at'   => current_time( 'mysql' ),
    ) );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
    if ( ! $row ) {
        return new WP_Error( 'insert_failed', 'Could not save the schedule item', array( 'status' => 500 ) );
    }
    $row['channel_keys'] = json_decode( $row['channel_keys'], true );
    $row['result']       = json_decode( $row['result'], true );
    return $row;
}

/**
 * Forward a post to specific channel keys (used by the schedule cron).
 * Bypasses the save_post hook and duplicate-prevention transients.
 *
 * @param int   $post_id
 * @param array $channel_keys Array of portal keys to forward to.
 * @return array Per-channel results.
 */
function post_forwarder_schedule_forward( $post_id, array $channel_keys, $fallback_image_url = '' ) {
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
    // Use the schedule's stored image URL as a fallback (e.g. new posts created from calendar).
    if ( ! $featured_image_url && $fallback_image_url ) {
        $featured_image_url = $fallback_image_url;
    }

    $results     = array();
    $wp_prepared = null; // Built once, only if a WordPress destination is selected.
    foreach ( $channel_keys as $key ) {
        if ( ! isset( $mappings[ $key ] ) ) {
            continue;
        }
        $mapping = $mappings[ $key ];
        $type    = isset( $mapping['type'] ) ? $mapping['type'] : '';

        if ( 'linkedin' === $type ) {
            $results[ $key ] = post_forwarder_forward_to_linkedin( $post, $mapping );
        } elseif ( 'x' === $type ) {
            $results[ $key ] = post_forwarder_forward_to_x( $post, $mapping, $key, $featured_image_url );
        } elseif ( 'wordpress' === $type ) {
            // Previously this only reported "Queued" and never sent anything.
            if ( null === $wp_prepared ) {
                $wp_prepared = post_forwarder_prepare_wp_payload( $post );
                if ( ! $wp_prepared['featured_image_url'] && $featured_image_url ) {
                    $wp_prepared['featured_image_url'] = $featured_image_url;
                }
            }
            $results[ $key ] = post_forwarder_forward_to_wordpress( $post, $mapping, $key, $wp_prepared );
        }
    }
    return $results;
}
