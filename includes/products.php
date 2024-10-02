<?php

// Add actions
add_action('process_bulk_action', 'handle_bulk_action');
add_action('admin_post_process_bulk_action', 'register_discogs_bulk_action');
add_action('admin_init', 'register_discogs_bulk_action');

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