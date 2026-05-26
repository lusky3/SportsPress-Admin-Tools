=== SportsPress Admin Tools ===
Contributors: Cody (lusky3)
Tags: sportspress, automation, sports
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL v2 or later

Extended tools for SportsPress

== Description ==

SportsPress Admin Tools is the parent framework plugin for a suite of SportsPress extensions. It provides a centralized settings interface, shared database utilities, and a plugin manager that coordinates child plugins.

**Child Plugins (installed separately):**

* **Player Registration** — Automatically create player records from WooCommerce orders.
* **e-Transfer Automation** — Webhook-based Interac e-Transfer payment matching.
* **Player Tools** — Email sync, captain selection, profile pictures, skill levels, batch roster upload.
* **Events Manager** — Bulk event import, auto-calendars, season rollover, dynamic standings.
* **Schedule Generator** — Multi-division round-robin schedule generation with constraints.
* **League Manager** — Task-oriented admin dashboard for non-technical league managers.

Each child plugin registers with this parent and loads only when its module is enabled in Settings → SportsPress Admin Tools.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/sportspress-admin-tools/`
2. Activate the plugin through the 'Plugins' screen in WordPress

== Changelog ==

= 1.0.0 =
* Initial release