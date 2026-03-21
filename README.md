# EVE Trade Tools Price Helper

A WordPress plugin that imports EVE Online static data from the official EVE Static Data Export (SDE) and pulls live market prices from ESI into an external MySQL database, making that data available to other plugins without requiring repeated ESI calls.

Part of the EVE Trade Tools suite. This is the base plugin — other ETT plugins depend on it for database access, SSO credentials, and the shared admin page.

---

## Features

- **SDE static data import** — uploads and imports the official EVE SDE ZIP from [developers.eveonline.com](https://developers.eveonline.com/static-data), populating market groups, item types, meta groups, meta types, manufacturing outputs, reprocessing materials, and solar system data into the external database. Import runs as six sequential AJAX calls (one YAML file each) with a live progress bar and per-table row counts
- **Hub price pulls** — fetches buy/sell orders from ESI for up to five NPC trade hubs; prices are upserted in-place rather than wiped, so consumers always have data
- **Private trade hubs** — configure one or more private market structures (alliance citadels, Upwell structures) as additional trade hubs, each with independent character authentication and structure selection
- **Structure price support** — optional secondary and tertiary player structure per NPC hub, fetched via EVE SSO; 401/403 responses are skipped cleanly rather than retried
- **Adjusted prices** — fetches CCP's adjusted price list from ESI after each hub run and stores it for use in reprocessing tax calculations
- **Market history** — fetches 30-day rolling average daily volume per type per region via parallel ESI requests; hubs sharing a region are deduplicated and fetched once
- **System cron runner** — jobs are driven by an external cron service pinging `/?ett_ph_run=TOKEN` each minute; each ping works for the full PHP execution window before saving state and resuming on the next ping
- **Configurable scheduler** — set a daily start time and repeat frequency (1–168 hours); pause and resume without losing settings
- **Manual job control** — start, monitor, and cancel jobs from the admin page with live progress updates
- **Job history** — completed jobs retained for 90 days with full progress and error detail
- **ESI error handling** — rate limit backoff (HTTP 429/420) with `Retry-After` support; transient errors retry after 5 seconds
- **Changelog tab** — automatically detects all active ETT plugins and renders their changelogs with GitHub links on a dedicated admin tab
- **Tab framework** — exposes `ETT_Admin::register_tab()` so other ETT plugins can add their own tabs to the shared admin page
- **Encrypted credential storage** — SSO Client Secret and database password stored using AES-256-CBC with HMAC-SHA256, keyed from WordPress secret constants

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0 or later |
| PHP | 8.0 or later |
| PHP extension | `zip` (required for SDE import) |
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

Once the connection is confirmed, the SDE import options appear immediately in the EVE SDE Import card without requiring a page refresh.

### 2. Run the SDE import

Download the EVE Static Data Export ZIP from [developers.eveonline.com/static-data](https://developers.eveonline.com/static-data) (~40 MB compressed), then use one of the two import options:

**Option A — Upload ZIP:** Upload the ZIP directly via the browser. Requires `upload_max_filesize` and `post_max_size` in `php.ini` to permit files of at least 50 MB.

**Option B — Server-side path:** If your host imposes strict upload limits, place the ZIP on the server via SSH or FTP and enter its absolute path (e.g. `/home/user/sde.zip`).

The import runs as six sequential AJAX calls. A progress bar and step log update live as each file is processed:

| File | Table(s) populated | Required |
|---|---|---|
| `marketGroups.yaml` | `ett_invMarketGroups` | Yes |
| `metaGroups.yaml` | `ett_invMetaGroups` | Yes |
| `types.yaml` | `ett_invTypes`, `ett_invMetaTypes` | Yes |
| `typeMaterials.yaml` | `ett_invTypeMaterials` | Yes |
| `blueprints.yaml` | `ett_industryActivityProducts` | Yes |
| `mapSolarSystems.yaml` | `ett_mapSolarSystems` | No |

`mapSolarSystems.yaml` is optional — if absent from the ZIP the other five files still import normally. It is required for private hub system name autocomplete and canonical in-game name display. All files are located by basename so any internal ZIP structure is handled automatically. No PHP YAML extension is required — the importer uses custom streaming line-by-line parsers.

The Market Groups card populates automatically when the import completes. Re-run this import whenever CCP releases an updated SDE.

### 3. Select market groups and generate type IDs

Choose the market groups you want to track, then click **Generate TypeIDs**. This builds the `ett_selected_typeids` table used by subsequent price pulls. Re-run this any time you change your market group selection.

### 4. Select trade hubs

Choose one or more NPC trade hubs. Prices will be fetched for the specific station at each selected hub. You may deselect all standard hubs if you intend to run with private hubs only.

**Supported hubs:**

| Key | Station |
|---|---|
| `jita` | Jita IV - Moon 4 - Caldari Navy Assembly Plant |
| `amarr` | Amarr VIII (Oris) - Emperor Family Academy |
| `rens` | Rens VI - Moon 8 - Brutor Tribe Treasury |
| `dodixie` | Dodixie IX - Moon 20 - Federation Navy Assembly Plant |
| `hek` | Hek VIII - Moon 12 - Boundless Creation Factory |

### 5. (Optional) Configure player structures

For each NPC hub you can optionally configure a secondary and tertiary player structure. Structure orders are fetched using EVE SSO and appended to the hub's price data after the NPC station orders are processed.

To enable structure access, connect an EVE character under the EVE SSO card with the `esi-markets.structure_markets.v1` scope.

### 6. (Optional) Configure private trade hubs

Private trade hubs let you pull market data from structures in any system — useful for alliance citadels or private markets not covered by the standard five hubs.

For each private hub:
1. Select the **Market Character** — either the primary SSO character or a separate private character (authenticated independently under this card). Use a separate character if the primary is not permitted to dock in the target structure.
2. Enter a **System Name** — autocomplete is powered by `ett_mapSolarSystems` and shows canonical in-game names and region as you type (requires `mapSolarSystems.yaml` to have been imported).
3. Click **Fetch Structures** to retrieve all structures in that system accessible to the selected character.
4. Check the structures you want to include in the price pull.
5. Click **Save Hub**.

Multiple private hubs are supported; each can be independently added or removed. Private hub prices appear in `ett_prices` under the system name as the hub key (e.g. `c-n4od`) and are automatically available to other ETT plugins such as ETT Reprocess Trading.

### 7. Configure the schedule and external cron

Set a daily start time (uses the WordPress site timezone) and a repeat frequency in hours. Use the **Pause / Resume** button to suspend scheduled runs without clearing your settings — in-progress jobs always complete normally.

**Scheduled runs require an external cron service.** The plugin does not use WP-Cron. Configure your cron service to send a request to the runner URL every minute:

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
1. **hub** — fetches region orders from ESI page by page for each selected hub (primary NPC station, then optional secondary and tertiary structures, then any configured private hubs)
2. **adjusted** — fetches CCP's full adjusted price list from `GET /markets/prices/` and upserts results into `ett_adjusted_prices`

Prices are written via `INSERT … ON DUPLICATE KEY UPDATE` — rows are overwritten in-place rather than the table being wiped. Rows for types that return no orders in a run retain their previous values; the `fetched_at` timestamp on each row indicates when it was last updated.

A history job is created and queued automatically when a prices job completes successfully.

### `history`

Fetches 30-day rolling average daily volume per type per region using parallel `curl_multi` requests to `GET /markets/{region_id}/history/`. Requests are sent in sub-groups with a 500 ms gap between bursts to avoid ESI rate limits; if the `X-Esi-Error-Limit-Remain` header drops below 10, remaining items are skipped and a 60-second backoff is triggered. Results are upserted into `ett_market_history`. Hubs sharing a region are deduplicated — the region is fetched once. Private hub regions are included automatically based on the system's region ID.

---

## Performance Settings

| Setting | Default | Range | Description |
|---|---|---|---|
| Max pages per tick | 5 | 1–50 | ESI pages processed per cron tick or browser step |
| Max seconds per tick | 25 | 1–25 | Wall-clock time budget per tick |
| History fetch concurrency | 5 | 1–20 | Type IDs fetched in parallel per history tick |

---

## External Database Tables

All tables are created automatically by `ensure_schema()` on first job run.

| Table | Populated by | Content |
|---|---|---|
| `ett_invMarketGroups` | SDE import | Market group hierarchy |
| `ett_invTypes` | SDE import | Item names, market group, portionSize |
| `ett_invMetaGroups` | SDE import | Meta group names |
| `ett_invMetaTypes` | SDE import | Per-item meta group assignment |
| `ett_industryActivityProducts` | SDE import | Blueprint-manufacturable type IDs |
| `ett_invTypeMaterials` | SDE import | Reprocessing materials and quantities per batch |
| `ett_mapSolarSystems` | SDE import (optional) | Solar system IDs, canonical names, and region IDs |
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

This hook fires on the EVE Trade Tools admin page before tabs are rendered. A **Changelog** tab is always added last by Price Helper itself, automatically detecting all active `ett-*` plugins and rendering their changelogs.

---

## Data Storage

### WordPress options (selected)

| Option | Content |
|---|---|
| `ett_extdb_settings` | External DB connection settings (password AES-256-CBC encrypted) |
| `ett_sde_last_import_meta` | Timestamp and row counts from the last SDE import |
| `ett_selected_market_groups` | Selected market group IDs |
| `ett_selected_hubs` | Selected hub keys |
| `ett_secondary_structures` / `ett_tertiary_structures` | Structure IDs per NPC hub |
| `ett_private_hubs` | Private hub configurations (system, region, structures, char source) |
| `ett_priv_access_{N}` / `ett_priv_refresh_{N}` + IV/MAC | Per-private-hub SSO tokens (encrypted) |
| `ett_priv_expires_{N}` | Per-private-hub token expiry timestamp |
| `ett_priv_char_id_{N}` / `ett_priv_char_name_{N}` | Per-private-hub authenticated character |
| `ett_sso_client_id` / `ett_sso_client_secret` + IV/MAC | EVE SSO application credentials |
| `ett_sso_access_token` / `ett_sso_refresh_token` + IV/MAC | Primary SSO tokens (encrypted) |
| `ett_sched_start_time` / `ett_sched_freq_hours` | Scheduler config |
| `ett_sched_enabled` | Scheduler pause/resume state |
| `ett_ph_runner_token` | System-cron secret token |
| `ett_batch_max_pages` / `ett_batch_max_seconds` | Tick performance limits |
| `ett_history_batch_size` | History parallel fetch concurrency |
| `ett_ph_cron_prices_job_id` / `ett_ph_cron_history_job_id` | Active cron job IDs |

### On uninstall

All WordPress options listed above are deleted, including all per-private-hub token options for indices 1–20. The external database and all price data within it are **never touched** by uninstall.

---

## Encryption

Database password and SSO credentials are stored encrypted using AES-256-CBC with a per-value random IV and an HMAC-SHA256 MAC, with keys derived from WordPress's `AUTH_KEY` and `SECURE_AUTH_KEY` constants. Other ETT plugins that need to read these credentials use the `ETT_Crypto` class directly, and the encryption scheme is shared across the suite so values are portable between plugins without migration.

---

## Production Notes

- Configure an external cron service to ping `/?ett_ph_run=TOKEN` every minute — the URL and token are shown in the Schedule tab
- The external database should be dedicated to ETT data — the plugin does not modify or clean up external DB tables on uninstall
- Re-run the SDE import whenever CCP releases an updated Static Data Export; include `mapSolarSystems.yaml` in the ZIP for private hub support
- Re-run Generate TypeIDs after changing market group selection or after an SDE import
- The `zip` PHP extension must be available for the SDE import to function
- Uploaded ZIPs are stored temporarily in `uploads/ett-price-helper/sde-tmp/` during import and deleted automatically on completion or failure; the directory is blocked from web access via `.htaccess`
- Job records are pruned after 90 days
- Private hub characters must have docking access to the target structures; ESI returns 401/403 for inaccessible structures and the plugin skips them cleanly

---

## License

GPLv2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)

---

*EVE Online and ESI are the property of CCP Games. This project is not affiliated with CCP Games.*
