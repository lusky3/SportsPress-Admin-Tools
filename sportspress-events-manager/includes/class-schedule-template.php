<?php
/**
 * Seeds a schedule-generator configuration from a rollover's divisions.
 *
 * Re-entering every division and its teams into the schedule generator is
 * duplicated effort — the rollover has just decided exactly that. This writes a
 * draft configuration the operator can pick from the generator's saved list and
 * finish off with the parts only they know: season dates, venues and time slots.
 *
 * Deliberately does NOT go through SPSG_Configuration_Manager::save(), because
 * that validates a configuration as ready to generate — it requires season
 * dates and venues, which a rollover cannot know. The draft is sanitised with
 * the same sanitiser and stored alongside the others; the generator validates
 * it when the operator actually runs it.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Schedule_Template {

	/**
	 * Option the schedule generator stores its saved configurations in.
	 */
	const OPTION_NAME = 'spsg_configurations';

	/**
	 * Create a draft configuration for a season's divisions.
	 *
	 * @param string            $season_name New season name.
	 * @param array<int, int[]> $assignments league term ID => team post IDs.
	 * @return string The configuration name on success, '' when unavailable.
	 */
	public function create( $season_name, array $assignments ) {
		if ( ! class_exists( 'SPSG_Configuration_Manager' ) || empty( $assignments ) ) {
			return '';
		}

		$divisions = $this->build_divisions( $assignments );

		if ( ! $divisions ) {
			return '';
		}

		$manager = new SPSG_Configuration_Manager();

		$config = array_merge(
			$manager->get_defaults(),
			array(
				'id'        => 'spem_' . sanitize_key( $season_name ),
				'name'      => sprintf(
					/* translators: %s: season name */
					__( '%s (from season rollover)', 'sportspress-events-manager' ),
					$season_name
				),
				'divisions' => $divisions,
			)
		);

		$sanitized = $manager->sanitize( $config );

		// sanitize() drops id/name when absent from the input; re-assert them so
		// a re-run overwrites its own draft rather than accumulating copies.
		$sanitized['id']       = $config['id'];
		$sanitized['name']     = $config['name'];
		$sanitized['modified'] = current_time( 'mysql' );

		$configurations = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $configurations ) ) {
			$configurations = array();
		}

		if ( ! isset( $configurations[ $sanitized['id'] ]['created'] ) ) {
			$sanitized['created'] = current_time( 'mysql' );
		} else {
			$sanitized['created'] = $configurations[ $sanitized['id'] ]['created'];
		}

		$configurations[ $sanitized['id'] ] = $sanitized;

		update_option( self::OPTION_NAME, $configurations, 'no' );

		return $sanitized['name'];
	}

	/**
	 * Shape the rollover's assignments the way the generator expects.
	 *
	 * Divisions whose league term has vanished are dropped rather than emitted
	 * nameless, since the generator lists them by name.
	 *
	 * @param array<int, int[]> $assignments league term ID => team post IDs.
	 * @return array List of array{id:string, name:string, teams:string[]}.
	 */
	private function build_divisions( array $assignments ) {
		$divisions = array();

		foreach ( $assignments as $league_id => $team_ids ) {
			$league = get_term( (int) $league_id, 'sp_league' );

			if ( ! $league || is_wp_error( $league ) ) {
				continue;
			}

			$divisions[] = array(
				'id'    => (string) (int) $league_id,
				'name'  => $league->name,
				'teams' => array_map( 'strval', array_map( 'intval', $team_ids ) ),
			);
		}

		return $divisions;
	}
}
