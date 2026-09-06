# Live Environment Matrix CI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A CI job that installs a real WordPress + SportsPress + WooCommerce
site across a PHP × WordPress matrix, installs this repo's eight
`sportspress-*` plugins, and proves two real user flows (registration,
waitlist) end to end — catching the class of bug this session found and fixed
by hand (real taxonomy/post-type behavior, real hook timing, a page template
with no page ever provisioned) that mocked standalone tests structurally
cannot reach.

**Architecture:** No Docker-in-Docker. wp-cli against a `mysql:8.0` service
container on the Actions runner, matching both reference repos
(`sportspress-player-merge/.github/workflows/compat.yml`,
`bulk-plugin-installer-for-wordpress/.github/workflows/integration.yml`). Every
piece is a standalone script runnable identically in CI or by a human, matching
`scripts/release-guard.php`/`scripts/build-manifest.php`'s existing pattern.

**Tech Stack:** Bash (install script), PHP via `wp eval-file` (smoke scripts),
GitHub Actions with a `mysql:8.0` service container.

**Spec:** `docs/superpowers/specs/2026-09-06-live-environment-matrix-design.md`

## Global Constraints

- Matrix: `php: ['8.2', '8.3', '8.4', '8.5']` × `wp: ['6.9', '7.0', '7.1', 'latest']`, `fail-fast: false`, no `continue-on-error` anywhere.
- Trigger: `push: branches: [main]` and `workflow_dispatch` only — not `pull_request`.
- Every GitHub Action reference is SHA-pinned, matching every other workflow in this repo. Use exactly `actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1` and `shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2` — these are the pins every other workflow in this repo already uses; do not substitute a different version.
- `permissions: contents: read` at the workflow level (least privilege, matching `ci-tests.yml`). `concurrency` group `${{ github.workflow }}-${{ github.ref }}` with `cancel-in-progress: true`.
- Plugin activation order is always parent-first: `sportspress-admin-tools` alone, then the seven children. Every child's `check_activation_requirements()` does `class_exists( 'SPAT_Plugin_Manager' )`, which only resolves once the parent is active.
- The registration/waitlist product fixtures follow the *real* store convention verified this session: season code embedded in the product **title** (e.g. `Player Registration (S2026)`), a category whose name contains `"Registration"`, position via a `Player`/`Goalie` product **tag**, and — for a waitlist product — an *additional* `Waitlist` product tag alongside the same registration category (this is the exact category+tag split `SPLM_Waitlist_Matcher` was fixed to understand this session).
- Scripts under `bin/live-env/` must be runnable by a human against any live WordPress install, not only inside the GitHub Actions job — no assumption of CI-only environment variables beyond what's documented in each script's own header.

---

# Phase 1 — SportsPress-Admin-Tools repository

All tasks in this phase happen on branch `feat/live-environment-matrix` (already
checked out), in this repo. One PR at the end of the phase.

### Task 1: `bin/live-env/install.sh`

**Files:**
- Create: `bin/live-env/install.sh`

**Interfaces:**
- Consumes: env vars `WP_DB_HOST`, `WP_DB_NAME`, `WP_DB_USER`, `WP_DB_PASSWORD` (all optional, sensible defaults), `WP_ROOT` (optional, defaults to `./wp`); positional arg `$1` = WordPress version string (`"6.9"`, `"7.0"`, `"7.1"`, or `"latest"`).
- Produces: a running WordPress site at `$WP_ROOT`, served on `http://localhost:8080` via `wp server` (backgrounded), with SportsPress + WooCommerce + this repo's eight plugins installed and the parent activated. Later tasks' smoke scripts run against this install via `wp eval-file ... --path=$WP_ROOT --allow-root`.

- [ ] **Step 1: Write the script**

```bash
#!/usr/bin/env bash
set -euo pipefail

# bin/live-env/install.sh <wp-version>
#
# Installs a real WordPress + SportsPress + WooCommerce site and this
# repo's eight sportspress-* plugins into it, for the live-environment
# matrix CI job (.github/workflows/live-environment.yml). Also runnable
# by hand against any MySQL-backed host for local verification -- matching
# how scripts/release-guard.php and scripts/build-manifest.php are both CI
# steps and standalone CLI tools.
#
# Env (all optional, matching the matrix job's mysql service container):
#   WP_DB_HOST      (default 127.0.0.1)
#   WP_DB_NAME      (default wordpress)
#   WP_DB_USER      (default root)
#   WP_DB_PASSWORD  (default root)
#   WP_ROOT         (default ./wp)
#
# Usage: bin/live-env/install.sh <wp-version>
#   <wp-version>: a WordPress version string ("6.9", "7.0", "7.1"), or
#   "latest" to omit --version and let wp-cli install the newest release.

WP_VERSION="${1:?usage: install.sh <wp-version>}"
WP_ROOT="${WP_ROOT:-$(pwd)/wp}"
WP_DB_HOST="${WP_DB_HOST:-127.0.0.1}"
WP_DB_NAME="${WP_DB_NAME:-wordpress}"
WP_DB_USER="${WP_DB_USER:-root}"
WP_DB_PASSWORD="${WP_DB_PASSWORD:-root}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

mkdir -p "$WP_ROOT"
cd "$WP_ROOT"

echo "== core download ($WP_VERSION) =="
if [ "$WP_VERSION" = "latest" ]; then
  wp core download --allow-root
else
  wp core download --version="$WP_VERSION" --allow-root
fi

echo "== config =="
wp config create \
  --dbname="$WP_DB_NAME" --dbuser="$WP_DB_USER" --dbpass="$WP_DB_PASSWORD" --dbhost="$WP_DB_HOST" \
  --allow-root

# The service container's own healthcheck gates job start, but wp-cli's first
# connection can still race the container's internal init on a cold start.
for i in $(seq 1 30); do
  wp db check --allow-root >/dev/null 2>&1 && break
  sleep 2
done

echo "== core install =="
wp core install \
  --url="http://localhost:8080" --title="Live Environment Matrix" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test \
  --skip-email --allow-root

echo "== SportsPress + WooCommerce (wordpress.org) =="
wp plugin install sportspress woocommerce --activate --allow-root

echo "== this repo's plugins, copied in (not symlinked, so build/ and every"
echo "   vendored asset comes along -- matches bulk-plugin-installer-for-wordpress) =="
for slug in sportspress-admin-tools sportspress-etransfer-automation sportspress-events-manager \
            sportspress-league-manager sportspress-player-registration sportspress-player-tools \
            sportspress-schedule-generator sportspress-score-sheets; do
  rsync -a --exclude=.git "$REPO_ROOT/$slug/" "wp-content/plugins/$slug/"
done

# Parent first, alone: every child's check_activation_requirements() does
# class_exists( 'SPAT_Plugin_Manager' ), which only resolves once the parent
# is active. Activating everything in one call races that check under
# WordPress's own undefined multi-plugin activation order.
wp plugin activate sportspress-admin-tools --allow-root
wp plugin activate \
  sportspress-etransfer-automation sportspress-events-manager sportspress-league-manager \
  sportspress-player-registration sportspress-player-tools sportspress-schedule-generator \
  sportspress-score-sheets \
  --allow-root

echo "== starting the site (wp-cli's PHP built-in server) =="
( wp server --host=0.0.0.0 --port=8080 --allow-root >/tmp/wp-server.log 2>&1 & )
for i in $(seq 1 30); do
  curl -sf http://localhost:8080/ >/dev/null 2>&1 && break
  sleep 1
done
if ! curl -sf http://localhost:8080/ >/dev/null 2>&1; then
  echo "site did not come up; wp-server log:" >&2
  cat /tmp/wp-server.log >&2
  exit 1
fi

echo "== done: $WP_ROOT, serving at http://localhost:8080 =="
```

- [ ] **Step 2: Syntax check**

Run: `bash -n bin/live-env/install.sh`
Expected: no output, exit 0.

- [ ] **Step 3: Run it against a real throwaway MySQL container**

```bash
chmod +x bin/live-env/install.sh
docker run -d --name live-env-check-mysql -p 3307:3306 \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress mysql:8.0
# wait for it to accept connections
for i in $(seq 1 30); do docker exec live-env-check-mysql mysqladmin ping -proot 2>/dev/null && break; sleep 2; done

WP_DB_HOST=127.0.0.1:3307 WP_ROOT=/tmp/live-env-check bin/live-env/install.sh 7.1
```

Expected: exits 0, ends with `== done: /tmp/live-env-check, serving at http://localhost:8080 ==`, and `curl -sf http://localhost:8080/` succeeds.

- [ ] **Step 4: Clean up and commit**

```bash
docker rm -f live-env-check-mysql
rm -rf /tmp/live-env-check
git add bin/live-env/install.sh
git commit -m "feat(live-env): wp-cli bootstrap for the matrix CI job"
```

---

### Task 2: `bin/live-env/smoke-activation.php`

**Files:**
- Create: `bin/live-env/smoke-activation.php`
- Depends on: Task 1's install having run first.

**Interfaces:**
- Consumes: a WordPress install at the path passed via `wp eval-file ... --path=<root> --allow-root`, with this repo's eight plugins installed but not yet configured.
- Produces: exit 0 and `All activation checks passed.` on stdout when every check passes; exit 1 and a stderr list of failed checks otherwise. Also leaves `spat_enabled_modules` set for Tasks 3 and 4 to build on (`league_waitlist`, `player_registration`, `events_management`, `player_profile_picture`, `league_manager_dashboard`).

- [ ] **Step 1: Write the script**

```php
<?php
/**
 * Tier 1: every plugin activates, key modules enable, the League Dashboard
 * page resolves, the parent's contract version satisfies every child's
 * declared floor, and nothing in debug.log says otherwise.
 *
 * Run via: wp eval-file bin/live-env/smoke-activation.php --path=<wp root> --allow-root
 */

$failures = array();

function check( $condition, $message ) {
	global $failures;
	echo ( $condition ? 'OK   ' : 'FAIL ' ) . $message . "\n";
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$plugins = array(
	'sportspress-admin-tools/sportspress-admin-tools.php',
	'sportspress-etransfer-automation/sportspress-etransfer-automation.php',
	'sportspress-events-manager/sportspress-events-manager.php',
	'sportspress-league-manager/sportspress-league-manager.php',
	'sportspress-player-registration/sportspress-player-registration.php',
	'sportspress-player-tools/sportspress-player-tools.php',
	'sportspress-schedule-generator/sportspress-schedule-generator.php',
	'sportspress-score-sheets/sportspress-score-sheets.php',
);

foreach ( $plugins as $file ) {
	check( is_plugin_active( $file ), "active: $file" );
}

update_option(
	'spat_enabled_modules',
	array(
		'league_manager_dashboard',
		'league_waitlist',
		'player_registration',
		'events_management',
		'player_profile_picture',
	)
);

// The classes that key off spat_enabled_modules hook plugins_loaded/admin_init,
// which already fired before this eval() runs -- re-fire init so the modules
// this call just enabled actually wire up their hooks.
do_action( 'init' );

check( class_exists( 'SPLM_Waitlist_Database' ), 'league_waitlist module classes load' );

if ( class_exists( 'SPLM_Dashboard_Frontend' ) ) {
	$page_id = SPLM_Dashboard_Frontend::ensure_page();
	check( $page_id > 0, 'League Dashboard page is provisioned' );
	check( $page_id > 0 && '' !== get_permalink( $page_id ), 'League Dashboard permalink resolves' );
} else {
	$failures[] = 'SPLM_Dashboard_Frontend did not load';
}

if ( defined( 'SPAT_CONTRACT_VERSION' ) ) {
	// Read the floor each child actually declares rather than hardcoding a
	// number here, so a future contract bump can't silently desync from
	// this check.
	foreach ( array(
		'sportspress-player-tools/sportspress-player-tools.php',
		'sportspress-player-registration/sportspress-player-registration.php',
	) as $file ) {
		$src = file_get_contents( WP_PLUGIN_DIR . '/' . $file );
		if ( preg_match( "/SPAT_CONTRACT_VERSION,\s*'([^']+)'/", $src, $m ) ) {
			check(
				version_compare( SPAT_CONTRACT_VERSION, $m[1], '>=' ),
				"contract floor satisfied: $file requires {$m[1]}, parent declares " . SPAT_CONTRACT_VERSION
			);
		}
	}
} else {
	$failures[] = 'SPAT_CONTRACT_VERSION is not defined';
}

$debug_log = WP_CONTENT_DIR . '/debug.log';
if ( file_exists( $debug_log ) ) {
	$log = file_get_contents( $debug_log );
	check( false === stripos( $log, 'PHP Fatal' ) && false === stripos( $log, 'Uncaught' ), 'debug.log has no fatals' );
} else {
	echo "OK   debug.log does not exist yet (nothing has logged)\n";
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "\n" . count( $failures ) . " check(s) failed:\n" );
	foreach ( $failures as $f ) {
		fwrite( STDERR, "  - $f\n" );
	}
	exit( 1 );
}

echo "\nAll activation checks passed.\n";
```

- [ ] **Step 2: Syntax check**

Run: `php -l bin/live-env/smoke-activation.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run it against Task 1's install**

```bash
docker run -d --name live-env-check-mysql -p 3307:3306 \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress mysql:8.0
for i in $(seq 1 30); do docker exec live-env-check-mysql mysqladmin ping -proot 2>/dev/null && break; sleep 2; done
WP_DB_HOST=127.0.0.1:3307 WP_ROOT=/tmp/live-env-check bin/live-env/install.sh 7.1
wp eval-file bin/live-env/smoke-activation.php --path=/tmp/live-env-check --allow-root
echo "exit: $?"
```

Expected: every line prints `OK`, final line `All activation checks passed.`, exit 0.

- [ ] **Step 4: Confirm it actually fails on a real regression**

This is the check that proves the script isn't vacuously green. Comment out
the `SPLM_Dashboard_Frontend::ensure_page()` call in
`sportspress-league-manager/includes/class-admin.php`'s activation hook (or
temporarily rename the method) in the *installed copy* at
`/tmp/live-env-check/wp-content/plugins/sportspress-league-manager/...`, rerun
the script, confirm it reports `FAIL League Dashboard page is provisioned` and
exits 1, then discard that installed copy (the source tree in this repo is
untouched — only the throwaway `/tmp/live-env-check` copy was edited).

- [ ] **Step 5: Clean up and commit**

```bash
docker rm -f live-env-check-mysql
rm -rf /tmp/live-env-check
git add bin/live-env/smoke-activation.php
git commit -m "feat(live-env): tier 1 smoke check -- activation, modules, dashboard page, contract floor"
```

---

### Task 3: `bin/live-env/smoke-registration.php`

**Files:**
- Create: `bin/live-env/smoke-registration.php`
- Depends on: Task 2 having run first (enables `player_registration`).

**Interfaces:**
- Consumes: the same live install, post-Task-2.
- Produces: exit 0 / `All registration-flow checks passed.` or exit 1 with a
  stderr list, same contract as Task 2's script.

- [ ] **Step 1: Write the script**

```php
<?php
/**
 * Tier 2: a real WooCommerce order completion creates a real sp_player.
 * Exercises the exact path that crashed on sp_team being a post type, not a
 * taxonomy ("a player registered but the order was flagged as failed"), until
 * real order data flowed through it this session.
 *
 * Run via: wp eval-file bin/live-env/smoke-registration.php --path=<wp root> --allow-root
 */

$failures = array();
function check( $condition, $message ) {
	global $failures;
	echo ( $condition ? 'OK   ' : 'FAIL ' ) . $message . "\n";
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'SPPR_Player_Registration' ) ) {
	fwrite( STDERR, "WooCommerce or SPPR_Player_Registration not loaded -- run smoke-activation.php first\n" );
	exit( 2 );
}

// A category whose name contains "Registration" (spr_registration_keyword's
// default), plus the season code embedded in the product TITLE --
// SPAT_Season::from_title() is the primary lookup path, and every real
// fixture on Tikal/Sonic is named this way.
$reg_cat       = get_term_by( 'name', 'Registration', 'product_cat' );
$reg_cat_id    = $reg_cat ? $reg_cat->term_id : wp_insert_term( 'Registration', 'product_cat' )['term_id'];
$player_tag    = get_term_by( 'name', 'Player', 'product_tag' );
$player_tag_id = $player_tag ? $player_tag->term_id : wp_insert_term( 'Player', 'product_tag' )['term_id'];

$product = new WC_Product_Simple();
$product->set_name( 'Player Registration (S2026)' );
$product->set_regular_price( '0' );
$product->set_price( '0' );
$product->set_virtual( true );
$product->set_status( 'publish' );
$product->set_category_ids( array( $reg_cat_id ) );
$product->set_tag_ids( array( $player_tag_id ) );
$product_id = $product->save();

$order = wc_create_order();
$order->add_product( wc_get_product( $product_id ), 1 );
$order->set_billing_email( 'smoke-test@example.test' );
$order->set_billing_first_name( 'Smoke' );
$order->set_billing_last_name( 'Test' );
$order->calculate_totals();
$order->save();
$order->update_status( 'completed' );

$order = wc_get_order( $order->get_id() ); // re-fetch: status hooks may have modified it

check(
	'1' === $order->get_meta( '_spr_processed' ),
	'order not flagged failed (_spr_processed=' . $order->get_meta( '_spr_processed' ) . ')'
);

$players = get_posts(
	array(
		'post_type'      => 'sp_player',
		'posts_per_page' => 1,
		'meta_key'       => 'spt_email', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => 'smoke-test@example.test', // phpcs:ignore WordPress.DB.SlowDBQuery
	)
);
check( ! empty( $players ), "a sp_player was created for the order's billing email" );

if ( ! empty( $players ) ) {
	$player_id = $players[0]->ID;
	$seasons   = wp_get_object_terms( $player_id, 'sp_season', array( 'fields' => 'names' ) );
	check( in_array( 'S2026', (array) $seasons, true ), 'the player carries the S2026 season term' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "\n" . count( $failures ) . " check(s) failed:\n" );
	foreach ( $failures as $f ) {
		fwrite( STDERR, "  - $f\n" );
	}
	exit( 1 );
}
echo "\nAll registration-flow checks passed.\n";
```

- [ ] **Step 2: Syntax check**

Run: `php -l bin/live-env/smoke-registration.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run against a fresh install (Tasks 1+2 in sequence)**

```bash
docker run -d --name live-env-check-mysql -p 3307:3306 \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress mysql:8.0
for i in $(seq 1 30); do docker exec live-env-check-mysql mysqladmin ping -proot 2>/dev/null && break; sleep 2; done
WP_DB_HOST=127.0.0.1:3307 WP_ROOT=/tmp/live-env-check bin/live-env/install.sh 7.1
wp eval-file bin/live-env/smoke-activation.php --path=/tmp/live-env-check --allow-root
wp eval-file bin/live-env/smoke-registration.php --path=/tmp/live-env-check --allow-root
echo "exit: $?"
```

Expected: every check `OK`, final line `All registration-flow checks passed.`, exit 0.

- [ ] **Step 4: Confirm it fails on the regression it exists to catch**

In the installed copy only, temporarily change
`sportspress-player-registration/includes/class-player-registration.php`'s
`player_team_names()`/`SPAT_Player::team_names()` call back to the broken
`wp_get_object_terms( $player_id, 'sp_team', ... )` form, rerun step 3's
commands, confirm `_spr_processed` check reports `FAIL` (the order gets
flagged `failed` again), then discard the installed copy.

- [ ] **Step 5: Clean up and commit**

```bash
docker rm -f live-env-check-mysql
rm -rf /tmp/live-env-check
git add bin/live-env/smoke-registration.php
git commit -m "feat(live-env): tier 2 smoke check -- real order completion creates a real player"
```

---

### Task 4: `bin/live-env/smoke-waitlist.php`

**Files:**
- Create: `bin/live-env/smoke-waitlist.php`
- Depends on: Tasks 2 and 3 having run first.

**Interfaces:**
- Consumes: the same live install, post-Task-3. Requires `home_url()` to
  resolve to a real, reachable server (Task 1's `wp server` step) for the
  claim-route HTTP check.
- Produces: exit 0 / `All waitlist-flow checks passed.` or exit 1 with a
  stderr list, same contract as Tasks 2 and 3.

- [ ] **Step 1: Write the script**

```php
<?php
/**
 * Tier 3: the waitlist flow this session hand-verified against Tikal three
 * times -- ingest, offer, claim, purchase, tie-back. Exercises the exact
 * category+tag matcher split SPLM_Waitlist_Matcher was fixed to understand
 * this session.
 *
 * Run via: wp eval-file bin/live-env/smoke-waitlist.php --path=<wp root> --allow-root
 */

$failures = array();
function check( $condition, $message ) {
	global $failures;
	echo ( $condition ? 'OK   ' : 'FAIL ' ) . $message . "\n";
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

if ( ! class_exists( 'SPLM_Waitlist_Database' ) ) {
	fwrite( STDERR, "SPLM_Waitlist_Database not loaded -- run smoke-activation.php first\n" );
	exit( 2 );
}

$reg_cat         = get_term_by( 'name', 'Registration', 'product_cat' );
$reg_cat_id      = $reg_cat ? $reg_cat->term_id : wp_insert_term( 'Registration', 'product_cat' )['term_id'];
$player_tag      = get_term_by( 'name', 'Player', 'product_tag' );
$player_tag_id   = $player_tag ? $player_tag->term_id : wp_insert_term( 'Player', 'product_tag' )['term_id'];
$waitlist_tag    = get_term_by( 'name', 'Waitlist', 'product_tag' );
$waitlist_tag_id = $waitlist_tag ? $waitlist_tag->term_id : wp_insert_term( 'Waitlist', 'product_tag' )['term_id'];

// Target: a real registration product, same convention as smoke-registration.php.
$target = new WC_Product_Simple();
$target->set_name( 'Player Registration (S2027)' );
$target->set_regular_price( '575' );
$target->set_price( '575' );
$target->set_status( 'publish' );
$target->set_category_ids( array( $reg_cat_id ) );
$target->set_tag_ids( array( $player_tag_id ) );
$target_id = $target->save();

// Waitlist: the SAME registration category (so it carries both markers, the
// way real waitlist products do) plus the Waitlist tag -- the exact split
// SPLM_Waitlist_Matcher was fixed to understand this session.
$waitlist = new WC_Product_Simple();
$waitlist->set_name( 'Player Waitlist (S2027)' );
$waitlist->set_regular_price( '0' );
$waitlist->set_price( '0' );
$waitlist->set_virtual( true );
$waitlist->set_status( 'publish' );
$waitlist->set_category_ids( array( $reg_cat_id ) );
$waitlist->set_tag_ids( array( $player_tag_id, $waitlist_tag_id ) );
$waitlist_id = $waitlist->save();

check(
	$target_id === SPLM_Waitlist_Matcher::find_target_product( 'S2027', 'player' ),
	"the matcher resolves the waitlist product's target from category + tag"
);

$order = wc_create_order();
$order->add_product( wc_get_product( $waitlist_id ), 1 );
$order->set_billing_email( 'waitlist-smoke@example.test' );
$order->calculate_totals();
$order->save();
$order->update_status( 'completed' );

global $wpdb;
$row = $wpdb->get_row(
	$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}splm_waitlist WHERE email=%s", 'waitlist-smoke@example.test' )
);
check( null !== $row, 'a waitlist row was ingested from the completed order' );
check( null !== $row && (int) $row->target_product_id === $target_id, "the row's target_product_id points at the real registration product" );
check( null !== $row && 'queued' === $row->status, 'the row starts queued' );

if ( null === $row ) {
	fwrite( STDERR, "\nIngestion failed -- cannot continue to offer/claim.\n" );
	exit( 1 );
}

$offer = SPLM_Waitlist_Offer::offer( $row->id, 48 );
check( ! is_wp_error( $offer ), 'offer() succeeds' );

$row = SPLM_Waitlist_Database::get( $row->id );
check( null !== $row && 64 === strlen( (string) $row->claim_token ), 'a 64-char claim token is generated' );

$pending = false;
foreach ( _get_cron_array() as $hooks ) {
	if ( isset( $hooks['splm_waitlist_expire_offer'] ) ) {
		$pending = true;
	}
}
check( $pending, 'an expiry event is scheduled' );

$claim_url = home_url( '/wp-json/splm/v1/waitlist/claim/' . $row->claim_token );
$response  = wp_remote_get( $claim_url, array( 'redirection' => 0 ) );
check( ! is_wp_error( $response ) && 302 === wp_remote_retrieve_response_code( $response ), 'the claim route redirects (302)' );

$after_claim_view = SPLM_Waitlist_Database::get( $row->id );
check( $after_claim_view->claim_token === $row->claim_token, 'viewing the claim link does not consume it (prefetch-safety)' );

$purchase = wc_create_order();
$purchase->add_product( wc_get_product( $target_id ), 1 );
$purchase->set_billing_email( 'waitlist-smoke@example.test' );
$purchase->save();
// Bind the claim token onto the line item the way the real cart flow does via
// SPLM_Waitlist_Claim's cart-item-data filter -- this script drives
// WooCommerce's order API directly rather than a real cart/checkout session,
// so the binding is done by hand here.
foreach ( $purchase->get_items() as $item ) {
	if ( (int) $item->get_product_id() === (int) $target_id ) {
		$item->add_meta_data( '_splm_waitlist_id', $row->claim_token, true );
		$item->save();
	}
}
$purchase->calculate_totals();
$purchase->save();
$purchase->update_status( 'completed' );

$final = SPLM_Waitlist_Database::get( $row->id );
check( null !== $final && 'claimed' === $final->status, 'the row lands claimed (status=' . ( $final->status ?? 'MISSING' ) . ')' );
check( null !== $final && (int) $final->resolved_order_id === $purchase->get_id(), 'resolved_order_id points at the completing order' );
check( null !== $final && null === $final->claim_token, 'the claim token is cleared' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "\n" . count( $failures ) . " check(s) failed:\n" );
	foreach ( $failures as $f ) {
		fwrite( STDERR, "  - $f\n" );
	}
	exit( 1 );
}
echo "\nAll waitlist-flow checks passed.\n";
```

- [ ] **Step 2: Syntax check**

Run: `php -l bin/live-env/smoke-waitlist.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run the full chain against a fresh install**

```bash
docker run -d --name live-env-check-mysql -p 3307:3306 \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress mysql:8.0
for i in $(seq 1 30); do docker exec live-env-check-mysql mysqladmin ping -proot 2>/dev/null && break; sleep 2; done
WP_DB_HOST=127.0.0.1:3307 WP_ROOT=/tmp/live-env-check bin/live-env/install.sh 7.1
wp eval-file bin/live-env/smoke-activation.php --path=/tmp/live-env-check --allow-root
wp eval-file bin/live-env/smoke-registration.php --path=/tmp/live-env-check --allow-root
wp eval-file bin/live-env/smoke-waitlist.php --path=/tmp/live-env-check --allow-root
echo "exit: $?"
```

Expected: every check `OK`, final line `All waitlist-flow checks passed.`, exit 0.

- [ ] **Step 4: Confirm it fails on the regression it exists to catch**

In the installed copy, temporarily narrow
`SPLM_Waitlist_Matcher::find_target_product()` back to `product_cat` only
(remove the tag lookup), rerun step 3's chain, confirm the `find_target_product`
check reports `FAIL` (target resolves to `0`, ingestion likely fails too), then
discard the installed copy.

- [ ] **Step 5: Clean up and commit**

```bash
docker rm -f live-env-check-mysql
rm -rf /tmp/live-env-check
git add bin/live-env/smoke-waitlist.php
git commit -m "feat(live-env): tier 3 smoke check -- waitlist ingest, offer, claim, purchase, tie-back"
```

---

### Task 5: `.github/workflows/live-environment.yml`

**Files:**
- Create: `.github/workflows/live-environment.yml`
- Depends on: Tasks 1-4 (this workflow wires all four scripts together).

**Interfaces:**
- Consumes: nothing new — invokes the four scripts already written.
- Produces: a 16-cell matrix job runnable via `push` to `main` or
  `workflow_dispatch` from any branch.

- [ ] **Step 1: Write the workflow**

```yaml
name: Live Environment Matrix

on:
  push:
    branches: [main]
  workflow_dispatch:

permissions:
  contents: read

concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  live-environment:
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3', '8.4', '8.5']
        wp: ['6.9', '7.0', '7.1', 'latest']
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -proot"
          --health-interval=5s
          --health-timeout=5s
          --health-retries=10
    steps:
      - name: Checkout Code
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1

      - name: Setup PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2
        with:
          php-version: ${{ matrix.php }}
          tools: wp-cli

      - name: Install WordPress + SportsPress + WooCommerce + this repo's plugins
        env:
          WP_DB_HOST: 127.0.0.1
        run: bash bin/live-env/install.sh "${{ matrix.wp }}"

      - name: Tier 1 -- activation, modules, dashboard page, contract floor
        run: wp eval-file bin/live-env/smoke-activation.php --path=wp --allow-root

      - name: Tier 2 -- registration flow
        run: wp eval-file bin/live-env/smoke-registration.php --path=wp --allow-root

      - name: Tier 3 -- waitlist flow
        run: wp eval-file bin/live-env/smoke-waitlist.php --path=wp --allow-root
```

- [ ] **Step 2: Validate the YAML**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/live-environment.yml'))" && echo valid`
Expected: `valid`.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/live-environment.yml
git commit -m "feat(live-env): wire the matrix workflow together"
```

- [ ] **Step 4: Push the branch, open the PR, and manually dispatch the workflow**

This is the step that proves the whole chain works exactly as it will run in
CI, not just in an ad-hoc local Docker container — this workflow's trigger is
`push: main` + `workflow_dispatch` only, so a normal PR push never runs it,
and it must be exercised by hand before merge:

```bash
git push -u origin feat/live-environment-matrix
gh pr create --title "feat: live-environment matrix CI" --body "<summarize the install script, the three smoke tiers, and the matrix/trigger decisions from the design spec>"
gh workflow run live-environment.yml --ref feat/live-environment-matrix
```

Poll `gh run list --workflow=live-environment.yml --branch=feat/live-environment-matrix`
until the run completes. All 16 matrix cells must report success before this
task — and this phase — is considered done. Fix and re-dispatch on any red
cell; do not merge on a partial pass.

---

# Phase 2 — sportspress-sandbox repository

All tasks in this phase happen in `~/git/sportspress-sandbox`, on its own new
branch (e.g. `feat/live-flow-fixtures`), and result in a separate PR in that
repo. Do **not** touch anything in this repo (SportsPress-Admin-Tools) while
working this phase — different remote, different CI, different PR.

### Task 6: Fix the per-plugin bind mount

**Files:**
- Modify: `~/git/sportspress-sandbox/compose.yml`

**Interfaces:**
- Consumes: the eight `sportspress-*` directories in the sibling
  `../SportsPress-Admin-Tools` checkout.
- Produces: all eight plugins visible inside the sandbox container, not just
  one mislabeled as `sportspress-admin-tools`.

- [ ] **Step 1: Replace the single stale mount with one per plugin**

In the `sportspress-test` service's `volumes:` list, replace:

```yaml
      - ../SportsPress-Admin-Tools:/var/www/html/wp-content/plugins/sportspress-admin-tools
```

with:

```yaml
      - ../SportsPress-Admin-Tools/sportspress-admin-tools:/var/www/html/wp-content/plugins/sportspress-admin-tools
      - ../SportsPress-Admin-Tools/sportspress-etransfer-automation:/var/www/html/wp-content/plugins/sportspress-etransfer-automation
      - ../SportsPress-Admin-Tools/sportspress-events-manager:/var/www/html/wp-content/plugins/sportspress-events-manager
      - ../SportsPress-Admin-Tools/sportspress-league-manager:/var/www/html/wp-content/plugins/sportspress-league-manager
      - ../SportsPress-Admin-Tools/sportspress-player-registration:/var/www/html/wp-content/plugins/sportspress-player-registration
      - ../SportsPress-Admin-Tools/sportspress-player-tools:/var/www/html/wp-content/plugins/sportspress-player-tools
      - ../SportsPress-Admin-Tools/sportspress-schedule-generator:/var/www/html/wp-content/plugins/sportspress-schedule-generator
      - ../SportsPress-Admin-Tools/sportspress-score-sheets:/var/www/html/wp-content/plugins/sportspress-score-sheets
```

- [ ] **Step 2: Validate the YAML**

Run: `docker compose -f compose.yml config >/dev/null && echo valid`
Expected: `valid`.

- [ ] **Step 3: Bring it up and confirm every plugin is now visible**

```bash
docker compose up -d --build --wait
docker exec sportspress-test wp plugin list --fields=name,status --allow-root | grep -E "^sportspress-"
```

Expected: all eight `sportspress-*` plugins listed (installed, whatever their
active/inactive state — `setup-test-data.sh` decides activation, not this
task).

- [ ] **Step 4: Tear down and commit**

```bash
docker compose down -v
git add compose.yml
git commit -m "fix: mount every sportspress-* plugin individually, not the whole monorepo as one"
```

---

### Task 7: Registration and waitlist fixture scripts

**Files:**
- Create: `config/scripts/fixtures-registration.sh`
- Create: `config/scripts/fixtures-waitlist.sh`
- Depends on: Task 6 (needs `sportspress-league-manager` actually mounted for
  the waitlist fixture to have anything to enable).

**Interfaces:**
- Consumes: a running `sportspress-test` container (via `docker exec`).
- Produces: the same registration/waitlist scenario Tasks 3/4 in Phase 1
  proved in CI, runnable interactively by a human against the sandbox with one
  command each — adapted to create fixtures and print what was created, not to
  assert and exit nonzero the way the CI smoke scripts do.

- [ ] **Step 1: Write `config/scripts/fixtures-registration.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail

# Creates a registration product + a completed order for it, so a human
# driving the sandbox can inspect the exact flow bin/live-env/smoke-registration.php
# in SportsPress-Admin-Tools proves in CI, without re-deriving the fixture by hand.
#
# Usage (from the host): docker exec sportspress-test bash /usr/local/bin/fixtures-registration.sh

wp eval '
$reg_cat       = get_term_by( "name", "Registration", "product_cat" );
$reg_cat_id    = $reg_cat ? $reg_cat->term_id : wp_insert_term( "Registration", "product_cat" )["term_id"];
$player_tag    = get_term_by( "name", "Player", "product_tag" );
$player_tag_id = $player_tag ? $player_tag->term_id : wp_insert_term( "Player", "product_tag" )["term_id"];

$product = new WC_Product_Simple();
$product->set_name( "Player Registration (S2026)" );
$product->set_regular_price( "0" );
$product->set_price( "0" );
$product->set_virtual( true );
$product->set_status( "publish" );
$product->set_category_ids( array( $reg_cat_id ) );
$product->set_tag_ids( array( $player_tag_id ) );
$product_id = $product->save();

$order = wc_create_order();
$order->add_product( wc_get_product( $product_id ), 1 );
$order->set_billing_email( "fixture-player@example.test" );
$order->set_billing_first_name( "Fixture" );
$order->set_billing_last_name( "Player" );
$order->calculate_totals();
$order->save();
$order->update_status( "completed" );

printf( "Product: %d  Order: %d  Status: %s\n", $product_id, $order->get_id(), $order->get_status() );
' --allow-root
```

- [ ] **Step 2: Write `config/scripts/fixtures-waitlist.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail

# Creates the waitlist scenario (target product + waitlist product + a queued
# entry) so a human driving the sandbox can offer/claim/complete it manually
# through the League Dashboard UI or REST, matching what
# bin/live-env/smoke-waitlist.php in SportsPress-Admin-Tools proves in CI.
#
# Usage (from the host): docker exec sportspress-test bash /usr/local/bin/fixtures-waitlist.sh

wp eval '
if ( ! class_exists( "SPLM_Waitlist_Database" ) ) {
	echo "league_waitlist module is not enabled -- run setup-test-data.sh first.\n";
	exit( 1 );
}

$reg_cat         = get_term_by( "name", "Registration", "product_cat" );
$reg_cat_id      = $reg_cat ? $reg_cat->term_id : wp_insert_term( "Registration", "product_cat" )["term_id"];
$player_tag      = get_term_by( "name", "Player", "product_tag" );
$player_tag_id   = $player_tag ? $player_tag->term_id : wp_insert_term( "Player", "product_tag" )["term_id"];
$waitlist_tag    = get_term_by( "name", "Waitlist", "product_tag" );
$waitlist_tag_id = $waitlist_tag ? $waitlist_tag->term_id : wp_insert_term( "Waitlist", "product_tag" )["term_id"];

$target = new WC_Product_Simple();
$target->set_name( "Player Registration (S2027)" );
$target->set_regular_price( "575" );
$target->set_price( "575" );
$target->set_status( "publish" );
$target->set_category_ids( array( $reg_cat_id ) );
$target->set_tag_ids( array( $player_tag_id ) );
$target_id = $target->save();

$waitlist = new WC_Product_Simple();
$waitlist->set_name( "Player Waitlist (S2027)" );
$waitlist->set_regular_price( "0" );
$waitlist->set_price( "0" );
$waitlist->set_virtual( true );
$waitlist->set_status( "publish" );
$waitlist->set_category_ids( array( $reg_cat_id ) );
$waitlist->set_tag_ids( array( $player_tag_id, $waitlist_tag_id ) );
$waitlist_id = $waitlist->save();

$order = wc_create_order();
$order->add_product( wc_get_product( $waitlist_id ), 1 );
$order->set_billing_email( "fixture-waitlist@example.test" );
$order->calculate_totals();
$order->save();
$order->update_status( "completed" );

global $wpdb;
$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}splm_waitlist WHERE email=%s", "fixture-waitlist@example.test" ) );

printf(
	"Target product: %d\nWaitlist product: %d\nWaitlist row: %s\n\nOpen the League Dashboard -> Waitlist tab to offer this entry a spot.\n",
	$target_id,
	$waitlist_id,
	$row ? $row->id : "NOT CREATED -- check league_waitlist is enabled"
);
' --allow-root
```

- [ ] **Step 3: Add both to the Dockerfile's script copy step**

In `Dockerfile`, alongside the existing:

```dockerfile
COPY config/scripts/setup-test-data.sh /usr/local/bin/
COPY config/scripts/start.sh /usr/local/bin/
COPY config/scripts/generate-extra-data.php /usr/local/bin/
RUN chmod +x /usr/local/bin/setup-test-data.sh /usr/local/bin/start.sh
```

add the two new scripts to both the `COPY` and `chmod +x` lines:

```dockerfile
COPY config/scripts/setup-test-data.sh /usr/local/bin/
COPY config/scripts/start.sh /usr/local/bin/
COPY config/scripts/generate-extra-data.php /usr/local/bin/
COPY config/scripts/fixtures-registration.sh /usr/local/bin/
COPY config/scripts/fixtures-waitlist.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/setup-test-data.sh /usr/local/bin/start.sh \
    /usr/local/bin/fixtures-registration.sh /usr/local/bin/fixtures-waitlist.sh
```

- [ ] **Step 4: Build, run both fixtures, confirm they work**

```bash
chmod +x config/scripts/fixtures-registration.sh config/scripts/fixtures-waitlist.sh
docker compose up -d --build --wait
docker exec sportspress-test bash /usr/local/bin/setup-test-data.sh  # ensures league_waitlist is enabled (Task 8)
docker exec sportspress-test bash /usr/local/bin/fixtures-registration.sh
docker exec sportspress-test bash /usr/local/bin/fixtures-waitlist.sh
```

Expected: both print their created IDs with no PHP errors; the waitlist
fixture's "Waitlist row" line shows a real numeric id, not "NOT CREATED".

- [ ] **Step 5: Tear down and commit**

```bash
docker compose down -v
git add Dockerfile config/scripts/fixtures-registration.sh config/scripts/fixtures-waitlist.sh
git commit -m "feat: interactive fixture scripts for the registration and waitlist flows"
```

---

### Task 8: Enable `league_waitlist` and provision the dashboard page by default

**Files:**
- Modify: `config/scripts/setup-test-data.sh`
- Depends on: nothing new (runs against whatever Task 6/7 already mounted).

**Interfaces:**
- Consumes: the running site's `spat_enabled_modules` option and
  `SPLM_Dashboard_Frontend::ensure_page()`.
- Produces: a sandbox where the League Dashboard menu item is reachable out of
  the box, instead of 404ing the way it did on both real hosts before this
  session's fix.

- [ ] **Step 1: Find where modules are currently enabled**

Run: `grep -n "spat_enabled_modules\|league_" config/scripts/setup-test-data.sh`

- [ ] **Step 2: Add `league_waitlist` to whatever module list already exists there**, and provision the dashboard page right after. If the script already has a block enabling `spat_enabled_modules`, add `league_waitlist` to it; otherwise add a new block modeled on this shape, placed after plugins are activated and before the script's own summary/echo lines:

```bash
wp eval '
update_option(
	"spat_enabled_modules",
	array_values(
		array_unique(
			array_merge(
				(array) get_option( "spat_enabled_modules", array() ),
				array( "league_manager_dashboard", "league_waitlist" )
			)
		)
	)
);
do_action( "init" );
if ( class_exists( "SPLM_Dashboard_Frontend" ) ) {
	$page_id = SPLM_Dashboard_Frontend::ensure_page();
	printf( "League Dashboard page: %d (%s)\n", $page_id, $page_id ? get_permalink( $page_id ) : "FAILED" );
}
' --allow-root
```

- [ ] **Step 3: Rebuild and confirm**

```bash
docker compose up -d --build --wait
docker exec sportspress-test wp option get spat_enabled_modules --format=json --allow-root
docker exec sportspress-test wp eval 'echo get_page_by_path( "league-dashboard" ) ? "page exists\n" : "MISSING\n";' --allow-root
```

Expected: `spat_enabled_modules` includes `"league_waitlist"`, and the page
check prints `page exists`.

- [ ] **Step 4: Tear down and commit**

```bash
docker compose down -v
git add config/scripts/setup-test-data.sh
git commit -m "feat: enable league_waitlist and provision the dashboard page by default"
```

- [ ] **Step 5: Push, open the PR, watch its CI (`agent-tests.yml`, `build-image.yml`), merge on green, fix on red**

```bash
git push -u origin feat/live-flow-fixtures
gh pr create --title "feat: mount every plugin, add registration/waitlist fixtures, enable league_waitlist by default" --body "<summarize the three fixes: the mount, the two fixture scripts, the default-enabled module + dashboard page>"
```

Poll checks the same way Phase 1's PR was watched. This is a separate repo
with its own CI — do not conflate its results with Phase 1's PR.

---

## Self-Review Notes

- **Spec coverage:** every section of the design doc maps to a task here —
  matrix/trigger (Task 5), tier 1/2/3 (Tasks 2/3/4), install (Task 1), sandbox
  mount fix / fixtures / default-enable (Tasks 6/7/8).
- **Type consistency:** every script's exit-code contract (0 = pass, 1 = a
  check failed, 2 = a precondition wasn't met) is stated identically across
  Tasks 2, 3, and 4, and matches what Task 5's workflow steps rely on (a
  nonzero `wp eval-file` exit fails that step).
- **No placeholders:** every task step contains complete, runnable code —
  nothing says "add appropriate checks" without showing the checks.
