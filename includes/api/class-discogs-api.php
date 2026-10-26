<?php

defined('ABSPATH') || exit;

class D2W_Discogs_API {

    const BASE_URL = 'https://api.discogs.com';

    // Pause before the next request when the rate limit window is nearly used up.
    const RATELIMIT_LOW = 3;

    private static $cache = [];

    // Last X-Discogs-Ratelimit-Remaining seen in this request, null until the first call.
    private static ?int $remaining = null;

    private static function user_agent(): string {
        // Discogs asks for a unique, identifying User-Agent; the site URL tells them who is calling.
        return 'DiscogsToWooCommerce/' . D2W_VERSION . ' +' . home_url('/');
    }

    // Token auth when set (60 req/min), otherwise key/secret (25 req/min), otherwise anonymous.
    private static function auth_header(): ?string {
        $token = get_option('d2w_user_token');
        if ($token) {
            return 'Discogs token=' . $token;
        }
        $key    = get_option('d2w_api_key');
        $secret = get_option('d2w_api_secret');
        if ($key && $secret) {
            return 'Discogs key=' . $key . ', secret=' . $secret;
        }
        return null;
    }

    /**
     * Single entry point for every Discogs request. Adds auth and User-Agent, backs off
     * when the rate limit is nearly exhausted, and retries once on 429.
     *
     * @return array|WP_Error The wp_remote_request() response.
     */
    private static function request(string $url, array $args = []) {
        $args = wp_parse_args($args, ['method' => 'GET', 'timeout' => 20]);
        $args['headers'] = array_merge(
            ['User-Agent' => self::user_agent(), 'Accept' => 'application/json'],
            $args['headers'] ?? []
        );
        $auth = self::auth_header();
        if ($auth) {
            $args['headers']['Authorization'] = $auth;
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if (self::$remaining !== null && self::$remaining <= self::RATELIMIT_LOW) {
                // Discogs uses a 60 second moving window; a short pause lets it refill.
                sleep(self::RATELIMIT_LOW - self::$remaining + 2);
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                D2W_Logger::error('Request failed: ' . $response->get_error_message(), ['url' => $url]);
                return $response;
            }

            $remaining = wp_remote_retrieve_header($response, 'x-discogs-ratelimit-remaining');
            self::$remaining = $remaining === '' ? null : (int) $remaining;

            if ((int) wp_remote_retrieve_response_code($response) !== 429 || $attempt === 2) {
                return $response;
            }

            $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
            $wait        = min(max($retry_after, 5), 60);
            D2W_Logger::warning("Rate limited by Discogs, retrying in {$wait}s.", ['url' => $url]);
            sleep($wait);
        }

        return $response;
    }

    // Decodes a 200 JSON response, logging and returning null for anything else.
    private static function decode($response, string $context): ?array {
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            D2W_Logger::error("Unexpected HTTP {$code} for {$context}.", ['body' => substr(wp_remote_retrieve_body($response), 0, 300)]);
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            D2W_Logger::error("Could not parse response for {$context}.");
            return null;
        }
        return $body;
    }

    private static function inventory_url(array $params): string {
        $user = (string) get_option('d2w_discogs_username');
        return self::BASE_URL . '/users/' . rawurlencode($user) . '/inventory?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * One page of the seller inventory, or null on failure. Cached for the rest of the request.
     */
    public static function fetch($page = 1, $args = []) {
        $cache_key = $page . '_' . md5(serialize($args));

        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        if (!get_option('d2w_discogs_username')) {
            return null;
        }

        $params = ['page' => (int) $page, 'per_page' => (int) ($args['per_page'] ?? 50)];
        foreach (['sort', 'sort_order', 'status'] as $key) {
            if (!empty($args[$key])) {
                $params[$key] = $args[$key];
            }
        }

        $data = self::decode(self::request(self::inventory_url($params)), "inventory page {$page}");
        if ($data !== null) {
            self::$cache[$cache_key] = $data;
        }

        return $data;
    }

    /**
     * Every For Sale listing (raw API arrays) keyed by listing ID, paging 100 at a time.
     * Returns null if any page fails, so callers never act on a partial inventory.
     */
    public static function fetch_inventory_for_sale(string $sort = 'listed', string $sort_order = 'asc'): ?array {
        if (!get_option('d2w_discogs_username')) {
            D2W_Logger::error('Inventory fetch skipped: no Discogs username set.');
            return null;
        }

        $listings = [];
        $page     = 1;
        $pages    = 1;

        do {
            $data = self::decode(self::request(self::inventory_url([
                'status'     => 'For Sale',
                'per_page'   => 100,
                'page'       => $page,
                'sort'       => $sort,
                'sort_order' => $sort_order,
            ])), "inventory page {$page}");

            if ($data === null || !isset($data['listings']) || !is_array($data['listings'])) {
                D2W_Logger::error("Inventory fetch aborted at page {$page} of {$pages}.");
                return null;
            }

            foreach ($data['listings'] as $listing) {
                if (isset($listing['id'])) {
                    $listings[(int) $listing['id']] = $listing;
                }
            }

            $pages = max(1, (int) ($data['pagination']['pages'] ?? 1));
            $page++;
        } while ($page <= $pages && $page <= 500);

        return $listings;
    }

    // Release (label, country, genres, styles, tracklist, formats), or null on failure.
    public static function fetch_release($release_id): ?array {
        $url = self::BASE_URL . '/releases/' . rawurlencode((string) $release_id);
        return self::decode(self::request($url), "release {$release_id}");
    }

    // Full marketplace listing, or null on failure.
    public static function fetch_listing($listing_id): ?array {
        $url = self::BASE_URL . '/marketplace/listings/' . rawurlencode((string) $listing_id);
        return self::decode(self::request($url), "listing {$listing_id}");
    }

    /**
     * Edits a marketplace listing. Discogs requires release_id, condition and price on
     * every edit, so callers should start from fetch_listing() values.
     *
     * @return true|WP_Error
     */
    public static function update_listing($listing_id, array $fields) {
        $url      = self::BASE_URL . '/marketplace/listings/' . rawurlencode((string) $listing_id);
        $response = self::request($url, [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($fields),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $body    = json_decode(wp_remote_retrieve_body($response), true);
            $message = is_array($body) && isset($body['message']) ? $body['message'] : "HTTP {$code}";
            D2W_Logger::error("Updating listing {$listing_id} failed: {$message}");
            return new WP_Error('d2w_discogs_update_failed', $message);
        }

        return true;
    }

    /**
     * Returns the status string for a single marketplace listing, or null on failure.
     * Requires a User Token to see non-"For Sale" statuses (Sold, Expired, Draft).
     */
    public static function fetch_listing_status($listing_id) {
        $url      = self::BASE_URL . '/marketplace/listings/' . rawurlencode((string) $listing_id);
        $response = self::request($url);

        if (is_wp_error($response)) {
            return null;
        }

        if ((int) wp_remote_retrieve_response_code($response) === 404) {
            return 'Not Found';
        }

        $body = self::decode($response, "listing {$listing_id}");

        return $body['status'] ?? null;
    }
}
