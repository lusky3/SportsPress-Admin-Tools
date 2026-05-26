=== SportsPress Player Registration ===
Contributors: lusky3
Tags: sportspress, woocommerce, player, registration, automation
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
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

= 1.0.0 =
* Initial release
* Automatic player creation from WooCommerce orders
* User account linking and role assignment
* Activity logging system

== AI Usage Disclaimer ==

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.