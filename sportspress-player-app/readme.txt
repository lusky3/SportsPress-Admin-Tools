=== SportsPress Player App ===
Contributors: lusky3
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: sportspress-admin-tools
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An installable player and game-night app for SportsPress leagues: your schedule, who is playing, and a spare pool for short teams.

== Description ==

A child module of SportsPress Admin Tools. The Player App (shown to players as "My ARL" by default) gives every registered player one link that shows their next game, full season schedule and roster, lets them mark In / Out / Maybe per game, lets a short team call a spare, and keeps a personal calendar feed in step with reschedules. Organizers get a game-night board.

The Player App is inert until the "Player App" module is enabled under Settings, SportsPress Admin Tools. Players see it at /my-arl/ under the name "My ARL"; the `sppa_app_name` option changes the name and the `sppa_base_path` filter changes the address.

== Installation ==

1. Install and activate SportsPress Admin Tools (the parent framework) and SportsPress.
2. Activate this plugin, then enable the "Player App" module in SportsPress Admin Tools settings.

== Changelog ==

= 0.1.0 =
* New: plugin scaffold, database schema, roster reader, event presenter and availability.
* New: change log for moved or cancelled games, personal calendar feed, spare pool and spare calls with emails.
* New: live scorekeeping. An "ARL Timekeeper" role logs goals, penalties and periods from the bench (safe to resend, two keepers per game), players in the game see the live score, and Finish game writes the result, outcomes (incl. overtime loss) and player G/A/PIM to SportsPress like a score sheet. A result someone else entered is only replaced after confirmation; organizers can reopen a finished game. Serious penalties are flagged for review only; nothing is emailed.
