=== SportsPress Schedule Generator ===
Contributors: lusky3
Tags: sportspress, schedule, league, round-robin, calendar
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: sportspress-admin-tools
Stable tag: 1.3.7
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

= 1.3.7 =
* Fix: a season sized to exactly its available slot count (e.g. 32 teams x 4 games = 64 games into 64 slots over five weeks, two of them shortened by blackout dates) failed with "Could not allocate all games" even though the feasibility check passed and a complete schedule existed. Under the one-game-per-team-per-week rule a team with N games left needs N distinct weeks it hasn't played in, and a full week can only be filled by teams that haven't played in it yet -- the allocator had no notion of that, so it spent the short weeks on teams that could afford to skip a full week and then had nowhere to put the ones that couldn't. Placement now rejects any slot that would leave the rest of the season impossible to complete, the backtracking search places the most constrained matchup first instead of walking the list in order, and it branches on which date a game goes on rather than re-trying every time slot under a doomed week choice (which is what made the old search run out of budget). Verified on the real 64-game case: every slot filled, every team at exactly 4, no double-headers, no restriction violations. Seasons with spare capacity are unaffected.

= 1.3.6 =
* Fix: saving a regular-season configuration could be rejected as "insufficient capacity" (a hard error) at a games-per-team/season-length combination that would actually generate a schedule cleanly. The save-time check and the generate-time feasibility check compute games-needed and slots-available identically, but the save-time check additionally required staying under 80% of raw capacity while generation itself only required not exceeding 100% -- so a configuration could fail to save even though it was fully feasible. The 80% line is now a warning (shown as "capacity is tight"), and the hard error only fires when games needed actually exceed slots available, matching what generation itself allows.
* Fix: a postseason configuration silently dropped several of the source season's settings instead of reusing them -- per-venue time grids, per-venue blackout dates, date-specific venue overrides, match length, team restrictions (back-to-back/overlap avoidance), division grouping, and day-balance preferences all fell back to bare defaults instead of the values already configured on the regular season. Blackout dates are now carried over too, but only the ones that actually fall inside the postseason bracket's own date range (copying the regular season's blackout dates verbatim would, by construction, almost always fall outside the postseason window and fail validation).

= 1.3.5 =
* Fix: with two venues, the first-listed one was reliably filled to capacity every week while the second sat mostly unused (some weeks 0 games, others a handful, no pattern). The slot allocator's cost function had no venue-specific term at all, so a slot at either venue on the same date scored identically -- ties always fell to whichever venue was listed first. It now costs more to add a game to a venue that's already fuller (relative to its own capacity that day) than to one with room, so both venues get used as they fill rather than one being exhausted before the other is ever touched. Verified against a real 272-game season: the second venue's average weekly Friday usage went from 30% to 55%, with zero weeks at 0% (previously 6 of 23).

= 1.3.4 =
* Fix: the postseason final week's placeholder team names read "Division 1 RR-Seed 1", "Division 1 RR-Seed 2", etc. -- confusing on top of already being an internal seed number, since the same configuration already calls these games "Championship" and "Consolation" (the Championship Day/Consolation Day settings). They now read "Division 1 Championship A"/"...B" for the Championship pairing, and "Division 1 Consolation 1 A"/"...B", "Division 1 Consolation 2 A"/"...B", etc. for every pairing after that. Cross round-robin week placeholder names ("Division 1 Seed 1"..."N") are unchanged.
* Fix: pressing "Create" on the classic admin page's postseason panel appeared to do nothing when the configuration failed validation -- the error was written to a message area that lives in the (hidden, inactive) Generate Schedule tab, only becoming visible once you happened to switch to it. The panel now has its own visible status area.
* Fix: that validation failure also only ever showed the generic "Configuration validation failed", with no indication of what to actually fix. The specific reason (e.g. "Division 4 has an odd number of teams; postseason brackets require an even division size") is now shown.
* Fix: a postseason configuration copied from a source with a Generic Teams roster-filling policy enabled dropped that setting entirely, so a division whose real team count was odd but relied on a generic placeholder teammate to round out to an even number (the same fixup the regular season already applies) was wrongly rejected as unsupported, and would have failed again at schedule-generation time regardless. Postseason creation and generation now both account for the generic placeholder the same way the regular season does.

= 1.3.2 =
* Fix: a postseason configuration no longer asks for its own separate "Season Start" date. A postseason bracket is the tail end of the same season the source configuration already describes, so its start date is now always derived automatically as the day after the source's Season End -- both the classic admin page and the React dashboard show the resulting date range instead of asking for one.

= 1.3.1 =
* Fix: the classic WP Admin "Schedule Generator" page had no postseason/playoffs options at all -- the feature only ever reached the League Manager React dashboard. The classic page's Basic Configuration tab now has a "Create Postseason Configuration" action (round-robin weeks, Championship day/time-window, Consolation day) for turning a saved regular-season configuration into a postseason one, and editable Postseason Settings for revisiting those values on a postseason configuration afterward. Postseason configurations are also marked with a 🏆 in the configuration dropdown.

= 1.3.0 =
* New: postseason/playoffs bracket generation. Configure a round-robin count, a Championship/Consolation day, and a "prime time" window, and the generator builds the full cross-round-robin-into-bracket structure automatically -- seeding real teams in from season standings once the round-robin weeks conclude, and pinning the Championship/Consolation week to the trailing week of the postseason's own dates (a hard constraint, verified against your actual venue/time-slot capacity before you can save the configuration).
* New: schedules can be saved as a draft and revisited before importing, with a change-history view of what each save changed; the React dashboard now has full parity with the classic admin page for draft handling.
* New: date-specific venue time overrides (e.g. a holiday date with a shortened window at one arena), a warning when a fully-available week still leaves a team unplayed, and a preference for scheduling teams that share a restricted resource on the same day rather than spreading them across the week.
* Fixed: a hidden 15-minute buffer was silently halving venue slot capacity in feasibility checks.
* Fixed: same-week team double-headers weren't hard-blocked -- every team now plays once per week whenever the arena is available both configured days, with a double-header only as a last resort.
* Fixed: per-team day-balance could drift 20-30 points off the configured day split (e.g. a 70/30 Friday/Sunday season) even though no single team was ever fully monopolized to one day.
* Fixed: a venue's explicit "no slots this day" setting was incorrectly falling back to the global time-slot grid instead of staying empty, generating games on days that venue never actually has open.
* Fixed: a configuration authored directly via the REST API (bare team IDs, no dedicated "create" UI existed yet) failed to resolve those IDs to real team names on load.
* Fixed: Configuration Name wasn't loaded or saved correctly, so "Save" silently created a duplicate configuration instead of updating the existing one, and the load dropdown went stale.
* Fixed: custom Day Weights were being dropped on save by a double-sanitize bug.
* Fixed: "Generic Teams" could leave one team a game short of its target whenever a division's real roster was already an odd number at or above the configured per-division target.
* Fixed: the Placeholder Teams tab never populated, so importing a generated schedule that used placeholder teams into SportsPress never actually worked.
* Fixed: the compact XLSX export showed the wrong day of week for every game, and the detailed export's Week column was empty.
* Fixed: the schedule preview didn't show Time, wasn't sorted by date/time by default, and had no way to group rows by arena.

= 1.1.1 =
* Fix: the parent-plugin requirement now uses WordPress's own `Requires Plugins` header instead of a made-up `Depends:` header that WordPress never actually read -- this plugin now shows up correctly in the parent's "Required by" list and gets native activation-order enforcement, not just this plugin's own runtime check.

= 1.1.0 =
* New: this plugin now updates itself through the normal WordPress Plugins screen, sourced from the project's GitHub releases.
* New: a back-to-back restriction you can set from the admin, so a team won't play on consecutive days and nobody has to hand-edit configuration to stop it.
* New: venue CSV import, per-day weighting, and rules for spreading games across dates and venues.
* New: a REST `/export/csv` route, plus a change-history viewer showing what a draft changed between saves.
* New: the last of the wp-admin features moved into the React dashboard.
* Corrected the allocator's backtracking validation cache, which could accept a schedule that violated a constraint it had already checked. Constraint checks follow cascades now, and a finished schedule isn't validated a second time.
* A cancelled allocation and one that timed out look different in the log. They used to surface identically, which made a stuck run hard to tell from an abandoned one.
* Overlap restrictions come back when you edit a configuration, rather than quietly emptying themselves.
* Exported CSV values neutralise spreadsheet formula injection, and export filenames carry enough entropy that one doesn't give away the next.
* Cloning a draft goes through `save_draft`, so validation the original already passed doesn't block the copy. Edits made in the dashboard turn up in history.
* Hardened the REST routes, fixed the venue cache reset, and deprecated the legacy `splm/v1/schedule/*` endpoints.

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
