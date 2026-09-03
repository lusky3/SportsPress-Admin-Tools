# Discipline Consequences Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make crossing a penalty-minute threshold notify the player — a warning at the lower tiers, a suspension at the upper ones — with per-severity control over whether that mail sends automatically, queues for a human to release, or is switched off.

**Architecture:** `SPLM_Penalty_Watch` gains an actionable `consequence` per tier and a new `matches()` that exposes every matched tier instead of one flag per scope. A new `splm_discipline_notice` table persists one row per decision, with a single re-fire predicate over its latest row per (player, season, ack_key). A daily `SPAT_Lock`-guarded cron pass writes rows and, in `automatic` mode, sends. Four REST routes serve both queue surfaces — a technical WP-admin tab and a simplified React page — so release logic exists exactly once.

**Tech Stack:** WordPress 6.4+, PHP 8.1+, `@wordpress/element` + `@wordpress/api-fetch` (built with `wp-scripts`), standalone `assert_test` PHP test harness (no WordPress bootstrap).

**Spec:** [`docs/superpowers/specs/2026-09-03-discipline-consequences-design.md`](../specs/2026-09-03-discipline-consequences-design.md)

---

## Global Constraints

Copied from the spec. Every task's requirements implicitly include this section.

- **Both delivery modes default to `disabled`.** Upgrading must never begin mailing players.
- **All three values — `disabled`, `queued`, `automatic` — are selectable and fully functional on delivery.** None is a stub; none is deferred.
- **Notices are never created by a read path.** Only the scheduled evaluation pass writes them. A convener opening the Leaders page must not mail anyone.
- **One authorization path.** Both UI surfaces act through the same REST routes; no `admin_post_*` handler.
- **Notice state never binds to an event ID.** A suspension is *N games owed*, never "the game on the 8th".
- **All new timestamps are UTC**, written with `gmdate( 'Y-m-d H:i:s' )`. See the UTC warning below — the nearest precedent gets this wrong.
- All new DB access goes through `$wpdb->prepare()`.
- Text domain is `sportspress-league-manager` on every string.
- Tests are standalone PHP with no WordPress bootstrap, using the repo's `assert_test` convention.

### ⚠️ UTC: do not copy the nearest precedent

`SPLM_Discipline_Database::acknowledge()` (`sportspress-league-manager/includes/class-discipline-database.php:177`) writes `current_time( 'mysql' )`, which is **site-local time**. That is the closest existing table to the one this plan adds, and copying it would violate the spec.

Every new timestamp uses `SPLM_Discipline_Notice_Database::now()`, which returns `gmdate( 'Y-m-d H:i:s' )`. Do not "fix" the existing ack table in this work — it is out of scope and changing it would reinterpret existing rows.

### ⚠️ Three registries silently swallow new code

Adding a file is not enough in this repo. Each of these is a hard requirement, and omitting one produces a silent no-op rather than an error:

1. **`sportspress-league-manager/includes/class-autoloader.php`** — `$class_map` is an explicit array. A `SPLM_*` class absent from it never loads, and every `class_exists()` probe for it returns false.
2. **`run-all-tests.sh`** — there is no test discovery. A test file without its own `run_test "$SCRIPT_DIR/..."` line never runs.
3. **`sportspress-league-manager.php` → `load_enabled_modules()`** — a class whose constructor registers hooks must be instantiated there or its hooks never register.

---

## File Structure

### New PHP classes — `sportspress-league-manager/includes/`

| File | Class | Responsibility |
|---|---|---|
| `class-discipline-notice-database.php` | `SPLM_Discipline_Notice_Database` | Table schema, UTC clock, CRUD, the latest-row lookup the predicate needs |
| `class-discipline-notice.php` | `SPLM_Discipline_Notice` | Pure decision core: modes, the re-fire predicate, one-notice-per-pass selection |
| `class-discipline-notice-recipients.php` | `SPLM_Discipline_Notice_Recipients` | Player address chain, captain chain, Bcc assembly, and the reverse email→players lookup privacy needs |
| `class-discipline-notice-mail.php` | `SPLM_Discipline_Notice_Mail` | Subject and body construction, next-game resolution, the send itself |
| `class-discipline-notice-pass.php` | `SPLM_Discipline_Notice_Pass` | The daily cron pass and baselining |
| `class-discipline-notice-rest.php` | `SPLM_Discipline_Notice_REST` | The four routes both surfaces call |
| `class-discipline-notice-admin.php` | `SPLM_Discipline_Notice_Admin` | The technical WP-admin tab |
| `class-discipline-notice-privacy.php` | `SPLM_Discipline_Notice_Privacy` | League-manager-owned GDPR exporter and eraser |

### Modified PHP

| File | Change |
|---|---|
| `includes/class-penalty-watch.php` | Unseal `consequence`, add `games`, add `matches()` and `consequence_rank()` |
| `includes/class-admin.php` | Register and render the mode/cc settings; add consequence + games inputs to the tier table |
| `includes/class-autoloader.php` | Eight new class-map entries |
| `includes/class-discipline-database.php` | Allow the `notice_sent` ack status (Task 16 only) |
| `sportspress-league-manager.php` | Instantiate the new classes, schedule/unschedule the cron, upgrade the schema |
| `uninstall.php` | One `DROP TABLE` line |

### Modified React — `sportspress-league-manager/src/dashboard/`

| File | Change |
|---|---|
| `pages/Notices.jsx` | **New.** The simplified convener queue |
| `components/NoticeQueueCard.jsx` | **New.** The dashboard alert card |
| `lib/api.js` | Four new call wrappers |
| `App.jsx` | `Notices` import + `PAGES` entry |
| `components/Layout.jsx` | `NAV_ITEMS` entry + `capMap` gate |
| `pages/Dashboard.jsx` | Render the alert card |

### New tests — `sportspress-league-manager/tests/`

`test-discipline-consequence.php`, `test-discipline-notice-selection.php`, `test-discipline-notice-predicate.php`, `test-discipline-notice-mode.php`, `test-discipline-notice-recipients.php`, `test-discipline-notice-body.php` — each registered in `run-all-tests.sh`.

---

## Task Index

| # | Task | Depends on |
|---|---|---|
| 1 | Tier consequences (unseal `sanitize_tiers`) | — |
| 2 | `matches()` extraction | 1 |
| 3 | The notice table | — |
| 4 | The re-fire predicate and selection | 1, 3 |
| 5 | Recipient resolution | — |
| 6 | Subject, body, next-game label | — |
| 7 | Modes and the send | 3, 5, 6 |
| 8 | Settings screen | 1, 7 |
| 9 | The cron evaluation pass | 2, 4, 7 |
| 10 | REST routes | 3, 7 |
| 11 | WP-admin technical tab | 10 |
| 12 | React Notices page | 10 |
| 13 | Alert card and nav wiring | 12 |
| 14 | Bootstrap, autoloader, uninstall | 9, 10, 11 |
| 15 | GDPR export and erase | 3, 5 |
| 16 | Phase 2 polish: digest suppression, health rows | 14 |

---

### Task 1: Tier consequences

Unseals the `consequence` field that has been permanently null since the discipline feature shipped, and adds the `games` companion.

**Files:**
- Modify: `sportspress-league-manager/includes/class-penalty-watch.php:34-58` (`default_tiers`), `:163-201` (`sanitize_tiers`), `:1-13` (class docblock)
- Modify: `sportspress-league-manager/tests/test-penalty-watch.php:49`, `:151`, `:158`, `:182`
- Test: `sportspress-league-manager/tests/test-discipline-consequence.php`
- Modify: `run-all-tests.sh`

**Interfaces:**
- Produces: tier arrays now carrying `'consequence' => 'none'|'warn'|'suspend'` and `'games' => int`. `SPLM_Penalty_Watch::consequence_rank( string $consequence ): int` returning 0 for `suspend`, 1 for `warn`, 9 for anything else — lower is more severe, matching the existing `severity_rank()` convention.

**⚠️ This task breaks two existing assertions.** `test-penalty-watch.php:49` asserts `null === $tiers[0]['consequence']` ('no tier asserts a consequence in this version') and `:182` asserts `null === $clean[0]['consequence']` ('consequence is normalised to null'). Both must be rewritten, not deleted — they become assertions about the new values. The two fixture literals at `:151` and `:158` also need `'consequence'` and `'games'` keys so the inverted-tier test still round-trips.

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-discipline-consequence.php`:

```php
<?php
/**
 * Standalone tests for tier consequences.
 *
 * The consequence field decides whether a player is mailed a warning or a
 * suspension, so its validation is pinned down here in detail. Until this
 * feature, sanitize_tiers() hard-coded consequence to null on every tier it
 * emitted, which meant the settings screen physically could not persist one.
 */

define( 'ABSPATH', __DIR__ );

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

echo "\n=== seeded consequences ===\n\n";

$tiers = SPLM_Penalty_Watch::default_tiers();
$by_key = array_column( $tiers, null, 'key' );

assert_test( 'warn' === $by_key['season-warn']['consequence'], 'season-warn warns' );
assert_test( 0 === $by_key['season-warn']['games'], 'a warning carries no games' );
assert_test( 'suspend' === $by_key['season-critical']['consequence'], 'season-critical suspends' );
assert_test( 1 === $by_key['season-critical']['games'], 'season-critical suspends for one game' );
assert_test( 'suspend' === $by_key['window-critical']['consequence'], 'window-critical suspends' );
assert_test( 1 === $by_key['window-critical']['games'], 'window-critical suspends for one game' );

echo "\n=== consequence_rank() ===\n\n";

assert_test(
	SPLM_Penalty_Watch::consequence_rank( 'suspend' ) < SPLM_Penalty_Watch::consequence_rank( 'warn' ),
	'suspend outranks warn'
);
assert_test(
	SPLM_Penalty_Watch::consequence_rank( 'warn' ) < SPLM_Penalty_Watch::consequence_rank( 'none' ),
	'warn outranks none'
);
assert_test(
	SPLM_Penalty_Watch::consequence_rank( 'nonsense' ) === SPLM_Penalty_Watch::consequence_rank( 'none' ),
	'an unknown consequence ranks with none rather than winning'
);

echo "\n=== sanitize_tiers() accepts consequences ===\n\n";

$clean = SPLM_Penalty_Watch::sanitize_tiers(
	array(
		array( 'key' => 'a', 'scope' => 'season', 'minutes' => '12', 'severity' => 'warning', 'consequence' => 'warn', 'games' => '0' ),
		array( 'key' => 'b', 'scope' => 'season', 'minutes' => '18', 'severity' => 'critical', 'consequence' => 'suspend', 'games' => '2' ),
	)
);

assert_test( 2 === count( $clean ), 'both tiers survive' );
assert_test( 'warn' === $clean[0]['consequence'], 'a warn consequence is preserved' );
assert_test( 'suspend' === $clean[1]['consequence'], 'a suspend consequence is preserved' );
assert_test( 2 === $clean[1]['games'], 'a games count is coerced to an int' );

echo "\n=== sanitize_tiers() defends the consequence field ===\n\n";

$defended = SPLM_Penalty_Watch::sanitize_tiers(
	array(
		array( 'key' => 'unknown', 'scope' => 'season', 'minutes' => '5', 'severity' => 'warning', 'consequence' => 'banish', 'games' => '3' ),
		array( 'key' => 'missing', 'scope' => 'season', 'minutes' => '6', 'severity' => 'warning' ),
		array( 'key' => 'warn-games', 'scope' => 'season', 'minutes' => '7', 'severity' => 'warning', 'consequence' => 'warn', 'games' => '4' ),
		array( 'key' => 'zero-suspend', 'scope' => 'season', 'minutes' => '8', 'severity' => 'critical', 'consequence' => 'suspend', 'games' => '0' ),
		array( 'key' => 'huge', 'scope' => 'season', 'minutes' => '9', 'severity' => 'critical', 'consequence' => 'suspend', 'games' => '99' ),
	)
);
$out = array_column( $defended, null, 'key' );

assert_test( 'none' === $out['unknown']['consequence'], 'an unrecognised consequence falls back to none' );
assert_test( 0 === $out['unknown']['games'], 'a non-suspend consequence forces games to zero' );
assert_test( 'none' === $out['missing']['consequence'], 'an absent consequence defaults to none' );
assert_test( 0 === $out['warn-games']['games'], 'a warn tier cannot carry a games count' );
assert_test(
	1 === $out['zero-suspend']['games'],
	'a suspend tier with zero games is corrected to one rather than dropped: a zero-game suspension is a configuration mistake, and silently dropping the tier would be worse'
);
assert_test( 10 === $out['huge']['games'], 'games is clamped to ten' );

echo "\n=== the existing tier contract still holds ===\n\n";

assert_test(
	SPLM_Penalty_Watch::default_tiers() === SPLM_Penalty_Watch::sanitize_tiers( array() ),
	'sanitising nothing still falls back to the defaults'
);
assert_test(
	SPLM_Penalty_Watch::default_tiers() === SPLM_Penalty_Watch::sanitize_tiers( null ),
	'null still falls back to the defaults instead of fatalling'
);
assert_test(
	1 === count( SPLM_Penalty_Watch::sanitize_tiers( array( array( 'key' => 'x', 'scope' => 'nonsense', 'minutes' => '5', 'severity' => 'warning' ), array( 'key' => 'y', 'scope' => 'season', 'minutes' => '5', 'severity' => 'warning' ) ) ) ),
	'an unknown scope is still dropped'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php sportspress-league-manager/tests/test-discipline-consequence.php
```

Expected: FAIL — `consequence_rank()` does not exist yet (fatal error), and the seeded-consequence assertions fail because `default_tiers()` still emits `null`.

- [ ] **Step 3: Add the consequence constants and rank helper**

In `class-penalty-watch.php`, beside the existing `SCOPES` and `SEVERITIES` constants (line 21-22):

```php
	const SCOPES       = array( 'season', 'window' );
	const SEVERITIES   = array( 'warning', 'critical' );
	const CONSEQUENCES = array( 'none', 'warn', 'suspend' );

	/** Upper bound on a tier's games count. A suspension longer than this is a data-entry error, not a policy. */
	const MAX_GAMES = 10;
```

Add the rank helper next to the existing `severity_rank()` (after line 240):

```php
	/**
	 * Sort rank for a consequence; lower is more severe.
	 *
	 * Deliberately mirrors severity_rank()'s "lower is more severe" convention
	 * so the two can be read together without one inverting the other.
	 *
	 * @param string $consequence Consequence name.
	 * @return int
	 */
	public static function consequence_rank( string $consequence ): int {
		$rank = array(
			'suspend' => 0,
			'warn'    => 1,
		);

		return $rank[ $consequence ] ?? 9;
	}
```

- [ ] **Step 4: Seed the default tiers with consequences**

Replace the three tier literals in `default_tiers()` (lines 35-57). Keep the existing calibration comment above the method — the numbers have not changed:

```php
		return array(
			array(
				'key'         => 'season-warn',
				'scope'       => 'season',
				'minutes'     => 12,
				'severity'    => 'warning',
				'consequence' => 'warn',
				'games'       => 0,
			),
			array(
				'key'         => 'season-critical',
				'scope'       => 'season',
				'minutes'     => 18,
				'severity'    => 'critical',
				'consequence' => 'suspend',
				'games'       => 1,
			),
			array(
				'key'         => 'window-critical',
				'scope'       => 'window',
				'minutes'     => 8,
				'severity'    => 'critical',
				'consequence' => 'suspend',
				'games'       => 1,
			),
		);
```

- [ ] **Step 5: Teach `sanitize_tiers()` to validate the new fields**

Replace the emitted array at the end of the loop (lines 190-196) and add the two normalisation blocks above it. The method stays untyped (`mixed $raw`) for the documented reason — `options.php` hands the callback null when the field is absent from the POST.

```php
			// Until this feature every tier's consequence was hard-coded to null
			// here, which is why the settings screen could never persist one.
			$consequence = (string) ( $tier['consequence'] ?? 'none' );
			if ( ! in_array( $consequence, self::CONSEQUENCES, true ) ) {
				$consequence = 'none';
			}

			// (int) rather than absint(). sanitize_tiers() otherwise touches only
			// sanitize_key() — test-penalty-watch.php's stub block says so in a
			// comment and stubs nothing else — so introducing absint() here
			// fatals that pre-existing suite with "undefined function absint()".
			$games = max( 0, (int) ( $tier['games'] ?? 0 ) );
			if ( 'suspend' !== $consequence ) {
				// Only a suspension owes games. Leaving a stale count on a warn
				// tier would let a later edit to the consequence resurrect it.
				$games = 0;
			} elseif ( $games < 1 ) {
				// A suspension of zero games is a configuration mistake. Correcting
				// it beats dropping the tier, which would silently disable the
				// threshold a convener had just tried to configure.
				$games = 1;
			} elseif ( $games > self::MAX_GAMES ) {
				$games = self::MAX_GAMES;
			}

			$out[] = array(
				'key'         => $key,
				'scope'       => $scope,
				'minutes'     => $minutes,
				'severity'    => $severity,
				'consequence' => $consequence,
				'games'       => $games,
			);
```

- [ ] **Step 6: Update the class docblock**

The header currently states the opposite of what is now true. Replace lines 5-8:

```php
 * Thresholds are a tier list rather than two loose numbers so that a suspension
 * rule can be expressed by populating a tier's 'consequence' instead of
 * rewriting this. A tier's consequence is one of 'none', 'warn' or 'suspend';
 * a 'suspend' tier also carries the number of games owed. Acting on a
 * consequence is SPLM_Discipline_Notice's job, not this class's.
```

- [ ] **Step 7: Repair the four existing assertions**

In `tests/test-penalty-watch.php`, line 49 becomes:

```php
assert_test( 'warn' === $tiers[0]['consequence'], 'the season warning tier carries a warn consequence' );
assert_test( 'suspend' === $tiers[1]['consequence'], 'the season critical tier suspends' );
```

Line 182 becomes:

```php
assert_test( 'none' === $clean[0]['consequence'], 'a tier submitted without a consequence normalises to none' );
```

The two `'consequence' => null,` literals in the `$inverted` fixture (lines 151 and 158) become `'consequence' => 'none',` with `'games' => 0,` added, so the fixture matches what `sanitize_tiers()` now emits.

- [ ] **Step 8: Register the new test file**

In `run-all-tests.sh`, immediately after the `test-penalty-watch.php` line:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-discipline-consequence.php"
```

- [ ] **Step 9: Run both suites**

```bash
php sportspress-league-manager/tests/test-discipline-consequence.php
php sportspress-league-manager/tests/test-penalty-watch.php
```

Expected: both PASS with `Failed: 0`. `test-penalty-watch.php` must still report its full original assertion count plus the one added in Step 7 — a drop means an assertion was deleted rather than repaired.

- [ ] **Step 10: Lint and commit**

```bash
php -l sportspress-league-manager/includes/class-penalty-watch.php
git add sportspress-league-manager/includes/class-penalty-watch.php \
        sportspress-league-manager/tests/test-penalty-watch.php \
        sportspress-league-manager/tests/test-discipline-consequence.php \
        run-all-tests.sh
git commit -m "feat(discipline): make tier consequences actionable

sanitize_tiers() hard-coded consequence to null on every tier it emitted,
and it is the register_setting() sanitiser — so the settings screen could
never persist a consequence. Validates 'none'|'warn'|'suspend' plus a
games count instead, clamped to 1..10 and forced to 0 for anything that
is not a suspension.

A suspend tier submitted with zero games is corrected to one rather than
dropped: a zero-game suspension is a configuration mistake, and dropping
the tier would silently disable the threshold a convener just set."
```

---

### Task 2: `matches()` extraction

`evaluate()` collapses to one flag per scope, keeping the highest *severity*. The notice pass needs the highest *consequence*, and those are independent axes — so the pre-collapse structure has to be reachable without changing `evaluate()`'s behaviour.

**Files:**
- Modify: `sportspress-league-manager/includes/class-penalty-watch.php:73-131` (`evaluate`)
- Test: `sportspress-league-manager/tests/test-discipline-notice-selection.php` (created here, extended in Task 4)
- Modify: `run-all-tests.sh`

**Interfaces:**
- Consumes: `SPLM_Penalty_Watch::CONSEQUENCES`, `consequence_rank()` from Task 1.
- Produces: `SPLM_Penalty_Watch::matches( array $totals, array $tiers, array $acks = array(), string $window_start = '' ): array` — returns `scope => array` of match rows, each row `array( 'tier_key' => string, 'scope' => string, 'severity' => string, 'minutes' => int, 'value' => int, 'consequence' => string, 'games' => int )`. Scopes with no match are absent from the result. `evaluate()` keeps its exact existing signature and return value.

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-discipline-notice-selection.php`:

```php
<?php
/**
 * Standalone tests for match collection and notice selection.
 *
 * evaluate() answers "what does the watch list show" — one flag per scope,
 * highest severity. matches() answers "what could fire a notice" — every
 * matched tier. They are different questions because severity and consequence
 * are independent axes, and conflating them would mail the wrong thing.
 */

define( 'ABSPATH', __DIR__ );

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

echo "\n=== matches() returns every match, not one per scope ===\n\n";

$over_both = SPLM_Penalty_Watch::matches( array( 'season' => 20, 'window' => 2 ), $tiers, array() );

assert_test( isset( $over_both['season'] ), 'a season match is grouped under its scope' );
assert_test( 2 === count( $over_both['season'] ), 'a player over both season tiers yields two season matches' );
assert_test(
	array( 'season-warn', 'season-critical' ) === array_column( $over_both['season'], 'tier_key' ),
	'matches are returned in tier order, not collapsed'
);
assert_test( ! isset( $over_both['window'] ), 'a scope with no match is absent rather than empty' );

$both_scopes = SPLM_Penalty_Watch::matches( array( 'season' => 20, 'window' => 9 ), $tiers, array() );
assert_test(
	isset( $both_scopes['season'], $both_scopes['window'] ),
	'season and window are separate keys and can both be present'
);

echo "\n=== matches() carries the fields a notice needs ===\n\n";

$one = SPLM_Penalty_Watch::matches( array( 'season' => 18, 'window' => 0 ), $tiers, array() )['season'][1];

assert_test( 'season-critical' === $one['tier_key'], 'a match carries its tier key' );
assert_test( 'suspend' === $one['consequence'], 'a match carries its consequence' );
assert_test( 1 === $one['games'], 'a match carries its games count' );
assert_test( 18 === $one['value'], 'a match carries the value that triggered it' );
assert_test( 18 === $one['minutes'], 'a match carries the threshold it crossed' );
assert_test( 'critical' === $one['severity'], 'a match still carries its severity' );
assert_test( 'season' === $one['scope'], 'a match carries its scope' );

echo "\n=== evaluate() is unchanged ===\n\n";

$keys = function ( $flags ) {
	return array_column( $flags, 'tier_key' );
};

assert_test(
	array( 'season-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 2 ), $tiers, array() ) ),
	'evaluate() still collapses two season matches to the highest severity'
);
assert_test(
	array( 'season-critical', 'window-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 9 ), $tiers, array() ) ),
	'evaluate() still reports one flag per scope'
);
assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 4, 'window' => 2 ), $tiers, array() ),
	'evaluate() still returns nothing for a player below every threshold'
);
assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ) ),
	'evaluate() still honours acknowledgements'
);

echo "\n=== severity still decides evaluate()'s pick, independent of consequence ===\n\n";

// A critical tier whose consequence is only a warning, sitting above a warning
// tier that suspends. evaluate() must pick on severity; a notice must not.
$crossed = array(
	array( 'key' => 'low-suspend', 'scope' => 'season', 'minutes' => 10, 'severity' => 'warning', 'consequence' => 'suspend', 'games' => 1 ),
	array( 'key' => 'high-warn', 'scope' => 'season', 'minutes' => 12, 'severity' => 'critical', 'consequence' => 'warn', 'games' => 0 ),
);

assert_test(
	array( 'high-warn' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 15, 'window' => 0 ), $crossed, array() ) ),
	'evaluate() picks the critical tier even though it only warns'
);
assert_test(
	2 === count( SPLM_Penalty_Watch::matches( array( 'season' => 15, 'window' => 0 ), $crossed, array() )['season'] ),
	'matches() keeps both, so the notice layer can pick on consequence instead'
);

echo "\n=== acknowledgement suppression works the same in matches() ===\n\n";

assert_test(
	! isset( SPLM_Penalty_Watch::matches( array( 'season' => 12, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ) )['season'] ),
	'an ack suppresses a match, so the watch list and the pass share one rule'
);

$window_start = '2026-01-05';
$window_ack   = array( SPLM_Penalty_Watch::ack_key( $tiers[2], $window_start ) => 9 );

assert_test(
	! isset( SPLM_Penalty_Watch::matches( array( 'season' => 0, 'window' => 9 ), $tiers, $window_ack, $window_start )['window'] ),
	'a window ack suppresses inside its own window'
);
assert_test(
	isset( SPLM_Penalty_Watch::matches( array( 'season' => 0, 'window' => 9 ), $tiers, $window_ack, '2025-11-10' )['window'] ),
	'a window ack does not suppress a disjoint window'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php sportspress-league-manager/tests/test-discipline-notice-selection.php
```

Expected: FAIL — `matches()` does not exist (fatal error).

- [ ] **Step 3: Extract `matches()` from `evaluate()`**

Replace the body of `evaluate()` (lines 73-131). The matching loop moves wholesale into `matches()` — including both acknowledgement comments, which are load-bearing documentation and must travel with the code they explain. `evaluate()` keeps only the collapse.

```php
	/**
	 * Every matched tier for one player, grouped by scope.
	 *
	 * This is evaluate()'s matching half, exposed on its own. evaluate() keeps
	 * the highest-SEVERITY match per scope, which is right for the watch list
	 * and wrong for notices: severity and consequence are independent axes, so
	 * the highest-severity match in a scope is not necessarily the one carrying
	 * a consequence. The notice pass needs all of them and picks its own winner.
	 *
	 * @param array  $totals       array( 'season' => int, 'window' => int ).
	 * @param array  $tiers        Tier list.
	 * @param array  $acks         Acknowledgement key => value_at_ack.
	 * @param string $window_start Week key the rolling window currently starts at.
	 * @return array scope => list of matches, in tier order. Scopes with no
	 *               match are absent.
	 */
	public static function matches( array $totals, array $tiers, array $acks = array(), string $window_start = '' ): array {
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
			//
			// A window acknowledgement is scoped to the window it was taken in:
			// a rolling window falls again as weeks roll past, so a bare tier key
			// would compare this window's total against a total earned in a
			// completely different window and mute the alarm for the rest of the
			// season. A season total only ever grows, so season scope needs no
			// such scoping.
			$ack_key = self::ack_key( $tier, $window_start );
			if ( array_key_exists( $ack_key, $acks ) && $value <= (int) $acks[ $ack_key ] ) {
				continue;
			}

			$matched[ $scope ][] = array(
				'tier_key'    => (string) $tier['key'],
				'scope'       => $scope,
				'severity'    => (string) $tier['severity'],
				'minutes'     => (int) $tier['minutes'],
				'value'       => $value,
				'consequence' => (string) ( $tier['consequence'] ?? 'none' ),
				'games'       => (int) ( $tier['games'] ?? 0 ),
			);
		}

		return $matched;
	}

	/**
	 * Flags for one player: the highest-severity match per scope, criticals first.
	 *
	 * Suppressed tiers are removed BEFORE the highest-per-scope choice, so
	 * acknowledging a critical reveals the warning underneath instead of hiding
	 * the player altogether. That happens inside matches().
	 *
	 * @param array  $totals       array( 'season' => int, 'window' => int ).
	 * @param array  $tiers        Tier list.
	 * @param array  $acks         Acknowledgement key => value_at_ack.
	 * @param string $window_start Week key the rolling window currently starts at.
	 * @return array Flags, criticals first.
	 */
	public static function evaluate( array $totals, array $tiers, array $acks, string $window_start = '' ): array {
		$flags = array();

		foreach ( self::matches( $totals, $tiers, $acks, $window_start ) as $scope_flags ) {
			// Severity decides which match represents the scope, not minutes:
			// thresholds are editable, so a critical tier can legitimately sit
			// below a warning tier and must still win.
			usort(
				$scope_flags,
				function ( $a, $b ) {
					$rank = self::severity_rank( $a['severity'] ) <=> self::severity_rank( $b['severity'] );

					return $rank ? $rank : ( $b['minutes'] <=> $a['minutes'] );
				}
			);
			$flags[] = $scope_flags[0];
		}

		usort( $flags, array( __CLASS__, 'compare_flags' ) );

		return $flags;
	}
```

- [ ] **Step 4: Register the new test file**

In `run-all-tests.sh`, after the `test-discipline-consequence.php` line added in Task 1:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-discipline-notice-selection.php"
```

- [ ] **Step 5: Run the three affected suites**

```bash
php sportspress-league-manager/tests/test-discipline-notice-selection.php
php sportspress-league-manager/tests/test-penalty-watch.php
php sportspress-league-manager/tests/test-leaders.php
```

Expected: all PASS. `test-penalty-watch.php` is the regression gate here — it covers `evaluate()`'s collapse, ack suppression, window scoping and the severity-over-minutes rule, none of which may change. `test-leaders.php` exercises the watch rows built on top of `evaluate()`.

- [ ] **Step 6: Lint and commit**

```bash
php -l sportspress-league-manager/includes/class-penalty-watch.php
git add sportspress-league-manager/includes/class-penalty-watch.php \
        sportspress-league-manager/tests/test-discipline-notice-selection.php \
        run-all-tests.sh
git commit -m "refactor(discipline): expose matched tiers via matches()

evaluate() keeps the highest-severity match per scope, which is right for
the watch list and wrong for notices: severity and consequence are
independent axes, so the highest-severity match in a scope is not
necessarily the one carrying a consequence.

matches() returns the pre-collapse structure; evaluate() is now written
over it and keeps its exact signature and behaviour."
```

---

### Task 3: The notice table

One row per decision the system makes about a (player, season, tier). Follows `class-discipline-database.php`'s schema and `dbDelta` conventions — **except its clock**, which is site-local and must not be copied.

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-notice-database.php`
- Test: `sportspress-league-manager/tests/test-discipline-notice-predicate.php` (created here, extended in Task 4)
- Modify: `run-all-tests.sh`

**Interfaces:**
- Produces: `SPLM_Discipline_Notice_Database` with status constants `STATUS_BASELINE`, `STATUS_PENDING`, `STATUS_SENT`, `STATUS_FAILED`, `STATUS_DISCARDED`, `STATUS_SERVED`, and:
  - `table_name(): string`
  - `now(): string` — `gmdate( 'Y-m-d H:i:s' )`, UTC
  - `create_table(): bool`, `table_exists(): bool`, `maybe_upgrade(): void`
  - `insert( array $row ): int` — 0 on failure
  - `update( int $id, array $fields ): bool`
  - `find( int $id )` — row object or `null`
  - `latest_for( int $player_id, int $season_id, string $ack_key )` — row object or `null`
  - `query( array $filters, int $page, int $per_page ): array` — `array( 'rows' => array, 'total' => int )`
  - `counts_by_status( int $season_id ): array` — `status => int`

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-discipline-notice-predicate.php`. Task 4 extends this file; this task's half covers the table's clock and shape.

```php
<?php
/**
 * Standalone tests for the notice table and the re-fire predicate.
 *
 * The clock assertions matter more than they look: the nearest precedent in
 * this plugin (SPLM_Discipline_Database::acknowledge) writes current_time(),
 * which is site-local. Every timestamp on this table is UTC, and these
 * assertions run under a non-UTC timezone so a reach for local time fails.
 */

define( 'ABSPATH', __DIR__ );

// A deliberately non-UTC site timezone. If any production code reaches for
// site-local time instead of UTC, these assertions are what catches it.
date_default_timezone_set( 'America/Toronto' );

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Notice_DB_Test_State {
	/** Rows the fake wpdb hands back, keyed by the first bound parameter. */
	public $rows = array();

	/** Every insert() call's data array, in order. */
	public $inserts = array();

	/** Every update() call, as array( id_where, data ). */
	public $updates = array();
}

function splm_notice_db_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Notice_DB_Test_State();
	}
	return $state;
}

class Fake_WPDB {
	public $prefix          = 'wp_';
	public $insert_succeeds = true;
	public $insert_id       = 0;
	private $last_args      = array();

	public function prepare( $query, ...$args ) {
		$this->last_args = $args;
		return $query;
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function get_row() {
		$key = $this->last_args[0] ?? null;
		return isset( splm_notice_db_test_state()->rows[ $key ] ) ? splm_notice_db_test_state()->rows[ $key ] : null;
	}

	public function get_results() {
		return array_values( splm_notice_db_test_state()->rows );
	}

	public function get_var() {
		return 'wp_splm_discipline_notice';
	}

	public function insert( $table, $data ) { // phpcs:ignore
		splm_notice_db_test_state()->inserts[] = $data;
		if ( ! $this->insert_succeeds ) {
			return false;
		}
		$this->insert_id = 701;
		return 1;
	}

	public function update( $table, $data, $where ) { // phpcs:ignore
		splm_notice_db_test_state()->updates[] = array( $where, $data );
		return 1;
	}
}

global $wpdb;
$wpdb = new Fake_WPDB();

function absint( $v ) {
	return abs( (int) $v );
}

require_once __DIR__ . '/../includes/class-discipline-notice-database.php';

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

$db = 'SPLM_Discipline_Notice_Database';

echo "\n=== the clock is UTC, not site-local ===\n\n";

$now = $db::now();

assert_test(
	1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now ),
	'now() returns a MySQL datetime string'
);
assert_test( $now === gmdate( 'Y-m-d H:i:s' ), 'now() is UTC' );
assert_test(
	$now !== date( 'Y-m-d H:i:s' ),
	'now() differs from local time under a non-UTC timezone, proving it is not date() or current_time()'
);

echo "\n=== statuses ===\n\n";

$statuses = array(
	$db::STATUS_BASELINE,
	$db::STATUS_PENDING,
	$db::STATUS_SENT,
	$db::STATUS_FAILED,
	$db::STATUS_DISCARDED,
	$db::STATUS_SERVED,
);

assert_test(
	array( 'baseline', 'pending', 'sent', 'failed', 'discarded', 'served' ) === $statuses,
	'all six statuses are defined with their documented names'
);
assert_test( 6 === count( array_unique( $statuses ) ), 'no two statuses collide' );

echo "\n=== table name ===\n\n";

assert_test( 'wp_splm_discipline_notice' === $db::table_name(), 'table name is prefixed' );

echo "\n=== insert() stamps its own UTC created_at ===\n\n";

splm_notice_db_test_state()->inserts = array();

$id = $db::insert(
	array(
		'player_id'     => 12,
		'season_id'     => 34,
		'tier_key'      => 'season-critical',
		'ack_key'       => 'season-critical',
		'severity'      => 'critical',
		'consequence'   => 'suspend',
		'games'         => 1,
		'value_at_fire' => 18,
		'status'        => $db::STATUS_PENDING,
	)
);

$written = splm_notice_db_test_state()->inserts[0];

assert_test( 701 === $id, 'insert() returns the new row id' );
assert_test( isset( $written['created_at'] ), 'insert() stamps created_at rather than trusting a column default' );
assert_test( $written['created_at'] === gmdate( 'Y-m-d H:i:s' ), 'created_at is UTC' );
assert_test( 12 === $written['player_id'] && 18 === $written['value_at_fire'], 'the caller fields survive' );
assert_test( 'pending' === $written['status'], 'the status survives' );

echo "\n=== insert() failure is reported, not swallowed ===\n\n";

$wpdb->insert_succeeds = false;
assert_test( 0 === $db::insert( array( 'player_id' => 1, 'season_id' => 1, 'ack_key' => 'x' ) ), 'a failed insert returns 0' );
$wpdb->insert_succeeds = true;

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php sportspress-league-manager/tests/test-discipline-notice-predicate.php
```

Expected: FAIL — the class file does not exist, so `require_once` fatals.

- [ ] **Step 3: Create the database class**

Create `sportspress-league-manager/includes/class-discipline-notice-database.php`:

```php
<?php
/**
 * Storage for disciplinary notices.
 *
 * Follows the discipline-ack table's schema and dbDelta conventions, including
 * verifying the table exists after dbDelta() rather than trusting its return
 * value: dbDelta() returns a list of applied statements and nothing useful on
 * failure, so stamping a version on its return records a failed CREATE as done
 * and never retries.
 *
 * It deliberately does NOT follow that table's clock. acknowledge() there
 * writes current_time( 'mysql' ), which is site-local; every timestamp here is
 * UTC via now(). Mixing the two would make deadline and audit comparisons wrong
 * by the site's offset.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Database {

	const DB_VERSION     = '1.0.0';
	const VERSION_OPTION = 'splm_discipline_notice_db_version';

	/** Recorded, never mailed: the value a player was already at when notices were switched on. */
	const STATUS_BASELINE = 'baseline';
	/** Waiting for a human to release it. */
	const STATUS_PENDING = 'pending';
	/** wp_mail() accepted it. */
	const STATUS_SENT = 'sent';
	/** wp_mail() rejected it, or no address resolved. Retried through release. */
	const STATUS_FAILED = 'failed';
	/** A convener decided not to send it. */
	const STATUS_DISCARDED = 'discarded';
	/** A suspension a convener has marked served. */
	const STATUS_SERVED = 'served';

	const STATUSES = array(
		self::STATUS_BASELINE,
		self::STATUS_PENDING,
		self::STATUS_SENT,
		self::STATUS_FAILED,
		self::STATUS_DISCARDED,
		self::STATUS_SERVED,
	);

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'splm_discipline_notice';
	}

	/**
	 * The current UTC time as a MySQL datetime.
	 *
	 * Every timestamp this class writes goes through here. Do not substitute
	 * current_time( 'mysql' ) — see the class docblock.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Create the table.
	 *
	 * The (player_id, season_id, ack_key) key is deliberately NOT unique: a
	 * player may legitimately receive the same tier's notice twice in a season
	 * — once at 18 minutes, again at 25 — and both rows are history worth
	 * keeping. Duplicate protection is the re-fire predicate plus the pass lock.
	 *
	 * @return bool True when the table is present afterwards.
	 */
	public static function create_table(): bool {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			player_id bigint(20) unsigned NOT NULL,
			season_id bigint(20) unsigned NOT NULL,
			tier_key varchar(50) NOT NULL,
			ack_key varchar(80) NOT NULL,
			severity varchar(20) NOT NULL DEFAULT '',
			consequence varchar(20) NOT NULL DEFAULT 'none',
			games smallint(5) unsigned NOT NULL DEFAULT 0,
			value_at_fire int NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			recipient varchar(200) NOT NULL DEFAULT '',
			recipient_via varchar(20) NOT NULL DEFAULT '',
			bcc text NULL,
			sent_at datetime NULL,
			served_at datetime NULL,
			released_by bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			note text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY player_season_ack (player_id, season_id, ack_key),
			KEY season_status (season_id, status)
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
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB
	}

	/**
	 * Create the table on first run or after a version bump.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION && self::table_exists() ) {
			return;
		}

		if ( self::create_table() ) {
			update_option( self::VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Insert a notice row.
	 *
	 * created_at is stamped here rather than left to the column default so the
	 * value is UTC regardless of the database server's timezone.
	 *
	 * @param array $row Row fields.
	 * @return int New row id, or 0 on failure.
	 */
	public static function insert( array $row ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$row['created_at'] = self::now();

		$result = $wpdb->insert( self::table_name(), $row ); // phpcs:ignore WordPress.DB

		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Update a notice row.
	 *
	 * @param int   $id     Row id.
	 * @param array $fields Fields to write.
	 * @return bool
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;

		if ( ! self::table_exists() || $id <= 0 || ! $fields ) {
			return false;
		}

		$result = $wpdb->update( self::table_name(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB

		return false !== $result;
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function find( int $id ) {
		global $wpdb;

		if ( ! self::table_exists() || $id <= 0 ) {
			return null;
		}

		$table = self::table_name();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				$id
			)
		);
	}

	/**
	 * The most recent row for one player, season and acknowledgement key.
	 *
	 * This is the row the re-fire predicate compares against. Ordering by id
	 * rather than created_at because two rows written inside the same second
	 * during one pass must still have a determinate winner.
	 *
	 * @param int    $player_id Player post id.
	 * @param int    $season_id Season term id.
	 * @param string $ack_key   Tier key, or "<tier>@<window start>".
	 * @return object|null
	 */
	public static function latest_for( int $player_id, int $season_id, string $ack_key ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$table = self::table_name();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				"SELECT * FROM {$table}
				 WHERE player_id = %d AND season_id = %d AND ack_key = %s
				 ORDER BY id DESC
				 LIMIT 1",
				$player_id,
				$season_id,
				$ack_key
			)
		);
	}

	/**
	 * Paginated rows for the queue surfaces.
	 *
	 * @param array $filters  Accepts 'season' (int) and 'status' (string).
	 * @param int   $page     1-indexed page.
	 * @param int   $per_page Rows per page.
	 * @return array array( 'rows' => array, 'total' => int ).
	 */
	public static function query( array $filters, int $page, int $per_page ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['season'] ) ) {
			$where[]  = 'season_id = %d';
			$params[] = (int) $filters['season'];
		}
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}

		$table  = self::table_name();
		$clause = implode( ' AND ', $where );
		$offset = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a clause of literal placeholders; values are bound below.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB

		$row_params = array_merge( $params, array( $per_page, $offset ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a clause of literal placeholders; values are bound here.
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				$row_params
			)
		);

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Row counts per status for one season, for the alert card and the
	 * technical view's diagnostics.
	 *
	 * @param int $season_id Season term id.
	 * @return array status => count. Statuses with no rows are absent.
	 */
	public static function counts_by_status( int $season_id ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n FROM {$table} WHERE season_id = %d GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				$season_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->n;
		}

		return $out;
	}
}
```

- [ ] **Step 4: Register the test file**

In `run-all-tests.sh`, after the `test-discipline-notice-selection.php` line:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-discipline-notice-predicate.php"
```

- [ ] **Step 5: Run it**

```bash
php sportspress-league-manager/tests/test-discipline-notice-predicate.php
```

Expected: PASS with `Failed: 0`. The three clock assertions are the ones that matter — if `now()` were `current_time( 'mysql' )` the "differs from local time" assertion fails.

- [ ] **Step 6: Lint and commit**

```bash
php -l sportspress-league-manager/includes/class-discipline-notice-database.php
git add sportspress-league-manager/includes/class-discipline-notice-database.php \
        sportspress-league-manager/tests/test-discipline-notice-predicate.php \
        run-all-tests.sh
git commit -m "feat(discipline): add the notice table

One row per decision the system makes about a (player, season, tier),
across six statuses. The (player, season, ack_key) key is deliberately
not unique so a second crossing of the same tier keeps its own row.

Timestamps are UTC via now(). The nearest precedent, the discipline ack
table, writes current_time('mysql') — site-local — and copying it would
make every audit comparison wrong by the site's offset."
```

---

### Task 4: The re-fire predicate and selection

The decision core. Pure functions: totals and rows in, decisions out — no database, no mail.

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-notice.php`
- Modify: `sportspress-league-manager/tests/test-discipline-notice-selection.php` (append a section)
- Modify: `sportspress-league-manager/tests/test-discipline-notice-predicate.php` (append a section)

**Interfaces:**
- Consumes: `SPLM_Penalty_Watch::matches()` output rows (Task 2); `SPLM_Discipline_Notice_Database::STATUS_*` (Task 3).
- Produces: `SPLM_Discipline_Notice` with:
  - `should_fire( array $match, $latest ): bool` — `$latest` is a row object or `null`
  - `select( array $matches_by_scope ): array` — `array( 'notice' => array|null, 'baselines' => array )`
  - `rank_matches( array $matches ): array` — sorted copy, most severe first

- [ ] **Step 1: Append the predicate tests**

Append to `sportspress-league-manager/tests/test-discipline-notice-predicate.php`, immediately **before** its `=== Results ===` block:

```php
require_once __DIR__ . '/../includes/class-discipline-notice.php';

$notice = 'SPLM_Discipline_Notice';
$row    = function ( $status, $value ) {
	return (object) array(
		'status'        => $status,
		'value_at_fire' => $value,
	);
};
$match  = function ( $value ) {
	return array(
		'tier_key'    => 'season-critical',
		'scope'       => 'season',
		'severity'    => 'critical',
		'minutes'     => 18,
		'value'       => $value,
		'consequence' => 'suspend',
		'games'       => 1,
	);
};

echo "\n=== the re-fire predicate ===\n\n";

assert_test( $notice::should_fire( $match( 18 ), null ), 'with no prior row, a match fires' );

foreach ( array( 'baseline', 'sent', 'discarded', 'served', 'pending' ) as $status ) {
	assert_test(
		! $notice::should_fire( $match( 18 ), $row( $status, 18 ) ),
		"a {$status} row at the same value suppresses the match"
	);
	assert_test(
		$notice::should_fire( $match( 19 ), $row( $status, 18 ) ),
		"a {$status} row does not suppress once the player earns more"
	);
}

assert_test(
	! $notice::should_fire( $match( 18 ), $row( 'baseline', 25 ) ),
	'a baseline above the current value still suppresses, so a mid-season switch-on cannot mail anyone'
);
assert_test(
	! $notice::should_fire( $match( 18 ), $row( 'failed', 18 ) ),
	'a failed row does not re-fire: it stays actionable in the queue and is retried through release instead'
);

echo "\n=== only consequence-bearing matches can fire ===\n\n";

$inert = $match( 18 );
$inert['consequence'] = 'none';

assert_test( ! $notice::should_fire( $inert, null ), 'a tier with no consequence never fires a notice' );
```

- [ ] **Step 2: Append the selection tests**

Append to `sportspress-league-manager/tests/test-discipline-notice-selection.php`, immediately **before** its `=== Results ===` block:

```php
require_once __DIR__ . '/../includes/class-discipline-notice.php';

$notice = 'SPLM_Discipline_Notice';
$mk     = function ( $key, $scope, $consequence, $games, $minutes ) {
	return array(
		'tier_key'    => $key,
		'scope'       => $scope,
		'severity'    => 'suspend' === $consequence ? 'critical' : 'warning',
		'minutes'     => $minutes,
		'value'       => $minutes + 2,
		'consequence' => $consequence,
		'games'       => $games,
	);
};

echo "\n=== one notice per player per pass ===\n\n";

$two_scopes = array(
	'season' => array( $mk( 'season-critical', 'season', 'suspend', 1, 18 ) ),
	'window' => array( $mk( 'window-critical', 'window', 'suspend', 1, 8 ) ),
);
$chosen = $notice::select( $two_scopes );

assert_test( is_array( $chosen['notice'] ), 'a winner is chosen' );
assert_test(
	1 === count( $chosen['baselines'] ),
	'the runner-up is baselined rather than sent, so one set of minutes cannot mail the player twice'
);
assert_test(
	$chosen['notice']['tier_key'] !== $chosen['baselines'][0]['tier_key'],
	'the winner is not also baselined'
);

echo "\n=== ranking: consequence, then games, then minutes ===\n\n";

$warn_and_suspend = array(
	'season' => array(
		$mk( 'high-warn', 'season', 'warn', 0, 30 ),
		$mk( 'low-suspend', 'season', 'suspend', 1, 10 ),
	),
);
assert_test(
	'low-suspend' === $notice::select( $warn_and_suspend )['notice']['tier_key'],
	'suspend beats warn even when the warn tier has the higher threshold'
);

$two_suspends = array(
	'season' => array(
		$mk( 'one-game', 'season', 'suspend', 1, 30 ),
		$mk( 'three-game', 'season', 'suspend', 3, 18 ),
	),
);
assert_test(
	'three-game' === $notice::select( $two_suspends )['notice']['tier_key'],
	'among suspensions the longer one wins, even from a lower threshold'
);

$tied = array(
	'season' => array(
		$mk( 'lower', 'season', 'suspend', 1, 12 ),
		$mk( 'higher', 'season', 'suspend', 1, 20 ),
	),
);
assert_test(
	'higher' === $notice::select( $tied )['notice']['tier_key'],
	'with consequence and games tied, the higher threshold wins'
);

echo "\n=== inert and empty input ===\n\n";

assert_test( null === $notice::select( array() )['notice'], 'nothing matched means no notice' );
assert_test( array() === $notice::select( array() )['baselines'], 'nothing matched means nothing to baseline' );

$only_none = array( 'season' => array( $mk( 'inert', 'season', 'none', 0, 12 ) ) );
assert_test( null === $notice::select( $only_none )['notice'], 'a match with no consequence cannot become a notice' );
assert_test(
	array() === $notice::select( $only_none )['baselines'],
	'a match with no consequence is not baselined either: it was never a candidate'
);
```

- [ ] **Step 3: Run both to confirm they fail**

```bash
php sportspress-league-manager/tests/test-discipline-notice-predicate.php
php sportspress-league-manager/tests/test-discipline-notice-selection.php
```

Expected: both FAIL — `class-discipline-notice.php` does not exist.

- [ ] **Step 4: Create the decision core**

Create `sportspress-league-manager/includes/class-discipline-notice.php`:

```php
<?php
/**
 * Notice decisions: whether a match fires, and which match wins a pass.
 *
 * Pure by construction — matches and rows in, decisions out. No database, no
 * mail, no options. That is what lets the rules that decide whether a player
 * is told they are suspended be tested exhaustively with no WordPress at all.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice {

	/** Consequences that can produce a notice. 'none' cannot. */
	const ACTIONABLE = array( 'warn', 'suspend' );

	/**
	 * Whether a match should produce a new notice row.
	 *
	 * One predicate governs every status. For a given (player, season,
	 * ack_key) the latest row's value_at_fire is the bar: the player must have
	 * earned MORE than that to be told again. Every status participates
	 * identically, which is what delivers all of these without special cases:
	 *
	 *  - a sent notice does not re-send next week at an unchanged total;
	 *  - a baseline row suppresses a player who was already over at switch-on;
	 *  - a served suspension re-fires once the player offends again;
	 *  - a convener's discard sticks until the player earns more.
	 *
	 * 'failed' is the one status needing care, and it is handled by the same
	 * rule: a failed row suppresses re-firing because it must stay actionable
	 * in the queue and be retried through the release route. Re-firing it would
	 * create a second row for a notice a human is still looking at.
	 *
	 * @param array       $match  A row from SPLM_Penalty_Watch::matches().
	 * @param object|null $latest The most recent notice row for this ack key.
	 * @return bool
	 */
	public static function should_fire( array $match, $latest ): bool {
		$consequence = (string) ( $match['consequence'] ?? 'none' );
		if ( ! in_array( $consequence, self::ACTIONABLE, true ) ) {
			return false;
		}

		if ( ! $latest ) {
			return true;
		}

		return (int) $match['value'] > (int) $latest->value_at_fire;
	}

	/**
	 * Choose at most one notice from a pass's surviving matches.
	 *
	 * Two scopes can both match a suspending tier in the same pass — a player
	 * crossing season-critical and window-critical together. Sending both would
	 * mail the player twice and imply two suspensions for one set of minutes.
	 *
	 * The winner takes the notice. Every other candidate is returned for a
	 * baseline row, which is what stops the runner-up firing its own notice on
	 * the next pass at an unchanged total while still letting it fire later if
	 * the player earns more.
	 *
	 * @param array $matches_by_scope Scope => list of matches, already filtered
	 *                                through should_fire().
	 * @return array array( 'notice' => array|null, 'baselines' => array ).
	 */
	public static function select( array $matches_by_scope ): array {
		$candidates = array();
		foreach ( $matches_by_scope as $scope_matches ) {
			foreach ( (array) $scope_matches as $match ) {
				if ( in_array( (string) ( $match['consequence'] ?? 'none' ), self::ACTIONABLE, true ) ) {
					$candidates[] = $match;
				}
			}
		}

		if ( ! $candidates ) {
			return array(
				'notice'    => null,
				'baselines' => array(),
			);
		}

		$ranked = self::rank_matches( $candidates );

		return array(
			'notice'    => array_shift( $ranked ),
			'baselines' => $ranked,
		);
	}

	/**
	 * Sort matches most severe first: consequence, then games, then minutes.
	 *
	 * Consequence leads because it is the actionable axis — a suspension must
	 * win over a warning even when the warning tier sits at a higher threshold,
	 * which is legal since thresholds are editable. Games breaks ties among
	 * suspensions so the longer one is the one the player is told about.
	 *
	 * @param array $matches Matches.
	 * @return array Sorted copy.
	 */
	public static function rank_matches( array $matches ): array {
		usort(
			$matches,
			function ( $a, $b ) {
				$rank = SPLM_Penalty_Watch::consequence_rank( (string) $a['consequence'] )
					<=> SPLM_Penalty_Watch::consequence_rank( (string) $b['consequence'] );
				if ( $rank ) {
					return $rank;
				}

				$games = (int) $b['games'] <=> (int) $a['games'];
				if ( $games ) {
					return $games;
				}

				return (int) $b['minutes'] <=> (int) $a['minutes'];
			}
		);

		return $matches;
	}
}
```

- [ ] **Step 5: Run both suites**

```bash
php sportspress-league-manager/tests/test-discipline-notice-predicate.php
php sportspress-league-manager/tests/test-discipline-notice-selection.php
```

Expected: both PASS with `Failed: 0`. Note `test-discipline-notice-predicate.php` needs no `class-penalty-watch.php` require — `should_fire()` touches nothing from it — while `test-discipline-notice-selection.php` already has one, which `rank_matches()`'s call to `consequence_rank()` depends on.

- [ ] **Step 6: Lint and commit**

```bash
php -l sportspress-league-manager/includes/class-discipline-notice.php
git add sportspress-league-manager/includes/class-discipline-notice.php \
        sportspress-league-manager/tests/test-discipline-notice-predicate.php \
        sportspress-league-manager/tests/test-discipline-notice-selection.php
git commit -m "feat(discipline): notice predicate and one-per-pass selection

One predicate over the latest row's value_at_fire governs every status,
which delivers baseline suppression, no-resend, re-fire after served, and
a sticky discard without special cases. A failed row suppresses re-firing
deliberately: it stays actionable and is retried through release.

select() takes at most one notice per player per pass, ranked by
consequence then games then minutes, and returns the runner-up for a
baseline row so one set of minutes cannot mail the player twice."
```

---

### Task 5: Recipient resolution

The player address, the captain's, and the Bcc list. Also the reverse email→players lookup, which lives here because Task 15's GDPR eraser needs exactly the inverse of what the mailer needs, and two copies of that chain would drift.

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-notice-recipients.php`
- Test: `sportspress-league-manager/tests/test-discipline-notice-recipients.php`
- Modify: `run-all-tests.sh`

**Interfaces:**
- Produces: `SPLM_Discipline_Notice_Recipients` with:
  - `player_email( int $player_id ): array` — `array( 'email' => string, 'via' => string )`; `via` is `'spt_email'`, `'sp_user'` or `''`
  - `captain_email( int $team_id ): string` — `''` when unresolvable
  - `bcc_for( int $season_id, int $team_id ): array` — de-duplicated address list
  - `players_for_email( string $email ): array` — player post ids

**Two constraints the spec pins down.** The captain mechanism lives in the **player-tools sibling plugin**, so resolution must degrade silently to no captain when it is inactive, when the team has no `sp_list`, or when the list has no `spt_captain`. And `sp_list` is **not season-scoped** — a team has one active list — so the captain of record may be wrong for a historical season. Captain Bcc is added **only when `$season_id` equals `splm_default_season`**.

Unlike `SPLM_Discipline_Digest::recipients()`, an empty Bcc list does **not** fall back to `admin_email`. Copying that fallback would silently copy the site admin on a player's disciplinary mail.

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-discipline-notice-recipients.php`:

```php
<?php
/**
 * Standalone tests for notice recipient resolution.
 *
 * Getting this wrong mails a player's disciplinary notice to the wrong
 * person, so the degradation paths are pinned down as tightly as the happy
 * path: no captain, no player-tools sibling, a historical season, and the
 * deliberate absence of the digest's admin_email fallback.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Recipients_Test_State {
	/** post_id => array( meta_key => value ). */
	public $meta = array();

	/** Option name => value. */
	public $options = array();

	/** user_id => email. */
	public $users = array();

	/** post_id => post_type, for the sp_list type check. */
	public $types = array();
}

function splm_recipients_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Recipients_Test_State();
	}
	return $state;
}

/**
 * $single is honoured deliberately, and this is load-bearing.
 *
 * WordPress returns an ARRAY when $single is false. A stub that ignored the
 * parameter would let production code that forgot `, true` pass every
 * assertion in this file — and that code is not merely wrong, it is
 * dangerous: absint( array( 77 ) ) evaluates to 1, so a missing $single
 * addresses every player's disciplinary notice to user ID 1, normally the
 * site owner, and records it on the row as correct.
 */
function get_post_meta( $post_id, $key, $single = false ) {
	$meta  = splm_recipients_test_state()->meta;
	$value = isset( $meta[ (int) $post_id ][ $key ] ) ? $meta[ (int) $post_id ][ $key ] : '';

	if ( $single ) {
		return $value;
	}

	return '' === $value ? array() : array( $value );
}

function get_option( $name, $default = false ) {
	$options = splm_recipients_test_state()->options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function get_post_type( $post_id ) {
	$types = splm_recipients_test_state()->types;
	return isset( $types[ (int) $post_id ] ) ? $types[ (int) $post_id ] : false;
}

function get_userdata( $user_id ) {
	$users = splm_recipients_test_state()->users;
	if ( ! isset( $users[ (int) $user_id ] ) ) {
		return false;
	}
	return (object) array( 'user_email' => $users[ (int) $user_id ] );
}

function is_email( $email ) {
	return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email ) ? $email : false;
}

function sanitize_email( $email ) {
	return trim( (string) $email );
}

function absint( $v ) {
	return abs( (int) $v );
}

require_once __DIR__ . '/../includes/class-discipline-notice-recipients.php';

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

$r     = 'SPLM_Discipline_Notice_Recipients';
$state = splm_recipients_test_state();

echo "\n=== the player address chain ===\n\n";

$state->meta[10] = array( 'spt_email' => 'direct@example.test' );
$found = $r::player_email( 10 );
assert_test( 'direct@example.test' === $found['email'], 'spt_email is used when present' );
assert_test( 'spt_email' === $found['via'], 'the resolution path is recorded' );

$state->meta[11] = array( 'sp_user' => 77 );
$state->users[77] = 'linked@example.test';
$fallback = $r::player_email( 11 );
assert_test( 'linked@example.test' === $fallback['email'], 'the linked user email is the fallback' );
assert_test( 'sp_user' === $fallback['via'], 'the fallback path is recorded distinctly' );

$state->meta[12] = array( 'spt_email' => 'first@example.test', 'sp_user' => 77 );
assert_test( 'spt_email' === $r::player_email( 12 )['via'], 'spt_email wins when both exist' );

echo "\n=== the player address chain degrades ===\n\n";

$none = $r::player_email( 99 );
assert_test( '' === $none['email'], 'a player with neither meta resolves to no address' );
assert_test( '' === $none['via'], 'no address means no recorded path' );

$state->meta[13] = array( 'spt_email' => 'not-an-email' );
assert_test( '' === $r::player_email( 13 )['email'], 'a malformed spt_email is rejected rather than mailed' );

$state->meta[14] = array( 'sp_user' => 4242 );
assert_test( '' === $r::player_email( 14 )['email'], 'an sp_user pointing at a missing user resolves to nothing' );

echo "\n=== the captain chain ===\n\n";

$state->meta[200] = array( 'sp_list' => 300 );
$state->types[300] = 'sp_list';
$state->meta[300] = array( 'spt_captain' => 10 );

assert_test( 'direct@example.test' === $r::captain_email( 200 ), 'a captain resolves through sp_list to an address' );

echo "\n=== the captain chain degrades silently ===\n\n";

$state->meta[201] = array();
assert_test( '' === $r::captain_email( 201 ), 'a team with no sp_list yields no captain' );

$state->meta[202] = array( 'sp_list' => 301 );
$state->types[301] = 'sp_list';
$state->meta[301] = array();
assert_test( '' === $r::captain_email( 202 ), 'a list with no spt_captain yields no captain' );

$state->meta[203] = array( 'sp_list' => 302 );
$state->types[302] = 'post';
$state->meta[302] = array( 'spt_captain' => 10 );
assert_test(
	'' === $r::captain_email( 203 ),
	'a sp_list meta pointing at something that is not an sp_list is refused rather than trusted'
);

$state->meta[204] = array( 'sp_list' => 303 );
$state->types[303] = 'sp_list';
$state->meta[303] = array( 'spt_captain' => 99 );
assert_test( '' === $r::captain_email( 204 ), 'a captain with no address yields nothing rather than an empty send' );

assert_test( '' === $r::captain_email( 0 ), 'no team means no captain' );

echo "\n=== the Bcc list ===\n\n";

$state->options['splm_default_season']                = 500;
$state->options['splm_discipline_digest_recipients']  = 'board@example.test, bad-entry, second@example.test';
$state->options['splm_discipline_notice_cc']          = 'extra@example.test';

$bcc = $r::bcc_for( 500, 200 );

assert_test( in_array( 'board@example.test', $bcc, true ), 'digest recipients are copied' );
assert_test( in_array( 'second@example.test', $bcc, true ), 'a multi-entry digest list is fully parsed' );
assert_test( ! in_array( 'bad-entry', $bcc, true ), 'a malformed digest entry is filtered out' );
assert_test( in_array( 'extra@example.test', $bcc, true ), 'the configurable cc list is copied' );
assert_test( in_array( 'direct@example.test', $bcc, true ), 'the captain is copied for the current season' );

echo "\n=== the captain is scoped to the current season ===\n\n";

$historical = $r::bcc_for( 499, 200 );
assert_test(
	! in_array( 'direct@example.test', $historical, true ),
	'the captain is NOT copied for a past season: sp_list is not season-scoped, so the captain of record may be the wrong person'
);
assert_test(
	in_array( 'board@example.test', $historical, true ),
	'the board is still copied for a past season'
);

echo "\n=== no admin_email fallback ===\n\n";

$state->options['splm_discipline_digest_recipients'] = '';
$state->options['splm_discipline_notice_cc']         = '';
$state->options['admin_email']                       = 'admin@example.test';

$empty = $r::bcc_for( 499, 0 );
assert_test( array() === $empty, 'with nothing configured the Bcc list is empty' );
assert_test(
	! in_array( 'admin@example.test', $empty, true ),
	'admin_email is NOT a fallback here, unlike the digest: silently copying the site admin on a player disciplinary notice is a privacy surprise'
);

echo "\n=== de-duplication ===\n\n";

$state->options['splm_discipline_digest_recipients'] = 'same@example.test, same@example.test';
$state->options['splm_discipline_notice_cc']         = 'same@example.test';
$deduped = $r::bcc_for( 499, 0 );
assert_test( array( 'same@example.test' ) === $deduped, 'an address listed twice is copied once' );

echo "\n=== the meta reads pass \$single ===\n\n";

// Regression guard. Without `, true` WordPress hands back an array, and
// absint( array( 77 ) ) is 1 — so a player with sp_user meta would resolve to
// user ID 1, normally the site owner, and the notice would be addressed to
// them and recorded on the row as correct. This asserts the stub's array
// behaviour is real, so the guard cannot be defeated by relaxing the stub.
assert_test(
	array() === get_post_meta( 999, 'spt_email' ),
	'the stub returns an array when $single is omitted, mirroring WordPress'
);
assert_test(
	array( 'direct@example.test' ) === get_post_meta( 10, 'spt_email' ),
	'a present value is still wrapped in an array when $single is omitted'
);
assert_test( 1 === absint( array( 77 ) ), 'absint() of an array is 1, which is why $single matters' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php sportspress-league-manager/tests/test-discipline-notice-recipients.php
```

Expected: FAIL — the class file does not exist.

- [ ] **Step 3: Create the recipients class**

Create `sportspress-league-manager/includes/class-discipline-notice-recipients.php`:

```php
<?php
/**
 * Who a disciplinary notice reaches.
 *
 * The player goes in To:; everyone else goes in Bcc: so the player never sees
 * the board's addresses and the board sees the player's copy verbatim.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Recipients {

	/**
	 * A player's email address and how it was found.
	 *
	 * Follows the chain class-privacy.php already establishes for players:
	 * the spt_email post meta first, then the linked WordPress user. The path
	 * is returned alongside the address because the technical queue view shows
	 * it — "which of the two sources did this come from" is the first question
	 * asked when a notice reaches the wrong inbox.
	 *
	 * @param int $player_id Player post id.
	 * @return array array( 'email' => string, 'via' => string ). Both empty
	 *               when nothing resolves.
	 */
	public static function player_email( int $player_id ): array {
		$none = array(
			'email' => '',
			'via'   => '',
		);

		if ( $player_id <= 0 ) {
			return $none;
		}

		$direct = sanitize_email( (string) get_post_meta( $player_id, 'spt_email', true ) );
		if ( $direct && is_email( $direct ) ) {
			return array(
				'email' => $direct,
				'via'   => 'spt_email',
			);
		}

		$user_id = absint( get_post_meta( $player_id, 'sp_user', true ) );
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && ! empty( $user->user_email ) ) {
				$linked = sanitize_email( (string) $user->user_email );
				if ( $linked && is_email( $linked ) ) {
					return array(
						'email' => $linked,
						'via'   => 'sp_user',
					);
				}
			}
		}

		return $none;
	}

	/**
	 * A team captain's email address.
	 *
	 * The captain lives on the team's active sp_list, a mechanism owned by the
	 * player-tools sibling plugin. Every step degrades to no captain rather
	 * than to an error: the sibling may be inactive, the team may have no list,
	 * the list may have no captain, and the captain may have no address. A
	 * notice must still reach the player in all four cases.
	 *
	 * @param int $team_id Team post id.
	 * @return string Address, or '' when unresolvable.
	 */
	public static function captain_email( int $team_id ): string {
		if ( $team_id <= 0 ) {
			return '';
		}

		$list_id = absint( get_post_meta( $team_id, 'sp_list', true ) );
		if ( ! $list_id || 'sp_list' !== get_post_type( $list_id ) ) {
			return '';
		}

		$captain_id = absint( get_post_meta( $list_id, 'spt_captain', true ) );
		if ( ! $captain_id ) {
			return '';
		}

		return self::player_email( $captain_id )['email'];
	}

	/**
	 * The Bcc list for a notice.
	 *
	 * Deliberately has NO admin_email fallback. SPLM_Discipline_Digest falls
	 * back to the site admin because a digest with no recipients is useless;
	 * a notice's purpose is served by reaching the player, and silently copying
	 * the site admin on a player's disciplinary mail is a privacy surprise.
	 *
	 * @param int $season_id Season the notice belongs to.
	 * @param int $team_id   The player's attributed team.
	 * @return array De-duplicated addresses.
	 */
	public static function bcc_for( int $season_id, int $team_id ): array {
		$out = array();

		// sp_list is not season-scoped — a team has one active list — so for a
		// past season the captain of record may be a different person than the
		// one who was captain when the minutes were earned. Copy the captain
		// only while the notice's season is the one the pass runs against.
		if ( $season_id === absint( get_option( 'splm_default_season', 0 ) ) ) {
			$captain = self::captain_email( $team_id );
			if ( $captain ) {
				$out[] = $captain;
			}
		}

		$out = array_merge(
			$out,
			self::parse_list( (string) get_option( 'splm_discipline_digest_recipients', '' ) ),
			self::parse_list( (string) get_option( 'splm_discipline_notice_cc', '' ) )
		);

		return array_values( array_unique( $out ) );
	}

	/**
	 * Parse a comma-separated address list, keeping only valid addresses.
	 *
	 * Matches SPLM_Discipline_Digest::recipients()' parsing so the same option
	 * cannot be read two different ways.
	 *
	 * @param string $raw Raw option value.
	 * @return array
	 */
	private static function parse_list( string $raw ): array {
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

		return array_values( array_filter( $parts, 'is_email' ) );
	}

	/**
	 * Player post ids whose address is this email.
	 *
	 * The inverse of player_email(), for the GDPR eraser. It lives here so the
	 * two directions of the same chain cannot drift: if spt_email stops being
	 * the primary source, both the mailer and the eraser change together.
	 *
	 * @param string $email Email address.
	 * @return array Player post ids.
	 */
	public static function players_for_email( string $email ): array {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( ! $email || ! is_email( $email ) ) {
			return array();
		}

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'spt_email' AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table property, not a value.
				$email
			)
		);

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$linked = $wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'sp_user' AND meta_value = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table property, not a value.
					(int) $user->ID
				)
			);
			$ids = array_merge( (array) $ids, (array) $linked );
		}

		return array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
	}
}
```

- [ ] **Step 4: Register the test file**

In `run-all-tests.sh`, after the `test-discipline-notice-predicate.php` line:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-discipline-notice-recipients.php"
```

- [ ] **Step 5: Run it**

```bash
php sportspress-league-manager/tests/test-discipline-notice-recipients.php
```

Expected: PASS with `Failed: 0`. Note `players_for_email()` is not exercised here — it needs `$wpdb` and `get_user_by`, and is covered by Task 15's staging check instead.

- [ ] **Step 6: Lint and commit**

```bash
php -l sportspress-league-manager/includes/class-discipline-notice-recipients.php
git add sportspress-league-manager/includes/class-discipline-notice-recipients.php \
        sportspress-league-manager/tests/test-discipline-notice-recipients.php \
        run-all-tests.sh
git commit -m "feat(discipline): resolve notice recipients

Player address via spt_email then the linked user, recording which path
was used. Captain via the team's sp_list, degrading silently when the
player-tools sibling is inactive or no captain is set.

The captain is copied only for the current season: sp_list is not
season-scoped, so for a past season the captain of record may be the
wrong person. And unlike the digest there is no admin_email fallback —
silently copying the site admin on a player's disciplinary mail is a
privacy surprise, not a convenience."
```

---

### Task 6: Subject, body, and the next-game label

The wording, and the disclaimer that lets a suspension name a date without binding state to it.

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-notice-mail.php` (body half; Task 7 adds the send)
- Test: `sportspress-league-manager/tests/test-discipline-notice-body.php`
- Modify: `run-all-tests.sh`

**Interfaces:**
- Produces: on `SPLM_Discipline_Notice_Mail`:
  - `subject( string $consequence, string $season_name ): string`
  - `body( array $context ): string` — pure; `$context` keys `player_name`, `season_name`, `consequence`, `games`, `value`, `next_threshold`, `game_label`
  - `next_game_label( int $team_id ): string` — `''` when nothing resolves
  - `next_threshold( int $value, array $tiers ): int` — 0 when nothing is above

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-discipline-notice-body.php`:

```php
<?php
/**
 * Standalone tests for notice wording.
 *
 * The suspension body's asterisked disclaimer is load-bearing, not cosmetic:
 * it is what makes the obligation "your next scheduled game" rather than a
 * specific fixture, which is why no notice state binds to an event id. A
 * degraded body that names no game must still read as a complete sentence.
 */

define( 'ABSPATH', __DIR__ );

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

function __( $text, $domain = null ) { // phpcs:ignore
	return $text;
}

// suspension_sentence() pluralises its game count through _n().
function _n( $single, $plural, $number, $domain = null ) { // phpcs:ignore
	return 1 === (int) $number ? $single : $plural;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function absint( $v ) {
	return abs( (int) $v );
}

require_once __DIR__ . '/../includes/class-penalty-watch.php';
require_once __DIR__ . '/../includes/class-discipline-notice-mail.php';

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

$mail = 'SPLM_Discipline_Notice_Mail';

echo "\n=== next_threshold() ===\n\n";

$tiers = SPLM_Penalty_Watch::default_tiers();

assert_test( 18 === $mail::next_threshold( 12, $tiers ), 'at 12 the next season threshold is 18' );
assert_test( 18 === $mail::next_threshold( 13, $tiers ), 'a value between tiers still points at the next one' );
assert_test( 0 === $mail::next_threshold( 18, $tiers ), 'at the top season tier there is no next threshold' );
assert_test( 0 === $mail::next_threshold( 40, $tiers ), 'past every threshold there is no next threshold' );

// warning_sentence() says "you will be suspended", so a tier that only warns
// must not be offered as the next threshold or the mail states a falsehood.
$warn_then_suspend = array(
	array( 'key' => 'w1', 'scope' => 'season', 'minutes' => 12, 'severity' => 'warning', 'consequence' => 'warn', 'games' => 0 ),
	array( 'key' => 'w2', 'scope' => 'season', 'minutes' => 18, 'severity' => 'warning', 'consequence' => 'warn', 'games' => 0 ),
	array( 'key' => 's1', 'scope' => 'season', 'minutes' => 25, 'severity' => 'critical', 'consequence' => 'suspend', 'games' => 1 ),
);
assert_test(
	25 === $mail::next_threshold( 12, $warn_then_suspend ),
	'a tier that only warns is skipped: the next threshold named is the next one that actually suspends'
);
assert_test(
	0 === $mail::next_threshold( 12, array( array( 'key' => 'w', 'scope' => 'season', 'minutes' => 18, 'severity' => 'warning', 'consequence' => 'warn', 'games' => 0 ) ) ),
	'with no suspending tier above, there is no threshold to promise'
);
assert_test(
	0 === $mail::next_threshold( 2, array( array( 'key' => 'win', 'scope' => 'window', 'minutes' => 8, 'severity' => 'critical', 'consequence' => 'suspend', 'games' => 1 ) ) ),
	'a window tier is not a number a player can reason about from a season total, so it is not offered'
);

echo "\n=== the warning body names the next threshold ===\n\n";

$warning = $mail::body(
    array(
        'player_name'    => 'Alex',
        'season_name'    => 'W2025-26',
        'consequence'    => 'warn',
        'games'          => 0,
        'value'          => 12,
        'next_threshold' => 18,
        'game_label'     => '',
    )
);

assert_test( false !== strpos( $warning, 'Alex' ), 'the warning greets the player by name' );
assert_test( false !== strpos( $warning, '12' ), 'the warning states the accumulated total' );
assert_test( false !== strpos( $warning, 'W2025-26' ), 'the warning names the season' );
assert_test( false !== strpos( $warning, '18' ), 'the warning names the next threshold, which is what makes it a warning' );
assert_test( false === strpos( $warning, 'suspended' ), 'a warning does not tell the player they are suspended' );

$topped_out = $mail::body(
    array(
        'player_name'    => 'Alex',
        'season_name'    => 'W2025-26',
        'consequence'    => 'warn',
        'games'          => 0,
        'value'          => 30,
        'next_threshold' => 0,
        'game_label'     => '',
    )
);
assert_test(
	false === strpos( $topped_out, ' 0 ' ),
	'a warning with no next threshold does not render a bare zero'
);

echo "\n=== the suspension body ===\n\n";

$suspension = $mail::body(
    array(
        'player_name'    => 'Alex',
        'season_name'    => 'W2025-26',
        'consequence'    => 'suspend',
        'games'          => 1,
        'value'          => 18,
        'next_threshold' => 0,
        'game_label'     => 'Sat Nov 8 vs Rangers',
    )
);

assert_test( false !== strpos( $suspension, 'suspended' ), 'the suspension says so plainly' );
assert_test( false !== strpos( $suspension, '1 game' ), 'the suspension states its length' );
assert_test( false !== strpos( $suspension, 'Sat Nov 8 vs Rangers' ), 'the resolved game is named' );
assert_test( false !== strpos( $suspension, '*' ), 'the resolved game carries the asterisk' );
assert_test(
	false !== strpos( $suspension, 'next scheduled game' ),
	'the footnote makes the obligation the next scheduled game, not the named fixture'
);

$multi = $mail::body(
    array(
        'player_name'    => 'Alex',
        'season_name'    => 'W2025-26',
        'consequence'    => 'suspend',
        'games'          => 3,
        'value'          => 26,
        'next_threshold' => 0,
        'game_label'     => 'Sat Nov 8 vs Rangers',
    )
);
assert_test( false !== strpos( $multi, '3 games' ), 'a multi-game suspension is pluralised' );

echo "\n=== the suspension body degrades without a resolved game ===\n\n";

$degraded = $mail::body(
    array(
        'player_name'    => 'Alex',
        'season_name'    => 'W2025-26',
        'consequence'    => 'suspend',
        'games'          => 1,
        'value'          => 18,
        'next_threshold' => 0,
        'game_label'     => '',
    )
);

assert_test( false !== strpos( $degraded, 'next scheduled game' ), 'the degraded body still states the obligation' );
assert_test(
	false === strpos( $degraded, '*' ),
	'with no game named the asterisk is dropped, so the mail never carries a dangling footnote reference'
);
assert_test( false !== strpos( $degraded, 'suspended' ), 'the degraded body still says the player is suspended' );

echo "\n=== a missing player name degrades to a greeting, not an empty one ===\n\n";

$nameless = $mail::body(
    array(
        'player_name'    => '',
        'season_name'    => 'W2025-26',
        'consequence'    => 'warn',
        'games'          => 0,
        'value'          => 12,
        'next_threshold' => 18,
        'game_label'     => '',
    )
);
assert_test( false === strpos( $nameless, 'Hi ,' ), 'an unnamed player does not produce "Hi ,"' );

echo "\n=== subjects ===\n\n";

assert_test(
	false !== strpos( $mail::subject( 'warn', 'W2025-26' ), 'W2025-26' ),
	'the warning subject names the season'
);
assert_test(
	$mail::subject( 'warn', 'W2025-26' ) !== $mail::subject( 'suspend', 'W2025-26' ),
	'a suspension does not arrive under the same subject as a warning'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php sportspress-league-manager/tests/test-discipline-notice-body.php
```

Expected: FAIL — the class file does not exist.

- [ ] **Step 3: Create the mail class's body half**

Create `sportspress-league-manager/includes/class-discipline-notice-mail.php`. Task 7 appends `send()` to this class.

```php
<?php
/**
 * Notice wording and delivery.
 *
 * body() is pure so the wording can be tested exhaustively with no WordPress:
 * the resolved game arrives as a string the caller looked up, never as an id
 * this class queries.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Mail {

	/**
	 * The subject line.
	 *
	 * Warnings and suspensions get different subjects so a player can tell
	 * which arrived without opening it, and so a mail client threads them apart.
	 *
	 * @param string $consequence 'warn' or 'suspend'.
	 * @param string $season_name Season name.
	 * @return string
	 */
	public static function subject( string $consequence, string $season_name ): string {
		if ( 'suspend' === $consequence ) {
			/* translators: %s: season name. */
			return sprintf( __( 'You are suspended — %s', 'sportspress-league-manager' ), $season_name );
		}

		/* translators: %s: season name. */
		return sprintf( __( 'Penalty minutes warning — %s', 'sportspress-league-manager' ), $season_name );
	}

	/**
	 * The next SUSPENDING season threshold above a value.
	 *
	 * Two filters, both load-bearing. Only season-scope tiers, because a
	 * warning tells the player what their season total is heading towards and a
	 * window threshold is not a number they can reason about from one. And only
	 * tiers that actually suspend, because warning_sentence() renders "you will
	 * be suspended" — a league configured warn@12 / warn@18 / suspend@25 would
	 * otherwise tell a player at 12 they face suspension at 18, which is false.
	 *
	 * @param int   $value Current season total.
	 * @param array $tiers Tier list.
	 * @return int The next suspending threshold, or 0 when there is none above.
	 */
	public static function next_threshold( int $value, array $tiers ): int {
		$above = array();

		foreach ( $tiers as $tier ) {
			if ( 'season' !== (string) ( $tier['scope'] ?? '' ) ) {
				continue;
			}
			if ( 'suspend' !== (string) ( $tier['consequence'] ?? '' ) ) {
				continue;
			}
			$minutes = (int) ( $tier['minutes'] ?? 0 );
			if ( $minutes > $value ) {
				$above[] = $minutes;
			}
		}

		return $above ? min( $above ) : 0;
	}

	/**
	 * Render the notice body.
	 *
	 * Plain text, matching the waitlist offer email's register.
	 *
	 * @param array $context Keys: player_name, season_name, consequence, games,
	 *                       value, next_threshold, game_label.
	 * @return string
	 */
	public static function body( array $context ): string {
		$name = (string) ( $context['player_name'] ?? '' );

		/* translators: used in place of a player's name when none is on record. */
		$greeting = $name ? $name : __( 'there', 'sportspress-league-manager' );

		$lines = array(
			/* translators: %s: player name. */
			sprintf( __( 'Hi %s,', 'sportspress-league-manager' ), $greeting ),
			'',
			sprintf(
				/* translators: 1: penalty minutes, 2: season name. */
				__( 'You have accumulated %1$d penalty minutes in %2$s.', 'sportspress-league-manager' ),
				(int) ( $context['value'] ?? 0 ),
				(string) ( $context['season_name'] ?? '' )
			),
			'',
		);

		if ( 'suspend' === (string) ( $context['consequence'] ?? '' ) ) {
			$lines[] = self::suspension_sentence(
				(int) ( $context['games'] ?? 1 ),
				(string) ( $context['game_label'] ?? '' )
			);
		} else {
			$lines[] = self::warning_sentence( (int) ( $context['next_threshold'] ?? 0 ) );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The warning's operative sentence.
	 *
	 * Naming the next threshold is what makes this a warning rather than a
	 * notification. With no threshold above the player's total there is nothing
	 * to warn towards, so the sentence is dropped rather than rendering a zero.
	 *
	 * @param int $next_threshold Next threshold, or 0.
	 * @return string
	 */
	private static function warning_sentence( int $next_threshold ): string {
		if ( $next_threshold > 0 ) {
			return sprintf(
				/* translators: %d: the next penalty-minute threshold. */
				__( 'This is a warning. At %d penalty minutes you will be suspended.', 'sportspress-league-manager' ),
				$next_threshold
			);
		}

		return __( 'This is a warning about your accumulated penalty minutes.', 'sportspress-league-manager' );
	}

	/**
	 * The suspension's operative sentence.
	 *
	 * The named game is advisory and the footnote is what makes it so. Games
	 * get rescheduled, so the obligation has to be "your next scheduled game"
	 * rather than a fixture — which is also why no notice row stores an event
	 * id. With nothing resolved the sentence degrades to the obligation alone
	 * and the asterisk is dropped, so the mail never carries a footnote marker
	 * with nothing to point at.
	 *
	 * @param int    $games      Games owed.
	 * @param string $game_label Resolved next game, or ''.
	 * @return string
	 */
	private static function suspension_sentence( int $games, string $game_label ): string {
		$games = max( 1, $games );

		$count = sprintf(
			/* translators: %d: number of games. */
			_n( '%d game', '%d games', $games, 'sportspress-league-manager' ),
			$games
		);

		if ( '' === $game_label ) {
			return sprintf(
				/* translators: %s: a game count such as "1 game". */
				__( 'You are suspended for %s, to be served at your next scheduled game.', 'sportspress-league-manager' ),
				$count
			);
		}

		return sprintf(
			/* translators: 1: a game count such as "1 game", 2: the next scheduled game. */
			__( "You are suspended for %1\$s, to be served %2\$s.*\n\n*or your next scheduled game.", 'sportspress-league-manager' ),
			$count,
			$game_label
		);
	}

	/**
	 * A human label for a team's next scheduled game.
	 *
	 * Resolved at render time and never stored. Includes 'future' as well as
	 * 'publish' because a scheduled fixture that has not been published yet is
	 * still the game the player will sit — the same status pair the dashboard's
	 * upcoming-games query uses.
	 *
	 * @param int $team_id Team post id.
	 * @return string Label, or '' when nothing resolves.
	 */
	public static function next_game_label( int $team_id ): string {
		if ( $team_id <= 0 ) {
			return '';
		}

		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				// current_time(), NOT gmdate(). WP_Query matches date_query
				// against post_date, which is site-local; between 00:00 and
				// 05:00 UTC the two dates disagree and an event later the same
				// local evening would be excluded. This is the same date the
				// pass and watch_context() derive.
				'date_query'     => array(
					array(
						'after'     => current_time( 'Y-m-d' ),
						'inclusive' => true,
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'sp_team',
						'value' => $team_id,
					),
				),
				'fields'         => 'ids',
			)
		);

		if ( ! $events ) {
			return '';
		}

		$event_id = (int) $events[0];
		$title    = get_the_title( $event_id );
		$when     = get_post_time( get_option( 'date_format' ), false, $event_id, true );

		if ( ! $when ) {
			return (string) $title;
		}

		if ( ! $title ) {
			return (string) $when;
		}

		/* translators: 1: game date, 2: game title. */
		return sprintf( __( '%1$s — %2$s', 'sportspress-league-manager' ), $when, $title );
	}
}
```

- [ ] **Step 4: Register the test file**

In `run-all-tests.sh`, after the `test-discipline-notice-recipients.php` line:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-discipline-notice-body.php"
```

- [ ] **Step 5: Run it**

```bash
php sportspress-league-manager/tests/test-discipline-notice-body.php
```

Expected: PASS with `Failed: 0`. `next_game_label()` is not exercised — it needs `get_posts()` and is covered by staging.

- [ ] **Step 6: Lint and commit**

```bash
php -l sportspress-league-manager/includes/class-discipline-notice-mail.php
git add sportspress-league-manager/includes/class-discipline-notice-mail.php \
        sportspress-league-manager/tests/test-discipline-notice-body.php \
        run-all-tests.sh
git commit -m "feat(discipline): notice wording

A warning names the next threshold, which is what makes it a warning
rather than a notification. A suspension names the resolved game but
footnotes it as 'or your next scheduled game', so the obligation is the
next game rather than a fixture — which is why no row stores an event id.

With nothing resolved the sentence degrades to the obligation alone and
the asterisk is dropped, so the mail never shows a footnote marker with
nothing to point at."
```

---

### Task 7: Modes and the send

Reads the two delivery modes, and performs the send with its Bcc header and failure recording.

**Files:**
- Modify: `sportspress-league-manager/includes/class-discipline-notice.php` (add the mode block)
- Modify: `sportspress-league-manager/includes/class-discipline-notice-mail.php` (add `send()`)
- Test: `sportspress-league-manager/tests/test-discipline-notice-mode.php`
- Modify: `run-all-tests.sh`

**Interfaces:**
- Consumes: `SPLM_Discipline_Notice_Recipients::bcc_for()` (Task 5); `SPLM_Discipline_Notice_Mail::subject()`/`body()` (Task 6); `SPLM_Discipline_Notice_Database::update()` and statuses (Task 3).
- Produces:
  - `SPLM_Discipline_Notice::MODE_AUTOMATIC` = `'automatic'`, `MODE_QUEUED` = `'queued'`, `MODE_DISABLED` = `'disabled'`, and `MODES` listing all three
  - `SPLM_Discipline_Notice::mode_for( string $consequence ): string`
  - `SPLM_Discipline_Notice::sanitize_mode( $raw ): string`
  - `SPLM_Discipline_Notice::option_for( string $consequence ): string` — the option name
  - `SPLM_Discipline_Notice_Mail::send( int $notice_id, array $context, string $to, array $bcc ): bool` — writes `sent`/`failed` and returns whether mail was accepted

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-discipline-notice-mode.php`:

```php
<?php
/**
 * Standalone tests for delivery modes and the send.
 *
 * Both modes default to disabled because this is outbound mail to players and
 * an upgrade must never begin sending. That default is asserted here rather
 * than left to the settings screen, since the pass reads these options
 * directly and a wrong default would start mailing on the first cron run.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Notice_Mode_Test_State {
	/** Option name => value. */
	public $options = array();

	/** Each wp_mail() call as array( to, subject, body, headers ). */
	public $mail = array();

	/** Whether wp_mail() should report success. */
	public $mail_succeeds = true;

	/** Each SPLM_Discipline_Notice_Database::update() call as array( id, fields ). */
	public $updates = array();
}

function splm_notice_mode_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Notice_Mode_Test_State();
	}
	return $state;
}

function get_option( $name, $default = false ) {
	$options = splm_notice_mode_test_state()->options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function wp_mail( $to, $subject, $body, $headers = array() ) {
	splm_notice_mode_test_state()->mail[] = array( $to, $subject, $body, $headers );
	return splm_notice_mode_test_state()->mail_succeeds;
}

function __( $text, $domain = null ) { // phpcs:ignore
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) { // phpcs:ignore
	return 1 === (int) $number ? $single : $plural;
}

function absint( $v ) {
	return abs( (int) $v );
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

// SPLM_Discipline_Notice_Pass::baseline_token() digests its inputs through this.
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

require_once __DIR__ . '/../includes/class-penalty-watch.php';
require_once __DIR__ . '/../includes/class-discipline-notice.php';
require_once __DIR__ . '/../includes/class-discipline-notice-mail.php';

/**
 * Stand-in for the database class, so the send's status writes are observable
 * without a fake $wpdb. Declared before the mailer is exercised; the real
 * class is never loaded in this file.
 */
class SPLM_Discipline_Notice_Database {
	const STATUS_SENT   = 'sent';
	const STATUS_FAILED = 'failed';

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function update( $id, $fields ) {
		splm_notice_mode_test_state()->updates[] = array( (int) $id, $fields );
		return true;
	}
}

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

$notice = 'SPLM_Discipline_Notice';
$mail   = 'SPLM_Discipline_Notice_Mail';
$state  = splm_notice_mode_test_state();

echo "\n=== both modes default to disabled ===\n\n";

$state->options = array();

assert_test(
	'disabled' === $notice::mode_for( 'warn' ),
	'the warning mode defaults to disabled, so an upgrade never begins mailing players'
);
assert_test(
	'disabled' === $notice::mode_for( 'suspend' ),
	'the suspension mode defaults to disabled'
);

echo "\n=== the two modes are independent ===\n\n";

$state->options['splm_discipline_notice_mode_warning']    = 'automatic';
$state->options['splm_discipline_notice_mode_suspension'] = 'queued';

assert_test( 'automatic' === $notice::mode_for( 'warn' ), 'the warning mode is read from its own option' );
assert_test( 'queued' === $notice::mode_for( 'suspend' ), 'the suspension mode is read from its own option' );
assert_test(
	$notice::option_for( 'warn' ) !== $notice::option_for( 'suspend' ),
	'the two modes are backed by two different options'
);

echo "\n=== an inert consequence has no mode ===\n\n";

assert_test( 'disabled' === $notice::mode_for( 'none' ), 'a consequence of none is treated as disabled' );
assert_test( 'disabled' === $notice::mode_for( 'nonsense' ), 'an unknown consequence is treated as disabled' );

echo "\n=== sanitize_mode() ===\n\n";

assert_test( 'automatic' === $notice::sanitize_mode( 'automatic' ), 'automatic survives' );
assert_test( 'queued' === $notice::sanitize_mode( 'queued' ), 'queued survives' );
assert_test( 'disabled' === $notice::sanitize_mode( 'disabled' ), 'disabled survives' );
assert_test( 'disabled' === $notice::sanitize_mode( 'banish' ), 'an unknown value falls back to disabled' );
assert_test(
	'disabled' === $notice::sanitize_mode( null ),
	'null falls back to disabled rather than fatalling: options.php passes null when the field is absent from the POST'
);
assert_test( 'disabled' === $notice::sanitize_mode( array( 'automatic' ) ), 'an array falls back to disabled' );

echo "\n=== a stored garbage value cannot enable sending ===\n\n";

$state->options['splm_discipline_notice_mode_warning'] = 'AUTOMATIC';
assert_test(
	'disabled' === $notice::mode_for( 'warn' ),
	'mode_for() sanitises what it reads, so a hand-edited option cannot enable an unrecognised mode'
);

echo "\n=== send() ===\n\n";

$state->mail          = array();
$state->updates       = array();
$state->mail_succeeds = true;

$context = array(
	'player_name'    => 'Alex',
	'season_name'    => 'W2025-26',
	'consequence'    => 'suspend',
	'games'          => 1,
	'value'          => 18,
	'next_threshold' => 0,
	'game_label'     => '',
);

$sent = $mail::send( 42, $context, 'alex@example.test', array( 'board@example.test', 'cap@example.test' ) );

assert_test( true === $sent, 'a successful send reports true' );
assert_test( 1 === count( $state->mail ), 'exactly one mail is sent' );
assert_test( 'alex@example.test' === $state->mail[0][0], 'the player is the To: recipient' );

$headers = implode( "\n", (array) $state->mail[0][3] );
assert_test( false !== strpos( $headers, 'Bcc:' ), 'the others are copied via Bcc' );
assert_test( false !== strpos( $headers, 'board@example.test' ), 'the board is in the Bcc header' );
assert_test(
	false === strpos( $state->mail[0][0], 'board@example.test' ),
	'the board is not in To:, so the player never sees the board addresses'
);

assert_test( 1 === count( $state->updates ), 'the row is updated exactly once' );
assert_test( 42 === $state->updates[0][0], 'the right row is updated' );
assert_test( 'sent' === $state->updates[0][1]['status'], 'a successful send records sent' );
assert_test( '' === $state->updates[0][1]['last_error'], 'a successful send clears any previous error' );
assert_test( ! empty( $state->updates[0][1]['sent_at'] ), 'a successful send stamps sent_at' );
assert_test(
	$state->updates[0][1]['sent_at'] === gmdate( 'Y-m-d H:i:s' ),
	'sent_at is UTC'
);
assert_test(
	false !== strpos( (string) $state->updates[0][1]['bcc'], 'board@example.test' ),
	'the addresses actually copied are stored on the row, so the technical view shows what happened rather than what would happen now'
);

echo "\n=== send() with no Bcc omits the header ===\n\n";

$state->mail    = array();
$state->updates = array();

$mail::send( 43, $context, 'alex@example.test', array() );
$no_bcc = implode( "\n", (array) $state->mail[0][3] );

assert_test( false === strpos( $no_bcc, 'Bcc:' ), 'an empty Bcc list produces no Bcc header rather than an empty one' );

echo "\n=== send() records failure instead of swallowing it ===\n\n";

$state->mail          = array();
$state->updates       = array();
$state->mail_succeeds = false;

$result = $mail::send( 44, $context, 'alex@example.test', array() );

assert_test( false === $result, 'a rejected send reports false' );
assert_test( 'failed' === $state->updates[0][1]['status'], 'a rejected send records failed' );
assert_test( '' !== $state->updates[0][1]['last_error'], 'a rejected send records why' );
assert_test( empty( $state->updates[0][1]['sent_at'] ), 'a rejected send does not stamp sent_at' );

echo "\n=== send() refuses an unresolvable address before calling wp_mail ===\n\n";

$state->mail          = array();
$state->updates       = array();
$state->mail_succeeds = true;

$no_address = $mail::send( 45, $context, '', array( 'board@example.test' ) );

assert_test( false === $no_address, 'no address means no send' );
assert_test( array() === $state->mail, 'wp_mail is not called at all' );
assert_test( 'failed' === $state->updates[0][1]['status'], 'the row is still marked failed so it stays actionable' );
assert_test(
	false !== stripos( (string) $state->updates[0][1]['last_error'], 'email' ),
	'the recorded error names the missing address'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php sportspress-league-manager/tests/test-discipline-notice-mode.php
```

Expected: FAIL — `mode_for()` and `send()` do not exist.

- [ ] **Step 3: Add the mode block to `SPLM_Discipline_Notice`**

Insert into `class-discipline-notice.php`, above `should_fire()`:

```php
	const MODE_AUTOMATIC = 'automatic';
	const MODE_QUEUED    = 'queued';
	const MODE_DISABLED  = 'disabled';

	const MODES = array( self::MODE_DISABLED, self::MODE_QUEUED, self::MODE_AUTOMATIC );

	const OPTION_MODE_WARNING    = 'splm_discipline_notice_mode_warning';
	const OPTION_MODE_SUSPENSION = 'splm_discipline_notice_mode_suspension';

	/**
	 * The option backing a consequence's delivery mode.
	 *
	 * @param string $consequence 'warn' or 'suspend'.
	 * @return string Option name, or '' for a consequence with no mode.
	 */
	public static function option_for( string $consequence ): string {
		if ( 'suspend' === $consequence ) {
			return self::OPTION_MODE_SUSPENSION;
		}
		if ( 'warn' === $consequence ) {
			return self::OPTION_MODE_WARNING;
		}

		return '';
	}

	/**
	 * The delivery mode governing a consequence.
	 *
	 * Sanitises what it reads rather than trusting the stored value: these
	 * options decide whether mail goes to players, so a hand-edited or
	 * partially-migrated option must not be able to enable an unrecognised
	 * mode. Anything unexpected reads as disabled.
	 *
	 * @param string $consequence 'warn' or 'suspend'.
	 * @return string One of MODES.
	 */
	public static function mode_for( string $consequence ): string {
		$option = self::option_for( $consequence );
		if ( '' === $option ) {
			return self::MODE_DISABLED;
		}

		return self::sanitize_mode( get_option( $option, self::MODE_DISABLED ) );
	}

	/**
	 * Validate a delivery mode.
	 *
	 * Untyped because this runs as a register_setting() sanitiser: options.php
	 * hands the callback null when the field is missing from the POST, and a
	 * hard string type hint would turn that into a fatal on save.
	 *
	 * @param mixed $raw Candidate mode.
	 * @return string One of MODES; disabled for anything unrecognised.
	 */
	public static function sanitize_mode( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return self::MODE_DISABLED;
		}

		return in_array( $raw, self::MODES, true ) ? $raw : self::MODE_DISABLED;
	}
```

- [ ] **Step 4: Add `send()` to `SPLM_Discipline_Notice_Mail`**

Append to `class-discipline-notice-mail.php`:

```php
	/**
	 * Send one notice and record the outcome on its row.
	 *
	 * The player is the To: recipient and everyone else is Bcc'd, so the player
	 * never sees the board's addresses and the board sees the player's copy
	 * verbatim. The addresses actually used are written to the row, not
	 * recomputed later: the technical queue view has to be able to show what
	 * happened rather than what would happen if the notice were sent now.
	 *
	 * @param int    $notice_id Row id.
	 * @param array  $context   Body context; see body().
	 * @param string $to        The player's resolved address.
	 * @param array  $bcc       Addresses to copy.
	 * @return bool Whether wp_mail() accepted the message.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function send( int $notice_id, array $context, string $to, array $bcc ): bool {
		if ( '' === $to ) {
			// Caught before wp_mail() so the row records a cause a human can
			// act on, rather than a generic delivery failure.
			SPLM_Discipline_Notice_Database::update(
				$notice_id,
				array(
					'status'     => SPLM_Discipline_Notice_Database::STATUS_FAILED,
					'last_error' => __( 'No email address on file for this player.', 'sportspress-league-manager' ),
					'bcc'        => implode( ', ', $bcc ),
				)
			);

			return false;
		}

		// A suspended player who captains their own team resolves as their own
		// captain Bcc and would receive two copies.
		$bcc = array_values( array_diff( $bcc, array( $to ) ) );

		$headers = array();
		if ( $bcc ) {
			$headers[] = 'Bcc: ' . implode( ', ', $bcc );
		}

		$sent = wp_mail(
			$to,
			self::subject( (string) ( $context['consequence'] ?? 'warn' ), (string) ( $context['season_name'] ?? '' ) ),
			self::body( $context ),
			$headers
		);

		if ( $sent ) {
			SPLM_Discipline_Notice_Database::update(
				$notice_id,
				array(
					'status'     => SPLM_Discipline_Notice_Database::STATUS_SENT,
					'sent_at'    => SPLM_Discipline_Notice_Database::now(),
					'recipient'  => $to,
					'bcc'        => implode( ', ', $bcc ),
					'last_error' => '',
				)
			);

			return true;
		}

		SPLM_Discipline_Notice_Database::update(
			$notice_id,
			array(
				'status'     => SPLM_Discipline_Notice_Database::STATUS_FAILED,
				'recipient'  => $to,
				'bcc'        => implode( ', ', $bcc ),
				'last_error' => __( 'wp_mail() rejected the message.', 'sportspress-league-manager' ),
			)
		);

		if ( class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::error(
				'discipline',
				sprintf( 'wp_mail() rejected a disciplinary notice: notice_id=%d', $notice_id )
			);
		}

		return false;
	}
```

- [ ] **Step 5: Register the test file**

In `run-all-tests.sh`, after the `test-discipline-notice-body.php` line:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-discipline-notice-mode.php"
```

- [ ] **Step 6: Run it**

```bash
php sportspress-league-manager/tests/test-discipline-notice-mode.php
```

Expected: PASS with `Failed: 0`. The two default-to-disabled assertions are the safety gate — if either fails, a cron run on an unconfigured site would mail players.

- [ ] **Step 7: Lint and commit**

```bash
php -l sportspress-league-manager/includes/class-discipline-notice.php
php -l sportspress-league-manager/includes/class-discipline-notice-mail.php
git add sportspress-league-manager/includes/class-discipline-notice.php \
        sportspress-league-manager/includes/class-discipline-notice-mail.php \
        sportspress-league-manager/tests/test-discipline-notice-mode.php \
        run-all-tests.sh
git commit -m "feat(discipline): delivery modes and the send

Two independent modes, warning and suspension, each disabled by default
because this is outbound mail to players and an upgrade must never begin
sending. mode_for() sanitises what it reads, so a hand-edited option
cannot enable an unrecognised mode.

send() puts the player in To: and everyone else in Bcc:, stores the
addresses actually used on the row, and records an unresolvable address
as a named failure before calling wp_mail rather than after."
```

---

### Task 8: Settings screen

Makes all three modes selectable, adds the cc list, and adds consequence and games inputs to the existing tier table.

**Files:**
- Modify: `sportspress-league-manager/includes/class-admin.php:115-192` (`register_spat_settings`), `:301-334` (`render_discipline_tiers_field`)
- Manual verification only — the settings screen needs a real WordPress.

**Interfaces:**
- Consumes: `SPLM_Discipline_Notice::MODES`, `sanitize_mode()`, `OPTION_MODE_WARNING`, `OPTION_MODE_SUSPENSION` (Task 7); `SPLM_Penalty_Watch::CONSEQUENCES`, `MAX_GAMES` (Task 1).
- Produces: three new registered options — `splm_discipline_notice_mode_warning`, `splm_discipline_notice_mode_suspension`, `splm_discipline_notice_cc`.

**⚠️ Every registered option in this group MUST have a rendered field.** `options.php` writes null over every option registered in a submitted group that is absent from the POST, so a registered-but-unrendered option is wiped on every save of this tab. `class-admin.php:186-188` already carries this warning for the three digest options; the three added here are subject to the same rule.

- [ ] **Step 1: Register the three options**

In `register_spat_settings()`, after the existing `splm_discipline_digest_day` registration (line 175):

```php
		register_setting(
			'splm_backend_settings',
			SPLM_Discipline_Notice::OPTION_MODE_WARNING,
			array(
				'sanitize_callback' => array( 'SPLM_Discipline_Notice', 'sanitize_mode' ),
				'default'           => SPLM_Discipline_Notice::MODE_DISABLED,
			)
		);
		register_setting(
			'splm_backend_settings',
			SPLM_Discipline_Notice::OPTION_MODE_SUSPENSION,
			array(
				'sanitize_callback' => array( 'SPLM_Discipline_Notice', 'sanitize_mode' ),
				'default'           => SPLM_Discipline_Notice::MODE_DISABLED,
			)
		);
		register_setting( 'splm_backend_settings', 'splm_discipline_notice_cc', array( 'sanitize_callback' => 'sanitize_text_field' ) );
```

- [ ] **Step 2: Add the three fields**

After the existing `splm_discipline_digest_day` field registration (line 190). Extend the existing "these three must have fields" comment to name all six:

```php
		$this->add_field( SPLM_Discipline_Notice::OPTION_MODE_WARNING, __( 'Warning Notices', 'sportspress-league-manager' ), array( $this, 'render_notice_mode_warning_field' ) );
		$this->add_field( SPLM_Discipline_Notice::OPTION_MODE_SUSPENSION, __( 'Suspension Notices', 'sportspress-league-manager' ), array( $this, 'render_notice_mode_suspension_field' ) );
		$this->add_field( 'splm_discipline_notice_cc', __( 'Notice Copies To', 'sportspress-league-manager' ), array( $this, 'render_notice_cc_field' ) );
```

- [ ] **Step 3: Render the mode fields**

Add to `class-admin.php`. One shared renderer, two thin callers, so the three options cannot drift apart:

```php
	/**
	 * Radio group for one notice delivery mode.
	 *
	 * @param string $option      Option name.
	 * @param string $description Field description.
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function render_notice_mode( string $option, string $description ): void {
		$labels = array(
			SPLM_Discipline_Notice::MODE_DISABLED  => __( 'Disabled — record nothing, send nothing', 'sportspress-league-manager' ),
			SPLM_Discipline_Notice::MODE_QUEUED    => __( 'Queued — hold for release in the dashboard', 'sportspress-league-manager' ),
			SPLM_Discipline_Notice::MODE_AUTOMATIC => __( 'Automatic — send as soon as the threshold is crossed', 'sportspress-league-manager' ),
		);

		$current = SPLM_Discipline_Notice::sanitize_mode( get_option( $option, SPLM_Discipline_Notice::MODE_DISABLED ) );

		echo '<fieldset>';
		foreach ( $labels as $value => $label ) {
			printf(
				'<label style="display:block"><input type="radio" name="%1$s" value="%2$s" %3$s/> %4$s</label>',
				esc_attr( $option ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}

	/**
	 * Delivery mode for warning notices.
	 */
	public function render_notice_mode_warning_field() {
		$this->render_notice_mode(
			SPLM_Discipline_Notice::OPTION_MODE_WARNING,
			__( 'What happens when a player crosses a threshold whose consequence is a warning. Off by default; turning this on starts mailing players.', 'sportspress-league-manager' )
		);
	}

	/**
	 * Delivery mode for suspension notices.
	 */
	public function render_notice_mode_suspension_field() {
		$this->render_notice_mode(
			SPLM_Discipline_Notice::OPTION_MODE_SUSPENSION,
			__( 'What happens when a player crosses a threshold whose consequence is a suspension. Queued is recommended: a score sheet that overstates penalty minutes would otherwise suspend a player before anyone reviews it.', 'sportspress-league-manager' )
		);
	}

	/**
	 * Extra addresses copied on every released notice.
	 */
	public function render_notice_cc_field() {
		echo '<input type="text" class="regular-text" name="splm_discipline_notice_cc" value="' . esc_attr( get_option( 'splm_discipline_notice_cc', '' ) ) . '"/>';
		echo '<p class="description">' . esc_html__( 'Comma-separated. Copied by Bcc on every notice, in addition to the digest recipients and the player’s captain. Leave blank to copy nobody extra.', 'sportspress-league-manager' ) . '</p>';
	}
```

- [ ] **Step 4: Add consequence and games to the tier table**

`render_discipline_tiers_field()` currently renders `key`, `scope` and `severity` as hidden inputs with only `minutes` editable. Add two columns. Replace the `<thead>` row and the `printf()` in the loop:

```php
		echo '<thead><tr><th>' . esc_html__( 'Tier', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Scope', 'sportspress-league-manager' )
			. '</th><th>' . esc_html__( 'Minutes', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Consequence', 'sportspress-league-manager' )
			. '</th><th>' . esc_html__( 'Games', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Would flag', 'sportspress-league-manager' ) . '</th></tr></thead><tbody>';

		$consequence_labels = array(
			'none'    => __( 'Nothing', 'sportspress-league-manager' ),
			'warn'    => __( 'Warning notice', 'sportspress-league-manager' ),
			'suspend' => __( 'Suspension', 'sportspress-league-manager' ),
		);

		foreach ( $tiers as $i => $tier ) {
			$count = $this->preview_flag_count( $tier );

			$consequence_select = '<select name="splm_discipline_tiers[' . (int) $i . '][consequence]">';
			foreach ( $consequence_labels as $value => $label ) {
				$consequence_select .= '<option value="' . esc_attr( $value ) . '" '
					. selected( (string) $tier['consequence'], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$consequence_select .= '</select>';

			printf(
				'<tr><td>%1$s<input type="hidden" name="splm_discipline_tiers[%2$d][key]" value="%3$s"/><input type="hidden" name="splm_discipline_tiers[%2$d][severity]" value="%4$s"/></td>'
					. '<td>%5$s<input type="hidden" name="splm_discipline_tiers[%2$d][scope]" value="%6$s"/></td>'
					. '<td><input type="number" min="1" max="200" name="splm_discipline_tiers[%2$d][minutes]" value="%7$d"/></td>'
					. '<td>%8$s</td>'
					. '<td><input type="number" min="0" max="%9$d" name="splm_discipline_tiers[%2$d][games]" value="%10$d"/></td>'
					. '<td>%11$s</td></tr>',
				esc_html( $tier['key'] ),
				(int) $i,
				esc_attr( $tier['key'] ),
				esc_attr( $tier['severity'] ),
				esc_html( $tier['scope'] ),
				esc_attr( $tier['scope'] ),
				(int) $tier['minutes'],
				$consequence_select, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr/esc_html above.
				(int) SPLM_Penalty_Watch::MAX_GAMES,
				(int) $tier['games'],
				esc_html(
					null === $count
						? __( '—', 'sportspress-league-manager' )
						/* translators: %d: number of players. */
						: sprintf( _n( '%d player', '%d players', $count, 'sportspress-league-manager' ), $count )
				)
			);
		}
```

Extend the closing description so the coercion rules are visible where they apply:

```php
		echo '<p class="description">' . esc_html__( 'Player counts are for the default season, so you can see whether a threshold is useful before saving it. Editing a threshold re-baselines that tier: players already over it are not notified, only those who earn more afterwards. Games apply to suspensions only.', 'sportspress-league-manager' ) . '</p>';
```

- [ ] **Step 5: Lint**

```bash
php -l sportspress-league-manager/includes/class-admin.php
```

- [ ] **Step 6: Confirm the existing suites still pass**

```bash
./run-all-tests.sh 2>&1 | tail -20
```

Expected: every suite passes. No test covers `class-admin.php` directly — this step is guarding against a syntax error or a changed constant breaking a suite that loads `SPLM_Penalty_Watch`.

- [ ] **Step 7: Commit**

```bash
git add sportspress-league-manager/includes/class-admin.php
git commit -m "feat(discipline): settings for notice modes and tier consequences

All three delivery modes are selectable for warnings and suspensions
independently, plus a comma-separated extra-copies list. The tier table
gains consequence and games inputs, which sanitize_tiers() can now
persist.

All six discipline options in this group have rendered fields, per the
existing warning in this file: options.php writes null over every option
registered in a submitted group that is absent from the POST."
```

---

### Task 9: The cron evaluation pass

The only writer of notice rows. Runs daily, `SPAT_Lock`-guarded, and never from a read path.

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-notice-pass.php`
- Test: `sportspress-league-manager/tests/test-discipline-notice-mode.php` (append a baseline-token section)

**Interfaces:**
- Consumes: `SPLM_Penalty_Watch::matches()` (Task 2); `SPLM_Discipline_Notice::should_fire()`/`select()`/`mode_for()` (Tasks 4, 7); `SPLM_Discipline_Notice_Database` (Task 3); `SPLM_Discipline_Notice_Recipients::player_email()`/`bcc_for()` (Task 5); `SPLM_Discipline_Notice_Mail` (Tasks 6, 7); `SPLM_Player_Stats_Aggregator::for_season()`/`window_cutoff()`/`window_totals()`/`season_start()`.
- Produces: `SPLM_Discipline_Notice_Pass` with `HOOK = 'splm_discipline_notices'`, `LOCK = 'splm_discipline_notices'`, `OPTION_BASELINE_TOKEN = 'splm_discipline_notice_baseline_token'`, and `schedule(): void`, `unschedule(): void`, `run(): int`, `baseline_token(): string`.

**How the three baselining triggers collapse into one mechanism.** The spec names three events that must baseline: first run after install, a mode leaving `disabled`, and a tier's `minutes` being edited. Rather than three separate hooks, the pass stores a token derived from *(each tier's key and minutes)* plus *(whether each mode is enabled at all)*. A token mismatch means baseline.

This gets all three for free, and gets one important non-trigger right too: the token records whether a mode is enabled as a **boolean**, not its value, so moving `queued → automatic` does **not** re-baseline. Both are enabled; only crossing the disabled boundary counts.

- [ ] **Step 1: Append the baseline-token tests**

Append to `sportspress-league-manager/tests/test-discipline-notice-mode.php`, immediately **before** its `=== Results ===` block:

```php
require_once __DIR__ . '/../includes/class-discipline-notice-pass.php';

// $state is already bound earlier in this file; do not redeclare it.
$pass = 'SPLM_Discipline_Notice_Pass';

echo "\n=== the baseline token ===\n\n";

$state->options = array(
	'splm_discipline_tiers'                     => SPLM_Penalty_Watch::default_tiers(),
	'splm_discipline_notice_mode_warning'       => 'queued',
	'splm_discipline_notice_mode_suspension'    => 'queued',
);
$base = $pass::baseline_token();

assert_test( '' !== $base, 'a token is produced' );
assert_test( $base === $pass::baseline_token(), 'the token is stable across calls with unchanged inputs' );

echo "\n=== a mode leaving disabled re-baselines ===\n\n";

$state->options['splm_discipline_notice_mode_warning'] = 'disabled';
assert_test(
	$base !== $pass::baseline_token(),
	'turning a mode off changes the token, so turning it back on re-baselines'
);

echo "\n=== queued to automatic does NOT re-baseline ===\n\n";

$state->options['splm_discipline_notice_mode_warning']    = 'automatic';
$state->options['splm_discipline_notice_mode_suspension'] = 'automatic';
assert_test(
	$base === $pass::baseline_token(),
	'the token records only WHETHER a mode is enabled, so queued to automatic is not a baselining event: both are enabled and the crossing that matters is the disabled boundary'
);

echo "\n=== editing a threshold re-baselines ===\n\n";

$lowered    = SPLM_Penalty_Watch::default_tiers();
$lowered[1]['minutes'] = 10;
$state->options['splm_discipline_tiers']                  = $lowered;
$state->options['splm_discipline_notice_mode_warning']    = 'queued';
$state->options['splm_discipline_notice_mode_suspension'] = 'queued';

assert_test(
	$base !== $pass::baseline_token(),
	'lowering a threshold changes the token, so nobody already over the new threshold is mailed'
);

echo "\n=== editing a consequence does NOT re-baseline ===\n\n";

$reconsequenced = SPLM_Penalty_Watch::default_tiers();
$reconsequenced[0]['consequence'] = 'suspend';
$reconsequenced[0]['games']       = 1;
$state->options['splm_discipline_tiers'] = $reconsequenced;

assert_test(
	$base === $pass::baseline_token(),
	'changing what a tier DOES is not a threshold change: a convener promoting a warning to a suspension means it to take effect, and the predicate still prevents a re-send at an unchanged total'
);
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
php sportspress-league-manager/tests/test-discipline-notice-mode.php
```

Expected: FAIL — `class-discipline-notice-pass.php` does not exist.

- [ ] **Step 3: Create the pass**

Create `sportspress-league-manager/includes/class-discipline-notice-pass.php`:

```php
<?php
/**
 * The daily notice evaluation pass.
 *
 * The ONLY writer of notice rows. Nothing on a read path may create one: if
 * watch_context() wrote notices, a convener opening the Leaders page would mail
 * players.
 *
 * Wrapped in SPAT_Lock for the reason the digest documents — WP-Cron can fire
 * the same event twice when two requests race the scheduler — with more at
 * stake here, because a duplicated suspension notice tells a player twice that
 * they are suspended.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Pass {

	const HOOK = 'splm_discipline_notices';
	const LOCK = 'splm_discipline_notices';

	const OPTION_BASELINE_TOKEN = 'splm_discipline_notice_baseline_token';

	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule the daily event if it is not already scheduled.
	 *
	 * WordPress forces PHP's timezone to UTC, so a bare strtotime() would
	 * schedule 07:00 UTC — 02:00 or 03:00 local for this league. The time is
	 * resolved in the site's timezone and converted back to a UTC timestamp,
	 * which is what wp_schedule_event() expects. Same workaround as the digest.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$next = false;
		try {
			$local = new DateTime( 'tomorrow 07:00', wp_timezone() );
			$next  = $local->getTimestamp();
		} catch ( Exception $e ) {
			$next = false;
		}

		if ( ! $next ) {
			$next = time() + DAY_IN_SECONDS;
		}

		wp_schedule_event( $next, 'daily', self::HOOK );
	}

	/**
	 * Clear the scheduled event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * A token over the inputs whose change must baseline rather than notify.
	 *
	 * Two things are folded in:
	 *
	 *  - each tier's key and minutes, so editing a threshold baselines that
	 *    tier — lowering season-critical from 18 to 10 must not mail everyone
	 *    already between the two;
	 *  - whether each mode is enabled, as a BOOLEAN, so turning notices on
	 *    mid-season baselines instead of mailing the sixteen players already
	 *    over season-warn.
	 *
	 * Recording enablement as a boolean rather than as the mode's value is what
	 * keeps queued -> automatic from re-baselining: both are enabled, and the
	 * only boundary that matters is disabled.
	 *
	 * A tier's consequence is deliberately NOT in the token. Promoting a
	 * warning tier to a suspension is a convener asking for it to take effect,
	 * and the re-fire predicate still prevents a re-send at an unchanged total.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function baseline_token(): string {
		$tiers = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );

		$thresholds = array();
		foreach ( $tiers as $tier ) {
			$thresholds[ (string) $tier['key'] ] = (int) $tier['minutes'];
		}
		ksort( $thresholds );

		$parts = array(
			'thresholds' => $thresholds,
			'warn_on'    => SPLM_Discipline_Notice::MODE_DISABLED !== SPLM_Discipline_Notice::mode_for( 'warn' ),
			'suspend_on' => SPLM_Discipline_Notice::MODE_DISABLED !== SPLM_Discipline_Notice::mode_for( 'suspend' ),
		);

		// Digest only, not a security primitive — xxh128 is faster than md5()
		// and does not trip weak-crypto scanners, matching the cache keys in
		// SPLM_Leaders_REST.
		return hash( 'xxh128', wp_json_encode( $parts ) );
	}

	/**
	 * Run the pass.
	 *
	 * @return int Rows written.
	 *
	 * SPAT_Lock and the discipline helpers are stateless static helpers with no
	 * dependencies — static access is exactly what lets them be called with no
	 * WordPress bootstrap. Injecting instances purely to satisfy the linter
	 * would cost testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function run(): int {
		if ( ! SPLM_REST_API::module_enabled( 'league_discipline' ) ) {
			return 0;
		}

		$warn_mode    = SPLM_Discipline_Notice::mode_for( 'warn' );
		$suspend_mode = SPLM_Discipline_Notice::mode_for( 'suspend' );

		// Both off means this feature is switched off: write nothing at all, so
		// discipline behaves exactly as it did before notices existed.
		if ( SPLM_Discipline_Notice::MODE_DISABLED === $warn_mode
			&& SPLM_Discipline_Notice::MODE_DISABLED === $suspend_mode ) {
			return 0;
		}

		// A parent too old to ship SPAT_Lock leaves nothing to serialise the
		// cron double-fire this method exists to survive. Telling a player twice
		// that they are suspended is worse than skipping a day, so the safe
		// failure is to write nothing.
		if ( ! class_exists( 'SPAT_Lock' ) ) {
			return 0;
		}

		$written = SPAT_Lock::with( self::LOCK, 300, array( __CLASS__, 'run_locked' ) );

		return false === $written ? 0 : (int) $written;
	}

	/**
	 * The pass body, already holding the lock.
	 *
	 * @return int Rows written.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function run_locked(): int {
		$season_id = (int) get_option( 'splm_default_season', 0 );
		if ( ! $season_id ) {
			return 0;
		}

		$players = SPLM_Player_Stats_Aggregator::for_season( $season_id, array( 'include_playoffs' => true ) );
		if ( ! $players ) {
			return 0;
		}

		$tiers  = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );
		$cutoff = SPLM_Player_Stats_Aggregator::window_cutoff(
			(int) get_option( 'splm_discipline_window_weeks', 4 ),
			current_time( 'Y-m-d' ),
			SPLM_Player_Stats_Aggregator::season_start( $players )
		);

		$token     = self::baseline_token();
		$baselining = (string) get_option( self::OPTION_BASELINE_TOKEN, '' ) !== $token;

		$written = 0;
		foreach ( $players as $player_id => $player ) {
			$written += self::process_player( (int) $player_id, $player, $season_id, $tiers, $cutoff, $baselining );
		}

		if ( $baselining ) {
			update_option( self::OPTION_BASELINE_TOKEN, $token, false );
		}

		return $written;
	}

	/**
	 * Evaluate and act on one player.
	 *
	 * @param int    $player_id  Player post id.
	 * @param array  $player     Aggregator row.
	 * @param int    $season_id  Season term id.
	 * @param array  $tiers      Tier list.
	 * @param string $cutoff     Window cutoff week key.
	 * @param bool   $baselining Whether this pass is a baselining pass.
	 * @return int Rows written.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function process_player( int $player_id, array $player, int $season_id, array $tiers, string $cutoff, bool $baselining ): int {
		$window = SPLM_Player_Stats_Aggregator::window_totals( $player['weeks'], $cutoff );

		// Acknowledgements are deliberately NOT passed. An ack means a convener
		// reviewed a flag and it suppresses the digest; if it also suppressed
		// notices then acknowledging a flag — the exact thing the digest email
		// tells conveners to do — would silently cancel the player's
		// notification. Notice suppression is the notice table's own predicate.
		$matches = SPLM_Penalty_Watch::matches(
			array(
				'season' => (int) $player['totals']['pim'],
				'window' => (int) $window['pim'],
			),
			$tiers,
			array(),
			$cutoff
		);

		$fireable = array();
		foreach ( $matches as $scope => $scope_matches ) {
			foreach ( $scope_matches as $match ) {
				$ack_key = SPLM_Penalty_Watch::ack_key(
					array(
						'key'   => $match['tier_key'],
						'scope' => $match['scope'],
					),
					$cutoff
				);
				$latest = SPLM_Discipline_Notice_Database::latest_for( $player_id, $season_id, $ack_key );

				if ( SPLM_Discipline_Notice::should_fire( $match, $latest ) ) {
					$match['ack_key']      = $ack_key;
					$fireable[ $scope ][] = $match;
				}
			}
		}

		if ( ! $fireable ) {
			return 0;
		}

		$chosen = SPLM_Discipline_Notice::select( $fireable );
		$written = 0;

		// On a baselining pass every candidate is recorded at its current value
		// and nobody is mailed. That is what makes switching notices on
		// mid-season, or lowering a threshold, silent.
		if ( $baselining ) {
			$all = $chosen['baselines'];
			if ( $chosen['notice'] ) {
				$all[] = $chosen['notice'];
			}
			foreach ( $all as $match ) {
				$written += self::write_row( $player_id, $season_id, $match, SPLM_Discipline_Notice_Database::STATUS_BASELINE ) ? 1 : 0;
			}

			return $written;
		}

		// The runner-up is baselined so it does not fire its own notice on the
		// next pass at an unchanged total.
		foreach ( $chosen['baselines'] as $match ) {
			$written += self::write_row( $player_id, $season_id, $match, SPLM_Discipline_Notice_Database::STATUS_BASELINE ) ? 1 : 0;
		}

		if ( ! $chosen['notice'] ) {
			return $written;
		}

		$match = $chosen['notice'];
		$mode  = SPLM_Discipline_Notice::mode_for( (string) $match['consequence'] );

		if ( SPLM_Discipline_Notice::MODE_DISABLED === $mode ) {
			// This consequence's mode is off while the other is on. Write
			// nothing: a disabled mode records nothing, per the spec.
			return $written;
		}

		$address = SPLM_Discipline_Notice_Recipients::player_email( $player_id );

		$notice_id = self::write_row(
			$player_id,
			$season_id,
			$match,
			SPLM_Discipline_Notice_Database::STATUS_PENDING,
			$address
		);

		if ( ! $notice_id ) {
			return $written;
		}

		++$written;

		if ( SPLM_Discipline_Notice::MODE_AUTOMATIC !== $mode ) {
			return $written;
		}

		SPLM_Discipline_Notice_Mail::send(
			$notice_id,
			array(
				'player_name'    => (string) $player['name'],
				'season_name'    => self::season_name( $season_id ),
				'consequence'    => (string) $match['consequence'],
				'games'          => (int) $match['games'],
				'value'          => (int) $match['value'],
				'next_threshold' => SPLM_Discipline_Notice_Mail::next_threshold( (int) $player['totals']['pim'], $tiers ),
				'game_label'     => SPLM_Discipline_Notice_Mail::next_game_label( (int) $player['team_id'] ),
			),
			$address['email'],
			SPLM_Discipline_Notice_Recipients::bcc_for( $season_id, (int) $player['team_id'] )
		);

		return $written;
	}

	/**
	 * Write one notice row.
	 *
	 * @param int    $player_id Player post id.
	 * @param int    $season_id Season term id.
	 * @param array  $match     Match row, carrying its ack_key.
	 * @param string $status    Row status.
	 * @param array  $address   Optional resolved address from player_email().
	 * @return int New row id, or 0 on failure.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function write_row( int $player_id, int $season_id, array $match, string $status, array $address = array() ): int {
		return SPLM_Discipline_Notice_Database::insert(
			array(
				'player_id'     => $player_id,
				'season_id'     => $season_id,
				'tier_key'      => (string) $match['tier_key'],
				'ack_key'       => (string) $match['ack_key'],
				'severity'      => (string) $match['severity'],
				'consequence'   => (string) $match['consequence'],
				'games'         => (int) $match['games'],
				'value_at_fire' => (int) $match['value'],
				'status'        => $status,
				'recipient'     => (string) ( $address['email'] ?? '' ),
				'recipient_via' => (string) ( $address['via'] ?? '' ),
			)
		);
	}

	/**
	 * A season's display name.
	 *
	 * @param int $season_id Season term id.
	 * @return string
	 */
	private static function season_name( int $season_id ): string {
		$season = get_term( $season_id, 'sp_season' );

		return ( $season && ! is_wp_error( $season ) ) ? (string) $season->name : '';
	}
}
```

- [ ] **Step 4: Run the mode suite**

```bash
php sportspress-league-manager/tests/test-discipline-notice-mode.php
```

Expected: PASS with `Failed: 0`. The `queued → automatic` assertion is the subtle one — if the token folded in the mode's *value* rather than its enabled-ness, promoting queued to automatic would silently baseline everyone and suppress a week of notices.

- [ ] **Step 5: Lint and commit**

```bash
php -l sportspress-league-manager/includes/class-discipline-notice-pass.php
git add sportspress-league-manager/includes/class-discipline-notice-pass.php \
        sportspress-league-manager/tests/test-discipline-notice-mode.php
git commit -m "feat(discipline): the daily notice pass

The only writer of notice rows — nothing on a read path creates one, so a
convener opening the Leaders page cannot mail anyone. SPAT_Lock-guarded
for the reason the digest documents, with more at stake: a duplicated
suspension tells a player twice that they are suspended.

All three baselining triggers collapse into one token over tier
thresholds plus whether each mode is enabled. Recording enablement as a
boolean is what keeps queued to automatic from re-baselining.

Acknowledgements are deliberately not passed to matches(). An ack
suppresses the digest; if it also suppressed notices then acknowledging a
flag — what the digest email tells conveners to do — would silently
cancel the player's notification."
```

---

### Task 10: REST routes

The four routes both queue surfaces call. Release logic exists here and only here.

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-notice-rest.php`
- Manual verification — REST needs a real WordPress.

**Interfaces:**
- Consumes: `SPLM_Discipline_Notice_Database` (Task 3); `SPLM_Discipline_Notice_Mail::send()` (Task 7); `SPLM_Discipline_Notice_Recipients` (Task 5); `SPLM_Capabilities::can_manage()`; `SPLM_REST_API::module_enabled()`; `splm_rest_list_response()`.
- Produces: `SPLM_Discipline_Notice_REST` registering, in `splm/v1`:
  - `GET /discipline/notices` — list, filters `season`, `status`, `page`, `per_page`
  - `POST /discipline/notices/(?P<id>\d+)/release`
  - `POST /discipline/notices/(?P<id>\d+)/discard`
  - `POST /discipline/notices/(?P<id>\d+)/serve`
  - `row_to_response( $row ): array` — the shared row shape both surfaces render

Per `docs/rest-api-conventions.md`: the list wraps in `{data, total, page, total_pages}`; mutations return `{success: true, ...}`; errors are `WP_Error` with a `status`.

**⚠️ `validate_callback` is not optional.** WordPress attaches `rest_validate_request_arg` as a sanitise fallback **only when no `sanitize_callback` is declared** — declaring one replaces it. An `enum` or `minimum`/`maximum` with a `sanitize_callback` and no `validate_callback` is inert. This is already documented at `class-leaders-rest.php:71-74`.

- [ ] **Step 1: Create the REST class**

Create `sportspress-league-manager/includes/class-discipline-notice-rest.php`:

```php
<?php
/**
 * REST routes for the notice queue.
 *
 * Both queue surfaces — the technical WP-admin tab and the React page — act
 * through these four routes. There is deliberately no admin_post_* handler:
 * release logic that exists twice is how one surface ends up enforcing
 * capabilities and the other does not.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_REST {

	const REST_NAMESPACE = 'splm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the four routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/discipline/notices',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_notices' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => self::list_args(),
			)
		);

		foreach ( array( 'release', 'discard', 'serve' ) as $action ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/discipline/notices/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => self::action_args(),
				)
			);
		}
	}

	/**
	 * Module-gated capability check, matching SPLM_Leaders_REST's discipline
	 * routes so the whole feature answers the same way when switched off.
	 *
	 * @return true|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function can_manage() {
		if ( ! SPLM_REST_API::module_enabled( 'league_discipline' ) ) {
			return new WP_Error( 'module_disabled', __( 'Penalty discipline is not enabled.', 'sportspress-league-manager' ), array( 'status' => 503 ) );
		}
		if ( ! SPLM_Capabilities::can_manage() ) {
			return new WP_Error( 'forbidden', __( 'You cannot manage disciplinary notices.', 'sportspress-league-manager' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Args for the list route.
	 *
	 * Every arg declares BOTH callbacks. WordPress only falls back to
	 * rest_validate_request_arg when no sanitize_callback is declared, so an
	 * enum with a sanitiser and no validator would not be enforced at all.
	 *
	 * @return array
	 */
	private static function list_args(): array {
		return array(
			'season'   => array(
				'required'          => false,
				'type'              => 'integer',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'status'   => array(
				'required'          => false,
				'type'              => 'string',
				'enum'              => SPLM_Discipline_Notice_Database::STATUSES,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
			'page'     => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 50,
				'minimum'           => 1,
				'maximum'           => 100,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Args shared by the three mutating routes.
	 *
	 * @return array
	 */
	private static function action_args(): array {
		return array(
			'id'   => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'note' => array(
				'required'          => false,
				'type'              => 'string',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'wp_kses_post',
			),
		);
	}

	/**
	 * GET /discipline/notices
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function get_notices( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$result = SPLM_Discipline_Notice_Database::query(
			array(
				'season' => (int) $request->get_param( 'season' ),
				'status' => (string) $request->get_param( 'status' ),
			),
			$page,
			$per_page
		);

		$items = array();
		foreach ( $result['rows'] as $row ) {
			$items[] = self::row_to_response( $row );
		}

		return new WP_REST_Response(
			splm_rest_list_response( $items, (int) $result['total'], $page, $per_page ),
			200
		);
	}

	/**
	 * POST /discipline/notices/{id}/release
	 *
	 * Sends a pending notice, or retries a failed one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function release( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( ! class_exists( 'SPAT_Lock' ) ) {
			return new WP_Error( 'splm_no_lock', __( 'Cannot release safely without the parent plugin’s lock.', 'sportspress-league-manager' ), array( 'status' => 503 ) );
		}

		// Per-notice lock, and the row is re-read INSIDE it. A double-click must
		// not send twice, and checking the status before taking the lock would
		// leave exactly that race open.
		$result = SPAT_Lock::with(
			'splm_discipline_notice_' . $id,
			60,
			function () use ( $id ) {
				return $this->release_locked( $id );
			}
		);

		if ( false === $result ) {
			return new WP_Error( 'splm_notice_busy', __( 'That notice is already being released.', 'sportspress-league-manager' ), array( 'status' => 409 ) );
		}

		return $result;
	}

	/**
	 * The release body, already holding the lock.
	 *
	 * @param int $id Notice id.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function release_locked( int $id ) {
		$row = SPLM_Discipline_Notice_Database::find( $id );
		if ( ! $row ) {
			return new WP_Error( 'splm_notice_not_found', __( 'Notice not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$releasable = array(
			SPLM_Discipline_Notice_Database::STATUS_PENDING,
			SPLM_Discipline_Notice_Database::STATUS_FAILED,
		);
		if ( ! in_array( (string) $row->status, $releasable, true ) ) {
			return new WP_Error(
				'splm_notice_not_releasable',
				__( 'Only a pending or failed notice can be released.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		$player_id = (int) $row->player_id;
		$season_id = (int) $row->season_id;

		// Re-resolved rather than trusting the stored address: a convener whose
		// first attempt failed for a missing address fixes the player record and
		// then releases, and that fix has to be picked up.
		$address = SPLM_Discipline_Notice_Recipients::player_email( $player_id );
		$team_id = self::team_for( $player_id, $season_id );

		$tiers = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );

		$sent = SPLM_Discipline_Notice_Mail::send(
			$id,
			array(
				'player_name'    => get_the_title( $player_id ),
				'season_name'    => self::season_name( $season_id ),
				'consequence'    => (string) $row->consequence,
				'games'          => (int) $row->games,
				'value'          => (int) $row->value_at_fire,
				'next_threshold' => SPLM_Discipline_Notice_Mail::next_threshold( (int) $row->value_at_fire, $tiers ),
				'game_label'     => SPLM_Discipline_Notice_Mail::next_game_label( $team_id ),
			),
			$address['email'],
			SPLM_Discipline_Notice_Recipients::bcc_for( $season_id, $team_id )
		);

		SPLM_Discipline_Notice_Database::update(
			$id,
			array(
				'released_by'   => get_current_user_id(),
				'recipient_via' => $address['via'],
			)
		);

		if ( ! $sent ) {
			$fresh = SPLM_Discipline_Notice_Database::find( $id );

			return new WP_Error(
				'splm_notice_send_failed',
				$fresh && $fresh->last_error
					? (string) $fresh->last_error
					: __( 'The notice could not be sent.', 'sportspress-league-manager' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'status'  => SPLM_Discipline_Notice_Database::STATUS_SENT,
			),
			200
		);
	}

	/**
	 * POST /discipline/notices/{id}/discard
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function discard( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$row = SPLM_Discipline_Notice_Database::find( $id );

		if ( ! $row ) {
			return new WP_Error( 'splm_notice_not_found', __( 'Notice not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$discardable = array(
			SPLM_Discipline_Notice_Database::STATUS_PENDING,
			SPLM_Discipline_Notice_Database::STATUS_FAILED,
		);
		if ( ! in_array( (string) $row->status, $discardable, true ) ) {
			return new WP_Error(
				'splm_notice_not_discardable',
				__( 'Only a pending or failed notice can be discarded.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		$ok = SPLM_Discipline_Notice_Database::update(
			$id,
			array(
				'status'      => SPLM_Discipline_Notice_Database::STATUS_DISCARDED,
				'released_by' => get_current_user_id(),
				'note'        => (string) $request->get_param( 'note' ),
			)
		);

		if ( ! $ok ) {
			return new WP_Error( 'splm_notice_write_failed', __( 'Could not discard the notice.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'status'  => SPLM_Discipline_Notice_Database::STATUS_DISCARDED,
			),
			200
		);
	}

	/**
	 * POST /discipline/notices/{id}/serve
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function serve( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$row = SPLM_Discipline_Notice_Database::find( $id );

		if ( ! $row ) {
			return new WP_Error( 'splm_notice_not_found', __( 'Notice not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		if ( 'suspend' !== (string) $row->consequence ) {
			return new WP_Error(
				'splm_notice_not_a_suspension',
				__( 'Only a suspension can be marked served.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		if ( SPLM_Discipline_Notice_Database::STATUS_SENT !== (string) $row->status ) {
			return new WP_Error(
				'splm_notice_not_sent',
				__( 'A suspension can only be marked served once the player has been told.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		$ok = SPLM_Discipline_Notice_Database::update(
			$id,
			array(
				'status'    => SPLM_Discipline_Notice_Database::STATUS_SERVED,
				'served_at' => SPLM_Discipline_Notice_Database::now(),
				'note'      => (string) $request->get_param( 'note' ),
			)
		);

		if ( ! $ok ) {
			return new WP_Error( 'splm_notice_write_failed', __( 'Could not mark the suspension served.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'status'  => SPLM_Discipline_Notice_Database::STATUS_SERVED,
			),
			200
		);
	}

	/**
	 * The row shape both surfaces render.
	 *
	 * The React page shows a subset; the technical tab shows all of it. One
	 * shape means the two views cannot disagree about what a row says.
	 *
	 * @param object $row Database row.
	 * @return array
	 */
	public static function row_to_response( $row ): array {
		$player_id = (int) $row->player_id;

		return array(
			'id'            => (int) $row->id,
			'player_id'     => $player_id,
			'player'        => get_the_title( $player_id ),
			'season_id'     => (int) $row->season_id,
			'tier_key'      => (string) $row->tier_key,
			'ack_key'       => (string) $row->ack_key,
			'severity'      => (string) $row->severity,
			'consequence'   => (string) $row->consequence,
			'games'         => (int) $row->games,
			'value_at_fire' => (int) $row->value_at_fire,
			'status'        => (string) $row->status,
			'recipient'     => (string) $row->recipient,
			'recipient_via' => (string) $row->recipient_via,
			'bcc'           => (string) $row->bcc,
			'sent_at'       => (string) $row->sent_at,
			'served_at'     => (string) $row->served_at,
			'released_by'   => (int) $row->released_by,
			'last_error'    => (string) $row->last_error,
			'note'          => (string) $row->note,
			'created_at'    => (string) $row->created_at,
		);
	}

	/**
	 * The team a player counts for in a season.
	 *
	 * Goes through the aggregator so the team used for the captain Bcc and the
	 * next-game lookup is the same one the watch list attributes the player to.
	 *
	 * @param int $player_id Player post id.
	 * @param int $season_id Season term id.
	 * @return int Team post id, or 0.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function team_for( int $player_id, int $season_id ): int {
		$players = SPLM_Player_Stats_Aggregator::for_season( $season_id, array( 'include_playoffs' => true ) );

		return (int) ( $players[ $player_id ]['team_id'] ?? 0 );
	}

	/**
	 * A season's display name.
	 *
	 * @param int $season_id Season term id.
	 * @return string
	 */
	private static function season_name( int $season_id ): string {
		$season = get_term( $season_id, 'sp_season' );

		return ( $season && ! is_wp_error( $season ) ) ? (string) $season->name : '';
	}
}
```

- [ ] **Step 2: Lint**

```bash
php -l sportspress-league-manager/includes/class-discipline-notice-rest.php
```

- [ ] **Step 3: Confirm nothing regressed**

```bash
./run-all-tests.sh 2>&1 | tail -12
```

Expected: every suite passes. No unit test reaches these routes; they are verified in staging.

- [ ] **Step 4: Commit**

```bash
git add sportspress-league-manager/includes/class-discipline-notice-rest.php
git commit -m "feat(discipline): REST routes for the notice queue

Four routes, module-gated and can_manage()-gated, matching the discipline
routes already on SPLM_Leaders_REST. Both queue surfaces act through
these; there is deliberately no admin_post_* handler, because release
logic that exists twice is how one surface ends up enforcing
capabilities and the other does not.

Release takes a per-notice lock and re-reads the row inside it, so a
double-click cannot send twice. It also re-resolves the address rather
than trusting the stored one: a convener whose first attempt failed for a
missing address fixes the player record and then releases.

Every arg declares validate_callback as well as sanitize_callback —
WordPress only falls back to rest_validate_request_arg when no sanitiser
is declared, so the status enum would otherwise be inert."
```

---

### Task 11: WP-admin technical tab

The administrator's view: every column, plus cron and mode diagnostics.

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-notice-admin.php`
- Manual verification — needs a real WordPress.

**Interfaces:**
- Consumes: `SPLM_Discipline_Notice_Database::query()`/`counts_by_status()` (Task 3); `SPLM_Discipline_Notice_REST::row_to_response()` (Task 10); `SPLM_Discipline_Notice_Pass::HOOK` (Task 9); `SPLM_Discipline_Notice::mode_for()` (Task 7).
- Produces: `SPLM_Discipline_Notice_Admin`, hooking `spat_admin_page_tabs` and `spat_admin_page_content`.

**Three constraints from the survey of the existing code:**

1. **This is its own tab**, not a section inside League Manager's. That panel is a single `<form action="options.php">` (`class-admin.php:90-103`); nesting an actionable queue inside it would be invalid HTML and would post the queue's buttons to `options.php`.
2. **No `WP_List_Table`.** There is no precedent for it anywhere in the repo, and it is a private core class. Use the repo's hand-rolled `<table class="widefat">` idiom, as in `render_discipline_tiers_field()`.
3. **Actions call the REST routes**, using a `wp_rest` nonce. No `admin_post_*` handler — see Task 10's commit message.

- [ ] **Step 1: Create the admin class**

Create `sportspress-league-manager/includes/class-discipline-notice-admin.php`:

```php
<?php
/**
 * The technical notice queue, on the SPAT settings page.
 *
 * A second League Manager tab rather than a section inside the existing one:
 * that panel is a single <form action="options.php">, so an actionable queue
 * nested in it would be invalid HTML and would post Release and Discard to
 * options.php.
 *
 * Actions call the REST routes rather than an admin_post_* handler, so release
 * logic lives in exactly one place and both surfaces are gated identically.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Admin {

	const PER_PAGE = 100;

	public function __construct() {
		add_action( 'spat_admin_page_tabs', array( $this, 'add_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_content' ) );
	}

	/**
	 * The nav tab.
	 *
	 * @return void
	 */
	public function add_tab() {
		echo '<a href="#discipline-queue" class="nav-tab">' . esc_html__( 'Discipline Queue', 'sportspress-league-manager' ) . '</a>';
	}

	/**
	 * The tab panel.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function add_content() {
		echo '<div id="discipline-queue" class="tab-content" style="display: none;">';

		if ( ! SPLM_REST_API::module_enabled( 'league_discipline' ) ) {
			echo '<p>' . esc_html__( 'The Penalty Discipline module is not enabled, so no notices are being evaluated.', 'sportspress-league-manager' ) . '</p></div>';
			return;
		}

		$season_id = (int) get_option( 'splm_default_season', 0 );

		$this->render_diagnostics( $season_id );

		if ( ! $season_id ) {
			echo '<p>' . esc_html__( 'No default season is set, so the evaluation pass does nothing. Set a Season Override on the League Manager tab.', 'sportspress-league-manager' ) . '</p></div>';
			return;
		}

		$this->render_table( $season_id );
		$this->render_script();

		echo '</div>';
	}

	/**
	 * Cron, mode and row-count diagnostics.
	 *
	 * The next-run line is how a cron that silently stopped firing gets
	 * noticed — without it, "no notices" and "no evaluation" look identical.
	 *
	 * @param int $season_id Season term id.
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function render_diagnostics( int $season_id ): void {
		$next  = wp_next_scheduled( SPLM_Discipline_Notice_Pass::HOOK );
		$counts = $season_id ? SPLM_Discipline_Notice_Database::counts_by_status( $season_id ) : array();

		echo '<h3>' . esc_html__( 'Evaluation', 'sportspress-league-manager' ) . '</h3>';
		echo '<table class="widefat" style="max-width:44em"><tbody>';

		printf(
			'<tr><td>%s</td><td>%s</td></tr>',
			esc_html__( 'Next scheduled run', 'sportspress-league-manager' ),
			esc_html( $next ? wp_date( 'Y-m-d H:i:s', $next ) : __( 'Not scheduled', 'sportspress-league-manager' ) )
		);
		printf(
			'<tr><td>%s</td><td><code>%s</code></td></tr>',
			esc_html__( 'Warning notices', 'sportspress-league-manager' ),
			esc_html( SPLM_Discipline_Notice::mode_for( 'warn' ) )
		);
		printf(
			'<tr><td>%s</td><td><code>%s</code></td></tr>',
			esc_html__( 'Suspension notices', 'sportspress-league-manager' ),
			esc_html( SPLM_Discipline_Notice::mode_for( 'suspend' ) )
		);
		printf(
			'<tr><td>%s</td><td><code>%s</code></td></tr>',
			esc_html__( 'Notice table', 'sportspress-league-manager' ),
			esc_html( SPLM_Discipline_Notice_Database::table_exists() ? SPLM_Discipline_Notice_Database::table_name() : __( 'missing', 'sportspress-league-manager' ) )
		);

		$summary = array();
		foreach ( SPLM_Discipline_Notice_Database::STATUSES as $status ) {
			$summary[] = $status . ': ' . (int) ( $counts[ $status ] ?? 0 );
		}
		printf(
			'<tr><td>%s</td><td><code>%s</code></td></tr>',
			esc_html__( 'Rows by status', 'sportspress-league-manager' ),
			esc_html( implode( '  ', $summary ) )
		);

		echo '</tbody></table>';
	}

	/**
	 * The full row table.
	 *
	 * @param int $season_id Season term id.
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function render_table( int $season_id ): void {
		$result = SPLM_Discipline_Notice_Database::query( array( 'season' => $season_id ), 1, self::PER_PAGE );

		echo '<h3>' . esc_html__( 'Notices', 'sportspress-league-manager' ) . '</h3>';

		if ( ! $result['rows'] ) {
			echo '<p>' . esc_html__( 'No notices have been recorded for this season yet.', 'sportspress-league-manager' ) . '</p>';
			return;
		}

		$headings = array(
			__( 'ID', 'sportspress-league-manager' ),
			__( 'Player', 'sportspress-league-manager' ),
			__( 'Tier / ack key', 'sportspress-league-manager' ),
			__( 'Consequence', 'sportspress-league-manager' ),
			__( 'PIM at fire', 'sportspress-league-manager' ),
			__( 'Status', 'sportspress-league-manager' ),
			__( 'Recipient (via)', 'sportspress-league-manager' ),
			__( 'Bcc', 'sportspress-league-manager' ),
			__( 'Sent', 'sportspress-league-manager' ),
			__( 'Released by', 'sportspress-league-manager' ),
			__( 'Last error', 'sportspress-league-manager' ),
			__( 'Created', 'sportspress-league-manager' ),
			__( 'Actions', 'sportspress-league-manager' ),
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $headings as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $result['rows'] as $raw ) {
			$row = SPLM_Discipline_Notice_REST::row_to_response( $raw );
			$this->render_row( $row );
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: rows shown, 2: total rows. */
					__( 'Showing %1$d of %2$d rows, newest first.', 'sportspress-league-manager' ),
					count( $result['rows'] ),
					(int) $result['total']
				)
			)
		);
	}

	/**
	 * One table row.
	 *
	 * @param array $row Response-shaped row.
	 * @return void
	 */
	private function render_row( array $row ): void {
		$actionable = in_array( $row['status'], array( 'pending', 'failed' ), true );
		$servable   = 'sent' === $row['status'] && 'suspend' === $row['consequence'];

		$buttons = '';
		if ( $actionable ) {
			$buttons .= sprintf(
				'<button type="button" class="button splm-notice-action" data-action="release" data-id="%d">%s</button> ',
				(int) $row['id'],
				esc_html__( 'Release', 'sportspress-league-manager' )
			);
			$buttons .= sprintf(
				'<button type="button" class="button splm-notice-action" data-action="discard" data-id="%d">%s</button>',
				(int) $row['id'],
				esc_html__( 'Discard', 'sportspress-league-manager' )
			);
		}
		if ( $servable ) {
			$buttons .= sprintf(
				'<button type="button" class="button splm-notice-action" data-action="serve" data-id="%d">%s</button>',
				(int) $row['id'],
				esc_html__( 'Mark served', 'sportspress-league-manager' )
			);
		}

		printf(
			'<tr><td>%1$d</td><td>%2$s <code>#%3$d</code></td><td><code>%4$s</code><br/><code>%5$s</code></td>'
				. '<td>%6$s%7$s</td><td>%8$d</td><td><code>%9$s</code></td><td>%10$s<br/><code>%11$s</code></td>'
				. '<td><code>%12$s</code></td><td>%13$s</td><td>%14$s</td><td>%15$s</td><td>%16$s</td><td>%17$s</td></tr>',
			(int) $row['id'],
			esc_html( $row['player'] ),
			(int) $row['player_id'],
			esc_html( $row['tier_key'] ),
			esc_html( $row['ack_key'] ),
			esc_html( $row['consequence'] ),
			$row['games'] ? esc_html( ' (' . (int) $row['games'] . ')' ) : '',
			(int) $row['value_at_fire'],
			esc_html( $row['status'] ),
			esc_html( $row['recipient'] ? $row['recipient'] : '—' ),
			esc_html( $row['recipient_via'] ),
			esc_html( $row['bcc'] ? $row['bcc'] : '—' ),
			esc_html( $row['sent_at'] ? $row['sent_at'] : '—' ),
			esc_html( $row['released_by'] ? (string) $row['released_by'] : __( 'automatic', 'sportspress-league-manager' ) ),
			esc_html( $row['last_error'] ? $row['last_error'] : '—' ),
			esc_html( $row['created_at'] ),
			$buttons // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from esc_html above.
		);
	}

	/**
	 * The action layer.
	 *
	 * Calls the same REST routes the React page uses. Kept inline because it is
	 * a dozen lines and this tab is the only consumer; shipping a file for it
	 * would need an enqueue path on a settings page that has none.
	 *
	 * @return void
	 */
	private function render_script(): void {
		$nonce = wp_create_nonce( 'wp_rest' );
		$base  = rest_url( 'splm/v1/discipline/notices/' );
		?>
		<script>
		( function () {
			var base = <?php echo wp_json_encode( $base ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			document.querySelectorAll( '.splm-notice-action' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var action = button.getAttribute( 'data-action' );
					var id = button.getAttribute( 'data-id' );
					button.disabled = true;
					fetch( base + id + '/' + action, {
						method: 'POST',
						headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
						credentials: 'same-origin'
					} ).then( function ( response ) {
						return response.json().then( function ( body ) {
							return { ok: response.ok, body: body };
						} );
					} ).then( function ( result ) {
						if ( result.ok ) {
							window.location.reload();
							return;
						}
						button.disabled = false;
						window.alert( ( result.body && result.body.message ) || 'The action failed.' );
					} ).catch( function () {
						button.disabled = false;
						window.alert( 'The action could not be sent.' );
					} );
				} );
			} );
		}() );
		</script>
		<?php
	}
}
```

- [ ] **Step 2: Lint and check the tab JS assumption**

```bash
php -l sportspress-league-manager/includes/class-discipline-notice-admin.php
```

SPAT's tab script activates panels by matching `<a href="#id">` against `<div id="id" class="tab-content">`. Confirm the id used here (`discipline-queue`) matches in both places — a mismatch renders a tab that switches to nothing.

- [ ] **Step 3: Confirm nothing regressed**

```bash
./run-all-tests.sh 2>&1 | tail -12
```

- [ ] **Step 4: Commit**

```bash
git add sportspress-league-manager/includes/class-discipline-notice-admin.php
git commit -m "feat(discipline): technical notice queue on the SPAT page

Its own League Manager tab, not a section inside the existing one: that
panel is a single <form action=options.php>, so an actionable queue
nested in it would be invalid HTML and would post Release and Discard to
options.php.

Shows every column plus the diagnostics an administrator actually needs —
the cron's next run, both delivery modes, whether the table exists, and
row counts by status. Without the next-run line, 'no notices' and 'the
cron stopped firing' look identical.

Hand-rolled widefat table rather than WP_List_Table: there is no
precedent for it in this repo and it is a private core class."
```

---

### Task 12: React Notices page

The convener's view: what they need to decide, and nothing else.

**Files:**
- Create: `sportspress-league-manager/src/dashboard/pages/Notices.jsx`
- Modify: `sportspress-league-manager/src/dashboard/lib/api.js`

**Interfaces:**
- Consumes: the four routes from Task 10.
- Produces: four exports in `lib/api.js` — `fetchNotices( params )`, `releaseNotice( id )`, `discardNotice( id )`, `serveNotice( id )`; and a default-exported `Notices` component.

**Conventions this must follow**, from the existing `Waitlist.jsx`:
- `apiFetch` from `@wordpress/api-fetch` directly — there is no wrapper, and the nonce is wired globally.
- The `cancelled` guard in `load` so a superseded request cannot overwrite fresher data.
- UTC timestamps parsed by appending `Z` before `new Date()` — without it they read as local time, a four-to-five hour error.
- `window.confirm` before an irreversible action, matching every other such action in this dashboard.

- [ ] **Step 1: Add the API wrappers**

Append to `sportspress-league-manager/src/dashboard/lib/api.js`:

```js
export function fetchNotices( params = {} ) {
	const query = new URLSearchParams(
		Object.fromEntries( Object.entries( params ).filter( ( [ , v ] ) => v !== '' && v != null ) )
	).toString();
	return apiFetch( { path: `/splm/v1/discipline/notices${ query ? '?' + query : '' }` } ).then( ( res ) => ( {
		data: Array.isArray( res?.data ) ? res.data : [],
		total: Number( res?.total ) || 0,
		totalPages: Number( res?.total_pages ) || 0,
		page: Number( res?.page ) || 1,
	} ) );
}

export function releaseNotice( id ) {
	return apiFetch( { path: `/splm/v1/discipline/notices/${ id }/release`, method: 'POST' } );
}

export function discardNotice( id ) {
	return apiFetch( { path: `/splm/v1/discipline/notices/${ id }/discard`, method: 'POST' } );
}

export function serveNotice( id ) {
	return apiFetch( { path: `/splm/v1/discipline/notices/${ id }/serve`, method: 'POST' } );
}
```

- [ ] **Step 2: Create the page**

Create `sportspress-league-manager/src/dashboard/pages/Notices.jsx`:

```jsx
import { useCallback, useEffect, useState } from '@wordpress/element';
import { fetchNotices, releaseNotice, discardNotice, serveNotice } from '../lib/api';
import HelpLink from '../components/HelpLink';

// Timestamps arrive as UTC 'Y-m-d H:i:s'. Date can't parse that shape reliably
// across browsers, so normalise it to ISO with an explicit Z before parsing —
// without the Z it would be read as local time, which is the same four-to-five
// hour error the server side guards against.
function parseUtc( value ) {
	if ( ! value ) {
		return null;
	}
	const parsed = new Date( value.replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( parsed.getTime() ) ? null : parsed;
}

function formatLocal( value ) {
	const date = parseUtc( value );
	return date ? date.toLocaleString() : '—';
}

// Conveners get plain language, never the stored vocabulary. 'baseline' in
// particular means "recorded so we don't mail them retroactively", which is
// not a phrase anyone should have to decode from a status badge.
const STATUS_LABELS = {
	baseline: 'On record',
	pending: 'Waiting for you',
	sent: 'Sent',
	failed: 'Could not send',
	discarded: 'Discarded',
	served: 'Served',
};

function consequenceLabel( row ) {
	if ( row.consequence === 'suspend' ) {
		return row.games === 1 ? 'Suspension — 1 game' : `Suspension — ${ row.games } games`;
	}
	return 'Warning';
}

function Problem( { row } ) {
	if ( row.status !== 'failed' ) {
		return null;
	}
	// The stored last_error is written for the technical view. A convener needs
	// the one cause they can actually fix, in words.
	const missingEmail = /email/i.test( row.last_error || '' );
	return (
		<p className="splm-notice__problem">
			{ missingEmail
				? 'No email address on file for this player — add one, then release again.'
				: 'The email could not be sent. Try releasing it again.' }
		</p>
	);
}

function RowActions( { row, busy, onRelease, onDiscard, onServe } ) {
	const actionable = row.status === 'pending' || row.status === 'failed';
	const servable = row.status === 'sent' && row.consequence === 'suspend';

	if ( ! actionable && ! servable ) {
		return <td />;
	}

	return (
		<td>
			{ actionable && (
				<>
					<button type="button" className="splm-btn" disabled={ busy } onClick={ () => onRelease( row ) }>
						{ row.status === 'failed' ? 'Try again' : 'Release' }
					</button>{ ' ' }
					<button type="button" className="splm-btn splm-btn--secondary" disabled={ busy } onClick={ () => onDiscard( row ) }>
						Discard
					</button>
				</>
			) }
			{ servable && (
				<button type="button" className="splm-btn" disabled={ busy } onClick={ () => onServe( row ) }>
					Mark served
				</button>
			) }
		</td>
	);
}

function NoticeRow( { row, busy, onRelease, onDiscard, onServe } ) {
	return (
		<tr>
			<td>{ row.player || '—' }</td>
			<td>{ row.value_at_fire } PIM</td>
			<td>{ consequenceLabel( row ) }</td>
			<td>
				<span className={ `splm-badge splm-badge--${ row.status }` }>
					{ STATUS_LABELS[ row.status ] || row.status }
				</span>
				<Problem row={ row } />
			</td>
			<td>{ formatLocal( row.sent_at || row.created_at ) }</td>
			<RowActions row={ row } busy={ busy } onRelease={ onRelease } onDiscard={ onDiscard } onServe={ onServe } />
		</tr>
	);
}

function Filters( { status, onStatusChange } ) {
	return (
		<div className="splm-filters">
			<label>
				Show{ ' ' }
				<select value={ status } onChange={ ( e ) => onStatusChange( e.target.value ) }>
					<option value="pending">Waiting for you</option>
					<option value="failed">Could not send</option>
					<option value="sent">Sent</option>
					<option value="served">Served</option>
					<option value="discarded">Discarded</option>
					<option value="">Everything</option>
				</select>
			</label>
		</div>
	);
}

export default function Notices( { season } ) {
	const [ rows, setRows ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ status, setStatus ] = useState( 'pending' );
	const [ busyId, setBusyId ] = useState( 0 );

	// cancelled guards against a slower earlier request (e.g. from a filter
	// change that has since been superseded) overwriting the table with stale
	// data after a later request resolves first — same pattern as Waitlist.jsx.
	const load = useCallback( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		fetchNotices( { season, status } )
			.then( ( res ) => {
				if ( cancelled ) return;
				setRows( res.data );
				setLoading( false );
			} )
			.catch( ( e ) => {
				if ( cancelled ) return;
				setError( e?.message || 'Could not load the notice queue.' );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [ season, status ] );

	useEffect( () => {
		const cleanup = load();
		return cleanup;
	}, [ load ] );

	const act = ( row, fn, confirmText, successText ) => {
		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}
		setBusyId( row.id );
		setError( '' );
		setNotice( '' );
		fn( row.id )
			.then( () => {
				setNotice( successText );
				load();
			} )
			.catch( ( e ) => setError( e?.message || 'That did not work.' ) )
			.finally( () => setBusyId( 0 ) );
	};

	const handleRelease = ( row ) =>
		act(
			row,
			releaseNotice,
			`Email ${ row.player } to tell them: ${ consequenceLabel( row ) }?`,
			'Notice sent.'
		);

	const handleDiscard = ( row ) =>
		act( row, discardNotice, `Discard this notice? ${ row.player } will not be told.`, 'Notice discarded.' );

	const handleServe = ( row ) => act( row, serveNotice, '', 'Suspension marked served.' );

	return (
		<div className="splm-notices">
			<h2>Discipline Notices <HelpLink topic="discipline" /></h2>

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
			{ notice && <div className="splm-alert splm-alert--success" role="status">{ notice }</div> }

			<Filters status={ status } onStatusChange={ setStatus } />

			{ loading && <div className="splm-loading">Loading…</div> }

			{ ! loading && rows.length === 0 && (
				<p className="splm-empty">
					{ status === 'pending'
						? 'Nothing is waiting for you.'
						: 'No notices match this filter.' }
				</p>
			) }

			{ ! loading && rows.length > 0 && (
				<div className="splm-table-wrapper">
					<table className="splm-table splm-notices__table">
						<thead>
							<tr>
								<th scope="col">Player</th>
								<th scope="col">Penalties</th>
								<th scope="col">Consequence</th>
								<th scope="col">Status</th>
								<th scope="col">When</th>
								<th scope="col">Actions</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<NoticeRow
									key={ row.id }
									row={ row }
									busy={ busyId === row.id }
									onRelease={ handleRelease }
									onDiscard={ handleDiscard }
									onServe={ handleServe }
								/>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}
```

- [ ] **Step 3: Verify `HelpLink` accepts the topic**

`Notices.jsx` uses `<HelpLink topic="discipline" />`. Check that `src/dashboard/pages/Help.jsx` has a `discipline` topic; if it does not, either add one or drop the `HelpLink` rather than shipping a link to a missing anchor.

```bash
grep -n "discipline" sportspress-league-manager/src/dashboard/pages/Help.jsx
```

- [ ] **Step 4: Build**

```bash
cd sportspress-league-manager && npm run build && cd ..
```

Expected: exit 0. `build/` is intentionally committed — the PHP loader enqueues `build/index.js` directly, so a source change without a rebuild ships a stale dashboard.

- [ ] **Step 5: Commit source and build together**

```bash
git add sportspress-league-manager/src/dashboard/pages/Notices.jsx \
        sportspress-league-manager/src/dashboard/lib/api.js \
        sportspress-league-manager/build/
git commit -m "feat(discipline): React notice queue for conveners

Player, penalties, what happens, status, and the three buttons. No ids,
no ack keys, no address-resolution internals — a failed row says 'no
email address on file for this player' rather than surfacing
recipient_via.

Stored statuses are relabelled for humans: 'baseline' reads as 'On
record', which is what it means, rather than asking a convener to decode
it. Timestamps parse as explicit UTC per the fix already applied to the
waitlist page."
```

---

### Task 13: Alert card and nav wiring

Makes the queue reachable and visible. A queue nobody can see is a queue that never gets released, which is the main failure mode for a human-release step.

**Files:**
- Create: `sportspress-league-manager/src/dashboard/components/NoticeQueueCard.jsx`
- Modify: `sportspress-league-manager/src/dashboard/App.jsx:1-40`
- Modify: `sportspress-league-manager/src/dashboard/components/Layout.jsx:8-27`, `:84-97`
- Modify: `sportspress-league-manager/src/dashboard/pages/Dashboard.jsx`

**Interfaces:**
- Consumes: `fetchNotices()` (Task 12).
- Produces: a default-exported `NoticeQueueCard` component; a `notices` entry in `PAGES`, `NAV_ITEMS` and `capMap`.

**⚠️ The alert card must NOT go through `visibleCards`.** `Dashboard.jsx:7` defines `const CARDS = [ 'upcoming', 'recent', 'activity', 'penalties' ]`, and `visibleCards` falls back to `CARDS` **only when the user has no saved `dashboardLayout`** (`:76-79`). Any convener who has ever toggled a card has a saved array that lacks `'notices'`, so adding the id to `CARDS` would leave the card invisible to exactly the people most likely to be using the dashboard.

That is also the right design: an alert saying "three notices are waiting on you" should not be permanently dismissable. Render it outside the toggle system, gated on the module and on having something to report.

- [ ] **Step 1: Create the card**

Create `sportspress-league-manager/src/dashboard/components/NoticeQueueCard.jsx`:

```jsx
import { useState, useEffect } from '@wordpress/element';
import { fetchNotices } from '../lib/api';

/**
 * "What is waiting on me" — distinct from PenaltyWatchCard, which answers
 * "who is over a threshold".
 *
 * Deliberately outside the Dashboard's visibleCards preference: a convener who
 * has ever toggled a card has a saved layout that cannot contain a card added
 * later, and an alert that can be permanently hidden defeats its own purpose.
 */
export default function NoticeQueueCard( { season, onNavigate } ) {
	const [ pending, setPending ] = useState( 0 );
	const [ failed, setFailed ] = useState( 0 );
	const [ loaded, setLoaded ] = useState( false );

	useEffect( () => {
		if ( ! season ) return undefined;
		let cancelled = false;

		Promise.all( [
			fetchNotices( { season, status: 'pending', per_page: 1 } ),
			fetchNotices( { season, status: 'failed', per_page: 1 } ),
		] )
			.then( ( [ p, f ] ) => {
				if ( cancelled ) return;
				setPending( p.total );
				setFailed( f.total );
				setLoaded( true );
			} )
			// The card is supplementary: a failure here must not break the
			// Dashboard, so it simply renders nothing.
			.catch( () => { if ( ! cancelled ) setLoaded( true ); } );

		return () => { cancelled = true; };
	}, [ season ] );

	if ( ! loaded || ( pending === 0 && failed === 0 ) ) {
		return null;
	}

	return (
		<section className="splm-card splm-card--alert">
			<h3>Discipline Notices</h3>
			<p className="splm-muted">
				{ pending > 0 && (
					<>
						<strong>{ pending }</strong>
						{ pending === 1 ? ' notice is waiting for you' : ' notices are waiting for you' }
					</>
				) }
				{ pending > 0 && failed > 0 && '. ' }
				{ failed > 0 && (
					<>
						<strong>{ failed }</strong>
						{ failed === 1 ? ' could not be sent' : ' could not be sent' }
					</>
				) }
			</p>
			<button type="button" className="splm-btn" onClick={ () => onNavigate( 'notices' ) }>
				Review them
			</button>
		</section>
	);
}
```

- [ ] **Step 2: Register the page**

In `App.jsx`, add the import beside the other page imports (after the `Waitlist` import on line 18):

```jsx
import Notices from './pages/Notices';
```

And add to the `PAGES` map, after `waitlist: Waitlist,`:

```jsx
	notices: Notices,
```

Note `PAGES` is deliberately not module-gated — every page is registered so a deep link always resolves. Gating happens in the nav.

- [ ] **Step 3: Register the nav item**

In `Layout.jsx`, add to `NAV_ITEMS` after the `leaders` entry:

```jsx
	{ id: 'notices', label: 'Notices', icon: 'season-report' },
```

And add to `capMap`:

```jsx
		notices: caps.canManage && modulePresent( 'discipline' ),
```

`modulePresent` is fail-open on a missing key, matching every other entry — a future build that drops a flag must not silently hide a working feature.

- [ ] **Step 4: Render the card on the dashboard**

In `Dashboard.jsx`, add the import beside `PenaltyWatchCard` (line 5):

```jsx
import NoticeQueueCard from '../components/NoticeQueueCard';
```

Then render it **immediately before** the `visibleCards.includes( 'penalties' )` block at line 271, and outside any `visibleCards` check:

```jsx
				{ window.splmDashboard?.modules?.discipline !== false && (
					<NoticeQueueCard season={ season } onNavigate={ onNavigate } />
				) }
```

Do **not** add `'notices'` to `CARDS` on line 7. See the warning above.

- [ ] **Step 5: Build**

```bash
cd sportspress-league-manager && npm run build && cd ..
```

Expected: exit 0.

- [ ] **Step 6: Verify the icon name resolves**

`NAV_ITEMS` entries carry an `icon` that `<Icon name={...} />` resolves. `season-report` is reused from the existing `leaders` entry, so it is known-good. The icon map is `components/icons.js` — **not** `Icon.jsx`, which does not exist:

```bash
grep -n "season-report" sportspress-league-manager/src/dashboard/components/icons.js
```

Expected: two matches. An empty result means you have the wrong filename, not a missing icon.

- [ ] **Step 7: Commit source and build together**

```bash
git add sportspress-league-manager/src/dashboard/components/NoticeQueueCard.jsx \
        sportspress-league-manager/src/dashboard/App.jsx \
        sportspress-league-manager/src/dashboard/components/Layout.jsx \
        sportspress-league-manager/src/dashboard/pages/Dashboard.jsx \
        sportspress-league-manager/build/
git commit -m "feat(discipline): notice queue alert card and nav

The card answers 'what is waiting on me', distinct from PenaltyWatchCard's
'who is over a threshold', and renders nothing when both counts are zero.

It deliberately does NOT go through the Dashboard's visibleCards
preference: that list falls back to the defaults only for a user with no
saved layout, so any convener who has ever toggled a card would never see
a card added later — exactly the people most likely to be using this. An
alert that can be permanently hidden also defeats its own purpose."
```

---

### Task 14: Bootstrap, autoloader, uninstall

Wires everything up. Until this task lands, none of the new classes load and the cron never fires.

**Files:**
- Modify: `sportspress-league-manager/includes/class-autoloader.php:38-65`
- Modify: `sportspress-league-manager/sportspress-league-manager.php:120-143` (module description), `:178-190` (`load_enabled_modules`)
- Modify: `sportspress-league-manager/uninstall.php:43-46`

**Interfaces:**
- Consumes: every class from Tasks 3-11.

- [ ] **Step 1: Add the autoloader entries**

`$class_map` is an explicit array — a `SPLM_*` class absent from it never loads, and every `class_exists()` probe returns false. Add these eight, keeping alphabetical order within the existing block:

```php
			'SPLM_Discipline_Notice'            => $base . 'class-discipline-notice.php',
			'SPLM_Discipline_Notice_Admin'      => $base . 'class-discipline-notice-admin.php',
			'SPLM_Discipline_Notice_Database'   => $base . 'class-discipline-notice-database.php',
			'SPLM_Discipline_Notice_Mail'       => $base . 'class-discipline-notice-mail.php',
			'SPLM_Discipline_Notice_Pass'       => $base . 'class-discipline-notice-pass.php',
			'SPLM_Discipline_Notice_Privacy'    => $base . 'class-discipline-notice-privacy.php',
			'SPLM_Discipline_Notice_Recipients' => $base . 'class-discipline-notice-recipients.php',
			'SPLM_Discipline_Notice_REST'       => $base . 'class-discipline-notice-rest.php',
```

`SPLM_Discipline_Notice_Privacy` is created in Task 15; adding its entry now is harmless — the autoloader only requires a file when the class is actually referenced.

- [ ] **Step 2: Wire the module block**

Replace the existing `league_discipline` block in `load_enabled_modules()` (lines 178-190):

```php
		// The discipline schema is only needed once the module is deliberately
		// enabled — see the module registration above for why it isn't folded
		// into league_manager_dashboard.
		if ( in_array( 'league_discipline', $enabled, true ) ) {
			SPLM_Discipline_Database::maybe_upgrade();
			SPLM_Discipline_Notice_Database::maybe_upgrade();

			new SPLM_Discipline_Digest();
			if ( get_option( 'splm_discipline_digest_enabled' ) ) {
				SPLM_Discipline_Digest::schedule();
			} else {
				SPLM_Discipline_Digest::unschedule();
			}

			// Four constructors, because the notice feature's hooks belong to
			// four concerns: the pass answers the scheduled event, the REST
			// class registers the routes both queue surfaces call, the admin
			// class contributes the technical tab, and the privacy class
			// registers the GDPR exporter and eraser. Drop any one of these
			// lines and its hooks silently never register.
			// SPLM_Discipline_Notice, _Mail and _Recipients are deliberately
			// absent: they hook nothing.
			new SPLM_Discipline_Notice_Pass();
			new SPLM_Discipline_Notice_REST();
			new SPLM_Discipline_Notice_Privacy();
			if ( is_admin() ) {
				new SPLM_Discipline_Notice_Admin();
			}

			// The pass is scheduled whenever either mode is on, and cleared when
			// both are off, so a league that switches notices off stops paying
			// for a daily aggregate scan.
			$notices_on = SPLM_Discipline_Notice::MODE_DISABLED !== SPLM_Discipline_Notice::mode_for( 'warn' )
				|| SPLM_Discipline_Notice::MODE_DISABLED !== SPLM_Discipline_Notice::mode_for( 'suspend' );
			if ( $notices_on ) {
				SPLM_Discipline_Notice_Pass::schedule();
			} else {
				SPLM_Discipline_Notice_Pass::unschedule();
			}
		}

		if ( ! in_array( 'league_discipline', $enabled, true ) ) {
			if ( class_exists( 'SPLM_Discipline_Digest' ) ) {
				SPLM_Discipline_Digest::unschedule();
			}
			// Disabling the module must also stop the notice pass, or a daily
			// event keeps firing against a feature whose REST routes now 503.
			if ( class_exists( 'SPLM_Discipline_Notice_Pass' ) ) {
				SPLM_Discipline_Notice_Pass::unschedule();
			}
		}
```

Note this replaces the standalone `if ( ! in_array( 'league_discipline', ... ) && class_exists( 'SPLM_Discipline_Digest' ) )` guard that followed the original block — its behaviour is preserved inside the combined negative branch.

- [ ] **Step 3: Update the module description**

The registered description still describes only the watch list. In the `league_discipline` registration (around line 126):

```php
				'description'   => 'Penalty-minute watch list, acknowledgements, weekly digest, and warning/suspension notices to players',
```

- [ ] **Step 4: Drop the table on uninstall**

In `uninstall.php`, beside the existing drops (line 45). The generic `DELETE FROM options WHERE option_name LIKE 'splm_%'` sweep already covers the four new options, so no option deletes are needed here:

```php
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}splm_discipline_notice" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
```

And clear the cron, beside the existing `wp_unschedule_hook` call. The same reasoning applies — `wp_clear_scheduled_hook()` only matches argless events, and while this hook *is* argless today, `wp_unschedule_hook()` is correct regardless and keeps the file consistent:

```php
wp_unschedule_hook( 'splm_discipline_notices' );
```

- [ ] **Step 5: Lint everything touched**

```bash
php -l sportspress-league-manager/includes/class-autoloader.php
php -l sportspress-league-manager/sportspress-league-manager.php
php -l sportspress-league-manager/uninstall.php
```

- [ ] **Step 6: Verify every new class is reachable**

This catches the most likely silent failure — a class-map typo. Each name must print `ok`:

```bash
cd sportspress-league-manager
for c in SPLM_Discipline_Notice SPLM_Discipline_Notice_Admin SPLM_Discipline_Notice_Database \
         SPLM_Discipline_Notice_Mail SPLM_Discipline_Notice_Pass SPLM_Discipline_Notice_Recipients \
         SPLM_Discipline_Notice_REST; do
  f="includes/class-$( echo "$c" | sed 's/^SPLM_//' | tr '[:upper:]' '[:lower:]' | tr '_' '-' ).php"
  if grep -q "'$c'" includes/class-autoloader.php && [ -f "$f" ]; then
    echo "ok   $c -> $f"
  else
    echo "FAIL $c -> $f"
  fi
done
cd ..
```

`SPLM_Discipline_Notice_Privacy` is expected to FAIL this check until Task 15 creates its file.

- [ ] **Step 7: Run the full suite**

```bash
./run-all-tests.sh 2>&1 | tail -14
```

Expected: every suite passes.

- [ ] **Step 8: Commit**

```bash
git add sportspress-league-manager/includes/class-autoloader.php \
        sportspress-league-manager/sportspress-league-manager.php \
        sportspress-league-manager/uninstall.php
git commit -m "feat(discipline): wire the notice feature into the plugin

Eight autoloader entries, because the class map is explicit and a class
absent from it never loads. Four constructors under the module gate,
because the notice feature's hooks belong to four concerns and dropping
any line silently registers nothing.

The pass is scheduled whenever either mode is enabled and cleared when
both are off, and disabling the module unschedules it too — otherwise a
daily event keeps firing against routes that now 503.

Uninstall drops the table and unschedules the hook. The options need no
explicit deletes: the existing splm_%% sweep already covers them."
```

---

### Task 15: GDPR export and erase

Notice rows hold a player's name, email address and penalty history, so they must be exportable and erasable.

**Files:**
- Create: `sportspress-league-manager/includes/class-discipline-notice-privacy.php`
- Manual verification — needs a real WordPress and the Tools → Export/Erase Personal Data screens.

**Interfaces:**
- Consumes: `SPLM_Discipline_Notice_Recipients::players_for_email()` (Task 5); `SPLM_Discipline_Notice_Database::table_name()`/`table_exists()` (Task 3).
- Produces: `SPLM_Discipline_Notice_Privacy`, registering a `sportspress-league-manager` exporter and eraser.

**⚠️ Spec deviation, deliberate.** The spec says notice rows "must join the existing exporter and eraser in `class-privacy.php`". That file lives in **`sportspress-admin-tools`, the parent plugin**, and it currently knows nothing about any league-manager table — `splm_player_notes` is not covered by it either. Making the parent read a child's table inverts the dependency direction the rest of this codebase maintains, and would mean a league-manager schema change requiring a parent-plugin edit.

WordPress supports any number of exporters and erasers keyed by name, so league-manager registers its own instead. Same coverage, correct ownership, and no edit to the parent. The pagination and transient discipline is copied from the parent's implementation because that file documents a real failure mode worth inheriting.

**⚠️ The eraser must not re-query mid-run.** `class-privacy.php:449-466` documents why: the email→player lookup resolves through `spt_email`/`sp_user` post meta, and an earlier page's erase may already have removed it. Cache the id list in a transient on page 1 and stop cleanly if the transient is gone, rather than re-querying and falsely reporting success. The same hazard applies here because this eraser uses the same lookup.

- [ ] **Step 1: Create the privacy class**

Create `sportspress-league-manager/includes/class-discipline-notice-privacy.php`:

```php
<?php
/**
 * GDPR export and erase for disciplinary notices.
 *
 * Registered by league-manager rather than added to the parent plugin's
 * class-privacy.php: that file lives in sportspress-admin-tools and knows
 * nothing about any league-manager table, and making the parent read a child's
 * schema inverts the dependency direction this codebase maintains. WordPress
 * supports any number of exporters keyed by name, so this is the same coverage
 * with the ownership the right way round.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Privacy {

	const BATCH_SIZE = 50;

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporters( $exporters ) {
		$exporters['sportspress-league-manager-discipline'] = array(
			'exporter_friendly_name' => __( 'SportsPress Disciplinary Notices', 'sportspress-league-manager' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_erasers( $erasers ) {
		$erasers['sportspress-league-manager-discipline'] = array(
			'eraser_friendly_name' => __( 'SportsPress Disciplinary Notices', 'sportspress-league-manager' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export notice rows for a person.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          1-indexed page.
	 * @return array array( 'data' => array, 'done' => bool ).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function export( $email_address, $page = 1 ) {
		$rows   = $this->rows_for( $email_address );
		$offset = ( max( 1, (int) $page ) - 1 ) * self::BATCH_SIZE;
		$slice  = array_slice( $rows, $offset, self::BATCH_SIZE );

		$items = array();
		foreach ( $slice as $row ) {
			$items[] = array(
				'group_id'          => 'splm-discipline-notices',
				'group_label'       => __( 'Disciplinary Notices', 'sportspress-league-manager' ),
				'group_description' => __( 'Penalty-threshold warnings and suspensions issued to this player.', 'sportspress-league-manager' ),
				'item_id'           => 'splm-notice-' . (int) $row->id,
				'data'              => array(
					array(
						'name'  => __( 'Recorded', 'sportspress-league-manager' ),
						'value' => $row->created_at,
					),
					array(
						'name'  => __( 'Threshold', 'sportspress-league-manager' ),
						'value' => $row->tier_key,
					),
					array(
						'name'  => __( 'Consequence', 'sportspress-league-manager' ),
						'value' => 'suspend' === $row->consequence
							? sprintf(
								/* translators: %d: number of games. */
								_n( 'Suspension — %d game', 'Suspension — %d games', (int) $row->games, 'sportspress-league-manager' ),
								(int) $row->games
							)
							: __( 'Warning', 'sportspress-league-manager' ),
					),
					array(
						'name'  => __( 'Penalty minutes at the time', 'sportspress-league-manager' ),
						'value' => (int) $row->value_at_fire,
					),
					array(
						'name'  => __( 'Status', 'sportspress-league-manager' ),
						'value' => $row->status,
					),
					array(
						'name'  => __( 'Sent to', 'sportspress-league-manager' ),
						'value' => $row->recipient,
					),
					array(
						'name'  => __( 'Sent at', 'sportspress-league-manager' ),
						'value' => $row->sent_at,
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => ( $offset + self::BATCH_SIZE ) >= count( $rows ),
		);
	}

	/**
	 * Anonymise notice rows for a person.
	 *
	 * Rows are anonymised rather than deleted: the league has a legitimate
	 * interest in the fact that a suspension was issued, and the identifying
	 * data is the address and the copied recipients, not the row's existence.
	 * player_id is zeroed so the row can no longer be tied back.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          1-indexed page.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function erase( $email_address, $page = 1 ) {
		global $wpdb;

		$messages = array();

		if ( ! SPLM_Discipline_Notice_Database::table_exists() ) {
			return $this->erase_result( 0, 0, $messages, true );
		}

		// The email -> player lookup resolves through spt_email / sp_user post
		// meta, which an earlier page's erasure may already have removed. Cache
		// the id list on page 1 and stop cleanly if it is gone, rather than
		// re-querying and falsely reporting success — the failure mode the
		// parent plugin's eraser documents at length.
		$transient_key = 'splm_notice_erase_' . md5( $email_address );

		if ( 1 === (int) $page ) {
			$player_ids = SPLM_Discipline_Notice_Recipients::players_for_email( $email_address );
			set_transient( $transient_key, $player_ids, HOUR_IN_SECONDS );
		} else {
			$player_ids = get_transient( $transient_key );
			if ( false === $player_ids ) {
				return $this->erase_result(
					0,
					0,
					array( __( 'Erasure session cache expired before pagination finished. Notices processed so far have already been anonymized; re-run the eraser to finish any remaining rows.', 'sportspress-league-manager' ) ),
					true
				);
			}
		}

		if ( ! $player_ids ) {
			delete_transient( $transient_key );
			return $this->erase_result( 0, 0, $messages, true );
		}

		$offset    = ( max( 1, (int) $page ) - 1 ) * self::BATCH_SIZE;
		$batch_ids = array_slice( $player_ids, $offset, self::BATCH_SIZE );
		$removed   = 0;

		$table     = SPLM_Discipline_Notice_Database::table_name();
		$redacted  = __( 'Redacted', 'sportspress-league-manager' );

		foreach ( $batch_ids as $player_id ) {
			$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE player_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value.
					(int) $player_id
				)
			);

			if ( ! $count ) {
				continue;
			}

			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value.
					"UPDATE {$table} SET recipient = %s, bcc = NULL, note = NULL, player_id = 0 WHERE player_id = %d",
					$redacted,
					(int) $player_id
				)
			);

			$removed += $count;
		}

		if ( $removed ) {
			$messages[] = sprintf(
				/* translators: %d: number of notices anonymized. */
				_n( 'Anonymized %d disciplinary notice.', 'Anonymized %d disciplinary notices.', $removed, 'sportspress-league-manager' ),
				$removed
			);
		}

		$done = ( $offset + self::BATCH_SIZE ) >= count( $player_ids );
		if ( $done ) {
			delete_transient( $transient_key );
		}

		return $this->erase_result( $removed, 0, $messages, $done );
	}

	/**
	 * Notice rows belonging to a person.
	 *
	 * @param string $email_address Email address.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function rows_for( string $email_address ): array {
		global $wpdb;

		if ( ! SPLM_Discipline_Notice_Database::table_exists() ) {
			return array();
		}

		$player_ids = SPLM_Discipline_Notice_Recipients::players_for_email( $email_address );
		if ( ! $player_ids ) {
			return array();
		}

		$table        = SPLM_Discipline_Notice_Database::table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $player_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a list of literal placeholders; values are bound below.
				"SELECT * FROM {$table} WHERE player_id IN ({$placeholders}) ORDER BY id ASC",
				$player_ids
			)
		);
	}

	/**
	 * The eraser return shape.
	 *
	 * @param int   $removed  Items removed.
	 * @param int   $retained Items retained.
	 * @param array $messages Messages.
	 * @param bool  $done     Whether the eraser is finished.
	 * @return array
	 */
	private function erase_result( int $removed, int $retained, array $messages, bool $done ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}
}
```

- [ ] **Step 2: Lint and confirm the class is reachable**

```bash
php -l sportspress-league-manager/includes/class-discipline-notice-privacy.php
grep -c "SPLM_Discipline_Notice_Privacy" sportspress-league-manager/includes/class-autoloader.php
```

Expected: `php -l` clean, and the grep prints `1` — the entry was added in Task 14.

- [ ] **Step 3: Run the full suite**

```bash
./run-all-tests.sh 2>&1 | tail -12
```

- [ ] **Step 4: Commit**

```bash
git add sportspress-league-manager/includes/class-discipline-notice-privacy.php
git commit -m "feat(discipline): GDPR export and erase for notices

Registered by league-manager rather than bolted onto the parent plugin's
class-privacy.php. That file knows nothing about any league-manager table
— splm_player_notes is not covered by it either — and making the parent
read a child's schema inverts the dependency direction this codebase
maintains. WordPress supports any number of exporters keyed by name.

Rows are anonymised rather than deleted: the league has a legitimate
interest in the fact a suspension was issued, and the identifying data is
the address and the copied recipients. player_id is zeroed so the row
cannot be tied back.

The eraser caches its id list on page one and stops cleanly if the
transient is gone rather than re-querying — the email lookup resolves
through post meta an earlier page may already have erased, which is the
failure mode the parent's eraser documents."
```

---

### Task 16: Phase 2 polish

Two small integrations that are genuinely deferrable: until they land, the digest redundantly lists an already-notified player, and the health page does not know about the new table or cron.

**Files:**
- Modify: `sportspress-league-manager/includes/class-discipline-database.php:132`, `:144`
- Modify: `sportspress-league-manager/includes/class-discipline-notice-mail.php` (add the ack write)
- Modify: `sportspress-league-manager/sportspress-league-manager.php` (health filters)

**Interfaces:**
- Consumes: `SPLM_Discipline_Database::acknowledge()`.

- [ ] **Step 1: Allow the `notice_sent` ack status**

In `class-discipline-database.php`, extend the allowed list at line 144:

```php
			$allowed = array( 'reviewed', 'suspension_served', 'dismissed', 'notice_sent' );
```

And the param docblock at line 132:

```php
	 * @param string $status    reviewed|suspension_served|dismissed|notice_sent.
```

Then add a read helper to the same class, because Step 2 must not overwrite a convener's own acknowledgement:

```php
	/**
	 * Whether an acknowledgement already exists for this key.
	 *
	 * acknowledge() upserts on UNIQUE (player_id, season_id, tier_key) and
	 * overwrites value_at_ack, status, note and author_id unconditionally. The
	 * notice path needs to know when NOT to call it: a convener who
	 * acknowledged a tier at a deliberately high value — the way they silence a
	 * player for the rest of a season — would otherwise have that value reset
	 * and their note erased by an automatic notice_sent write.
	 *
	 * @param int    $player_id Player post id.
	 * @param int    $season_id Season term id.
	 * @param string $tier_key  Acknowledgement key.
	 * @return bool
	 */
	public static function has_ack( int $player_id, int $season_id, string $tier_key ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$table = self::table_name();

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE player_id = %d AND season_id = %d AND tier_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				$player_id,
				$season_id,
				$tier_key
			)
		);
	}
```

- [ ] **Step 2: Write the companion ack on a successful send**

In `SPLM_Discipline_Notice_Mail::send()`, inside the `if ( $sent )` branch, after the row update and before the `return true;`:

```php
			// A sent notice also acknowledges the flag, so the weekly digest
			// stops listing a player who has already been told. Reuses the
			// existing suppression machinery rather than adding a second one:
			// value_at_ack already means "quiet until they earn more".
			//
			// ONLY when no acknowledgement exists. acknowledge() upserts on
			// UNIQUE (player, season, tier) and overwrites value_at_ack, status,
			// note AND author_id unconditionally — so writing unconditionally
			// here would destroy a convener's own acknowledgement, losing their
			// note and resetting a deliberately-high value_at_ack (the way a
			// convener silences a player) back down, which restarts the digest
			// nagging about someone they had already dealt with.
			if ( class_exists( 'SPLM_Discipline_Database' ) ) {
				$row = SPLM_Discipline_Notice_Database::find( $notice_id );
				if ( $row && ! SPLM_Discipline_Database::has_ack( (int) $row->player_id, (int) $row->season_id, (string) $row->ack_key ) ) {
					SPLM_Discipline_Database::acknowledge(
						(int) $row->player_id,
						(int) $row->season_id,
						(string) $row->ack_key,
						(int) $row->value_at_fire,
						'notice_sent',
						'',
						get_current_user_id()
					);
				}
			}
```

**Note the ack table's clock.** `acknowledge()` writes `current_time( 'mysql' )` internally. That is the existing table's convention and this task does not change it — the notice table's own `created_at` remains the UTC record. Do not "fix" it here.

- [ ] **Step 3: Register with the health dashboard**

No sibling plugin currently hooks these filters — `splm_player_notes` is hard-coded into the parent's default list — so this is the first use. That is the correct direction: a child contributing its own rows beats editing the parent.

Add to `load_enabled_modules()`, inside the `league_discipline` branch:

```php
			add_filter(
				'spat_health_dashboard_tables',
				function ( $tables ) {
					$tables[] = SPLM_Discipline_Notice_Database::table_name();
					return $tables;
				}
			);
			add_filter(
				'spat_health_dashboard_crons',
				function ( $crons ) {
					$crons[ SPLM_Discipline_Notice_Pass::HOOK ] = 'Discipline Notice Evaluation';
					return $crons;
				}
			);
```

The tables filter expects a flat list of `$wpdb->prefix`-qualified table names; the crons filter expects `hook => label`.

- [ ] **Step 4: Lint and run the suite**

```bash
php -l sportspress-league-manager/includes/class-discipline-database.php
php -l sportspress-league-manager/includes/class-discipline-notice-mail.php
php -l sportspress-league-manager/sportspress-league-manager.php
php sportspress-league-manager/tests/test-discipline-notice-mode.php
./run-all-tests.sh 2>&1 | tail -12
```

Expected: all pass. `test-discipline-notice-mode.php` stubs `SPLM_Discipline_Notice_Database` but not `SPLM_Discipline_Database`, and the new code is guarded by `class_exists()`, so the send tests still pass without a new stub.

- [ ] **Step 5: Commit**

```bash
git add sportspress-league-manager/includes/class-discipline-database.php \
        sportspress-league-manager/includes/class-discipline-notice-mail.php \
        sportspress-league-manager/sportspress-league-manager.php
git commit -m "feat(discipline): digest suppression and health dashboard rows

A sent notice writes a notice_sent acknowledgement, so the weekly digest
stops listing a player who has already been told. Reuses the existing
value_at_ack suppression rather than adding a second mechanism.

Registers the notice table and the daily cron with the health dashboard.
No sibling plugin hooks those filters today — splm_player_notes is
hard-coded into the parent's list — so this is the first use, which is
the right direction: a child contributing its own rows beats editing the
parent."
```

---

## Spec Deviations

Two, both deliberate, both discovered while reading the code the spec argues about.

1. **GDPR lives in league-manager, not the parent.** The spec says notice rows "must join the existing exporter and eraser in `class-privacy.php`". That file is in `sportspress-admin-tools` and references no league-manager table at all — `splm_player_notes` is not covered by it either. Task 15 registers a league-manager-owned exporter and eraser instead. Same coverage; the parent is not edited; a future schema change does not require touching the parent plugin.

2. **The technical queue is its own tab.** The spec says "a second League Manager tab", which this follows — recorded here only because the reason is load-bearing and easy to undo by accident: League Manager's existing tab panel is a single `<form action="options.php">`, so an actionable queue cannot be nested inside it.

## Staging Verification

None of the following is reachable by a unit test. Run it after Task 16, in this order.

- [ ] **The table exists.** Enable the Penalty Discipline module, load any admin page, then check the Discipline Queue tab's diagnostics: "Notice table" must name the table, not "missing".
- [ ] **Nothing is scheduled while both modes are off.** The diagnostics' "Next scheduled run" reads "Not scheduled", and both modes read `disabled`.
- [ ] **Switching a mode on schedules the pass.** Set Suspension Notices to `queued`, save, reload: "Next scheduled run" now shows a timestamp at 07:00 **site** time — not 02:00 or 03:00, which would mean the timezone conversion was dropped.
- [ ] **The first pass baselines silently.** `wp cron event run splm_discipline_notices`. Every player already over a threshold gets a `baseline` row and **no mail is sent**. Confirm in the technical tab's row counts.
- [ ] **A real crossing queues.** Add penalty minutes to one player past a suspending threshold, re-run the pass. Exactly one `pending` row appears, and no mail has gone out.
- [ ] **Release sends.** Release that notice from the React Notices page. Confirm the player receives it, the named game matches their next fixture, and the asterisked footnote is present. Confirm the digest recipients and the captain received Bcc copies and that the player's copy shows no other recipients.
- [ ] **A player with no email fails visibly.** Clear a flagged player's `spt_email` and `sp_user`, run the pass, and confirm the row lands `failed` with the address message, that the React page says "No email address on file for this player", and that adding an address and releasing then succeeds.
- [ ] **Automatic mode sends without a human.** Set Warning Notices to `automatic`, cause a warning crossing, run the pass, confirm the mail arrives and the row is `sent` with `released_by` showing "automatic".
- [ ] **`queued → automatic` does not re-baseline.** Note the row counts, switch a mode from `queued` to `automatic`, run the pass, and confirm no new `baseline` rows appeared.
- [ ] **Lowering a threshold does not mail anyone.** Drop `season-critical` to a value several flagged players already exceed, save, run the pass: new `baseline` rows appear and no notice is sent.
- [ ] **Both surfaces agree.** The same notice's status matches in the WP-admin tab and the React page, and releasing from one is reflected in the other after reload.
- [ ] **Double-click cannot double-send.** Click Release twice quickly; the second attempt returns a 409 conflict, and the player receives one email.
- [ ] **The alert card appears for a convener with a saved layout.** Log in as a convener who has previously toggled dashboard cards and confirm the notice alert card still renders — this is the `visibleCards` trap from Task 13.
- [ ] **An acknowledgement does not cancel a notice.** This is the one load-bearing behaviour no unit test reaches: `matches()` honours acknowledgements (the watch list needs that), and the pass's independence comes from passing it an *empty* ack array. Acknowledge a flagged player from the Leaders page, then run the pass, and confirm the notice still fires. If it does not, the pass is passing real acks and acknowledging a flag — the exact thing the digest email tells conveners to do — silently cancels the player's notification.
- [ ] **Serving works and re-firing works.** Mark a suspension served, then add more penalty minutes and re-run the pass: a new notice fires.
- [ ] **Disabling the module stops the cron.** Turn the module off, load an admin page, confirm `wp cron event list | grep splm_discipline_notices` returns nothing.
- [ ] **GDPR round trip.** Run Tools → Export Personal Data for a notified player's address and confirm the Disciplinary Notices group appears. Then run Erase Personal Data and confirm `recipient` reads "Redacted" and `player_id` is 0.
- [ ] **The health page lists both.** Confirm the SPAT health dashboard shows the notice table with a row count and the notice cron with its next run.
