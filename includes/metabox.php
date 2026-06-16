<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

// Meta box callback function
function post_forwarder_meta_box_callback($post) {
    wp_nonce_field('post_forwarding_meta_box', 'post_forwarding_meta_box_nonce');

    $options = get_option('post_forwarding_options', array());
    $mappings_json = isset($options['mappings']) ? $options['mappings'] : '{}';
    $mappings = json_decode($mappings_json, true);
    $selected_products = get_post_meta($post->ID, 'product', false);

    echo '<div style="margin-bottom: 10px;">';
    echo '<strong>' . esc_html__('Select destinations to forward to:', 'post-forwarder') . '</strong>';
    echo '</div>';

    if (!empty($mappings) && is_array($mappings)) {
        foreach ($mappings as $product_key => $mapping) {
            $account_type = isset($mapping['type']) ? $mapping['type'] : 'wordpress';
            $portal_name  = isset($mapping['name']) ? $mapping['name'] : $product_key;

            if ($account_type === 'linkedin') {
                $is_connected = !empty($mapping['access_token'])
                    && (!isset($mapping['token_expires']) || $mapping['token_expires'] > time());
                $badge        = '<span style="background:#0a66c2;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:5px;">LI</span>';
                $display_name = esc_html($portal_name) . $badge;
                if (!$is_connected) {
                    $display_name .= ' <span style="color:#cc0000;font-size:11px;">' . esc_html__('(not connected)', 'post-forwarder') . '</span>';
                }
            } elseif ($account_type === 'x') {
                $is_connected = !empty($mapping['access_token'])
                    && (!isset($mapping['token_expires']) || $mapping['token_expires'] > time());
                $badge        = '<span style="background:#000;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:5px;">X</span>';
                $display_name = esc_html($portal_name) . $badge;
                if (!$is_connected) {
                    $display_name .= ' <span style="color:#cc0000;font-size:11px;">' . esc_html__('(not connected)', 'post-forwarder') . '</span>';
                }
            } else {
                $wp_connected = ! empty( $mapping['user'] ) && ! empty( $mapping['password'] );
                $badge        = '<span style="background:#3858e9;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:5px;">WP</span>';
                $display_name = esc_html( $portal_name ) . $badge;
                if ( ! $wp_connected ) {
                    $display_name .= ' <span style="color:#cc0000;font-size:11px;">' . esc_html__( '(not connected)', 'post-forwarder' ) . '</span>';
                }
                $is_connected = $wp_connected;
            }

            $is_selected = in_array($product_key, $selected_products);

            echo '<div style="margin-bottom: 8px;">';
            echo '<label style="display: flex; align-items: center;">';
            echo '<input type="checkbox" name="post_forwarding_product[]" value="' . esc_attr($product_key) . '"' . ($is_selected ? ' checked' : '') . ' style="margin-right: 8px;">';
            echo '<span>' . wp_kses($display_name, array('span' => array('style' => array()))) . '</span>';
            echo '</label>';
            echo '</div>';
        }
    } else {
        echo '<p style="color: #666; font-style: italic;">' . esc_html__('No portals configured', 'post-forwarder') . '</p>';
    }

    // Show forwarding results from the last save (set by post_forwarder_forward_post via save_post).
    $pf_results_key = 'pf_forward_results_' . get_current_user_id() . '_' . $post->ID;
    $pf_results     = get_transient( $pf_results_key );
    if ( $pf_results && is_array( $pf_results ) ) {
        delete_transient( $pf_results_key );
        $all_ok = true;
        foreach ( $pf_results as $r ) { if ( ! $r['success'] ) { $all_ok = false; break; } }
        $border = $all_ok ? '#00a32a' : '#cc0000';
        echo '<div style="margin-top:10px;border-top:1px solid #ddd;padding-top:8px;">';
        echo '<strong style="font-size:11px;">' . esc_html__( 'Forwarding results:', 'post-forwarder' ) . '</strong>';
        echo '<ul style="margin:4px 0 0 0;padding:0;list-style:none;">';
        foreach ( $pf_results as $r ) {
            $icon  = $r['success'] ? '✓' : '✗';
            $color = $r['success'] ? '#00a32a' : '#cc0000';
            $badge_colors = array( 'linkedin' => '#0a66c2', 'x' => '#000', 'wordpress' => '#3858e9' );
            $badge_labels = array( 'linkedin' => 'LI', 'x' => 'X', 'wordpress' => 'WP' );
            $badge = '';
            if ( isset( $r['type'], $badge_colors[ $r['type'] ] ) ) {
                $badge = '<span style="background:' . esc_attr( $badge_colors[ $r['type'] ] ) . ';color:#fff;font-size:9px;padding:1px 4px;border-radius:2px;margin-left:3px;">' . esc_html( $badge_labels[ $r['type'] ] ) . '</span>';
            }
            echo '<li style="font-size:11px;margin:3px 0;display:flex;align-items:center;gap:4px;">';
            echo '<span style="color:' . esc_attr( $color ) . ';font-weight:700;flex-shrink:0;">' . esc_html( $icon ) . '</span>';
            echo '<span><strong>' . esc_html( $r['name'] ) . '</strong>' . $badge . ':</span>'; // phpcs:ignore
            echo '<span style="color:' . esc_attr( $color ) . ';word-break:break-all;">' . esc_html( $r['message'] ) . '</span>';
            echo '</li>';
        }
        echo '</ul></div>';
    }

    echo '<p style="font-size: 11px; color: #666; margin-top: 10px; border-top: 1px solid #ddd; padding-top: 8px;">';
    echo esc_html__('Configure portals in Settings → Post Forwarding', 'post-forwarder');
    echo '</p>';
}
