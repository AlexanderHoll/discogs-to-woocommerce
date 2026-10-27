<?php

defined('ABSPATH') || exit;

/**
 * One-off data jobs for linked products: release details, format/condition attributes,
 * and missing regular prices. Batched through Action Scheduler from the admin, or run
 * directly from WP-CLI.
 */
class D2W_Backfill {

    const BATCH_HOOK = 'd2w_backfill_batch';
    const BATCH_SIZE = 10;
    // Each product costs about two API requests; a minute between batches keeps a run
    // well inside the 60 requests/minute limit alongside normal sync traffic.
    const BATCH_GAP  = MINUTE_IN_SECONDS;
    const STATUS     = 'd2w_backfill_status';

    public static function register() {
        add_action(self::BATCH_HOOK, [__CLASS__, 'run_batch']);
    }

    // Linked products that still need release data (or all linked products when $force).
    public static function pending_ids(bool $force = false, int $limit = 0): array {
        global $wpdb;
        $sql = "SELECT p.ID FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = '_discogs_listing_id' AND l.meta_value <> ''
                LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = %s
                WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')" .
               ($force ? '' : ' AND f.meta_id IS NULL') .
               ' ORDER BY p.ID' . ($limit > 0 ? ' LIMIT ' . (int) $limit : '');
        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, D2W_Release_Data::FETCHED_META)));
    }

    /**
     * Processes products: listing attributes (from the inventory map or a single listing
     * fetch) and release details. Returns counts.
     */
    public static function process(array $product_ids, bool $dry_run = false, ?array $inventory = null): array {
        $counts    = ['processed' => 0, 'release' => 0, 'attributes' => 0, 'failed' => 0];
        $changed   = [];
        $inventory ??= D2W_Product_Linker::inventory() ?? [];

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $counts['processed']++;
            $listing_id = absint($product->get_meta('_discogs_listing_id'));
            $listing    = $inventory[$listing_id] ?? D2W_Discogs_API::fetch_listing($listing_id);

            if ($dry_run) {
                continue;
            }

            if ($listing && D2W_Release_Data::apply_listing_attributes($product, $listing)) {
                $product->save();
                $counts['attributes']++;
            }

            if (D2W_Release_Data::fetch_and_save($product_id, $listing)) {
                $counts['release']++;
                $changed[] = $product_id;
            } else {
                $counts['failed']++;
                // Mark as attempted so a release that no longer exists is not retried forever.
                update_post_meta($product_id, D2W_Release_Data::FETCHED_META, 0);
            }
        }

        D2W_Cache::purge_products($changed);
        return $counts;
    }

    // Queues the first batch; later batches queue themselves.
    public static function queue(bool $force = false): int {
        $pending = count(self::pending_ids($force));
        update_option(self::STATUS, ['started' => time(), 'total' => $pending, 'done' => 0, 'force' => $force, 'counts' => []], false);
        if ($pending && function_exists('as_enqueue_async_action')) {
            as_unschedule_all_actions(self::BATCH_HOOK, [], 'd2w');
            as_enqueue_async_action(self::BATCH_HOOK, [], 'd2w');
        }
        return $pending;
    }

    public static function run_batch(): void {
        $status = get_option(self::STATUS, []);
        $force  = !empty($status['force']);
        $ids    = self::pending_ids(false, self::BATCH_SIZE);
        if ($force) {
            // Forced runs re-process everything once: walk past what this run already did.
            $done = (int) ($status['done'] ?? 0);
            $ids  = array_slice(self::pending_ids(true), $done, self::BATCH_SIZE);
        }
        if (!$ids) {
            $status['finished'] = time();
            update_option(self::STATUS, $status, false);
            D2W_Logger::info('Backfill finished: ' . wp_json_encode($status['counts'] ?? []));
            return;
        }

        $counts = self::process($ids);
        $status['done'] = (int) ($status['done'] ?? 0) + count($ids);
        foreach ($counts as $k => $v) {
            $status['counts'][$k] = ($status['counts'][$k] ?? 0) + $v;
        }
        update_option(self::STATUS, $status, false);

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + self::BATCH_GAP, self::BATCH_HOOK, [], 'd2w');
        }
    }

    // -------------------------------------------------------------------------
    // Prices
    // -------------------------------------------------------------------------

    /**
     * Sets the regular price from _price where the regular price is empty and there is no
     * sale price. Uses the product API so lookup tables update.
     *
     * @return array{fixed: int, ids: int[]}
     */
    public static function fix_prices(bool $dry_run = false): array {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_price' AND pr.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} rp ON rp.post_id = p.ID AND rp.meta_key = '_regular_price'
             LEFT JOIN {$wpdb->postmeta} sp ON sp.post_id = p.ID AND sp.meta_key = '_sale_price'
             WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (rp.meta_value IS NULL OR rp.meta_value = '')
               AND (sp.meta_value IS NULL OR sp.meta_value = '')"
        ));

        $fixed = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product || !$product->is_type('simple')) {
                continue;
            }
            $price = (string) get_post_meta($id, '_price', true);
            if ($price === '' || !is_numeric($price)) {
                continue;
            }
            if (!$dry_run) {
                $product->set_regular_price(wc_format_decimal($price));
                $product->save();
            }
            $fixed[] = $id;
        }

        if (!$dry_run && $fixed) {
            D2W_Logger::info('Regular price set from _price on ' . count($fixed) . ' product(s).');
        }
        return ['fixed' => count($fixed), 'ids' => $fixed];
    }
}
