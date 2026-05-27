<?php

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Discogs_Product_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'discogs_product',
            'plural'   => 'discogs_products',
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        return [
            'cb'          => '<input type="checkbox" />',
            'thumbnail'   => 'Thumbnail',
            'artist'      => 'Artist',
            'title'       => 'Title',
            'comments'    => 'Comments',
            'description' => 'Description',
            'value'       => 'Value (GBP)',
        ];
    }

    public function get_bulk_actions() {
        return [
            'insert_as_product'   => 'Insert as Product (Published)',
            'insert_as_product_d' => 'Insert as Product (Draft)',
        ];
    }

    public function prepare_items() {
        $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $this->items  = D2W_Product_Mapper::map_listings($current_page);

        set_transient('d2w_products_' . get_current_user_id(), $this->items, 600);

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->process_bulk_action();
    }

    public function column_cb($item) {
        return isset($item['id'])
            ? sprintf('<input type="checkbox" name="product[]" value="%s" />', esc_attr($item['id']))
            : '';
    }

    public function column_thumbnail($item) {
        if (!empty($item['image_thumb'])) {
            return '<img src="' . esc_url($item['image_thumb']) . '" alt="Thumbnail" width="50" height="50" />';
        }
        return '';
    }

    public function column_default($item, $column_name) {
        if ($column_name === 'thumbnail') {
            return $this->column_thumbnail($item);
        }
        return esc_html($item[$column_name] ?? '');
    }

    public function bulk_actions_form() {
        echo '<select name="action">';
        echo '<option value="-1">Bulk Actions</option>';
        foreach ($this->get_bulk_actions() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<input type="submit" name="" id="doaction" class="button action" value="Apply">';
    }
}