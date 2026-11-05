<?php
/**
 * Turns one raw SportsPress event into the API's game object, from one team's
 * point of view.
 *
 * Pure: no WordPress calls, so it is unit-testable and the roster reader owns
 * every database read.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPA_Event_Presenter {

	/**
	 * Normalised statuses a game can have in the API.
	 */
	const STATUSES = array( 'ok', 'postponed', 'cancelled', 'tbd' );

	/**
	 * One event as the game object.
	 *
	 * `$raw` is SPPA_Roster_Reader::raw_event(): id, start (ISO 8601), status
	 * (sp_status), spem_cancelled, team_ids (first = home), venue{name,rink,address,map_url},
	 * results (sp_results), result_key, team_names and team_logos by team id.
	 *
	 * A viewer who is on neither side (an organizer) sees the home side as "team".
	 *
	 * @param array $raw            Raw event.
	 * @param int   $viewer_team_id The side whose point of view to take.
	 * @param array $extra          changed_recently, my_availability, counts, as_spare.
	 * @return array
	 */
	public static function present( array $raw, int $viewer_team_id, array $extra = array() ): array {
		$team_ids = array_values( array_map( 'intval', (array) ( $raw['team_ids'] ?? array() ) ) );
		$team_id  = in_array( $viewer_team_id, $team_ids, true ) ? $viewer_team_id : (int) ( $team_ids[0] ?? 0 );
		$opp_id   = 0;
		foreach ( $team_ids as $candidate ) {
			if ( $candidate !== $team_id ) {
				$opp_id = $candidate;
				break;
			}
		}

		$names = (array) ( $raw['team_names'] ?? array() );
		$logos = (array) ( $raw['team_logos'] ?? array() );
		$venue = (array) ( $raw['venue'] ?? array() );

		return array(
			'id'               => (int) ( $raw['id'] ?? 0 ),
			'start'            => (string) ( $raw['start'] ?? '' ),
			'status'           => self::status( (string) ( $raw['status'] ?? '' ), true === ( $raw['spem_cancelled'] ?? false ) ),
			'venue'            => array(
				'name'    => (string) ( $venue['name'] ?? '' ),
				'rink'    => (string) ( $venue['rink'] ?? '' ),
				'address' => (string) ( $venue['address'] ?? '' ),
				'map_url' => (string) ( $venue['map_url'] ?? '' ),
			),
			'team'             => array(
				'id'   => $team_id,
				'name' => (string) ( $names[ $team_id ] ?? '' ),
				'logo' => (string) ( $logos[ $team_id ] ?? '' ),
			),
			'opponent'         => array(
				'id'   => $opp_id,
				'name' => (string) ( $names[ $opp_id ] ?? '' ),
				'logo' => (string) ( $logos[ $opp_id ] ?? '' ),
			),
			'is_home'          => isset( $team_ids[0] ) && $team_ids[0] === $team_id,
			'result'           => self::result( $raw, $team_id, $opp_id ),
			'changed_recently' => (bool) ( $extra['changed_recently'] ?? false ),
			'my_availability'  => $extra['my_availability'] ?? null,
			'counts'           => $extra['counts'] ?? array(
				'in'    => 0,
				'out'   => 0,
				'maybe' => 0,
			),
			'as_spare'         => (bool) ( $extra['as_spare'] ?? false ),
		);
	}

	/**
	 * One team's recorded main result, or null when none is entered.
	 *
	 * SportsPress stores blank placeholders (goals => '') for a game not yet
	 * played; casting '' with (int) would turn every unscored game into a
	 * finished 0-0. Falls back to the legacy 'gf' key that batch writes used.
	 *
	 * @param mixed  $team_result One team's entry from sp_results.
	 * @param string $key         Main-result key (SportsPress default 'goals').
	 * @return int|null
	 */
	public static function main_result( $team_result, string $key ): ?int {
		if ( ! is_array( $team_result ) ) {
			return null;
		}
		foreach ( array( $key, 'gf' ) as $candidate ) {
			if ( isset( $team_result[ $candidate ] ) && '' !== $team_result[ $candidate ] && is_numeric( $team_result[ $candidate ] ) ) {
				return (int) $team_result[ $candidate ];
			}
		}
		return null;
	}

	/**
	 * Whether a normalised status means the game is not going ahead as listed.
	 */
	public static function is_called_off( string $status ): bool {
		return in_array( $status, array( 'postponed', 'cancelled' ), true );
	}

	/**
	 * sp_status ('' means ok) to a normalised status. events-manager's cancel
	 * path leaves sp_status alone and sets _spem_cancelled, which wins.
	 */
	private static function status( string $sp_status, bool $spem_cancelled ): string {
		if ( $spem_cancelled ) {
			return 'cancelled';
		}
		return in_array( $sp_status, self::STATUSES, true ) ? $sp_status : 'ok';
	}

	/**
	 * {for,against,outcome} for the viewer side, or null while either side has
	 * no recorded score.
	 */
	private static function result( array $raw, int $team_id, int $opp_id ): ?array {
		if ( $team_id <= 0 || $opp_id <= 0 ) {
			return null;
		}
		$results = (array) ( $raw['results'] ?? array() );
		$key     = (string) ( $raw['result_key'] ?? '' );
		$key     = '' === $key ? 'goals' : $key;
		$for     = self::main_result( $results[ $team_id ] ?? null, $key );
		$against = self::main_result( $results[ $opp_id ] ?? null, $key );
		if ( null === $for || null === $against ) {
			return null;
		}
		return array(
			'for'     => $for,
			'against' => $against,
			'outcome' => $for > $against ? 'W' : ( $for < $against ? 'L' : 'T' ),
		);
	}
}
