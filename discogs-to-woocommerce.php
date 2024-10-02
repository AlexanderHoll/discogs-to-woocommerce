<?php
/*
Plugin Name:  Discogs to Woocommerce
Plugin URI:   https://www.alexanderhollingworth.co.uk
Description:  A plugin that intends to fetch a Discogs seller inventory and allow to import these products as Woocommerce products
Version:      1.0
Author:       Alexander Hollingworth
Author URI:   https://www.alexanderhollingworth.co.uk
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  discogs-to-woocommerce
Domain Path:  /
*/

// Main plugin file adjustments (if needed)
require_once plugin_dir_path(__FILE__) . 'includes/admin-menu.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/api.php';
require_once plugin_dir_path(__FILE__) . 'includes/page.php';
require_once plugin_dir_path(__FILE__) . 'includes/products.php';

// create a custom class for the table
class Discogs_Product_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'discogs_product',   // singular name of the listed records
            'plural'   => 'discogs_products',  // plural name of the listed records
            'ajax'     => false                // does this table support ajax?
        ]);
    }
    
    // store product data fetched from Discogs
    private $products_data = [];

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'thumbnail' => 'Thumbnail',
            'artist' => 'Artist',
            'title' => 'Title',
            'comments' => 'Comments',
            'description' => 'Description',
            'value' => 'Value (GBP)',
        ];
    }

    // Establish bulk actions for table
    public function get_bulk_actions() {
        $actions = [
            'insert_as_product' => 'Insert as Product (Published)',
            'insert_as_product_d' => 'Insert as Product (Draft)',
        ];
        return $actions;
    }

    public function prepare_items() {
        // Fetch and prepare items for the table
        $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $this->items = return_listings($current_page);
    
        // Store products_data in a session variable to be fetched when inserting products
        if (!session_id()) {
            session_start();
        }
        $_SESSION['discogs_products_data'] = $this->items;
    
        // Prepare column headers
        $this->_column_headers = [$this->get_columns(), [], []];
    
        // Process bulk action
        $this->process_bulk_action();
    
        // Handle bulk actions if any
        if (isset($_POST['product']) && !empty($_POST['product'])) {
            do_action('process_bulk_action', $_POST['product']);
        }
    }
    
    public function column_thumbnail($item) {
        $thumbnail_url = isset($item['image_thumb']) ? $item['image_thumb'] : '';
    
        if (!empty($thumbnail_url)) {
            return '<img src="' . esc_url($thumbnail_url) . '" alt="Thumbnail" width="50" height="50" />';
        }
    
        return '';  // or handle appropriately if thumbnail URL doesn't exist
    }
    
    
    // define what each column in the table displays
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'thumbnail':
                return $this->column_thumbnail($item); // Call the new function for the thumbnail column
            case 'artist':
            case 'title':
            case 'comments':
            case 'description':
            case 'value':
                return $item[$column_name] ?? ''; // Return the value if it exists, otherwise return an empty string.
            default:
                return isset($item[$column_name]) ? $item[$column_name] : ''; // Return the value if it exists, otherwise return an empty string.
        }
    }
    
    // render checkbox for bulk actions
    public function column_cb($item) {
        
        if (isset($item['id'])) {
            return sprintf(
                '<input type="checkbox" name="product[]" value="%s" />', $item['id']
            );
        }
        return '';  // or handle appropriately if not an ID doesn't exist
    }

    // render bulk actions form
    public function bulk_actions_form() {
        echo '<select name="action">';
        echo '<option value="-1">Bulk Actions</option>';
        foreach ($this->get_bulk_actions() as $value => $label) {
            echo "<option value='$value'>$label</option>";
        }
        echo '</select>';
        echo '<input type="submit" name="" id="doaction" class="button action" value="Apply">';
    }
}