<?php

defined('ABSPATH') || exit;

/**
 * Discogs to WooCommerce commands.
 */
class D2W_Sync_CLI {

    /**
     * Syncs stock (and optionally prices and new listings) from Discogs.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would change without changing anything.
     *
     * ## EXAMPLES
     *
     *     wp d2w sync
     *     wp d2w sync --dry-run
     *
     * @when after_wp_load
     */
    public function sync($args, $assoc_args) {
        $dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);

        WP_CLI::log($dry_run ? 'Dry run: nothing will be changed.' : 'Running sync...');

        $report = D2W_Discogs_Sync::run_sync($dry_run, 'cli');

        if ($report['changes']) {
            \WP_CLI\Utils\format_items('table', array_map(static fn($c) => [
                'change'     => $c['type'],
                'product'    => $c['product_id'] ?: '-',
                'listing'    => $c['listing_id'],
                'name'       => $c['name'],
                'detail'     => $c['detail'],
            ], $report['changes']), ['change', 'product', 'listing', 'name', 'detail']);
        } else {
            WP_CLI::log('No changes.');
        }

        foreach ($report['messages'] as $message) {
            WP_CLI::log($message);
        }

        $summary = implode(', ', array_map(
            static fn($k, $v) => "{$k} {$v}",
            array_keys($report['counts']),
            $report['counts']
        ));

        if ($report['status'] === 'ok') {
            WP_CLI::success(($dry_run ? 'Would apply: ' : 'Done: ') . $summary);
        } else {
            WP_CLI::error(ucfirst($report['status']) . ': ' . $summary);
        }
    }
}
