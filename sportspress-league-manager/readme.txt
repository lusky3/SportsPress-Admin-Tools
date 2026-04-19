=== SportsPress League Manager ===
Contributors: lusky3
Tags: sportspress, league, manager, roster, fees
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A clean, task-oriented admin interface for league managers who find the WordPress admin intimidating. Requires SportsPress Admin Tools parent plugin.

== Description ==

SportsPress League Manager surfaces common league management tasks in a single, guided interface that doesn't require full admin access:

* **Dashboard** - At-a-glance overview: team count, player count, upcoming games, fee summary, and health check.
* **Roster Manager** - View team rosters and upload new ones via CSV with preview step and drag-and-drop upload.
* **Fee Tracker** - See which players have paid league fees. Integrates with WooCommerce orders for automatic tracking. Search and CSV export.
* **Health Check** - Diagnoses common SportsPress issues: missing league/season assignments, inactive plugins, missing default season.
* **First-Run Wizard** - 3-step onboarding: select league, verify teams, run health check. Dismissible and re-accessible.
* **Contextual Help** - Inline tooltips on every page element and WordPress help tabs for page-level guidance.
* **Player Notes** - Add private, timestamped notes to player records. Meta box on player edit screen, AJAX-powered, and frontend display for admins.

Uses a custom `manage_league` capability — does NOT require `manage_options`. Administrators get the capability automatically.

== Installation ==

1. Install and activate SportsPress and SportsPress Admin Tools (parent plugin).
2. Upload the plugin folder to `/wp-content/plugins/`.
3. Activate the plugin through the WordPress admin.
4. Go to Settings → SportsPress Admin Tools → Modules and enable the League Manager modules.
5. The "League Manager" menu appears in the WordPress admin sidebar.

== Frequently Asked Questions ==

= How do I give league managers access without full admin? =

Install a role editor plugin (e.g., User Role Editor) and add the `manage_league` capability to the desired user or role.

= Does the fee tracker require WooCommerce? =

WooCommerce is optional. Without it, fee tracking can be configured for manual entry or disabled entirely.

= What CSV format is required for roster uploads? =

CSV files with columns: name (required), number (optional), position (optional), email (optional).

== Changelog ==

= 1.0.0 =
* Initial release
* Dashboard with league overview and health check
* Roster manager with CSV upload and preview
* Fee tracker with WooCommerce integration and CSV export
* Health check for common SportsPress configuration issues
* First-run wizard for new league managers
* Contextual help tooltips and WordPress help tabs
* Custom manage_league capability
