<?php

class D2W_Discogs_API {

    private static $cache = [];

    private static function auth_args() {
        $token = get_option('d2w_user_token');
        if (!$token) {
            return [];
        }
        return [
            'headers' => [
                'Authorization' => 'Discogs token=' . $token,
                'User-Agent'    => 'DiscogsToWooCommerce/1.0',
            ],
        ];
    }

    public static function fetch($page = 1) {
        if (isset(self::$cache[$page])) {
            return self::$cache[$page];
        }

        $user   = get_option('d2w_discogs_username');
        $key    = get_option('d2w_api_key');
        $secret = get_option('d2w_api_secret');
        $token  = get_option('d2w_user_token');

        // Use key/secret query params only when no token is set (token auth takes precedence
        // and raises the rate limit from 25 to 60 req/min).
        $params = "page={$page}";
        if (!$token && ($key || $secret)) {
            $params .= "&key={$key}&secret={$secret}";
        }

        $url      = "https://api.discogs.com/users/{$user}/inventory?{$params}";
        $response = wp_remote_get($url, self::auth_args());

        if (is_wp_error($response)) {
            return null;
        }

        $data                 = json_decode(wp_remote_retrieve_body($response), true);
        $data['account_info'] = [$user, $url];

        self::$cache[$page] = $data;
        return $data;
    }

    /**
     * Returns the status string for a single marketplace listing, or null on failure.
     * Requires a User Token to see non-"For Sale" statuses (Sold, Expired, Draft).
     */
    public static function fetch_listing_status($listing_id) {
        $url      = "https://api.discogs.com/marketplace/listings/{$listing_id}";
        $response = wp_remote_get($url, self::auth_args());

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 404) {
            // Listing removed or never existed — treat as sold/unavailable.
            return 'Not Found';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['status'] ?? null;
    }
}