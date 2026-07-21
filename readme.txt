=== EVE Trade Tools Price Helper ===
Contributors: c4813
Tags: eve online, esi, prices, market, admin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.22.0
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

= 1.22.0 =
* Critical fix: every scheduled, cron-driven Contract Fetch run has been silently
  running the PRICES fetch logic instead, under a "contracts" label — meaning BPC
  contract data was never actually refreshed via scheduled cron at all, only ever by
  a manual, browser-driven run. Root cause: class-ett-runner.php's own job dispatch
  only ever checked for job_type 'history' explicitly, falling through to
  step_prices() for anything else — including 'contracts'. This was a completely
  separate, never-updated dispatch path from the correct one already used for manual
  runs (class-ett-jobs.php's ajax_step(), which already handled 'contracts'
  correctly). Explains the exact symptoms reported: prices-style messages ("Hub hek
  Primary...", "All hubs and adjusted prices complete...") appearing under Contract
  Fetch Progress, and consistent ~5 minute "contract fetch" durations far too short
  for genuine contract checking. Added the missing step_contracts() dispatch and
  reflection wrapper (mirroring the existing step_prices/step_history ones), fixed
  in both places this dispatch appears. Verified directly: all 3 job types now
  correctly route to their own respective step functions.

= 1.19.0 – 1.21.0 = Barter/item-payment contracts fully excluded from pricing, in two parts. First: any contract with at least one is_included=false item (EVE's "also request items from buyer" — the buyer pays partly or entirely in items, not ISK) is now excluded, since its price field alone doesn't represent the true acquisition cost and could make an otherwise-expensive blueprint look artificially cheap. Second, found after a real report that the fix wasn't taking effect even after a full truncate-and-re-pull: the checking phase only ever inserted/updated on a match, never removed a row when a re-check found a contract no longer matches. A contract that matched under the OLD rule but is still genuinely listed survives pruning and gets correctly re-excluded on re-check — but its stale row from the earlier match was never actively deleted, sitting there indefinitely. Now explicitly deleted whenever a re-check resolves to no match. Verified both parts directly against the real reported scenario end to end.

= 1.17.0 – 1.18.0 = Chased down the real root cause behind a purchased/expired contract still being usable well after it should have been gone, even right after a fresh Contract Fetch run. Two real, contributing issues found: (1) aggregate_bpc_prices() only ever revisits a blueprint currently in ett_contract_bpc_active — once a blueprint's last active listing drops out, nothing ever touched its old row in ett_contract_bpc_prices/ett_contract_bpc_candidates again, letting a stale winner persist indefinitely; now pruned every run. (2) The bigger one: pruning ran AFTER aggregating, not before — a contract matched in a previous run stays cached (checking only adds new matches, never re-verifies old ones), so if it had since been purchased, aggregation would still read it as valid and compute a winner from it before pruning deleted it moments later. Phase order is now listing -> checking -> pruning -> aggregating -> done, so aggregation only ever works from already-current data. Both verified directly against the exact failure scenario.

= 1.16.0 – 1.20.0 = Chased down the full extent of the deadlock bug class (SQLSTATE[40001]) across two rounds. First: ETT_Jobs' own update_status(), heartbeat(), finish(), and create_job() — the ones actually used by the browser/AJAX "Fetch All"/Cancel buttons, not the external-cron path that already had protection — had no deadlock-retry at all, so a Cancel click racing an in-flight heartbeat poll had nothing to fall back on. Second, found later from a real error log: the actual bulk price/market-data writes during a prices run (ett_prices_staging on both the standard and private-hub paths, ett_adjusted_prices, ett_market_history) were a completely separate, still-unprotected set of writes to different tables entirely. All eight write sites now use the same retry mechanism (3 retries, 50/100/150ms backoff). Verified the retry logic itself against 4 scenarios, and verified the wrapped writes work correctly end-to-end against a real, native-prepares connection.

= 1.15.0 =
* Fixed a real bug in outlier rejection: listings were bucketed by run count alone, so two listings sharing a run count but representing genuinely different ME levels (e.g. an 8ME copy at 400m vs a 9ME copy at 2b, both 1-run) got compared against each other and the cheaper, lower-ME one was incorrectly discarded as if it were a troll/mistake listing. Now buckets by run count AND ME%/TE% combined, so outlier rejection only ever compares listings claiming to be the same thing — genuinely different ME options at the same run count no longer get silently thrown away, which directly affects how good a candidate pool the total-cost optimizer in ett-build-costs has to work with. Verified against 3 scenarios: the original troll-rejection case (unaffected, still correctly rejects a genuine mistake-priced listing), the newly-discovered issue (both ME levels now correctly preserved), and a combined case with both patterns present simultaneously (troll rejected within its own ME group, a legitimately different ME listing at the same run count survives independently).

= 1.14.0 =
* Added contract_id to ett_contract_bpc_prices and ett_contract_bpc_candidates — foundation for an upcoming "Open Contract In-Game" feature in ett-build-costs. ett_contract_bpc_active already had contract_id (always has, since the first version of Contract Fetch); the gap was purely that aggregate_bpc_prices() never carried it forward into the downstream tables. No re-pull needed — the very next normal Contract Fetch run (aggregation runs unconditionally every cycle) will populate it for every currently-active listing, including ones matched long before this update. Verified directly against both a same-run-count scenario (which correctly exercises existing outlier rejection, unrelated to this change) and a differing-run-count scenario, confirming contract_id threads through correctly to both the winner and candidates tables in each case.

= 1.13.0 =
* Added (foundation, existing behavior untouched): a new ett_contract_bpc_candidates table now preserves every genuinely distinct (ME%, TE%) combination that survives outlier rejection for a blueprint, not just the single overall cheapest — needed because "cheapest to acquire" and "cheapest once real material waste is factored in" are different questions, and a more expensive, better-researched candidate can still win once total build cost is considered. The existing ett_contract_bpc_prices single-winner table is completely unchanged, verified directly to still pick the exact same result as before. Purely additive — nothing currently reads the new table yet.

= 1.12.0 =
* Added: Contract Fetch now records the full contents of multi-blueprint-type contracts (mixed bundles that can't be fairly priced per-item on their own) as pack candidates — every distinct tracked blueprint found, grouped by type/ME%/TE%, plus the contract's total price and whether any non-blueprint or untracked items were also present. This doesn't decide "is this a valid build pack" itself (that requires knowing a specific hull's real requirements, which only ett-build-costs can determine) — it just records what's actually inside a contract for that later comparison. New ett_contract_packs and ett_contract_pack_items tables, pruned at the end of each run the same way existing contract tables already are. Verified directly against 5 scenarios: multiple distinct tracked blueprints with no extras, a blueprint mixed with a non-blueprint item, a tracked blueprint mixed with an untracked one, a clean same-type bulk match correctly NOT also being stored as a pack, and a contract with no blueprints at all correctly producing nothing. Also verified pruning correctly removes a stale contract's multiple composite-key rows while preserving an still-active one's.

= 1.11.0 =
* Added: Contract Fetch now looks inside multi-item contracts instead of skipping them entirely. A bundle of several genuinely identical BPC copies (same blueprint, same ME%/TE%) is now priced correctly as one bulk listing — total contract price divided by the summed runs across every copy, e.g. 5x 5-run 10/20 copies for 80m becomes 3.2m/run. Bundles mixing different blueprint types, or blueprints mixed with non-blueprint items, are deliberately still excluded — there's no way to fairly attribute a lump sum across genuinely different items, and guessing at a split risked a confidently-wrong number rather than a known gap. No new ESI calls needed — every item in a contract was already being fetched, just discarded past the first one before now. New winning_quantity column records how many copies contributed to a winning bulk listing, for future display use. Verified directly against the motivating real-world example.

= 1.10.0 =
* Contract Fetch now also captures each listing's material_efficiency and time_efficiency (already returned by ESI, previously discarded), and records the SPECIFIC winning listing's own real price, run count, and ME%/TE% — not just a derived per-run rate — in a new winning_price/winning_runs/material_efficiency/time_efficiency columns on ett_contract_bpc_prices. Enables ett-build-costs to show the real contract's actual numbers and use its genuine ME%/TE% as a smarter default than assuming an unresearched copy. Verified the winner-tracking logic picks the correct specific listing (not just any survivor) against both the original troll-rejection scenario and a mixed-ME/TE scenario.

= 1.9.3 =
* Fixed: the outlier-rejection step in Contract Fetch's price aggregation compared per-run prices across different run counts as if they were one population, incorrectly discarding legitimately cheaper bulk-run BPCs (e.g. a 30-run copy at 12,000/run) as "troll" listings simply because they were cheaper per-run than smaller-run-count copies of the same blueprint — a normal, expected pattern (fixed listing overhead spread across more runs), not a mistake. Outlier rejection now happens within each distinct run count separately, then takes the minimum across whatever survives from every run-count group. Verified against real data (a genuine 12,000/run listing that was being wrongly rejected in favor of 66,666.67) and against the original troll-listing and mixed-run-count test cases, confirming both still behave correctly.

= 1.9.2 =
* Contract Fetch's blueprint-matching logic no longer filters to blueprints lacking a market_group_id — it now tracks any known manufacturing blueprint, BPO-available or not. Blueprint copies can never be sold via market orders for any item, ever (a hard game rule), so contracts are the only way to price one regardless of BPO availability — the earlier "BPC-only" framing was based on a mistaken assumption. Reaction blueprints remain untracked (unchanged from before — the SDE import only ever captured manufacturing activity), matching ett-build-costs' 0.32.0 decision to treat reaction formulas as owned infrastructure rather than a per-build cost.

= 1.9.1 =
* Fixed: Contract Fetch's timestamps (checked_at, computed_at) used WordPress's current_time('mysql'), which returns time adjusted to the site's configured timezone rather than genuine UTC. ett-build-costs' own snapshot timestamps use true UTC (gmdate()) — comparing the two directly during troubleshooting made two independently-correct systems look like they disagreed with each other by however many hours the site's timezone differs from UTC. All Contract Fetch timestamps now use gmdate() for consistency with the rest of the pipeline.

= 1.9.0 =
* Added: Contract Fetch — a new third scheduled step (after Prices and History) that scans Jita's public item_exchange contracts and identifies confirmed blueprint-copy listings for any blueprint that can't be bought as a BPO at all (no market_group_id). Per-listing runs, material efficiency, and time efficiency are read directly from ESI's contract-items response rather than assumed — verified this against a live test call before building on it. Stores one already-normalized per-run ISK price per blueprint (median-based outlier rejection — anything under 50% of the median discarded as a likely mistake/troll listing — then the minimum of what survives), ready for companion plugins to read without any further ESI calls of their own.
* Added: `ett_blueprint_products` table (blueprint_type_id -> product_type_id), captured during the existing SDE blueprint import in the same pass, without changing `ett_industryActivityProducts`'s existing behavior or structure at all — that table is relied on elsewhere via a plain existence-check JOIN that a primary-key change would have broken into duplicate rows.
* Added: "Fetch Contracts" button, its own progress panel (phase, page, candidates found, checked, matched, elapsed, heartbeat, ESI status), and full Fetch All chain wiring (Prices -> History -> Contracts). A standalone "Fetch History" click correctly does not drag Contracts along (a `history_only` flag was needed here, mirroring the existing `prices_only` flag for Prices -> History).
* Performance: the expensive per-contract contents lookup only ever needs to happen once per contract_id — contents can't change once a contract is created, only its status can (accepted/expired/cancelled, at which point it simply leaves the live list). A permanent "already resolved" cache means every run after the first only checks contracts genuinely never seen before, not the full candidate pool each time. Both this cache and the live "currently active BPC listings" table are pruned at the end of each run using that same run's already-fetched contract list — no extra ESI calls needed for pruning.

= 1.0.0 – 1.8.3 = Early development history, condensed: initial release through SDE-based static data import (replacing the earlier Fuzzwork CSV source), Private Trade Hubs (alliance/Upwell structure market data), unified EVE SSO callback across ETT plugins, external cron-driven scheduling (replacing WP-Cron) with pause/resume and run history, parallel ESI history fetching with rate-limit backoff, an atomic staging-table swap for price writes (preventing partial/interrupted runs from corrupting live data), and a long tail of bug fixes around buy-order range filtering, timezone-correct heartbeats, portionSize tracking for reprocessing calculations, and PHPCS compliance.

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
