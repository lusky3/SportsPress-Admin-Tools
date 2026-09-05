=== SportsPress League Manager ===
Contributors: lusky3
Tags: sportspress, league, manager, roster, fees
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.1.0
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

Gated by the `manage_sportspress` capability — does NOT require `manage_options`. Any SportsPress manager (and administrators, who have `manage_sportspress`) can use it.

== Installation ==

1. Install and activate SportsPress and SportsPress Admin Tools (parent plugin).
2. Upload the plugin folder to `/wp-content/plugins/`.
3. Activate the plugin through the WordPress admin.
4. Go to Settings → SportsPress Admin Tools → Modules and enable the League Manager modules.
5. The "League Manager" menu appears in the WordPress admin sidebar.

== Frequently Asked Questions ==

= How do I give league managers access without full admin? =

Grant the user (or their role) the `manage_sportspress` capability — for example via a role editor plugin such as User Role Editor, or by assigning a SportsPress manager-level role. No `manage_options` access is required.

= Does the fee tracker require WooCommerce? =

WooCommerce is optional. Without it, fee tracking can be configured for manual entry or disabled entirely.

= What CSV format is required for roster uploads? =

CSV files with `Team` and `Name` columns (both required). Each row maps a player Name to a Team; rows missing either value are skipped.

== Changelog ==

= 1.1.0 =
**Registration and payments**

* New: a waitlist with timed claim offers. A spot stays reserved for whoever holds the offer until they take it or it runs out.
* Payments search, and a CSV export covering the whole set rather than just the page you're looking at.

**Discipline and player records**

* New: warning and suspension notices that go to players, with the delivery mode set per severity.
* New: a stat leaders page, and penalty tracking for discipline.
* Click a skill level to see who's in it.
* Delete a player note from the dashboard, and drop a roster file onto the page to upload it.

**Season management**

* New: a season audit for conveners. It surfaces configuration problems and offers to fix them in one click.
* New: Season Setup rebuilt around divisions, with a box per division for its teams.
* New: a league table generator, shared with Events Manager.
* Season Report rebuilt with standings, a per-division breakdown, and registration figures.

**Dashboard**

* New: at-a-glance stat tiles, a Help page pulling everything together with "?" links per section, and Score Sheets help without leaving the dashboard.
* Schedule view gains filters, pagination, view and edit actions, and import help.
* Dropped the SportsPress Pro chrome. Added links back to the site and to wp-admin.

**Score sheets**

* Reprocess a sheet that failed, and duplicate audit rows stick around rather than vanishing.
* Provider errors surface with a "Test connection" check rather than failing quietly.

**Fixes**

* Standings no longer count SportsPress's reserved totals row as a team.
* "Calculate Skills" returned 503 because it called a Player Tools class and method that Player Tools had since renamed.
* Leaders come from box scores. The report standings were unreliable, so they're gone.
* Player and team names containing entities render properly in the React dashboard instead of showing raw HTML.
* Delegated game routes check that the events_management module is on.
* CSV column mapping corrected, roster handling degrades gracefully on malformed input, and batch score submission has a cap.
* Code chips in Help are legible in dark mode. They were dark on dark.

= 1.0.0 =
* Initial release
* Dashboard with league overview and health check
* Roster manager with CSV upload and preview
* Fee tracker with WooCommerce integration and CSV export
* Health check for common SportsPress configuration issues
* First-run wizard for new league managers
* Contextual help tooltips and WordPress help tabs
* Gated by the manage_sportspress capability (no manage_options required)

== AI Usage Disclaimer ==

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.
