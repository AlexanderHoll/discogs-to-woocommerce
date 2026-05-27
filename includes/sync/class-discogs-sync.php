<?php

class D2W_Discogs_Sync {

    public static function register() {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_intervals']);
        add_action('d2w_sync_event', [__CLASS__, 'run_sync']);
        add_action('admin_post_d2w_manual_sync', [__CLASS__, 'handle_manual_sync']);
        add_action('update_option_d2w_sync_interval', [__CLASS__, 'reschedule_on_change'], 10, 2);

        // Ensure the cron event is scheduled if it isn't already (e.g. after settings save
        // without a deactivation/reactivation cycle).
        add_action('admin_init', [__CLASS__, 'maybe_schedule']);
    }

    public static function schedule() {
        $interval = get_option('d2w_sync_interval', 'disabled');
        if ($interval !== 'disabled' && !wp_next_scheduled('d2w_sync_event')) {
            wp_schedule_event(time(), $interval, 'd2w_sync_event');
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook('d2w_sync_event');
    }

    public static function maybe_schedule() {
        $interval = get_option('d2w_sync_interval', 'disabled');
        if ($interval === 'disabled') {
            wp_clear_scheduled_hook('d2w_sync_event');
            return;
        }
        if (!wp_next_scheduled('d2w_sync_event')) {
            wp_schedule_event(time(), $interval, 'd2w_sync_event');
        }
    }

    public static function reschedule_on_change($old_value, $new_value) {
        wp_clear_scheduled_hook('d2w_sync_event');
        if ($new_value !== 'disabled') {
            wp_schedule_event(time(), $new_value, 'd2w_sync_event');
        }
    }

    public static function add_cron_intervals($schedules) {
        $schedules['d2w_15min'] = ['interval' => 900,  'display' => 'Every 15 Minutes'];
        $schedules['d2w_30min'] = ['interval' => 1800, 'display' => 'Every 30 Minutes'];
        return $schedules;
    }

    public static function handle_manual_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('d2w_manual_sync');
        self::run_sync();
        wp_redirect(add_query_arg('d2w_synced', '1', admin_url('admin.php?page=d2w-settings')));
        exit;
    }

    public static function run_sync() {
        if (!get_option('d2w_user_token')) {
            return;
        }

        // Only check products we've imported that are still showing as in stock.
        $post_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft'],
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_discogs_listing_id', 'compare' => 'EXISTS'],
                ['key' => '_stock_status', 'value' => 'instock'],
            ],
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);

        $marked_sold = 0;

        foreach ($post_ids as $post_id) {
            $listing_id = get_post_meta($post_id, '_discogs_listing_id', true);
            if (!$listing_id) {
                continue;
            }

            $status = D2W_Discogs_API::fetch_listing_status($listing_id);

            // null means the API request failed — skip to avoid false positives.
            if ($status === null) {
                continue;
            }

            if ($status !== 'For Sale') {
                $product = wc_get_product($post_id);
                if ($product) {
                    $product->set_stock_status('outofstock');
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity(0);
                    $product->save();
                    $marked_sold++;
                }
            }
        }

        update_option('d2w_last_sync', current_time('mysql'));
        update_option('d2w_last_sync_count', $marked_sold);
    }
}