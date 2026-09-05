=== SportsPress e-Transfer Automation ===
Contributors: lusky3
Tags: sportspress, woocommerce, etransfer, payment, automation
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated Interac e-Transfer payment processing for WooCommerce orders. Requires SportsPress Admin Tools parent plugin.

== Description ==

SportsPress e-Transfer Automation receives e-Transfer notification emails via webhook and automatically matches them to WooCommerce orders:

* **Webhook Processing** - Receives forwarded e-Transfer emails as JSON from Generic, deliverhook.com, or Cloudflare Email Routing providers.
* **HMAC SHA256 Authentication** - All webhook requests are verified using a shared secret with timestamp-based replay protection.
* **Rate Limiting** - IP-based throttling (30 requests/minute) to prevent abuse.
* **Smart Order Matching** - Cascading match strategy: email → name (with equivalent names support) → amount validation.
* **Manual Match Interface** - WooCommerce submenu page for manually matching unmatched payments to on-hold orders.
* **Equivalent Names** - Configurable name equivalency list for fuzzy matching (e.g., Nicholas/Nick, Robert/Rob/Bob).
* **Activity Logging** - Full webhook activity log with sender, amount, reference number, match criteria, and result.
* **Cron Cleanup** - Daily automatic cleanup of logs older than 90 days with manual purge option.
* **Cloudflare Worker Downloads** - Pre-configured worker script and wrangler.toml with your settings injected.

== Installation ==

1. Install and activate SportsPress Admin Tools (parent plugin) and WooCommerce.
2. Upload the plugin folder to `/wp-content/plugins/`.
3. Activate the plugin through the WordPress admin.
4. Go to Settings → SportsPress Admin Tools.
5. Enable the "e-Transfer Automation" module.
6. Configure webhook secret and service provider in the e-Transfer tab.

== Frequently Asked Questions ==

= What email forwarding services are supported? =

Generic (any service that forwards email as JSON), deliverhook.com, and Cloudflare Email Routing with Workers.

= How does order matching work? =

Payments are matched to on-hold WooCommerce orders by: (1) Reply-To email vs billing email, (2) sender name vs billing name with equivalent name support. Amount is validated after matching.

= What happens if a payment can't be matched? =

Unmatched payments appear in WooCommerce → e-Transfer Webhooks where you can manually match them to orders or hide false positives.

== Changelog ==

= 1.0.1 =
* A name-matched payment no longer completes the order unless the amount matches exactly. Name matching handles nicknames and equivalents, so a name hit on its own says very little about who actually paid; anything that doesn't line up goes to manual review. Payments matched on the Reply-To address behave as before, since that's a strong enough signal to complete on unless the amount disagrees.
* DKIM checks pin the `authserv-id` in Authentication-Results, and the worker strips ARC headers, so nobody can replay a forwarded notification as a fresh one. Failures show up in the log before they start rejecting anything.
* The plugin claims a payment before it touches the order, so two webhooks firing at once can't both complete it. A payment that failed and was later corrected now retries cleanly.
* `X-Forwarded-For` counts only when the request comes from a proxy IP or CIDR you've listed. Otherwise `REMOTE_ADDR` wins.
* When extraction fails, the log says why. It used to return a bare 400 and record nothing at all.
* Logs mask secrets, and stored personal data expires on whatever retention tier you've set.
* Works with WooCommerce High-Performance Order Storage.

= 1.0.0 =
* Initial release
* Webhook endpoint with HMAC SHA256 authentication
* Rate limiting and replay protection
* Smart order matching (email, name with equivalents)
* Manual match interface under WooCommerce menu
* Equivalent names configuration
* Activity logging with cron cleanup
* Cloudflare Worker file downloads
