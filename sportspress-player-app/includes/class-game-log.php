<?php
/**
 * Live scorekeeping rules: what a valid log entry is, and what a log adds up to.
 *
 * Pure: entries and the game's facts (its teams, their rosters, whether it is
 * finalized) come in, a verdict or totals go out, so the rules are unit-tested
 * without WordPress. SPPA_Game_Log_Store keeps the rows; SPPA_Result_Writer
 * writes the totals to SportsPress.
 *
 * Periods: 1-3 regulation, 4 overtime, 5 shootout. A shootout goal is a goal
 * entry in period 5. It never adds to a player's G, and the shootout only
 * matters when the game is level after overtime: then its winner (more period-5
 * goals) gets exactly one goal added to their score, as SportsPress records a
 * shootout win. The loser of a game decided in overtime or a shootout takes
 * the overtime loss.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPA_Game_Log {

	const TYPES = array( 'goal', 'penalty', 'period', 'note' );

	const PERIOD_OT = 4;

	const PERIOD_SO = 5;

	/** Goal flags: power play, short-handed, empty net. */
	const FLAGS = array( 'pp', 'sh', 'en' );

	/** Entries per POST. */
	const MAX_ENTRIES = 50;

	const TEXT_MAX = 140;

	const MINUTES_MIN = 1;

	const MINUTES_MAX = 20;

	/** The client's id for an entry (a UUID, or anything of that alphabet). */
	const UID_PATTERN = '/^[A-Za-z0-9-]{8,36}$/';

	const CLOCK_PATTERN = '/^[0-5]?\d:[0-5]\d$/';

	const JERSEY_PATTERN = '/^\d{1,3}$/';

	/**
	 * Offense => default minutes and whether an organizer should look at it.
	 * Nothing happens automatically from a flag: it only lists the game for review.
	 */
	const OFFENSES = array(
		'minor'            => array(
			'minutes' => 2,
			'review'  => false,
		),
		'double_minor'     => array(
			'minutes' => 4,
			'review'  => false,
		),
		'major'            => array(
			'minutes' => 5,
			'review'  => false,
		),
		'misconduct'       => array(
			'minutes' => 10,
			'review'  => false,
		),
		'game_misconduct'  => array(
			'minutes' => 10,
			'review'  => true,
		),
		'gross_misconduct' => array(
			'minutes' => 10,
			'review'  => true,
		),
		'match'            => array(
			'minutes' => 5,
			'review'  => true,
		),
		'fighting'         => array(
			'minutes' => 5,
			'review'  => true,
		),
		'ejection'         => array(
			'minutes' => 10,
			'review'  => true,
		),
		'other'            => array(
			'minutes' => 2,
			'review'  => false,
		),
	);

	/**
	 * Check one entry against its game and normalise it to the stored shape.
	 *
	 * @param array $in  Entry as sent: uid, type, team_id, period, clock?, player_id?,
	 *                   assist1_id?, assist2_id?, jersey?, minutes?, offense?, flags?, text?.
	 * @param array $ctx team_ids (home first), rosters (team id => player ids the
	 *                   keeper may name), finalized (bool: refuse everything).
	 * @return array{ok:bool,reason?:string,entry?:array}
	 */
	public static function validate( array $in, array $ctx ): array {
		$uid = is_string( $in['uid'] ?? null ) ? $in['uid'] : '';
		if ( 1 !== preg_match( self::UID_PATTERN, $uid ) ) {
			return self::no( 'invalid_uid' );
		}
		if ( ! empty( $ctx['finalized'] ) ) {
			return self::no( 'finalized' );
		}
		$type = is_string( $in['type'] ?? null ) ? $in['type'] : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return self::no( 'invalid_type' );
		}
		$period = self::id( $in['period'] ?? null );
		if ( ! is_int( $period ) || $period > self::PERIOD_SO ) {
			return self::no( 'invalid_period' );
		}

		$clock = self::text( $in['clock'] ?? null );
		if ( null !== $clock && 1 !== preg_match( self::CLOCK_PATTERN, $clock ) ) {
			return self::no( 'invalid_clock' );
		}
		$text = self::text( $in['text'] ?? null );
		if ( null !== $text && mb_strlen( $text ) > self::TEXT_MAX ) {
			return self::no( 'text_too_long' );
		}
		$flags = self::flags( $in['flags'] ?? null );
		if ( false === $flags ) {
			return self::no( 'invalid_flags' );
		}

		$team_ids = array_values( array_map( 'intval', (array) ( $ctx['team_ids'] ?? array() ) ) );
		$team_id  = self::id( $in['team_id'] ?? null );
		if ( false === $team_id || ( null !== $team_id && ! in_array( $team_id, $team_ids, true ) ) ) {
			return self::no( 'team_not_on_game' );
		}

		$entry = array(
			'uid'          => $uid,
			'type'         => $type,
			'team_id'      => (int) $team_id,
			'period'       => $period,
			'clock'        => $clock,
			'player_id'    => null,
			'assist1_id'   => null,
			'assist2_id'   => null,
			'jersey'       => null,
			'minutes'      => null,
			'offense'      => null,
			'flags'        => null,
			'text'         => $text,
			'needs_review' => 0,
		);

		if ( 'period' === $type || 'note' === $type ) {
			if ( 'period' === $type && null !== $team_id ) {
				return self::no( 'team_not_allowed' );
			}
			if ( 'note' === $type && null === $text ) {
				return self::no( 'text_required' );
			}
			if ( null !== $flags ) {
				return self::no( 'invalid_flags' );
			}
			return self::yes( $entry );
		}

		// Goals and penalties belong to a team and name a player (or a jersey not on the list).
		if ( null === $team_id ) {
			return self::no( 'team_not_on_game' );
		}
		$roster    = array_map( 'intval', (array) ( $ctx['rosters'][ $team_id ] ?? array() ) );
		$player_id = self::id( $in['player_id'] ?? null );
		if ( false === $player_id || ( null !== $player_id && ! in_array( $player_id, $roster, true ) ) ) {
			return self::no( 'player_not_on_team' );
		}
		$jersey = null;
		if ( null === $player_id ) {
			$jersey = self::text( isset( $in['jersey'] ) && is_scalar( $in['jersey'] ) ? (string) $in['jersey'] : null );
			if ( null === $jersey ) {
				return self::no( 'player_required' );
			}
			if ( 1 !== preg_match( self::JERSEY_PATTERN, $jersey ) ) {
				return self::no( 'invalid_jersey' );
			}
		}
		$entry['player_id'] = $player_id;
		$entry['jersey']    = $jersey;

		if ( 'goal' === $type ) {
			return self::goal( $entry, $in, $roster, $flags );
		}
		return self::penalty( $entry, $in, $flags );
	}

	/**
	 * Whether an entry names a jersey that is not on the team's list.
	 *
	 * @param array $entry Stored or normalised entry.
	 */
	public static function not_on_list( array $entry ): bool {
		return empty( $entry['player_id'] ) && null !== ( $entry['jersey'] ?? null ) && '' !== (string) $entry['jersey'];
	}

	/**
	 * What a log adds up to. Voided entries (voided_at set) are ignored.
	 *
	 * @param array $entries  Stored entries (any order).
	 * @param int[] $team_ids The game's teams, home first.
	 * @return array{
	 *   team_ids:int[], score:array<int,int>, home:int, away:int, period:int,
	 *   decided_in:string, ot_loss_team:int, shootout:array<int,int>,
	 *   players:array<int,array<int,array{g:int,a:int,pim:int}>>, pim:array<int,int>,
	 *   goals:int, penalties:int, needs_review:int, unattributed_goals:int
	 * }
	 */
	public static function derive( array $entries, array $team_ids ): array {
		$team_ids = array_values( array_map( 'intval', $team_ids ) );
		$counts   = array();
		$players  = array();
		$pim      = array_fill_keys( $team_ids, 0 );
		$period   = 1;
		$totals   = array(
			'goals'              => 0,
			'penalties'          => 0,
			'needs_review'       => 0,
			'unattributed_goals' => 0,
		);

		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['voided_at'] ) ) {
				continue;
			}
			$team = (int) ( $entry['team_id'] ?? 0 );
			$per  = (int) ( $entry['period'] ?? 1 );
			$period                  = max( $period, $per );
			$totals['needs_review'] += (int) ! empty( $entry['needs_review'] );

			if ( 'goal' === ( $entry['type'] ?? '' ) && $team > 0 ) {
				$counts[ $team ][ $per ] = ( $counts[ $team ][ $per ] ?? 0 ) + 1;
				if ( self::PERIOD_SO === $per ) {
					continue; // A shootout goal decides the game; it is not a player's goal.
				}
				++$totals['goals'];
				$scorer = (int) ( $entry['player_id'] ?? 0 );
				if ( $scorer > 0 ) {
					self::add( $players, $team, $scorer, 'g', 1 );
				} else {
					++$totals['unattributed_goals'];
				}
				foreach ( array( 'assist1_id', 'assist2_id' ) as $key ) {
					$assist = (int) ( $entry[ $key ] ?? 0 );
					if ( $assist > 0 ) {
						self::add( $players, $team, $assist, 'a', 1 );
					}
				}
			} elseif ( 'penalty' === ( $entry['type'] ?? '' ) && $team > 0 ) {
				++$totals['penalties'];
				$minutes      = (int) ( $entry['minutes'] ?? 0 );
				$pim[ $team ] = ( $pim[ $team ] ?? 0 ) + $minutes;
				$player       = (int) ( $entry['player_id'] ?? 0 );
				if ( $player > 0 ) {
					self::add( $players, $team, $player, 'pim', $minutes );
				}
			}
		}

		return array_merge(
			self::score( $counts, $team_ids ),
			array(
				'team_ids' => $team_ids,
				'period'   => $period,
				'players'  => $players,
				'pim'      => $pim,
			),
			$totals
		);
	}

	/**
	 * Score, period and review count from grouped counts of active entries (one
	 * query for many games), by the same rules as derive().
	 *
	 * @param array $rows     Rows {team_id, type, period, n, review}.
	 * @param int[] $team_ids The game's teams, home first.
	 * @return array{score:array<int,int>,home:int,away:int,period:int,needs_review:int,decided_in:string}
	 */
	public static function from_counts( array $rows, array $team_ids ): array {
		$team_ids = array_values( array_map( 'intval', $team_ids ) );
		$counts   = array();
		$period   = 1;
		$review   = 0;
		foreach ( $rows as $row ) {
			$row    = (array) $row;
			$per    = (int) ( $row['period'] ?? 1 );
			$period = max( $period, $per );
			$review += (int) ( $row['review'] ?? 0 );
			if ( 'goal' === ( $row['type'] ?? '' ) && (int) ( $row['team_id'] ?? 0 ) > 0 ) {
				$team                    = (int) $row['team_id'];
				$counts[ $team ][ $per ] = ( $counts[ $team ][ $per ] ?? 0 ) + (int) ( $row['n'] ?? 0 );
			}
		}
		$score = self::score( $counts, $team_ids );
		return array(
			'score'        => $score['score'],
			'home'         => $score['home'],
			'away'         => $score['away'],
			'period'       => $period,
			'needs_review' => $review,
			'decided_in'   => $score['decided_in'],
		);
	}

	/**
	 * Score from goals per team per period (see the class comment for the
	 * overtime and shootout rules).
	 *
	 * @param array $counts   team id => period => goals.
	 * @param int[] $team_ids Home first.
	 */
	private static function score( array $counts, array $team_ids ): array {
		$home = (int) ( $team_ids[0] ?? 0 );
		$away = (int) ( $team_ids[1] ?? 0 );

		$regulation = array();
		$total      = array();
		$shootout   = array();
		foreach ( $team_ids as $team ) {
			$per                 = $counts[ $team ] ?? array();
			$regulation[ $team ] = (int) ( $per[1] ?? 0 ) + (int) ( $per[2] ?? 0 ) + (int) ( $per[3] ?? 0 );
			$total[ $team ]      = $regulation[ $team ] + (int) ( $per[ self::PERIOD_OT ] ?? 0 );
			$shootout[ $team ]   = (int) ( $per[ self::PERIOD_SO ] ?? 0 );
		}

		$decided = 'tie';
		$winner  = 0;
		$loser   = 0;
		if ( $home > 0 && $away > 0 ) {
			if ( $total[ $home ] !== $total[ $away ] ) {
				$decided = $regulation[ $home ] === $regulation[ $away ] ? 'overtime' : 'regulation';
				$winner  = $total[ $home ] > $total[ $away ] ? $home : $away;
			} elseif ( $shootout[ $home ] !== $shootout[ $away ] ) {
				$decided = 'shootout';
				$winner  = $shootout[ $home ] > $shootout[ $away ] ? $home : $away;
				++$total[ $winner ];
			}
			$loser = 0 === $winner ? 0 : ( $winner === $home ? $away : $home );
		}

		return array(
			'score'        => $total,
			'home'         => (int) ( $total[ $home ] ?? 0 ),
			'away'         => (int) ( $total[ $away ] ?? 0 ),
			'decided_in'   => $decided,
			'ot_loss_team' => in_array( $decided, array( 'overtime', 'shootout' ), true ) ? $loser : 0,
			'shootout'     => $shootout,
		);
	}

	private static function goal( array $entry, array $in, array $roster, ?string $flags ): array {
		$assists = array();
		foreach ( array( 'assist1_id', 'assist2_id' ) as $key ) {
			$assist = self::id( $in[ $key ] ?? null );
			if ( false === $assist || ( null !== $assist && ! in_array( $assist, $roster, true ) ) ) {
				return self::no( 'assist_not_on_team' );
			}
			if ( null !== $assist ) {
				$assists[] = $assist;
			}
		}
		if ( $assists && self::PERIOD_SO === $entry['period'] ) {
			return self::no( 'no_assists_in_shootout' );
		}
		if ( null !== $entry['player_id'] && in_array( $entry['player_id'], $assists, true ) ) {
			return self::no( 'assist_is_scorer' );
		}
		if ( 2 === count( $assists ) && $assists[0] === $assists[1] ) {
			return self::no( 'assists_same' );
		}
		$entry['assist1_id'] = $assists[0] ?? null;
		$entry['assist2_id'] = $assists[1] ?? null;
		$entry['flags']      = $flags;
		return self::yes( $entry );
	}

	private static function penalty( array $entry, array $in, ?string $flags ): array {
		$offense = is_string( $in['offense'] ?? null ) ? $in['offense'] : '';
		if ( ! isset( self::OFFENSES[ $offense ] ) ) {
			return self::no( 'invalid_offense' );
		}
		if ( null !== $flags ) {
			return self::no( 'invalid_flags' );
		}
		$minutes = self::OFFENSES[ $offense ]['minutes'];
		if ( isset( $in['minutes'] ) && '' !== $in['minutes'] ) {
			$given = is_int( $in['minutes'] ) || ( is_string( $in['minutes'] ) && ctype_digit( $in['minutes'] ) ) ? (int) $in['minutes'] : -1;
			if ( $given < self::MINUTES_MIN || $given > self::MINUTES_MAX ) {
				return self::no( 'invalid_minutes' );
			}
			$minutes = $given;
		}
		$entry['offense']      = $offense;
		$entry['minutes']      = $minutes;
		$entry['needs_review'] = self::OFFENSES[ $offense ]['review'] ? 1 : 0;
		return self::yes( $entry );
	}

	/**
	 * A positive id, null when absent (null, '', 0), or false when malformed.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null|false
	 */
	private static function id( $value ) {
		if ( null === $value || '' === $value || 0 === $value || '0' === $value ) {
			return null;
		}
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', $value ) ) {
			return (int) $value;
		}
		return false;
	}

	/**
	 * Trimmed plain text, or null when empty or not a string.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = sanitize_text_field( $value );
		return '' === $value ? null : $value;
	}

	/**
	 * Flags as a comma list in FLAGS order, null when none, false when unknown.
	 *
	 * @param mixed $value Array or comma-separated string.
	 * @return string|null|false
	 */
	private static function flags( $value ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return null;
		}
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		$given = array();
		foreach ( $value as $flag ) {
			$flag = is_string( $flag ) ? strtolower( trim( $flag ) ) : '';
			if ( ! in_array( $flag, self::FLAGS, true ) ) {
				return false;
			}
			$given[] = $flag;
		}
		return implode( ',', array_values( array_intersect( self::FLAGS, $given ) ) );
	}

	private static function add( array &$players, int $team, int $player, string $stat, int $by ): void {
		if ( ! isset( $players[ $team ][ $player ] ) ) {
			$players[ $team ][ $player ] = array(
				'g'   => 0,
				'a'   => 0,
				'pim' => 0,
			);
		}
		$players[ $team ][ $player ][ $stat ] += $by;
	}

	private static function yes( array $entry ): array {
		return array(
			'ok'    => true,
			'entry' => $entry,
		);
	}

	private static function no( string $reason ): array {
		return array(
			'ok'     => false,
			'reason' => $reason,
		);
	}
}
