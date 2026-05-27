<?php

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Discogs_Product_List_Table extends WP_List_Table {

    // One DB query per page load, shared across all column renders.
    private static $imported_ids = null;

    private array $filter_args;

    public function __construct() {
        parent::__construct([
            'singular' => 'discogs_product',
            'plural'   => 'discogs_products',
            'ajax'     => false,
        ]);
        $this->filter_args = self::parse_filter_args();
    }

    // -------------------------------------------------------------------------
    // Filter args (read from GET, sanitised)
    // -------------------------------------------------------------------------

    public static function parse_filter_args(): array {
        $valid_sorts  = ['listed', 'artist', 'title', 'price', 'format', 'year'];
        $valid_status = ['For Sale', 'Sold', 'Draft'];

        $sort     = $_GET['d2w_sort'] ?? 'listed';
        $status   = $_GET['d2w_status'] ?? 'For Sale';
        $per_page = (int) ($_GET['d2w_per_page'] ?? 50);

        return [
            'sort'       => in_array($sort, $valid_sorts, true) ? $sort : 'listed',
            'sort_order' => ($_GET['d2w_sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'per_page'   => in_array($per_page, [25, 50, 100], true) ? $per_page : 50,
            'status'     => in_array($status, $valid_status, true) ? $status : 'For Sale',
        ];
    }

    public function get_filter_args(): array {
        return $this->filter_args;
    }

    // -------------------------------------------------------------------------
    // Table structure
    // -------------------------------------------------------------------------

    public function get_columns(): array {
        return [
            'cb'               => '<input type="checkbox" />',
            'thumbnail'        => 'Cover',
            'artist'           => 'Artist',
            'title'            => 'Title',
            'format'           => 'Format',
            'year'             => 'Year',
            'media_condition'  => 'Media',
            'sleeve_condition' => 'Sleeve',
            'value'            => 'Price',
            'imported'         => 'Imported',
        ];
    }

    public function get_bulk_actions(): array {
        return [
            'insert_as_product'   => 'Insert as Product (Published)',
            'insert_as_product_d' => 'Insert as Product (Draft)',
        ];
    }

    // -------------------------------------------------------------------------
    // Data
    // -------------------------------------------------------------------------

    public function prepare_items(): void {
        $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $this->items  = D2W_Product_Mapper::map_listings($current_page, $this->filter_args);

        set_transient('d2w_products_' . get_current_user_id(), $this->items, 600);

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->process_bulk_action();
    }

    private static function get_imported_ids(): array {
        if (self::$imported_ids !== null) {
            return self::$imported_ids;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
                '_discogs_listing_id'
            )
        );

        self::$imported_ids = array_flip(wp_list_pluck($rows, 'meta_value'));

        return self::$imported_ids;
    }

    // -------------------------------------------------------------------------
    // Column renderers
    // -------------------------------------------------------------------------

    public function column_cb($item): string {
        return isset($item['id'])
            ? sprintf('<input type="checkbox" name="product[]" value="%s" />', esc_attr($item['id']))
            : '';
    }

    public function column_thumbnail($item): string {
        if (!empty($item['image_thumb'])) {
            return '<img src="' . esc_url($item['image_thumb']) . '" alt="" width="50" height="50" class="d2w-thumb" />';
        }
        return '<span class="d2w-thumb d2w-thumb--empty"></span>';
    }

    public function column_media_condition($item): string {
        $c = $item['media_condition'];
        return $c
            ? '<span class="d2w-condition d2w-condition--' . esc_attr(self::condition_slug($c)) . '" title="' . esc_attr($c) . '">' . esc_html($c) . '</span>'
            : '';
    }

    public function column_sleeve_condition($item): string {
        $c = $item['sleeve_condition'];
        return $c
            ? '<span class="d2w-condition" title="' . esc_attr($c) . '">' . esc_html($c) . '</span>'
            : '<span class="d2w-muted">—</span>';
    }

    public function column_value($item): string {
        return esc_html(($item['currency'] ?? '') . ' ' . number_format((float) $item['value'], 2));
    }

    public function column_imported($item): string {
        if (isset(self::get_imported_ids()[$item['id']])) {
            return '<span class="d2w-badge d2w-badge--imported">&#10003; Imported</span>';
        }
        return '';
    }

    // WP_List_Table calls column_{name}() directly when the method exists,
    // so column_default only handles artist, title, format, year.
    public function column_default($item, $column_name): string {
        return esc_html($item[$column_name] ?? '');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function condition_slug(string $condition): string {
        // Map full condition strings to short slugs for CSS colour coding.
        $map = [
            'Mint'              => 'm',
            'Near Mint'         => 'nm',
            'Very Good Plus'    => 'vgp',
            'Very Good'         => 'vg',
            'Good Plus'         => 'gp',
            'Good'              => 'g',
            'Fair'              => 'f',
            'Poor'              => 'p',
        ];
        foreach ($map as $label => $slug) {
            if (stripos($condition, $label) !== false) {
                return $slug;
            }
        }
        return 'unknown';
    }

    // -------------------------------------------------------------------------
    // Filter form rendered above the table
    // -------------------------------------------------------------------------

    public function render_filters(): void {
        $args = $this->filter_args;
        $sort_options = [
            'listed' => 'Recently listed',
            'artist' => 'Artist',
            'title'  => 'Title',
            'price'  => 'Price',
            'format' => 'Format',
            'year'   => 'Year',
        ];
        $status_options = [
            'For Sale' => 'For Sale',
            'Sold'     => 'Sold',
            'Draft'    => 'Draft',
        ];
        ?>
        <div class="d2w-filter-bar">
            <div class="d2w-filter-bar__search">
                <label for="d2w-search" class="screen-reader-text">Filter current page</label>
                <input
                    type="search"
                    id="d2w-search"
                    placeholder="Filter current page&hellip;"
                    autocomplete="off"
                />
            </div>

            <form method="get" class="d2w-filter-bar__form">
                <input type="hidden" name="page" value="d2w_page" />
                <input type="hidden" name="paged" value="1" />

                <label for="d2w_sort" class="screen-reader-text">Sort by</label>
                <select id="d2w_sort" name="d2w_sort">
                    <?php foreach ($sort_options as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($args['sort'], $val); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="d2w_sort_order">
                    <option value="desc" <?php selected($args['sort_order'], 'desc'); ?>>Desc</option>
                    <option value="asc"  <?php selected($args['sort_order'], 'asc'); ?>>Asc</option>
                </select>

                <select name="d2w_per_page">
                    <?php foreach ([25, 50, 100] as $n): ?>
                        <option value="<?php echo $n; ?>" <?php selected($args['per_page'], $n); ?>>
                            <?php echo $n; ?> per page
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="d2w_status">
                    <?php foreach ($status_options as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($args['status'], $val); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php submit_button('Apply', 'secondary', '', false); ?>
            </form>
        </div>

        <script>
        (function () {
            var input = document.getElementById('d2w-search');
            if (!input) return;
            input.addEventListener('input', function () {
                var term = this.value.toLowerCase();
                document.querySelectorAll('#the-list tr').forEach(function (row) {
                    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        })();
        </script>
        <?php
    }
}
