<?php

defined('ABSPATH') || exit;

// Discogs to WooCommerce > Link & Backfill: connect existing products to listings and
// fill in release data, attributes and missing regular prices.
class D2W_Link_Page {

    const RESULT_KEY = 'd2w_link_result_';

    public static function register() {
        foreach (['d2w_auto_link', 'd2w_link_selected', 'd2w_backfill_start', 'd2w_fix_prices'] as $action) {
            add_action('admin_post_' . $action, [__CLASS__, 'handle_' . substr($action, 4)]);
        }
        add_action('wp_ajax_d2w_backfill_status', [__CLASS__, 'ajax_backfill_status']);
    }

    /**
     * Backfill progress for the Link & Backfill page, polled while a backfill runs.
     */
    public static function ajax_backfill_status() {
        check_ajax_referer('d2w_backfill_status');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(null, 403);
        }
        $status = get_option(D2W_Backfill::STATUS, []);
        wp_send_json_success([
            'text'       => self::backfill_text($status),
            'running'    => self::backfill_running($status),
            'no_release' => count(D2W_Backfill::pending_ids()),
        ]);
    }

    private static function backfill_running(array $status): bool {
        return !empty($status['started']) && empty($status['finished']);
    }

    private static function backfill_text(array $status): string {
        return sprintf(
            'Started %s, %d of %d processed%s.',
            wp_date('j M Y, H:i', $status['started']),
            (int) ($status['done'] ?? 0),
            (int) ($status['total'] ?? 0),
            !empty($status['finished']) ? ', finished' : ', running'
        );
    }

    private static function guard(string $nonce): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'discogs-to-woocommerce'), 403);
        }
        check_admin_referer($nonce);
    }

    private static function done(array $messages): void {
        set_transient(self::RESULT_KEY . get_current_user_id(), $messages, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=d2w-link&d2w_done=1'));
        exit;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    public static function handle_auto_link() {
        self::guard('d2w_auto_link');
        $r = D2W_Product_Linker::auto_link();
        $messages = [sprintf('Auto-linked %d product(s) using CedCommerce listing IDs.', count($r['linked']))];
        if ($r['missing']) {
            $messages[] = sprintf('%d CedCommerce listing(s) no longer exist on Discogs: %s', count($r['missing']), implode(', ', array_map(static fn($m) => "#{$m['product_id']} {$m['name']}", $r['missing'])));
        }
        foreach (array_merge($r['skipped'], $r['errors']) as $m) {
            $messages[] = "Product #{$m['product_id']} not linked: {$m['reason']}";
        }
        self::done($messages);
    }

    public static function handle_link_selected() {
        self::guard('d2w_link_selected');
        $inventory = D2W_Product_Linker::inventory() ?? [];
        $linked    = 0;
        $messages  = [];
        foreach ((array) wp_unslash($_POST['pair'] ?? []) as $pair) {
            [$product_id, $listing_id] = array_map('absint', explode(':', (string) $pair) + [1 => 0]);
            if (!$product_id || !$listing_id) {
                continue;
            }
            $ok = D2W_Product_Linker::link($product_id, $listing_id, $inventory[$listing_id] ?? D2W_Discogs_API::fetch_listing($listing_id));
            if (is_wp_error($ok)) {
                $messages[] = $ok->get_error_message();
            } else {
                $linked++;
            }
        }
        array_unshift($messages, sprintf('Linked %d product(s). Run the backfill to fetch their release details.', $linked));
        self::done($messages);
    }

    public static function handle_backfill_start() {
        self::guard('d2w_backfill_start');
        $count = D2W_Backfill::queue(!empty($_POST['force']));
        self::done([$count
            ? sprintf('Backfill queued for %d product(s). It runs in the background in batches of %d, about one batch a minute.', $count, D2W_Backfill::BATCH_SIZE)
            : 'Every linked product already has release details.']);
    }

    public static function handle_fix_prices() {
        self::guard('d2w_fix_prices');
        $r = D2W_Backfill::fix_prices();
        self::done([sprintf('Set the regular price on %d product(s) that only had a display price.', $r['fixed'])]);
    }

    // -------------------------------------------------------------------------
    // Page
    // -------------------------------------------------------------------------

    private static function button(string $action, string $label, string $class = 'secondary', string $extra = ''): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="d2w-inline-form">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>" />
            <?php wp_nonce_field($action); ?>
            <?php echo $extra; // Trusted markup built in this class. ?>
            <?php submit_button($label, $class, 'submit', false); ?>
        </form>
        <?php
    }

    public static function render_page() {
        global $wpdb;

        $messages = isset($_GET['d2w_done']) ? get_transient(self::RESULT_KEY . get_current_user_id()) : false;
        delete_transient(self::RESULT_KEY . get_current_user_id());

        $linked      = count(D2W_Product_Importer::get_imported_listing_ids());
        $unlinked    = count(D2W_Product_Linker::unlinked_products());
        $ced_pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} c
             LEFT JOIN {$wpdb->postmeta} l ON l.post_id = c.post_id AND l.meta_key = '_discogs_listing_id'
             WHERE c.meta_key = %s AND c.meta_value <> '' AND (l.meta_value IS NULL OR l.meta_value = '')",
            D2W_Product_Linker::CED_LISTING_META
        ));
        $no_release  = count(D2W_Backfill::pending_ids());
        $prices      = D2W_Backfill::fix_prices(true)['fixed'];
        $status      = get_option(D2W_Backfill::STATUS, []);
        $inventory   = D2W_Product_Linker::inventory();
        $proposals   = $inventory === null ? null : D2W_Product_Linker::proposals($inventory);
        ?>
        <div class="wrap">
            <h1>Link &amp; Backfill</h1>
            <p>Connect existing products to their Discogs listings so sync manages them, then fetch release details (label, cat no, genres, styles, tracklist) and the format and condition attributes used for filtering.</p>

            <?php if (is_array($messages)): ?>
                <div class="notice notice-success is-dismissible">
                    <?php foreach ($messages as $m): ?><p><?php echo esc_html($m); ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <table class="widefat striped d2w-link-status" style="max-width: 720px">
                <tbody>
                    <tr><th>Products linked to a Discogs listing</th><td><?php echo esc_html($linked); ?></td></tr>
                    <tr><th>Products not linked</th><td><?php echo esc_html($unlinked); ?></td></tr>
                    <tr><th>Not linked but carrying a CedCommerce listing ID</th><td><?php echo esc_html($ced_pending); ?></td></tr>
                    <tr><th>Linked products without release details</th><td data-d2w-no-release><?php echo esc_html($no_release); ?></td></tr>
                    <tr><th>Products missing a regular price</th><td><?php echo esc_html($prices); ?></td></tr>
                    <?php if (!empty($status['started'])): ?>
                        <tr><th>Last backfill</th><td>
                            <span class="spinner<?php echo self::backfill_running($status) ? ' is-active' : ''; ?>" data-d2w-backfill-spinner style="float: none; margin: 0 6px 0 0;"></span>
                            <span data-d2w-backfill-status><?php echo esc_html(self::backfill_text($status)); ?></span>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (self::backfill_running($status)): ?>
                <script>
                // While a backfill runs, refresh its progress every 10 seconds (no page reload).
                (function () {
                    const status = document.querySelector('[data-d2w-backfill-status]');
                    const spinner = document.querySelector('[data-d2w-backfill-spinner]');
                    const noRelease = document.querySelector('[data-d2w-no-release]');
                    const body = new URLSearchParams({ action: 'd2w_backfill_status', _ajax_nonce: <?php echo wp_json_encode(wp_create_nonce('d2w_backfill_status')); ?> });
                    const poll = () => fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body })
                        .then((r) => r.json())
                        .then((r) => {
                            if (!r.success) return;
                            status.textContent = r.data.text;
                            noRelease.textContent = r.data.no_release;
                            spinner.classList.toggle('is-active', r.data.running);
                            if (r.data.running) setTimeout(poll, 10000);
                        })
                        .catch(() => setTimeout(poll, 30000));
                    setTimeout(poll, 10000);
                })();
                </script>
            <?php endif; ?>

            <h2>1. Auto-link</h2>
            <p>Links products created by the old CedCommerce Discogs plugin, using the listing ID it stored. Listings that have since sold still link, so sync knows about them.</p>
            <?php self::button('d2w_auto_link', sprintf('Auto-link %d product(s)', $ced_pending), 'primary'); ?>

            <h2>2. Confirm suggested matches</h2>
            <?php if ($proposals === null): ?>
                <p><em>Could not load the Discogs inventory. Check the API settings and try again.</em></p>
            <?php elseif (!$proposals): ?>
                <p>No suggested matches. Every unlinked product either has no For Sale listing or does not match one closely enough.</p>
            <?php else: ?>
                <p>Products matched to For Sale listings by artist and title. Nothing is linked until you tick it and confirm. Exact matches are pre-ticked.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="d2w_link_selected" />
                    <?php wp_nonce_field('d2w_link_selected'); ?>
                    <table class="widefat striped d2w-proposals">
                        <thead><tr><td class="check-column"><input type="checkbox" aria-label="Select all" /></td><th>Product</th><th>Discogs listing</th><th>Confidence</th></tr></thead>
                        <tbody>
                        <?php foreach ($proposals as $p):
                            $l = $p['listing']; ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="pair[]" value="<?php echo esc_attr($p['product_id'] . ':' . $p['listing_id']); ?>" <?php checked($p['score'] === 100); ?> aria-label="<?php echo esc_attr('Link ' . $p['name']); ?>" />
                                </th>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link($p['product_id'], 'url') ?? ''); ?>"><?php echo esc_html($p['name']); ?></a>
                                    <br /><span class="description">#<?php echo esc_html($p['product_id']); ?>, <?php echo esc_html($p['stock'] === 'instock' ? 'in stock' : 'out of stock'); ?></span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url('https://www.discogs.com/sell/item/' . $p['listing_id']); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html(D2W_Product_Mapper::clean_artist_name((string) ($l['release']['artist'] ?? '')) . ' - ' . ($l['release']['title'] ?? '')); ?>
                                    </a>
                                    <br /><span class="description"><?php echo esc_html(implode(' · ', array_filter([
                                        $l['release']['format'] ?? '',
                                        isset($l['price']['value']) ? ($l['price']['currency'] ?? '') . ' ' . number_format((float) $l['price']['value'], 2) : '',
                                        $l['condition'] ?? '',
                                    ]))); ?></span>
                                </td>
                                <td><?php echo esc_html($p['score'] . '%'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php submit_button('Link selected products', 'primary'); ?>
                </form>
            <?php endif; ?>

            <h2>3. Backfill release details and attributes</h2>
            <p>Fetches each linked product's release (about two API requests per product) and sets the format and condition attributes. Runs in the background in batches.</p>
            <?php self::button('d2w_backfill_start', sprintf('Backfill %d product(s)', $no_release), 'primary'); ?>
            <?php self::button('d2w_backfill_start', 'Re-fetch all linked products', 'secondary', '<input type="hidden" name="force" value="1" />'); ?>

            <h2>4. Fix missing regular prices</h2>
            <p>Some older products only have a display price, which breaks sale prices, price filters and price sync. This copies it into the regular price.</p>
            <?php self::button('d2w_fix_prices', sprintf('Fix %d product(s)', $prices)); ?>
        </div>
        <?php
    }
}
