<?php

class D2W_Discogs_API {

    private static $cache = [];

    public static function fetch($page = 1) {
        if (isset(self::$cache[$page])) {
            return self::$cache[$page];
        }

        $user   = get_option('d2w_discogs_username');
        $key    = get_option('d2w_api_key');
        $secret = get_option('d2w_api_secret');

        if ($key || $secret) {
            $url = "https://api.discogs.com/users/{$user}/inventory?page={$page}&key={$key}&secret={$secret}";
        } else {
            $url = "https://api.discogs.com/users/{$user}/inventory?page={$page}";
        }

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return null;
        }

        $data                 = json_decode(wp_remote_retrieve_body($response), true);
        $data['account_info'] = [$user, $url];

        self::$cache[$page] = $data;

        return $data;
    }
}