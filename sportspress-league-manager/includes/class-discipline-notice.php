<?php
/**
 * Notice decisions: whether a match fires, and which match wins a pass.
 *
 * Pure by construction — matches and rows in, decisions out. No database, no
 * mail, no options. That is what lets the rules that decide whether a player
 * is told they are suspended be tested exhaustively with no WordPress at all.
 *
 * SPLM_Penalty_Watch and SPLM_Discipline_Notice_Database are stateless static
 * helpers with no dependencies — static access is exactly what lets them be
 * reached with no WordPress bootstrap, which is this class's whole point.
 * Injecting instances purely to satisfy the linter would cost the testability
 * and buy nothing.
 *
 * @author Cody (lusky3)
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice {

	/** Consequences that can produce a notice. 'none' cannot. */
	const ACTIONABLE = array( 'warn', 'suspend' );

	const MODE_AUTOMATIC = 'automatic';
	const MODE_QUEUED    = 'queued';
	const MODE_DISABLED  = 'disabled';

	const MODES = array( self::MODE_DISABLED, self::MODE_QUEUED, self::MODE_AUTOMATIC );

	const OPTION_MODE_WARNING    = 'splm_discipline_notice_mode_warning';
	const OPTION_MODE_SUSPENSION = 'splm_discipline_notice_mode_suspension';

	/**
	 * The option backing a consequence's delivery mode.
	 *
	 * @param string $consequence 'warn' or 'suspend'.
	 * @return string Option name, or '' for a consequence with no mode.
	 */
	public static function option_for( string $consequence ): string {
		if ( 'suspend' === $consequence ) {
			return self::OPTION_MODE_SUSPENSION;
		}
		if ( 'warn' === $consequence ) {
			return self::OPTION_MODE_WARNING;
		}

		return '';
	}

	/**
	 * The delivery mode governing a consequence.
	 *
	 * Sanitises what it reads rather than trusting the stored value: these
	 * options decide whether mail goes to players, so a hand-edited or
	 * partially-migrated option must not be able to enable an unrecognised
	 * mode. Anything unexpected reads as disabled.
	 *
	 * @param string $consequence 'warn' or 'suspend'.
	 * @return string One of MODES.
	 */
	public static function mode_for( string $consequence ): string {
		$option = self::option_for( $consequence );
		if ( '' === $option ) {
			return self::MODE_DISABLED;
		}

		return self::sanitize_mode( get_option( $option, self::MODE_DISABLED ) );
	}

	/**
	 * Validate a delivery mode.
	 *
	 * Untyped because this runs as a register_setting() sanitiser: options.php
	 * hands the callback null when the field is missing from the POST, and a
	 * hard string type hint would turn that into a fatal on save.
	 *
	 * @param mixed $raw Candidate mode.
	 * @return string One of MODES; disabled for anything unrecognised.
	 */
	public static function sanitize_mode( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return self::MODE_DISABLED;
		}

		return in_array( $raw, self::MODES, true ) ? $raw : self::MODE_DISABLED;
	}

	/**
	 * Whether a match should produce a NEW notice row.
	 *
	 * One predicate governs every status, and it compares the player's SEASON
	 * total — never the matched value.
	 *
	 * That distinction is the whole correctness argument. A season total only
	 * ever grows; a rolling window total falls as weeks roll past. Comparing
	 * the matched value would mean a window tier re-fires every week the
	 * minutes stay inside the window — one 8-minute incident becoming four
	 * suspension emails — while keying suppression on the window itself would
	 * mute a genuine later offence that reached the same window figure. A
	 * monotonic comparison has neither failure.
	 *
	 * Every status participates identically, which delivers all of these with
	 * no special cases:
	 *
	 *  - a sent notice does not re-send while the player earns nothing;
	 *  - a baseline row suppresses a player who was already over at switch-on;
	 *  - a served suspension re-fires once the player offends again;
	 *  - a convener's discard sticks until the player earns more;
	 *  - a failed row does not duplicate: it stays actionable in the queue and
	 *    is retried through the release route.
	 *
	 * 'pending' is the one status that returns false even when the total HAS
	 * grown. A pending notice is a draft nobody has released yet, so a rising
	 * total should revise it in place rather than stack a second row — three
	 * pending rows for one escalation would mail three suspensions when
	 * released. The caller updates the pending row instead; see needs_refresh().
	 *
	 * @param array       $match        A row from SPLM_Penalty_Watch::matches().
	 * @param object|null $latest       The most recent notice row for this tier.
	 * @param int         $season_total The player's current season PIM.
	 * @return bool
	 */
	public static function should_fire( array $match, $latest, int $season_total ): bool {
		$consequence = (string) ( $match['consequence'] ?? 'none' );
		if ( ! in_array( $consequence, self::ACTIONABLE, true ) ) {
			return false;
		}

		if ( ! $latest ) {
			return true;
		}

		if ( SPLM_Discipline_Notice_Database::STATUS_PENDING === (string) $latest->status ) {
			return false;
		}

		return $season_total > (int) $latest->season_at_fire;
	}

	/**
	 * Whether an unreleased pending row should be revised in place.
	 *
	 * The counterpart to should_fire()'s pending exclusion: the notice still
	 * needs to tell the convener the player's current total, it just must not
	 * become a second row.
	 *
	 * @param object|null $latest       The most recent notice row for this tier.
	 * @param int         $season_total The player's current season PIM.
	 * @return bool
	 */
	public static function needs_refresh( $latest, int $season_total ): bool {
		if ( ! $latest || SPLM_Discipline_Notice_Database::STATUS_PENDING !== (string) $latest->status ) {
			return false;
		}

		return $season_total > (int) $latest->season_at_fire;
	}

	/**
	 * Decide what a pass writes for one player.
	 *
	 * Pure: matches, modes, and two booleans in; decisions out. No options, no
	 * database, no mail. This exists as its own function because the three
	 * rules below are the ones most likely to be got wrong, and inside the
	 * pass's database-and-mail body nothing could test them.
	 *
	 * The mode filter runs BEFORE selection, which is load-bearing. Selecting
	 * first and checking the mode afterwards means a league running
	 * warn=queued with suspend=disabled gets nothing at all for a player over
	 * both tiers: the suspension wins selection, its disabled mode aborts, and
	 * the warning the league actually enabled is both skipped and baselined out
	 * of existence.
	 *
	 * @param array $matches_by_scope        Scope => matches that passed should_fire().
	 * @param array $modes                   consequence => mode, e.g. array( 'warn' => 'queued' ).
	 * @param bool  $baselining              Whether this pass is a baselining pass.
	 * @param bool  $has_address             Whether the player's address resolved.
	 * @param bool  $suspension_outstanding  Whether a suspension notice already exists
	 *                                       for this player and season.
	 * @param bool  $pending_outstanding     Whether an unreleased notice already exists
	 *                                       for this player and season. Defaults false
	 *                                       so existing callers are unaffected.
	 * @return array array(
	 *               'notice'    => array|null,
	 *               'status'    => string,
	 *               'send'      => bool,
	 *               'baselines' => array,
	 *             )
	 */
	public static function plan_writes( array $matches_by_scope, array $modes, bool $baselining, bool $has_address, bool $suspension_outstanding = false, bool $pending_outstanding = false ): array {
		$eligible = self::eligible_matches( $matches_by_scope, $modes, $suspension_outstanding );

		$nothing = array(
			'notice'    => null,
			'status'    => SPLM_Discipline_Notice_Database::STATUS_PENDING,
			'send'      => false,
			'baselines' => array(),
		);

		if ( ! $eligible ) {
			return $nothing;
		}

		// At most one unreleased notice per player per season. When an earlier
		// pass's winner is still `pending`, should_fire() suppresses it — and
		// without this bound the runner-up wins selection on its own, so a
		// convener releasing the queue sends two notices for one escalation.
		// That is the harm select() prevents within a pass, displaced across
		// passes. A baselining pass is exempt: it mails nobody.
		if ( $pending_outstanding && ! $baselining ) {
			return $nothing;
		}

		// A baselining pass records every candidate at its current value and
		// mails nobody. That is what makes switching notices on mid-season, or
		// editing a threshold, silent.
		if ( $baselining ) {
			$flat = array();
			foreach ( $eligible as $scope_matches ) {
				$flat = array_merge( $flat, $scope_matches );
			}

			return array(
				'notice'    => null,
				'status'    => SPLM_Discipline_Notice_Database::STATUS_PENDING,
				'send'      => false,
				'baselines' => $flat,
			);
		}

		$chosen = self::select( $eligible );
		if ( ! $chosen['notice'] ) {
			return array_merge( $nothing, array( 'baselines' => $chosen['baselines'] ) );
		}

		$mode = (string) ( $modes[ (string) $chosen['notice']['consequence'] ] ?? self::MODE_DISABLED );

		return array(
			'notice'    => $chosen['notice'],
			// No address means the row lands failed immediately, with a cause a
			// human can act on, rather than sitting as an ordinary pending row
			// whose problem only surfaces when someone tries to release it.
			'status'    => $has_address
				? SPLM_Discipline_Notice_Database::STATUS_PENDING
				: SPLM_Discipline_Notice_Database::STATUS_FAILED,
			'send'      => self::MODE_AUTOMATIC === $mode && $has_address,
			'baselines' => $chosen['baselines'],
		);
	}

	/**
	 * Choose at most one notice from a pass's surviving matches.
	 *
	 * Two scopes can both match a suspending tier in the same pass — a player
	 * crossing season-critical and window-critical together. Sending both would
	 * mail the player twice and imply two suspensions for one set of minutes.
	 *
	 * The winner takes the notice. Every other candidate is returned for a
	 * baseline row, which is what stops the runner-up firing its own notice on
	 * the next pass at an unchanged total while still letting it fire later if
	 * the player earns more.
	 *
	 * @param array $matches_by_scope Scope => list of matches, already filtered
	 *                                through should_fire().
	 * @return array array( 'notice' => array|null, 'baselines' => array ).
	 */
	public static function select( array $matches_by_scope ): array {
		$candidates = array();
		foreach ( $matches_by_scope as $scope_matches ) {
			foreach ( (array) $scope_matches as $match ) {
				if ( in_array( (string) ( $match['consequence'] ?? 'none' ), self::ACTIONABLE, true ) ) {
					$candidates[] = $match;
				}
			}
		}

		if ( ! $candidates ) {
			return array(
				'notice'    => null,
				'baselines' => array(),
			);
		}

		$ranked = self::rank_matches( $candidates );

		return array(
			'notice'    => array_shift( $ranked ),
			'baselines' => $ranked,
		);
	}

	/**
	 * Drop matches whose consequence has its delivery mode switched off.
	 *
	 * A disabled consequence writes nothing at all — not a notice and not a
	 * baseline row. The spec is explicit: disabled means discipline behaves
	 * exactly as it did before notices existed.
	 *
	 * @param array $matches_by_scope       Scope => matches.
	 * @param array $modes                  consequence => mode.
	 * @param bool  $suspension_outstanding Whether a suspension notice already exists
	 *                                      for this player and season.
	 * @return array Scope => surviving matches.
	 */
	private static function eligible_matches( array $matches_by_scope, array $modes, bool $suspension_outstanding = false ): array {
		$eligible = array();

		foreach ( $matches_by_scope as $scope => $scope_matches ) {
			foreach ( (array) $scope_matches as $match ) {
				$consequence = (string) ( $match['consequence'] ?? '' );

				// Inert consequences are dropped here as well as in select(),
				// so the two filters cannot disagree. Without this, a
				// baselining pass whose modes map happens to carry a 'none'
				// key writes a baseline row for a tier that can never produce
				// a notice.
				if ( ! in_array( $consequence, self::ACTIONABLE, true ) ) {
					continue;
				}

				// A warning is moot once a suspension has been issued this
				// season: its whole content is "at N you will be suspended",
				// which is false for a player already suspended at N.
				if ( $suspension_outstanding && 'suspend' !== $consequence ) {
					continue;
				}

				$mode = (string) ( $modes[ $consequence ] ?? self::MODE_DISABLED );
				if ( self::MODE_DISABLED !== $mode ) {
					$eligible[ $scope ][] = $match;
				}
			}
		}

		return $eligible;
	}

	/**
	 * Sort matches most severe first: consequence, then games, then minutes.
	 *
	 * Consequence leads because it is the actionable axis — a suspension must
	 * win over a warning even when the warning tier sits at a higher threshold,
	 * which is legal since thresholds are editable. Games breaks ties among
	 * suspensions so the longer one is the one the player is told about.
	 *
	 * @param array $matches Matches.
	 * @return array Sorted copy.
	 */
	public static function rank_matches( array $matches ): array {
		usort(
			$matches,
			function ( $a, $b ) {
				$rank = SPLM_Penalty_Watch::consequence_rank( (string) $a['consequence'] )
					<=> SPLM_Penalty_Watch::consequence_rank( (string) $b['consequence'] );
				if ( $rank ) {
					return $rank;
				}

				$games = (int) $b['games'] <=> (int) $a['games'];
				if ( $games ) {
					return $games;
				}

				return (int) $b['minutes'] <=> (int) $a['minutes'];
			}
		);

		return $matches;
	}
}
