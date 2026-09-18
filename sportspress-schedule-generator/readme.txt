=== SportsPress Schedule Generator ===
Contributors: lusky3
Tags: sportspress, schedule, league, round-robin, calendar
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: sportspress-admin-tools
Stable tag: 1.3.10
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

= 1.3.10 =
* Fix: in a double round-robin the two meetings of every pair were scheduled in consecutive weeks. They are now a full rotation apart, as the matchup order always intended.
* Fix: "Custom" matchup style combined with inter-division games passed validation but could never generate ("Team has 7 games but expected 6"). Inter-division games now count toward each team's games-per-team total.
* Fix: inter-division games are spread so every team in a division plays the same number (within one), which the generator's own count check requires; some division sizes previously failed generation after a clean validation.
* Fix: inter-division games now carry full team records, so team restrictions apply to them, statistics count them, and SportsPress import recognizes their teams.
* Fix: Generate, Validate and Export now act on the configuration shown on the page. Previously they used whichever configuration was saved most recently, and the REST API silently substituted another configuration when the requested one did not exist (it now returns 404). An unsaved configuration must be saved before it can be generated, validated or exported.
* Fix: validation and the pre-generation feasibility check now count the games the chosen format actually produces. Round-robin seasons whose "games per team" was set higher than the format needs were wrongly blocked as "not enough time slots".
* Fix: a season with more weeks than rounds now spreads every division's games across the whole season; two-division seasons previously finished up to a third early.
* Fix: the game-by-game scheduler now compares the nearest playing dates when placing a game instead of only the first slots of one date, so day balance can move a game between a Friday and a Sunday.
* Fix: "Custom" matchup style rotates opponents; a team no longer replays its previous opponent while other opponents are still available.
* Fix: postseason Championship time windows compare times numerically, so "9:00" is treated the same as "09:00"; postseason day names are normalised on save; the final-week capacity check counts placeholder-padded teams.
* Fix: the settings-page "Balance time slots" and "Balance home/away" toggles now apply to REST-driven generation even when no day weights are set.
* Fix: venue-utilization warnings compare each venue against its own slot capacity, matching how the scheduler balances venues.
* Fix: form input is unslashed before saving (names with apostrophes no longer gain backslashes); backend settings are clamped server-side; SportsPress import only trusts real venue ids; venue-schedule CSV import writes to the configuration on screen.
* Removed: the never-configurable Day Cap constraint and the unused legacy venue-import endpoint.
* Maintenance: plugin uninstall removes drafts, weights and placeholder teams; assorted escaping and translation fixes.
* New: a ninth Advanced slider, "Double-Header Avoidance", controls how strongly a team is kept from playing twice on one date; the former "Restricted-Pair / Overlap Avoidance" slider is now "Restricted-Pair Same-Day Bonus" and covers only that bonus. An existing Overlap Avoidance value is copied to the new slider on upgrade.
* Fix: a postseason bracket's dates now start on the Monday after the regular season ends, so its round-robin and final weeks line up with the scheduler's Mon-Sun weeks and get the same rounds-first placement as the regular season; existing postseason configurations are moved forward to the next Monday automatically on upgrade (their end date follows); a bracket whose dates are later edited off a Monday is rejected by validation.
* Fix: postseason Championship, Consolation and cross-round-robin games are identified by an explicit stage marker rather than by pattern-matching team names, so a real team called e.g. "Hawks Seed 7" is never mistaken for a placeholder.
* Fix: saving a configuration in the classic admin now redirects after saving, so refreshing the page no longer re-submits the form; the Advanced-weights settings script and style are enqueued as plugin assets.
* Fix: the classic admin no longer reports "Configuration saved successfully" when the database write failed.
* Removed: the makeup-game scheduling code, which could never run (blackout dates are excluded before any slot exists). The Makeup export columns remain; the code is recoverable from the repository history as noted in docs/DEVELOPMENT-HISTORY.md.

= 1.3.9 =
* New: an opt-in "Advanced" section in Settings > SportsPress Admin Tools > Schedule Generator exposes 8 sliders to fine-tune how strongly the algorithm favors each kind of balance (day/Sunday split, time-of-night, season pacing, venue utilization, preferred-venue priority, division grouping, and restricted-pair avoidance), each 0-200% of the algorithm's own built-in weight, with a one-click reset to defaults. Off by default -- an install that never visits Advanced schedules exactly as it did before.
* Fix: the "Balance Time Slots" setting was saved and shown as a checkbox but the algorithm never actually read it, so turning it off changed nothing. It now genuinely disables time-of-night balancing when unchecked.

= 1.3.8 =
* New: the generated schedule's Detailed Statistics now report the balances a real season review kept computing by hand -- each team's Friday-vs-Sunday (or whatever playing days are configured) split, early/middle/late-third start counts plus first-and-last-of-the-night counts, each division's grouping percentage (how often its games land within an hour of another of its own that night), and every configured overlap/back-to-back restricted pair's shared nights and smallest gap. A division whose grouping percentage drops below 60% is now flagged as an imbalance, the same way an uneven games-per-team or home/away split already was.
* Fix: the distribution constraint scored each start time by its own literal clock value, so 21:45, 22:45 and 23:00 on the same night were treated as three unrelated slots instead of the related late-night starts they are. It now builds one shared timeline per night across every venue and scores a start by its position on that timeline -- early, middle, or late third, plus whether it's the very first or very last start of the night -- and charges a team only for exceeding its fair share of early or late starts.
* Fix: the Friday/Sunday day-balance target was scored against the operator's configured split even when the season's slot supply couldn't actually deliver it (e.g. a 70/30 split when Friday only holds 62.5% of the season's slots), so whichever teams reached the configured share first pushed every leftover Sunday onto the rest. SPSG_Schedule_Helper::feasible_day_ratios() now caps each day's target at what the supply can actually give every team and hands the excess to the days that still have room, so the day-balance cost reflects a target the season can actually meet.
* Fix: division grouping is now scored across both pads, by hours of separation from the division's other games that night, and the rounds-first week placer now places the rest of a division's games right after its first one so they have a chance to stack.
* New: after a week's games are placed, pairs of games within that week swap slots whenever both stay valid and the swap lowers the week's soft cost.
* Fix: the allocator now hands the season's slot grid to constraints via SPSG_Constraint_Manager::set_slot_supply(), so the day-balance and other supply-aware scoring above has the season-wide numbers it needs.

= 1.3.7 =
* Fix: a season whose games exactly (or nearly) filled its available slots failed with "Could not allocate all games" even though the feasibility check passed and a complete schedule existed -- e.g. 32 teams x 4 games into 64 slots over five weeks, or the full 32-team, 17-game regular season into 17 full weeks. The allocator placed games one at a time and had no notion of the structure the one-game-per-team-per-week rule imposes: each week a division either plays a full round (every one of its teams once) or sits out, a short week (a blackout on one of its days) can only take some divisions, and a season with more weeks than rounds has to spread each division's idle weeks. It now builds that structure directly: each division's matchups are split into full rounds, divisions are assigned to weeks (a week with room for everyone takes everyone; a short week takes the divisions that sat out the previous short week; spare idle weeks are spread one per week so no week collapses), and only then are each week's games placed into that week's slots with all the usual venue, day-balance and restriction scoring. On the real season that means every shortened week is filled to capacity, every full week carries either all 32 teams or all but one division (13 of 16 games), no team ever plays twice in a week, and a season sized exactly to its slots fills every one of them. A postseason gets the same treatment: its three cross-round-robin weeks are full rounds of the seeds, and the Championship/Consolation games (which are between a different set of placeholder teams) are each division's final round, so a 4-week bracket fills all 64 of its slots with every Championship game on the configured day. Note that a Consolation day of "Sunday" is not feasible with one rink open on Sundays (10-11 consolation games against 6 slots) -- leave the Consolation day unset to let those games use the rest of the final week. The game-by-game search that runs when a season doesn't have this shape (inter-division games, or divisions whose matchups don't form full rounds) was also fixed to detect a dead end as soon as it is created, place the most constrained game first, and branch on dates rather than time slots, so it no longer exhausts its budget re-trying equivalent slots.

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
