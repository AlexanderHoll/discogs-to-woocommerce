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

define('D2W_PLUGIN_FILE', __FILE__);

require_once plugin_dir_path(__FILE__) . 'includes/api/class-discogs-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/woocommerce/class-product-mapper.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-field-mapping-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/woocommerce/class-product-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-list-table.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-import-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-admin-menu.php';
require_once plugin_dir_path(__FILE__) . 'includes/sync/class-discogs-sync.php';

register_activation_hook(__FILE__, ['D2W_Discogs_Sync', 'schedule']);
register_deactivation_hook(__FILE__, ['D2W_Discogs_Sync', 'unschedule']);

add_action('admin_enqueue_scripts', function () {
    wp_enqueue_style('wp-lists');
    wp_enqueue_style('d2w-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '1.1');
});

add_action('admin_enqueue_scripts', function () {
    if (($_GET['page'] ?? '') !== 'd2w-field-mapping') {
        return;
    }
    wp_enqueue_script('d2w-field-mapping', plugin_dir_url(__FILE__) . 'assets/js/field-mapping.js', [], '1.0', true);
    wp_enqueue_style('d2w-field-mapping-css', plugin_dir_url(__FILE__) . 'assets/css/field-mapping.css', [], '1.0');
});

D2W_Admin_Menu::register();
D2W_Product_Importer::register_hooks();
D2W_Field_Mapping_Page::register();
D2W_Discogs_Sync::register();