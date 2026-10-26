<?php

defined('ABSPATH') || exit;

/**
 * Website sales of imported products.
 *
 * Always: flags the product as sold on the website so sync does not restock it while
 * the Discogs listing is still For Sale.
 * Optionally (d2w_sync_push_to_drafts, default off): sets the Discogs listing to Draft.
 */
class D2W_Order_Sync {

    const ORDER_DONE_META = '_d2w_discogs_synced';
    const ASYNC_HOOK      = 'd2w_push_order_to_discogs';

    public static function register() {
        add_action('woocommerce_order_status_processing', [__CLASS__, 'handle_order'], 10, 2);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'handle_order'], 10, 2);
        add_action(self::ASYNC_HOOK, [__CLASS__, 'push_order']);
    }

    public static function handle_order($order_id, $order = null) {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $items = self::discogs_items($order);
        if (!$items) {
            return;
        }

        foreach ($items as [$product]) {
            if (!$product->get_meta(D2W_Discogs_Sync::SOLD_ON_SITE_META)) {
                $product->update_meta_data(D2W_Discogs_Sync::SOLD_ON_SITE_META, time());
                $product->save_meta_data();
            }
        }

        if (!D2W_Discogs_Sync::enabled('d2w_sync_push_to_drafts') || $order->get_meta(self::ORDER_DONE_META)) {
            return;
        }

        // Claim the order before queueing so processing -> completed does not queue it twice.
        $order->update_meta_data(self::ORDER_DONE_META, 'queued');
        $order->save_meta_data();

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ASYNC_HOOK, ['order_id' => $order->get_id()], 'd2w');
        } else {
            self::push_order($order->get_id());
        }
    }

    /**
     * Sets each Discogs listing in the order to Draft and adds an order note per item.
     * Never deletes listings.
     */
    public static function push_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta(self::ORDER_DONE_META) === 'done') {
            return;
        }

        foreach (self::discogs_items($order) as [$product, $listing_id]) {
            $order->add_order_note(self::draft_listing($listing_id, $product->get_name()));
        }

        $order->update_meta_data(self::ORDER_DONE_META, 'done');
        $order->save();
    }

    private static function draft_listing(int $listing_id, string $name): string {
        $prefix  = sprintf('Discogs listing %d (%s): ', $listing_id, $name);
        $listing = D2W_Discogs_API::fetch_listing($listing_id);

        if ($listing === null) {
            return $prefix . 'could not be fetched, so it was not changed. Check it on Discogs.';
        }

        $status = $listing['status'] ?? '';
        if ($status !== 'For Sale') {
            return $prefix . "already {$status}, not changed.";
        }

        $result = D2W_Discogs_API::update_listing($listing_id, self::edit_fields($listing, 'Draft'));

        if (is_wp_error($result)) {
            return $prefix . 'setting to Draft failed: ' . $result->get_error_message();
        }

        D2W_Logger::info("Listing {$listing_id} set to Draft after website sale.");
        return $prefix . 'set to Draft.';
    }

    // Current listing values plus the new status, so nothing else changes on Discogs.
    public static function edit_fields(array $listing, string $status): array {
        $fields = [
            'release_id' => $listing['release']['id'] ?? null,
            'condition'  => $listing['condition'] ?? null,
            'price'      => $listing['price']['value'] ?? null,
            'status'     => $status,
        ];

        foreach (['sleeve_condition', 'comments', 'allow_offers', 'external_id', 'location', 'weight', 'format_quantity'] as $key) {
            if (array_key_exists($key, $listing) && $listing[$key] !== null && $listing[$key] !== '') {
                $fields[$key] = $listing[$key];
            }
        }

        return $fields;
    }

    /**
     * @return array<int, array{0: WC_Product, 1: int}> [product, listing ID] per Discogs line item.
     */
    private static function discogs_items(WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
            if (!$product) {
                continue;
            }
            $listing_id = absint($product->get_meta('_discogs_listing_id'));
            if ($listing_id) {
                $items[] = [$product, $listing_id];
            }
        }
        return $items;
    }
}
