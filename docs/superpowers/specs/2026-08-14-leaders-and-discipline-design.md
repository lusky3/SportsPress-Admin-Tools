# Stat Leaders & Penalty Discipline — Design

**Status:** approved for planning
**Date:** 2026-08-14
**Plugin:** `sportspress-league-manager`
**Namespace:** `splm/v1`

## Goal

Give league managers a live, in-season view of who leads the league in goals,
assists, points and penalty minutes — overall and per division — and warn them
when a player's penalty minutes cross a threshold, either across the whole
season or within a rolling window of recent weeks.

## Why this isn't already covered

`GET /reports/season-summary` already computes season-wide top-10 leaders for
Points, Goals, Assists and PIM from event box scores, rendered by
`src/dashboard/pages/SeasonReport.jsx`. It is missing everything this feature is
about:

- no division breakdown — leaders are league-wide only
- no time dimension — it cannot express "the last four weeks"
- no thresholds, flags, or any notion of a player needing attention
- it lives on a page framed as an end-of-season wrap-up, not a weekly tool

What it *does* provide, and what this design reuses, is the derivation of the
`team → division` and `player → division` maps: regular-season `sp_table` posts
for the season, collapsed by their `sp_league` term, with playoff-child tables
skipped.

## Data reality

These numbers are measured from the staging copy of production (season W2025-26,
term 654, plus its playoff child 655) and drive several decisions below.

| Fact | Value |
|---|---|
| Events in the season (incl. playoff child, see below) | 380 |
| Events tagged regular-season only | 332 |
| Events with a box score | 361 |
| Events with any PIM | 254 |
| Player-rows across box scores | 10,940 |
| Players with any PIM | 300 |
| Highest season PIM total | 20 |
| Players at ≥10 / ≥15 / ≥20 PIM | 33 / 4 / 1 |
| Highest PIM in any 4-week span | 11 |

**Stat keys.** `g`, `a`, `pim`, `ga` exist as `sp_performance` (per-event box
score); `gp`, `p`, `gaatwo` exist as `sp_statistic` (computed). This feature
reads `g`, `a`, `pim` and derives points as `g + a`.

**Event dates** come from `post_date` (SportsPress stores the event datetime
there). There is no separate date meta; `sp_timeline` is unrelated. This is what
makes a rolling window possible at all.

**Source of truth is the event box score** (`sp_players` post meta), not each
player's `sp_statistics` meta. The dashboard and score-sheet writers populate the
box score, and SportsPress does not reliably recompute the per-player aggregate,
so `sp_statistics` is frequently empty even when stats exist. The existing
season-summary code carries this same warning; it is not a new finding.

**Playoff events are already being swept in silently.** `tax_query` sets
`include_children => true` by default for hierarchical taxonomies, and `sp_season`
is hierarchical with playoff terms as children (655 "W2025-26 Playoffs" is a child
of 654). Measured:

| Query | Events |
|---|---|
| Parent 654, default | 380 |
| Parent 654, `include_children => false` | 332 |
| Playoff child 655 only | 64 |

So the **existing** `/reports/season-summary` endpoint — which queries the parent
term with the default — already counts playoff games in its "season" leaders and
its scheduled/played tallies, without saying so. That is pre-existing behaviour,
not something this feature introduces, but it means "exclude playoffs" requires
explicit `include_children => false` rather than being the default.

Note `332 + 64 > 380`: 16 events carry **both** the parent and the playoff term.
The rule this design adopts is that an event is regular-season if it explicitly
carries the parent term, so those 16 count as regular season and are not
double-counted. Dual-tagged events are almost certainly tagging drift and are
worth a separate health check; this feature only needs to be deterministic about
them.

**Known limitation.** The box score stores only a summed `pim` integer per player
per game. The score-sheets plugin extracts individual penalties (length, period,
offense) but writes only the derived total to the event. Therefore this feature
cannot report "3 majors" or "2 game misconducts" for historical games — only
total minutes. Penalty *detail* would require a separate design that persists
per-penalty rows, and is out of scope.

## Architecture

### Why not SQL, and why not a derived table

`sp_players` is a **serialized PHP array in postmeta**. It cannot be aggregated
in SQL at any level of cleverness. That leaves two real options:

1. **On-demand PHP scan with a cached result.** One pass over the season's
   events, bucketed by week; the computed response is cached and invalidated on
   score writes.
2. **A materialized table** of one row per player-per-event.

This design takes **option 1**. The dataset is small (≈380 events, ≈11k rows,
sub-second with a primed meta cache), and the existing season-summary endpoint
already proves the pattern at this scale. A materialized table would be a second
copy of the truth that silently drifts the moment any write path is missed — and
there are three (dashboard score entry, score-sheet ingest, and plain WP admin
edits of an `sp_event`). Correct-but-recomputed beats fast-but-wrong for data
that drives disciplinary decisions.

### Weekly buckets

The aggregator returns, for one season:

```
player_id => array(
    'team_id'  => int,   // team the player is attributed to
    'div_id'   => int,   // division term id (sp_league)
    'weeks'    => array( 'Y-m-d' => array( 'gp' => int, 'g' => int, 'a' => int, 'pim' => int ) ),
)
```

Weekly granularity is the central choice. Because the window is expressed in
calendar weeks, "last X weeks" becomes a suffix sum over buckets and the season
total is the sum of all of them — so a single scan serves both the leaderboards
and the watch list.

The week key is the **Monday of that week as `Y-m-d`**, not an ISO year-week
number. Year-week integers look tidier but break window arithmetic across a year
boundary (`202552 + 1 != 202601`), which is exactly where a winter season sits.
Date strings compare and subtract correctly with no special cases, and keying on
the week start also keeps window boundaries stable rather than shifting daily.

### Division attribution

Primary: the roster mapping `sp_leagues[league][season] => team`, the same
season-scoped source Rosters and Division Balance use. Fallback, when a player
has no roster mapping for the season: the team they appeared for most often in
box scores. This keeps a player who changed teams mid-season on a leaderboard
instead of dropping them.

### Scope decisions

- **Playoffs are excluded from leaderboards** (with an `include_playoffs` toggle)
  but **included in the penalty watch**, unconditionally. Leaderboards describe a
  regular-season race; discipline is cumulative and a playoff major counts.
  Exclusion is implemented with an explicit `include_children => false` on the
  season tax_query — not by filtering results — because, as measured above, the
  default sweeps children in.
- **The rolling window is clamped to the selected season.** A window that would
  reach back past the season's first game stops there — a new season starts
  everyone at zero rather than carrying grudges across a rollover.

## Components

Each unit below is independently testable. The three pure ones carry the logic
worth testing; the rest are thin I/O.

| File | Responsibility |
|---|---|
| `includes/class-player-stats-aggregator.php` | `SPLM_Player_Stats_Aggregator` — scan a season's events once, return weekly buckets + attribution. Touches WP. |
| `includes/class-leaders.php` | `SPLM_Leaders` — rank aggregator output into overall and per-division boards. **Pure.** |
| `includes/class-penalty-watch.php` | `SPLM_Penalty_Watch` — evaluate threshold tiers, apply acknowledgement suppression. **Pure.** |
| `includes/class-discipline-database.php` | `SPLM_Discipline_Database` — acknowledgement table CRUD. |
| `includes/class-discipline-digest.php` | `SPLM_Discipline_Digest` — cron registration + digest email. |
| `includes/class-leaders-rest.php` | `SPLM_Leaders_REST` — the three endpoints. |

`class-rest-api.php` is **5,010 lines**. The new endpoints go in their own
controller rather than growing it further; `sportspress-score-sheets` already
sets this precedent with `class-dashboard-rest.php`. This is the one targeted
structural improvement in scope — no unrelated refactoring of the monolith.

## Thresholds

A **tier list**, not two loose numbers:

```php
array(
    'key'         => 'season-warn',
    'scope'       => 'season',   // 'season' | 'window'
    'minutes'     => 12,
    'severity'    => 'warning',  // 'warning' | 'critical'
    'consequence' => null,       // reserved; unused in this version
)
```

`SPLM_Penalty_Watch` evaluates every tier against a player's totals and returns
the highest-severity match per scope. Encoding a real suspension rule later means
adding tiers with a populated `consequence` and a handler for it — not a
rewrite. Nothing in this version claims or implies a consequence.

### Defaults

Chosen from the measured distribution above so the list is useful on day one
rather than silent or deafening:

| Tier | Scope | Minutes | Severity | Would have flagged in W2025-26 |
|---|---|---|---|---|
| `season-warn` | season | 12 | warning | 16 players (5.3%) |
| `season-critical` | season | 18 | critical | 3 players (1.0%) |
| `window-critical` | window (4 weeks) | 8 | critical | 2 players |

A 4-week *warning* tier was considered and rejected: at 6 minutes it flags 30
players, which is noise. The window gets a single critical tier.

Settings store the tier list plus `window_weeks` (default 4). The settings screen
renders a live **"these thresholds would have flagged N players last season"**
preview as the numbers are edited, so tuning is empirical rather than blind.

## Acknowledgement

New table `{prefix}splm_discipline_ack`, following the schema and `dbDelta`
pattern of the existing `class-player-notes-database.php` (including its
`create_table()` returning a verified `table_exists()` rather than trusting
`dbDelta`'s return):

```
id            bigint unsigned auto_increment
player_id     bigint unsigned      KEY
season_id     bigint unsigned      KEY
tier_key      varchar(50)
value_at_ack  int                  -- PIM total when acknowledged
status        varchar(20)          -- 'reviewed' | 'suspension_served' | 'dismissed'
note          text
author_id     bigint unsigned
created_at    datetime
UNIQUE KEY (player_id, season_id, tier_key)
```

`value_at_ack` is the load-bearing column. An acknowledged player re-alerts only
once their total climbs **above** the recorded value or they reach a higher tier.
Without it the same handful of players alert every week forever and the feature
gets ignored — which is the normal failure mode for this kind of list.

## REST surface

Conforms to `docs/rest-api-conventions.md`.

| Endpoint | Method | Shape | Capability |
|---|---|---|---|
| `/leaders` | GET | aggregate object (a report, not a list) | `SPLM_Capabilities::can_read()` |
| `/discipline/watch` | GET | list — `{data, total, page, total_pages}` | `SPLM_Capabilities::can_manage()` |
| `/discipline/acknowledge` | POST | `{success: true, ...}` | `SPLM_Capabilities::can_manage()` |

`GET /leaders` params: `season` (required), `division` (optional term id, omit
for all), `limit` (default from `splm_report_leader_count`), `include_playoffs`
(bool, default false), `window_weeks` (optional — when present, boards are
computed over the window instead of the full season).

Response:

```json
{
  "season":   { "id": 654, "name": "W2025-26" },
  "scope":    { "window_weeks": null, "include_playoffs": false },
  "overall":  { "p": [ { "player_id": 1, "player": "...", "team": "...", "division": "...", "value": 31, "gp": 18 } ], "g": [], "a": [], "pim": [] },
  "divisions": [ { "id": 12, "name": "Division 1", "leaders": { "p": [], "g": [], "a": [], "pim": [] } } ]
}
```

Errors use `WP_Error`: `403` on capability denial, `404` for an unknown season,
`503` when the required module is disabled.

Client functions land in `src/dashboard/lib/api.js` alongside the existing ones.
Per the conventions doc, `api.js` unwraps `.data` for the list endpoint; no
defensive shape shims on either side.

## Caching

The computed response is cached in a transient keyed by
`season | division | limit | window_weeks | include_playoffs | tier-config hash`,
TTL 15 minutes.

Invalidated on the known write paths — the dashboard's game-players save, the
score-sheet writer's event write, and `save_post_sp_event`. The TTL is a
deliberate backstop: if a write path is ever missed, stale discipline data
expires on its own instead of persisting indefinitely.

Caching the *aggregator output* instead — one entry per season, serving every
division and window from a single scan — was considered and rejected on size:
roughly 600 players × ~18 played weeks × 4 ints serializes to about a megabyte,
which is at or past the point where object-cache backends start silently
refusing to store it. Caching the finished response keeps entries in the
kilobytes. The cost is one scan per distinct parameter combination per TTL;
with ~5–7 divisions and a sub-second scan, that is acceptable.

## Digest email

A weekly WP-Cron event (`splm_discipline_digest`) wrapped in
`SPAT_Lock::with( $key, $ttl, $callback )` so a double-fired cron cannot
double-send. It sends **only** when at least one flag is unacknowledged;
a quiet week produces no mail.

Settings: enable/disable, recipient list (default: site admin email), and day of
week. Disabling the `league_discipline` module unschedules the event.

## Module gating & capabilities

- Leaderboards ride the existing `league_manager_dashboard` module.
- Discipline tracking gets a **new module, `league_discipline`**, registered via
  `SPAT_Plugin_Manager::register_plugin()` alongside the existing four. Enabling
  it starts emailing people, so it must be a deliberate act rather than something
  that switches on with the dashboard.
- Server-side enforcement uses the existing `SPLM_REST_API::module_enabled()`
  helper; the frontend hides the nav item and card via the `modules` config the
  Layout already consumes.
- Leaderboards are visible to any dashboard reader. The watch list and
  acknowledgement are gated on `can_manage()` — it is disciplinary data about
  named individuals, not a public scoreboard.

## UI

**`src/dashboard/pages/Leaders.jsx`** — new nav item "Leaders". A division
switcher (All + one entry per division), boards for Points / Goals / Assists /
PIM, a season-vs-window toggle, and an `include_playoffs` checkbox. Below the
boards, for managers only, the penalty watch list: player, team, division, season
PIM, window PIM, tier hit, and an Acknowledge action with an optional note.

**`src/dashboard/components/PenaltyWatchCard.jsx`** — compact card on the
Dashboard home showing unacknowledged critical flags with a count and a link
through to the Leaders page. Registered in the existing `CARDS` visibility list
so it can be hidden like every other card.

Wiring: `App.jsx` `PAGES` map, `Layout.jsx` nav array with module gating.

## Out of scope

- **Goalie leaders** (GAA / goals-against) — needs inverted sort and
  minimum-games handling, and was not requested.
- **Per-penalty detail** (majors, misconducts, offense breakdown) — the data does
  not exist on events; see Data reality.
- **Automatic suspensions** — the tier structure is ready for it, but no
  consequence is computed or asserted in this version.
- Any refactor of `class-rest-api.php` beyond not adding to it.

## Testing

Standalone harness (`assert_test`, echo-based, exit code drives pass/fail),
registered in `run-all-tests.sh` next to the existing league-manager tests.

| Test file | Covers |
|---|---|
| `tests/test-player-stats-aggregator.php` | week bucketing from event dates, window suffix sums, season clamping, division attribution incl. the mid-season-move fallback, and playoff inclusion/exclusion incl. the dual-tagged-event rule |
| `tests/test-leaders.php` | ranking and tie handling, `p = g + a`, limit slicing, per-division grouping, zero-value exclusion |
| `tests/test-penalty-watch.php` | tier evaluation per scope, highest-severity-wins, acknowledgement suppression, re-alert when the total climbs past `value_at_ack`, re-alert on reaching a higher tier |

The three pure classes take plain arrays in and return plain arrays out, so these
run with no WordPress bootstrap. The aggregator's WP-touching scan is verified
against staging rather than mocked.

## Phasing

Three independently shippable phases; cut from the back if scope needs trimming.

1. **Leaders** — aggregator, `SPLM_Leaders`, `GET /leaders`, `Leaders.jsx`, nav.
2. **Watch** — tiers, `SPLM_Penalty_Watch`, ack table, watch + acknowledge
   endpoints, watch list UI, Dashboard card, `league_discipline` module.
3. **Digest** — cron, email, digest settings.
