<?php

class D2W_Product_Mapper {

    public static function map_listings($page = 1) {
        $data     = D2W_Discogs_API::fetch($page);
        $products = [];

        if (!isset($data['listings']) || !is_array($data['listings'])) {
            return $products;
        }

        foreach ($data['listings'] as $listing) {
            $images = $listing['release']['images'] ?? [];

            $products[] = [
                'id'          => $listing['id'] ?? '',
                'images'      => $images,
                'image_main'  => $images[0]['uri'] ?? '',
                'image_thumb' => $images[0]['uri150'] ?? '',
                'artist'      => self::clean_artist_name($listing['release']['artist'] ?? ''),
                'title'       => $listing['release']['title'] ?? '',
                'comments'    => $listing['comments'] ?? '',
                'description' => self::clean_artist_name($listing['release']['description'] ?? ''),
                'value'       => $listing['price']['value'] ?? 0.0,
            ];
        }

        return $products;
    }

    public static function clean_artist_name($text) {
        return preg_replace('/\(\d+\)/', '', $text);
    }
}