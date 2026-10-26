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

    /**
     * Links existing products to Discogs listings.
     *
     * Without --auto, lists suggested matches (nothing is linked: confirm them in
     * WP Admin > Discogs to WooCommerce > Link & Backfill).
     *
     * ## OPTIONS
     *
     * [--auto]
     * : Link products that carry a CedCommerce listing ID.
     *
     * [--dry-run]
     * : With --auto, show what would be linked without linking.
     *
     * @when after_wp_load
     */
    public function link($args, $assoc_args) {
        $dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);

        if (\WP_CLI\Utils\get_flag_value($assoc_args, 'auto', false)) {
            $r = D2W_Product_Linker::auto_link($dry_run);
            foreach ($r['missing'] as $m) {
                WP_CLI::log("Listing {$m['listing_id']} no longer exists (product #{$m['product_id']} {$m['name']}).");
            }
            foreach (array_merge($r['skipped'], $r['errors']) as $m) {
                WP_CLI::warning("Product #{$m['product_id']}: {$m['reason']}");
            }
            WP_CLI::success(sprintf(
                '%s %d, missing %d, skipped %d, errors %d.',
                $dry_run ? 'Would link' : 'Linked',
                count($r['linked']), count($r['missing']), count($r['skipped']), count($r['errors'])
            ));
            return;
        }

        $proposals = D2W_Product_Linker::proposals();
        if (!$proposals) {
            WP_CLI::log('No suggested matches.');
            return;
        }
        \WP_CLI\Utils\format_items('table', array_map(static fn($p) => [
            'product'    => $p['product_id'],
            'name'       => $p['name'],
            'listing'    => $p['listing_id'],
            'discogs'    => trim(($p['listing']['release']['artist'] ?? '') . ' - ' . ($p['listing']['release']['title'] ?? '')),
            'confidence' => $p['score'] . '%',
        ], $proposals), ['product', 'name', 'listing', 'discogs', 'confidence']);
        WP_CLI::log('Confirm matches in WP Admin > Discogs to WooCommerce > Link & Backfill.');
    }

    /**
     * Fetches release details and sets format / condition attributes for linked products.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Process at most this many products.
     *
     * [--force]
     * : Include products that already have release details.
     *
     * [--dry-run]
     * : Show how many products would be processed.
     *
     * @when after_wp_load
     */
    public function backfill($args, $assoc_args) {
        $limit = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'limit', 0);
        $ids   = D2W_Backfill::pending_ids((bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'force', false), $limit);

        if (\WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false)) {
            WP_CLI::success(sprintf('Would process %d product(s).', count($ids)));
            return;
        }

        $totals = ['processed' => 0, 'release' => 0, 'attributes' => 0, 'failed' => 0];
        foreach (array_chunk($ids, D2W_Backfill::BATCH_SIZE) as $chunk) {
            foreach (D2W_Backfill::process($chunk) as $k => $v) {
                $totals[$k] += $v;
            }
            WP_CLI::log(sprintf('%d / %d', $totals['processed'], count($ids)));
        }
        WP_CLI::success(sprintf(
            'Processed %d: release details %d, attributes changed %d, failed %d.',
            $totals['processed'], $totals['release'], $totals['attributes'], $totals['failed']
        ));
    }

    /**
     * Sets the regular price from the display price where it is missing.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Count affected products without changing them.
     *
     * @subcommand fix-prices
     * @when after_wp_load
     */
    public function fix_prices($args, $assoc_args) {
        $dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
        $r = D2W_Backfill::fix_prices($dry_run);
        WP_CLI::success(sprintf('%s %d product(s).', $dry_run ? 'Would fix' : 'Fixed', $r['fixed']));
    }
}
