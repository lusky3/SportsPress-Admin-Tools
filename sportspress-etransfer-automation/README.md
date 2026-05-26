# SportsPress e-Transfer Automation

Automated Interac e-Transfer payment processing for WooCommerce orders.
Requires SportsPress Admin Tools parent plugin and WooCommerce.

## Features

### Webhook Processing

Receives e-Transfer notification emails via webhook and automatically matches
them to WooCommerce orders. Supports three service providers:

- **Generic** — Any service that forwards email as JSON
- **deliverhook.com** — Dedicated email-to-webhook service
- **Cloudflare Email Routing** — Cloudflare Workers-based email processing (includes downloadable worker script and wrangler config)

**Endpoint:** `POST /wp-json/spet/v1/etransfer-webhook`

### Security

- **HMAC SHA256 Authentication** — All webhook requests are verified using a shared secret
- **Rate Limiting** — IP-based throttling (30 requests/minute) to prevent abuse
- **Replay Protection** — Timestamp validation rejects requests older than 5 minutes
- **Duplicate Detection** — Reference numbers are tracked to prevent double-processing

### Smart Order Matching

Incoming payments are matched to on-hold WooCommerce orders using a cascading strategy:

1. **Email Match** — Reply-To email address matched against billing email
2. **Name Match** — Sender name matched against billing name (exact or equivalent)
3. **Amount Validation** — Matched orders are verified against payment amount; mismatches are flagged for manual review

### Equivalent Names

Configurable name equivalency list for fuzzy matching (e.g., `Nicholas|Nick`,
`Robert|Rob|Bob|Bobby`). Ships with a comprehensive default list of common
English name variants. Supports comments (`#`) and pipe-delimited groups.

### Manual Match Interface

Unmatched webhooks appear under **WooCommerce → e-Transfer Webhooks** with:

- Dropdown to select from on-hold orders
- One-click "Match & Complete" to process payment
- "Hide" button to dismiss false positives
- Pending count badge on the WooCommerce menu item

### Activity Logging

All webhook activity is logged with timestamp, sender, amount, reference number,
match criteria, and result. Logs are viewable in both the settings tab and the
WooCommerce management page.

### Cron Cleanup

- Daily scheduled cleanup of logs older than 90 days
- Manual purge button on the management page

### Secure File Downloads

Download pre-configured Cloudflare Worker files (worker script, wrangler.toml,
setup guide) with your webhook URL and secret automatically injected. Downloads
require admin authentication and nonce verification.

## Installation

1. Install and activate SportsPress Admin Tools (parent plugin)
2. Install and activate WooCommerce
3. Install and activate this plugin
4. Go to Settings → SportsPress Admin Tools
5. Enable "e-Transfer Automation" module
6. Configure webhook secret and service provider in the e-Transfer tab

## Quick Start

### Configure Webhook

1. Go to Settings → SportsPress Admin Tools → e-Transfer tab
2. Copy the Webhook URL
3. Note the Webhook Secret for your forwarding service
4. Save settings

### Set Up Email Forwarding

Configure your email forwarding service to POST e-Transfer notification emails
to the webhook URL with the `X-Signature` header containing the HMAC SHA256
signature of the request body.

**Signature canonicalization:** the HMAC is computed over the *exact* raw HTTP
request body received by WordPress (the bytes returned by
`WP_REST_Request::get_body()`). The signing side must transmit the same byte
sequence — no JSON re-encoding, no whitespace normalization, no trailing
newline insertion, no character-set conversion. Mismatches between the
forwarding service's pre-sign body and the body delivered on the wire are the
most common cause of `invalid_signature` 401 responses; verify by capturing
both bodies and diffing byte-for-byte.

For Cloudflare Email Routing, download the pre-configured worker files from
the e-Transfer settings tab.

### Monitor Activity

- **Settings → SportsPress Admin Tools → e-Transfer** — Configuration and full activity log
- **WooCommerce → e-Transfer Webhooks** — Unmatched webhook management with manual matching

## Requirements

- WordPress 5.0+
- PHP 7.4+
- WooCommerce
- SportsPress Admin Tools (parent plugin)

## License

GPL v2 or later

## Author

Cody (lusky3)

## AI Usage Disclaimer

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.
