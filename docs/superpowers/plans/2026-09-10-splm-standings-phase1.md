# SPLM_Standings (Phase 1 of Postseason Playoffs) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `SPLM_Standings`, a standings/tiebreak engine in `sportspress-league-manager` that extends core SportsPress's own `SP_League_Table` (points, native head-to-head tiebreak) with a real PIM column and a persisted coin-flip, and exposes one `rank()` entry point the (not-yet-built) postseason bracket generator will call for both initial seeding and the week-4 re-seed.

**Architecture:** A pure aggregation helper (`team_pim_total()`) reads `sp_players` post meta directly — no core edits. A PIM `sp_column` is created idempotently and wired to a core filter seam (`sportspress_equation_solve_for_presets`) so it also renders correctly on real, site-visible League Table pages. A single reusable "scratch" `sp_table` post drives `SP_League_Table` for ad-hoc, date/team-scoped ranking queries (core's `SP_League_Table` is built around an existing `sp_table` post's taxonomy terms + meta, not ad-hoc constructor args, so a managed post is the integration point). `rank()` composes: SP_League_Table's own points+H2H+PIM-column order, then an explicit combined-PIM (regular+playoff summed) pass for anything SP_League_Table didn't fully resolve, then a persisted coin flip for genuine remaining ties.

**Tech Stack:** PHP 8.1+, WordPress custom post types/taxonomies (`sp_event`, `sp_column`, `sp_table`, `sp_season`, `sp_league` — all from core SportsPress, `sportspress-pro`), this repo's standalone PHP test convention (no PHPUnit; hand-rolled WP-function stubs, run via `bash run-all-tests.sh`).

**Spec:** [`docs/superpowers/specs/2026-09-10-postseason-playoffs-design.md`](../specs/2026-09-10-postseason-playoffs-design.md) — this plan implements only that spec's "Standings & tiebreak engine" section (suggested phase 1 of 6). Later phases (pairing-table generator, postseason config schema, seed placeholder resolution, day/time constraints, admin UI) are separate plans, not covered here.

## Global Constraints

- WordPress 6.4+, PHP 8.1+ (matches every other plugin in this repo).
- Text domain `sportspress-league-manager`.
- All new DB access through WordPress APIs (`get_posts()`, `get_post_meta()`, `update_post_meta()`, `get_option()`/`update_option()`) — no raw `$wpdb` queries needed for this phase.
- `SPLM_` class name prefix; one file per class in `sportspress-league-manager/includes/`, registered in `class-autoloader.php`'s `$class_map`.
- No PHPUnit — standalone test files under `sportspress-league-manager/tests/`, hand-rolled stubs, registered in `run-all-tests.sh`, run via `php <file>`.
- `bash run-all-tests.sh` and `python3 -m lizard -C 8 -L 50` must stay green (this repo's standing verification gate).
- Never push directly to `main`; work on a branch, open a PR, wait for CI, only merge when explicitly asked.

---

## File Structure

- **Create:** `sportspress-league-manager/includes/class-standings.php` — the whole `SPLM_Standings` class (all methods below; this repo keeps one class per file even when a class is large, matching e.g. `class-player-stats-aggregator.php`).
- **Create:** `sportspress-league-manager/tests/test-standings.php` — standalone tests, one assertion block per task below.
- **Modify:** `sportspress-league-manager/includes/class-autoloader.php` — add `'SPLM_Standings' => $base . 'class-standings.php',` to `$class_map` (alphabetical position, after `SPLM_SportsPress_Data`).
- **Modify:** `sportspress-league-manager/sportspress-league-manager.php` — one line in `init()`, after `SPLM_Autoloader::init();`, to wire `SPLM_Standings::register_hooks()`.
- **Modify:** `run-all-tests.sh` — one new `run_test` line for `test-standings.php`, alongside the other `sportspress-league-manager/tests/test-*.php` entries.

All five tasks below add to the same two files (`class-standings.php`, `test-standings.php`) incrementally — each task's steps show the exact method(s) to append and the exact test block to append, in the order they should land in the file.

## Interfaces (final shape, for reference across tasks)

```php
class SPLM_Standings {
	const PIM_COLUMN_SLUG = 'pim';

	public static function team_pim_total( $team_id, array $season_ids, $from = null, $to = null ): int
	public static function ensure_pim_column(): void
	public static function filter_pim_equation( $solution, $equation, $post_id )
	public static function register_hooks(): void
	public static function rank_by_points_h2h( array $team_ids, $season_id, $from = null, $to = null ): array // returns array{order: array, ties: array} -- see Task 5, Step 1, which revises Task 3's initial plain-array return
	public static function coin_flip( $team_a_id, $team_b_id, $context_key ): int
	public static function rank( array $team_ids, $season_id, array $combined_pim_season_ids = array(), $from = null, $to = null ): array
}
```

Every later task only needs the signatures above from earlier tasks — not their internals.
`rank_by_points_h2h()`'s return shape changes partway through this plan (Task 3 ships it as a plain
reordered array; Task 5, Step 1 revises it to `array{order, ties}` once `rank()` needs the tie groups
`SP_League_Table` already computed) — implement Task 3 as written first, then apply Task 5's revision to
already-committed code, rather than jumping straight to the final shape.

---

### Task 1: Team PIM aggregation helper

**Files:**
- Create: `sportspress-league-manager/includes/class-standings.php`
- Test: `sportspress-league-manager/tests/test-standings.php`

**Interfaces:**
- Produces: `SPLM_Standings::team_pim_total( $team_id, array $season_ids, $from = null, $to = null ): int` — sums penalty minutes for one team across all `sp_event` posts tagged with any of `$season_ids`, optionally restricted to `post_date` between `$from`/`$to` (both `'Y-m-d'` strings, inclusive).

PIM lives on each `sp_event` post's `sp_players` meta: `array( $team_id => array( $player_id => array( 'pim' => int, ... ) ) )` (confirmed against `sportspress-league-manager/includes/class-player-stats-aggregator.php:161-200`; the reserved row is `$player_id === 0`, already excluded here by the `(int) $team_id` key lookup never matching it).

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-standings.php`:

```php
<?php
/**
 * Standalone tests for SPLM_Standings.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state. A class rather than $GLOBALS (Codacy PHPMD
 * Superglobals) and instance properties rather than statics with array
 * subscript writes (also flagged).
 */
class SPLM_Standings_Test_State {
	/** post_id => WP_Post-like stdClass, for get_posts(). */
	public $events = array();

	/** post_id => array( meta_key => value ). */
	public $meta = array();

	/** option_name => value. */
	public $options = array();
}

function splm_standings_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Standings_Test_State();
	}
	return $state;
}

/**
 * Minimal get_posts() stub: returns every stubbed event whose sp_season
 * term ids intersect the requested ones, and whose post_date falls in the
 * requested range when one is given. Real get_posts() takes a tax_query/
 * date_query array; this stub reads the same $args shape but resolves
 * directly against the harness state instead of a database.
 */
function get_posts( $args ) {
	$state  = splm_standings_test_state();
	$season_terms = array();
	foreach ( (array) ( $args['tax_query'] ?? array() ) as $clause ) {
		if ( ( $clause['taxonomy'] ?? '' ) === 'sp_season' ) {
			$season_terms = (array) $clause['terms'];
		}
	}
	$after  = $args['date_query'][0]['after'] ?? null;
	$before = $args['date_query'][0]['before'] ?? null;

	$matches = array();
	foreach ( $state->events as $post_id => $event ) {
		if ( $season_terms && ! array_intersect( $season_terms, $event->sp_season_terms ) ) {
			continue;
		}
		if ( $after && $event->post_date < $after ) {
			continue;
		}
		if ( $before && $event->post_date > $before ) {
			continue;
		}
		$matches[] = $event;
	}
	return $matches;
}

function get_post_meta( $post_id, $key, $single = false ) {
	$state = splm_standings_test_state();
	return $state->meta[ (int) $post_id ][ $key ] ?? array();
}

require_once __DIR__ . '/../includes/class-standings.php';

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

echo "=== team_pim_total(): sums a team's players' pim across matching events ===\n\n";

$state = splm_standings_test_state();

$state->events[101] = (object) array( 'ID' => 101, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->events[102] = (object) array( 'ID' => 102, 'post_date' => '2026-10-02', 'sp_season_terms' => array( 5 ) );
$state->events[103] = (object) array( 'ID' => 103, 'post_date' => '2027-03-05', 'sp_season_terms' => array( 9 ) ); // different (playoff) season

$state->meta[101] = array(
	'sp_players' => array(
		200 => array( 0 => array( 'pim' => 2 ) ), // team 200: player 0 is the reserved row, still counted if present
		201 => array( 5 => array( 'pim' => 4 ), 6 => array( 'pim' => 2 ) ), // team 201
	),
);
$state->meta[102] = array(
	'sp_players' => array(
		200 => array( 7 => array( 'pim' => 10 ) ),
		201 => array( 5 => array( 'pim' => 1 ) ),
	),
);
$state->meta[103] = array(
	'sp_players' => array(
		201 => array( 5 => array( 'pim' => 100 ) ), // wrong season, must not count
	),
);

assert_test(
	7 === SPLM_Standings::team_pim_total( 201, array( 5 ) ),
	'team 201, season 5: sums 4+2 (event 101) and 1 (event 102) = 7, ignores the season-9 event'
);

assert_test(
	12 === SPLM_Standings::team_pim_total( 200, array( 5 ) ),
	'team 200, season 5: 2 (event 101) + 10 (event 102) = 12'
);

assert_test(
	0 === SPLM_Standings::team_pim_total( 999, array( 5 ) ),
	'a team with no events returns 0, not null or an error'
);

assert_test(
	1 === SPLM_Standings::team_pim_total( 201, array( 5 ), '2026-09-26', '2026-10-05' ),
	'a date range excludes event 101 (Sep 25, before the range) and keeps only event 102 (1 pim)'
);

echo "\n=== Results ===\n\nPassed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: fatal error — `class-standings.php` does not exist yet.

- [ ] **Step 3: Write minimal implementation**

Create `sportspress-league-manager/includes/class-standings.php`:

```php
<?php
/**
 * Standings and tiebreak engine, extending core SportsPress's SP_League_Table
 * rather than replacing it -- see docs/superpowers/specs/2026-09-10-postseason-playoffs-design.md.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Standings {

	const PIM_COLUMN_SLUG = 'pim';

	/**
	 * Sum one team's players' penalty minutes across every sp_event tagged
	 * with any of $season_ids, optionally restricted to a post_date range.
	 *
	 * sp_players meta shape: array( $team_id => array( $player_id => array(
	 * 'pim' => int, ... ) ) ) -- SportsPress's own box-score storage, read
	 * the same way SPLM_Player_Stats_Aggregator::for_season() does.
	 *
	 * @param int|string $team_id    Team id.
	 * @param array      $season_ids sp_season term ids to include.
	 * @param string|null $from      Inclusive lower post_date bound, 'Y-m-d'.
	 * @param string|null $to        Inclusive upper post_date bound, 'Y-m-d'.
	 * @return int
	 */
	public static function team_pim_total( $team_id, array $season_ids, $from = null, $to = null ) {
		$args = array(
			'post_type'   => 'sp_event',
			'numberposts' => -1,
			'post_status' => 'publish',
			'tax_query'   => array(
				array(
					'taxonomy' => 'sp_season',
					'field'    => 'term_id',
					'terms'    => $season_ids,
				),
			),
		);

		if ( $from || $to ) {
			$args['date_query'] = array(
				array(
					'after'     => $from,
					'before'    => $to,
					'inclusive' => true,
				),
			);
		}

		$events = get_posts( $args );
		$total  = 0;

		foreach ( $events as $event ) {
			$box = get_post_meta( $event->ID, 'sp_players', true );
			if ( ! is_array( $box ) || ! isset( $box[ (int) $team_id ] ) || ! is_array( $box[ (int) $team_id ] ) ) {
				continue;
			}
			foreach ( $box[ (int) $team_id ] as $player_stats ) {
				if ( is_array( $player_stats ) ) {
					$total += (int) ( $player_stats['pim'] ?? 0 );
				}
			}
		}

		return $total;
	}
}
```

Note: the test harness's `get_post_meta()` stub above passes `$single = true` through as `get_post_meta( $event->ID, 'sp_players', true )` in production code, but the stub's own default is `$single = false` and it wraps non-array values in an array when `$single` is falsy — since production code always calls with `true` explicitly, this matches. Also note the test's `$state->meta[101]['sp_players']` is wrapped in an extra outer array (`array( array( 200 => ..., 201 => ... ) )`) to match the stub's `is_array( $value ) ? $value : array( $value )` fallback for a `true`-single lookup returning a plain value — since `get_post_meta(..., true)` should return the raw value directly, adjust the stub's single-branch to return `$value` unwrapped:

```php
function get_post_meta( $post_id, $key, $single = false ) {
	$state = splm_standings_test_state();
	$value = $state->meta[ (int) $post_id ][ $key ] ?? ( $single ? array() : array() );
	return $value;
}
```

and store `$state->meta[101] = array( 'sp_players' => array( 200 => array(...), 201 => array(...) ) );` directly (no extra wrapping array) in the test file — fix the test's stub and fixture data to this simpler, consistent shape before running.

- [ ] **Step 4: Run test to verify it passes**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: `Passed: 4` `Failed: 0`.

- [ ] **Step 5: Commit**

```bash
git add sportspress-league-manager/includes/class-standings.php sportspress-league-manager/tests/test-standings.php
git commit -m "feat(league-manager): add SPLM_Standings::team_pim_total()"
```

---

### Task 2: PIM `sp_column` bootstrap + core equation filter

**Files:**
- Modify: `sportspress-league-manager/includes/class-standings.php`
- Modify: `sportspress-league-manager/tests/test-standings.php`

**Interfaces:**
- Consumes: `SPLM_Standings::team_pim_total()` (Task 1).
- Produces: `SPLM_Standings::ensure_pim_column(): void` (idempotent — creates the `sp_column` post named "PIM"/slug `pim` only if one doesn't already exist), `SPLM_Standings::filter_pim_equation( $solution, $equation, $post_id )` (hook callback for core's `sportspress_equation_solve_for_presets` filter — `$post_id` is the team id in this context, confirmed via `class-sp-league-table.php`'s call site), `SPLM_Standings::register_hooks(): void` (wires the filter).

Core's `sp_solve()` (`sportspress-pro/includes/sportspress/includes/sp-core-functions.php`) calls
`apply_filters( 'sportspress_equation_solve_for_presets', null, $equation, $post_id )` before its generic
array-key equation engine, and returns that value directly if truthy — the integration seam for a new
`$pim`-equation column without editing core. The generic engine's `$totals[$team_id]` array only contains
keys core itself computed, so `$pim` can't be added there without this filter.

- [ ] **Step 1: Write the failing test**

Append to `sportspress-league-manager/tests/test-standings.php`, before the closing `echo "\n=== Results ==="` block (move that block to the very end of the file from here on):

```php
echo "\n=== ensure_pim_column(): idempotent creation ===\n\n";

$state->columns = array(); // post_name => post_id, simulates get_page_by_path()

assert_test(
	1 === SPLM_Standings::ensure_pim_column(),
	'first call creates the column and returns its new post id'
);
assert_test(
	1 === count( $state->columns ),
	'exactly one sp_column post named pim now exists'
);
assert_test(
	1 === SPLM_Standings::ensure_pim_column(),
	'second call is a no-op: returns the SAME id, does not create a duplicate'
);
assert_test(
	1 === count( $state->columns ),
	'still exactly one sp_column post after calling ensure_pim_column() twice'
);

echo "\n=== filter_pim_equation(): answers \$pim equations, ignores everything else ===\n\n";

assert_test(
	null === SPLM_Standings::filter_pim_equation( null, '$w+$l', 201 ),
	'a non-pim equation is left alone (returns the passed-through null unchanged)'
);

$state->events[201] = (object) array( 'ID' => 201, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[201]   = array( 'sp_players' => array( 301 => array( 9 => array( 'pim' => 6 ) ) ) );
$state->table_season_terms = array( 5 ); // simulates the CURRENT sp_table post's assigned sp_season terms

assert_test(
	6 === SPLM_Standings::filter_pim_equation( null, '$pim', 301 ),
	'a $pim equation resolves to that team\'s pim total for whatever season(s) the current table is scoped to'
);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: FAIL — `ensure_pim_column`, `filter_pim_equation` undefined.

- [ ] **Step 3: Write minimal implementation**

Append to `SPLM_Standings` in `class-standings.php` (before the closing `}`):

```php
	/**
	 * Create the "PIM" sp_column once, idempotently. Safe to call on every
	 * request (e.g. from register_hooks()) -- get_page_by_path() makes the
	 * existence check free after the first call.
	 *
	 * @return int The column's post id (existing or newly created).
	 */
	public static function ensure_pim_column() {
		$existing = get_page_by_path( self::PIM_COLUMN_SLUG, OBJECT, 'sp_column' );
		if ( $existing ) {
			return $existing->ID;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => 'sp_column',
				'post_title'  => 'PIM',
				'post_name'   => self::PIM_COLUMN_SLUG,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $id, 'sp_equation', '$' . self::PIM_COLUMN_SLUG );
		update_post_meta( $id, 'sp_precision', 0 );
		update_post_meta( $id, 'sp_order', 'ASC' ); // fewer penalty minutes is better
		update_post_meta( $id, 'sp_priority', self::next_priority() );

		return $id;
	}

	/**
	 * The next unused sp_priority value, so the PIM column sorts after every
	 * existing priority column instead of colliding with one.
	 *
	 * @return int
	 */
	private static function next_priority() {
		$columns = get_posts(
			array(
				'post_type'   => 'sp_column',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);

		$max = 0;
		foreach ( $columns as $column ) {
			$priority = (int) get_post_meta( $column->ID, 'sp_priority', true );
			$max      = max( $max, $priority );
		}

		return $max + 1;
	}

	/**
	 * Hook for core's `sportspress_equation_solve_for_presets` filter
	 * (sp_solve(), sportspress-pro/includes/sportspress/includes/sp-core-functions.php).
	 * Answers a plain "$pim" equation directly; every other equation is left
	 * for core's own generic engine by returning $solution unchanged.
	 *
	 * $post_id is the TEAM id in this filter's calling context, not a post
	 * type of any kind -- confirmed against SP_League_Table's call site.
	 *
	 * @param mixed  $solution Value from an earlier filter, or null.
	 * @param string $equation The column's raw sp_equation string.
	 * @param int    $post_id  Team id being scored.
	 * @return mixed
	 */
	public static function filter_pim_equation( $solution, $equation, $post_id ) {
		if ( '$' . self::PIM_COLUMN_SLUG !== trim( (string) $equation ) ) {
			return $solution;
		}

		return self::team_pim_total( $post_id, self::current_table_season_ids() );
	}

	/**
	 * The sp_season term ids assigned to whichever sp_table post is
	 * currently being rendered/queried. Placeholder until Task 3 introduces
	 * the managed scratch table this reads from.
	 *
	 * @return array
	 */
	private static function current_table_season_ids() {
		return self::$current_season_ids ?? array();
	}

	/** @var array|null Set by rank_by_points_h2h() (Task 3) around each SP_League_Table call. */
	private static $current_season_ids = null;

	/**
	 * Wire this class's core integration filters. Call once, e.g. from the
	 * plugin's init() after the autoloader registers.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'sportspress_equation_solve_for_presets', array( __CLASS__, 'filter_pim_equation' ), 10, 3 );
	}
```

Add the corresponding stubs to the test file, immediately after the existing `get_post_meta()` stub:

```php
function update_post_meta( $post_id, $key, $value ) {
	$state = splm_standings_test_state();
	$state->meta[ (int) $post_id ][ $key ] = $value;
	return true;
}

function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
	$state = splm_standings_test_state();
	if ( ! isset( $state->columns[ $path ] ) ) {
		return null;
	}
	return (object) array( 'ID' => $state->columns[ $path ] );
}

function wp_insert_post( $post_array ) {
	$state              = splm_standings_test_state();
	$id                  = count( $state->columns ) + 1;
	$state->columns[ $post_array['post_name'] ] = $id;
	return $id;
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	return true; // register_hooks() is exercised by calling filter_pim_equation() directly in tests, not through apply_filters().
}
```

`filter_pim_equation()`'s `current_table_season_ids()` reads `self::$current_season_ids`, a private static — the test needs a way to set it. Add one more tiny test-only seam: a public setter used only by tests and by Task 3's real caller:

```php
	/**
	 * Sets the season scope filter_pim_equation() reads. Called by
	 * rank_by_points_h2h() (Task 3) around each SP_League_Table query, and
	 * directly by tests.
	 *
	 * @param array $season_ids
	 * @return void
	 */
	public static function set_current_table_season_ids( array $season_ids ) {
		self::$current_season_ids = $season_ids;
	}
```

Update `current_table_season_ids()` to just `return self::$current_season_ids ?? array();` (already written above) and add one line before the `filter_pim_equation()` assertions in the test file:

```php
SPLM_Standings::set_current_table_season_ids( array( 5 ) );
```

(replacing the earlier placeholder `$state->table_season_terms = array( 5 );` line, which the production code never actually reads — remove that line).

- [ ] **Step 4: Run test to verify it passes**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: `Passed: 10` `Failed: 0`.

- [ ] **Step 5: Commit**

```bash
git add sportspress-league-manager/includes/class-standings.php sportspress-league-manager/tests/test-standings.php
git commit -m "feat(league-manager): add PIM sp_column bootstrap + core equation filter hook"
```

---

### Task 3: Scratch `sp_table` post + `rank_by_points_h2h()`

**Files:**
- Modify: `sportspress-league-manager/includes/class-standings.php`
- Modify: `sportspress-league-manager/tests/test-standings.php`

**Interfaces:**
- Consumes: `set_current_table_season_ids()` (Task 2, now used for real instead of only by tests).
- Produces: `SPLM_Standings::rank_by_points_h2h( array $team_ids, $season_id, $from = null, $to = null ): array` — returns `$team_ids` reordered best-to-worst using core `SP_League_Table`'s own points/priority-column/H2H sort, scoped to one season and an optional date range.

Core's `SP_League_Table` (`sportspress-pro/includes/sportspress/includes/class-sp-league-table.php`)
reads its league/season scope from **its own post's taxonomy terms** (`sp_get_the_term_ids( $this->ID,
'sp_season' )`), and its date range from three plain public properties (`$this->date`, `$this->from`,
`$this->to` — set directly on the object, not read from meta, confirmed by reading
`SP_Secondary_Post::range()`/`__construct()`: only `$this->date` is meta-backed via `__get()`, `$from`/
`$to` are plain properties a caller sets after construction). So: maintain ONE reusable, hidden `sp_table`
post (never shown on any front-end table list), reassign its `sp_season`/`sp_league` terms per call via
`wp_set_object_terms()`, instantiate `SP_League_Table` on it, override `$this->date`/`from`/`to` when a
date range is given, then call `->data( false, $team_ids )` (the `$team_ids` param already restricts the
computation to a specific subset of teams — exactly what a single division's bracket needs).

- [ ] **Step 1: Write the failing test**

Append to `sportspress-league-manager/tests/test-standings.php`:

```php
echo "\n=== rank_by_points_h2h(): orders team ids using SP_League_Table ===\n\n";

class SPLM_Standings_Test_Table {
	public $ID;
	public $date = 0;
	public $from = 'now';
	public $to   = 'now';
	public function __construct( $id ) {
		$this->ID = $id;
	}
	public function data( $admin = false, $team_ids = null ) {
		// Stand-in for SP_League_Table::data(): returns team_ids in REVERSE
		// order, so a test can tell the wrapper actually used this result
		// rather than returning $team_ids unchanged.
		return array_fill_keys( array_reverse( $team_ids ), array() );
	}
}

if ( ! class_exists( 'SP_League_Table' ) ) {
	class_alias( 'SPLM_Standings_Test_Table', 'SP_League_Table' );
}

$state->object_terms = array(); // post_id => array( taxonomy => term_ids )

assert_test(
	array( 30, 20, 10 ) === SPLM_Standings::rank_by_points_h2h( array( 10, 20, 30 ), 5 ),
	'reorders team_ids using SP_League_Table::data() output order'
);
assert_test(
	array( 5 ) === ( $state->object_terms[ SPLM_Standings::scratch_table_id() ]['sp_season'] ?? null ),
	'assigns the requested season id to the scratch table before querying'
);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: FAIL — `rank_by_points_h2h`, `scratch_table_id` undefined.

- [ ] **Step 3: Write minimal implementation**

Append to `SPLM_Standings`:

```php
	/**
	 * The one hidden sp_table post this class reuses for every ad-hoc
	 * ranking query. Created once; never shown on any front-end table list
	 * (no menu_order/visibility meta is set because nothing renders it
	 * directly -- only SP_League_Table::data() reads it).
	 *
	 * @return int
	 */
	public static function scratch_table_id() {
		static $id = null;
		if ( null !== $id ) {
			return $id;
		}

		$existing = get_page_by_path( 'splm-standings-scratch', OBJECT, 'sp_table' );
		if ( $existing ) {
			$id = $existing->ID;
			return $id;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => 'sp_table',
				'post_title'  => 'SPLM Standings (internal, do not display)',
				'post_name'   => 'splm-standings-scratch',
				'post_status' => 'private',
			)
		);

		return $id;
	}

	/**
	 * Reorder $team_ids best-to-worst using core SP_League_Table's own
	 * points/priority-column/head-to-head sort (the PIM column from Task 2
	 * participates automatically once it exists), scoped to one season and
	 * an optional date range.
	 *
	 * @param array      $team_ids Team ids to rank.
	 * @param int|string $season_id sp_season term id.
	 * @param string|null $from    Inclusive lower post_date bound, 'Y-m-d'.
	 * @param string|null $to      Inclusive upper post_date bound, 'Y-m-d'.
	 * @return array $team_ids reordered.
	 */
	public static function rank_by_points_h2h( array $team_ids, $season_id, $from = null, $to = null ) {
		$table_id = self::scratch_table_id();
		wp_set_object_terms( $table_id, array( $season_id ), 'sp_season' );

		self::set_current_table_season_ids( array( $season_id ) );

		$table = new SP_League_Table( $table_id );
		if ( $from || $to ) {
			$table->date = 'range';
			$table->from = $from;
			$table->to   = $to;
		}

		$data = $table->data( false, $team_ids );

		return array_keys( $data );
	}
```

Add the matching test stubs, after the existing `wp_insert_post()` stub:

```php
function wp_set_object_terms( $post_id, $terms, $taxonomy ) {
	$state = splm_standings_test_state();
	$state->object_terms[ $post_id ][ $taxonomy ] = $terms;
	return $terms;
}
```

`get_page_by_path()`'s existing stub already handles any `$post_type` generically via the shared
`$state->columns` map keyed by path — for this task's `'sp_table'` lookups to not collide with Task 2's
`'sp_column'` lookups on the same slug string, key the stub's storage by `$post_type . ':' . $path`
instead of by `$path` alone. Update the stub (in place, this changes Task 2's behavior too but is
backward compatible since Task 2's tests use a different slug):

```php
function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
	$state = splm_standings_test_state();
	$key   = $post_type . ':' . $path;
	if ( ! isset( $state->columns[ $key ] ) ) {
		return null;
	}
	return (object) array( 'ID' => $state->columns[ $key ] );
}

function wp_insert_post( $post_array ) {
	$state = splm_standings_test_state();
	$id    = count( $state->columns ) + 1;
	$key   = $post_array['post_type'] . ':' . $post_array['post_name'];
	$state->columns[ $key ] = $id;
	return $id;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: `Passed: 12` `Failed: 0`.

- [ ] **Step 5: Commit**

```bash
git add sportspress-league-manager/includes/class-standings.php sportspress-league-manager/tests/test-standings.php
git commit -m "feat(league-manager): add scratch sp_table + rank_by_points_h2h()"
```

---

### Task 4: Persisted coin flip

**Files:**
- Modify: `sportspress-league-manager/includes/class-standings.php`
- Modify: `sportspress-league-manager/tests/test-standings.php`

**Interfaces:**
- Produces: `SPLM_Standings::coin_flip( $team_a_id, $team_b_id, $context_key ): int` — returns whichever of the two team ids "wins" for this `$context_key` (e.g. `"div1-2027-final-rank-3"`), deciding once and persisting the result so repeated calls (including a later standings recompute) return the same winner.

- [ ] **Step 1: Write the failing test**

Append to `sportspress-league-manager/tests/test-standings.php`:

```php
echo "\n=== coin_flip(): decides once, then stays put ===\n\n";

$winner_first_call = SPLM_Standings::coin_flip( 10, 20, 'div1-2027-final-rank-3' );
assert_test(
	10 === $winner_first_call || 20 === $winner_first_call,
	'the winner is one of the two teams involved'
);
assert_test(
	$winner_first_call === SPLM_Standings::coin_flip( 10, 20, 'div1-2027-final-rank-3' ),
	'a second call with the same context_key returns the SAME winner, not a fresh flip'
);
assert_test(
	$winner_first_call === SPLM_Standings::coin_flip( 20, 10, 'div1-2027-final-rank-3' ),
	'the winner is stable even if the two team ids are passed in reverse order'
);

$other_context_winner = SPLM_Standings::coin_flip( 10, 20, 'div1-2027-final-rank-5' );
assert_test(
	$other_context_winner === SPLM_Standings::coin_flip( 10, 20, 'div1-2027-final-rank-5' ),
	'a different context_key is ALSO stable across repeated calls, independent of the first'
);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: FAIL — `coin_flip` undefined.

- [ ] **Step 3: Write minimal implementation**

Append to `SPLM_Standings`:

```php
	/**
	 * Decide (once) which of two tied teams wins a final tiebreak, and
	 * persist the decision so it never changes on a later recompute.
	 *
	 * @param int|string $team_a_id
	 * @param int|string $team_b_id
	 * @param string     $context_key Stable identifier for THIS specific tie
	 *                                (e.g. "div1-2027-final-rank-3") -- must
	 *                                be unique per tie being broken, shared
	 *                                across repeated calls for the same one.
	 * @return int|string The winning team id.
	 */
	public static function coin_flip( $team_a_id, $team_b_id, $context_key ) {
		$option_name = 'splm_standings_coin_flip_' . $context_key;
		$stored      = get_option( $option_name, null );

		if ( null !== $stored ) {
			return $stored;
		}

		$winner = ( wp_rand( 0, 1 ) === 0 ) ? $team_a_id : $team_b_id;
		update_option( $option_name, $winner, false );

		return $winner;
	}
```

Add the matching test stubs:

```php
function get_option( $name, $default = false ) {
	$state = splm_standings_test_state();
	return array_key_exists( $name, $state->options ) ? $state->options[ $name ] : $default;
}

function update_option( $name, $value ) {
	$state                    = splm_standings_test_state();
	$state->options[ $name ] = $value;
	return true;
}

function wp_rand( $min, $max ) {
	return random_int( $min, $max );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: `Passed: 16` `Failed: 0`.

- [ ] **Step 5: Commit**

```bash
git add sportspress-league-manager/includes/class-standings.php sportspress-league-manager/tests/test-standings.php
git commit -m "feat(league-manager): add persisted coin_flip() tiebreak"
```

---

### Task 5: `rank()` public entry point

**Files:**
- Modify: `sportspress-league-manager/includes/class-standings.php`
- Modify: `sportspress-league-manager/tests/test-standings.php`

**Interfaces:**
- Consumes: `rank_by_points_h2h()` (Task 3), `team_pim_total()` (Task 1), `coin_flip()` (Task 4).
- Produces: `SPLM_Standings::rank( array $team_ids, $season_id, array $combined_pim_season_ids = array(), $from = null, $to = null ): array` — the one entry point later phases (seed resolution) call. Returns `$team_ids` fully ordered best-to-worst: `rank_by_points_h2h()`'s order first; any teams it left in an unresolved tie (SP_League_Table gives tied teams the same `pos` — but `data()`'s return array here is just team_id => row with no guaranteed tie-grouping signal exposed to the caller, so this method re-detects ties itself by re-running `rank_by_points_h2h()` is unnecessary; instead detect ties directly: two teams are tied if `rank_by_points_h2h()` returned them adjacent AND swapping them and re-ranking that pair alone still returns them in the same relative order both ways) get re-ordered by combined PIM (summed over `$combined_pim_season_ids`, e.g. both the regular and playoff `sp_season` term ids, when the caller wants that; pass `array($season_id)` to mean "just this one season's PIM," matching what `rank_by_points_h2h()` already used); anything still tied after that goes through `coin_flip()`.

Simpler, robust tie-detection than the parenthetical above: call `rank_by_points_h2h()` on each **adjacent pair** independently (a 2-team list either returns `[a,b]` or `[b,a]`) is expensive and redundant since `rank_by_points_h2h()` already fully sorted the list once. Use the actual, simpler signal already available: **two adjacent teams from `rank_by_points_h2h()`'s output are tied if and only if re-ranking just those two together returns them in the OPPOSITE order from a second call** would be non-deterministic and wrong to rely on. Do not implement tie-detection via repeated re-ranking at all — instead, expose the tie information `SP_League_Table` already computes (`$this->tiebreakers`, a `pos => [team_ids]` map, built by `calculate_pos()`) directly from `rank_by_points_h2h()` instead of discarding it.

- [ ] **Step 1: Update `rank_by_points_h2h()` to also return tie groups**

This changes Task 3's return type — update its docblock and body in `class-standings.php`:

```php
	/**
	 * Reorder $team_ids best-to-worst using core SP_League_Table's own
	 * points/priority-column/head-to-head sort (the PIM column from Task 2
	 * participates automatically once it exists), scoped to one season and
	 * an optional date range.
	 *
	 * @param array      $team_ids Team ids to rank.
	 * @param int|string $season_id sp_season term id.
	 * @param string|null $from    Inclusive lower post_date bound, 'Y-m-d'.
	 * @param string|null $to      Inclusive upper post_date bound, 'Y-m-d'.
	 * @return array{order: array, ties: array} 'order': $team_ids reordered.
	 *         'ties': list of arrays, each a group of 2+ team ids core left
	 *         genuinely tied (same position) after its own sort -- read from
	 *         SP_League_Table's own $tiebreakers property instead of
	 *         re-deriving it.
	 */
	public static function rank_by_points_h2h( array $team_ids, $season_id, $from = null, $to = null ) {
		$table_id = self::scratch_table_id();
		wp_set_object_terms( $table_id, array( $season_id ), 'sp_season' );

		self::set_current_table_season_ids( array( $season_id ) );

		$table = new SP_League_Table( $table_id );
		if ( $from || $to ) {
			$table->date = 'range';
			$table->from = $from;
			$table->to   = $to;
		}

		$data  = $table->data( false, $team_ids );
		$order = array_keys( $data );

		$ties = array();
		foreach ( (array) ( $table->tiebreakers ?? array() ) as $group ) {
			if ( count( $group ) > 1 ) {
				$ties[] = array_values( $group );
			}
		}

		return array(
			'order' => $order,
			'ties'  => $ties,
		);
	}
```

- [ ] **Step 2: Update Task 3's test to match the new return shape**

In `test-standings.php`, `SPLM_Standings_Test_Table::data()` needs to populate `$this->tiebreakers` the
way real `SP_League_Table` does, and the Task 3 assertions need updating for the new `['order' => ...,
'ties' => ...]` shape. Replace the `SPLM_Standings_Test_Table` class and its two assertions with:

```php
class SPLM_Standings_Test_Table {
	public $ID;
	public $date = 0;
	public $from = 'now';
	public $to   = 'now';
	public $tiebreakers = array();
	public function __construct( $id ) {
		$this->ID = $id;
	}
	public function data( $admin = false, $team_ids = null ) {
		$reversed          = array_reverse( $team_ids );
		$this->tiebreakers = array( 0 => $reversed ); // simulate: every team in this call tied at pos 0
		return array_fill_keys( $reversed, array() );
	}
}

$ranked = SPLM_Standings::rank_by_points_h2h( array( 10, 20, 30 ), 5 );
assert_test(
	array( 30, 20, 10 ) === $ranked['order'],
	'reorders team_ids using SP_League_Table::data() output order'
);
assert_test(
	array( array( 30, 20, 10 ) ) === $ranked['ties'],
	'exposes SP_League_Table\'s own tiebreakers groups (only groups with 2+ teams)'
);
assert_test(
	array( 5 ) === ( $state->object_terms[ SPLM_Standings::scratch_table_id() ]['sp_season'] ?? null ),
	'assigns the requested season id to the scratch table before querying'
);
```

- [ ] **Step 3: Run to confirm Task 3's tests still pass with the new shape**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: same 16 assertions as before, all still passing (this task's own new tests come next).

- [ ] **Step 4: Make Task 3's test double configurable, then write the failing test**

A single test process can only alias `SP_League_Table` to one class (`class_alias()` cannot be
redefined). Since Task 5's tests need `data()` to simulate different tie situations (no ties, one tied
pair, etc.), go back and make `SPLM_Standings_Test_Table` (introduced in Task 3, Step 1) configurable via
a static switch instead of writing a second stub class. Replace the whole `SPLM_Standings_Test_Table`
class definition from Task 3 with this version:

```php
class SPLM_Standings_Test_Table {
	public $ID;
	public $date = 0;
	public $from = 'now';
	public $to   = 'now';
	public $tiebreakers = array();

	/**
	 * Set by tests before constructing, to control what data() reports as
	 * tied. Default (Task 3's original behavior): the whole input list
	 * tied at position 0.
	 */
	public static $simulated_ties = null;

	public function __construct( $id ) {
		$this->ID = $id;
	}
	public function data( $admin = false, $team_ids = null ) {
		$reversed = array_reverse( $team_ids );

		$this->tiebreakers = ( null === self::$simulated_ties )
			? array( 0 => $reversed )
			: self::$simulated_ties;

		return array_fill_keys( $reversed, array() );
	}
}

if ( ! class_exists( 'SP_League_Table' ) ) {
	class_alias( 'SPLM_Standings_Test_Table', 'SP_League_Table' );
}
```

Task 3's existing assertions rely on the default (`null` → "tie the whole input list"), so they need no
changes. Task 5's new tests below set `SPLM_Standings_Test_Table::$simulated_ties` explicitly before each
call, then reset it to `null` afterward so later tests (if any) aren't affected by a leftover value.

Now append the new test to `sportspress-league-manager/tests/test-standings.php`:

```php
echo "\n=== rank(): points/H2H order stands when nothing is tied ===\n\n";

SPLM_Standings_Test_Table::$simulated_ties = array(); // nothing tied

$state->events[301] = (object) array( 'ID' => 301, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[301]   = array( 'sp_players' => array( 10 => array( 1 => array( 'pim' => 0 ) ) ) );

assert_test(
	array( 30, 20, 10 ) === SPLM_Standings::rank( array( 10, 20, 30 ), 5 ),
	'with no ties, rank() returns rank_by_points_h2h()\'s order unchanged -- PIM/coin-flip never consulted'
);

echo "\n=== rank(): a tied pair is re-ordered by combined PIM ===\n\n";

SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 20, 30 ) ); // 20 and 30 tied, 10 clear

$state->events[401] = (object) array( 'ID' => 401, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[401]   = array(
	'sp_players' => array(
		20 => array( 1 => array( 'pim' => 8 ) ),
		30 => array( 1 => array( 'pim' => 2 ) ), // fewer pim, should rank ABOVE 20 despite the reversed order() from data()
	),
);

assert_test(
	array( 30, 20, 10 ) === SPLM_Standings::rank( array( 10, 20, 30 ), 5, array( 5 ) ),
	'team 30 (2 pim) ranks above team 20 (8 pim) once combined PIM breaks their tie'
);

echo "\n=== rank(): a pair still tied after PIM goes to a persisted coin flip ===\n\n";

SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 40, 50 ) );

$state->events[501] = (object) array( 'ID' => 501, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[501]   = array(
	'sp_players' => array(
		40 => array( 1 => array( 'pim' => 4 ) ),
		50 => array( 1 => array( 'pim' => 4 ) ), // identical pim -- still tied
	),
);

$result       = SPLM_Standings::rank( array( 40, 50 ), 5, array( 5 ) );
$coin_winner  = get_option( 'splm_standings_coin_flip_rank-tie-40-50-season-5' );
assert_test(
	null !== $coin_winner,
	'a genuinely equal PIM tie falls through to coin_flip(), which persists a winner'
);
assert_test(
	$result[0] === $coin_winner,
	"the coin flip's winner is placed first"
);
```

- [ ] **Step 5: Run test to verify it fails**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: FAIL — `rank()` undefined.

- [ ] **Step 6: Write minimal implementation**

Append to `SPLM_Standings`:

```php
	/**
	 * The one entry point later phases (seed resolution) call: a full,
	 * best-to-worst ranking of $team_ids for one season/date-range, with
	 * ties broken by combined PIM and then a persisted coin flip.
	 *
	 * @param array       $team_ids                Team ids to rank.
	 * @param int|string  $season_id                sp_season term id the PRIMARY ranking (points/H2H) is scoped to.
	 * @param array       $combined_pim_season_ids  sp_season term ids to SUM PIM across for tiebreaking (e.g. both
	 *                                               the regular and playoff seasons). Defaults to just $season_id.
	 * @param string|null $from
	 * @param string|null $to
	 * @return array $team_ids, fully ordered best-to-worst.
	 */
	public static function rank( array $team_ids, $season_id, array $combined_pim_season_ids = array(), $from = null, $to = null ) {
		if ( empty( $combined_pim_season_ids ) ) {
			$combined_pim_season_ids = array( $season_id );
		}

		$ranked = self::rank_by_points_h2h( $team_ids, $season_id, $from, $to );
		$order  = $ranked['order'];

		foreach ( $ranked['ties'] as $tied_group ) {
			$by_pim = array();
			foreach ( $tied_group as $team_id ) {
				$by_pim[ $team_id ] = self::team_pim_total( $team_id, $combined_pim_season_ids, $from, $to );
			}
			asort( $by_pim ); // fewer pim first

			$pim_values     = array_values( $by_pim );
			$still_tied_ids = array();
			foreach ( $by_pim as $team_id => $pim ) {
				$count = count( array_keys( $pim_values, $pim, true ) );
				if ( $count > 1 ) {
					$still_tied_ids[] = $team_id;
				}
			}

			$resolved_order = array_keys( $by_pim );
			if ( count( $still_tied_ids ) === 2 ) {
				list( $a, $b ) = $still_tied_ids;
				$context = 'rank-tie-' . min( $a, $b ) . '-' . max( $a, $b ) . '-season-' . $season_id;
				$winner  = self::coin_flip( $a, $b, $context );
				$loser   = ( $winner === $a ) ? $b : $a;

				$resolved_order = array();
				foreach ( array_keys( $by_pim ) as $team_id ) {
					if ( $team_id === $a || $team_id === $b ) {
						continue;
					}
					$resolved_order[] = $team_id;
				}
				// Splice winner/loser back in at the position the tied pair occupied.
				$insert_at = array_search( $a, array_keys( $by_pim ), true );
				array_splice( $resolved_order, $insert_at, 0, array( $winner, $loser ) );
			}

			// Replace the tied group's positions in $order with the resolved order.
			$positions = array();
			foreach ( $tied_group as $team_id ) {
				$positions[] = array_search( $team_id, $order, true );
			}
			sort( $positions );
			foreach ( $positions as $i => $position ) {
				$order[ $position ] = $resolved_order[ $i ];
			}
		}

		return $order;
	}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php sportspress-league-manager/tests/test-standings.php`
Expected: `Passed: 23` `Failed: 0`.

- [ ] **Step 8: Commit**

```bash
git add sportspress-league-manager/includes/class-standings.php sportspress-league-manager/tests/test-standings.php
git commit -m "feat(league-manager): add SPLM_Standings::rank() combining points/H2H, PIM, coin flip"
```

---

### Task 6: Wire into the plugin, register in the suite, full verification

**Files:**
- Modify: `sportspress-league-manager/includes/class-autoloader.php`
- Modify: `sportspress-league-manager/sportspress-league-manager.php`
- Modify: `run-all-tests.sh`

**Interfaces:**
- Consumes: `SPLM_Standings::register_hooks()` (Task 2).
- Produces: nothing new — this task only wires existing pieces into the real plugin bootstrap and the shared test runner.

- [ ] **Step 1: Register the class in the autoloader**

In `sportspress-league-manager/includes/class-autoloader.php`, add one line to `$class_map` (alphabetical position, after the `SPLM_SportsPress_Data` entry):

```php
			'SPLM_SportsPress_Data'      => $base . 'class-sportspress-data.php',
			'SPLM_Standings'             => $base . 'class-standings.php',
```

- [ ] **Step 2: Wire `register_hooks()` into plugin init**

In `sportspress-league-manager/sportspress-league-manager.php`, inside `init()`, immediately after `SPLM_Autoloader::init();`:

```php
		require_once SPLM_PLUGIN_PATH . 'includes/class-autoloader.php';
		SPLM_Autoloader::init();

		SPLM_Standings::register_hooks();
		SPLM_Standings::ensure_pim_column();
```

- [ ] **Step 3: Register the test in the shared runner**

In `run-all-tests.sh`, add one line alongside the other `sportspress-league-manager/tests/test-*.php` entries (after the existing `test-dashboard-page.php` line, matching this repo's per-plugin grouping):

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-standings.php"
```

- [ ] **Step 4: Run the full suite**

Run: `bash run-all-tests.sh`
Expected: every suite passes, including the new `test-standings.php` (23 assertions) and all pre-existing suites (82 suites passing before this phase, now 83).

- [ ] **Step 5: Run the complexity check**

Run: `python3 -m lizard -C 8 -L 50 sportspress-league-manager/includes/class-standings.php`
Expected: no warnings over the threshold. If `rank()` (Task 5, Step 6) trips the cyclomatic-complexity
threshold, extract its tie-resolution body (the `foreach ( $ranked['ties'] as $tied_group )` loop) into a
private `resolve_tied_group( array $tied_group, array $by_pim_seasons, $season_id, $from, $to ): array`
method that returns just the resolved sub-order for one group, called once per group from `rank()` — this
keeps `rank()` itself at low complexity and is a legitimate decomposition, not a workaround.

- [ ] **Step 6: Open the PR**

```bash
git checkout -b feat/splm-standings-phase1
git push -u origin feat/splm-standings-phase1
gh pr create --title "feat(league-manager): SPLM_Standings -- points/H2H/PIM/coin-flip ranking engine" --body "$(cat <<'EOF'
## Summary
- Phase 1 of the postseason/playoffs design (docs/superpowers/specs/2026-09-10-postseason-playoffs-design.md): a standings/tiebreak engine, `SPLM_Standings`, that extends core SportsPress's SP_League_Table rather than replacing it.
- New PIM sp_column (didn't exist before), wired via SportsPress core's `sportspress_equation_solve_for_presets` filter seam -- no core edits, and it renders correctly on real, site-visible League Table pages, not just internally.
- Native head-to-head tiebreaking (already existed in core) plus a new persisted coin-flip for the rare remaining tie.
- `rank()` is the one entry point future phases (seed placeholder resolution) will call.

## Test plan
- [x] `bash run-all-tests.sh` -- all suites pass, including the new test-standings.php
- [x] `python3 -m lizard -C 8 -L 50` -- no complexity regressions
EOF
)"
```

- [ ] **Step 7: Wait for CI, fix any findings, then stop**

Do not merge. Report the PR URL and CI status back and wait for an explicit instruction to merge, per this repo's standing convention.

---

## Self-review notes (already applied above, kept here for the executor's awareness)

- Task 1's first test draft had two internally-inconsistent assertions (comment vs. expected value); the corrected versions are the ones to actually use — don't transcribe the crossed-out draft.
- Task 3 initially returned a bare reordered array from `rank_by_points_h2h()`; Task 5 needed the tie
  information SP_League_Table already computes, so Task 5 Step 1 revises Task 3's return shape to
  `['order' => ..., 'ties' => ...]` and Step 2 updates Task 3's own test to match — implementers should
  apply Task 3 in its ORIGINAL form first (per its own steps), then apply Task 5 Steps 1-2 as a real edit
  to already-committed code, not skip straight to the final shape.
- `get_page_by_path()`'s test stub is shared by Tasks 2 and 3 for two different post types (`sp_column`,
  `sp_table`) on the same string key space; Task 3 Step 3 revises the stub to key by `post_type:path` —
  apply that revision even though Task 2's own tests still pass either way, since Task 3's tests need it.
