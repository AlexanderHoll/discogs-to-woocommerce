<?php

defined('ABSPATH') || exit;

/**
 * Product categories and tags from release data, as set on the Field Mapping screen
 * (by default Discogs genres to categories and styles to tags).
 *
 * Terms are matched to existing ones by name or slug ("Funk / Soul" finds "Funk & Soul"),
 * then through aliases, and created when missing. Terms added by hand (Signed, Rarities,
 * Metal...) are never removed: D2W remembers which terms it set and only replaces those.
 */
class D2W_Taxonomies {

    const ASSIGNED_META = '_d2w_terms';

    // Release fields that can feed a taxonomy, keyed as in the field mapping.
    const SOURCES = [
        'genres' => 'Genres',
        'styles' => 'Styles',
    ];

    // Discogs genres that contain ", " themselves, so a saved "_d2w_genres" list splits correctly.
    const MULTI_WORD_GENRES = ['Folk, World, & Country'];

    /**
     * Taxonomies offered on the Field Mapping screen: taxonomy => label.
     */
    public static function targets(): array {
        $targets = apply_filters('d2w_taxonomy_targets', [
            'product_cat' => 'Product categories',
            'product_tag' => 'Product tags',
        ]);
        return array_filter($targets, static fn($label, $taxonomy) => taxonomy_exists($taxonomy), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Discogs names that should land on a differently named term: taxonomy => [Discogs => term name].
     */
    public static function aliases(): array {
        return apply_filters('d2w_term_aliases', [
            'product_cat' => [
                'Stage & Screen'         => 'Soundtracks',
                'Folk, World, & Country' => 'Country',
            ],
        ]);
    }

    /**
     * Extra terms added when any source name matches: taxonomy => [regex => term name].
     * Discogs has no plain "Metal" style, only Heavy Metal, Metalcore, Black Metal..., so
     * those also get a Metal tag, which the shop's Metal page uses.
     */
    public static function groups(): array {
        return apply_filters('d2w_term_groups', [
            'product_tag' => ['/metal/i' => 'Metal'],
        ]);
    }

    /**
     * Sets the mapped taxonomies on a product (not saved here). $values: source => names,
     * e.g. ['genres' => ['Electronic'], 'styles' => ['House', 'Techno']]. Returns true if
     * any terms changed.
     */
    public static function apply(WC_Product $product, array $values): bool {
        $mapping  = D2W_Field_Mapping_Page::get_mapping()['taxonomies'] ?? [];
        $assigned = $product->get_meta(self::ASSIGNED_META);
        $assigned = is_array($assigned) ? $assigned : [];
        $changed  = false;

        foreach (array_keys(self::targets()) as $taxonomy) {
            $source = (string) ($mapping[$taxonomy] ?? '');
            if ($source === '' || !isset($values[$source])) {
                continue; // Not mapped: the taxonomy is left alone.
            }

            $names = (array) $values[$source];
            foreach (self::groups()[$taxonomy] ?? [] as $pattern => $group) {
                if (preg_grep($pattern, $names)) {
                    $names[] = $group;
                }
            }

            $new = [];
            foreach ($names as $name) {
                $id = self::term_id($taxonomy, (string) $name);
                if ($id) {
                    $new[] = $id;
                }
            }
            $new = array_values(array_unique($new));

            $current  = array_map('intval', $taxonomy === 'product_cat' ? $product->get_category_ids('edit') : $product->get_tag_ids('edit'));
            $previous = array_map('intval', (array) ($assigned[$taxonomy] ?? []));
            // Keep terms added by hand; replace the ones D2W set last time.
            $ids = array_values(array_unique(array_merge(array_diff($current, $previous), $new)));

            if ($taxonomy === 'product_cat') {
                $default = (int) get_option('default_product_cat');
                if ($default && count($ids) > 1) {
                    $ids = array_values(array_diff($ids, [$default])); // Drop "Uncategorised".
                }
            }

            $sorted_ids = $ids;
            $sorted_current = $current;
            sort($sorted_ids);
            sort($sorted_current);
            if ($sorted_ids !== $sorted_current) {
                $taxonomy === 'product_cat' ? $product->set_category_ids($ids) : $product->set_tag_ids($ids);
                $changed = true;
            }
            $assigned[$taxonomy] = $new;
        }

        $product->update_meta_data(self::ASSIGNED_META, $assigned);
        return $changed;
    }

    /**
     * Applies the mapping from the genres and styles already saved on a product, without
     * fetching from Discogs. Returns true if the product's terms changed.
     */
    public static function apply_saved(int $product_id): bool {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
        $genres = (string) $product->get_meta('_d2w_genres');
        if ($genres === '' && (string) $product->get_meta('_d2w_styles') === '') {
            return false;
        }
        $changed = self::apply($product, [
            'genres' => self::split($genres, self::MULTI_WORD_GENRES),
            'styles' => self::split((string) $product->get_meta('_d2w_styles')),
        ]);
        $product->save();
        return $changed;
    }

    /**
     * Linked products with saved release data, for the Link & Backfill page.
     *
     * @return int[]
     */
    public static function products_with_release_data(): array {
        global $wpdb;
        return array_map('intval', $wpdb->get_col(
            "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key IN ('_d2w_genres', '_d2w_styles') AND m.meta_value <> ''
               AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')
             ORDER BY m.post_id"
        ));
    }

    // Splits a saved ", " list, keeping names that contain ", " whole.
    private static function split(string $list, array $whole = []): array {
        $tokens = [];
        foreach ($whole as $i => $name) {
            if (str_contains($list, $name)) {
                $list = str_replace($name, "\x1f{$i}\x1f", $list);
                $tokens["\x1f{$i}\x1f"] = $name;
            }
        }
        $names = array_filter(array_map('trim', explode(', ', $list)), 'strlen');
        return array_values(array_map(static fn($n) => $tokens[$n] ?? $n, $names));
    }

    // Existing term by name, slug or alias; created when missing. 0 on failure.
    private static function term_id(string $taxonomy, string $name): int {
        static $cache = [];
        $name = trim(html_entity_decode($name, ENT_QUOTES, 'UTF-8'));
        if ($name === '') {
            return 0;
        }
        $key = $taxonomy . '|' . strtolower($name);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $alias = self::aliases()[$taxonomy][$name] ?? '';
        foreach (array_filter([$name, $alias]) as $candidate) {
            $term = get_term_by('name', $candidate, $taxonomy) ?: get_term_by('slug', sanitize_title($candidate), $taxonomy);
            if ($term) {
                return $cache[$key] = (int) $term->term_id;
            }
        }

        $created = wp_insert_term($alias ?: $name, $taxonomy);
        if (is_wp_error($created)) {
            $existing = (int) $created->get_error_data('term_exists');
            if (!$existing) {
                D2W_Logger::warning("Could not create {$taxonomy} term '{$name}': " . $created->get_error_message());
            }
            return $cache[$key] = $existing;
        }
        return $cache[$key] = (int) $created['term_id'];
    }
}
