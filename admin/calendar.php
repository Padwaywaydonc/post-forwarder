<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

function post_forwarder_calendar_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $options      = get_option( 'post_forwarding_options', array() );
    $mappings_raw = isset( $options['mappings'] ) ? $options['mappings'] : '{}';
    $mappings     = json_decode( is_string( $mappings_raw ) ? $mappings_raw : '{}', true );
    if ( ! is_array( $mappings ) ) {
        $mappings = array();
    }

    $platform_colors = array(
        'linkedin'  => array( 'bg' => '#0a66c2', 'label' => 'in' ),
        'x'         => array( 'bg' => '#000000', 'label' => 'X' ),
        'meta'      => array( 'bg' => '#1877f2', 'label' => 'f' ),
        'facebook'  => array( 'bg' => '#1877f2', 'label' => 'FB' ),
        'instagram' => array( 'bg' => '#c13584', 'label' => 'IG' ),
        'wordpress' => array( 'bg' => '#3858e9', 'label' => 'W' ),
    );

    $settings_url = admin_url( 'admin.php?page=post-forwarder-settings' );
    $rest_url     = rest_url( 'post-forwarder/v1/' );
    $rest_nonce   = wp_create_nonce( 'wp_rest' );

    // Pre-load current week's schedule from PHP so the grid renders immediately without a JS fetch.
    $pf_today_utc  = gmdate( 'Y-m-d' );
    $pf_week_start = $pf_today_utc;
    $pf_week_end   = gmdate( 'Y-m-d', strtotime( $pf_today_utc . ' +6 days' ) );
    global $wpdb;
    $pf_table = $wpdb->prefix . 'pf_schedule';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $pf_initial_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$pf_table} WHERE scheduled_at BETWEEN %s AND %s ORDER BY scheduled_at ASC",
        $pf_week_start . ' 00:00:00',
        $pf_week_end   . ' 23:59:59'
    ), ARRAY_A ) ?: array();
    foreach ( $pf_initial_rows as &$pf_r ) {
        $pf_r['channel_keys'] = json_decode( $pf_r['channel_keys'], true );
        $pf_r['result']       = json_decode( $pf_r['result'], true );
        if ( $pf_r['post_id'] ) {
            $pf_p = get_post( (int) $pf_r['post_id'] );
            $pf_r['post_title'] = $pf_p ? $pf_p->post_title : '';
            $pf_r['post_url']   = $pf_p ? get_permalink( $pf_p ) : '';
        }
    }
    unset( $pf_r );
    ?>
    <div class="pf-cal-root">
        <style>
        /* ── Post Forwarder Calendar — WP light theme ──────────────────── */
        .pf-cal-root { display:flex; height:calc(100vh - 32px); overflow:hidden; background:#f0f0f1; color:#1d2327; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; margin:-12px -20px 0; }

        /* Sidebar */
        .pf-sidebar { width:260px; flex-shrink:0; background:#fff; border-right:1px solid #c3c4c7; display:flex; flex-direction:column; overflow-y:auto; }
        .pf-sidebar-head { padding:16px 16px 8px; border-bottom:1px solid #f0f0f1; }
        .pf-sidebar-head h3 { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:#50575e; margin:0; }
        .pf-channel-list { list-style:none; margin:0; padding:8px 0; flex:1; }
        .pf-channel-item { padding:6px 16px 10px; border-bottom:1px solid #f6f7f7; }
        .pf-channel-item-top { display:flex; align-items:center; gap:10px; }
        .pf-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; color:#fff; flex-shrink:0; position:relative; }
        .pf-platform-badge { position:absolute; bottom:-2px; right:-2px; width:14px; height:14px; border-radius:3px; display:flex; align-items:center; justify-content:center; font-size:8px; font-weight:700; color:#fff; border:2px solid #fff; }
        .pf-channel-info { flex:1; min-width:0; }
        .pf-channel-name { font-size:13px; font-weight:600; color:#1d2327; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pf-channel-status { display:flex; align-items:center; gap:5px; margin-top:3px; }
        .pf-status-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
        .pf-status-dot.connected { background:#00a32a; }
        .pf-status-dot.disconnected { background:#d63638; }
        .pf-status-text { font-size:11px; color:#50575e; }
        .pf-reconnect-link { font-size:11px; color:#2271b1; text-decoration:none; margin-left:4px; }
        .pf-reconnect-link:hover { text-decoration:underline; }
        .pf-sidebar-actions { padding:12px 16px; border-top:1px solid #c3c4c7; display:flex; flex-direction:column; gap:8px; }
        .pf-btn-primary { background:#2271b1; color:#fff; border:none; border-radius:4px; padding:8px 16px; font-size:13px; font-weight:600; cursor:pointer; text-align:center; display:flex; align-items:center; justify-content:center; gap:6px; transition:background .15s; text-decoration:none; box-shadow:inset 0 -1px 0 rgba(0,0,0,.15); }
        .pf-btn-primary:hover { background:#135e96; color:#fff; }
        .pf-btn-secondary { background:#fff; color:#2271b1; border:1px solid #2271b1; border-radius:4px; padding:7px 16px; font-size:13px; cursor:pointer; text-align:center; transition:all .15s; text-decoration:none; }
        .pf-btn-secondary:hover { background:#f6f7f7; color:#135e96; border-color:#135e96; }
        .pf-no-channels { padding:16px; color:#50575e; font-size:13px; text-align:center; }

        /* Main */
        .pf-cal-main { flex:1; display:flex; flex-direction:column; min-width:0; background:#f0f0f1; }
        .pf-cal-nav { display:flex; align-items:center; gap:12px; padding:12px 20px; background:#fff; border-bottom:1px solid #c3c4c7; flex-shrink:0; }
        .pf-cal-nav h2 { font-size:15px; font-weight:600; margin:0; flex:1; color:#1d2327; }
        .pf-nav-btn { background:#fff; border:1px solid #c3c4c7; color:#1d2327; width:30px; height:30px; border-radius:4px; cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center; transition:all .15s; }
        .pf-nav-btn:hover { background:#f0f0f1; border-color:#8c8f94; }
        .pf-today-btn { background:#fff; border:1px solid #c3c4c7; color:#2271b1; padding:5px 12px; border-radius:4px; cursor:pointer; font-size:13px; transition:all .15s; font-weight:500; }
        .pf-today-btn:hover { background:#f0f0f1; }

        /* Grid */
        .pf-grid-wrap { flex:1; overflow:auto; }
        .pf-grid { display:grid; grid-template-columns:52px repeat(7,1fr); min-width:700px; }
        .pf-day-header { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#50575e; padding:8px 4px 6px; border-bottom:1px solid #c3c4c7; border-right:1px solid #e2e4e7; text-align:center; position:sticky; top:0; background:#fff; z-index:10; }
        .pf-day-header.today { background:#f0f6fc; }
        .pf-day-header.today .pf-day-num { background:#2271b1; color:#fff; border-radius:50%; width:26px; height:26px; display:inline-flex; align-items:center; justify-content:center; }
        .pf-day-header .pf-day-num { display:block; font-size:20px; font-weight:700; color:#1d2327; margin-top:2px; }
        .pf-day-header.today .pf-day-num { color:#fff; }
        .pf-time-col { color:#8c8f94; font-size:10px; text-align:right; padding:0 6px; height:60px; display:flex; align-items:flex-start; padding-top:4px; border-right:1px solid #c3c4c7; background:#fff; }
        .pf-time-col.spacer { height:auto; border-bottom:1px solid #c3c4c7; background:#fff; position:sticky; top:0; z-index:11; }
        .pf-slot { height:60px; border-right:1px solid #e2e4e7; border-bottom:1px solid #f0f0f1; position:relative; cursor:pointer; background:#fff; transition:background .1s; }
        .pf-slot:hover { background:#f6f7f7; }

        /* Scheduled items */
        .pf-item { position:absolute; left:3px; right:3px; border-radius:3px; padding:3px 5px; font-size:11px; cursor:pointer; overflow:hidden; z-index:5; display:flex; align-items:center; gap:4px; transition:opacity .15s; color:#fff; }
        .pf-item:hover { opacity:.85; }
        .pf-item-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:500; }
        .pf-item-badges { display:flex; gap:2px; flex-shrink:0; }
        .pf-item-badge { width:12px; height:12px; border-radius:2px; display:flex; align-items:center; justify-content:center; font-size:7px; font-weight:700; color:#fff; }
        .pf-item.status-sent { opacity:.5; }
        .pf-item.status-failed { outline:2px solid #d63638; }

        /* Now line */
        .pf-now-line { position:absolute; left:0; right:0; height:2px; background:#d63638; z-index:8; pointer-events:none; }
        .pf-now-line::before { content:''; position:absolute; left:-4px; top:-4px; width:10px; height:10px; border-radius:50%; background:#d63638; }

        /* Modal */
        .pf-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:100000; display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none; transition:opacity .2s; }
        .pf-modal-overlay.open { opacity:1; pointer-events:all; }
        .pf-modal { background:#fff; border-radius:4px; width:540px; max-width:95vw; max-height:92vh; overflow-y:auto; box-shadow:0 5px 30px rgba(0,0,0,.3); transform:translateY(8px); transition:transform .2s; }
        .pf-modal-overlay.open .pf-modal { transform:translateY(0); }
        .pf-modal-header { padding:16px 20px 14px; border-bottom:1px solid #c3c4c7; display:flex; align-items:center; justify-content:space-between; background:#f6f7f7; border-radius:4px 4px 0 0; }
        .pf-modal-header h3 { margin:0; font-size:15px; font-weight:600; color:#1d2327; }
        .pf-modal-close { background:none; border:none; color:#50575e; font-size:22px; cursor:pointer; line-height:1; padding:0; }
        .pf-modal-close:hover { color:#1d2327; }
        .pf-modal-body { padding:16px 20px; display:flex; flex-direction:column; gap:14px; }
        .pf-field label { display:block; font-size:12px; font-weight:600; color:#1d2327; margin-bottom:5px; }
        .pf-field input[type=text], .pf-field input[type=datetime-local], .pf-field textarea { width:100%; border:1px solid #8c8f94; border-radius:4px; padding:7px 10px; font-size:13px; color:#1d2327; box-sizing:border-box; outline:none; font-family:inherit; background:#fff; }
        .pf-field input:focus, .pf-field textarea:focus { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; }
        .pf-field textarea { resize:vertical; min-height:90px; }
        .pf-mode-tabs { display:flex; gap:0; border:1px solid #c3c4c7; border-radius:4px; overflow:hidden; }
        .pf-mode-tab { flex:1; padding:7px 12px; background:#f6f7f7; border:none; color:#50575e; cursor:pointer; font-size:13px; text-align:center; transition:all .15s; border-right:1px solid #c3c4c7; }
        .pf-mode-tab:last-child { border-right:none; }
        .pf-mode-tab.active { background:#2271b1; color:#fff; }
        .pf-post-list { border:1px solid #c3c4c7; border-radius:4px; max-height:200px; overflow-y:auto; margin-top:6px; }
        .pf-post-result { padding:8px 12px; cursor:pointer; border-bottom:1px solid #f0f0f1; font-size:13px; color:#1d2327; transition:background .1s; display:flex; align-items:center; gap:8px; }
        .pf-post-result:last-child { border-bottom:none; }
        .pf-post-result:hover { background:#f0f6fc; }
        .pf-post-result.selected { background:#f0f6fc; font-weight:600; }
        .pf-post-result .pf-post-status { font-size:10px; color:#646970; background:#f0f0f1; padding:1px 5px; border-radius:3px; flex-shrink:0; }
        .pf-post-result-title { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pf-channels-grid { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
        .pf-channel-check { display:flex; align-items:center; gap:8px; padding:7px 10px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; cursor:pointer; transition:all .15s; user-select:none; }
        .pf-channel-check:hover { background:#f0f6fc; border-color:#2271b1; }
        .pf-channel-check.checked { background:#f0f6fc; border-color:#2271b1; }
        .pf-channel-check input { width:auto; margin:0; }
        .pf-channel-check .pf-avatar { width:26px; height:26px; font-size:10px; }
        .pf-channel-check-name { font-size:12px; font-weight:500; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#1d2327; }
        .pf-modal-footer { padding:12px 20px 16px; border-top:1px solid #c3c4c7; display:flex; align-items:center; justify-content:space-between; gap:12px; background:#f6f7f7; }
        .pf-modal-footer .pf-btn-primary { padding:8px 20px; }
        .pf-delete-btn { background:none; border:1px solid #d63638; color:#d63638; border-radius:4px; padding:7px 14px; cursor:pointer; font-size:13px; transition:all .15s; }
        .pf-delete-btn:hover { background:#d63638; color:#fff; }
        .pf-loading { text-align:center; padding:40px; color:#50575e; }
        /* Thumbnail upload */
        .pf-thumb-wrap { display:flex; flex-direction:column; gap:6px; }
        .pf-thumb-label { display:block; border:2px dashed #c3c4c7; border-radius:4px; padding:12px; text-align:center; cursor:pointer; transition:border-color .15s; color:#50575e; font-size:13px; }
        .pf-thumb-label:hover { border-color:#2271b1; color:#2271b1; }
        .pf-thumb-clear { background:none; border:none; color:#d63638; font-size:12px; cursor:pointer; padding:0; text-align:left; }
        .pf-thumb-clear:hover { text-decoration:underline; }
        .pf-thumb-uploading { color:#2271b1; font-size:12px; }

        /* Add-channel button and modal */
        .pf-add-ch-item { padding:8px 12px; }
        .pf-add-ch-btn { width:100%; background:none; border:1px dashed #c3c4c7; border-radius:4px; color:#50575e; font-size:12px; padding:7px 10px; cursor:pointer; transition:all .15s; }
        .pf-add-ch-btn:hover { border-color:#2271b1; color:#2271b1; background:#f0f6fc; }
        .pf-type-picker { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
        .pf-type-btn { display:flex; align-items:center; gap:8px; padding:8px 10px; background:#f6f7f7; border:2px solid #c3c4c7; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; color:#1d2327; transition:all .15s; }
        .pf-type-btn:hover { border-color:#2271b1; background:#f0f6fc; }
        .pf-type-btn.selected { border-color:#2271b1; background:#f0f6fc; color:#2271b1; }
        .pf-type-btn .pf-avatar { width:24px; height:24px; font-size:10px; flex-shrink:0; }
        .pf-field input[type=url] { width:100%; border:1px solid #8c8f94; border-radius:4px; padding:7px 10px; font-size:13px; color:#1d2327; box-sizing:border-box; outline:none; font-family:inherit; background:#fff; }
        .pf-field input[type=url]:focus { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; }
        .pf-ch-info { padding:10px 12px; border-radius:4px; font-size:13px; background:#f0f6fc; border:1px solid #72aee6; color:#1d2327; line-height:1.5; }
        </style>

    <?php
    $channels_js = array();
    foreach ( $mappings as $key => $m ) {
        $type      = isset( $m['type'] ) ? $m['type'] : 'wordpress';
        $name      = isset( $m['name'] ) ? $m['name'] : $key;
        $color     = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['bg'] : '#555';
        $badge     = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['label'] : '?';
        $connected = post_forwarder_channel_connected( $m );
        $channels_js[] = array( 'key' => $key, 'name' => $name, 'type' => $type, 'color' => $color, 'badge' => $badge, 'connected' => $connected );
    }
    ?>

        <div class="pf-sidebar">
            <div class="pf-sidebar-head">
                <h3><?php esc_html_e( 'Channels', 'post-forwarder' ); ?></h3>
            </div>
            <ul class="pf-channel-list">
                <?php if ( empty( $mappings ) ) : ?>
                    <li class="pf-no-channels"><?php esc_html_e( 'No channels configured yet.', 'post-forwarder' ); ?></li>
                <?php else : ?>
                    <?php foreach ( $mappings as $key => $m ) :
                        $type      = isset( $m['type'] ) ? $m['type'] : 'wordpress';
                        $name      = isset( $m['name'] ) ? $m['name'] : $key;
                        $color     = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['bg'] : '#555';
                        $badge_lbl = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['label'] : '?';
                        $letter    = strtoupper( mb_substr( $name, 0, 1 ) );
                        $connected = post_forwarder_channel_connected( $m );
                    ?>
                    <li class="pf-channel-item">
                        <div class="pf-channel-item-top">
                            <span class="pf-avatar" style="background:<?php echo esc_attr( $color ); ?>;">
                                <?php echo esc_html( $letter ); ?>
                                <span class="pf-platform-badge" style="background:<?php echo esc_attr( $color ); ?>;filter:brightness(.7);"><?php echo esc_html( $badge_lbl ); ?></span>
                            </span>
                            <div class="pf-channel-info">
                                <div class="pf-channel-name"><?php echo esc_html( $name ); ?></div>
                                <div class="pf-channel-status">
                                    <span class="pf-status-dot <?php echo $connected ? 'connected' : 'disconnected'; ?>"></span>
                                    <span class="pf-status-text"><?php echo $connected ? esc_html__( 'Connected', 'post-forwarder' ) : esc_html__( 'Not connected', 'post-forwarder' ); ?></span>
                                    <?php if ( ! $connected ) : ?>
                                        <a class="pf-reconnect-link" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Reconnect', 'post-forwarder' ); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
                <li class="pf-add-ch-item">
                    <button type="button" class="pf-add-ch-btn" id="pf-add-channel-btn">+ <?php esc_html_e( 'Add channel', 'post-forwarder' ); ?></button>
                </li>
            </ul>
            <div class="pf-sidebar-actions">
                <button class="pf-btn-primary" id="pf-new-post-btn">+ <?php esc_html_e( 'New Post', 'post-forwarder' ); ?></button>
                <a class="pf-btn-secondary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'post-forwarder' ); ?></a>
            </div>
        </div>

        <div class="pf-cal-main">
            <div class="pf-cal-nav">
                <button class="pf-nav-btn" id="pf-prev-week">&#8249;</button>
                <button class="pf-today-btn" id="pf-today-btn"><?php esc_html_e( 'Today', 'post-forwarder' ); ?></button>
                <h2 id="pf-week-label"><?php
                    $pf_months = array('Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec');
                    $pf_s_ts   = strtotime( $pf_week_start );
                    $pf_e_ts   = strtotime( $pf_week_end );
                    echo esc_html(
                        $pf_months[ (int) gmdate( 'n', $pf_s_ts ) - 1 ] . ' ' . gmdate( 'j', $pf_s_ts ) .
                        ' – ' .
                        $pf_months[ (int) gmdate( 'n', $pf_e_ts ) - 1 ] . ' ' . gmdate( 'j', $pf_e_ts ) .
                        ', ' . gmdate( 'Y', $pf_e_ts )
                    );
                ?></h2>
                <button class="pf-nav-btn" id="pf-next-week">&#8250;</button>
            </div>
            <div class="pf-grid-wrap" id="pf-grid-wrap">
                <div class="pf-loading"><?php esc_html_e( 'Loading…', 'post-forwarder' ); ?></div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="pf-modal-overlay" id="pf-modal-overlay">
        <div class="pf-modal">
            <div class="pf-modal-header">
                <h3 id="pf-modal-title"><?php esc_html_e( 'Schedule Post', 'post-forwarder' ); ?></h3>
                <button class="pf-modal-close" id="pf-modal-close">&times;</button>
            </div>
            <div class="pf-modal-body">
                <div class="pf-field">
                    <label><?php esc_html_e( 'Post source', 'post-forwarder' ); ?></label>
                    <div class="pf-mode-tabs">
                        <button class="pf-mode-tab active" data-mode="existing"><?php esc_html_e( 'Existing WP post', 'post-forwarder' ); ?></button>
                        <button class="pf-mode-tab" data-mode="new"><?php esc_html_e( 'Write new content', 'post-forwarder' ); ?></button>
                    </div>
                </div>
                <div id="pf-mode-existing">
                    <div class="pf-field">
                        <label><?php esc_html_e( 'Select post', 'post-forwarder' ); ?></label>
                        <input type="text" id="pf-post-search" placeholder="<?php esc_attr_e( 'Search by title…', 'post-forwarder' ); ?>" autocomplete="off">
                        <div class="pf-post-list" id="pf-post-results">
                            <div class="pf-post-result" style="color:#50575e;cursor:default;"><?php esc_html_e( 'Loading posts…', 'post-forwarder' ); ?></div>
                        </div>
                        <input type="hidden" id="pf-post-id">
                    </div>
                </div>
                <div id="pf-mode-new" style="display:none;">
                    <div class="pf-field">
                        <label><?php esc_html_e( 'Title', 'post-forwarder' ); ?></label>
                        <input type="text" id="pf-new-title" placeholder="<?php esc_attr_e( 'Post title', 'post-forwarder' ); ?>">
                    </div>
                    <div class="pf-field">
                        <label><?php esc_html_e( 'Content', 'post-forwarder' ); ?></label>
                        <textarea id="pf-new-content" placeholder="<?php esc_attr_e( 'Write your post content…', 'post-forwarder' ); ?>"></textarea>
                    </div>
                    <div class="pf-field">
                        <label><?php esc_html_e( 'Thumbnail', 'post-forwarder' ); ?></label>
                        <div class="pf-thumb-wrap">
                            <label class="pf-thumb-label" id="pf-thumb-label" for="pf-new-thumb-file">
                                <span id="pf-thumb-hint"><?php esc_html_e( 'Click to choose image…', 'post-forwarder' ); ?></span>
                                <img id="pf-thumb-preview" src="" alt="" style="display:none;max-width:100%;max-height:120px;border-radius:4px;margin-top:6px;">
                            </label>
                            <input type="file" id="pf-new-thumb-file" accept="image/*" style="display:none;">
                            <input type="hidden" id="pf-new-thumb-url">
                            <button type="button" class="pf-thumb-clear" id="pf-thumb-clear" style="display:none;"><?php esc_html_e( 'Remove', 'post-forwarder' ); ?></button>
                        </div>
                    </div>
                </div>
                <div class="pf-field">
                    <label><?php esc_html_e( 'Date & time', 'post-forwarder' ); ?></label>
                    <input type="datetime-local" id="pf-scheduled-at">
                </div>
                <div class="pf-field">
                    <label><?php esc_html_e( 'Channels', 'post-forwarder' ); ?></label>
                    <?php if ( empty( $mappings ) ) : ?>
                        <p style="color:#50575e;font-size:13px;margin:0;"><?php esc_html_e( 'No channels configured. Go to Settings first.', 'post-forwarder' ); ?></p>
                    <?php else : ?>
                    <div class="pf-channels-grid">
                        <?php foreach ( $mappings as $key => $m ) :
                            $type   = isset( $m['type'] ) ? $m['type'] : 'wordpress';
                            $name   = isset( $m['name'] ) ? $m['name'] : $key;
                            $color  = isset( $platform_colors[ $type ] ) ? $platform_colors[ $type ]['bg'] : '#555';
                            $letter = strtoupper( mb_substr( $name, 0, 1 ) );
                        ?>
                        <label class="pf-channel-check" data-key="<?php echo esc_attr( $key ); ?>">
                            <input type="checkbox" name="channels[]" value="<?php echo esc_attr( $key ); ?>">
                            <span class="pf-avatar" style="background:<?php echo esc_attr( $color ); ?>;">
                                <span style="font-size:11px;font-weight:700;"><?php echo esc_html( $letter ); ?></span>
                            </span>
                            <span class="pf-channel-check-name"><?php echo esc_html( $name ); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pf-modal-footer">
                <button class="pf-delete-btn" id="pf-delete-btn" style="display:none;"><?php esc_html_e( 'Delete', 'post-forwarder' ); ?></button>
                <button class="pf-btn-primary" id="pf-save-btn"><?php esc_html_e( 'Save', 'post-forwarder' ); ?></button>
            </div>
        </div>
    </div>

    <!-- Add Channel modal -->
    <div class="pf-modal-overlay" id="pf-ch-overlay">
        <div class="pf-modal" style="width:420px;">
            <div class="pf-modal-header">
                <h3><?php esc_html_e( 'Add Channel', 'post-forwarder' ); ?></h3>
                <button class="pf-modal-close" id="pf-ch-close">&times;</button>
            </div>
            <div class="pf-modal-body">
                <div class="pf-field">
                    <label for="pf-ch-name"><?php esc_html_e( 'Channel Name', 'post-forwarder' ); ?></label>
                    <input type="text" id="pf-ch-name" placeholder="<?php esc_attr_e( 'e.g. My Facebook Page', 'post-forwarder' ); ?>">
                </div>
                <div class="pf-field">
                    <label><?php esc_html_e( 'Platform', 'post-forwarder' ); ?></label>
                    <div class="pf-type-picker">
                        <button type="button" class="pf-type-btn selected" data-type="wordpress">
                            <span class="pf-avatar" style="background:#3858e9;">W</span>
                            WordPress
                        </button>
                        <button type="button" class="pf-type-btn" data-type="linkedin">
                            <span class="pf-avatar" style="background:#0a66c2;">in</span>
                            LinkedIn
                        </button>
                        <button type="button" class="pf-type-btn" data-type="x">
                            <span class="pf-avatar" style="background:#000;">X</span>
                            X (Twitter)
                        </button>
                        <button type="button" class="pf-type-btn" data-type="facebook">
                            <span class="pf-avatar" style="background:#1877f2;">f</span>
                            Facebook
                        </button>
                        <button type="button" class="pf-type-btn" data-type="instagram">
                            <span class="pf-avatar" style="background:#c13584;">IG</span>
                            Instagram
                        </button>
                        <button type="button" class="pf-type-btn" data-type="meta">
                            <span class="pf-avatar" style="background:#1877f2;">f+</span>
                            Meta (FB+IG)
                        </button>
                    </div>
                </div>
                <div id="pf-ch-wp-section">
                    <div class="pf-field">
                        <label for="pf-ch-wp-url"><?php esc_html_e( 'Site URL', 'post-forwarder' ); ?></label>
                        <input type="url" id="pf-ch-wp-url" placeholder="https://example.com">
                    </div>
                    <p style="margin:4px 0 0;font-size:12px;color:#50575e;">
                        <?php esc_html_e( "You'll be redirected to the target site to authorize via WordPress Application Passwords.", 'post-forwarder' ); ?>
                        <a href="#" id="pf-ch-wp-manual-toggle" style="display:block;margin-top:4px;"><?php esc_html_e( 'Enter credentials manually instead', 'post-forwarder' ); ?></a>
                    </p>
                    <div id="pf-ch-wp-manual" style="display:none;margin-top:10px;">
                        <div class="pf-field">
                            <label for="pf-ch-wp-user"><?php esc_html_e( 'Username', 'post-forwarder' ); ?></label>
                            <input type="text" id="pf-ch-wp-user" placeholder="<?php esc_attr_e( 'username', 'post-forwarder' ); ?>">
                        </div>
                        <div class="pf-field">
                            <label for="pf-ch-wp-pass"><?php esc_html_e( 'App Password', 'post-forwarder' ); ?></label>
                            <input type="text" id="pf-ch-wp-pass" placeholder="xxxx xxxx xxxx xxxx">
                        </div>
                    </div>
                </div>
                <div id="pf-ch-social-section" style="display:none;">
                    <p class="pf-ch-info"><?php esc_html_e( "After saving you'll be redirected to authorize with the platform. You'll land on the Settings page once connected.", 'post-forwarder' ); ?></p>
                </div>
                <div id="pf-ch-msg" style="display:none;margin-top:4px;"></div>
            </div>
            <div class="pf-modal-footer">
                <span></span>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="pf-btn-secondary" id="pf-ch-cancel"><?php esc_html_e( 'Cancel', 'post-forwarder' ); ?></button>
                    <button type="button" class="pf-btn-primary" id="pf-ch-save"><?php esc_html_e( 'Save & Connect', 'post-forwarder' ); ?></button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var REST_URL      = <?php echo wp_json_encode( $rest_url ); ?>;
        var REST_NONCE    = <?php echo wp_json_encode( $rest_nonce ); ?>;
        var AJAX_URL      = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var AJAX_NONCE    = <?php echo wp_json_encode( wp_create_nonce( 'pf_add_channel' ) ); ?>;
        var SETTINGS_URL  = <?php echo wp_json_encode( admin_url( 'admin.php?page=post-forwarder-settings' ) ); ?>;
        var CHANNELS      = <?php echo wp_json_encode( $channels_js ); ?>;
        var INITIAL_ITEMS = <?php echo wp_json_encode( array_values( $pf_initial_rows ) ); ?>;
        var DAY_NAMES  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var MONTHS     = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var START_HOUR = 0;
        var END_HOUR   = 23;

        var currentStart  = startOfDay(new Date()); // today, not Monday
        var editingId     = null;
        var scheduleItems = INITIAL_ITEMS;
        var searchTimer   = null;

        function startOfDay(d) { var r = new Date(d); r.setHours(0,0,0,0); return r; }
        function addDays(d, n) { var r = new Date(d); r.setDate(r.getDate() + n); return r; }
        function fmtDate(d) { return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }
        function pad(n) { return n < 10 ? '0'+n : ''+n; }
        function fmtWeekLabel(start) {
            var end = addDays(start, 6);
            return MONTHS[start.getMonth()] + ' ' + start.getDate() + ' – ' + MONTHS[end.getMonth()] + ' ' + end.getDate() + ', ' + end.getFullYear();
        }
        function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        function apiFetch(method, path, body) {
            var headers = { 'X-WP-Nonce': REST_NONCE };
            if (body) { headers['Content-Type'] = 'application/json'; }
            return fetch(REST_URL + path, {
                method: method,
                headers: headers,
                body: body ? JSON.stringify(body) : undefined,
            }).then(function(r) {
                if (!r.ok) { return Promise.reject(new Error('HTTP ' + r.status)); }
                return r.json();
            });
        }

        // ── Grid ─────────────────────────────────────────────────────────
        function renderGrid() {
            document.getElementById('pf-week-label').textContent = fmtWeekLabel(currentStart);
            var today = new Date(); today.setHours(0,0,0,0);
            var wrap = document.getElementById('pf-grid-wrap');
            var html = '<div class="pf-grid">';
            html += '<div class="pf-time-col spacer"></div>';
            for (var di = 0; di < 7; di++) {
                var day = addDays(currentStart, di);
                var isToday = day.getTime() === today.getTime();
                html += '<div class="pf-day-header' + (isToday ? ' today' : '') + '">' + DAY_NAMES[day.getDay()] + '<span class="pf-day-num">' + day.getDate() + '</span></div>';
            }
            for (var h = START_HOUR; h <= END_HOUR; h++) {
                var label = h === 0 ? '12 AM' : h < 12 ? h+' AM' : h === 12 ? '12 PM' : (h-12)+' PM';
                html += '<div class="pf-time-col">' + label + '</div>';
                for (var dc = 0; dc < 7; dc++) {
                    html += '<div class="pf-slot" data-datetime="' + fmtDate(addDays(currentStart, dc)) + 'T' + pad(h) + ':00"></div>';
                }
            }
            html += '</div>';
            wrap.innerHTML = html;
            placeItems();
            wrap.addEventListener('click', function(e) {
                var item = e.target.closest('.pf-item');
                if (item) { e.stopPropagation(); var rec = scheduleItems.find(function(s){return s.id==item.dataset.id;}); if(rec) openModal(rec); return; }
                var slot = e.target.closest('.pf-slot');
                if (slot) openModal(null, slot.dataset.datetime);
            });
            drawNowLine();
            setInterval(drawNowLine, 60000);
        }

        function placeItems() {
            // Group items by cell so we can split width for collisions.
            var cellMap = {};
            var slots = document.querySelectorAll('.pf-slot');
            scheduleItems.forEach(function(item) {
                var dt = new Date(item.scheduled_at.replace(' ','T') + 'Z');
                var dayIdx = Math.round((new Date(fmtDate(dt)).getTime() - currentStart.getTime()) / 86400000);
                if (dayIdx < 0 || dayIdx > 6) return;
                var h = dt.getHours();
                if (h < START_HOUR || h > END_HOUR) return;
                var key = h + '-' + dayIdx;
                if (!cellMap[key]) cellMap[key] = [];
                cellMap[key].push({ item: item, dt: dt, h: h, dayIdx: dayIdx });
            });
            Object.keys(cellMap).forEach(function(key) {
                var entries = cellMap[key];
                var total   = entries.length;
                entries.forEach(function(entry, idx) {
                    var item = entry.item, dt = entry.dt;
                    var cell = slots[(entry.h - START_HOUR) * 7 + entry.dayIdx];
                    if (!cell) return;
                    var m      = dt.getMinutes();
                    var keys   = Array.isArray(item.channel_keys) ? item.channel_keys : [];
                    var firstCh = keys.length ? CHANNELS.find(function(c){return c.key===keys[0];}) : null;
                    var color  = firstCh ? firstCh.color : '#2271b1';
                    var badges = keys.map(function(k) {
                        var ch = CHANNELS.find(function(c){return c.key===k;});
                        return ch ? '<span class="pf-item-badge" style="background:rgba(0,0,0,.25)">'+ch.badge+'</span>' : '';
                    }).join('');
                    var el = document.createElement('div');
                    el.className = 'pf-item status-' + (item.status||'pending');
                    el.dataset.id = item.id;
                    var topPct   = (m / 60) * 100;
                    var wPct     = 100 / total;
                    var leftPct  = wPct * idx;
                    var rightPct = 100 - leftPct - wPct;
                    el.style.cssText = 'top:'+topPct+'%;'
                        + 'left:calc('+leftPct+'% + 3px);'
                        + 'right:calc('+rightPct+'% + 3px);'
                        + 'background:'+color+';border-left:3px solid rgba(0,0,0,.2);';
                    el.innerHTML = '<span class="pf-item-badges">'+badges+'</span><span class="pf-item-label">'+escHtml(item.post_title||item.title||'(untitled)')+'</span>';
                    cell.appendChild(el);
                });
            });
        }

        function drawNowLine() {
            var ex = document.querySelector('.pf-now-line'); if(ex) ex.remove();
            var now = new Date();
            var todayIdx = Math.round((new Date(fmtDate(now)).getTime()-currentStart.getTime())/86400000);
            if (todayIdx<0||todayIdx>6) return;
            var h=now.getHours(), m=now.getMinutes();
            if (h<START_HOUR||h>END_HOUR) return;
            var cell = document.querySelectorAll('.pf-slot')[(h-START_HOUR)*7+todayIdx];
            if (!cell) return;
            var line = document.createElement('div');
            line.className = 'pf-now-line';
            line.style.top = ((m/60)*100)+'%';
            cell.appendChild(line);
        }

        function safeRenderGrid() {
            try { renderGrid(); } catch(e) {
                console.error('Post Forwarder renderGrid error:', e);
                var w = document.getElementById('pf-grid-wrap');
                if (w) { w.innerHTML = '<div class="pf-loading" style="color:#cc0000;">Calendar error — please refresh the page.<br><small>'+e+'</small></div>'; }
            }
        }

        function loadWeek() {
            document.getElementById('pf-grid-wrap').innerHTML = '<div class="pf-loading">Loading…</div>';
            apiFetch('GET', 'schedule?week_start='+fmtDate(currentStart)).then(function(data) {
                scheduleItems = Array.isArray(data) ? data : [];
                safeRenderGrid();
            }).catch(function(e) {
                scheduleItems = [];
                safeRenderGrid();
            });
        }

        // ── Post list ─────────────────────────────────────────────────────
        function loadPosts(q) {
            var res = document.getElementById('pf-post-results');
            res.innerHTML = '<div class="pf-post-result" style="color:#50575e;cursor:default;">Loading…</div>';
            apiFetch('GET', 'posts?s='+encodeURIComponent(q)).then(function(posts) {
                var selId = document.getElementById('pf-post-id').value;
                if (!Array.isArray(posts) || !posts.length) {
                    res.innerHTML = '<div class="pf-post-result" style="color:#50575e;cursor:default;">No posts found.</div>';
                    return;
                }
                res.innerHTML = posts.map(function(p) {
                    var isSel = String(p.id) === String(selId);
                    return '<div class="pf-post-result'+(isSel?' selected':'')+'" data-id="'+p.id+'" data-title="'+escHtml(p.title)+'">'
                        +'<span class="pf-post-result-title">'+escHtml(p.title)+'</span>'
                        +'<span class="pf-post-status">'+p.status+'</span>'
                        +'</div>';
                }).join('');
                res.querySelectorAll('.pf-post-result[data-id]').forEach(function(el) {
                    el.addEventListener('click', function() {
                        document.getElementById('pf-post-id').value = this.dataset.id;
                        res.querySelectorAll('.pf-post-result').forEach(function(r){r.classList.remove('selected');});
                        this.classList.add('selected');
                    });
                });
            });
        }

        // ── Modal ────────────────────────────────────────────────────────
        function openModal(item, prefillDatetime) {
            editingId = item ? item.id : null;
            document.getElementById('pf-modal-overlay').classList.add('open');
            document.getElementById('pf-modal-title').textContent = item ? '<?php echo esc_js( __( 'Edit Scheduled Post', 'post-forwarder' ) ); ?>' : '<?php echo esc_js( __( 'Schedule Post', 'post-forwarder' ) ); ?>';
            document.getElementById('pf-delete-btn').style.display = item ? '' : 'none';
            document.getElementById('pf-post-id').value = '';
            document.getElementById('pf-new-title').value = '';
            document.getElementById('pf-new-content').value = '';
            document.getElementById('pf-new-thumb-file').value = '';
            document.getElementById('pf-new-thumb-url').value = '';
            document.getElementById('pf-thumb-preview').style.display = 'none';
            document.getElementById('pf-thumb-preview').src = '';
            document.getElementById('pf-thumb-hint').style.display = '';
            document.getElementById('pf-thumb-clear').style.display = 'none';
            document.querySelectorAll('.pf-channel-check').forEach(function(el) {
                el.classList.remove('checked'); el.querySelector('input').checked = false;
            });

            if (item) {
                // scheduled_at is stored in UTC — convert to local for the datetime-local input.
                var dt = new Date(item.scheduled_at.replace(' ','T') + 'Z');
                document.getElementById('pf-scheduled-at').value = fmtDate(dt)+'T'+pad(dt.getHours())+':'+pad(dt.getMinutes());
                if (item.post_id) {
                    setMode('existing');
                    document.getElementById('pf-post-id').value = item.post_id;
                } else {
                    setMode('new');
                    document.getElementById('pf-new-title').value = item.title || '';
                    document.getElementById('pf-new-content').value = item.content || '';
                }
                (Array.isArray(item.channel_keys) ? item.channel_keys : []).forEach(function(k) {
                    var lbl = document.querySelector('.pf-channel-check[data-key="'+k+'"]');
                    if (lbl) { lbl.classList.add('checked'); lbl.querySelector('input').checked = true; }
                });
            } else {
                setMode('existing');
                var now2 = new Date(); now2.setMinutes(0); now2.setSeconds(0);
                document.getElementById('pf-scheduled-at').value = prefillDatetime || (fmtDate(now2)+'T'+pad(now2.getHours())+':00');
            }
            // Always load post list when in existing mode
            if (document.querySelector('.pf-mode-tab.active').dataset.mode === 'existing') {
                loadPosts(document.getElementById('pf-post-search').value || '');
            }
        }

        function closeModal() {
            document.getElementById('pf-modal-overlay').classList.remove('open');
            editingId = null;
        }

        function setMode(mode) {
            document.querySelectorAll('.pf-mode-tab').forEach(function(t){t.classList.toggle('active', t.dataset.mode===mode);});
            document.getElementById('pf-mode-existing').style.display = mode==='existing' ? '' : 'none';
            document.getElementById('pf-mode-new').style.display      = mode==='new'      ? '' : 'none';
            if (mode === 'existing') loadPosts(document.getElementById('pf-post-search').value || '');
        }

        function saveModal() {
            var mode     = document.querySelector('.pf-mode-tab.active').dataset.mode;
            var dateVal  = document.getElementById('pf-scheduled-at').value;
            var channels = Array.from(document.querySelectorAll('.pf-channel-check input:checked')).map(function(i){return i.value;});
            if (!dateVal)      { alert('<?php echo esc_js( __( 'Please set a date and time.', 'post-forwarder' ) ); ?>'); return; }
            if (!channels.length) { alert('<?php echo esc_js( __( 'Please select at least one channel.', 'post-forwarder' ) ); ?>'); return; }
            // Convert browser local time to UTC ISO string for consistent server-side storage.
            var dateUTC = new Date(dateVal).toISOString();
            var body = { scheduled_at: dateUTC, channel_keys: channels };
            if (mode === 'existing') {
                var pid = document.getElementById('pf-post-id').value;
                if (!pid) { alert('<?php echo esc_js( __( 'Please select a post from the list.', 'post-forwarder' ) ); ?>'); return; }
                body.post_id = parseInt(pid, 10);
            } else {
                body.title     = document.getElementById('pf-new-title').value;
                body.content   = document.getElementById('pf-new-content').value;
                body.image_url = document.getElementById('pf-new-thumb-url').value;
                if (!body.title && !body.content) { alert('<?php echo esc_js( __( 'Please enter a title or content.', 'post-forwarder' ) ); ?>'); return; }
            }
            var btn = document.getElementById('pf-save-btn');
            btn.textContent = '<?php echo esc_js( __( 'Saving…', 'post-forwarder' ) ); ?>';
            btn.disabled = true;
            (editingId ? apiFetch('PUT','schedule/'+editingId,body) : apiFetch('POST','schedule',body))
            .then(function() { closeModal(); loadWeek(); })
            .catch(function() { alert('<?php echo esc_js( __( 'Save failed. Please try again.', 'post-forwarder' ) ); ?>'); })
            .finally(function() { btn.textContent='<?php echo esc_js( __( 'Save', 'post-forwarder' ) ); ?>'; btn.disabled=false; });
        }

        function deleteItem() {
            if (!editingId || !confirm('<?php echo esc_js( __( 'Delete this scheduled post?', 'post-forwarder' ) ); ?>')) return;
            apiFetch('DELETE','schedule/'+editingId).then(function(){closeModal();loadWeek();});
        }

        // ── Events ───────────────────────────────────────────────────────
        document.getElementById('pf-prev-week').addEventListener('click', function(){currentStart=addDays(currentStart,-7);loadWeek();});
        document.getElementById('pf-next-week').addEventListener('click', function(){currentStart=addDays(currentStart,7);loadWeek();});
        document.getElementById('pf-today-btn').addEventListener('click', function(){currentStart=startOfDay(new Date());loadWeek();});
        document.getElementById('pf-new-post-btn').addEventListener('click', function(){openModal(null);});
        document.getElementById('pf-modal-close').addEventListener('click', closeModal);
        document.getElementById('pf-modal-overlay').addEventListener('click', function(e){if(e.target===this)closeModal();});
        document.getElementById('pf-save-btn').addEventListener('click', saveModal);
        document.getElementById('pf-delete-btn').addEventListener('click', deleteItem);
        document.querySelectorAll('.pf-mode-tab').forEach(function(tab){tab.addEventListener('click',function(){setMode(this.dataset.mode);});});
        document.querySelectorAll('.pf-channel-check').forEach(function(lbl){
            lbl.addEventListener('click', function(e){
                e.preventDefault(); // stop browser from auto-toggling the input a second time
                var cb = this.querySelector('input');
                cb.checked = !cb.checked;
                this.classList.toggle('checked', cb.checked);
            });
        });
        document.getElementById('pf-post-search').addEventListener('input', function(){
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function(){loadPosts(document.getElementById('pf-post-search').value);}, 300);
        });

        // Thumbnail upload for new-content mode.
        document.getElementById('pf-thumb-label').addEventListener('click', function(){
            document.getElementById('pf-new-thumb-file').click();
        });
        document.getElementById('pf-new-thumb-file').addEventListener('change', function(){
            var file = this.files[0];
            if (!file) return;
            var hint = document.getElementById('pf-thumb-hint');
            hint.textContent = '<?php echo esc_js( __( 'Uploading…', 'post-forwarder' ) ); ?>';
            hint.className = 'pf-thumb-uploading';
            var fd = new FormData();
            fd.append('file', file);
            fd.append('title', file.name);
            fetch('<?php echo esc_js( rest_url( 'wp/v2/media' ) ); ?>', {
                method: 'POST',
                headers: { 'X-WP-Nonce': REST_NONCE },
                body: fd,
            }).then(function(r){ return r.json(); }).then(function(media){
                if (media.source_url) {
                    document.getElementById('pf-new-thumb-url').value = media.source_url;
                    var img = document.getElementById('pf-thumb-preview');
                    img.src = media.source_url;
                    img.style.display = 'block';
                    hint.textContent = file.name;
                    hint.className = '';
                    document.getElementById('pf-thumb-clear').style.display = '';
                } else {
                    hint.textContent = '<?php echo esc_js( __( 'Upload failed. Try again.', 'post-forwarder' ) ); ?>';
                    hint.className = '';
                }
            }).catch(function(){
                hint.textContent = '<?php echo esc_js( __( 'Upload failed. Try again.', 'post-forwarder' ) ); ?>';
                hint.className = '';
            });
        });
        document.getElementById('pf-thumb-clear').addEventListener('click', function(){
            document.getElementById('pf-new-thumb-file').value = '';
            document.getElementById('pf-new-thumb-url').value = '';
            document.getElementById('pf-thumb-preview').style.display = 'none';
            document.getElementById('pf-thumb-preview').src = '';
            document.getElementById('pf-thumb-hint').textContent = '<?php echo esc_js( __( 'Click to choose image…', 'post-forwarder' ) ); ?>';
            document.getElementById('pf-thumb-hint').className = '';
            this.style.display = 'none';
        });

        document.addEventListener('keydown', function(e){if(e.key==='Escape'){closeModal();closeChModal();}});

        // ── Add Channel modal ─────────────────────────────────────────────
        var selectedChType = 'wordpress';
        var chManualMode   = false;

        function openChModal() {
            selectedChType = 'wordpress';
            chManualMode   = false;
            document.getElementById('pf-ch-name').value = '';
            document.getElementById('pf-ch-wp-url').value = '';
            document.getElementById('pf-ch-wp-user').value = '';
            document.getElementById('pf-ch-wp-pass').value = '';
            document.getElementById('pf-ch-wp-manual').style.display = 'none';
            document.getElementById('pf-ch-msg').style.display = 'none';
            document.querySelectorAll('.pf-type-btn').forEach(function(b){ b.classList.toggle('selected', b.dataset.type === 'wordpress'); });
            document.getElementById('pf-ch-wp-section').style.display = '';
            document.getElementById('pf-ch-social-section').style.display = 'none';
            document.getElementById('pf-ch-save').textContent = <?php echo wp_json_encode( __( 'Save & Connect', 'post-forwarder' ) ); ?>;
            document.getElementById('pf-ch-overlay').classList.add('open');
            document.getElementById('pf-ch-name').focus();
        }
        function closeChModal() {
            document.getElementById('pf-ch-overlay').classList.remove('open');
        }
        function setChType(type) {
            selectedChType = type;
            chManualMode   = false;
            document.querySelectorAll('.pf-type-btn').forEach(function(b){ b.classList.toggle('selected', b.dataset.type === type); });
            document.getElementById('pf-ch-wp-section').style.display = (type === 'wordpress') ? '' : 'none';
            document.getElementById('pf-ch-social-section').style.display = (type !== 'wordpress') ? '' : 'none';
            document.getElementById('pf-ch-wp-manual').style.display = 'none';
            document.getElementById('pf-ch-save').textContent = <?php echo wp_json_encode( __( 'Save & Connect', 'post-forwarder' ) ); ?>;
        }
        function saveChannel() {
            var name = document.getElementById('pf-ch-name').value.trim();
            var url  = document.getElementById('pf-ch-wp-url').value.trim();
            var user = document.getElementById('pf-ch-wp-user').value.trim();
            var pass = document.getElementById('pf-ch-wp-pass').value.trim();
            var msg  = document.getElementById('pf-ch-msg');
            msg.style.display = 'none';

            if (!name) { msg.style.display=''; msg.style.color='#d63638'; msg.textContent='<?php echo esc_js( __( 'Please enter a channel name.', 'post-forwarder' ) ); ?>'; return; }
            if (selectedChType === 'wordpress' && !url) { msg.style.display=''; msg.style.color='#d63638'; msg.textContent='<?php echo esc_js( __( 'Please enter the site URL.', 'post-forwarder' ) ); ?>'; return; }

            var btn = document.getElementById('pf-ch-save');
            btn.disabled = true;
            btn.textContent = '<?php echo esc_js( __( 'Saving…', 'post-forwarder' ) ); ?>';

            var fd = new FormData();
            fd.append('action',   'pf_add_channel');
            fd.append('nonce',    AJAX_NONCE);
            fd.append('name',     name);
            fd.append('type',     selectedChType);
            fd.append('url',      url);
            fd.append('user',     user);
            fd.append('password', pass);

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    btn.textContent = <?php echo wp_json_encode( __( 'Save & Connect', 'post-forwarder' ) ); ?>;
                    if (!res.success) {
                        msg.style.display=''; msg.style.color='#d63638';
                        msg.textContent = res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'An error occurred.', 'post-forwarder' ) ); ?>';
                        return;
                    }
                    var ch = res.data;
                    // Add to CHANNELS so new posts can target it.
                    CHANNELS.push({ key: ch.key, name: ch.name, type: ch.type, color: ch.color, badge: ch.badge, connected: ch.connected });
                    // Add to sidebar list.
                    var letter = ch.name.charAt(0).toUpperCase();
                    var li = document.createElement('li');
                    li.className = 'pf-channel-item';
                    li.innerHTML = '<div class="pf-channel-item-top">'
                        + '<span class="pf-avatar" style="background:'+ch.color+';">'+escHtml(letter)
                        + '<span class="pf-platform-badge" style="background:'+ch.color+';filter:brightness(.7);">'+escHtml(ch.badge)+'</span></span>'
                        + '<div class="pf-channel-info"><div class="pf-channel-name">'+escHtml(ch.name)+'</div>'
                        + '<div class="pf-channel-status"><span class="pf-status-dot disconnected"></span>'
                        + '<span class="pf-status-text"><?php echo esc_js( __( 'Not connected', 'post-forwarder' ) ); ?></span></div></div></div>';
                    // Insert before the "Add channel" list item.
                    var addBtn = document.querySelector('.pf-add-ch-item');
                    addBtn.parentNode.insertBefore(li, addBtn);
                    // Add checkbox to schedule modal channel grid.
                    var grid = document.querySelector('.pf-channels-grid');
                    if (grid) {
                        var lbl = document.createElement('label');
                        lbl.className = 'pf-channel-check';
                        lbl.dataset.key = ch.key;
                        lbl.innerHTML = '<input type="checkbox" value="'+escHtml(ch.key)+'">'
                            + '<span class="pf-avatar" style="background:'+ch.color+';width:26px;height:26px;font-size:10px;">'+escHtml(letter)
                            + '<span class="pf-platform-badge" style="background:'+ch.color+';filter:brightness(.7);">'+escHtml(ch.badge)+'</span></span>'
                            + '<span class="pf-channel-check-name">'+escHtml(ch.name)+'</span>';
                        lbl.addEventListener('click', function(e){
                            e.preventDefault();
                            var cb = this.querySelector('input');
                            cb.checked = !cb.checked;
                            this.classList.toggle('checked', cb.checked);
                        });
                        grid.appendChild(lbl);
                    }
                    // Remove "No channels" placeholder if present.
                    var noChEl = document.querySelector('.pf-no-channels');
                    if (noChEl) noChEl.remove();
                    closeChModal();
                    // Redirect to OAuth/auth flow if URL returned.
                    if (ch.oauth_url) { window.location.href = ch.oauth_url; }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = <?php echo wp_json_encode( __( 'Save & Connect', 'post-forwarder' ) ); ?>;
                    msg.style.display=''; msg.style.color='#d63638';
                    msg.textContent = '<?php echo esc_js( __( 'Request failed. Please try again.', 'post-forwarder' ) ); ?>';
                });
        }

        document.getElementById('pf-add-channel-btn').addEventListener('click', openChModal);
        document.getElementById('pf-ch-close').addEventListener('click', closeChModal);
        document.getElementById('pf-ch-cancel').addEventListener('click', closeChModal);
        document.getElementById('pf-ch-overlay').addEventListener('click', function(e){ if(e.target===this) closeChModal(); });
        document.getElementById('pf-ch-save').addEventListener('click', saveChannel);
        document.querySelectorAll('.pf-type-btn').forEach(function(btn){
            btn.addEventListener('click', function(){ setChType(this.dataset.type); });
        });
        document.getElementById('pf-ch-wp-manual-toggle').addEventListener('click', function(e){
            e.preventDefault();
            chManualMode = !chManualMode;
            document.getElementById('pf-ch-wp-manual').style.display = chManualMode ? '' : 'none';
            this.textContent = chManualMode
                ? '<?php echo esc_js( __( 'Use one-click connect instead', 'post-forwarder' ) ); ?>'
                : '<?php echo esc_js( __( 'Enter credentials manually instead', 'post-forwarder' ) ); ?>';
        });

        // Fire after all WP admin footer scripts have executed, so nothing can reset our DOM.
        function initCalendar() { loadWeek(); }
        if (document.readyState === 'complete') {
            initCalendar();
        } else {
            window.addEventListener('load', initCalendar);
        }
    })();
    </script>
    <?php
}
