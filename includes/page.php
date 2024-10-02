<?php

function return_pagination($current_page) {
    $data = fetch_discogs($current_page); // Fetch data for the current page to get pagination info

    // Parse pagination array
    if (isset($data['pagination'])) {
        $pagination = $data['pagination'];
    } else {
        // Handle missing pagination data
        $pagination = [];
    }

    // Parse Discogs info array for base url
    if (isset($data[0]["account_info"])) {
        $account_info = $data[0]["account_info"];
    } else {
        // Handle missing pagination data
        $account_info = [];
    }

    $base_url = $account_info[1];
    $total_pages = $pagination["pages"];
    $per_page = $pagination["per_page"];

    $urls = generate_discogs_urls($base_url, $total_pages, $per_page);

    // Add total_pages to the URLs array for reference
    $urls['total_pages'] = $total_pages;

    // Return URLs as an array
    return $urls;
}

function generate_discogs_urls($base_url, $total_pages, $per_page) {
    $urls = [];

    for ($page = 1; $page <= $total_pages; $page++) {
        $urls[] = esc_url(admin_url("admin.php?page=d2w_page&paged={$page}"));
    }

    return $urls;
}



// Function to display content for the menu page
function d2w_page_content() {
    echo '<div class="wrap">';
    echo '<h1>Welcome to Discogs to WooCommerce</h1>';

    // Fetch and process listings
    $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $products = return_listings($current_page);

    // Debug: Check if products are fetched
    if (empty($products)) {
        echo '<p>No products fetched.</p>';
        return;
    }

    // Create table instance
    $table = new Discogs_Product_List_Table();
    // Prepare table items
    $table->prepare_items();

    // Display top of the table nav / bulk actions
    echo '<form method="post" action="">';

    // Render Discogs listings table
    echo '<div class="wrap">';
    echo '<h2 class="wp-heading-inline">Product Listings</h2>';
    // Render issue here
    $table->display();
    echo '</div>';
    echo '</form>';
    /* End of listings */

    /* Start of pagination */
    // Capture returned pagination URLs
    $pagination_urls = return_pagination($current_page);

    // Output pagination buttons HTML
    echo '<div class="pagination-buttons">';

    // Display First link if available
    if ($current_page > 1) {
        echo '<a href="' . esc_url(admin_url("admin.php?page=d2w_page&paged=1")) . '" class="pagination-button">First</a>';
    }

    // Display Previous link if available
    if ($current_page > 1) {
        echo '<a href="' . esc_url(admin_url("admin.php?page=d2w_page&paged=" . ($current_page - 1))) . '" class="pagination-button">Previous</a>';
    }

    // Display Next link if available
    if ($current_page < $pagination_urls['total_pages']) {
        echo '<a href="' . esc_url(admin_url("admin.php?page=d2w_page&paged=" . ($current_page + 1))) . '" class="pagination-button">Next</a>';
    }

    // Display Last link if available
    if ($current_page < $pagination_urls['total_pages']) {
        echo '<a href="' . esc_url(admin_url("admin.php?page=d2w_page&paged=" . $pagination_urls['total_pages'])) . '" class="pagination-button">Last</a>';
    }

    echo '</div>';
    /* End of pagination */

    echo '</div>';
}

// Callback function to display content for the import results page
function d2w_import_results_page_content() {
    // Ensure the session is started
    if (!session_id()) {
        session_start();
    }

    echo '<div class="wrap">';
    echo '<h1>Import Results</h1>';

    // Fetch and display result messages
    if (isset($_SESSION['discogs_result_messages']) && !empty($_SESSION['discogs_result_messages'])) {
        $result_messages = $_SESSION['discogs_result_messages'];

        foreach ($result_messages as $message) {
            echo "<p>" . esc_html($message) . "</p>";
        }

        // Clear the result messages from the session after displaying
        unset($_SESSION['discogs_result_messages']);
    } else {
        echo '<p>No import results to display.</p>';
    }

    // Add a link back to the main plugin page
    $plugin_page_url = admin_url('admin.php?page=d2w_page');
    echo '<p><a href="' . esc_url($plugin_page_url) . '">Back to Product List</a></p>';
    echo '</div>';
}