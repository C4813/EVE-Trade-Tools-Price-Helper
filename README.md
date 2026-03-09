# EVE Trade Tools Price Helper

A WordPress plugin that imports EVE Online static data from Fuzzwork and pulls live market prices from ESI into an external MySQL database, making that data available to other plugins without requiring repeated ESI calls.

Part of the EVE Trade Tools suite. This is the base plugin — other ETT plugins depend on it for database access, SSO credentials, and the shared admin page.

---

## Features

- **Fuzzwork static data import** — downloads and imports market groups, item types, meta groups, meta types, manufacturing outputs, and reprocessing materials into the external database
- **Hub price pulls** — fetches buy/sell orders from ESI for up to five NPC trade hubs; prices are upserted in-place rather than wiped, so consumers always have data
- **Structure price support** — optional secondary and tertiary player structure per hub, fetched via EVE SSO; 401/403 responses are skipped cleanly rather than retried
- **Adjusted prices** — fetches CCP's adjusted price list from ESI after each hub run and stores it for use in reprocessing tax calculations
- **Market history** — fetches 30-day rolling average daily volume per type per region via parallel ESI requests; Rens and Hek share the Heimatar region and are fetched once
- **System cron runner** — jobs are driven by an external cron service pinging `/?ett_ph_run=TOKEN` each minute; each ping works for the full PHP execution window before saving state and resuming on the next ping
- **Configurable scheduler** — set a daily start time and repeat frequency (1–168 hours); pause and resume without losing settings
- **Manual job control** — start, monitor, and cancel jobs from the admin page with live progress updates
- **Job history** — completed jobs retained for 90 days with full progress and error detail
- **ESI error handling** — rate limit backoff (HTTP 429/420) with `Retry-After` support; transient errors retry after 5 seconds
- **Tab framework** — exposes `ETT_Admin::register_tab()` so other ETT plugins can add their own tabs to the shared admin page
- **Encrypted credential storage** — SSO Client Secret and database password stored using AES-256-CBC with HMAC-SHA256, keyed from WordPress secret constants

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0 or later |
| PHP | 8.0 or later |
| PHP extension | `bz2` (required for Fuzzwork import) |
| PHP extension | `curl` (required for parallel history fetching) |
| External MySQL database | Separate from the WordPress database |

---

## Installation

1. Upload `ett-price-helper` to `/wp-content/plugins/` and activate it
2. Navigate to **WP Admin → EVE Trade Tools**
3. Follow the setup steps below

---

## Initial Setup

### 1. Configure the external database

Enter the host, port, database name, username, and password for a MySQL database **separate from your WordPress database**. Test and save the connection. The plugin creates and manages its own tables — the database should be dedicated to ETT data.

### 2. Run the Fuzzwork import

Click **Run Fuzzwork Import**. This downloads and imports six datasets from [fuzzwork.co.uk](https://www.fuzzwork.co.uk):

| Dataset | Purpose |
|---|---|
| `invMarketGroups` | Market group hierarchy |
| `invTypes` (CSV) | Item names, market group IDs, portionSize |
| `invMetaGroups` | Meta group names (T1, T2, Faction, etc.) |
| `invMetaTypes` | Per-item meta group assignments |
| `industryActivityProducts` | Blueprint manufacturing output mapping |
| `invTypeMaterials` | Reprocessing output materials and quantities |

Downloaded files are written to the WordPress uploads directory under `ett-price-helper/fuzzwork/` and are blocked from web access via `.htaccess` and `web.config`. Re-run this import whenever the Fuzzwork static data dump is updated.

### 3. Select market groups and generate type IDs

Choose the market groups you want to track, then click **Generate TypeIDs**. This builds the `ett_selected_typeids` table used by subsequent price pulls. Re-run this any time you change your market group selection.

### 4. Select trade hubs

Choose one or more NPC trade hubs. Prices will be fetched for the specific station at each selected hub.

**Supported hubs:**

| Key | Station |
|---|---|
| `jita` | Jita IV - Moon 4 - Caldari Navy Assembly Plant |
| `amarr` | Amarr VIII (Oris) - Emperor Family Academy |
| `rens` | Rens VI - Moon 8 - Brutor Tribe Treasury |
| `dodixie` | Dodixie IX - Moon 20 - Federation Navy Assembly Plant |
| `hek` | Hek VIII - Moon 12 - Boundless Creation Factory |

### 5. (Optional) Configure player structures

For each hub you can optionally configure a secondary and tertiary player structure ID. Structure orders are fetched using EVE SSO and appended to the hub's price data after the NPC station orders are processed.

To enable structure access, connect an EVE character under the SSO settings with the `esi-markets.structure_markets.v1` scope.

### 6. Configure the schedule and external cron

Set a daily start time (uses the WordPress site timezone) and a repeat frequency in hours. Use the **Pause / Resume** button to suspend scheduled runs without clearing your settings — in-progress jobs always complete normally.

**Scheduled runs require an external cron service.** The plugin no longer uses WP-Cron. Configure your cron service to send a request to the runner URL every minute:

```
https://your-site.com/?ett_ph_run=TOKEN
```

The full URL including the token is shown in the **Schedule** tab. Each ping processes work for the full PHP execution window before saving state; a 10–20 minute run completes across a handful of pings with no PHP timeout risk.

---

## Job Types

All jobs run via the same tick-based execution model. Each tick processes up to a configurable number of ESI pages within a configurable time budget, then saves state and yields until the next cron ping.

### `typeids`

Reads the selected market groups from WordPress options and rebuilds `ett_selected_typeids` in the external database. Completes in a single tick.

### `prices`

Phases:
1. **hub** — fetches region orders from ESI page by page for each selected hub (primary NPC station, then optional secondary and tertiary structures)
2. **adjusted** — fetches CCP's full adjusted price list from `GET /markets/prices/` and upserts results into `ett_adjusted_prices`

Prices are written via `INSERT … ON DUPLICATE KEY UPDATE` — rows are overwritten in-place rather than the table being wiped. Rows for types that return no orders in a run retain their previous values; the `fetched_at` timestamp on each row indicates when it was last updated.

A history job is created and queued automatically when a prices job completes successfully.

### `history`

Fetches 30-day rolling average daily volume per type per region using parallel `curl_multi` requests to `GET /markets/{region_id}/history/`. Requests are sent in sub-groups with a 500 ms gap between bursts to avoid ESI rate limits; if the `X-Esi-Error-Limit-Remain` header drops below 10, remaining items are skipped and a 60-second backoff is triggered. Results are upserted into `ett_market_history`. Rens and Hek share the Heimatar region (`10000030`) and are deduplicated — the region is fetched once and the result written under both hub keys.

---

## Performance Settings

| Setting | Default | Range | Description |
|---|---|---|---|
| Max pages per tick | 5 | 1–50 | ESI pages processed per cron tick or browser step |
| Max seconds per tick | 25 | 1–25 | Wall-clock time budget per tick |
| History fetch concurrency | 5 | 1–20 | Type IDs fetched in parallel per history tick |

---

## External Database Tables

All tables are created automatically by `ensure_schema()` on first job run. Schema migrations (e.g. adding `portionSize` to `ett_invTypes`) are applied automatically via `ALTER TABLE` on plugin load.

| Table | Populated by | Content |
|---|---|---|
| `ett_invMarketGroups` | Fuzzwork import | Market group hierarchy |
| `ett_invTypes` | Fuzzwork import | Item names, market group, portionSize |
| `ett_invMetaGroups` | Fuzzwork import | Meta group names |
| `ett_invMetaTypes` | Fuzzwork import | Per-item meta group assignment |
| `ett_industryActivityProducts` | Fuzzwork import | Blueprint-manufacturable type IDs |
| `ett_invTypeMaterials` | Fuzzwork import | Reprocessing materials and quantities per batch |
| `ett_selected_typeids` | Generate TypeIDs | Active type ID list with meta tier |
| `ett_prices` | Prices job | Best buy/sell per type per hub with volumes |
| `ett_market_history` | History job | 30-day average daily volume per type per hub |
| `ett_adjusted_prices` | Prices job | CCP adjusted and average prices per type |
| `ett_jobs` | All jobs | Job records with status, progress, and error detail |

---

## Tab Framework

Other ETT plugins can add tabs to the shared admin page by hooking into `ett_admin_tabs` and calling:
```php
ETT_Admin::register_tab('my-tab', 'My Tab Label', function () {
    // render tab content
});
```

This hook fires on the EVE Trade Tools admin page before tabs are rendered.

---

## Data Storage

### WordPress options (selected)

| Option | Content |
|---|---|
| `ett_extdb_settings` | External DB connection settings (password AES-256-CBC encrypted) |
| `ett_selected_market_groups` | Selected market group IDs |
| `ett_selected_hubs` | Selected hub keys |
| `ett_secondary_structures` / `ett_tertiary_structures` | Structure IDs per hub |
| `ett_sso_client_id` / `ett_sso_client_secret` + IV/MAC | EVE SSO application credentials |
| `ett_sso_access_token` / `ett_sso_refresh_token` + IV/MAC | SSO tokens (encrypted) |
| `ett_sched_start_time` / `ett_sched_freq_hours` | Scheduler config |
| `ett_sched_enabled` | Scheduler pause/resume state |
| `ett_ph_runner_token` | System-cron secret token |
| `ett_batch_max_pages` / `ett_batch_max_seconds` | Tick performance limits |
| `ett_history_batch_size` | History parallel fetch concurrency |
| `ett_ph_cron_prices_job_id` / `ett_ph_cron_history_job_id` | Active cron job IDs |

### On uninstall

All WordPress options listed above are deleted. The external database and all price data within it are **never touched** by uninstall.

---

## Encryption

Database password and SSO credentials are stored encrypted using AES-256-CBC with a per-value random IV and an HMAC-SHA256 MAC, with keys derived from WordPress's `AUTH_KEY` and `SECURE_AUTH_KEY` constants. Other ETT plugins that need to read these credentials use the `ETT_Crypto` class directly, and the encryption scheme is shared across the suite so values are portable between plugins without migration.

---

## Production Notes

- Configure an external cron service to ping `/?ett_ph_run=TOKEN` every minute — the URL and token are shown in the Schedule tab
- The external database should be dedicated to ETT data — the plugin does not modify or clean up external DB tables on uninstall
- Re-run the Fuzzwork import after each new Fuzzwork static data dump is published
- Re-run Generate TypeIDs after changing market group selection or after a Fuzzwork import
- The `bz2` PHP extension must be available for the Fuzzwork import to function
- Job records are pruned after 90 days

---

## License

GPLv2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)

---

*EVE Online and ESI are the property of CCP Games. This project is not affiliated with CCP Games.*
