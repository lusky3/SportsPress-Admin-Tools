<?php
/**
 * Standalone tests for SPPA_Schema.
 *
 * Usage: php test-schema.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-schema.php';

echo "\n=== create_sql ===\n\n";

$sql = SPPA_Schema::create_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' );

sppa_assert( 5 === count( $sql ), 'create_sql yields 5 statements' );

foreach ( $sql as $i => $statement ) {
	sppa_assert( 0 === strpos( $statement, 'CREATE TABLE wp_sppa_' ), "statement {$i} starts CREATE TABLE wp_sppa_" );
	sppa_assert( false !== strpos( $statement, "\n\tPRIMARY KEY  (id)" ), "statement {$i} uses dbDelta's two-space PRIMARY KEY" );
}

$by_table = array();
foreach ( $sql as $statement ) {
	preg_match( '/^CREATE TABLE (\S+) \(/', $statement, $m );
	$by_table[ $m[1] ] = $statement;
}

sppa_assert(
	array( 'wp_sppa_availability', 'wp_sppa_spare_calls', 'wp_sppa_spare_responses', 'wp_sppa_changes', 'wp_sppa_game_log' ) === array_keys( $by_table ),
	'tables are availability, spare_calls, spare_responses, changes, game_log in that order'
);
sppa_assert( false !== strpos( $by_table['wp_sppa_game_log'], 'UNIQUE KEY event_uid (event_id,uid)' ), 'game_log: UNIQUE KEY event_uid, so a retried entry cannot be stored twice' );
sppa_assert( false !== strpos( $by_table['wp_sppa_game_log'], 'KEY event_active (event_id,voided_at)' ), 'game_log: KEY event_active' );
foreach ( array( 'uid char(36) NOT NULL', 'type varchar(10)', 'clock varchar(8) DEFAULT NULL', 'jersey varchar(6) DEFAULT NULL', 'offense varchar(24) DEFAULT NULL', 'flags varchar(40) DEFAULT NULL', 'text varchar(140) DEFAULT NULL', 'needs_review tinyint(1)', 'recorded_at datetime NOT NULL', 'voided_at datetime DEFAULT NULL' ) as $column ) {
	sppa_assert( false !== strpos( $by_table['wp_sppa_game_log'], $column ), "game_log: {$column}" );
}
sppa_assert( false !== strpos( $by_table['wp_sppa_availability'], 'UNIQUE KEY event_player (event_id,player_id)' ), 'availability: UNIQUE KEY event_player' );
sppa_assert( false !== strpos( $by_table['wp_sppa_spare_responses'], 'UNIQUE KEY call_user (call_id,user_id)' ), 'spare_responses: UNIQUE KEY call_user' );
sppa_assert( false !== strpos( $by_table['wp_sppa_changes'], 'KEY event_changed (event_id,changed_at)' ), 'changes: KEY event_changed' );
sppa_assert( false !== strpos( $by_table['wp_sppa_spare_calls'], 'KEY event_id (event_id)' ), 'spare_calls: KEY event_id' );
sppa_assert( false !== strpos( $by_table['wp_sppa_spare_calls'], 'KEY status (status)' ), 'spare_calls: KEY status' );
sppa_assert( false !== strpos( $by_table['wp_sppa_availability'], 'note varchar(140)' ), 'availability note is varchar(140)' );
sppa_assert( false !== strpos( $by_table['wp_sppa_changes'], 'before_json longtext' ) && false !== strpos( $by_table['wp_sppa_changes'], 'after_json longtext' ), 'changes carries before_json and after_json' );

// dbDelta wants one field per line: no two column definitions share a line.
foreach ( $by_table as $name => $statement ) {
	$bad = false;
	foreach ( explode( "\n", $statement ) as $line ) {
		if ( substr_count( $line, ' NOT NULL' ) > 1 ) {
			$bad = true;
		}
	}
	sppa_assert( ! $bad, "{$name}: one field per line" );
}

sppa_assert( false !== strpos( $sql[0], 'DEFAULT CHARACTER SET utf8mb4' ), 'charset collate is appended' );

echo "\n=== tables ===\n\n";

$tables = SPPA_Schema::tables();
sppa_assert(
	array(
		'availability'    => 'wp_sppa_availability',
		'spare_calls'     => 'wp_sppa_spare_calls',
		'spare_responses' => 'wp_sppa_spare_responses',
		'changes'         => 'wp_sppa_changes',
		'game_log'        => 'wp_sppa_game_log',
	) === $tables,
	'tables() maps logical name to prefixed table'
);

$GLOBALS['wpdb']->prefix = 'x9_';
sppa_assert( 'x9_sppa_changes' === SPPA_Schema::tables()['changes'], 'tables() follows $wpdb->prefix' );
$GLOBALS['wpdb']->prefix = 'wp_';

echo "\n=== maybe_upgrade ===\n\n";

$GLOBALS['sppa_dbdelta'] = array();
function dbDelta( $sql ) {
	$GLOBALS['sppa_dbdelta'][] = $sql;
	return array();
}

sppa_assert( '2' === SPPA_Schema::DB_VERSION, 'DB_VERSION is 2 (the game log)' );

SPPA_Schema::maybe_upgrade();
sppa_assert( 5 === count( $GLOBALS['sppa_dbdelta'] ), 'first run dbDeltas all five tables' );
sppa_assert( '2' === get_option( 'sppa_db_version' ), 'first run stores sppa_db_version' );

SPPA_Schema::maybe_upgrade();
sppa_assert( 5 === count( $GLOBALS['sppa_dbdelta'] ), 'second run at the same version is a no-op' );

update_option( 'sppa_db_version', '1' );
SPPA_Schema::maybe_upgrade();
sppa_assert( 10 === count( $GLOBALS['sppa_dbdelta'] ) && false !== strpos( end( $GLOBALS['sppa_dbdelta'] ), 'sppa_game_log' ), 'an install at version 1 re-runs dbDelta, which adds the game log' );

sppa_done();
