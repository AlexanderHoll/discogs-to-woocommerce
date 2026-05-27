<?php

class D2W_Discogs_API {

    private static $cache = [];

    private static function auth_args(): array {
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

    public static function fetch($page = 1, $args = []) {
        $cache_key = $page . '_' . md5(serialize($args));

        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        $user   = get_option('d2w_discogs_username');
        $key    = get_option('d2w_api_key');
        $secret = get_option('d2w_api_secret');
        $token  = get_option('d2w_user_token');

        $params = ['page' => $page, 'per_page' => $args['per_page'] ?? 50];

        // Key/secret only used when no token is set — token auth gives 60 req/min vs 25.
        if (!$token && ($key || $secret)) {
            $params['key']    = $key;
            $params['secret'] = $secret;
        }
        if (!empty($args['sort'])) {
            $params['sort'] = $args['sort'];
        }
        if (!empty($args['sort_order'])) {
            $params['sort_order'] = $args['sort_order'];
        }
        if (!empty($args['status'])) {
            $params['status'] = $args['status'];
        }

        $url      = "https://api.discogs.com/users/{$user}/inventory?" . http_build_query($params);
        $response = wp_remote_get($url, self::auth_args());

        if (is_wp_error($response)) {
            return null;
        }

        $data                 = json_decode(wp_remote_retrieve_body($response), true);
        $data['account_info'] = [$user, $url];

        self::$cache[$cache_key] = $data;

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
            return 'Not Found';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['status'] ?? null;
    }
}
