=== SportsPress Events Manager ===
Contributors: lusky3
Tags: sportspress, events, calendar, import, season
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calendar management, bulk event import, dynamic standings, and season rollover tools for SportsPress. Requires SportsPress Admin Tools parent plugin.

== Description ==

SportsPress Events Manager adds event and season management tools to SportsPress:

* **Auto-Create Calendars** - Automatically creates a calendar when a new team is saved, with configurable naming (prefix, suffix, separator, division).
* **Bulk Event Import** - Upload XLSX or CSV files to create SportsPress events. Flexible column matching, auto-creates missing teams/venues/leagues.
* **League Table Generator** *(coming soon)* - Create league tables with teams pre-populated from league and season filters. Not yet available; the module is a placeholder pending a future release.
* **Season Rollover Wizard** - Guided workflow to transition teams between seasons. Creates new season, assigns teams, optionally creates calendars and rosters, archives old events.
* **Dynamic Standings** - Frontend shortcode (`[arl_standings]`) with AJAX-powered season and type filtering for league tables.
* **Calendar Tools** - Bulk create missing calendars and reset all calendars to the current season.

== Installation ==

1. Install and activate SportsPress and SportsPress Admin Tools (parent plugin).
2. Upload the plugin folder to `/wp-content/plugins/`.
3. Activate the plugin through the WordPress admin.
4. Go to Settings → SportsPress Admin Tools.
5. Enable desired modules: Events Management, Season Rollover, Dynamic Standings.

== Frequently Asked Questions ==

= What file formats are supported for event import? =

XLSX and CSV files with at minimum Date, Home Team, and Away Team columns. Optional columns include Time, Venue, and League.

= What happens to teams and venues that don't exist yet? =

They are automatically created as SportsPress posts/terms during import.

= Can I undo a season rollover? =

No. The rollover creates new season terms and updates team assignments. Preview your teams before executing and use the archive option carefully.

== Changelog ==

= 1.1.0 =
* Season rollover finishes the job now. It used to stop after calendars and rosters; it carries on through the rest of the transition, including a single pass that puts every team into a division.
* New: a league table generator in wp-admin, shared with the League Manager dashboard.
* Score entry shows player stats up front. A visibility bug had left that section unreachable.
* Run the rollover twice and the second run does nothing, instead of duplicating the first.
* Event import reads dates in the site's timezone rather than the server's, and the standings asset URL resolves even when the plugin sits outside the default directory.
* CSV imports neutralise spreadsheet formula injection, and uploads get checked on MIME type rather than extension alone.
* Standings caches carry a version, so editing the underlying data no longer leaves a stale table on the page. An empty roster renders instead of erroring.
* Notifications go out through a cron handler that picks up a stashed payload, so a slow mail server stops holding up the request that triggered it.

= 1.0.0 =
* Initial release
* Auto-create calendars for new teams with configurable naming
* Bulk XLSX/CSV event import with flexible column matching
* Season rollover wizard with preview, calendar/roster creation, and event archiving
* Dynamic standings shortcode ([arl_standings]) with season/type filtering
* Calendar reset and missing calendar creation tools
* League Table Generator module scaffolding (placeholder; feature not yet available)
