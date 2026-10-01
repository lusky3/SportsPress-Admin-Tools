<?php
/**
 * Wording for manually issued suspension emails.
 *
 * Reads ONLY an allow-list of context keys. The convener's incident note is
 * deliberately not one of them, so it cannot reach a body even if a caller
 * passes it in the context array — the guarantee is structural, not a filter.
 *
 * Pure: no WordPress calls beyond __()/_n()/esc_url_raw(), so every sentence
 * is unit-testable.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Suspension_Body {

	/**
	 * Email subject.
	 *
	 * @param string $kind        issued|decided|amended|revoked.
	 * @param string $season_name Season name.
	 * @return string
	 */
	public static function subject( string $kind, string $season_name ): string {
		switch ( $kind ) {
			case 'revoked':
				/* translators: %s: season name. */
				return sprintf( __( 'Correction: suspension withdrawn — %s', 'sportspress-league-manager' ), $season_name );
			case 'amended':
				/* translators: %s: season name. */
				return sprintf( __( 'Updated suspension notice — %s', 'sportspress-league-manager' ), $season_name );
			case 'decided':
				/* translators: %s: season name. */
				return sprintf( __( 'Suspension Decision — %s', 'sportspress-league-manager' ), $season_name );
			default:
				/* translators: %s: season name. */
				return sprintf( __( 'Suspension notice — %s', 'sportspress-league-manager' ), $season_name );
		}
	}

	/**
	 * Email body.
	 *
	 * @param string $audience player|captain.
	 * @param array  $ctx      Context (allow-listed keys only; see class docblock).
	 * @return string Plain text.
	 */
	public static function body( string $audience, array $ctx ): string {
		$kind   = (string) ( $ctx['kind'] ?? 'issued' );
		$lines  = array();
		$player = (string) ( $ctx['player_name'] ?? '' );

		$lines[] = self::opening( $audience, $kind, $player );

		if ( 'revoked' !== $kind ) {
			$lines[] = self::infraction_block( $ctx );
			$lines[] = self::length_sentence( $kind, $ctx );

			$eligibility = self::eligibility_sentence( $ctx );
			if ( '' !== $eligibility ) {
				$lines[] = $eligibility;
			}

			$lines[] = __( 'A suspension applies to ALL league play — every team you are on, on any night — and a regular-season suspension carries into the playoffs.', 'sportspress-league-manager' );

			if ( 'captain' === $audience ) {
				$lines[] = __( "It is the team captain's responsibility to make sure a suspended player does not play. A team that plays a suspended player forfeits the game, and the player is removed from the league without refund.", 'sportspress-league-manager' );
			}
		}

		$lines[] = self::footer( $ctx );

		return implode( "\n\n", array_filter( $lines, 'strlen' ) );
	}

	/**
	 * Opening sentence by audience and kind.
	 *
	 * @param string $audience player|captain.
	 * @param string $kind     Notice kind.
	 * @param string $player   Player name.
	 * @return string
	 */
	private static function opening( string $audience, string $kind, string $player ): string {
		if ( 'revoked' === $kind ) {
			return 'captain' === $audience
				/* translators: %s: player name. */
				? sprintf( __( 'Correction: the suspension of %s no longer applies. The player is eligible to play.', 'sportspress-league-manager' ), $player )
				: __( 'Correction: your suspension has been withdrawn and no longer applies. You are eligible to play.', 'sportspress-league-manager' );
		}

		if ( 'captain' === $audience ) {
			/* translators: %s: player name. */
			return sprintf( __( 'This is to let you know that %s has been suspended by the league.', 'sportspress-league-manager' ), $player );
		}

		/* translators: %s: player name. */
		return sprintf( __( 'Hello %s — you have been suspended by the league.', 'sportspress-league-manager' ), $player );
	}

	/**
	 * Infraction, rule number, rulebook wording and incident match.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	private static function infraction_block( array $ctx ): string {
		$title = (string) ( $ctx['infraction_title'] ?? '' );
		$ref   = (string) ( $ctx['rule_ref'] ?? '' );
		$text  = (string) ( $ctx['rule_text'] ?? '' );

		$head = '' !== $ref
			/* translators: 1: infraction title, 2: rule number. */
			? sprintf( __( 'Infraction: %1$s (Rule %2$s)', 'sportspress-league-manager' ), $title, $ref )
			/* translators: %s: infraction title. */
			: sprintf( __( 'Infraction: %s', 'sportspress-league-manager' ), $title );

		$out = $head;
		if ( '' !== $text ) {
			$out .= "\n" . '"' . $text . '"';
		}

		$incident = (string) ( $ctx['incident_label'] ?? '' );
		if ( '' !== $incident ) {
			/* translators: %s: match description. */
			$out .= "\n" . sprintf( __( 'Incident: %s', 'sportspress-league-manager' ), $incident );
		}

		return $out;
	}

	/**
	 * Length sentence. Indefinite never renders "0 games".
	 *
	 * @param string $kind Notice kind.
	 * @param array  $ctx  Context.
	 * @return string
	 */
	private static function length_sentence( string $kind, array $ctx ): string {
		if ( 'indefinite' === ( $ctx['outcome'] ?? '' ) ) {
			return __( 'Length: indefinite, pending convenor review.', 'sportspress-league-manager' );
		}

		$games = (int) ( $ctx['games'] ?? 0 );
		/* translators: %d: number of games. */
		$new = sprintf( _n( '%d game', '%d games', $games, 'sportspress-league-manager' ), $games );

		if ( 'amended' === $kind && isset( $ctx['prior_games'] ) ) {
			$prior = (int) $ctx['prior_games'];
			/* translators: %d: number of games. */
			$old = sprintf( _n( '%d game', '%d games', $prior, 'sportspress-league-manager' ), $prior );
			/* translators: 1: previous length, 2: new length. */
			return sprintf( __( 'Length: changed from %1$s to %2$s.', 'sportspress-league-manager' ), $old, $new );
		}

		/* translators: %s: "3 games". */
		return sprintf( __( 'Length: %s.', 'sportspress-league-manager' ), $new );
	}

	/**
	 * Next-eligible-game sentence. Never invents a date.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	private static function eligibility_sentence( array $ctx ): string {
		if ( 'indefinite' === ( $ctx['outcome'] ?? '' ) ) {
			return __( 'You remain suspended from all league play until the review is complete. You will be told the outcome.', 'sportspress-league-manager' );
		}

		$label = (string) ( $ctx['eligible_label'] ?? '' );
		if ( '' === $label ) {
			return __( 'The remaining games will be served at your next scheduled game.', 'sportspress-league-manager' );
		}

		$sentence = sprintf(
			/* translators: %s: game date and title. */
			__( 'Next eligible game: %s', 'sportspress-league-manager' ),
			$label
		);

		return ! empty( $ctx['projected'] )
			? $sentence . ' ' . __( "(projected, if the schedule doesn't change)", 'sportspress-league-manager' )
			: $sentence;
	}

	/**
	 * Rulebook link, revision and contact.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	private static function footer( array $ctx ): string {
		$parts = array();

		$url = esc_url_raw( (string) ( $ctx['rulebook_url'] ?? '' ) );
		if ( '' !== $url ) {
			$rev = (string) ( $ctx['rulebook_rev'] ?? '' );
			/* translators: 1: rulebook URL, 2: revision label. */
			$parts[] = sprintf( __( 'Rulebook: %1$s %2$s', 'sportspress-league-manager' ), $url, '' !== $rev ? '(' . $rev . ')' : '' );
		}

		$contact = (string) ( $ctx['contact'] ?? '' );
		if ( '' !== $contact ) {
			/* translators: %s: convenor contact. */
			$parts[] = sprintf( __( 'Questions: contact the league convenor at %s.', 'sportspress-league-manager' ), $contact );
		}

		return implode( "\n", $parts );
	}
}
