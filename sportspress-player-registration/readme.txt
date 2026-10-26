=== SportsPress Player Registration ===
Contributors: lusky3
Tags: sportspress, woocommerce, player, registration, automation
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: sportspress-admin-tools
Stable tag: 1.2.1
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

= 1.2.1 =
* Fix: an order is marked registered only when every registration item on it linked a player. Orders whose items failed (season not detected, player creation failed, or a name/email conflict) used to be marked done anyway, so they looked successful and completing the order again never retried them. They now stay open: completing the order again, or the "Re-run player registration" action, retries them.
* Fix: a retry re-processes only the items that failed. Items that already linked a player are remembered on the order and skipped, so a retry writes no duplicate log rows and does not assign the season again.
* Fix: with automatic player creation off, an order that matches no existing player now logs a `no_match` entry and stays open, instead of logging nothing and looking registered.
* Fix: refunding or cancelling an order logs "registration reversed" only when at least one item on it actually linked a player. A partly registered order still logs it; an order where every item failed no longer does.
* Orders already marked registered before this release are left as they are; there is no migration. Such an order whose items had in fact failed still looks registered, and a refund still logs a reversal for it. Use "Re-run player registration" on it to retry.

= 1.2.0 =
* New: prorated late-registration pricing (opt-in module, off by default). A product tagged `Late Registration` is priced at its regular price x (remaining game dates / total game dates) for its season, counted from the real schedule; playoffs are excluded, a season with no schedule yet is never discounted, and a finished season floors at one game's worth.

= 1.1.2 =
* Add: placeholder plugin icon, shown in the Plugins and Updates screens in place of the generic one.

= 1.1.1 =
* Fix: the parent-plugin requirement now uses WordPress's own `Requires Plugins` header instead of a made-up `Depends:` header that WordPress never actually read -- this plugin now shows up correctly in the parent's "Required by" list and gets native activation-order enforcement, not just this plugin's own runtime check.

= 1.1.0 =
* New: this plugin now updates itself through the normal WordPress Plugins screen, sourced from the project's GitHub releases.
* New: a waitlist for full divisions. Registrants arriving after a division fills join the queue, and as places open the plugin sends a timed claim offer; nobody else can buy that spot until the offer expires or its holder takes it.
* Two orders landing at the same moment can no longer both create the same player. The claim is atomic now.
* The importer matches someone registering as Jr or III to their existing record instead of creating a second one.
* The position the importer detects actually reaches the player record. It used to read the value and drop it.
* A registration whose email collides with an existing account fails once and stops, rather than retrying forever.
* Season assignment no longer rides along with record creation, and the keyword the name matcher uses is a visible setting.
* Linking a player to an order sets `links_to_order`, so other tools can spot registration-created records without inferring it from a list of actions.
* Refunds follow their own path. A refund no longer leaves behind a registration entry saying the opposite.
* Sample data in the admin is synthetic, imports validate harder, and email addresses sit under the PII retention tier.
* Works with WooCommerce High-Performance Order Storage.

= 1.0.0 =
* Initial release
* Automatic player creation from WooCommerce orders
* User account linking and role assignment
* Activity logging system

== AI Usage Disclaimer ==

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.