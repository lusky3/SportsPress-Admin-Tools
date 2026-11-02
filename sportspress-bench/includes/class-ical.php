<?php
/**
 * The personal calendar feed: RFC 5545 rendering and per-user feed tokens.
 *
 * render() is pure (games in, text out) so the escaping, folding and timezone
 * rules are unit-tested; the token methods are thin user-meta wrappers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_Ical {

	const TOKEN_META = 'spbn_ical_token';

	/** Longest physical line, in octets, before folding (RFC 5545 section 3.1). */
	const FOLD_OCTETS = 75;

	/** Game length when the filter does not say otherwise. */
	const DEFAULT_DURATION_MINUTES = 75;

	/**
	 * A VCALENDAR for a set of games.
	 *
	 * Each game is the presenter's game object (id, start, status, venue, team,
	 * opponent, is_home, as_spare) plus `sequence` (the count of change rows, so
	 * calendar apps take a reschedule as an update). A game with no parseable
	 * start is skipped. Postponed and cancelled games are still listed, as
	 * STATUS:CANCELLED.
	 *
	 * @param array[]  $games            Game objects.
	 * @param string   $host             Site host, for UIDs.
	 * @param string   $calname          Calendar name (X-WR-CALNAME).
	 * @param int|null $duration_minutes Game length; null applies the
	 *                                   spbn_game_duration_minutes filter.
	 * @return string CRLF-terminated iCalendar text.
	 */
	public static function render( array $games, string $host, string $calname, ?int $duration_minutes = null ): string {
		if ( null === $duration_minutes ) {
			$duration_minutes = (int) apply_filters( 'spbn_game_duration_minutes', self::DEFAULT_DURATION_MINUTES );
		}
		$duration_minutes = max( 1, $duration_minutes );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//SportsPress Admin Tools//Bench//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape_text( $calname ),
			'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
			'X-PUBLISHED-TTL:PT1H',
		);

		foreach ( $games as $game ) {
			$lines = array_merge( $lines, self::event_lines( (array) $game, $host, $duration_minutes ) );
		}
		$lines[] = 'END:VCALENDAR';

		$out = '';
		foreach ( $lines as $line ) {
			$out .= self::fold( $line ) . "\r\n";
		}
		return $out;
	}

	/**
	 * The user's feed token, created on first use.
	 */
	public static function token_for( int $user_id ): string {
		$token = (string) get_user_meta( $user_id, self::TOKEN_META, true );
		if ( ! self::is_token( $token ) ) {
			$token = self::new_token();
			update_user_meta( $user_id, self::TOKEN_META, $token );
		}
		return $token;
	}

	/**
	 * Replace the user's token; the old feed URL stops working.
	 */
	public static function rotate( int $user_id ): string {
		$token = self::new_token();
		update_user_meta( $user_id, self::TOKEN_META, $token );
		return $token;
	}

	/**
	 * The user a feed token belongs to, or 0. A token that is not 32
	 * alphanumerics never reaches the database. MySQL compares meta values
	 * without regard to case, so the stored token is re-checked exactly.
	 */
	public static function user_for_token( string $token ): int {
		if ( ! self::is_token( $token ) ) {
			return 0;
		}
		$ids = get_users(
			array(
				'meta_key'   => self::TOKEN_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one exact lookup per feed request.
				'meta_value' => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( ! $ids ) {
			return 0;
		}
		$user_id = (int) reset( $ids );
		return hash_equals( (string) get_user_meta( $user_id, self::TOKEN_META, true ), $token ) ? $user_id : 0;
	}

	public static function feed_url( string $token ): string {
		return home_url( '/bench/ical/' . $token . '.ics' );
	}

	private static function is_token( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]{32}$/D', $token );
	}

	private static function new_token(): string {
		return (string) wp_generate_password( 32, false );
	}

	/**
	 * One VEVENT's logical lines, or none when the start cannot be read.
	 *
	 * @return string[]
	 */
	private static function event_lines( array $game, string $host, int $duration_minutes ): array {
		$start = self::utc( (string) ( $game['start'] ?? '' ) );
		if ( null === $start ) {
			return array();
		}
		$end    = $start->modify( '+' . $duration_minutes . ' minutes' );
		$status = (string) ( $game['status'] ?? 'ok' );
		$team   = (array) ( $game['team'] ?? array() );
		$venue  = (array) ( $game['venue'] ?? array() );
		$stamp  = isset( $game['dtstamp'] ) && is_int( $game['dtstamp'] ) ? $game['dtstamp'] : time();

		$lines = array(
			'BEGIN:VEVENT',
			'UID:spbn-' . (int) ( $game['id'] ?? 0 ) . '-' . (int) ( $team['id'] ?? 0 ) . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $stamp ),
			'DTSTART:' . $start->format( 'Ymd\THis\Z' ),
			'DTEND:' . $end->format( 'Ymd\THis\Z' ),
			'SEQUENCE:' . max( 0, (int) ( $game['sequence'] ?? 0 ) ),
			'STATUS:' . self::ical_status( $status ),
			'SUMMARY:' . self::escape_text( self::summary( $game, $status ) ),
		);

		$place = array_filter(
			array(
				trim( (string) ( $venue['name'] ?? '' ) ),
				trim( (string) ( $venue['address'] ?? '' ) ),
			),
			'strlen'
		);
		if ( $place ) {
			$lines[] = 'LOCATION:' . self::escape_text( implode( ', ', $place ) );
		}

		$lines[] = 'END:VEVENT';
		return $lines;
	}

	private static function utc( string $iso ): ?DateTimeImmutable {
		if ( '' === trim( $iso ) ) {
			return null;
		}
		try {
			return ( new DateTimeImmutable( $iso ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}
	}

	private static function ical_status( string $status ): string {
		if ( 'postponed' === $status || 'cancelled' === $status ) {
			return 'CANCELLED';
		}
		return 'tbd' === $status ? 'TENTATIVE' : 'CONFIRMED';
	}

	/**
	 * "[stick] Ducks vs Knights" at home, "[stick] Ducks @ Knights" away; spare
	 * games are tagged and called-off ones say so for clients that ignore STATUS.
	 */
	private static function summary( array $game, string $status ): string {
		$team     = trim( (string) ( ( (array) ( $game['team'] ?? array() ) )['name'] ?? '' ) );
		$opponent = trim( (string) ( ( (array) ( $game['opponent'] ?? array() ) )['name'] ?? '' ) );

		$title = "\u{1F3D2} " . $team;
		if ( '' !== $opponent ) {
			$title .= ( ! empty( $game['is_home'] ) ? ' vs ' : ' @ ' ) . $opponent;
		}
		if ( ! empty( $game['as_spare'] ) ) {
			$title .= ' (spare)';
		}
		if ( 'postponed' === $status ) {
			return 'Postponed: ' . $title;
		}
		if ( 'cancelled' === $status ) {
			return 'Cancelled: ' . $title;
		}
		return $title;
	}

	/**
	 * Escape a TEXT value: backslash first, then ; and , and line breaks.
	 */
	private static function escape_text( string $text ): string {
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( array( ';', ',' ), array( '\\;', '\\,' ), $text );
		return str_replace( array( "\r\n", "\r", "\n" ), '\\n', $text );
	}

	/**
	 * Fold a logical line to 75 octets per physical line: continuation lines
	 * start with one space (which counts toward their 75). Breaks fall between
	 * characters, never inside a UTF-8 multibyte sequence.
	 */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= self::FOLD_OCTETS ) {
			return $line;
		}
		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			$chars = str_split( $line );
		}

		$out     = '';
		$current = '';
		$limit   = self::FOLD_OCTETS;
		foreach ( $chars as $char ) {
			if ( strlen( $current ) + strlen( $char ) > $limit ) {
				$out    .= $current . "\r\n ";
				$current = '';
				$limit   = self::FOLD_OCTETS - 1;
			}
			$current .= $char;
		}
		return $out . $current;
	}
}
