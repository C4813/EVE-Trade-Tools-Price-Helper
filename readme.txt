=== EVE Trade Tools Price Helper ===
Contributors: c4813
Tags: eve online, esi, prices, market, admin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Admin-only tool to import Fuzzwork market groups/types and pull hub prices from ESI into an external database.

== Description ==

EVE Trade Tools Price Helper is an admin-only utility plugin for WordPress that integrates external EVE Online market data into a separate database.

It provides a controlled interface for:

* Importing static reference data from Fuzzwork (market groups, types, meta groups/types, industry activity products)
* Managing trade hubs and optional structure overrides
* Connecting to EVE Online via ESI (including SSO for structures)
* Running scheduled or manual price pulls
* Writing normalized pricing data into an external database

The plugin does not expose frontend functionality and does not modify the WordPress database schema beyond storing its own settings.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Navigate to **WP Admin → ETT Prices**.
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

= 1.0.1 =
* Moved inline styles into `admin.css` and removed redundant CSS rules.

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.0.1 =
Maintenance release improving admin UI stability and internal structure. No database changes.

= 1.0.0 =
Initial public release.
