# Live WordPress/SportsPress Matrix CI — Design

**Status:** approved for planning
**Date:** 2026-09-06

## Goal

A new CI job that installs a real WordPress + SportsPress + WooCommerce site
across a PHP × WordPress version matrix, installs this repo's eight
`sportspress-*` plugins into it, and exercises two real user flows —
registration and the waitlist — end to end. It exists to catch the class of
bug the repo's existing standalone test suites structurally cannot: real hook
timing, real taxonomy/post-type behavior, real `dbDelta` migrations, real
plugin-activation order.

Three bugs found and fixed by hand against Tikal staging this session would
all have been caught by this job before they ever reached staging:

- `sp_team` is a WordPress post type, not a taxonomy — `wp_get_object_terms()`
  silently returns `WP_Error` for it, which only surfaced once a real
  registration order completed and the notification code tried to `implode()`
  that error.
- The waitlist matcher read `product_cat` only, on the assumption a full
  season's registration product gets re-categorized. The store actually marks
  a waitlist product with a `product_tag` instead — invisible until a real
  order for a real waitlist SKU was ingested.
- `SPLM_Dashboard_Frontend`'s page template had no page provisioning anywhere,
  so enabling any league module gave a 404 — invisible until someone actually
  clicked the admin menu item.

None of these are reachable from a mocked-WordPress standalone test. All three
are reachable from "install the plugin for real and click the thing."

## Non-goals

This does not replace `run-all-tests.sh`'s ~60 standalone suites, which run
fast and mocked on every PR via `ci-tests.yml`. This job is deliberately
narrower than "re-run everything against real WordPress" — it proves
installability across the matrix, plus two concrete flows chosen because they
are the parts of the codebase most recently shown to hide integration bugs.
Broader flow coverage (discipline notices, schedule generation, e-Transfer
webhooks, score sheets) is out of scope for this pass; the pattern this
establishes generalizes to those later if wanted.

## Trigger and matrix

- **Trigger:** `push: branches: [main]` and `workflow_dispatch`. Not on pull
  requests — this keeps PR feedback at its current speed; the matrix runs
  after merge and on demand, catching integration bugs before the next
  release tag rather than before merge.
- **Matrix:** `fail-fast: false`, all cells required (no
  `continue-on-error`):
  - `php: ['8.2', '8.3', '8.4', '8.5']`
  - `wp: ['6.9', '7.0', '7.1', 'latest']`
  - 16 cells total.

## Architecture

New workflow file `.github/workflows/live-environment.yml`, following this
repo's existing one-workflow-per-concern convention
(`ci-tests.yml`/`code-quality.yml`/`package-plugins.yml`/etc. are already
split this way). `permissions: contents: read` (least privilege, matching
`ci-tests.yml`); `concurrency` group with `cancel-in-progress: true`.

Per matrix cell, a job:

1. Starts a `mysql:8.0` service container (health-checked via
   `mysqladmin ping`), matching both reference repos
   (`sportspress-player-merge/.github/workflows/compat.yml`,
   `bulk-plugin-installer-for-wordpress/.github/workflows/integration.yml`).
2. Sets up PHP via `shivammathur/setup-php` with `tools: wp-cli`, for the
   matrix's PHP version.
3. Runs `bin/live-env/install.sh $WP_VERSION`, which:
   - `wp core download --version=$WP_VERSION` (or omits `--version` for
     `latest`), `wp config create`, `wp core install` against the MySQL
     service.
   - `wp plugin install sportspress woocommerce --activate` — both are public
     wordpress.org plugins; this repo's own `sportspress-player-merge` sibling
     already proves this pattern works for SportsPress.
   - Installs this repo's eight plugins by `rsync`-ing each `sportspress-*`
     directory into `wp-content/plugins/<same-name>` (not a symlink — matches
     `bulk-plugin-installer-for-wordpress`'s pattern, and ensures no `build/`
     or vendored asset is silently missed the way a symlink-of-repo-root would
     miss it).
   - Activates them **in dependency order**: `sportspress-admin-tools` first
     (the parent every child's `class_exists( 'SPAT_Plugin_Manager' )` check
     requires), then the seven children in any order.
4. Runs the three smoke tiers below, each a `wp eval-file` script under
   `bin/live-env/`, so they are runnable identically in CI or by a human
   against any live install (matching how `scripts/release-guard.php` and
   `scripts/build-manifest.php` are both CI steps and standalone CLI tools
   today).

### Tier 1 — `bin/live-env/smoke-activation.php`

- Confirms every one of the eight plugins reports `active` via
  `is_plugin_active()`.
- Enables a representative `spat_enabled_modules` set — at minimum
  `league_manager_dashboard`, `league_waitlist`, `player_registration`,
  `events_management`, `player_profile_picture` — covering every plugin that
  registers a module.
- Confirms `SPLM_Dashboard_Frontend::ensure_page()` produces a page whose
  permalink resolves (this is the exact bug: the page not existing).
- Confirms `SPAT_CONTRACT_VERSION` satisfies every child's declared floor —
  reads each child's own `version_compare(...)` check rather than hardcoding
  the number here, so a future contract bump can't silently drift out of sync
  with this check.
- Greps `debug.log` for `PHP Fatal` / `Uncaught` and fails the step if found.

### Tier 2 — `bin/live-env/smoke-registration.php`

Reproduces the registration flow this session hand-verified on Tikal:

1. Creates a WooCommerce product via `wp eval` — `$0`, named with the season
   code embedded in the title (e.g. `Player Registration (S2026)`, matching
   `SPAT_Season::from_title()`'s primary lookup path and how every real
   fixture on Tikal/Sonic is actually named), in a category whose name
   contains the configured `spr_registration_keyword` (default
   `"Registration"`), tagged `Player`.
2. Places an order for it via `wc_create_order()` + `wp eval` (no HTTP
   checkout needed — this is testing the plugin's order-completion hook, not
   WooCommerce's own checkout, which is out of scope) and marks it
   `completed`.
3. Asserts: a `sp_player` post now exists, its `spt_email` meta matches the
   order's billing email, it carries an `sp_season` taxonomy term named
   `S2026` (added by `add_season_to_player()`), and `_spr_processed` is
   `'1'` (not `'failed'` — this is precisely the assertion that would have
   caught the `sp_team` taxonomy crash, since that crash fired from inside
   this exact code path and left the order flagged as failed).

### Tier 3 — `bin/live-env/smoke-waitlist.php`

Reproduces the waitlist flow this session hand-verified on Tikal three times:

1. Enables `league_waitlist`.
2. Creates two products for the same season/position: a target registration
   product (category) and a waitlist product (tag `Waitlist`) — the exact
   category/tag split this session's fix made the matcher understand.
3. Places and completes an order for the waitlist product; asserts a
   `wp_splm_waitlist` row is created `queued` with the correct
   `target_product_id` (this is the assertion that would have caught the
   tag/category matcher bug — before the fix, `target_product_id` would be
   `0`).
4. Calls `SPLM_Waitlist_Offer::offer()` directly via `wp eval`; asserts a
   64-char `claim_token` and a scheduled `splm_waitlist_expire_offer` cron
   event.
5. Hits the public claim REST route (`GET /wp-json/splm/v1/waitlist/claim/<token>`)
   via `curl` against the site's actual URL; asserts the `302` redirect and
   that the row is unchanged (the claim route's prefetch-safety property).
6. Places and completes an order for the target product carrying the claim
   token; asserts the row lands `claimed` with `resolved_order_id` set and the
   token nulled.

## Error handling

A failure in any tier fails that matrix cell's job; `fail-fast: false` means
the other 15 cells still report. No tier suppresses or retries a failure —
this workflow's entire purpose is to surface exactly this class of bug, so
swallowing one defeats it.

## Sandbox backport

Investigating `~/git/sportspress-sandbox` for reuse turned up one real
defect worth fixing regardless of this design: `compose.yml` currently
bind-mounts the entire monorepo root into a single container path named
`wp-content/plugins/sportspress-admin-tools`, so only one plugin's worth of
files is actually visible to the sandboxed site — the other seven
`sportspress-*` directories are invisible to it. (This matches a gap already
flagged in this project's own memory as stale.)

Three changes to `sportspress-sandbox`, in its own PR, its own repo:

1. **Fix the mount** — one bind mount per plugin directory in `compose.yml`
   (eight total), replacing the single stale mount.
2. **Fixture scripts** — `config/scripts/fixtures-registration.sh` and
   `config/scripts/fixtures-waitlist.sh`, built from the same wp-cli logic as
   `bin/live-env/smoke-registration.php` / `smoke-waitlist.php` (adapted for
   interactive use — creating fixtures and stopping, not asserting and
   exiting nonzero), so a human driving the sandbox gets the same scenario
   without re-deriving it by hand.
3. **Default-enable the waitlist path** in `config/scripts/setup-test-data.sh`
   — enable `league_waitlist` alongside the modules it already enables, and
   call `SPLM_Dashboard_Frontend::ensure_page()` so the League Dashboard menu
   item is reachable out of the box, rather than 404ing the way it did on both
   real hosts before this session's fix.

Not touched by this design: the sandbox's pinned base image PHP/WordPress
version — that is its own separate PR per direct instruction, tracked and
landed independently of this plan — the Playwright/MCP setup, and
`tests/suites/*.md` (adding an `08-waitlist.md` suite is a natural follow-on
but is not part of this design — flagged as a possible future addition, not a
deliverable here).

## Testing this design

The workflow's own correctness is proven by running: a fresh push to a
throwaway branch (or `workflow_dispatch`) must show all 16 cells green before
this is considered done. There is no separate "test the test" layer beyond
that — the three smoke tiers are themselves the tests, run for real.
