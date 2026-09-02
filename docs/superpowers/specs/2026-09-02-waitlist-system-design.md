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
| `includes/class-waitlist-matcher.php` | `SPLM_Waitlist_Matcher` — pure functions: does a product belong to the waitlist category, extract season, detect goalie tag, find the paired real product. Mirrors the equivalent private methods in `SPPR_Player_Registration` (not shared code — that class has no public API for this — but the same regex/category conventions). |
| `includes/class-waitlist.php` | `SPLM_Waitlist` — orchestration: ingestion from orders, offer/cancel, cron expiry, claim-token validation, marking claimed from a completed order. |
| `includes/class-waitlist-rest.php` | `SPLM_Waitlist_REST` — the REST surface (admin + the one public claim route). |

`class-rest-api.php` is already flagged as too large in the leaders/discipline
design; this is another controller kept out of it, same precedent.

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
created_at            datetime DEFAULT CURRENT_TIMESTAMP
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

## Ingestion

A listener on `woocommerce_order_status_processing` inspects each line
item's product. Using `SPLM_Waitlist_Matcher`:

1. Is the product's category the configured waitlist category (new option
   `splm_waitlist_keyword`, default `"waitlist"`, matched the same
   case-insensitive-substring way `spr_registration_keyword` is)? If not,
   skip.
2. Extract season from the product title/category (same regex convention as
   `SPPR_Player_Registration::extract_season_from_product()`).
3. Detect position from a `goalie` product tag (same convention as
   `SPPR_Player_Registration::get_registration_items()`).
4. Find the paired **real** product: same season + position, category
   matches the registration keyword, does **not** carry the waitlist
   category. Ambiguous (0 or 2+ matches) is logged and the row is still
   created with `target_product_id = 0`; the dashboard flags such rows so a
   convener can set the target manually before offering.
5. Insert a `queued` row, skipped if an active (`queued` or `offered`) row
   already exists for that email + season + position.

The dashboard also exposes a manual "add to waitlist" action (name, email,
season, position, target product) for entries with no order at all.

## Offer flow

From the dashboard, a convener picks a `queued` row and offers it, optionally
overriding the default 48-hour window. This:

- generates a random `claim_token` (`wp_generate_password( 32, false )` or
  equivalent, matching the token style already used for the CF Access secret
  field),
- sets `status = offered`, `offered_at = now`, `expires_at = now + hours`,
- emails the entrant via `wp_mail()` with the claim link and deadline,
  logging a failure the way `class-notifications.php` does,
- schedules a WP-Cron single event `splm_waitlist_expire_offer` (payload: row
  id) at `expires_at`, matching the `spss_process_sheet` scheduling pattern
  score-sheets already uses.

The whole write (token generation → row update → email → cron schedule) runs
inside `SPAT_Lock::with()` keyed per row id, so a double click cannot
double-offer or double-schedule.

Given this session's own experience with unreliable WP-Cron self-triggering
on the staging box, the dashboard's waitlist list endpoint also sweeps and
expires any `offered` row whose `expires_at` has already passed before
returning results — the scheduled cron is the primary mechanism, this is a
cheap backstop so a stalled cron can't leave a stale "offered" row showing
indefinitely.

## Claim flow

`GET /splm/v1/waitlist/claim/{token}` — public (`permission_callback:
__return_true`), same "unauthenticated route that does its own validation"
pattern `sportspress-score-sheets` already uses for intake. Looks up the row
by token:

- **`status === 'offered'` and `expires_at` in the future** → `302` redirect
  to the real product's add-to-cart URL
  (`get_permalink( target_product_id ) . '?add-to-cart=' . target_product_id`),
  landing the player in a completely normal WooCommerce cart/checkout. No
  custom order-creation code — this is what makes "an order is generated as
  normal" literally true.
- **anything else** (unknown token, expired, already claimed/cancelled) →
  `200` with a small static HTML body ("This invite has expired — contact
  your convener.") rather than a redirect or JSON, since a dead link is
  opened directly in a browser by a player, not consumed by the dashboard.

## Tying a completed order back to an offer

A new listener on `woocommerce_order_status_completed` (independent of, and
added without modifying, `SPPR_Player_Registration`'s own listener on the
same hook — both are just separate subscribers to the same WooCommerce
event) checks each completed order's line items: if a product matches an
`offered` row's `target_product_id` and the order's billing email matches
that row's `email` (case-insensitive), the row is set to `status = claimed`,
`resolved_order_id = $order_id`, and its pending expire-cron event is cleared
with `wp_clear_scheduled_hook()`.

Matching by product + email (not by requiring the claim link to have been
clicked) is deliberate: it self-heals if the emailed link is never used at
all — forwarded email breaks, or the player already had the product's direct
URL from some other source.

**Explicitly out of scope:** a refund or cancellation of the resulting order
does not revert the waitlist row. This matches
`SPPR_Player_Registration::handle_order_reversed()`'s own philosophy — log,
don't auto-mutate, leave reconciliation to a human.

## REST surface

Conforms to `docs/rest-api-conventions.md`.

| Endpoint | Method | Shape | Capability |
|---|---|---|---|
| `/waitlist` | GET | list — `{data, total, page, total_pages}`, filterable by `season`, `position`, `status` | `SPLM_Capabilities::can_manage()` |
| `/waitlist` | POST | `{success: true, id}` — manual add | `SPLM_Capabilities::can_manage()` |
| `/waitlist/{id}/offer` | POST | `{success: true, expires_at}` — body: `{hours}` optional, default 48 | `SPLM_Capabilities::can_manage()` |
| `/waitlist/{id}/cancel` | POST | `{success: true}` — cancels an offer or removes a queued entry | `SPLM_Capabilities::can_manage()` |
| `/waitlist/claim/{token}` | GET | `302` redirect or static HTML | `__return_true` (self-checked token) |

Errors use `WP_Error`: `404` for an unknown row id, `409` if `offer` is
called on a row that isn't `queued`/`expired`, `400` for an unresolvable
`target_product_id` on manual add.

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
(default 48) before sending.

Wiring: `App.jsx` `PAGES` map, `Layout.jsx` nav array with module gating —
same pattern as every other dashboard page.

## Testing

Standalone harness (`assert_test`, echo-based, exit code drives pass/fail),
registered in `run-all-tests.sh`.

| Test file | Covers |
|---|---|
| `tests/test-waitlist-matcher.php` | waitlist-category detection, season extraction, goalie-tag detection, real-product pairing incl. 0-match and 2+-match ambiguity |
| `tests/test-waitlist-lifecycle.php` | queued→offered→claimed/expired/cancelled transitions, re-offer resetting the same row, duplicate-active-row prevention at ingestion, expiry-sweep behavior on stale `offered` rows |
| `tests/test-waitlist-claim.php` | token validation (valid/expired/unknown/already-resolved), order-completion matching by product+email, cron cleared on claim |

`SPLM_Waitlist_Matcher` and the pure transition logic in `SPLM_Waitlist` take
plain arrays/mocks in and return plain data out, so these run with no
WordPress bootstrap, matching every other test file in this repo.

## Out of scope

- Automatic queue advancement on expiry — the convener explicitly wants to
  choose who gets offered next each time, not have the system pick.
- Non-email notification channels (SMS, Discord).
- Reverting a waitlist row on order refund/cancellation.
- Multi-cycle offer history per entry (only the most recent offer is
  retained per row).
- Migrating or backfilling today's in-flight Processing waitlist orders into
  the new table — this ships forward-only; any currently-queued people get
  entered manually via the "add to waitlist" action once.

## Phasing

1. **Data + ingestion** — `SPLM_Waitlist_Database`, `SPLM_Waitlist_Matcher`,
   order-processing listener, manual add.
2. **Offer + claim** — offer action, email, cron expiry + sweep, public claim
   route, order-completion tie-back.
3. **Dashboard UI** — `Waitlist.jsx`, nav wiring, module registration.
