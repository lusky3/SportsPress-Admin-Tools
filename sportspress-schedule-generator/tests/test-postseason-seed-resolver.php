<?php
/**
 * Test Postseason Seed Resolver
 *
 * Phase 4 of the postseason/playoffs design (phase 1: SPLM_Standings in
 * sportspress-league-manager; phase 2: SPSG_Postseason_Pairing; phase 3:
 * postseason config schema): SPSG_Postseason_Seed_Resolver's placeholder
 * naming/minting, idempotent resolution, and automatic-mode completion
 * detection.
 *
 * SPSG_Placeholder_Team_Manager is stubbed out entirely rather than loaded
 * (and its own, much deeper WordPress dependencies mocked) -- it's an
 * existing, separately-maintained class; this file tests only
 * SPSG_Postseason_Seed_Resolver's own logic, via a call-recording double
 * with the same static-method signatures.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

class SPSR_Test_State {
	/** post_id => array( meta_key => value ). */
	public $meta = array();

	/** Every SPSG_Placeholder_Team_Manager::create_placeholder_team() call's args. */
	public $create_calls = array();

	/** Every SPSG_Placeholder_Team_Manager::replace_team() call's args. */
	public $replace_calls = array();

	/** team_id => bool, whether is_placeholder() reports it as a live placeholder. */
	public $is_placeholder = array();

	/** Next id create_placeholder_team() mints. */
	public $next_team_id = 500;

	/** Set to a WP_Error to make the NEXT create_placeholder_team() call fail. */
	public $next_create_failure = null;
}

function sr_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPSR_Test_State();
	}
	return $state;
}

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function get_post_meta( $post_id, $key, $single = false ) {
	$state = sr_test_state();
	$value = $state->meta[ (int) $post_id ][ $key ] ?? ( $single ? '' : array() );
	return $value;
}

/**
 * Test double for SPSG_Placeholder_Team_Manager -- same static-method
 * signatures as the real class, recording every call instead of touching
 * WordPress. Named identically so SPSG_Postseason_Seed_Resolver's calls to
 * SPSG_Placeholder_Team_Manager::... resolve to this class.
 */
class SPSG_Placeholder_Team_Manager {
	public static function create_placeholder_team( $team_name, $config_id = '', $division = '' ) {
		$state = sr_test_state();
		$state->create_calls[] = array( 'name' => $team_name, 'config_id' => $config_id, 'division' => $division );

		if ( null !== $state->next_create_failure ) {
			$failure                    = $state->next_create_failure;
			$state->next_create_failure = null;
			return $failure;
		}

		$id                            = $state->next_team_id++;
		$state->is_placeholder[ $id ]  = true;
		return $id;
	}

	public static function is_placeholder( $team_id ) {
		$state = sr_test_state();
		return ! empty( $state->is_placeholder[ $team_id ] );
	}

	public static function replace_team( $placeholder_id, $replacement_id, $delete_placeholder = true ) {
		$state                  = sr_test_state();
		$state->replace_calls[] = array(
			'placeholder_id' => $placeholder_id,
			'replacement_id' => $replacement_id,
			'delete'         => $delete_placeholder,
		);
		$state->is_placeholder[ $placeholder_id ] = false; // matches the real class's post-resolution state

		return array( 'events_updated' => 1, 'placeholder_status' => 'trashed', 'errors' => array() );
	}
}

require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-seed-resolver.php';

$passed = 0;
$failed = 0;

function sr_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		$passed++;
	} else {
		echo "✗ FAIL: $message\n";
		$failed++;
	}
}

echo "=== seed_placeholder_names(): pure naming ===\n\n";

$names = SPSG_Postseason_Seed_Resolver::seed_placeholder_names( 'Div 1', 3, SPSG_Postseason_Seed_Resolver::SEED_STAGE );
sr_assert(
	array( 1 => 'Div 1 Seed 1', 2 => 'Div 1 Seed 2', 3 => 'Div 1 Seed 3' ) === $names,
	'Seed stage names follow "<division> Seed <n>" exactly, 1-indexed'
);

$rr_names = SPSG_Postseason_Seed_Resolver::seed_placeholder_names( 'Div 1', 2, SPSG_Postseason_Seed_Resolver::RR_SEED_STAGE );
sr_assert(
	array( 1 => 'Div 1 RR-Seed 1', 2 => 'Div 1 RR-Seed 2' ) === $rr_names,
	'RR-Seed stage names follow "<division> RR-Seed <n>" exactly'
);

$threw = false;
try {
	SPSG_Postseason_Seed_Resolver::seed_placeholder_names( 'Div 1', 0, SPSG_Postseason_Seed_Resolver::SEED_STAGE );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
sr_assert( $threw, 'team_count=0 throws InvalidArgumentException' );

$threw = false;
try {
	SPSG_Postseason_Seed_Resolver::seed_placeholder_names( 'Div 1', 4, 'bogus-stage' );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
sr_assert( $threw, 'an unrecognized stage throws InvalidArgumentException' );

echo "\n=== mint_division_placeholders(): mints Seed and RR-Seed placeholders via SPSG_Placeholder_Team_Manager ===\n\n";

$state = sr_test_state();
$state->create_calls = array();

$minted = SPSG_Postseason_Seed_Resolver::mint_division_placeholders( 'Div 1', 3, 'config_playoffs1' );

sr_assert( 6 === count( $state->create_calls ), 'create_placeholder_team() is called exactly 6 times for a 3-team division (3 Seed + 3 RR-Seed)' );
sr_assert( 3 === count( $minted['seed'] ) && 3 === count( $minted['rr_seed'] ), 'returns 3 seed ids and 3 rr_seed ids' );
sr_assert(
	'Div 1 Seed 1' === $state->create_calls[0]['name'] && 'config_playoffs1' === $state->create_calls[0]['config_id'] && 'Div 1' === $state->create_calls[0]['division'],
	'each create_placeholder_team() call carries the right name, config_id, and division'
);
sr_assert(
	array_values( $minted['seed'] ) !== array_values( $minted['rr_seed'] ),
	'Seed and RR-Seed placeholders are distinct teams, not the same ids reused'
);

echo "\n=== mint_division_placeholders(): a failed creation is skipped, not fatal ===\n\n";

$state->create_calls = array();
$state->next_create_failure = new WP_Error( 'db_error', 'could not insert' );

$minted_with_failure = SPSG_Postseason_Seed_Resolver::mint_division_placeholders( 'Div 2', 2, '' );
sr_assert(
	1 === count( $minted_with_failure['seed'] ) && ! isset( $minted_with_failure['seed'][1] ),
	'seed 1\'s failed creation is simply omitted from the result -- seed 2 still succeeds'
);

echo "\n=== resolve_seeds(): normal resolution ===\n\n";

$state->create_calls = array();
$state->replace_calls = array();
$division_placeholders = SPSG_Postseason_Seed_Resolver::mint_division_placeholders( 'Div 3', 3, 'config_x' )['seed'];

$results = SPSG_Postseason_Seed_Resolver::resolve_seeds( $division_placeholders, array( 1 => 901, 2 => 902, 3 => 903 ) );

sr_assert( 3 === count( $state->replace_calls ), 'replace_team() is called once per seed' );
sr_assert(
	$state->replace_calls[0]['placeholder_id'] === $division_placeholders[1] && 901 === $state->replace_calls[0]['replacement_id'],
	'seed 1\'s placeholder is replaced with the rank-1 team'
);
sr_assert( 3 === count( $results ), 'returns one result per resolved seed' );

echo "\n=== resolve_seeds(): idempotent -- an already-resolved placeholder is skipped, not re-replaced ===\n\n";

$state->create_calls  = array();
$state->replace_calls = array();
$idempotency_placeholders = SPSG_Postseason_Seed_Resolver::mint_division_placeholders( 'Div 4', 3, 'config_y' )['seed'];
// Simulate seed 2 already having been resolved by an earlier call -- seeds 1
// and 3 are freshly-minted, still-live placeholders.
$state->is_placeholder[ $idempotency_placeholders[2] ] = false;

$results_again = SPSG_Postseason_Seed_Resolver::resolve_seeds( $idempotency_placeholders, array( 1 => 901, 2 => 902, 3 => 903 ) );

sr_assert( 2 === count( $state->replace_calls ), 'replace_team() is called for seeds 1 and 3 only -- seed 2 is skipped' );
sr_assert( ! isset( $results_again[2] ), 'the result array omits the already-resolved seed entirely' );
sr_assert( isset( $results_again[1] ) && isset( $results_again[3] ), '...but still reports results for the seeds that were actually resolved' );

echo "\n=== resolve_seeds(): a seed number with no corresponding ranked team is skipped ===\n\n";

$state->replace_calls = array();
$partial_placeholders = SPSG_Postseason_Seed_Resolver::mint_division_placeholders( 'Div 5', 3, 'config_z' )['seed'];

$partial_ranking = SPSG_Postseason_Seed_Resolver::resolve_seeds( $partial_placeholders, array( 1 => 901 ) ); // no rank 2 or 3
sr_assert( 1 === count( $state->replace_calls ), 'only seed 1 (the only seed with a ranked team) is resolved' );
sr_assert( 1 === count( $partial_ranking ) && isset( $partial_ranking[1] ), 'the result array reflects only the one resolved seed' );

echo "\n=== is_range_complete(): automatic-mode completion detection ===\n\n";

$state->meta = array();

sr_assert( false === SPSG_Postseason_Seed_Resolver::is_range_complete( array() ), 'an empty event list is never "complete"' );

$state->meta[701] = array(
	'sp_team'    => array( 10, 20 ),
	'sp_results' => array( 10 => array( 'outcome' => 'win' ), 20 => array( 'outcome' => 'loss' ) ),
);
sr_assert(
	true === SPSG_Postseason_Seed_Resolver::is_range_complete( array( 701 ) ),
	'an event with a recorded outcome for every team is decided'
);

$state->meta[702] = array(
	'sp_team'    => array( 30, 40 ),
	'sp_results' => array( 30 => array( 'outcome' => 'win' ) ), // team 40 has no outcome yet
);
sr_assert(
	false === SPSG_Postseason_Seed_Resolver::is_range_complete( array( 701, 702 ) ),
	'the whole range is incomplete if even one event is missing a team\'s outcome'
);

$state->meta[703] = array(
	'sp_team'    => array( 50, 60 ),
	'sp_results' => array( 50 => array( 'outcome' => 'forfeit' ), 60 => array( 'outcome' => 'forfeit' ) ),
);
sr_assert(
	true === SPSG_Postseason_Seed_Resolver::is_range_complete( array( 701, 703 ) ),
	'a forfeit is just another recorded outcome -- no special-casing needed for it to count as decided'
);

$state->meta[704] = array( 'sp_team' => array() ); // no teams assigned at all
sr_assert(
	false === SPSG_Postseason_Seed_Resolver::is_range_complete( array( 704 ) ),
	'an event with no teams assigned yet is never decided'
);

echo "\n=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo 'Total: ' . ( $passed + $failed ) . "\n";

if ( 0 === $failed ) {
	echo "\n✓ All tests passed!\n";
	exit( 0 );
} else {
	echo "\n✗ Some tests failed\n";
	exit( 1 );
}
