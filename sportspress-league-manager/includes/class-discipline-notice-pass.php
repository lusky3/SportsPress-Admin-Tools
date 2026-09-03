<?php
/**
 * The daily notice evaluation pass.
 *
 * The ONLY writer of notice rows. Nothing on a read path may create one: if
 * watch_context() wrote notices, a convener opening the Leaders page would mail
 * players.
 *
 * Wrapped in SPAT_Lock for the reason the digest documents — WP-Cron can fire
 * the same event twice when two requests race the scheduler — with more at
 * stake here, because a duplicated suspension notice tells a player twice that
 * they are suspended.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Pass {

	const HOOK = 'splm_discipline_notices';
	const LOCK = 'splm_discipline_notices';

	const OPTION_BASELINE_TOKEN = 'splm_discipline_notice_baseline_token';

	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule the daily event if it is not already scheduled.
	 *
	 * WordPress forces PHP's timezone to UTC, so a bare strtotime() would
	 * schedule 07:00 UTC — 02:00 or 03:00 local for this league. The time is
	 * resolved in the site's timezone and converted back to a UTC timestamp,
	 * which is what wp_schedule_event() expects. Same workaround as the digest.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$next = false;
		try {
			$local = new DateTime( 'tomorrow 07:00', wp_timezone() );
			$next  = $local->getTimestamp();
		} catch ( Exception $e ) {
			$next = false;
		}

		if ( ! $next ) {
			$next = time() + DAY_IN_SECONDS;
		}

		wp_schedule_event( $next, 'daily', self::HOOK );
	}

	/**
	 * Clear the scheduled event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * A token over the inputs whose change must baseline rather than notify.
	 *
	 * Two things are folded in:
	 *
	 *  - each tier's key and minutes, so editing a threshold baselines that
	 *    tier — lowering season-critical from 18 to 10 must not mail everyone
	 *    already between the two;
	 *  - whether each mode is enabled, as a BOOLEAN, so turning notices on
	 *    mid-season baselines instead of mailing the sixteen players already
	 *    over season-warn.
	 *
	 * Recording enablement as a boolean rather than as the mode's value is what
	 * keeps queued -> automatic from re-baselining: both are enabled, and the
	 * only boundary that matters is disabled.
	 *
	 * A tier's consequence is deliberately NOT in the token. Promoting a
	 * warning tier to a suspension is a convener asking for it to take effect,
	 * and the re-fire predicate still prevents a re-send at an unchanged total.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function baseline_token(): string {
		$tiers = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );

		$thresholds = array();
		foreach ( $tiers as $tier ) {
			$thresholds[ (string) $tier['key'] ] = (int) $tier['minutes'];
		}
		ksort( $thresholds );

		$parts = array(
			'thresholds' => $thresholds,
			'warn_on'    => SPLM_Discipline_Notice::MODE_DISABLED !== SPLM_Discipline_Notice::mode_for( 'warn' ),
			'suspend_on' => SPLM_Discipline_Notice::MODE_DISABLED !== SPLM_Discipline_Notice::mode_for( 'suspend' ),
		);

		// Digest only, not a security primitive — xxh128 is faster than md5()
		// and does not trip weak-crypto scanners, matching the cache keys in
		// SPLM_Leaders_REST.
		return hash( 'xxh128', wp_json_encode( $parts ) );
	}

	/**
	 * Run the pass.
	 *
	 * @return int Rows written.
	 *
	 * SPAT_Lock and the discipline helpers are stateless static helpers with no
	 * dependencies — static access is exactly what lets them be called with no
	 * WordPress bootstrap. Injecting instances purely to satisfy the linter
	 * would cost testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function run(): int {
		if ( ! SPLM_REST_API::module_enabled( 'league_discipline' ) ) {
			return 0;
		}

		$warn_mode    = SPLM_Discipline_Notice::mode_for( 'warn' );
		$suspend_mode = SPLM_Discipline_Notice::mode_for( 'suspend' );

		// Both off means this feature is switched off: write nothing at all, so
		// discipline behaves exactly as it did before notices existed.
		if ( SPLM_Discipline_Notice::MODE_DISABLED === $warn_mode
			&& SPLM_Discipline_Notice::MODE_DISABLED === $suspend_mode ) {
			// The token is stored even on this path, and that is load-bearing.
			// Without it: a league running warn=queued stores token T1, turns
			// warnings off (token would become T2 but is never written), lets
			// players accumulate for weeks, then turns warnings back on — at
			// which point the recomputed token is T1 again, matches what is
			// stored, no baselining happens, and every player who crossed while
			// notices were off is mailed at once. That is precisely the
			// mid-season switch-on that baselining exists to prevent.
			self::remember_token();

			return 0;
		}

		// A parent too old to ship SPAT_Lock leaves nothing to serialise the
		// cron double-fire this method exists to survive. Telling a player twice
		// that they are suspended is worse than skipping a day, so the safe
		// failure is to write nothing.
		if ( ! class_exists( 'SPAT_Lock' ) ) {
			return 0;
		}

		$written = SPAT_Lock::with( self::LOCK, 300, array( __CLASS__, 'run_locked' ) );

		return false === $written ? 0 : (int) $written;
	}

	/**
	 * The pass body, already holding the lock.
	 *
	 * @return int Rows written.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function run_locked(): int {
		$season_id = (int) get_option( 'splm_default_season', 0 );
		if ( ! $season_id ) {
			return 0;
		}

		$players = SPLM_Player_Stats_Aggregator::for_season( $season_id, array( 'include_playoffs' => true ) );
		if ( ! $players ) {
			return 0;
		}

		$tiers  = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );
		$cutoff = SPLM_Player_Stats_Aggregator::window_cutoff(
			(int) get_option( 'splm_discipline_window_weeks', 4 ),
			current_time( 'Y-m-d' ),
			SPLM_Player_Stats_Aggregator::season_start( $players )
		);

		$baselining = self::is_baselining();

		$written = 0;
		foreach ( $players as $player_id => $player ) {
			$written += self::process_player( (int) $player_id, $player, $season_id, $tiers, $cutoff, $baselining );
		}

		self::remember_token();

		return $written;
	}

	/**
	 * Whether the next pass must baseline rather than notify.
	 *
	 * A named seam rather than an inline comparison so a test can assert the
	 * BEHAVIOUR — "does this configuration change baseline or notify" — instead
	 * of asserting that two token strings differ, which is an implementation
	 * detail that can hold while the stored token is never updated.
	 *
	 * @return bool
	 */
	public static function is_baselining(): bool {
		return (string) get_option( self::OPTION_BASELINE_TOKEN, '' ) !== self::baseline_token();
	}

	/**
	 * Store the current token, so the next pass does not baseline again.
	 *
	 * @return void
	 */
	public static function remember_token(): void {
		update_option( self::OPTION_BASELINE_TOKEN, self::baseline_token(), false );
	}

	/**
	 * Evaluate and act on one player.
	 *
	 * @param int    $player_id  Player post id.
	 * @param array  $player     Aggregator row.
	 * @param int    $season_id  Season term id.
	 * @param array  $tiers      Tier list.
	 * @param string $cutoff     Window cutoff week key.
	 * @param bool   $baselining Whether this pass is a baselining pass.
	 * @return int Rows written.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function process_player( int $player_id, array $player, int $season_id, array $tiers, string $cutoff, bool $baselining ): int {
		$season_total = (int) $player['totals']['pim'];
		$window       = SPLM_Player_Stats_Aggregator::window_totals( $player['weeks'], $cutoff );

		// Acknowledgements are deliberately NOT passed. An ack means a convener
		// reviewed a flag and it suppresses the digest; if it also suppressed
		// notices then acknowledging a flag — the exact thing the digest email
		// tells conveners to do — would silently cancel the player's
		// notification. Notice suppression is the notice table's own predicate.
		$matches = SPLM_Penalty_Watch::matches(
			array(
				'season' => $season_total,
				'window' => (int) $window['pim'],
			),
			$tiers,
			array(),
			$cutoff
		);

		$collected = self::collect_fireable( $player_id, $season_id, $matches, $cutoff, $season_total );
		$fireable  = $collected['fireable'];
		$written   = $collected['refreshed'];

		if ( ! $fireable ) {
			return $written;
		}

		// Resolved before planning, because whether an address exists decides
		// the row's status. Skipped on a baselining pass, which mails nobody
		// and so has no need of an address.
		$address = $baselining
			? array(
				'email' => '',
				'via'   => '',
			)
			: SPLM_Discipline_Notice_Recipients::player_email( $player_id );

		$planned = SPLM_Discipline_Notice::plan_writes(
			$fireable,
			array(
				'warn'    => SPLM_Discipline_Notice::mode_for( 'warn' ),
				'suspend' => SPLM_Discipline_Notice::mode_for( 'suspend' ),
			),
			$baselining,
			'' !== $address['email'],
			// Without this the runner-up baseline lets a warning fire a pass
			// later and the player receives BOTH emails for one escalation.
			// See Task 9's "the warning that arrives after the suspension".
			SPLM_Discipline_Notice_Database::has_suspension_notice( $player_id, $season_id ),
			// Bounds the queue to one unreleased notice per player per season.
			// Without it, a winner suppressed as `pending` lets the runner-up
			// win selection alone next pass and the convener releases two.
			SPLM_Discipline_Notice_Database::has_pending_notice( $player_id, $season_id )
		);

		foreach ( $planned['baselines'] as $match ) {
			$written += self::write_row(
				$player_id,
				$season_id,
				$match,
				$player,
				$season_total,
				SPLM_Discipline_Notice_Database::STATUS_BASELINE
			) ? 1 : 0;
		}

		if ( ! $planned['notice'] ) {
			return $written;
		}

		$match     = $planned['notice'];
		$notice_id = self::write_row(
			$player_id,
			$season_id,
			$match,
			$player,
			$season_total,
			$planned['status'],
			$address
		);

		if ( ! $notice_id ) {
			return $written;
		}

		++$written;

		if ( ! $planned['send'] ) {
			return $written;
		}

		SPLM_Discipline_Notice_Mail::send(
			$notice_id,
			self::mail_context( $match, $player, $season_id, $season_total, $tiers ),
			$address['email'],
			SPLM_Discipline_Notice_Recipients::bcc_for( $season_id, (int) $player['team_id'] )
		);

		return $written;
	}

	/**
	 * What the notice email needs to know about one crossing.
	 *
	 * Extracted from process_player() rather than inlined: it is a distinct
	 * concern — the body's inputs, as opposed to the pass's bookkeeping — and
	 * leaving it inline put process_player() at 104 lines against PHPMD's
	 * ExcessiveMethodLength threshold of 100, which Codacy's zero-new-issues
	 * gate would have flagged.
	 *
	 * @param array $match        The winning match.
	 * @param array $player       Aggregator row.
	 * @param int   $season_id    Season term id.
	 * @param int   $season_total The player's season PIM.
	 * @param array $tiers        Tier list, for the next-threshold lookup.
	 * @return array Context for SPLM_Discipline_Notice_Mail::body().
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function mail_context( array $match, array $player, int $season_id, int $season_total, array $tiers ): array {
		return array(
			'player_name'    => (string) $player['name'],
			'season_name'    => self::season_name( $season_id ),
			'consequence'    => (string) $match['consequence'],
			'games'          => (int) $match['games'],
			'value'          => (int) $match['value'],
			'next_threshold' => SPLM_Discipline_Notice_Mail::next_threshold( $season_total, $tiers ),
			'game_label'     => SPLM_Discipline_Notice_Mail::next_game_label( (int) $player['team_id'] ),
		);
	}

	/**
	 * Partition a player's matches into those that fire and those that revise.
	 *
	 * Extracted from process_player() rather than inlined: together they trip
	 * CyclomaticComplexity, NPathComplexity and ExcessiveMethodLength, and
	 * Codacy's gate is zero-new-issues. This half is also the only part that
	 * touches the database on the read side, so it isolates cleanly.
	 *
	 * @param int    $player_id    Player post id.
	 * @param int    $season_id    Season term id.
	 * @param array  $matches      Scope => matches from SPLM_Penalty_Watch::matches().
	 * @param string $cutoff       Window cutoff week key.
	 * @param int    $season_total The player's season PIM.
	 * @return array array( 'fireable' => array, 'refreshed' => int ).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function collect_fireable( int $player_id, int $season_id, array $matches, string $cutoff, int $season_total ): array {
		$fireable  = array();
		$refreshed = 0;

		foreach ( $matches as $scope => $scope_matches ) {
			foreach ( $scope_matches as $match ) {
				// Keyed on the tier, not the ack key: ack_key embeds the rolling
				// window's start, which advances weekly, so an ack_key lookup
				// would find nothing each week and re-fire the same suspension
				// once per week the minutes remain in the window.
				$latest = SPLM_Discipline_Notice_Database::latest_for( $player_id, $season_id, (string) $match['tier_key'] );

				if ( SPLM_Discipline_Notice::should_fire( $match, $latest, $season_total ) ) {
					// The ack key still travels on the row, for the digest's
					// acknowledgement write.
					$match['ack_key']     = SPLM_Penalty_Watch::ack_key(
						array(
							'key'   => $match['tier_key'],
							'scope' => $match['scope'],
						),
						$cutoff
					);
					$fireable[ $scope ][] = $match;
					continue;
				}

				// An unreleased draft is revised in place rather than stacked:
				// three pending rows for one escalation would mail three
				// suspensions when a convener released them.
				if ( SPLM_Discipline_Notice::needs_refresh( $latest, $season_total ) ) {
					SPLM_Discipline_Notice_Database::update(
						(int) $latest->id,
						array(
							'value_at_fire'  => (int) $match['value'],
							'season_at_fire' => $season_total,
						)
					);
					++$refreshed;
				}
			}
		}

		return array(
			'fireable'  => $fireable,
			'refreshed' => $refreshed,
		);
	}

	/**
	 * Write one notice row.
	 *
	 * Team and division are snapshotted from the aggregator row rather than
	 * resolved on read: cheaper, and it records who the player was playing for
	 * when the minutes were earned rather than who they play for now.
	 *
	 * @param int    $player_id    Player post id.
	 * @param int    $season_id    Season term id.
	 * @param array  $match        Match row, carrying its ack_key.
	 * @param array  $player       Aggregator row, for the team/division snapshot.
	 * @param int    $season_total The player's season PIM, for the predicate.
	 * @param string $status       Row status.
	 * @param array  $address      Optional resolved address from player_email().
	 * @return int New row id, or 0 on failure.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function write_row( int $player_id, int $season_id, array $match, array $player, int $season_total, string $status, array $address = array() ): int {
		$row = array(
			'player_id'      => $player_id,
			'season_id'      => $season_id,
			'tier_key'       => (string) $match['tier_key'],
			'ack_key'        => (string) ( $match['ack_key'] ?? $match['tier_key'] ),
			'scope'          => (string) $match['scope'],
			'severity'       => (string) $match['severity'],
			'consequence'    => (string) $match['consequence'],
			'games'          => (int) $match['games'],
			'value_at_fire'  => (int) $match['value'],
			'season_at_fire' => $season_total,
			'team'           => (string) ( $player['team'] ?? '' ),
			'division'       => (string) ( $player['div_name'] ?? '' ),
			'status'         => $status,
			'recipient'      => (string) ( $address['email'] ?? '' ),
			'recipient_via'  => (string) ( $address['via'] ?? '' ),
		);

		// A row that lands failed for a missing address needs to say so, or the
		// convener sees a failure with no cause.
		if ( SPLM_Discipline_Notice_Database::STATUS_FAILED === $status ) {
			$row['last_error'] = __( 'No email address on file for this player.', 'sportspress-league-manager' );
		}

		return SPLM_Discipline_Notice_Database::insert( $row );
	}

	/**
	 * A season's display name.
	 *
	 * @param int $season_id Season term id.
	 * @return string
	 */
	private static function season_name( int $season_id ): string {
		$season = get_term( $season_id, 'sp_season' );

		return ( $season && ! is_wp_error( $season ) ) ? (string) $season->name : '';
	}
}
