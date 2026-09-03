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

// $domain is never consulted, so it is omitted rather than declared as an
// ignored formal parameter — PHP allows extra arguments to a userland
// function, and this is the repo's convention for unused stub params.
function __( $text ) { // phpcs:ignore
	return $text;
}

// suspension_sentence() pluralises its game count through _n().
function _n( $single, $plural, $number ) { // phpcs:ignore
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
// The warning DOES contain the word "suspended" — "at 18 you will be
// suspended" is the whole point of naming the next threshold. What it must
// never carry is the declarative claim that the player is suspended NOW.
assert_test(
	false === strpos( $warning, 'You are suspended' ),
	'a warning says what will happen, never that the player is already suspended'
);

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

echo "\n=== a window tier does not report its figure as a season total ===\n\n";

// A window tier's matched value is a rolling few-weeks total. Reporting it as
// "N penalty minutes in <season>" understates the player's real season figure
// and reads as wrong to anyone who knows their own record.
$window_body = $mail::body(
	array(
		'player_name'    => 'Bob',
		'season_name'    => 'W2025-26',
		'scope'          => 'window',
		'value'          => 9,
		'season_value'   => 13,
		'consequence'    => 'suspend',
		'games'          => 1,
		'next_threshold' => 0,
		'game_label'     => '',
	)
);

assert_test( false !== strpos( $window_body, 'last few weeks' ), 'a window figure is described as recent, not seasonal' );
assert_test( false !== strpos( $window_body, '13' ), 'and the real season total is stated alongside it' );
assert_test(
	false === strpos( $window_body, '9 penalty minutes in W2025-26' ),
	'the window figure is never presented as the season total'
);

$season_body = $mail::body(
	array(
		'player_name'    => 'Alex',
		'season_name'    => 'W2025-26',
		'scope'          => 'season',
		'value'          => 18,
		'season_value'   => 18,
		'consequence'    => 'suspend',
		'games'          => 1,
		'next_threshold' => 0,
		'game_label'     => '',
	)
);
assert_test(
	false !== strpos( $season_body, '18 penalty minutes in W2025-26' ),
	'a season tier still reads exactly as before'
);
assert_test( false === strpos( $season_body, 'last few weeks' ), 'and does not gain the window wording' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
