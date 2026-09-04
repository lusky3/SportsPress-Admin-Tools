# Per-Player Discipline History — Design

**Status:** approved design, not yet planned or implemented
**Date:** 2026-09-04
**Author:** Cody (lusky3), with Claude
**Depends on:** [`2026-09-03-discipline-consequences-design.md`](2026-09-03-discipline-consequences-design.md) — the `splm_discipline_notice` table it reads is created there (PR #111)
**Plugin:** `sportspress-league-manager`

## Goal

Let a convener open one player and see every disciplinary notice that player has ever received — across seasons — with enough detail to answer "how many suspensions, and what for" without opening a database client.

## The problem

There is no suspension history today. What looks like one isn't:

**`splm_discipline_ack`** is a *suppression* marker, not a record. It carries `UNIQUE (player_id, season_id, tier_key)` and upserts, so it holds at most one row per player per season per tier and a second acknowledgement overwrites the first. It has a `status` column accepting `suspension_served` and a `note` column — and both are dead in practice: `Leaders.jsx:63` calls `acknowledgePenalty({ player, season, tierKey })` with no status and no note, so every row is `status = 'reviewed'`, `note = ''`. `suspension_served` is an API value no UI can send. The only reader anywhere is `acks_for_season()`, used to decide who to hide from the digest.

**`splm_player_notes`** is where this actually lives today — free-text, per player, surfaced in the Rosters modal. But its category is a plain text input (`Category (optional)`, maxlength 50), so "discipline", "Discipline" and "disc" are three different categories, and nothing counts or aggregates anything.

So a convener with a player in front of them is relying on memory, or on whatever someone typed into a notes box.

## Why this belongs in `sportspress-league-manager`

Surveyed against the alternative (`sportspress-player-tools`, the nominally player-centric plugin):

- **League-manager already owns every source table** — `SPLM_Discipline_Notice_Database`, `SPLM_Discipline_Database`, `SPLM_Player_Notes_Database`. No new cross-plugin coupling.
- **The UI precedent already exists here.** `Rosters.jsx`'s `NotesPanel` is a modal keyed on one `player.id`, opened from a per-row button, rendering that player's whole history. A discipline panel is a sibling component, not a new information architecture.
- **The index is already right.** `KEY player_season_tier (player_id, season_id, tier_key)` makes a player-only, cross-season query an efficient index hit.
- **The module gate already exists.** `league_discipline` is registered independently, so the history view's availability follows the module that produces its data.
- **`player-tools` has no aggregating per-player view at all** — five single-field meta boxes and bulk CSV tools. Building this there would be a first for that plugin *and* would require it to read three of league-manager's tables, a direction it has no precedent for (it currently only *writes* to `splm_player_notes`, via a raw `$wpdb->insert` in `log_transfer_note()`).

**The argument against, recorded rather than dismissed:** if the long-term intent is one consolidated player-profile screen — skill, email, notes, discipline in a single place — `player-tools` is its natural home, and cross-plugin table access is already established here. But no such screen exists in either plugin today, so building toward it now is designing for a hypothetical. Revisit if that screen is ever specced.

## What a convener sees

A **Discipline** button in the Rosters row-actions cell, beside the existing Notes button, opening a modal titled `Discipline — {player name}`.

The modal lists every notice for that player, newest first, grouped by season with the season name as a subheading. Each row shows:

| Column | Source | Notes |
|---|---|---|
| Date | `created_at` | Rendered local; parsed as explicit UTC |
| Consequence | `consequence`, `games` | "Warning" or "Suspension — 2 games" |
| Threshold | `tier_key`, `scope` | e.g. "season-critical (season)" |
| Penalties | `value_at_fire`, `season_at_fire`, `scope` | Labelled by scope, as the queue does: "9 PIM in the recent window" vs "18 PIM this season" |
| Outcome | `status`, `served_at` | Plain language, not the stored vocabulary |
| Team | `team`, `division` | The snapshot taken at fire time, not the player's team now |

Above the list, a one-line summary: **"3 suspensions (4 games), 2 warnings, across 2 seasons."**

Rows with status `baseline` are **excluded by default** with a "show recorded-only rows" toggle. A baseline means nothing was issued — it exists so the system doesn't retroactively notify — and including it by default would inflate every player's apparent record. The toggle exists because a convener investigating "why was this player never warned?" needs to see them.

## Status vocabulary, in words

The stored statuses are internal. The panel renders:

| Stored | Shown |
|---|---|
| `baseline` | On record (nothing issued) |
| `pending` | Waiting for release |
| `sent` | Notified |
| `failed` | Could not send |
| `discarded` | Not issued — convener declined |
| `served` | Served |

An unmapped status falls back to the raw string rather than rendering blank.

## Data access

One new method on the existing gateway:

```php
/**
 * Every notice for one player, newest first, across all seasons.
 *
 * Deliberately not season-scoped: the question this answers is "what is this
 * player's record", which does not stop at a season boundary. The existing
 * KEY (player_id, season_id, tier_key) serves a player_id-only lookup as a
 * left-prefix index hit.
 *
 * @param int  $player_id       Player post id.
 * @param bool $include_baseline Whether to include rows that issued nothing.
 * @return array Row objects.
 */
public static function for_player( int $player_id, bool $include_baseline = false ): array
```

Capped at 200 rows, ordered `id DESC`. A player with more than 200 disciplinary notices is a data problem, not a pagination problem, and the cap keeps one bad row from hanging a modal.

**No new table.** This is a read of data the notices feature already writes.

## REST

One route, following `docs/rest-api-conventions.md`:

| Endpoint | Method | Shape | Capability |
|---|---|---|---|
| `/discipline/history` | GET | list — `{data, total, page, total_pages}` | `SPLM_Capabilities::can_manage()`, module-gated |

Args: `player` (required, `absint`), `include_baseline` (optional bool). Both declare **`validate_callback` as well as `sanitize_callback`** — WordPress only falls back to `rest_validate_request_arg` when no sanitiser is declared, so an arg with a sanitiser and no validator is silently unenforced.

The permission callback matches `SPLM_Discipline_Notice_REST::can_manage()` exactly: 503 when `league_discipline` is off, 403 when the capability is missing.

It reuses `SPLM_Discipline_Notice_REST::row_to_response()` rather than shaping rows a second way, so the history panel and the queue cannot disagree about what a row says.

## Surfaces

**Rosters modal** — the primary surface, matching `NotesPanel`'s wiring exactly: one `useState` for the open player, one button in the actions cell, one conditional render. Follows the page's existing conventions: the `cancelled` guard in `load()`, UTC timestamps parsed by appending `Z`, and a `splm-alert` for errors.

**`sp_player` meta box** — a second surface for the WP-admin player edit screen, beside the existing Player Notes meta box, read-only. A convener editing a player record should not have to leave it to see this. Registered on `add_meta_boxes_sp_player`, gated on `league_discipline` and `can_manage()`.

Both render the same data through the same REST route; the meta box reads the database directly only if a page-load without JS is required, matching how the notice queue's admin tab already works.

## What this deliberately does not do

- **No backfill.** The notice table only records from the moment the notices feature runs. Suspensions served before that live in people's heads and in free-text notes, and nothing here invents them. **This is the single most important thing to tell conveners** — the history starts empty and fills forward, so the sooner notices are switched on (even in `queued` mode, which sends nothing) the sooner the record begins.
- **No editing.** The panel is read-only. A notice's status changes through the queue's release/discard/serve routes, which already carry the locking and capability checks. A second write path would be a second place to get authorization wrong.
- **No merge with player notes.** They stay separate lists. Notes are free-text about anything; this is structured discipline. Interleaving them would make both harder to scan.
- **No cross-player reporting** — "every suspension this season" is the existing queue's job, filtered by status.
- **Nothing that revives `splm_discipline_ack`'s dead `suspension_served` status.** That column is a suppression marker; the notice table is the record. Recorded here so a future reader does not mistake the dead status for a second source of truth and try to reconcile them.

## Testing

Standalone `assert_test` suites, registered in `run-all-tests.sh`:

| Test file | Covers |
|---|---|
| `tests/test-discipline-history.php` | `for_player()` returning rows newest-first across seasons; the baseline include/exclude switch; the 200-row cap; an unknown player returning an empty array rather than every row |
| extend `tests/test-discipline-notice-predicate.php` | that `for_player()` and `latest_for()` cannot disagree about which row is newest |

The summary line's arithmetic ("3 suspensions (4 games), 2 warnings") is pure and should be a function taking rows and returning the sentence, tested directly — a miscount here is the panel's most visible possible error.

**Not unit-tested**, needing real WordPress: the REST route, the meta box, and the React panel. Staging checks:

- [ ] A player with no notices shows an empty state, not an error.
- [ ] A player with notices in two seasons shows both, newest first, grouped.
- [ ] Baseline rows are hidden by default and appear with the toggle.
- [ ] The summary count matches the rows shown.
- [ ] The team/division shown is the snapshot from when the notice fired, not the player's current team — verify by moving a player after a notice exists.
- [ ] Disabling the `league_discipline` module makes the button disappear and the route 503.
- [ ] A non-manager cannot reach the route (403) and does not see the button.
- [ ] The meta box renders on the player edit screen and matches the modal.

## Effort

Small, because the data and every pattern already exist: one gateway method, one REST route, one React component modelled on `NotesPanel`, one meta box modelled on the notes meta box, and two test files. The bulk of the work is the summary-line arithmetic and the staging pass.
