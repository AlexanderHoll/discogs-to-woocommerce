<?php

// Include WP_List_Table class
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

// Function to remove "(NUMBER)"
function clean_artist_name($text) {
    // Remove numbers in brackets using regular expression
    $cleaned_text = preg_replace('/\(\d+\)/', '', $text);

    return $cleaned_text;
}