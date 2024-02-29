<?php
/*
Plugin Name:  Discogs to Woocommerce
Plugin URI:   https://www.alexanderhollingworth.co.uk
Description:  A plugin that intends to fetch the Deckheads seller inventory and allow to import these products as Woocommerce products
Version:      1.0
Author:       Alexander Hollingworth
Author URI:   https://www.alexanderhollingworth.co.uk
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  discogs-to-woocommerce
Domain Path:  /
*/

// Add actions
add_action('process_bulk_action', 'handle_bulk_action');
add_action('admin_post_process_bulk_action', 'register_discogs_bulk_action');
add_action('admin_init', 'register_discogs_bulk_action');
// Hook into the admin menu action
add_action('admin_menu', 'd2w_menu');

// Function to remove "(NUMBER)"
function clean_artist_name($text) {
    // Remove numbers in brackets using regular expression
    $cleaned_text = preg_replace('/\(\d+\)/', '', $text);

    return $cleaned_text;
}

function register_discogs_bulk_action() {
    // Check if 'insert_as_product' action is set in the request
    if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'insert_as_product') {
        $draft = 0;
 
        // Trigger the action
        do_action('process_bulk_action', $draft);
    }

    // Check if 'insert_as_product_d' action is set in the request
    if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'insert_as_product_d') {
        $draft = 1;

        // Trigger the action
        do_action('process_bulk_action', $draft);
    }
}

function handle_bulk_action($draft) {
    
    // Check if the form has been submitted and products are selected
    if (isset($_POST['product']) && !empty($_POST['product'])) {
        // Check if the session is started
        if (!session_id()) {
            session_start();
        }

        // Retrieve product details from the session
        $products_data = isset($_SESSION['discogs_products_data']) ? $_SESSION['discogs_products_data'] : [];

        // Initialize result messages array
        $result_messages = [];

        // Loop through each selected product ID to fetch its details and create a new WooCommerce product
        foreach ($_POST['product'] as $product_id) {
            // Find the product in the products_data array based on its ID
            $selected_product = array_filter($products_data, function ($product) use ($product_id) {
                return $product['id'] == $product_id;
        });

            if (!empty($selected_product)) {

                // Since array_filter returns an array, we pick the first (and only) item
                $selected_product = reset($selected_product);

                // Create a new WooCommerce product
                $new_product = new WC_Product();

                // Set product data
                $new_product->set_name($selected_product['artist'] . ' - ' . $selected_product['title']);
                $new_product->set_description($selected_product['description']);
                $new_product->set_regular_price($selected_product['value']);
                $new_product->set_short_description($selected_product['comments']);

                // Set the post status to 'draft'
                if ($draft) {
                    $new_product->set_status('draft');
                }
                
                // Save the product
                $new_product_id = $new_product->save();

                if ($new_product_id) {
                    // Store the result message
                    $result_messages[] = "Product '{$selected_product['title']}' created successfully with ID: {$new_product_id} and status set to draft.";
                } else {
                    // Store the result message
                    $result_messages[] = "Failed to create product '{$selected_product['title']}'.";
                }
            } else {

                // Store the result message
                $result_messages[] = "Product with ID $product_id not found!";
            }
        }

        // Display the result messages within the WordPress admin area
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Import Results</h1>

            <?php foreach ($result_messages as $message) : ?>
                <p><?php echo esc_html($message); ?></p>
            <?php endforeach; ?>

            <?php
            // Add a link back to the main plugin page
            $plugin_page_url = admin_url('admin.php?page=d2w_page'); // Replace 'd2w_page' with the actual slug of your plugin page
            echo '<p><a href="' . esc_url($plugin_page_url) . '">Back to Product List</a></p>';
            ?>
        </div>

        <?php

        exit;
    }

}


// Enqueue admin styles
function custom_enqueue_admin_styles() {
    wp_enqueue_style('wp-lists');
}
add_action('admin_enqueue_scripts', 'custom_enqueue_admin_styles');

// Include WP_List_Table class
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

function d2w_menu() {
    // Add a new top-level menu
    add_menu_page(
        'Discogs to WooCommerce',           // Page title
        'Discogs to WooCommerce',           // Menu title
        'manage_options',                   // Capability
        'd2w_page',                         // Menu slug
        'd2w_page_content',                 // Function to display content
        'dashicons-admin-generic',          // Icon (Optional)
        99                                  // Position (Optional)
    );
}

// make api call, fetch data from discogs
function fetch_discogs($page = 1) {
    // variables
    $discogs_user = "DeckHeadRecords";
    $api_url = "https://api.discogs.com/users/{$discogs_user}/inventory?page={$page}";

    $discogs_info["account_info"] = array($discogs_user, $api_url);

    // Fetch API data
    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        // Handle error (e.g., log error, display message)
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);  // Decode JSON data
    array_push($data, $discogs_info);

    return $data;
}

// create a custom class for the table
class Discogs_Product_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'discogs_product',   // singular name of the listed records
            'plural'   => 'discogs_products',  // plural name of the listed records
            'ajax'     => false                // does this table support ajax?
        ]);
    }
    
    // store product data fetched from Discogs
    private $products_data = [];

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'artist' => 'Artist',
            'title' => 'Title',
            'comments' => 'Comments',
            'description' => 'Description',
            'value' => 'Value (GBP)',
        ];
    }

    // Establish bulk actions for table
    public function get_bulk_actions() {
        $actions = [
            'insert_as_product' => 'Insert as Product (Published)',
            'insert_as_product_d' => 'Insert as Product (Draft)',
        ];
        return $actions;
    }

    public function prepare_items() {
        // Fetch and prepare items for the table
        $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $products_data = return_listings($current_page);
    
        // Store products_data in a session variable to be fetched when inserting products
        if (!session_id()) {
            session_start();
        }
        $_SESSION['discogs_products_data'] = $products_data;
    
        // Prepare column headers
        $this->_column_headers = [$this->get_columns(), [], []];
    
        // Fetch and prepare items for the table
        $this->items = $products_data;
    
        // Process bulk action
        $this->process_bulk_action();
    
        // Handle bulk actions if any
        if (isset($_POST['product']) && !empty($_POST['product'])) {
            do_action('process_bulk_action', $_POST['product']);
        }
    }
    
    
    // define what each column in the table displays
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'artist':
            case 'title':
            case 'comments':
            case 'description':
            case 'value':
                return $item[$column_name] ?? ''; // Return the value if it exists, otherwise return an empty string.
            default:
                return isset($item[$column_name]) ? $item[$column_name] : ''; // Return the value if it exists, otherwise return an empty string.
        }
    }
    
    // render checkbox for bulk actions
    public function column_cb($item) {
        
        if (isset($item['id'])) {
            return sprintf(
                '<input type="checkbox" name="product[]" value="%s" />', $item['id']
            );
        }
        return '';  // or handle appropriately if not an ID doesn't exist
    }

    // render bulk actions form
    public function bulk_actions_form() {
        echo '<select name="action">';
        echo '<option value="-1">Bulk Actions</option>';
        foreach ($this->get_bulk_actions() as $value => $label) {
            echo "<option value='$value'>$label</option>";
        }
        echo '</select>';
        echo '<input type="submit" name="" id="doaction" class="button action" value="Apply">';
    }
}

function return_listings($page = 1) {
    $data = fetch_discogs($page);

    // Initialize an empty array to store products
    $products = [];

    // Check if 'listings' key exists in $data and if it's an array
    if (isset($data['listings']) && is_array($data['listings'])) {
        
        // Iterate through each listing in $data['listings']
        foreach ($data['listings'] as $listing) {
            // Ensure the necessary nested keys exist and provide defaults if they don't
            $id = isset($listing['id']) ? $listing['id'] : '';
            $artist = isset($listing['release']['artist']) ? clean_artist_name($listing['release']['artist']) : '';
            $title = isset($listing['release']['title']) ? $listing['release']['title'] : '';
            $comments = isset($listing['comments']) ? $listing['comments'] : '';
            $description = isset($listing['release']['description']) ? clean_artist_name($listing['release']['description']) : '';
            $value = isset($listing['price']['value']) ? $listing['price']['value'] : 0.0;

            // Construct the product array with the values (either from the listing or defaults)
            $product = [
                'id' => $id,
                'artist' => $artist,
                'title' => $title,
                'comments' => $comments,
                'description' => $description,
                'value' => $value
            ];

            // Add the product to the $products array
            $products[] = $product;
        }
    }

    return $products;
}


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
    echo '<form method="get" action="">';

    // Render Discogs listings table
    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">Product Listings</h1>';
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





