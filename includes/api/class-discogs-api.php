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
        return 'DeckheadsD2W/' . D2W_VERSION . ' +https://deckheads-records.co.uk';
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
