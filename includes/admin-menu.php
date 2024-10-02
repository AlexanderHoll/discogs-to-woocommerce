<?php

// Define top-level menu and submenu pages
function d2w_menu() {
    // Add top-level menu
    add_menu_page(
        'Discogs to WooCommerce',           // Page title
        'Discogs to WooCommerce',           // Menu title
        'manage_options',                   // Capability
        'd2w_page',                         // Menu slug
        'd2w_page_content',                 // Callback function for main page content
        'dashicons-admin-generic',          // Icon (Optional)
        99                                  // Position (Optional)
    );

    // Add submenu under the main plugin menu for settings
    add_submenu_page(
        'd2w_page',                        // Parent slug
        'D2W Settings',                    // Page title
        'Settings',                        // Menu title
        'manage_options',                  // Capability
        'd2w-settings',                    // Menu slug
        'd2w_settings_page'                // Callback function for settings page content
    );
}

// Add submenu page without a direct menu item, for results
add_action('admin_menu', 'd2w_submenu_page');
function d2w_submenu_page() {
    add_submenu_page(
        null,                              // No parent, accessed directly via URL
        'Import Results',                  // Page title
        'Import Results',                  // Menu title (unused here)
        'manage_options',                  // Capability
        'd2w_import_results_page',         // Menu slug
        'd2w_import_results_page_content'  // Callback function for import results
    );
}

// Settings initialization and registration
function d2w_settings_init() {
    register_setting('d2w_options', 'd2w_api_key', 'sanitize_text_field');
    register_setting('d2w_options', 'd2w_api_secret', 'sanitize_text_field');
    register_setting('d2w_options', 'd2w_discogs_username', 'sanitize_text_field');

    add_settings_section(
        'd2w_section',
        'API Settings',
        'd2w_section_callback',
        'd2w-settings'
    );

    add_settings_field(
        'd2w_api_key',
        'API Key',
        'd2w_api_key_callback',
        'd2w-settings',
        'd2w_section'
    );

    add_settings_field(
        'd2w_api_secret',
        'API Secret',
        'd2w_api_secret_callback',
        'd2w-settings',
        'd2w_section'
    );

    add_settings_field(
        'd2w_discogs_username',
        'Discogs Username',
        'd2w_discogs_username_callback',
        'd2w-settings',
        'd2w_section'
    );
}

// Callback for settings page
function d2w_settings_page() {
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

// Ensure the menus are hooked into WordPress
add_action('admin_menu', 'd2w_menu');
add_action('admin_init', 'd2w_settings_init');

// Callback function for Discogs Username field
function d2w_discogs_username_callback() {
    $option = get_option('d2w_discogs_username');
    echo '<input type="text" id="d2w_discogs_username" name="d2w_discogs_username" value="' . esc_attr($option) . '" />';
}

// Callback function for API Key field
function d2w_section_callback() {
    echo '<p>Please add your Discogs API key and secret here in order to fetch product images. You can find these at: <a href="https://www.discogs.com/settings/developers">https://www.discogs.com/settings/developers</a></p>';
}

function d2w_api_key_callback() {
    $option = get_option('d2w_api_key');
    echo '<input type="text" id="d2w_api_key" name="d2w_api_key" value="' . esc_attr($option) . '" />';
}

// Callback function for API Secret field
function d2w_api_secret_callback() {
    $option = get_option('d2w_api_secret');
    echo '<input type="text" id="d2w_api_secret" name="d2w_api_secret" value="' . esc_attr($option) . '" />';
}