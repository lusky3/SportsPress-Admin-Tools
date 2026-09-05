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
* New: the season rollover now automates the rest of the transition rather than stopping after calendars and rosters — including a season-wide pass that assigns every team to a division in one step.
* New: league table generator, available in wp-admin and shared with the League Manager dashboard.
* Player stats are surfaced up front on score entry, fixing a visibility bug that left the section unreachable.
* Rollover is idempotent: running it twice no longer duplicates the work of the first run.
* Event import reads dates in the site's timezone instead of the server's, and the standings asset URL resolves correctly when the plugin is not in the default directory.
* Imported CSV values are neutralised against spreadsheet formula injection, and uploads are checked by MIME type rather than by extension alone.
* Standings caching is versioned so a stale table is no longer served after an underlying change, and roster keys are seeded so an empty roster renders rather than erroring.
* Notification sending moved to an asynchronous cron handler that reads a stashed payload, so a slow mail server no longer blocks the request that triggered it.

= 1.0.0 =
* Initial release
* Auto-create calendars for new teams with configurable naming
* Bulk XLSX/CSV event import with flexible column matching
* Season rollover wizard with preview, calendar/roster creation, and event archiving
* Dynamic standings shortcode ([arl_standings]) with season/type filtering
* Calendar reset and missing calendar creation tools
* League Table Generator module scaffolding (placeholder; feature not yet available)
