<?php

class D2W_Product_Importer {

    public static function register_hooks() {
        add_action('admin_init', [__CLASS__, 'handle_request']);
    }

    public static function handle_request() {
        $action = $_REQUEST['action'] ?? '';

        if ($action === 'insert_as_product') {
            self::process_import(false);
        } elseif ($action === 'insert_as_product_d') {
            self::process_import(true);
        }
    }

    public static function process_import($draft) {
        if (empty($_POST['product'])) {
            return;
        }

        $user_id       = get_current_user_id();
        $products_data = get_transient('d2w_products_' . $user_id) ?: [];
        $mapping       = D2W_Field_Mapping_Page::get_mapping();
        $messages      = [];

        foreach ($_POST['product'] as $listing_id) {
            $match = array_filter($products_data, fn($p) => $p['id'] == $listing_id);

            if (empty($match)) {
                $messages[] = "Product with ID {$listing_id} not found.";
                continue;
            }

            $product = reset($match);
            $status  = $draft ? 'draft' : 'publish';

            $post_data = [
                'post_status' => $status,
                'post_type'   => 'product',
            ];

            // post_title: template takes precedence; falls back to mapped field or hardcoded default
            $title_map = $mapping['post_title'] ?? [];
            $template  = trim($title_map['template'] ?? '');
            if ($template !== '') {
                $post_data['post_title'] = D2W_Field_Mapping_Page::render_template($template, $product);
            } elseif (!empty($title_map['discogs_field'])) {
                $post_data['post_title'] = $product[$title_map['discogs_field']] ?? '';
            } else {
                $post_data['post_title'] = trim(($product['artist'] ?? '') . ' - ' . ($product['title'] ?? ''), ' -');
            }

            // post_content and post_excerpt
            foreach (['post_content', 'post_excerpt'] as $post_field) {
                $df = $mapping[$post_field]['discogs_field'] ?? '';
                if ($df !== '') {
                    $post_data[$post_field] = $product[$df] ?? '';
                }
            }

            $post_id = wp_insert_post($post_data);

            if (!$post_id) {
                $messages[] = "Failed to create product '{$product['title']}'.";
                continue;
            }

            // Standard WooCommerce meta fields driven by mapping
            foreach (['_price', '_sale_price', '_sku'] as $meta_key) {
                $df = $mapping[$meta_key]['discogs_field'] ?? '';
                if ($df !== '' && isset($product[$df]) && $product[$df] !== '') {
                    update_post_meta($post_id, $meta_key, $product[$df]);
                }
            }

            // Always-set fields
            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_stock_status', 'instock');
            update_post_meta($post_id, '_stock', 1);
            update_post_meta($post_id, '_discogs_listing_id', $listing_id);
            wp_set_object_terms($post_id, 'simple', 'product_type');

            // Custom meta fields
            foreach ($mapping['custom_meta'] ?? [] as $custom) {
                $mk = sanitize_key($custom['meta_key'] ?? '');
                $df = $custom['discogs_field'] ?? '';
                if ($mk !== '' && $df !== '' && isset($product[$df]) && $product[$df] !== '') {
                    update_post_meta($post_id, $mk, $product[$df]);
                }
            }

            // Featured image
            $img_df = $mapping['featured_image']['discogs_field'] ?? 'image_main';
            if ($img_df !== '') {
                $img_url = $product[$img_df] ?? '';
                if ($img_url !== '' && filter_var($img_url, FILTER_VALIDATE_URL)) {
                    $image_id = self::sideload_image($img_url, $post_id);
                    if ($image_id) {
                        set_post_thumbnail($post_id, $image_id);
                    }
                }
            }

            // Gallery: sideload all images beyond the first
            $gallery_ids = [];
            foreach (array_slice($product['images'] ?? [], 1) as $img) {
                $img_url = $img['uri'] ?? '';
                if ($img_url !== '' && filter_var($img_url, FILTER_VALIDATE_URL)) {
                    $gid = self::sideload_image($img_url, $post_id);
                    if ($gid) {
                        $gallery_ids[] = $gid;
                    }
                }
            }
            if (!empty($gallery_ids)) {
                update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
            }

            $messages[] = "Product '{$product['title']}' created (ID: {$post_id}, status: {$status}).";
        }

        set_transient('d2w_results_' . $user_id, $messages, 300);
        wp_redirect(admin_url('admin.php?page=d2w_import_results_page'));
        exit;
    }

    private static function sideload_image($url, $post_id) {
        $tmp = download_url($url);

        if (is_wp_error($tmp)) {
            return false;
        }

        $file_array = [
            'name'     => sanitize_file_name(basename($url)),
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file_array, $post_id, $file_array['name']);

        return is_wp_error($id) ? false : $id;
    }
}