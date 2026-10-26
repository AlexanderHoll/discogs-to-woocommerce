<?php
// Removes D2W options, transients and cron events. Product meta (_discogs_*) is left alone
// so imported products keep their link to Discogs if the plugin is reinstalled.

defined('ABSPATH') || exit;
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('d2w_') . '%',
        $wpdb->esc_like('_transient_d2w_') . '%',
        $wpdb->esc_like('_transient_timeout_d2w_') . '%'
    )
);

wp_clear_scheduled_hook('d2w_sync_event');
wp_cache_flush();
