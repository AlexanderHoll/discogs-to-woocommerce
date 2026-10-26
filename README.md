# Discogs to WooCommerce (D2W)

A WordPress plugin that imports a Discogs seller inventory into WooCommerce and keeps stock in sync. It is aimed at record shops that sell on both Discogs and their own website, and treats Discogs as the source of truth for stock.

## What it does

- Lists your Discogs `For Sale` inventory (plus Sold and Draft) in WP Admin with covers, conditions, prices and an "Imported" badge.
- Imports selected listings as WooCommerce simple products (published or draft), including the cover as the featured image and the remaining release images as a gallery.
- Lets you choose which Discogs fields go where via a drag and drop **Field Mapping** screen, including a title template such as `{artist} - {title}` and any number of custom meta fields.
- Runs a scheduled **sync** that marks products out of stock when they sell on Discogs, and can optionally restock relisted items, update prices, auto-import new listings and push website sales back to Discogs.

## Requirements

- WordPress 6.4 or later
- WooCommerce 8.0 or later
- PHP 8.0 or later
- A Discogs account with a seller inventory

## Setup

1. Install the plugin (see [Releasing](#releasing) for a zip, or deploy from this repo) and activate it. WooCommerce must be active first.
2. Create a **Personal Access Token** at [discogs.com/settings/developers](https://www.discogs.com/settings/developers) ("Generate new token").
3. Go to **Discogs to WooCommerce > Settings** and enter your Discogs username and the token. Save.

An API key and secret can be used instead of a token for browsing only (25 requests per minute instead of 60), but sync needs the token.

## Importing

1. Open **Discogs to WooCommerce**. The table shows one page of your inventory, with sort, status, per page and an instant filter for the current page.
2. Tick the listings you want and choose **Insert as Product (Published)** or **Insert as Product (Draft)** from either bulk action dropdown.
3. The results page lists what was created and what was skipped. Listings that are already imported are always skipped, so it is safe to re-run.

Each product is created through the WooCommerce product API with a regular price, SKU (if mapped), managed stock of 1 and these meta fields:

| Meta key | Purpose |
| --- | --- |
| `_discogs_listing_id` | Links the product to its Discogs listing. Used by sync. |
| `_discogs_release_id` | The Discogs release, needed when updating a listing on Discogs. |
| `_discogs_last_synced` | Unix timestamp of the last time sync checked this product. |

Images are saved to the media library as `artist-title-1.jpg`, `artist-title-2.jpg` and so on.

## Field mapping

**Discogs to WooCommerce > Field Mapping** controls how listing data becomes product data. Changes only affect future imports.

- Drag a Discogs field onto a WooCommerce field, or click the x to clear it.
- **Product Name** can use a template. Click a token to insert it at the cursor. Unknown tokens are replaced with nothing.
- **Custom Meta Fields** save any Discogs field to any post meta key.
- **Reset to Defaults** restores the built-in mapping.

Available fields: `id`, `artist`, `title`, `comments`, `description`, `value`, `currency`, `media_condition`, `sleeve_condition`, `format`, `year`, `image_main`, `image_thumb`. Developers can add more with the `d2w_discogs_fields` filter:

```php
add_filter('d2w_discogs_fields', function (array $fields) {
    $fields['label'] = 'Label';
    return $fields;
});
```

(The new key must also be present in the product data, see `D2W_Product_Mapper::map_listings()`.)

## Sync

Sync compares your imported products with your Discogs inventory. It fetches the whole `For Sale` inventory 100 listings at a time (about one API request per 100 listings), and only makes changes once every page has loaded. If any page fails, the run is abandoned without touching stock.

| Setting | Default | Behaviour |
| --- | --- | --- |
| Sync interval | Disabled | How often sync runs via WP-Cron: 15 or 30 minutes, hourly, twice daily or daily. |
| Mark sold items out of stock | Always on | An in-stock product whose listing is no longer `For Sale` is set to out of stock. Each one is double-checked against the listing itself first. |
| Restock relisted items | On | An out-of-stock product whose listing is `For Sale` again goes back to stock 1. Products that sold on the website are **not** restocked while their listing is still `For Sale` (see below). |
| Sync prices | Off | If the Discogs price differs from the WooCommerce regular price (or no regular price is set), the regular price is updated. Listings priced in a different currency from the store are skipped. |
| Auto-import new listings as drafts | Off | New `For Sale` listings are imported as draft products using the field mapping, up to 10 per run to keep runs short. |
| Set Discogs listing to Draft when it sells on the website | Off | When an order containing an imported product reaches Processing or Completed, the Discogs listing is set to Draft so it cannot sell twice. An order note records the result for each item. Listings are never deleted. |

**Website sales and restocking.** When an order containing an imported product reaches Processing or Completed, the product is flagged as sold on the website. Without that flag, a website sale whose Discogs listing is still `For Sale` would be restocked by the next sync and could sell twice. Flagged products are reported in the sync results instead ("remove or draft it on Discogs"). The flag clears automatically once the listing leaves `For Sale`, so a genuine relist later is restocked as normal. Turning on "Set Discogs listing to Draft" avoids the manual step.

**Safety checks.** Sync holds a lock so two runs never overlap. If 10 or more in-stock products, and over half of all in-stock products, are missing from Discogs at once, the run stops without changes (filter `d2w_sync_max_missing_ratio` to adjust), since that usually means an API or account problem rather than a sales rush.

The settings page also shows:

- **New listings**: how many `For Sale` listings are not yet imported, with a link to the import screen filtered to unimported items.
- **Sync log**: the last 20 runs with counts for checked, sold, restocked, price changes, skipped and errors.
- **Preview sync**: a dry run that lists what would change without changing anything.

Details of every run (and any API errors) are written to **WooCommerce > Status > Logs** under the source `d2w`.

### WP-CLI

```bash
wp d2w sync            # run a sync now
wp d2w sync --dry-run  # show what would change, change nothing
```

### Reliable scheduling

WP-Cron only runs when someone visits the site, so 15 and 30 minute syncs can drift on quiet sites. For reliable timing, disable the built-in trigger and call `wp-cron.php` from a real server cron job:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```
*/5 * * * * curl -fsS https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

In cPanel this goes under **Advanced > Cron Jobs**.

## Deploying

This section is for maintainers deploying from a fork of the repository. Site owners can simply install a release zip.

Every push to `main` runs the **Deploy** workflow: it lints the PHP on 8.0 and 8.3, then uploads the plugin over SFTP to your site. It can also be run by hand from the Actions tab (**Deploy > Run workflow**). Until the secrets below are set, the workflow stops at its first check without touching any server.

The workflow uses `lftp mirror` over SFTP rather than rsync, so it also works on shared hosting that allows SFTP but no shell access. It only uploads plugin files (`.git*`, `.github/`, `README.md`, `CHANGELOG.md`, `plugin-notes.md` and `claude-brief.md` are excluded), and only deletes files inside `DEPLOY_PATH` that no longer exist in the repo. It refuses to run if `DEPLOY_PATH` is not inside `wp-content/plugins`.

Add these in GitHub under **Settings > Secrets and variables > Actions > New repository secret**:

| Secret | Value |
| --- | --- |
| `DEPLOY_HOST` | The server's hostname or IP. If the site is behind Cloudflare or another proxy, use the origin server, since SFTP does not pass through the proxy. |
| `DEPLOY_PORT` | SFTP port (defaults to `22`; some shared hosts use a custom port). |
| `DEPLOY_USER` | The SFTP (e.g. cPanel) username. |
| `DEPLOY_KEY` | A private SSH key (OpenSSH format) whose public key is authorised on the server (e.g. cPanel > SSH Access). Create a dedicated key for deploys. |
| `DEPLOY_PATH` | Absolute path to the plugin folder, e.g. `/home/username/public_html/wp-content/plugins/d2w` |
| `DEPLOY_KNOWN_HOSTS` | Optional. The server's host key line(s) (`ssh-keyscan -p <port> <host>`). Without it the workflow trusts whatever key the server presents on each run. |

The workflow runs in a GitHub environment called `production`. Add required reviewers to that environment (Settings > Environments) if you want to approve each deploy.

## Releasing

1. Update `D2W_VERSION` and the `Version:` header in `discogs-to-woocommerce.php`, and add a section to `CHANGELOG.md`.
2. Commit, then tag and push: `git tag v1.1.0 && git push origin v1.1.0`.
3. The **Release** workflow checks that the tag matches both version numbers, builds `d2w-1.1.0.zip` with `git archive` (files marked `export-ignore` in `.gitattributes` are left out) and attaches it to a GitHub Release.

The zip unpacks to a `d2w/` folder. If a site already runs the plugin from a differently named folder, uploading the zip through WP Admin installs a second copy; deactivate and delete the old folder first (settings and product links are kept, since they live in the database).

## Uninstalling

Deleting the plugin from WP Admin removes its options, transients and scheduled sync. Product meta (`_discogs_*`) is kept, so products stay linked if the plugin is reinstalled.
