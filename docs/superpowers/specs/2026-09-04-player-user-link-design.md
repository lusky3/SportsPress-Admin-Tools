# Player↔User Link — Design

**Status:** approved design, not yet planned or implemented
**Date:** 2026-09-04
**Author:** Cody (lusky3), with Claude
**Plugin:** `sportspress-player-tools` — both parts (see "Where Part 2 lives")
**Reported as:** "the player profile image is targeting the wrong image"

## The bug, confirmed

`SPT_Player_Profile_Picture::get_user_player_posts()` resolves "your player record"
by **post author**:

```php
get_posts( array(
    'post_type'      => 'sp_player',
    'author'         => $user_id,   // wrong link
    'posts_per_page' => -1,
    'fields'         => 'ids',
) );
```

Post author records who *created* a record, not who it is *about*. Confirmed on
`arl-local` against a player with a known photo:

| Account | What the Profile Picture page does |
|---|---|
| 9 `Cody` | Refuses — "multiple player profiles"; he authored **294** players |
| **1276 `codylusk`** | **Shows and writes player 3352 "Nick Prystie"** |
| 825, 1872 | Menu item hidden — authored none |

Cody's real record is player **66**, carrying a genuine photo (attachment 67,
uploaded 2016). It is never touched. On the `codylusk` account the page presents
Nick Prystie's record as "your Profile Picture", and pressing Upload calls
`set_post_thumbnail( 3352, … )` — writing Cody's photo onto **Nick Prystie's**
player profile.

So it is not targeting the wrong image. It is targeting the wrong *player*, and
it writes there. That is the whole severity of this bug: a read bug shows you
someone else's face; this one defaces someone else's record.

### Measured blast radius

Only accounts authoring exactly one player get through the form at all:

| Class | Count |
|---|---|
| `sp_user` set and agrees with author — correct | 58 |
| `sp_user` unset, player name matches the user — correct by accident | 145 |
| `sp_user` set but **disagrees** with author — definitively wrong | 2 |
| `sp_user` unset and name does not match — suspect (incl. `codylusk`→Nick Prystie) | 18 |
| Authors of >1 player — refused outright | 13 accounts |

## Status of this document

**Part 1 (the fix) is implemented and stands. Part 2 (a new backfill) is
WITHDRAWN.** Three peer reviews and a look outside this repo refuted several of
the claims the original Part 2 rested on. Corrections are recorded below rather
than quietly edited away, because two of them are mistakes worth not repeating.

Consolidating `sp_user` ownership is now tracked in its own document:
`2026-09-05-sp-user-ownership-design.md`.

## What `sp_user` actually is

`sp_user` links a WordPress user to an `sp_player` record. SportsPress does not
define it: the only `sp_user*` keys in SportsPress Pro are `sp_user_scores` and
`sp_user_results`, which are event score submissions and unrelated.

**Correction 1 — SportsPress is not silent on player↔user.** An earlier draft
said it "has no player↔user concept at all". It has one, and it is
`post_author`: `sportspress-user-registration.php:187-200` sets
`post_author = $user_id` when SportsPress itself creates a player at user
registration. So the original `'author' => $user_id` query was upstream-shaped,
not arbitrary. The fix is still right — a 2,100-row bulk-imported roster makes
authorship meaningless *here* — but for a narrower reason than first claimed.

**Correction 2 — `sp_user` does not have one writer. It has five, across four
codebases.** The original draft grepped `wp-content/plugins/` and concluded
there was a single writer. The most consequential one is in the active theme.

| Writer | Where | Notes |
|---|---|---|
| `link_user_to_player()` | `sportspress-player-registration` | per-order, at checkout |
| **Player claim flow** | **`rookiehockey-blueline` theme**, `inc/account/player-link.php:891` | **user-facing**; fuzzy match ≥0.85 against `billing_first_name`/`billing_last_name`, which the account holder edits |
| GDPR eraser | `sportspress-admin-tools`, `class-privacy.php:507` | deletes it, leaves no tombstone |
| Merge processor | `sportspress-player-merge` | raw `REPLACE(meta_value…)` over `meta_key LIKE 'sp_%'` — a **substring** rewrite that can corrupt ids |
| Merge restore | `sportspress-player-merge` | `add_post_meta` on restore |

All four codebases are ours and modifiable, which is what makes consolidation
possible rather than merely desirable.

### Coverage: the denominator matters more than the number

**Correction 3 — the "83% of players are unlinked" figure measured the wrong
population.** It counted every `sp_player` post ever created, i.e. two decades
of people who have left the league. Measured against the players who would
actually use an account feature:

| Season (staging) | Players | Linked | |
|---|---|---|---|
| **W2026-27 (current)** | 285 | 227 | **80%** |
| S2026 | 350 | 175 | 50% |
| W2025-26 | 524 | 301 | 57% |
| *All players, all time* | *2,183* | *367* | *17%* |

The theme repo had already made and corrected this same class of error, against
a different wrong denominator — `scripts/one-off/2026-08-11-sp-user-backfill.php`
calls its own earlier figure "a retracted premise, off by roughly 5x". Two
independent attempts reached for a convenient denominator; **state the
population before the percentage.**

## Why Part 2 was withdrawn

A new backfill was specced here to close an 83% gap that does not exist as
described. Beyond the denominator, three things settled it:

1. **The work already exists.** The theme ships a self-service claim flow —
   described in its own docs as "the PRIMARY mechanism" — plus a conservative
   one-off backfill (auto-writes only at ≥0.95, never in [0.85, 0.95)).
2. **It would have been a second matcher.** The proposed signal ladder
   duplicated `blueline_name_match_score()` on a different population with a
   looser bar. The theme's backfill docblock names this exact hazard: "a second,
   subtly different matcher would be exactly the kind of hazard this task exists
   to avoid."
3. **Its primary signal answered the wrong question.** `find_existing_player()`
   resolves "which player does this ORDER register", not "which player IS this
   account". An order carries two identities — `_customer_user` (who paid) and
   the billing name (who is being registered) — and the draft paired them with
   no check they were the same person. A captain paying for three teammates
   would have produced three pre-checked HIGH links onto strangers' records.
   The tell was already in this document: the 2 records where `sp_user`
   disagrees with `post_author` were produced by exactly that conflation.

**Correction 4 — the "load-bearing invariant" was false.** This document
claimed email-sync only writes an author-derived address when `sp_user` already
confirms the author, and that the backfill was therefore non-circular. The
`sp_user` check selects a *confidence label*; it does not gate the write.
`SPT_Email_Sync::handle_apply()` writes whatever was posted with no confidence
check, so an admin ticking one LOW row today writes an author-derived address
onto an unlinked player. The circularity was reachable already.

The accurate, narrower invariant: author-derived emails are never *offered* for
authors of more than five players, and are only *pre-checked* when `sp_user`
confirms the author.

## What still needs doing

These stand on their own merits and are carried into the ownership design:

- **`sp_user` has no provenance.** Nothing records whether a link came from a
  paid registration, a self-service claim, or a script — so nothing downstream
  can weigh it.
- **There is no un-link path anywhere.** No plugin or theme removes a link.
- **Server-side confidence is unenforced.** In email-sync, "only HIGH is
  pre-checked" is a rendered `checked` attribute; the apply handler trusts the
  POST. A comment in that file records this bug already shipping once.
- **The merge processor can corrupt links.** `REPLACE()` over
  `meta_key LIKE 'sp_%'` rewrites `sp_user = 13352` to `166` when merging 3352
  into 66.
- **A wrong link is one setting from write access.** `backfill-owners` sets
  `post_author` from `sp_user`; with `spr_owner_can_edit` on, that grants edit
  and delete on the player record.

## Testing

`sportspress-player-tools/tests/test-profile-picture-resolution.php`, 16
assertions, registered in `run-all-tests.sh`.

The design of that suite matters more than its size. Its `get_posts()` stub
honours **both** `author` and `meta_query` against a fixture roster and answers
whichever was asked — a stub that ignored its arguments would have passed
against the broken code. The tests assert the shape of the question, not just
the answer, and the `codylusk` → Nick Prystie case is a named regression
assertion. Confirmed failing on `main` before the fix was written.

Verified on `arl-local` against live data: `codylusk` resolves to no player (was
player 3352 "Nick Prystie"), user 2341 resolves to player 938 "Andrea Breuer"
whose `post_author` is a different user, and no thumbnail was mutated.

Staging checks still owed:

- [ ] A player with `sp_user` sees their own record and its existing photo.
- [ ] Uploading writes the thumbnail to the linked player and no other post.
- [ ] A user who authored one unlinked player sees the menu item gone; confirm
      player 3352 is untouched.
- [ ] Account 9 (294 authored) sees the refusal with the new copy.
- [ ] A player who self-claims through the theme's flow immediately gains the
      menu item and resolves to the claimed record.

## What this deliberately does not do

- **No author fallback**, ever. A fallback would put fuzzy identity matching
  back into a write path, and a bare author match is what produced the bug.
- **No writes.** This page only reads `sp_user`.
- **No un-link, no claim UI, no admin picker.** Handing someone who authored 294
  players a picker is the bug with a nicer interface. Un-linking is a real gap,
  but it belongs with the ownership work, not here.

## What Part 1 actually costs

The menu item disappears for players with no `sp_user` link. On current-season
figures that is about **20%** of players, not the 83% an earlier draft claimed —
and the theme's self-service claim flow is available to them today, so it is
recoverable without an administrator.

That is a much smaller cost than the risk it removes, and it does not block
merging.

## Effort

Part 1: one query, one string, one test file. **Implemented.**

Part 2: **withdrawn** — see "Why Part 2 was withdrawn". The follow-on work is
consolidating `sp_user` ownership into SPAT, specified separately in
`2026-09-05-sp-user-ownership-design.md`.
