# Registration Waitlist — Design

**Status:** approved for planning
**Date:** 2026-09-02
**Plugin:** `sportspress-league-manager`
**Namespace:** `splm/v1`

## Goal

Replace the manual waitlist workflow — swap the registration page's SKU to a
"waitlist" SKU, watch WooCommerce orders sit in Processing, manually email a
person a link to the real SKU — with a dashboard a convener can drive: see who
is waiting per season/position, offer a specific person their spot with a
claim deadline, and let the existing checkout flow do the rest.

## Current manual process (baseline)

- Each season has two `$0.00` WooCommerce products — one for players, one for
  goalies — categorized `registration` (matched by `SPPR_Player_Registration`
  via the `spr_registration_keyword` option) and tagged `goalie` when
  applicable.
- When a season fills up, the convener replaces the registration product's
  category with a "waitlist" category (this used to be a naming convention;
  it was recently changed to a category, which is the more reliable signal
  and the one this design keys on).
- Interested players check out for the waitlist product ($0). Those orders
  are deliberately left in **Processing** — never marked Completed — so
  `SPPR_Player_Registration::process_completed_order()` never fires for them.
  This is today's *de facto* queue: "orders for the waitlist SKU, oldest
  first, still in Processing."
- To offer a spot, the convener manually emails that person a link to the
  real registration product. They check out normally, the order completes,
  and `SPPR_Player_Registration` registers them exactly as any other
  registrant.

The user-confirmed pain point: tying queue state to WooCommerce order status
is fragile and was never a deliberate design — it's just what fell out of
using the waitlist SKU as the entry point. This design keeps the entry point
(buying the waitlist SKU still joins the queue) but stops using order status
as the state machine.

## Operational precondition — read this first

**The claim link is a convenience, not an access control.** It redirects to
the real product's normal add-to-cart URL, so anyone who reaches that URL by
any other route — a forwarded email, catalog browsing, site search, a
remembered permalink — can buy the spot without ever being offered one. The
product+email tie-back below would then either mark the wrong row claimed or
match nothing at all.

This is not a regression: the current manual process emails a link to the
same public product and has exactly the same hole. But the design only works
in practice when the real registration product is set to **Catalog
visibility: Hidden** (hidden from shop and search) while the season is full,
so link-holders are the only people who can reach it.

The offer endpoint therefore checks the target product's catalog visibility
and returns a non-blocking warning in its response when the product is
publicly visible, surfaced in the dashboard next to the offer confirmation.
It warns rather than refuses — visibility is a legitimate convener choice,
and a hard block would strand them mid-workflow.

## Architecture

### Why a dedicated table, not order meta

Two options were considered:

1. **Keep WC order status as the source of truth**, adding offer state
   (token, expiry) as order meta on the existing Processing order.
2. **A dedicated table** that records each waitlist entry's own lifecycle,
   populated automatically from waitlist-SKU purchases (preserving today's
   entry point) but independent of the order's own status afterward.

Option 2 is what this design implements. Order status already carries
payment-workflow meaning elsewhere in this codebase (`SPPR_Player_Registration`
hooks `woocommerce_order_status_completed`); overloading it further to also
mean "queue position" is the exact coupling the user flagged as accidental,
not desired. A dedicated table also makes "who is currently offered and
about to expire" a plain indexed query instead of a meta scan, and gives each
entry a real status history instead of inferring it from order status
transitions.

### Components

| File | Responsibility |
|---|---|
| `includes/class-waitlist-database.php` | `SPLM_Waitlist_Database` — table CRUD, following `SPLM_Discipline_Database`'s dbDelta + verified-`table_exists()` pattern. |
| `includes/class-waitlist-matcher.php` | `SPLM_Waitlist_Matcher` — pure functions: does a product belong to the waitlist category, find the paired real product. Season/position parsing is delegated to the shared SPAT helper (below). |
| `includes/class-waitlist.php` | `SPLM_Waitlist` — orchestration: ingestion from orders, offer/cancel, cron expiry, claim-token validation, marking claimed from a completed order. |
| `includes/class-waitlist-rest.php` | `SPLM_Waitlist_REST` — the REST surface (admin + the one public claim route). |
| `sportspress-admin-tools/includes/class-season.php` | **New shared helper** `SPAT_Season` — `from_product( $product_id )` and `position_from_product( $product_id )`. |

`class-rest-api.php` is already flagged as too large in the leaders/discipline
design; this is another controller kept out of it, same precedent.

### Shared season/position parsing

`SPPR_Player_Registration::extract_season_from_product()` and the goalie-tag
detection inside `get_registration_items()` are both **private**, so this
feature cannot reuse them and an earlier draft of this design copied the
regex and the tag convention instead. Two copies of a convention that the
league changes by hand (season codes, the goalie tag name) will drift.

Both plugins already require the SPAT parent, so the convention moves to a
single `SPAT_Season` helper there and both call it. `SPPR_Player_Registration`
delegates its private methods to the helper, keeping its existing behaviour
and its existing signatures — the shared helper is extracted from that code,
not a reimplementation of it, so the registration path is unchanged. This
follows the same cross-plugin care the repo already applies to the
`spt_email` / `spr_email_meta` gate.

### WooCommerce HPOS

`sportspress-league-manager` already calls `wc_get_order()` and
`wc_get_orders()` throughout `class-rest-api.php` but its main plugin file
does **not** declare High-Performance Order Storage compatibility, unlike
`sportspress-etransfer-automation`, which declares it on
`before_woocommerce_init`:

```php
\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
    'custom_order_tables', __FILE__, true
);
```

That gap is pre-existing, but this feature makes SPLM a first-class
order-hooking consumer, so the declaration is added as part of this work.
Nothing in this design reads `wp_posts` / `wp_postmeta` for orders directly —
all order access goes through `wc_get_order()` and the CRUD API, which is
HPOS-safe either way.

## Data model

New table `{prefix}splm_waitlist`, `SPLM_Waitlist_Database::DB_VERSION`:

```
id                    bigint unsigned auto_increment
season                varchar(20)      -- e.g. "S2026"
position              varchar(20)      -- 'player' | 'goalie'
waitlist_product_id   bigint unsigned  -- the waitlist SKU they bought in on
target_product_id     bigint unsigned  -- the real SKU this entry would claim
name                  varchar(191)
email                 varchar(191)
user_id               bigint unsigned NOT NULL DEFAULT 0
source_order_id       bigint unsigned NOT NULL DEFAULT 0   -- 0 if added manually
status                varchar(20) NOT NULL DEFAULT 'queued'
                      -- queued | offered | claimed | expired | cancelled
claim_token           varchar(64) NULL
offered_at            datetime NULL
expires_at            datetime NULL
resolved_order_id     bigint unsigned NULL
created_at            datetime NOT NULL
updated_at            datetime NULL
PRIMARY KEY (id)
KEY season_position_status (season, position, status)
UNIQUE KEY claim_token (claim_token)
KEY email (email)
```

One row per person per season/position. Re-offering after an expiry resets
`status`/`offered_at`/`expires_at`/`claim_token` on the same row rather than
inserting a new one — full multi-cycle offer history is not tracked
separately (not requested; add later if needed).

**`claim_token` is nullable and that is load-bearing.** MySQL permits any
number of NULLs under a UNIQUE index, so every un-offered row coexists
happily while offered rows are still guaranteed a distinct token. Changing
it to `NOT NULL DEFAULT ''` — the obvious-looking tidy-up — makes the second
tokenless row fail to insert. The schema carries this as a comment.

**`user_id`** stores the purchasing WP user when the waitlist order had one.
It is used as a tie-back signal (below) and to link the eventual registration
to an account; it is not used for authentication anywhere.

### Time is stored in UTC, without exception

This feature is made entirely of deadlines, and three different clocks are
within reach: MySQL server time (`CURRENT_TIMESTAMP` column defaults),
WordPress site-local time (`current_time( 'mysql' )`, the prevailing pattern
elsewhere in this repo), and UTC epoch seconds (what
`wp_schedule_single_event()` consumes). Mixing any two of them offsets every
deadline by the site's UTC offset — four to five hours for this league.

The rules, which the tests assert:

- Every `datetime` column is written as UTC via `gmdate( 'Y-m-d H:i:s' )`.
  `created_at` is written explicitly rather than relying on a
  `DEFAULT CURRENT_TIMESTAMP` that would use MySQL's server clock.
- `expires_at` is computed as `gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS )`.
- The cron event is scheduled at `time() + $hours * HOUR_IN_SECONDS` — the
  same epoch value, never a re-parsed string.
- All comparisons are made against `time()` / `gmdate()`, never
  `current_time()`.
- The dashboard and the notification email render local time with
  `wp_date()`, which applies the site timezone at display time only.

## Ingestion

A listener on **`woocommerce_order_status_changed`**, acting on any paid
status (`processing`, `completed`, and anything else in
`wc_get_is_paid_statuses()`), inspects each line item's product.

Hooking `woocommerce_order_status_processing` alone — which is what today's
"orders stay in Processing" convention suggests — would be a silent trap.
WooCommerce's `payment_complete()` routes a paid order to `completed` instead
of `processing` whenever `WC_Order::needs_processing()` is false, which is
the case when every line item is virtual **and** downloadable. Today's
waitlist products are evidently not configured that way, but a $0
non-shippable registration SKU is one product checkbox away from becoming so,
and the failure mode is an order that creates no waitlist row and reports no
error. Listening for any paid status removes the trap; the duplicate guard in
step 5 makes repeated firing harmless.

Using `SPLM_Waitlist_Matcher` and `SPAT_Season`:

1. Is the product's category the configured waitlist category (new option
   `splm_waitlist_keyword`, default `"waitlist"`, matched the same
   case-insensitive-substring way `spr_registration_keyword` is)? If not,
   skip.
2. Extract season via `SPAT_Season::from_product()`.
3. Detect position via `SPAT_Season::position_from_product()` (the `goalie`
   product tag).
4. Find the paired **real** product: same season + position, category
   matches the registration keyword, does **not** carry the waitlist
   category. Ambiguous (0 or 2+ matches) is logged and the row is still
   created with `target_product_id = 0`; the dashboard flags such rows so a
   convener can set the target manually before offering.
5. Insert a `queued` row, skipped if an active (`queued` or `offered`) row
   already exists for that email + season + position. This guard is what
   makes the listener idempotent across repeated status transitions.

The dashboard also exposes a manual "add to waitlist" action (name, email,
season, position, target product) for entries with no order at all.

## Offer flow

From the dashboard, a convener picks a `queued` (or previously `expired`) row
and offers it, optionally overriding the default 48-hour window. Offering a
row whose `target_product_id` is `0` is refused with `409` — that ambiguous
row is precisely the one most likely to be offered by accident, and the UI
surfaces the reason rather than a bare failure.

The whole write runs inside `SPAT_Lock::with()` keyed per row id, so a double
click cannot double-offer or double-schedule, and proceeds:

1. `wp_clear_scheduled_hook( 'splm_waitlist_expire_offer', array( $id ) )` —
   unconditionally, before anything else. See the cron hazard below.
2. Generate `claim_token` as `bin2hex( random_bytes( 32 ) )`. This is the
   repo's existing pattern for generated identifiers
   (`class-schedule-generator.php`, `class-configuration-manager.php`) and it
   avoids the Semgrep weak-crypto rules that flagged PR #108. (An earlier
   draft cited the Cloudflare Access secret field as precedent; that value is
   user-entered, not generated. The generated-secret precedents are
   `spss_webhook_secret` and `spet_webhook_secret`, which use
   `wp_generate_password()`.)
3. Set `status = offered`, `offered_at`, `expires_at` (UTC, per the rules
   above).
4. Schedule `splm_waitlist_expire_offer` with `array( $id )`.
5. Email the entrant via `wp_mail()` with the claim link and the deadline
   rendered in site-local time.

**A failed send unwinds the offer.** If `wp_mail()` returns false, the row is
reverted to `queued`, the cron event is cleared, and the endpoint returns
`500`. The alternative — log the failure and carry on, as an earlier draft
had it — leaves a ticking 48-hour clock on an invite that nobody received,
and the person silently loses their turn.

### The cron hazard

`wp_schedule_single_event( $ts, 'splm_waitlist_expire_offer', array( $id ) )`
leaves a pending event that survives a cancel. Cancel the offer, re-offer the
same row a day later, and the *first* event is still queued: it fires at the
old `expires_at` and expires the brand-new offer early. `wp_clear_scheduled_hook()`
only removes an event when passed identical args, which is why every call site
passes `array( $id )`.

Clearing is necessary but not sufficient — a cron event already in flight
cannot be recalled. So the expire callback is defensive and re-reads the row,
expiring it **only** when `status === 'offered'` **and** `expires_at <= now`.
It never expires based on the fact that it fired. That re-check is the
load-bearing guard; the clears are hygiene.

Given this session's experience with unreliable WP-Cron self-triggering on
the staging box, the dashboard's waitlist list endpoint also sweeps past-due
`offered` rows before returning results — the scheduled cron is the primary
mechanism, this is a cheap backstop so a stalled cron cannot leave a stale
"offered" row showing indefinitely. The sweep is bounded to the rows matching
the request's own season/position filters, and a sweep failure is logged and
swallowed rather than failing the read.

## Claim flow

`GET /splm/v1/waitlist/claim/{token}` — public (`permission_callback:
__return_true`), validating the token itself. Looks up the row by token:

- **`status === 'offered'` and `expires_at` in the future** → `302` redirect
  to the real product's add-to-cart URL with the binding arg:
  `get_permalink( $target ) . '?add-to-cart=' . $target . '&splm_wl=' . $token`,
  landing the player in a completely normal WooCommerce cart/checkout. No
  custom order-creation code — this is what makes "an order is generated as
  normal" literally true, and it avoids reimplementing tax, coupon and stock
  handling.
- **anything else** (unknown token, expired, already claimed/cancelled) →
  `200` with a small static HTML body ("This invite has expired — contact
  your convener."), translated with the `sportspress-league-manager` text
  domain. A dead link is opened directly in a browser by a player, not
  consumed by the dashboard, so HTML beats JSON or a redirect here.

Two properties of this route are deliberate and must survive future edits:

- **It has no side effects.** Email security scanners — Outlook SafeLinks,
  Gmail, corporate mail gateways — prefetch links in messages. A future
  "mark it claimed when they click" optimization would burn every invite
  before the player ever opened the mail. Validation and redirect only.
- **The failure message is uniform** across unknown, expired, used and
  cancelled tokens. It is not an oracle, and a later "more helpful error
  messages" pass must not make it one.

Nothing from the token or the database is interpolated into the HTML body;
it is a static translated string. A 32-byte random token makes enumeration
infeasible, so no rate limit is specified for this route.

## Tying a completed order back to an offer

A new listener on `woocommerce_order_status_completed` — independent of, and
added without modifying, `SPPR_Player_Registration`'s own listener on the same
hook; both are just separate subscribers to the same WooCommerce event.

**Primary match: order line item meta.** The `splm_wl` query arg from the
claim redirect is captured into cart item data via
`woocommerce_add_cart_item_data` and persisted onto the line item via
`woocommerce_checkout_create_order_line_item` as `_splm_waitlist_id`. The
tie-back reads that meta directly — an exact match with no inference.

**Fallback match: product + email/user.** When no `_splm_waitlist_id` is
present (the link was never clicked, the player reached the product some
other way, or the cart was rebuilt), the listener matches an `offered` row
whose `target_product_id` equals the line item's product and whose `email`
matches the order's billing email case-insensitively, or whose `user_id`
matches the order's customer id.

The fallback alone was the earlier draft's only mechanism, and it fails
whenever a player checks out under a different address than their waitlist
order used — shared and family email addresses make that common. The line
item meta is what makes the normal path exact; the fallback is what keeps the
never-clicked-the-link case working.

On a match the row is set to `status = claimed`, `resolved_order_id = $order_id`,
and its pending expire event is cleared with
`wp_clear_scheduled_hook( 'splm_waitlist_expire_offer', array( $id ) )`.

**Explicitly out of scope:** a refund or cancellation of the resulting order
does not revert the waitlist row. This matches
`SPPR_Player_Registration::handle_order_reversed()`'s own philosophy — log,
don't auto-mutate, leave reconciliation to a human.

## REST surface

Conforms to `docs/rest-api-conventions.md`.

| Endpoint | Method | Shape | Capability |
|---|---|---|---|
| `/waitlist` | GET | list — `{data, total, page, total_pages}` | `SPLM_Capabilities::can_manage()` |
| `/waitlist` | POST | `{success: true, id}` — manual add | `SPLM_Capabilities::can_manage()` |
| `/waitlist/{id}/offer` | POST | `{success: true, expires_at, warnings}` | `SPLM_Capabilities::can_manage()` |
| `/waitlist/{id}/cancel` | POST | `{success: true}` | `SPLM_Capabilities::can_manage()` |
| `/waitlist/claim/{token}` | GET | `302` redirect or static HTML | `__return_true` (self-checked token) |

Every argument declares `validate_callback` and `sanitize_callback`:

- `hours` — integer, `1..720` (30 days), default `48`. Unvalidated, a typo'd
  `0` or a negative creates an offer that is already expired when it is sent,
  and an absurd value creates a permanent one.
- `season` — `sanitize_text_field`, validated against the seasons present in
  the table.
- `position` — enum `player|goalie`.
- `status` — enum over the five status values.
- `page` / `per_page` — `absint`, `per_page` capped at 100.
- `token` — `[a-f0-9]{64}`, rejected by the route's own regex before any
  query runs.

Errors use `WP_Error`: `404` for an unknown row id, `409` when `offer` is
called on a row that is not `queued`/`expired` **or** whose
`target_product_id` is `0`, `400` for an unresolvable `target_product_id` on
manual add, `500` when the notification email fails to send.

## Module gating

New toggleable module `league_waitlist`, registered via
`SPAT_Plugin_Manager::register_plugin()` alongside the existing five
league-manager modules — same reasoning as `league_discipline`: this sends
email to named individuals, so it must be a deliberate opt-in rather than
riding on the base dashboard module. Disabling the module stops the order
listeners from registering and hides the dashboard nav item.

## UI

**`src/dashboard/pages/Waitlist.jsx`** — new nav item "Waitlist", gated on
`can_manage()` and the `league_waitlist` module. Season/position filters, a
table of entries (name, email, status badge, live countdown when `offered`),
row actions (Offer, Cancel, Re-offer), and a manual "Add to waitlist" form.
Offering a spot opens a small dialog to confirm/override the expiry hours
(default 48) before sending, and renders any warnings the endpoint returned —
notably the "this product is publicly visible" warning from the operational
precondition above. Rows with `target_product_id = 0` render the offer action
disabled with the reason inline.

All deadline rendering uses the site timezone (values arrive as UTC and are
formatted client-side against the dashboard's existing timezone config).

Wiring: `App.jsx` `PAGES` map, `Layout.jsx` nav array with module gating —
same pattern as every other dashboard page.

## Uninstall

`sportspress-league-manager/uninstall.php` sweeps `splm_`-prefixed options,
transients and user meta generically, so `splm_waitlist_keyword` is already
covered. Tables, however, are dropped by name, and the file currently lists
only `splm_player_notes`. Add `splm_waitlist` — and, in the same pass, the
already-missing `splm_discipline_ack`, which is a pre-existing leak from the
discipline feature. Also `wp_clear_scheduled_hook( 'splm_waitlist_expire_offer' )`
on uninstall, matching the score-sheets and schedule-generator precedent.

## Testing

Standalone harness (`assert_test`, echo-based, exit code drives pass/fail),
registered in `run-all-tests.sh`.

| Test file | Covers |
|---|---|
| `tests/test-waitlist-matcher.php` | waitlist-category detection, real-product pairing incl. 0-match and 2+-match ambiguity, and `SPAT_Season` season/position extraction (incl. parity with the behaviour `SPPR_Player_Registration` had before the extraction) |
| `tests/test-waitlist-lifecycle.php` | queued→offered→claimed/expired/cancelled transitions, re-offer resetting the same row, duplicate-active-row prevention across repeated paid-status transitions, bounded expiry sweep, offer refused on `target_product_id = 0`, offer unwound on mail failure, `hours` range validation |
| `tests/test-waitlist-claim.php` | token validation (valid/expired/unknown/already-resolved) incl. the uniform failure message, side-effect-free claim route, order-completion matching by line item meta **and** the product+email/user fallback, cron cleared on claim |
| `tests/test-waitlist-time.php` | UTC round-tripping of `expires_at`, cron timestamp agreeing with the stored deadline under a non-UTC site timezone, and the expire callback refusing to expire a re-offered row when a stale event fires |

The matcher and the pure transition logic take plain arrays/mocks in and
return plain data out, so these run with no WordPress bootstrap, matching
every other test file in this repo.

## Out of scope

- Automatic queue advancement on expiry — the convener explicitly wants to
  choose who gets offered next each time, not have the system pick.
- Non-email notification channels (SMS, Discord).
- Reverting a waitlist row on order refund/cancellation.
- Multi-cycle offer history per entry (only the most recent offer is
  retained per row).
- Enforcing that only offer-holders can purchase the real SKU — see the
  operational precondition; catalog visibility is the mechanism, and making
  the product genuinely unpurchasable without a token would mean intercepting
  `woocommerce_is_purchasable`, which is a larger change than this feature
  needs.
- Migrating or backfilling today's in-flight Processing waitlist orders into
  the new table — this ships forward-only; any currently-queued people get
  entered manually via the "add to waitlist" action once.

## Phasing

1. **Shared groundwork** — `SPAT_Season` extraction (with SPPR delegating to
   it), SPLM HPOS declaration, `SPLM_Waitlist_Database`, uninstall additions.
2. **Ingestion** — `SPLM_Waitlist_Matcher`, paid-status listener, manual add.
3. **Offer + claim** — offer action, email, cron expiry + defensive callback
   + sweep, public claim route, cart-item binding, order-completion tie-back.
4. **Dashboard UI** — `Waitlist.jsx`, nav wiring, module registration.
