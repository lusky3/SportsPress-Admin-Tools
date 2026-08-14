# Stat Leaders & Penalty Discipline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Leaders page showing goal/assist/point/penalty leaders overall and per division, plus a penalty-minute watch list that flags players crossing configurable thresholds over a season or a rolling window of weeks, with acknowledgement tracking and a weekly digest email.

**Architecture:** One PHP pass over a season's events builds per-player weekly stat buckets from event box scores (`sp_players` post meta); every leaderboard and threshold check is a pure function over those buckets, so all the logic is unit-testable with no WordPress. Three new endpoints live in their own REST controller rather than extending the existing 5,010-line one. Acknowledgements go in a new table following the existing player-notes pattern.

**Tech Stack:** WordPress plugin PHP (WPCS 3.x), WP-Cron, `@wordpress/element` (React) dashboard built with wp-scripts, standalone echo-based PHP test harness.

**Spec:** `docs/superpowers/specs/2026-08-14-leaders-and-discipline-design.md`

## Global Constraints

- Plugin: `sportspress-league-manager`. REST namespace: `splm/v1`. Path collisions across plugins sharing this namespace are forbidden.
- List endpoints **always** wrap: `{ data, total, page, total_pages }`. Aggregate/report endpoints return the object directly. Writes return `{ success: true, ... }`. See `docs/rest-api-conventions.md`.
- `api.js` unwraps `.data` for list endpoints. Never add `Array.isArray(x) ? x : x?.foo` shims on the client — if a shape is wrong, fix the server.
- Errors are `WP_Error`: `400` malformed input, `403` capability denied, `404` not found, `503` module/dependency unavailable.
- Stat source is the **event box score** `sp_players` meta, never `sp_statistics` (frequently empty).
- Event dates come from `post_date`.
- `sp_season` is hierarchical and `tax_query` defaults to `include_children => true`. Excluding playoffs requires an explicit `'include_children' => false`.
- Week bucket keys are the **Monday of the week as `Y-m-d`**, never ISO year-week integers.
- Every new `SPLM_*` class must be added to `SPLM_Autoloader::build_class_map()` in `includes/class-autoloader.php` or `class_exists()` probes silently return false.
- New test files must be registered in `run-all-tests.sh`.
- All new classes start with `if ( ! defined( 'ABSPATH' ) ) { exit; }` and carry `@author Cody (lusky3)`.
- Threshold defaults: season warning 12 PIM, season critical 18 PIM, 4-week window critical 8 PIM. Default `window_weeks` is 4.

---

## File Structure

**Phase 1 — Leaders**

| File | Responsibility |
|---|---|
| `includes/class-leaders.php` | `SPLM_Leaders` — rank bucket totals into overall + per-division boards. Pure. |
| `includes/class-player-stats-aggregator.php` | `SPLM_Player_Stats_Aggregator` — scan a season's events into weekly buckets; pure static helpers for week keys, totals and windows. |
| `includes/class-leaders-rest.php` | `SPLM_Leaders_REST` — registers and handles all three endpoints; owns the transient cache. |
| `src/dashboard/pages/Leaders.jsx` | Leaders page UI. |

**Phase 2 — Watch**

| File | Responsibility |
|---|---|
| `includes/class-penalty-watch.php` | `SPLM_Penalty_Watch` — tier evaluation + acknowledgement suppression. Pure. |
| `includes/class-discipline-database.php` | `SPLM_Discipline_Database` — acknowledgement table CRUD. |
| `src/dashboard/components/PenaltyWatchCard.jsx` | Dashboard home card. |

**Phase 3 — Digest**

| File | Responsibility |
|---|---|
| `includes/class-discipline-digest.php` | `SPLM_Discipline_Digest` — cron registration, digest assembly, mail. |

**Modified throughout:** `includes/class-autoloader.php`, `includes/class-admin.php` (settings), `sportspress-league-manager.php` (module registration), `src/dashboard/lib/api.js`, `src/dashboard/App.jsx`, `src/dashboard/components/Layout.jsx`, `src/dashboard/pages/Dashboard.jsx`, `run-all-tests.sh`.

---

## Task 1: SPLM_Leaders — ranking

**Files:**
- Create: `sportspress-league-manager/includes/class-leaders.php`
- Test: `sportspress-league-manager/tests/test-leaders.php`
- Modify: `sportspress-league-manager/includes/class-autoloader.php`, `run-all-tests.sh`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `SPLM_Leaders::STAT_KEYS` = `array( 'p', 'g', 'a', 'pim' )`
  - `SPLM_Leaders::rank( array $players, array $stat_keys, int $limit ): array` — returns `array( stat_key => array( array( 'player_id' => int, 'player' => string, 'team' => string, 'division' => string, 'value' => int, 'gp' => int ) ) )`
  - `SPLM_Leaders::by_division( array $players, array $stat_keys, int $limit ): array` — returns `array( array( 'id' => int, 'name' => string, 'leaders' => <rank() shape> ) )`
  - Input `$players` shape: `player_id => array( 'name' => string, 'team' => string, 'team_id' => int, 'div_id' => int, 'div_name' => string, 'totals' => array( 'gp' => int, 'g' => int, 'a' => int, 'pim' => int ) )`

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-leaders.php`:

```php
<?php
/**
 * Standalone tests for SPLM_Leaders.
 *
 * Pure ranking over already-aggregated totals — no WordPress needed.
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-leaders.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

function player( $name, $div_id, $div_name, $gp, $g, $a, $pim ) {
	return array(
		'name'     => $name,
		'team'     => 'Team ' . $name,
		'team_id'  => 1,
		'div_id'   => $div_id,
		'div_name' => $div_name,
		'totals'   => array( 'gp' => $gp, 'g' => $g, 'a' => $a, 'pim' => $pim ),
	);
}

$players = array(
	10 => player( 'Alice', 5, 'Division 1', 18, 12, 9, 4 ),   // p = 21
	11 => player( 'Bob', 5, 'Division 1', 18, 15, 2, 20 ),    // p = 17
	12 => player( 'Cara', 7, 'Division 2', 16, 3, 20, 0 ),    // p = 23
	13 => player( 'Dan', 7, 'Division 2', 4, 0, 0, 0 ),       // nothing
);

echo "\n=== rank() ===\n\n";

$ranked = SPLM_Leaders::rank( $players, SPLM_Leaders::STAT_KEYS, 10 );

assert_test(
	array( 'Cara', 'Alice', 'Bob' ) === array_column( $ranked['p'], 'player' ),
	'points rank descending and are derived as g + a'
);
assert_test( 23 === $ranked['p'][0]['value'], 'top points value is g + a, not a stored field' );
assert_test(
	array( 'Bob', 'Alice', 'Cara' ) === array_column( $ranked['g'], 'player' ),
	'goals rank independently of points'
);
assert_test( 'Bob' === $ranked['pim'][0]['player'] && 20 === $ranked['pim'][0]['value'], 'PIM leader is the most-penalised player' );
assert_test( 2 === count( $ranked['pim'] ), 'players with zero in a category are excluded from that board (Cara and Dan have no PIM)' );
assert_test(
	! in_array( 'Dan', array_column( $ranked['p'], 'player' ), true ),
	'a player with no production appears on no board'
);
assert_test( 18 === $ranked['p'][1]['gp'], 'games played travels with each row' );
assert_test( 'Division 1' === $ranked['p'][1]['division'], 'row carries the division name' );
assert_test( 10 === $ranked['p'][1]['player_id'], 'row carries the player id' );

echo "\n--- ties and limits ---\n\n";

$tied = array(
	20 => player( 'Zoe', 5, 'Division 1', 10, 5, 0, 0 ),
	21 => player( 'Adam', 5, 'Division 1', 10, 5, 0, 0 ),
);
$tie_ranked = SPLM_Leaders::rank( $tied, array( 'g' ), 10 );
assert_test(
	array( 'Adam', 'Zoe' ) === array_column( $tie_ranked['g'], 'player' ),
	'ties break alphabetically so the order is deterministic between requests'
);

$limited = SPLM_Leaders::rank( $players, array( 'p' ), 2 );
assert_test( 2 === count( $limited['p'] ), 'limit slices the board' );
assert_test( 'Cara' === $limited['p'][0]['player'], 'limit keeps the top of the board, not the bottom' );

$zero_limit = SPLM_Leaders::rank( $players, array( 'p' ), 0 );
assert_test( 1 === count( $zero_limit['p'] ), 'a zero limit is coerced to 1 rather than emptying the board' );

$empty = SPLM_Leaders::rank( array(), SPLM_Leaders::STAT_KEYS, 10 );
assert_test(
	array( 'p', 'g', 'a', 'pim' ) === array_keys( $empty ),
	'every requested key is present even with no players, so the client never branches on missing keys'
);
assert_test( array() === $empty['g'], 'empty input yields empty boards' );

echo "\n=== by_division() ===\n\n";

$divs = SPLM_Leaders::by_division( $players, SPLM_Leaders::STAT_KEYS, 10 );

assert_test( 2 === count( $divs ), 'one entry per division that has players' );
assert_test( 'Division 1' === $divs[0]['name'], 'divisions sort by the number in their name' );
assert_test( 5 === $divs[0]['id'], 'division id is carried' );
assert_test(
	array( 'Alice', 'Bob' ) === array_column( $divs[0]['leaders']['p'], 'player' ),
	'a division board contains only that division\'s players'
);
assert_test(
	array( 'Cara' ) === array_column( $divs[1]['leaders']['p'], 'player' ),
	'the second division board is independent'
);

$unassigned = SPLM_Leaders::by_division( array( 30 => player( 'Eve', 0, '', 5, 2, 2, 0 ) ), array( 'p' ), 10 );
assert_test( array() === $unassigned, 'players with no division produce no division board' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php sportspress-league-manager/tests/test-leaders.php`
Expected: FAIL — PHP fatal, `Failed to open stream: ... class-leaders.php`

- [ ] **Step 3: Write the implementation**

Create `sportspress-league-manager/includes/class-leaders.php`:

```php
<?php
/**
 * Ranking of aggregated player totals into leaderboards.
 *
 * Pure: takes the aggregator's output and returns ordered boards. Keeping this
 * free of WordPress is what makes leaderboard behaviour (tie order, limits,
 * zero exclusion) testable without a bootstrap.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Leaders {

	/**
	 * Boards produced by default. 'p' is derived, not stored.
	 */
	const STAT_KEYS = array( 'p', 'g', 'a', 'pim' );

	/**
	 * Rank players into one board per stat key.
	 *
	 * @param array $players   player_id => array( name, team, team_id, div_id, div_name, totals ).
	 * @param array $stat_keys Keys to build boards for.
	 * @param int   $limit     Rows per board; coerced to at least 1.
	 * @return array stat_key => ordered rows.
	 */
	public static function rank( array $players, array $stat_keys, $limit ) {
		$limit = max( 1, (int) $limit );
		$out   = array_fill_keys( $stat_keys, array() );

		foreach ( $players as $player_id => $player ) {
			$totals = isset( $player['totals'] ) && is_array( $player['totals'] ) ? $player['totals'] : array();
			$values = self::values( $totals );

			foreach ( $stat_keys as $key ) {
				// A zero is not an achievement: leaving zeroes out keeps a board
				// of three scorers from listing 300 players.
				if ( empty( $values[ $key ] ) ) {
					continue;
				}

				$out[ $key ][] = array(
					'player_id' => (int) $player_id,
					'player'    => (string) ( $player['name'] ?? '' ),
					'team'      => (string) ( $player['team'] ?? '' ),
					'division'  => (string) ( $player['div_name'] ?? '' ),
					'value'     => (int) $values[ $key ],
					'gp'        => (int) ( $totals['gp'] ?? 0 ),
				);
			}
		}

		foreach ( $out as &$board ) {
			usort( $board, array( __CLASS__, 'compare_rows' ) );
			$board = array_slice( $board, 0, $limit );
		}
		unset( $board );

		return $out;
	}

	/**
	 * Build one board set per division, ordered by the number in the division name.
	 *
	 * @param array $players   Same shape as rank().
	 * @param array $stat_keys Keys to build boards for.
	 * @param int   $limit     Rows per board.
	 * @return array List of array( id, name, leaders ).
	 */
	public static function by_division( array $players, array $stat_keys, $limit ) {
		$grouped = array();
		$names   = array();

		foreach ( $players as $player_id => $player ) {
			$div_id = (int) ( $player['div_id'] ?? 0 );
			if ( ! $div_id ) {
				continue;
			}
			$grouped[ $div_id ][ $player_id ] = $player;
			$names[ $div_id ]                 = (string) ( $player['div_name'] ?? '' );
		}

		$out = array();
		foreach ( $grouped as $div_id => $members ) {
			$out[] = array(
				'id'      => (int) $div_id,
				'name'    => $names[ $div_id ],
				'sort'    => preg_match( '/(\d+)/', $names[ $div_id ], $m ) ? (int) $m[1] : PHP_INT_MAX,
				'leaders' => self::rank( $members, $stat_keys, $limit ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['sort'] !== $b['sort'] ) {
					return $a['sort'] <=> $b['sort'];
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		// 'sort' is an internal ordering aid, not part of the response contract.
		return array_map(
			function ( $division ) {
				unset( $division['sort'] );
				return $division;
			},
			$out
		);
	}

	/**
	 * Resolve every board value for one player, deriving points.
	 *
	 * @param array $totals gp/g/a/pim.
	 * @return array
	 */
	private static function values( array $totals ) {
		$goals   = (int) ( $totals['g'] ?? 0 );
		$assists = (int) ( $totals['a'] ?? 0 );

		return array(
			'g'   => $goals,
			'a'   => $assists,
			'pim' => (int) ( $totals['pim'] ?? 0 ),
			'p'   => $goals + $assists,
		);
	}

	/**
	 * Order rows by value descending, breaking ties by name.
	 *
	 * The tie-break is not cosmetic: usort() is not stable across PHP versions
	 * for equal elements, so without it two players on equal goals could swap
	 * places between requests and make the board look like it was changing.
	 *
	 * @param array $a Row.
	 * @param array $b Row.
	 * @return int
	 */
	private static function compare_rows( $a, $b ) {
		if ( $a['value'] !== $b['value'] ) {
			return $b['value'] <=> $a['value'];
		}
		return strcasecmp( $a['player'], $b['player'] );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php sportspress-league-manager/tests/test-leaders.php`
Expected: PASS, `Failed: 0`

- [ ] **Step 5: Register the class and the test**

In `sportspress-league-manager/includes/class-autoloader.php`, add to the `self::$class_map` array (keep alphabetical alignment with the existing entries):

```php
			'SPLM_Leaders'               => $base . 'class-leaders.php',
```

In `run-all-tests.sh`, after the `test-rest-api.php` line:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-leaders.php"
```

- [ ] **Step 6: Run the whole suite**

Run: `./run-all-tests.sh`
Expected: all suites pass, including the new one.

- [ ] **Step 7: Commit**

```bash
git add sportspress-league-manager/includes/class-leaders.php \
        sportspress-league-manager/tests/test-leaders.php \
        sportspress-league-manager/includes/class-autoloader.php \
        run-all-tests.sh
git commit -m "feat(leaders): rank player totals into overall and per-division boards"
```

---

## Task 2: SPLM_Player_Stats_Aggregator — weekly buckets

**Files:**
- Create: `sportspress-league-manager/includes/class-player-stats-aggregator.php`
- Test: `sportspress-league-manager/tests/test-player-stats-aggregator.php`
- Modify: `sportspress-league-manager/includes/class-autoloader.php`, `run-all-tests.sh`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `SPLM_Player_Stats_Aggregator::week_key( string $datetime ): string` — Monday of that week, `Y-m-d`
  - `SPLM_Player_Stats_Aggregator::totals( array $weeks ): array` — `array( 'gp', 'g', 'a', 'pim' )`
  - `SPLM_Player_Stats_Aggregator::window_totals( array $weeks, string $cutoff ): array` — same shape, buckets with key `>= $cutoff`
  - `SPLM_Player_Stats_Aggregator::window_cutoff( int $weeks_back, string $today, string $season_start ): string`
  - `SPLM_Player_Stats_Aggregator::season_start( array $players ): string` — earliest week key across all players, `''` when empty
  - `SPLM_Player_Stats_Aggregator::for_season( int $season_id, array $args = array() ): array` — `$args['include_playoffs']` bool, default false. Returns the `$players` shape Task 1 consumes, plus a `weeks` key: `player_id => array( 'name', 'team', 'team_id', 'div_id', 'div_name', 'weeks' => array( 'Y-m-d' => array( 'gp', 'g', 'a', 'pim' ) ), 'totals' => array( ... ) )`

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-player-stats-aggregator.php`:

```php
<?php
/**
 * Standalone tests for SPLM_Player_Stats_Aggregator's pure helpers.
 *
 * for_season() scans WordPress and is verified against staging rather than
 * mocked; what is pinned down here is the date and window arithmetic, which is
 * where the bugs actually live — particularly across a calendar-year boundary,
 * exactly where a winter season sits.
 */

define( 'ABSPATH', __DIR__ );

// The helpers under test use WordPress's time constants; the pure code path
// needs no other part of WordPress.
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

require_once __DIR__ . '/../includes/class-player-stats-aggregator.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

$agg = 'SPLM_Player_Stats_Aggregator';

echo "\n=== week_key() ===\n\n";

assert_test( '2026-03-16' === $agg::week_key( '2026-03-22 21:00:00' ), 'a Sunday game buckets into the Monday that began its week' );
assert_test( '2026-03-16' === $agg::week_key( '2026-03-16 19:30:00' ), 'a Monday game buckets into itself' );
assert_test(
	$agg::week_key( '2026-03-16 19:30:00' ) === $agg::week_key( '2026-03-22 21:00:00' ),
	'two games in the same week share a bucket'
);
assert_test(
	$agg::week_key( '2026-03-22 21:00:00' ) !== $agg::week_key( '2026-03-23 21:00:00' ),
	'consecutive days either side of Monday fall in different buckets'
);
assert_test( '2025-12-29' === $agg::week_key( '2026-01-01 20:00:00' ), 'a New Year game buckets into the week that started in the previous year' );

echo "\n=== totals() ===\n\n";

$weeks = array(
	'2026-01-05' => array( 'gp' => 1, 'g' => 2, 'a' => 1, 'pim' => 2 ),
	'2026-01-12' => array( 'gp' => 1, 'g' => 0, 'a' => 3, 'pim' => 0 ),
	'2026-02-02' => array( 'gp' => 2, 'g' => 1, 'a' => 0, 'pim' => 5 ),
);

$t = $agg::totals( $weeks );
assert_test( 4 === $t['gp'], 'games played sum across buckets' );
assert_test( 3 === $t['g'] && 4 === $t['a'] && 7 === $t['pim'], 'every stat sums across buckets' );
assert_test(
	array( 'gp' => 0, 'g' => 0, 'a' => 0, 'pim' => 0 ) === $agg::totals( array() ),
	'no buckets totals to zeroes, not an empty array'
);

echo "\n=== window_totals() ===\n\n";

$w = $agg::window_totals( $weeks, '2026-01-12' );
assert_test( 1 === $w['g'] && 3 === $w['a'] && 5 === $w['pim'], 'only buckets on or after the cutoff are counted' );
assert_test( 3 === $w['gp'], 'window games played excludes buckets before the cutoff' );
assert_test( 7 === $agg::window_totals( $weeks, '2026-01-05' )['pim'], 'a cutoff at the first bucket includes everything' );
assert_test( 0 === $agg::window_totals( $weeks, '2026-03-01' )['pim'], 'a cutoff past every bucket counts nothing' );
assert_test(
	$agg::window_totals( $weeks, '2026-01-05' ) === $agg::totals( $weeks ),
	'a window covering the whole season equals the season total'
);

echo "\n=== window_cutoff() ===\n\n";

assert_test(
	'2026-03-02' === $agg::window_cutoff( 4, '2026-03-25', '2025-09-22' ),
	'a 4-week window from a Wednesday starts on the Monday 3 weeks earlier (4 weeks inclusive)'
);
assert_test(
	'2026-03-23' === $agg::window_cutoff( 1, '2026-03-25', '2025-09-22' ),
	'a 1-week window is the current week only'
);
assert_test(
	'2025-12-22' === $agg::window_cutoff( 4, '2026-01-14', '2025-09-22' ),
	'a window reaching back over New Year does not break'
);
assert_test(
	'2025-09-22' === $agg::window_cutoff( 52, '2026-01-14', '2025-09-22' ),
	'a window longer than the season is clamped to the season start, so a new season starts everyone at zero'
);
assert_test(
	'2025-09-22' === $agg::window_cutoff( 4, '2025-09-30', '2025-09-22' ),
	'early in a season the window is clamped rather than reaching into the previous one'
);

echo "\n=== season_start() ===\n\n";

assert_test(
	'2026-01-05' === $agg::season_start( array( array( 'weeks' => $weeks ), array( 'weeks' => array( '2026-02-09' => array() ) ) ) ),
	'season start is the earliest week across every player, not just the first one'
);
assert_test( '' === $agg::season_start( array() ), 'no players yields an empty season start' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php sportspress-league-manager/tests/test-player-stats-aggregator.php`
Expected: FAIL — PHP fatal, `Failed to open stream: ... class-player-stats-aggregator.php`

- [ ] **Step 3: Write the implementation**

Create `sportspress-league-manager/includes/class-player-stats-aggregator.php`:

```php
<?php
/**
 * Aggregates a season's event box scores into per-player weekly buckets.
 *
 * Stats come from each event's sp_players meta, NOT from a player's
 * sp_statistics meta: the dashboard and score-sheet writers populate the event
 * box score, and SportsPress does not reliably recompute the per-player
 * aggregate, so sp_statistics is frequently empty even when stats exist.
 *
 * Buckets are keyed by the Monday of the event's week. Year-week integers were
 * rejected because 202552 + 1 != 202601, and a winter season sits exactly on
 * that boundary.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Player_Stats_Aggregator {

	/**
	 * Upper bound on events scanned in one pass. A season runs ~380 events;
	 * this is a runaway guard, not an expected limit.
	 */
	const MAX_EVENTS = 5000;

	/**
	 * The Monday that began the week containing $datetime.
	 *
	 * @param string $datetime Any strtotime-parsable date.
	 * @return string Y-m-d.
	 */
	public static function week_key( $datetime ) {
		$timestamp = strtotime( (string) $datetime );
		if ( ! $timestamp ) {
			return '';
		}

		// 'N' is 1 (Mon) through 7 (Sun), so subtracting N-1 days always lands
		// on that week's Monday regardless of locale start-of-week settings.
		$offset = (int) gmdate( 'N', $timestamp ) - 1;

		return gmdate( 'Y-m-d', $timestamp - ( $offset * DAY_IN_SECONDS ) );
	}

	/**
	 * Sum every bucket.
	 *
	 * @param array $weeks week => array( gp, g, a, pim ).
	 * @return array
	 */
	public static function totals( array $weeks ) {
		return self::sum( $weeks, '' );
	}

	/**
	 * Sum buckets on or after a cutoff week.
	 *
	 * @param array  $weeks  week => array( gp, g, a, pim ).
	 * @param string $cutoff Y-m-d week key.
	 * @return array
	 */
	public static function window_totals( array $weeks, $cutoff ) {
		return self::sum( $weeks, (string) $cutoff );
	}

	/**
	 * The first week key included by a window of $weeks_back calendar weeks.
	 *
	 * The window is inclusive of the current week, so 4 weeks back from any day
	 * in week W covers W-3..W. The result is clamped to the season's first week
	 * so a window early in a season cannot reach into the previous one.
	 *
	 * @param int    $weeks_back   Window length in weeks; coerced to at least 1.
	 * @param string $today        Reference date (Y-m-d or full datetime).
	 * @param string $season_start Week key of the season's first event.
	 * @return string Y-m-d week key.
	 */
	public static function window_cutoff( $weeks_back, $today, $season_start ) {
		$weeks_back = max( 1, (int) $weeks_back );
		$this_week  = self::week_key( $today );
		if ( '' === $this_week ) {
			return (string) $season_start;
		}

		$cutoff = gmdate(
			'Y-m-d',
			strtotime( $this_week ) - ( ( $weeks_back - 1 ) * WEEK_IN_SECONDS )
		);

		$season_start = (string) $season_start;
		if ( '' !== $season_start && $cutoff < $season_start ) {
			return $season_start;
		}

		return $cutoff;
	}

	/**
	 * Scan a season's events into per-player weekly buckets.
	 *
	 * @param int   $season_id sp_season term id.
	 * @param array $args      include_playoffs (bool, default false).
	 * @return array player_id => array( name, team, team_id, div_id, div_name, weeks, totals ).
	 */
	public static function for_season( $season_id, array $args = array() ) {
		$season_id        = (int) $season_id;
		$include_playoffs = ! empty( $args['include_playoffs'] );

		$event_ids = self::event_ids( $season_id, $include_playoffs );
		if ( ! $event_ids ) {
			return array();
		}
		update_meta_cache( 'post', $event_ids );

		$maps      = self::division_maps( $season_id );
		$buckets   = array();
		$team_tally = array();

		foreach ( $event_ids as $event_id ) {
			$box = get_post_meta( $event_id, 'sp_players', true );
			if ( ! is_array( $box ) ) {
				continue;
			}

			$post = get_post( $event_id );
			$week = self::week_key( $post ? $post->post_date : '' );
			if ( '' === $week ) {
				continue;
			}

			foreach ( $box as $team_id => $rows ) {
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $player_id => $stats ) {
					$player_id = (int) $player_id;
					// Key 0 is SportsPress's reserved row, not a player.
					if ( ! $player_id || ! is_array( $stats ) ) {
						continue;
					}

					if ( ! isset( $buckets[ $player_id ][ $week ] ) ) {
						$buckets[ $player_id ][ $week ] = array(
							'gp'  => 0,
							'g'   => 0,
							'a'   => 0,
							'pim' => 0,
						);
					}

					$buckets[ $player_id ][ $week ]['gp']  += 1;
					$buckets[ $player_id ][ $week ]['g']   += (int) ( $stats['g'] ?? 0 );
					$buckets[ $player_id ][ $week ]['a']   += (int) ( $stats['a'] ?? 0 );
					$buckets[ $player_id ][ $week ]['pim'] += (int) ( $stats['pim'] ?? 0 );

					$team_tally[ $player_id ][ (int) $team_id ] = ( $team_tally[ $player_id ][ (int) $team_id ] ?? 0 ) + 1;
				}
			}
		}

		$out = array();
		foreach ( $buckets as $player_id => $weeks ) {
			ksort( $weeks );
			$team_id = self::attributed_team( $player_id, $maps, $team_tally );
			$div_id  = (int) ( $maps['team_to_div'][ $team_id ] ?? 0 );

			$out[ $player_id ] = array(
				'name'     => splm_display_title( $player_id ),
				'team_id'  => $team_id,
				'team'     => $team_id ? splm_display_title( $team_id ) : '',
				'div_id'   => $div_id,
				'div_name' => (string) ( $maps['div_names'][ $div_id ] ?? '' ),
				'weeks'    => $weeks,
				'totals'   => self::totals( $weeks ),
			);
		}

		return $out;
	}

	/**
	 * The earliest week key present across every player's buckets.
	 *
	 * @param array $players Output of for_season().
	 * @return string Y-m-d, or '' when there is nothing.
	 */
	public static function season_start( array $players ) {
		$earliest = '';
		foreach ( $players as $player ) {
			foreach ( array_keys( $player['weeks'] ) as $week ) {
				if ( '' === $earliest || $week < $earliest ) {
					$earliest = $week;
				}
			}
		}

		return $earliest;
	}

	/**
	 * Event ids for a season.
	 *
	 * sp_season is hierarchical and tax_query defaults to include_children,
	 * which silently sweeps playoff games into a "regular season" query. The
	 * flag is therefore set explicitly in both directions rather than left to
	 * the default.
	 *
	 * @param int  $season_id        Term id.
	 * @param bool $include_playoffs Whether to include child (playoff) terms.
	 * @return array
	 */
	private static function event_ids( $season_id, $include_playoffs ) {
		return get_posts(
			array(
				'post_type'      => 'sp_event',
				'posts_per_page' => self::MAX_EVENTS,
				'post_status'    => array( 'publish', 'future' ),
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'sp_season',
						'terms'            => $season_id,
						'include_children' => (bool) $include_playoffs,
					),
				),
			)
		);
	}

	/**
	 * team_to_div, div_names, and the season's roster mapping.
	 *
	 * Mirrors the derivation already used by the season summary and division
	 * balance screens: regular-season league tables for the season, collapsed by
	 * their sp_league term, with playoff tables skipped.
	 *
	 * @param int $season_id Term id.
	 * @return array
	 */
	private static function division_maps( $season_id ) {
		$team_to_div = array();
		$div_names   = array();
		$roster      = array();

		$table_ids = get_posts(
			array(
				'post_type'      => 'sp_table',
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'sp_season',
						'terms'            => $season_id,
						'include_children' => false,
					),
				),
			)
		);

		foreach ( $table_ids as $table_id ) {
			$league = wp_get_object_terms( $table_id, 'sp_league' );
			if ( is_wp_error( $league ) || empty( $league ) ) {
				continue;
			}
			$league                          = $league[0];
			$div_names[ $league->term_id ]   = $league->name;

			if ( ! class_exists( 'SP_League_Table' ) ) {
				continue;
			}
			$table = new SP_League_Table( $table_id );
			foreach ( (array) $table->data() as $team_id => $unused ) {
				if ( is_numeric( $team_id ) && (int) $team_id ) {
					$team_to_div[ (int) $team_id ] = (int) $league->term_id;
				}
			}
		}

		// Roster mapping: sp_leagues[league][season] => team. This is the
		// season-scoped source; sp_current_team is not season-scoped and would
		// mis-attribute anyone who has since moved.
		$player_ids = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => self::MAX_EVENTS,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'sp_season',
						'terms'            => $season_id,
						'include_children' => true,
					),
				),
			)
		);
		if ( $player_ids ) {
			update_meta_cache( 'post', $player_ids );
		}
		foreach ( $player_ids as $player_id ) {
			$leagues = get_post_meta( $player_id, 'sp_leagues', true );
			if ( ! is_array( $leagues ) ) {
				continue;
			}
			foreach ( $leagues as $season_map ) {
				if ( is_array( $season_map ) && ! empty( $season_map[ $season_id ] ) ) {
					$roster[ (int) $player_id ] = (int) $season_map[ $season_id ];
					break;
				}
			}
		}

		return array(
			'team_to_div' => $team_to_div,
			'div_names'   => $div_names,
			'roster'      => $roster,
		);
	}

	/**
	 * The team a player counts for.
	 *
	 * Prefers the season roster mapping; falls back to the team they most often
	 * appeared for, so a player with no roster row still lands on a board
	 * instead of vanishing.
	 *
	 * @param int   $player_id  Player post id.
	 * @param array $maps       Output of division_maps().
	 * @param array $team_tally player_id => team_id => appearances.
	 * @return int
	 */
	private static function attributed_team( $player_id, array $maps, array $team_tally ) {
		if ( ! empty( $maps['roster'][ $player_id ] ) ) {
			return (int) $maps['roster'][ $player_id ];
		}

		$tally = $team_tally[ $player_id ] ?? array();
		if ( ! $tally ) {
			return 0;
		}
		arsort( $tally );

		return (int) array_key_first( $tally );
	}

	/**
	 * Sum buckets, optionally only those on or after a cutoff.
	 *
	 * @param array  $weeks  week => stats.
	 * @param string $cutoff Y-m-d, or '' for everything.
	 * @return array
	 */
	private static function sum( array $weeks, $cutoff ) {
		$out = array(
			'gp'  => 0,
			'g'   => 0,
			'a'   => 0,
			'pim' => 0,
		);

		foreach ( $weeks as $week => $stats ) {
			if ( '' !== $cutoff && (string) $week < $cutoff ) {
				continue;
			}
			foreach ( $out as $key => $unused ) {
				$out[ $key ] += (int) ( $stats[ $key ] ?? 0 );
			}
		}

		return $out;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php sportspress-league-manager/tests/test-player-stats-aggregator.php`
Expected: PASS, `Failed: 0`

- [ ] **Step 5: Register the class and the test**

In `class-autoloader.php`:

```php
			'SPLM_Player_Stats_Aggregator' => $base . 'class-player-stats-aggregator.php',
```

In `run-all-tests.sh`, after the `test-leaders.php` line:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-player-stats-aggregator.php"
```

- [ ] **Step 6: Run the whole suite**

Run: `./run-all-tests.sh`
Expected: all suites pass.

- [ ] **Step 7: Commit**

```bash
git add sportspress-league-manager/includes/class-player-stats-aggregator.php \
        sportspress-league-manager/tests/test-player-stats-aggregator.php \
        sportspress-league-manager/includes/class-autoloader.php \
        run-all-tests.sh
git commit -m "feat(leaders): aggregate season box scores into weekly player buckets"
```

---

## Task 3: GET /leaders endpoint

**Files:**
- Create: `sportspress-league-manager/includes/class-leaders-rest.php`
- Modify: `sportspress-league-manager/includes/class-autoloader.php`, `sportspress-league-manager/sportspress-league-manager.php`

**Interfaces:**
- Consumes: `SPLM_Leaders::rank()`, `SPLM_Leaders::by_division()`, `SPLM_Leaders::STAT_KEYS`, `SPLM_Player_Stats_Aggregator::for_season()`, `::window_cutoff()`, `::window_totals()`, `::season_start()`.
- Produces:
  - `SPLM_Leaders_REST` — instantiated with `new SPLM_Leaders_REST()`, hooks its own `rest_api_init`.
  - `GET /splm/v1/leaders` returning the aggregate object documented in the spec.
  - `SPLM_Leaders_REST::flush_cache()` — public static, called by later tasks and by write hooks.

- [ ] **Step 1: Write the controller**

Create `sportspress-league-manager/includes/class-leaders-rest.php`:

```php
<?php
/**
 * REST controller for stat leaders and penalty discipline.
 *
 * Deliberately a separate controller: class-rest-api.php is past 5,000 lines and
 * adding to it makes review harder for everyone. sportspress-score-sheets sets
 * the same precedent with its own class-dashboard-rest.php.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Leaders_REST {

	const NAMESPACE_V1 = 'splm/v1';
	const CACHE_GROUP  = 'splm_leaders_cache_keys';
	const CACHE_TTL    = 900; // 15 minutes.

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register every route this controller owns.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/leaders',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_leaders' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'season'           => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'division'         => array( 'sanitize_callback' => 'absint' ),
					'limit'            => array( 'sanitize_callback' => 'absint' ),
					'window_weeks'     => array( 'sanitize_callback' => 'absint' ),
					'include_playoffs' => array( 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			)
		);
	}

	/**
	 * Read access: any dashboard reader may see leaderboards.
	 *
	 * @return bool|WP_Error
	 */
	public function can_read() {
		if ( ! SPLM_Capabilities::can_read() ) {
			return new WP_Error( 'forbidden', __( 'You cannot view league data.', 'sportspress-league-manager' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * GET /leaders — overall and per-division boards for one season.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_leaders( $request ) {
		$season_id = absint( $request->get_param( 'season' ) );
		$season    = get_term( $season_id, 'sp_season' );
		if ( ! $season || is_wp_error( $season ) ) {
			return new WP_Error( 'invalid_season', __( 'Season not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$limit            = absint( $request->get_param( 'limit' ) );
		$limit            = $limit ? $limit : (int) get_option( 'splm_report_leader_count', 10 );
		$window_weeks     = absint( $request->get_param( 'window_weeks' ) );
		$include_playoffs = (bool) $request->get_param( 'include_playoffs' );
		$division         = absint( $request->get_param( 'division' ) );

		$cache_key = self::cache_key(
			'leaders',
			array( $season_id, $division, $limit, $window_weeks, (int) $include_playoffs )
		);
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$players = SPLM_Player_Stats_Aggregator::for_season(
			$season_id,
			array( 'include_playoffs' => $include_playoffs )
		);

		// A window request re-bases every player's totals onto the window before
		// ranking, so the boards answer "who has been hot lately" rather than
		// "who leads the season".
		if ( $window_weeks ) {
			$cutoff = SPLM_Player_Stats_Aggregator::window_cutoff(
				$window_weeks,
				current_time( 'Y-m-d' ),
				SPLM_Player_Stats_Aggregator::season_start( $players )
			);
			foreach ( $players as $player_id => $player ) {
				$players[ $player_id ]['totals'] = SPLM_Player_Stats_Aggregator::window_totals( $player['weeks'], $cutoff );
			}
		}

		if ( $division ) {
			$players = array_filter(
				$players,
				function ( $player ) use ( $division ) {
					return (int) $player['div_id'] === $division;
				}
			);
		}

		$payload = array(
			'season'    => array(
				'id'   => (int) $season->term_id,
				'name' => $season->name,
			),
			'scope'     => array(
				'window_weeks'     => $window_weeks ? $window_weeks : null,
				'include_playoffs' => $include_playoffs,
				'division'         => $division ? $division : null,
			),
			'overall'   => SPLM_Leaders::rank( $players, SPLM_Leaders::STAT_KEYS, $limit ),
			'divisions' => SPLM_Leaders::by_division( $players, SPLM_Leaders::STAT_KEYS, $limit ),
		);

		self::remember( $cache_key );
		set_transient( $cache_key, $payload, self::CACHE_TTL );

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Build a namespaced transient key from its inputs.
	 *
	 * @param string $prefix Logical cache name.
	 * @param array  $parts  Values that change the result.
	 * @return string
	 */
	public static function cache_key( $prefix, array $parts ) {
		return 'splm_' . $prefix . '_' . md5( wp_json_encode( $parts ) );
	}

	/**
	 * Record a key so flush_cache() can delete it later.
	 *
	 * Transients have no wildcard delete, so the keys in use are tracked in an
	 * option. The TTL is still the real safety net: if this list is ever lost,
	 * entries expire on their own rather than serving stale discipline data
	 * forever.
	 *
	 * @param string $key Transient key.
	 */
	private static function remember( $key ) {
		$keys = (array) get_option( self::CACHE_GROUP, array() );
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::CACHE_GROUP, $keys, false );
		}
	}

	/**
	 * Drop every cached leaders/watch payload.
	 */
	public static function flush_cache() {
		foreach ( (array) get_option( self::CACHE_GROUP, array() ) as $key ) {
			delete_transient( $key );
		}
		update_option( self::CACHE_GROUP, array(), false );
	}
}
```

- [ ] **Step 2: Register the class**

In `class-autoloader.php`:

```php
			'SPLM_Leaders_REST'          => $base . 'class-leaders-rest.php',
```

- [ ] **Step 3: Instantiate it and wire cache invalidation**

In `sportspress-league-manager.php`, inside `load_enabled_modules()`, immediately after `new SPLM_REST_API();`:

```php
		new SPLM_Leaders_REST();

		// Any write that can change a box score invalidates the cached boards.
		// The 15-minute TTL remains the backstop for a path missed here.
		add_action( 'save_post_sp_event', array( 'SPLM_Leaders_REST', 'flush_cache' ) );
		add_action( 'splm_game_players_saved', array( 'SPLM_Leaders_REST', 'flush_cache' ) );
		add_action( 'spss_event_written', array( 'SPLM_Leaders_REST', 'flush_cache' ) );
```

- [ ] **Step 4: Verify the two upstream hooks exist, and add them if not**

Run: `grep -rn "splm_game_players_saved\|spss_event_written" sportspress-league-manager/includes/ sportspress-score-sheets/includes/`

If either action is missing, add it at the end of the corresponding write method — `do_action( 'splm_game_players_saved', $event_id );` in the game-players save handler in `sportspress-league-manager/includes/class-rest-api.php`, and `do_action( 'spss_event_written', $event_id );` in `sportspress-score-sheets/includes/class-sportspress-writer.php` after the box score is written. `save_post_sp_event` covers the WP admin path and needs nothing.

- [ ] **Step 5: Verify against staging**

Deploy the plugin to the staging container and call the endpoint for W2025-26 (term 654):

```bash
ssh tikal 'docker exec -u www-data staging-wp sh -c "cd /var/www/html && wp eval \"
\\\$r = new WP_REST_Request( \\\"GET\\\", \\\"/splm/v1/leaders\\\" );
\\\$r->set_param( \\\"season\\\", 654 );
\\\$d = rest_do_request( \\\$r )->get_data();
echo \\\"overall pim leader: \\\" . \\\$d[\\\"overall\\\"][\\\"pim\\\"][0][\\\"player\\\"] . \\\" \\\" . \\\$d[\\\"overall\\\"][\\\"pim\\\"][0][\\\"value\\\"] . PHP_EOL;
echo \\\"divisions: \\\" . count( \\\$d[\\\"divisions\\\"] ) . PHP_EOL;
\" --allow-root'
```

Expected: a PIM leader with a value in the mid-to-high teens and a non-zero division count. Excluding playoffs, the season PIM leader should be at or just under 20.

- [ ] **Step 6: Commit**

```bash
git add sportspress-league-manager/includes/class-leaders-rest.php \
        sportspress-league-manager/includes/class-autoloader.php \
        sportspress-league-manager/sportspress-league-manager.php
git commit -m "feat(leaders): add GET /leaders with per-division boards and caching"
```

---

## Task 4: Leaders page UI

**Files:**
- Create: `sportspress-league-manager/src/dashboard/pages/Leaders.jsx`
- Modify: `sportspress-league-manager/src/dashboard/lib/api.js`, `src/dashboard/App.jsx`, `src/dashboard/components/Layout.jsx`

**Interfaces:**
- Consumes: `GET /splm/v1/leaders`.
- Produces: `fetchLeaders( seasonId, opts )` in `api.js`; a `leaders` page id in `PAGES` and the nav.

- [ ] **Step 1: Add the API client function**

In `src/dashboard/lib/api.js`, next to `fetchSeasonSummary`:

```js
export function fetchLeaders( seasonId, { division = 0, limit = 0, windowWeeks = 0, includePlayoffs = false } = {} ) {
	const params = new URLSearchParams( { season: seasonId } );
	if ( division ) params.set( 'division', division );
	if ( limit ) params.set( 'limit', limit );
	if ( windowWeeks ) params.set( 'window_weeks', windowWeeks );
	if ( includePlayoffs ) params.set( 'include_playoffs', '1' );
	return apiFetch( { path: `/splm/v1/leaders?${ params.toString() }` } );
}
```

Match the surrounding style: if the other functions in this file build paths with `addQueryArgs` or a shared helper rather than `URLSearchParams`, use that instead — read the neighbours before writing.

- [ ] **Step 2: Create the page**

Create `sportspress-league-manager/src/dashboard/pages/Leaders.jsx`:

```jsx
import { useState, useEffect } from '@wordpress/element';
import HelpLink from '../components/HelpLink';
import { fetchLeaders } from '../lib/api';

const STAT_LABELS = { p: 'Points', g: 'Goals', a: 'Assists', pim: 'Penalty Minutes' };
const STAT_ORDER = [ 'p', 'g', 'a', 'pim' ];

function Board( { statKey, rows } ) {
	if ( ! rows || rows.length === 0 ) return null;
	return (
		<section className="splm-card">
			<h3>{ STAT_LABELS[ statKey ] }</h3>
			<div className="splm-table-wrapper">
				<table className="splm-table">
					<thead>
						<tr>
							<th scope="col">#</th>
							<th scope="col">Player</th>
							<th scope="col">Team</th>
							<th scope="col">GP</th>
							<th scope="col">{ STAT_LABELS[ statKey ] }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row, i ) => (
							<tr key={ row.player_id }>
								<td>{ i + 1 }</td>
								<td>{ row.player }</td>
								<td>{ row.team }</td>
								<td>{ row.gp }</td>
								<td className="splm-table__pts">{ row.value }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</section>
	);
}

export default function Leaders( { season } ) {
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ division, setDivision ] = useState( 0 );
	const [ windowWeeks, setWindowWeeks ] = useState( 0 );
	const [ includePlayoffs, setIncludePlayoffs ] = useState( false );

	useEffect( () => {
		if ( ! season ) return undefined;
		let cancelled = false;
		setLoading( true );
		setError( '' );
		fetchLeaders( season, { windowWeeks, includePlayoffs } )
			.then( ( d ) => {
				if ( cancelled ) return;
				setData( d );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err?.message || 'Failed to load leaders' );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [ season, windowWeeks, includePlayoffs ] );

	if ( ! season ) {
		return (
			<div className="splm-leaders">
				<h2>Leaders <HelpLink topic="leaders" /></h2>
				<p className="splm-empty">Select a season to see leaders.</p>
			</div>
		);
	}

	const divisions = data?.divisions || [];
	const active = division
		? divisions.find( ( d ) => d.id === division )?.leaders
		: data?.overall;

	return (
		<div className="splm-leaders">
			<h2>Leaders{ data ? ` — ${ data.season.name }` : '' } <HelpLink topic="leaders" /></h2>
			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			<section className="splm-card">
				<label htmlFor="splm-leaders-division">Division</label>
				<select
					id="splm-leaders-division"
					value={ division }
					onChange={ ( e ) => setDivision( Number( e.target.value ) ) }
				>
					<option value={ 0 }>All divisions</option>
					{ divisions.map( ( d ) => (
						<option key={ d.id } value={ d.id }>{ d.name }</option>
					) ) }
				</select>

				<label htmlFor="splm-leaders-window">Range</label>
				<select
					id="splm-leaders-window"
					value={ windowWeeks }
					onChange={ ( e ) => setWindowWeeks( Number( e.target.value ) ) }
				>
					<option value={ 0 }>Full season</option>
					<option value={ 4 }>Last 4 weeks</option>
					<option value={ 8 }>Last 8 weeks</option>
				</select>

				<label className="splm-checkbox">
					<input
						type="checkbox"
						checked={ includePlayoffs }
						onChange={ () => setIncludePlayoffs( ( v ) => ! v ) }
					/>
					Include playoff games
				</label>
			</section>

			{ loading && <div className="splm-loading">Loading leaders...</div> }

			{ ! loading && active && STAT_ORDER.every( ( k ) => ! active[ k ]?.length ) && (
				<section className="splm-card">
					<p className="splm-muted">No player statistics recorded for this selection yet. Leaders appear once game player-stats are entered.</p>
				</section>
			) }

			{ ! loading && active && STAT_ORDER.map( ( k ) => (
				<Board key={ k } statKey={ k } rows={ active[ k ] } />
			) ) }
		</div>
	);
}
```

- [ ] **Step 3: Wire it into routing and nav**

In `src/dashboard/App.jsx`, add the import beside the other page imports:

```jsx
import Leaders from './pages/Leaders';
```

and the entry in `PAGES`, after `'team-compare': TeamComparison,`:

```jsx
	leaders: Leaders,
```

In `src/dashboard/components/Layout.jsx`, add to the nav array after the `team-compare` entry:

```jsx
	{ id: 'leaders', label: 'Leaders', icon: 'season-report' },
```

Reuse the `season-report` icon unless `components/icons.js` already exports something more apt — check it first and add a dedicated icon only if the existing set has an obvious fit.

- [ ] **Step 4: Build and check**

Run: `cd sportspress-league-manager && npm run build`
Expected: build succeeds with no errors.

- [ ] **Step 5: Verify in the browser on staging**

Deploy, open the dashboard, navigate to Leaders. Confirm: boards render for the current season; the division selector filters; "Last 4 weeks" changes the values; the playoff checkbox changes the totals.

- [ ] **Step 6: Commit**

```bash
git add sportspress-league-manager/src/dashboard/pages/Leaders.jsx \
        sportspress-league-manager/src/dashboard/lib/api.js \
        sportspress-league-manager/src/dashboard/App.jsx \
        sportspress-league-manager/src/dashboard/components/Layout.jsx \
        sportspress-league-manager/build
git commit -m "feat(leaders): add Leaders page with division and window filters"
```

Note: `build/` is committed in this repo and CI enforces that it matches a fresh build — always commit the rebuilt assets with the source change.

---

## Task 5: SPLM_Penalty_Watch — tier evaluation

**Files:**
- Create: `sportspress-league-manager/includes/class-penalty-watch.php`
- Test: `sportspress-league-manager/tests/test-penalty-watch.php`
- Modify: `sportspress-league-manager/includes/class-autoloader.php`, `run-all-tests.sh`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `SPLM_Penalty_Watch::default_tiers(): array` — the three seeded tiers
  - `SPLM_Penalty_Watch::evaluate( array $totals, array $tiers, array $acks ): array` where `$totals = array( 'season' => int, 'window' => int )`, `$acks = array( tier_key => value_at_ack )`; returns a list of `array( 'tier_key', 'scope', 'severity', 'minutes', 'value' )`, criticals first, at most one per scope
  - `SPLM_Penalty_Watch::sanitize_tiers( array $raw ): array`

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-penalty-watch.php`:

```php
<?php
/**
 * Standalone tests for SPLM_Penalty_Watch.
 *
 * Tier evaluation and acknowledgement suppression decide whether this feature
 * is useful or is ignored after week three, so the suppression rules are pinned
 * down here in detail.
 */

define( 'ABSPATH', __DIR__ );

// sanitize_tiers() sanitises tier keys; this is the only WordPress function the
// class touches.
function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

require_once __DIR__ . '/../includes/class-penalty-watch.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

$tiers = SPLM_Penalty_Watch::default_tiers();
$keys  = function ( $flags ) {
	return array_column( $flags, 'tier_key' );
};

echo "\n=== default tiers ===\n\n";

assert_test( 3 === count( $tiers ), 'three tiers ship by default' );
assert_test(
	array( 'season-warn', 'season-critical', 'window-critical' ) === array_column( $tiers, 'key' ),
	'default tier keys are stable identifiers'
);
assert_test( 12 === $tiers[0]['minutes'], 'season warning defaults to 12 PIM' );
assert_test( 18 === $tiers[1]['minutes'], 'season critical defaults to 18 PIM' );
assert_test( 8 === $tiers[2]['minutes'], 'window critical defaults to 8 PIM' );
assert_test( null === $tiers[0]['consequence'], 'no tier asserts a consequence in this version' );

echo "\n=== evaluate() ===\n\n";

assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 4, 'window' => 2 ), $tiers, array() ),
	'a player below every threshold produces no flags'
);
assert_test(
	array( 'season-warn' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 2 ), $tiers, array() ) ),
	'a tier fires exactly at its threshold, not one past it'
);
assert_test(
	array( 'season-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 2 ), $tiers, array() ) ),
	'when two season tiers match, only the highest is reported'
);
assert_test(
	array( 'season-critical', 'window-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 9 ), $tiers, array() ) ),
	'season and window are separate scopes and can both fire'
);

$flags = SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 9 ), $tiers, array() );
assert_test( 20 === $flags[0]['value'], 'a flag carries the value that triggered it' );
assert_test( 'season' === $flags[0]['scope'] && 'window' === $flags[1]['scope'], 'flags carry their scope' );
assert_test( 'critical' === $flags[0]['severity'], 'flags carry their severity' );

$mixed = SPLM_Penalty_Watch::evaluate( array( 'season' => 13, 'window' => 9 ), $tiers, array() );
assert_test(
	array( 'window-critical', 'season-warn' ) === $keys( $mixed ),
	'criticals sort ahead of warnings regardless of scope order'
);

echo "\n=== acknowledgement suppression ===\n\n";

assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ) ),
	'acknowledging at the current value suppresses the flag'
);
assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 0 ), $tiers, array( 'season-warn' => 14 ) ),
	'an acknowledgement above the current value still suppresses'
);
assert_test(
	array( 'season-warn' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 13, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ) ) ),
	're-alerts once the player picks up more minutes than were acknowledged'
);
assert_test(
	array( 'season-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 18, 'window' => 0 ), $tiers, array( 'season-warn' => 99 ) ) ),
	'acknowledging the warning tier does not suppress the critical tier'
);
assert_test(
	array( 'season-warn' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 0 ), $tiers, array( 'season-critical' => 20 ) ) ),
	'suppressing the highest tier falls back to the next unacknowledged one rather than hiding the player entirely'
);
assert_test(
	array( 'window-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 8 ), $tiers, array( 'season-warn' => 12 ) ) ),
	'suppression is per scope'
);

echo "\n=== sanitize_tiers() ===\n\n";

$clean = SPLM_Penalty_Watch::sanitize_tiers(
	array(
		array( 'key' => 'season-warn', 'scope' => 'season', 'minutes' => '15', 'severity' => 'warning' ),
		array( 'key' => 'bogus', 'scope' => 'nonsense', 'minutes' => '5', 'severity' => 'warning' ),
		array( 'key' => 'no-minutes', 'scope' => 'season', 'minutes' => '0', 'severity' => 'critical' ),
	)
);
assert_test( 1 === count( $clean ), 'tiers with an unknown scope or a zero threshold are dropped' );
assert_test( 15 === $clean[0]['minutes'], 'numeric strings are coerced to ints' );
assert_test( null === $clean[0]['consequence'], 'consequence is normalised to null' );
assert_test(
	SPLM_Penalty_Watch::default_tiers() === SPLM_Penalty_Watch::sanitize_tiers( array() ),
	'sanitising nothing falls back to the defaults so the feature is never silently disabled'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php sportspress-league-manager/tests/test-penalty-watch.php`
Expected: FAIL — PHP fatal, `Failed to open stream: ... class-penalty-watch.php`

- [ ] **Step 3: Write the implementation**

Create `sportspress-league-manager/includes/class-penalty-watch.php`:

```php
<?php
/**
 * Penalty-minute threshold evaluation.
 *
 * Thresholds are a tier list rather than two loose numbers so that a real
 * suspension rule can later be expressed by adding tiers with a populated
 * 'consequence' instead of rewriting this. Nothing here asserts a consequence
 * today; the field exists and is always null.
 *
 * Pure by construction: totals in, flags out.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Penalty_Watch {

	const SCOPES     = array( 'season', 'window' );
	const SEVERITIES = array( 'warning', 'critical' );

	/**
	 * Seeded tiers.
	 *
	 * The numbers come from the observed W2025-26 distribution across 300
	 * players with any PIM: 12 flags 16 players (5.3%), 18 flags 3, and 8 in a
	 * 4-week window flags 2. A 4-week warning was deliberately omitted — at 6
	 * minutes it flags 30 players, which is noise.
	 *
	 * @return array
	 */
	public static function default_tiers() {
		return array(
			array(
				'key'         => 'season-warn',
				'scope'       => 'season',
				'minutes'     => 12,
				'severity'    => 'warning',
				'consequence' => null,
			),
			array(
				'key'         => 'season-critical',
				'scope'       => 'season',
				'minutes'     => 18,
				'severity'    => 'critical',
				'consequence' => null,
			),
			array(
				'key'         => 'window-critical',
				'scope'       => 'window',
				'minutes'     => 8,
				'severity'    => 'critical',
				'consequence' => null,
			),
		);
	}

	/**
	 * Flags for one player.
	 *
	 * Suppressed tiers are removed BEFORE the highest-per-scope choice, so
	 * acknowledging a critical reveals the warning underneath instead of hiding
	 * the player altogether.
	 *
	 * @param array $totals array( 'season' => int, 'window' => int ).
	 * @param array $tiers  Tier list.
	 * @param array $acks   tier_key => value_at_ack.
	 * @return array Flags, criticals first.
	 */
	public static function evaluate( array $totals, array $tiers, array $acks ) {
		$matched = array();

		foreach ( $tiers as $tier ) {
			$scope = (string) ( $tier['scope'] ?? '' );
			if ( ! in_array( $scope, self::SCOPES, true ) ) {
				continue;
			}

			$value = (int) ( $totals[ $scope ] ?? 0 );
			if ( $value < (int) $tier['minutes'] ) {
				continue;
			}

			// An acknowledgement records the total at the time. The flag stays
			// down until the player earns more than that, which is what stops
			// the same three names alerting every week forever.
			$key = (string) $tier['key'];
			if ( array_key_exists( $key, $acks ) && $value <= (int) $acks[ $key ] ) {
				continue;
			}

			$matched[ $scope ][] = array(
				'tier_key' => $key,
				'scope'    => $scope,
				'severity' => (string) $tier['severity'],
				'minutes'  => (int) $tier['minutes'],
				'value'    => $value,
			);
		}

		$flags = array();
		foreach ( $matched as $scope_flags ) {
			usort(
				$scope_flags,
				function ( $a, $b ) {
					return $b['minutes'] <=> $a['minutes'];
				}
			);
			$flags[] = $scope_flags[0];
		}

		usort( $flags, array( __CLASS__, 'compare_flags' ) );

		return $flags;
	}

	/**
	 * Validate a stored or submitted tier list.
	 *
	 * @param array $raw Candidate tiers.
	 * @return array Valid tiers, or the defaults when none survive.
	 */
	public static function sanitize_tiers( array $raw ) {
		$out = array();

		foreach ( $raw as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$scope    = (string) ( $tier['scope'] ?? '' );
			$severity = (string) ( $tier['severity'] ?? '' );
			$minutes  = (int) ( $tier['minutes'] ?? 0 );
			$key      = sanitize_key( (string) ( $tier['key'] ?? '' ) );

			if ( '' === $key || $minutes < 1 ) {
				continue;
			}
			if ( ! in_array( $scope, self::SCOPES, true ) || ! in_array( $severity, self::SEVERITIES, true ) ) {
				continue;
			}

			$out[] = array(
				'key'         => $key,
				'scope'       => $scope,
				'minutes'     => $minutes,
				'severity'    => $severity,
				'consequence' => null,
			);
		}

		// Never let a bad save silently disable the watch list.
		return $out ? $out : self::default_tiers();
	}

	/**
	 * Criticals first, then higher thresholds first.
	 *
	 * @param array $a Flag.
	 * @param array $b Flag.
	 * @return int
	 */
	private static function compare_flags( $a, $b ) {
		$rank = array(
			'critical' => 0,
			'warning'  => 1,
		);
		$a_rank = $rank[ $a['severity'] ] ?? 9;
		$b_rank = $rank[ $b['severity'] ] ?? 9;

		if ( $a_rank !== $b_rank ) {
			return $a_rank <=> $b_rank;
		}

		return $b['minutes'] <=> $a['minutes'];
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php sportspress-league-manager/tests/test-penalty-watch.php`
Expected: PASS, `Failed: 0`

- [ ] **Step 5: Register the class and the test**

In `class-autoloader.php`:

```php
			'SPLM_Penalty_Watch'         => $base . 'class-penalty-watch.php',
```

In `run-all-tests.sh`, after `test-player-stats-aggregator.php`:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-penalty-watch.php"
```

- [ ] **Step 6: Run the whole suite and commit**

Run: `./run-all-tests.sh` — expected: all pass.

```bash
git add sportspress-league-manager/includes/class-penalty-watch.php \
        sportspress-league-manager/tests/test-penalty-watch.php \
        sportspress-league-manager/includes/class-autoloader.php \
        run-all-tests.sh
git commit -m "feat(discipline): evaluate penalty-minute tiers with acknowledgement suppression"
```

---

## Task 6: Acknowledgement table

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-database.php`
- Modify: `sportspress-league-manager/includes/class-autoloader.php`, `sportspress-league-manager.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `SPLM_Discipline_Database::table_name(): string`
  - `SPLM_Discipline_Database::create_table(): bool`
  - `SPLM_Discipline_Database::table_exists(): bool`
  - `SPLM_Discipline_Database::maybe_upgrade(): void`
  - `SPLM_Discipline_Database::acks_for_season( int $season_id ): array` — `player_id => array( tier_key => value_at_ack )`
  - `SPLM_Discipline_Database::acknowledge( int $player_id, int $season_id, string $tier_key, int $value, string $status, string $note, int $author_id ): bool`

- [ ] **Step 1: Write the class**

Create `sportspress-league-manager/includes/class-discipline-database.php`:

```php
<?php
/**
 * Storage for penalty-threshold acknowledgements.
 *
 * Follows the player-notes table pattern, including verifying the table exists
 * after dbDelta() rather than trusting its return value — dbDelta() returns a
 * list of applied statements and nothing useful on failure, so stamping a
 * version on its return records a failed CREATE as done and never retries.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Database {

	const DB_VERSION = '1.0.0';
	const VERSION_OPTION = 'splm_discipline_db_version';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'splm_discipline_ack';
	}

	/**
	 * Create the table.
	 *
	 * @return bool True when the table is present afterwards.
	 */
	public static function create_table() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			player_id bigint(20) unsigned NOT NULL,
			season_id bigint(20) unsigned NOT NULL,
			tier_key varchar(50) NOT NULL,
			value_at_ack int NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'reviewed',
			note text NULL,
			author_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY player_season_tier (player_id, season_id, tier_key),
			KEY season_id (season_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return self::table_exists();
	}

	/**
	 * Whether the table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB
	}

	/**
	 * Create the table on first run or after a version bump.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION && self::table_exists() ) {
			return;
		}

		if ( self::create_table() ) {
			update_option( self::VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Every acknowledgement for a season, indexed for direct use by
	 * SPLM_Penalty_Watch::evaluate().
	 *
	 * @param int $season_id Season term id.
	 * @return array player_id => array( tier_key => value_at_ack ).
	 */
	public static function acks_for_season( $season_id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT player_id, tier_key, value_at_ack FROM ' . self::table_name() . ' WHERE season_id = %d',
				(int) $season_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->player_id ][ (string) $row->tier_key ] = (int) $row->value_at_ack;
		}

		return $out;
	}

	/**
	 * Record or update an acknowledgement.
	 *
	 * Upserts on (player_id, season_id, tier_key): acknowledging the same tier
	 * again simply raises the recorded value.
	 *
	 * @param int    $player_id Player post id.
	 * @param int    $season_id Season term id.
	 * @param string $tier_key  Tier identifier.
	 * @param int    $value     PIM total at acknowledgement.
	 * @param string $status    reviewed|suspension_served|dismissed.
	 * @param string $note      Optional note.
	 * @param int    $author_id Acting user.
	 * @return bool
	 */
	public static function acknowledge( $player_id, $season_id, $tier_key, $value, $status, $note, $author_id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$allowed = array( 'reviewed', 'suspension_served', 'dismissed' );
		$status  = in_array( $status, $allowed, true ) ? $status : 'reviewed';

		$result = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'INSERT INTO ' . self::table_name() . '
					(player_id, season_id, tier_key, value_at_ack, status, note, author_id, created_at)
				 VALUES (%d, %d, %s, %d, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE
					value_at_ack = VALUES(value_at_ack),
					status = VALUES(status),
					note = VALUES(note),
					author_id = VALUES(author_id),
					created_at = VALUES(created_at)',
				(int) $player_id,
				(int) $season_id,
				sanitize_key( $tier_key ),
				(int) $value,
				$status,
				wp_kses_post( $note ),
				(int) $author_id,
				current_time( 'mysql' )
			)
		);

		return false !== $result;
	}
}
```

- [ ] **Step 2: Register the class and run the upgrade**

In `class-autoloader.php`:

```php
			'SPLM_Discipline_Database'   => $base . 'class-discipline-database.php',
```

In `sportspress-league-manager.php`, inside `load_enabled_modules()`, gated on the new module (added in Task 7) — for now add it next to the other instantiations and it will be moved under the module check in Task 7:

```php
		SPLM_Discipline_Database::maybe_upgrade();
```

- [ ] **Step 3: Verify the table is created on staging**

```bash
ssh tikal 'docker exec -u www-data staging-wp sh -c "cd /var/www/html && wp eval \"var_dump( SPLM_Discipline_Database::table_exists() );\" --allow-root"'
```

Expected: `bool(true)`.

- [ ] **Step 4: Verify the upsert behaves**

```bash
ssh tikal 'docker exec -u www-data staging-wp sh -c "cd /var/www/html && wp eval \"
SPLM_Discipline_Database::acknowledge( 912, 654, \\\"season-warn\\\", 12, \\\"reviewed\\\", \\\"first\\\", 1 );
SPLM_Discipline_Database::acknowledge( 912, 654, \\\"season-warn\\\", 16, \\\"reviewed\\\", \\\"second\\\", 1 );
print_r( SPLM_Discipline_Database::acks_for_season( 654 ) );
\" --allow-root"'
```

Expected: a single entry for player 912 with `season-warn => 16` — proving the upsert raised the value rather than inserting a duplicate row. Clean up afterwards:

```bash
ssh tikal 'docker exec -u www-data staging-wp sh -c "cd /var/www/html && wp db query \"DELETE FROM wp_splm_discipline_ack WHERE player_id = 912\" --allow-root"'
```

- [ ] **Step 5: Commit**

```bash
git add sportspress-league-manager/includes/class-discipline-database.php \
        sportspress-league-manager/includes/class-autoloader.php \
        sportspress-league-manager/sportspress-league-manager.php
git commit -m "feat(discipline): add acknowledgement table with upsert on player/season/tier"
```

---

## Task 7: league_discipline module and settings

**Files:**
- Modify: `sportspress-league-manager/sportspress-league-manager.php`, `sportspress-league-manager/includes/class-admin.php`, `sportspress-league-manager/includes/class-dashboard-frontend.php`

**Interfaces:**
- Consumes: `SPLM_Penalty_Watch::default_tiers()`, `::sanitize_tiers()`, `SPLM_Discipline_Database::maybe_upgrade()`.
- Produces: module id `league_discipline`; options `splm_discipline_tiers` (array), `splm_discipline_window_weeks` (int, default 4), `splm_discipline_digest_enabled` (bool), `splm_discipline_digest_recipients` (string, comma-separated), `splm_discipline_digest_day` (string, lowercase weekday). `modules.discipline` exposed to the dashboard config.

- [ ] **Step 1: Register the module**

In `sportspress-league-manager.php`, after the `league_player_notes` registration block:

```php
		SPAT_Plugin_Manager::register_plugin(
			'league_discipline',
			array(
				'name'          => 'Penalty Discipline',
				'description'   => 'Penalty-minute watch list, acknowledgements and weekly digest',
				'parent_module' => 'league_discipline',
				'version'       => SPLM_VERSION,
				'file'          => __FILE__,
			)
		);
```

Add `'league_discipline'` to the `array_intersect()` list in `load_enabled_modules()`:

```php
		$any_enabled = array_intersect(
			$enabled,
			array( 'league_manager_dashboard', 'league_roster_management', 'league_fee_tracking', 'league_player_notes', 'league_discipline' )
		);
```

and move the database upgrade under its own gate, beside the existing player-notes gate:

```php
		if ( in_array( 'league_discipline', $enabled, true ) ) {
			SPLM_Discipline_Database::maybe_upgrade();
		}
```

- [ ] **Step 2: Add the settings**

In `includes/class-admin.php`, alongside the existing `register_setting()` calls for `splm_backend_settings`:

```php
		register_setting(
			'splm_backend_settings',
			'splm_discipline_tiers',
			array(
				'sanitize_callback' => array( 'SPLM_Penalty_Watch', 'sanitize_tiers' ),
				'default'           => SPLM_Penalty_Watch::default_tiers(),
			)
		);
		register_setting( 'splm_backend_settings', 'splm_discipline_window_weeks', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'splm_backend_settings', 'splm_discipline_digest_enabled', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'splm_backend_settings', 'splm_discipline_digest_recipients', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'splm_backend_settings', 'splm_discipline_digest_day', array( 'sanitize_callback' => 'sanitize_key' ) );
```

and the matching fields, following the existing `$this->add_field( ... )` pattern:

```php
		$this->add_field( 'splm_discipline_window_weeks', __( 'Penalty Window (weeks)', 'sportspress-league-manager' ), array( $this, 'render_discipline_window_field' ) );
		$this->add_field( 'splm_discipline_tiers', __( 'Penalty Thresholds', 'sportspress-league-manager' ), array( $this, 'render_discipline_tiers_field' ) );
```

with renderers:

```php
	/**
	 * Rolling-window length in weeks.
	 */
	public function render_discipline_window_field() {
		echo '<input type="number" name="splm_discipline_window_weeks" value="' . esc_attr( get_option( 'splm_discipline_window_weeks', 4 ) ) . '" min="1" max="52"/>';
		echo '<p class="description">' . esc_html__( 'How many recent calendar weeks the rolling penalty window covers. Includes the current week.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Threshold tiers, one row per tier, with a preview of how many players
	 * each threshold would have flagged in the selected season.
	 */
	public function render_discipline_tiers_field() {
		$tiers = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );

		echo '<table class="widefat" style="max-width:40em">';
		echo '<thead><tr><th>' . esc_html__( 'Tier', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Scope', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Minutes', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Would flag', 'sportspress-league-manager' ) . '</th></tr></thead><tbody>';

		foreach ( $tiers as $i => $tier ) {
			$count = $this->preview_flag_count( $tier );
			printf(
				'<tr><td>%s<input type="hidden" name="splm_discipline_tiers[%d][key]" value="%s"/><input type="hidden" name="splm_discipline_tiers[%d][severity]" value="%s"/></td>'
					. '<td>%s<input type="hidden" name="splm_discipline_tiers[%d][scope]" value="%s"/></td>'
					. '<td><input type="number" min="1" max="200" name="splm_discipline_tiers[%d][minutes]" value="%d"/></td>'
					. '<td>%s</td></tr>',
				esc_html( $tier['key'] ),
				(int) $i,
				esc_attr( $tier['key'] ),
				(int) $i,
				esc_attr( $tier['severity'] ),
				esc_html( $tier['scope'] ),
				(int) $i,
				esc_attr( $tier['scope'] ),
				(int) $i,
				(int) $tier['minutes'],
				esc_html(
					null === $count
						? __( '—', 'sportspress-league-manager' )
						/* translators: %d: number of players. */
						: sprintf( _n( '%d player', '%d players', $count, 'sportspress-league-manager' ), $count )
				)
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Player counts are for the default season, so you can see whether a threshold is useful before saving it.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * How many players the given tier would flag in the default season.
	 *
	 * @param array $tier Tier definition.
	 * @return int|null Null when there is no season to measure against.
	 */
	private function preview_flag_count( array $tier ) {
		$season_id = (int) get_option( 'splm_default_season', 0 );
		if ( ! $season_id ) {
			return null;
		}

		$players = SPLM_Player_Stats_Aggregator::for_season( $season_id, array( 'include_playoffs' => true ) );
		if ( ! $players ) {
			return null;
		}

		$weeks  = (int) get_option( 'splm_discipline_window_weeks', 4 );
		$cutoff = SPLM_Player_Stats_Aggregator::window_cutoff(
			$weeks,
			current_time( 'Y-m-d' ),
			SPLM_Player_Stats_Aggregator::season_start( $players )
		);

		$count = 0;
		foreach ( $players as $player ) {
			$value = 'window' === $tier['scope']
				? SPLM_Player_Stats_Aggregator::window_totals( $player['weeks'], $cutoff )['pim']
				: $player['totals']['pim'];
			if ( $value >= (int) $tier['minutes'] ) {
				$count++;
			}
		}

		return $count;
	}
```

- [ ] **Step 3: Expose the module to the dashboard**

In `includes/class-dashboard-frontend.php`, find where the `modules` array is built for the localized config and add:

```php
					'discipline' => SPLM_REST_API::module_enabled( 'league_discipline' ),
```

- [ ] **Step 4: Verify on staging**

Enable the module, then confirm the settings screen renders the tier table with non-null "would flag" counts, and that saving a changed threshold persists:

```bash
ssh tikal 'docker exec -u www-data staging-wp sh -c "cd /var/www/html && wp eval \"print_r( get_option( \\\"splm_discipline_tiers\\\" ) );\" --allow-root"'
```

Expected: three tiers with the defaults, or your edited values after saving.

- [ ] **Step 5: Commit**

```bash
git add sportspress-league-manager/sportspress-league-manager.php \
        sportspress-league-manager/includes/class-admin.php \
        sportspress-league-manager/includes/class-dashboard-frontend.php
git commit -m "feat(discipline): add league_discipline module and threshold settings"
```

---

## Task 8: Watch and acknowledge endpoints

**Files:**
- Modify: `sportspress-league-manager/includes/class-leaders-rest.php`

**Interfaces:**
- Consumes: `SPLM_Penalty_Watch::evaluate()`, `::sanitize_tiers()`, `SPLM_Discipline_Database::acks_for_season()`, `::acknowledge()`, `SPLM_Player_Stats_Aggregator::*`, `SPLM_Leaders_REST::flush_cache()`.
- Produces: `GET /splm/v1/discipline/watch` (list shape), `POST /splm/v1/discipline/acknowledge` (write shape), and `SPLM_Leaders_REST::build_watch( int $season_id ): array` used by the digest in Task 10.

- [ ] **Step 1: Register the two routes**

In `SPLM_Leaders_REST::register_routes()`, after the `/leaders` registration:

```php
		register_rest_route(
			self::NAMESPACE_V1,
			'/discipline/watch',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_watch' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'season' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/discipline/acknowledge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_acknowledge' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'player'   => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'season'   => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'tier_key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'status'   => array( 'sanitize_callback' => 'sanitize_key' ),
					'note'     => array( 'sanitize_callback' => 'wp_kses_post' ),
				),
			)
		);
```

- [ ] **Step 2: Add the permission callback**

```php
	/**
	 * Manage access: the watch list names individuals and their penalty
	 * records, so it is not part of the general read tier.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage() {
		if ( ! SPLM_REST_API::module_enabled( 'league_discipline' ) ) {
			return new WP_Error( 'module_disabled', __( 'Penalty discipline is not enabled.', 'sportspress-league-manager' ), array( 'status' => 503 ) );
		}
		if ( ! SPLM_Capabilities::can_manage() ) {
			return new WP_Error( 'forbidden', __( 'You cannot view discipline data.', 'sportspress-league-manager' ), array( 'status' => 403 ) );
		}

		return true;
	}
```

- [ ] **Step 3: Add the watch builder and handler**

```php
	/**
	 * Flagged players for a season.
	 *
	 * Playoffs are included unconditionally: discipline is cumulative and a
	 * playoff penalty counts the same as a regular-season one.
	 *
	 * @param int $season_id Season term id.
	 * @return array Rows, most severe first.
	 */
	public static function build_watch( $season_id ) {
		$season_id = (int) $season_id;
		$players   = SPLM_Player_Stats_Aggregator::for_season( $season_id, array( 'include_playoffs' => true ) );
		if ( ! $players ) {
			return array();
		}

		$tiers  = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );
		$acks   = SPLM_Discipline_Database::acks_for_season( $season_id );
		$weeks  = (int) get_option( 'splm_discipline_window_weeks', 4 );
		$cutoff = SPLM_Player_Stats_Aggregator::window_cutoff(
			$weeks,
			current_time( 'Y-m-d' ),
			SPLM_Player_Stats_Aggregator::season_start( $players )
		);

		$rows = array();
		foreach ( $players as $player_id => $player ) {
			$window = SPLM_Player_Stats_Aggregator::window_totals( $player['weeks'], $cutoff );
			$flags  = SPLM_Penalty_Watch::evaluate(
				array(
					'season' => (int) $player['totals']['pim'],
					'window' => (int) $window['pim'],
				),
				$tiers,
				$acks[ $player_id ] ?? array()
			);

			if ( ! $flags ) {
				continue;
			}

			$rows[] = array(
				'player_id'  => (int) $player_id,
				'player'     => $player['name'],
				'team'       => $player['team'],
				'division'   => $player['div_name'],
				'season_pim' => (int) $player['totals']['pim'],
				'window_pim' => (int) $window['pim'],
				'gp'         => (int) $player['totals']['gp'],
				'flags'      => $flags,
				'severity'   => $flags[0]['severity'],
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['severity'] !== $b['severity'] ) {
					return 'critical' === $a['severity'] ? -1 : 1;
				}
				return $b['season_pim'] <=> $a['season_pim'];
			}
		);

		return $rows;
	}

	/**
	 * GET /discipline/watch — flagged players, wrapped as a list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_watch( $request ) {
		$season_id = absint( $request->get_param( 'season' ) );
		$season    = get_term( $season_id, 'sp_season' );
		if ( ! $season || is_wp_error( $season ) ) {
			return new WP_Error( 'invalid_season', __( 'Season not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$cache_key = self::cache_key( 'watch', array( $season_id ) );
		$rows      = get_transient( $cache_key );
		if ( false === $rows ) {
			$rows = self::build_watch( $season_id );
			self::remember( $cache_key );
			set_transient( $cache_key, $rows, self::CACHE_TTL );
		}

		return new WP_REST_Response( splm_rest_list_response( $rows ), 200 );
	}

	/**
	 * POST /discipline/acknowledge — record that a flag was actioned.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_acknowledge( $request ) {
		$player_id = absint( $request->get_param( 'player' ) );
		$season_id = absint( $request->get_param( 'season' ) );
		$tier_key  = sanitize_key( (string) $request->get_param( 'tier_key' ) );

		if ( ! $player_id || ! $season_id || '' === $tier_key ) {
			return new WP_Error( 'invalid_input', __( 'Player, season and tier are required.', 'sportspress-league-manager' ), array( 'status' => 400 ) );
		}

		// The value recorded must be the value that actually triggered the flag,
		// so it is read from the current watch rather than taken from the client.
		$value = null;
		foreach ( self::build_watch( $season_id ) as $row ) {
			if ( $row['player_id'] !== $player_id ) {
				continue;
			}
			foreach ( $row['flags'] as $flag ) {
				if ( $flag['tier_key'] === $tier_key ) {
					$value = (int) $flag['value'];
					break 2;
				}
			}
		}

		if ( null === $value ) {
			return new WP_Error( 'not_flagged', __( 'That player is not currently flagged for this threshold.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$ok = SPLM_Discipline_Database::acknowledge(
			$player_id,
			$season_id,
			$tier_key,
			$value,
			(string) $request->get_param( 'status' ),
			(string) $request->get_param( 'note' ),
			get_current_user_id()
		);

		if ( ! $ok ) {
			return new WP_Error( 'ack_failed', __( 'Could not record the acknowledgement.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		self::flush_cache();

		return new WP_REST_Response(
			array(
				'success'      => true,
				'player_id'    => $player_id,
				'tier_key'     => $tier_key,
				'value_at_ack' => $value,
			),
			200
		);
	}
```

- [ ] **Step 4: Verify on staging**

```bash
ssh tikal 'docker exec -u www-data staging-wp sh -c "cd /var/www/html && wp eval \"
\\\$rows = SPLM_Leaders_REST::build_watch( 654 );
echo count( \\\$rows ) . \\\" flagged\\\" . PHP_EOL;
foreach ( array_slice( \\\$rows, 0, 5 ) as \\\$r ) {
  echo \\\$r[\\\"player\\\"] . \\\" season=\\\" . \\\$r[\\\"season_pim\\\"] . \\\" window=\\\" . \\\$r[\\\"window_pim\\\"] . \\\" \\\" . \\\$r[\\\"flags\\\"][0][\\\"tier_key\\\"] . PHP_EOL;
}
\" --allow-root"'
```

Expected: roughly 16 flagged players for W2025-26 at the default thresholds, criticals first. The window counts will be 0 because that season is over — that is correct behaviour, not a bug.

- [ ] **Step 5: Commit**

```bash
git add sportspress-league-manager/includes/class-leaders-rest.php
git commit -m "feat(discipline): add watch list and acknowledgement endpoints"
```

---

## Task 9: Watch list UI and Dashboard card

**Files:**
- Create: `sportspress-league-manager/src/dashboard/components/PenaltyWatchCard.jsx`
- Modify: `src/dashboard/lib/api.js`, `src/dashboard/pages/Leaders.jsx`, `src/dashboard/pages/Dashboard.jsx`

**Interfaces:**
- Consumes: `GET /splm/v1/discipline/watch`, `POST /splm/v1/discipline/acknowledge`.
- Produces: `fetchPenaltyWatch( seasonId )`, `acknowledgePenalty( { player, season, tierKey, status, note } )` in `api.js`; `<PenaltyWatchCard season={ season } onNavigate={ onNavigate } />`.

- [ ] **Step 1: Add the API client functions**

In `src/dashboard/lib/api.js`, next to `fetchLeaders`:

```js
export function fetchPenaltyWatch( seasonId ) {
	return apiFetch( { path: `/splm/v1/discipline/watch?season=${ seasonId }` } ).then( ( r ) => r.data );
}

export function acknowledgePenalty( { player, season, tierKey, status = 'reviewed', note = '' } ) {
	return apiFetch( {
		path: '/splm/v1/discipline/acknowledge',
		method: 'POST',
		data: { player, season, tier_key: tierKey, status, note },
	} );
}
```

`fetchPenaltyWatch` unwraps `.data` because `/discipline/watch` is a list endpoint; `fetchLeaders` does not because `/leaders` is an aggregate. Match how the neighbouring functions unwrap — if this file uses a shared helper for list endpoints, use it.

- [ ] **Step 2: Create the Dashboard card**

Create `sportspress-league-manager/src/dashboard/components/PenaltyWatchCard.jsx`:

```jsx
import { useState, useEffect } from '@wordpress/element';
import { fetchPenaltyWatch } from '../lib/api';

export default function PenaltyWatchCard( { season, onNavigate } ) {
	const [ rows, setRows ] = useState( [] );
	const [ loaded, setLoaded ] = useState( false );

	useEffect( () => {
		if ( ! season ) return undefined;
		let cancelled = false;
		fetchPenaltyWatch( season )
			.then( ( d ) => {
				if ( cancelled ) return;
				setRows( d || [] );
				setLoaded( true );
			} )
			// The card is supplementary: a failure here must not break the
			// Dashboard, so it simply renders nothing.
			.catch( () => { if ( ! cancelled ) setLoaded( true ); } );
		return () => { cancelled = true; };
	}, [ season ] );

	const critical = rows.filter( ( r ) => r.severity === 'critical' );
	if ( ! loaded || rows.length === 0 ) return null;

	return (
		<section className="splm-card">
			<h3>Penalty Watch</h3>
			<p className="splm-muted">
				{ critical.length } critical, { rows.length - critical.length } warning
			</p>
			<ul className="splm-list">
				{ rows.slice( 0, 5 ).map( ( r ) => (
					<li key={ r.player_id }>
						<strong>{ r.player }</strong> — { r.season_pim } PIM
						{ r.window_pim > 0 && ` (${ r.window_pim } recent)` }
						{ ' ' }<span className={ `splm-badge splm-badge--${ r.severity }` }>{ r.severity }</span>
					</li>
				) ) }
			</ul>
			<button className="splm-btn" onClick={ () => onNavigate( 'leaders' ) }>
				View all
			</button>
		</section>
	);
}
```

- [ ] **Step 3: Mount the card on the Dashboard**

In `src/dashboard/pages/Dashboard.jsx`, add the import:

```jsx
import PenaltyWatchCard from '../components/PenaltyWatchCard';
```

Add `'penalties'` to the `CARDS` array so it can be toggled like the others, and render it inside the same conditional pattern the other cards use:

```jsx
			{ visibleCards.includes( 'penalties' ) && window.splmDashboard?.modules?.discipline !== false && (
				<PenaltyWatchCard season={ season } onNavigate={ onNavigate } />
			) }
```

- [ ] **Step 4: Add the watch list to the Leaders page**

In `src/dashboard/pages/Leaders.jsx`, add the imports:

```jsx
import { fetchLeaders, fetchPenaltyWatch, acknowledgePenalty } from '../lib/api';
```

Add state and loader inside the `Leaders` component:

```jsx
	const [ watch, setWatch ] = useState( [] );
	const canSeeWatch = window.splmDashboard?.modules?.discipline !== false
		&& window.splmDashboard?.capabilities?.canManage !== false;

	const loadWatch = () => {
		if ( ! season || ! canSeeWatch ) return;
		fetchPenaltyWatch( season ).then( setWatch ).catch( () => setWatch( [] ) );
	};

	useEffect( loadWatch, [ season, canSeeWatch ] );

	const onAcknowledge = ( row, flag ) => {
		acknowledgePenalty( { player: row.player_id, season, tierKey: flag.tier_key } )
			.then( loadWatch )
			.catch( ( err ) => setError( err?.message || 'Could not acknowledge' ) );
	};
```

Render it below the boards:

```jsx
			{ canSeeWatch && watch.length > 0 && (
				<section className="splm-card">
					<h3>Penalty Watch</h3>
					<div className="splm-table-wrapper">
						<table className="splm-table">
							<thead>
								<tr>
									<th scope="col">Player</th>
									<th scope="col">Team</th>
									<th scope="col">Division</th>
									<th scope="col">Season PIM</th>
									<th scope="col">Recent PIM</th>
									<th scope="col">Flag</th>
									<th scope="col">Action</th>
								</tr>
							</thead>
							<tbody>
								{ watch.map( ( row ) => (
									<tr key={ row.player_id }>
										<td>{ row.player }</td>
										<td>{ row.team }</td>
										<td>{ row.division }</td>
										<td>{ row.season_pim }</td>
										<td>{ row.window_pim }</td>
										<td>
											{ row.flags.map( ( f ) => (
												<span key={ f.tier_key } className={ `splm-badge splm-badge--${ f.severity }` }>
													{ f.tier_key } ({ f.value })
												</span>
											) ) }
										</td>
										<td>
											{ row.flags.map( ( f ) => (
												<button
													key={ f.tier_key }
													className="splm-btn splm-btn--small"
													onClick={ () => onAcknowledge( row, f ) }
												>
													Acknowledge { f.tier_key }
												</button>
											) ) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					<p className="splm-muted">Acknowledging records the player’s current total. They reappear here only if they pass it or reach a higher threshold.</p>
				</section>
			) }
```

Confirm the exact capability flag name in `class-dashboard-frontend.php` before using `capabilities.canManage` — the existing config exposes `canManageRosters` and `canViewPayments`, so add a `canManage` entry there if it does not exist.

- [ ] **Step 5: Add badge styles**

In `src/dashboard/styles.css`, following the existing `splm-alert--warning` conventions:

```css
.splm-badge { display: inline-block; padding: 0.1em 0.5em; border-radius: 3px; font-size: 0.85em; margin-right: 0.25em; }
.splm-badge--critical { background: #d63638; color: #fff; }
.splm-badge--warning { background: #dba617; color: #1d2327; }
```

- [ ] **Step 6: Build, verify, commit**

Run: `cd sportspress-league-manager && npm run build` — expected: succeeds.

Verify on staging that the watch list renders, that Acknowledge removes a row, and that reloading keeps it gone.

```bash
git add sportspress-league-manager/src sportspress-league-manager/build
git commit -m "feat(discipline): add penalty watch list and Dashboard card"
```

---

## Task 10: Weekly digest email

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-digest.php`
- Modify: `sportspress-league-manager/includes/class-autoloader.php`, `sportspress-league-manager.php`

**Interfaces:**
- Consumes: `SPLM_Leaders_REST::build_watch()`, `SPAT_Lock::with()`.
- Produces: `SPLM_Discipline_Digest::schedule()`, `::unschedule()`, `::run()`, `::build_body( array $rows, string $season_name ): string`.

- [ ] **Step 1: Write the class**

Create `sportspress-league-manager/includes/class-discipline-digest.php`:

```php
<?php
/**
 * Weekly penalty digest.
 *
 * Wrapped in SPAT_Lock because WP-Cron can fire the same event twice when two
 * requests race the scheduler, and a duplicated disciplinary email to the whole
 * board is not a harmless duplicate.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Digest {

	const HOOK = 'splm_discipline_digest';
	const LOCK = 'splm_discipline_digest';

	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule the weekly event if it is not already scheduled.
	 */
	public static function schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$day  = (string) get_option( 'splm_discipline_digest_day', 'monday' );
		$next = strtotime( 'next ' . $day . ' 08:00', current_time( 'timestamp' ) );
		if ( ! $next ) {
			$next = time() + DAY_IN_SECONDS;
		}

		wp_schedule_event( $next, 'weekly', self::HOOK );
	}

	/**
	 * Clear the scheduled event.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Build and send the digest.
	 *
	 * @return bool Whether mail was sent.
	 */
	public static function run() {
		if ( ! get_option( 'splm_discipline_digest_enabled' ) ) {
			return false;
		}

		return (bool) SPAT_Lock::with(
			self::LOCK,
			120,
			function () {
				$season_id = (int) get_option( 'splm_default_season', 0 );
				if ( ! $season_id ) {
					return false;
				}

				$rows = SPLM_Leaders_REST::build_watch( $season_id );
				// A quiet week sends nothing. A digest that arrives every week
				// saying "nothing to report" trains people to filter it.
				if ( ! $rows ) {
					return false;
				}

				$season = get_term( $season_id, 'sp_season' );
				$name   = ( $season && ! is_wp_error( $season ) ) ? $season->name : '';

				return wp_mail(
					self::recipients(),
					sprintf(
						/* translators: %s: season name. */
						__( 'Penalty watch — %s', 'sportspress-league-manager' ),
						$name
					),
					self::build_body( $rows, $name ),
					array( 'Content-Type: text/html; charset=UTF-8' )
				);
			}
		);
	}

	/**
	 * Digest recipients, falling back to the site admin.
	 *
	 * @return array
	 */
	public static function recipients() {
		$raw   = (string) get_option( 'splm_discipline_digest_recipients', '' );
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$valid = array_values( array_filter( $parts, 'is_email' ) );

		return $valid ? $valid : array( get_option( 'admin_email' ) );
	}

	/**
	 * Render the digest body.
	 *
	 * @param array  $rows        Watch rows.
	 * @param string $season_name Season name.
	 * @return string
	 */
	public static function build_body( array $rows, $season_name ) {
		$out  = '<p>' . sprintf(
			/* translators: 1: number of players, 2: season name. */
			esc_html__( '%1$d player(s) are over a penalty threshold in %2$s.', 'sportspress-league-manager' ),
			count( $rows ),
			esc_html( $season_name )
		) . '</p>';
		$out .= '<table cellpadding="6" border="1" style="border-collapse:collapse">';
		$out .= '<tr><th>' . esc_html__( 'Player', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Team', 'sportspress-league-manager' ) . '</th><th>'
			. esc_html__( 'Division', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Season PIM', 'sportspress-league-manager' ) . '</th><th>'
			. esc_html__( 'Recent PIM', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Flag', 'sportspress-league-manager' ) . '</th></tr>';

		foreach ( $rows as $row ) {
			$out .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
				esc_html( $row['player'] ),
				esc_html( $row['team'] ),
				esc_html( $row['division'] ),
				(int) $row['season_pim'],
				(int) $row['window_pim'],
				esc_html( implode( ', ', array_column( $row['flags'], 'tier_key' ) ) )
			);
		}

		$out .= '</table>';
		$out .= '<p>' . esc_html__( 'Acknowledge these in the League Manager dashboard under Leaders.', 'sportspress-league-manager' ) . '</p>';

		return $out;
	}
}
```

- [ ] **Step 2: Register and wire scheduling**

In `class-autoloader.php`:

```php
			'SPLM_Discipline_Digest'     => $base . 'class-discipline-digest.php',
```

In `sportspress-league-manager.php`, inside the `league_discipline` gate added in Task 7:

```php
		if ( in_array( 'league_discipline', $enabled, true ) ) {
			SPLM_Discipline_Database::maybe_upgrade();
			new SPLM_Discipline_Digest();
			if ( get_option( 'splm_discipline_digest_enabled' ) ) {
				SPLM_Discipline_Digest::schedule();
			} else {
				SPLM_Discipline_Digest::unschedule();
			}
		}
```

Also unschedule when the module is off — add to the same method, outside the gate:

```php
		if ( ! in_array( 'league_discipline', $enabled, true ) && class_exists( 'SPLM_Discipline_Digest' ) ) {
			SPLM_Discipline_Digest::unschedule();
		}
```

- [ ] **Step 3: Confirm the `weekly` schedule exists**

Run: `grep -rn "'weekly'" sportspress-league-manager/ sportspress-admin-tools/ --include="*.php"`

WordPress core does not define a `weekly` cron interval by default. If nothing registers it, add to `SPLM_Discipline_Digest`'s constructor:

```php
		add_filter( 'cron_schedules', array( __CLASS__, 'add_weekly_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval
```

and the callback:

```php
	/**
	 * Register a weekly interval; core only ships hourly/twicedaily/daily.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'sportspress-league-manager' ),
			);
		}

		return $schedules;
	}
```

- [ ] **Step 4: Verify on staging without sending mail**

Check the body renders before enabling delivery:

```bash
ssh tikal 'docker exec -u www-data staging-wp sh -c "cd /var/www/html && wp eval \"
\\\$rows = SPLM_Leaders_REST::build_watch( 654 );
echo substr( SPLM_Discipline_Digest::build_body( \\\$rows, \\\"W2025-26\\\" ), 0, 600 ) . PHP_EOL;
print_r( SPLM_Discipline_Digest::recipients() );
\" --allow-root"'
```

Expected: an HTML table listing flagged players, and the recipients array falling back to the site admin email.

Then confirm the event schedules and clears:

```bash
ssh tikal 'docker exec -u www-data staging-wp sh -c "cd /var/www/html && wp cron event list --allow-root | grep splm_discipline_digest"'
```

- [ ] **Step 5: Commit**

```bash
git add sportspress-league-manager/includes/class-discipline-digest.php \
        sportspress-league-manager/includes/class-autoloader.php \
        sportspress-league-manager/sportspress-league-manager.php
git commit -m "feat(discipline): add weekly penalty digest email"
```

---

## Final verification

- [ ] Run the full suite: `./run-all-tests.sh` — all pass.
- [ ] Run PHPCS over the new files: `phpcs --standard=WordPress sportspress-league-manager/includes/class-leaders.php sportspress-league-manager/includes/class-player-stats-aggregator.php sportspress-league-manager/includes/class-penalty-watch.php sportspress-league-manager/includes/class-leaders-rest.php sportspress-league-manager/includes/class-discipline-database.php sportspress-league-manager/includes/class-discipline-digest.php`
- [ ] Rebuild and confirm `build/` is committed and matches: `cd sportspress-league-manager && npm run build && git status --porcelain build`  — expected: no output.
- [ ] On staging with the module **disabled**, confirm `GET /splm/v1/discipline/watch` returns 503 and the Dashboard card does not render.
- [ ] On staging as a non-manager user, confirm the watch endpoint returns 403 while `/leaders` still returns 200.
