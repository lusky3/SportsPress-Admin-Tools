# SportsPress Bench

Bench is a child plugin of SportsPress Admin Tools. It serves a small installable web app (a PWA)
at `/bench/` on your own site. A logged-in player sees their next game, their season schedule and
their roster, says whether they are playing (In / Out / Maybe) for each game, and can call a spare
when the team is short. Each player also gets a personal calendar feed that follows reschedules.
Organizers get a "Tonight" board for game nights.

Bench only reads SportsPress data. It never edits games, scores or rosters. It stores its own data
in four custom tables (availability, spare calls, spare responses, change log) plus a little user
and post meta.

## Requirements

- WordPress 5.0+, PHP 8.1+, SportsPress, and SportsPress Admin Tools (the parent plugin).
- Pretty permalinks (any structure other than "Plain"), because `/bench/` is a rewrite rule.
- Players must be linked to their user account: the `sp_player` post needs the `sp_user` meta set
  to the WordPress user ID.

## Turning it on

Bench ships dark. Activating the plugin does nothing visible until you enable the module.

1. Activate **SportsPress Bench** under Plugins.
2. Go to **Settings, SportsPress Admin Tools** and enable the **Bench** module.
3. Visit `https://your-site.example/bench/` while logged in.

If `/bench/` returns a 404, go to **Settings, Permalinks** and click **Save Changes** without
changing anything. That rebuilds the rewrite rules. Bench flushes them itself on activation and
once per plugin version, but a permalink plugin or a host-level cache can leave stale rules.

A visitor who is not logged in is sent to the WordPress login page and then back to the Bench page
they asked for (a link to a game or to `/bench/spares` from an email lands there), or to `/bench/`
when the address is not a plain Bench path. A
logged-in user with no linked player sees a short "we couldn't match your account to a player yet"
card instead of an error. The app does not need any other setup: there are no Bench settings.

Players can install it to their home screen (Chrome and Edge offer an install prompt; on iPhone use
Share, then Add to Home Screen). Offline, the app shows what it last loaded and says so.

## What players see

- **Next**: the next game that is going ahead (date, time, rink, opponent, home or away), In / Out /
  Maybe buttons and a count for the team. A game that has been moved or called off is flagged.
- **Schedule**: the full current-season schedule for the player's teams, with scores and W / L / T
  for finished games. Postponed and cancelled games stay in the list, struck through.
- **Team**: record and roster (number, name, position, captain mark).
- **Spares**: opt in to be a spare, and take open calls.
- **Me**: the player's name and teams, the calendar link, and the reset link.

Which teams count as "mine": the published teams in the player's `sp_current_team` meta that have at
least one event in the current SportsPress season (the `sportspress_season` option). A stale team
left over from a previous season is ignored. A user with several linked player posts (for example a
skater record and a goalie record) gets all of them.

A game counts as called off when its `sp_status` is `postponed` or `cancelled`, or when Events
Manager has cancelled it (its `_spem_cancelled` flag, which also drafts the post).

## Calendar feed

Each user has a private feed at `/bench/ical/<32-character token>`. A `.ics` suffix on the end is
still accepted, so older subscriptions keep working. The Me tab shows it with a Subscribe button
(`webcal://`), a Google Calendar link and a copy-link button. The feed lists the player's teams' current-season games and any game they have accepted as a spare. Reschedules change
the event in place and raise its `SEQUENCE`; cancelled games are `STATUS:CANCELLED`. Games are 75
minutes long unless a theme or plugin changes it with the `spbn_game_duration_minutes` filter.

- The feed is cached on the server for up to ten minutes. A logged change to an upcoming game (a
  new time, rink or opponent, or a postponement or cancellation; see "Changed games") clears the
  cache at once. Anything else, such as a newly added game or an edit to a game already played,
  shows up when the ten minutes are up. How soon a phone shows the change is up to the calendar
  app: Apple Calendar and Outlook poll every few hours, and Google Calendar can take a day.
- The token is the only credential. Anyone who has the link can read that player's schedule, so it
  should be treated like a password.
- **Reset link** (Me tab) rotates the token. The old link stops working immediately (404) and the
  player has to subscribe again with the new one. Use it if a link was shared by mistake.

## Spares

A team that is short can post a spare call for a game: skater or goalie, 1 to 3 players, and a note
of up to 140 characters. Any player on the calling team, or any organizer, can post or cancel a call.
Captains are not required, because most rosters have none set.

Every call emails the spare pool, so two limits keep it from being flooded:

- A team can have only one open call per game and position. Posting another skater call while one
  is still open is refused (`409 spbn_call_exists`, with the open call in the response); once it
  has filled or been cancelled, a new one can go out.
- One person can post at most five calls in any 24 hours, counting calls that were later cancelled
  (`429 spbn_too_many_calls`). The limit is per person, organizers included.

Who gets offered a call (all of these must hold):

- They opted in under Spares and picked that position.
- They have at least one team in the current season (registered this season).
- They are not on either team in that game.
- None of their own games starts within 150 minutes either side of it.
- They have not already taken a place on another call for the same game (one place per game).
- The game is in the future and not called off, and the call is still open.

Opted-in, eligible players are emailed, at most 300 per call; when more than 300 qualify, 300 are
picked at random each time, so nobody is always left out. The first players to tap **I'll
play** fill the call, up to the number needed; a tap on a call that is already full or closed is refused. Accepted spares count in the team's In total (and in goalies in for a goalie call) and show
in that team's list tagged as a spare. A spare can withdraw before the game, which re-opens a call
that had filled. If a game with accepted spares is rescheduled, postponed or cancelled, only those
spares are emailed.

## Organizer "Tonight" board

Users with the `manage_sportspress` capability (the same gate as the League Manager dashboard) see a
**Tonight** tab. A date stepper lists that day's games with the time and rink, both sides'
in / out / no-reply counts, goalies in, open spare calls and whether a result has been entered.
Organizers can also open any game, see both sides' availability lists, and post a spare call for a
team. A game changed in the last 14 days is flagged "Changed" on the board.

## Privacy

- **Availability.** A player's In / Out / Maybe and note are visible to the people on that side of
  the game and to organizers. Everyone else gets counts only. Opponents never see names. A player
  can change their own answer until three hours after the game starts; after that it is locked
  (organizers can still edit).
- **Contact details.** The app shows names, numbers and positions only. It does not show email
  addresses or phone numbers.
- **Email.** Bench sends three kinds of mail, all plain text, through `wp_mail()`:
  - a spare call to eligible, opted-in players (game time, rink, opponent, the caller's note and a
    link to `/bench/spares`);
  - a "spare filled" note to the person who posted the call, naming the spare;
  - a "Your spare game changed / is cancelled: {team} · {time}" note to accepted spares only.

  Bench does not email teams about reschedules; Events Manager already does that. Pool and spare
  mail is sent in batches of 40 with the players on Bcc, so recipients cannot see one another. The
  visible To address of those batches is `noreply@<your site's host>` by default, not the site admin,
  whose inbox would otherwise get a copy of every blast. To use another address, set the
  `spbn_mail_to` option (for example `wp option update spbn_mail_to league@example.com`) or return
  one from the `spbn_mail_to` filter, which receives the default and has the last word. An invalid
  address stops these batches from being sent.
- **What is stored.** Availability, spare calls and responses, a log of game changes, each user's
  calendar token and spare preferences, and a fingerprint of each game used to detect changes.
- **Uninstall.** Deactivating removes no data, only the `/bench/` routes and the scheduled
  baseline job (turning the module off drops the routes too). Deleting the plugin removes its data only if
  **Remove Data on Uninstall** is on in SportsPress Admin Tools settings (the
  `spat_remove_data_on_uninstall` option). With it on, Bench drops its four tables, every `spbn_*`
  option and transient, the `spbn_ical_token` and `spbn_spare_prefs` user meta, and the
  `_spbn_fingerprint` / `_spbn_snapshot` event meta. With it off, all of that is left in place.

## Changed games

When a saved game's start time, rink, teams or called-off state differs from what Bench last saw,
it logs a change. The first save of a game never logs one, and neither do edits to games in the
past. Games changed in the last 14 days show a "Changed" flag. This works for edits made in wp-admin
and by Events Manager, not just through Bench.

Games that were already on the schedule before Bench was turned on have nothing to compare against
yet, so Bench records a baseline for every upcoming game (without logging a change): on activation,
when the module is enabled, from a daily `spbn_baseline` cron job that stops once every upcoming
game has one, and whenever a game without one is opened in the app or the feed. The first reschedule
or cancellation of such a game is then logged like any other.

## Troubleshooting

- `/bench/` returns 404: re-save **Settings, Permalinks** (see "Turning it on").
- If your web server has rules that serve `.js`/`.webmanifest` files statically, Bench is unaffected
  because its service worker and manifest URLs have no extension (`/bench/sw` and
  `/bench/manifest`).

## Development

The PHP side is standalone-tested like the other plugins (`bash run-all-tests.sh` from the repo
root). The app source is in `app/` (Vite, React, TypeScript, Node 20.19 or newer) and builds into
`build/`, which is committed because the PHP shell serves it as-is and the plugin zip ships it.

```bash
cd sportspress-bench/app
npm ci
npm test          # Vitest
npm run build     # tsc -b && vite build, writes ../build/
git add ../build  # commit the rebuilt output with the source change
```

CI runs the same three commands, checks that `build/.vite/manifest.json` and `build/sw.js` exist,
and fails if the rebuilt `build/` differs from what is committed. The release workflow checks that
the zip contains both files. `app/`, `tests/` and `node_modules/` are listed in `.distignore` and
are not shipped.

The app has no UI kit, router library or data-fetching library, and its initial JavaScript is kept
under 90 KB gzipped.

REST API: namespace `spbn/v1`, response shapes in `docs/rest-api-conventions.md` at the repo root.
