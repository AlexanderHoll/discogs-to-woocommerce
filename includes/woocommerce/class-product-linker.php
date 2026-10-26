<?php

defined('ABSPATH') || exit;

/**
 * Links products that were not imported by D2W to their Discogs listings, so sync and
 * release data cover the whole catalogue.
 *
 * - Auto: products carrying a listing ID from the old CedCommerce Discogs plugin.
 * - Proposed: name matches against the For Sale inventory, confirmed by a person.
 */
class D2W_Product_Linker {

    const CED_LISTING_META = 'ced_discogs_discogsitemId';
    const CED_RELEASE_META = 'ced_discogs_release_id';
    const MIN_CONFIDENCE   = 70;

    // Raw For Sale inventory keyed by listing ID, cached for 10 minutes. Null on failure.
    public static function inventory(bool $refresh = false): ?array {
        $cached = $refresh ? false : get_transient('d2w_inventory_raw');
        if (is_array($cached)) {
            return $cached;
        }
        $inventory = D2W_Discogs_API::fetch_inventory_for_sale();
        if ($inventory !== null) {
            set_transient('d2w_inventory_raw', $inventory, 10 * MINUTE_IN_SECONDS);
        }
        return $inventory;
    }

    // IDs of products with no Discogs listing linked (any status except trash).
    public static function unlinked_products(): array {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = '_discogs_listing_id'
             WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (l.meta_value IS NULL OR l.meta_value = '')
             ORDER BY p.ID"
        );
    }

    /**
     * Links one product to one listing. Refuses if the listing is already linked to another
     * product. $listing (raw API listing) is optional; it supplies the release ID and the
     * condition/format attributes.
     *
     * @return true|WP_Error
     */
    public static function link(int $product_id, int $listing_id, ?array $listing = null, int $release_id = 0) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('d2w_no_product', "Product {$product_id} not found.");
        }
        $linked = D2W_Product_Importer::get_imported_listing_ids();
        if (isset($linked[$listing_id]) && (int) $linked[$listing_id] !== $product_id) {
            return new WP_Error('d2w_listing_taken', "Listing {$listing_id} is already linked to product {$linked[$listing_id]}.");
        }
        if ($product->get_meta('_discogs_listing_id')) {
            return new WP_Error('d2w_already_linked', "Product {$product_id} is already linked.");
        }

        $release_id = $release_id ?: (int) ($listing['release']['id'] ?? 0);

        $product->update_meta_data('_discogs_listing_id', (string) $listing_id);
        if ($release_id) {
            $product->update_meta_data('_discogs_release_id', (string) $release_id);
        }
        $product->update_meta_data('_discogs_linked', time());
        if ($listing) {
            D2W_Release_Data::apply_listing_attributes($product, $listing);
        }

        try {
            $product->save();
        } catch (Exception $e) {
            return new WP_Error('d2w_save_failed', $e->getMessage());
        }

        D2W_Logger::info("Linked product {$product_id} to listing {$listing_id}.");
        return true;
    }

    /**
     * Links products that carry a CedCommerce listing ID. Listings not in the For Sale
     * inventory are fetched one by one (sold listings still link, so sync knows them).
     *
     * @return array{linked: array, missing: array, skipped: array, errors: array}
     */
    public static function auto_link(bool $dry_run = false, ?array $inventory = null): array {
        global $wpdb;

        $result    = ['linked' => [], 'missing' => [], 'skipped' => [], 'errors' => []];
        $inventory ??= self::inventory() ?? [];
        $linked    = D2W_Product_Importer::get_imported_listing_ids();
        $unlinked  = array_flip(self::unlinked_products());

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
            self::CED_LISTING_META
        ));

        foreach ($rows as $row) {
            $product_id = (int) $row->post_id;
            $listing_id = absint($row->meta_value);
            if (!isset($unlinked[$product_id]) || !$listing_id) {
                continue;
            }
            if (isset($linked[$listing_id])) {
                $result['skipped'][] = ['product_id' => $product_id, 'listing_id' => $listing_id, 'reason' => "listing already linked to product {$linked[$listing_id]}"];
                continue;
            }

            $listing = $inventory[$listing_id] ?? D2W_Discogs_API::fetch_listing($listing_id);
            if ($listing === null) {
                $result['missing'][] = ['product_id' => $product_id, 'listing_id' => $listing_id, 'name' => get_the_title($product_id)];
                continue;
            }

            $release_id = (int) ($listing['release']['id'] ?? 0) ?: absint(get_post_meta($product_id, self::CED_RELEASE_META, true));
            $entry      = ['product_id' => $product_id, 'listing_id' => $listing_id, 'name' => get_the_title($product_id), 'status' => $listing['status'] ?? ''];

            if ($dry_run) {
                $result['linked'][] = $entry;
                $linked[$listing_id] = $product_id;
                continue;
            }

            $ok = self::link($product_id, $listing_id, $listing, $release_id);
            if (is_wp_error($ok)) {
                $result['errors'][] = $entry + ['reason' => $ok->get_error_message()];
            } else {
                $result['linked'][] = $entry;
                $linked[$listing_id] = $product_id;
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Proposed matches
    // -------------------------------------------------------------------------

    // Lower case, no "(2)" suffixes, no punctuation, no leading "the", single spaces.
    public static function normalise(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = D2W_Product_Mapper::clean_artist_name($text);
        $text = function_exists('remove_accents') ? remove_accents($text) : $text;
        $text = strtolower($text);
        $text = str_replace('&', ' and ', $text);
        $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);
        $text = preg_replace('/\bthe\b/', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    // [artist, title] from a product name like "Artist - Title".
    public static function split_name(string $name): array {
        $name  = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
        $parts = preg_split('/\s+[-–]\s+/u', $name, 2);
        return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : ['', trim($name)];
    }

    /**
     * 0-100 similarity of a product name to a listing (artist and title compared
     * separately). 100 only for an exact normalised match.
     */
    public static function score(string $product_name, array $listing): int {
        [$artist, $title] = self::split_name($product_name);
        // Shop-added qualifiers such as "(Signed)" or "(Test Pressing)" are not on Discogs.
        $stripped = trim(preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*$/', '', $title));
        $qualified = $stripped !== '' && $stripped !== $title;

        $p_artist = self::normalise($artist);
        $p_title  = self::normalise($qualified ? $stripped : $title);
        $l_artist = self::normalise((string) ($listing['release']['artist'] ?? ($listing['artist'] ?? '')));
        $l_title  = self::normalise((string) ($listing['release']['title'] ?? ($listing['title'] ?? '')));

        if ($p_title === '' || $l_title === '') {
            return 0;
        }

        // Different volume numbers or letters ("Vol 2" / "Vol 3", "Selects X" / "Selects Y")
        // are different releases however similar the rest is.
        $p_tokens = explode(' ', $p_title);
        $l_tokens = explode(' ', $l_title);
        foreach (array_merge(array_diff($p_tokens, $l_tokens), array_diff($l_tokens, $p_tokens)) as $token) {
            if (preg_match('/\d/', $token) || strlen($token) === 1) {
                return 0;
            }
        }

        $cap = $qualified ? 95 : 99;
        if ($p_title === $l_title && $p_artist === $l_artist) {
            return $qualified ? $cap : 100;
        }

        similar_text($p_title, $l_title, $title_pct);
        if ($p_artist === '') {
            // No artist in the product name: compare the whole name against "artist title".
            similar_text($p_title, trim("{$l_artist} {$l_title}"), $whole_pct);
            return (int) round(min(90, max($title_pct * 0.8, $whole_pct)));
        }
        similar_text($p_artist, $l_artist, $artist_pct);

        return (int) round(min($cap, $title_pct * 0.6 + $artist_pct * 0.4));
    }

    /**
     * Best listing per unlinked product, at or above MIN_CONFIDENCE, against For Sale
     * listings that are not linked yet. Each listing is proposed to one product only.
     *
     * @return array<int, array{product_id: int, name: string, stock: string, listing_id: int, listing: array, score: int}>
     */
    public static function proposals(?array $inventory = null): array {
        $inventory ??= self::inventory() ?? [];
        $linked    = D2W_Product_Importer::get_imported_listing_ids();
        $available = array_diff_key($inventory, $linked);
        if (!$available) {
            return [];
        }

        $candidates = [];
        foreach (self::unlinked_products() as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $best = null;
            foreach ($available as $listing_id => $listing) {
                $score = self::score($product->get_name(), $listing);
                if ($score >= self::MIN_CONFIDENCE && (!$best || $score > $best['score'])) {
                    $best = ['listing_id' => (int) $listing_id, 'score' => $score];
                }
            }
            if ($best) {
                $candidates[] = $best + [
                    'product_id' => (int) $product_id,
                    'name'       => $product->get_name(),
                    'stock'      => $product->get_stock_status(),
                    'listing'    => $available[$best['listing_id']],
                ];
            }
        }

        // One product per listing: keep the highest score.
        usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);
        $taken = [];
        $out   = [];
        foreach ($candidates as $c) {
            if (isset($taken[$c['listing_id']])) {
                continue;
            }
            $taken[$c['listing_id']] = true;
            $out[] = $c;
        }
        return $out;
    }
}
