<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* Part of Post Forwarder plugin */

function post_forwarder_review_notice() {
    // Show to anyone who can edit posts (covers editors, authors) or manage options.
    if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Only on the post editor and the plugin settings page.
    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }
    $is_post_edit = in_array( $screen->base, array( 'post', 'edit' ), true );
    $is_settings  = ( 'settings_page_post-forwarding' === $screen->id );
    if ( ! $is_post_edit && ! $is_settings ) {
        return;
    }

    // Once dismissed, never show again for this user.
    if ( get_user_meta( get_current_user_id(), 'post_forwarder_review_dismissed', true ) ) {
        return;
    }
    ?>
    <div class="notice notice-info is-dismissible" id="post-forwarder-review-notice">
        <p>
            <?php esc_html_e( '👋 Enjoying Post Forwarder? It would mean a lot if you left a quick review — it helps others find the plugin.', 'post-forwarder' ); ?>
            &nbsp;
            <a href="https://wordpress.org/support/plugin/post-forwarder/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( '⭐ Leave a Review', 'post-forwarder' ); ?></a>
            <a href="#" class="button" style="margin-left:6px;" id="post-forwarder-dismiss-review"><?php esc_html_e( 'I already did', 'post-forwarder' ); ?></a>
        </p>
    </div>
    <script>
    jQuery(document).ready(function($){
        function dismissReview(){
            $.post(ajaxurl, { action: 'post_forwarder_dismiss_review', nonce: '<?php echo esc_js( wp_create_nonce( 'post_forwarder_dismiss_review' ) ); ?>' });
            $('#post-forwarder-review-notice').remove();
        }
        $('#post-forwarder-dismiss-review').on('click', function(e){ e.preventDefault(); dismissReview(); });
        $(document).on('click', '#post-forwarder-review-notice .notice-dismiss', function(){ dismissReview(); });
    });
    </script>
    <?php
}

function post_forwarder_dismiss_review() {
    check_ajax_referer( 'post_forwarder_dismiss_review', 'nonce' );
    update_user_meta( get_current_user_id(), 'post_forwarder_review_dismissed', true );
    wp_die();
}
