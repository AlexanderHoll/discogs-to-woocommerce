<?php

class D2W_Admin_Menu {

    public static function register() {
        add_action('admin_menu', [__CLASS__, 'register_menus']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_menus() {
        add_menu_page(
            'Discogs to WooCommerce',
            'Discogs to WooCommerce',
            'manage_options',
            'd2w_page',
            [D2W_Import_Page::class, 'render_main'],
            'dashicons-admin-generic',
            99
        );

        add_submenu_page(
            'd2w_page',
            'D2W Settings',
            'Settings',
            'manage_options',
            'd2w-settings',
            [__CLASS__, 'render_settings']
        );

        // Hidden page for import results (no parent = not shown in nav)
        add_submenu_page(
            null,
            'Import Results',
            'Import Results',
            'manage_options',
            'd2w_import_results_page',
            [D2W_Import_Page::class, 'render_results']
        );
    }

    public static function register_settings() {
        register_setting('d2w_options', 'd2w_api_key', 'sanitize_text_field');
        register_setting('d2w_options', 'd2w_api_secret', 'sanitize_text_field');
        register_setting('d2w_options', 'd2w_discogs_username', 'sanitize_text_field');

        add_settings_section('d2w_section', 'API Settings', [__CLASS__, 'render_section_intro'], 'd2w-settings');

        add_settings_field('d2w_api_key', 'API Key', [__CLASS__, 'render_field_api_key'], 'd2w-settings', 'd2w_section');
        add_settings_field('d2w_api_secret', 'API Secret', [__CLASS__, 'render_field_api_secret'], 'd2w-settings', 'd2w_section');
        add_settings_field('d2w_discogs_username', 'Discogs Username', [__CLASS__, 'render_field_username'], 'd2w-settings', 'd2w_section');
    }

    public static function render_settings() {
        ?>
        <div class="wrap">
            <h2>Discogs to WooCommerce Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('d2w_options');
                do_settings_sections('d2w-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function render_section_intro() {
        echo '<p>Please add your Discogs API key and secret here in order to fetch product images. You can find these at: <a href="https://www.discogs.com/settings/developers">https://www.discogs.com/settings/developers</a></p>';
    }

    public static function render_field_api_key() {
        $value = get_option('d2w_api_key');
        echo '<input type="text" id="d2w_api_key" name="d2w_api_key" value="' . esc_attr($value) . '" />';
    }

    public static function render_field_api_secret() {
        $value = get_option('d2w_api_secret');
        echo '<input type="text" id="d2w_api_secret" name="d2w_api_secret" value="' . esc_attr($value) . '" />';
    }

    public static function render_field_username() {
        $value = get_option('d2w_discogs_username');
        echo '<input type="text" id="d2w_discogs_username" name="d2w_discogs_username" value="' . esc_attr($value) . '" />';
    }
}