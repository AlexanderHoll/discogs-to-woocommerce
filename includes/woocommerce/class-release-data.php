<?php

defined('ABSPATH') || exit;

/**
 * Release details and filterable attributes for linked products.
 *
 * - Release meta (_d2w_*) comes from GET /releases/{id}, including the release's YouTube
 *   videos (_d2w_videos).
 * - With "Fill empty galleries" on, products without gallery images get the release images.
 * - pa_format, pa_media_condition, pa_sleeve_condition come from the listing.
 * - pa_label and pa_cat_no are filled from the release when the product has none.
 *
 * Works with any theme; nothing here renders output.
 */
class D2W_Release_Data {

    const FETCH_HOOK   = 'd2w_fetch_release';
    const FETCHED_META = '_d2w_release_fetched';

    // Created by ensure_attributes() when missing. slug => label.
    const ATTRIBUTES = [
        'format'          => 'Format',
        'media_condition' => 'Media condition',
        'sleeve_condition'=> 'Sleeve condition',
    ];

    // Grade code => [term slug, sort order]. Order follows the Goldmine scale, best first.
    const GRADES = [
        'M'          => ['m', 1],
        'NM'         => ['nm', 2],
        'VG+'        => ['vg-plus', 3],
        'VG'         => ['vg', 4],
        'G+'         => ['g-plus', 5],
        'G'          => ['g', 6],
        'F'          => ['f', 7],
        'P'          => ['p', 8],
        'Generic'    => ['generic', 9],
        'No cover'   => ['no-cover', 10],
        'Not graded' => ['not-graded', 11],
    ];

    // Format name => term slug. Multiples of LP keep their count (2xLP, 3xLP...).
    const FORMATS = [
        'LP'       => 'lp',
        '12"'      => '12-inch',
        '10"'      => '10-inch',
        '7"'       => '7-inch',
        'CD'       => 'cd',
        'Box Set'  => 'box-set',
        'Cassette' => 'cassette',
        'Other'    => 'other',
    ];

    public static function register() {
        add_action(self::FETCH_HOOK, [__CLASS__, 'handle_fetch'], 10, 1);
        add_action('admin_init', [__CLASS__, 'ensure_attributes']);
    }

    // -------------------------------------------------------------------------
    // Parsing
    // -------------------------------------------------------------------------

    /**
     * Grade code from a Discogs condition string, e.g. "Very Good Plus (VG+)" => "VG+",
     * "Near Mint (NM or M-)" => "NM", "Generic" => "Generic". Empty string if unknown.
     */
    public static function grade_code(string $condition): string {
        $condition = trim($condition);
        if ($condition === '') {
            return '';
        }
        if (preg_match('/\(([^)]+)\)/', $condition, $m)) {
            $code = strtoupper(trim(explode(' ', $m[1])[0]));
            return isset(self::GRADES[$code]) ? $code : '';
        }
        $lower = strtolower($condition);
        if (str_contains($lower, 'generic')) {
            return 'Generic';
        }
        if (str_contains($lower, 'no cover')) {
            return 'No cover';
        }
        if (str_contains($lower, 'not graded')) {
            return 'Not graded';
        }
        return '';
    }

    /**
     * Normalised format from a Discogs format string ("2xLP, Album, Ltd" => "2xLP",
     * "12\", EP" => "12\"", "2x7\", Gat" => "7\"") or from a release's formats array.
     */
    public static function format_name(string $format, array $release_formats = []): string {
        $tokens = array_map('trim', explode(',', $format));
        foreach ($release_formats as $f) {
            $tokens[] = trim((string) ($f['name'] ?? ''));
            foreach ((array) ($f['descriptions'] ?? []) as $d) {
                $tokens[] = trim((string) $d);
            }
        }
        $tokens = array_values(array_filter($tokens, 'strlen'));
        if (!$tokens) {
            return '';
        }

        if (array_intersect(['Box Set', 'Box'], $tokens)) {
            return 'Box Set';
        }
        foreach ($tokens as $t) {
            if (preg_match('/^(?:(\d+)x)?LP$/i', $t, $m)) {
                $qty = (int) ($m[1] ?? 1);
                return $qty > 1 ? "{$qty}xLP" : 'LP';
            }
        }
        foreach (['12"', '10"', '7"'] as $size) {
            foreach ($tokens as $t) {
                if (preg_match('/^(?:\d+x)?' . preg_quote($size, '/') . '$/', $t)) {
                    return $size;
                }
            }
        }
        foreach ($tokens as $t) {
            if (preg_match('/^(?:\d+x)?CD[r]?$/i', $t)) {
                return 'CD';
            }
            if (preg_match('/^(?:\d+x)?Cass(ette)?$/i', $t)) {
                return 'Cassette';
            }
        }
        return 'Other';
    }

    private static function format_slug(string $name): string {
        if (preg_match('/^(\d+)xLP$/', $name, $m)) {
            return $m[1] . 'xlp';
        }
        return self::FORMATS[$name] ?? sanitize_title($name);
    }

    // -------------------------------------------------------------------------
    // Attributes
    // -------------------------------------------------------------------------

    // Creates pa_format / pa_media_condition / pa_sleeve_condition if missing.
    public static function ensure_attributes(): void {
        if (!function_exists('wc_create_attribute')) {
            return;
        }
        foreach (self::ATTRIBUTES as $slug => $label) {
            $taxonomy = wc_attribute_taxonomy_name($slug);
            if (!wc_attribute_taxonomy_id_by_name($taxonomy)) {
                $id = wc_create_attribute([
                    'name'         => $label,
                    'slug'         => $slug,
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ]);
                if (is_wp_error($id)) {
                    D2W_Logger::error("Could not create attribute {$slug}: " . $id->get_error_message());
                    continue;
                }
                delete_transient('wc_attribute_taxonomies');
                WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
            }
            if (!taxonomy_exists($taxonomy)) {
                // Created mid-request: WooCommerce only registers attribute taxonomies on init.
                register_taxonomy($taxonomy, ['product'], ['hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false]);
            }
        }
    }

    // Term ID for a value in an attribute taxonomy, created with our slug and sort order.
    private static function term_id(string $taxonomy, string $name, string $slug, int $order = 0): int {
        $term = get_term_by('slug', $slug, $taxonomy);
        if (!$term) {
            $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
            if (is_wp_error($created)) {
                $existing = $created->get_error_data('term_exists');
                if (!$existing) {
                    D2W_Logger::error("Could not create term {$name} in {$taxonomy}: " . $created->get_error_message());
                    return 0;
                }
                $term_id = (int) $existing;
            } else {
                $term_id = (int) $created['term_id'];
            }
            if ($order) {
                update_term_meta($term_id, 'order', $order);
            }
            return $term_id;
        }
        return (int) $term->term_id;
    }

    /**
     * Sets one taxonomy attribute on the product (in memory; caller saves), keeping all
     * other attributes. New attributes are hidden from the default "Additional information"
     * tab; existing ones keep their visibility.
     */
    private static function set_attribute(WC_Product $product, string $taxonomy, array $term_ids): bool {
        $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
        $attrs    = $product->get_attributes();
        $current  = isset($attrs[$taxonomy]) ? array_map('intval', $attrs[$taxonomy]->get_options()) : null;

        if ($current !== null) {
            sort($current);
            $sorted = $term_ids;
            sort($sorted);
            if ($current === $sorted) {
                return false;
            }
        }
        if (!$term_ids && $current === null) {
            return false;
        }

        if (!$term_ids) {
            unset($attrs[$taxonomy]);
        } else {
            $attr = isset($attrs[$taxonomy]) ? clone $attrs[$taxonomy] : new WC_Product_Attribute();
            if ($current === null) {
                $attr->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
                $attr->set_name($taxonomy);
                $attr->set_visible(false);
                $attr->set_variation(false);
                $attr->set_position(count($attrs));
            }
            $attr->set_options($term_ids);
            $attrs[$taxonomy] = $attr;
        }
        $product->set_attributes($attrs);
        return true;
    }

    /**
     * Applies format and condition attributes from listing data (raw API listing or a
     * D2W_Product_Mapper row). Returns true if anything changed; caller saves.
     */
    public static function apply_listing_attributes(WC_Product $product, array $listing): bool {
        self::ensure_attributes();

        $media  = $listing['media_condition'] ?? ($listing['condition'] ?? '');
        $sleeve = $listing['sleeve_condition'] ?? '';
        $format = $listing['format'] ?? ($listing['release']['format'] ?? '');

        $changed = false;
        foreach (['pa_media_condition' => $media, 'pa_sleeve_condition' => $sleeve] as $taxonomy => $value) {
            $code = self::grade_code((string) $value);
            if ($code === '') {
                continue;
            }
            [$slug, $order] = self::GRADES[$code];
            $changed = self::set_attribute($product, $taxonomy, [self::term_id($taxonomy, $code, $slug, $order)]) || $changed;
        }

        $name = self::format_name((string) $format);
        if ($name !== '') {
            $changed = self::set_attribute($product, 'pa_format', [self::term_id('pa_format', $name, self::format_slug($name))]) || $changed;
        }

        return $changed;
    }

    // True if the listing's condition/format would change the product's attributes.
    public static function listing_attributes_differ(WC_Product $product, array $listing): bool {
        $copy = clone $product;
        return self::apply_listing_attributes($copy, $listing);
    }

    // -------------------------------------------------------------------------
    // Release details
    // -------------------------------------------------------------------------

    // Queues a release fetch (used after import so imports stay fast).
    public static function queue_fetch(int $product_id): void {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::FETCH_HOOK, ['product_id' => $product_id], 'd2w');
        } else {
            self::fetch_and_save($product_id);
        }
    }

    public static function handle_fetch($product_id): void {
        self::fetch_and_save((int) $product_id);
    }

    /**
     * Fetches the release for a linked product and saves release meta, plus pa_label and
     * pa_cat_no when the product has none. Returns true on success.
     */
    public static function fetch_and_save(int $product_id, ?array $listing = null): bool {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        $release_id = (int) $product->get_meta('_discogs_release_id');
        if (!$release_id && $listing) {
            $release_id = (int) ($listing['release']['id'] ?? ($listing['release_id'] ?? 0));
        }
        if (!$release_id) {
            return false;
        }

        $release = D2W_Discogs_API::fetch_release($release_id);
        if ($release === null) {
            return false;
        }

        self::save_release($product, $release);
        if (D2W_Discogs_Sync::enabled('d2w_fill_gallery')) {
            self::fill_gallery($product, $release);
        }
        if ($listing) {
            self::apply_listing_attributes($product, $listing);
        }
        $product->update_meta_data('_discogs_release_id', (string) $release_id);
        $product->update_meta_data(self::FETCHED_META, time());

        try {
            $product->save();
        } catch (Exception $e) {
            D2W_Logger::error("Saving release details for product {$product_id} failed: " . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * The release's YouTube videos, in Discogs order: [{id, title, duration}], duration in
     * seconds. Only embeddable YouTube links are kept; the ID is all a theme needs.
     */
    public static function videos(array $release): array {
        $videos = [];
        foreach ((array) ($release['videos'] ?? []) as $v) {
            if (empty($v['embed']) || !preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{11})#', (string) ($v['uri'] ?? ''), $m)) {
                continue;
            }
            $videos[$m[1]] = [
                'id'       => $m[1],
                'title'    => trim((string) ($v['title'] ?? '')),
                'duration' => (int) ($v['duration'] ?? 0),
            ];
        }
        return array_values($videos);
    }

    /**
     * Adds the release's images to a product that has no gallery yet: the first release
     * image becomes the main image only if the product has none, the rest form the gallery
     * (matching what an import does). Products with a gallery are left alone. Caller saves.
     */
    public static function fill_gallery(WC_Product $product, array $release): void {
        $images = array_values(array_filter((array) ($release['images'] ?? []), static fn($i) => !empty($i['uri'])));
        if (!$images || $product->get_gallery_image_ids()) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $base = sanitize_title(trim($product->get_meta('_d2w_artist') . ' ' . $product->get_meta('_d2w_title'))) ?: 'discogs-' . $product->get_id();
        $n = 0;
        if (!$product->get_image_id()) {
            $main = D2W_Product_Importer::sideload_image($images[0]['uri'], $product->get_id(), $base . '-' . (++$n));
            if ($main) {
                $product->set_image_id($main);
            }
        }
        $gallery = [];
        foreach (array_slice($images, 1) as $img) {
            $id = D2W_Product_Importer::sideload_image($img['uri'], $product->get_id(), $base . '-' . (++$n));
            if ($id) {
                $gallery[] = $id;
            }
        }
        if ($gallery) {
            $product->set_gallery_image_ids($gallery);
            D2W_Logger::info(sprintf('Gallery filled for product %d: %d image(s) from release %s.', $product->get_id(), count($gallery), $release['id'] ?? '?'));
        }
    }

    // Writes release meta and fills empty label / cat no attributes. Caller saves.
    public static function save_release(WC_Product $product, array $release): void {
        // Artists joined the way Discogs displays them, e.g. "Chase & Status", "A, B".
        $artist = '';
        foreach ((array) ($release['artists'] ?? []) as $a) {
            $name = trim(D2W_Product_Mapper::clean_artist_name((string) (($a['anv'] ?? '') ?: ($a['name'] ?? ''))));
            $join = trim((string) ($a['join'] ?? ''));
            $artist .= $name . ($join === '' ? ' ' : ($join === ',' ? ', ' : " {$join} "));
        }
        $artist = trim(preg_replace('/\s+/', ' ', $artist), ' ,&');

        $label = (array) (($release['labels'] ?? [])[0] ?? []);
        $label_name = trim(D2W_Product_Mapper::clean_artist_name((string) ($label['name'] ?? '')));
        $catno = trim((string) ($label['catno'] ?? ''));
        if (strcasecmp($catno, 'none') === 0) {
            $catno = '';
        }

        $tracks = [];
        foreach ((array) ($release['tracklist'] ?? []) as $t) {
            if (($t['type_'] ?? 'track') !== 'track') {
                continue;
            }
            $tracks[] = [
                'position' => (string) ($t['position'] ?? ''),
                'title'    => (string) ($t['title'] ?? ''),
                'duration' => (string) ($t['duration'] ?? ''),
            ];
        }

        $meta = [
            '_d2w_videos'    => wp_json_encode(self::videos($release)),
            '_d2w_artist'    => $artist,
            '_d2w_title'     => (string) ($release['title'] ?? ''),
            '_d2w_label'     => $label_name,
            '_d2w_catno'     => $catno,
            '_d2w_country'   => (string) ($release['country'] ?? ''),
            '_d2w_genres'    => implode(', ', (array) ($release['genres'] ?? [])),
            '_d2w_styles'    => implode(', ', (array) ($release['styles'] ?? [])),
            '_d2w_tracklist' => wp_json_encode($tracks),
        ];
        foreach ($meta as $key => $value) {
            $product->update_meta_data($key, $value);
        }

        // Existing global attributes power the /label/ archives; only fill gaps.
        $attrs = $product->get_attributes();
        $empty = static fn($key) => empty($attrs[$key]) || !array_filter($attrs[$key]->get_options());
        if ($label_name !== '' && taxonomy_exists('pa_label') && $empty('pa_label')) {
            self::set_attribute($product, 'pa_label', [self::term_id('pa_label', $label_name, sanitize_title($label_name))]);
        }
        if ($catno !== '' && taxonomy_exists('pa_cat_no') && $empty('pa_cat_no')) {
            self::set_attribute($product, 'pa_cat_no', [self::term_id('pa_cat_no', $catno, sanitize_title($catno))]);
        }
    }
}
