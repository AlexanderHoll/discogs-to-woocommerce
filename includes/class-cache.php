<?php

defined('ABSPATH') || exit;

/**
 * Clears page caches for products D2W changed (sync, backfill, release fetches), so stock,
 * prices and release details show straight away.
 *
 * - LiteSpeed Cache: purges each product page and every page that lists products, through
 *   LiteSpeed's own actions (nothing happens if the plugin is not active).
 * - Cloudflare: optional. Only needed when Cloudflare caches HTML pages (APO or a "Cache
 *   Everything" rule). With a zone ID and an API token (Zone > Cache Purge permission) set,
 *   the changed product URLs, the shop page and the home page are purged by URL.
 * - Other plugins can hook `d2w_products_changed` (array of product IDs).
 */
class D2W_Cache {

    const CF_ENDPOINT = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';
    const CF_BATCH    = 30; // Cloudflare's limit of URLs per purge request.

    public static function purge_products(array $product_ids): void {
        $ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
        if (!$ids) {
            return;
        }

        foreach ($ids as $id) {
            do_action('litespeed_purge_post', $id);
        }
        // Shop, category and search pages also show stock and prices.
        do_action('litespeed_purge_posttype', 'product');

        do_action('d2w_products_changed', $ids);

        self::purge_cloudflare($ids);
    }

    private static function purge_cloudflare(array $ids): void {
        $zone  = trim((string) get_option('d2w_cf_zone', ''));
        $token = trim((string) get_option('d2w_cf_token', ''));
        if ($zone === '' || $token === '') {
            return;
        }

        $urls = [home_url('/')];
        if (function_exists('wc_get_page_id') && wc_get_page_id('shop') > 0) {
            $urls[] = get_permalink(wc_get_page_id('shop'));
        }
        foreach ($ids as $id) {
            if (get_post_status($id) === 'publish') {
                $urls[] = get_permalink($id);
            }
        }
        $urls = array_values(array_unique(array_filter($urls)));

        foreach (array_chunk($urls, self::CF_BATCH) as $batch) {
            $response = wp_remote_post(sprintf(self::CF_ENDPOINT, rawurlencode($zone)), [
                'timeout' => 10,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['files' => $batch]),
            ]);
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                D2W_Logger::warning('Cloudflare purge failed: ' . (is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . $code . ' ' . wp_remote_retrieve_body($response)));
                return;
            }
        }
        D2W_Logger::info(sprintf('Cloudflare purged %d URL(s).', count($urls)));
    }
}
