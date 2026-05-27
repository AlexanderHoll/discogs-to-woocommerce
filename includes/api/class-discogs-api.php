<?php

class D2W_Discogs_API {

    private static $cache = [];

    public static function fetch($page = 1, $args = []) {
        $cache_key = $page . '_' . md5(serialize($args));

        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        $user   = get_option('d2w_discogs_username');
        $key    = get_option('d2w_api_key');
        $secret = get_option('d2w_api_secret');

        $params = ['page' => $page, 'per_page' => $args['per_page'] ?? 50];

        if ($key || $secret) {
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
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return null;
        }

        $data                 = json_decode(wp_remote_retrieve_body($response), true);
        $data['account_info'] = [$user, $url];

        self::$cache[$cache_key] = $data;

        return $data;
    }
}
