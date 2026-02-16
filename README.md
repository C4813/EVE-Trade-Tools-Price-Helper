# EVE Trade Tools – Price Helper

A WordPress plugin that pulls live market price data from the EVE Online ESI API and stores it in an external database for use in tools, analytics, dashboards, or trading systems.

This plugin is designed for controlled, rate-aware price harvesting with manual and scheduled execution, fuzzwork data imports for market group/type generation, and optional authenticated structure price support via EVE SSO.

---

## Overview

**EVE Trade Tools – Price Helper** provides:

- Selectable market group → typeID generation
- Hub and structure price support
- Manual and scheduled price pulls
- External database storage (does NOT use WordPress tables for market data)
- Fuzzwork static data import support
- EVE SSO authentication for structure access
- Cron-driven incremental processing
- Rate-aware execution with backoff handling
- Job tracking and retention

It is designed to run reliably in production environments and to isolate market data storage from WordPress core tables.

The primary purpose of this plugin is to enable other plugins to call price data without requiring lengthy ESI calls. The other plugins can simply access the external database this plugin writes to and pull price data from there.

My intention is to redesign my existing plugins to both strengthen their code base, but also speed up their processes by allowing them to call an external database instead of running ESI calls every time something needs updating. As and when these are ready, ETT Price Helper will be updated to feature a market group preset toggle to automatically select the appropriate market groups based on which plugins you are running.

---

## Architecture Summary

The plugin consists of several functional components:

### Admin Interface (`class-ett-admin.php`)
- Settings configuration
- Market group selection
- Hub/structure selection
- Manual job control
- Fuzzwork import triggers
- SSO connect/disconnect
- Status display + notices

### Job System (`class-ett-jobs.php`)
- Scheduled start hook
- Tick-based processing
- Rate-limited incremental execution
- Active job tracking
- Job retention cleanup

### Fuzzwork Importer (`class-ett-fuzzwork.php`)
Imports static data from:
- invMarketGroups
- invTypes
- invMetaGroups
- invMetaTypes
- industryActivityProducts

Used to generate selectable market groups and derive valid typeIDs.

### External Database
All market prices are written to an external database defined in plugin settings.

**Important:** The plugin does NOT modify or delete external database data during uninstall. It is strongly recommended to use an empty and stand-alone database to store the price data.

---

## Features

- Manual price pulls (admin-triggered)
- Scheduled automatic pulls
- Hub market price support
- Player structure price support (via SSO)
- Fuzzwork data import
- Tick-based execution for rate safety
- Cron-safe execution model
- Clean uninstall (removes only WP-side data)
- PHPCS clean (WordPress standards compliant)

---

## Installation

1. Upload the plugin to `/wp-content/plugins/`
2. Activate via the WordPress admin
3. Navigate to: ```Settings → ETT Price Helper```

---

## Initial Setup

### 1. Configure External Database

Provide:
- Host (On shared hosting, this is usually ```localhost```)
- Database name
- Username
- Password

Test and save connection.

---

### 2. Run Fuzzwork Import

Before generating typeIDs, you must import static data.

In the admin panel:

- Run Fuzzwork import
- Wait for all datasets to complete

This generates:
- Market groups
- TypeIDs
- Meta mappings

**Important:** If this is not run, market group selection will not function. It is only necessary to run this once after first plugin activation, and again after the fuzzwork index dump latest is updated.

---

### 3. Select Market Groups

After Fuzzwork import:

- Select desired market groups
- Save configuration

These determine which typeIDs are generated.

---

### 4. Generate TypeIDs

Click: ```Generate TypeIDs```

This builds the internal list of typeIDs used for price pulls.

---

### 5. Configure Trade Hubs

Select one or more NPC trade hubs (e.g., Jita, Amarr, etc.).

These will be used for public market price pulls.

---

### 6. (Optional) Connect EVE SSO

If you want structure pricing:

1. Enter:
   - EVE SSO Client ID
   - EVE SSO Client Secret
2. Click **Connect**
3. Authorize via EVE Online
4. Save returned character + token

This allows authenticated structure price pulls.

---

## Running Price Pulls

### Manual Run

Click: ```Run Price Pull```

The job system will:

- Create a job record
- Process in ticks
- Respect rate limits
- Display progress live

Execution continues via cron ticks until complete.

---

### Scheduled Runs

You can configure scheduled execution:

- Example: Every 12 hours at 07:00 / 19:00
- Uses WordPress cron hooks:
  - `ett_ph_prices_scheduled_start`
  - `ett_ph_prices_tick`

**Important:** By default, WP-Cron relies on site traffic.  
For production reliability, use a **real system cron** calling: ```wp-cron.php``` or trigger WP CLI.

---

## Job Execution Model

The plugin uses:

- Incremental tick processing
- Stored active job ID
- Rate-aware API calls
- Backoff handling for ESI errors
- Heartbeat monitoring
- Job retention (90 days default)

No long-running PHP execution is required.

---

## Data Storage Model

### WordPress Stores:
- Settings
- Selected groups
- Selected hubs
- SSO tokens
- Job metadata
- Cron state

### External Database Stores:
- Price data
- Historical records (if implemented)
- Market snapshots

Uninstall removes:
- All WordPress options
- Cron events
- Job metadata

It does NOT modify:
- External database tables

---

## Security

- All form actions use nonce validation
- Output is escaped
- Tokens are stored securely
- No direct execution entry points
- PHPCS WordPress standards clean

---

## Uninstall Behavior

When deleted via WordPress Plugins screen:

Removes:
- Plugin options
- Stored tokens
- Cron schedules
- Job state

Does NOT:
- Touch external database
- Delete price data

---

## Reliability Notes

For production environments:

- Disable default traffic-based WP-Cron
- Use a system cron to call `wp-cron.php`
- Ensure PHP memory limit ≥ 256MB
- Ensure max_execution_time ≥ 60 seconds

---

## Development Notes

- WordPress coding standards compliant
- No MAC-level errors
- Rate-aware ESI usage
- Designed for extensibility

---

## Recommended Production Setup

- Real server cron
- External DB with proper indexing
- Regular Fuzzwork updates
- Monitoring on scheduled jobs
- ESI status awareness

---

## Use Cases

- Trading dashboards
- Industry profit calculators
- Arbitrage analysis
- Market alert systems
- Data warehousing
- Analytics pipelines

---

## Disclaimer

This project is not affiliated with CCP Games.

EVE Online and ESI are property of CCP Games.

I am not a developer. ChatGPT has done *all* of the heavy lifting here. Whilst I more or less understand *what* is happening, I would not have coded this myself from scratch.

---

## License

GPLv3 or later.


