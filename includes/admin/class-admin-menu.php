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

        add_submenu_page(
            'd2w_page',
            'Field Mapping',
            'Field Mapping',
            'manage_options',
            'd2w-field-mapping',
            [D2W_Field_Mapping_Page::class, 'render_page']
        );

        // Hidden page for import results (no parent = not shown in nav).
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
        // API credentials
        register_setting('d2w_options', 'd2w_discogs_username', 'sanitize_text_field');
        register_setting('d2w_options', 'd2w_user_token', 'sanitize_text_field');
        register_setting('d2w_options', 'd2w_api_key', 'sanitize_text_field');
        register_setting('d2w_options', 'd2w_api_secret', 'sanitize_text_field');

        add_settings_section('d2w_section', 'API Settings', [__CLASS__, 'render_section_intro'], 'd2w-settings');
        add_settings_field('d2w_discogs_username', 'Discogs Username', [__CLASS__, 'render_field_username'], 'd2w-settings', 'd2w_section');
        add_settings_field('d2w_user_token', 'Personal Access Token', [__CLASS__, 'render_field_user_token'], 'd2w-settings', 'd2w_section');
        add_settings_field('d2w_api_key', 'API Key', [__CLASS__, 'render_field_api_key'], 'd2w-settings', 'd2w_section');
        add_settings_field('d2w_api_secret', 'API Secret', [__CLASS__, 'render_field_api_secret'], 'd2w-settings', 'd2w_section');

        // Sync settings
        register_setting('d2w_options', 'd2w_sync_interval', 'sanitize_text_field');

        add_settings_section('d2w_sync_section', 'Sync Settings', [__CLASS__, 'render_sync_section_intro'], 'd2w-settings');
        add_settings_field('d2w_sync_interval', 'Sync Interval', [__CLASS__, 'render_field_sync_interval'], 'd2w-settings', 'd2w_sync_section');
    }

    public static function render_settings() {
        $has_token       = (bool) get_option('d2w_user_token');
        $last_sync       = get_option('d2w_last_sync');
        $last_sync_count = (int) get_option('d2w_last_sync_count', 0);
        ?>
        <div class="wrap">
            <h2>Discogs to WooCommerce Settings</h2>

            <?php if (isset($_GET['d2w_synced'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Sync complete &mdash; <?php echo esc_html(get_option('d2w_last_sync_count', 0)); ?> product(s) marked as sold.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('d2w_options');
                do_settings_sections('d2w-settings');
                submit_button('Save Settings');
                ?>
            </form>

            <?php if ($has_token): ?>
                <hr />
                <h2>Manual Sync</h2>
                <p>
                    <?php if ($last_sync): ?>
                        Last sync ran at <strong><?php echo esc_html($last_sync); ?></strong>
                        and marked <strong><?php echo esc_html($last_sync_count); ?></strong> product(s) as sold.
                    <?php else: ?>
                        No sync has run yet.
                    <?php endif; ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="d2w_manual_sync" />
                    <?php wp_nonce_field('d2w_manual_sync'); ?>
                    <input type="submit" class="button button-secondary" value="Sync Now" />
                </form>
            <?php else: ?>
                <p><em>Add a Personal Access Token above to enable inventory sync.</em></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_section_intro() {
        echo '<p>Enter your Discogs credentials. A <strong>Personal Access Token</strong> is required for sync and gives a higher API rate limit. Generate one at <a href="https://www.discogs.com/settings/developers" target="_blank">discogs.com/settings/developers</a>.</p>';
    }

    public static function render_sync_section_intro() {
        echo '<p>Controls how often WooCommerce checks Discogs for sold listings. Requires a Personal Access Token.</p>';
    }

    public static function render_field_username() {
        $value = get_option('d2w_discogs_username');
        echo '<input type="text" id="d2w_discogs_username" name="d2w_discogs_username" value="' . esc_attr($value) . '" />';
    }

    public static function render_field_user_token() {
        $value = get_option('d2w_user_token');
        echo '<input type="text" id="d2w_user_token" name="d2w_user_token" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Used for sync and authenticated API requests (60 req/min). Replaces key/secret for browsing when set.</p>';
    }

    public static function render_field_api_key() {
        $value = get_option('d2w_api_key');
        echo '<input type="text" id="d2w_api_key" name="d2w_api_key" value="' . esc_attr($value) . '" />';
        echo '<p class="description">Optional. Only used for browsing when no Personal Access Token is set (25 req/min).</p>';
    }

    public static function render_field_api_secret() {
        $value = get_option('d2w_api_secret');
        echo '<input type="text" id="d2w_api_secret" name="d2w_api_secret" value="' . esc_attr($value) . '" />';
    }

    public static function render_field_sync_interval() {
        $value = get_option('d2w_sync_interval', 'disabled');
        $options = [
            'disabled'   => 'Disabled',
            'd2w_15min'  => 'Every 15 minutes',
            'd2w_30min'  => 'Every 30 minutes',
            'hourly'     => 'Hourly',
            'twicedaily' => 'Twice daily',
            'daily'      => 'Daily',
        ];
        echo '<select id="d2w_sync_interval" name="d2w_sync_interval">';
        foreach ($options as $key => $label) {
            $selected = selected($value, $key, false);
            echo '<option value="' . esc_attr($key) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        $next = wp_next_scheduled('d2w_sync_event');
        if ($next) {
            echo '<p class="description">Next scheduled sync: ' . esc_html(get_date_from_gmt(date('Y-m-d H:i:s', $next), 'D j M Y, H:i')) . '</p>';
        }
    }
}
