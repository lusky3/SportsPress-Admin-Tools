# `sp_user` Ownership — Design

**Status:** approved direction, not yet planned
**Date:** 2026-09-04
**Author:** Cody (lusky3), with Claude
**Repos:** `SportsPress-Admin-Tools`, `rookiehockey-blueline`, `sportspress-player-merge`
**Follows:** [`2026-09-04-player-user-link-design.md`](2026-09-04-player-user-link-design.md)

## Goal

Give `sp_user` — the link between a WordPress user and an `sp_player` record —
a single owner, a provenance trail, and a way to be undone. Today it has five
writers across four codebases, no record of how any link was formed, and no way
to remove one.

## Why this is worth doing

`sp_user` stopped being decorative when the profile-picture fix shipped. It is
now the authorization token for a write path: whoever holds the link can
overwrite a player's photo. It is also read by the GDPR exporter and **eraser**,
by discipline-notice email routing, and by `backfill-owners`, which converts it
into `post_author` — and with `spr_owner_can_edit` enabled, into edit and delete
rights on the record.

So a wrong link is not a cosmetic problem. It routes someone's suspension notice
to a stranger, exposes one member's data to another under a GDPR request, and
can anonymise the wrong roster record irreversibly.

### The five writers today

| Writer | Where | Trigger |
|---|---|---|
| `link_user_to_player()` | `sportspress-player-registration` | per-order at checkout |
| Player claim flow | `rookiehockey-blueline` theme, `inc/account/player-link.php:891` | **any logged-in user**, fuzzy ≥0.85 against a self-edited billing name |
| GDPR eraser | `sportspress-admin-tools`, `class-privacy.php:507` | deletes, no tombstone |
| Merge processor | `sportspress-player-merge` | raw `REPLACE()` over `meta_key LIKE 'sp_%'` |
| Merge restore | `sportspress-player-merge` | `add_post_meta` |

## Part A — Move the claim flow into SPAT

### Where it lands: `sportspress-player-tools`

Not because the flow is admin-triggered — it is not; a logged-in player claims
their own record from WooCommerce My Account. It lands there because
**player-tools already owns that exact surface**: the `player_profile_picture`
module registers `woocommerce_account_menu_items` and an `add_rewrite_endpoint`,
and it *consumes* what the claim flow produces. They are siblings on one
surface, and they should share a module gate.

### Why it leaves the theme

- **A theme switch would delete the only mechanism that creates and repairs
  links, while every consumer keeps trusting them.** Data outlives the theme.
- **WordPress.org's "plugin territory" rule** forbids themes shipping this kind
  of functionality. If Blueline is ever open-sourced to .org — a stated
  possibility — the flow has to move regardless.
- **The code is already not doing theme work.** `player-link.php` contains zero
  `get_template_part` / `wp_enqueue` / `get_header` / `locate_template` calls,
  and zero rookiehockey-specific references.

### What moves, what stays

| File | Lines | Disposition |
|---|---|---|
| `inc/account/player-link.php` | 941 | **moves** — the flow, the scorer, the candidate pool |
| `inc/account/player-data.php` | 682 | **partly moves** — `blueline_get_player_team()` and `blueline_player_jersey_number()` are its only external dependencies; audit the rest |
| `tests/PlayerLinkTest.php` | 585 | **moves** with the code |
| `inc/account/dashboard.php` | 817 | **stays** — presentation |

### The league-specific part that must not move

Season and product resolution — "the newest child `product_cat` term of
Registration", `BLUELINE_REGISTRATION_TERM_ID` — encodes *this league's* product
taxonomy. SPAT ships to WordPress.org (`readme.txt`, `Stable tag`,
`plugin-check` CI), so that belongs behind a filter the site supplies:

```php
apply_filters( 'spt_player_claim_candidate_pool', $default_pool_query );
apply_filters( 'spt_player_claim_season_terms', array() );
```

Blueline keeps a small integration supplying its own convention, plus the My
Account templates. Without SPAT the theme still works and the feature is simply
absent.

## Part B — One scorer, two resolvers

"Unify the matchers" is the wrong instruction, and acting on it literally would
cause a bug. The two functions answer **different questions**:

| | Question | Method |
|---|---|---|
| `find_existing_player( $name, $email )` | *Which player does this ORDER register?* | exact: email → exact title → title with roster suffixes stripped |
| `blueline_find_player_candidates( $user_id )` | *Which player IS this USER?* | fuzzy: ≥0.85 score against the user's own billing/display name |

An order carries two identities — who paid, and who is being registered — and
they are frequently different people (a captain paying for teammates). Merging
these two resolvers would conflate payer with registrant, which is exactly the
defect that killed the previous draft's backfill.

**What is genuinely duplicated is the scoring and normalisation**, and that is
what the theme's own docblock warns about: *"a second, subtly different matcher
would be exactly the kind of hazard this task exists to avoid."*

So:

- **One shared primitive layer** — name normalisation, token rules,
  `name_match_score()`, `name_pair_is_specific_enough()`, tombstone detection —
  owned by player-tools and used by both resolvers.
- **Two resolvers, deliberately kept apart**, each named for its question, each
  with a docblock stating which identity it resolves and which it must never be
  used for.
- **Neither creates anything.** `find_existing_player()` is verified read-only
  today (traced transitively); promoting it to a supported seam means that
  guarantee needs a test, not just a comment — the double's `wp_insert_post`
  should fatal.

## Part C — The trust model

The three gaps that make a wrong link unrecoverable.

### Provenance

`sp_user` records a user id and nothing else. Add a companion meta recording how
the link was formed and by whom:

```
sp_user_source = registration | claim | admin | import
sp_user_set_at = UTC timestamp
sp_user_set_by = user id of the actor (0 for automated)
```

Provenance is what lets a consumer weigh a link: a paid-registration link and a
fuzzy self-claim are not equally trustworthy, and today nothing downstream can
tell them apart. It is also what makes a bad batch identifiable after the fact.

**Write it wherever `sp_user` is written**, including the GDPR eraser (which
should clear it) and the merge tool.

### Un-linking

Nothing in any codebase removes a link. Two paths are needed:

- **Self-service** — a player who claimed the wrong record can release it, in
  the same My Account surface that let them claim it.
- **Administrative** — a convener can unlink, with the reason recorded.

Releasing a link must clear the provenance meta with it, and must not delete the
player record.

### Server-side enforcement

`SPT_Email_Sync::handle_apply()` writes whatever was POSTed, with no confidence
check — its "only HIGH is pre-checked" guarantee is a rendered `checked`
attribute and nothing more. A comment in that file records this bug shipping
once already. Any apply handler that writes identity must **re-derive
confidence server-side** and refuse anything below its own bar, rather than
trusting the form it rendered.

This applies to the existing email-sync handler too, which is in scope as a
bug fix regardless of the rest of this work.

## Part D — Fixes in the other repos

Independent of the move, and worth doing on their own merits:

**`sportspress-player-merge` corrupts `sp_user`.** `class-sp-merge-processor.php:322-333`
runs `REPLACE( meta_value, %s, %s )` across `meta_key LIKE 'sp_%'`. That is a
**substring** rewrite of post ids: merging player 3352 into 66 rewrites
`sp_user = 3352` → `66` as intended, and `sp_user = 13352` → `166` silently. The
fix is an exact-match update on the keys that hold ids, not a substring replace
across a key wildcard. This is a live data-corruption bug and should not wait for
the rest.

**`backfill-owners` is one setting from granting write access.** It sets
`post_author` from `sp_user`; with `spr_owner_can_edit` enabled that is
`edit_post`/`delete_post` on the record. It stays out of scope, but its docblock
should say plainly what a wrong link costs once it has run.

## Testing

**PHPUnit, introduced alongside the existing harness — not a migration.**

SPAT runs 57 standalone `assert_test()` suites (~1,445 assertions) with no
WordPress bootstrap; Blueline runs 60 PHPUnit files (~1,349 assertions) against
a shared 2,758-line bootstrap. The theme's is the better harness here, on
evidence rather than taste:

- **SPAT re-stubs WordPress in 12 separate test files.** Stub quality varies by
  author, and a weak stub weakens the test silently. This is not theoretical:
  `test-email-sync.php`'s `get_posts()` stub ignores its arguments entirely, so
  reusing it for the profile-picture tests would have let all 16 assertions pass
  against the broken code — the bug *was* which arguments were passed.
- **`run-all-tests.sh` is a manual registry.** A suite not added to it never
  runs, and nothing says so. PHPUnit discovers.
- **`assert_test( bool, $message )` reports only that something broke.**
  `assertSame` reports expected against actual.
- **No coverage measurement is possible** in the standalone harness.

The decisive argument is narrower: the code being moved *already has* 585 lines
of working PHPUnit tests. Hand-porting them to a weaker harness would lose
coverage on identity code during a migration — the worst combination available.
Bring the tests with the code.

Coexistence, not a flag day: `run-all-tests.sh` keeps running all 57 suites
unchanged, CI gains a PHPUnit job for this module, and other suites migrate only
if someone chooses to. `.distignore` already strips `/tests`, so dev-only
dependencies are not a packaging problem.

The theme's bootstrap is **63 generic WordPress/WooCommerce stubs against 19
theme-specific symbols**, so most of it is the seed of the shared bootstrap SPAT
lacks — which is the durable fix for the 12-file duplication above.

### What the tests must cover

- Every case in the existing `PlayerLinkTest.php`, preserved through the move.
- Provenance written on every write path, and cleared on erase and release.
- An apply handler refusing a POST whose confidence it cannot re-derive.
- The shared scorer produces identical results for both resolvers.
- `find_existing_player()` creates nothing — with a double whose
  `wp_insert_post` fatals.
- Un-link removes the link and its provenance and leaves the player intact.

## Sequencing

1. **Merge-tool corruption fix.** Independent, live bug, no dependencies.
2. **Server-side confidence in email-sync's apply handler.** Same.
3. **Move the claim flow** to player-tools with its PHPUnit tests, behind the
   existing module gate, with the pool and season resolution exposed as filters.
   Blueline keeps templates and supplies its convention.
4. **Extract the shared scorer**, leaving the two resolvers separate and named
   for their questions.
5. **Provenance**, written by every writer at once — a partial rollout is worse
   than none, because a missing value is indistinguishable from an old link.
6. **Un-link**, both paths.

Steps 1 and 2 can ship immediately. Step 3 is the large one and should land as
its own PR pair (SPAT gains the module; Blueline loses it and gains the
integration) so the two repos can be reviewed together but reverted separately.

## What this deliberately does not do

- **No new backfill.** Withdrawn in the previous spec and not revived here.
  Current-season coverage is 80%, the claim flow is the primary mechanism, and a
  one-off top-up script already exists.
- **No change to `find_existing_player()`'s question.** It resolves orders. It
  is promoted to a supported seam, not repurposed.
- **No uniqueness constraint on `sp_user`.** One user legitimately holding two
  player records (a parent and their own record) is real; the correct response
  is surfacing it, not forbidding it at the storage layer.
- **No removal of `post_author` as a signal.** SportsPress itself sets it at
  user registration; it stays a corroborating input, never an authority.
