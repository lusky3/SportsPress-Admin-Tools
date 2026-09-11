<?php
/**
 * Postseason bracket-stage detection, shared by the postseason allocator
 * constraints (day carve-out, championship time window).
 *
 * A game is identifiable as the final week's Championship or Consolation
 * matchup purely from its two teams' placeholder names -- both teams are
 * the same division's "RR-Seed" placeholders (see
 * SPSG_Postseason_Seed_Resolver), and per
 * SPSG_Postseason_Pairing::final_week_pairs()'s own construction ("new-1 vs
 * new-2 (Championship), new-3 vs new-4, ... (Consolation)"), the pairing of
 * RR-Seed 1 with RR-Seed 2 always IS the Championship game, regardless of
 * which real teams those seeds go on to resolve to. This only holds before
 * seed resolution swaps the placeholders for real teams -- exactly the
 * window these constraints need to govern, since the design's own
 * day/time/venue structure is meant to be locked in ahead of that.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSG_Postseason_Bracket_Detector {

	/**
	 * Whether $game is a final-week postseason matchup, and if so, whether
	 * it's the Championship game or a Consolation one.
	 *
	 * @param object $game A game with home_team/away_team properties (string,
	 *                      object, or array -- see team_label()).
	 * @return array{division: string, is_championship: bool}|null Null if
	 *              $game isn't a final-week (RR-Seed vs RR-Seed, same
	 *              division) matchup at all.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function final_week_info( $game ) {
		$home = SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name(
			self::team_label( $game->home_team ?? null )
		);
		$away = SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name(
			self::team_label( $game->away_team ?? null )
		);

		if ( ! self::is_matching_rr_seed_pair( $home, $away ) ) {
			return null;
		}

		$seeds = array( $home['seed'], $away['seed'] );
		sort( $seeds );

		return array(
			'division'        => $home['division'],
			'is_championship' => array( 1, 2 ) === $seeds,
		);
	}

	/**
	 * Whether two parsed placeholder names are both RR-Seed placeholders
	 * for the same division -- i.e. this is a final-week game at all.
	 *
	 * @param array|null $home Parsed placeholder name for the home team, or null.
	 * @param array|null $away Parsed placeholder name for the away team, or null.
	 * @return bool
	 */
	private static function is_matching_rr_seed_pair( $home, $away ) {
		if ( null === $home || null === $away ) {
			return false;
		}
		if ( SPSG_Postseason_Seed_Resolver::RR_SEED_STAGE !== $home['stage'] || SPSG_Postseason_Seed_Resolver::RR_SEED_STAGE !== $away['stage'] ) {
			return false;
		}
		return $home['division'] === $away['division'];
	}

	/**
	 * A team reference's display name, whatever shape it arrives in --
	 * schedule generation carries teams as plain name strings, objects, or
	 * arrays depending on how far a game is through the pipeline.
	 *
	 * @param mixed $team Team reference to render a display name for.
	 * @return string
	 */
	private static function team_label( $team ) {
		if ( is_string( $team ) ) {
			return $team;
		}

		$name = self::field( $team, 'name' );

		return '' !== $name ? (string) $name : (string) self::field( $team, 'id' );
	}

	/**
	 * One field of a team reference given as an object or array, or '' when
	 * absent or $entity is neither shape.
	 *
	 * @param mixed  $entity Team reference (object, array, or anything else).
	 * @param string $key    Field name to read.
	 * @return mixed
	 */
	private static function field( $entity, $key ) {
		if ( is_array( $entity ) ) {
			return $entity[ $key ] ?? '';
		}
		if ( is_object( $entity ) ) {
			return $entity->$key ?? '';
		}
		return '';
	}
}
