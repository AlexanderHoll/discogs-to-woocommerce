<?php

defined('ABSPATH') || exit;

/**
 * Release details and filterable attributes for linked products.
 *
 * - Release meta (_d2w_*) comes from GET /releases/{id}: details, format descriptions,
 *   credits, estimated weight, and YouTube videos matched to tracks (_d2w_videos).
 * - pa_pressing (Limited Edition, Coloured Vinyl, Repress...) comes from the release formats.
 * - With "Fill empty product weights" on, products without a weight get the estimated one.
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
        'pressing'        => 'Pressing',
    ];

    // Pressing details worth filtering and badging on: name => [term slug, sort order].
    // From the release's format descriptions and free text (colours, weight, sleeve).
    const PRESSING = [
        'Test Pressing'   => ['test-pressing', 1],
        'Limited Edition' => ['limited-edition', 2],
        'Numbered'        => ['numbered', 3],
        'Coloured Vinyl'  => ['coloured-vinyl', 4],
        'Picture Disc'    => ['picture-disc', 5],
        'White Label'     => ['white-label', 6],
        'Promo'           => ['promo', 7],
        '180g'            => ['180g', 8],
        'Gatefold'        => ['gatefold', 9],
        'Repress'         => ['repress', 10],
        'Reissue'         => ['reissue', 11],
        'Remastered'      => ['remastered', 12],
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
    private static function set_attribute(WC_Product $product, string $taxonomy, array $term_ids, bool $merge = false): bool {
        $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
        $attrs    = $product->get_attributes();
        $current  = isset($attrs[$taxonomy]) ? array_map('intval', $attrs[$taxonomy]->get_options()) : null;
        if ($merge && $current) {
            $term_ids = array_values(array_unique(array_merge($current, $term_ids)));
        }

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
        // A variable product's variants can differ, so its values are added to, not replaced.
        $merge  = $product->is_type('variable');

        $changed = false;
        foreach (['pa_media_condition' => $media, 'pa_sleeve_condition' => $sleeve] as $taxonomy => $value) {
            $code = self::grade_code((string) $value);
            if ($code === '') {
                continue;
            }
            [$slug, $order] = self::GRADES[$code];
            $changed = self::set_attribute($product, $taxonomy, [self::term_id($taxonomy, $code, $slug, $order)], $merge) || $changed;
        }

        $name = self::format_name((string) $format);
        if ($name !== '') {
            $changed = self::set_attribute($product, 'pa_format', [self::term_id('pa_format', $name, self::format_slug($name))], $merge) || $changed;
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
        if (self::fetch_and_save((int) $product_id)) {
            D2W_Cache::purge_products([(int) $product_id]);
        }
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
     * Every format's descriptions and free text, e.g.
     * {"descriptions": ["12\"", "45 RPM", "Limited Edition"], "text": ["Pink", "180g"]}.
     */
    public static function format_details(array $release): array {
        $descriptions = [];
        $text = [];
        foreach ((array) ($release['formats'] ?? []) as $f) {
            foreach ((array) ($f['descriptions'] ?? []) as $d) {
                $descriptions[] = trim((string) $d);
            }
            if (trim((string) ($f['text'] ?? '')) !== '') {
                $text[] = trim((string) $f['text']);
            }
        }
        return [
            'descriptions' => array_values(array_unique(array_filter($descriptions, 'strlen'))),
            'text'         => array_values(array_unique($text)),
        ];
    }

    /**
     * Pressing details (keys of PRESSING) found in the release formats, best first.
     * Colours count only on vinyl, and not when they describe a label, sleeve or print
     * ("Red Labels", "Yellow OBI-strip", "Pink Gel Sleeve"), so black vinyl with a coloured
     * label or sleeve is not "Coloured Vinyl".
     */
    public static function pressing_details(array $release): array {
        $found = [];
        $map = [
            'test pressing' => 'Test Pressing', 'limited edition' => 'Limited Edition', 'numbered' => 'Numbered',
            'picture disc' => 'Picture Disc', 'white label' => 'White Label', 'promo' => 'Promo',
            'repress' => 'Repress', 'reissue' => 'Reissue', 'remastered' => 'Remastered', 'gatefold' => 'Gatefold',
        ];
        foreach ((array) ($release['formats'] ?? []) as $f) {
            foreach ((array) ($f['descriptions'] ?? []) as $d) {
                $key = strtolower(trim((string) $d));
                if (isset($map[$key])) {
                    $found[$map[$key]] = true;
                }
            }
            $text = strtolower(remove_accents((string) ($f['text'] ?? '')));
            if ($text === '') {
                continue;
            }
            if (str_contains($text, 'gatefold')) {
                $found['Gatefold'] = true;
            }
            if (preg_match('/\b180\s*-?\s*(?:g|gr|gram|grams)\b/', $text)) {
                $found['180g'] = true;
            }
            if (strcasecmp((string) ($f['name'] ?? ''), 'Vinyl') === 0 && preg_match(
                '/\b(?:colou?red|red|pink|blue|green|yellow|orange|purple|violet|gold|golden|silver|clear|transparent|translucent|marbled?|splatter(?:ed)?|swirl(?:ed)?|smoke[dy]?|grey|gray|cream|white|bone|brown|magenta|turquoise|teal|neon|glow|opaque|mint|olive|lilac|lavender)\b(?![\s&-]*(?:labels?|sleeves?|print(?:ed)?|stamp(?:ed)?|text|cover|ink|inserts?|obi|strips?|gel|jacket|poster|inner))/',
                $text
            )) {
                $found['Coloured Vinyl'] = true;
            }
        }
        return array_values(array_filter(array_keys(self::PRESSING), static fn($name) => isset($found[$name])));
    }

    /**
     * Credits from the release and its tracks: [{name, role, tracks}], one row per person
     * and role, with the track positions they are credited on (empty for the whole release).
     */
    public static function credits(array $release): array {
        $rows = [];
        $add = static function (array $a, string $tracks) use (&$rows) {
            $name = trim(D2W_Product_Mapper::clean_artist_name((string) (($a['anv'] ?? '') ?: ($a['name'] ?? ''))));
            $role = trim((string) ($a['role'] ?? ''));
            if ($name === '' || $role === '') {
                return;
            }
            $key = strtolower($name . '|' . $role);
            $rows[$key] ??= ['name' => $name, 'role' => $role, 'tracks' => []];
            if ($tracks !== '') {
                $rows[$key]['tracks'][] = $tracks;
            }
        };
        foreach ((array) ($release['extraartists'] ?? []) as $a) {
            $add((array) $a, trim((string) ($a['tracks'] ?? '')));
        }
        foreach ((array) ($release['tracklist'] ?? []) as $t) {
            foreach ((array) ($t['extraartists'] ?? []) as $a) {
                $add((array) $a, trim((string) ($t['position'] ?? '')));
            }
        }
        return array_values(array_map(static function ($r) {
            $r['tracks'] = implode(', ', array_unique($r['tracks']));
            return $r;
        }, $rows));
    }

    /**
     * The release's YouTube videos, in Discogs order: [{id, title, duration, track}].
     * Duration is in seconds; track is the matched tracklist position ("A1"), or empty.
     * Only embeddable YouTube links are kept; the ID is all a theme needs.
     */
    public static function videos(array $release, array $tracks = []): array {
        $videos = [];
        foreach ((array) ($release['videos'] ?? []) as $v) {
            if (empty($v['embed']) || !preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{11})#', (string) ($v['uri'] ?? ''), $m)) {
                continue;
            }
            $title = trim((string) ($v['title'] ?? ''));
            $videos[$m[1]] = [
                'id'       => $m[1],
                'title'    => $title,
                'duration' => (int) ($v['duration'] ?? 0),
                'track'    => self::match_track($title, $tracks),
            ];
        }
        return array_values($videos);
    }

    /**
     * Tracklist position whose title best matches a video title, or '' when nothing is
     * close enough. Video titles are user-entered on Discogs ("Artist - Title [CAT001]",
     * "Title (Official Audio)"), so artist prefixes and bracketed extras are tried both ways.
     */
    public static function match_track(string $video_title, array $tracks): string {
        $norm = static function (string $s, bool $strip_brackets): string {
            $s = strtolower(remove_accents(html_entity_decode($s, ENT_QUOTES, 'UTF-8')));
            if ($strip_brackets) {
                $s = preg_replace('/[\(\[\{][^\)\]\}]*[\)\]\}]/', ' ', $s);
            }
            $s = preg_replace('/\b(?:official|video|audio|visuali[sz]er|lyric|lyrics|hq|hd|4k|premiere|full|free download|out now)\b/', ' ', $s);
            return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $s)));
        };
        // "Artist - Title": also try the part after the first dash or pipe.
        $candidates = [$video_title];
        if (preg_match('/\s[-\x{2013}\x{2014}|]\s(.+)$/u', $video_title, $m)) {
            $candidates[] = $m[1];
        }

        $best = ['', 0];
        foreach ($tracks as $t) {
            $position = (string) ($t['position'] ?? '');
            foreach ([false, true] as $strip) {
                $track = $norm((string) ($t['title'] ?? ''), $strip);
                if (strlen($track) < 2) {
                    continue;
                }
                foreach ($candidates as $c) {
                    $video = $norm($c, $strip);
                    if ($video === '') {
                        continue;
                    }
                    $score = 0;
                    if ($video === $track) {
                        $score = $strip ? 70 : 100;
                    } elseif (strlen($track) >= 4 && preg_match('/(?:^| )' . preg_quote($track, '/') . '(?: |$)/', $video)) {
                        // Longer contained titles are better evidence.
                        $score = ($strip ? 40 : 60) + min(19, strlen($track));
                    }
                    if ($score > $best[1]) {
                        $best = [$position, $score];
                    }
                }
            }
        }
        return $best[1] >= 50 ? $best[0] : '';
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

        // A tracklist edited by hand on the product screen is kept, and videos are matched to it.
        $tracklist_edited = (bool) $product->get_meta(D2W_Product_Tracklist::EDITED_META);
        if ($tracklist_edited) {
            $saved = json_decode((string) $product->get_meta(D2W_Product_Tracklist::META), true);
            $tracks = is_array($saved) ? $saved : $tracks;
        }

        $credits = self::credits($release);
        $weight  = (int) ($release['estimated_weight'] ?? 0);

        $meta = [
            '_d2w_artist'    => $artist,
            '_d2w_title'     => (string) ($release['title'] ?? ''),
            '_d2w_label'     => $label_name,
            '_d2w_catno'     => $catno,
            '_d2w_country'   => (string) ($release['country'] ?? ''),
            '_d2w_genres'    => implode(', ', (array) ($release['genres'] ?? [])),
            '_d2w_styles'    => implode(', ', (array) ($release['styles'] ?? [])),
            '_d2w_tracklist' => wp_json_encode($tracks),
            '_d2w_formats'   => wp_json_encode(self::format_details($release), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '_d2w_credits'   => wp_json_encode($credits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            // Plain list of credited names, so searches can match them.
            '_d2w_credit_names' => implode(', ', array_values(array_unique(array_column($credits, 'name')))),
            '_d2w_weight'    => $weight ?: '',
        ];
        if ($tracklist_edited) {
            unset($meta['_d2w_tracklist']);
        }
        // Videos edited by hand on the product screen are kept as they are.
        if (!$product->get_meta(D2W_Product_Videos::EDITED_META)) {
            $meta['_d2w_videos'] = wp_json_encode(self::videos($release, $tracks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        foreach ($meta as $key => $value) {
            $product->update_meta_data($key, $value);
        }

        // Categories and tags from genres and styles, as mapped on the Field Mapping screen.
        D2W_Taxonomies::apply($product, [
            'genres' => (array) ($release['genres'] ?? []),
            'styles' => (array) ($release['styles'] ?? []),
        ]);

        // Shipping: fill an empty WooCommerce weight from Discogs' estimate (grams).
        if ($weight > 0 && $product->get_weight() === '' && D2W_Discogs_Sync::enabled('d2w_fill_weight')) {
            $unit = (string) get_option('woocommerce_weight_unit', 'kg');
            $product->set_weight(wc_format_decimal(wc_get_weight($weight, $unit, 'g'), 3));
        }

        // Pressing details replace the previous set, so corrections on Discogs carry over.
        self::ensure_attributes();
        $pressing = [];
        foreach (self::pressing_details($release) as $name) {
            [$slug, $order] = self::PRESSING[$name];
            $pressing[] = self::term_id('pa_pressing', $name, $slug, $order);
        }
        self::set_attribute($product, 'pa_pressing', $pressing);

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
