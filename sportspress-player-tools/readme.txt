=== SportsPress Player Tools ===
Contributors: lusky3
Tags: sportspress, player, roster, csv, captain
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced player management tools for SportsPress. Requires SportsPress Admin Tools parent plugin.

== Description ==

SportsPress Player Tools adds enhanced player management features to SportsPress:

* **Batch Player List Creator** - Upload CSV files to create or update player lists for multiple teams at once with smart name matching.
* **Player Statistics Enabler** - Bulk enable statistics display for players, automatically configuring data structures.
* **Captain Role Selection** - Designate team captains with a visual "C" indicator on the frontend.
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

= 1.0.1 =
* Security: Sanitize columns array in batch processing
* Security: Add esc_url/esc_attr output escaping throughout
* Security: Add file size validation for profile picture uploads
* Security: Add nonce verification to settings form
* Fix: Settings form now posts correctly instead of to options.php
* Fix: Batch list creator respects module enable/disable setting
* Fix: Use parent plugin bundled Select2 instead of CDN
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
