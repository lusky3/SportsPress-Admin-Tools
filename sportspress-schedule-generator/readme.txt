=== SportsPress Schedule Generator ===
Contributors: lusky3
Tags: sportspress, schedule, league, round-robin, calendar
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Comprehensive league schedule generation with multi-division support, venue management, and advanced constraints. Requires SportsPress Admin Tools parent plugin.

== Description ==

SportsPress Schedule Generator automates the creation of complex sports league schedules for recreational leagues:

* **Multi-Division Support** - Handle multiple divisions with different teams and requirements.
* **Flexible Matchup Styles** - Single round-robin, double round-robin, or custom game counts.
* **Inter-Division Games** - Configure cross-division matchups with custom game counts per division pair.
* **Home/Away Balance** - Automatic balancing of home/away designations across all teams.
* **Venue Management** - Assign games across multiple venues with automatic distribution and CSV venue schedule import.
* **Blackout Dates** - Automatic avoidance of blackout dates during scheduling, per-venue or global.
* **Team Restrictions** - Back-to-back avoidance and overlap prevention for teams with shared resources.
* **Constraint System** - Hard and soft constraints with feasibility pre-checking.
* **Schedule Preview** - Review generated schedules with filtering by division, team, venue, and date.
* **Export** - CSV for data processing and styled XLSX for human reading.
* **SportsPress Import** - Direct import of generated schedules into SportsPress events with conflict detection.
* **Configuration Management** - Save, load, clone, import/export configurations with change tracking.
* **Preset Templates** - Quick start templates for youth, adult, and tournament leagues.
* **Progress Tracking** - Real-time progress indicators during generation with cancellation support.

== Installation ==

1. Install and activate SportsPress and SportsPress Admin Tools (parent plugin).
2. Upload the plugin folder to `/wp-content/plugins/`.
3. Activate the plugin through the WordPress admin.
4. Go to Settings → SportsPress Admin Tools.
5. Enable the "League Schedule Generator" module.
6. Navigate to Admin → Schedule Generator to configure and generate schedules.

== Frequently Asked Questions ==

= What matchup styles are supported? =

Single round-robin (each team plays every other team once), double round-robin (home and away), and custom game counts.

= Can I schedule games across multiple divisions? =

Yes. Configure inter-division games to specify how many cross-division matchups each team should play.

= What happens if the schedule can't be generated? =

The plugin validates configuration feasibility before generation and provides specific error messages about what needs to change (more time slots, fewer constraints, etc.).

= Can I import the generated schedule into SportsPress? =

Yes. The import dialog lets you choose conflict resolution (skip or overwrite), event status, league, and season. Import runs in chunks with progress tracking.

== Changelog ==

= 1.0.0 =
* Initial release
* Multi-division schedule generation with round-robin algorithms
* Venue management with CSV schedule import
* Blackout dates and team restriction constraints
* SportsPress event import with conflict detection
* CSV and XLSX export with filtering
* Configuration management with change tracking
* Preset templates for common league types
* Real-time generation progress with cancellation
