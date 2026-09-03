# Discipline Consequences — Design

**Status:** approved design, not yet planned or implemented
**Date:** 2026-09-03
**Author:** Cody (lusky3), with Claude
**Builds on:** [`2026-08-14-leaders-and-discipline-design.md`](2026-08-14-leaders-and-discipline-design.md)

## Goal

Turn the existing penalty-minute watch list from a convener-facing alert into a
system that notifies the *player* when they cross a threshold — a warning at the
lower tiers, a suspension at the upper ones — with per-league control over
whether that mail sends automatically, waits for a human to release it, or is
switched off entirely.

## What exists today

The `league_discipline` module already accumulates penalty minutes and flags
players. Specifically:

- `SPLM_Player_Stats_Aggregator` buckets PIM per player per week and sums a
  rolling window whose length is `splm_discipline_window_weeks` (default 4,
  settable 1–52), clamped to season start.
- `SPLM_Penalty_Watch::evaluate()` compares season and window totals against a
  tier list and returns flags, highest severity per scope.
- `splm_discipline_ack` records convener acknowledgements. `value_at_ack` keeps
  an acknowledged player quiet until their total climbs past the recorded value.
- `SPLM_Discipline_Digest` mails a weekly table of flagged players to
  `splm_discipline_digest_recipients`, off by default.

Seeded tiers, calibrated against the observed W2025-26 distribution over 300
players with any PIM:

| Tier | Scope | Minutes | Severity | Flagged in W2025-26 |
|---|---|---|---|---|
| `season-warn` | season | 12 | warning | 16 players (5.3%) |
| `season-critical` | season | 18 | critical | 3 players |
| `window-critical` | window (4 weeks) | 8 | critical | 2 players |

There is deliberately no window *warning* tier: at 6 minutes in 4 weeks it
flagged 30 players, which is noise.

## What is missing

Every tier carries a `consequence` field that is permanently null. The prior
spec listed automatic suspensions as out of scope with the structure left ready.
That structure is **sealed, not merely empty**: `SPLM_Penalty_Watch::sanitize_tiers()`
hard-codes `'consequence' => null` on every tier it emits, and it is wired as the
`register_setting()` sanitiser — so the settings screen physically cannot persist
a consequence today.

No mail has ever been addressed to a player about discipline. The only
player-directed mail in the plugin is the waitlist offer notification.

## Global constraints

- **Both delivery modes default to `disabled`.** Upgrading must never begin
  mailing players. Same opt-in discipline the digest already has.
- **Notices are never created by a read path.** Only the scheduled evaluation
  pass may write them. A convener opening the Leaders page must not mail anyone.
- **One authorization path.** Both UI surfaces act through the same REST routes;
  no second server-side action handler.
- **Notice state never binds to an event ID.** A suspension is *N games owed*,
  never "the game on the 8th".
- WordPress 6.4+, PHP 8.1+. All new DB access through `$wpdb->prepare()`.
- All timestamps stored UTC via `gmdate()`; rendered with `wp_date()`.
- Text domain `sportspress-league-manager`.

## Tier consequences

`consequence` stops being null and gains a companion field:

```php
array(
    'key'         => 'season-critical',
    'scope'       => 'season',
    'minutes'     => 18,
    'severity'    => 'critical',   // presentational: digest ordering and colour
    'consequence' => 'suspend',    // actionable: 'none' | 'warn' | 'suspend'
    'games'       => 1,            // meaningful only when consequence === 'suspend'
)
```

Seeded consequences:

| Tier | consequence | games |
|---|---|---|
| `season-warn` | `warn` | 0 |
| `season-critical` | `suspend` | 1 |
| `window-critical` | `suspend` | 1 |

`severity` and `consequence` stay separate axes. `severity` continues to drive
digest ordering and the watch list's presentation; `consequence` drives notices.
A league may legitimately configure a critical flag that carries no consequence,
which is why collapsing the two would be wrong.

### Unsealing the sanitiser

`sanitize_tiers()` must stop hard-coding null and instead validate:

- `consequence` ∈ `{ none, warn, suspend }`, defaulting to `none` when absent or
  unrecognised.
- `games` cast via `absint()`, clamped to 0–10, and forced to 0 unless
  `consequence === 'suspend'`.
- A tier with `consequence === 'suspend'` and `games === 0` is coerced to
  `games = 1` rather than rejected — a suspension of zero games is a
  configuration mistake, and silently dropping the tier would be worse than
  correcting it.

It remains untyped (`mixed $raw`) for the reason already documented: `options.php`
hands the callback null when the field is absent from the POST, and a hard array
type hint would fatal on save.

The settings screen's tier table gains a consequence select and a games number
input per row, alongside the existing minutes input and the live "would flag N
players" preview.

## Delivery modes

Two options, each `automatic | queued | disabled`:

| Option | Default |
|---|---|
| `splm_discipline_notice_mode_warning` | `disabled` |
| `splm_discipline_notice_mode_suspension` | `disabled` |

All three values are selectable and fully functional on delivery; none is a
stub, and none is deferred to a later phase. See Phasing.

Behaviour by mode:

- **`disabled`** — no notice rows are written at all. Discipline behaves exactly
  as it does today: flags, acks, weekly digest.
- **`queued`** — a crossing writes a `pending` row. Nothing sends until a human
  releases it.
- **`automatic`** — a crossing writes a row and attempts the send in the same
  pass, landing on `sent` or `failed`.

Both are registered in the `splm_backend_settings` group with a
`sanitize_callback` that whitelists the three values and falls back to
`disabled`. Both **must** be rendered as fields on the League Manager tab:
`options.php` writes null over every option registered in a submitted group that
is absent from the POST, so a registered-but-unrendered option is wiped on every
save of that tab. This is already a documented hazard in `class-admin.php` for
the three digest options.

## The notice object

A flag is computed on demand and forgotten. A notice cannot be — "have we
already told this player?" must survive a page load. One new table:

```
{prefix}splm_discipline_notice

id             bigint unsigned auto_increment PRIMARY KEY
player_id      bigint unsigned NOT NULL          KEY
season_id      bigint unsigned NOT NULL          KEY
tier_key       varchar(50)     NOT NULL          -- the suppression key
ack_key        varchar(80)     NOT NULL          -- tier_key, or tier_key@window_start; for the digest ack only
scope          varchar(20)     NOT NULL          -- 'season' | 'window'
severity       varchar(20)     NOT NULL
consequence    varchar(20)     NOT NULL          -- 'warn' | 'suspend'
games          smallint unsigned NOT NULL DEFAULT 0
value_at_fire  int             NOT NULL          -- the figure that crossed the threshold; display
season_at_fire int             NOT NULL          -- the season total at the time; the predicate compares this
team           varchar(200)    NOT NULL DEFAULT ''  -- snapshot at fire time
division       varchar(200)    NOT NULL DEFAULT ''  -- snapshot at fire time
status         varchar(20)     NOT NULL DEFAULT 'pending'
recipient      varchar(200)    NOT NULL DEFAULT ''  -- address actually used
recipient_via  varchar(20)     NOT NULL DEFAULT ''  -- 'spt_email' | 'sp_user'
bcc            text                                  -- addresses actually copied
sent_at        datetime            NULL
served_at      datetime            NULL
released_by    bigint unsigned NOT NULL DEFAULT 0    -- 0 for an automatic send
last_error     varchar(255)    NOT NULL DEFAULT ''
note           text
created_at     datetime        NOT NULL
KEY player_season_tier (player_id, season_id, tier_key)
KEY season_status (season_id, status)
```

`team` and `division` are snapshots rather than resolved on read: cheaper, and
more accurate, since they record who the player was playing for when the
minutes were earned rather than who they play for now.

Statuses: `baseline | pending | sent | failed | discarded | served`.

`ack_key` is produced by the existing `SPLM_Penalty_Watch::ack_key()`, unchanged.
Window notices therefore inherit the window-scoping fix already in place, rather
than re-deriving it: a bare tier key would compare this window's total against a
total earned in a disjoint window.

Created following `class-player-notes-database.php`'s `dbDelta` pattern,
including its `create_table()` returning a verified `table_exists()` rather than
trusting `dbDelta`'s return value.

The key is deliberately **not** unique. A player may legitimately receive
`season-critical` twice in a season — once at 18 minutes, again at 25 — and both
rows are history worth keeping. Duplicate protection comes from the re-fire
predicate plus the pass lock, described next.

### One predicate governs re-firing

For a given `(player_id, season_id, tier_key)`, take the row with the highest
`id`. Suppress the crossing unless the player's **season total** now exceeds
that row's `season_at_fire`.

The comparison is the season total, never the value that matched the tier, and
that is the whole correctness argument. A season total only ever grows; a
rolling window total falls as weeks roll past. Comparing the matched value would
re-fire a window tier every week the minutes stay inside the window — one
eight-minute incident becoming four suspension emails — and scoping suppression
to the window instead would mute a genuine later offence that happened to reach
the same window figure. A monotonic comparison has neither failure.

The row therefore stores two figures: `value_at_fire`, the number that crossed
the threshold and the one shown to humans, and `season_at_fire`, the number the
predicate compares.

Suppression is keyed on `tier_key`, **not** on `ack_key`. `ack_key` embeds the
rolling window's start date, which advances weekly, so an `ack_key` lookup finds
no prior row each week — the same re-fire bug by a different route. The
`ack_key` is still stored, because the digest's acknowledgement write needs it.

Every status participates identically. That single rule delivers:

- A `sent` notice does not re-send while the player earns nothing more.
- A `baseline` row suppresses a player who was already over at switch-on.
- A `served` suspension re-fires once the player earns more, with no special case.
- A `discarded` notice does not come back — a convener's decision to discard
  sticks until the player earns more.
- A `failed` send does not duplicate: it stays visible in the queue and is
  retried through the release route.

`pending` is the one status that suppresses even when the total *has* grown. An
unreleased notice is a draft, so a rising total revises it in place rather than
stacking a second row — three pending rows for one escalation would mail three
suspensions when a convener released them.

## Baselining

Three events write `baseline` rows for every player currently over a tier,
recording their present total in `value_at_fire` and mailing nobody:

1. **First evaluation after install.**
2. **A mode transitioning out of `disabled`.** Turning notices on mid-season must
   not mail the 16 players already over `season-warn`. Consequently, minutes
   accumulated during a disabled period never generate a notice — which is
   correct: the league chose not to notify then.
3. **A tier's `minutes` being edited.** This is the retroactivity guard.
   Dropping `season-critical` from 18 to 10 re-baselines the tier, so nobody
   currently over is mailed; only minutes earned after the edit can trigger.
   Hooked on the existing `update_option_splm_discipline_tiers` action, which
   already fires `SPLM_Leaders_REST::flush_cache`.

Baselines live in the notice table rather than in `splm_discipline_ack`. Reusing
the ack table is tempting — `value_at_ack` already means "suppress until they
climb past this" — but ack rows also suppress the **digest** flag, so baselining
there would silence the weekly convener digest for every flagged player at
switch-on. The digest must keep listing flagged players; only notices are
baselined.

## Recipients

The player goes in `To:`. Everyone else goes in `Bcc:`, so the player never sees
the board's addresses and the board sees the player's copy verbatim.

### Resolving the player's address

Following the chain `class-privacy.php` already establishes for players:

1. `spt_email` post meta on the `sp_player` post → record `recipient_via = 'spt_email'`.
2. Otherwise `sp_user` post meta → that user's `user_email` → record
   `recipient_via = 'sp_user'`.
3. Otherwise no send. The row lands on `failed` with
   `last_error = 'no email on file'` and surfaces in both queues for a human to
   fix. In `queued` mode this is caught before anyone tries to release it,
   because the address is resolved at evaluation time and stored.

### Bcc list

- **The player's team captain**, resolved `sp_team` → `sp_list` post meta →
  `spt_captain` meta → captain player id → that player's address by the same
  two-step chain above.

  Two constraints. The captain mechanism lives in the **player-tools sibling
  plugin**, so resolution must degrade silently to no captain when that plugin is
  inactive, when the team has no `sp_list`, or when the list has no `spt_captain`.
  And `sp_list` is **not season-scoped** — a team has one active list — so the
  captain of record may be wrong for a historical season. Captain Bcc is
  therefore added **only when the notice's `season_id` equals
  `splm_default_season`**, the season the evaluation pass runs against.

- **`splm_discipline_digest_recipients`** — the existing board list, so the board
  has a record of every notice actually sent.

- **`splm_discipline_notice_cc`** — a new global comma-separated list, sanitised
  with `sanitize_text_field` and filtered through `is_email` per entry, matching
  how `SPLM_Discipline_Digest::recipients()` already parses its list.

Unlike the digest, an empty Bcc list does **not** fall back to `admin_email`. A
notice's purpose is served by reaching the player; silently copying the site
admin on a player's disciplinary mail is a privacy surprise.

The addresses actually used are stored on the row in `bcc`, so the technical view
can show what really happened rather than recomputing what would happen now.

## Notice content

Plain text, matching the waitlist offer email's register.

**Warning** — names the next threshold, which is what makes it a warning rather
than a notification:

> Hi Alex,
>
> You have accumulated 12 penalty minutes in W2025-26.
>
> This is a warning. At 18 penalty minutes you will be suspended.

**Suspension** — names the resolved game but makes the obligation the next
scheduled game, which is what keeps state decoupled from the schedule:

> Hi Alex,
>
> You have accumulated 18 penalty minutes in W2025-26.
>
> You are suspended for 1 game, to be served Sat Nov 8 vs Rangers.\*
>
> \*or your next scheduled game.

The date is resolved at render time from the player's attributed team's next
`future`-or-`publish` `sp_event` after today, using the same query shape as the
existing upcoming-games endpoint in `class-rest-api.php`. It is advisory and
never stored. When nothing resolves — no team, no scheduled game, season over —
the sentence degrades to "to be served at your next scheduled game." and the
asterisked footnote is dropped, so the mail never carries a dangling reference.

Player and season names are escaped for the context they render in. Bodies are
built by a pure function taking a row plus a resolved game string, so content is
testable without WordPress.

## Evaluation pass

A new cron event, `splm_discipline_notices`, daily. Not weekly: a notice should
follow a game promptly rather than up to seven days later.

Scheduled in the site's timezone, following the digest's documented workaround —
WordPress forces PHP's timezone to UTC, so a bare `strtotime()` would schedule
08:00 UTC, which is 03:00 or 04:00 local for this league.

Wrapped in `SPAT_Lock` on the digest's precedent, and for the same reason stated
there: WP-Cron can fire the same event twice when two requests race the
scheduler, and a duplicated suspension email is not a harmless duplicate. As in
the digest, a parent too old to ship `SPAT_Lock` causes the pass to do nothing
rather than risk a double send.

The pass:

1. Bail unless the `league_discipline` module is enabled and at least one mode is
   not `disabled`.
2. Resolve the season from `splm_default_season`; bail if unset.
3. Compute `watch_context()` — the same tiers, acks, window cutoff and totals the
   watch list and digest already use, so the three can never disagree.
4. For each player, collect every matched tier whose `consequence` is `warn` or
   `suspend`, and apply the re-fire predicate to each.
5. Choose **at most one notice per player per pass** (see below).
6. Write the row per the mode governing that consequence, sending immediately
   under `automatic`.

### The pass needs all matches, not the collapsed flags

`SPLM_Penalty_Watch::evaluate()` returns one flag per scope — it builds an
internal `$matched[$scope][]` and then collapses it, keeping the
highest-*severity* entry. That is right for the digest and the watch list and
wrong for notices, because `severity` and `consequence` are independent axes: the
highest-severity match in a scope is not necessarily the one carrying a
consequence.

So add `SPLM_Penalty_Watch::matches()`, returning the pre-collapse structure —
every matched tier, grouped by scope. `evaluate()` stays exactly as it is, so the
digest and watch list are untouched, and `matches()` becomes the shared core both
call.

### Notices ignore convener acknowledgements

`matches()` is called from the pass with an **empty** ack array.

This matters more than it looks. An acknowledgement means "a convener has
reviewed this flag" and it suppresses the digest. If it also suppressed notices,
then a convener acknowledging a flag — the exact thing the digest email tells
them to go and do — would silently cancel the player's notification. Notice
suppression is governed solely by the notice table's own predicate; the two
mechanisms stay independent, in the same way and for the same reason that
baselines do not live in the ack table.

### One notice per player per pass

Two scopes can both match a `suspend` tier in the same pass — a player crossing
`season-critical` and `window-critical` together. Sending both would mail the
player twice and imply two suspensions for one set of minutes.

Rank the surviving matches by `consequence` (`suspend` > `warn`), then by `games`
descending, then by `minutes` descending, and take the first. That one becomes
the notice, carrying its own tier's `tier_key` and `ack_key`.

Every *other* surviving match gets a `baseline` row at its current value in the
same pass. This is what stops the runner-up firing its own notice on the next
pass at an unchanged total, while still letting it fire later if the player earns
more.

`wp cron event run` is a routine operation on this league's staging box, so the
callback must tolerate being invoked with no arguments and must be idempotent
within a single day — both covered by the lock and the predicate.

## REST surface

Conforms to `docs/rest-api-conventions.md`. All four require
`SPLM_Capabilities::can_manage()`.

| Endpoint | Method | Purpose |
|---|---|---|
| `/discipline/notices` | GET | list — `{data, total, page, total_pages}`; filters `status`, `season` |
| `/discipline/notices/{id}/release` | POST | send a `pending`, or retry a `failed` |
| `/discipline/notices/{id}/discard` | POST | never send; records actor and optional note |
| `/discipline/notices/{id}/serve` | POST | mark a suspension served |

Every route declares `validate_callback` and `sanitize_callback` on its args. Note
that WordPress attaches `rest_validate_request_arg` as a sanitise fallback **only
when no `sanitize_callback` is declared** — declaring one replaces it — so
validation must be stated explicitly rather than assumed from an `enum`.

`release` is `SPAT_Lock`-guarded per notice id and re-reads the row inside the
lock, refusing anything not `pending` or `failed`. A double-click must not double
send. `serve` refuses a notice whose `consequence` is not `suspend`.

Releasing sets `released_by` to the acting user. An automatic send leaves it 0,
which is how the technical view distinguishes the two.

### Interaction with the digest

A `sent` notice writes a companion row into `splm_discipline_ack` with status
`notice_sent` and `value_at_ack` set to `value_at_fire`, so the weekly digest
stops nagging about a player who has already been told. This reuses the existing
suppression machinery rather than adding a second one, and requires adding
`notice_sent` to the allowed-status list in
`SPLM_Discipline_Database::record()`, which currently whitelists
`reviewed | suspension_served | dismissed`.

## Surface A — WP admin, technical

A second League Manager tab on the SPAT settings page, registered through the
existing `spat_admin_page_tabs` / `spat_admin_page_content` actions that
league-manager already hooks. It is a **separate tab** from League Manager's
settings, not a section inside them: that panel is a single
`<form action="options.php">`, and nesting an actionable queue inside it would
produce invalid HTML and post the queue's buttons to `options.php`.

Server-rendered `<table class="widefat">`, following the repo's existing
hand-rolled table pattern. No `WP_List_Table` — there is no precedent for it
anywhere in the repo, and it is a private core class.

Shows everything: notice id, player id and name, season id, `tier_key`,
`ack_key`, consequence and games, `value_at_fire`, status, resolved `recipient`
with its `recipient_via`, the `bcc` actually used, `sent_at`, `released_by`,
`last_error`, `created_at`. Filterable by status. Plus administrator-grade
diagnostics: the next scheduled run of `splm_discipline_notices`, the current
mode of each delivery setting, and a row count by status.

Actions call the REST routes from a small vanilla-JS layer using a
`wp_create_nonce( 'wp_rest' )` nonce — no `admin_post_*` handler, so release
logic exists once. Bulk release and bulk discard operate by issuing the
per-notice calls, which keeps the server contract single-item and idempotent.

Capability: the page is inside a `manage_options` settings screen, and every
action re-checks `can_manage()` server-side at the REST boundary.

## Surface B — React dashboard, simplified

A new `Notices.jsx` page, gated on the `league_discipline` module in the
`PAGES` map, the `Layout.jsx` nav array, and the modules map in
`class-dashboard-frontend.php`.

Conveners see what they need to make a decision and nothing else: player name,
team, division, penalty minutes and which window they were earned in, what
happens (a warning, or a suspension of N games), and the buttons —
**Release**, **Discard**, **Mark served**. No ids, no `ack_key`, no address
resolution internals. A row whose address failed to resolve says so in words —
"no email on file for this player" — rather than surfacing `recipient_via`.

Timestamps parse as explicit UTC (append `Z`) before formatting, per the fix
already applied in the waitlist page.

### Alert card

A distinct card on the dashboard, separate from the existing
`PenaltyWatchCard`: a count of `pending` notices plus a count of `failed` ones,
linking to the Notices page. The watch card answers "who is over a threshold";
this one answers "what is waiting on me". A queue nobody can see is a queue that
never gets released, which is the main failure mode for a human-release step.

The card renders nothing when both counts are zero and both modes are
`disabled`, so a league not using notices sees no dead furniture.

## Privacy, uninstall, health

- **GDPR export and erase.** Notice rows hold a player's name, email address and
  penalty history. They must join the existing exporter and eraser in
  `class-privacy.php`, alongside `spt_email` and the notes table. Erasing a
  player must clear `recipient` and `bcc`, not merely the row's association.
- **Uninstall.** `uninstall.php` drops the new table, deletes the four new
  options, and clears the cron event with `wp_unschedule_hook()` — not
  `wp_clear_scheduled_hook()`, which only matches argless events.
- **Health dashboard.** Register the new table with
  `spat_health_dashboard_tables` and the new cron with
  `spat_health_dashboard_crons`, so the SPAT health page reports both.

## Failure modes

| Failure | Behaviour |
|---|---|
| `wp_mail()` returns false | Row → `failed`, `last_error` recorded, logged via `SPAT_Logger::error`, retried through `release`. |
| No address resolves | Row → `failed` with `no email on file`; never silently skipped. |
| Player-tools inactive | No captain Bcc; notice still sends. |
| `SPAT_Lock` unavailable | Pass does nothing. Safer than a possible double send. |
| `splm_default_season` unset | Pass does nothing; technical view says so explicitly. |
| Module disabled mid-season | Pass stops writing rows. The REST routes and the React page 503, per this codebase's module convention; the WP-admin technical tab keeps rendering existing rows read-only, with a warning banner and no action controls, because an administrator looking at a feature someone just switched off is usually trying to find out what it already sent. |
| Cron never fires | No notices. The technical view's next-run display is how this gets noticed. |
| Score sheet inflates PIM erroneously | Why `queued` is the recommended mode for suspensions: a human sees the notice before the player does. |

## Out of scope

- **Appeals workflow.** A discarded notice records a note; there is no
  player-facing dispute path.
- **Multi-game suspensions spanning a season boundary.** Notices are
  season-scoped.
- **Per-division threshold overrides.** One tier list per site.
- **Notifying on flag removal.** Nothing tells a player they are back in good
  standing.
- **Event-driven evaluation.** Firing on score entry would be more responsive,
  but SportsPress stat writes are too diffuse to hook reliably. Daily cron is the
  honest mechanism.
- **Enforcement.** Nothing prevents a suspended player being rostered or
  appearing in a box score. The suspension is a notice and a tracked obligation,
  not a gate.
- **Per-tier delivery modes.** Modes are per severity class, not per tier.

## Testing

Standalone harness (`assert_test`, echo-based, exit code drives pass/fail),
registered in `run-all-tests.sh`. The pure classes take arrays in and return
arrays out, so these run with no WordPress bootstrap.

| Test file | Covers |
|---|---|
| `tests/test-discipline-consequence.php` | `sanitize_tiers` accepting and clamping `consequence`/`games`; `suspend` with 0 games coerced to 1; unknown consequence falling back to `none`; the existing tier validation still holding |
| `tests/test-discipline-notice-predicate.php` | the re-fire predicate across all six statuses; `baseline` suppression; re-fire after `served`; `discarded` sticking until the total climbs; window `ack_key` scoping preventing cross-window suppression |
| `tests/test-discipline-notice-mode.php` | each mode's row-writing behaviour; the three baselining triggers |
| `tests/test-discipline-notice-selection.php` | `matches()` returning every matched tier per scope while `evaluate()` still returns one; a populated ack array not suppressing a notice; one-notice-per-player-per-pass ranking by consequence then games then minutes; the runner-up receiving a `baseline` row and consequently not firing next pass at an unchanged total |
| `tests/test-discipline-notice-recipients.php` | the two-step player address chain and its `recipient_via`; captain resolution degrading when player-tools is absent, when no `sp_list` exists, and when no `spt_captain` is set; captain omitted for a non-current season; no `admin_email` fallback; `is_email` filtering of the cc list |
| `tests/test-discipline-notice-body.php` | warning naming the next threshold; suspension including the resolved game and footnote; graceful degradation to "your next scheduled game." with the footnote dropped |

Tests run under `America/Toronto` so any reach for site-local time where UTC is
required fails the suite, matching the convention established by the waitlist
work.

Staging verification, which no unit test reaches: the cron actually firing at the
configured local hour; a real `wp_mail()` send with a real Bcc list; the
technical tab rendering inside SPAT's tab JS; the React page and alert card
behind the module gate; and one end-to-end crossing driven by an actual score
sheet ingest.

## Phasing

Two phases. **All three delivery modes — `disabled`, `queued` and `automatic` —
must be fully implemented and selectable in Phase 1.** The release surfaces are
what make `queued` mean anything, so they are not separable from the modes that
depend on them; splitting them would ship a setting a league could select and
then be stranded by.

1. **The feature, end to end** — unseal `sanitize_tiers` and add the consequence
   and games inputs; both mode settings offering all three values; the notice
   table; `matches()`; the re-fire predicate; the three baselining triggers; the
   evaluation pass with `automatic` sending; the four REST routes; the WP-admin
   technical tab; the React Notices page and its alert card. Plus
   `uninstall.php` dropping the table, options and cron, and the GDPR exporter
   and eraser covering notice rows.

2. **Integration polish** — digest suppression via the `notice_sent` ack status,
   and health dashboard registration through `spat_health_dashboard_tables` and
   `spat_health_dashboard_crons`.

Uninstall and privacy sit in Phase 1 rather than in polish deliberately. The
notice table holds player names and email addresses; a table the uninstaller
does not drop and the GDPR exporter does not know about is a compliance gap, not
a finishing touch. What genuinely defers is the digest's `notice_sent`
suppression — until it lands, the weekly digest keeps listing a player who has
already been notified, which is redundant but harmless — and the health
dashboard rows, which are diagnostics.

Cutting scope, if it comes to that, means reducing what Phase 1 *contains* —
dropping the captain Bcc, or shipping the React surface before the WP-admin one
— not deferring a mode.
