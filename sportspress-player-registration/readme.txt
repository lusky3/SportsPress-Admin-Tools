=== SportsPress Player Registration ===
Contributors: lusky3
Tags: sportspress, woocommerce, player, registration, automation
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later

Automatically creates SportsPress player records from WooCommerce registration orders.

== Description ==

SportsPress Player Registration automatically creates SportsPress player records when customers complete WooCommerce orders for registration products. It also links user accounts to player records and assigns player roles.

**Features:**
* Automatic player creation from registration orders
* User account linking to player records
* Season management based on product categories
* Player role assignment
* Comprehensive activity logging

**Requirements:**
* WordPress 5.0+
* WooCommerce plugin
* SportsPress plugin

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/sportspress-player-registration/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings → SportsPress Admin Tools and enable the Player Registration module

== Configuration ==

1. Create WooCommerce products with "registration" in category name
2. Add season categories in format W2024-25, S2025, etc.
3. Use "player" or "goalie" product tags for position assignment
4. Configure automatic creation and role assignment in settings

== Changelog ==

= 1.1.0 =
* New: registration waitlist. When a division is full, further registrants join a waitlist and are sent a timed claim offer as places open up; the offer gates purchase until it expires or is taken.
* Players are claimed atomically, so two simultaneous orders can no longer both create a record for the same person.
* Registrations for names carrying a suffix (Jr, III) no longer create a second player alongside the existing one.
* A detected playing position is now actually applied to the player record — it was read and then discarded.
* An email that conflicts with an existing account is treated as terminal rather than retried indefinitely.
* Season assignment is decoupled from record creation, and the name-matching keyword setting is exposed in the admin.
* `links_to_order` is set when a player is linked to an order, so downstream tools can tell registration-created records from hand-made ones without guessing from an action allowlist.
* Refunds are handled explicitly, and refund logging is gated so a refund no longer writes a misleading registration entry.
* Sample data used in the admin is synthetic; imported data is validated more strictly; e-mail addresses are stored under the PII retention tier.
* Compatible with WooCommerce High-Performance Order Storage (HPOS).

= 1.0.0 =
* Initial release
* Automatic player creation from WooCommerce orders
* User account linking and role assignment
* Activity logging system

== AI Usage Disclaimer ==

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.