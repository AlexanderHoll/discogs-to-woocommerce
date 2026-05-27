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
        $messages      = [];

        foreach ($_POST['product'] as $listing_id) {
            $match = array_filter($products_data, fn($p) => $p['id'] == $listing_id);

            if (empty($match)) {
                $messages[] = "Product with ID {$listing_id} not found.";
                continue;
            }

            $product = reset($match);
            $status  = $draft ? 'draft' : 'publish';

            $post_id = wp_insert_post([
                'post_title'   => $product['artist'] . ' - ' . $product['title'],
                'post_content' => $product['description'],
                'post_excerpt' => $product['comments'],
                'post_status'  => $status,
                'post_type'    => 'product',
            ]);

            if (!$post_id) {
                $messages[] = "Failed to create product '{$product['title']}'.";
                continue;
            }

            update_post_meta($post_id, '_price', $product['value']);
            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_stock_status', 'instock');
            update_post_meta($post_id, '_stock', 1);
            wp_set_object_terms($post_id, 'simple', 'product_type');

            if (!empty($product['images'][0]['uri'])) {
                $image_id = self::sideload_image($product['images'][0]['uri'], $post_id);
                if ($image_id) {
                    set_post_thumbnail($post_id, $image_id);
                }
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