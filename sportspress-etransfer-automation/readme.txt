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
* Payment safety: a name-based order match now requires the paid amount to align exactly with the order total before the order is auto-completed. Name matching is fuzzy, so a name hit alone is weak evidence of who paid; anything that does not align is routed to manual review instead. Email (Reply-To) matches are a strong identity signal and keep their existing behaviour of auto-completing unless the amount mismatches.
* DKIM verification pins the `authserv-id` in Authentication-Results and strips ARC headers at the worker, so a forwarded or replayed notification cannot be presented as a fresh one. Verification failures are logged before they are enforced.
* Order side effects run only after the payment claim is won, so a duplicate or concurrent webhook can no longer act on the same order twice. Payments corrected after an initial failure can be retried.
* `X-Forwarded-For` is honoured only when the request arrives from an admin-configured proxy IP or CIDR; otherwise `REMOTE_ADDR` is used.
* Extraction failures are logged with their reason instead of returning a bare 400 with nothing recorded.
* Secrets are masked in log output, and stored personal data is pruned according to the configured retention tier.
* Compatible with WooCommerce High-Performance Order Storage (HPOS).

= 1.0.0 =
* Initial release
* Webhook endpoint with HMAC SHA256 authentication
* Rate limiting and replay protection
* Smart order matching (email, name with equivalents)
* Manual match interface under WooCommerce menu
* Equivalent names configuration
* Activity logging with cron cleanup
* Cloudflare Worker file downloads
