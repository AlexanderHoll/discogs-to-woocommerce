<?php

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