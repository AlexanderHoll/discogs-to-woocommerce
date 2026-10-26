<?php

defined('ABSPATH') || exit;

// Thin wrapper around the WooCommerce logger. Entries appear under
// WooCommerce > Status > Logs with the source "d2w".
class D2W_Logger {

    const SOURCE = 'd2w';

    public static function log(string $level, string $message, array $context = []): void {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        wc_get_logger()->log($level, $message, array_merge($context, ['source' => self::SOURCE]));
    }

    public static function info(string $message, array $context = []): void {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('error', $message, $context);
    }
}
