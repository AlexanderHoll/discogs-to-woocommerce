<?php

class D2W_Import_Page {

    public static function render_main() {
        $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;

        echo '<div class="wrap">';
        echo '<h1>Welcome to Discogs to WooCommerce</h1>';

        $table = new Discogs_Product_List_Table();
        $table->prepare_items();

        if (empty($table->items)) {
            echo '<p>No products fetched.</p>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="">';
        echo '<div class="wrap">';
        echo '<h2 class="wp-heading-inline">Product Listings</h2>';
        $table->display();
        echo '</div>';
        echo '</form>';

        self::render_pagination($current_page);

        echo '</div>';
    }

    public static function render_results() {
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

        echo '<p><a href="' . esc_url(admin_url('admin.php?page=d2w_page')) . '">Back to Product List</a></p>';
        echo '</div>';
    }

    private static function render_pagination($current_page) {
        // D2W_Discogs_API::fetch() is already cached from prepare_items(), no extra HTTP request.
        $data = D2W_Discogs_API::fetch($current_page);

        if (!isset($data['pagination']['pages'])) {
            return;
        }

        $total_pages = (int) $data['pagination']['pages'];

        echo '<div class="pagination-buttons">';

        if ($current_page > 1) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=d2w_page&paged=1')) . '" class="pagination-button">First</a>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=d2w_page&paged=' . ($current_page - 1))) . '" class="pagination-button">Previous</a>';
        }

        if ($current_page < $total_pages) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=d2w_page&paged=' . ($current_page + 1))) . '" class="pagination-button">Next</a>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=d2w_page&paged=' . $total_pages)) . '" class="pagination-button">Last</a>';
        }

        echo '</div>';
    }
}