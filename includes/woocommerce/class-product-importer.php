<?php

defined('ABSPATH') || exit;

class D2W_Product_Importer {

    const BULK_ACTIONS = [
        'insert_as_product'   => 'publish',
        'insert_as_product_d' => 'draft',
    ];

    public static function register_hooks() {
        add_action('admin_init', [__CLASS__, 'handle_request']);
    }

    public static function handle_request() {
        if (($_REQUEST['page'] ?? '') !== 'd2w_page' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        // Top dropdown posts `action`, bottom dropdown posts `action2`; "-1" means none chosen.
        $action = sanitize_key(wp_unslash($_POST['action'] ?? '-1'));
        if (!isset(self::BULK_ACTIONS[$action])) {
            $action = sanitize_key(wp_unslash($_POST['action2'] ?? '-1'));
        }
        if (!isset(self::BULK_ACTIONS[$action])) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to import products.', 'discogs-to-woocommerce'), 403);
        }
        check_admin_referer('bulk-discogs_products');

        $listing_ids = array_filter(array_map('absint', (array) wp_unslash($_POST['product'] ?? [])));
        if (empty($listing_ids)) {
            return;
        }

        $messages = self::import(array_values(array_unique($listing_ids)), self::BULK_ACTIONS[$action]);

        set_transient('d2w_results_' . get_current_user_id(), $messages, 300);
        wp_safe_redirect(admin_url('admin.php?page=d2w_import_results_page'));
        exit;
    }

    /**
     * Imports listings from the current user's cached inventory page.
     *
     * @param int[]  $listing_ids
     * @param string $status      'publish' or 'draft'.
     * @return string[] Result messages.
     */
    public static function import(array $listing_ids, string $status, ?array $products_data = null): array {
        $products_data ??= get_transient('d2w_products_' . get_current_user_id()) ?: [];
        $by_id    = array_column($products_data, null, 'id');
        $imported = self::get_imported_listing_ids();
        $mapping  = D2W_Field_Mapping_Page::get_mapping();
        $messages = [];

        foreach ($listing_ids as $listing_id) {
            if (isset($imported[$listing_id])) {
                $messages[] = "Skipped listing {$listing_id}: already imported (product ID {$imported[$listing_id]}).";
                continue;
            }

            if (!isset($by_id[$listing_id])) {
                // Not on the cached page (e.g. a different page or per-page setting): fetch it directly.
                $listing = D2W_Discogs_API::fetch_listing($listing_id);
                if ($listing === null) {
                    $messages[] = "Listing {$listing_id} not found on Discogs.";
                    continue;
                }
                $by_id[$listing_id] = D2W_Product_Mapper::map_listing($listing);
            }

            $product = $by_id[$listing_id];
            $result  = self::create_product($product, $mapping, $status);

            if (is_wp_error($result)) {
                $messages[] = "Failed to create product '{$product['title']}': " . $result->get_error_message();
                D2W_Logger::error("Import of listing {$listing_id} failed: " . $result->get_error_message());
                continue;
            }

            $imported[$listing_id] = $result;
            $messages[] = "Product '{$product['title']}' created (ID: {$result}, status: {$status}).";
        }

        return $messages;
    }

    // listing_id => product_id for every product that has already been imported.
    public static function get_imported_listing_ids(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_value AS listing_id, pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status <> 'trash'",
                '_discogs_listing_id'
            )
        );

        return array_column($rows, 'post_id', 'listing_id');
    }

    /**
     * @return int|WP_Error New product ID.
     */
    private static function create_product(array $product, array $mapping, string $status) {
        $field = static fn(string $key) => $mapping[$key]['discogs_field'] ?? '';
        $value = static fn(string $df) => ($df !== '' && isset($product[$df]) && is_scalar($product[$df])) ? (string) $product[$df] : '';

        // Name: template takes precedence, then a single mapped field, then "Artist - Title".
        $template = trim($mapping['post_title']['template'] ?? '');
        if ($template !== '') {
            $name = D2W_Field_Mapping_Page::render_template($template, $product);
        } elseif ($field('post_title') !== '') {
            $name = $value($field('post_title'));
        } else {
            $name = trim(($product['artist'] ?? '') . ' - ' . ($product['title'] ?? ''), ' -');
        }

        $wc = new WC_Product_Simple();
        $wc->set_name(sanitize_text_field($name));
        $wc->set_status($status);
        $wc->set_description(wp_kses_post($value($field('post_content'))));
        $wc->set_short_description(wp_kses_post($value($field('post_excerpt'))));

        $price = $value($field('_price'));
        if ($price !== '') {
            $wc->set_regular_price(wc_format_decimal($price));
        }
        $sale = $value($field('_sale_price'));
        if ($sale !== '') {
            $wc->set_sale_price(wc_format_decimal($sale));
        }

        $sku = $value($field('_sku'));
        if ($sku !== '') {
            try {
                $wc->set_sku(sanitize_text_field($sku));
            } catch (WC_Data_Exception $e) {
                D2W_Logger::warning("SKU '{$sku}' not set for listing {$product['id']}: " . $e->getMessage());
            }
        }

        $wc->set_manage_stock(true);
        $wc->set_stock_quantity(1);
        $wc->set_stock_status('instock');

        $wc->update_meta_data('_discogs_listing_id', (string) $product['id']);
        $wc->update_meta_data('_discogs_release_id', (string) ($product['release_id'] ?? ''));
        $wc->update_meta_data('_discogs_last_synced', time());

        foreach ($mapping['custom_meta'] ?? [] as $custom) {
            $mk = sanitize_key($custom['meta_key'] ?? '');
            $mv = $value($custom['discogs_field'] ?? '');
            if ($mk !== '' && $mv !== '') {
                $wc->update_meta_data($mk, sanitize_text_field($mv));
            }
        }

        try {
            $post_id = $wc->save();
        } catch (Exception $e) {
            return new WP_Error('d2w_save_failed', $e->getMessage());
        }
        if (!$post_id) {
            return new WP_Error('d2w_save_failed', 'WooCommerce did not return a product ID.');
        }

        self::attach_images($wc, $product, $mapping);

        return $post_id;
    }

    private static function attach_images(WC_Product $wc, array $product, array $mapping): void {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $basename = sanitize_title(trim(($product['artist'] ?? '') . ' ' . ($product['title'] ?? ''))) ?: 'discogs-' . $product['id'];
        $n        = 0;

        $img_df  = $mapping['featured_image']['discogs_field'] ?? 'image_main';
        $img_url = $img_df !== '' ? ($product[$img_df] ?? '') : '';
        if (is_string($img_url) && $img_url !== '') {
            $image_id = self::sideload_image($img_url, $wc->get_id(), $basename . '-' . (++$n));
            if ($image_id) {
                $wc->set_image_id($image_id);
            }
        }

        // Gallery: every image beyond the first.
        $gallery_ids = [];
        foreach (array_slice($product['images'] ?? [], 1) as $img) {
            $gid = self::sideload_image($img['uri'] ?? '', $wc->get_id(), $basename . '-' . (++$n));
            if ($gid) {
                $gallery_ids[] = $gid;
            }
        }
        if ($gallery_ids) {
            $wc->set_gallery_image_ids($gallery_ids);
        }

        if ($n > 0) {
            $wc->save();
        }
    }

    // Downloads an image into the media library as "{$name}.{ext}". Returns the attachment ID or 0.
    private static function sideload_image(string $url, int $post_id, string $name): int {
        if ($url === '' || !wp_http_validate_url($url)) {
            return 0;
        }

        $ext = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $ext = 'jpg';
        }

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            D2W_Logger::warning("Image download failed for {$url}: " . $tmp->get_error_message());
            return 0;
        }

        $file_array = [
            'name'     => sanitize_file_name($name . '.' . $ext),
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file_array, $post_id, $name);

        if (is_wp_error($id)) {
            wp_delete_file($tmp);
            D2W_Logger::warning("Image sideload failed for {$url}: " . $id->get_error_message());
            return 0;
        }

        return (int) $id;
    }
}
