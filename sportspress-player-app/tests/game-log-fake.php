<?php
/**
 * A stateful fake of the wp_sppa_game_log table on the test wpdb, for the
 * store and REST suites. It understands exactly the statements
 * SPPA_Game_Log_Store issues, keeps the UNIQUE (event_id, uid) key, and passes
 * any other SQL to the handlers that were installed before it.
 *
 * Usage: $log = sppa_fake_game_log(); $log->rows holds id => row.
 */

function sppa_fake_game_log() {
	$s = (object) array(
		'rows'        => array(), // id => row (strings and nulls, like wpdb).
		'next'        => 1,
		'fail_insert' => false,
		'race'        => null, // callable( $data ): runs before an insert lands (a competing writer).
	);
	$db             = $GLOBALS['wpdb'];
	$prev_results   = $db->results_handler;
	$prev_query     = $db->query_handler;
	$prev_insert    = $db->insert_handler;
	$columns        = array( 'id', 'event_id', 'uid', 'type', 'team_id', 'period', 'clock', 'player_id', 'assist1_id', 'assist2_id', 'jersey', 'minutes', 'offense', 'flags', 'text', 'needs_review', 'recorded_by', 'recorded_at', 'voided_by', 'voided_at' );
	$as_row         = function ( array $row ) use ( $columns ) {
		$out = array();
		foreach ( $columns as $column ) {
			$value          = $row[ $column ] ?? null;
			$out[ $column ] = null === $value ? null : (string) $value;
		}
		return $out;
	};

	$db->insert_handler = function ( $table, $data, $default_id ) use ( $s, $prev_insert ) {
		if ( 'wp_sppa_game_log' !== $table ) {
			return $prev_insert ? call_user_func( $prev_insert, $table, $data, $default_id ) : $default_id;
		}
		if ( is_callable( $s->race ) ) {
			$race    = $s->race;
			$s->race = null;
			$race( $data );
		}
		foreach ( $s->rows as $row ) {
			if ( (int) $row['event_id'] === (int) $data['event_id'] && $row['uid'] === $data['uid'] ) {
				return false; // The unique key refuses it.
			}
		}
		$id             = $s->next++;
		$s->rows[ $id ] = array( 'id' => $id ) + $data + array(
			'voided_by' => null,
			'voided_at' => null,
		);
		return $id;
	};

	$db->results_handler = function ( $sql ) use ( $s, $prev_results, $as_row ) {
		if ( false === strpos( $sql, 'wp_sppa_game_log' ) ) {
			return $prev_results ? call_user_func( $prev_results, $sql ) : array();
		}
		if ( preg_match( "/WHERE event_id = (\d+) AND uid = '([^']*)' LIMIT 1/", $sql, $m ) ) {
			foreach ( $s->rows as $row ) {
				if ( (int) $row['event_id'] === (int) $m[1] && $row['uid'] === stripslashes( $m[2] ) ) {
					return array( $as_row( $row ) );
				}
			}
			return array();
		}
		if ( preg_match( '/WHERE event_id = (\d+) ORDER BY recorded_at ASC, id ASC/', $sql, $m ) ) {
			$out = array();
			foreach ( $s->rows as $row ) {
				if ( (int) $row['event_id'] === (int) $m[1] ) {
					$out[] = $as_row( $row );
				}
			}
			usort(
				$out,
				function ( $a, $b ) {
					return array( $a['recorded_at'], (int) $a['id'] ) <=> array( $b['recorded_at'], (int) $b['id'] );
				}
			);
			return $out;
		}
		if ( preg_match( '/WHERE event_id IN \(([\d,]+)\) AND voided_at IS NULL GROUP BY event_id, team_id, type, period/', $sql, $m ) ) {
			$ids    = array_map( 'intval', explode( ',', $m[1] ) );
			$groups = array();
			foreach ( $s->rows as $row ) {
				if ( null !== $row['voided_at'] || ! in_array( (int) $row['event_id'], $ids, true ) ) {
					continue;
				}
				$key = implode( '|', array( $row['event_id'], $row['team_id'], $row['type'], $row['period'] ) );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'event_id' => (string) $row['event_id'],
						'team_id'  => (string) $row['team_id'],
						'type'     => (string) $row['type'],
						'period'   => (string) $row['period'],
						'n'        => 0,
						'review'   => 0,
					);
				}
				++$groups[ $key ]['n'];
				$groups[ $key ]['review'] += (int) $row['needs_review'];
			}
			$GLOBALS['sppa_log_count_queries'] = ( $GLOBALS['sppa_log_count_queries'] ?? 0 ) + 1;
			return array_values( $groups );
		}
		return array();
	};

	$db->query_handler = function ( $sql ) use ( $s, $prev_query ) {
		if ( preg_match( "/^UPDATE wp_sppa_game_log SET voided_by = (\d+), voided_at = '([^']*)' WHERE id = (\d+) AND voided_at IS NULL$/", $sql, $m ) ) {
			$id = (int) $m[3];
			if ( isset( $s->rows[ $id ] ) && null === $s->rows[ $id ]['voided_at'] ) {
				$s->rows[ $id ]['voided_by'] = (int) $m[1];
				$s->rows[ $id ]['voided_at'] = $m[2];
				return 1;
			}
			return 0;
		}
		return $prev_query ? call_user_func( $prev_query, $sql ) : 0;
	};

	return $s;
}
