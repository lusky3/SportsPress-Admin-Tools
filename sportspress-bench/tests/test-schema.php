<?php
/**
 * Standalone tests for SPBN_Schema.
 *
 * Usage: php test-schema.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-schema.php';

echo "\n=== create_sql ===\n\n";

$sql = SPBN_Schema::create_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' );

spbn_assert( 4 === count( $sql ), 'create_sql yields 4 statements' );

foreach ( $sql as $i => $statement ) {
	spbn_assert( 0 === strpos( $statement, 'CREATE TABLE wp_spbn_' ), "statement {$i} starts CREATE TABLE wp_spbn_" );
	spbn_assert( false !== strpos( $statement, "\n\tPRIMARY KEY  (id)" ), "statement {$i} uses dbDelta's two-space PRIMARY KEY" );
}

$by_table = array();
foreach ( $sql as $statement ) {
	preg_match( '/^CREATE TABLE (\S+) \(/', $statement, $m );
	$by_table[ $m[1] ] = $statement;
}

spbn_assert(
	array( 'wp_spbn_availability', 'wp_spbn_spare_calls', 'wp_spbn_spare_responses', 'wp_spbn_changes' ) === array_keys( $by_table ),
	'tables are availability, spare_calls, spare_responses, changes in that order'
);
spbn_assert( false !== strpos( $by_table['wp_spbn_availability'], 'UNIQUE KEY event_player (event_id,player_id)' ), 'availability: UNIQUE KEY event_player' );
spbn_assert( false !== strpos( $by_table['wp_spbn_spare_responses'], 'UNIQUE KEY call_user (call_id,user_id)' ), 'spare_responses: UNIQUE KEY call_user' );
spbn_assert( false !== strpos( $by_table['wp_spbn_changes'], 'KEY event_changed (event_id,changed_at)' ), 'changes: KEY event_changed' );
spbn_assert( false !== strpos( $by_table['wp_spbn_spare_calls'], 'KEY event_id (event_id)' ), 'spare_calls: KEY event_id' );
spbn_assert( false !== strpos( $by_table['wp_spbn_spare_calls'], 'KEY status (status)' ), 'spare_calls: KEY status' );
spbn_assert( false !== strpos( $by_table['wp_spbn_availability'], 'note varchar(140)' ), 'availability note is varchar(140)' );
spbn_assert( false !== strpos( $by_table['wp_spbn_changes'], 'before_json longtext' ) && false !== strpos( $by_table['wp_spbn_changes'], 'after_json longtext' ), 'changes carries before_json and after_json' );

// dbDelta wants one field per line: no two column definitions share a line.
foreach ( $by_table as $name => $statement ) {
	$bad = false;
	foreach ( explode( "\n", $statement ) as $line ) {
		if ( substr_count( $line, ' NOT NULL' ) > 1 ) {
			$bad = true;
		}
	}
	spbn_assert( ! $bad, "{$name}: one field per line" );
}

spbn_assert( false !== strpos( $sql[0], 'DEFAULT CHARACTER SET utf8mb4' ), 'charset collate is appended' );

echo "\n=== tables ===\n\n";

$tables = SPBN_Schema::tables();
spbn_assert(
	array(
		'availability'    => 'wp_spbn_availability',
		'spare_calls'     => 'wp_spbn_spare_calls',
		'spare_responses' => 'wp_spbn_spare_responses',
		'changes'         => 'wp_spbn_changes',
	) === $tables,
	'tables() maps logical name to prefixed table'
);

$GLOBALS['wpdb']->prefix = 'x9_';
spbn_assert( 'x9_spbn_changes' === SPBN_Schema::tables()['changes'], 'tables() follows $wpdb->prefix' );
$GLOBALS['wpdb']->prefix = 'wp_';

echo "\n=== maybe_upgrade ===\n\n";

$GLOBALS['spbn_dbdelta'] = array();
function dbDelta( $sql ) {
	$GLOBALS['spbn_dbdelta'][] = $sql;
	return array();
}

spbn_assert( '1' === SPBN_Schema::DB_VERSION, 'DB_VERSION is 1' );

SPBN_Schema::maybe_upgrade();
spbn_assert( 4 === count( $GLOBALS['spbn_dbdelta'] ), 'first run dbDeltas all four tables' );
spbn_assert( '1' === get_option( 'spbn_db_version' ), 'first run stores spbn_db_version' );

SPBN_Schema::maybe_upgrade();
spbn_assert( 4 === count( $GLOBALS['spbn_dbdelta'] ), 'second run at the same version is a no-op' );

update_option( 'spbn_db_version', '0' );
SPBN_Schema::maybe_upgrade();
spbn_assert( 8 === count( $GLOBALS['spbn_dbdelta'] ), 'an older stored version re-runs dbDelta' );

spbn_done();
