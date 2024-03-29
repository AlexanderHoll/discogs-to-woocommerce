<?php
/*
Plugin Name:  Discogs to Woocommerce
Plugin URI:   https://www.alexanderhollingworth.co.uk
Description:  A plugin that intends to fetch a Discogs seller inventory and allow to import these products as Woocommerce products
Version:      1.0
Author:       Alexander Hollingworth
Author URI:   https://www.alexanderhollingworth.co.uk
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  discogs-to-woocommerce
Domain Path:  /
*/



// Define the callback function for the menu page
function d2w_settings_page() {
    ?>
    <div class="wrap">
        <h2>Discogs to WooCommerce Settings</h2>
        <form method="post" action="options.php">
            <?php
            // Output nonce, action, and option_page fields for a settings page
            settings_fields('d2w_options');
            // Output the settings sections and their fields
            do_settings_sections('d2w-settings');
            // Output submit button
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Update the d2w_settings_init function
function d2w_settings_init() {
    // Register a setting and its sanitization callback for API Key
    register_setting('d2w_options', 'd2w_api_key', 'd2w_sanitize_text_field');
    // Register a setting and its sanitization callback for API Secret
    register_setting('d2w_options', 'd2w_api_secret', 'd2w_sanitize_text_field');
    // Register a setting and its sanitization callback for Discogs Username
    register_setting('d2w_options', 'd2w_discogs_username', 'd2w_sanitize_text_field');

    // Add a section to the settings page
    add_settings_section(
        'd2w_section',
        'API Settings',
        'd2w_section_callback',
        'd2w-settings'
    );

    // Add fields to the section
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

    // Add a new field for Discogs Username
    add_settings_field(
        'd2w_discogs_username',
        'Discogs Username',
        'd2w_discogs_username_callback',
        'd2w-settings',
        'd2w_section'
    );
}

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

// Add actions
add_action('process_bulk_action', 'handle_bulk_action');
add_action('admin_post_process_bulk_action', 'register_discogs_bulk_action');
add_action('admin_init', 'register_discogs_bulk_action');
add_action('admin_menu', 'd2w_menu');
add_action('admin_init', 'd2w_settings_init');

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

// resolve image from Discogs URL
function d2w_insert_product_image($full_scale_image_url, $product_id) {
    // Download the full-scale image to the uploads directory
    $full_scale_image_path = download_url($full_scale_image_url);

    // Check for download errors
    if (is_wp_error($full_scale_image_path)) {
        // Handle error (e.g., log error, display message)
        return false;
    }

    $file_array = array(
        'name'     => sanitize_file_name(basename($full_scale_image_url)),
        'tmp_name' => $full_scale_image_path,
    );

    // Insert the full-scale image into the media library
    $full_scale_image_id = media_handle_sideload($file_array, $product_id, $file_array['name']);

    // Check for media handle sideload errors
    if (is_wp_error($full_scale_image_id)) {
        // Handle error (e.g., log error, display message)
        return false;
    }

    // Set the full-scale image as the product thumbnail
    set_post_thumbnail($product_id, $full_scale_image_id);

    return $full_scale_image_id;
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

                // Establish the product data
                $new_product_data = array(
                    'name'              => $selected_product['artist'] . ' - ' . $selected_product['title'],
                    'regular_price'     => $selected_product['value'],
                    'description'       => $selected_product['description'],
                    'short_description' => $selected_product['comments'],
                    'status'            => $draft ? 'draft' : 'publish',
                );

                // Insert the product into the posts table
                $product_id = wp_insert_post(array(
                    'post_title'   => $new_product_data['name'],
                    'post_content' => $new_product_data['description'],
                    'post_excerpt' => $new_product_data['short_description'],
                    'post_status'  => $new_product_data['status'],
                    'post_type'    => 'product',
                ));

                // Add product meta data
                if ($product_id) {
                    update_post_meta($product_id, '_price', $new_product_data['regular_price']);
                    update_post_meta($product_id, '_manage_stock', 'yes'); // Enable stock management
                    update_post_meta($product_id, '_stock_status', 'instock'); // Set stock status to in stock
                    update_post_meta($product_id, '_stock', 1); // Set initial stock quantity to 1

                    // Add more meta data as needed

                    $image_url_full_scale = $selected_product['images'][0]['uri'];  // Assuming the full-scale image URL is the first in the images array
                    $image_id = d2w_insert_product_image($image_url_full_scale, $product_id);

                    // Set the product thumbnail
                    set_post_thumbnail($product_id, $image_id);
                }

                // Set product type to 'simple'
                wp_set_object_terms($product_id, 'simple', 'product_type');

                if ($product_id) {
                    // Store the result message
                    $result_messages[] = "Product '{$selected_product['title']}' created successfully with ID: {$product_id} and status set to " . ($draft ? 'draft' : 'publish') . ".";
                } else {
                    // Store the result message
                    $result_messages[] = "Failed to create product '{$selected_product['title']}'.";
                }
            } else {
                // Store the result message
                $result_messages[] = "Product with ID $product_id not found!";
            }
        }

        // Store result messages in the session
        $_SESSION['discogs_result_messages'] = $result_messages;

        // Redirect to the import results page
        wp_redirect(admin_url('admin.php?page=d2w_import_results_page'));
        exit;
    } else {
        print_r("ERROR - POST variable is empty!");
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

// Add submenu page
add_action('admin_menu', 'd2w_submenu_page');
function d2w_submenu_page() {
    add_submenu_page(
        null,                      // Parent slug
        'Import Results',                // Page title
        'Import Results',                // Menu title
        'manage_options',                // Capability
        'd2w_import_results_page',       // Menu slug
        'd2w_import_results_page_content' // Callback function to display content
    );

    add_submenu_page(
        'd2w_page',                    // Parent slug
        'D2W Settings',                // Page title
        'Settings',                    // Menu title
        'manage_options',              // Capability
        'd2w-settings',                // Menu slug
        'd2w_settings_page'           // Callback function to display content
    );
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


// make api call, fetch data from discogs
function fetch_discogs($page = 1) {
    // variables
    $discogs_user = get_option('d2w_discogs_username');
    $discogs_key = get_option('d2w_api_key');
    $discogs_secret = get_option('d2w_api_secret');
    
    if ($discogs_key || $discogs_secret) {
        $api_url = "https://api.discogs.com/users/{$discogs_user}/inventory?page={$page}&key={$discogs_key}&secret={$discogs_secret}";
    } else {
        $api_url = "https://api.discogs.com/users/{$discogs_user}/inventory?page={$page}";
    }
    

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
            'thumbnail' => 'Thumbnail',
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
        $this->items = return_listings($current_page);
    
        // Store products_data in a session variable to be fetched when inserting products
        if (!session_id()) {
            session_start();
        }
        $_SESSION['discogs_products_data'] = $this->items;
    
        // Prepare column headers
        $this->_column_headers = [$this->get_columns(), [], []];
    
        // Process bulk action
        $this->process_bulk_action();
    
        // Handle bulk actions if any
        if (isset($_POST['product']) && !empty($_POST['product'])) {
            do_action('process_bulk_action', $_POST['product']);
        }
    }
    
    public function column_thumbnail($item) {
        $thumbnail_url = isset($item['image_thumb']) ? $item['image_thumb'] : '';
    
        if (!empty($thumbnail_url)) {
            return '<img src="' . esc_url($thumbnail_url) . '" alt="Thumbnail" width="50" height="50" />';
        }
    
        return '';  // or handle appropriately if thumbnail URL doesn't exist
    }
    
    
    // define what each column in the table displays
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'thumbnail':
                return $this->column_thumbnail($item); // Call the new function for the thumbnail column
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

    // Initialise an empty array to store products
    $products = [];

    // Check if 'listings' key exists in $data and if it's an array
    if (isset($data['listings']) && is_array($data['listings'])) {
        
        // Iterate through each listing in $data['listings']
        foreach ($data['listings'] as $listing) {

            // Initialise an empty array to store product images per listing
            $images = [];

            // Iterate through each listing's images and add to images array
            foreach ($listing['release']['images'] as $image) {
                $images[] = $image;
                $full_scale = isset($image['uri']) ? $image['uri'] : '';
                $thumbnail = isset($image['uri150']) ? $image['uri150'] : '';
            }
         
            // Ensure the necessary nested keys exist and provide defaults if they don't
            $id = isset($listing['id']) ? $listing['id'] : '';
            $image_main = isset($images[0]['uri']) ? $images[0]['uri'] : '';
            $image_thumb = isset($images[0]['uri150']) ? $images[0]['uri150'] : '';
            $artist = isset($listing['release']['artist']) ? clean_artist_name($listing['release']['artist']) : '';
            $title = isset($listing['release']['title']) ? $listing['release']['title'] : '';
            $comments = isset($listing['comments']) ? $listing['comments'] : '';
            $description = isset($listing['release']['description']) ? clean_artist_name($listing['release']['description']) : '';
            $value = isset($listing['price']['value']) ? $listing['price']['value'] : 0.0;

            // Construct the product array with the values (either from the listing or defaults)
            $product = [
                'id' => $id,
                'images' => $images,
                'image_main' => $image_main,
                'image_thumb' => $image_thumb,
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

    // MAKE THIS DYNAMIC - FETCH FROM DATA
    // $per_page = 50;

    // update this so we don't fetch ALL products at once, only ones need on the page
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