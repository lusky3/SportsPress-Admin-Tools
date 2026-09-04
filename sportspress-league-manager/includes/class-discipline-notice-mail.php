<?php
/**
 * Notice wording and delivery.
 *
 * The body() helper is pure, so the wording can be tested exhaustively with no
 * WordPress: the resolved game arrives as a string the caller looked up, never
 * as an id this class queries.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Mail {

	/**
	 * The subject line.
	 *
	 * Warnings and suspensions get different subjects so a player can tell
	 * which arrived without opening it, and so a mail client threads them apart.
	 *
	 * @param string $consequence 'warn' or 'suspend'.
	 * @param string $season_name Season name.
	 * @return string
	 */
	public static function subject( string $consequence, string $season_name ): string {
		if ( 'suspend' === $consequence ) {
			/* translators: %s: season name. */
			return sprintf( __( 'You are suspended — %s', 'sportspress-league-manager' ), $season_name );
		}

		/* translators: %s: season name. */
		return sprintf( __( 'Penalty minutes warning — %s', 'sportspress-league-manager' ), $season_name );
	}

	/**
	 * The next SUSPENDING season threshold above a value.
	 *
	 * Two filters, both load-bearing. Only season-scope tiers, because a
	 * warning tells the player what their season total is heading towards and a
	 * window threshold is not a number they can reason about from one. And only
	 * tiers that actually suspend, because warning_sentence() renders "you will
	 * be suspended" — a league configured warn@12 / warn@18 / suspend@25 would
	 * otherwise tell a player at 12 they face suspension at 18, which is false.
	 *
	 * @param int   $value Current season total.
	 * @param array $tiers Tier list.
	 * @return int The next suspending threshold, or 0 when there is none above.
	 */
	public static function next_threshold( int $value, array $tiers ): int {
		$above = array();

		foreach ( $tiers as $tier ) {
			if ( 'season' !== (string) ( $tier['scope'] ?? '' ) ) {
				continue;
			}
			if ( 'suspend' !== (string) ( $tier['consequence'] ?? '' ) ) {
				continue;
			}
			$minutes = (int) ( $tier['minutes'] ?? 0 );
			if ( $minutes > $value ) {
				$above[] = $minutes;
			}
		}

		return $above ? min( $above ) : 0;
	}

	/**
	 * Render the notice body.
	 *
	 * Plain text, matching the waitlist offer email's register.
	 *
	 * @param array $context Keys: player_name, season_name, consequence, games,
	 *                       value, next_threshold, game_label.
	 * @return string
	 */
	public static function body( array $context ): string {
		$name = (string) ( $context['player_name'] ?? '' );

		/* translators: used in place of a player's name when none is on record. */
		$greeting = $name ? $name : __( 'there', 'sportspress-league-manager' );

		$lines = array(
			/* translators: %s: player name. */
			sprintf( __( 'Hi %s,', 'sportspress-league-manager' ), $greeting ),
			'',
			self::accumulated_sentence( $context ),
			'',
		);

		if ( 'suspend' === (string) ( $context['consequence'] ?? '' ) ) {
			$lines[] = self::suspension_sentence(
				(int) ( $context['games'] ?? 1 ),
				(string) ( $context['game_label'] ?? '' )
			);
		} else {
			$lines[] = self::warning_sentence( (int) ( $context['next_threshold'] ?? 0 ) );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The sentence stating what the player has accumulated.
	 *
	 * A window tier's matched value is a rolling few-weeks total, not a season
	 * total — so reporting it as "N penalty minutes in <season>" understates
	 * the player's actual season figure and reads as simply wrong to anyone who
	 * knows their own record. The queue page already distinguishes the two; the
	 * email now does too.
	 *
	 * @param array $context Body context; see body().
	 * @return string
	 */
	private static function accumulated_sentence( array $context ): string {
		$value  = (int) ( $context['value'] ?? 0 );
		$season = (string) ( $context['season_name'] ?? '' );

		if ( 'window' === (string) ( $context['scope'] ?? '' ) ) {
			return sprintf(
				/* translators: 1: recent penalty minutes, 2: season penalty minutes, 3: season name. */
				__( 'You have accumulated %1$d penalty minutes in the last few weeks, and %2$d in %3$s overall.', 'sportspress-league-manager' ),
				$value,
				(int) ( $context['season_value'] ?? $value ),
				$season
			);
		}

		return sprintf(
			/* translators: 1: penalty minutes, 2: season name. */
			__( 'You have accumulated %1$d penalty minutes in %2$s.', 'sportspress-league-manager' ),
			$value,
			$season
		);
	}

	/**
	 * The warning's operative sentence.
	 *
	 * Naming the next threshold is what makes this a warning rather than a
	 * notification. With no threshold above the player's total there is nothing
	 * to warn towards, so the sentence is dropped rather than rendering a zero.
	 *
	 * @param int $next_threshold Next threshold, or 0.
	 * @return string
	 */
	private static function warning_sentence( int $next_threshold ): string {
		if ( $next_threshold > 0 ) {
			return sprintf(
				/* translators: %d: the next penalty-minute threshold. */
				__( 'This is a warning. At %d penalty minutes you will be suspended.', 'sportspress-league-manager' ),
				$next_threshold
			);
		}

		return __( 'This is a warning about your accumulated penalty minutes.', 'sportspress-league-manager' );
	}

	/**
	 * The suspension's operative sentence.
	 *
	 * The named game is advisory and the footnote is what makes it so. Games
	 * get rescheduled, so the obligation has to be "your next scheduled game"
	 * rather than a fixture — which is also why no notice row stores an event
	 * id. With nothing resolved the sentence degrades to the obligation alone
	 * and the asterisk is dropped, so the mail never carries a footnote marker
	 * with nothing to point at.
	 *
	 * @param int    $games      Games owed.
	 * @param string $game_label Resolved next game, or ''.
	 * @return string
	 */
	private static function suspension_sentence( int $games, string $game_label ): string {
		$games = max( 1, $games );

		$count = sprintf(
			/* translators: %d: number of games. */
			_n( '%d game', '%d games', $games, 'sportspress-league-manager' ),
			$games
		);

		if ( '' === $game_label ) {
			return sprintf(
				/* translators: %s: a game count such as "1 game". */
				__( 'You are suspended for %s, to be served at your next scheduled game.', 'sportspress-league-manager' ),
				$count
			);
		}

		return sprintf(
			/* translators: 1: a game count such as "1 game", 2: the next scheduled game. */
			__( "You are suspended for %1\$s, to be served %2\$s.*\n\n*or your next scheduled game.", 'sportspress-league-manager' ),
			$count,
			$game_label
		);
	}

	/**
	 * A human label for a team's next scheduled game.
	 *
	 * Resolved at render time and never stored. Includes 'future' as well as
	 * 'publish' because a scheduled fixture that has not been published yet is
	 * still the game the player will sit — the same status pair the dashboard's
	 * upcoming-games query uses.
	 *
	 * @param int $team_id Team post id.
	 * @return string Label, or '' when nothing resolves.
	 */
	public static function next_game_label( int $team_id ): string {
		if ( $team_id <= 0 ) {
			return '';
		}

		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				// current_time(), NOT gmdate(). WP_Query matches date_query
				// against post_date, which is site-local; between 00:00 and
				// 05:00 UTC the two dates disagree and an event later the same
				// local evening would be excluded. This is the same date the
				// pass and watch_context() derive.
				'date_query'     => array(
					array(
						'after'     => current_time( 'Y-m-d' ),
						'inclusive' => true,
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'sp_team',
						'value' => $team_id,
					),
				),
				'fields'         => 'ids',
			)
		);

		if ( ! $events ) {
			return '';
		}

		$event_id = (int) $events[0];
		$title    = get_the_title( $event_id );
		$when     = get_post_time( get_option( 'date_format' ), false, $event_id, true );

		if ( ! $when ) {
			return (string) $title;
		}

		if ( ! $title ) {
			return (string) $when;
		}

		/* translators: 1: game date, 2: game title. */
		return sprintf( __( '%1$s — %2$s', 'sportspress-league-manager' ), $when, $title );
	}

	/**
	 * Assemble the body context for one notice.
	 *
	 * Two callers need this: the scheduled pass, which has the live match, and
	 * the release route, which has only the stored row. They are the same nine
	 * keys, and the two derived ones carry rules that must not diverge — so the
	 * derivation lives here once rather than being copied into both.
	 *
	 * @param array $fields Keys: player_name, season_name, scope, consequence,
	 *                      games, value, season_value, team_id.
	 * @param array $tiers  Sanitized tier list.
	 * @return array Body context; see body().
	 */
	public static function context( array $fields, array $tiers ): array {
		$season_value = (int) ( $fields['season_value'] ?? 0 );

		return array(
			'player_name'    => (string) ( $fields['player_name'] ?? '' ),
			'season_name'    => (string) ( $fields['season_name'] ?? '' ),
			'scope'          => (string) ( $fields['scope'] ?? '' ),
			'season_value'   => $season_value,
			'consequence'    => (string) ( $fields['consequence'] ?? '' ),
			'games'          => (int) ( $fields['games'] ?? 0 ),
			'value'          => (int) ( $fields['value'] ?? 0 ),
			// season_value, not value: next_threshold() compares against SEASON
			// suspending tiers, and value is the matched figure — a rolling-window
			// total for a window tier. Feeding it the window number told a player
			// "at 25 you will be suspended" when their season total was already
			// past 25.
			'next_threshold' => self::next_threshold( $season_value, $tiers ),
			'game_label'     => self::next_game_label( (int) ( $fields['team_id'] ?? 0 ) ),
		);
	}

	/**
	 * Send one notice and record the outcome on its row.
	 *
	 * The player is the To: recipient and everyone else is Bcc'd, so the player
	 * never sees the board's addresses and the board sees the player's copy
	 * verbatim. The addresses actually used are written to the row, not
	 * recomputed later: the technical queue view has to be able to show what
	 * happened rather than what would happen if the notice were sent now.
	 *
	 * @param int    $notice_id Row id.
	 * @param array  $context   Body context; see body().
	 * @param string $to        The player's resolved address.
	 * @param array  $bcc       Addresses to copy.
	 * @return bool Whether wp_mail() accepted the message.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function send( int $notice_id, array $context, string $to, array $bcc ): bool {
		if ( '' === $to ) {
			// Caught before wp_mail() so the row records a cause a human can
			// act on, rather than a generic delivery failure.
			return self::fail(
				$notice_id,
				$to,
				$bcc,
				__( 'No email address on file for this player.', 'sportspress-league-manager' )
			);
		}

		// A suspended player who captains their own team resolves as their own
		// captain Bcc and would receive two copies.
		$bcc = array_values( array_diff( $bcc, array( $to ) ) );

		$headers = array();
		if ( $bcc ) {
			$headers[] = 'Bcc: ' . implode( ', ', $bcc );
		}

		$sent = wp_mail(
			$to,
			self::subject( (string) ( $context['consequence'] ?? 'warn' ), (string) ( $context['season_name'] ?? '' ) ),
			self::body( $context ),
			$headers
		);

		if ( ! $sent ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::error(
					'discipline',
					sprintf( 'wp_mail() rejected a disciplinary notice: notice_id=%d', $notice_id )
				);
			}

			return self::fail(
				$notice_id,
				$to,
				$bcc,
				__( 'wp_mail() rejected the message.', 'sportspress-league-manager' )
			);
		}

		SPLM_Discipline_Notice_Database::update(
			$notice_id,
			array(
				'status'     => SPLM_Discipline_Notice_Database::STATUS_SENT,
				'sent_at'    => SPLM_Discipline_Notice_Database::now(),
				'recipient'  => $to,
				'bcc'        => implode( ', ', $bcc ),
				'last_error' => '',
			)
		);

		self::record_ack( $notice_id );

		return true;
	}

	/**
	 * Record a delivery failure on the row and report it as a failed send.
	 *
	 * Both failure paths write the same shape, and the row is the only place a
	 * convener learns why nothing arrived — so they share one writer rather than
	 * two that can drift apart on which columns they set.
	 *
	 * @param int    $notice_id Row id.
	 * @param string $to        The address attempted; '' when none was resolved.
	 * @param array  $bcc       Addresses that would have been copied.
	 * @param string $message   The cause, in words a convener can act on.
	 * @return bool Always false, so callers can `return self::fail( ... )`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function fail( int $notice_id, string $to, array $bcc, string $message ): bool {
		SPLM_Discipline_Notice_Database::update(
			$notice_id,
			array(
				'status'     => SPLM_Discipline_Notice_Database::STATUS_FAILED,
				'recipient'  => $to,
				'bcc'        => implode( ', ', $bcc ),
				'last_error' => $message,
			)
		);

		return false;
	}

	/**
	 * Let a sent notice stand in for the convener's acknowledgement.
	 *
	 * A sent notice also acknowledges the flag, so the weekly digest stops
	 * listing a player who has already been told. Reuses the existing
	 * suppression machinery rather than adding a second one: value_at_ack
	 * already means "quiet until they earn more".
	 *
	 * ONLY when no acknowledgement exists. acknowledge() upserts on
	 * UNIQUE (player, season, tier) and overwrites value_at_ack, status, note
	 * AND author_id unconditionally — so writing unconditionally here would
	 * destroy a convener's own acknowledgement, losing their note and resetting
	 * a deliberately-high value_at_ack (the way a convener silences a player)
	 * back down, which restarts the digest nagging about someone they had
	 * already dealt with.
	 *
	 * @param int $notice_id Row id.
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function record_ack( int $notice_id ): void {
		if ( ! class_exists( 'SPLM_Discipline_Database' ) ) {
			return;
		}

		$row = SPLM_Discipline_Notice_Database::find( $notice_id );
		if ( ! $row ) {
			return;
		}

		if ( SPLM_Discipline_Database::has_ack( (int) $row->player_id, (int) $row->season_id, (string) $row->ack_key ) ) {
			return;
		}

		SPLM_Discipline_Database::acknowledge(
			(int) $row->player_id,
			(int) $row->season_id,
			(string) $row->ack_key,
			(int) $row->value_at_fire,
			'notice_sent',
			'',
			get_current_user_id()
		);
	}
}
