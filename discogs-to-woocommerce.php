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

require_once plugin_dir_path(__FILE__) . 'includes/api/class-discogs-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/woocommerce/class-product-mapper.php';
require_once plugin_dir_path(__FILE__) . 'includes/woocommerce/class-product-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-list-table.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-import-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-admin-menu.php';

add_action('admin_enqueue_scripts', function () {
    wp_enqueue_style('wp-lists');
});

D2W_Admin_Menu::register();
D2W_Product_Importer::register_hooks();