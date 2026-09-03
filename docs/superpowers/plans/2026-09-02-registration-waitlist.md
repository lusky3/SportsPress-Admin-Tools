# Registration Waitlist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the manual waitlist workflow (swap in a waitlist SKU, watch Processing orders, hand-email a link to the real SKU) with a dashboard queue, timed claim offers, and a purchase gate so only offer-holders can buy the spot.

**Architecture:** A dedicated `wp_splm_waitlist` table owns each entrant's lifecycle (`queued → offered → claimed/expired/cancelled`), populated automatically from waitlist-SKU purchases but independent of WooCommerce order status. A convener offers a spot from the League Manager React dashboard; the entrant gets an emailed claim link that redirects into normal WooCommerce checkout. A `woocommerce_is_purchasable` gate makes the real product unbuyable without a live offer.

**Tech Stack:** WordPress 6.4+, PHP 8.1+, WooCommerce (HPOS-safe CRUD only), SportsPress, React (`@wordpress/scripts`) for the dashboard, standalone `assert_test` PHP harness for tests.

**Spec:** `docs/superpowers/specs/2026-09-02-waitlist-system-design.md`

## Global Constraints

These apply to **every** task. They are not repeated per task.

- **Time is UTC, always.** Every `datetime` column is written with `gmdate( 'Y-m-d H:i:s' )`. Never `current_time()`. Never a `DEFAULT CURRENT_TIMESTAMP` for a value this feature reads. Cron timestamps are `time() + $hours * HOUR_IN_SECONDS`. Only display converts, via `wp_date()`.
- **Prefixes.** New League Manager classes are `SPLM_`, files `sportspress-league-manager/includes/class-*.php`. The one new parent-plugin class is `SPAT_`, file `sportspress-admin-tools/includes/class-season.php`.
- **Text domain** for League Manager strings: `sportspress-league-manager`. For the parent: `sportspress-admin-tools`.
- **Order access is HPOS-safe.** `wc_get_order()`, `wc_get_orders()`, `WC_Order` methods only. Never query `wp_posts`/`wp_postmeta` for orders.
- **REST conventions** per `docs/rest-api-conventions.md`: list endpoints wrap in `{data, total, page, total_pages}` via `splm_rest_list_response()`; writes return `{success: true, ...}`; errors are `WP_Error`.
- **Every REST arg declares `validate_callback` and `sanitize_callback`.** No exceptions.
- **Codacy gate is zero-new-issues.** Static calls to `SPLM_*`/`SPAT_*` helpers are this repo's deliberate shape — annotate the *declaration* with `@SuppressWarnings(PHPMD.StaticAccess)` when PHPMD flags it, one rule per annotation, matching `class-dashboard-frontend.php` and `load_enabled_modules()`. Superglobal reads get `@SuppressWarnings(PHPMD.Superglobals)`. Unused stub params in tests use `func_get_arg()`/`func_num_args()` rather than named params.
- **Tests are standalone.** No WordPress bootstrap. `define( 'ABSPATH', __DIR__ )`, stub the WP functions the unit needs, `assert_test( $cond, $msg )`, exit non-zero on failure. Register every new file in `run-all-tests.sh`.
- **Randomness** for tokens and ids is `bin2hex( random_bytes( n ) )` — never `md5()`, never `wp_rand()`. (Semgrep weak-crypto flagged `md5()` in PR #108.)
- **Commit after every task.** Conventional Commits, scope `waitlist`. Never push to `main`; work on a branch and open a PR.

## Branch

All work happens on one branch:

```bash
git checkout -b feat/registration-waitlist
```

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `sportspress-admin-tools/includes/class-season.php` | `SPAT_Season` — season code and position parsing, shared by SPPR and SPLM. Pure predicates plus thin I/O wrappers. |
| `sportspress-league-manager/includes/class-waitlist-database.php` | `SPLM_Waitlist_Database` — schema, `maybe_upgrade()`, all table CRUD, the shared `now()` UTC helper. |
| `sportspress-league-manager/includes/class-waitlist-matcher.php` | `SPLM_Waitlist_Matcher` — is this a waitlist product, and which real product does it pair with. |
| `sportspress-league-manager/includes/class-waitlist.php` | `SPLM_Waitlist` — ingestion listener, offer/cancel, cron expiry, sweep, claim validation, cart binding, order tie-back. |
| `sportspress-league-manager/includes/class-waitlist-gate.php` | `SPLM_Waitlist_Gate` — `woocommerce_is_purchasable` filter, session entitlement, cart-expiry messaging. |
| `sportspress-league-manager/includes/class-waitlist-rest.php` | `SPLM_Waitlist_REST` — admin routes plus the public claim route. |
| `sportspress-league-manager/src/dashboard/pages/Waitlist.jsx` | Dashboard page: queue table, offer dialog, Season access panel. |
| `sportspress-admin-tools/tests/test-season-helper.php` | `SPAT_Season` predicates + SPPR parity. |
| `sportspress-league-manager/tests/test-waitlist-matcher.php` | Matcher logic. |
| `sportspress-league-manager/tests/test-waitlist-lifecycle.php` | Status transitions, dedupe, sweep, mail-failure unwind, `hours` validation. |
| `sportspress-league-manager/tests/test-waitlist-claim.php` | Token validation, tie-back both paths, cron cleared. |
| `sportspress-league-manager/tests/test-waitlist-time.php` | UTC round-trip, cron/deadline agreement, stale-event defence. |
| `sportspress-league-manager/tests/test-waitlist-gate.php` | Purchasability filter behaviour. |

**Modified:**

| File | Change |
|---|---|
| `sportspress-admin-tools/sportspress-admin-tools.php` | Add `SPAT_Season` to `$autoload_map`. |
| `sportspress-player-registration/includes/class-player-registration.php` | `extract_season_from_product()` and the goalie-tag detection delegate to `SPAT_Season`. Behaviour-preserving. |
| `sportspress-league-manager/sportspress-league-manager.php` | HPOS declaration; register the `league_waitlist` module; load the feature in `load_enabled_modules()`. |
| `sportspress-league-manager/includes/class-autoloader.php` | Add the four new `SPLM_Waitlist*` classes to `$class_map`. |
| `sportspress-league-manager/uninstall.php` | Drop `splm_waitlist` (and the already-leaking `splm_discipline_ack`); clear the cron hook; delete `_splm_waitlist_gated` meta. |
| `sportspress-league-manager/src/dashboard/App.jsx` | Add `Waitlist` to the `PAGES` map. |
| `sportspress-league-manager/src/dashboard/components/Layout.jsx` | Add the nav item, gated on the module. |
| `sportspress-league-manager/src/dashboard/lib/api.js` | Client functions for the five admin routes. |
| `sportspress-league-manager/src/dashboard/styles.css` | Styles for the queue table, countdown, and Season access panel. |
| `run-all-tests.sh` | Register the five new test files. |

**Deliberate refinement of the spec's file assignment:** the spec's component table puts the cart-item binding in `class-waitlist-gate.php`. This plan puts it in `SPLM_Waitlist` (Task 9) instead, because the order tie-back in Phase 3 depends on the binding while the gate in Phase 4 does not — keeping them together would force Phase 4 to land before Phase 3. The gate class keeps the purchasability filter, session entitlement, and cart-expiry messaging.

---

## Phase 1 — Shared groundwork

### Task 1: `SPAT_Season` shared season/position helper

Both SPPR and the new waitlist code need to read a season code and a position off a WooCommerce product. SPPR's versions are `private`, so an earlier spec draft copied the regexes. Two copies of a convention the league edits by hand will drift, so it moves to the shared parent both plugins already require.

**Files:**
- Create: `sportspress-admin-tools/includes/class-season.php`
- Create: `sportspress-admin-tools/tests/test-season-helper.php`
- Modify: `sportspress-admin-tools/sportspress-admin-tools.php:53-58` (the `$autoload_map` array)
- Modify: `run-all-tests.sh`

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  - `SPAT_Season::from_title( string $title ): ?string` — pure. Season code from a product title, or null.
  - `SPAT_Season::from_category_name( string $name ): ?string` — pure. Season code when the whole category name *is* a season code, or null.
  - `SPAT_Season::is_goalie_tag_name( string $name ): bool` — pure.
  - `SPAT_Season::from_product( int $product_id ): ?string` — I/O. Title first, then `product_cat` terms.
  - `SPAT_Season::position_from_product( int $product_id, $product = null ): string` — I/O. Returns `'goalie'` or `'player'`. Passes `$product` through to the `spr_is_goalie_tag` filter so existing consumers keep their third argument.

- [ ] **Step 1: Write the failing test**

Create `sportspress-admin-tools/tests/test-season-helper.php`:

```php
<?php
/**
 * Standalone tests for SPAT_Season.
 *
 * These predicates decide which season and position a paid registration is
 * attributed to, so they are pinned down here without a WordPress bootstrap.
 * The regexes are the ones SPPR_Player_Registration used before the
 * extraction; the parity assertions at the bottom are what prove that.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPAT_Season_Test_State {
	public $titles     = array();
	public $cat_terms  = array();
	public $tag_terms  = array();
}

function spat_season_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPAT_Season_Test_State();
	}
	return $state;
}

function get_the_title( $post_id ) {
	$state = spat_season_test_state();
	return isset( $state->titles[ $post_id ] ) ? $state->titles[ $post_id ] : '';
}

function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) { // phpcs:ignore
	$state = spat_season_test_state();
	$bag   = ( 'product_cat' === $taxonomy ) ? $state->cat_terms : $state->tag_terms;
	return isset( $bag[ $post_id ] ) ? $bag[ $post_id ] : array();
}

function apply_filters( $hook, $value ) { // phpcs:ignore
	return $value;
}

function term( $name ) {
	return (object) array( 'name' => $name );
}

require_once __DIR__ . '/../includes/class-season.php';

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

$s = 'SPAT_Season';

echo "\n=== from_title() ===\n\n";

assert_test( 'S2026' === $s::from_title( 'S2026 Player Registration' ), 'a summer season code is read from a title' );
assert_test( 'W2025-26' === $s::from_title( 'W2025-26 Goalie Registration' ), 'a split-year winter code is read from a title' );
assert_test( 'W2026' === $s::from_title( 'Registration W2026' ), 'a code at the end of a title is read' );
assert_test( null === $s::from_title( 'Player Registration' ), 'a title with no code returns null' );
assert_test( null === $s::from_title( 'X2026 Registration' ), 'a code with an unknown season letter is not matched' );
assert_test( null === $s::from_title( 'S202 Registration' ), 'a three-digit year is not matched' );
assert_test( null === $s::from_title( '' ), 'an empty title returns null' );

echo "\n=== from_category_name() ===\n\n";

assert_test( 'S2026' === $s::from_category_name( 'S2026' ), 'a category that is exactly a season code matches' );
assert_test( 'W2025-26' === $s::from_category_name( 'W2025-26' ), 'a split-year category code matches' );
assert_test( null === $s::from_category_name( 'S2026 Registration' ), 'a category merely containing a code does not match' );
assert_test( null === $s::from_category_name( 'registration' ), 'an ordinary category name returns null' );

echo "\n=== is_goalie_tag_name() ===\n\n";

assert_test( $s::is_goalie_tag_name( 'goalie' ), 'the goalie tag matches' );
assert_test( $s::is_goalie_tag_name( 'Goalie' ), 'the goalie tag matches case-insensitively' );
assert_test( $s::is_goalie_tag_name( ' goalie ' ), 'surrounding whitespace is tolerated' );
assert_test( ! $s::is_goalie_tag_name( 'goalies' ), 'a plural tag does not match, preserving SPPR behaviour' );
assert_test( ! $s::is_goalie_tag_name( 'player' ), 'the player tag does not match' );

echo "\n=== from_product() ===\n\n";

$state                 = spat_season_test_state();
$state->titles[ 101 ]  = 'S2026 Player Registration';
$state->titles[ 102 ]  = 'Player Registration';
$state->cat_terms[ 102 ] = array( term( 'registration' ), term( 'W2026' ) );
$state->titles[ 103 ]  = 'Player Registration';
$state->cat_terms[ 103 ] = array( term( 'registration' ) );

assert_test( 'S2026' === $s::from_product( 101 ), 'the title wins when it carries a code' );
assert_test( 'W2026' === $s::from_product( 102 ), 'a category code is used when the title has none' );
assert_test( null === $s::from_product( 103 ), 'a product with no code anywhere returns null' );

echo "\n=== position_from_product() ===\n\n";

$state->tag_terms[ 201 ] = array( term( 'goalie' ) );
$state->tag_terms[ 202 ] = array( term( 'skater' ) );

assert_test( 'goalie' === $s::position_from_product( 201 ), 'a goalie-tagged product is a goalie' );
assert_test( 'player' === $s::position_from_product( 202 ), 'an otherwise-tagged product is a player' );
assert_test( 'player' === $s::position_from_product( 203 ), 'an untagged product defaults to player' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-admin-tools/tests/test-season-helper.php`

Expected: a PHP fatal — `Failed opening required '.../includes/class-season.php'`. The file does not exist yet.

- [ ] **Step 3: Write the implementation**

Create `sportspress-admin-tools/includes/class-season.php`:

```php
<?php
/**
 * Season and position parsing for WooCommerce registration products.
 *
 * These conventions are edited by hand by the league every season — the
 * season code format and the goalie tag name — so they live in exactly one
 * place. Both SPPR_Player_Registration (which registers paid players) and
 * SPLM_Waitlist (which queues them) call this; the regexes below were
 * extracted verbatim from SPPR's formerly-private methods, and
 * tests/test-season-helper.php asserts that parity.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Season {

	/**
	 * Season code embedded anywhere in a product title.
	 *
	 * @param string $title Product title.
	 * @return string|null Season code, or null when absent.
	 */
	public static function from_title( $title ) {
		if ( preg_match( '/\b([WS]\d{4}(?:-\d{2})?)\b/', (string) $title, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Season code when a category name IS a season code.
	 *
	 * Anchored deliberately: a category merely containing a code ("S2026
	 * Registration") is a registration category, not a season category.
	 *
	 * @param string $name Category name.
	 * @return string|null Season code, or null.
	 */
	public static function from_category_name( $name ) {
		if ( preg_match( '/^[WS]\d{4}(-\d{2})?$/', (string) $name ) ) {
			return (string) $name;
		}
		return null;
	}

	/**
	 * Whether a product tag name marks a goalie product.
	 *
	 * Exact match, not a substring: "goalies" must not match, because that is
	 * how SPPR has always behaved and a league tag rename should be a
	 * deliberate edit here rather than a silent behaviour change.
	 *
	 * @param string $name Tag name.
	 * @return bool
	 */
	public static function is_goalie_tag_name( $name ) {
		return strtolower( trim( (string) $name ) ) === 'goalie';
	}

	/**
	 * Season for a product: title first, then its categories.
	 *
	 * @param int $product_id Product post ID.
	 * @return string|null Season code, or null.
	 */
	public static function from_product( $product_id ) {
		$from_title = self::from_title( get_the_title( $product_id ) );
		if ( null !== $from_title ) {
			return $from_title;
		}

		$categories = wp_get_post_terms( $product_id, 'product_cat' );
		if ( is_array( $categories ) ) {
			foreach ( $categories as $category ) {
				$code = self::from_category_name( $category->name );
				if ( null !== $code ) {
					return $code;
				}
			}
		}

		return null;
	}

	/**
	 * Position for a product, from its product tags.
	 *
	 * The spr_is_goalie_tag filter keeps its original three arguments so any
	 * existing consumer registered against SPPR's version still works.
	 *
	 * @param int   $product_id Product post ID.
	 * @param mixed $product    Optional WC_Product, passed to the filter.
	 * @return string 'goalie' or 'player'.
	 */
	public static function position_from_product( $product_id, $product = null ) {
		$tags = wp_get_post_terms( $product_id, 'product_tag' );
		if ( is_array( $tags ) ) {
			foreach ( $tags as $tag ) {
				$matched = self::is_goalie_tag_name( $tag->name );
				if ( apply_filters( 'spr_is_goalie_tag', $matched, $tag, $product ) ) {
					return 'goalie';
				}
			}
		}

		return 'player';
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-admin-tools/tests/test-season-helper.php`

Expected: `Passed: 23`, `Failed: 0`, exit 0.

- [ ] **Step 5: Register the class for autoloading**

In `sportspress-admin-tools/sportspress-admin-tools.php`, add the entry to `$autoload_map` (keep the existing alignment):

```php
		private static array $autoload_map = array(
			'SPAT_Text_Helper'       => 'includes/class-text-helper.php',
			'SPAT_Logger'            => 'includes/class-logger.php',
			'SPAT_Lock'              => 'includes/class-lock.php',
			'SPAT_Season'            => 'includes/class-season.php',
			'SPAT_Upload_Validator'  => 'includes/class-upload-validator.php',
			'SimpleXLSX'             => 'includes/SimpleXLSX.php',
		);
```

- [ ] **Step 6: Register the test file**

In `run-all-tests.sh`, add above the score-sheets block:

```bash
run_test "$SCRIPT_DIR/sportspress-admin-tools/tests/test-season-helper.php"
```

- [ ] **Step 7: Run the whole suite**

Run: `./run-all-tests.sh`

Expected: one more suite than before, all passing.

- [ ] **Step 8: Commit**

```bash
git add sportspress-admin-tools/includes/class-season.php \
        sportspress-admin-tools/tests/test-season-helper.php \
        sportspress-admin-tools/sportspress-admin-tools.php \
        run-all-tests.sh
git commit -m "feat(waitlist): add SPAT_Season shared season and position parsing

Both the registration path and the new waitlist queue need to read a season
code and a position off a WooCommerce product. SPPR's versions are private,
so extracting them here gives the convention one home instead of two copies
that drift when the league renames a tag or changes a season format.

Pure predicates (from_title, from_category_name, is_goalie_tag_name) carry
the logic and are tested directly; from_product and position_from_product are
thin I/O over them. The spr_is_goalie_tag filter keeps its third argument so
existing consumers are unaffected."
```

---

### Task 2: SPPR delegates to `SPAT_Season`

Task 1 copied SPPR's regexes into the shared helper. This task makes SPPR *use* it, so there is genuinely one copy. This touches the plugin that processes paid registrations, so the change is strictly behaviour-preserving: same method names, same signatures, same return values, same filter.

**Files:**
- Modify: `sportspress-player-registration/includes/class-player-registration.php:263-272` (goalie detection inside `get_registration_items()`) and `:283-297` (`extract_season_from_product()`)
- Modify: `sportspress-admin-tools/tests/test-season-helper.php` (append parity assertions)

**Interfaces:**
- Consumes: `SPAT_Season::from_product()`, `SPAT_Season::position_from_product()` from Task 1.
- Produces: no new public surface. `SPPR_Player_Registration`'s private methods keep their existing names and behaviour.

- [ ] **Step 1: Write the failing parity test**

Append to `sportspress-admin-tools/tests/test-season-helper.php`, immediately before the `echo "\n";` summary block at the bottom:

```php
echo "\n=== SPPR parity: the cases that shaped these regexes ===\n\n";

// These are the shapes actually present on the live roster and store. They are
// asserted here because Task 2 rewires SPPR_Player_Registration to call this
// helper, and a regression here silently misfiles a paid registration.
assert_test( 'W2025-26' === $s::from_title( 'W2025-26 Player Registration' ), 'parity: the current winter season product title' );
assert_test( 'S2026' === $s::from_title( 'S2026 Goalie Registration' ), 'parity: the current summer goalie product title' );
assert_test( 'W2025-26' === $s::from_title( 'W2025-26 Player Registration - Waitlist' ), 'parity: a waitlist-suffixed title still yields the season' );
assert_test( null === $s::from_title( 'Late Fee' ), 'parity: a non-registration product yields no season' );

$state->titles[ 301 ]    = 'Player Registration';
$state->cat_terms[ 301 ] = array( term( 'W2025-26' ), term( 'registration' ) );
assert_test( 'W2025-26' === $s::from_product( 301 ), 'parity: category fallback order matches SPPR (first matching category wins)' );
```

- [ ] **Step 2: Run the test to confirm it passes already**

Run: `php sportspress-admin-tools/tests/test-season-helper.php`

Expected: `Passed: 28`, `Failed: 0`. These assertions describe the helper written in Task 1, so they pass now — they exist to fail loudly if the delegation in Step 3 changes behaviour.

- [ ] **Step 3: Rewire `extract_season_from_product()`**

In `sportspress-player-registration/includes/class-player-registration.php`, replace the whole body of `extract_season_from_product()`:

```php
	/**
	 * Season code for a registration product.
	 *
	 * Delegates to SPAT_Season so the waitlist queue in League Manager and this
	 * registration path cannot disagree about what season a product belongs to.
	 * Kept as a private method with its original name and return contract so
	 * every existing call site is unchanged.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $product_id Product post ID.
	 * @return string|null Season code, or null.
	 */
	private function extract_season_from_product( $product_id ) {
		if ( ! class_exists( 'SPAT_Season' ) ) {
			// The parent plugin gates this class's loading, so this is
			// unreachable in practice — but returning null rather than
			// fataling keeps a paid order recoverable if it ever happens.
			return null;
		}
		return SPAT_Season::from_product( $product_id );
	}
```

- [ ] **Step 4: Rewire the goalie detection**

In the same file, inside `get_registration_items()`, replace the tag loop:

```php
			$position = class_exists( 'SPAT_Season' )
				? SPAT_Season::position_from_product( $lookup_id, $product )
				: 'player';
```

Delete the now-dead `$tags = wp_get_post_terms( $lookup_id, 'product_tag' );` line and the `foreach ( $tags as $tag )` block it fed. The `$position = 'player';` initialiser goes too — the helper returns the default.

If PHPMD flags the static call, add `@SuppressWarnings(PHPMD.StaticAccess)` to the `get_registration_items()` docblock.

- [ ] **Step 5: Run the registration tests**

Run: `php sportspress-player-registration/tests/test-registration-logic.php && php sportspress-player-registration/tests/test-player-matching.php`

Expected: both pass unchanged. If either stubs `wp_get_post_terms` for the goalie path, it now also needs `SPAT_Season` available — require `sportspress-admin-tools/includes/class-season.php` at the top of that test file alongside its existing requires.

- [ ] **Step 6: Run the whole suite**

Run: `./run-all-tests.sh`

Expected: all suites pass.

- [ ] **Step 7: Commit**

```bash
git add sportspress-player-registration/includes/class-player-registration.php \
        sportspress-admin-tools/tests/test-season-helper.php
git commit -m "refactor(waitlist): point SPPR season and position parsing at SPAT_Season

Completes the extraction started in the previous commit: there is now one
copy of the season regex and the goalie tag convention, not two. The private
methods keep their names, signatures and return contracts, so every call site
in process_completed_order() is untouched, and both class_exists() guards
return the old defaults rather than fataling if the parent ever fails to load.

Parity assertions cover the product-title and category shapes actually present
on the live store, since a regression here misfiles a paid registration."
```

---

### Task 3: `SPLM_Waitlist_Database`

The table and every read/write against it. Follows `SPLM_Discipline_Database`'s pattern, including verifying `table_exists()` after `dbDelta()` rather than trusting its return value.

**Files:**
- Create: `sportspress-league-manager/includes/class-waitlist-database.php`
- Create: `sportspress-league-manager/tests/test-waitlist-time.php`
- Modify: `run-all-tests.sh`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `SPLM_Waitlist_Database::table_name(): string`
  - `SPLM_Waitlist_Database::create_table(): bool`
  - `SPLM_Waitlist_Database::table_exists(): bool`
  - `SPLM_Waitlist_Database::maybe_upgrade(): void`
  - `SPLM_Waitlist_Database::now(): string` — `gmdate( 'Y-m-d H:i:s' )`. **Every** datetime write in this feature goes through it.
  - `SPLM_Waitlist_Database::expiry_from_hours( int $hours ): array` — `array( 'expires_at' => string, 'timestamp' => int )`. The single place a deadline is computed, so the stored string and the cron epoch can never disagree.
  - `SPLM_Waitlist_Database::insert( array $data ): int|false`
  - `SPLM_Waitlist_Database::get( int $id ): ?object`
  - `SPLM_Waitlist_Database::update( int $id, array $data ): bool` — stamps `updated_at`.
  - `SPLM_Waitlist_Database::find_by_token( string $token ): ?object`
  - `SPLM_Waitlist_Database::find_active( string $email, string $season, string $position ): ?object` — a `queued` or `offered` row.
  - `SPLM_Waitlist_Database::find_offered_for_product( int $product_id ): array` — offered rows whose `target_product_id` matches.
  - `SPLM_Waitlist_Database::past_due_offered( array $filters = array() ): array`
  - `SPLM_Waitlist_Database::query( array $filters, int $page, int $per_page ): array` — `array( 'rows' => object[], 'total' => int )`
  - `SPLM_Waitlist_Database::target_product_ids( string $season = '' ): array`
  - Status constants: `STATUS_QUEUED`, `STATUS_OFFERED`, `STATUS_CLAIMED`, `STATUS_EXPIRED`, `STATUS_CANCELLED`.

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-waitlist-time.php`:

```php
<?php
/**
 * Standalone tests for the waitlist's time handling.
 *
 * This feature is made entirely of deadlines, and three clocks are within
 * reach: MySQL server time, WordPress site-local time, and UTC epoch seconds
 * (what wp_schedule_single_event consumes). Mixing any two offsets every
 * deadline by the site's UTC offset — four to five hours for this league — so
 * the rule that everything is stored and compared in UTC is asserted here
 * rather than left to reviewer vigilance.
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

// A deliberately non-UTC site timezone. If any production code reaches for
// site-local time instead of UTC, these assertions are what catches it.
date_default_timezone_set( 'America/Toronto' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

require_once __DIR__ . '/../includes/class-waitlist-database.php';

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

$db = 'SPLM_Waitlist_Database';

echo "\n=== now() ===\n\n";

$now = $db::now();
assert_test( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now ), 'now() returns a MySQL datetime string' );
assert_test( $now === gmdate( 'Y-m-d H:i:s' ), 'now() is UTC, not the site timezone' );
assert_test( $now !== date( 'Y-m-d H:i:s' ), 'now() differs from local time under a non-UTC timezone, proving it is not date()' );

echo "\n=== expiry_from_hours() ===\n\n";

$expiry = $db::expiry_from_hours( 48 );
assert_test( is_array( $expiry ) && isset( $expiry['expires_at'], $expiry['timestamp'] ), 'expiry_from_hours returns both a string and an epoch' );
assert_test( $expiry['expires_at'] === gmdate( 'Y-m-d H:i:s', $expiry['timestamp'] ), 'the stored deadline and the cron epoch describe the same instant' );
assert_test( abs( ( $expiry['timestamp'] - time() ) - ( 48 * 3600 ) ) <= 2, 'a 48 hour window lands 48 hours out' );

$one = $db::expiry_from_hours( 1 );
assert_test( abs( ( $one['timestamp'] - time() ) - 3600 ) <= 2, 'a one hour window lands one hour out' );

$long = $db::expiry_from_hours( 720 );
assert_test( abs( ( $long['timestamp'] - time() ) - ( 720 * 3600 ) ) <= 2, 'the maximum 720 hour window lands thirty days out' );

echo "\n=== is_past_due() ===\n\n";

assert_test( $db::is_past_due( gmdate( 'Y-m-d H:i:s', time() - 60 ) ), 'a deadline a minute ago is past due' );
assert_test( ! $db::is_past_due( gmdate( 'Y-m-d H:i:s', time() + 60 ) ), 'a deadline a minute from now is not past due' );
assert_test( ! $db::is_past_due( '' ), 'an empty deadline is not past due' );
assert_test( ! $db::is_past_due( null ), 'a null deadline is not past due' );

// The trap this guards: comparing a UTC-stored deadline against local time.
// Under America/Toronto that is a four to five hour error in one direction,
// which would expire every offer early (or never).
$in_two_hours = gmdate( 'Y-m-d H:i:s', time() + ( 2 * 3600 ) );
assert_test( ! $db::is_past_due( $in_two_hours ), 'a deadline two hours out is not past due despite a -4/-5h site offset' );

echo "\n=== status constants ===\n\n";

assert_test( 'queued' === $db::STATUS_QUEUED, 'queued' );
assert_test( 'offered' === $db::STATUS_OFFERED, 'offered' );
assert_test( 'claimed' === $db::STATUS_CLAIMED, 'claimed' );
assert_test( 'expired' === $db::STATUS_EXPIRED, 'expired' );
assert_test( 'cancelled' === $db::STATUS_CANCELLED, 'cancelled' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-league-manager/tests/test-waitlist-time.php`

Expected: fatal — `Failed opening required '.../includes/class-waitlist-database.php'`.

- [ ] **Step 3: Write the implementation**

Create `sportspress-league-manager/includes/class-waitlist-database.php`:

```php
<?php
/**
 * Storage for registration waitlist entries.
 *
 * Follows the discipline/player-notes table pattern, including verifying the
 * table exists after dbDelta() rather than trusting its return value —
 * dbDelta() returns a list of applied statements and nothing useful on
 * failure, so stamping a version on its return records a failed CREATE as
 * done and never retries.
 *
 * TIME: every datetime this class writes is UTC, via now(). The feature is
 * made of deadlines and three clocks are in reach (MySQL server time, WP
 * site-local, UTC epoch for cron); mixing any two offsets every deadline by
 * the site's UTC offset. expiry_from_hours() is the single place a deadline
 * is computed so the stored string and the cron epoch cannot disagree.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Database {

	const DB_VERSION     = '1.0.0';
	const VERSION_OPTION = 'splm_waitlist_db_version';

	const STATUS_QUEUED    = 'queued';
	const STATUS_OFFERED   = 'offered';
	const STATUS_CLAIMED   = 'claimed';
	const STATUS_EXPIRED   = 'expired';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Every status, for validating request input.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_OFFERED,
			self::STATUS_CLAIMED,
			self::STATUS_EXPIRED,
			self::STATUS_CANCELLED,
		);
	}

	/**
	 * Current UTC time as a MySQL datetime.
	 *
	 * Deliberately not current_time('mysql'), which is site-local.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * A deadline, as both a stored string and a cron epoch.
	 *
	 * Both come from one $timestamp so they always describe the same instant.
	 *
	 * @param int $hours Window length in hours.
	 * @return array{expires_at:string,timestamp:int}
	 */
	public static function expiry_from_hours( $hours ): array {
		$timestamp = time() + ( (int) $hours * HOUR_IN_SECONDS );
		return array(
			'expires_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'timestamp'  => $timestamp,
		);
	}

	/**
	 * Whether a stored UTC deadline has passed.
	 *
	 * An empty or null deadline is never past due — a queued row has no
	 * deadline and must not be swept.
	 *
	 * @param string|null $expires_at Stored UTC datetime.
	 * @return bool
	 */
	public static function is_past_due( $expires_at ): bool {
		if ( empty( $expires_at ) ) {
			return false;
		}
		return strtotime( $expires_at . ' UTC' ) <= time();
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'splm_waitlist';
	}

	/**
	 * Create the table.
	 *
	 * claim_token is nullable and UNIQUE deliberately: MySQL permits any
	 * number of NULLs under a unique index, so every un-offered row coexists
	 * while offered rows are still guaranteed a distinct token. Changing this
	 * to NOT NULL DEFAULT '' — the obvious-looking tidy-up — makes the second
	 * tokenless row fail to insert.
	 *
	 * created_at is written explicitly by insert() rather than defaulting to
	 * CURRENT_TIMESTAMP, which would use MySQL's server clock instead of UTC.
	 *
	 * @return bool True when the table is present afterwards.
	 */
	public static function create_table(): bool {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			season varchar(20) NOT NULL DEFAULT '',
			position varchar(20) NOT NULL DEFAULT 'player',
			waitlist_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'queued',
			claim_token varchar(64) NULL DEFAULT NULL,
			offered_at datetime NULL DEFAULT NULL,
			expires_at datetime NULL DEFAULT NULL,
			resolved_order_id bigint(20) unsigned NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY claim_token (claim_token),
			KEY season_position_status (season, position, status),
			KEY email (email),
			KEY target_product_id (target_product_id)
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
	 * Insert a row. Stamps created_at in UTC.
	 *
	 * @param array $data Column values.
	 * @return int|false Inserted id, or false.
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$data['created_at'] = self::now();
		$inserted           = $wpdb->insert( self::table_name(), $data ); // phpcs:ignore WordPress.DB
		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Update a row. Stamps updated_at in UTC.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column values.
	 * @return bool
	 */
	public static function update( $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = self::now();
		return false !== $wpdb->update( self::table_name(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One row by claim token.
	 *
	 * @param string $token Claim token.
	 * @return object|null
	 */
	public static function find_by_token( $token ) {
		global $wpdb;
		if ( '' === (string) $token ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE claim_token = %s', (string) $token ) ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * An active (queued or offered) row for this person and season/position.
	 *
	 * This is the duplicate guard that makes the ingestion listener idempotent
	 * across repeated paid-status transitions on the same order.
	 *
	 * @param string $email    Billing email.
	 * @param string $season   Season code.
	 * @param string $position 'player' or 'goalie'.
	 * @return object|null
	 */
	public static function find_active( $email, $season, $position ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE email = %s AND season = %s AND position = %s AND status IN (%s, %s) ORDER BY id ASC LIMIT 1',
				strtolower( (string) $email ),
				(string) $season,
				(string) $position,
				self::STATUS_QUEUED,
				self::STATUS_OFFERED
			)
		);
		return $row ? $row : null;
	}

	/**
	 * Offered rows whose target product is this one.
	 *
	 * @param int $product_id Product post ID.
	 * @return object[]
	 */
	public static function find_offered_for_product( $product_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE target_product_id = %d AND status = %s',
				(int) $product_id,
				self::STATUS_OFFERED
			)
		);
	}

	/**
	 * Offered rows whose deadline has passed.
	 *
	 * Bounded by the caller's own filters so the sweep on a list request only
	 * touches rows that request was already asking about.
	 *
	 * @param array $filters Optional 'season' and 'position'.
	 * @return object[]
	 */
	public static function past_due_offered( array $filters = array() ): array {
		global $wpdb;
		$sql    = 'SELECT * FROM ' . self::table_name() . ' WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s';
		$params = array( self::STATUS_OFFERED, self::now() );

		if ( ! empty( $filters['season'] ) ) {
			$sql     .= ' AND season = %s';
			$params[] = (string) $filters['season'];
		}
		if ( ! empty( $filters['position'] ) ) {
			$sql     .= ' AND position = %s';
			$params[] = (string) $filters['position'];
		}

		$sql .= ' LIMIT 200';

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A filtered, paginated page of rows.
	 *
	 * @param array $filters  Optional 'season', 'position', 'status'.
	 * @param int   $page     1-indexed page.
	 * @param int   $per_page Rows per page.
	 * @return array{rows:object[],total:int}
	 */
	public static function query( array $filters, $page = 1, $per_page = 50 ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'season', 'position', 'status' ) as $column ) {
			if ( ! empty( $filters[ $column ] ) ) {
				$where[]  = "{$column} = %s";
				$params[] = (string) $filters[ $column ];
			}
		}

		$clause = implode( ' AND ', $where );
		$table  = self::table_name();

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( empty( $params )
			? $wpdb->get_var( $total_sql ) // phpcs:ignore WordPress.DB
			: $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) ); // phpcs:ignore WordPress.DB

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$rows_sql    = "SELECT * FROM {$table} WHERE {$clause} ORDER BY created_at ASC, id ASC LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = (array) $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ) ); // phpcs:ignore WordPress.DB

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Distinct non-zero target product ids, optionally for one season.
	 *
	 * Backs the Season access panel and validates the gate toggle's product_id
	 * so it cannot be pointed at an arbitrary post.
	 *
	 * @param string $season Optional season code.
	 * @return int[]
	 */
	public static function target_product_ids( $season = '' ): array {
		global $wpdb;
		$table = self::table_name();

		if ( '' !== (string) $season ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT DISTINCT target_product_id FROM {$table} WHERE target_product_id > 0 AND season = %s",
					(string) $season
				)
			);
		} else {
			$ids = $wpdb->get_col( "SELECT DISTINCT target_product_id FROM {$table} WHERE target_product_id > 0" ); // phpcs:ignore WordPress.DB
		}

		return array_map( 'intval', (array) $ids );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-league-manager/tests/test-waitlist-time.php`

Expected: `Passed: 20`, `Failed: 0`, exit 0. (Nothing in the assertions touches `$wpdb`; the CRUD methods are verified against staging, matching how this repo treats thin I/O.)

- [ ] **Step 5: Register the test file**

In `run-all-tests.sh`, add alongside the other league-manager tests:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-time.php"
```

- [ ] **Step 6: Run the whole suite**

Run: `./run-all-tests.sh`

Expected: all suites pass.

- [ ] **Step 7: Commit**

```bash
git add sportspress-league-manager/includes/class-waitlist-database.php \
        sportspress-league-manager/tests/test-waitlist-time.php \
        run-all-tests.sh
git commit -m "feat(waitlist): add the waitlist table and its UTC time helpers

One row per person per season/position, with its own status lifecycle so
queue state stops being inferred from WooCommerce order status.

The time handling is the part worth reviewing. expiry_from_hours() returns
the stored string and the cron epoch from one timestamp so they cannot
disagree, is_past_due() compares UTC against UTC, and created_at is written
explicitly rather than defaulting to CURRENT_TIMESTAMP (MySQL server time).
The tests run under America/Toronto precisely so a reach for site-local time
fails them.

claim_token is nullable under a UNIQUE index on purpose — MySQL allows many
NULLs, and the obvious tidy-up to NOT NULL DEFAULT '' breaks the second
tokenless insert."
```

---

### Task 4: Plugin wiring — module, autoloader, HPOS, uninstall

Makes the plugin aware of the feature: a toggleable module, the table created when it is enabled, the new classes autoloadable, HPOS compatibility declared, and uninstall cleaning up after itself.

**This task deliberately does not instantiate `SPLM_Waitlist`, `SPLM_Waitlist_Gate` or `SPLM_Waitlist_REST`** — those classes do not exist yet, and `new`-ing a missing class fatals. Each later task adds its own instantiation line to `load_enabled_modules()`. Autoloader *map entries* are safe to add now: `SPLM_Autoloader::autoload()` only requires a file when the class is actually referenced.

**Files:**
- Modify: `sportspress-league-manager/sportspress-league-manager.php` (activation/init area, module registrations at `:57-110`, `load_enabled_modules()` at `:125-160`)
- Modify: `sportspress-league-manager/includes/class-autoloader.php:37-57` (`$class_map`)
- Modify: `sportspress-league-manager/uninstall.php:45`

**Interfaces:**
- Consumes: `SPLM_Waitlist_Database::maybe_upgrade()` from Task 3.
- Produces:
  - Module key `league_waitlist`, registered with `SPAT_Plugin_Manager::register_plugin()`.
  - Autoloader entries for `SPLM_Waitlist`, `SPLM_Waitlist_Database`, `SPLM_Waitlist_Gate`, `SPLM_Waitlist_Matcher`, `SPLM_Waitlist_REST`.
  - Cron hook name `splm_waitlist_expire_offer` (cleared on uninstall; scheduled in Task 7).
  - Product meta key `_splm_waitlist_gated` (deleted on uninstall; written in Task 11).

- [ ] **Step 1: Declare HPOS compatibility**

`sportspress-league-manager` already calls `wc_get_order()` and `wc_get_orders()` throughout `class-rest-api.php`, but its main file never declared High-Performance Order Storage compatibility — so WooCommerce lists it as incompatible and can refuse to enable HPOS. This feature adds order hooks, making SPLM a first-class order consumer, so fix it here.

In `sportspress-league-manager/sportspress-league-manager.php`, inside the main class's `__construct()`, alongside the existing `add_action` calls:

```php
			add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
```

And add the method to the class:

```php
	/**
	 * Declare WooCommerce High-Performance Order Storage (custom order tables)
	 * compatibility.
	 *
	 * This plugin reads orders exclusively through the HPOS-safe CRUD layer
	 * (wc_get_order / wc_get_orders / WC_Order methods) and never queries
	 * wp_posts or wp_postmeta for order data, so it is compatible either way.
	 * Without this declaration WooCommerce lists the plugin as incompatible and
	 * blocks HPOS from being enabled. Mirrors the declaration in
	 * sportspress-etransfer-automation.
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
```

- [ ] **Step 2: Register the module**

In the same file's `init()`, after the `league_discipline` registration:

```php
		SPAT_Plugin_Manager::register_plugin(
			'league_waitlist',
			array(
				'name'          => 'Registration Waitlist',
				'description'   => 'Waitlist queue, timed spot offers and purchase gating for full seasons',
				'parent_module' => 'league_waitlist',
				'version'       => SPLM_VERSION,
				'file'          => __FILE__,
			)
		);
```

This is a separate module rather than part of `league_manager_dashboard` for the same reason `league_discipline` is: enabling it starts emailing named individuals and can make a product unpurchasable, so it must be a deliberate act.

- [ ] **Step 3: Create the table when the module is enabled**

In `load_enabled_modules()`, add `'league_waitlist'` to the `$any_enabled` intersection array:

```php
		$any_enabled = array_intersect(
			$enabled,
			array( 'league_manager_dashboard', 'league_roster_management', 'league_fee_tracking', 'league_player_notes', 'league_discipline', 'league_waitlist' )
		);
```

Then, after the `league_discipline` block:

```php
		// The waitlist schema is only needed once the module is deliberately
		// enabled — see the module registration above for why it is not folded
		// into league_manager_dashboard. The feature's own classes are wired in
		// by later commits; this establishes the table.
		if ( in_array( 'league_waitlist', $enabled, true ) ) {
			SPLM_Waitlist_Database::maybe_upgrade();
		}
```

`load_enabled_modules()` already carries `@SuppressWarnings(PHPMD.StaticAccess)`, so the static call needs no new annotation.

- [ ] **Step 4: Add the autoloader entries**

In `sportspress-league-manager/includes/class-autoloader.php`, add to `$class_map` (alphabetical, matching the existing ordering):

```php
			'SPLM_Waitlist'              => $base . 'class-waitlist.php',
			'SPLM_Waitlist_Database'     => $base . 'class-waitlist-database.php',
			'SPLM_Waitlist_Gate'         => $base . 'class-waitlist-gate.php',
			'SPLM_Waitlist_Matcher'      => $base . 'class-waitlist-matcher.php',
			'SPLM_Waitlist_REST'         => $base . 'class-waitlist-rest.php',
```

- [ ] **Step 5: Clean up on uninstall**

`sportspress-league-manager/uninstall.php` already sweeps `splm_`-prefixed options, transients and user meta generically, so `splm_waitlist_keyword` and `splm_waitlist_db_version` are covered. Tables are dropped by name and only `splm_player_notes` is listed — `splm_discipline_ack` has been leaking since the discipline feature shipped. Fix both, and clear the cron and the product meta.

Replace the single `DROP TABLE` line with:

```php
// Drop this plugin's tables. splm_discipline_ack was missing here since the
// discipline feature shipped, so it is added in the same pass.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}splm_player_notes" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}splm_discipline_ack" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}splm_waitlist" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Pending offer-expiry events would otherwise sit in cron with no handler.
wp_clear_scheduled_hook( 'splm_waitlist_expire_offer' );

// The generic sweep above covers options, transients and user meta but not
// post meta. This key is inert once the gate filter is gone, so removing it is
// tidiness rather than a functional fix.
delete_post_meta_by_key( '_splm_waitlist_gated' );
```

- [ ] **Step 6: Verify the wiring on staging**

Copy the plugin to staging and check each piece:

```bash
# Module appears and is off by default
docker exec -u www-data staging-wp wp option get spat_enabled_modules --allow-root

# Enable it, then confirm the table was created
docker exec -u www-data staging-wp wp eval \
  'update_option("spat_enabled_modules", array_unique(array_merge((array)get_option("spat_enabled_modules",array()),["league_waitlist"])));' --allow-root
docker exec -u www-data staging-wp wp db query "SHOW TABLES LIKE '%splm_waitlist'" --allow-root
docker exec -u www-data staging-wp wp db query "DESCRIBE wp_splm_waitlist" --allow-root
docker exec -u www-data staging-wp wp option get splm_waitlist_db_version --allow-root
```

Expected: the table exists with all sixteen columns, `claim_token` shows `Null: YES` with a `UNI` key, and the version option reads `1.0.0`.

Then confirm HPOS no longer flags the plugin: **WooCommerce → Settings → Advanced → Features** should not list SPLM under incompatible plugins.

- [ ] **Step 7: Run the whole suite**

Run: `./run-all-tests.sh`

Expected: all suites pass (no test changes in this task; this confirms nothing regressed).

- [ ] **Step 8: Commit**

```bash
git add sportspress-league-manager/sportspress-league-manager.php \
        sportspress-league-manager/includes/class-autoloader.php \
        sportspress-league-manager/uninstall.php
git commit -m "feat(waitlist): register the league_waitlist module and wire up the schema

A separate toggleable module rather than part of league_manager_dashboard,
for the same reason league_discipline is separate: enabling it starts
emailing named individuals and can make a product unpurchasable, so it has
to be deliberate.

Also declares HPOS compatibility, which this plugin never did despite already
reading orders through wc_get_order()/wc_get_orders() throughout the REST
controller — WooCommerce was listing it as incompatible and could refuse to
enable custom order tables. This feature adds order hooks, so it matters now.

Uninstall drops the new table, clears the pending-expiry cron and removes the
gate product meta. It also drops splm_discipline_ack, which has been leaking
since the discipline feature shipped.

The feature's own classes are not instantiated yet — later commits add their
own lines to load_enabled_modules() as each class lands, so every commit in
this series leaves a working tree."
```

---

## Phase 2 — Ingestion

### Task 5: `SPLM_Waitlist_Matcher`

Decides whether a purchased product is a waitlist SKU, and which real registration SKU it pairs with. The selection logic is a pure function over already-resolved facts so it can be tested exhaustively; the WordPress queries that gather those facts are thin wrappers over it.

**Files:**
- Create: `sportspress-league-manager/includes/class-waitlist-matcher.php`
- Create: `sportspress-league-manager/tests/test-waitlist-matcher.php`
- Modify: `run-all-tests.sh`

**Interfaces:**
- Consumes: `SPAT_Season::from_product()`, `SPAT_Season::position_from_product()` (Task 1).
- Produces:
  - `SPLM_Waitlist_Matcher::keyword(): string` — `splm_waitlist_keyword` option, default `'waitlist'`.
  - `SPLM_Waitlist_Matcher::registration_keyword(): string` — reads SPPR's existing `spr_registration_keyword` option, default `'registration'`.
  - `SPLM_Waitlist_Matcher::matches_keyword( string $name, string $keyword ): bool` — pure, case-insensitive substring.
  - `SPLM_Waitlist_Matcher::select_target( array $candidates, string $season, string $position ): int` — **pure**. `$candidates` is a list of `array( 'id' => int, 'season' => ?string, 'position' => string, 'is_waitlist' => bool )`. Returns the single matching id, or `0` for zero or multiple matches.
  - `SPLM_Waitlist_Matcher::is_waitlist_product( int $product_id ): bool` — I/O.
  - `SPLM_Waitlist_Matcher::find_target_product( string $season, string $position ): int` — I/O; builds candidates and delegates to `select_target()`.

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-waitlist-matcher.php`:

```php
<?php
/**
 * Standalone tests for SPLM_Waitlist_Matcher.
 *
 * select_target() decides which real registration product a waitlist entry
 * will be offered. Getting it wrong either strands an entrant (no target) or
 * points them at the wrong season's product, so every ambiguity case is
 * pinned down here. Ambiguity resolves to 0 — never a guess — because the
 * dashboard can ask a human, and picking silently cannot be undone.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

class SPLM_Matcher_Test_State {
	public $options = array();
}

function splm_matcher_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Matcher_Test_State();
	}
	return $state;
}

function get_option( $name, $default = false ) {
	$state = splm_matcher_test_state();
	return array_key_exists( $name, $state->options ) ? $state->options[ $name ] : $default;
}

require_once __DIR__ . '/../includes/class-waitlist-matcher.php';

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

$m = 'SPLM_Waitlist_Matcher';

echo "\n=== matches_keyword() ===\n\n";

assert_test( $m::matches_keyword( 'Waitlist', 'waitlist' ), 'an exact name matches case-insensitively' );
assert_test( $m::matches_keyword( 'S2026 Waitlist', 'waitlist' ), 'a name containing the keyword matches' );
assert_test( $m::matches_keyword( 'registration', 'REGISTRATION' ), 'the keyword itself is matched case-insensitively' );
assert_test( ! $m::matches_keyword( 'S2026 Registration', 'waitlist' ), 'an unrelated name does not match' );
assert_test( ! $m::matches_keyword( 'anything', '' ), 'an empty keyword never matches, so a blank option cannot swallow every product' );
assert_test( ! $m::matches_keyword( '', 'waitlist' ), 'an empty name does not match' );

echo "\n=== keyword defaults ===\n\n";

$state = splm_matcher_test_state();
assert_test( 'waitlist' === $m::keyword(), 'the waitlist keyword defaults to waitlist' );
assert_test( 'registration' === $m::registration_keyword(), 'the registration keyword defaults to SPPR\'s own default' );

$state->options['splm_waitlist_keyword']   = 'queue';
$state->options['spr_registration_keyword'] = 'signup';
assert_test( 'queue' === $m::keyword(), 'a configured waitlist keyword is used' );
assert_test( 'signup' === $m::registration_keyword(), 'SPPR\'s configured registration keyword is honoured' );

$state->options = array();

echo "\n=== select_target() ===\n\n";

function candidate( $id, $season, $position, $is_waitlist = false ) {
	return array(
		'id'          => $id,
		'season'      => $season,
		'position'    => $position,
		'is_waitlist' => $is_waitlist,
	);
}

$one = array( candidate( 11, 'S2026', 'player' ) );
assert_test( 11 === $m::select_target( $one, 'S2026', 'player' ), 'a single exact match is selected' );
assert_test( 0 === $m::select_target( $one, 'S2026', 'goalie' ), 'a position mismatch selects nothing' );
assert_test( 0 === $m::select_target( $one, 'W2026', 'player' ), 'a season mismatch selects nothing' );

assert_test( 0 === $m::select_target( array(), 'S2026', 'player' ), 'no candidates selects nothing' );

$two = array( candidate( 11, 'S2026', 'player' ), candidate( 12, 'S2026', 'player' ) );
assert_test( 0 === $m::select_target( $two, 'S2026', 'player' ), 'two equally valid candidates are ambiguous and select nothing' );

$mixed = array(
	candidate( 11, 'S2026', 'player' ),
	candidate( 12, 'S2026', 'goalie' ),
	candidate( 13, 'W2026', 'player' ),
);
assert_test( 11 === $m::select_target( $mixed, 'S2026', 'player' ), 'the right season and position is picked out of a mixed set' );
assert_test( 12 === $m::select_target( $mixed, 'S2026', 'goalie' ), 'the goalie product is picked for a goalie entry' );

// The whole point of the exclusion: the waitlist SKU itself shares the season
// and position with the product being looked for, so without this filter it
// would be its own target and the claim link would loop back to the waitlist.
$with_waitlist = array(
	candidate( 11, 'S2026', 'player' ),
	candidate( 99, 'S2026', 'player', true ),
);
assert_test( 11 === $m::select_target( $with_waitlist, 'S2026', 'player' ), 'the waitlist product is excluded from its own target search' );

$only_waitlist = array( candidate( 99, 'S2026', 'player', true ) );
assert_test( 0 === $m::select_target( $only_waitlist, 'S2026', 'player' ), 'a set containing only the waitlist product selects nothing' );

$null_season = array( candidate( 11, null, 'player' ) );
assert_test( 0 === $m::select_target( $null_season, 'S2026', 'player' ), 'a candidate with no detectable season is skipped' );

assert_test( 0 === $m::select_target( $one, '', 'player' ), 'an empty season to look for selects nothing rather than matching everything' );

$dupes = array( candidate( 11, 'S2026', 'player' ), candidate( 11, 'S2026', 'player' ) );
assert_test( 11 === $m::select_target( $dupes, 'S2026', 'player' ), 'the same id listed twice is one match, not an ambiguity' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-league-manager/tests/test-waitlist-matcher.php`

Expected: fatal — `Failed opening required '.../includes/class-waitlist-matcher.php'`.

- [ ] **Step 3: Write the implementation**

Create `sportspress-league-manager/includes/class-waitlist-matcher.php`:

```php
<?php
/**
 * Identifying waitlist products and their real counterparts.
 *
 * The league marks a season full by swapping a registration product's
 * category to a waitlist one, so the category is the signal (it used to be a
 * naming convention, which was less reliable). Matching mirrors how SPPR
 * matches its own registration category: a case-insensitive substring test
 * against a configurable keyword.
 *
 * select_target() is pure and carries the logic worth testing. The queries
 * that feed it are thin, and are verified against staging.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Matcher {

	/**
	 * Category keyword that marks a waitlist product.
	 *
	 * @return string
	 */
	public static function keyword(): string {
		return (string) get_option( 'splm_waitlist_keyword', 'waitlist' );
	}

	/**
	 * Category keyword that marks a real registration product.
	 *
	 * Deliberately reads SPPR's existing option rather than introducing a
	 * second one: if a convener renames the registration category, the
	 * registration path and the waitlist path must agree about it.
	 *
	 * @return string
	 */
	public static function registration_keyword(): string {
		return (string) get_option( 'spr_registration_keyword', 'registration' );
	}

	/**
	 * Case-insensitive substring match, matching SPPR's category test.
	 *
	 * An empty keyword never matches. Without that guard a blanked-out option
	 * would make stripos() match every product name and treat the entire
	 * catalogue as waitlist products.
	 *
	 * @param string $name    Term name.
	 * @param string $keyword Configured keyword.
	 * @return bool
	 */
	public static function matches_keyword( $name, $keyword ): bool {
		$name    = (string) $name;
		$keyword = (string) $keyword;
		if ( '' === $keyword || '' === $name ) {
			return false;
		}
		return stripos( $name, $keyword ) !== false;
	}

	/**
	 * The single real product matching a season and position.
	 *
	 * Pure. Ambiguity resolves to 0 rather than a guess: the dashboard can ask
	 * a convener which product was meant, but a silently wrong target sends a
	 * player to the wrong season's checkout and cannot be undone.
	 *
	 * Waitlist candidates are excluded because the waitlist SKU shares its
	 * season and position with the product being searched for — without the
	 * filter it would be its own target and the claim link would loop back to
	 * the waitlist.
	 *
	 * @param array  $candidates List of id/season/position/is_waitlist maps.
	 * @param string $season     Season code to match.
	 * @param string $position   'player' or 'goalie'.
	 * @return int Product id, or 0 when there is not exactly one match.
	 */
	public static function select_target( array $candidates, $season, $position ): int {
		if ( '' === (string) $season ) {
			return 0;
		}

		$matches = array();
		foreach ( $candidates as $candidate ) {
			if ( ! empty( $candidate['is_waitlist'] ) ) {
				continue;
			}
			if ( ( $candidate['season'] ?? null ) !== $season ) {
				continue;
			}
			if ( ( $candidate['position'] ?? '' ) !== $position ) {
				continue;
			}
			// Keyed by id so the same product listed twice is one match and
			// cannot fake an ambiguity.
			$matches[ (int) $candidate['id'] ] = (int) $candidate['id'];
		}

		return count( $matches ) === 1 ? (int) reset( $matches ) : 0;
	}

	/**
	 * product_cat term ids whose name matches a keyword.
	 *
	 * @param string $keyword Configured keyword.
	 * @return int[]
	 */
	public static function category_ids_for_keyword( $keyword ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$ids = array();
		foreach ( $terms as $term ) {
			if ( self::matches_keyword( $term->name, $keyword ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	}

	/**
	 * Whether a product carries the waitlist category.
	 *
	 * @param int $product_id Product post ID.
	 * @return bool
	 */
	public static function is_waitlist_product( $product_id ): bool {
		$ids = self::category_ids_for_keyword( self::keyword() );
		if ( empty( $ids ) ) {
			return false;
		}
		return (bool) has_term( $ids, 'product_cat', (int) $product_id );
	}

	/**
	 * The real registration product for a season and position.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string $season   Season code.
	 * @param string $position 'player' or 'goalie'.
	 * @return int Product id, or 0 when ambiguous or absent.
	 */
	public static function find_target_product( $season, $position ): int {
		$registration_ids = self::category_ids_for_keyword( self::registration_keyword() );
		if ( empty( $registration_ids ) ) {
			return 0;
		}
		$waitlist_ids = self::category_ids_for_keyword( self::keyword() );

		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $registration_ids,
					),
				),
			)
		);

		$candidates = array();
		foreach ( (array) $product_ids as $product_id ) {
			$candidates[] = array(
				'id'          => (int) $product_id,
				'season'      => SPAT_Season::from_product( (int) $product_id ),
				'position'    => SPAT_Season::position_from_product( (int) $product_id ),
				'is_waitlist' => ! empty( $waitlist_ids ) && has_term( $waitlist_ids, 'product_cat', (int) $product_id ),
			);
		}

		return self::select_target( $candidates, $season, $position );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-league-manager/tests/test-waitlist-matcher.php`

Expected: `Passed: 24`, `Failed: 0`, exit 0.

- [ ] **Step 5: Register the test file**

In `run-all-tests.sh`:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-matcher.php"
```

- [ ] **Step 6: Verify the I/O half against staging**

```bash
docker exec -u www-data staging-wp wp eval \
  'echo "waitlist cats: "; print_r(SPLM_Waitlist_Matcher::category_ids_for_keyword(SPLM_Waitlist_Matcher::keyword()));
   echo "registration cats: "; print_r(SPLM_Waitlist_Matcher::category_ids_for_keyword(SPLM_Waitlist_Matcher::registration_keyword()));
   echo "target for the current season: "; var_dump(SPLM_Waitlist_Matcher::find_target_product("W2025-26","player"));' --allow-root
```

Expected: real category ids for both keywords, and either a real product id or `0`. A `0` here is information, not a failure — it means the paired product is ambiguous or absent for that season, which is exactly the case the dashboard surfaces.

- [ ] **Step 7: Run the whole suite and commit**

Run: `./run-all-tests.sh` — all suites pass.

```bash
git add sportspress-league-manager/includes/class-waitlist-matcher.php \
        sportspress-league-manager/tests/test-waitlist-matcher.php \
        run-all-tests.sh
git commit -m "feat(waitlist): identify waitlist products and pair them with the real SKU

Category is the signal, matching how the league actually marks a season full
and mirroring SPPR's own case-insensitive category test. The registration
keyword is read from SPPR's existing option rather than a second one, so a
renamed category cannot make the two paths disagree.

select_target() is pure and resolves every ambiguity to 0 rather than
guessing — the dashboard can ask a convener which product was meant, but a
silently wrong target sends a player to the wrong season's checkout. It also
excludes waitlist candidates, without which the waitlist SKU would be its own
target and the claim link would loop back to the waitlist.

An empty keyword never matches, so a blanked-out option cannot make stripos()
treat the entire catalogue as waitlist products."
```

---

### Task 6: Ingestion listener

Turns a waitlist-SKU purchase into a `queued` row. Creates `SPLM_Waitlist`, the class the rest of Phase 3 grows into.

**Files:**
- Create: `sportspress-league-manager/includes/class-waitlist.php`
- Create: `sportspress-league-manager/tests/test-waitlist-lifecycle.php`
- Modify: `sportspress-league-manager/sportspress-league-manager.php` (`load_enabled_modules()`, the `league_waitlist` block from Task 4)
- Modify: `run-all-tests.sh`

**Interfaces:**
- Consumes: `SPLM_Waitlist_Database` (Task 3), `SPLM_Waitlist_Matcher` (Task 5), `SPAT_Season` (Task 1).
- Produces:
  - `new SPLM_Waitlist()` — constructor registers `woocommerce_order_status_changed`.
  - `SPLM_Waitlist::build_row( array $facts ): ?array` — **pure**. Returns the insert payload, or null when the line item should not be ingested. `$facts` keys: `is_waitlist` (bool), `season` (?string), `position` (string), `product_id` (int), `target_product_id` (int), `email` (string), `name` (string), `user_id` (int), `order_id` (int), `has_active` (bool).
  - `SPLM_Waitlist::handle_order_status_changed( $order_id, $from, $to, $order = null ): void`
  - `SPLM_Waitlist::ingest_order( $order ): int` — returns how many rows were created; callable from WP-CLI and the staging checks.

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-waitlist-lifecycle.php`:

```php
<?php
/**
 * Standalone tests for SPLM_Waitlist's ingestion decision.
 *
 * build_row() is the gate between "someone bought something" and "a person is
 * now in the queue". It runs on every line item of every paid order in the
 * store, so the cases where it must decline are as important as the case where
 * it accepts.
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

function sanitize_email( $email ) {
	return $email;
}

function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

function get_option( $name, $default = false ) { // phpcs:ignore
	return $default;
}

function add_action() { // phpcs:ignore
	return true;
}

function add_filter() { // phpcs:ignore
	return true;
}

require_once __DIR__ . '/../includes/class-waitlist-database.php';
require_once __DIR__ . '/../includes/class-waitlist.php';

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

$w = 'SPLM_Waitlist';

/**
 * A complete, ingestible set of facts. Individual assertions override one key
 * each so it is obvious which single condition is under test.
 */
function facts( array $overrides = array() ) {
	return array_merge(
		array(
			'is_waitlist'        => true,
			'season'             => 'S2026',
			'position'           => 'player',
			'product_id'         => 99,
			'target_product_id'  => 11,
			'email'              => 'Player@Example.COM',
			'name'               => 'Sam Player',
			'user_id'            => 7,
			'order_id'           => 4321,
			'has_active'         => false,
		),
		$overrides
	);
}

echo "\n=== build_row(): the accepting case ===\n\n";

$row = $w::build_row( facts() );
assert_test( is_array( $row ), 'a complete waitlist purchase produces a row' );
assert_test( 'queued' === $row['status'], 'a new row starts queued' );
assert_test( 'S2026' === $row['season'], 'the season is carried through' );
assert_test( 'player' === $row['position'], 'the position is carried through' );
assert_test( 99 === $row['waitlist_product_id'], 'the waitlist product is recorded' );
assert_test( 11 === $row['target_product_id'], 'the paired real product is recorded' );
assert_test( 4321 === $row['source_order_id'], 'the originating order is recorded' );
assert_test( 7 === $row['user_id'], 'the purchasing user is recorded' );
assert_test( 'Sam Player' === $row['name'], 'the name is carried through' );
assert_test( 'player@example.com' === $row['email'], 'the email is lowercased so matching is case-insensitive' );
assert_test( ! isset( $row['claim_token'] ), 'a queued row carries no token' );
assert_test( ! isset( $row['expires_at'] ), 'a queued row carries no deadline' );

echo "\n=== build_row(): the declining cases ===\n\n";

assert_test( null === $w::build_row( facts( array( 'is_waitlist' => false ) ) ), 'a non-waitlist product is not ingested' );
assert_test( null === $w::build_row( facts( array( 'has_active' => true ) ) ), 'someone already queued or offered is not ingested again' );
assert_test( null === $w::build_row( facts( array( 'season' => null ) ) ), 'a product with no detectable season is not ingested' );
assert_test( null === $w::build_row( facts( array( 'season' => '' ) ) ), 'an empty season is not ingested' );
assert_test( null === $w::build_row( facts( array( 'email' => '' ) ) ), 'an order with no billing email is not ingested, since email is how the entrant is identified' );

echo "\n=== build_row(): an ambiguous target is still queued ===\n\n";

// A 0 target is deliberately NOT a reason to decline. The person really did
// buy a waitlist spot; refusing to record them would lose them entirely.
// The dashboard flags the row and a convener sets the target before offering.
$ambiguous = $w::build_row( facts( array( 'target_product_id' => 0 ) ) );
assert_test( is_array( $ambiguous ), 'an unresolvable target still queues the person rather than losing them' );
assert_test( 0 === $ambiguous['target_product_id'], 'the unresolved target is recorded as 0 for the dashboard to flag' );

echo "\n=== build_row(): normalisation ===\n\n";

$padded = $w::build_row( facts( array( 'name' => '  Sam Player  ', 'email' => '  MiXeD@Example.com ' ) ) );
assert_test( 'Sam Player' === $padded['name'], 'a padded name is trimmed' );
assert_test( 'mixed@example.com' === $padded['email'], 'a padded, mixed-case email is trimmed and lowercased' );

$guest = $w::build_row( facts( array( 'user_id' => 0 ) ) );
assert_test( is_array( $guest ) && 0 === $guest['user_id'], 'a guest checkout is ingested with user_id 0' );

echo "\n=== is_paid_status() ===\n\n";

assert_test( $w::is_paid_status( 'processing', array( 'processing', 'completed' ) ), 'processing is a paid status' );
assert_test( $w::is_paid_status( 'completed', array( 'processing', 'completed' ) ), 'completed is a paid status, which is the trap this listener exists to avoid' );
assert_test( ! $w::is_paid_status( 'pending', array( 'processing', 'completed' ) ), 'pending is not paid' );
assert_test( ! $w::is_paid_status( 'cancelled', array( 'processing', 'completed' ) ), 'cancelled is not paid' );
assert_test( ! $w::is_paid_status( '', array( 'processing', 'completed' ) ), 'an empty status is not paid' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-league-manager/tests/test-waitlist-lifecycle.php`

Expected: fatal — `Failed opening required '.../includes/class-waitlist.php'`.

- [ ] **Step 3: Write the implementation**

Create `sportspress-league-manager/includes/class-waitlist.php`:

```php
<?php
/**
 * Registration waitlist: queue ingestion and entry lifecycle.
 *
 * The league marks a season full by swapping a registration product's category
 * to a waitlist one; buying that product is how a person joins the queue. That
 * entry point is unchanged from the manual process. What changes is that the
 * entry's lifecycle now lives in its own table instead of being inferred from
 * the WooCommerce order sitting in Processing forever.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist {

	/**
	 * Hook name for the single-event offer expiry (scheduled in a later task).
	 */
	const EXPIRE_HOOK = 'splm_waitlist_expire_offer';

	public function __construct() {
		// woocommerce_order_status_changed, NOT ..._status_processing.
		//
		// Today's waitlist orders sit in Processing, which makes hooking that
		// status look right. It is a trap: WooCommerce's payment_complete()
		// routes a paid order to `completed` instead whenever
		// WC_Order::needs_processing() is false, which is the case when every
		// line item is virtual AND downloadable. These are $0 non-shippable
		// products, so they are one product checkbox away from that — and the
		// failure mode is an order that creates no waitlist row and reports no
		// error at all. Listening for any paid status removes the trap; the
		// duplicate guard in build_row() makes repeated firing harmless.
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_changed' ), 10, 4 );
	}

	/**
	 * Whether a status counts as paid.
	 *
	 * Pure, with the status list injected so it is testable without
	 * WooCommerce loaded.
	 *
	 * @param string   $status        Status being transitioned to.
	 * @param string[] $paid_statuses Statuses WooCommerce considers paid.
	 * @return bool
	 */
	public static function is_paid_status( $status, array $paid_statuses ): bool {
		$status = (string) $status;
		if ( '' === $status ) {
			return false;
		}
		return in_array( $status, $paid_statuses, true );
	}

	/**
	 * The insert payload for one line item, or null to decline.
	 *
	 * Pure. Runs against every line item of every paid order in the store, so
	 * the declining cases matter as much as the accepting one.
	 *
	 * An unresolvable target (0) is deliberately NOT a reason to decline: the
	 * person really did buy a waitlist spot, and refusing to record them would
	 * lose them entirely. The row is stored with target_product_id = 0 and the
	 * dashboard flags it so a convener can set the target before offering.
	 *
	 * @param array $facts Resolved facts about the line item and order.
	 * @return array|null
	 */
	public static function build_row( array $facts ) {
		if ( empty( $facts['is_waitlist'] ) ) {
			return null;
		}
		if ( ! empty( $facts['has_active'] ) ) {
			return null;
		}

		$season = (string) ( $facts['season'] ?? '' );
		if ( '' === $season ) {
			return null;
		}

		// Email is how an entrant is identified for deduplication, for the
		// offer notification and for the order tie-back. Without one there is
		// nothing to queue.
		$email = strtolower( trim( (string) ( $facts['email'] ?? '' ) ) );
		if ( '' === $email ) {
			return null;
		}

		return array(
			'season'              => $season,
			'position'            => (string) ( $facts['position'] ?? 'player' ),
			'waitlist_product_id' => (int) ( $facts['product_id'] ?? 0 ),
			'target_product_id'   => (int) ( $facts['target_product_id'] ?? 0 ),
			'name'                => sanitize_text_field( $facts['name'] ?? '' ),
			'email'               => $email,
			'user_id'             => (int) ( $facts['user_id'] ?? 0 ),
			'source_order_id'     => (int) ( $facts['order_id'] ?? 0 ),
			'status'              => SPLM_Waitlist_Database::STATUS_QUEUED,
		);
	}

	/**
	 * Ingest waitlist purchases when an order reaches a paid status.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order object, when WooCommerce passes one.
	 * @return void
	 */
	public function handle_order_status_changed( $order_id, $from, $to, $order = null ) {
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		if ( ! self::is_paid_status( $to, $paid ) ) {
			return;
		}

		if ( ! $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		self::ingest_order( $order );
	}

	/**
	 * Create queued rows for every waitlist line item on an order.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WC_Order $order Order object.
	 * @return int Rows created.
	 */
	public static function ingest_order( $order ): int {
		$created = 0;
		$email   = strtolower( sanitize_email( (string) $order->get_billing_email() ) );
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			// A variation's category lives on its parent, matching how SPPR
			// resolves the same thing.
			$lookup_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product->get_id();

			if ( ! SPLM_Waitlist_Matcher::is_waitlist_product( $lookup_id ) ) {
				continue;
			}

			$season   = SPAT_Season::from_product( $lookup_id );
			$position = SPAT_Season::position_from_product( $lookup_id, $product );

			$existing = ( $season && $email )
				? SPLM_Waitlist_Database::find_active( $email, $season, $position )
				: null;

			$row = self::build_row(
				array(
					'is_waitlist'       => true,
					'season'            => $season,
					'position'          => $position,
					'product_id'        => (int) $lookup_id,
					'target_product_id' => $season ? SPLM_Waitlist_Matcher::find_target_product( $season, $position ) : 0,
					'email'             => $email,
					'name'              => $name,
					'user_id'           => (int) $order->get_user_id(),
					'order_id'          => (int) $order->get_id(),
					'has_active'        => (bool) $existing,
				)
			);

			if ( null === $row ) {
				continue;
			}

			if ( SPLM_Waitlist_Database::insert( $row ) ) {
				$created++;
			} elseif ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::error(
					'waitlist',
					'failed to insert a waitlist row',
					array(
						'order_id'   => (int) $order->get_id(),
						'product_id' => (int) $lookup_id,
					)
				);
			}
		}

		return $created;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-league-manager/tests/test-waitlist-lifecycle.php`

Expected: `Passed: 27`, `Failed: 0`, exit 0.

- [ ] **Step 5: Instantiate the class**

In `sportspress-league-manager/sportspress-league-manager.php`, extend the `league_waitlist` block added in Task 4:

```php
		if ( in_array( 'league_waitlist', $enabled, true ) ) {
			SPLM_Waitlist_Database::maybe_upgrade();
			new SPLM_Waitlist();
		}
```

- [ ] **Step 6: Register the test file**

In `run-all-tests.sh`:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-lifecycle.php"
```

- [ ] **Step 7: Verify ingestion end to end on staging**

Find a real waitlist order and replay it:

```bash
# An existing Processing order on a waitlist SKU
docker exec -u www-data staging-wp wp eval \
  '$orders = wc_get_orders(["status"=>"processing","limit"=>5]);
   foreach ($orders as $o) { echo $o->get_id()," ",$o->get_billing_email(),"\n"; }' --allow-root

# Replay ingestion for one of them (idempotent — run it twice)
docker exec -u www-data staging-wp wp eval \
  'echo SPLM_Waitlist::ingest_order(wc_get_order(ORDER_ID)), " rows created\n";' --allow-root
docker exec -u www-data staging-wp wp eval \
  'echo SPLM_Waitlist::ingest_order(wc_get_order(ORDER_ID)), " rows created\n";' --allow-root

docker exec -u www-data staging-wp wp db query \
  "SELECT id, season, position, email, target_product_id, status, created_at FROM wp_splm_waitlist" --allow-root
```

Expected: the first replay reports `1 rows created`, the **second reports `0`** (the duplicate guard), and `created_at` is a UTC timestamp — compare it against `date -u` and confirm they agree rather than differing by the site's offset.

- [ ] **Step 8: Run the whole suite and commit**

Run: `./run-all-tests.sh` — all suites pass.

```bash
git add sportspress-league-manager/includes/class-waitlist.php \
        sportspress-league-manager/tests/test-waitlist-lifecycle.php \
        sportspress-league-manager/sportspress-league-manager.php \
        run-all-tests.sh
git commit -m "feat(waitlist): ingest waitlist purchases into the queue

Buying the waitlist SKU still joins the queue — that entry point is unchanged
from the manual process. What changes is that the entry now has its own row
and its own status rather than being inferred from an order parked in
Processing.

Hooks woocommerce_order_status_changed, not ..._status_processing. Today's
orders sitting in Processing make the narrower hook look right, but
payment_complete() routes a paid order to completed instead whenever
needs_processing() is false — every line item virtual AND downloadable — which
a \$0 non-shippable SKU is one checkbox away from. That would create no row
and report no error. Any paid status removes the trap, and the duplicate guard
makes repeated firing harmless.

An unresolvable target product queues the person anyway with target 0 for the
dashboard to flag. They really did buy a spot; declining would lose them."
```

---

## Phase 3 — Offer, expiry, claim, tie-back

### Task 7: Offer and cancel

The convener action: token, deadline, email, cron, all under one lock — and a full unwind when the email fails, so nobody silently burns their window on an invite they never received.

**Files:**
- Modify: `sportspress-league-manager/includes/class-waitlist.php`
- Modify: `sportspress-league-manager/tests/test-waitlist-lifecycle.php` (append)

**Interfaces:**
- Consumes: `SPLM_Waitlist_Database::{get,update,expiry_from_hours,now,STATUS_*}` (Task 3), `SPAT_Lock::with()`.
- Produces:
  - `SPLM_Waitlist::DEFAULT_HOURS` = `48`, `MIN_HOURS` = `1`, `MAX_HOURS` = `720`.
  - `SPLM_Waitlist::validate_hours( $hours ): int|WP_Error` — pure.
  - `SPLM_Waitlist::can_offer( string $status ): bool` — pure. True for `queued` and `expired`.
  - `SPLM_Waitlist::generate_token(): string` — 64 hex chars.
  - `SPLM_Waitlist::offer_updates( string $token, array $expiry ): array` — pure. The column payload for an offer.
  - `SPLM_Waitlist::unwind_updates(): array` — pure. The column payload that returns a row to `queued`.
  - `SPLM_Waitlist::claim_url( string $token ): string`
  - `SPLM_Waitlist::offer( int $id, $hours = null ): array|WP_Error`
  - `SPLM_Waitlist::cancel( int $id ): array|WP_Error`

- [ ] **Step 1: Write the failing test**

Append to `sportspress-league-manager/tests/test-waitlist-lifecycle.php`, immediately before the final `echo "\n";` summary block. Also add these stubs to the top of that file, next to the existing ones:

```php
class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}
```

And the new assertions:

```php
echo "\n=== validate_hours() ===\n\n";

assert_test( 48 === $w::validate_hours( null ), 'an omitted window defaults to 48 hours' );
assert_test( 48 === $w::validate_hours( 48 ), 'the default is accepted explicitly' );
assert_test( 72 === $w::validate_hours( 72 ), 'a longer window is accepted' );
assert_test( 72 === $w::validate_hours( '72' ), 'a numeric string is accepted and cast' );
assert_test( 1 === $w::validate_hours( 1 ), 'the minimum of one hour is accepted' );
assert_test( 720 === $w::validate_hours( 720 ), 'the maximum of 720 hours is accepted' );

// The reason this validation exists: a typo'd 0 or a negative would create an
// offer that is already expired at the moment it is emailed, and an absurd
// value would create one that never expires.
assert_test( is_wp_error( $w::validate_hours( 0 ) ), 'zero hours is refused, since it would send an already-expired invite' );
assert_test( is_wp_error( $w::validate_hours( -5 ) ), 'a negative window is refused' );
assert_test( is_wp_error( $w::validate_hours( 721 ) ), 'a window past the maximum is refused' );
assert_test( is_wp_error( $w::validate_hours( 100000 ) ), 'an absurd window is refused rather than creating a permanent offer' );
assert_test( is_wp_error( $w::validate_hours( 'soon' ) ), 'a non-numeric window is refused' );
assert_test( 'splm_invalid_hours' === $w::validate_hours( 0 )->get_error_code(), 'the refusal carries a specific error code' );

echo "\n=== can_offer() ===\n\n";

assert_test( $w::can_offer( 'queued' ), 'a queued row can be offered' );
assert_test( $w::can_offer( 'expired' ), 'an expired row can be re-offered' );
assert_test( ! $w::can_offer( 'offered' ), 'a row already offered cannot be offered again without cancelling first' );
assert_test( ! $w::can_offer( 'claimed' ), 'a claimed row cannot be offered' );
assert_test( ! $w::can_offer( 'cancelled' ), 'a cancelled row cannot be offered' );
assert_test( ! $w::can_offer( '' ), 'an empty status cannot be offered' );

echo "\n=== generate_token() ===\n\n";

$token_a = $w::generate_token();
$token_b = $w::generate_token();
assert_test( 64 === strlen( $token_a ), 'a token is 64 characters, fitting the varchar(64) column exactly' );
assert_test( 1 === preg_match( '/^[a-f0-9]{64}$/', $token_a ), 'a token is lowercase hex, matching the route regex' );
assert_test( $token_a !== $token_b, 'two tokens differ' );

echo "\n=== offer_updates() ===\n\n";

$expiry  = SPLM_Waitlist_Database::expiry_from_hours( 48 );
$updates = $w::offer_updates( $token_a, $expiry );
assert_test( 'offered' === $updates['status'], 'an offer sets status to offered' );
assert_test( $token_a === $updates['claim_token'], 'the token is stored' );
assert_test( $expiry['expires_at'] === $updates['expires_at'], 'the deadline is stored as the UTC string from expiry_from_hours' );
assert_test( isset( $updates['offered_at'] ), 'the offer time is stamped' );
assert_test( $updates['offered_at'] === gmdate( 'Y-m-d H:i:s' ), 'the offer time is UTC' );
assert_test( null === $updates['resolved_order_id'], 'a fresh offer clears any resolved order from a previous cycle' );

echo "\n=== unwind_updates() ===\n\n";

$unwind = $w::unwind_updates();
assert_test( 'queued' === $unwind['status'], 'unwinding returns the row to queued so the person keeps their place' );
assert_test( null === $unwind['claim_token'], 'unwinding clears the token so the dead link cannot be used' );
assert_test( null === $unwind['expires_at'], 'unwinding clears the deadline' );
assert_test( null === $unwind['offered_at'], 'unwinding clears the offer time' );

echo "\n=== claim_url() ===\n\n";

$url = $w::claim_url( $token_a );
assert_test( strpos( $url, $token_a ) !== false, 'the claim URL carries the token' );
assert_test( strpos( $url, 'splm/v1/waitlist/claim/' ) !== false, 'the claim URL points at the claim route' );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-league-manager/tests/test-waitlist-lifecycle.php`

Expected: fatal — `Call to undefined method SPLM_Waitlist::validate_hours()`.

- [ ] **Step 3: Add the pure helpers**

In `sportspress-league-manager/includes/class-waitlist.php`, add the constants below `EXPIRE_HOOK`:

```php
	/**
	 * Offer window bounds, in hours.
	 *
	 * The floor matters: a zero or negative window creates an offer that is
	 * already expired at the moment it is emailed, which reads to the player
	 * as a broken link. The ceiling stops a typo turning an offer permanent.
	 */
	const DEFAULT_HOURS = 48;
	const MIN_HOURS     = 1;
	const MAX_HOURS     = 720;
```

And these methods:

```php
	/**
	 * Validate a requested offer window.
	 *
	 * @param mixed $hours Requested hours, or null for the default.
	 * @return int|WP_Error
	 */
	public static function validate_hours( $hours ) {
		if ( null === $hours || '' === $hours ) {
			return self::DEFAULT_HOURS;
		}
		if ( ! is_numeric( $hours ) ) {
			return new WP_Error(
				'splm_invalid_hours',
				__( 'The claim window must be a number of hours.', 'sportspress-league-manager' ),
				array( 'status' => 400 )
			);
		}

		$hours = (int) $hours;
		if ( $hours < self::MIN_HOURS || $hours > self::MAX_HOURS ) {
			return new WP_Error(
				'splm_invalid_hours',
				sprintf(
					/* translators: 1: minimum hours, 2: maximum hours. */
					__( 'The claim window must be between %1$d and %2$d hours.', 'sportspress-league-manager' ),
					self::MIN_HOURS,
					self::MAX_HOURS
				),
				array( 'status' => 400 )
			);
		}

		return $hours;
	}

	/**
	 * Whether a row's status permits offering it.
	 *
	 * An expired row can be re-offered — that is the normal way a convener
	 * moves down the queue. An already-offered row cannot: cancel it first, so
	 * the live token is invalidated rather than orphaned.
	 *
	 * @param string $status Current status.
	 * @return bool
	 */
	public static function can_offer( $status ): bool {
		return in_array(
			(string) $status,
			array( SPLM_Waitlist_Database::STATUS_QUEUED, SPLM_Waitlist_Database::STATUS_EXPIRED ),
			true
		);
	}

	/**
	 * A claim token.
	 *
	 * random_bytes(), not wp_generate_password() or md5(): this is a security
	 * token, 32 bytes of CSPRNG output makes enumeration infeasible, and the
	 * repo's Semgrep rules flag weaker constructions. 64 hex characters fits
	 * the varchar(64) column exactly.
	 *
	 * @return string
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Column payload for an offer.
	 *
	 * resolved_order_id is explicitly cleared: a re-offered row may carry one
	 * from a previous cycle, and leaving it would make the new offer look
	 * already fulfilled.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string $token  Claim token.
	 * @param array  $expiry Output of SPLM_Waitlist_Database::expiry_from_hours().
	 * @return array
	 */
	public static function offer_updates( $token, array $expiry ): array {
		return array(
			'status'            => SPLM_Waitlist_Database::STATUS_OFFERED,
			'claim_token'       => (string) $token,
			'offered_at'        => SPLM_Waitlist_Database::now(),
			'expires_at'        => (string) $expiry['expires_at'],
			'resolved_order_id' => null,
		);
	}

	/**
	 * Column payload returning a row to queued.
	 *
	 * Used when the notification email fails to send. The person keeps their
	 * place in the queue and the token is cleared so the link that was never
	 * delivered cannot later be used.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return array
	 */
	public static function unwind_updates(): array {
		return array(
			'status'      => SPLM_Waitlist_Database::STATUS_QUEUED,
			'claim_token' => null,
			'offered_at'  => null,
			'expires_at'  => null,
		);
	}

	/**
	 * The public claim URL for a token.
	 *
	 * @param string $token Claim token.
	 * @return string
	 */
	public static function claim_url( $token ): string {
		return rest_url( 'splm/v1/waitlist/claim/' . rawurlencode( (string) $token ) );
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-league-manager/tests/test-waitlist-lifecycle.php`

Expected: `Passed: 57`, `Failed: 0`, exit 0.

- [ ] **Step 5: Add `offer()`, `cancel()` and the notification email**

Still in `class-waitlist.php`:

```php
	/**
	 * Offer a spot: token, deadline, cron, email — all under one lock.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int   $id    Row id.
	 * @param mixed $hours Requested window, or null for the default.
	 * @return array|WP_Error
	 */
	public static function offer( $id, $hours = null ) {
		$hours = self::validate_hours( $hours );
		if ( is_wp_error( $hours ) ) {
			return $hours;
		}

		$id = (int) $id;

		// One lock around the whole sequence so a double-clicked button cannot
		// issue two tokens or schedule two expiry events for one row.
		$result = SPAT_Lock::with(
			'splm_waitlist_offer_' . $id,
			60,
			static function () use ( $id, $hours ) {
				return self::offer_locked( $id, $hours );
			}
		);

		if ( false === $result ) {
			return new WP_Error(
				'splm_waitlist_locked',
				__( 'Another offer for this entry is in progress. Try again in a moment.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		return $result;
	}

	/**
	 * The offer sequence, already serialised by offer().
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $id    Row id.
	 * @param int $hours Validated window.
	 * @return array|WP_Error
	 */
	private static function offer_locked( $id, $hours ) {
		$row = SPLM_Waitlist_Database::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'splm_waitlist_not_found', __( 'Waitlist entry not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_offer( $row->status ) ) {
			return new WP_Error(
				'splm_waitlist_bad_status',
				__( 'Only a queued or expired entry can be offered a spot.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}
		if ( (int) $row->target_product_id <= 0 ) {
			// The row most likely to be offered by accident: ingestion could
			// not pair it with a real product, so there is nothing to send
			// the player to.
			return new WP_Error(
				'splm_waitlist_no_target',
				__( 'This entry has no registration product set. Choose one before offering the spot.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		// Unconditionally, before scheduling anything. A cancelled offer's
		// event survives the cancel; without this clear, a re-offer would have
		// two events pending and the older one would fire at the old deadline.
		wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( $id ) );

		$token  = self::generate_token();
		$expiry = SPLM_Waitlist_Database::expiry_from_hours( $hours );

		if ( ! SPLM_Waitlist_Database::update( $id, self::offer_updates( $token, $expiry ) ) ) {
			return new WP_Error( 'splm_waitlist_write_failed', __( 'Could not record the offer.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		wp_schedule_single_event( $expiry['timestamp'], self::EXPIRE_HOOK, array( $id ) );

		$fresh = SPLM_Waitlist_Database::get( $id );
		if ( ! self::send_offer_email( $fresh, $token ) ) {
			// A failed send would otherwise leave a ticking deadline on an
			// invite nobody received, and the person would silently lose their
			// turn. Unwind completely so a retry is clean.
			wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( $id ) );
			SPLM_Waitlist_Database::update( $id, self::unwind_updates() );

			return new WP_Error(
				'splm_waitlist_mail_failed',
				__( 'The offer email could not be sent, so the offer was cancelled. The entry is still queued — try again.', 'sportspress-league-manager' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success'    => true,
			'id'         => $id,
			'expires_at' => $expiry['expires_at'],
			'warnings'   => self::offer_warnings( (int) $fresh->target_product_id ),
		);
	}

	/**
	 * Non-blocking advisories to show beside the offer confirmation.
	 *
	 * @param int $product_id Target product id.
	 * @return array<int, array{code:string,message:string}>
	 */
	public static function offer_warnings( $product_id ): array {
		$warnings = array();

		if ( ! get_post_meta( (int) $product_id, '_splm_waitlist_gated', true ) ) {
			$warnings[] = array(
				'code'    => 'not_gated',
				'message' => __( 'This registration product is not gated, so anyone who finds its URL can buy the spot without an offer.', 'sportspress-league-manager' ),
			);
		}

		return $warnings;
	}

	/**
	 * Email the entrant their claim link.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object $row   Waitlist row.
	 * @param string $token Claim token.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public static function send_offer_email( $row, $token ): bool {
		$deadline = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $row->expires_at . ' UTC' )
		);

		$subject = sprintf(
			/* translators: %s: season code. */
			__( 'A %s registration spot is available for you', 'sportspress-league-manager' ),
			$row->season
		);

		$body = sprintf(
			/* translators: 1: entrant name, 2: season code, 3: local deadline, 4: claim URL. */
			__(
				"Hi %1\$s,\n\nA spot has opened up for %2\$s and it is being offered to you.\n\nClaim it by %3\$s:\n\n%4\$s\n\nIf you do not claim it by then, the spot will be offered to someone else.\n",
				'sportspress-league-manager'
			),
			$row->name ? $row->name : __( 'there', 'sportspress-league-manager' ),
			$row->season,
			$deadline,
			self::claim_url( $token )
		);

		$sent = wp_mail( $row->email, $subject, $body );

		if ( ! $sent && class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::error(
				'waitlist',
				'wp_mail() rejected a waitlist offer notification',
				array(
					'waitlist_id' => (int) $row->id,
					'season'      => (string) $row->season,
				)
			);
		}

		return (bool) $sent;
	}

	/**
	 * Cancel a live offer, or remove a queued entry from the queue.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $id Row id.
	 * @return array|WP_Error
	 */
	public static function cancel( $id ) {
		$id  = (int) $id;
		$row = SPLM_Waitlist_Database::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'splm_waitlist_not_found', __( 'Waitlist entry not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}
		if ( SPLM_Waitlist_Database::STATUS_CLAIMED === $row->status ) {
			return new WP_Error(
				'splm_waitlist_bad_status',
				__( 'A claimed entry cannot be cancelled. Reverse the order instead.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( $id ) );

		SPLM_Waitlist_Database::update(
			$id,
			array(
				'status'      => SPLM_Waitlist_Database::STATUS_CANCELLED,
				'claim_token' => null,
				'expires_at'  => null,
			)
		);

		return array(
			'success' => true,
			'id'      => $id,
		);
	}
```

- [ ] **Step 6: Verify offer, unwind and lock on staging**

```bash
# Offer a real row and read back what changed
docker exec -u www-data staging-wp wp eval \
  'print_r(SPLM_Waitlist::offer(1, 48));' --allow-root
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status,claim_token,offered_at,expires_at FROM wp_splm_waitlist WHERE id=1" --allow-root
docker exec -u www-data staging-wp wp cron event list --fields=hook,next_run_relative --allow-root | grep splm_waitlist

# Refusals
docker exec -u www-data staging-wp wp eval 'print_r(SPLM_Waitlist::offer(1, 0));' --allow-root
docker exec -u www-data staging-wp wp eval 'print_r(SPLM_Waitlist::offer(1, 48));' --allow-root

# The unwind: force wp_mail to fail, then confirm nothing is left behind
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist::cancel(1);
   SPLM_Waitlist_Database::update(1, ["status"=>"queued","claim_token"=>null]);
   add_filter("pre_wp_mail", function(){ return false; });
   print_r(SPLM_Waitlist::offer(1, 48));
   print_r(SPLM_Waitlist_Database::get(1));' --allow-root
docker exec -u www-data staging-wp wp cron event list --fields=hook --allow-root | grep splm_waitlist || echo "no pending expiry event — correct"
```

Expected: the first offer succeeds with a 64-char token, a UTC `expires_at`, and one scheduled event. `offer(1, 0)` returns `splm_invalid_hours`; a second offer on an already-offered row returns `splm_waitlist_bad_status`. The forced-failure run returns `splm_waitlist_mail_failed`, the row is back to `queued` with a null token, and **no expiry event remains**.

- [ ] **Step 7: Run the whole suite and commit**

Run: `./run-all-tests.sh` — all suites pass.

```bash
git add sportspress-league-manager/includes/class-waitlist.php \
        sportspress-league-manager/tests/test-waitlist-lifecycle.php
git commit -m "feat(waitlist): offer a spot with a timed claim link, and cancel one

Token, deadline, cron event and email all happen under one per-row SPAT_Lock,
so a double-clicked button cannot issue two tokens or schedule two expiry
events.

A failed wp_mail() unwinds the whole offer: the row goes back to queued, the
token is cleared so the undelivered link is dead, the cron event is removed
and the caller gets a 500. Logging the failure and carrying on would leave a
ticking 48-hour deadline on an invite nobody received, and the person would
silently lose their turn.

Every offer clears any pending expiry event before scheduling a new one. A
cancelled offer's event survives the cancel, so without that clear a re-offer
would have two events pending and the older would fire at the old deadline.

validate_hours() bounds the window to 1..720. A typo'd 0 would email an invite
that had already expired; an absurd value would create a permanent one.
Offering a row with no paired registration product is refused 409 — that is
the row most likely to be offered by accident."
```

---

### Task 8: Expiry — the defensive callback and the sweep

Clearing pending events (Task 7) is necessary but not sufficient: a cron event already in flight cannot be recalled. So the callback never trusts the fact that it fired.

**Files:**
- Modify: `sportspress-league-manager/includes/class-waitlist.php`
- Modify: `sportspress-league-manager/tests/test-waitlist-time.php` (append)

**Interfaces:**
- Consumes: `SPLM_Waitlist_Database::{get,update,is_past_due,past_due_offered,STATUS_*}` (Task 3), `SPLM_Waitlist::EXPIRE_HOOK` (Task 6).
- Produces:
  - `SPLM_Waitlist::should_expire( $status, $expires_at ): bool` — **pure**. The whole defence, in one testable predicate.
  - `SPLM_Waitlist::expire_offer( int $id ): bool` — the cron callback.
  - `SPLM_Waitlist::sweep( array $filters = array() ): int` — the backstop, returns rows expired.
  - Constructor now also registers `add_action( self::EXPIRE_HOOK, ... )`.

- [ ] **Step 1: Write the failing test**

Append to `sportspress-league-manager/tests/test-waitlist-time.php`, before the summary block. It needs the waitlist class, so add this require next to the existing one at the top of that file:

```php
require_once __DIR__ . '/../includes/class-waitlist.php';
```

and these stubs above it (the class references them at load time via `add_action`):

```php
function add_action() { // phpcs:ignore
	return true;
}

function add_filter() { // phpcs:ignore
	return true;
}

function get_option( $name, $default = false ) { // phpcs:ignore
	return $default;
}

function sanitize_text_field( $text ) {
	return trim( (string) $text );
}
```

Then the assertions:

```php
echo "\n=== should_expire(): the stale-event defence ===\n\n";

$w    = 'SPLM_Waitlist';
$past = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$soon = gmdate( 'Y-m-d H:i:s', time() + 3600 );

assert_test( $w::should_expire( 'offered', $past ), 'an offered row past its deadline expires' );

// THE bug this predicate exists to prevent. Cancel an offer, re-offer the
// same row a day later, and the FIRST cron event is still queued: it fires at
// the old deadline and would expire the brand-new offer. wp_clear_scheduled_hook
// cannot recall an event already in flight, so the callback must re-check.
assert_test( ! $w::should_expire( 'offered', $soon ), 'an offered row whose deadline is still in the future survives a stale event firing' );

assert_test( ! $w::should_expire( 'queued', $past ), 'a queued row is never expired, whatever a stale event says' );
assert_test( ! $w::should_expire( 'claimed', $past ), 'a claimed row is never expired — the player already paid' );
assert_test( ! $w::should_expire( 'cancelled', $past ), 'a cancelled row is not re-expired' );
assert_test( ! $w::should_expire( 'expired', $past ), 'an already-expired row is not expired twice' );
assert_test( ! $w::should_expire( 'offered', null ), 'an offered row with no deadline is not expired' );
assert_test( ! $w::should_expire( 'offered', '' ), 'an offered row with an empty deadline is not expired' );

// The boundary, stated explicitly: a deadline reached exactly now has passed.
assert_test( $w::should_expire( 'offered', gmdate( 'Y-m-d H:i:s' ) ), 'a deadline of exactly now has passed' );

// And the timezone trap: under America/Toronto, comparing a UTC-stored
// deadline against local time is a four to five hour error. A deadline two
// hours out must not look past due.
assert_test( ! $w::should_expire( 'offered', gmdate( 'Y-m-d H:i:s', time() + ( 2 * 3600 ) ) ), 'a deadline two hours out is not expired despite the site being UTC-4/5' );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-league-manager/tests/test-waitlist-time.php`

Expected: fatal — `Call to undefined method SPLM_Waitlist::should_expire()`.

- [ ] **Step 3: Write the implementation**

In `class-waitlist.php`, register the callback in the constructor, below the existing `add_action`:

```php
		add_action( self::EXPIRE_HOOK, array( __CLASS__, 'expire_offer' ) );
```

And add the methods:

```php
	/**
	 * Whether a row should be expired right now.
	 *
	 * Pure, and the whole defence against a stale cron event. Clearing pending
	 * events on cancel and re-offer is not sufficient: an event already in
	 * flight cannot be recalled, so if a convener cancels an offer and
	 * re-offers the same row a day later, the FIRST event still fires at the
	 * old deadline. Expiring on the mere fact of firing would kill the new
	 * offer. This re-reads the row's own state instead.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string      $status     Current status.
	 * @param string|null $expires_at Stored UTC deadline.
	 * @return bool
	 */
	public static function should_expire( $status, $expires_at ): bool {
		if ( SPLM_Waitlist_Database::STATUS_OFFERED !== (string) $status ) {
			return false;
		}
		return SPLM_Waitlist_Database::is_past_due( $expires_at );
	}

	/**
	 * Cron callback: expire one offer, if it really is due.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $id Row id.
	 * @return bool Whether the row was expired.
	 */
	public static function expire_offer( $id ): bool {
		$row = SPLM_Waitlist_Database::get( (int) $id );
		if ( ! $row || ! self::should_expire( $row->status, $row->expires_at ) ) {
			return false;
		}

		// The token is cleared so a link that arrives late cannot be claimed,
		// and so the UNIQUE index is free for the next offer on this row.
		return SPLM_Waitlist_Database::update(
			(int) $id,
			array(
				'status'      => SPLM_Waitlist_Database::STATUS_EXPIRED,
				'claim_token' => null,
			)
		);
	}

	/**
	 * Backstop: expire every past-due offer matching these filters.
	 *
	 * The scheduled event is the primary mechanism. This exists because
	 * WP-Cron's self-trigger is not reliable on every host — it has been
	 * observed failing to complete on this league's staging box — and a
	 * stalled cron would otherwise leave a row showing "offered" with a
	 * deadline in the past indefinitely.
	 *
	 * Bounded to the caller's own filters so a dashboard request only touches
	 * rows it was already asking about, and failures are swallowed: a sweep
	 * problem must never fail the read that triggered it.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param array $filters Optional 'season' and 'position'.
	 * @return int Rows expired.
	 */
	public static function sweep( array $filters = array() ): int {
		$expired = 0;

		try {
			foreach ( SPLM_Waitlist_Database::past_due_offered( $filters ) as $row ) {
				if ( self::expire_offer( (int) $row->id ) ) {
					wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( (int) $row->id ) );
					$expired++;
				}
			}
		} catch ( \Throwable $e ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				$context = array( 'filters' => $filters );
				if ( method_exists( 'SPAT_Logger', 'is_verbose' ) && SPAT_Logger::is_verbose() ) {
					$context['exception_msg'] = $e->getMessage();
				}
				SPAT_Logger::error( 'waitlist', 'waitlist sweep failed', $context );
			}
		}

		return $expired;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-league-manager/tests/test-waitlist-time.php`

Expected: `Passed: 30`, `Failed: 0`, exit 0.

- [ ] **Step 5: Verify the stale-event scenario on staging**

This is the scenario the predicate exists for, so exercise it for real rather than trusting the unit test:

```bash
# Offer with a very short window so an event is queued in the near future
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1, ["status"=>"queued","claim_token"=>null,"expires_at"=>null]);
   print_r(SPLM_Waitlist::offer(1, 1));' --allow-root

# Cancel it — the event remains pending, which is the whole problem
docker exec -u www-data staging-wp wp eval 'print_r(SPLM_Waitlist::cancel(1));' --allow-root

# Re-offer with a long window
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1, ["status"=>"queued"]);
   print_r(SPLM_Waitlist::offer(1, 72));' --allow-root

# Fire the expiry callback directly, as a stale event would
docker exec -u www-data staging-wp wp eval 'var_dump(SPLM_Waitlist::expire_offer(1));' --allow-root
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status,expires_at FROM wp_splm_waitlist WHERE id=1" --allow-root
```

Expected: `expire_offer()` returns `false` and the row is **still `offered`** with its 72-hour deadline intact. If it returns `true` and the status flips to `expired`, the defence is not working.

Then confirm the sweep does fire on a genuinely past-due row:

```bash
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1, ["status"=>"offered","expires_at"=>gmdate("Y-m-d H:i:s", time()-60)]);
   echo SPLM_Waitlist::sweep(), " expired\n";
   print_r(SPLM_Waitlist_Database::get(1));' --allow-root
```

Expected: `1 expired`, status `expired`, `claim_token` null.

- [ ] **Step 6: Run the whole suite and commit**

Run: `./run-all-tests.sh` — all suites pass.

```bash
git add sportspress-league-manager/includes/class-waitlist.php \
        sportspress-league-manager/tests/test-waitlist-time.php
git commit -m "feat(waitlist): expire offers defensively, with a sweep as backstop

should_expire() re-reads the row rather than trusting that a cron event
fired, which is the fix for a real sequence: cancel an offer, re-offer the
same row a day later, and the first event is still queued — it fires at the
old deadline and would expire the brand-new offer. wp_clear_scheduled_hook
cannot recall an event already in flight, so clearing on cancel and re-offer
is necessary but not sufficient. The predicate is the load-bearing guard.

sweep() is the backstop for WP-Cron's unreliable self-trigger, which has been
observed failing to complete on this league's staging box; without it a
stalled cron leaves a row showing offered with a deadline in the past
indefinitely. It is bounded to the caller's filters and swallows its own
failures, since a sweep problem must never fail the read that triggered it.

Expiring clears the token, so a link that arrives late cannot be claimed and
the UNIQUE index is free for the next offer on that row.

The time tests run under America/Toronto so a comparison against site-local
instead of UTC — a four to five hour error either way — fails them."
```

---

### Task 9: The claim route and cart binding

The public link a player clicks. It validates and redirects; it persists nothing. The `splm_wl` arg it carries is captured into cart item data on the frontend request so Task 10 can match an order back to an offer exactly.

**Files:**
- Create: `sportspress-league-manager/includes/class-waitlist-rest.php`
- Create: `sportspress-league-manager/tests/test-waitlist-claim.php`
- Modify: `sportspress-league-manager/includes/class-waitlist.php` (cart binding)
- Modify: `sportspress-league-manager/sportspress-league-manager.php` (instantiate the REST controller)
- Modify: `run-all-tests.sh`

**Interfaces:**
- Consumes: `SPLM_Waitlist_Database::find_by_token()` (Task 3), `SPLM_Waitlist::should_expire()` (Task 8).
- Produces:
  - `SPLM_Waitlist::CART_META_KEY` = `'_splm_waitlist_id'`, `SPLM_Waitlist::CLAIM_ARG` = `'splm_wl'`.
  - `SPLM_Waitlist::claim_state( ?object $row ): string` — **pure**. Returns `'valid'`, `'missing'`, `'expired'`, `'claimed'` or `'cancelled'`.
  - `SPLM_Waitlist::is_claimable( ?object $row ): bool` — pure convenience over `claim_state()`.
  - `SPLM_Waitlist::add_cart_item_data( array $data, $product_id ): array` — `woocommerce_add_cart_item_data` filter.
  - `SPLM_Waitlist::persist_cart_item_meta( $item, $key, $values ): void` — `woocommerce_checkout_create_order_line_item` action.
  - `SPLM_Waitlist_REST::add_to_cart_url( object $row, string $token ): string`
  - `new SPLM_Waitlist_REST()` — registers `GET splm/v1/waitlist/claim/(?P<token>[a-f0-9]{64})`.

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-waitlist-claim.php`:

```php
<?php
/**
 * Standalone tests for the claim link's validation.
 *
 * claim_state() decides whether a player who clicked an emailed link reaches
 * checkout or a dead end. Two of its properties are load-bearing and are
 * asserted here so a later refactor cannot quietly undo them: every failure
 * looks identical from outside (no oracle), and nothing about validating a
 * link changes any state (email security scanners prefetch links).
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

date_default_timezone_set( 'America/Toronto' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

function add_action() { // phpcs:ignore
	return true;
}

function add_filter() { // phpcs:ignore
	return true;
}

function get_option( $name, $default = false ) { // phpcs:ignore
	return $default;
}

function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

require_once __DIR__ . '/../includes/class-waitlist-database.php';
require_once __DIR__ . '/../includes/class-waitlist.php';

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

$w = 'SPLM_Waitlist';

function row( array $overrides = array() ) {
	return (object) array_merge(
		array(
			'id'                => 1,
			'status'            => 'offered',
			'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			'target_product_id' => 11,
			'email'             => 'player@example.com',
			'user_id'           => 0,
			'claim_token'       => str_repeat( 'a', 64 ),
		),
		$overrides
	);
}

echo "\n=== claim_state() ===\n\n";

assert_test( 'valid' === $w::claim_state( row() ), 'a live offer is claimable' );
assert_test( 'missing' === $w::claim_state( null ), 'an unknown token is missing' );
assert_test( 'expired' === $w::claim_state( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ) ), 'an offer past its deadline is expired' );
assert_test( 'claimed' === $w::claim_state( row( array( 'status' => 'claimed' ) ) ), 'an already-claimed offer reports claimed' );
assert_test( 'cancelled' === $w::claim_state( row( array( 'status' => 'cancelled' ) ) ), 'a cancelled offer reports cancelled' );
assert_test( 'expired' === $w::claim_state( row( array( 'status' => 'expired' ) ) ), 'a row already marked expired reports expired' );
assert_test( 'missing' === $w::claim_state( row( array( 'status' => 'queued' ) ) ), 'a queued row is not claimable — its token was cleared, so this is a stale link' );

// A live offer whose target product went missing cannot be claimed: there is
// nowhere to redirect to, and 0 would add-to-cart the wrong thing.
assert_test( 'missing' === $w::claim_state( row( array( 'target_product_id' => 0 ) ) ), 'an offer with no target product is not claimable' );

echo "\n=== is_claimable() ===\n\n";

assert_test( $w::is_claimable( row() ), 'a live offer is claimable' );
assert_test( ! $w::is_claimable( null ), 'an unknown token is not claimable' );
assert_test( ! $w::is_claimable( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ) ), 'a lapsed offer is not claimable' );

// The timezone trap again: a deadline two hours out must not read as lapsed
// on a site running four to five hours behind UTC.
assert_test( $w::is_claimable( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 2 * 3600 ) ) ) ) ), 'a deadline two hours out is still claimable under a non-UTC site timezone' );

echo "\n=== every failure looks the same from outside ===\n\n";

// Deliberately NOT an oracle. A caller must not be able to tell an unknown
// token from an expired or already-used one, and a later "more helpful error
// messages" pass must not make it possible.
$states   = array( 'missing', 'expired', 'claimed', 'cancelled' );
$messages = array();
foreach ( $states as $state ) {
	$messages[] = $w::claim_failure_message( $state );
}
assert_test( 1 === count( array_unique( $messages ) ), 'unknown, expired, claimed and cancelled all produce one identical message' );
assert_test( '' !== $messages[0], 'the message is not empty' );
assert_test( strpos( strtolower( $messages[0] ), 'expire' ) !== false, 'the shared message reads as an expiry, which is the common case' );

echo "\n=== cart item data binding ===\n\n";

$bound = $w::build_cart_item_data( array( 'existing' => 'kept' ), str_repeat( 'b', 64 ) );
assert_test( 'kept' === $bound['existing'], 'existing cart item data is preserved' );
assert_test( str_repeat( 'b', 64 ) === $bound[ $w::CART_META_KEY ], 'the token is bound to the cart item' );

$unbound = $w::build_cart_item_data( array( 'existing' => 'kept' ), '' );
assert_test( ! isset( $unbound[ $w::CART_META_KEY ] ), 'no token means no binding key, so an ordinary purchase is untouched' );

$bad = $w::build_cart_item_data( array(), 'not-a-token' );
assert_test( ! isset( $bad[ $w::CART_META_KEY ] ), 'a malformed token is not bound' );

echo "\n=== token shape guard ===\n\n";

assert_test( $w::is_token_shaped( str_repeat( 'a', 64 ) ), 'a 64-char lowercase hex string is token-shaped' );
assert_test( ! $w::is_token_shaped( str_repeat( 'a', 63 ) ), 'a short string is not' );
assert_test( ! $w::is_token_shaped( str_repeat( 'A', 64 ) ), 'uppercase is not, matching the route regex exactly' );
assert_test( ! $w::is_token_shaped( str_repeat( 'z', 64 ) ), 'non-hex characters are not' );
assert_test( ! $w::is_token_shaped( '' ), 'an empty string is not' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-league-manager/tests/test-waitlist-claim.php`

Expected: fatal — `Call to undefined method SPLM_Waitlist::claim_state()`.

- [ ] **Step 3: Add the claim predicates and cart binding to `SPLM_Waitlist`**

Add the constants below `MAX_HOURS`:

```php
	/**
	 * Query arg on the claim redirect, and the line item meta it becomes.
	 */
	const CLAIM_ARG    = 'splm_wl';
	const CART_META_KEY = '_splm_waitlist_id';
```

Register the cart hooks in the constructor:

```php
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_cart_item_meta' ), 10, 3 );
```

And the methods:

```php
	/**
	 * Whether a string has the shape of a claim token.
	 *
	 * Matches the REST route's own regex exactly — lowercase hex, 64 chars —
	 * so a malformed value is rejected before it reaches a query.
	 *
	 * @param string $token Candidate token.
	 * @return bool
	 */
	public static function is_token_shaped( $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $token );
	}

	/**
	 * What state a claim link is in.
	 *
	 * Pure. A queued row reports 'missing' rather than its own status: its
	 * token was cleared when the offer ended, so a link presenting one is
	 * stale, and saying so distinguishes nothing useful.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object|null $row Waitlist row, or null when the token is unknown.
	 * @return string 'valid'|'missing'|'expired'|'claimed'|'cancelled'
	 */
	public static function claim_state( $row ): string {
		if ( ! $row ) {
			return 'missing';
		}

		$status = (string) $row->status;

		if ( SPLM_Waitlist_Database::STATUS_CLAIMED === $status ) {
			return 'claimed';
		}
		if ( SPLM_Waitlist_Database::STATUS_CANCELLED === $status ) {
			return 'cancelled';
		}
		if ( SPLM_Waitlist_Database::STATUS_EXPIRED === $status ) {
			return 'expired';
		}
		if ( SPLM_Waitlist_Database::STATUS_OFFERED !== $status ) {
			return 'missing';
		}
		if ( SPLM_Waitlist_Database::is_past_due( $row->expires_at ) ) {
			return 'expired';
		}
		// A live offer with nowhere to send the player is not claimable;
		// redirecting to product 0 would add the wrong thing to their cart.
		if ( (int) $row->target_product_id <= 0 ) {
			return 'missing';
		}

		return 'valid';
	}

	/**
	 * Convenience over claim_state().
	 *
	 * @param object|null $row Waitlist row.
	 * @return bool
	 */
	public static function is_claimable( $row ): bool {
		return 'valid' === self::claim_state( $row );
	}

	/**
	 * The one message every claim failure produces.
	 *
	 * Deliberately identical for unknown, expired, claimed and cancelled. It
	 * is not an oracle, and a later pass adding "more helpful error messages"
	 * must not make it one. The state is still logged server-side.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param string $state Result of claim_state(); accepted for the caller's
	 *                      clarity and deliberately not branched on.
	 * @return string
	 */
	public static function claim_failure_message( $state ): string {
		return __( 'This invite has expired. Please contact your convener.', 'sportspress-league-manager' );
	}

	/**
	 * Cart item data for a token.
	 *
	 * Pure, so the binding rule is testable without a cart.
	 *
	 * @param array  $data  Existing cart item data.
	 * @param string $token Claim token from the request, or ''.
	 * @return array
	 */
	public static function build_cart_item_data( array $data, $token ): array {
		if ( ! self::is_token_shaped( $token ) ) {
			return $data;
		}
		$data[ self::CART_META_KEY ] = (string) $token;
		return $data;
	}

	/**
	 * Capture the claim token from an add-to-cart request.
	 *
	 * This is what makes the order tie-back exact rather than inferred: the
	 * token rides the cart item into the order as line item meta, so matching
	 * does not depend on the player checking out under the same email address
	 * their waitlist order used.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param array $data       Existing cart item data.
	 * @param int   $product_id Product being added; unused, the token is
	 *                          validated against the row at tie-back time.
	 * @return array
	 */
	public function add_cart_item_data( $data, $product_id ) {
		$token = isset( $_GET[ self::CLAIM_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::CLAIM_ARG ] ) ) : '';
		return self::build_cart_item_data( (array) $data, $token );
	}

	/**
	 * Persist the bound token onto the order line item.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param object $item   Order line item.
	 * @param string $key    Cart item key; unused.
	 * @param array  $values Cart item data.
	 * @return void
	 */
	public function persist_cart_item_meta( $item, $key, $values ) {
		if ( ! empty( $values[ self::CART_META_KEY ] ) ) {
			$item->add_meta_data( self::CART_META_KEY, (string) $values[ self::CART_META_KEY ], true );
		}
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-league-manager/tests/test-waitlist-claim.php`

Expected: `Passed: 24`, `Failed: 0`, exit 0.

- [ ] **Step 5: Create the REST controller with the claim route**

Create `sportspress-league-manager/includes/class-waitlist-rest.php`:

```php
<?php
/**
 * REST surface for the registration waitlist.
 *
 * The claim route is public — a player clicks it out of an email — and does
 * its own token validation, following the pattern sportspress-score-sheets
 * uses for unauthenticated intake. Unlike that route it cannot require an
 * HMAC signature, because a human with a link cannot compute one; a 32-byte
 * CSPRNG token in the URL is the appropriate substitute, and enumeration is
 * infeasible.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_REST {

	const REST_NAMESPACE = 'splm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/waitlist/claim/(?P<token>[a-f0-9]{64})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_claim' ),
				// Public by design: the token in the path IS the credential,
				// and handle_claim() validates it. A malformed token never
				// reaches a query — the route regex rejects it first.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( 'SPLM_Waitlist', 'is_token_shaped' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * The add-to-cart URL a valid claim redirects to.
	 *
	 * WooCommerce's own add-to-cart flow, so the resulting order is created by
	 * WooCommerce exactly as any other — no custom order construction, and no
	 * reimplementation of tax, coupon or stock handling.
	 *
	 * @param object $row   Waitlist row.
	 * @param string $token Claim token.
	 * @return string
	 */
	public static function add_to_cart_url( $row, $token ): string {
		$product_id = (int) $row->target_product_id;

		return add_query_arg(
			array(
				'add-to-cart'             => $product_id,
				SPLM_Waitlist::CLAIM_ARG => (string) $token,
			),
			get_permalink( $product_id )
		);
	}

	/**
	 * Validate a claim link and send the player onward.
	 *
	 * THIS ROUTE MUST REMAIN SIDE-EFFECT-FREE. Email security scanners —
	 * Outlook SafeLinks, Gmail, corporate mail gateways — prefetch links in
	 * messages. Marking a row claimed (or consuming its token) here would burn
	 * every invite before the player ever opened the mail. Validation and
	 * redirect only; the session entitlement is seeded on the frontend
	 * add-to-cart request that carries the token, not here.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_claim( $request ) {
		$token = (string) $request->get_param( 'token' );
		$row   = SPLM_Waitlist_Database::find_by_token( $token );
		$state = SPLM_Waitlist::claim_state( $row );

		if ( 'valid' !== $state ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::warn(
					'waitlist',
					'a claim link was rejected',
					array(
						'state'       => $state,
						'waitlist_id' => $row ? (int) $row->id : 0,
					)
				);
			}
			return $this->failure_response( $state );
		}

		return new WP_REST_Response(
			null,
			302,
			array( 'Location' => self::add_to_cart_url( $row, $token ) )
		);
	}

	/**
	 * A small HTML page for a dead link.
	 *
	 * HTML rather than JSON or a redirect: a dead claim link is opened
	 * directly in a browser by a player, not consumed by the dashboard.
	 *
	 * The body is a static translated string. Nothing from the token or the
	 * database is interpolated into it, so there is nothing to escape and no
	 * way for a crafted token to reach the output.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string $state Result of claim_state().
	 * @return WP_REST_Response
	 */
	private function failure_response( $state ): WP_REST_Response {
		$message = SPLM_Waitlist::claim_failure_message( $state );

		$html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex">'
			. '<title>' . esc_html__( 'Invite unavailable', 'sportspress-league-manager' ) . '</title>'
			. '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;padding:1.5rem;color:#1e1e1e;background:#f6f7f7}p{max-width:32rem;text-align:center}</style>'
			. '</head><body><p>' . esc_html( $message ) . '</p></body></html>';

		$response = new WP_REST_Response( $html, 200 );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		// A dead link must not be cached as though it were the live answer.
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
```

- [ ] **Step 6: Instantiate the controller**

In `sportspress-league-manager/sportspress-league-manager.php`, extend the `league_waitlist` block:

```php
		if ( in_array( 'league_waitlist', $enabled, true ) ) {
			SPLM_Waitlist_Database::maybe_upgrade();
			new SPLM_Waitlist();
			new SPLM_Waitlist_REST();
		}
```

- [ ] **Step 7: Register the test file**

In `run-all-tests.sh`:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-claim.php"
```

- [ ] **Step 8: Verify the route on staging**

```bash
# A live offer's token
TOKEN=$(docker exec -u www-data staging-wp wp db query \
  "SELECT claim_token FROM wp_splm_waitlist WHERE status='offered' LIMIT 1" --skip-column-names --allow-root)

# Valid: expect 302 with a Location carrying add-to-cart and splm_wl
curl -s -o /dev/null -D - "https://staging.example/wp-json/splm/v1/waitlist/claim/$TOKEN" | head -5

# Confirm it changed nothing — the row must still be `offered`
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status FROM wp_splm_waitlist WHERE claim_token='$TOKEN'" --allow-root

# Unknown token: expect 200 text/html with the shared message
curl -s -o /dev/null -D - "https://staging.example/wp-json/splm/v1/waitlist/claim/$(printf 'a%.0s' {1..64})" | head -5

# Malformed token: expect 404 from the route regex, never a query
curl -s -o /dev/null -w '%{http_code}\n' "https://staging.example/wp-json/splm/v1/waitlist/claim/nope"
```

Expected: `302` with the add-to-cart `Location`; the row **unchanged** afterwards (this is the prefetch-safety property); `200` with `Content-Type: text/html` for an unknown token; `404` for a malformed one.

Then verify the binding survives into an order: open the 302's `Location` in a browser, complete checkout, and check the line item meta:

```bash
docker exec -u www-data staging-wp wp eval \
  '$o = wc_get_order(ORDER_ID);
   foreach ($o->get_items() as $i) { var_dump($i->get_meta("_splm_waitlist_id")); }' --allow-root
```

Expected: the 64-char token on the line item.

- [ ] **Step 9: Run the whole suite and commit**

Run: `./run-all-tests.sh` — all suites pass.

```bash
git add sportspress-league-manager/includes/class-waitlist-rest.php \
        sportspress-league-manager/includes/class-waitlist.php \
        sportspress-league-manager/tests/test-waitlist-claim.php \
        sportspress-league-manager/sportspress-league-manager.php \
        run-all-tests.sh
git commit -m "feat(waitlist): add the public claim route and bind the token to the cart

A valid link 302s into WooCommerce's own add-to-cart flow, so the order is
created by WooCommerce exactly as any other — no custom order construction,
and no reimplementation of tax, coupon or stock handling.

Two properties are deliberate and are asserted rather than left to reviewer
vigilance:

The route is side-effect-free. Outlook SafeLinks, Gmail and corporate mail
gateways prefetch links in messages, so marking a row claimed or consuming
its token here would burn every invite before the player opened the mail.
Validation and redirect only.

Every failure — unknown token, expired, already claimed, cancelled — returns
one identical message. It is not an oracle, and the test asserts the messages
are indistinguishable so a later 'more helpful errors' pass cannot make it
one. The real state is logged server-side.

The splm_wl arg is captured into cart item data and persisted as line item
meta, which is what lets Task 10 match an order to an offer exactly instead
of guessing from the billing email — that guess breaks whenever someone
checks out under a different address than their waitlist order used.

A malformed token is rejected by the route regex before reaching a query, and
the dead-link page is a static translated string with nothing interpolated."
```

---

### Task 10: Order tie-back

Marks an offer claimed when the resulting order completes. Exact via the line item meta from Task 9; falls back to product plus email or user so a never-clicked link still resolves.

**Files:**
- Modify: `sportspress-league-manager/includes/class-waitlist.php`
- Modify: `sportspress-league-manager/tests/test-waitlist-claim.php` (append)

**Interfaces:**
- Consumes: `SPLM_Waitlist::CART_META_KEY` (Task 9), `SPLM_Waitlist_Database::{find_by_token,find_offered_for_product,get,update}` (Task 3).
- Produces:
  - `SPLM_Waitlist::match_offer( ?object $by_token, array $offered_for_product, string $email, int $user_id ): ?object` — **pure**. The whole resolution rule.
  - `SPLM_Waitlist::handle_order_completed( $order_id ): void` — `woocommerce_order_status_completed`.
  - `SPLM_Waitlist::mark_claimed( int $id, int $order_id ): bool`

- [ ] **Step 1: Write the failing test**

Append to `sportspress-league-manager/tests/test-waitlist-claim.php`, before the summary block:

```php
echo "\n=== match_offer(): the exact path ===\n\n";

$token_row = row( array( 'id' => 5, 'email' => 'queued@example.com' ) );

// The token came off the order's own line item, so it is authoritative: the
// email is not consulted at all. This is what makes a shared or changed
// billing address a non-issue.
assert_test( 5 === $w::match_offer( $token_row, array(), 'someone-else@example.com', 0 )->id, 'a line item token wins outright, whatever email the order used' );
assert_test( 5 === $w::match_offer( $token_row, array(), '', 0 )->id, 'a line item token resolves even with no billing email' );

// But a token pointing at a row that is no longer offerable must not resolve.
$stale_token_row = row( array( 'id' => 5, 'status' => 'claimed' ) );
assert_test( null === $w::match_offer( $stale_token_row, array(), 'player@example.com', 0 ), 'a token for an already-claimed row does not re-resolve' );

$lapsed_token_row = row( array( 'id' => 5, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
assert_test( null === $w::match_offer( $lapsed_token_row, array(), 'player@example.com', 0 ), 'a token for a lapsed offer does not resolve' );

echo "\n=== match_offer(): the fallback path ===\n\n";

$offered = array(
	row( array( 'id' => 7, 'email' => 'player@example.com', 'user_id' => 0 ) ),
	row( array( 'id' => 8, 'email' => 'other@example.com', 'user_id' => 42 ) ),
);

assert_test( 7 === $w::match_offer( null, $offered, 'player@example.com', 0 )->id, 'with no token, a matching billing email resolves' );
assert_test( 7 === $w::match_offer( null, $offered, 'PLAYER@Example.COM', 0 )->id, 'the email match is case-insensitive' );
assert_test( 8 === $w::match_offer( null, $offered, 'unrelated@example.com', 42 )->id, 'a matching user id resolves when the email does not' );
assert_test( null === $w::match_offer( null, $offered, 'nobody@example.com', 0 )->id ?? null, 'no match on either signal resolves nothing' );
assert_test( null === $w::match_offer( null, array(), 'player@example.com', 0 ), 'no offered rows for the product resolves nothing' );

// user_id 0 must never match a guest row's 0 — every guest would collide.
$guest_offered = array( row( array( 'id' => 9, 'email' => 'someone@example.com', 'user_id' => 0 ) ) );
assert_test( null === $w::match_offer( null, $guest_offered, 'different@example.com', 0 ), 'a guest order does not match a guest row by user_id 0' );

// An empty billing email must not match a row with an empty email either.
$blank_offered = array( row( array( 'id' => 10, 'email' => '', 'user_id' => 0 ) ) );
assert_test( null === $w::match_offer( null, $blank_offered, '', 0 ), 'two empty emails are not a match' );

echo "\n=== match_offer(): ambiguity ===\n\n";

// Two live offers for the same product and the same person should not happen —
// find_active() prevents it at ingestion — but if it does, resolve the oldest
// rather than picking arbitrarily, so the behaviour is at least deterministic.
$dupes = array(
	row( array( 'id' => 12, 'email' => 'player@example.com' ) ),
	row( array( 'id' => 11, 'email' => 'player@example.com' ) ),
);
assert_test( 11 === $w::match_offer( null, $dupes, 'player@example.com', 0 )->id, 'duplicate live offers resolve the lowest id deterministically' );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-league-manager/tests/test-waitlist-claim.php`

Expected: fatal — `Call to undefined method SPLM_Waitlist::match_offer()`.

- [ ] **Step 3: Write the implementation**

Register the hook in the `SPLM_Waitlist` constructor:

```php
		// A separate subscriber to the same event SPPR_Player_Registration
		// listens on. Neither knows about the other; both just react to a
		// completed order.
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ) );
```

And the methods:

```php
	/**
	 * Which offer, if any, a completed order fulfils.
	 *
	 * Pure. Two paths, strongest signal first:
	 *
	 * 1. The claim token carried on the order's own line item. Authoritative —
	 *    the email is not consulted at all, which is what makes a shared or
	 *    changed billing address a non-issue.
	 * 2. Product plus email or user id. This is the never-clicked-the-link
	 *    case: a forwarded email that lost the link, or a player who reached
	 *    the product some other way. It is a fallback precisely because it
	 *    guesses, and the guess fails whenever someone checks out under a
	 *    different address than their waitlist order used.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object|null $by_token            Row found by line item token.
	 * @param object[]    $offered_for_product Offered rows for the purchased product.
	 * @param string      $email               Order billing email.
	 * @param int         $user_id             Order customer id.
	 * @return object|null
	 */
	public static function match_offer( $by_token, array $offered_for_product, $email, $user_id ) {
		if ( $by_token && self::is_claimable( $by_token ) ) {
			return $by_token;
		}

		$email   = strtolower( trim( (string) $email ) );
		$user_id = (int) $user_id;

		$matches = array();
		foreach ( $offered_for_product as $row ) {
			if ( ! self::is_claimable( $row ) ) {
				continue;
			}

			$row_email = strtolower( trim( (string) $row->email ) );
			$row_user  = (int) $row->user_id;

			// Both guards matter: an empty billing email must not match a row
			// with an empty email, and user_id 0 must not match a guest row's
			// 0 — otherwise every guest checkout would collide with every
			// guest entry.
			$email_hit = ( '' !== $email && $row_email === $email );
			$user_hit  = ( $user_id > 0 && $row_user === $user_id );

			if ( $email_hit || $user_hit ) {
				$matches[ (int) $row->id ] = $row;
			}
		}

		if ( empty( $matches ) ) {
			return null;
		}

		// find_active() should make duplicates impossible, but if two live
		// offers exist for one person, resolve the oldest so the outcome is
		// deterministic rather than dependent on row order.
		ksort( $matches );
		return reset( $matches );
	}

	/**
	 * Mark an offer claimed and stand down its expiry.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $id       Row id.
	 * @param int $order_id Fulfilling order id.
	 * @return bool
	 */
	public static function mark_claimed( $id, $order_id ): bool {
		$id = (int) $id;

		wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( $id ) );

		return SPLM_Waitlist_Database::update(
			$id,
			array(
				'status'            => SPLM_Waitlist_Database::STATUS_CLAIMED,
				'resolved_order_id' => (int) $order_id,
				// Cleared so the link cannot be replayed and the UNIQUE index
				// is free if this person is ever queued again.
				'claim_token'       => null,
			)
		);
	}

	/**
	 * Resolve offers fulfilled by a completed order.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function handle_order_completed( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email   = strtolower( sanitize_email( (string) $order->get_billing_email() ) );
		$user_id = (int) $order->get_user_id();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$token    = (string) $item->get_meta( self::CART_META_KEY );
			$by_token = self::is_token_shaped( $token )
				? SPLM_Waitlist_Database::find_by_token( $token )
				: null;

			$product_id = (int) $product->get_id();
			$offered    = SPLM_Waitlist_Database::find_offered_for_product( $product_id );

			// A variation is purchased, but the waitlist stored the parent.
			if ( empty( $offered ) && $product->get_type() === 'variation' ) {
				$offered = SPLM_Waitlist_Database::find_offered_for_product( (int) $product->get_parent_id() );
			}

			$match = self::match_offer( $by_token, $offered, $email, $user_id );
			if ( ! $match ) {
				continue;
			}

			self::mark_claimed( (int) $match->id, (int) $order->get_id() );

			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::info(
					'waitlist',
					'a waitlist offer was claimed',
					array(
						'waitlist_id' => (int) $match->id,
						'order_id'    => (int) $order->get_id(),
						'matched_by'  => ( $by_token && self::is_claimable( $by_token ) ) ? 'token' : 'email_or_user',
					)
				);
			}
		}
	}
```

If `SPAT_Logger::info()` does not exist, use `SPAT_Logger::warn()` — check the class before writing this line and match whatever level methods it actually exposes.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-league-manager/tests/test-waitlist-claim.php`

Expected: `Passed: 39`, `Failed: 0`, exit 0.

- [ ] **Step 5: Verify both paths on staging**

```bash
# Path 1 — the token path. Complete a checkout reached through a claim link.
docker exec -u www-data staging-wp wp eval \
  '$o = wc_get_order(ORDER_ID);
   foreach ($o->get_items() as $i) { echo "line item token: ", $i->get_meta("_splm_waitlist_id"), "\n"; }' --allow-root
docker exec -u www-data staging-wp wp eval '$o = wc_get_order(ORDER_ID); $o->update_status("completed");' --allow-root
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status,resolved_order_id,claim_token FROM wp_splm_waitlist WHERE source_order_id IS NOT NULL" --allow-root

# Path 2 — the fallback. Offer a row, then buy the product directly with no
# claim link at all, using the same billing email.
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Database::update(1,["status"=>"queued","claim_token"=>null]);
   print_r(SPLM_Waitlist::offer(1,48));' --allow-root
# ...place an order for the target product in a browser, no splm_wl arg...
docker exec -u www-data staging-wp wp db query \
  "SELECT id,status,resolved_order_id FROM wp_splm_waitlist WHERE id=1" --allow-root

# Confirm the expiry event was stood down in both cases
docker exec -u www-data staging-wp wp cron event list --fields=hook --allow-root | grep splm_waitlist || echo "no pending expiry event — correct"
```

Expected: both paths land `status=claimed` with `resolved_order_id` set and `claim_token` null, and no expiry event remains. The log line's `matched_by` should read `token` for the first and `email_or_user` for the second.

- [ ] **Step 6: Run the whole suite and commit**

Run: `./run-all-tests.sh` — all suites pass.

```bash
git add sportspress-league-manager/includes/class-waitlist.php \
        sportspress-league-manager/tests/test-waitlist-claim.php
git commit -m "feat(waitlist): resolve an offer when its order completes

Two paths, strongest signal first. The claim token on the order's own line
item is authoritative and the billing email is not consulted at all — which
is the point, because matching on email alone breaks whenever someone checks
out under a different address than their waitlist order used, and shared or
family addresses make that common.

Product plus email or user id remains as a fallback for the case the token
cannot cover: a forwarded email that lost the link, or a player who reached
the product some other way. It guesses, which is why it is second.

Two guards that look redundant and are not: an empty billing email must not
match a row with an empty email, and user_id 0 must not match a guest row's
0 — without those, every guest checkout would collide with every guest entry.

Duplicate live offers for one person should be impossible (find_active()
prevents them at ingestion) but resolve the lowest id if they occur, so the
outcome is deterministic rather than row-order dependent.

This is a separate subscriber to the same woocommerce_order_status_completed
event SPPR_Player_Registration listens on. Neither knows about the other."
```

---

## Phase 4 — Purchase gate

> **This is the riskiest task in the plan.** A bug here makes a live registration product silently unbuyable, which to a player is indistinguishable from a broken site. Verify on staging with the toggle **off** first, confirm normal purchase is untouched, and only then turn it on.

### Task 11: `SPLM_Waitlist_Gate`

Catalog visibility and post passwords both failed as mitigations — "Hidden" only adds product-visibility terms that WooCommerce applies to its own queries (no `noindex`, unreliable sitemap coverage, ignored by search plugins), and a post password gates the product *page* while `?add-to-cart={id}` on `wp_loaded` never consults it. So gating happens at the purchase.

**Files:**
- Create: `sportspress-league-manager/includes/class-waitlist-gate.php`
- Create: `sportspress-league-manager/tests/test-waitlist-gate.php`
- Modify: `sportspress-league-manager/sportspress-league-manager.php`
- Modify: `run-all-tests.sh`

**Interfaces:**
- Consumes: `SPLM_Waitlist::{is_token_shaped,is_claimable,CLAIM_ARG}` (Task 9), `SPLM_Waitlist_Database::find_by_token()` (Task 3), `SPLM_Capabilities::can_manage()`.
- Produces:
  - `SPLM_Waitlist_Gate::GATE_META` = `'_splm_waitlist_gated'`, `SESSION_KEY` = `'splm_waitlist_entitlements'`.
  - `SPLM_Waitlist_Gate::decide( bool $incoming, bool $gated, bool $is_manager, bool $entitled ): bool` — **pure**. The whole rule.
  - `SPLM_Waitlist_Gate::is_gated( int $product_id ): bool`
  - `SPLM_Waitlist_Gate::set_gated( int $product_id, bool $gated ): bool`
  - `SPLM_Waitlist_Gate::filter_is_purchasable( $purchasable, $product )`
  - `SPLM_Waitlist_Gate::seed_entitlement(): void` — `wp_loaded`, priority 5.
  - `SPLM_Waitlist_Gate::check_cart_items(): void` — replaces WooCommerce's message when an offer lapsed mid-checkout.

- [ ] **Step 1: Write the failing test**

Create `sportspress-league-manager/tests/test-waitlist-gate.php`:

```php
<?php
/**
 * Standalone tests for the purchase gate's decision.
 *
 * decide() runs inside woocommerce_is_purchasable, which WooCommerce calls
 * for every product in every loop. A wrong answer either makes a live
 * registration product unbuyable — indistinguishable from a broken site — or
 * leaves the waitlist unenforced. Every combination is enumerated here.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

function add_action() { // phpcs:ignore
	return true;
}

function add_filter() { // phpcs:ignore
	return true;
}

require_once __DIR__ . '/../includes/class-waitlist-gate.php';

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

$g = 'SPLM_Waitlist_Gate';

echo "\n=== decide(): an ungated product is never touched ===\n\n";

// The overwhelmingly common case. Every product in the store that has nothing
// to do with the waitlist must come back exactly as it went in.
assert_test( true === $g::decide( true, false, false, false ), 'a purchasable ungated product stays purchasable' );
assert_test( false === $g::decide( false, false, false, false ), 'an unpurchasable ungated product stays unpurchasable' );
assert_test( true === $g::decide( true, false, true, true ), 'an ungated product is unaffected by manager status or entitlement' );

echo "\n=== decide(): the gate never resurrects an unpurchasable product ===\n\n";

// WooCommerce may have already said no for its own reasons — out of stock,
// price missing, draft. The gate only ever subtracts.
assert_test( false === $g::decide( false, true, false, true ), 'an entitled visitor cannot buy a product WooCommerce already refused' );
assert_test( false === $g::decide( false, true, true, true ), 'not even a manager can buy a product WooCommerce already refused' );

echo "\n=== decide(): gated products ===\n\n";

assert_test( false === $g::decide( true, true, false, false ), 'a visitor with no entitlement cannot buy a gated product' );
assert_test( true === $g::decide( true, true, false, true ), 'a visitor holding an entitlement can buy a gated product' );

// Managers bypass, so manual order creation in wp-admin and a convener's own
// testing are unaffected.
assert_test( true === $g::decide( true, true, true, false ), 'a manager can buy a gated product with no entitlement' );
assert_test( true === $g::decide( true, true, true, true ), 'a manager with an entitlement can buy a gated product' );

echo "\n=== normalise_entitlements() ===\n\n";

assert_test( array() === $g::normalise_entitlements( null ), 'a null session value yields no entitlements' );
assert_test( array() === $g::normalise_entitlements( '' ), 'an empty session value yields no entitlements' );
assert_test( array() === $g::normalise_entitlements( 'garbage' ), 'a non-array session value yields no entitlements' );
assert_test( array( 11, 12 ) === $g::normalise_entitlements( array( 11, 12 ) ), 'a list of ids is preserved' );
assert_test( array( 11 ) === $g::normalise_entitlements( array( '11', 11 ) ), 'ids are cast to int and deduplicated' );
assert_test( array() === $g::normalise_entitlements( array( 0, -1, 'x' ) ), 'non-positive and non-numeric ids are dropped' );

echo "\n=== entitles() ===\n\n";

assert_test( $g::entitles( array( 11, 12 ), 11 ), 'an id present in the list entitles that product' );
assert_test( ! $g::entitles( array( 11, 12 ), 13 ), 'an id absent from the list does not' );
assert_test( ! $g::entitles( array(), 11 ), 'an empty list entitles nothing' );
assert_test( ! $g::entitles( array( 11 ), 0 ), 'product 0 is never entitled' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-league-manager/tests/test-waitlist-gate.php`

Expected: fatal — `Failed opening required '.../includes/class-waitlist-gate.php'`.

- [ ] **Step 3: Write the implementation**

Create `sportspress-league-manager/includes/class-waitlist-gate.php`:

```php
<?php
/**
 * Purchase gating for waitlisted registration products.
 *
 * WHY THIS EXISTS. The claim link is not access control on its own: it
 * redirects to a normal product URL, so anyone reaching that URL by another
 * route could buy the spot. Two mitigations were tried in the manual process
 * and neither holds:
 *
 *   - "Catalog visibility: Hidden" only adds the exclude-from-catalog and
 *     exclude-from-search product-visibility terms, which WooCommerce applies
 *     to its own queries. It sets no noindex, does not reliably cover core
 *     sitemaps, and search plugins that build their own queries ignore it. The
 *     league has observed hidden products surfacing in search.
 *   - A post password gates the product PAGE (WooCommerce's
 *     content-single-product.php checks post_password_required) but not the
 *     purchase: ?add-to-cart={id} is handled by
 *     WC_Form_Handler::add_to_cart_action() on wp_loaded, which never consults
 *     the password.
 *
 * So the gate sits on the purchase itself, where discovery stops mattering.
 *
 * IT FAILS OPEN. Disabling the module or deactivating the plugin unhooks this
 * filter and every gated product becomes publicly purchasable again — the
 * meta is inert without the code that reads it. That is the right default: a
 * broken plugin must not leave a store unable to sell anything.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Gate {

	const GATE_META   = '_splm_waitlist_gated';
	const SESSION_KEY = 'splm_waitlist_entitlements';

	public function __construct() {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 10, 2 );

		// Priority 5, ahead of WC_Form_Handler::add_to_cart_action() at 20, so
		// the session is seeded before the cart consults is_purchasable().
		// filter_is_purchasable() also accepts a token straight off the
		// request, so this ordering is belt-and-braces rather than load-bearing.
		add_action( 'wp_loaded', array( $this, 'seed_entitlement' ), 5 );

		// WC_Cart::check_cart_items() re-runs is_purchasable() on every
		// checkout load. When an offer lapses mid-checkout the item leaves the
		// cart, and WooCommerce's default wording ("Sorry, this product cannot
		// be purchased") tells the player nothing.
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ) );
	}

	/**
	 * The gate's entire rule.
	 *
	 * Pure. Note it only ever subtracts: a product WooCommerce already refused
	 * stays refused, because it may be out of stock or unpriced for reasons
	 * that have nothing to do with the waitlist.
	 *
	 * @param bool $incoming   What WooCommerce decided before this filter.
	 * @param bool $gated      Whether this product is waitlist-gated.
	 * @param bool $is_manager Whether the current user manages the league.
	 * @param bool $entitled   Whether the visitor holds a live offer for it.
	 * @return bool
	 */
	public static function decide( $incoming, $gated, $is_manager, $entitled ): bool {
		if ( ! $incoming ) {
			return false;
		}
		if ( ! $gated ) {
			return true;
		}
		return (bool) ( $is_manager || $entitled );
	}

	/**
	 * Coerce a session value into a clean list of product ids.
	 *
	 * The session is client-influenced storage, so nothing about its shape is
	 * assumed.
	 *
	 * @param mixed $raw Session value.
	 * @return int[]
	 */
	public static function normalise_entitlements( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			if ( ! is_numeric( $id ) ) {
				continue;
			}
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Whether a list of entitlements covers a product.
	 *
	 * @param int[] $ids        Entitled product ids.
	 * @param int   $product_id Product to check.
	 * @return bool
	 */
	public static function entitles( array $ids, $product_id ): bool {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return false;
		}
		return in_array( $product_id, $ids, true );
	}

	/**
	 * Whether a product is waitlist-gated.
	 *
	 * A post meta read, deliberately: is_purchasable() runs for every product
	 * in every loop, and this value is object-cached alongside the post, so
	 * the overwhelmingly common "not gated" answer costs no query. The
	 * waitlist table is never consulted from the filter path.
	 *
	 * @param int $product_id Product post ID.
	 * @return bool
	 */
	public static function is_gated( $product_id ): bool {
		return (bool) get_post_meta( (int) $product_id, self::GATE_META, true );
	}

	/**
	 * Turn gating on or off for a product.
	 *
	 * @param int  $product_id Product post ID.
	 * @param bool $gated      Desired state.
	 * @return bool
	 */
	public static function set_gated( $product_id, $gated ): bool {
		$product_id = (int) $product_id;

		if ( $gated ) {
			return (bool) update_post_meta( $product_id, self::GATE_META, '1' );
		}
		return (bool) delete_post_meta( $product_id, self::GATE_META );
	}

	/**
	 * Product ids the current visitor holds a live offer for.
	 *
	 * WC()->session is null in REST and cron contexts, so every read is
	 * guarded.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return int[]
	 */
	public static function entitlement_ids(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->session ) || ! WC()->session ) {
			return array();
		}
		return self::normalise_entitlements( WC()->session->get( self::SESSION_KEY ) );
	}

	/**
	 * Record an entitlement in the session.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $product_id Product post ID.
	 * @return void
	 */
	public static function grant( $product_id ): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->session ) || ! WC()->session ) {
			return;
		}

		$ids = self::entitlement_ids();
		if ( ! self::entitles( $ids, $product_id ) ) {
			$ids[] = (int) $product_id;
			WC()->session->set( self::SESSION_KEY, $ids );
		}
	}

	/**
	 * The product a request-borne token entitles, if any.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.Superglobals)
	 *
	 * @return int Product id, or 0.
	 */
	public static function product_from_request_token(): int {
		if ( ! isset( $_GET[ SPLM_Waitlist::CLAIM_ARG ] ) ) {
			return 0;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ SPLM_Waitlist::CLAIM_ARG ] ) );
		if ( ! SPLM_Waitlist::is_token_shaped( $token ) ) {
			return 0;
		}

		$row = SPLM_Waitlist_Database::find_by_token( $token );
		if ( ! $row || ! SPLM_Waitlist::is_claimable( $row ) ) {
			return 0;
		}

		return (int) $row->target_product_id;
	}

	/**
	 * Seed the session from a claim token on this request.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return void
	 */
	public function seed_entitlement(): void {
		$product_id = self::product_from_request_token();
		if ( $product_id > 0 ) {
			self::grant( $product_id );
		}
	}

	/**
	 * Gate a product's purchasability.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param bool   $purchasable WooCommerce's decision so far.
	 * @param object $product     Product object.
	 * @return bool
	 */
	public function filter_is_purchasable( $purchasable, $product ) {
		// Cheapest possible exit for the common case, before anything else.
		if ( ! $purchasable || ! $product ) {
			return $purchasable;
		}

		$product_id = (int) $product->get_id();
		$gated      = self::is_gated( $product_id );

		// A variation inherits its parent's gate.
		if ( ! $gated && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$gated = self::is_gated( (int) $product->get_parent_id() );
		}

		if ( ! $gated ) {
			return $purchasable;
		}

		$is_manager = class_exists( 'SPLM_Capabilities' ) && SPLM_Capabilities::can_manage();

		$entitled = self::entitles( self::entitlement_ids(), $product_id )
			|| self::product_from_request_token() === $product_id;

		return self::decide( true, true, $is_manager, $entitled );
	}

	/**
	 * Explain a gated item disappearing from the cart.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return void
	 */
	public function check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->cart ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product || ! self::is_gated( (int) $product->get_id() ) ) {
				continue;
			}
			if ( $product->is_purchasable() ) {
				continue;
			}

			wc_add_notice(
				__( 'Your invite for this registration has expired, so it was removed from your cart. Please contact your convener.', 'sportspress-league-manager' ),
				'error'
			);
			return;
		}
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-league-manager/tests/test-waitlist-gate.php`

Expected: `Passed: 23`, `Failed: 0`, exit 0.

- [ ] **Step 5: Instantiate the gate**

In `sportspress-league-manager/sportspress-league-manager.php`:

```php
		if ( in_array( 'league_waitlist', $enabled, true ) ) {
			SPLM_Waitlist_Database::maybe_upgrade();
			new SPLM_Waitlist();
			new SPLM_Waitlist_Gate();
			new SPLM_Waitlist_REST();
		}
```

- [ ] **Step 6: Register the test file**

In `run-all-tests.sh`:

```bash
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-gate.php"
```

- [ ] **Step 7: Verify with the gate OFF first**

Do this before turning any gate on. The failure being guarded against is a store that cannot sell anything.

```bash
# No product is gated yet
docker exec -u www-data staging-wp wp db query \
  "SELECT post_id FROM wp_postmeta WHERE meta_key='_splm_waitlist_gated'" --allow-root

# Every product must still be purchasable
docker exec -u www-data staging-wp wp eval \
  '$ids = get_posts(["post_type"=>"product","post_status"=>"publish","posts_per_page"=>20,"fields"=>"ids"]);
   foreach ($ids as $id) { $p = wc_get_product($id); printf("%d %s %s\n", $id, $p->is_purchasable() ? "YES" : "NO", $p->get_name()); }' --allow-root
```

Expected: identical output to before this task landed. Any product flipping to `NO` is a bug in the cheap-exit path — stop and fix it before continuing.

- [ ] **Step 8: Verify with the gate ON**

```bash
# Gate the target product
docker exec -u www-data staging-wp wp eval \
  'var_dump(SPLM_Waitlist_Gate::set_gated(TARGET_PRODUCT_ID, true));' --allow-root

# Anonymous / non-manager context: must be unpurchasable
docker exec -u www-data staging-wp wp eval \
  '$p = wc_get_product(TARGET_PRODUCT_ID); var_dump($p->is_purchasable());' --allow-root

# Every OTHER product must be unaffected
docker exec -u www-data staging-wp wp eval \
  '$ids = get_posts(["post_type"=>"product","post_status"=>"publish","posts_per_page"=>20,"fields"=>"ids"]);
   foreach ($ids as $id) { $p = wc_get_product($id); printf("%d %s\n", $id, $p->is_purchasable() ? "YES" : "NO"); }' --allow-root
```

Then in a browser, logged out:

1. Visit the gated product directly — the add-to-cart button must be gone.
2. Visit the claim link for a live offer — the 302 must land you in the cart with the item present.
3. Proceed to checkout and confirm the item **stays** in the cart (this is the `check_cart_items()` re-run; a session-less implementation loses it here).
4. Complete the order, and confirm the row goes `claimed` (Task 10).
5. Expire the offer manually mid-checkout, reload checkout, and confirm the item leaves the cart with the "your invite has expired" notice rather than WooCommerce's default wording.

Finally, un-gate and confirm the product is publicly purchasable again:

```bash
docker exec -u www-data staging-wp wp eval \
  'SPLM_Waitlist_Gate::set_gated(TARGET_PRODUCT_ID, false);
   $p = wc_get_product(TARGET_PRODUCT_ID); var_dump($p->is_purchasable());' --allow-root
```

- [ ] **Step 9: Run the whole suite and commit**

Run: `./run-all-tests.sh` — all suites pass.

```bash
git add sportspress-league-manager/includes/class-waitlist-gate.php \
        sportspress-league-manager/tests/test-waitlist-gate.php \
        sportspress-league-manager/sportspress-league-manager.php \
        run-all-tests.sh
git commit -m "feat(waitlist): gate purchase of waitlisted products on holding an offer

Catalog visibility and post passwords both failed as mitigations. Hidden only
adds the exclude-from-catalog/exclude-from-search terms WooCommerce applies to
its own queries — no noindex, unreliable sitemap coverage, and search plugins
that build their own queries ignore it; the league has seen hidden products in
search. A post password gates the product page (content-single-product.php
does check post_password_required) but not ?add-to-cart={id}, which
WC_Form_Handler handles on wp_loaded without ever consulting it.

So the gate sits on the purchase, where discovery stops mattering.

The parts that make it non-trivial:

is_purchasable() runs for every product in every loop, so the filter exits on
a _splm_waitlist_gated post meta read — object-cached with the post — and
never queries the waitlist table.

Entitlement lives in the WC session, because check_cart_items() re-runs
is_purchasable() on every checkout load; a URL-only check would admit the item
to the cart and then drop it at checkout with WooCommerce's unhelpful wording.
A request-borne token is accepted too, so the filter does not depend on
winning a priority race against add_to_cart_action() at wp_loaded 20.

decide() only ever subtracts: a product WooCommerce already refused stays
refused, since it may be out of stock or unpriced for unrelated reasons.
Managers bypass so manual order creation still works, WC()->session is
null-guarded for REST and cron, and variations inherit the parent's gate.

The gate fails open when the module is disabled — the right default, since a
broken plugin must not leave a store unable to sell anything."
```

---

## Phase 5 — Admin surface

### Task 12: Admin REST routes

The five routes the dashboard calls. Adds to the controller created in Task 9.

**Files:**
- Modify: `sportspress-league-manager/includes/class-waitlist-rest.php`
- Modify: `sportspress-league-manager/tests/test-waitlist-lifecycle.php` (append)

**Interfaces:**
- Consumes: `SPLM_Waitlist::{offer,cancel,sweep,build_row}` (Tasks 6–8), `SPLM_Waitlist_Database::{query,insert,target_product_ids,statuses,find_active}` (Task 3), `SPLM_Waitlist_Gate::{set_gated,is_gated}` (Task 11), `SPLM_Capabilities::can_manage()`, `splm_rest_list_response()`.
- Produces:
  - `SPLM_Waitlist_REST::can_manage(): bool` — permission callback.
  - `SPLM_Waitlist_REST::row_to_response( object $row ): array`
  - `SPLM_Waitlist_REST::validate_position( $value ): bool`, `::validate_status( $value ): bool`, `::validate_hours( $value ): bool`, `::validate_target_product( $value ): bool`
  - Routes: `GET /waitlist`, `POST /waitlist`, `POST /waitlist/{id}/offer`, `POST /waitlist/{id}/cancel`, `POST /waitlist/gate`.

- [ ] **Step 1: Write the failing test**

Append to `sportspress-league-manager/tests/test-waitlist-lifecycle.php`, before the summary. Add this require at the top of the file, after the existing ones:

```php
require_once __DIR__ . '/../includes/class-waitlist-rest.php';
```

and these stubs alongside the others:

```php
function register_rest_route() { // phpcs:ignore
	return true;
}

function esc_html__( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

function esc_html( $text ) {
	return $text;
}
```

Then the assertions:

```php
echo "\n=== REST arg validation ===\n\n";

$r = 'SPLM_Waitlist_REST';

assert_test( $r::validate_position( 'player' ), 'player is a valid position' );
assert_test( $r::validate_position( 'goalie' ), 'goalie is a valid position' );
assert_test( ! $r::validate_position( 'defence' ), 'an arbitrary position is refused' );
assert_test( ! $r::validate_position( '' ), 'an empty position is refused' );
assert_test( ! $r::validate_position( array( 'player' ) ), 'a non-scalar position is refused' );

assert_test( $r::validate_status( 'queued' ), 'queued is a valid status filter' );
assert_test( $r::validate_status( 'claimed' ), 'claimed is a valid status filter' );
assert_test( ! $r::validate_status( 'pending' ), 'a WooCommerce status is not a waitlist status' );
assert_test( ! $r::validate_status( 'DROP TABLE' ), 'an injection attempt is refused by the enum, never reaching a query' );

assert_test( $r::validate_hours( 48 ), '48 hours validates' );
assert_test( $r::validate_hours( '72' ), 'a numeric string validates' );
assert_test( ! $r::validate_hours( 0 ), 'zero hours fails validation at the route boundary too' );
assert_test( ! $r::validate_hours( 721 ), 'a window past the maximum fails at the route boundary' );
assert_test( ! $r::validate_hours( 'soon' ), 'a non-numeric window fails at the route boundary' );

echo "\n=== row_to_response() ===\n\n";

$response_row = (object) array(
	'id'                  => 3,
	'season'              => 'S2026',
	'position'            => 'goalie',
	'waitlist_product_id' => 99,
	'target_product_id'   => 11,
	'name'                => 'Sam Player',
	'email'               => 'player@example.com',
	'user_id'             => 7,
	'source_order_id'     => 4321,
	'status'              => 'offered',
	'claim_token'         => str_repeat( 'c', 64 ),
	'offered_at'          => '2026-09-02 12:00:00',
	'expires_at'          => '2026-09-04 12:00:00',
	'resolved_order_id'   => null,
	'created_at'          => '2026-09-01 08:00:00',
	'updated_at'          => '2026-09-02 12:00:00',
);

$shaped = $r::row_to_response( $response_row );

assert_test( 3 === $shaped['id'], 'the id is exposed' );
assert_test( 'S2026' === $shaped['season'], 'the season is exposed' );
assert_test( 'offered' === $shaped['status'], 'the status is exposed' );
assert_test( '2026-09-04 12:00:00' === $shaped['expires_at'], 'the UTC deadline is exposed for the client to localise' );
assert_test( true === $shaped['has_target'], 'a row with a target reports has_target true' );

// The token must never reach the dashboard. Anyone who can read the queue
// could otherwise claim any spot on someone else's behalf, and the dashboard
// has no use for it — the offer email carries the link.
assert_test( ! isset( $shaped['claim_token'] ), 'the claim token is NOT exposed in the admin response' );
assert_test( ! array_key_exists( 'claim_token', $shaped ), 'the claim token key is absent entirely, not merely null' );

$no_target = clone $response_row;
$no_target->target_product_id = 0;
assert_test( false === $r::row_to_response( $no_target )['has_target'], 'a row without a target reports has_target false so the UI can disable Offer' );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php sportspress-league-manager/tests/test-waitlist-lifecycle.php`

Expected: fatal — `Call to undefined method SPLM_Waitlist_REST::validate_position()`.

- [ ] **Step 3: Add the validators and the response shaper**

In `class-waitlist-rest.php`:

```php
	/**
	 * Permission callback for every admin route.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return class_exists( 'SPLM_Capabilities' ) && SPLM_Capabilities::can_manage();
	}

	/**
	 * @param mixed $value Candidate position.
	 * @return bool
	 */
	public static function validate_position( $value ): bool {
		return is_scalar( $value ) && in_array( (string) $value, array( 'player', 'goalie' ), true );
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param mixed $value Candidate status.
	 * @return bool
	 */
	public static function validate_status( $value ): bool {
		return is_scalar( $value ) && in_array( (string) $value, SPLM_Waitlist_Database::statuses(), true );
	}

	/**
	 * @param mixed $value Candidate window.
	 * @return bool
	 */
	public static function validate_hours( $value ): bool {
		if ( ! is_numeric( $value ) ) {
			return false;
		}
		$hours = (int) $value;
		return $hours >= SPLM_Waitlist::MIN_HOURS && $hours <= SPLM_Waitlist::MAX_HOURS;
	}

	/**
	 * Whether a product id is one this feature actually manages.
	 *
	 * Constrains the gate toggle so it cannot be pointed at an arbitrary post
	 * and make some unrelated product unpurchasable.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param mixed $value Candidate product id.
	 * @return bool
	 */
	public static function validate_target_product( $value ): bool {
		if ( ! is_numeric( $value ) || (int) $value <= 0 ) {
			return false;
		}
		return in_array( (int) $value, SPLM_Waitlist_Database::target_product_ids(), true );
	}

	/**
	 * Shape one row for the dashboard.
	 *
	 * claim_token is deliberately absent. Anyone who can read the queue could
	 * otherwise claim any spot on someone else's behalf, and the dashboard has
	 * no use for it — the offer email carries the link.
	 *
	 * Datetimes go out as the stored UTC strings; the client localises them.
	 *
	 * @param object $row Waitlist row.
	 * @return array
	 */
	public static function row_to_response( $row ): array {
		return array(
			'id'                  => (int) $row->id,
			'season'              => (string) $row->season,
			'position'            => (string) $row->position,
			'waitlist_product_id' => (int) $row->waitlist_product_id,
			'target_product_id'   => (int) $row->target_product_id,
			'has_target'          => (int) $row->target_product_id > 0,
			'name'                => (string) $row->name,
			'email'               => (string) $row->email,
			'user_id'             => (int) $row->user_id,
			'source_order_id'     => (int) $row->source_order_id,
			'status'              => (string) $row->status,
			'offered_at'          => $row->offered_at ? (string) $row->offered_at : null,
			'expires_at'          => $row->expires_at ? (string) $row->expires_at : null,
			'resolved_order_id'   => $row->resolved_order_id ? (int) $row->resolved_order_id : null,
			'created_at'          => (string) $row->created_at,
		);
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php sportspress-league-manager/tests/test-waitlist-lifecycle.php`

Expected: `Passed: 78`, `Failed: 0`, exit 0.

- [ ] **Step 5: Register the admin routes**

Add to `register_routes()`, after the claim route:

```php
		register_rest_route(
			self::REST_NAMESPACE,
			'/waitlist',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_waitlist' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'season'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'position' => array(
							'required'          => false,
							'type'              => 'string',
							'validate_callback' => array( __CLASS__, 'validate_position' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'   => array(
							'required'          => false,
							'type'              => 'string',
							'validate_callback' => array( __CLASS__, 'validate_status' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'     => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_entry' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'name'              => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'email'             => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => 'is_email',
							'sanitize_callback' => 'sanitize_email',
						),
						'season'            => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'position'          => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => array( __CLASS__, 'validate_position' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'target_product_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/waitlist/(?P<id>\d+)/offer',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'offer_spot' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'id'    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'hours' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => SPLM_Waitlist::DEFAULT_HOURS,
						'validate_callback' => array( __CLASS__, 'validate_hours' ),
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/waitlist/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_offer' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/waitlist/gate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle_gate' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'product_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => array( __CLASS__, 'validate_target_product' ),
						'sanitize_callback' => 'absint',
					),
					'gated'      => array(
						'required'          => true,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);
```

- [ ] **Step 6: Add the route callbacks**

```php
	/**
	 * List the queue, sweeping any past-due offers first.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|array
	 */
	public function get_waitlist( $request ) {
		$filters = array(
			'season'   => (string) $request->get_param( 'season' ),
			'position' => (string) $request->get_param( 'position' ),
			'status'   => (string) $request->get_param( 'status' ),
		);

		// Backstop for WP-Cron's unreliable self-trigger, bounded to the rows
		// this request was already asking about. sweep() swallows its own
		// failures so a sweep problem cannot fail the read.
		SPLM_Waitlist::sweep(
			array(
				'season'   => $filters['season'],
				'position' => $filters['position'],
			)
		);

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$result = SPLM_Waitlist_Database::query( $filters, $page, $per_page );

		$items = array();
		foreach ( $result['rows'] as $row ) {
			$items[] = self::row_to_response( $row );
		}

		if ( function_exists( 'splm_rest_list_response' ) ) {
			return splm_rest_list_response( $items, (int) $result['total'], $page, $per_page );
		}

		return array(
			'data'        => $items,
			'total'       => (int) $result['total'],
			'page'        => $page,
			'total_pages' => (int) ceil( $result['total'] / $per_page ),
		);
	}

	/**
	 * Add someone to the queue by hand.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function create_entry( $request ) {
		$email    = strtolower( (string) $request->get_param( 'email' ) );
		$season   = (string) $request->get_param( 'season' );
		$position = (string) $request->get_param( 'position' );
		$target   = (int) $request->get_param( 'target_product_id' );

		if ( $target <= 0 || ! wc_get_product( $target ) ) {
			return new WP_Error(
				'splm_waitlist_bad_target',
				__( 'Choose an existing registration product for this entry.', 'sportspress-league-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( SPLM_Waitlist_Database::find_active( $email, $season, $position ) ) {
			return new WP_Error(
				'splm_waitlist_duplicate',
				__( 'This person is already queued or has a live offer for that season and position.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		$row = SPLM_Waitlist::build_row(
			array(
				'is_waitlist'       => true,
				'season'            => $season,
				'position'          => $position,
				'product_id'        => 0,
				'target_product_id' => $target,
				'email'             => $email,
				'name'              => (string) $request->get_param( 'name' ),
				'user_id'           => 0,
				'order_id'          => 0,
				'has_active'        => false,
			)
		);

		if ( null === $row ) {
			return new WP_Error(
				'splm_waitlist_invalid',
				__( 'That entry is missing a season or an email address.', 'sportspress-league-manager' ),
				array( 'status' => 400 )
			);
		}

		$id = SPLM_Waitlist_Database::insert( $row );
		if ( ! $id ) {
			return new WP_Error( 'splm_waitlist_write_failed', __( 'Could not save the entry.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'id'      => (int) $id,
		);
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function offer_spot( $request ) {
		return SPLM_Waitlist::offer( (int) $request->get_param( 'id' ), $request->get_param( 'hours' ) );
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function cancel_offer( $request ) {
		return SPLM_Waitlist::cancel( (int) $request->get_param( 'id' ) );
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function toggle_gate( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$gated      = (bool) $request->get_param( 'gated' );

		SPLM_Waitlist_Gate::set_gated( $product_id, $gated );

		return array(
			'success'    => true,
			'product_id' => $product_id,
			'gated'      => SPLM_Waitlist_Gate::is_gated( $product_id ),
		);
	}
```

- [ ] **Step 7: Verify the routes on staging**

```bash
BASE="https://staging.example/wp-json/splm/v1"
COOKIE="--user admin:APP_PASSWORD"   # or reuse the session cookie the dashboard uses

# List
curl -s $COOKIE "$BASE/waitlist?season=S2026" | python3 -m json.tool | head -30

# The token must not appear anywhere in the payload
curl -s $COOKIE "$BASE/waitlist" | grep -c claim_token   # expect 0

# Refusals
curl -s $COOKIE "$BASE/waitlist?status=pending"          # expect 400 rest_invalid_param
curl -s $COOKIE -X POST "$BASE/waitlist/1/offer" -d 'hours=0'    # expect 400
curl -s $COOKIE -X POST "$BASE/waitlist/999999/cancel"           # expect 404

# Unauthenticated must be refused on every admin route
curl -s -o /dev/null -w '%{http_code}\n' "$BASE/waitlist"        # expect 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE/waitlist/gate" -d 'product_id=1&gated=1'  # expect 401

# Gate toggle refuses an unmanaged product
curl -s $COOKIE -X POST "$BASE/waitlist/gate" -d 'product_id=1&gated=1'   # expect 400 unless product 1 is a real target
```

Expected: the list wraps in `{data,total,page,total_pages}`, `claim_token` appears zero times, every refusal returns its documented status, and both unauthenticated calls are refused.

- [ ] **Step 8: Run the whole suite and commit**

Run: `./run-all-tests.sh` — all suites pass.

```bash
git add sportspress-league-manager/includes/class-waitlist-rest.php \
        sportspress-league-manager/tests/test-waitlist-lifecycle.php
git commit -m "feat(waitlist): add the admin REST routes

Five routes, all gated on SPLM_Capabilities::can_manage(), all declaring
validate_callback and sanitize_callback on every argument. The status and
position filters are enums, so an injection attempt is refused at the route
boundary and never reaches a query, and hours is bounded 1..720 there as well
as inside offer().

row_to_response() deliberately omits claim_token. Anyone who can read the
queue could otherwise claim any spot on someone else's behalf, and the
dashboard has no use for it — the offer email carries the link. The test
asserts the key is absent entirely rather than merely null.

The gate toggle's product_id must be a product this feature actually manages,
so it cannot be pointed at an arbitrary post to make some unrelated product
unpurchasable.

The list route sweeps past-due offers first, bounded to the filters the
request already carried."
```

---

### Task 13: Dashboard page

**Files:**
- Create: `sportspress-league-manager/src/dashboard/pages/Waitlist.jsx`
- Modify: `sportspress-league-manager/src/dashboard/lib/api.js`
- Modify: `sportspress-league-manager/src/dashboard/App.jsx`
- Modify: `sportspress-league-manager/src/dashboard/components/Layout.jsx`
- Modify: `sportspress-league-manager/src/dashboard/styles.css`

**Interfaces:**
- Consumes: the five routes from Task 12.
- Produces: page id `waitlist`, nav entry gated on `modulePresent( 'waitlist' )`, and API functions `fetchWaitlist`, `addWaitlistEntry`, `offerWaitlistSpot`, `cancelWaitlistEntry`, `setWaitlistGate`.

- [ ] **Step 1: Add the API client functions**

Append to `sportspress-league-manager/src/dashboard/lib/api.js`:

```js
// Waitlist. The list endpoint conforms to the standard envelope; the paged
// variant is kept because a season's queue can outgrow one page.
export function fetchWaitlist( params = {} ) {
	const query = new URLSearchParams(
		Object.fromEntries( Object.entries( params ).filter( ( [ , v ] ) => v !== '' && v != null ) )
	).toString();
	return apiFetch( { path: `/splm/v1/waitlist${ query ? '?' + query : '' }` } ).then( ( res ) => ( {
		data: Array.isArray( res?.data ) ? res.data : [],
		total: Number( res?.total ) || 0,
		totalPages: Number( res?.total_pages ) || 0,
		page: Number( res?.page ) || 1,
	} ) );
}

export function addWaitlistEntry( entry ) {
	return apiFetch( { path: '/splm/v1/waitlist', method: 'POST', data: entry } );
}

export function offerWaitlistSpot( id, hours ) {
	return apiFetch( { path: `/splm/v1/waitlist/${ id }/offer`, method: 'POST', data: { hours } } );
}

export function cancelWaitlistEntry( id ) {
	return apiFetch( { path: `/splm/v1/waitlist/${ id }/cancel`, method: 'POST' } );
}

export function setWaitlistGate( productId, gated ) {
	return apiFetch( {
		path: '/splm/v1/waitlist/gate',
		method: 'POST',
		data: { product_id: productId, gated },
	} );
}
```

- [ ] **Step 2: Create the page**

Create `sportspress-league-manager/src/dashboard/pages/Waitlist.jsx`:

```jsx
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
	fetchWaitlist,
	addWaitlistEntry,
	offerWaitlistSpot,
	cancelWaitlistEntry,
	setWaitlistGate,
} from '../lib/api';

const DEFAULT_HOURS = 48;

// Deadlines arrive as UTC 'Y-m-d H:i:s'. Date can't parse that shape reliably
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

function Countdown( { expiresAt } ) {
	const [ now, setNow ] = useState( () => Date.now() );

	useEffect( () => {
		const timer = setInterval( () => setNow( Date.now() ), 30000 );
		return () => clearInterval( timer );
	}, [] );

	const target = parseUtc( expiresAt );
	if ( ! target ) {
		return null;
	}

	const remaining = target.getTime() - now;
	if ( remaining <= 0 ) {
		return <span className="splm-waitlist__countdown splm-waitlist__countdown--lapsed">expired</span>;
	}

	const hours = Math.floor( remaining / 3600000 );
	const minutes = Math.floor( ( remaining % 3600000 ) / 60000 );
	const label = hours > 0 ? `${ hours }h ${ minutes }m left` : `${ minutes }m left`;

	return (
		<span className={ `splm-waitlist__countdown${ hours < 6 ? ' splm-waitlist__countdown--soon' : '' }` }>
			{ label }
		</span>
	);
}

export default function Waitlist() {
	const [ rows, setRows ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ warnings, setWarnings ] = useState( [] );
	const [ filters, setFilters ] = useState( { season: '', position: '', status: '' } );
	const [ gates, setGates ] = useState( {} );
	const [ busyId, setBusyId ] = useState( 0 );
	const [ adding, setAdding ] = useState( false );
	const [ form, setForm ] = useState( { name: '', email: '', season: '', position: 'player', target_product_id: '' } );

	const load = useCallback( () => {
		setLoading( true );
		setError( '' );
		fetchWaitlist( filters )
			.then( ( res ) => setRows( res.data ) )
			.catch( ( e ) => setError( e?.message || 'Could not load the waitlist.' ) )
			.finally( () => setLoading( false ) );
	}, [ filters ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// Target products for the Season access panel, derived from the rows on
	// screen so the panel always describes what the convener is looking at.
	const targets = useMemo( () => {
		const seen = new Map();
		rows.forEach( ( row ) => {
			if ( row.target_product_id > 0 && ! seen.has( row.target_product_id ) ) {
				seen.set( row.target_product_id, { id: row.target_product_id, season: row.season, position: row.position } );
			}
		} );
		return Array.from( seen.values() );
	}, [ rows ] );

	const handleOffer = ( row ) => {
		const input = window.prompt(
			`Claim window in hours for ${ row.name || row.email }?`,
			String( DEFAULT_HOURS )
		);
		if ( input === null ) {
			return;
		}
		const hours = Number( input );
		if ( ! Number.isInteger( hours ) || hours < 1 || hours > 720 ) {
			setError( 'The claim window must be a whole number of hours between 1 and 720.' );
			return;
		}

		setBusyId( row.id );
		setError( '' );
		setNotice( '' );
		setWarnings( [] );
		offerWaitlistSpot( row.id, hours )
			.then( ( res ) => {
				setNotice( `Offer sent. It expires ${ formatLocal( res.expires_at ) }.` );
				setWarnings( res.warnings || [] );
				load();
			} )
			.catch( ( e ) => setError( e?.message || 'Could not send the offer.' ) )
			.finally( () => setBusyId( 0 ) );
	};

	const handleCancel = ( row ) => {
		const label = row.status === 'offered' ? 'Cancel this offer?' : 'Remove this entry from the queue?';
		// Matches every other bulk/irreversible action in this dashboard.
		if ( ! window.confirm( label ) ) {
			return;
		}
		setBusyId( row.id );
		setError( '' );
		cancelWaitlistEntry( row.id )
			.then( () => load() )
			.catch( ( e ) => setError( e?.message || 'Could not cancel.' ) )
			.finally( () => setBusyId( 0 ) );
	};

	const handleGate = ( productId, gated ) => {
		const label = gated
			? 'Gate this product? The public will no longer be able to buy it without an offer.'
			: 'Un-gate this product? Anyone will be able to buy it again.';
		if ( ! window.confirm( label ) ) {
			return;
		}
		setError( '' );
		setWaitlistGate( productId, gated )
			.then( ( res ) => {
				setGates( ( prev ) => ( { ...prev, [ productId ]: res.gated } ) );
				setNotice( res.gated ? 'Product gated.' : 'Product un-gated.' );
			} )
			.catch( ( e ) => setError( e?.message || 'Could not change gating.' ) );
	};

	const handleAdd = ( event ) => {
		event.preventDefault();
		setAdding( true );
		setError( '' );
		addWaitlistEntry( { ...form, target_product_id: Number( form.target_product_id ) } )
			.then( () => {
				setForm( { name: '', email: '', season: '', position: 'player', target_product_id: '' } );
				setNotice( 'Entry added to the queue.' );
				load();
			} )
			.catch( ( e ) => setError( e?.message || 'Could not add the entry.' ) )
			.finally( () => setAdding( false ) );
	};

	return (
		<div className="splm-waitlist">
			<h1>Waitlist</h1>

			{ error && <div className="splm-notice splm-notice--error" role="alert">{ error }</div> }
			{ notice && <div className="splm-notice splm-notice--success" role="status">{ notice }</div> }
			{ warnings.map( ( w ) => (
				<div key={ w.code } className="splm-notice splm-notice--warning" role="alert">{ w.message }</div>
			) ) }

			<section className="splm-waitlist__access">
				<h2>Season access</h2>
				<p className="splm-waitlist__hint">
					A gated product cannot be bought by the public — only by someone holding a live
					offer. Un-gating puts it back on sale to anyone who has its URL.
				</p>
				{ targets.length === 0 && <p>No registration products for the current filter.</p> }
				<ul className="splm-waitlist__gates">
					{ targets.map( ( t ) => {
						const gated = gates[ t.id ];
						return (
							<li key={ t.id }>
								<span>#{ t.id } — { t.season } { t.position }</span>
								<button type="button" onClick={ () => handleGate( t.id, ! gated ) }>
									{ gated ? 'Un-gate' : 'Gate' }
								</button>
							</li>
						);
					} ) }
				</ul>
			</section>

			<section className="splm-waitlist__filters">
				<label>
					Season
					<input
						type="text"
						value={ filters.season }
						onChange={ ( e ) => setFilters( { ...filters, season: e.target.value } ) }
					/>
				</label>
				<label>
					Position
					<select
						value={ filters.position }
						onChange={ ( e ) => setFilters( { ...filters, position: e.target.value } ) }
					>
						<option value="">All</option>
						<option value="player">Player</option>
						<option value="goalie">Goalie</option>
					</select>
				</label>
				<label>
					Status
					<select
						value={ filters.status }
						onChange={ ( e ) => setFilters( { ...filters, status: e.target.value } ) }
					>
						<option value="">All</option>
						<option value="queued">Queued</option>
						<option value="offered">Offered</option>
						<option value="claimed">Claimed</option>
						<option value="expired">Expired</option>
						<option value="cancelled">Cancelled</option>
					</select>
				</label>
			</section>

			{ loading && <p>Loading…</p> }

			{ ! loading && rows.length === 0 && <p>Nobody is on the waitlist for this filter.</p> }

			{ ! loading && rows.length > 0 && (
				<table className="splm-waitlist__table">
					<thead>
						<tr>
							<th scope="col">Joined</th>
							<th scope="col">Name</th>
							<th scope="col">Email</th>
							<th scope="col">Season</th>
							<th scope="col">Position</th>
							<th scope="col">Status</th>
							<th scope="col">Deadline</th>
							<th scope="col">Actions</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.id }>
								<td>{ formatLocal( row.created_at ) }</td>
								<td>{ row.name || '—' }</td>
								<td>{ row.email }</td>
								<td>{ row.season }</td>
								<td>{ row.position }</td>
								<td>
									<span className={ `splm-waitlist__status splm-waitlist__status--${ row.status }` }>
										{ row.status }
									</span>
									{ ! row.has_target && (
										<div className="splm-waitlist__flag">
											No registration product paired — set one before offering.
										</div>
									) }
								</td>
								<td>
									{ row.status === 'offered' ? (
										<>
											{ formatLocal( row.expires_at ) }{ ' ' }
											<Countdown expiresAt={ row.expires_at } />
										</>
									) : (
										'—'
									) }
								</td>
								<td>
									{ ( row.status === 'queued' || row.status === 'expired' ) && (
										<button
											type="button"
											disabled={ busyId === row.id || ! row.has_target }
											title={ row.has_target ? '' : 'This entry has no registration product paired.' }
											onClick={ () => handleOffer( row ) }
										>
											{ row.status === 'expired' ? 'Re-offer' : 'Offer' }
										</button>
									) }
									{ row.status !== 'claimed' && row.status !== 'cancelled' && (
										<button
											type="button"
											disabled={ busyId === row.id }
											onClick={ () => handleCancel( row ) }
										>
											{ row.status === 'offered' ? 'Cancel offer' : 'Remove' }
										</button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<section className="splm-waitlist__add">
				<h2>Add to waitlist</h2>
				<form onSubmit={ handleAdd }>
					<label>
						Name
						<input
							type="text"
							required
							value={ form.name }
							onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) }
						/>
					</label>
					<label>
						Email
						<input
							type="email"
							required
							value={ form.email }
							onChange={ ( e ) => setForm( { ...form, email: e.target.value } ) }
						/>
					</label>
					<label>
						Season
						<input
							type="text"
							required
							placeholder="S2026"
							value={ form.season }
							onChange={ ( e ) => setForm( { ...form, season: e.target.value } ) }
						/>
					</label>
					<label>
						Position
						<select
							value={ form.position }
							onChange={ ( e ) => setForm( { ...form, position: e.target.value } ) }
						>
							<option value="player">Player</option>
							<option value="goalie">Goalie</option>
						</select>
					</label>
					<label>
						Registration product ID
						<input
							type="number"
							required
							min="1"
							value={ form.target_product_id }
							onChange={ ( e ) => setForm( { ...form, target_product_id: e.target.value } ) }
						/>
					</label>
					<button type="submit" disabled={ adding }>
						{ adding ? 'Adding…' : 'Add' }
					</button>
				</form>
			</section>
		</div>
	);
}
```

- [ ] **Step 3: Wire the page into the router**

In `App.jsx`, add the import alongside the others and the entry in `PAGES`:

```jsx
import Waitlist from './pages/Waitlist';
```

```jsx
	waitlist: Waitlist,
```

- [ ] **Step 4: Wire the nav item**

In `components/Layout.jsx`, add to the nav array after `payments`:

```jsx
	{ id: 'waitlist', label: 'Waitlist', icon: 'payments' },
```

And in the visibility map next to the existing entries:

```jsx
		waitlist: caps.canManage && modulePresent( 'waitlist' ),
```

Confirm the server sends `modules.waitlist` — the dashboard's config builder must include the `league_waitlist` module under the key `waitlist`, matching how `fees` maps to `league_fee_tracking`. Add it where the other module keys are assembled in `class-dashboard-frontend.php` if it is missing.

- [ ] **Step 5: Add the styles**

Append to `src/dashboard/styles.css`:

```css
.splm-waitlist__hint {
	color: var( --splm-text-muted, #646970 );
	max-width: 46rem;
}

.splm-waitlist__gates {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.splm-waitlist__gates li {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	border: 1px solid var( --splm-border, #dcdcde );
	border-radius: 4px;
	padding: 0.35rem 0.6rem;
}

.splm-waitlist__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	margin: 1rem 0;
}

.splm-waitlist__filters label {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.splm-waitlist__table {
	width: 100%;
	border-collapse: collapse;
}

.splm-waitlist__table th,
.splm-waitlist__table td {
	text-align: left;
	padding: 0.5rem;
	border-bottom: 1px solid var( --splm-border, #dcdcde );
	vertical-align: top;
}

.splm-waitlist__status {
	display: inline-block;
	padding: 0.1rem 0.45rem;
	border-radius: 999px;
	font-size: 0.8125rem;
	text-transform: capitalize;
	background: var( --splm-surface-alt, #f0f0f1 );
}

.splm-waitlist__status--offered { background: #fcf3d7; }
.splm-waitlist__status--claimed { background: #d7f0dd; }
.splm-waitlist__status--expired,
.splm-waitlist__status--cancelled { background: #f0f0f1; color: #646970; }

.splm-waitlist__countdown {
	font-size: 0.8125rem;
	color: var( --splm-text-muted, #646970 );
	white-space: nowrap;
}

.splm-waitlist__countdown--soon { color: #b32d2e; font-weight: 600; }
.splm-waitlist__countdown--lapsed { color: #646970; font-style: italic; }

.splm-waitlist__flag {
	color: #b32d2e;
	font-size: 0.8125rem;
	margin-top: 0.25rem;
	max-width: 18rem;
}

.splm-waitlist__add form {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	align-items: flex-end;
}

.splm-waitlist__add label {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}
```

- [ ] **Step 6: Build and check the bundle**

Run: `cd sportspress-league-manager && npm ci && npm run build`

Expected: a clean build with no lint errors. **The repo has a build-drift CI gate**, so the rebuilt `build/` artifacts must be committed with this change — see the CI note in memory. Confirm `git status` shows the expected `build/` changes and include them.

- [ ] **Step 7: Verify the page on staging**

Deploy and walk the whole flow as a convener:

1. Enable the `league_waitlist` module; the Waitlist nav item appears. Disable it; the item disappears.
2. The queue lists real rows with local-time timestamps. Compare a `created_at` against the DB's UTC value and confirm the displayed time is correctly offset, not doubled.
3. Offer a spot with a custom window; the countdown shows and ticks down.
4. A row with no paired product shows the flag and a disabled Offer button.
5. Cancel an offer; re-offer it.
6. Toggle a gate on and off from Season access, confirming the confirm dialog fires.
7. Add someone manually, then try adding the same person again — expect the duplicate refusal surfaced as an error notice.

- [ ] **Step 8: Run the whole suite and commit**

Run: `./run-all-tests.sh` — all suites pass.

```bash
git add sportspress-league-manager/src/dashboard/pages/Waitlist.jsx \
        sportspress-league-manager/src/dashboard/lib/api.js \
        sportspress-league-manager/src/dashboard/App.jsx \
        sportspress-league-manager/src/dashboard/components/Layout.jsx \
        sportspress-league-manager/src/dashboard/styles.css \
        sportspress-league-manager/build
git commit -m "feat(waitlist): add the Waitlist dashboard page

Queue table with a live countdown on offered rows, an offer dialog that
confirms or overrides the 48-hour default, cancel and re-offer, a manual add
form, and a Season access panel for the purchase gate.

Deadlines arrive as UTC strings and are parsed with an explicit Z before
display. Without it the browser reads 'Y-m-d H:i:s' as local time, which is
the same four-to-five hour error the server side guards against — the
countdown would be wrong in the direction that matters.

Rows with no paired registration product show the reason inline and disable
Offer, rather than letting a convener discover the problem from a 409.

Gating and cancelling both confirm first, matching every other irreversible
action in this dashboard. The gate confirmation says plainly that the public
will no longer be able to buy the product."
```

---

## Self-review

Run through this before handing the plan off.

**Spec coverage.** Every section of the spec maps to a task:

| Spec section | Task |
|---|---|
| Purchase gating (rationale, mechanism, which products, fails open) | 11 |
| Architecture — dedicated table, not order meta | 3 |
| Shared season/position parsing | 1, 2 |
| WooCommerce HPOS | 4 |
| Data model, nullable UNIQUE token, `user_id` purpose | 3, 10 |
| Time is UTC, without exception | 3, 8, 13 |
| Ingestion (paid statuses, five steps, manual add) | 5, 6, 12 |
| Offer flow (token, lock, mail unwind, warnings) | 7 |
| The cron hazard (clear + defensive re-check) | 7, 8 |
| Bounded sweep | 8, 12 |
| Claim flow (302, dead-link HTML, side-effect-free, non-oracle) | 9 |
| Order tie-back (line item meta primary, email/user fallback) | 10 |
| REST surface, arg validation, error codes | 9, 12 |
| Module gating | 4 |
| UI (table, countdown, Season access, warnings) | 13 |
| Uninstall | 4 |
| Testing (five test files) | 1, 3, 5, 6, 7, 8, 9, 10, 11, 12 |
| Phasing | task order |

**Gaps found and closed while reviewing:**

1. The spec's file table put the cart binding in the gate class, which would have forced Phase 4 before Phase 3. Moved to `SPLM_Waitlist` and documented as a deliberate refinement in File Structure.
2. Nothing in the spec said the admin API must withhold `claim_token`. It must — anyone who can read the queue could otherwise claim any spot on someone else's behalf. Asserted in Task 12.
3. The spec did not say the dashboard config needs a `modules.waitlist` key. Called out in Task 13 Step 4.
4. `SPAT_Logger::info()` may not exist; Task 10 says to check and match the available level methods.

**Type consistency.** Names used across tasks match their definitions: `SPLM_Waitlist::CART_META_KEY` / `CLAIM_ARG` (defined Task 9, used Tasks 10–11), `is_token_shaped` / `is_claimable` / `claim_state` (Task 9, used 10–11), `SPLM_Waitlist_Database::is_past_due` (Task 3, used 8–9), `expiry_from_hours` returning `{expires_at, timestamp}` (Task 3, used 7), `MIN_HOURS` / `MAX_HOURS` / `DEFAULT_HOURS` (Task 7, used 12–13), `SPLM_Waitlist_Gate::{GATE_META,set_gated,is_gated}` (Task 11, used 7 and 12), `has_target` in the response shape (Task 12, consumed Task 13).

**Placeholders.** None. Every code step carries the actual code; every verification step carries the actual command and its expected output. Placeholders that look like gaps but are not: `ORDER_ID`, `TARGET_PRODUCT_ID`, `APP_PASSWORD` and `staging.example` in the staging commands, which are values the executor substitutes from their own environment.
