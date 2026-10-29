<?php

defined('ABSPATH') || exit;

class D2W_Discogs_Sync {

    const LOCK_KEY        = 'd2w_sync_lock';
    const LOCK_TTL        = 15 * MINUTE_IN_SECONDS;
    const LOG_OPTION      = 'd2w_sync_log';
    const LOG_SIZE        = 20;
    const NEW_LISTINGS    = 'd2w_unimported_count';
    const AUTO_IMPORT_MAX = 10;

    // option => default. Read with D2W_Discogs_Sync::enabled().
    const SETTINGS = [
        'd2w_sync_restock'       => '1',
        'd2w_sync_prices'        => '0',
        'd2w_sync_auto_import'   => '0',
        'd2w_sync_push_to_drafts' => '0',
        'd2w_fill_gallery'       => '0',
        'd2w_fill_weight'        => '1',
    ];

    // Product meta set when an imported product sells on the website (see D2W_Order_Sync).
    const SOLD_ON_SITE_META = '_d2w_sold_on_site';

    public static function register() {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_intervals']);
        add_action('d2w_sync_event', [__CLASS__, 'run_cron']);
        add_action('admin_post_d2w_manual_sync', [__CLASS__, 'handle_manual_sync']);
        add_action('admin_post_d2w_preview_sync', [__CLASS__, 'handle_preview_sync']);
        add_action('update_option_d2w_sync_interval', [__CLASS__, 'reschedule_on_change'], 10, 2);
        add_action('add_option_d2w_sync_interval', [__CLASS__, 'reschedule_on_add'], 10, 2);

        // Ensure the cron event is scheduled if it isn't already (e.g. after settings save
        // without a deactivation/reactivation cycle).
        add_action('admin_init', [__CLASS__, 'maybe_schedule']);
    }

    public static function enabled(string $option): bool {
        return get_option($option, self::SETTINGS[$option] ?? '0') === '1';
    }

    // -------------------------------------------------------------------------
    // Scheduling
    // -------------------------------------------------------------------------

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

    public static function reschedule_on_add($option, $value) {
        self::reschedule_on_change(null, $value);
    }

    public static function add_cron_intervals($schedules) {
        $schedules['d2w_15min'] = ['interval' => 900,  'display' => 'Every 15 Minutes'];
        $schedules['d2w_30min'] = ['interval' => 1800, 'display' => 'Every 30 Minutes'];
        return $schedules;
    }

    // -------------------------------------------------------------------------
    // Admin triggers
    // -------------------------------------------------------------------------

    public static function run_cron() {
        self::run_sync(false, 'cron');
    }

    public static function handle_manual_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('d2w_manual_sync');
        $report = self::run_sync(false, 'manual');
        set_transient('d2w_sync_report_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('d2w_synced', '1', admin_url('admin.php?page=d2w-settings')));
        exit;
    }

    public static function handle_preview_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('d2w_preview_sync');
        $report = self::run_sync(true, 'preview');
        set_transient('d2w_sync_report_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('d2w_synced', 'preview', admin_url('admin.php?page=d2w-settings')));
        exit;
    }

    // -------------------------------------------------------------------------
    // Sync
    // -------------------------------------------------------------------------

    /**
     * Compares imported products with the Discogs For Sale inventory and applies (or,
     * for a dry run, only reports) the changes.
     *
     * @return array{status: string, dry_run: bool, trigger: string, time: int, duration: float,
     *               counts: array<string, int>, changes: array<int, array>, messages: string[]}
     */
    public static function run_sync(bool $dry_run = false, string $trigger = 'cron'): array {
        $started = microtime(true);
        $report  = [
            'status'   => 'ok',
            'dry_run'  => $dry_run,
            'trigger'  => $trigger,
            'time'     => time(),
            'duration' => 0.0,
            'counts'   => array_fill_keys(['checked', 'sold', 'restocked', 'prices', 'imported', 'new', 'skipped', 'errors'], 0),
            'changes'  => [],
            'messages' => [],
        ];

        if (!get_option('d2w_user_token')) {
            $report['status']     = 'error';
            $report['messages'][] = 'No Personal Access Token set.';
            return $report;
        }

        if (get_transient(self::LOCK_KEY)) {
            $report['status']     = 'locked';
            $report['messages'][] = 'Another sync is already running.';
            D2W_Logger::warning('Sync skipped: another run holds the lock.', ['trigger' => $trigger]);
            return $report;
        }
        set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);

        try {
            if (function_exists('wc_set_time_limit')) {
                wc_set_time_limit(0);
            }
            self::compare($report, $dry_run);
        } catch (Throwable $e) {
            $report['status']     = 'error';
            $report['messages'][] = 'Sync failed: ' . $e->getMessage();
            $report['counts']['errors']++;
            D2W_Logger::error('Sync failed: ' . $e->getMessage());
        } finally {
            delete_transient(self::LOCK_KEY);
        }

        $report['duration'] = round(microtime(true) - $started, 1);

        if (!$dry_run) {
            self::record_run($report);
        }

        D2W_Logger::info(sprintf(
            '%s %s: %s',
            $dry_run ? 'Dry run' : 'Sync',
            $report['status'],
            wp_json_encode($report['counts'])
        ), ['trigger' => $trigger]);

        return $report;
    }

    private static function compare(array &$report, bool $dry_run): void {
        $listings = D2W_Discogs_API::fetch_inventory_for_sale();
        if ($listings === null) {
            $report['status']     = 'error';
            $report['messages'][] = 'Could not fetch the full Discogs inventory. No changes were made.';
            $report['counts']['errors']++;
            return;
        }

        $imported = D2W_Product_Importer::get_imported_listing_ids();
        $restock  = self::enabled('d2w_sync_restock');
        $prices   = self::enabled('d2w_sync_prices');
        $currency = get_woocommerce_currency();

        // A holder (product, or variation) can hold several listings of one release; its
        // stock is the number of them For Sale on Discogs and not sold on the website.
        $holders = [];
        foreach ($imported as $listing_id => $holder_id) {
            $holders[(int) $holder_id][] = (int) $listing_id;
        }

        $plans   = [];
        $missing = []; // holder ID => listing IDs counted in stock but gone from the inventory
        $changes = [];
        $in_stock_listings = 0;

        foreach ($holders as $holder_id => $listing_ids) {
            $product = wc_get_product($holder_id);
            if (!$product) {
                continue;
            }
            $report['counts']['checked'] += count($listing_ids);

            $sold     = D2W_Copies::sold_listings($holder_id);
            $was_gone = D2W_Copies::gone_listings($holder_id);
            $current  = $product->get_stock_status() === 'instock' ? max(1, (int) $product->get_stock_quantity()) : 0;
            $base     = ['product_id' => $holder_id, 'listing_id' => $listing_ids[0], 'name' => self::holder_name($product)];
            $for_sale = 0;
            $gone     = [];
            $returned = [];
            $first    = null;

            foreach ($listing_ids as $listing_id) {
                $listing = $listings[$listing_id] ?? null;
                if ($listing === null) {
                    if (isset($sold[$listing_id])) {
                        // Gone from Discogs too, so a future relist is a genuine relist.
                        $changes[] = ['listing_id' => $listing_id] + $base + ['type' => 'clear_flag', 'detail' => 'Listing no longer For Sale; website sale flag cleared'];
                    } elseif (!isset($was_gone[$listing_id])) {
                        $gone[] = $listing_id; // Not counted as sold yet.
                    }
                    continue;
                }
                if (isset($was_gone[$listing_id])) {
                    $returned[] = $listing_id; // Sold on Discogs before, For Sale again.
                }
                if (isset($sold[$listing_id])) {
                    $report['counts']['skipped']++;
                    $report['messages'][] = sprintf(
                        '"%s" sold on the website but listing %d is still For Sale on Discogs. Not restocked; remove or draft it on Discogs.',
                        $base['name'],
                        $listing_id
                    );
                    continue;
                }
                $for_sale++;
                $first ??= ['id' => $listing_id, 'listing' => $listing];
            }

            // A missing listing only matters while the holder has stock to take it from.
            if ($gone && $current > 0) {
                $missing[$holder_id] = array_slice($gone, 0, $current);
            }
            $in_stock_listings += $current;
            $plans[$holder_id] = ['product' => $product, 'current' => $current, 'for_sale' => $for_sale, 'returned' => $returned, 'gone' => [], 'base' => $base];

            if (!$first) {
                continue;
            }
            $fbase = ['listing_id' => $first['id']] + $base;

            // Keep the filterable format / condition attributes in step with the listing
            // (a variation's go on its parent product, which the shop filters).
            $attr_product = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
            if ($attr_product && D2W_Release_Data::listing_attributes_differ($attr_product, $first['listing'])) {
                $changes[] = $fbase + ['type' => 'details', 'detail' => 'Format or condition attributes updated from the listing'];
            }

            if ($prices && isset($first['listing']['price']['value'])) {
                $new = wc_format_decimal($first['listing']['price']['value'], 2);
                $old = wc_format_decimal($product->get_regular_price(), 2);
                if (($first['listing']['price']['currency'] ?? $currency) !== $currency) {
                    $report['counts']['skipped']++;
                    $report['messages'][] = sprintf('Price for listing %d is in %s, store uses %s. Not updated.', $first['id'], $first['listing']['price']['currency'], $currency);
                } elseif ($product->get_regular_price() === '' || (float) $new !== (float) $old) {
                    $from      = $product->get_regular_price() === '' ? 'not set' : $old;
                    $changes[] = $fbase + ['type' => 'price', 'detail' => "Regular price {$from} to {$new}", 'price' => $new];
                }
            }
        }

        // Guard against a bad API response emptying the shop: if most in-stock listings are
        // missing at once, stop rather than double-check (and mark sold) each one.
        $missing_total = array_sum(array_map('count', $missing));
        $max_ratio     = (float) apply_filters('d2w_sync_max_missing_ratio', 0.5);
        if ($missing_total >= 10 && $in_stock_listings > 0 && $missing_total / $in_stock_listings > $max_ratio) {
            $report['status']     = 'error';
            $report['messages'][] = sprintf('%d of %d in-stock listings are missing from Discogs. Aborted without changes; check the Discogs account and run again.', $missing_total, $in_stock_listings);
            $report['counts']['errors']++;
            D2W_Logger::error(end($report['messages']));
            return;
        }

        // Double-check each missing listing before counting it as sold.
        $sold_now = [];
        foreach ($missing as $holder_id => $gone) {
            foreach ($gone as $listing_id) {
                $status = D2W_Discogs_API::fetch_listing_status($listing_id);
                if ($status === null) {
                    $report['counts']['skipped']++; // Unknown: leave the copy in stock.
                    continue;
                }
                if ($status === 'For Sale') {
                    // Listed but missed by the paged fetch (e.g. inventory changed mid-run).
                    continue;
                }
                $sold_now[$holder_id][] = "{$listing_id} ({$status})";
                $plans[$holder_id]['gone'][] = $listing_id;
            }
        }

        // Stock moves by what changed on Discogs: one less per listing sold there, one more
        // per listing For Sale again. Stock kept beyond the listings is left alone.
        foreach ($plans as $holder_id => $plan) {
            $current  = $plan['current'];
            $returned = $restock ? $plan['returned'] : [];
            $target   = max(0, $current - count($plan['gone']) + count($returned));
            // Out of stock while a listing is still For Sale (not sold on the website): back in.
            if ($restock && $target === 0 && $plan['for_sale'] > 0) {
                $target = $plan['for_sale'];
            }
            $memo = ['gone_add' => $plan['gone'], 'gone_remove' => $plan['returned']];
            if ($target < $current) {
                $detail = 'Discogs listing ' . implode(', ', $sold_now[$holder_id] ?? []) . " no longer For Sale; stock {$current} to {$target}";
                $changes[] = $plan['base'] + $memo + ['type' => 'sold', 'detail' => $target ? $detail : $detail . ', marked out of stock', 'qty' => $target];
            } elseif ($target > $current) {
                $changes[] = $plan['base'] + $memo + ['type' => 'restock', 'detail' => "Listing(s) For Sale again; stock {$current} to {$target}", 'qty' => $target];
            } elseif ($plan['gone'] || $plan['returned']) {
                // Nothing to change in stock (e.g. restocking is off), but remember the listings.
                $changes[] = $plan['base'] + $memo + ['type' => 'gone_memo', 'detail' => 'Discogs listing status recorded'];
            }
        }

        // New listings not yet on the website.
        $new_ids = array_diff_key($listings, $imported);
        $report['counts']['new'] = count($new_ids);

        if (self::enabled('d2w_sync_auto_import') && $new_ids) {
            foreach (array_slice($new_ids, 0, self::AUTO_IMPORT_MAX, true) as $listing_id => $listing) {
                $changes[] = [
                    'product_id' => 0,
                    'listing_id' => (int) $listing_id,
                    'name'       => trim(($listing['release']['artist'] ?? '') . ' - ' . ($listing['release']['title'] ?? ''), ' -'),
                    'type'       => 'import',
                    'detail'     => 'New listing; import as draft',
                ];
            }
            if (count($new_ids) > self::AUTO_IMPORT_MAX) {
                $report['messages'][] = sprintf('%d new listings; importing %d per run.', count($new_ids), self::AUTO_IMPORT_MAX);
            }
        }

        $report['changes'] = $changes;

        foreach ($changes as $change) {
            $key = ['sold' => 'sold', 'restock' => 'restocked', 'price' => 'prices', 'import' => 'imported'][$change['type']] ?? null;
            if ($key) {
                $report['counts'][$key]++;
            }
        }

        if ($dry_run) {
            return;
        }

        self::apply($changes, $listings, $report);

        $now = time();
        foreach (array_unique($imported) as $product_id) {
            update_post_meta($product_id, '_discogs_last_synced', $now);
        }
        update_option(self::NEW_LISTINGS, ['count' => max(0, $report['counts']['new'] - $report['counts']['imported']), 'time' => $now], false);
    }

    private static function apply(array $changes, array $listings, array &$report): void {
        $to_import = [];
        $changed   = [];

        foreach ($changes as $change) {
            if ($change['type'] === 'import') {
                $to_import[] = $change['listing_id'];
                continue;
            }

            $product = wc_get_product($change['product_id']);
            if (!$product) {
                continue;
            }

            switch ($change['type']) {
                case 'sold':
                case 'restock':
                    $qty = (int) ($change['qty'] ?? ($change['type'] === 'sold' ? 0 : 1));
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($qty);
                    $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
                    D2W_Copies::note_gone($product->get_id(), $change['gone_add'] ?? [], $change['gone_remove'] ?? []);
                    break;
                case 'gone_memo':
                    D2W_Copies::note_gone($product->get_id(), $change['gone_add'] ?? [], $change['gone_remove'] ?? []);
                    break;
                case 'price':
                    $product->set_regular_price($change['price']);
                    break;
                case 'clear_flag':
                    D2W_Copies::clear_sold($product->get_id(), (int) $change['listing_id']);
                    break;
                case 'details':
                    // A variation's attributes go on its parent product, which the shop filters.
                    $attr_product = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
                    if ($attr_product && D2W_Release_Data::apply_listing_attributes($attr_product, $listings[$change['listing_id']] ?? []) && $attr_product !== $product) {
                        $attr_product->save();
                    }
                    break;
            }

            try {
                $product->save();
                $changed[] = $product->get_id();
            } catch (Exception $e) {
                $report['counts']['errors']++;
                D2W_Logger::error("Saving product {$change['product_id']} failed: " . $e->getMessage());
            }
        }
        D2W_Cache::purge_products($changed);

        if ($to_import) {
            $products = array_map(
                [D2W_Product_Mapper::class, 'map_listing'],
                array_intersect_key($listings, array_flip($to_import))
            );
            foreach (D2W_Product_Importer::import($to_import, 'draft', array_values($products)) as $message) {
                $report['messages'][] = $message;
            }
        }
    }

    // Product name, with the variant for a variation ("Pendulum - Inertia (Signed)").
    private static function holder_name(WC_Product $product): string {
        if (!$product->is_type('variation')) {
            return $product->get_name();
        }
        $parent  = wc_get_product($product->get_parent_id());
        $variant = D2W_Copies::variant_of($product);
        return ($parent ? $parent->get_name() : $product->get_name()) . ($variant !== '' ? " ({$variant})" : '');
    }

    private static function record_run(array $report): void {
        $log = get_option(self::LOG_OPTION, []);
        $log = is_array($log) ? $log : [];

        array_unshift($log, [
            'time'     => $report['time'],
            'trigger'  => $report['trigger'],
            'status'   => $report['status'],
            'duration' => $report['duration'],
            'counts'   => $report['counts'],
        ]);

        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_SIZE), false);
    }

    public static function get_log(): array {
        $log = get_option(self::LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }
}
