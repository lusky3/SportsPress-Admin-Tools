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
 * Every method here is one rule in the decision core, and the rules are the
 * reason this class exists as a testable unit. Merging them to reduce the
 * count would put several rules behind one entry point and undo that.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
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
	 * The consequence rank of an unreleased row, or 9 when there is none.
	 *
	 * Keeps the pass from having to know how ranks are numbered.
	 *
	 * @param object|null $row Notice row, or null.
	 * @return int
	 */
	public static function consequence_rank_of( $row ): int {
		if ( ! $row ) {
			return 9;
		}

		return SPLM_Penalty_Watch::consequence_rank( (string) $row->consequence );
	}

	/**
	 * Whether a row is still awaiting a human.
	 *
	 * `pending` and `failed` are both unreleased drafts: a failed send stays in
	 * the queue with a Release button and is retried through the same route.
	 * Treating only `pending` as unreleased let a failed row re-fire the moment
	 * the player's total grew, so a convener who fixed a missing address and hit
	 * "Release all" sent the same player two identical suspensions.
	 *
	 * @param object|null $row Notice row.
	 * @return bool
	 */
	private static function is_unreleased( $row ): bool {
		if ( ! $row ) {
			return false;
		}

		return in_array(
			(string) $row->status,
			array(
				SPLM_Discipline_Notice_Database::STATUS_PENDING,
				SPLM_Discipline_Notice_Database::STATUS_FAILED,
			),
			true
		);
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

		if ( self::is_unreleased( $latest ) ) {
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
		if ( ! self::is_unreleased( $latest ) ) {
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
	 * @param int   $pending_rank            consequence_rank() of the unreleased notice
	 *                                       already queued for this player and season,
	 *                                       or 9 when the queue is clear. Defaults 9 so
	 *                                       existing callers are unaffected.
	 * @return array array(
	 *               'notice'    => array|null,
	 *               'status'    => string,
	 *               'send'      => bool,
	 *               'baselines' => array,
	 *             )
	 */
	public static function plan_writes( array $matches_by_scope, array $modes, bool $baselining, bool $has_address, bool $suspension_outstanding = false, int $pending_rank = 9 ): array {
		$eligible = self::eligible_matches( $matches_by_scope, $modes, $suspension_outstanding );

		$nothing = array(
			'notice'     => null,
			'status'     => SPLM_Discipline_Notice_Database::STATUS_PENDING,
			'send'       => false,
			'baselines'  => array(),
			'supersedes' => false,
		);

		if ( ! $eligible ) {
			return $nothing;
		}

		// A baselining pass records every candidate at its current value and
		// mails nobody. That is what makes switching notices on mid-season, or
		// editing a threshold, silent.
		if ( $baselining ) {
			return array_merge( $nothing, array( 'baselines' => self::flatten( $eligible ) ) );
		}

		$chosen = self::select( $eligible );
		if ( ! $chosen['notice'] ) {
			return array_merge( $nothing, array( 'baselines' => $chosen['baselines'] ) );
		}

		// At most one unreleased notice per player per season — but the bound is
		// applied AFTER selection, comparing severity, and that ordering is the
		// whole point. Applying it before select() discarded a strictly more
		// severe candidate in favour of an already-queued lesser one: a player
		// sitting on a queued warning who then crossed a suspending tier had the
		// suspension silently dropped, and the convener released a warning
		// saying "at 25 you will be suspended" to someone already past it.
		//
		// A winner no more severe than what is already queued writes nothing.
		// A more severe winner is returned with 'supersedes' set, and the caller
		// rewrites that row in place rather than adding a second one.
		if ( $pending_rank < 9 ) {
			return self::against_queue( $chosen['notice'], $pending_rank, $has_address, $nothing );
		}

		$mode = (string) ( $modes[ (string) $chosen['notice']['consequence'] ] ?? self::MODE_DISABLED );

		return array(
			'supersedes' => false,
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
	 * Flatten scope-grouped matches into one list.
	 *
	 * @param array $by_scope Scope => matches.
	 * @return array
	 */
	private static function flatten( array $by_scope ): array {
		$flat = array();

		foreach ( $by_scope as $scope_matches ) {
			$flat = array_merge( $flat, $scope_matches );
		}

		return $flat;
	}

	/**
	 * Resolve a winner against whatever is already queued for this player.
	 *
	 * Extracted from plan_writes() to keep it off CyclomaticComplexity and
	 * NPathComplexity, and because the rule is independently meaningful: the
	 * queue holds at most one unreleased notice per player per season, and the
	 * one it holds is the most severe live consequence.
	 *
	 * @param array $winner       The selected match.
	 * @param int   $pending_rank Rank of the queued row.
	 * @param bool  $has_address  Whether the player's address resolved.
	 * @param array $nothing      The empty result to return when blocked.
	 * @return array
	 */
	private static function against_queue( array $winner, int $pending_rank, bool $has_address, array $nothing ): array {
		if ( SPLM_Penalty_Watch::consequence_rank( (string) $winner['consequence'] ) >= $pending_rank ) {
			return $nothing;
		}

		return array(
			'notice'     => $winner,
			'status'     => $has_address
				? SPLM_Discipline_Notice_Database::STATUS_PENDING
				: SPLM_Discipline_Notice_Database::STATUS_FAILED,
			'send'       => false,
			'baselines'  => array(),
			'supersedes' => true,
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
