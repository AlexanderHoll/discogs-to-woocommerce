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

        self::$screens[add_submenu_page(
            'd2w_page',
            'Link & Backfill',
            'Link & Backfill',
            'manage_woocommerce',
            'd2w-link',
            [D2W_Link_Page::class, 'render_page']
        )] = 'link';

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

        $toggles = [
            'd2w_sync_restock'        => ['Restock relisted items', 'If an out-of-stock product\'s listing is For Sale again, set its stock back to 1. Items that sold on the website are not restocked while their listing is still For Sale.'],
            'd2w_sync_prices'         => ['Sync prices', 'Update the WooCommerce regular price when the Discogs price differs.'],
            'd2w_sync_auto_import'    => ['Auto-import new listings', 'Import new For Sale listings as draft products (up to ' . D2W_Discogs_Sync::AUTO_IMPORT_MAX . ' per run).'],
            'd2w_sync_push_to_drafts' => ['Website sales to Discogs', 'When an item sells on the website, set the Discogs listing to Draft. This changes your Discogs listings. Listings are never deleted.'],
        ];
        foreach ($toggles as $option => [$label, $description]) {
            register_setting('d2w_options', $option, [
                'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
                'default'           => D2W_Discogs_Sync::SETTINGS[$option],
            ]);
            add_settings_field($option, $label, [__CLASS__, 'render_checkbox'], 'd2w-settings', 'd2w_sync_section', [
                'label_for'   => $option,
                'option'      => $option,
                'description' => $description,
            ]);
        }
    }

    public static function sanitize_sync_interval($value): string {
        $value = is_string($value) ? $value : '';
        return array_key_exists($value, self::SYNC_INTERVALS) ? $value : 'disabled';
    }

    public static function sanitize_checkbox($value): string {
        return $value === '1' || $value === 1 || $value === true ? '1' : '0';
    }

    public static function render_checkbox(array $args) {
        $option = $args['option'];
        printf(
            '<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>',
            esc_attr($option),
            checked(D2W_Discogs_Sync::enabled($option), true, false),
            esc_html($args['description'])
        );
    }

    public static function render_settings() {
        $has_token = (bool) get_option('d2w_user_token');
        $report    = null;
        if (isset($_GET['d2w_synced'])) {
            $report = get_transient('d2w_sync_report_' . get_current_user_id());
            delete_transient('d2w_sync_report_' . get_current_user_id());
        }
        ?>
        <div class="wrap">
            <h1>Discogs to WooCommerce Settings</h1>

            <?php if (is_array($report)) {
                self::render_sync_report($report);
            } ?>

            <?php if ($has_token) {
                self::render_new_listings();
            } ?>

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
                <p>Preview lists what a sync would change without changing anything.</p>
                <div class="d2w-sync-buttons">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="d2w_preview_sync" />
                        <?php wp_nonce_field('d2w_preview_sync'); ?>
                        <?php submit_button('Preview Sync', 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="d2w_manual_sync" />
                        <?php wp_nonce_field('d2w_manual_sync'); ?>
                        <?php submit_button('Sync Now', 'primary', 'submit', false); ?>
                    </form>
                </div>

                <?php self::render_sync_log(); ?>
            <?php else: ?>
                <p><em>Add a Personal Access Token above to enable inventory sync.</em></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_sync_report(array $report): void {
        $labels = ['sold' => 'Mark sold', 'restock' => 'Restock', 'price' => 'Price', 'import' => 'Import', 'clear_flag' => 'Clear flag', 'details' => 'Details'];
        $class  = $report['status'] === 'ok' ? 'notice-success' : 'notice-error';
        $title  = $report['dry_run'] ? 'Sync preview (nothing was changed)' : 'Sync complete';
        if ($report['status'] !== 'ok') {
            $title = $report['dry_run'] ? 'Sync preview failed' : 'Sync did not complete';
        }
        ?>
        <div class="notice <?php echo esc_attr($class); ?> d2w-sync-report">
            <p><strong><?php echo esc_html($title); ?>.</strong> <?php echo esc_html(self::format_counts($report['counts'])); ?></p>
            <?php foreach ($report['messages'] as $message): ?>
                <p><?php echo esc_html($message); ?></p>
            <?php endforeach; ?>
            <?php if (!empty($report['changes'])): ?>
                <table class="widefat striped d2w-sync-changes">
                    <thead><tr><th>Change</th><th>Product</th><th>Listing</th><th>Detail</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['changes'] as $c): ?>
                        <tr>
                            <td><?php echo esc_html($labels[$c['type']] ?? $c['type']); ?></td>
                            <td>
                                <?php if ($c['product_id']): ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($c['product_id'], 'url') ?? ''); ?>"><?php echo esc_html($c['name']); ?></a>
                                <?php else: ?>
                                    <?php echo esc_html($c['name']); ?>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?php echo esc_url('https://www.discogs.com/sell/item/' . $c['listing_id']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($c['listing_id']); ?></a></td>
                            <td><?php echo esc_html($c['detail']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($report['status'] === 'ok'): ?>
                <p>No changes needed.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_new_listings(): void {
        $new = get_option(D2W_Discogs_Sync::NEW_LISTINGS);
        if (!is_array($new)) {
            return;
        }
        $url = admin_url('admin.php?page=d2w_page&d2w_unimported=1');
        ?>
        <div class="notice notice-info d2w-new-listings">
            <p>
                <?php if ($new['count'] > 0): ?>
                    <strong><?php echo esc_html(number_format_i18n($new['count'])); ?></strong>
                    For Sale listing(s) on Discogs are not on the website yet
                    (as of <?php echo esc_html(wp_date('j M Y, H:i', $new['time'])); ?>).
                    <a href="<?php echo esc_url($url); ?>">View unimported listings</a>
                <?php else: ?>
                    Every For Sale listing on Discogs is on the website
                    (as of <?php echo esc_html(wp_date('j M Y, H:i', $new['time'])); ?>).
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    private static function render_sync_log(): void {
        $log = D2W_Discogs_Sync::get_log();
        ?>
        <h2>Sync Log</h2>
        <?php if (!$log): ?>
            <p>No sync has run yet.</p>
            <?php return; ?>
        <?php endif; ?>
        <table class="widefat striped d2w-sync-log">
            <thead>
                <tr>
                    <th>Time</th><th>Trigger</th><th>Status</th><th>Checked</th><th>Sold</th><th>Restocked</th>
                    <th>Price changes</th><th>Imported</th><th>Skipped</th><th>Errors</th><th>Duration</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($log as $run): $c = $run['counts']; ?>
                <tr>
                    <td><?php echo esc_html(wp_date('j M Y, H:i', $run['time'])); ?></td>
                    <td><?php echo esc_html($run['trigger']); ?></td>
                    <td><?php echo esc_html($run['status']); ?></td>
                    <td><?php echo esc_html($c['checked']); ?></td>
                    <td><?php echo esc_html($c['sold']); ?></td>
                    <td><?php echo esc_html($c['restocked']); ?></td>
                    <td><?php echo esc_html($c['prices']); ?></td>
                    <td><?php echo esc_html($c['imported']); ?></td>
                    <td><?php echo esc_html($c['skipped']); ?></td>
                    <td><?php echo esc_html($c['errors']); ?></td>
                    <td><?php echo esc_html($run['duration'] . 's'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">Details are in WooCommerce &gt; Status &gt; Logs (source d2w).</p>
        <?php
    }

    private static function format_counts(array $counts): string {
        return sprintf(
            'Checked %d, sold %d, restocked %d, price changes %d, imported %d, new listings %d, skipped %d, errors %d.',
            $counts['checked'], $counts['sold'], $counts['restocked'], $counts['prices'],
            $counts['imported'], $counts['new'], $counts['skipped'], $counts['errors']
        );
    }

    public static function render_section_intro() {
        echo '<p>Enter your Discogs credentials. A <strong>Personal Access Token</strong> is required for sync and gives a higher API rate limit. Generate one at <a href="' . esc_url('https://www.discogs.com/settings/developers') . '" target="_blank" rel="noopener noreferrer">discogs.com/settings/developers</a>.</p>';
    }

    public static function render_sync_section_intro() {
        echo '<p>Sync compares imported products with your Discogs For Sale inventory. Products whose listing has sold are always marked out of stock. Requires a Personal Access Token.</p>';
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
