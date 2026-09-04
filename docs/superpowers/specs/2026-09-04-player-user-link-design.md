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

## What `sp_user` actually is

**Correction to a widely-held assumption, including one stated earlier in the
investigation:** SportsPress has **no player↔user concept at all.** The only
`sp_user*` keys in SportsPress Pro are `sp_user_scores` and `sp_user_results`,
which are event score submissions and unrelated. A grep across every plugin in
`wp-content/plugins/` finds exactly one writer of `sp_user`:

```
sportspress-player-registration/includes/class-player-registration.php:882
    update_post_meta( $player_id, 'sp_user', $user_id );
```

`sp_user` is **our own convention**, invented by this monorepo. That matters:
nothing upstream will ever populate it, so its coverage is entirely a function
of our own code paths.

It is written by `link_user_to_player()`, called from exactly one place — order
processing at `class-player-registration.php:162` — and only when the order
resolved to a player, the order has a logged-in customer (`$user_id > 0`), and
once per order. It sets both `sp_user` **and** `post_author`.

### Current coverage

| | Count |
|---|---|
| Published players | 2,108 |
| With an `sp_user` link | 204 (10%) |
| **Without any link** | **1,904 (90%)** |
| Of the 204: `post_author == sp_user` | 58 |
| Of the 204: `post_author != sp_user` | 146 |

The 90% are bulk imports, admin-created records, and anything predating the
feature. Cody's player 66 is one of them.

### The direction of truth is already settled

`sportspress-player-registration/includes/class-cli.php` carries
`wp spr backfill-owners`, whose docblock reads: *"Backfill `sp_player`
post_author from the `sp_user` meta link."* Authorship is the **derived** field;
`sp_user` is the source of truth. A sibling file in the very plugin holding the
bug — `sportspress-player-tools/includes/class-email-sync.php:516` — already
reads `sp_user` explicitly to *verify* an author claim, with confidence scoring
and tests asserting that "author without a matching `sp_user` is only low
confidence". The profile-picture class simply never received that treatment.

## Design

Two parts, shipped together. Part 1 alone would leave the feature dark for 90%
of players; Part 2 alone would leave the write bug in place.

### Part 1 — Strict `sp_user` resolution

`get_user_player_posts()` stops querying by author and queries the link:

```php
get_posts( array(
    'post_type'      => 'sp_player',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_query'     => array(
        array( 'key' => 'sp_user', 'value' => (int) $user_id ),
    ),
) );
```

- **No author fallback.** A fallback would bake fuzzy matching into a *write*
  path permanently, and it is precisely a bare author match that produced
  `codylusk` → Nick Prystie. Correctness here is worth more than availability.
- **Read-only.** This page never writes `sp_user`. Registration and the backfill
  remain the only writers, so there is one place to audit how a link is formed.
- **The 0/1/many branches stay as they are.** With `sp_user` checked first, an
  admin who has a link gets their own record no matter how many they authored;
  without one they keep hitting the existing refusal. The 13 multi-author
  accounts are out of scope.

Only the copy changes, because the meanings have changed:

| Case | New copy |
|---|---|
| 0 players | "Your account isn't linked to a player record yet. Please contact the site administrator." — now literally true, and actionable |
| >1 player | Unchanged wording; genuinely means two records share one link |

**Accepted regression, stated plainly:** on the day Part 1 ships, the Profile
Picture menu item disappears for every player without a link — including the 145
for whom it currently works by accident. This is deliberate. A hidden feature is
recoverable; a photo written onto a stranger's record is not.

### Where Part 2 lives

`sportspress-player-tools`, not `sportspress-player-registration`, despite
registration being the only current writer of `sp_user`.

The two write it for different reasons. Registration sets a link as a *side
effect* of processing one order, for one player, at the moment of purchase.
This is *bulk data repair* across 1,904 historical records with a human review
gate — which is what player-tools already is: five single-field meta boxes and
bulk CSV tools, including `SPT_Email_Sync`, whose panel, confidence ladder and
threshold constant this reuses directly. Putting a reviewed bulk operation
inside the order-processing plugin would mix a checkout code path with an
admin migration tool.

The argument against, recorded rather than dismissed: it means `sp_user` gains
a second writer in a second plugin, and the invariant in "One load-bearing
invariant" now spans two files that must stay in agreement. That is the cost
being accepted, and it is why the invariant gets a comment in both files rather
than only one.

#### The cross-plugin dependency this creates

Signal 1 calls `SPPR_Player_Registration::find_existing_player()`, which today is
**private** and lives in player-registration. Part 2 therefore needs it promoted
to a callable seam before it can be reused. Promote it to `public static`, or —
better — lift the matcher into its own class that both the order pipeline and
the backfill call, so neither owns it.

Whichever shape is chosen, two properties are non-negotiable and belong in its
docblock:

- **It creates nothing.** The moment a "find" helper grows a create path, this
  backfill inherits it silently and starts minting players.
- **It returns terminal `*_conflict` actions rather than guessing.** That
  behaviour is what makes it safe to run unattended across thousands of orders;
  a variant that picked a winner would be actively dangerous here.

Do NOT reach for `find_or_create_player()`. See "Rejected: bulk re-running order
processing".

This is a real coupling and worth naming: player-tools would gain a dependency
on a player-registration class. It is narrower than the alternative — the
alternative is a *second* name/email matcher, which would drift from the one
that actually processes orders and produce a backfill that disagrees with
registration about who a player is.

### Part 2 — A new `sp_user` backfill

A scan → preview → human-approves → apply tool, modelled directly on
`SPT_Email_Sync`, which already solves the same shape of problem in the same
plugin and the same admin panel. **Not** an automatic migration: see "Why reach
is deliberately unmeasured" below.

Candidate signals per unlinked player, highest confidence first:

1. **Completed WooCommerce order → customer → player** (HIGH). Walk completed
   orders, take the order's `_customer_user`, and resolve the player with
   `SPPR_Player_Registration::find_existing_player( $billing_name, $billing_email )`.
   That method is the pure-find half of the registration matcher — email, then
   exact title, then title with roster suffixes stripped — and it already
   returns terminal `*_conflict` actions instead of guessing when two records
   claim one person. It creates nothing.
2. **`spt_email` matches exactly one user's `user_email` or `billing_email`**
   (HIGH).
3. **Normalised name matches exactly one user's `display_name`** (LOW).
4. **`post_author`, corroborated by signal 2 or 3, and only when that author has
   authored no more than `BULK_IMPORT_AUTHOR_THRESHOLD` (5) players** (LOW).

Signal 1 supersedes an earlier draft that read the *registration log*
(`spat_registration_logs`) rather than the orders. That was a measurement
error worth recording, because it nearly sized this feature out of existence:
the log resolves **1** of the 1,904 unlinked players, because the log only
holds what was already processed. The orders behind it are a different
population entirely.

Rules:

- Reuse `SPT_Email_Sync::BULK_IMPORT_AUTHOR_THRESHOLD` rather than a second
  constant. Its rationale already covers this case exactly: five staff accounts
  author ~1,800 of the players, so anything above a handful is data entry.
- **Only HIGH is pre-checked.** LOW requires a human tick, mirroring email-sync.
- **More than one candidate user is never pre-checked**, at any confidence. It is
  listed for manual resolution.
- **Writes `sp_user` only.** `post_author` is left alone — that belongs to
  `wp spr backfill-owners`, which stays a separate, separately-approved step.
- **Idempotent and non-destructive.** Skips any player that already has a link;
  never overwrites one.
- **CSV export of the unmatched**, as email-sync does, so the remainder can be
  resolved offline and re-imported.

#### One load-bearing invariant

Signal 2 must never consume an `spt_email` that was itself derived from
`post_author`, or a weak author guess launders itself into a trusted link.

Today that cannot happen: `spt_email` stores no provenance, but email-sync only
writes an author-derived address when the player's `sp_user` *already* confirms
that author — and those players are excluded from the backfill by definition.
The two tools are safe as a pair only because of that ordering. **If
email-sync's priority-2 rule ever loosens, this backfill becomes circular.** A
comment saying so belongs in both files.

#### Reach: measured where it can be, and not where it cannot

Against the 1,904 unlinked players and the order history behind them:

| Signal | Reach on `arl-local` |
|---|---|
| **Completed orders with a logged-in customer** | **5,686** of 7,261 |
| **Distinct customers on those orders** | **1,445** |
| **…of whom have no `sp_user` link today** | **1,246** |
| Registration log → one user | 1 (the log is nearly empty) |
| `spt_email` present on an unlinked player | 0 — only 9 `spt_email` rows exist site-wide, all empty; email-sync has never run here |
| Exact `display_name` match to one user | 850 (48 ambiguous, 1,006 no match) |

So signal 1 is worth building and signals 2–4 are the tail. Around **1,246
customers** are reachable through order history alone — the difference between
a backfill that is not worth writing and one that resolves most of the gap.

Two honest caveats. `arl-local` is a local copy: `_spr_processed` is unset on
**all 7,266** completed orders there, which either means the pipeline never ran
over history or the flag did not come across in the copy. And signal 1's *hit
rate* still depends on `find_existing_player()` matching a billing name to a
roster title, which cannot be predicted from counts. **Confirm both on prod or
staging before Part 2 is written.**

Name matching cannot close the remainder even where it fires: `Cody Lusk`
normalises to the same string for **five** users (9, 825, 1276, 1872, 2083), so
the reporter's own record lands in the ambiguous bucket. Some links will only
ever be made by a human who knows the league — which is why the preview gate is
not optional, whatever the reach turns out to be.

#### Rejected: bulk re-running order processing

The obvious shortcut is to re-run registration over historical orders. The
per-order action already exists — "Re-run player registration", a row action on
completed orders — and re-running calls `link_user_to_player()`, so it does
write `sp_user`. Reusing it looks free.

It is not. Re-running order processing also calls `find_or_create_player()`,
which **creates player records**, and then assigns roles, writes registration
log rows, changes `post_author`, and marks the order processed. Firing that
across 5,686 never-processed orders could mint thousands of duplicate players
and hand out roles — on the very table this project exists to repair.

This is why the design calls `find_existing_player()` directly instead. It is
the same matching logic with none of the side effects: read-only, creates
nothing, and already returns a terminal conflict action rather than guessing.
The backfill writes `sp_user` and nothing else.

Recorded explicitly because the shortcut looks like free reuse of tested code,
and a future reader who reaches for it will not discover the blast radius until
after the run.

## Testing

Standalone `assert_test` suites, registered in `run-all-tests.sh`. The pure
functions carry the risk, so they are what gets tested:

| Test file | Covers |
|---|---|
| `sportspress-player-tools/tests/test-profile-picture-resolution.php` | The resolver returns the `sp_user`-linked player and *never* an authored-but-unlinked one; 0/1/many branch selection; that a player linked to a different user is not returned |
| `sportspress-player-tools/tests/test-user-link-backfill.php` | Candidate ranking (HIGH before LOW); only HIGH pre-checked; >1 candidate never pre-checked; the bulk-author threshold excludes a 294-player author; idempotence — an already-linked player yields no candidate; a name ambiguous across 5 users is not auto-linked |

The `codylusk` → Nick Prystie case becomes a named regression assertion: a user
who authored exactly one player they are not linked to must resolve to **no**
player.

**Not unit-testable**, needing real WordPress — the WooCommerce endpoint, the
admin preview screen, the apply handler. Staging checks:

- [ ] A player with `sp_user` sees their own record and its existing photo.
- [ ] Uploading writes the thumbnail to the linked player and no other post.
- [ ] A user who authored one unlinked player sees the menu item **gone** (this is
      the fix; verify against `codylusk`, and confirm player 3352 is untouched).
- [ ] Account 9 (294 authored) still sees the refusal, with the new copy.
- [ ] Backfill preview: HIGH pre-checked, LOW unchecked, ambiguous unchecked.
- [ ] Applying writes only `sp_user`, leaves `post_author` unchanged.
- [ ] Re-running the backfill immediately proposes nothing (idempotence).
- [ ] CSV export of unmatched opens and round-trips.
- [ ] After a backfill, a previously-dark account gains the menu item and resolves
      to the right player.

## What this deliberately does not do

- **No author fallback in the UI**, ever. See Part 1.
- **No `post_author` writes.** `wp spr backfill-owners` already exists for that
  and would fix the 146 disagreeing records, but it is a separate decision with
  its own capability side-effects (guarded by `spr_owner_can_edit`) and is not
  required for this fix to be correct.
- **No player-facing claim flow.** Letting a user assert their own link is a
  larger design with real impersonation risk; the backfill puts the decision
  with an administrator instead.
- **No change to the multi-player refusal**, and no picker for admins — handing
  someone who authored 294 players a UI for writing photos onto any of them is
  the bug with a nicer interface.
- **No bulk re-run of order processing**, however tempting the reuse. See
  "Rejected: bulk re-running order processing".
- **No new player↔user table.** `sp_user` is established and read by four files
  across three plugins; a second mechanism would mean two truths.

## Effort

Part 1 is small — one query, one string, one test file. **Implemented.**

Part 2 is the real work, but less of it than a fresh matcher would be: it is a
close sibling of `SPT_Email_Sync` (scan, confidence ladder, preview table, apply
handler, CSV export) and it borrows the matching from
`find_existing_player()` rather than reinventing it. The genuinely new pieces
are the order walk, promoting that matcher to a callable seam, and the preview
screen.

Sequencing matters more than size, and it has one hard ordering:

1. **Measure on prod or staging first.** Confirm the order and `_spr_processed`
   counts hold there, and sample what `find_existing_player()` actually matches
   for real billing names. `arl-local` cannot answer the second question at all.
2. **Then write Part 2**, sized to what that found.
3. **Part 1 does not reach players until Part 2 ships**, because on its own it
   hides the feature from ~90% of them.
