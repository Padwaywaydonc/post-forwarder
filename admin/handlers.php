<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

// Sanitize options
function post_forwarder_sanitize_options($input) {
    // Start from existing values so that fields not present in this form (e.g. mappings,
    // managed by the portals form) are never wiped by saving the global settings form.
    $existing  = get_option( 'post_forwarding_options', array() );
    $sanitized = is_array( $existing ) ? $existing : array();

    // Checkbox: must explicitly set false when not in POST (unchecked checkboxes are omitted).
    $sanitized['enabled'] = ! empty( $input['enabled'] );

    $sanitized['post_status'] = ( isset( $input['post_status'] ) && $input['post_status'] === 'publish' )
        ? 'publish'
        : 'draft';

    // Preserve mappings from input when provided — OAuth callbacks and the portals form pass this.
    // When the global settings form submits (no mappings key), leave existing mappings untouched.
    // When the JSON textarea is submitted, '***' placeholders must not overwrite real stored values.
    if ( isset( $input['mappings'] ) && $input['mappings'] !== '' ) {
        $new_mappings = json_decode( $input['mappings'], true );
        if ( is_array( $new_mappings ) ) {
            $existing_mappings = isset( $sanitized['mappings'] ) ? $sanitized['mappings'] : array();
            if ( ! is_array( $existing_mappings ) ) {
                $existing_mappings = json_decode( is_string( $existing_mappings ) ? $existing_mappings : '{}', true );
                if ( ! is_array( $existing_mappings ) ) {
                    $existing_mappings = array();
                }
            }
            $sensitive_keys = array( 'access_token', 'refresh_token', 'password', 'client_secret' );
            foreach ( $new_mappings as $mkey => &$mapping ) {
                foreach ( $sensitive_keys as $sk ) {
                    if ( isset( $mapping[ $sk ] ) && '***' === $mapping[ $sk ] && isset( $existing_mappings[ $mkey ][ $sk ] ) ) {
                        $mapping[ $sk ] = $existing_mappings[ $mkey ][ $sk ];
                    }
                }
            }
            unset( $mapping );
            $sanitized['mappings'] = wp_json_encode( $new_mappings );
        } else {
            $sanitized['mappings'] = $input['mappings'];
        }
    }

    return $sanitized;
}

// Register settings (called from admin_init hook in main file)
function post_forwarder_register_settings() {
    register_setting('post_forwarding', 'post_forwarding_options', array(
        'sanitize_callback' => 'post_forwarder_sanitize_options'
    ));
}

// Handle the portals form submission early (admin_init) so wp_redirect() works
// before any output is sent — standard WordPress PRG pattern.
function post_forwarder_handle_portals_save() {
    if ( ! isset( $_POST['submit_portals'], $_POST['portals_nonce'] ) ) {
        return;
    }
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['portals_nonce'] ) ), 'save_portals' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $options = get_option( 'post_forwarding_options', array() );
    if ( is_string( $options ) ) {
        $options = json_decode( $options, true );
        if ( ! is_array( $options ) ) {
            $options = array();
        }
    }

    $portals = array();
    $existing_mappings_for_save = isset( $options['mappings'] ) ? $options['mappings'] : array();
    if ( ! is_array( $existing_mappings_for_save ) ) {
        $existing_mappings_for_save = json_decode( is_string( $existing_mappings_for_save ) ? $existing_mappings_for_save : '{}', true );
        if ( ! is_array( $existing_mappings_for_save ) ) {
            $existing_mappings_for_save = array();
        }
    }

    if ( isset( $_POST['portals'] ) && is_array( $_POST['portals'] ) ) {
        $portals_raw = wp_unslash( $_POST['portals'] );

        foreach ( $portals_raw as $index => $portal ) {
            if ( ! is_array( $portal ) || empty( $portal['name'] ) ) {
                continue;
            }
            // Use existing key if present (existing portal), otherwise generate from name.
            if ( ! empty( $portal['key'] ) ) {
                $key = sanitize_key( $portal['key'] );
            } else {
                $base = sanitize_title( $portal['name'] );
                if ( ! $base ) { $base = 'portal'; }
                $key  = $base;
                $n    = 2;
                while ( isset( $portals[ $key ] ) ) {
                    $key = $base . '-' . $n++;
                }
            }
            $allowed_types = array( 'linkedin', 'x', 'meta', 'wordpress' );
            $type          = ( isset( $portal['type'] ) && in_array( $portal['type'], $allowed_types, true ) ) ? $portal['type'] : 'wordpress';

            if ( $type === 'linkedin' ) {
                $portals[ $key ] = array(
                    'type'          => 'linkedin',
                    'name'          => sanitize_text_field( $portal['name'] ),
                    'client_id'     => sanitize_text_field( isset( $portal['client_id'] )     ? $portal['client_id']     : '' ),
                    'client_secret' => sanitize_text_field( isset( $portal['client_secret'] ) ? $portal['client_secret'] : '' ),
                    'author_urn'    => sanitize_text_field( isset( $portal['author_urn'] )    ? $portal['author_urn']    : '' ),
                );
                foreach ( array( 'access_token', 'refresh_token', 'token_expires', 'refresh_token_expires', 'person_urn', 'last_error' ) as $token_field ) {
                    if ( isset( $existing_mappings_for_save[ $key ][ $token_field ] ) ) {
                        $portals[ $key ][ $token_field ] = $existing_mappings_for_save[ $key ][ $token_field ];
                    }
                }
            } elseif ( $type === 'x' ) {
                $portals[ $key ] = array(
                    'type'          => 'x',
                    'name'          => sanitize_text_field( $portal['name'] ),
                    'client_id'     => sanitize_text_field( isset( $portal['client_id'] )     ? $portal['client_id']     : '' ),
                    'client_secret' => sanitize_text_field( isset( $portal['client_secret'] ) ? $portal['client_secret'] : '' ),
                );
                // Preserve existing client_secret when the submitted value is empty (masked display).
                if ( empty( $portals[ $key ]['client_secret'] ) && ! empty( $existing_mappings_for_save[ $key ]['client_secret'] ) ) {
                    $portals[ $key ]['client_secret'] = $existing_mappings_for_save[ $key ]['client_secret'];
                }
                foreach ( array( 'access_token', 'refresh_token', 'token_expires', 'refresh_token_expires', 'x_user_id', 'x_username', 'last_error' ) as $token_field ) {
                    if ( isset( $existing_mappings_for_save[ $key ][ $token_field ] ) ) {
                        $portals[ $key ][ $token_field ] = $existing_mappings_for_save[ $key ][ $token_field ];
                    }
                }
            } elseif ( $type === 'meta' ) {
                $portals[ $key ] = array(
                    'type'              => 'meta',
                    'name'              => sanitize_text_field( $portal['name'] ),
                    'post_to_facebook'  => ! empty( $portal['post_to_facebook'] ),
                    'post_to_instagram' => ! empty( $portal['post_to_instagram'] ),
                );
                foreach ( array( 'access_token', 'page_id', 'page_name', 'instagram_account_id', 'last_error' ) as $token_field ) {
                    if ( isset( $existing_mappings_for_save[ $key ][ $token_field ] ) ) {
                        $portals[ $key ][ $token_field ] = $existing_mappings_for_save[ $key ][ $token_field ];
                    }
                }
            } else {
                if ( empty( $portal['url'] ) ) {
                    continue;
                }
                $portals[ $key ] = array(
                    'type'     => 'wordpress',
                    'name'     => sanitize_text_field( $portal['name'] ),
                    'url'      => esc_url_raw( $portal['url'] ),
                    'user'     => sanitize_text_field( isset( $portal['user'] )     ? $portal['user']     : '' ),
                    'password' => sanitize_text_field( isset( $portal['password'] ) ? $portal['password'] : '' ),
                );
                // Preserve button-connected credentials when manual fields are empty (hidden).
                foreach ( array( 'user', 'password', 'wp_auth_mode', 'wp_site_url', 'last_error' ) as $pf ) {
                    if ( empty( $portals[ $key ][ $pf ] ) && isset( $existing_mappings_for_save[ $key ][ $pf ] ) ) {
                        $portals[ $key ][ $pf ] = $existing_mappings_for_save[ $key ][ $pf ];
                    }
                }
            }
        }
    }

    $options['mappings'] = wp_json_encode( $portals );
    update_option( 'post_forwarding_options', $options );

    $settings_url = admin_url( 'admin.php?page=post-forwarder-settings' );

    // Save & Connect — LinkedIn.
    if ( ! empty( $_POST['pending_linkedin_connect'] ) ) {
        $connect_key  = sanitize_key( wp_unslash( $_POST['pending_linkedin_connect'] ) );
        $relay_target = post_forwarder_relay_url();
        if ( $relay_target && isset( $portals[ $connect_key ] ) && 'linkedin' === $portals[ $connect_key ]['type'] ) {
            wp_redirect( $relay_target . '/start?' . http_build_query( array(
                'return_url' => $settings_url,
                'portal_key' => $connect_key,
                'wp_nonce'   => wp_create_nonce( 'linkedin_oauth_' . $connect_key ),
            ) ) );
            exit;
        }
    }

    // Save & Connect — X.
    if ( ! empty( $_POST['pending_x_connect'] ) ) {
        $x_connect_key  = sanitize_key( wp_unslash( $_POST['pending_x_connect'] ) );
        $x_relay_target = post_forwarder_relay_url();
        if ( $x_relay_target && isset( $portals[ $x_connect_key ] ) && 'x' === $portals[ $x_connect_key ]['type'] ) {
            $x_redirect_args = array(
                'return_url' => $settings_url,
                'portal_key' => $x_connect_key,
                'wp_nonce'   => wp_create_nonce( 'x_oauth_' . $x_connect_key ),
            );
            if ( ! empty( $portals[ $x_connect_key ]['client_id'] ) ) {
                $x_redirect_args['client_id'] = $portals[ $x_connect_key ]['client_id'];
            }
            if ( ! empty( $portals[ $x_connect_key ]['client_secret'] ) ) {
                $x_redirect_args['client_secret'] = $portals[ $x_connect_key ]['client_secret'];
            }
            wp_redirect( $x_relay_target . '/x/start?' . http_build_query( $x_redirect_args ) );
            exit;
        }
    }

    // Save & Connect — Meta (Facebook + Instagram).
    if ( ! empty( $_POST['pending_meta_connect'] ) ) {
        $meta_connect_key  = sanitize_key( wp_unslash( $_POST['pending_meta_connect'] ) );
        $meta_relay_target = post_forwarder_relay_url();
        if ( $meta_relay_target && isset( $portals[ $meta_connect_key ] ) && 'meta' === $portals[ $meta_connect_key ]['type'] ) {
            wp_redirect( $meta_relay_target . '/meta/start?' . http_build_query( array(
                'return_url' => $settings_url,
                'portal_key' => $meta_connect_key,
                'wp_nonce'   => wp_create_nonce( 'meta_oauth_' . $meta_connect_key ),
            ) ) );
            exit;
        }
    }

    // Save & Connect — WordPress Application Password authorization.
    if ( ! empty( $_POST['pending_wp_connect'] ) ) {
        $wp_connect_key = sanitize_key( wp_unslash( $_POST['pending_wp_connect'] ) );
        if ( isset( $portals[ $wp_connect_key ] ) && 'wordpress' === $portals[ $wp_connect_key ]['type'] ) {
            $target_url = isset( $portals[ $wp_connect_key ]['url'] ) ? $portals[ $wp_connect_key ]['url'] : '';
            if ( $target_url ) {
                $preflight    = wp_remote_get(
                    rtrim( $target_url, '/' ) . '/wp-json/',
                    array( 'timeout' => 10, 'redirection' => 5 )
                );
                $pf_data      = ! is_wp_error( $preflight ) ? json_decode( wp_remote_retrieve_body( $preflight ), true ) : null;
                $preflight_ok = ! is_wp_error( $preflight )
                    && 200 === wp_remote_retrieve_response_code( $preflight )
                    && is_array( $pf_data )
                    && isset( $pf_data['namespaces'] );

                if ( ! $preflight_ok ) {
                    wp_redirect( $settings_url . '&wp_connect_error=preflight_failed&portal_key=' . urlencode( $wp_connect_key ) );
                    exit;
                }

                // Use the site URL from the API response — this is where wp-admin actually lives
                // and may differ from the entered URL when WordPress is installed in a subdirectory.
                $wp_site_url = ! empty( $pf_data['url'] ) ? rtrim( $pf_data['url'], '/' ) : rtrim( $target_url, '/' );

                $nonce       = wp_create_nonce( 'wp_auth_' . $wp_connect_key );
                $success_url = add_query_arg( array(
                    'wordpress_auth_callback' => '1',
                    'portal_key'              => $wp_connect_key,
                    'wp_nonce'                => $nonce,
                ), admin_url( 'admin.php?page=post-forwarder-settings' ) );
                $reject_url  = add_query_arg( array(
                    'wordpress_auth_rejected' => '1',
                    'portal_key'              => $wp_connect_key,
                ), admin_url( 'admin.php?page=post-forwarder-settings' ) );

                wp_redirect( $wp_site_url . '/wp-admin/authorize-application.php?' . http_build_query( array(
                    'app_name'    => 'Post Forwarder',
                    'success_url' => $success_url,
                    'reject_url'  => $reject_url,
                ) ) );
                exit;
            }
        }
    }

    // Normal save — redirect back with success flag (PRG).
    wp_redirect( $settings_url . '&portals_saved=1' );
    exit;
}

// REST endpoint: return (and consume) forwarding results for a post.
function post_forwarder_register_results_rest_route() {
    register_rest_route( 'post-forwarder/v1', '/results/(?P<post_id>\d+)', array(
        'methods'             => 'GET',
        'callback'            => function ( WP_REST_Request $request ) {
            $post_id = (int) $request->get_param( 'post_id' );
            $key     = 'pf_forward_results_' . get_current_user_id() . '_' . $post_id;
            $results = get_transient( $key );
            if ( ! $results || ! is_array( $results ) ) {
                return rest_ensure_response( array( 'results' => null ) );
            }
            delete_transient( $key );
            return rest_ensure_response( array( 'results' => array_values( $results ) ) );
        },
        'permission_callback' => function ( WP_REST_Request $request ) {
            return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
        },
    ) );
}

// Calendar schedule REST endpoints.
function post_forwarder_register_schedule_rest_routes() {
    $perm = function () { return current_user_can( 'manage_options' ); };

    // GET /schedule?week_start=YYYY-MM-DD
    register_rest_route( 'post-forwarder/v1', '/schedule', array(
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            $table = $wpdb->prefix . 'pf_schedule';
            $week  = sanitize_text_field( $req->get_param( 'week_start' ) ?: date( 'Y-m-d', strtotime( 'monday this week' ) ) );
            $start = date( 'Y-m-d 00:00:00', strtotime( $week ) );
            $end   = date( 'Y-m-d 23:59:59', strtotime( $week . ' +6 days' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE scheduled_at BETWEEN %s AND %s ORDER BY scheduled_at ASC",
                $start, $end
            ), ARRAY_A );
            foreach ( $rows as &$r ) {
                $r['channel_keys'] = json_decode( $r['channel_keys'], true );
                $r['result']       = json_decode( $r['result'], true );
                if ( $r['post_id'] ) {
                    $p = get_post( (int) $r['post_id'] );
                    $r['post_title'] = $p ? $p->post_title : '';
                    $r['post_url']   = $p ? get_permalink( $p ) : '';
                }
            }
            unset( $r );
            return rest_ensure_response( $rows );
        },
    ) );

    // POST /schedule — create
    register_rest_route( 'post-forwarder/v1', '/schedule', array(
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            $table        = $wpdb->prefix . 'pf_schedule';
            $post_id      = (int) ( $req->get_param( 'post_id' ) ?: 0 );
            $title        = sanitize_text_field( $req->get_param( 'title' ) ?: '' );
            $content      = wp_kses_post( $req->get_param( 'content' ) ?: '' );
            $excerpt      = sanitize_text_field( $req->get_param( 'excerpt' ) ?: '' );
            $image_url    = esc_url_raw( $req->get_param( 'image_url' ) ?: '' );
            $channel_keys = $req->get_param( 'channel_keys' ) ?: array();
            $scheduled_at = sanitize_text_field( $req->get_param( 'scheduled_at' ) ?: '' );

            if ( ! $scheduled_at ) {
                return new WP_Error( 'missing_date', 'scheduled_at is required', array( 'status' => 400 ) );
            }
            if ( empty( $channel_keys ) ) {
                return new WP_Error( 'missing_channels', 'At least one channel is required', array( 'status' => 400 ) );
            }

            // If linking an existing post, fill title/excerpt from it.
            if ( $post_id ) {
                $p = get_post( $post_id );
                if ( $p ) {
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
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table, array(
                'post_id'      => $post_id ?: null,
                'title'        => $title,
                'content'      => $content,
                'excerpt'      => $excerpt,
                'image_url'    => $image_url,
                'channel_keys' => wp_json_encode( array_values( (array) $channel_keys ) ),
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', strtotime( $scheduled_at ) ),
                'status'       => 'pending',
                'result'       => '{}',
                'created_at'   => current_time( 'mysql' ),
            ) );

            $id  = $wpdb->insert_id;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
            $row['channel_keys'] = json_decode( $row['channel_keys'], true );
            $row['result']       = json_decode( $row['result'], true );
            return rest_ensure_response( $row );
        },
    ) );

    // PUT /schedule/{id} — update
    register_rest_route( 'post-forwarder/v1', '/schedule/(?P<id>\d+)', array(
        'methods'             => 'PUT',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            $table = $wpdb->prefix . 'pf_schedule';
            $id    = (int) $req->get_param( 'id' );
            $data  = array();

            if ( null !== $req->get_param( 'scheduled_at' ) ) {
                $data['scheduled_at'] = gmdate( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $req->get_param( 'scheduled_at' ) ) ) );
            }
            if ( null !== $req->get_param( 'channel_keys' ) ) {
                $data['channel_keys'] = wp_json_encode( array_values( (array) $req->get_param( 'channel_keys' ) ) );
            }
            if ( null !== $req->get_param( 'title' ) ) {
                $data['title'] = sanitize_text_field( $req->get_param( 'title' ) );
            }
            if ( null !== $req->get_param( 'content' ) ) {
                $data['content'] = wp_kses_post( $req->get_param( 'content' ) );
            }

            if ( $data ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update( $table, $data, array( 'id' => $id ) );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
            if ( ! $row ) {
                return new WP_Error( 'not_found', 'Schedule item not found', array( 'status' => 404 ) );
            }
            $row['channel_keys'] = json_decode( $row['channel_keys'], true );
            $row['result']       = json_decode( $row['result'], true );
            return rest_ensure_response( $row );
        },
    ) );

    // DELETE /schedule/{id}
    register_rest_route( 'post-forwarder/v1', '/schedule/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            $table = $wpdb->prefix . 'pf_schedule';
            $id    = (int) $req->get_param( 'id' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $table, array( 'id' => $id ) );
            return rest_ensure_response( array( 'deleted' => true ) );
        },
    ) );

    // GET /posts?s=search — search WP posts for the picker
    register_rest_route( 'post-forwarder/v1', '/posts', array(
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function ( WP_REST_Request $req ) {
            $search = sanitize_text_field( $req->get_param( 's' ) ?: '' );
            $posts  = get_posts( array(
                'post_status'    => array( 'publish', 'draft', 'future' ),
                'posts_per_page' => 20,
                's'              => $search,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ) );
            $out = array();
            foreach ( $posts as $p ) {
                $img  = '';
                $tid  = get_post_thumbnail_id( $p->ID );
                if ( $tid ) {
                    $src = wp_get_attachment_image_src( $tid, 'thumbnail' );
                    $img = $src ? $src[0] : '';
                }
                $out[] = array(
                    'id'         => $p->ID,
                    'title'      => $p->post_title,
                    'status'     => $p->post_status,
                    'date'       => $p->post_date,
                    'excerpt'    => wp_trim_words( $p->post_excerpt ?: $p->post_content, 20 ),
                    'image_url'  => $img,
                    'permalink'  => get_permalink( $p->ID ),
                );
            }
            return rest_ensure_response( $out );
        },
    ) );
}

// Enqueue Gutenberg save-listener on post edit screens.
function post_forwarder_enqueue_scripts( $hook ) {
    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
        return;
    }
    $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
    if ( ! $post_id && isset( $_POST['post_ID'] ) ) {
        $post_id = (int) $_POST['post_ID'];
    }
    $results_url = rest_url( 'post-forwarder/v1/results/' . $post_id );
    $rest_nonce  = wp_create_nonce( 'wp_rest' );
    wp_add_inline_script( 'wp-data', '
(function() {
    if (typeof wp === "undefined" || !wp.data || !wp.data.subscribe) { return; }
    var restBase = ' . wp_json_encode( rest_url( 'post-forwarder/v1/results/' ) ) . ';
    var nonce    = ' . wp_json_encode( $rest_nonce ) . ';
    var wasSaving = false;
    wp.data.subscribe(function() {
        var editor = wp.data.select("core/editor");
        if (!editor) { return; }
        var nowSaving = editor.isSavingPost();
        if (!nowSaving && wasSaving) {
            wasSaving = false;
            var post   = editor.getCurrentPost();
            var postId = post && post.id ? post.id : 0;
            if (!postId) { return; }
            setTimeout(function() {
                fetch(restBase + postId, { headers: { "X-WP-Nonce": nonce } })
                    .then(function(r){ return r.json(); })
                    .then(function(data) {
                        if (!data || !data.results || !data.results.length) { return; }
                        var notices = wp.data.dispatch("core/notices");
                        if (!notices) { return; }
                        data.results.forEach(function(r, i) {
                            var icon  = r.success ? "✓" : "✗";
                            var badge = r.type === "linkedin" ? " [LI]" : r.type === "x" ? " [X]" : " [WP]";
                            notices.createNotice(
                                r.success ? "success" : "error",
                                "Post Forwarder" + badge + " " + icon + " " + r.name + ": " + r.message,
                                { id: "pf-result-" + i, isDismissible: true, type: "snackbar" }
                            );
                        });
                    }).catch(function(){});
            }, 800);
        } else if (nowSaving) {
            wasSaving = true;
        }
    });
})();
', 'after' );
}

// AJAX handler for Test Connection
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

// AJAX: add a new channel from the calendar modal.
function post_forwarder_ajax_add_channel() {
    if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'pf_add_channel', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }

    $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    $type = sanitize_key( wp_unslash( $_POST['type'] ?? 'wordpress' ) );
    $url  = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
    $user = sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) );
    $pass = sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) );

    if ( ! $name ) {
        wp_send_json_error( array( 'message' => __( 'Channel name is required.', 'post-forwarder' ) ) );
    }

    $allowed_types = array( 'linkedin', 'x', 'meta', 'wordpress' );
    if ( ! in_array( $type, $allowed_types, true ) ) {
        $type = 'wordpress';
    }
    if ( 'wordpress' === $type && ! $url ) {
        wp_send_json_error( array( 'message' => __( 'Site URL is required for WordPress portals.', 'post-forwarder' ) ) );
    }

    $options = get_option( 'post_forwarding_options', array() );
    if ( is_string( $options ) ) { $options = json_decode( $options, true ) ?: array(); }
    $mappings_raw = isset( $options['mappings'] ) ? $options['mappings'] : array();
    $mappings = is_array( $mappings_raw ) ? $mappings_raw : ( json_decode( is_string( $mappings_raw ) ? $mappings_raw : '{}', true ) ?: array() );

    // Generate unique key.
    $base = sanitize_title( $name ) ?: 'portal';
    $key  = $base;
    $n    = 2;
    while ( isset( $mappings[ $key ] ) ) { $key = $base . '-' . $n++; }

    switch ( $type ) {
        case 'linkedin':
            $mappings[ $key ] = array( 'type' => 'linkedin', 'name' => $name );
            break;
        case 'x':
            $mappings[ $key ] = array( 'type' => 'x', 'name' => $name );
            break;
        case 'meta':
            $mappings[ $key ] = array( 'type' => 'meta', 'name' => $name, 'post_to_facebook' => true, 'post_to_instagram' => true );
            break;
        default:
            $mappings[ $key ] = array( 'type' => 'wordpress', 'name' => $name, 'url' => $url, 'user' => $user, 'password' => $pass );
    }
    $options['mappings'] = wp_json_encode( $mappings );
    update_option( 'post_forwarding_options', $options );

    // Build OAuth / auth URL to redirect the user into the connect flow.
    $settings_url = admin_url( 'admin.php?page=post-forwarder-settings' );
    $relay        = post_forwarder_relay_url();
    $oauth_url    = null;

    if ( 'linkedin' === $type && $relay ) {
        $oauth_url = $relay . '/start?' . http_build_query( array(
            'return_url' => $settings_url,
            'portal_key' => $key,
            'wp_nonce'   => wp_create_nonce( 'linkedin_oauth_' . $key ),
            'wp_site'    => admin_url(),
        ) );
    } elseif ( 'x' === $type && $relay ) {
        $x_ajax_args = array(
            'return_url' => $settings_url,
            'portal_key' => $key,
            'wp_nonce'   => wp_create_nonce( 'x_oauth_' . $key ),
            'wp_site'    => admin_url(),
        );
        if ( ! empty( $mappings[ $key ]['client_id'] ) ) {
            $x_ajax_args['client_id'] = $mappings[ $key ]['client_id'];
        }
        if ( ! empty( $mappings[ $key ]['client_secret'] ) ) {
            $x_ajax_args['client_secret'] = $mappings[ $key ]['client_secret'];
        }
        $oauth_url = $relay . '/x/start?' . http_build_query( $x_ajax_args );
    } elseif ( 'meta' === $type && $relay ) {
        $oauth_url = $relay . '/meta/start?' . http_build_query( array(
            'return_url' => $settings_url,
            'portal_key' => $key,
            'wp_nonce'   => wp_create_nonce( 'meta_oauth_' . $key ),
            'wp_site'    => admin_url(),
        ) );
    } elseif ( 'wordpress' === $type && $url && ! $user ) {
        // App Password flow: redirect to target site's authorize page.
        $nonce       = wp_create_nonce( 'wp_auth_' . $key );
        $success_url = add_query_arg( array(
            'wordpress_auth_callback' => '1',
            'portal_key'              => $key,
            'wp_nonce'                => $nonce,
        ), $settings_url );
        $reject_url = add_query_arg( array(
            'wordpress_auth_rejected' => '1',
            'portal_key'              => $key,
        ), $settings_url );
        $oauth_url = rtrim( $url, '/' ) . '/wp-admin/authorize-application.php?' . http_build_query( array(
            'app_name'    => 'Post Forwarder',
            'success_url' => $success_url,
            'reject_url'  => $reject_url,
        ) );
    }

    $platform_colors = array(
        'linkedin'  => array( 'bg' => '#0a66c2', 'label' => 'in' ),
        'x'         => array( 'bg' => '#000000', 'label' => 'X' ),
        'meta'      => array( 'bg' => '#1877f2', 'label' => 'FB' ),
        'wordpress' => array( 'bg' => '#3858e9', 'label' => 'W' ),
    );
    $color     = $platform_colors[ $type ]['bg'] ?? '#555';
    $badge     = $platform_colors[ $type ]['label'] ?? '?';
    $connected = post_forwarder_channel_connected( $mappings[ $key ] );

    wp_send_json_success( array(
        'key'       => $key,
        'name'      => $name,
        'type'      => $type,
        'color'     => $color,
        'badge'     => $badge,
        'connected' => $connected,
        'oauth_url' => $oauth_url,
    ) );
}
