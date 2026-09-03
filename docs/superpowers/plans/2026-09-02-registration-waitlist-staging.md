# Deferred staging verification

> ## STEP 0 — CONFIRM THE PRODUCT TOPOLOGY FIRST. Nothing below runs without it.
>
> The whole-branch review established that this feature presupposes **two coexisting products per
> season/position**: one carrying the waitlist category, one carrying the registration category, both with the
> same season code and the same goalie-tag state. `find_target_product()` looks for a registration-categorised
> sibling that does *not* carry the waitlist category.
>
> The design spec's own baseline instead describes **one** product whose category is flipped when the season
> fills. If that is what the store actually does, every ingested row gets `target_product_id = 0`, every offer
> refuses with 409, and `target_product_ids()` returns empty — so **the purchase gate can never be enabled** and
> the rest of this checklist cannot be executed.
>
> ```bash
> docker exec -u www-data staging-wp wp eval \
>   '$reg = SPLM_Waitlist_Matcher::category_ids_for_keyword( SPLM_Waitlist_Matcher::registration_keyword() );
>    $wl  = SPLM_Waitlist_Matcher::category_ids_for_keyword( SPLM_Waitlist_Matcher::keyword() );
>    echo "registration cats: ", implode( ",", $reg ), "\n";
>    echo "waitlist cats: ", implode( ",", $wl ), "\n";
>    foreach ( array( "player", "goalie" ) as $pos ) {
>      printf( "target for the current season / %s: %d\n", $pos,
>        SPLM_Waitlist_Matcher::find_target_product( "W2025-26", $pos ) );
>    }' --allow-root
> ```
>
> A non-zero target for each position means the two-product topology holds — proceed. **A `0` here is not
> information, it is a stop condition**: it means the precondition is not met, and the finding is the missing
> set-target capability rather than a staging result. (An earlier draft of the Task 5 section below said a `0` was
> "information, not failure". That was wrong and is corrected there.)

Per the controller ruling in `progress.md`: implementers do not mutate staging. Every task's
staging-verification step is collected here and run as one consolidated pass at the end of the
branch, before the PR.

Environment: `ssh tikal`, container `staging-wp`. **Always run wp-cli as www-data**
(`docker exec -u www-data staging-wp wp ... --allow-root`) — running as root creates root-owned
files under `wp-content/uploads/` and has already caused one bug this session.

Substitute real values for `ORDER_ID`, `TARGET_PRODUCT_ID`, `APP_PASSWORD` and the staging hostname.

---

## From Task 3 — CRUD against a real `$wpdb`

The reviewer flagged that no unit test exercises the CRUD (by design — thin I/O). Coverage is
distributed across the passes below; this table is the audit trail.

| Method | Verified by |
|---|---|
| `create_table`, `maybe_upgrade`, `table_exists` | Task 4 pass |
| `insert`, `get`, `find_active` | Task 6 pass |
| `update`, `get` | Task 7 pass |
| `past_due_offered`, `is_past_due` end-to-end | Task 8 pass |
| `query`, `target_product_ids` | Task 12 pass |
| `find_by_token` | Task 9 pass |
| `find_offered_for_product` | Task 10 pass |

## Task 4 — module, schema, HPOS

```bash
docker exec -u www-data staging-wp wp option get spat_enabled_modules --allow-root

docker exec -u www-data staging-wp wp eval \
  'update_option("spat_enabled_modules", array_unique(array_merge((array)get_option("spat_enabled_modules",array()),["league_waitlist"])));' --allow-root
docker exec -u www-data staging-wp wp db query "SHOW TABLES LIKE '%splm_waitlist'" --allow-root
docker exec -u www-data staging-wp wp db query "DESCRIBE wp_splm_waitlist" --allow-root
docker exec -u www-data staging-wp wp option get splm_waitlist_db_version --allow-root
```

Expect: sixteen columns; `claim_token` shows `Null: YES` with a `UNI` key; version reads `1.0.0`.
Then confirm **WooCommerce → Settings → Advanced → Features** no longer lists SPLM as incompatible.

## Task 4b — uninstall cleanup (added by the whole-branch review)

Run this **last**, on a throwaway copy — it deletes data. It exists because the review verified against WP core
(`cron.php:611`) that `wp_clear_scheduled_hook()` keys on `md5( serialize( $args ) )` and therefore only
unschedules *argless* events, while every event this feature schedules carries `array( $id )`.

```bash
# With at least one row `offered` (so an expiry event is pending), then uninstall the plugin.
docker exec -u www-data staging-wp wp cron event list --fields=hook --allow-root | grep -c splm_waitlist   # expect >= 1
docker exec -u www-data staging-wp wp plugin uninstall sportspress-league-manager --allow-root
docker exec -u www-data staging-wp wp cron event list --fields=hook --allow-root | grep -c splm_waitlist   # expect 0
docker exec -u www-data staging-wp wp db query "SHOW TABLES LIKE '%splm_waitlist'" --allow-root             # expect empty
docker exec -u www-data staging-wp wp db query \
  "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_splm_waitlist_gated'" --allow-root                     # expect 0
```

The event count **fails before the I2 fix** — that is the point of the step.

## Task 4c — degraded environments (added by the whole-branch review)

Neither of these is exercised anywhere in the suite.

```bash
# WooCommerce inactive with the module enabled — this is I4's fatal.
docker exec -u www-data staging-wp wp plugin deactivate woocommerce --allow-root
curl -s -o /dev/null -w '%{http_code}\n' --user admin:APP_PASSWORD \
  -X POST "https://staging.example/wp-json/splm/v1/waitlist" \
  -d 'name=Test&email=t@example.com&season=S2026&position=player&target_product_id=1'
# ...load the dashboard too, then reactivate.
docker exec -u www-data staging-wp wp plugin activate woocommerce --allow-root
```

Then: **module disabled with an offer in flight.** Disable `league_waitlist` while a row is `offered` — the
expire handler unhooks and the REST sweep stops, so the row sits past its deadline indefinitely. Re-enable and
confirm the sweep catches up rather than leaving it stuck.

Finally, one timing number rather than a belief: `is_purchasable()` runs for every product in every loop, so time
a 20-product loop with the module enabled and disabled and record the difference.

## Task 5 — matcher I/O

```bash
docker exec -u www-data staging-wp wp eval \
  'echo "waitlist cats: "; print_r(SPLM_Waitlist_Matcher::category_ids_for_keyword(SPLM_Waitlist_Matcher::keyword()));
   echo "registration cats: "; print_r(SPLM_Waitlist_Matcher::category_ids_for_keyword(SPLM_Waitlist_Matcher::registration_keyword()));
   echo "target for the current season: "; var_dump(SPLM_Waitlist_Matcher::find_target_product("W2025-26","player"));' --allow-root
```

A `0` target here means the **two-product precondition is not met — stop** and see Step 0. (This line
previously read "information, not failure"; the whole-branch review showed that normalises the one condition
that makes the feature inert.)

## Task 6 — ingestion, and its idempotency

> **This pass is not optional.** Task 6's reviewer established that nothing in the PHP suite
> instantiates `SPLM_Waitlist` or calls `handle_order_status_changed()` / `ingest_order()`. The hook
> wiring, the order iteration, the variation-parent lookup and the `find_active()` /
> `find_by_source_order()` calls have only code-reading behind them. If any single staging pass in
> this file gets run, it is this one.
>
> Add to the checks below: **replay an already-claimed order's status transition** and confirm no
> second queued row appears — that is the per-source-order guard added in Task 6's fix round.

```bash
docker exec -u www-data staging-wp wp eval \
  '$orders = wc_get_orders(["status"=>"processing","limit"=>5]);
   foreach ($orders as $o) { echo $o->get_id()," ",$o->get_billing_email(),"\n"; }' --allow-root

docker exec -u www-data staging-wp wp eval \
  'echo SPLM_Waitlist::ingest_order(wc_get_order(ORDER_ID)), " rows created\n";' --allow-root
docker exec -u www-data staging-wp wp eval \
  'echo SPLM_Waitlist::ingest_order(wc_get_order(ORDER_ID)), " rows created\n";' --allow-root

docker exec -u www-data staging-wp wp db query \
  "SELECT id, season, position, email, target_product_id, status, created_at FROM wp_splm_waitlist" --allow-root
```

Expect: first replay `1 rows created`, **second `0`** (the duplicate guard). Compare `created_at`
against `date -u` — they must agree, not differ by the site's offset.

## Task 7 — offer, refusals, and the mail-failure unwind

```bash
docker exec -u www-data staging-wp wp eval 'print_r(SPLM_Waitlist::offer(1, 48));' --allow-root
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status,claim_token,offered_at,expires_at FROM wp_splm_waitlist WHERE id=1" --allow-root
docker exec -u www-data staging-wp wp cron event list --fields=hook,next_run_relative --allow-root | grep splm_waitlist

docker exec -u www-data staging-wp wp eval 'print_r(SPLM_Waitlist::offer(1, 0));' --allow-root
docker exec -u www-data staging-wp wp eval 'print_r(SPLM_Waitlist::offer(1, 48));' --allow-root

docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist::cancel(1);
   SPLM_Waitlist_Database::update(1, ["status"=>"queued","claim_token"=>null]);
   add_filter("pre_wp_mail", function(){ return false; });
   print_r(SPLM_Waitlist::offer(1, 48));
   print_r(SPLM_Waitlist_Database::get(1));' --allow-root
docker exec -u www-data staging-wp wp cron event list --fields=hook --allow-root | grep splm_waitlist || echo "no pending expiry event — correct"
```

Expect: 64-char token, UTC `expires_at`, one scheduled event; `splm_invalid_hours` for 0 hours;
`splm_waitlist_bad_status` on a second offer; and the forced-failure run returns
`splm_waitlist_mail_failed` with the row back to `queued`, a null token, and **no** expiry event left.

Task 7's reviewer asked for three specific confirmations here, because nothing in the PHP suite verifies the
offer sequence as a sequence — only its payloads:

```bash
# (a) exactly ONE pending event after an offer, and ZERO after a forced unwind
docker exec -u www-data staging-wp wp cron event list --fields=hook,args,next_run_gmt --allow-root | grep splm_waitlist

# (b) the cron instant must equal the stored UTC deadline to the second
docker exec -u www-data staging-wp wp eval \
  '$r = SPLM_Waitlist_Database::get(1);
   echo "stored expires_at (UTC): ", $r->expires_at, "\n";
   foreach (_get_cron_array() as $ts => $hooks) {
     if (isset($hooks["splm_waitlist_expire_offer"])) { echo "cron next_run_gmt: ", gmdate("Y-m-d H:i:s", $ts), "\n"; }
   }' --allow-root

# (c) a re-offer of a previously CANCELLED row must leave exactly one event, not two
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1,["status"=>"queued","claim_token"=>null,"expires_at"=>null]);
   SPLM_Waitlist::offer(1, 24); SPLM_Waitlist::cancel(1);
   SPLM_Waitlist_Database::update(1,["status"=>"queued"]);
   SPLM_Waitlist::offer(1, 72);' --allow-root
docker exec -u www-data staging-wp wp cron event list --fields=hook,next_run_gmt --allow-root | grep -c splm_waitlist
```

Expect (a) `1` then `0`; (b) the two timestamps identical; (c) exactly `1`.

## Task 8 — the stale-event defence, and the sweep

```bash
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1, ["status"=>"queued","claim_token"=>null,"expires_at"=>null]);
   print_r(SPLM_Waitlist::offer(1, 1));' --allow-root
docker exec -u www-data staging-wp wp eval 'print_r(SPLM_Waitlist::cancel(1));' --allow-root
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1, ["status"=>"queued"]);
   print_r(SPLM_Waitlist::offer(1, 72));' --allow-root
docker exec -u www-data staging-wp wp eval 'var_dump(SPLM_Waitlist::expire_offer(1));' --allow-root
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status,expires_at FROM wp_splm_waitlist WHERE id=1" --allow-root
```

Expect `false`, and the row **still `offered`** with its 72-hour deadline intact. A `true` here
means the defence is broken.

```bash
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1, ["status"=>"offered","expires_at"=>gmdate("Y-m-d H:i:s", time()-60)]);
   echo SPLM_Waitlist::sweep(), " expired\n";
   print_r(SPLM_Waitlist_Database::get(1));' --allow-root
```

Expect `1 expired`, status `expired`, `claim_token` null.

## Task 9 — the claim route

```bash
TOKEN=$(docker exec -u www-data staging-wp wp db query \
  "SELECT claim_token FROM wp_splm_waitlist WHERE status='offered' LIMIT 1" --skip-column-names --allow-root)

curl -s -o /dev/null -D - "https://staging.example/wp-json/splm/v1/waitlist/claim/$TOKEN" | head -5
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status FROM wp_splm_waitlist WHERE claim_token='$TOKEN'" --allow-root
curl -s -o /dev/null -D - "https://staging.example/wp-json/splm/v1/waitlist/claim/$(printf 'a%.0s' {1..64})" | head -5
curl -s -o /dev/null -w '%{http_code}\n' "https://staging.example/wp-json/splm/v1/waitlist/claim/nope"
```

Expect: `302` with an add-to-cart `Location`; the row **unchanged** afterwards (the prefetch-safety
property); `200 text/html` for an unknown token; `404` for a malformed one.

Then open the 302's `Location` in a browser, complete checkout, and confirm the binding survived:

```bash
docker exec -u www-data staging-wp wp eval \
  '$o = wc_get_order(ORDER_ID);
   foreach ($o->get_items() as $i) { var_dump($i->get_meta("_splm_waitlist_id")); }' --allow-root
```

## Task 10 — tie-back, both paths

```bash
# Path 1 — token on the line item
docker exec -u www-data staging-wp wp eval \
  '$o = wc_get_order(ORDER_ID);
   foreach ($o->get_items() as $i) { echo "line item token: ", $i->get_meta("_splm_waitlist_id"), "\n"; }' --allow-root
docker exec -u www-data staging-wp wp eval '$o = wc_get_order(ORDER_ID); $o->update_status("completed");' --allow-root
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status,resolved_order_id,claim_token FROM wp_splm_waitlist" --allow-root

# Path 2 — no claim link, same billing email
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1,["status"=>"queued","claim_token"=>null]);
   print_r(SPLM_Waitlist::offer(1,48));' --allow-root
# ...place an order for the target product in a browser, no splm_wl arg...
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status,resolved_order_id FROM wp_splm_waitlist WHERE id=1" --allow-root

docker exec -u www-data staging-wp wp cron event list --fields=hook --allow-root | grep splm_waitlist || echo "no pending expiry event — correct"
```

Expect both paths to land `claimed` with `resolved_order_id` set and a null token, and the log's
`matched_by` to read `token` for the first and `email_or_user` for the second.

> **On reading `matched_by`:** Task 9's review established that `SPAT_Logger::write()` appends its `$context`
> array only when verbose logging is enabled, so a context-carried diagnostic is stripped on a default install.
> Task 10 was therefore directed to fold `waitlist_id`, `order_id` and `matched_by` into the log *message string*,
> so this check works without touching `spat_verbose`. If the line comes back bare, that direction was not
> followed — treat it as a finding rather than enabling verbose to work around it.

## Task 10b — the clock collision (added by the whole-branch review; catches the branch's Critical)

This is the single step that exercises C1. The tie-back keys on order *completion* while the offer expires on
wall-clock time, and this league completes registration orders by hand.

```bash
# Offer with a 1-hour window, then claim it through the link in a browser
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1,["status"=>"queued","claim_token"=>null,"expires_at"=>null]);
   print_r(SPLM_Waitlist::offer(1, 1));' --allow-root
# ...open the emailed claim link, complete checkout, leave the order in Processing...

# Force the offer past its deadline, then let it expire
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1,["expires_at"=>gmdate("Y-m-d H:i:s", time()-60)]);
   echo SPLM_Waitlist::sweep(), " expired\n";' --allow-root

# NOW complete the order, as a convener would days later
docker exec -u www-data staging-wp wp eval '$o = wc_get_order(ORDER_ID); $o->update_status("completed");' --allow-root
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status,resolved_order_id FROM wp_splm_waitlist WHERE id=1" --allow-root
```

Expect `status=claimed` with `resolved_order_id` set. Before the C1 fix this leaves the row `expired` with a null
`resolved_order_id` — a paid player recorded as having lost their spot.

## Task 11 — the purchase gate (RUN GATE-OFF FIRST)

This is the highest-risk verification in the branch: a bug makes a live registration product
silently unbuyable, which to a player looks like a broken site.

```bash
# 1. Gate off — nothing should have changed
docker exec -u www-data staging-wp wp db query \
  "SELECT post_id FROM wp_postmeta WHERE meta_key='_splm_waitlist_gated'" --allow-root
docker exec -u www-data staging-wp wp eval \
  '$ids = get_posts(["post_type"=>"product","post_status"=>"publish","posts_per_page"=>20,"fields"=>"ids"]);
   foreach ($ids as $id) { $p = wc_get_product($id); printf("%d %s %s\n", $id, $p->is_purchasable() ? "YES" : "NO", $p->get_name()); }' --allow-root
```

Any product flipping to `NO` here is a bug in the cheap-exit path. **Stop and fix before gating anything.**

```bash
# 2. Gate on
docker exec -u www-data staging-wp wp eval \
  'var_dump(SPLM_Waitlist_Gate::set_gated(TARGET_PRODUCT_ID, true));' --allow-root
docker exec -u www-data staging-wp wp eval \
  '$p = wc_get_product(TARGET_PRODUCT_ID); var_dump($p->is_purchasable());' --allow-root
docker exec -u www-data staging-wp wp eval \
  '$ids = get_posts(["post_type"=>"product","post_status"=>"publish","posts_per_page"=>20,"fields"=>"ids"]);
   foreach ($ids as $id) { $p = wc_get_product($id); printf("%d %s\n", $id, $p->is_purchasable() ? "YES" : "NO"); }' --allow-root
```

Then, logged out, in a browser:

1. The gated product page — add-to-cart must be gone.
2. A live offer's claim link — the 302 must land you in the cart with the item present.
3. Proceed to checkout — the item must **stay** (this is the checkout-time `is_purchasable()` re-run; a
   session-less implementation loses it here).
3a. Open the claim link in a **fresh private window** with no prior WooCommerce cookie, then navigate away to
   another page and return to the cart. The whole claim flow rests on the session entitlement surviving that,
   and the reasoning that it does is a two-hop inference across three WooCommerce methods — verify it, do not
   trust the trace.
3b. **Multi-spot check.** With one live offer, set quantity to 3 at the cart and check out. Then, still holding
   the entitlement, place a *second* order for the same product. Both succeed today — the gate limits who can
   buy, not how many. See the follow-up issue; worth seeing the real behaviour before prioritising it.
4. Complete the order — the row goes `claimed`.
5. Expire the offer mid-checkout, reload — the item leaves the cart with the "your invite expired"
   notice, not WooCommerce's default wording.

```bash
# 3. Un-gate and confirm it is public again
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Gate::set_gated(TARGET_PRODUCT_ID, false);
   $p = wc_get_product(TARGET_PRODUCT_ID); var_dump($p->is_purchasable());' --allow-root
```

## Task 12 — admin REST routes

```bash
BASE="https://staging.example/wp-json/splm/v1"
COOKIE="--user admin:APP_PASSWORD"

curl -s $COOKIE "$BASE/waitlist?season=S2026" | python3 -m json.tool | head -30
curl -s $COOKIE "$BASE/waitlist" | grep -c claim_token   # expect 0
curl -s $COOKIE "$BASE/waitlist?status=pending"          # expect 400
curl -s $COOKIE -X POST "$BASE/waitlist/1/offer" -d 'hours=0'    # expect 400
curl -s $COOKIE -X POST "$BASE/waitlist/999999/cancel"           # expect 404
curl -s -o /dev/null -w '%{http_code}\n' "$BASE/waitlist"        # expect 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE/waitlist/gate" -d 'product_id=1&gated=1'  # expect 401
curl -s $COOKIE -X POST "$BASE/waitlist/gate" -d 'product_id=1&gated=1'   # expect 400 unless product 1 is a real target
```

## Task 13 — the dashboard page

1. Enable `league_waitlist` — the Waitlist nav item appears. Disable — it disappears.
2. Timestamps render in local time. Compare a `created_at` against the DB's UTC value: correctly
   offset, not doubled.
3. Offer a spot with a custom window — the countdown shows and ticks.
4. A row with no paired product shows the flag and a disabled Offer button.
5. Cancel an offer; re-offer it.
6. Toggle a gate from Season access — the confirm dialog fires.
7. Add someone manually, then add them again — the duplicate refusal surfaces as an error notice.
