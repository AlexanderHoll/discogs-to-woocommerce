<?php

defined('ABSPATH') || exit;

class D2W_Admin_Menu {

    const SYNC_INTERVALS = [
        'disabled'   => 'Disabled',
        'd2w_15min'  => 'Every 15 minutes',
        'd2w_30min'  => 'Every 30 minutes',
        'hourly'     => 'Hourly',
        'twicedaily' => 'Twice daily',
        'daily'      => 'Daily',
    ];

    // hook_suffix => screen key, filled as menus are registered.
    private static array $screens = [];

    public static function register() {
        add_action('admin_menu', [__CLASS__, 'register_menus']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_menus() {
        self::$screens[add_menu_page(
            'Discogs to WooCommerce',
            'Discogs to WooCommerce',
            'manage_options',
            'd2w_page',
            [D2W_Import_Page::class, 'render_main'],
            'dashicons-album',
            99
        )] = 'import';

        self::$screens[add_submenu_page(
            'd2w_page',
            'D2W Settings',
            'Settings',
            'manage_options',
            'd2w-settings',
            [__CLASS__, 'render_settings']
        )] = 'settings';

        self::$screens[add_submenu_page(
            'd2w_page',
            'Field Mapping',
            'Field Mapping',
            'manage_options',
            'd2w-field-mapping',
            [D2W_Field_Mapping_Page::class, 'render_page']
        )] = 'field-mapping';

        // Hidden page for import results (empty parent = not shown in nav).
        $results_hook = add_submenu_page(
            '',
            'Import Results',
            'Import Results',
            'manage_options',
            'd2w_import_results_page',
            [D2W_Import_Page::class, 'render_results']
        );
        self::$screens[$results_hook] = 'results';

        // WP cannot resolve a title for parentless pages, which leaves admin-header.php with null.
        add_action('load-' . $results_hook, static function () {
            $GLOBALS['title'] = 'Import Results';
        });
    }

    // Returns the D2W screen key for an admin hook suffix, or null for non-D2W screens.
    public static function screen_for_hook(string $hook_suffix): ?string {
        return self::$screens[$hook_suffix] ?? null;
    }

    public static function register_settings() {
        // API credentials
        register_setting('d2w_options', 'd2w_discogs_username', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('d2w_options', 'd2w_user_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('d2w_options', 'd2w_api_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('d2w_options', 'd2w_api_secret', ['sanitize_callback' => 'sanitize_text_field']);

        add_settings_section('d2w_section', 'API Settings', [__CLASS__, 'render_section_intro'], 'd2w-settings');
        add_settings_field('d2w_discogs_username', 'Discogs Username', [__CLASS__, 'render_field_username'], 'd2w-settings', 'd2w_section', ['label_for' => 'd2w_discogs_username']);
        add_settings_field('d2w_user_token', 'Personal Access Token', [__CLASS__, 'render_field_user_token'], 'd2w-settings', 'd2w_section', ['label_for' => 'd2w_user_token']);
        add_settings_field('d2w_api_key', 'API Key', [__CLASS__, 'render_field_api_key'], 'd2w-settings', 'd2w_section', ['label_for' => 'd2w_api_key']);
        add_settings_field('d2w_api_secret', 'API Secret', [__CLASS__, 'render_field_api_secret'], 'd2w-settings', 'd2w_section', ['label_for' => 'd2w_api_secret']);

        // Sync settings
        register_setting('d2w_options', 'd2w_sync_interval', [
            'sanitize_callback' => [__CLASS__, 'sanitize_sync_interval'],
            'default'           => 'disabled',
        ]);

        add_settings_section('d2w_sync_section', 'Sync Settings', [__CLASS__, 'render_sync_section_intro'], 'd2w-settings');
        add_settings_field('d2w_sync_interval', 'Sync Interval', [__CLASS__, 'render_field_sync_interval'], 'd2w-settings', 'd2w_sync_section', ['label_for' => 'd2w_sync_interval']);
    }

    public static function sanitize_sync_interval($value): string {
        $value = is_string($value) ? $value : '';
        return array_key_exists($value, self::SYNC_INTERVALS) ? $value : 'disabled';
    }

    public static function render_settings() {
        $has_token = (bool) get_option('d2w_user_token');
        $last_sync = get_option('d2w_last_sync');
        $checked   = (int) get_option('d2w_last_sync_checked', 0);
        $sold      = (int) get_option('d2w_last_sync_count', 0);
        $skipped   = (int) get_option('d2w_last_sync_skipped', 0);
        ?>
        <div class="wrap">
            <h1>Discogs to WooCommerce Settings</h1>

            <?php if (isset($_GET['d2w_synced'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        Sync complete. Checked <strong><?php echo esc_html($checked); ?></strong> product(s),
                        marked <strong><?php echo esc_html($sold); ?></strong> as sold<?php if ($skipped > 0): ?>,
                        <strong><?php echo esc_html($skipped); ?></strong> skipped due to API errors (see WooCommerce &gt; Status &gt; Logs, source d2w)<?php endif; ?>.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
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
                        Last sync ran at <strong><?php echo esc_html($last_sync); ?></strong>.
                        Checked <strong><?php echo esc_html($checked); ?></strong> product(s),
                        marked <strong><?php echo esc_html($sold); ?></strong> as sold<?php if ($skipped > 0): ?>,
                        <strong><?php echo esc_html($skipped); ?></strong> skipped (API error)<?php endif; ?>.
                    <?php else: ?>
                        No sync has run yet.
                    <?php endif; ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="d2w_manual_sync" />
                    <?php wp_nonce_field('d2w_manual_sync'); ?>
                    <?php submit_button('Sync Now', 'secondary', 'submit', false); ?>
                </form>
            <?php else: ?>
                <p><em>Add a Personal Access Token above to enable inventory sync.</em></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_section_intro() {
        echo '<p>Enter your Discogs credentials. A <strong>Personal Access Token</strong> is required for sync and gives a higher API rate limit. Generate one at <a href="' . esc_url('https://www.discogs.com/settings/developers') . '" target="_blank" rel="noopener noreferrer">discogs.com/settings/developers</a>.</p>';
    }

    public static function render_sync_section_intro() {
        echo '<p>Controls how often WooCommerce checks Discogs for sold listings. Requires a Personal Access Token.</p>';
    }

    public static function render_field_username() {
        printf(
            '<input type="text" id="d2w_discogs_username" name="d2w_discogs_username" value="%s" class="regular-text" />',
            esc_attr(get_option('d2w_discogs_username'))
        );
    }

    public static function render_field_user_token() {
        self::render_secret_input('d2w_user_token');
        echo '<p class="description">Used for sync and authenticated API requests (60 req/min). Replaces key/secret for browsing when set.</p>';
    }

    public static function render_field_api_key() {
        printf(
            '<input type="text" id="d2w_api_key" name="d2w_api_key" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr(get_option('d2w_api_key'))
        );
        echo '<p class="description">Optional. Only used for browsing when no Personal Access Token is set (25 req/min).</p>';
    }

    public static function render_field_api_secret() {
        self::render_secret_input('d2w_api_secret');
    }

    private static function render_secret_input(string $option): void {
        printf(
            '<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="new-password" spellcheck="false" />',
            esc_attr($option),
            esc_attr(get_option($option))
        );
    }

    public static function render_field_sync_interval() {
        $value = get_option('d2w_sync_interval', 'disabled');
        echo '<select id="d2w_sync_interval" name="d2w_sync_interval">';
        foreach (self::SYNC_INTERVALS as $key => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($value, $key, false), esc_html($label));
        }
        echo '</select>';
        $next = wp_next_scheduled('d2w_sync_event');
        if ($next) {
            echo '<p class="description">Next scheduled sync: ' . esc_html(wp_date('D j M Y, H:i', $next)) . '</p>';
        }
    }
}
