# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.2.0] - 2026-10-26

### Added
- **Link & Backfill** admin screen: auto-links products that carry a CedCommerce Discogs listing ID, suggests artist/title matches against the For Sale inventory for confirmation, and shows linking and backfill status.
- Release details from the Discogs release endpoint saved as `_d2w_artist`, `_d2w_title`, `_d2w_label`, `_d2w_catno`, `_d2w_country`, `_d2w_genres`, `_d2w_styles` and `_d2w_tracklist`, fetched after each import and by the backfill.
- `pa_format`, `pa_media_condition` and `pa_sleeve_condition` global attributes (created if missing, grades ordered M to P), set on import, link and backfill, and kept up to date by sync.
- Existing `pa_label` and `pa_cat_no` attributes are filled from the release when a product has none.
- Background backfill through Action Scheduler, 10 products a minute.
- Fix for products that only have a display price: their regular price is set from it.
- WP-CLI: `wp d2w link [--auto] [--dry-run]`, `wp d2w backfill [--limit] [--force] [--dry-run]`, `wp d2w fix-prices [--dry-run]`.
- README section "Data written by D2W" documenting every meta key and attribute.

### Changed
- Listing format is read from the embedded release, so `format` is no longer empty for imports.
- Sync reports a "Details" change when a listing's format or condition attributes need updating.

## [1.1.0] - 2026-10-26

### Added
- Field Mapping screen (restored from the unmerged `field-mapping` branch): drag and drop mapping of Discogs fields to product fields, a title template with click-to-insert tokens and custom meta rows. The field list can be extended with the `d2w_discogs_fields` filter.
- `_discogs_release_id` and `_discogs_last_synced` meta on imported products.
- `uninstall.php` that removes options, transients and the sync cron event.
- Logging through the WooCommerce logger (source `d2w`).
- Admin notice when WooCommerce is not active; the plugin does not boot without it.
- GitHub Actions: PHP lint on 8.0 and 8.3, SFTP deploy to live on push to `main`, and release zips on `v*` tags.
- Sync settings: restock relisted items (default on), sync prices (default off), auto-import new listings as drafts (default off, 10 per run).
- Optional website to Discogs sync (default off): when an order reaches Processing or Completed, imported items' Discogs listings are set to Draft via Action Scheduler, with an order note per item. Runs once per order; listings are never deleted.
- Website sales flag products so sync does not restock them while their Discogs listing is still For Sale.
- New listings report on the settings page and an "Unimported only" filter on the import screen.
- Sync log of the last 20 runs, a "Preview Sync" dry run button and `wp d2w sync [--dry-run]`.
- Sync lock, and a guard that aborts if most in-stock products are missing from Discogs at once (`d2w_sync_max_missing_ratio`).
- HPOS compatibility declaration.

### Changed
- Sync fetches the whole For Sale inventory (100 listings per request) and diffs it against imported products, instead of one request per product. Nothing is changed unless every page loads; missing listings are double-checked before a product is marked sold.
- Importing a listing that is not on the cached inventory page now fetches it from Discogs instead of failing.
- Products are created with the WooCommerce product API, so regular price, stock and the product lookup tables are set correctly.
- Already imported listings are skipped on re-import and reported as skipped.
- Imported images are named `artist-title-N.jpg`.
- All Discogs requests go through one method that always sends a User-Agent, backs off when the rate limit is low and retries once on HTTP 429.
- API key and secret are sent in the Authorization header instead of the URL.
- Token and secret fields are password inputs; the sync interval is whitelisted.
- Assets only load on D2W screens and are versioned with `D2W_VERSION`.
- README rewritten.

### Fixed
- Fatal error on activation caused by the missing field mapping files.
- Bulk import ran on every `admin_init` with no nonce or capability check.
- Bulk actions from the bottom dropdown were ignored.
- PHP 8.1+ deprecation notices from the hidden results page.

### Removed
- `account_info` from inventory responses (it could expose the API secret).
