# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] - Unreleased

### Added
- Field Mapping screen (restored from the unmerged `field-mapping` branch): drag and drop mapping of Discogs fields to product fields, a title template with click-to-insert tokens and custom meta rows. The field list can be extended with the `d2w_discogs_fields` filter.
- `_discogs_release_id` and `_discogs_last_synced` meta on imported products.
- `uninstall.php` that removes options, transients and the sync cron event.
- Logging through the WooCommerce logger (source `d2w`).
- Admin notice when WooCommerce is not active; the plugin does not boot without it.
- GitHub Actions: PHP lint on 8.0 and 8.3, SFTP deploy to live on push to `main`, and release zips on `v*` tags.

### Changed
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
