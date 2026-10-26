<?php

defined('ABSPATH') || exit;

class D2W_Product_Mapper {

    // How long the full For Sale inventory is cached for the "Unimported only" view.
    const INVENTORY_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    public static function map_listings($page = 1, $args = []) {
        $data = D2W_Discogs_API::fetch($page, $args);

        if (!isset($data['listings']) || !is_array($data['listings'])) {
            return [];
        }

        return array_map([__CLASS__, 'map_listing'], $data['listings']);
    }

    /**
     * Every For Sale listing, mapped, keyed by listing ID. Null if any page failed.
     * Cached briefly so paging through the "Unimported only" view does not refetch.
     */
    public static function map_inventory(array $args = []): ?array {
        $sort      = $args['sort'] ?? 'listed';
        $order     = $args['sort_order'] ?? 'desc';
        $cache_key = 'd2w_inventory_' . md5($sort . $order);

        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $listings = D2W_Discogs_API::fetch_inventory_for_sale($sort, $order);
        if ($listings === null) {
            return null;
        }

        $mapped = array_map([__CLASS__, 'map_listing'], $listings);
        set_transient($cache_key, $mapped, self::INVENTORY_CACHE_TTL);

        return $mapped;
    }

    public static function map_listing(array $listing): array {
        $images = $listing['release']['images'] ?? [];

        return [
            'id'               => $listing['id'] ?? '',
            'release_id'       => $listing['release']['id'] ?? '',
            'images'           => $images,
            'image_main'       => $images[0]['uri'] ?? '',
            'image_thumb'      => $images[0]['uri150'] ?? ($listing['release']['thumbnail'] ?? ''),
            'artist'           => self::clean_artist_name($listing['release']['artist'] ?? ''),
            'title'            => $listing['release']['title'] ?? '',
            'comments'         => $listing['comments'] ?? '',
            'description'      => self::clean_artist_name($listing['release']['description'] ?? ''),
            'value'            => $listing['price']['value'] ?? 0.0,
            'currency'         => $listing['price']['currency'] ?? get_woocommerce_currency(),
            'media_condition'  => $listing['condition'] ?? '',
            'sleeve_condition' => $listing['sleeve_condition'] ?? '',
            'format'           => $listing['format'] ?? '',
            'year'             => $listing['release']['year'] ?? '',
        ];
    }

    public static function clean_artist_name($text) {
        return preg_replace('/\(\d+\)/', '', $text);
    }
}
