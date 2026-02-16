=== EVE Trade Tools Price Helper ===
Contributors: c4813
Tags: eve online, esi, prices, market, admin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin-only tool to import Fuzzwork market groups/types and pull hub prices from ESI into an external database.

== Description ==
This plugin provides an admin UI to:
* Import required static data from Fuzzwork (market groups, types, meta groups/types, industry activity products)
* Configure hubs and structures
* Pull prices from EVE ESI and write them to an external database

== Installation ==
1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to WP Admin → ETT Prices.
4. Configure your external database connection and run the Fuzzwork import.

== Frequently Asked Questions ==
= Does uninstall delete my external database? =
No. Uninstall removes WordPress-side options/transients and cron hooks only.

== Changelog ==
= 1.0.0 =
* Initial public release.

== Upgrade Notice ==
= 1.0.0 =
Initial public release.
