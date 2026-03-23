=== EVE Trade Tools Price Helper ===
Contributors: c4813
Tags: eve online, esi, prices, market, admin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin-only tool to import the EVE Static Data Export (SDE) and pull hub prices from ESI into an external database.

== Description ==

EVE Trade Tools Price Helper is an admin-only utility plugin for WordPress that integrates external EVE Online market data into a separate database.

It provides a controlled interface for:

* Importing static reference data from the official EVE Static Data Export (SDE) ZIP, downloaded from https://developers.eveonline.com/static-data:
  - marketGroups.yaml → invMarketGroups
  - metaGroups.yaml → invMetaGroups
  - types.yaml → invTypes + invMetaTypes (metaGroupID is a direct field on each type, so no separate file is needed)
  - typeMaterials.yaml → invTypeMaterials
  - blueprints.yaml → industryActivityProducts (manufacturing outputs only)
* Managing trade hubs and optional structure overrides
* Connecting to EVE Online via ESI (including SSO for structures)
* Running scheduled or manual price pulls
* Writing normalized pricing data into an external database

The SDE import uses streaming line-by-line YAML parsers — no PHP YAML extension is required, and memory usage stays low even for the largest files (types.yaml ~200 MB, blueprints.yaml ~500 MB uncompressed). Files are located by basename so nested ZIP paths such as `sde/fsd/types.yaml` are handled automatically.

The plugin does not expose frontend functionality and does not modify the WordPress database schema beyond storing its own settings.

**Upgrading from 1.6.x:** Version 1.7.0 removes the Fuzzwork import and replaces it with direct SDE ZIP import. A fresh install and new SDE import are required. The external database schema is unchanged; only the import source has changed.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Navigate to **WP Admin → EVE Trade Tools**.
4. Configure your external database connection.
5. Download the SDE ZIP from https://developers.eveonline.com/static-data.
6. Upload the ZIP (or enter its server-side path) using the EVE SDE Import card.
7. Configure hubs/structures and run price pulls manually or via schedule.

== Frequently Asked Questions ==

= Does uninstall delete my external database? =
No. Uninstall removes WordPress-side options, transients, and scheduled cron hooks only. Your external database is never modified or dropped.

= Does this plugin expose any frontend output? =
No. All functionality is restricted to the WordPress admin area.

= Does this rely on WP-Cron? =
No. As of 1.5.0, scheduled runs use an external system cron pinging a token-authenticated HTTP endpoint every minute. WP-Cron is no longer used. The Schedule tab shows the curl command and optional WP-CLI command to configure your cron service.

= Where do I get the SDE ZIP? =
Download it from https://developers.eveonline.com/static-data. The file is ~1 GB. If your host has strict upload limits, download it directly to the server via SSH/FTP and use the server-path option in the SDE Import card.

= Do I need a PHP YAML extension? =
No. The importer uses custom streaming line-by-line parsers that handle the SDE YAML format without any PHP extension.

= Which files are used from the SDE ZIP? =
marketGroups.yaml, metaGroups.yaml, types.yaml, typeMaterials.yaml, and blueprints.yaml. The importer finds them by basename regardless of their path inside the ZIP (e.g. sde/fsd/types.yaml works fine).

== Changelog ==

= 1.8.1 =
* Fixed: "Potential Daily Profit" in ETT Reprocess Trading degraded on every scheduled run due to two compounding bugs in the price fetch pipeline. First, the `ON DUPLICATE KEY UPDATE` upsert used `GREATEST`/`LEAST` to merge ESI order pages correctly within a single run, but also persisted that aggregation across runs — causing `buy_max` (item cost) to ratchet up and `sell_min` (material revenue) to ratchet down with each execution, permanently compressing margins even when market conditions were stable. Second, failed ESI responses (non-200) still wrote `avg_daily_volume = 0` to `ett_market_history`, overwriting previously valid volume figures and silently removing items from results on subsequent runs.
* Changed: Price data is now written to a staging table (`ett_prices_staging`) during each run and promoted to the live `ett_prices` table via an atomic `RENAME TABLE` only on successful completion. This means the reprocess tool always reads from a complete, consistent snapshot; a failed or interrupted run leaves the live table untouched. The `GREATEST`/`LEAST` aggregation is preserved — it still correctly merges data across ESI pages within a single run — but can no longer accumulate drift across runs.
* Fixed: Failed ESI history responses (non-200 HTTP codes) no longer write a zero `avg_daily_volume` to `ett_market_history`. Items that could not be fetched retain their last known volume, preventing them from being incorrectly filtered out by the minimum volume threshold on subsequent runs.

= 1.8.0.1 =
* Fixed: Changelog stopped rendering after the first occurrence of `== ` anywhere in the content — including mid-line substrings such as `=== 5` in code examples. The section-boundary regex now requires `==` to be at the start of a line, so inline `==` in changelog text no longer truncates the output.

= 1.8.0 =
* Added: **Private Trade Hubs** — a new card below the Trade Hubs card allows one or more private market structures (alliance citadels, Upwell structures) to be configured as additional trade hubs. Each private hub has its own system name with autocomplete powered by `ett_mapSolarSystems`, an independent character authentication (either the primary SSO character or a dedicated private character), and a selectable list of accessible structures fetched live from ESI. Multiple private hubs are supported; each can be added or removed independently.
* Added: Private hub prices are written to `ett_prices` using the system name as the hub key (e.g. `c-n4od`), which ETT Reprocess Trading reads automatically via `DISTINCT hub_key` — no additional configuration required.
* Added: Private hub market history is fetched for the region the configured system belongs to, deduplicated against other hubs that share the same region.
* Added: Deselecting all standard trade hubs is now respected — a price or history run will skip standard hubs entirely and process only private hubs if any are configured. Previously, deselecting all hubs caused the run to silently fall back to all five standard hubs.
* Added: **Changelog tab** on the EVE Trade Tools admin page — automatically detects all active `ett-*` plugins, reads their `readme.txt`, and renders each plugin's changelog section with a link to its GitHub repository. New ETT plugins appear here automatically.
* Added: `mapSolarSystems.yaml` as an optional step 6 in the SDE import. When present in the ZIP, it populates `ett_mapSolarSystems` (`solar_system_id`, `name`, `region_id`), which powers private hub system name autocomplete and canonical name display throughout the plugin. Missing from the ZIP is handled gracefully — the other five files still import normally.
* Added: Job progress hub labels for private hubs now use the canonical in-game system name queried from `ett_mapSolarSystems` rather than an uppercased version of the sanitized key. The `private_hub_N` internal key is also resolved to the display name via a dynamic map passed to JS.
* Added: History progress `last_msg` now shows in orange when rate limiting or ESI errors are active, consistent with the prices progress display.
* Added: `Heartbeat: OK` label now includes a colon, consistent with `ESI: OK`.
* Fixed: SDE import AJAX step handler had a hardcoded `$step > 5` guard that rejected step 6, and the final-step summary logic was hardcoded to `$step === 5`. Both now derive the boundary from `count(ETT_SDE::STEPS)`.
* Fixed: The JavaScript `SDE_STEPS` array was missing the `mapSolarSystems.yaml` entry, so step 6 was never called even after the PHP changes added it.
* Fixed: ZIP file uploads were rejected with "not allowed to upload this file type" on some server configurations where `finfo`/`mime_content_type` returns `application/octet-stream` or `application/x-zip-compressed` instead of `application/zip`. A `wp_check_filetype_and_ext` filter now force-allows `.zip` files regardless of server MIME detection.
* Fixed: SDE card stated the full SDE ZIP was ~1 GB; corrected to ~40 MB compressed. The misleading "nested paths such as `sde/fsd/` are handled" text was also removed — the SDE does not use nested paths.
* Fixed: Private hub `region_id` was not included as a hidden field in the PHP-rendered hub card, causing every save after a page reload to overwrite it with 0. The job-run guard `$region_id <= 0` then silently excluded the private hub from the price run entirely.
* Fixed: Structure `enabled` state was always lost on save when the hub card was rendered by PHP (as opposed to JS-built new hubs). The PHP checkbox format (`structures[STRUCT_ID] = 1`) and the JS object-array format are now both handled by `ajax_priv_save_hub`.
* Fixed: `ajax_save_hubs` overwrote an empty hub selection with all five standard hubs before saving. Deselecting all hubs now persists correctly.

= 1.7.0.1 =
* Fixed: PHPCS — `$step` and `$entry` in exception messages in `class-ett-sde.php` were not passed through an escaping function; both now use `esc_html()`.
* Fixed: PHPCS — `$_FILES['sde_zip']` in both `handle_import_sde()` and `ajax_sde_prepare()` triggered `InputNotSanitized` warnings; `phpcs:ignore` comments updated with explicit justification (security enforced by `is_uploaded_file()` / `wp_handle_upload()`, not sanitization).
* Fixed: PHPCS — `move_uploaded_file()` is forbidden under WordPress coding standards; replaced with `wp_handle_upload()` using a temporary `upload_dir` filter to direct the file into the `sde-tmp/` subdirectory.
* Fixed: PHPCS — `$_POST['step']` cast with bare `(int)` in `ajax_sde_import_step()`; replaced with `absint()` which PHPCS recognises as explicit sanitization.

= 1.7.0 =
* Breaking change: Fuzzwork import removed entirely. Static reference data is now imported directly from the official EVE Static Data Export (SDE) ZIP from developers.eveonline.com. A fresh install and new SDE import are required — no backward compatibility with 1.6.x.
* New: ETT_SDE class with streaming line-by-line YAML parsers for all five required files. No PHP YAML extension needed; memory use stays low regardless of file size.
* New: SDE Import admin card replaces the Fuzzwork Import card. Supports two import methods — Option A: ZIP file upload via browser; Option B: server-side absolute path for hosts where upload limits make the ~1 GB ZIP impractical to upload over HTTP.
* New: Import runs as five sequential AJAX calls (one per YAML file) rather than a single synchronous form POST. A progress bar and step log update live as each file completes, showing the row count written per table. An animated ellipsis on the status line confirms activity during each step so the UI never appears frozen.
* New: Market Groups card tree auto-populates immediately after a successful SDE import without requiring a page refresh. The Generate TypeIDs button is re-enabled at the same time.
* New: SDE import Option A / Option B forms appear immediately when DB settings are saved and the schema becomes ready, without requiring a page refresh.
* Changed: invMetaTypes is now populated from types.yaml — each type carries a metaGroupID field directly, so no separate source file is required.
* Changed: industryActivityProducts is now populated from blueprints.yaml, filtering to manufacturing activities only (equivalent to the previous activityID = 1 filter on industryActivityProducts.csv).
* Changed: Minimum PHP version raised to 8.0.
* Removed: class-ett-fuzzwork.php and card-fuzzwork.php deleted. Uninstall cleans up ett_sde_last_import_meta only.

= 1.6.2 =
* Fixed: buy_max inflated by buy orders that cannot be fulfilled at the target station. ESI returns all buy orders region-wide; the plugin was filtering by location_id only, allowing wide-range buy orders to set an artificially high buy_max. Buy orders are now filtered by their range field — station-range orders must match the target station_id, solarsystem-range orders must match the hub system_id, region-range orders are always included, and jump-range orders are conservatively excluded. Secondary and tertiary structure sources are unaffected.

= 1.6.1 =
* Internal: Template files moved from templates/price-helper/ to templates/.

= 1.6.0 =
* Changed: Action buttons renamed for clarity — "Run All" is now "Fetch All", "Run Prices" is now "Fetch Prices", and "Run History" is now "Fetch History". Descriptive tooltips added to each button.
* Internal: All admin card HTML extracted from ETT_Admin::render() into dedicated template files under templates/price-helper/ — one file per card (External Database, Fuzzwork Import, EVE SSO, Market Groups, Trade Hubs, Actions, Schedule, Run History).
* Internal: Three inline <script> blocks (schedule pause/resume, copy buttons + token regeneration, clear history) moved into assets/admin.js. No behaviour change.
* Internal: sched_enabled and home_url added to the ETT_ADMIN localised data object.
* Internal: Added private ETT_Admin::render_template() helper for scoped template inclusion.

= 1.5.1 =
* Performance: History fetch default concurrency raised from 5 to 20; maximum raised to 50. Users who had never explicitly saved the concurrency setting were running at the original slow rate of 5 regardless of the cap changes in 1.5.0.
* Performance: History fetch sub-group overhead eliminated — all items in a batch now fire in a single curl_multi call with no inter-group sleep gaps.
* Added: Run Prices button — runs prices only, does not auto-start a history fetch on completion.
* Added: Run History button — runs history fetch independently of a price run.
* Changed: Former "Run Prices" button renamed to "Run All" (behaviour unchanged — prices followed by automatic history fetch).
* Added: Concurrency KPI tile in the history fetch progress panel showing the active concurrency value in use.
* Added: ESI status indicator in the history fetch progress panel, mirroring the indicator on the prices panel. Appears above the heartbeat indicator.
* Fixed: Heartbeat indicator on the prices panel stuck on "Waiting for heartbeat" despite the job actively stepping. Root cause was a server/browser timezone mismatch — heartbeat_at is returned as a WordPress local-time MySQL timestamp with no timezone suffix, which new Date() parsed as browser local time, making the staleness delta incorrect. Receipt time is now recorded locally in the browser at the moment the response arrives.
* Fixed: Same timezone-mismatch fix applied to the history fetch heartbeat indicator.
* Fixed: "Waiting for heartbeat" shown immediately for cron-driven jobs even on a fresh heartbeat due to an erroneous observeOnly guard on the 15-second green threshold.
* Fixed: Cancelling a history fetch restarted it. Redundant create_job('history') calls in the runner duplicated the job that finish_job() already creates; the idle-attach watcher picked up the second queued job immediately after cancel.
* Misc: Removed empty activation and deactivation hook stubs from the main plugin file.

= 1.5.0 =
* Changed: WP-Cron removed entirely as the scheduling mechanism. Scheduled runs are now driven by an external cron service (e.g. Hostinger cPanel, crontab) pinging a token-authenticated HTTP endpoint (`/?ett_ph_run=TOKEN`) every minute. Each ping works for the full PHP execution window before saving state, so a 10–20 minute run completes across a handful of pings with no timeout risk.
* Changed: Schedule card redesigned — flat layout with Start time, Run every, and a new Next scheduled run field. Cron setup section provides a ready-to-use curl command (Option A, any host) and a WP-CLI command (Option B, requires SSH) with copy buttons.
* Added: Pause Schedule / Resume Schedule button on the Schedule card. When paused, no new price runs are triggered by incoming cron pings; any in-progress job completes normally. The Next scheduled run field updates immediately to reflect the paused state.
* Added: Run History card below the Schedule card showing both Price runs and History fetch jobs. Columns: Type, Started, Finished, Status, Driver, Last message. Previously only price runs were listed, and the table was hidden inside a collapsible dropdown.
* Added: Clear History button in the Run History card header. Removes all completed (done/error/cancelled) job records; active and queued jobs are unaffected.
* Fixed: History fetch was skipping all secondary structures despite EVE SSO being authenticated. The access-token gate (`get_access_token_for_jobs()`) only permitted `is_admin()`, WP-Cron, and WP-CLI contexts — system-cron HTTP pings matched none of these, causing a silent `forbidden_context` fallback. The gate now also permits requests arriving via `?ett_ph_run=TOKEN`.
* Fixed: PHP execution deadline calculated from `REQUEST_TIME` (arrival of HTTP request) rather than the current moment, causing the work loop to expire before doing any work on slow shared hosts where WordPress boot consumes several seconds of the time limit. Deadline is now calculated from `microtime(true)` using remaining budget.
* Fixed: History fetch rate limiting caused by firing all parallel ESI requests simultaneously. `curl_multi_history()` now sends requests in sub-groups of up to `concurrency` simultaneous connections with a 500 ms gap between sub-groups, and reads the `X-Esi-Error-Limit-Remain` response header — if the error budget drops below 10, remaining items in the batch are skipped and the 60-second backoff is triggered automatically.
* Changed: History fetch concurrency default lowered from 15 to 5; maximum capped at 20. The setting now controls sub-group size (parallel connections per burst) rather than total batch size.
* Fixed: Heartbeat stale warning fired after ~15 seconds for cron-driven jobs, which update only once per minute. Threshold is now 90 seconds for system-cron jobs (15 seconds for manual browser-driven jobs). Warning text for cron jobs now reads "Waiting for next cron ping — heartbeat updates once per minute." rather than implying a stall.
* Fixed: History fetch progress panel flickered back to "Starting history fetch…" when re-attaching to an already-running job. `startHistoryJob()` now accepts and renders the existing progress immediately on attach.
* Fixed: Cancel History required two clicks. The idle-attach watcher (polling every 1 s) would re-attach to the still-running DB job in the gap between `stopHistoryJob()` clearing `historyRunning` and the cancel AJAX landing on the server. The `historyCancelling` flag is now held for 1.5 s after the AJAX resolves, blocking spurious re-attachment.
* Fixed: Deadlock (MySQL error 1213) when two overlapping cron pings both tried to write to the same job row simultaneously. A concurrent-ping guard now bails out immediately if a heartbeat was written within the last 30 seconds, and `update_status()`, `heartbeat()`, and `finish_job()` retry up to 3 times with exponential back-off on SQLSTATE 40001.
* Fixed: `is_run_due()` ignored `start_time` after the first run, anchoring subsequent runs to `last_run + freq_hours` instead. Runs are now anchored to the configured start time regardless of when the previous run completed — with start_time 10:32 and freq 24 h, every run fires at 10:32 daily.
* Fixed: Idle-attach watcher interval reduced from 5 s to 1 s; status poll interval reduced from 2 s to 1 s for faster UI attach on cron-driven jobs.

= 1.4.4 =
* Fixed: `Domain Path` header in the plugin file referenced a `languages/` directory that did not exist, producing a validation warning. The header has been removed as the plugin does not include any translation files.
* Fixed: Upgrade notice for 1.4.3 exceeded the 300-character limit. Notice has been trimmed to a concise summary.

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

= 1.7.0.1 =
PHPCS compliance fixes only. No functional or database changes. Safe to upgrade in place.

= 1.7.0 =
Fresh install required — uninstall 1.6.x first, then activate 1.7.0 and run the SDE import. Download the SDE ZIP from developers.eveonline.com. External database schema is unchanged; only the import source has changed. PHP 8.0+ now required.

= 1.6.2 =
Bug fix release. buy_max values will be corrected after running a fresh Fetch Prices. No database schema changes. Safe to upgrade in place.

= 1.6.1 =
Housekeeping release. No database schema changes. No behaviour changes. Safe to upgrade in place.

= 1.6.0 =
Cosmetic and code-quality release. Action buttons renamed (Fetch All / Fetch Prices / Fetch History). No database schema changes. No behaviour changes. Safe to upgrade in place.

= 1.5.0 =
WP-Cron scheduling removed. After upgrading, configure an external cron service to ping the URL shown in the Schedule tab every minute — your existing schedule settings (start time, frequency) are preserved. No database schema changes. Safe to upgrade in place.

= 1.4.4 =
Housekeeping release. No database schema changes. Safe to upgrade in place.

= 1.4.3 =
Schema update: `portionSize` column added to `ett_invTypes`, added automatically on next plugin load. Re-run the Fuzzwork import to populate correct values — reprocessing calculations in ETT Reprocess Trading will be incorrect until the import is re-run.

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
