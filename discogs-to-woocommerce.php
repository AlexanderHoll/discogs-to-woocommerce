<?php
/**
 * Plugin Name:          Discogs to WooCommerce
 * Plugin URI:           https://github.com/AlexanderHoll/discogs-to-woocommerce
 * Description:          Imports a Discogs seller inventory as WooCommerce products and keeps stock in sync with Discogs.
 * Version:              1.2.0
 * Requires at least:    6.4
 * Requires PHP:         8.0
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * Author:               Alexander Hollingworth
 * Author URI:           https://www.alexanderhollingworth.co.uk
 * License:              GPL2
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          discogs-to-woocommerce
 */

defined('ABSPATH') || exit;

define('D2W_VERSION', '1.2.0');
define('D2W_PLUGIN_FILE', __FILE__);
define('D2W_PATH', plugin_dir_path(__FILE__));
define('D2W_URL', plugin_dir_url(__FILE__));

require_once D2W_PATH . 'includes/class-logger.php';
require_once D2W_PATH . 'includes/class-cache.php';
require_once D2W_PATH . 'includes/api/class-discogs-api.php';
require_once D2W_PATH . 'includes/woocommerce/class-product-mapper.php';
require_once D2W_PATH . 'includes/admin/class-field-mapping-page.php';
require_once D2W_PATH . 'includes/woocommerce/class-product-importer.php';
require_once D2W_PATH . 'includes/woocommerce/class-order-sync.php';
require_once D2W_PATH . 'includes/woocommerce/class-release-data.php';
require_once D2W_PATH . 'includes/woocommerce/class-product-linker.php';
require_once D2W_PATH . 'includes/sync/class-backfill.php';
require_once D2W_PATH . 'includes/admin/class-link-page.php';
require_once D2W_PATH . 'includes/admin/class-product-tracklist.php';
require_once D2W_PATH . 'includes/admin/class-product-videos.php';
require_once D2W_PATH . 'includes/admin/class-import-page.php';
require_once D2W_PATH . 'includes/admin/class-admin-menu.php';
require_once D2W_PATH . 'includes/sync/class-discogs-sync.php';

register_activation_hook(__FILE__, ['D2W_Discogs_Sync', 'schedule']);
register_deactivation_hook(__FILE__, ['D2W_Discogs_Sync', 'unschedule']);

add_action('plugins_loaded', 'd2w_boot');

// Order meta is read and written through the WC_Order API, so HPOS is supported.
add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

function d2w_boot(): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>' . esc_html__('Discogs to WooCommerce requires WooCommerce to be installed and active.', 'discogs-to-woocommerce') . '</p></div>';
        });
        return;
    }

    D2W_Admin_Menu::register();
    D2W_Product_Importer::register_hooks();
    D2W_Field_Mapping_Page::register();
    D2W_Discogs_Sync::register();
    D2W_Order_Sync::register();
    D2W_Release_Data::register();
    D2W_Backfill::register();
    D2W_Link_Page::register();
    D2W_Product_Tracklist::register();
    D2W_Product_Videos::register();

    if (defined('WP_CLI') && WP_CLI) {
        require_once D2W_PATH . 'includes/sync/class-sync-cli.php';
        WP_CLI::add_command('d2w', 'D2W_Sync_CLI');
    }

    add_action('admin_enqueue_scripts', 'd2w_enqueue_admin_assets');
}

function d2w_enqueue_admin_assets(string $hook_suffix): void {
    $screen = D2W_Admin_Menu::screen_for_hook($hook_suffix);
    if ($screen === null) {
        return;
    }

    wp_enqueue_style('d2w-admin', D2W_URL . 'assets/admin.css', [], D2W_VERSION);
    wp_enqueue_script('d2w-admin', D2W_URL . 'assets/js/admin.js', [], D2W_VERSION, true);

    if ($screen === 'field-mapping') {
        wp_enqueue_script('d2w-field-mapping', D2W_URL . 'assets/js/field-mapping.js', [], D2W_VERSION, true);
        wp_enqueue_style('d2w-field-mapping', D2W_URL . 'assets/css/field-mapping.css', ['d2w-admin'], D2W_VERSION);
    }
}
