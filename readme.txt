=== EVE Trade Tools Price Helper ===
Contributors: c4813
Tags: eve online, esi, prices, market, admin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin-only tool to import Fuzzwork market groups/types and pull hub prices from ESI into an external database.

== Description ==

EVE Trade Tools Price Helper is an admin-only utility plugin for WordPress that integrates external EVE Online market data into a separate database.

It provides a controlled interface for:

* Importing static reference data from Fuzzwork:
  - invMarketGroups
  - invTypes (nodescription CSV)
  - invMetaGroups
  - invMetaTypes
  - industryActivityProducts
  - invTypeMaterials (CSV bz2)
* Managing trade hubs and optional structure overrides
* Connecting to EVE Online via ESI (including SSO for structures)
* Running scheduled or manual price pulls
* Writing normalized pricing data into an external database

The plugin does not expose frontend functionality and does not modify the WordPress database schema beyond storing its own settings.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Navigate to **WP Admin → EVE Trade Tools**.
4. Configure your external database connection.
5. Run the Fuzzwork import to populate required reference data.
6. Configure hubs/structures and run price pulls manually or via schedule.

== Frequently Asked Questions ==

= Does uninstall delete my external database? =
No. Uninstall removes WordPress-side options, transients, and scheduled cron hooks only. Your external database is never modified or dropped.

= Does this plugin expose any frontend output? =
No. All functionality is restricted to the WordPress admin area.

= Does this rely on WP-Cron? =
Yes. Scheduled runs use WordPress cron. For production reliability, a real system cron triggering `wp-cron.php` is recommended.

== Changelog ==

= 1.4.3 =
* Fixed: `portionSize` was not stored in `ett_invTypes` — the column was absent from both the schema definition and the Fuzzwork CSV importer, causing ETT Reprocess Trading to treat every item as a batch size of 1. Items with a portionSize greater than 1 (e.g. ammunition at 100) produced reprocessed values inflated by the full portionSize factor.
* Changed: `ett_invTypes` now includes a `portionSize` column. `ensure_schema()` will automatically add the column to existing installations via `ALTER TABLE` on next plugin load.
* Changed: Fuzzwork `invTypes` CSV importer now reads and stores `portionSize` for every type.
* Note: a re-run of the Fuzzwork import is required after upgrading to populate portionSize values. Until then, all rows default to 1.

= 1.4.2 =
* Fixed: Saving SSO settings without re-entering the Client Secret wiped the stored secret — the form never pre-fills the decrypted secret, so submitting with a blank field now preserves the existing value, matching the behaviour of the database password field.
* Fixed: Plugin deactivation left the `ett_ph_history_tick` WP-Cron hook scheduled and the `ett_ph_cron_history_job_id` option in the database. Both are now cleaned up on deactivation.
* Fixed: Job history table rendered with a malformed opening tag — the `<table>` element was missing its closing `>`, which caused the table headers to render incorrectly in strict browsers.
* Security: EVE SSO Client Secret is no longer written into the page source. The secret field now always renders empty; a placeholder indicates whether a secret is already saved. This prevents the decrypted value from appearing in HTML source or browser developer tools.
* Security: PDO connections to the external database now use real server-side prepared statements (`ATTR_EMULATE_PREPARES => false`), replacing the previous PHP-emulated parameterisation.

= 1.4.1 =
* Fixed: History fetch concurrency setting not saving — the Advanced performance form was not including the `history_batch_size` field in its AJAX request, so changes to that setting were silently discarded.
* Fixed: Plugin options not fully cleaned up on uninstall — `ett_ph_cron_history_job_id`, `ett_history_batch_size`, and the `ett_ph_history_tick` WP-Cron hook were not removed during uninstall. Note: the external database is intentionally never modified by uninstall.
* Fixed: Price table wiped at the start of each run — `ett_prices` was truncated before new data was written, creating a window where consumers could read an empty or incomplete dataset. Prices are now overwritten in-place via `INSERT … ON DUPLICATE KEY UPDATE`. Rows for types that have no active orders in the current run are left at their previous values; the per-row `fetched_at` timestamp reflects when each price was last refreshed and can be surfaced in consumer plugins as a staleness indicator.

= 1.4.0 =
* Renamed admin menu entry from "ETT Prices" to "EVE Trade Tools".
* Introduced a master tabbed admin page; existing Price Helper settings are now presented under a "Price Helper" tab.
* Added tab registration API (`ETT_Admin::register_tab()` / `ett_admin_tabs` action) allowing other ETT plugins to add tabs to the shared admin page without modifying this plugin.
* Introduced a unified EVE SSO callback URL (`?action=ett_eve_callback`) that handles OAuth returns for all ETT plugins via a state-based dispatcher, replacing the previous plugin-specific callback.
* Added `ETT_Admin::unified_callback_url()` public method for consistent URL generation across plugins.
* Updated the developer app setup instructions: callback URL field labelled as "universal — handles all ETT plugins"; required scopes split into separate labelled groups for ETT Price Helper and ETT Reprocess Trading (if installed).
* Legacy callback action (`ett_sso_callback`) retained for backwards compatibility.

= 1.3.0 =
* The prices job now fetches CCP's universe-wide adjusted and average price data from ESI immediately after all hub fetches complete, written to a new `ett_adjusted_prices` table.
* New job phase `adjusted` runs between the hub phase and job completion.
* Fetches `GET /markets/prices/` and filters to selected type IDs before writing.
* Full rate-limit and transient-error handling with backoff/retry, consistent with the hub phase.
* Elapsed time in the completion message now covers the full prices + adjusted run.
* New database table `ett_adjusted_prices` — created automatically by `ensure_schema()`, no manual migration required.
* The history fetch concurrency (previously hardcoded at 50 parallel ESI requests per step) is now configurable under Advanced performance settings. New option: History fetch concurrency — range 1–50, default 15. Reducing this value is the recommended fix if you encounter rate limiting during the history phase.
* Fixed: history cron tick ignored `sleep_until` after a 429 — when the history job was rate-limited during a cron run, the next tick was always scheduled 1 second later instead of waiting out the backoff window.
* Fixed: Run Prices button briefly re-enabled between prices finishing and history starting — a race condition in `stopJob()` allowed the button to become clickable during the transition; it now stays disabled for the full combined run.
* Fixed: stale history progress panel left visible on new price run — starting a fresh prices run now clears any previously completed history progress panel.
* Fixed: history job elapsed timer reset on page refresh — if the page was reloaded while a history job was in progress, the elapsed timer restarted from zero; it now anchors to the job's `started_at` timestamp from the database.
* Fixed: history job progress blob included prices-only fields — `create_job()` no longer adds `current_hub`, `page`, `orders_seen`, and `matched_orders` to the initial progress JSON for history jobs.
* Fixed: rate-limit warning retained stale "Backing off and retrying" suffix after recovery — once a rate-limited history batch completed successfully, the warning is now updated to remove the action clause.

= 1.2.0 =
* Market history fetching — after every price run (manual or scheduled), a history fetch job now runs automatically, pulling 30-day rolling average daily volume per item from the ESI market history endpoint for all selected trade hubs.
* Parallel ESI requests — history fetching uses `curl_multi` to fire 50 requests simultaneously, keeping total fetch time well under 10 minutes for typical type ID lists.
* New `ett_market_history` table storing `hub_key`, `type_id`, `avg_daily_volume`, and `fetched_at`.
* History Fetch Progress UI — new progress section in the Actions card showing phase, hub, items done/total, rows written, elapsed time, heartbeat indicator, progress bar, and debug JSON box.
* Rate limiting detection — 429 responses during history fetch trigger a 60-second backoff and surface a warning in the UI and debug box, matching the behaviour of the prices job.
* Non-200 error tracking — non-429 ESI errors during history fetch are counted and logged to the warnings array in progress JSON.
* Job history table now shows both `prices` and `history` type jobs.
* Fixed: Run Prices button remains disabled until the history fetch completes, not just until the prices job finishes.
* Fixed: Cancel button re-enables as "Cancel History" during the history fetch phase and correctly cancels the history job.
* Fixed: ESI health indicator no longer flips to red on a single transient failed status check — only a previously unknown or already-down state will show Down on a failed check.

= 1.1.0 =
* Added import of `invTypeMaterials` from Fuzzwork.
* Renamed external database tables to match Fuzzwork source file names exactly: `ett_invMarketGroups`, `ett_invTypes`, `ett_invMetaGroups`, `ett_invMetaTypes`, `ett_industryActivityProducts`, `ett_invTypeMaterials`.
* Updated import routines to target renamed tables.
* Updated schema creation logic to align with new table naming.
* Updated admin import reporting to reflect new table names.
* No frontend or pricing logic changes.

= 1.0.1 =
* Moved inline styles into `admin.css` and removed redundant CSS rules.

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.4.3 =
Schema update: `portionSize` column added to `ett_invTypes`. The column is added automatically via `ALTER TABLE` on next plugin load — no manual migration required. However, a re-run of the Fuzzwork import is required to populate correct portionSize values for existing types. Reprocessing calculations in ETT Reprocess Trading will be incorrect until the import is re-run.

= 1.4.2 =
Bug fix and security release. No database schema changes. Safe to upgrade in place. After upgrading, re-enter and save your EVE SSO Client Secret once — the field no longer pre-fills, so the stored value is preserved but you will see the placeholder rather than the previous value.

= 1.4.1 =
Bug fix release. No database schema changes. Safe to upgrade in place. Note: `ett_prices` is no longer truncated at the start of a run — existing rows are overwritten in-place, so no data loss occurs during an upgrade or first run after updating.

= 1.4.0 =
The admin menu entry has been renamed to "EVE Trade Tools". Settings are unchanged and no database migration is required.

= 1.3.0 =
New `ett_adjusted_prices` table created automatically on first load via `ensure_schema()`. No manual migration required. Safe to upgrade in place.

= 1.2.0 =
New `ett_market_history` table created automatically on first load via `ensure_schema()`. No manual migration required. Safe to upgrade in place.

= 1.1.0 =
Schema update: external database table names were aligned with Fuzzwork source file names. If upgrading from 1.0.x, ensure `ensure_schema()` runs or re-run the Fuzzwork import to initialise the renamed tables.

= 1.0.1 =
Maintenance release improving admin UI stability and internal structure. No database changes.

= 1.0.0 =
Initial public release.
