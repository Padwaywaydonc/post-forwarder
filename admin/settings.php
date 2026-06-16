<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

function post_forwarder_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'post-forwarder'));
    }
    
    $options = get_option( 'post_forwarding_options', array() );

    if ( is_string( $options ) ) {
        $options = json_decode( $options, true );
        if ( ! is_array( $options ) ) {
            $options = array();
        }
    }

    // Handle LinkedIn relay callback (relay-mode OAuth via Cloudflare Worker).
    $linkedin_oauth_notice = '';
    if ( isset( $_GET['linkedin_relay_callback'] ) ) {
        $relay_url       = post_forwarder_relay_url();
        $relay_token_key = isset( $_GET['relay_token_key'] ) ? sanitize_text_field( wp_unslash( $_GET['relay_token_key'] ) ) : '';
        $relay_error     = isset( $_GET['relay_error'] )     ? sanitize_text_field( wp_unslash( $_GET['relay_error'] ) )     : '';

        if ( $relay_error ) {
            /* translators: %s: error description returned by the relay worker */
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html( sprintf( __( 'LinkedIn connection failed: %s', 'post-forwarder' ), $relay_error ) )
                . '</p></div>';
        } elseif ( ! $relay_url ) {
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'LinkedIn connection failed: Relay URL is not configured (POST_FORWARDER_RELAY_URL constant missing).', 'post-forwarder' )
                . '</p></div>';
        } elseif ( ! $relay_token_key ) {
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'LinkedIn connection failed: relay_token_key missing from relay response.', 'post-forwarder' )
                . '</p></div>';
        } else {
            // Fetch the token payload from the relay (one-time, deleted on retrieval).
            $relay_response = wp_remote_post(
                $relay_url . '/token',
                array(
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode( array( 'key' => $relay_token_key ) ),
                    'timeout' => 15,
                )
            );

            if ( is_wp_error( $relay_response ) ) {
                $error_msg = $relay_response->get_error_message();
                post_forwarder_log_error( 'Relay /token request failed: ' . $error_msg );
                /* translators: %s: error message */
                $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                    . esc_html( sprintf( __( 'LinkedIn connection failed: %s', 'post-forwarder' ), $error_msg ) )
                    . '</p></div>';
            } else {
                $relay_http_code = wp_remote_retrieve_response_code( $relay_response );
                $payload         = json_decode( wp_remote_retrieve_body( $relay_response ), true );

                if ( 200 !== $relay_http_code || ! isset( $payload['access_token'] ) ) {
                    $err_msg = is_array( $payload ) && isset( $payload['error'] ) ? $payload['error'] : 'HTTP ' . $relay_http_code;
                    post_forwarder_log_error( 'Relay /token returned unexpected response: ' . $err_msg );
                    /* translators: %s: error detail */
                    $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                        . esc_html( sprintf( __( 'LinkedIn connection failed: Relay returned an error (%s).', 'post-forwarder' ), $err_msg ) )
                        . '</p></div>';
                } else {
                    // Verify the WordPress nonce that was embedded in the relay payload.
                    $portal_key = isset( $payload['portal_key'] ) ? sanitize_key( $payload['portal_key'] ) : '';
                    $wp_nonce   = isset( $payload['wp_nonce'] )   ? $payload['wp_nonce'] : '';

                    if ( ! $portal_key || ! wp_verify_nonce( $wp_nonce, 'linkedin_oauth_' . $portal_key ) ) {
                        $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                            . esc_html__( 'LinkedIn connection failed: Security check failed.', 'post-forwarder' )
                            . '</p></div>';
                    } else {
                        $relay_mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
                        if ( ! is_array( $relay_mappings ) ) {
                            $relay_mappings = json_decode( is_string( $relay_mappings ) ? $relay_mappings : '{}', true );
                            if ( ! is_array( $relay_mappings ) ) {
                                $relay_mappings = array();
                            }
                        }

                        if ( ! isset( $relay_mappings[ $portal_key ]['type'] ) || 'linkedin' !== $relay_mappings[ $portal_key ]['type'] ) {
                            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                                . esc_html__( 'LinkedIn connection failed: Portal configuration not found.', 'post-forwarder' )
                                . '</p></div>';
                        } else {
                            unset( $relay_mappings[ $portal_key ]['last_error'] );
                            $relay_mappings[ $portal_key ]['access_token']  = $payload['access_token'];
                            $relay_mappings[ $portal_key ]['token_expires'] = time() + (int) ( $payload['expires_in'] ?? 3600 );

                            if ( ! empty( $payload['refresh_token'] ) ) {
                                $relay_mappings[ $portal_key ]['refresh_token']         = $payload['refresh_token'];
                                $relay_mappings[ $portal_key ]['refresh_token_expires'] = time() + (int) ( $payload['refresh_token_expires_in'] ?? 2592000 );
                            }

                            if ( ! empty( $payload['person_urn'] ) ) {
                                $relay_mappings[ $portal_key ]['person_urn'] = $payload['person_urn'];
                                if ( empty( $relay_mappings[ $portal_key ]['author_urn'] ) ) {
                                    $relay_mappings[ $portal_key ]['author_urn'] = $payload['person_urn'];
                                }
                            }

                            $options['mappings'] = wp_json_encode( $relay_mappings );
                            update_option( 'post_forwarding_options', $options );
                            $options = get_option( 'post_forwarding_options', array() );
                            if ( is_string( $options ) ) {
                                $options = json_decode( $options, true );
                                if ( ! is_array( $options ) ) {
                                    $options = array();
                                }
                            }
                            $linkedin_oauth_notice = '<div class="notice notice-success is-dismissible"><p>'
                                . esc_html__( 'LinkedIn account connected successfully!', 'post-forwarder' )
                                . '</p></div>';
                        }
                    }
                }
            }
        }
    }

    // Handle X (Twitter) relay callback.
    $x_oauth_notice = '';
    if ( isset( $_GET['x_relay_callback'] ) ) {
        $x_relay_url       = post_forwarder_relay_url();
        $x_relay_token_key = isset( $_GET['relay_token_key'] ) ? sanitize_text_field( wp_unslash( $_GET['relay_token_key'] ) ) : '';
        $x_relay_error     = isset( $_GET['relay_error'] )     ? sanitize_text_field( wp_unslash( $_GET['relay_error'] ) )     : '';

        if ( $x_relay_error ) {
            /* translators: %s: error description returned by the relay worker */
            $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html( sprintf( __( 'X connection failed: %s', 'post-forwarder' ), $x_relay_error ) )
                . '</p></div>';
        } elseif ( ! $x_relay_url ) {
            $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'X connection failed: Relay URL is not configured (POST_FORWARDER_RELAY_URL constant missing).', 'post-forwarder' )
                . '</p></div>';
        } elseif ( ! $x_relay_token_key ) {
            $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'X connection failed: relay_token_key missing from relay response.', 'post-forwarder' )
                . '</p></div>';
        } else {
            $x_relay_response = wp_remote_post(
                $x_relay_url . '/token',
                array(
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode( array( 'key' => $x_relay_token_key ) ),
                    'timeout' => 15,
                )
            );

            if ( is_wp_error( $x_relay_response ) ) {
                $x_error_msg = $x_relay_response->get_error_message();
                post_forwarder_log_error( 'X Relay /token request failed: ' . $x_error_msg );
                /* translators: %s: error message */
                $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                    . esc_html( sprintf( __( 'X connection failed: %s', 'post-forwarder' ), $x_error_msg ) )
                    . '</p></div>';
            } else {
                $x_relay_http_code = wp_remote_retrieve_response_code( $x_relay_response );
                $x_payload         = json_decode( wp_remote_retrieve_body( $x_relay_response ), true );

                if ( 200 !== $x_relay_http_code || ! isset( $x_payload['access_token'] ) ) {
                    $x_err_msg = is_array( $x_payload ) && isset( $x_payload['error'] ) ? $x_payload['error'] : 'HTTP ' . $x_relay_http_code;
                    post_forwarder_log_error( 'X Relay /token returned unexpected response: ' . $x_err_msg );
                    /* translators: %s: error detail */
                    $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                        . esc_html( sprintf( __( 'X connection failed: Relay returned an error (%s).', 'post-forwarder' ), $x_err_msg ) )
                        . '</p></div>';
                } else {
                    $x_portal_key = isset( $x_payload['portal_key'] ) ? sanitize_key( $x_payload['portal_key'] ) : '';
                    $x_wp_nonce   = isset( $x_payload['wp_nonce'] )   ? $x_payload['wp_nonce'] : '';

                    if ( ! $x_portal_key || ! wp_verify_nonce( $x_wp_nonce, 'x_oauth_' . $x_portal_key ) ) {
                        $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                            . esc_html__( 'X connection failed: Security check failed.', 'post-forwarder' )
                            . '</p></div>';
                    } else {
                        $x_relay_mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
                        if ( ! is_array( $x_relay_mappings ) ) {
                            $x_relay_mappings = json_decode( is_string( $x_relay_mappings ) ? $x_relay_mappings : '{}', true );
                            if ( ! is_array( $x_relay_mappings ) ) {
                                $x_relay_mappings = array();
                            }
                        }

                        if ( ! isset( $x_relay_mappings[ $x_portal_key ]['type'] ) || 'x' !== $x_relay_mappings[ $x_portal_key ]['type'] ) {
                            $x_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                                . esc_html__( 'X connection failed: Portal configuration not found.', 'post-forwarder' )
                                . '</p></div>';
                        } else {
                            unset( $x_relay_mappings[ $x_portal_key ]['last_error'] );
                            $x_relay_mappings[ $x_portal_key ]['access_token']  = $x_payload['access_token'];
                            $expires_in = isset( $x_payload['expires_in'] ) && $x_payload['expires_in'] > 0
                                ? (int) $x_payload['expires_in']
                                : 7200;
                            $x_relay_mappings[ $x_portal_key ]['token_expires'] = time() + $expires_in;

                            if ( ! empty( $x_payload['refresh_token'] ) ) {
                                $x_relay_mappings[ $x_portal_key ]['refresh_token']         = $x_payload['refresh_token'];
                                $x_relay_mappings[ $x_portal_key ]['refresh_token_expires'] = time() + (int) ( $x_payload['refresh_token_expires_in'] ?? 7776000 );
                            }
                            if ( ! empty( $x_payload['x_user_id'] ) ) {
                                $x_relay_mappings[ $x_portal_key ]['x_user_id'] = sanitize_text_field( $x_payload['x_user_id'] );
                            }
                            if ( ! empty( $x_payload['x_username'] ) ) {
                                $x_relay_mappings[ $x_portal_key ]['x_username'] = sanitize_text_field( $x_payload['x_username'] );
                            }

                            $options['mappings'] = wp_json_encode( $x_relay_mappings );
                            update_option( 'post_forwarding_options', $options );
                            $options = get_option( 'post_forwarding_options', array() );
                            if ( is_string( $options ) ) {
                                $options = json_decode( $options, true );
                                if ( ! is_array( $options ) ) {
                                    $options = array();
                                }
                            }
                            $x_oauth_notice = '<div class="notice notice-success is-dismissible"><p>'
                                . esc_html__( 'X account connected successfully!', 'post-forwarder' )
                                . '</p></div>';
                        }
                    }
                }
            }
        }
    }

    // Handle WordPress Application Password authorization callbacks.
    $wp_auth_notice = '';

    if ( isset( $_GET['wordpress_auth_callback'] ) ) {
        $wp_portal_key = isset( $_GET['portal_key'] ) ? sanitize_key( wp_unslash( $_GET['portal_key'] ) ) : '';
        $wp_nonce_val  = isset( $_GET['wp_nonce'] )   ? sanitize_text_field( wp_unslash( $_GET['wp_nonce'] ) ) : '';

        if ( ! $wp_portal_key || ! wp_verify_nonce( $wp_nonce_val, 'wp_auth_' . $wp_portal_key ) ) {
            $wp_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'WordPress connection failed: security check failed.', 'post-forwarder' ) . '</p></div>';
        } else {
            $cb_user_login = isset( $_GET['user_login'] ) ? sanitize_user( wp_unslash( $_GET['user_login'] ), true ) : '';
            $cb_password   = isset( $_GET['password'] )   ? sanitize_text_field( wp_unslash( $_GET['password'] ) )  : '';

            if ( ! $cb_user_login || ! $cb_password ) {
                $wp_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'WordPress connection failed: credentials missing from callback.', 'post-forwarder' ) . '</p></div>';
            } else {
                $cb_mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
                if ( ! is_array( $cb_mappings ) ) {
                    $cb_mappings = json_decode( is_string( $cb_mappings ) ? $cb_mappings : '{}', true );
                    if ( ! is_array( $cb_mappings ) ) {
                        $cb_mappings = array();
                    }
                }
                if ( ! isset( $cb_mappings[ $wp_portal_key ]['type'] ) || 'wordpress' !== $cb_mappings[ $wp_portal_key ]['type'] ) {
                    $wp_auth_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'WordPress connection failed: portal configuration not found.', 'post-forwarder' ) . '</p></div>';
                } else {
                    unset( $cb_mappings[ $wp_portal_key ]['last_error'] );
                    $cb_mappings[ $wp_portal_key ]['user']         = $cb_user_login;
                    $cb_mappings[ $wp_portal_key ]['password']     = $cb_password;
                    $cb_mappings[ $wp_portal_key ]['wp_auth_mode'] = 'button';
                    // Store the WordPress site URL (where REST API / wp-admin actually live).
                    if ( ! empty( $_GET['site_url'] ) ) {
                        $cb_mappings[ $wp_portal_key ]['wp_site_url'] = esc_url_raw( wp_unslash( $_GET['site_url'] ) );
                    }
                    $options['mappings'] = wp_json_encode( $cb_mappings );
                    update_option( 'post_forwarding_options', $options );
                    $options = get_option( 'post_forwarding_options', array() );
                    if ( is_string( $options ) ) {
                        $options = json_decode( $options, true );
                        if ( ! is_array( $options ) ) {
                            $options = array();
                        }
                    }
                    $wp_auth_notice = '<div class="notice notice-success is-dismissible"><p>'
                        . esc_html( sprintf( __( 'WordPress site connected successfully as %s!', 'post-forwarder' ), $cb_user_login ) )
                        . '</p></div>';
                }
            }
        }
    }

    if ( isset( $_GET['wordpress_auth_rejected'] ) ) {
        $wp_auth_notice = '<div class="notice notice-warning is-dismissible"><p>'
            . esc_html__( 'WordPress connection was cancelled. You can try again or enter credentials manually.', 'post-forwarder' )
            . '</p></div>';
    }

    if ( isset( $_GET['wp_connect_error'] ) && 'preflight_failed' === $_GET['wp_connect_error'] ) {
        $wp_auth_notice = '<div class="notice notice-error is-dismissible"><p>'
            . esc_html__( 'WordPress connection failed: could not reach the REST API on the target site. Make sure the URL is correct and the site runs WordPress 5.6+.', 'post-forwarder' )
            . '</p></div>';
    }

    // Handle LinkedIn direct OAuth callback (non-relay mode).
    if ( '' === $linkedin_oauth_notice && isset( $_GET['linkedin_oauth_callback'] ) ) {
        if ( isset( $_GET['error'] ) || isset( $_GET['error_description'] ) ) {
            $li_error_desc = isset( $_GET['error_description'] )
                ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) )
                : sanitize_text_field( wp_unslash( $_GET['error'] ) );

            // Store the error on the portal so it shows in the UI.
            if ( isset( $_GET['state'] ) ) {
                $err_state_parts = explode( '|', sanitize_text_field( wp_unslash( $_GET['state'] ) ) );
                if ( count( $err_state_parts ) === 2 ) {
                    $err_portal_key = sanitize_key( $err_state_parts[0] );
                    $err_mappings   = isset( $options['mappings'] ) ? $options['mappings'] : array();
                    if ( ! is_array( $err_mappings ) ) {
                        $err_mappings = json_decode( is_string( $err_mappings ) ? $err_mappings : '{}', true );
                        if ( ! is_array( $err_mappings ) ) {
                            $err_mappings = array();
                        }
                    }
                    if ( isset( $err_mappings[ $err_portal_key ] ) ) {
                        $err_mappings[ $err_portal_key ]['last_error'] = $li_error_desc;
                        $options['mappings'] = wp_json_encode( $err_mappings );
                        update_option( 'post_forwarding_options', $options );
                        $options = get_option( 'post_forwarding_options', array() );
                        if ( is_string( $options ) ) {
                            $options = json_decode( $options, true );
                            if ( ! is_array( $options ) ) {
                                $options = array();
                            }
                        }
                    }
                }
            }

            /* translators: %s: error description returned by LinkedIn */
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html( sprintf( __( 'LinkedIn connection failed: %s', 'post-forwarder' ), $li_error_desc ) )
                . '</p></div>';

        } elseif ( isset( $_GET['code'], $_GET['state'] ) ) {
            $state_parts = explode( '|', sanitize_text_field( wp_unslash( $_GET['state'] ) ) );

            if ( count( $state_parts ) !== 2 ) {
                $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                    . esc_html__( 'LinkedIn connection failed: Invalid state parameter.', 'post-forwarder' )
                    . '</p></div>';
            } else {
                $portal_key = sanitize_key( $state_parts[0] );
                $nonce_val  = $state_parts[1];

                if ( ! wp_verify_nonce( $nonce_val, 'linkedin_oauth_' . $portal_key ) ) {
                    $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                        . esc_html__( 'LinkedIn connection failed: Security check failed.', 'post-forwarder' )
                        . '</p></div>';
                } else {
                    $mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
                    if ( ! is_array( $mappings ) ) {
                        $mappings = json_decode( is_string( $mappings ) ? $mappings : '{}', true );
                        if ( ! is_array( $mappings ) ) {
                            $mappings = array();
                        }
                    }

                    if ( ! isset( $mappings[ $portal_key ]['type'] ) || 'linkedin' !== $mappings[ $portal_key ]['type'] ) {
                        $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                            . esc_html__( 'LinkedIn connection failed: Portal configuration not found.', 'post-forwarder' )
                            . '</p></div>';
                    } else {
                        $redirect_uri = admin_url( 'admin.php?page=post-forwarder-settings&linkedin_oauth_callback=1' );
                        $auth_code    = sanitize_text_field( wp_unslash( $_GET['code'] ) );

                        $token_response = wp_remote_post(
                            'https://www.linkedin.com/oauth/v2/accessToken',
                            array(
                                'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
                                'body'    => array(
                                    'grant_type'    => 'authorization_code',
                                    'code'          => $auth_code,
                                    'client_id'     => post_forwarder_linkedin_client_id( isset( $mappings[ $portal_key ]['client_id'] ) ? $mappings[ $portal_key ]['client_id'] : '' ),
                                    'client_secret' => post_forwarder_linkedin_client_secret( isset( $mappings[ $portal_key ]['client_secret'] ) ? $mappings[ $portal_key ]['client_secret'] : '' ),
                                    'redirect_uri'  => $redirect_uri,
                                ),
                                'timeout' => 30,
                            )
                        );

                        if ( is_wp_error( $token_response ) ) {
                            $error_msg = $token_response->get_error_message();
                            post_forwarder_log_error( 'LinkedIn token request failed: ' . $error_msg );
                            /* translators: %s: error message */
                            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                                . esc_html( sprintf( __( 'LinkedIn connection failed: %s', 'post-forwarder' ), $error_msg ) )
                                . '</p></div>';
                        } else {
                            $http_code  = wp_remote_retrieve_response_code( $token_response );
                            $token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );

                            if ( 200 === $http_code && isset( $token_data['access_token'] ) ) {
                                unset( $mappings[ $portal_key ]['last_error'] );
                                $mappings[ $portal_key ]['access_token']  = $token_data['access_token'];
                                $mappings[ $portal_key ]['token_expires'] = time() + (int) ( isset( $token_data['expires_in'] ) ? $token_data['expires_in'] : 3600 );
                                if ( isset( $token_data['refresh_token'] ) ) {
                                    $mappings[ $portal_key ]['refresh_token']         = $token_data['refresh_token'];
                                    $mappings[ $portal_key ]['refresh_token_expires'] = time() + (int) ( isset( $token_data['refresh_token_expires_in'] ) ? $token_data['refresh_token_expires_in'] : 2592000 );
                                }

                                // Fetch the member's person URN via the OpenID Connect userinfo endpoint (requires openid + profile scopes).
                                $me_response = wp_remote_get(
                                    'https://api.linkedin.com/v2/userinfo',
                                    array(
                                        'headers' => array( 'Authorization' => 'Bearer ' . $token_data['access_token'] ),
                                        'timeout' => 10,
                                    )
                                );
                                if ( ! is_wp_error( $me_response ) && 200 === wp_remote_retrieve_response_code( $me_response ) ) {
                                    $me_data = json_decode( wp_remote_retrieve_body( $me_response ), true );
                                    // The `sub` field contains the member ID (e.g. "AbCdEf123").
                                    if ( isset( $me_data['sub'] ) ) {
                                        $mappings[ $portal_key ]['person_urn'] = 'urn:li:person:' . $me_data['sub'];
                                    }
                                }

                                $options['mappings'] = wp_json_encode( $mappings );
                                update_option( 'post_forwarding_options', $options );
                                $options = get_option( 'post_forwarding_options', array() );
                                if ( is_string( $options ) ) {
                                    $options = json_decode( $options, true );
                                    if ( ! is_array( $options ) ) {
                                        $options = array();
                                    }
                                }
                                $linkedin_oauth_notice = '<div class="notice notice-success is-dismissible"><p>'
                                    . esc_html__( 'LinkedIn account connected successfully!', 'post-forwarder' )
                                    . '</p></div>';
                            } else {
                                $err = isset( $token_data['error_description'] )
                                    ? $token_data['error_description']
                                    : ( isset( $token_data['error'] ) ? $token_data['error'] : 'Unknown error' );
                                post_forwarder_log_error( 'LinkedIn token exchange failed: ' . $err . ' (HTTP ' . $http_code . ')' );
                                $mappings[ $portal_key ]['last_error'] = $err;
                                $options['mappings'] = wp_json_encode( $mappings );
                                update_option( 'post_forwarding_options', $options );
                                /* translators: 1: error message, 2: HTTP status code */
                                $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                                    . esc_html( sprintf( __( 'LinkedIn connection failed: %s (HTTP %d)', 'post-forwarder' ), $err, $http_code ) )
                                    . '</p></div>';
                            }
                        }
                    }
                }
            }
        } else {
            $linkedin_oauth_notice = '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__( 'LinkedIn connection failed: Missing authorization code.', 'post-forwarder' )
                . '</p></div>';
        }
    }

    // Show success notice after PRG redirect.
    if ( isset( $_GET['portals_saved'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Accounts saved successfully!', 'post-forwarder' ) . '</p></div>';
    }

    // Re-read options here so any OAuth callbacks that ran above (and updated the DB) are reflected.
    $options = get_option( 'post_forwarding_options', array() );
    if ( is_string( $options ) ) {
        $options = json_decode( $options, true );
        if ( ! is_array( $options ) ) { $options = array(); }
    }

    // Parse existing mappings.
    $mappings = isset( $options['mappings'] ) ? $options['mappings'] : array();
    if ( ! is_array( $mappings ) ) {
        $mappings_json = is_string( $mappings ) ? $mappings : '{}';
        $mappings      = json_decode( $mappings_json, true );
        if ( ! is_array( $mappings ) ) {
            $mappings = array();
        }
    }

    // True when app-wide LinkedIn credentials are configured as PHP constants.
    $li_creds_from_constants = defined( 'POST_FORWARDER_LINKEDIN_CLIENT_ID' )
        && defined( 'POST_FORWARDER_LINKEDIN_CLIENT_SECRET' )
        && POST_FORWARDER_LINKEDIN_CLIENT_ID
        && POST_FORWARDER_LINKEDIN_CLIENT_SECRET;

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php echo wp_kses_post($linkedin_oauth_notice); ?>
        <?php echo wp_kses_post($x_oauth_notice); ?>
        <?php echo wp_kses_post( $wp_auth_notice ); ?>
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

        <h2><?php esc_html_e('Connection Configuration', 'post-forwarder'); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('save_portals', 'portals_nonce'); ?>
            <input type="hidden" name="pending_linkedin_connect" value="">
            <input type="hidden" name="pending_x_connect" value="">
            <input type="hidden" name="pending_meta_connect" value="">
            <input type="hidden" name="pending_wp_connect" value="">
            
            <div id="portals-container">

                <?php if ( empty( $mappings ) ) : ?>
                    <div class="portal-row" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;" data-type="wordpress">
                        <h4><?php esc_html_e( 'Account #1', 'post-forwarder' ); ?></h4>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'Account Type', 'post-forwarder' ); ?></th>
                                <td>
                                    <select name="portals[0][type]" class="portal-type-select">
                                        <option value="wordpress"><?php esc_html_e( 'WordPress Portal', 'post-forwarder' ); ?></option>
                                        <option value="linkedin"><?php esc_html_e( 'LinkedIn Account', 'post-forwarder' ); ?></option>
                                        <option value="x"><?php esc_html_e( 'X (Twitter) Account', 'post-forwarder' ); ?></option>
                                        <option value="meta"><?php esc_html_e( 'Meta (Facebook + Instagram)', 'post-forwarder' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Account Name', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][name]" placeholder="<?php esc_attr_e( 'e.g., Example Portal', 'post-forwarder' ); ?>" style="width: 300px;" /></td>
                            </tr>
                            <tr class="fields-wordpress">
                                <th><?php esc_html_e( 'URL', 'post-forwarder' ); ?></th>
                                <td><input type="url" name="portals[0][url]" placeholder="https://example.com" style="width: 400px;" /></td>
                            </tr>
                            <tr class="fields-wordpress">
                                <th><?php esc_html_e( 'Connection', 'post-forwarder' ); ?></th>
                                <td>
                                    <button type="button" class="button save-and-connect-wp" style="background:#3858e9;border-color:#3858e9;color:#fff;">
                                        &#10132; <?php esc_html_e( 'Save & Connect with WordPress', 'post-forwarder' ); ?>
                                    </button>
                                    <p class="description" style="margin-top:6px;">
                                        <a href="#" class="wp-manual-toggle"><?php esc_html_e( 'Enter credentials manually instead', 'post-forwarder' ); ?></a>
                                    </p>
                                </td>
                            </tr>
                            <tr class="fields-wordpress wp-manual-fields" style="display:none;">
                                <th><?php esc_html_e( 'Username / User ID', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][user]" placeholder="<?php esc_attr_e( 'username or user ID', 'post-forwarder' ); ?>" style="width: 200px;" /></td>
                            </tr>
                            <tr class="fields-wordpress wp-manual-fields" style="display:none;">
                                <th><?php esc_html_e( 'App Password', 'post-forwarder' ); ?></th>
                                <td><input type="password" name="portals[0][password]" placeholder="xxxx xxxx xxxx xxxx" style="width: 300px;" /></td>
                            </tr>
                            <?php if ( ! $li_creds_from_constants ) : ?>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Client ID', 'post-forwarder' ); ?></th>
                                <td><input type="text" name="portals[0][client_id]" placeholder="<?php esc_attr_e( 'LinkedIn App Client ID', 'post-forwarder' ); ?>" style="width: 300px;" /></td>
                            </tr>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Client Secret', 'post-forwarder' ); ?></th>
                                <td><input type="password" name="portals[0][client_secret]" placeholder="<?php esc_attr_e( 'LinkedIn App Client Secret', 'post-forwarder' ); ?>" style="width: 300px;" /></td>
                            </tr>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Author URN', 'post-forwarder' ); ?></th>
                                <td>
                                    <input type="text" name="portals[0][author_urn]" placeholder="urn:li:person:XXXX or urn:li:organization:XXXX" style="width: 420px;" />
                                    <p class="description"><?php esc_html_e( 'Your LinkedIn person or organization URN.', 'post-forwarder' ); ?></p>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr class="fields-linkedin" style="display:none;">
                                <th><?php esc_html_e( 'Connection Status', 'post-forwarder' ); ?></th>
                                <td>
                                    <?php if ( post_forwarder_relay_url() ) : ?>
                                        <button type="button" class="button save-and-connect-linkedin" style="background:#0a66c2;border-color:#0a66c2;color:#fff;">&#10132; <?php esc_html_e( 'Save & Connect with LinkedIn', 'post-forwarder' ); ?></button>
                                    <?php else : ?>
                                        <span style="color:#666;"><?php esc_html_e( 'Save the account first, then click Connect with LinkedIn.', 'post-forwarder' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr class="fields-x" style="display:none;">
                                <th><?php esc_html_e( 'Client ID', 'post-forwarder' ); ?></th>
                                <td>
                                    <input type="text" name="portals[0][client_id]" placeholder="<?php esc_attr_e( 'X App Client ID', 'post-forwarder' ); ?>" style="width: 300px;" />
                                    <p class="description"><?php esc_html_e( 'Your X (Twitter) developer app Client ID. Required to use your own API quota.', 'post-forwarder' ); ?></p>
                                </td>
                            </tr>
                            <tr class="fields-x" style="display:none;">
                                <th><?php esc_html_e( 'Client Secret', 'post-forwarder' ); ?></th>
                                <td>
                                    <input type="password" name="portals[0][client_secret]" placeholder="<?php esc_attr_e( 'X App Client Secret', 'post-forwarder' ); ?>" style="width: 300px;" />
                                    <p class="description"><?php esc_html_e( 'Your X (Twitter) developer app Client Secret.', 'post-forwarder' ); ?></p>
                                </td>
                            </tr>
                            <tr class="fields-x" style="display:none;">
                                <th><?php esc_html_e( 'Connection Status', 'post-forwarder' ); ?></th>
                                <td>
                                    <?php if ( post_forwarder_relay_url() ) : ?>
                                        <button type="button" class="button save-and-connect-x" style="background:#000;border-color:#000;color:#fff;">&#10132; <?php esc_html_e( 'Save & Connect with X', 'post-forwarder' ); ?></button>
                                    <?php else : ?>
                                        <span style="color:#666;"><?php esc_html_e( 'X connection requires the relay. Configure POST_FORWARDER_RELAY_URL first.', 'post-forwarder' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr class="fields-meta" style="display:none;">
                                <th><?php esc_html_e( 'Post to Facebook', 'post-forwarder' ); ?></th>
                                <td><label><input type="checkbox" name="portals[0][post_to_facebook]" value="1" checked> <?php esc_html_e( 'Post to Facebook Page', 'post-forwarder' ); ?></label></td>
                            </tr>
                            <tr class="fields-meta" style="display:none;">
                                <th><?php esc_html_e( 'Post to Instagram', 'post-forwarder' ); ?></th>
                                <td><label><input type="checkbox" name="portals[0][post_to_instagram]" value="1" checked> <?php esc_html_e( 'Post to Instagram (requires Professional account linked to Page)', 'post-forwarder' ); ?></label></td>
                            </tr>
                            <tr class="fields-meta" style="display:none;">
                                <th><?php esc_html_e( 'Connection Status', 'post-forwarder' ); ?></th>
                                <td>
                                    <?php if ( post_forwarder_relay_url() ) : ?>
                                        <button type="button" class="button save-and-connect-meta" style="background:#1877f2;border-color:#1877f2;color:#fff;">&#10132; <?php esc_html_e( 'Save & Connect with Meta', 'post-forwarder' ); ?></button>
                                    <?php else : ?>
                                        <span style="color:#666;"><?php esc_html_e( 'Meta connection requires the relay.', 'post-forwarder' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <button type="button" class="button test-connection" style="margin-right: 8px;"><?php esc_html_e( 'Test Connection', 'post-forwarder' ); ?></button>
                        <span class="connection-result" style="font-weight: 600;"></span>
                        <button type="button" class="button remove-portal" style="float: right;"><?php esc_html_e( 'Remove Account', 'post-forwarder' ); ?></button>
                    </div>
                <?php else: ?>
                    <?php $i = 0; foreach ($mappings as $key => $mapping): ?>
                        <?php
                        $mapping_type  = isset($mapping['type']) ? $mapping['type'] : 'wordpress';
                        $is_linkedin  = ($mapping_type === 'linkedin');
                        $is_connected   = $is_linkedin
                            && ! empty( $mapping['access_token'] )
                            && ( ! isset( $mapping['token_expires'] ) || $mapping['token_expires'] > time() );
                        $has_credentials = $li_creds_from_constants || (bool) post_forwarder_relay_url() || ! empty( $mapping['client_id'] );
                        $oauth_redirect  = admin_url( 'admin.php?page=post-forwarder-settings&linkedin_oauth_callback=1' );
                        $oauth_state     = $key . '|' . wp_create_nonce( 'linkedin_oauth_' . $key );

                        // Build the OAuth start URL: use the relay when configured, otherwise
                        // hit LinkedIn directly (requires a pre-registered redirect URI).
                        $relay_url_val = post_forwarder_relay_url();
                        if ( $relay_url_val ) {
                            $oauth_url = $relay_url_val . '/start?' . http_build_query( array(
                                'return_url' => admin_url( 'admin.php?page=post-forwarder-settings' ),
                                'portal_key' => $key,
                                'wp_nonce'   => wp_create_nonce( 'linkedin_oauth_' . $key ),
                            ) );
                        } else {
                            $oauth_url = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query( array(
                                'response_type' => 'code',
                                'client_id'     => post_forwarder_linkedin_client_id( isset( $mapping['client_id'] ) ? $mapping['client_id'] : '' ),
                                'redirect_uri'  => $oauth_redirect,
                                'state'         => $oauth_state,
                                'scope'         => 'w_member_social openid profile',
                            ) );
                        }

                        $is_x           = ( 'x' === $mapping_type );
                        $is_x_connected = $is_x
                            && ! empty( $mapping['access_token'] )
                            && ( ! isset( $mapping['token_expires'] ) || $mapping['token_expires'] > time() );
                        if ( $is_x && $relay_url_val ) {
                            $x_oauth_args = array(
                                'return_url' => admin_url( 'admin.php?page=post-forwarder-settings' ),
                                'portal_key' => $key,
                                'wp_nonce'   => wp_create_nonce( 'x_oauth_' . $key ),
                            );
                            if ( ! empty( $mapping['client_id'] ) )     { $x_oauth_args['client_id']     = $mapping['client_id']; }
                            if ( ! empty( $mapping['client_secret'] ) ) { $x_oauth_args['client_secret'] = $mapping['client_secret']; }
                            $x_oauth_url = $relay_url_val . '/x/start?' . http_build_query( $x_oauth_args );
                        } else {
                            $x_oauth_url = '';
                        }

                        $is_meta           = ( 'meta' === $mapping_type );
                        $is_meta_connected = $is_meta
                            && ! empty( $mapping['access_token'] )
                            && ! empty( $mapping['page_id'] );
                        $meta_oauth_url    = ( $is_meta && $relay_url_val )
                            ? $relay_url_val . '/meta/start?' . http_build_query( array(
                                'return_url' => admin_url( 'admin.php?page=post-forwarder-settings' ),
                                'portal_key' => $key,
                                'wp_nonce'   => wp_create_nonce( 'meta_oauth_' . $key ),
                            ) )
                            : '';

                        $wp_button_connected = ( 'wordpress' === $mapping_type )
                            && ! empty( $mapping['user'] )
                            && ! empty( $mapping['password'] )
                            && isset( $mapping['wp_auth_mode'] ) && 'button' === $mapping['wp_auth_mode'];
                        $wp_manual_has_data  = ( 'wordpress' === $mapping_type )
                            && ( ! empty( $mapping['user'] ) || ! empty( $mapping['password'] ) )
                            && ! $wp_button_connected;

                        // Determine overall connected state and build summary info for the compact header.
                        $is_portal_connected = $is_connected || $is_x_connected || $is_meta_connected || $wp_button_connected || $wp_manual_has_data;

                        if ( $is_linkedin ) {
                            $summary_badge = '<span style="background:#0a66c2;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;flex-shrink:0;">LI</span>';
                            if ( $is_connected ) {
                                $summary_status = '<span style="color:#00a32a;font-weight:600;">&#10003; Connected</span>';
                                if ( ! empty( $mapping['person_urn'] ) ) {
                                    $summary_status .= ' <span style="color:#666;font-size:12px;">' . esc_html( $mapping['person_urn'] ) . '</span>';
                                }
                                if ( isset( $mapping['token_expires'] ) ) {
                                    $exp_label = $mapping['token_expires'] > time()
                                        ? esc_html( sprintf( __( 'Token expires: %s', 'post-forwarder' ), date_i18n( get_option( 'date_format' ), $mapping['token_expires'] ) ) )
                                        : '<span style="color:#cc0000;">' . esc_html__( 'Token expired', 'post-forwarder' ) . '</span>';
                                    $summary_status .= ' <span style="color:#999;font-size:11px;margin-left:6px;">· ' . $exp_label . '</span>';
                                }
                                $summary_action = '<a href="' . esc_url( $oauth_url ) . '" class="button button-secondary button-small">' . esc_html__( 'Reconnect', 'post-forwarder' ) . '</a>';
                            } else {
                                $summary_status = '<span style="color:#999;">' . esc_html__( 'Not connected', 'post-forwarder' ) . '</span>';
                                $summary_action = $has_credentials
                                    ? '<a href="' . esc_url( $oauth_url ) . '" class="button button-small" style="background:#0a66c2;border-color:#0a66c2;color:#fff;">&#10132; ' . esc_html__( 'Connect', 'post-forwarder' ) . '</a>'
                                    : '';
                            }
                        } elseif ( $is_x ) {
                            $summary_badge = '<span style="background:#000;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;flex-shrink:0;">X</span>';
                            if ( $is_x_connected ) {
                                $summary_status = '<span style="color:#00a32a;font-weight:600;">&#10003; Connected</span>';
                                if ( ! empty( $mapping['x_username'] ) ) {
                                    $summary_status .= ' <span style="color:#666;font-size:12px;">@' . esc_html( $mapping['x_username'] ) . '</span>';
                                }
                                if ( isset( $mapping['token_expires'] ) ) {
                                    $exp_label = $mapping['token_expires'] > time()
                                        ? esc_html( sprintf( __( 'Token expires: %s', 'post-forwarder' ), date_i18n( get_option( 'date_format' ), $mapping['token_expires'] ) ) )
                                        : '<span style="color:#cc0000;">' . esc_html__( 'Token expired', 'post-forwarder' ) . '</span>';
                                    $summary_status .= ' <span style="color:#999;font-size:11px;margin-left:6px;">· ' . $exp_label . '</span>';
                                }
                                $summary_action = $x_oauth_url
                                    ? '<a href="' . esc_url( $x_oauth_url ) . '" class="button button-secondary button-small">' . esc_html__( 'Reconnect', 'post-forwarder' ) . '</a>'
                                    : '';
                            } else {
                                $summary_status = '<span style="color:#999;">' . esc_html__( 'Not connected', 'post-forwarder' ) . '</span>';
                                $summary_action = $x_oauth_url
                                    ? '<a href="' . esc_url( $x_oauth_url ) . '" class="button button-small" style="background:#000;border-color:#000;color:#fff;">&#10132; ' . esc_html__( 'Connect', 'post-forwarder' ) . '</a>'
                                    : '';
                            }
                        } elseif ( $is_meta ) {
                            $summary_badge = '<span style="background:#1877f2;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;flex-shrink:0;">FB</span>';
                            if ( $is_meta_connected ) {
                                $summary_status = '<span style="color:#00a32a;font-weight:600;">&#10003; Connected</span>';
                                if ( ! empty( $mapping['page_name'] ) ) {
                                    $summary_status .= ' <span style="color:#666;font-size:12px;">' . esc_html( $mapping['page_name'] ) . '</span>';
                                }
                                if ( ! empty( $mapping['instagram_account_id'] ) ) {
                                    $summary_status .= ' <span style="color:#999;font-size:11px;margin-left:4px;">· Instagram linked</span>';
                                }
                                $summary_action = $meta_oauth_url
                                    ? '<a href="' . esc_url( $meta_oauth_url ) . '" class="button button-secondary button-small">' . esc_html__( 'Reconnect', 'post-forwarder' ) . '</a>'
                                    : '';
                            } else {
                                $summary_status = '<span style="color:#999;">' . esc_html__( 'Not connected', 'post-forwarder' ) . '</span>';
                                $summary_action = $meta_oauth_url
                                    ? '<a href="' . esc_url( $meta_oauth_url ) . '" class="button button-small" style="background:#1877f2;border-color:#1877f2;color:#fff;">&#10132; ' . esc_html__( 'Connect', 'post-forwarder' ) . '</a>'
                                    : '';
                            }
                        } else {
                            $summary_badge = '<span style="background:#3858e9;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;flex-shrink:0;">WP</span>';
                            if ( $wp_button_connected || $wp_manual_has_data ) {
                                $summary_status = '<span style="color:#00a32a;font-weight:600;">&#10003; Connected</span>';
                                if ( ! empty( $mapping['user'] ) ) {
                                    $summary_status .= ' <span style="color:#666;font-size:12px;">' . esc_html( $mapping['user'] ) . '</span>';
                                }
                                if ( ! empty( $mapping['url'] ) ) {
                                    $summary_status .= ' <span style="color:#999;font-size:11px;margin-left:4px;">· ' . esc_html( wp_parse_url( $mapping['url'], PHP_URL_HOST ) ) . '</span>';
                                }
                                $summary_action = '<button type="button" class="button button-secondary button-small save-and-connect-wp">' . esc_html__( 'Reconnect', 'post-forwarder' ) . '</button>';
                            } else {
                                $summary_status = '<span style="color:#999;">' . esc_html__( 'Not connected', 'post-forwarder' ) . '</span>';
                                $summary_action = '';
                            }
                        }
                        ?>
                        <div class="portal-row" style="border:1px solid #ddd;border-radius:4px;margin-bottom:10px;overflow:hidden;" data-type="<?php echo esc_attr($mapping_type); ?>">

                            <?php if ( $is_portal_connected ) : ?>
                            <div class="portal-summary" style="display:flex;align-items:center;gap:10px;padding:10px 15px;background:#fafafa;cursor:pointer;" title="<?php esc_attr_e( 'Click to expand', 'post-forwarder' ); ?>">
                                <?php echo $summary_badge; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <strong style="flex-shrink:0;"><?php echo esc_html( $mapping['name'] ); ?></strong>
                                <span style="color:#bbb;font-size:11px;flex-shrink:0;"><?php echo esc_html( $key ); ?></span>
                                <span style="flex:1;min-width:0;"><?php echo wp_kses( $summary_status, array( 'span' => array( 'style' => array() ) ) ); ?></span>
                                <?php echo wp_kses( $summary_action, array( 'a' => array( 'href' => array(), 'class' => array(), 'style' => array() ), 'button' => array( 'type' => array(), 'class' => array(), 'style' => array() ) ) ); ?>
                                <button type="button" class="button button-small portal-expand-btn" style="flex-shrink:0;">
                                    <?php esc_html_e( 'Edit', 'post-forwarder' ); ?> &#9660;
                                </button>
                            </div>
                            <?php endif; ?>

                            <div class="portal-detail" style="padding:15px;<?php echo $is_portal_connected ? 'display:none;' : ''; ?>">
                            <?php if ( ! $is_portal_connected ) : ?>
                            <h4 style="margin-top:0;">
                                <?php echo esc_html( sprintf( __( 'Account #%d', 'post-forwarder' ), $i + 1 ) ); ?>
                            </h4>
                            <?php endif; ?>
                            <table class="form-table" style="margin-top:0;">
                                <tr>
                                    <th><?php esc_html_e('Account Type', 'post-forwarder'); ?></th>
                                    <td>
                                        <select name="portals[<?php echo esc_attr($i); ?>][type]" class="portal-type-select">
                                            <option value="wordpress" <?php selected($mapping_type, 'wordpress'); ?>><?php esc_html_e('WordPress Portal', 'post-forwarder'); ?></option>
                                            <option value="linkedin"  <?php selected($mapping_type, 'linkedin');   ?>><?php esc_html_e('LinkedIn Account',  'post-forwarder'); ?></option>
                                            <option value="x"         <?php selected($mapping_type, 'x');         ?>><?php esc_html_e('X (Twitter) Account', 'post-forwarder'); ?></option>
                                            <option value="meta"      <?php selected($mapping_type, 'meta');      ?>><?php esc_html_e('Meta (Facebook + Instagram)', 'post-forwarder'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <input type="hidden" name="portals[<?php echo esc_attr($i); ?>][key]" value="<?php echo esc_attr($key); ?>">
                                <tr>
                                    <th><?php esc_html_e('Account Name', 'post-forwarder'); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][name]" value="<?php echo esc_attr($mapping['name']); ?>" style="width: 300px;" /></td>
                                </tr>
                                <tr class="fields-wordpress" <?php echo ( $is_linkedin || $is_x || $is_meta ) ? 'style="display:none;"' : ''; ?>>
                                    <th><?php esc_html_e( 'URL', 'post-forwarder' ); ?></th>
                                    <td><input type="url" name="portals[<?php echo esc_attr($i); ?>][url]"
                                         value="<?php echo esc_attr( isset( $mapping['url'] ) ? $mapping['url'] : '' ); ?>"
                                         style="width: 400px;" /></td>
                                </tr>
                                <tr class="fields-wordpress" <?php echo ( $is_linkedin || $is_x || $is_meta ) ? 'style="display:none;"' : ''; ?>>
                                    <th><?php esc_html_e( 'Connection', 'post-forwarder' ); ?></th>
                                    <td>
                                        <?php if ( $wp_button_connected ) : ?>
                                            <span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'post-forwarder' ); ?></span>
                                            <span style="color:#666;font-size:12px;margin-left:8px;"><?php echo esc_html( $mapping['user'] ); ?></span>
                                            <button type="button" class="button button-secondary save-and-connect-wp" style="margin-left:10px;">
                                                <?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?>
                                            </button>
                                        <?php else : ?>
                                            <button type="button" class="button save-and-connect-wp" style="background:#3858e9;border-color:#3858e9;color:#fff;">
                                                &#10132; <?php esc_html_e( 'Save & Connect with WordPress', 'post-forwarder' ); ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $mapping['last_error'] ) && ! $wp_button_connected ) : ?>
                                            <p class="description" style="color:#cc0000;margin-top:6px;">
                                                <strong><?php esc_html_e( 'Last error:', 'post-forwarder' ); ?></strong>
                                                <?php echo esc_html( $mapping['last_error'] ); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ( ! $wp_button_connected ) : ?>
                                        <p class="description" style="margin-top:6px;">
                                            <a href="#" class="wp-manual-toggle"><?php esc_html_e( 'Enter credentials manually instead', 'post-forwarder' ); ?></a>
                                        </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php $show_manual = ( ! $is_linkedin && ! $is_x && ! $is_meta && $wp_manual_has_data ) ? '' : 'style="display:none;"'; ?>
                                <tr class="fields-wordpress wp-manual-fields" <?php echo $show_manual; ?>>
                                    <th><?php esc_html_e( 'Username / User ID', 'post-forwarder' ); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr($i); ?>][user]"
                                         value="<?php echo esc_attr( isset( $mapping['user'] ) ? $mapping['user'] : '' ); ?>"
                                         placeholder="<?php esc_attr_e( 'username or user ID', 'post-forwarder' ); ?>"
                                         style="width: 200px;" /></td>
                                </tr>
                                <tr class="fields-wordpress wp-manual-fields" <?php echo $show_manual; ?>>
                                    <th><?php esc_html_e( 'App Password', 'post-forwarder' ); ?></th>
                                    <td><input type="password" name="portals[<?php echo esc_attr($i); ?>][password]"
                                         value="<?php echo esc_attr( isset( $mapping['password'] ) ? $mapping['password'] : '' ); ?>"
                                         placeholder="xxxx xxxx xxxx xxxx"
                                         style="width: 300px;" /></td>
                                </tr>
                                <?php if ( ! $li_creds_from_constants ) : ?>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Client ID', 'post-forwarder' ); ?></th>
                                    <td><input type="text" name="portals[<?php echo esc_attr( $i ); ?>][client_id]" value="<?php echo esc_attr( isset( $mapping['client_id'] ) ? $mapping['client_id'] : '' ); ?>" style="width: 300px;" /></td>
                                </tr>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Client Secret', 'post-forwarder' ); ?></th>
                                    <td><input type="password" name="portals[<?php echo esc_attr( $i ); ?>][client_secret]" value="<?php echo esc_attr( isset( $mapping['client_secret'] ) ? $mapping['client_secret'] : '' ); ?>" style="width: 300px;" /></td>
                                </tr>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Author URN', 'post-forwarder' ); ?></th>
                                    <td>
                                        <input type="text" name="portals[<?php echo esc_attr( $i ); ?>][author_urn]" value="<?php echo esc_attr( isset( $mapping['author_urn'] ) ? $mapping['author_urn'] : '' ); ?>" placeholder="urn:li:person:XXXX or urn:li:organization:XXXX" style="width: 420px;" />
                                        <p class="description">
                                            <?php esc_html_e( 'For personal posts: urn:li:person:YOUR_ID. For organization posts: urn:li:organization:YOUR_ORG_ID (requires Community Management API product on your LinkedIn app).', 'post-forwarder' ); ?>
                                            <?php if ( ! empty( $mapping['person_urn'] ) ) : ?>
                                                <br><strong><?php esc_html_e( 'Auto-detected person URN:', 'post-forwarder' ); ?></strong> <?php echo esc_html( $mapping['person_urn'] ); ?>
                                            <?php endif; ?>
                                        </p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr class="fields-linkedin" <?php echo $is_linkedin ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e('Connection Status', 'post-forwarder'); ?></th>
                                    <td>
                                        <?php if ( $is_connected ) : ?>
                                            <span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'post-forwarder' ); ?></span>
                                            <?php if ( $is_linkedin && ! empty( $mapping['person_urn'] ) ) : ?>
                                                <span style="color:#666;font-size:12px;margin-left:8px;"><?php echo esc_html( $mapping['person_urn'] ); ?></span>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-secondary" style="margin-left:10px;"><?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?></a>
                                        <?php elseif ( $has_credentials ) : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'Not connected', 'post-forwarder' ); ?></span>
                                            <a href="<?php echo esc_url( $oauth_url ); ?>" class="button" style="margin-left:10px;background:#0a66c2;border-color:#0a66c2;color:#fff;">&#10132; <?php esc_html_e( 'Connect with LinkedIn', 'post-forwarder' ); ?></a>
                                        <?php else : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'Save Client ID and Secret first, then connect.', 'post-forwarder' ); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($mapping['last_error']) && !$is_connected): ?>
                                            <p class="description" style="color:#cc0000;margin-top:6px;">
                                                <strong><?php esc_html_e('Last error:', 'post-forwarder'); ?></strong>
                                                <?php echo esc_html($mapping['last_error']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($is_linkedin && isset($mapping['token_expires'])): ?>
                                            <p class="description">
                                                <?php if ($mapping['token_expires'] > time()): ?>
                                                    <?php
                                                    /* translators: %s: expiry date */
                                                    echo esc_html(sprintf(__('Token expires: %s', 'post-forwarder'), date_i18n(get_option('date_format'), $mapping['token_expires'])));
                                                    ?>
                                                <?php else: ?>
                                                    <span style="color:#cc0000;"><?php esc_html_e('Token expired — please reconnect.', 'post-forwarder'); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr class="fields-x" <?php echo $is_x ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Client ID', 'post-forwarder' ); ?></th>
                                    <td>
                                        <input type="text" name="portals[<?php echo esc_attr( $i ); ?>][client_id]" value="<?php echo esc_attr( isset( $mapping['client_id'] ) ? $mapping['client_id'] : '' ); ?>" style="width: 300px;" />
                                        <p class="description"><?php esc_html_e( 'Your X (Twitter) developer app Client ID. Required to use your own API quota.', 'post-forwarder' ); ?></p>
                                    </td>
                                </tr>
                                <tr class="fields-x" <?php echo $is_x ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Client Secret', 'post-forwarder' ); ?></th>
                                    <td>
                                        <input type="password" name="portals[<?php echo esc_attr( $i ); ?>][client_secret]" value="<?php echo esc_attr( isset( $mapping['client_secret'] ) ? $mapping['client_secret'] : '' ); ?>" style="width: 300px;" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'post-forwarder' ); ?>" />
                                        <p class="description"><?php esc_html_e( 'Your X (Twitter) developer app Client Secret. Leave blank to keep the saved value.', 'post-forwarder' ); ?></p>
                                    </td>
                                </tr>
                                <tr class="fields-x" <?php echo $is_x ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Connection Status', 'post-forwarder' ); ?></th>
                                    <td>
                                        <?php if ( $is_x_connected ) : ?>
                                            <span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'post-forwarder' ); ?></span>
                                            <?php if ( ! empty( $mapping['x_username'] ) ) : ?>
                                                <span style="color:#666;font-size:12px;margin-left:8px;">@<?php echo esc_html( $mapping['x_username'] ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( $x_oauth_url ) : ?>
                                                <a href="<?php echo esc_url( $x_oauth_url ); ?>" class="button button-secondary" style="margin-left:10px;"><?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?></a>
                                            <?php endif; ?>
                                        <?php elseif ( $x_oauth_url ) : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'Not connected', 'post-forwarder' ); ?></span>
                                            <a href="<?php echo esc_url( $x_oauth_url ); ?>" class="button" style="margin-left:10px;background:#000;border-color:#000;color:#fff;">&#10132; <?php esc_html_e( 'Connect with X', 'post-forwarder' ); ?></a>
                                        <?php else : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'X connection requires the relay. Configure POST_FORWARDER_RELAY_URL first.', 'post-forwarder' ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $is_x && ! empty( $mapping['last_error'] ) && ! $is_x_connected ) : ?>
                                            <p class="description" style="color:#cc0000;margin-top:6px;">
                                                <strong><?php esc_html_e( 'Last error:', 'post-forwarder' ); ?></strong>
                                                <?php echo esc_html( $mapping['last_error'] ); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ( $is_x && isset( $mapping['token_expires'] ) ) : ?>
                                            <p class="description">
                                                <?php if ( $mapping['token_expires'] > time() ) : ?>
                                                    <?php
                                                    /* translators: %s: expiry date */
                                                    echo esc_html( sprintf( __( 'Token expires: %s', 'post-forwarder' ), date_i18n( get_option( 'date_format' ), $mapping['token_expires'] ) ) );
                                                    ?>
                                                <?php else : ?>
                                                    <span style="color:#cc0000;"><?php esc_html_e( 'Token expired — please reconnect.', 'post-forwarder' ); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr class="fields-meta" <?php echo $is_meta ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Post to Facebook', 'post-forwarder' ); ?></th>
                                    <td><label><input type="checkbox" name="portals[<?php echo esc_attr( $i ); ?>][post_to_facebook]" value="1" <?php checked( ! empty( $mapping['post_to_facebook'] ) ); ?>> <?php esc_html_e( 'Post to Facebook Page', 'post-forwarder' ); ?></label></td>
                                </tr>
                                <tr class="fields-meta" <?php echo $is_meta ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Post to Instagram', 'post-forwarder' ); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="portals[<?php echo esc_attr( $i ); ?>][post_to_instagram]" value="1" <?php checked( ! empty( $mapping['post_to_instagram'] ) ); ?>> <?php esc_html_e( 'Post to Instagram', 'post-forwarder' ); ?></label>
                                        <?php if ( $is_meta && empty( $mapping['instagram_account_id'] ) ) : ?>
                                            <p class="description"><?php esc_html_e( 'No Instagram account detected. Link a Professional account to your Facebook Page, then reconnect.', 'post-forwarder' ); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr class="fields-meta" <?php echo $is_meta ? '' : 'style="display:none;"'; ?>>
                                    <th><?php esc_html_e( 'Connection Status', 'post-forwarder' ); ?></th>
                                    <td>
                                        <?php if ( $is_meta_connected ) : ?>
                                            <span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Connected', 'post-forwarder' ); ?></span>
                                            <?php if ( ! empty( $mapping['page_name'] ) ) : ?>
                                                <span style="color:#666;font-size:12px;margin-left:8px;"><?php echo esc_html( $mapping['page_name'] ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $mapping['page_id'] ) ) : ?>
                                                <span style="color:#999;font-size:11px;margin-left:4px;">· Page ID: <?php echo esc_html( $mapping['page_id'] ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( $meta_oauth_url ) : ?>
                                                <a href="<?php echo esc_url( $meta_oauth_url ); ?>" class="button button-secondary" style="margin-left:10px;"><?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?></a>
                                            <?php endif; ?>
                                        <?php elseif ( $meta_oauth_url ) : ?>
                                            <span style="color:#666;"><?php esc_html_e( 'Not connected', 'post-forwarder' ); ?></span>
                                            <a href="<?php echo esc_url( $meta_oauth_url ); ?>" class="button" style="margin-left:10px;background:#1877f2;border-color:#1877f2;color:#fff;">&#10132; <?php esc_html_e( 'Connect with Meta', 'post-forwarder' ); ?></a>
                                        <?php else : ?>
                                            <button type="button" class="button save-and-connect-meta" style="background:#1877f2;border-color:#1877f2;color:#fff;">&#10132; <?php esc_html_e( 'Save & Connect with Meta', 'post-forwarder' ); ?></button>
                                        <?php endif; ?>
                                        <?php if ( $is_meta && ! empty( $mapping['last_error'] ) && ! $is_meta_connected ) : ?>
                                            <p class="description" style="color:#cc0000;margin-top:6px;">
                                                <strong><?php esc_html_e( 'Last error:', 'post-forwarder' ); ?></strong>
                                                <?php echo esc_html( $mapping['last_error'] ); ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                            <button type="button" class="button test-connection" style="margin-right: 8px;<?php echo ( $is_linkedin || $is_x || $is_meta ) ? ' display:none;' : ''; ?>"><?php esc_html_e('Test Connection', 'post-forwarder'); ?></button>
                            <span class="connection-result" style="font-weight: 600;"></span>
                            <button type="button" class="button remove-portal" style="float: right;"><?php esc_html_e('Remove Account', 'post-forwarder'); ?></button>
                        </div><!-- end portal-detail -->
                        </div><!-- end portal-row -->
                        <?php $i++; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="button" id="add-portal" class="button"><?php esc_html_e('Add Another Connection', 'post-forwarder'); ?></button>
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
                        <textarea name="post_forwarding_options[mappings]" rows="10" cols="70"><?php echo esc_textarea(wp_json_encode(post_forwarder_mask_mappings($mappings))); ?></textarea><br>
                        <small><?php esc_html_e('Advanced users can edit the JSON directly. Use the form above for easier configuration.', 'post-forwarder'); ?></small>
                    </td>
                </tr>
            </table>
            <?php submit_button(esc_html__('Save JSON', 'post-forwarder')); ?>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var portalCount          = <?php echo count( $mappings ); ?>;
        var liCredsFromConstants = <?php echo $li_creds_from_constants ? 'true' : 'false'; ?>;
        var relayMode            = <?php echo post_forwarder_relay_url() ? 'true' : 'false'; ?>;

        // Toggle field visibility based on account type
        function updatePortalFields($row) {
            var type = $row.find('.portal-type-select').val();
            $row.find('.fields-wordpress').toggle(type === 'wordpress');
            $row.find('.fields-linkedin').toggle(type === 'linkedin');
            $row.find('.fields-x').toggle(type === 'x');
            $row.find('.fields-meta').toggle(type === 'meta');
            $row.find('.test-connection').toggle(type === 'wordpress');
        }

        $(document).on('change', '.portal-type-select', function() {
            updatePortalFields($(this).closest('.portal-row'));
        });

        $('#add-portal').click(function() {
            var n = portalCount;
            var liCredFields = liCredsFromConstants ? '' :
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Client ID', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][client_id]" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Client Secret', 'post-forwarder' ) ); ?></th><td><input type="password" name="portals[' + n + '][client_secret]" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Author URN', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][author_urn]" placeholder="urn:li:person:XXXX or urn:li:organization:XXXX" style="width: 420px;" /></td></tr>';
            var newPortal =
                '<div class="portal-row" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;" data-type="wordpress">' +
                '<h4><?php echo esc_js( __( 'Account #', 'post-forwarder' ) ); ?>' + (n + 1) + '</h4>' +
                '<table class="form-table">' +
                '<tr><th><?php echo esc_js( __( 'Account Type', 'post-forwarder' ) ); ?></th><td>' +
                '<select name="portals[' + n + '][type]" class="portal-type-select">' +
                '<option value="wordpress"><?php echo esc_js( __( 'WordPress Portal', 'post-forwarder' ) ); ?></option>' +
                '<option value="linkedin"><?php echo esc_js( __( 'LinkedIn Account', 'post-forwarder' ) ); ?></option>' +
                '<option value="x"><?php echo esc_js( __( 'X (Twitter) Account', 'post-forwarder' ) ); ?></option>' +
                '<option value="meta"><?php echo esc_js( __( 'Meta (Facebook + Instagram)', 'post-forwarder' ) ); ?></option>' +
                '</select></td></tr>' +
                '<tr><th><?php echo esc_js( __( 'Account Name', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][name]" placeholder="<?php echo esc_js( __( 'e.g., My Account', 'post-forwarder' ) ); ?>" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-wordpress"><th><?php echo esc_js( __( 'URL', 'post-forwarder' ) ); ?></th><td><input type="url" name="portals[' + n + '][url]" placeholder="https://example.com" style="width: 400px;" /></td></tr>' +
                '<tr class="fields-wordpress"><th><?php echo esc_js( __( 'Connection', 'post-forwarder' ) ); ?></th><td>' +
                '<button type="button" class="button save-and-connect-wp" style="background:#3858e9;border-color:#3858e9;color:#fff;">&#10132; <?php echo esc_js( __( 'Save & Connect with WordPress', 'post-forwarder' ) ); ?></button>' +
                '<p class="description" style="margin-top:6px;"><a href="#" class="wp-manual-toggle"><?php echo esc_js( __( 'Enter credentials manually instead', 'post-forwarder' ) ); ?></a></p>' +
                '</td></tr>' +
                '<tr class="fields-wordpress wp-manual-fields" style="display:none;"><th><?php echo esc_js( __( 'Username / User ID', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][user]" placeholder="<?php echo esc_js( __( 'username or user ID', 'post-forwarder' ) ); ?>" style="width: 200px;" /></td></tr>' +
                '<tr class="fields-wordpress wp-manual-fields" style="display:none;"><th><?php echo esc_js( __( 'App Password', 'post-forwarder' ) ); ?></th><td><input type="password" name="portals[' + n + '][password]" placeholder="xxxx xxxx xxxx xxxx" style="width: 300px;" /></td></tr>' +
                liCredFields +
                '<tr class="fields-linkedin" style="display:none;"><th><?php echo esc_js( __( 'Connection Status', 'post-forwarder' ) ); ?></th><td>' +
                ( relayMode
                    ? '<button type="button" class="button save-and-connect-linkedin" style="background:#0a66c2;border-color:#0a66c2;color:#fff;">&#10132; <?php echo esc_js( __( 'Save & Connect with LinkedIn', 'post-forwarder' ) ); ?></button>'
                    : '<span style="color:#666;"><?php echo esc_js( __( 'Save the account first, then click Connect with LinkedIn.', 'post-forwarder' ) ); ?></span>'
                ) + '</td></tr>' +
                '<tr class="fields-x" style="display:none;"><th><?php echo esc_js( __( 'Client ID', 'post-forwarder' ) ); ?></th><td><input type="text" name="portals[' + n + '][client_id]" placeholder="<?php echo esc_js( __( 'X App Client ID', 'post-forwarder' ) ); ?>" style="width: 300px;" /><p class="description"><?php echo esc_js( __( 'Your X developer app Client ID. Required to use your own API quota.', 'post-forwarder' ) ); ?></p></td></tr>' +
                '<tr class="fields-x" style="display:none;"><th><?php echo esc_js( __( 'Client Secret', 'post-forwarder' ) ); ?></th><td><input type="password" name="portals[' + n + '][client_secret]" placeholder="<?php echo esc_js( __( 'X App Client Secret', 'post-forwarder' ) ); ?>" style="width: 300px;" /></td></tr>' +
                '<tr class="fields-x" style="display:none;"><th><?php echo esc_js( __( 'Connection Status', 'post-forwarder' ) ); ?></th><td>' +
                ( relayMode
                    ? '<button type="button" class="button save-and-connect-x" style="background:#000;border-color:#000;color:#fff;">&#10132; <?php echo esc_js( __( 'Save & Connect with X', 'post-forwarder' ) ); ?></button>'
                    : '<span style="color:#666;"><?php echo esc_js( __( 'X connection requires the relay. Configure POST_FORWARDER_RELAY_URL first.', 'post-forwarder' ) ); ?></span>'
                ) + '</td></tr>' +
                '<tr class="fields-meta" style="display:none;"><th><?php echo esc_js( __( 'Post to Facebook', 'post-forwarder' ) ); ?></th><td><label><input type="checkbox" name="portals[' + n + '][post_to_facebook]" value="1" checked> <?php echo esc_js( __( 'Post to Facebook Page', 'post-forwarder' ) ); ?></label></td></tr>' +
                '<tr class="fields-meta" style="display:none;"><th><?php echo esc_js( __( 'Post to Instagram', 'post-forwarder' ) ); ?></th><td><label><input type="checkbox" name="portals[' + n + '][post_to_instagram]" value="1" checked> <?php echo esc_js( __( 'Post to Instagram', 'post-forwarder' ) ); ?></label></td></tr>' +
                '<tr class="fields-meta" style="display:none;"><th><?php echo esc_js( __( 'Connection Status', 'post-forwarder' ) ); ?></th><td>' +
                ( relayMode
                    ? '<button type="button" class="button save-and-connect-meta" style="background:#1877f2;border-color:#1877f2;color:#fff;">&#10132; <?php echo esc_js( __( 'Save & Connect with Meta', 'post-forwarder' ) ); ?></button>'
                    : '<span style="color:#666;"><?php echo esc_js( __( 'Meta connection requires the relay.', 'post-forwarder' ) ); ?></span>'
                ) + '</td></tr>' +
                '</table>' +
                '<button type="button" class="button test-connection" style="margin-right: 8px;"><?php echo esc_js( __( 'Test Connection', 'post-forwarder' ) ); ?></button>' +
                '<span class="connection-result" style="font-weight: 600;"></span>' +
                '<button type="button" class="button remove-portal" style="float: right;"><?php echo esc_js( __( 'Remove Account', 'post-forwarder' ) ); ?></button>' +
                '</div>';

            $('#portals-container').append(newPortal);
            portalCount++;
        });

        $(document).on('click', '.remove-portal', function() {
            $(this).closest('.portal-row').remove();
        });

        function pfGetPortalKey($row) {
            // Existing portal: hidden key input. New portal: derive from name at save time.
            var hidden = $row.find('input[type="hidden"][name$="[key]"]').val();
            return hidden ? $.trim(hidden) : '';
        }

        $(document).on('click', '.save-and-connect-linkedin', function() {
            var $row = $(this).closest('.portal-row');
            var key  = pfGetPortalKey($row);
            var name = $.trim($row.find('input[name$="[name]"]').val());
            if (!name) { alert('<?php echo esc_js( __( 'Please enter an Account Name first.', 'post-forwarder' ) ); ?>'); return; }
            $('input[name="pending_linkedin_connect"]').val(key || name);
            $row.closest('form').find('input[name="submit_portals"]').click();
        });

        $(document).on('click', '.save-and-connect-x', function() {
            var $row = $(this).closest('.portal-row');
            var key  = pfGetPortalKey($row);
            var name = $.trim($row.find('input[name$="[name]"]').val());
            if (!name) { alert('<?php echo esc_js( __( 'Please enter an Account Name first.', 'post-forwarder' ) ); ?>'); return; }
            $('input[name="pending_x_connect"]').val(key || name);
            $row.closest('form').find('input[name="submit_portals"]').click();
        });

        $(document).on('click', '.save-and-connect-meta', function() {
            var $row = $(this).closest('.portal-row');
            var key  = pfGetPortalKey($row);
            var name = $.trim($row.find('input[name$="[name]"]').val());
            if (!name) { alert('<?php echo esc_js( __( 'Please enter an Account Name first.', 'post-forwarder' ) ); ?>'); return; }
            $('input[name="pending_meta_connect"]').val(key || name);
            $row.closest('form').find('input[name="submit_portals"]').click();
        });

        $(document).on('click', '.save-and-connect-wp', function() {
            var $row = $(this).closest('.portal-row');
            var key  = pfGetPortalKey($row);
            var name = $.trim($row.find('input[name$="[name]"]').val());
            var url  = $.trim($row.find('input[name$="[url]"]').val());
            if (!name) { alert('<?php echo esc_js( __( 'Please enter an Account Name first.', 'post-forwarder' ) ); ?>'); return; }
            if (!url)  { alert('<?php echo esc_js( __( 'Please enter the WordPress site URL first.', 'post-forwarder' ) ); ?>'); return; }
            $('input[name="pending_wp_connect"]').val(key || name);
            $row.closest('form').find('input[name="submit_portals"]').click();
        });

        $(document).on('click', '.wp-manual-toggle', function(e) {
            e.preventDefault();
            var $connectRow = $(this).closest('tr');
            $connectRow.nextAll('.wp-manual-fields').slice(0, 2).toggle();
        });

        // Expand/collapse connected portal detail.
        $(document).on('click', '.portal-expand-btn', function(e) {
            e.stopPropagation();
            var $row    = $(this).closest('.portal-row');
            var $detail = $row.find('.portal-detail');
            var $btn    = $(this);
            if ($detail.is(':visible')) {
                $detail.slideUp(150);
                $btn.html('<?php echo esc_js( __( 'Edit', 'post-forwarder' ) ); ?> &#9660;');
            } else {
                $detail.slideDown(150);
                $btn.html('<?php echo esc_js( __( 'Collapse', 'post-forwarder' ) ); ?> &#9650;');
            }
        });

        $(document).on('click', '.portal-summary', function(e) {
            if ($(e.target).closest('button, a').length) { return; }
            $(this).find('.portal-expand-btn').trigger('click');
        });

        $(document).on('click', '.test-connection', function() {
            var $btn = $(this);
            var $row = $btn.closest('.portal-row');
            var $result = $row.find('.connection-result');
            var url = $row.find('input[name$="[url]"]').val();
            var user = $row.find('input[name$="[user]"]').val();
            var password = $row.find('input[name$="[password]"]').val();

            if (!url || !user || !password) {
                $result.css('color', '#cc0000').text('<?php echo esc_js(__('Please fill in URL, Username/User ID, and App Password first.', 'post-forwarder')); ?>');
                return;
            }

            $btn.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'post-forwarder')); ?>');
            $result.text('');

            $.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                action: 'post_forwarder_test_connection',
                nonce: '<?php echo wp_create_nonce('post_forwarder_test_connection'); ?>',
                url: url,
                user: user,
                password: password
            }, function(response) {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Test Connection', 'post-forwarder')); ?>');
                if (response.success) {
                    $result.css('color', '#00a32a').text(response.data.message);
                } else {
                    $result.css('color', '#cc0000').text(response.data.message);
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Test Connection', 'post-forwarder')); ?>');
                $result.css('color', '#cc0000').text('<?php echo esc_js(__('Request failed. Please try again.', 'post-forwarder')); ?>');
            });
        });
    });
    </script>
    <?php
}
