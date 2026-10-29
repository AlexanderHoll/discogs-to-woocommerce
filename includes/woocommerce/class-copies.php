<?php

defined('ABSPATH') || exit;

/**
 * Several Discogs listings for one release.
 *
 * A "holder" is what holds copies for sale: a simple product, or one variation of a variable
 * product. Each listing is stored as its own `_discogs_listing_id` row on the holder, and the
 * holder's stock is the number of its listings still For Sale and not sold on the website.
 *
 * On import, a listing for a release that is already in the shop joins it instead of creating
 * a second product: an identical copy (same grades, price and notes) adds stock to the
 * matching holder; a different copy (signed, other grade, price or notes) becomes a variant,
 * turning a simple product into a variable one with a "Variant" attribute, the convention the
 * shop's hand-made variable products already use.
 */
class D2W_Copies {

    const LISTING_META = '_discogs_listing_id';
    // [listing ID => time] for listings sold on the website (see D2W_Order_Sync).
    const SOLD_META    = '_d2w_sold_listings';
    // [listing ID => time] for listings sync counted as sold on Discogs.
    const GONE_META    = '_d2w_gone_listings';
    // What the holder's copies are: grades, price and seller notes (see copy_from_listing()).
    const COPY_META    = '_d2w_copy';
    // Local attribute used by the shop's variable products.
    const VARIANT_NAME = 'Variant';
    const VARIANT_KEY  = 'variant';

    // -------------------------------------------------------------------------
    // Listings on a holder
    // -------------------------------------------------------------------------

    /** @return int[] */
    public static function listing_ids($holder): array {
        $id = $holder instanceof WC_Product ? $holder->get_id() : (int) $holder;
        return array_values(array_unique(array_filter(array_map('absint', (array) get_post_meta($id, self::LISTING_META, false)))));
    }

    public static function add_listing(int $holder_id, int $listing_id): void {
        // The old single sold flag covers every listing on the holder; pin it to the current
        // ones first so the new listing does not inherit it.
        if (get_post_meta($holder_id, D2W_Discogs_Sync::SOLD_ON_SITE_META, true)) {
            update_post_meta($holder_id, self::SOLD_META, self::sold_listings($holder_id));
            delete_post_meta($holder_id, D2W_Discogs_Sync::SOLD_ON_SITE_META);
        }
        if ($listing_id && !in_array($listing_id, self::listing_ids($holder_id), true)) {
            add_post_meta($holder_id, self::LISTING_META, (string) $listing_id);
        }
    }

    /**
     * Listings sold on the website. The old single flag (one listing per product) counts for
     * every listing on the holder.
     *
     * @return array<int, int> listing ID => time
     */
    public static function sold_listings(int $holder_id): array {
        $sold = get_post_meta($holder_id, self::SOLD_META, true);
        $sold = is_array($sold) ? array_map('intval', $sold) : [];
        $legacy = (int) get_post_meta($holder_id, D2W_Discogs_Sync::SOLD_ON_SITE_META, true);
        if ($legacy) {
            foreach (self::listing_ids($holder_id) as $listing_id) {
                $sold[$listing_id] ??= $legacy;
            }
        }
        return $sold;
    }

    public static function mark_sold(int $holder_id, array $listing_ids): void {
        $sold = self::sold_listings($holder_id);
        foreach ($listing_ids as $listing_id) {
            $sold[(int) $listing_id] ??= time();
        }
        update_post_meta($holder_id, self::SOLD_META, $sold);
        delete_post_meta($holder_id, D2W_Discogs_Sync::SOLD_ON_SITE_META);
    }

    public static function clear_sold(int $holder_id, int $listing_id): void {
        $sold = self::sold_listings($holder_id);
        unset($sold[$listing_id]);
        $sold ? update_post_meta($holder_id, self::SOLD_META, $sold) : delete_post_meta($holder_id, self::SOLD_META);
        delete_post_meta($holder_id, D2W_Discogs_Sync::SOLD_ON_SITE_META);
    }

    /**
     * Listings sync has counted as sold on Discogs, so a listing that stays gone is only taken
     * off stock once, and one that comes back For Sale adds it again.
     *
     * @return array<int, int> listing ID => time
     */
    public static function gone_listings(int $holder_id): array {
        $gone = get_post_meta($holder_id, self::GONE_META, true);
        return is_array($gone) ? array_map('intval', $gone) : [];
    }

    public static function note_gone(int $holder_id, array $add, array $remove): void {
        if (!$add && !$remove) {
            return;
        }
        $gone = self::gone_listings($holder_id);
        foreach ($add as $listing_id) {
            $gone[(int) $listing_id] = time();
        }
        foreach ($remove as $listing_id) {
            unset($gone[(int) $listing_id]);
        }
        $gone ? update_post_meta($holder_id, self::GONE_META, $gone) : delete_post_meta($holder_id, self::GONE_META);
    }

    /**
     * Listings to hand over for a website sale of $quantity copies: not yet sold on the site.
     *
     * @return int[]
     */
    public static function take_for_sale(int $holder_id, int $quantity): array {
        $sold = self::sold_listings($holder_id);
        $free = array_values(array_filter(self::listing_ids($holder_id), static fn($id) => !isset($sold[$id])));
        return array_slice($free, 0, max(0, $quantity));
    }

    // Product that shows the holder: the variable parent for a variation.
    public static function product_id(int $holder_id): int {
        return get_post_type($holder_id) === 'product_variation' ? (int) wp_get_post_parent_id($holder_id) : $holder_id;
    }

    // -------------------------------------------------------------------------
    // What a copy is
    // -------------------------------------------------------------------------

    /**
     * Grades, price and notes of a listing (raw API listing or a D2W_Product_Mapper row).
     *
     * @return array{media: string, sleeve: string, price: string, notes: string}
     */
    public static function copy_from_listing(array $listing): array {
        $price = $listing['value'] ?? ($listing['price']['value'] ?? '');
        return [
            'media'  => D2W_Release_Data::grade_code((string) ($listing['media_condition'] ?? ($listing['condition'] ?? ''))),
            'sleeve' => D2W_Release_Data::grade_code((string) ($listing['sleeve_condition'] ?? '')),
            'price'  => $price === '' ? '' : wc_format_decimal($price, 2),
            'notes'  => trim(wp_strip_all_tags((string) ($listing['comments'] ?? ''))),
        ];
    }

    // The copy a holder stands for: saved on import, or worked out for older products.
    public static function copy_of(WC_Product $holder): array {
        $saved = $holder->get_meta(self::COPY_META);
        if (is_array($saved) && isset($saved['media'])) {
            return $saved;
        }
        $notes = $holder->is_type('variation') ? $holder->get_description('edit') : $holder->get_short_description('edit');
        return [
            'media'  => D2W_Release_Data::grade_code((string) $holder->get_meta('_media_condition')),
            'sleeve' => D2W_Release_Data::grade_code((string) $holder->get_meta('_sleeve_condition')),
            'price'  => $holder->get_regular_price('edit') === '' ? '' : wc_format_decimal($holder->get_regular_price('edit'), 2),
            'notes'  => trim(wp_strip_all_tags((string) $notes)),
        ];
    }

    public static function same_copy(array $a, array $b): bool {
        $norm = static fn($s) => strtolower(preg_replace('/\s+/', ' ', trim((string) $s)));
        return $a['media'] === $b['media'] && $a['sleeve'] === $b['sleeve']
            && (float) $a['price'] === (float) $b['price'] && $norm($a['notes']) === $norm($b['notes']);
    }

    public static function is_signed(array $copy): bool {
        return (bool) preg_match('/\bsigned\b/i', $copy['notes']) && !preg_match('/\bunsigned\b/i', $copy['notes']);
    }

    /**
     * Variant name for a copy, from what sets it apart: "Signed", its grades when the other
     * copies' grades differ, or the first sentence of its notes; "Standard" otherwise.
     * Names can be changed on the product screen afterwards.
     */
    public static function label(array $copy, array $others): string {
        $parts = [];
        if (self::is_signed($copy)) {
            $parts[] = 'Signed';
        }
        $grades = trim($copy['media'] . ' / ' . $copy['sleeve'], ' /');
        foreach ($others as $other) {
            if ($grades !== '' && ($other['media'] !== $copy['media'] || $other['sleeve'] !== $copy['sleeve'])) {
                $parts[] = $grades;
                break;
            }
        }
        if (!$parts && $copy['notes'] !== '') {
            $first = trim((string) preg_split('/(?<=[.!?])\s/', $copy['notes'])[0], " .\t\n");
            $parts[] = mb_strlen($first) <= 40 ? $first : rtrim(mb_substr($first, 0, 38)) . '…';
        }
        return $parts ? implode(', ', $parts) : 'Standard';
    }

    // -------------------------------------------------------------------------
    // Finding the product for a release
    // -------------------------------------------------------------------------

    /**
     * The linked product already holding a release (oldest first), or null.
     */
    public static function product_for_release(int $release_id): ?WC_Product {
        global $wpdb;
        if (!$release_id) {
            return null;
        }
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_discogs_release_id' AND r.meta_value = %s
             WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} l WHERE l.post_id = p.ID AND l.meta_key = %s AND l.meta_value <> '')
                 OR EXISTS (SELECT 1 FROM {$wpdb->posts} v INNER JOIN {$wpdb->postmeta} l ON l.post_id = v.ID AND l.meta_key = %s AND l.meta_value <> ''
                            WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status <> 'trash'))
             ORDER BY p.ID LIMIT 1",
            (string) $release_id, self::LISTING_META, self::LISTING_META
        ));
        return $id ? wc_get_product((int) $id) : null;
    }

    /**
     * Releases linked on more than one product, for merging.
     *
     * @return array<int, int[]> release ID => product IDs, oldest first
     */
    public static function duplicate_releases(): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.meta_value AS release_id, GROUP_CONCAT(p.ID ORDER BY p.ID) AS ids
             FROM {$wpdb->postmeta} r
             INNER JOIN {$wpdb->posts} p ON p.ID = r.post_id AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')
             WHERE r.meta_key = '_discogs_release_id' AND r.meta_value <> ''
               AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} l WHERE l.post_id = p.ID AND l.meta_key = %s AND l.meta_value <> '')
                 OR EXISTS (SELECT 1 FROM {$wpdb->posts} v INNER JOIN {$wpdb->postmeta} l ON l.post_id = v.ID AND l.meta_key = %s AND l.meta_value <> ''
                            WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status <> 'trash'))
             GROUP BY r.meta_value HAVING COUNT(*) > 1",
            self::LISTING_META, self::LISTING_META
        ));
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->release_id] = array_map('intval', explode(',', $row->ids));
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Adding a copy
    // -------------------------------------------------------------------------

    /**
     * Adds a listing to a product holding the same release. Returns the holder ID and what
     * happened, e.g. "added as a second copy" or "added as variant 'Signed'".
     *
     * @param array $copy  copy_from_listing() of the listing.
     * @return array{holder: int, message: string}|WP_Error
     */
    public static function add_copy(WC_Product $product, int $listing_id, array $copy, int $stock = 1) {
        // An identical copy already on the product: more stock on that holder.
        foreach (self::holders($product) as $holder) {
            if (self::same_copy(self::copy_of($holder), $copy)) {
                self::add_listing($holder->get_id(), $listing_id);
                $holder = wc_get_product($holder->get_id());
                $qty = max(0, (int) $holder->get_stock_quantity()) + $stock;
                self::set_stock($holder, $qty);
                $where = $holder->is_type('variation') ? sprintf(" of variant '%s'", self::variant_of($holder)) : '';
                return ['holder' => $holder->get_id(), 'message' => sprintf('added as another copy%s (stock now %d)', $where, $qty)];
            }
        }

        // A different copy: a new variant.
        if (!$product->is_type('variable')) {
            if (!$product->is_type('simple')) {
                return new WP_Error('d2w_copy_type', sprintf('product %d is a %s product, so the copy was not added', $product->get_id(), $product->get_type()));
            }
            $product = self::make_variable($product, $copy);
            if (is_wp_error($product)) {
                return $product;
            }
        }

        $others = array_map([__CLASS__, 'copy_of'], self::holders($product));
        $label  = self::unique_label($product, self::label($copy, $others), $copy);
        $variation_id = self::create_variation($product, $label, $copy, [$listing_id], $stock);
        if (is_wp_error($variation_id)) {
            return $variation_id;
        }
        return ['holder' => $variation_id, 'message' => sprintf("added as variant '%s'", $label)];
    }

    /**
     * Moves every copy of $from onto $into (same release) and trashes $from. Used to merge
     * products imported before D2W grouped copies.
     *
     * @return string|WP_Error What happened.
     */
    public static function merge(WC_Product $into, WC_Product $from) {
        if ($into->get_id() === $from->get_id()) {
            return new WP_Error('d2w_merge_same', 'Cannot merge a product into itself.');
        }
        $notes = [];
        foreach (self::holders($from) as $holder) {
            $listings = self::listing_ids($holder);
            if (!$listings) {
                continue;
            }
            $copy  = self::copy_of($holder);
            $stock = max(0, (int) $holder->get_stock_quantity());
            $sold  = self::sold_listings($holder->get_id());
            $first = array_shift($listings);
            // Detach first, so the listings are not linked twice while moving.
            delete_post_meta($holder->get_id(), self::LISTING_META);
            $into = wc_get_product($into->get_id());
            $result = self::add_copy($into, $first, $copy, $stock);
            if (is_wp_error($result)) {
                foreach (array_merge([$first], $listings) as $listing_id) {
                    self::add_listing($holder->get_id(), $listing_id); // Put them back.
                }
                return $result;
            }
            foreach ($listings as $listing_id) {
                self::add_listing($result['holder'], $listing_id);
            }
            if ($sold) {
                self::mark_sold($result['holder'], array_keys(array_intersect_key($sold, array_flip(array_merge([$first], $listings)))));
            }
            $notes[] = $result['message'];
        }
        $from->update_meta_data('_d2w_merged_into', $into->get_id());
        $from->save();
        $old_path = (string) wp_parse_url(get_permalink($from->get_id()), PHP_URL_PATH);
        wp_trash_post($from->get_id());
        if (self::redirect($old_path, get_permalink($into->get_id()))) {
            $notes[] = 'old address redirected';
        }
        D2W_Logger::info(sprintf('Merged product %d into %d: %s', $from->get_id(), $into->get_id(), implode('; ', $notes)));
        return sprintf('"%s" (%d) merged into product %d: %s.', $from->get_name(), $from->get_id(), $into->get_id(), implode('; ', $notes) ?: 'nothing to move');
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    // 301 from a merged product's old address with Rank Math's redirections, when active.
    private static function redirect(string $from_path, string $to_url): bool {
        $from_path = trim($from_path, '/');
        if ($from_path === '' || !$to_url || !class_exists('\RankMath\Redirections\Redirection')
            || !in_array('redirections', (array) get_option('rank_math_modules'), true)) {
            return false;
        }
        $redirect = \RankMath\Redirections\Redirection::from([
            'sources'     => [['pattern' => $from_path, 'comparison' => 'exact']],
            'url_to'      => $to_url,
            'header_code' => '301',
            'status'      => 'active',
        ]);
        return (bool) $redirect->save();
    }

    /**
     * Holders of a product: its variations, or the product itself.
     *
     * @return WC_Product[]
     */
    public static function holders(WC_Product $product): array {
        if (!$product->is_type('variable')) {
            return [$product];
        }
        return array_values(array_filter(array_map('wc_get_product', $product->get_children())));
    }

    public static function variant_of(WC_Product $variation): string {
        return (string) ($variation->get_attributes()[self::VARIANT_KEY] ?? '');
    }

    private static function set_stock(WC_Product $holder, int $qty): void {
        $holder->set_manage_stock(true);
        $holder->set_stock_quantity($qty);
        $holder->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
        $holder->save();
    }

    private static function unique_label(WC_Product $product, string $label, array $copy): string {
        $taken = array_map('strtolower', array_map([__CLASS__, 'variant_of'], self::holders($product)));
        if (!in_array(strtolower($label), $taken, true)) {
            return $label;
        }
        $with_price = $copy['price'] !== '' ? sprintf('%s (%s)', $label, html_entity_decode(wp_strip_all_tags(wc_price($copy['price'])))) : $label;
        $candidate  = $with_price;
        for ($n = 2; in_array(strtolower($candidate), $taken, true); $n++) {
            $candidate = "{$with_price} {$n}";
        }
        return $candidate;
    }

    /**
     * Turns a simple product into a variable one; its own copies become the first variant.
     *
     * @param array $incoming The copy about to be added, to name the existing one against.
     * @return WC_Product_Variable|WP_Error
     */
    private static function make_variable(WC_Product $simple, array $incoming) {
        $id       = $simple->get_id();
        $copy     = self::copy_of($simple);
        $listings = self::listing_ids($simple);
        $sold     = self::sold_listings($id);
        $stock    = max(0, (int) $simple->get_stock_quantity());
        $label    = self::label($copy, [$incoming]);
        $excerpt  = trim(wp_strip_all_tags($simple->get_short_description('edit')));

        wp_set_object_terms($id, 'variable', 'product_type');
        wc_delete_product_transients($id);
        clean_post_cache($id);
        $product = new WC_Product_Variable($id);

        // The copy's listings, notes and stock move to its variant.
        delete_post_meta($id, self::LISTING_META);
        delete_post_meta($id, self::SOLD_META);
        delete_post_meta($id, D2W_Discogs_Sync::SOLD_ON_SITE_META);
        $product->delete_meta_data(self::COPY_META);
        if ($excerpt !== '' && $excerpt === $copy['notes']) {
            $product->set_short_description('');
        }
        $product->set_manage_stock(false);
        $product->set_stock_quantity(null);
        $product->set_regular_price('');
        $product->set_sale_price('');
        $product->save();

        $variation_id = self::create_variation($product, $label, $copy, $listings, $stock);
        if (is_wp_error($variation_id)) {
            foreach ($listings as $listing_id) {
                self::add_listing($id, $listing_id); // Keep the listings linked to the product.
            }
            D2W_Logger::error("Product {$id} could not get its first variant: " . $variation_id->get_error_message());
            return $variation_id;
        }
        if ($sold) {
            update_post_meta($variation_id, self::SOLD_META, $sold);
        }
        D2W_Logger::info(sprintf("Product %d became variable: its copy is now variant '%s'.", $id, $label));
        return wc_get_product($id);
    }

    /**
     * @return int|WP_Error Variation ID.
     */
    private static function create_variation(WC_Product $product, string $label, array $copy, array $listing_ids, int $stock) {
        // Add the value to the parent's Variant attribute (created like the shop's own).
        $attrs = $product->get_attributes();
        $attr  = isset($attrs[self::VARIANT_KEY]) ? clone $attrs[self::VARIANT_KEY] : new WC_Product_Attribute();
        if (!isset($attrs[self::VARIANT_KEY])) {
            $attr->set_id(0);
            $attr->set_name(self::VARIANT_NAME);
            $attr->set_visible(true);
            $attr->set_variation(true);
            $attr->set_position(0);
        }
        $attr->set_options(array_values(array_unique(array_merge($attr->get_options(), [$label]))));
        $attrs[self::VARIANT_KEY] = $attr;
        $product->set_attributes($attrs);
        $product->save();

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product->get_id());
        $variation->set_attributes([self::VARIANT_KEY => $label]);
        $variation->set_status('publish');
        if ($copy['price'] !== '') {
            $variation->set_regular_price($copy['price']);
        }
        $variation->set_description($copy['notes']);
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($stock);
        $variation->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        $variation->update_meta_data(self::COPY_META, $copy);
        foreach (['_media_condition' => $copy['media'], '_sleeve_condition' => $copy['sleeve']] as $key => $code) {
            if ($code !== '' && isset(D2W_Release_Data::GRADES[$code])) {
                $variation->update_meta_data($key, $code);
            }
        }
        try {
            $variation_id = $variation->save();
        } catch (Exception $e) {
            return new WP_Error('d2w_variation_failed', $e->getMessage());
        }
        foreach ($listing_ids as $listing_id) {
            self::add_listing($variation_id, (int) $listing_id);
        }
        WC_Product_Variable::sync($product->get_id());
        wc_delete_product_transients($product->get_id());
        return $variation_id;
    }
}
