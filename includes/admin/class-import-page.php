<?php

class D2W_Import_Page {

    public static function render_main(): void {
        $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Discogs Inventory</h1>';

        $table = new Discogs_Product_List_Table();
        $table->prepare_items();

        if (empty($table->items)) {
            echo '<p>No products fetched. Check your credentials in <a href="' . esc_url(admin_url('admin.php?page=d2w-settings')) . '">Settings</a>.</p>';
            echo '</div>';
            return;
        }

        $table->render_filters();

        echo '<form method="post" action="">';
        $table->display();
        echo '</form>';

        self::render_pagination($current_page, $table->get_filter_args());

        echo '</div>';
    }

    public static function render_results(): void {
        $user_id  = get_current_user_id();
        $messages = get_transient('d2w_results_' . $user_id);

        echo '<div class="wrap">';
        echo '<h1>Import Results</h1>';

        if (!empty($messages)) {
            foreach ($messages as $message) {
                echo '<p>' . esc_html($message) . '</p>';
            }
            delete_transient('d2w_results_' . $user_id);
        } else {
            echo '<p>No import results to display.</p>';
        }

        echo '<p><a href="' . esc_url(admin_url('admin.php?page=d2w_page')) . '">&larr; Back to Product List</a></p>';
        echo '</div>';
    }

    private static function render_pagination(int $current_page, array $filter_args): void {
        // Re-uses the cached response — no extra HTTP request.
        $data = D2W_Discogs_API::fetch($current_page, $filter_args);

        if (!isset($data['pagination'])) {
            return;
        }

        $total_pages = (int) ($data['pagination']['pages'] ?? 1);
        $total_items = (int) ($data['pagination']['items'] ?? 0);

        // Build base params preserving active filters, omitting defaults to keep URLs clean.
        $base = array_filter([
            'page'            => 'd2w_page',
            'd2w_sort'        => $filter_args['sort'] !== 'listed'     ? $filter_args['sort']       : null,
            'd2w_sort_order'  => $filter_args['sort_order'] !== 'desc' ? $filter_args['sort_order'] : null,
            'd2w_per_page'    => $filter_args['per_page'] !== 50       ? $filter_args['per_page']   : null,
            'd2w_status'      => $filter_args['status'] !== 'For Sale' ? $filter_args['status']     : null,
        ]);

        $page_url = static fn($p) => esc_url(admin_url('admin.php?' . http_build_query(array_merge($base, ['paged' => $p]))));

        echo '<div class="d2w-pagination">';
        printf(
            '<span class="d2w-pagination__info">Page %d of %d &mdash; %s listings</span>',
            $current_page,
            $total_pages,
            number_format($total_items)
        );

        echo '<span class="d2w-pagination__links">';

        if ($current_page > 1) {
            echo '<a href="' . $page_url(1) . '" class="button button-small">&laquo; First</a>';
            echo '<a href="' . $page_url($current_page - 1) . '" class="button button-small">&lsaquo; Prev</a>';
        }

        if ($current_page < $total_pages) {
            echo '<a href="' . $page_url($current_page + 1) . '" class="button button-small">Next &rsaquo;</a>';
            echo '<a href="' . $page_url($total_pages) . '" class="button button-small">Last &raquo;</a>';
        }

        echo '</span>';
        echo '</div>';
    }
}
