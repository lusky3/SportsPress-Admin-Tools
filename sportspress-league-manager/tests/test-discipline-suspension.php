<?php
/**
 * Standalone tests for the manual-suspension service: statuses, row building,
 * duplicate detection, recipients and the history summary line.
 */

define( 'ABSPATH', __DIR__ );

function __( $t ) { return $t; } // phpcs:ignore
function _n( $s, $p, $n ) { return 1 === (int) $n ? $s : $p; } // phpcs:ignore
function absint( $v ) { return abs( (int) $v ); }

require_once __DIR__ . '/../includes/class-discipline-notice-database.php';
require_once __DIR__ . '/../includes/class-discipline-infraction.php';
require_once __DIR__ . '/../includes/class-discipline-captain-mail.php';
require_once __DIR__ . '/../includes/class-discipline-suspension.php';

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) { echo "✓ PASS: {$message}\n"; $passed++; } else { echo "✗ FAIL: {$message}\n"; $failed++; }
}

$db = 'SPLM_Discipline_Notice_Database';

echo "\n=== statuses ===\n\n";
assert_test( 'revoked' === $db::STATUS_REVOKED, 'STATUS_REVOKED is "revoked"' );
assert_test( in_array( 'revoked', $db::STATUSES, true ), 'revoked is a valid status' );
assert_test( 'manual' === $db::SOURCE_MANUAL && 'auto' === $db::SOURCE_AUTO, 'source constants exist' );
assert_test( '1.1.0' === $db::DB_VERSION, 'DB_VERSION bumped so maybe_upgrade() re-runs dbDelta' );

$s = 'SPLM_Discipline_Suspension';

$inf = (object) array( 'id' => 5, 'rule_ref' => '6.5', 'title' => 'Fighting (first offence)', 'rule_text' => 'Rule text', 'outcome' => 'games', 'default_games' => 3 );
$elig = array( 'date' => '2026-10-27 21:00:00', 'event_id' => 77, 'team_id' => 11, 'remaining' => 0 );

echo "\n=== schema ===\n\n";
$cols = array( 'source', 'infraction_id', 'rule_ref', 'infraction_title', 'rule_text', 'outcome', 'incident_event_id', 'incident_note', 'parent_id', 'eligible_on', 'captains_notified' );
assert_test( $cols === $db::required_columns(), 'required_columns lists exactly the 11 manual-suspension columns' );
$sql = $db::create_sql( 'wp_splm_discipline_notice', 'DEFAULT CHARSET=utf8mb4' );
foreach ( $cols as $col ) {
	assert_test( false !== strpos( $sql, "\t\t\t{$col} " ), "CREATE TABLE defines {$col}" );
}
assert_test( false !== strpos( $sql, 'PRIMARY KEY  (id)' ), 'two-space PRIMARY KEY for dbDelta' );
assert_test( false !== strpos( $sql, 'KEY parent (parent_id)' ) && false !== strpos( $sql, 'KEY source_status (source, status)' ), 'new indexes present' );
assert_test( 0 === strpos( $sql, 'CREATE TABLE wp_splm_discipline_notice (' ) && false !== strpos( $sql, 'DEFAULT CHARSET=utf8mb4;' ), 'table name and charset interpolated' );

class T_Wpdb { public $prefix = 'wp_'; public $cols = array(); public function get_col() { return $this->cols; } }
$wpdb = new T_Wpdb();
$wpdb->cols = array( 'id', 'player_id' );
assert_test( $cols === $db::missing_columns(), 'missing_columns: old table lacks all 11' );
$wpdb->cols = array_merge( array( 'id' ), array_diff( $cols, array( 'eligible_on' ) ) );
assert_test( array( 'eligible_on' ) === $db::missing_columns(), 'missing_columns names just the absent one' );
$wpdb->cols = array_merge( array( 'id' ), $cols );
assert_test( array() === $db::missing_columns(), 'missing_columns empty when all present' );
$wpdb->cols = array();
assert_test( $cols === $db::missing_columns(), 'unreadable table: all reported missing' );

echo "\n=== build_row() ===\n\n";
$row = $s::build_row( array( 'player_id' => 9, 'season_id' => 4, 'incident_event_id' => 70, 'incident_note' => 'private', 'team' => 'Wolves', 'division' => 'A' ), $inf, $elig );
assert_test( 'manual' === $row['source'], 'source is manual' );
assert_test( 'suspend' === $row['consequence'] && 3 === $row['games'], 'default games come from the infraction' );
assert_test( 'games' === $row['outcome'], 'outcome copied' );
assert_test( '2026-10-27' === $row['eligible_on'], 'eligible_on is the date part of the projection' );
assert_test( '6.5' === $row['rule_ref'] && 'Fighting (first offence)' === $row['infraction_title'] && 'Rule text' === $row['rule_text'], 'infraction text is snapshotted onto the row' );
assert_test( 'private' === $row['incident_note'], 'incident note stored on the row' );
assert_test( 'pending' === $row['status'], 'new rows start pending (draft) — sending is a separate step' );
assert_test( 70 === $row['incident_event_id'], 'incident match is recorded as a reference' );

$row = $s::build_row( array( 'player_id' => 9, 'season_id' => 4, 'games' => 5 ), $inf, $elig );
assert_test( 5 === $row['games'], 'convener override of length wins over the default' );

$ind = (object) array_merge( (array) $inf, array( 'outcome' => 'indefinite', 'default_games' => 0 ) );
$row = $s::build_row( array( 'player_id' => 9, 'season_id' => 4, 'games' => 4 ), $ind, array( 'date' => null, 'event_id' => 0, 'team_id' => 0, 'remaining' => 0 ) );
assert_test( 0 === $row['games'] && null === $row['eligible_on'], 'indefinite: games forced to 0 and no eligible date' );

$row = $s::build_row( array( 'player_id' => 9, 'season_id' => 4 ), $inf, array( 'date' => null, 'event_id' => 0, 'team_id' => 0, 'remaining' => 2 ) );
assert_test( null === $row['eligible_on'], 'schedule shortage leaves eligible_on null' );

echo "\n=== is_duplicate() ===\n\n";
$existing = array(
	(object) array( 'player_id' => 9, 'infraction_id' => 5, 'incident_event_id' => 70, 'status' => 'sent', 'created_at' => '2026-10-07 10:00:00', 'source' => 'manual' ),
);
assert_test( $s::is_duplicate( $existing, 9, 5, 70, '2026-10-07' ), 'same player + infraction + incident match is a duplicate' );
assert_test( ! $s::is_duplicate( $existing, 9, 5, 71, '2026-10-07' ), 'different incident match is not a duplicate' );
assert_test( ! $s::is_duplicate( $existing, 9, 6, 70, '2026-10-07' ), 'different infraction is not a duplicate' );
assert_test( ! $s::is_duplicate( $existing, 10, 5, 70, '2026-10-07' ), 'different player is not a duplicate' );
$revoked = array( (object) array( 'player_id' => 9, 'infraction_id' => 5, 'incident_event_id' => 70, 'status' => 'revoked', 'created_at' => '2026-10-07 10:00:00', 'source' => 'manual' ) );
assert_test( ! $s::is_duplicate( $revoked, 9, 5, 70, '2026-10-07' ), 'a revoked notice does not block re-issuing' );
$nomatch = array( (object) array( 'player_id' => 9, 'infraction_id' => 5, 'incident_event_id' => 0, 'status' => 'sent', 'created_at' => '2026-10-07 10:00:00', 'source' => 'manual' ) );
assert_test( $s::is_duplicate( $nomatch, 9, 5, 0, '2026-10-07' ), 'no incident match: same player + infraction + same day is a duplicate' );
assert_test( ! $s::is_duplicate( $nomatch, 9, 5, 0, '2026-10-08' ), 'no incident match: a later day is not' );

echo "\n=== summary_line() ===\n\n";
$rows = array(
	(object) array( 'consequence' => 'suspend', 'games' => 2, 'season_id' => 1, 'status' => 'sent' ),
	(object) array( 'consequence' => 'suspend', 'games' => 2, 'season_id' => 2, 'status' => 'served' ),
	(object) array( 'consequence' => 'suspend', 'games' => 0, 'season_id' => 2, 'status' => 'sent' ),
	(object) array( 'consequence' => 'warn', 'games' => 0, 'season_id' => 2, 'status' => 'sent' ),
	(object) array( 'consequence' => 'warn', 'games' => 0, 'season_id' => 2, 'status' => 'sent' ),
	(object) array( 'consequence' => 'suspend', 'games' => 9, 'season_id' => 2, 'status' => 'baseline' ),
	(object) array( 'consequence' => 'suspend', 'games' => 9, 'season_id' => 2, 'status' => 'revoked' ),
	(object) array( 'consequence' => 'warn', 'games' => 0, 'season_id' => 2, 'status' => 'discarded' ),
);
assert_test( '3 suspensions (4 games), 2 warnings, across 2 seasons.' === $s::summary_line( $rows ), 'counts issued suspensions/games/warnings; ignores baseline, revoked and discarded' );
assert_test( 'No disciplinary record.' === $s::summary_line( array() ), 'empty history reads as no record' );
$one = array( (object) array( 'consequence' => 'suspend', 'games' => 1, 'season_id' => 1, 'status' => 'sent' ) );
assert_test( '1 suspension (1 game), 0 warnings, across 1 season.' === $s::summary_line( $one ), 'singular forms' );

echo "\n=== plan_captain_mail() ===\n\n";
$plan = $s::plan_captain_mail(
	array(
		array( 'team_id' => 1, 'team' => 'Wolves', 'email' => 'Cap@Example.com' ),
		array( 'team_id' => 2, 'team' => 'Bears', 'email' => 'cap@example.com' ),
	),
	'player@example.com',
	array()
);
assert_test( 1 === count( $plan ) && array( 'Wolves', 'Bears' ) === $plan[0]['teams'] && '' === $plan[0]['covered_by'], 'same captain on two teams gets one entry listing both teams' );

$plan = $s::plan_captain_mail( array( array( 'team_id' => 1, 'team' => 'Wolves', 'email' => 'Player@Example.com' ) ), 'player@example.com', array() );
assert_test( 'player' === $plan[0]['covered_by'], 'captain who is the player (different case) is covered by the player copy' );

$plan = $s::plan_captain_mail( array( array( 'team_id' => 1, 'team' => 'Wolves', 'email' => 'conv@example.com' ) ), 'player@example.com', array( 'Conv@Example.com' ) );
assert_test( 'bcc' === $plan[0]['covered_by'], 'captain in the Bcc list is covered by the Bcc copy' );

$plan = $s::plan_captain_mail( array( array( 'team_id' => 1, 'team' => 'Wolves', 'email' => '' ) ), 'player@example.com', array() );
assert_test( 1 === count( $plan ) && '' === $plan[0]['email'] && '' === $plan[0]['covered_by'] && array( 'Wolves' ) === $plan[0]['teams'], 'captain with no address is kept, uncovered, so it records as not notified' );

$plan = $s::plan_captain_mail(
	array(
		array( 'team_id' => 1, 'team' => 'Wolves', 'email' => 'a@example.com' ),
		array( 'team_id' => 2, 'team' => 'Bears', 'email' => 'b@example.com' ),
	),
	'player@example.com',
	array()
);
assert_test( 2 === count( $plan ) && 'a@example.com' === $plan[0]['email'] && 'b@example.com' === $plan[1]['email'], 'two different captains give two entries' );

echo "\n=== deliver() guards ===\n\n";
foreach ( array( 'pending' => true, 'failed' => true, 'sent' => false, 'revoked' => false, 'discarded' => false, 'baseline' => false, '' => false ) as $st => $want ) {
	assert_test( $want === $s::can_deliver( $st ), "can_deliver('{$st}') is " . ( $want ? 'true' : 'false' ) );
}
assert_test( array( 'b@x.test' ) === $s::bcc_without( array( 'A@X.test', 'b@x.test' ), 'a@x.TEST' ), 'bcc_without removes the player case-insensitively' );
assert_test( array( 'a@x.test', 'b@x.test' ) === $s::bcc_without( array( 'a@x.test', 'b@x.test' ), 'c@x.test' ), 'bcc_without keeps others, reindexed' );
assert_test( array() === $s::bcc_without( array(), 'a@x.test' ), 'bcc_without on empty list' );

$row = $s::build_row( array( 'player_id' => 9, 'season_id' => 4, 'games' => 500 ), $inf, $elig );
assert_test( SPLM_Discipline_Infraction::MAX_GAMES === $row['games'], 'convener games override clamped to MAX_GAMES' );
$row = $s::build_row( array( 'player_id' => 9, 'season_id' => 4, 'games' => 20 ), $inf, $elig );
assert_test( 20 === $row['games'], 'games at the cap is unchanged' );

echo "\n=== summary_line() supersession ===\n\n";
function splm_sum_row( $id, $parent, $consequence, $games, $status = 'sent' ) {
	return (object) array( 'id' => $id, 'parent_id' => $parent, 'status' => $status, 'consequence' => $consequence, 'games' => $games, 'season_id' => 4 );
}
$line = $s::summary_line( array( splm_sum_row( 1, 0, 'suspend', 2 ), splm_sum_row( 2, 1, 'suspend', 3 ) ) );
assert_test( false !== strpos( $line, '1 suspension (3 games)' ), 'amended suspension counts once at its latest length' );
$line = $s::summary_line( array( splm_sum_row( 1, 0, 'suspend', 0 ), splm_sum_row( 2, 1, 'suspend', 4 ) ) );
assert_test( false !== strpos( $line, '1 suspension (4 games)' ), 'decided indefinite counts once at the decided length' );
$line = $s::summary_line( array( splm_sum_row( 1, 0, 'suspend', 3, 'revoked' ), splm_sum_row( 2, 1, 'none', 0 ) ) );
assert_test( 'No disciplinary record.' === $line, 'a revoke chain counts zero' );
$line = $s::summary_line( array( splm_sum_row( 1, 0, 'suspend', 3 ), splm_sum_row( 2, 1, 'suspend', 5, 'discarded' ) ) );
assert_test( '1 suspension (3 games), 0 warnings, across 1 season.' === $line, 'a discarded amend leaves the parent standing' );
$line = $s::summary_line( array( splm_sum_row( 1, 0, 'suspend', 2 ), splm_sum_row( 2, 1, 'suspend', 3, 'failed' ) ) );
assert_test( false !== strpos( $line, '1 suspension (3 games)' ), 'a failed child still supersedes its parent' );
$line = $s::summary_line( array( (object) array( 'status' => 'sent', 'consequence' => 'suspend', 'games' => 2, 'season_id' => 4 ) ) );
assert_test( false !== strpos( $line, '1 suspension (2 games)' ), 'rows without id/parent_id still count' );

echo "\n=== row_to_response() shaping ===\n\n";
function get_the_title( $id ) { return 'Player ' . $id; } // phpcs:ignore
require_once __DIR__ . '/../includes/class-discipline-notice-rest.php';
$legacy = (object) array(
	'id' => 1, 'player_id' => 9, 'season_id' => 4, 'tier_key' => 't', 'ack_key' => 'a', 'scope' => 'season', 'severity' => 'warn',
	'consequence' => 'warn', 'games' => 0, 'value_at_fire' => 5, 'season_at_fire' => 5, 'team' => 'W', 'division' => 'D',
	'status' => 'sent', 'recipient' => '', 'recipient_via' => '', 'bcc' => '', 'sent_at' => '', 'served_at' => '',
	'released_by' => 0, 'last_error' => '', 'note' => '', 'created_at' => '',
);
$rest = 'SPLM_Discipline_Notice_REST';
$out  = $rest::row_to_response( $legacy );
assert_test( 'auto' === $out['source'] && 'games' === $out['outcome'] && 0 === $out['infraction_id'] && 0 === $out['parent_id'], 'legacy row gets defaults' );
assert_test( '' === $out['eligible_on'] && array() === $out['captains_notified'] && 0 === $out['incident_event_id'], 'legacy row: empty eligible_on, captains, event' );
assert_test( ! array_key_exists( 'incident_note', $out ), 'legacy row has no incident_note' );
$new = clone $legacy;
foreach ( array( 'source' => 'manual', 'infraction_id' => '5', 'rule_ref' => '6.5', 'infraction_title' => 'Fighting', 'rule_text' => 'RT', 'outcome' => 'games', 'incident_event_id' => '77', 'incident_note' => 'private', 'parent_id' => '2', 'eligible_on' => null, 'captains_notified' => '[{"team":"W","sent":true}]' ) as $k => $v ) {
	$new->$k = $v;
}
$out = $rest::row_to_response( $new );
assert_test( 'manual' === $out['source'] && 5 === $out['infraction_id'] && '6.5' === $out['rule_ref'] && 77 === $out['incident_event_id'] && 2 === $out['parent_id'], 'new row fields shaped and cast' );
assert_test( ! array_key_exists( 'incident_note', $out ), 'incident_note withheld by default' );
assert_test( '' === $out['eligible_on'], 'null eligible_on becomes empty string' );
assert_test( array( array( 'team' => 'W', 'sent' => true ) ) === $out['captains_notified'], 'captains_notified JSON decoded' );
$out = $rest::row_to_response( $new, true );
assert_test( 'private' === $out['incident_note'], 'incident_note present when requested' );
$new->captains_notified = 'not json';
assert_test( array() === $rest::row_to_response( $new )['captains_notified'], 'invalid captains_notified JSON becomes []' );

echo "\n=== build_child_row() ===\n\n";
$parent = (object) array(
	'id' => 40, 'player_id' => 9, 'season_id' => 4, 'severity' => 'critical', 'ack_key' => 'manual:5', 'team' => 'Wolves', 'division' => '',
	'infraction_id' => 5, 'rule_ref' => '6.5', 'infraction_title' => 'Fighting', 'rule_text' => 'RT', 'outcome' => 'games',
	'incident_event_id' => 70, 'incident_note' => 'private', 'games' => 3, 'eligible_on' => '2026-10-27',
);
$dec = $s::build_child_row( $parent, 'decided', array( 'games' => 4, 'eligible_on' => '2026-11-03 21:00:00' ) );
assert_test( 'manual-decided' === $dec['scope'] && 40 === $dec['parent_id'] && 'manual' === $dec['source'] && 'manual' === $dec['tier_key'], 'decided: scope, parent, source, tier_key' );
assert_test( 'manual:5' === $dec['ack_key'] && 'critical' === $dec['severity'] && 9 === $dec['player_id'] && 4 === $dec['season_id'], 'decided: ack_key, severity, player and season copied' );
assert_test( 'games' === $dec['outcome'] && 4 === $dec['games'] && 'suspend' === $dec['consequence'] && 'pending' === $dec['status'], 'decided: games outcome, 4 games, suspend, pending' );
assert_test( '2026-11-03' === $dec['eligible_on'], 'decided: eligible_on trimmed to the date' );
assert_test( 5 === $dec['infraction_id'] && '6.5' === $dec['rule_ref'] && 'Fighting' === $dec['infraction_title'] && 'RT' === $dec['rule_text'] && 70 === $dec['incident_event_id'] && 'Wolves' === $dec['team'], 'decided: snapshot and incident reference copied' );
assert_test( '' === $dec['incident_note'], 'decided: the parent private note is not duplicated onto the child' );
$ind_parent = clone $parent;
$ind_parent->outcome = 'indefinite';
$ind_parent->games   = 0;
assert_test( 'games' === $s::build_child_row( $ind_parent, 'decided', array( 'games' => 2 ) )['outcome'], 'decided: an indefinite parent becomes a games outcome' );
assert_test( 1 === $s::build_child_row( $parent, 'decided', array( 'games' => 0 ) )['games'], 'decided: games clamped up to 1' );
assert_test( 20 === $s::build_child_row( $parent, 'decided', array( 'games' => 99 ) )['games'], 'decided: games clamped down to 20' );
assert_test( null === $s::build_child_row( $parent, 'decided', array( 'games' => 2 ) )['eligible_on'], 'decided: no eligible_on field gives null' );

$amd = $s::build_child_row( $parent, 'amended', array( 'games' => 6, 'eligible_on' => '2026-11-10' ) );
assert_test( 'manual-amended' === $amd['scope'] && 6 === $amd['games'] && 'games' === $amd['outcome'] && 'suspend' === $amd['consequence'] && 'pending' === $amd['status'], 'amended: scope, games, outcome, suspend, pending' );
assert_test( '2026-11-10' === $amd['eligible_on'], 'amended: eligible_on from fields' );
assert_test( 0 === $s::build_child_row( $parent, 'amended', array( 'games' => -3 ) )['games'], 'amended: games may be clamped down to 0' );
assert_test( 20 === $s::build_child_row( $parent, 'amended', array( 'games' => 500 ) )['games'], 'amended: games clamped to 20' );

$rev = $s::build_child_row( $parent, 'revoked', array() );
assert_test( 'manual-revoked' === $rev['scope'] && 'none' === $rev['consequence'] && 0 === $rev['games'] && null === $rev['eligible_on'], 'revoked: scope, consequence none, 0 games, no eligible_on' );
assert_test( 'pending' === $rev['status'], 'revoked: pending by default' );
assert_test( 'pending' === $s::build_child_row( $parent, 'revoked', array( 'notify' => true ) )['status'], 'revoked: notify=true stays pending' );
assert_test( 'discarded' === $s::build_child_row( $parent, 'revoked', array( 'notify' => false ) )['status'], 'revoked: notify=false is stored discarded' );
assert_test( 40 === $rev['parent_id'] && 'games' === $rev['outcome'] && 70 === $rev['incident_event_id'], 'revoked: parent link and snapshot copied' );

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
