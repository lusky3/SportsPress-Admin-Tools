# Registration Waitlist — follow-ups and open items

Companion to `2026-09-02-registration-waitlist.md` (plan) and
`../specs/2026-09-02-waitlist-system-design.md` (spec). Written at the end of the branch's whole-branch
review, so the things deliberately left undone are recorded rather than remembered.

Nothing here blocks the branch. Everything here was raised by a review, adjudicated, and left open on
purpose — with the reasoning, so a future reader can disagree with the reasoning rather than rediscover
the issue.

---

## 1. One live offer still admits more than one spot

**What.** The purchase gate limits *who* can buy a waitlisted registration product, not *how many*.
Three mechanics combine:

- Quantity is not capped. Nothing in the feature touches `sold_individually`, a quantity input filter, or
  `woocommerce_add_to_cart_validation`.
- For a gated *variable* product, the entitlement is held against the parent id, so it covers every
  variation.
- The entitlement is consumed only when the order reaches `completed`. This league takes e-Transfer
  payments, so an order can legitimately sit unpaid for days with the entitlement still live.

**Why it was not fixed here.** The gate delivers what the spec actually claims — it stops someone who was
never offered a spot from buying one. "How many" is a property the spec never claimed. The exposure is
bounded to a 48-hour window against a named person who was deliberately invited, and a convener sees every
order.

**Why it still matters.** It compounds with the token-in-query-string exposure below: one leaked claim link
plus uncapped quantity is unbounded spots taken by an arbitrary party, not just by the invitee.

**The cheap partial, available today with no code.** Tick **Sold individually** on each gated registration
product, or add a `woocommerce_quantity_input_max` filter returning 1 for gated products. That caps the
single-order case for the cost of a checkbox.

**The real fix.** Consume the entitlement when the order is *created* rather than when it completes. Note
this was considered during the branch and deliberately deferred: consuming at creation means an abandoned
unpaid order burns a spot, which with e-Transfer is a real scenario, so it needs its own design pass rather
than a one-line change.

---

## 2. The claim token travels as a query argument

`?splm_wl=<64 hex chars>` rides on a public product URL, so it lands in browser history, in the `Referer`
header sent to every third-party asset on that page, and in server access logs. Because the claim route is
deliberately side-effect-free (it must be, or link-prefetching scanners would burn every invite), the token
stays live for the whole offer window rather than being consumed on first use.

Inherent to the chosen claim mechanism. The alternative — swapping the arg for a cookie on arrival — is a
design change, and it is what makes item 1 above bounded rather than open-ended, so the two should be
considered together.

---

## 3. `SPLM_Waitlist` should be split

1065 lines, 29 methods, a constructor registering five hooks across three unrelated concerns, grown across
five tasks.

**The seam is the claim vocabulary, not the biggest cluster.** Extract `CLAIM_ARG`, `CART_META_KEY`,
`is_token_shaped()`, `claim_state()`, `is_claimable()`, `is_claimable_by_token()`,
`claim_failure_message()`, `generate_token()`, `claim_url()`, `build_cart_item_data()` and the two cart
hooks into `SPLM_Waitlist_Claim`. Two reasons:

- Those are the members with external consumers. `SPLM_Waitlist_Gate` and `SPLM_Waitlist_REST` both reach
  into a thousand-line orchestration class to obtain four pure predicates. Extracting makes the dependency
  honest and cuts the class by roughly a third.
- The branch's Critical finding was a DRY collision inside exactly that vocabulary — `is_claimable()`
  serving two rules that differ. A class that owns the claim vocabulary is where that distinction has an
  obvious home; a class that owns everything is why it went unnoticed across five task reviews.

Deferred because it is a pure move-refactor across five files whose safety net does not cover the ingestion
end, and because it would have churned the diff at the moment the staging pass most needed to be
unambiguous. Do it after staging is green.

> **DONE — this item is closed.** The repo owner subsequently asked for a full repair pass on the static
> analysis findings (see item 8), which made this split the right move after all rather than a deferral.
> Commit `56205c9` extracted `SPLM_Waitlist_Claim` exactly as argued above, and went one step further:
> the offer's convener actions became `SPLM_Waitlist_Offer` and the scheduler's side became
> `SPLM_Waitlist_Expiry`, on the argument that the scheduler is a different actor — no lock, no human
> waiting, returning `bool`/`int` rather than `WP_Error`, failures swallowed by design. Every assertion
> count held across the move. What remains in `SPLM_Waitlist` is order-driven ingestion and tie-back,
> which two reviews independently judged should stay together.

---

## 4. Residual Minors from the fix-wave re-review

| Item | Location | Adjudication |
|---|---|---|
| `set_target()` accepts a `claimed` or `cancelled` row, so a fulfilled registration's product could be repointed with `resolved_order_id` left pointing at an order for a different product | `class-waitlist.php` — only `offered` is refused | **Follow-up.** UI-unreachable (the control renders only when the row has no target, and a claimed row has one), so it needs a hand-made API call from someone holding manager capability. A `can_offer( $row->status )` check is the one-line tightening. |
| The inline set-target input has no accessible name, and the per-row `Set` buttons are all named just "Set" with no row context — both inside a `role="note"` container that is not meant to hold interactive controls | `src/dashboard/pages/Waitlist.jsx` | **DONE** — closed by commit `ee06f14` during the repair pass in item 8. The input got a real `<label>`, each `Set` button an `aria-label` naming its row, and the interactive controls moved out of the `role="note"` region. |
| The Waitlist nav item shares a glyph with Rosters | `src/dashboard/components/Layout.jsx` | **Accept.** The named defect (duplicating the *adjacent* Payments icon) is fixed, and this nav already tolerates a shared glyph between `leaders` and `season-report`. |
| `Fake_WPDB::get_row()` keys off the bound parameter rather than the row's `claim_token` column, so it cannot reproduce "the row became unfindable because its token was nulled" behaviourally | `tests/test-waitlist-claim.php` | **Accept.** The guard is a field assertion rather than a behavioural one, but it was verified to fail against the pre-fix code by direct reproduction, so it does its job. |

---

## 5. Documentation drift

The spec still describes `check_cart_items()` and the `woocommerce_check_cart_items` listener as the design
(`../specs/2026-09-02-waitlist-system-design.md`, the purchase-gating section). That handler was **deleted**
during the fix wave, after a reviewer verified against WooCommerce source that
`WC_Cart_Session::get_cart_from_session()` removes a non-purchasable item and fires
`woocommerce_cart_item_removed_message` at `wp_loaded` priority 10 — before `woocommerce_check_cart_items`
ever fires. The code and its comments were updated; the spec was not.

---

## 6. Operational consequence worth knowing

When an offer lapses and a convener offers the *next* person, the lapsed row now keeps its claim token (this
is deliberate — it is what lets a late-completing order find its own offer, and it closed the branch's
Critical). So if player 1's still-Processing order completes days later, their row is marked `claimed`
alongside player 2's.

Pre-fix, that same sequence silently dropped player 1 entirely. Post-fix the double-book becomes **visible
in the queue** rather than invisible — strictly better, but the actual capacity control is item 1 above.
Cancel-then-reoffer is immune, because `cancel()` still nulls the token.

---

## 7. Environment

`sportspress-league-manager/node_modules` is owned by `root:root`, so `npm ci` fails with `EACCES` and the
dashboard bundle can only be rebuilt against the existing tree. That is why the build artifacts on this
branch were not produced from a clean install. Fixing it needs a `chown`; it is the same class of ownership
problem as the `wp-content/uploads` issue seen earlier on staging.

---

## 8. Codacy: a repair pass, then ten owner-authorised suppressions

A fresh Codacy scan of this branch's PR came back with 51 findings. A full repair pass — not suppression —
took that to 12, and none of it changed behaviour:

- `build_row()` (`class-waitlist.php`): cyclomatic 22 → 3, via named extracted decisions.
- `ingest_order()` (`class-waitlist.php`): NPath 577 → 3.
- `register_routes()` (`class-waitlist-rest.php`): 235 lines → 21, by extracting one argument-definition
  helper per route.
- `SPLM_Waitlist` (as it stood mid-branch, 1225 lines): split into four classes by concern. The commit
  (`56205c9`) added `SPLM_Waitlist_Claim`, `SPLM_Waitlist_Offer` and `SPLM_Waitlist_Expiry`, leaving
  `SPLM_Waitlist` itself holding order-driven ingestion and tie-back. `SPLM_Waitlist_Gate` and
  `SPLM_Waitlist_Database` already existed and were not products of the split. **This means item 3 below
  is now done** — see the correction appended to it.
- PHPCS: 27 errors → 0.
- `UnusedFormalParameter`: repaired via `func_get_arg()` rather than suppressed, everywhere it appeared.

Of the 12 remaining, ten were suppressed with the owner's explicit authorisation, each carrying its own
justification in the class docblock (the `class-season-audit.php` convention: one
`@SuppressWarnings(PHPMD.RuleName)` per rule, on the class declaration). The other two have no repo-side
suppression mechanism at all and stay visible — see below.

| Class | Rule(s) | Why this is a metric artefact, not a defect |
|---|---|---|
| `class-waitlist.php` | `TooManyMethods`, `ExcessiveClassComplexity` | Two mirror-image order handlers (ingestion, tie-back) that two independent reviews judged should not be split — they share the order object, the table, and the lifecycle. The method count rose *because* `build_row()` and `ingest_order()` were repaired by extraction; class complexity is a sum, so it rose too (69→67 net, against +14 from extraction alone). |
| `class-waitlist-rest.php` | `TooManyMethods`, `ExcessiveClassComplexity` | Six REST routes' worth of per-route argument-definition helpers, extracted specifically to shrink `register_routes()`. Splitting the public claim route from the admin routes was considered and rejected at this size. |
| `class-waitlist-gate.php` | `TooManyMethods`, `ExcessiveClassComplexity` | Complexity here is guard density, not branching: every `WC()`, `WC()->session`, `WC()->cart` read is null-guarded for REST/cron contexts and hostile session input, and at least one guard exists because a reviewer reproduced a PHP 8 warning without it. Removing a guard to lower the metric reintroduces a defect. |
| `class-waitlist-database.php` | `TooManyMethods` | A single-table gateway holding its own schema and queries, same as the two sibling gateways (`class-discipline-database.php`, `class-player-notes-database.php`) elsewhere in the repo. Splitting only this one makes it the odd file out for a metric. |
| `class-waitlist-offer.php` | `TooManyMethods` | One method over the threshold. These are the convener's actions for a single offer's lifecycle; the scheduler's side is already its own class (`SPLM_Waitlist_Expiry`). |

**The structural finding underneath all eight PHPMD suppressions.** PHPMD's method-count and
class-complexity metrics are in direct tension with its method-complexity metrics. Repairing a method's
cyclomatic complexity by extraction necessarily raises its class's method count, and because class
complexity is a sum over methods, raises that too. The two cannot both be satisfied by decomposition
*within* a class — only by moving methods to a different class, and that is only a real fix when a genuine
concern boundary exists to move them across. Absent that boundary, chasing both metrics at once means
undoing the extraction that fixed the first one.

**The two that could not be suppressed.** `Lizard nloc-medium` on `Waitlist.jsx` flags `Waitlist` (67 lines,
limit 50) and `AddEntryForm` (63 lines) — both already under cyclomatic 6, after seven components were
extracted from this file; what is left is form and table JSX, which Lizard counts as lines of code the same
as logic. Lizard has no inline suppression comment, so the repo-side options were checked before concluding
anything: there is no `.codacy.yml`, `.codacyrc`, or `.codacy/` directory anywhere in this repository, so
there is no file- or pattern-level exclusion to add for these two findings even in principle. And this
org's Codacy tooling is already known (from prior triage — see `codacy-gate-triage` in project memory) to be
locked by an org-level Coding Standard that repository config cannot override, so a repo-level exclusion,
if one existed, would likely not take effect regardless. Rather than add a suppression that does nothing,
these two are left visible in the gate.

**For whoever revisits this.** Re-examine concern boundaries before splitting anything further — a split
argued for by the metric alone is the failure mode this section documents. The `SPLM_Waitlist_Claim`
extraction in item 3 is still the one split on this branch with an actual argument behind it (external
consumers reaching into a thousand-line class for four pure predicates, and the claim vocabulary being
where the branch's Critical DRY finding lived); it remains the template for what a justified split looks
like here, not the eight left alone above.
