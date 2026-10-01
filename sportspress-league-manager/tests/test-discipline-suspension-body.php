<?php
/**
 * Wording tests for manual suspension emails. The structural guarantee that
 * the incident note cannot leak is that the builder only reads an allow-list
 * of context keys — asserted here by feeding it a hostile context.
 */

define( 'ABSPATH', __DIR__ );

function __( $t ) { return $t; } // phpcs:ignore
function _n( $s, $p, $n ) { return 1 === (int) $n ? $s : $p; } // phpcs:ignore
function esc_url_raw( $u ) { return (string) $u; } // phpcs:ignore

require_once __DIR__ . '/../includes/class-discipline-suspension-body.php';

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) { echo "✓ PASS: {$message}\n"; $passed++; } else { echo "✗ FAIL: {$message}\n"; $failed++; }
}

$b = 'SPLM_Discipline_Suspension_Body';

$ctx = array(
	'kind'             => 'issued',
	'player_name'      => 'Alex Doe',
	'season_name'      => 'W2026-27',
	'rule_ref'         => '6.5',
	'infraction_title' => 'Fighting (first offence)',
	'rule_text'        => 'Fighting (first offence) — minimum 3 game suspension.',
	'outcome'          => 'games',
	'games'            => 3,
	'incident_label'   => 'Oct 6 — Wolves vs Bears',
	'eligible_label'   => 'Oct 27 — Wolves vs Hawks',
	'projected'        => true,
	'remaining'        => 0,
	'rulebook_url'     => 'https://example.test/rules.pdf',
	'rulebook_rev'     => 'Rev 20241009',
	'contact'          => 'convener@example.test',
	'team_names'       => 'Wolves',
	// Hostile extras: must never be read.
	'incident_note'    => 'SECRET referee comment about Sam',
	'note'             => 'SECRET2',
);

echo "\n=== subject() ===\n\n";
assert_test( 'Suspension notice — W2026-27' === $b::subject( 'issued', 'W2026-27' ), 'issued subject names the season' );
assert_test( false !== strpos( $b::subject( 'revoked', 'W2026-27' ), 'Correction' ), 'revoked subject says correction' );
assert_test( false !== strpos( $b::subject( 'amended', 'W2026-27' ), 'Updated' ), 'amended subject says updated' );
assert_test( false !== strpos( $b::subject( 'decided', 'W2026-27' ), 'Decision' ), 'decided subject says decision' );

echo "\n=== player body ===\n\n";
$p = $b::body( 'player', $ctx );
assert_test( false !== strpos( $p, 'Fighting (first offence)' ), 'names the infraction' );
assert_test( false !== strpos( $p, '6.5' ), 'cites the rule number' );
assert_test( false !== strpos( $p, 'minimum 3 game suspension' ), 'quotes the rulebook wording' );
assert_test( false !== strpos( $p, '3 games' ), 'states the length' );
assert_test( false !== strpos( $p, 'Oct 27 — Wolves vs Hawks' ), 'states the next eligible game' );
assert_test( false !== strpos( $p, "if the schedule doesn't change" ), 'labels the date as a projection' );
assert_test( false !== strpos( $p, 'Oct 6 — Wolves vs Bears' ), 'names the incident match' );
assert_test( false !== strpos( $p, 'https://example.test/rules.pdf' ), 'links the rulebook' );
assert_test( false !== strpos( $p, 'Rev 20241009' ), 'names the rulebook revision' );
assert_test( false === strpos( $p, 'forfeit' ), 'player copy has no captain forfeit line' );
assert_test( false === strpos( $p, 'SECRET' ), 'incident note never appears in the player body' );

echo "\n=== captain body ===\n\n";
$c = $b::body( 'captain', $ctx );
assert_test( false !== strpos( $c, 'Alex Doe' ), 'captain copy names the player' );
assert_test( false !== strpos( $c, 'forfeit' ), 'captain copy carries the forfeit line' );
assert_test( false === strpos( $c, 'SECRET' ), 'incident note never appears in the captain body' );

echo "\n=== indefinite ===\n\n";
$i_ctx = array_merge( $ctx, array( 'outcome' => 'indefinite', 'games' => 0, 'eligible_label' => '', 'projected' => false ) );
$ip = $b::body( 'player', $i_ctx );
assert_test( false !== strpos( $ip, 'pending convenor review' ) || false !== strpos( $ip, 'pending review' ), 'indefinite says pending review' );
assert_test( false === strpos( $ip, '0 games' ), 'indefinite never says "0 games"' );
assert_test( false !== strpos( $ip, 'until the review is complete' ), 'indefinite says suspended until review is complete' );

echo "\n=== shortage (no date) ===\n\n";
$s_ctx = array_merge( $ctx, array( 'eligible_label' => '', 'remaining' => 2 ) );
$sp = $b::body( 'player', $s_ctx );
assert_test( false !== strpos( $sp, 'next scheduled game' ), 'with no date, falls back to "next scheduled game"' );
assert_test( false === strpos( $sp, 'Oct 27' ), 'no invented date' );

echo "\n=== follow-ups ===\n\n";
$a = $b::body( 'player', array_merge( $ctx, array( 'kind' => 'amended', 'prior_games' => 2 ) ) );
assert_test( false !== strpos( $a, '2 games' ) && false !== strpos( $a, '3 games' ), 'amend shows old and new lengths' );
$r = $b::body( 'player', array_merge( $ctx, array( 'kind' => 'revoked' ) ) );
assert_test( false !== strpos( $r, 'no longer' ), 'revoke says the suspension no longer applies' );
$d = $b::body( 'player', array_merge( $i_ctx, array( 'kind' => 'decided', 'outcome' => 'games', 'games' => 4, 'eligible_label' => 'Nov 3 — Wolves vs Owls' ) ) );
assert_test( false !== strpos( $d, '4 games' ) && false !== strpos( $d, 'Nov 3' ), 'decision states the games and the date' );

echo "\n=== captain audience-specific wording ===\n\n";
$ca_i = $b::body( 'captain', array_merge( $i_ctx, array() ) );
assert_test( false !== strpos( $ca_i, 'Alex Doe' ), 'captain indefinite contains player name' );
assert_test( false !== strpos( $ca_i, 'remains suspended' ), 'captain indefinite says remains suspended' );
assert_test( false === strpos( $ca_i, 'You remain suspended' ), 'captain indefinite does NOT contain "You remain suspended"' );
assert_test( false === strpos( $ca_i, 'your next scheduled game' ), 'captain indefinite does NOT contain "your next scheduled game"' );

$ca_s = $b::body( 'captain', $s_ctx );
assert_test( false === strpos( $ca_s, 'your next scheduled game' ), 'captain shortage does NOT contain "your next scheduled game"' );
assert_test( false !== strpos( $ca_s, "player's next scheduled game" ), 'captain shortage says "player\'s next scheduled game"' );

$ca_all = $b::body( 'captain', $ctx );
assert_test( false === strpos( $ca_all, 'every team you are on' ), 'captain all-play does NOT contain "you are on"' );
assert_test( false !== strpos( $ca_all, 'every team the player is on' ), 'captain all-play contains "every team the player is on"' );

echo "\n=== player indefinite regression ===\n\n";
$p_i = $b::body( 'player', $i_ctx );
assert_test( false !== strpos( $p_i, 'You remain suspended' ), 'player indefinite contains "You remain suspended"' );

echo "\n=== zero-game suspensions ===\n\n";
$z_ctx = array_merge( $ctx, array( 'games' => 0, 'eligible_label' => '', 'projected' => false ) );
$zp    = $b::body( 'player', $z_ctx );
assert_test( false !== strpos( $zp, 'Length: balance of the game' ) && false !== strpos( $zp, 'no further games' ), 'player 0 games: balance-of-the-game length' );
assert_test( false === strpos( $zp, '0 games' ) && false === strpos( $zp, 'remaining games' ) && false === strpos( $zp, 'Next eligible' ) && false === strpos( $zp, 'next scheduled game' ), 'player 0 games: no 0 games / eligibility sentences' );
assert_test( false === strpos( $zp, 'ALL league play' ), 'player 0 games: no all-play sentence' );
assert_test( false !== strpos( $zp, 'Fighting (first offence)' ) && false !== strpos( $zp, 'Rev 20241009' ), 'player 0 games: infraction and footer remain' );
$zc = $b::body( 'captain', $z_ctx );
assert_test( false !== strpos( $zc, 'balance of the game; no further games' ) && false === strpos( $zc, 'Length: balance' ), 'captain 0 games: third-person balance-of-the-game' );
assert_test( false === strpos( $zc, '0 games' ) && false === strpos( $zc, 'remaining games' ) && false === strpos( $zc, "player's next scheduled game" ) && false === strpos( $zc, 'ALL league play' ), 'captain 0 games: no eligibility or all-play sentences' );
assert_test( false === strpos( $zc, 'forfeits the game' ), 'captain 0 games: no forfeit line' );

echo "\n=== 1+ game wording regression ===\n\n";
$one = $b::body( 'player', array_merge( $ctx, array( 'games' => 1 ) ) );
assert_test( false !== strpos( $one, 'Length: 1 game.' ) && false !== strpos( $one, 'Next eligible game:' ) && false !== strpos( $one, 'ALL league play' ), '1 game: length, eligibility and all-play unchanged' );
$oc = $b::body( 'captain', array_merge( $ctx, array( 'games' => 1 ) ) );
assert_test( false !== strpos( $oc, 'forfeits the game' ), '1 game captain: forfeit line unchanged' );

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
