=== SportsPress Player Tools ===
Contributors: lusky3
Tags: sportspress, player, roster, csv, captain
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced player management tools for SportsPress. Requires SportsPress Admin Tools parent plugin.

== Description ==

SportsPress Player Tools adds enhanced player management features to SportsPress:

* **Batch Player List Creator** - Upload CSV files to create or update player lists for multiple teams at once with smart name matching.
* **Player Statistics Enabler** - Bulk enable statistics display for players, automatically configuring data structures.
* **Captain Role Selection** - Designate team captains with a visual "C" indicator on the frontend.
* **Sync Player Emails** - Bulk-populate missing player emails from WooCommerce registration orders and linked user accounts with preview and CSV export.
* **Player Skill Level** - Admin-only skill ratings (1-10) with manual input and auto-calculation from SportsPress statistics.
* **Email Meta Box** - Add email addresses to player records for communication and user linking.
* **Player Profile Picture** - Allow players to upload profile pictures via WooCommerce My Account (requires WooCommerce).

== Installation ==

1. Install and activate SportsPress Admin Tools (parent plugin).
2. Install and activate this plugin.
3. Go to Settings → SportsPress Admin Tools.
4. Enable desired modules (Player Modifications, Stats Enabler, Batch List Creator).
5. Save settings.

== Frequently Asked Questions ==

= What CSV format is required for batch upload? =

CSV files must have "Team" and "Name" columns. Player names are automatically cleaned (jersey numbers and position prefixes removed).

= Can I update existing rosters? =

Yes. The batch list creator supports both "Create new" and "Update existing" modes. Update mode finds lists matching team and season, then replaces players.

== Changelog ==

= 1.1.0 =
* Fixed a write bug in profile pictures. The page resolved "your player record" by post author, which records who created a record rather than who it is about — so an account that had created someone else's player was shown that player as its own profile, and uploading wrote the photo onto it. Resolution is now by the `sp_user` link only. Note that players with no `sp_user` set no longer see the upload form at all; that is deliberate, and safer than writing to the wrong record.
* New: skill ratings are computed from box scores, with a season scope, goals weighted above assists (assists count 0.5), and goaltenders ranked separately from skaters. Goalie detection was also fixed, and a rating set automatically no longer flips itself back to manual.
* New: REST endpoints for roster management and schedule generation.
* Email sync re-derives on the server which address may be written to which player, instead of trusting the submitted pair.
* Roster lookups use `sp_leagues` meta so they are season-correct, and roster details use `sp_team` meta; player number, position and email are shown on roster pages.
* Registration-created players are identified by the `links_to_order` column rather than an allowlist of actions.
* "Sync Player Emails" no longer appears on every settings tab, and child tabs no longer render blank.
* Batch processing: check-all works, batch runs take a lock, queries are capped, and the batch wipe and registered-flag bugs are closed. Dead REST methods removed.
* Sample data shown in the admin is synthetic; imported data is validated more strictly; e-mail addresses are stored under the PII retention tier.

= 1.0.1 =
* Security: Sanitize columns array in batch processing
* Security: Add esc_url/esc_attr output escaping throughout
* Security: Add file size validation for profile picture uploads
* Security: Add nonce verification to settings form
* Fix: Settings form now posts correctly instead of to options.php
* Fix: Batch list creator respects module enable/disable setting
* Fix: Use parent plugin bundled Slim Select instead of CDN
* Add: Sync Player Emails bulk tool (populate missing emails from WooCommerce orders and user accounts)
* Add: Player Skill Level tracking (1-10 ratings with auto-calculation from statistics)
* Add: Captain indicator text filter (spt_captain_indicator_text)
* Add: uninstall.php for proper cleanup
* Remove: Dead code (class-batch-list-preview.php)
* Remove: Debug logging from production code
* Remove: Duplicate admin_post hook registrations

= 1.0.0 =
* Initial release
* Batch list creator with CSV upload
* Player statistics enabler
* Captain role selection
* Email meta box
* Player profile picture upload
