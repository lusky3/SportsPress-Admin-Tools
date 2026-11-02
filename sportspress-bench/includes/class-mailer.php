<?php
/**
 * Bench's emails: a spare call going out to the pool, the team being told it
 * is filled, and accepted spares hearing that their game changed.
 *
 * Plain text, sent in-request. Pool and spare mails go out as BCC chunks so
 * nobody sees another player's address; the To address is bcc_to() (the
 * spbn_mail_to option or noreply@ the site's host, then the spbn_mail_to filter).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_Mailer {

	/** Recipients per BCC mail. */
	const CHUNK_SIZE = 40;

	/**
	 * Listen for game changes. Only accepted spares are mailed: events-manager
	 * already tells the teams, and never the spares.
	 */
	public static function register(): void {
		add_action( 'spbn_event_changed', array( __CLASS__, 'game_changed_for_spares' ), 10, 3 );
	}

	/**
	 * Offer a spare call to a set of users.
	 *
	 * @param array $call     The call {position,needed,note,...}.
	 * @param array $game     Game object from the calling team's point of view.
	 * @param int[] $user_ids Users to email.
	 * @return int Recipients mailed.
	 */
	public static function call_posted( array $call, array $game, array $user_ids ): int {
		$emails = self::emails_for( $user_ids );
		if ( ! $emails ) {
			return 0;
		}

		$team    = self::team_name( $game );
		$subject = sprintf(
			/* translators: 1: team name, 2: game date and time. */
			__( 'Spare needed: %1$s · %2$s', 'sportspress-bench' ),
			$team,
			self::when( (string) ( $game['start'] ?? '' ) )
		);

		$position = 'goalie' === ( $call['position'] ?? '' ) ? 'goalie' : 'skater';
		$needed   = max( 1, (int) ( $call['needed'] ?? 1 ) );
		$lines    = array(
			sprintf(
				/* translators: 1: team name, 2: number needed, 3: skater or goalie. */
				_n( '%1$s need %2$d %3$s spare.', '%1$s need %2$d %3$s spares.', $needed, 'sportspress-bench' ),
				$team,
				$needed,
				$position
			),
			'',
			__( 'When:', 'sportspress-bench' ) . ' ' . self::when( (string) ( $game['start'] ?? '' ) ),
		);
		$venue    = trim( (string) ( ( (array) ( $game['venue'] ?? array() ) )['name'] ?? '' ) );
		if ( '' !== $venue ) {
			$lines[] = __( 'Where:', 'sportspress-bench' ) . ' ' . $venue;
		}
		$opponent = trim( (string) ( ( (array) ( $game['opponent'] ?? array() ) )['name'] ?? '' ) );
		if ( '' !== $opponent ) {
			$lines[] = __( 'Against:', 'sportspress-bench' ) . ' ' . $opponent;
		}
		$note = trim( (string) ( $call['note'] ?? '' ) );
		if ( '' !== $note ) {
			$lines[] = __( 'Note:', 'sportspress-bench' ) . ' ' . $note;
		}
		$lines[] = '';
		$lines[] = __( 'Can you play? Open Bench and tap "I\'ll play":', 'sportspress-bench' );
		$lines[] = home_url( '/bench/spares' );

		return self::send_bcc( $emails, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Tell the person who posted a call that a spare has taken a place.
	 */
	public static function call_filled( array $call, array $game, int $creator_id, string $spare_name ): bool {
		$emails = self::emails_for( array( $creator_id ) );
		if ( ! $emails ) {
			return false;
		}

		$team    = self::team_name( $game );
		$when    = self::when( (string) ( $game['start'] ?? '' ) );
		$subject = sprintf(
			/* translators: 1: team name, 2: game date and time. */
			__( 'Spare filled: %1$s · %2$s', 'sportspress-bench' ),
			$team,
			$when
		);
		$body    = implode(
			"\n",
			array(
				sprintf(
					/* translators: 1: spare's name, 2: skater or goalie, 3: team name, 4: game date and time. */
					__( '%1$s will play as a %2$s spare for %3$s on %4$s.', 'sportspress-bench' ),
					self::one_line( $spare_name ),
					'goalie' === ( $call['position'] ?? '' ) ? 'goalie' : 'skater',
					$team,
					$when
				),
				'',
				home_url( '/bench/spares' ),
			)
		);

		return self::send( $emails[0], $subject, $body, array() );
	}

	/**
	 * Tell spares who had accepted a call that the team no longer needs them.
	 *
	 * @param array $call     The cancelled call.
	 * @param array $game     Game object from the calling team's point of view.
	 * @param int[] $user_ids The spares who held a place.
	 * @return int Recipients mailed.
	 */
	public static function call_cancelled( array $call, array $game, array $user_ids ): int {
		$emails = self::emails_for( $user_ids );
		if ( ! $emails ) {
			return 0;
		}

		$team = self::team_name( $game );
		$when = self::when( (string) ( $game['start'] ?? '' ) );
		$body = implode(
			"\n",
			array(
				sprintf(
					/* translators: 1: team name, 2: game date and time. */
					__( '%1$s no longer need a spare for their game on %2$s. You do not need to play.', 'sportspress-bench' ),
					$team,
					$when
				),
				'',
				home_url( '/bench/spares' ),
			)
		);

		return self::send_bcc(
			$emails,
			sprintf(
				/* translators: 1: team name, 2: game date and time. */
				__( 'Spare game cancelled: %1$s · %2$s', 'sportspress-bench' ),
				$team,
				$when
			),
			$body
		);
	}

	/**
	 * Tell the accepted spares of a game that it changed or was called off.
	 *
	 * @param int   $event_id Event id.
	 * @param array $before   {start,venue,teams,status} before the change.
	 * @param array $after    {start,venue,teams,status} now.
	 * @return int Spares mailed.
	 */
	public static function game_changed_for_spares( int $event_id, array $before, array $after ): int {
		global $wpdb;
		$tables = SPBN_Schema::tables();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from SPBN_Schema, not input; every value stays bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT r.user_id, c.team_id FROM {$tables['spare_responses']} r
				INNER JOIN {$tables['spare_calls']} c ON c.id = r.call_id
				WHERE c.event_id = %d AND r.status = %s AND c.status <> %s
				ORDER BY c.team_id ASC, r.user_id ASC",
				$event_id,
				'accepted',
				'cancelled'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// One mail per calling team, so the subject can name the team they spare for.
		$by_team = array();
		$seen    = array();
		foreach ( (array) $rows as $row ) {
			$user_id = (int) ( $row['user_id'] ?? 0 );
			$team_id = (int) ( $row['team_id'] ?? 0 );
			if ( $user_id > 0 && ! isset( $seen[ $user_id ] ) ) {
				$seen[ $user_id ]      = true;
				$by_team[ $team_id ][] = $user_id;
			}
		}

		$status    = (string) ( $after['status'] ?? 'ok' );
		$cancelled = 'cancelled' === $status;
		$body      = self::change_body( $status, $before, $after );
		// A change names the new time (when the spare must show up); a cancellation
		// names the time the game was listed for. Each falls back to the other.
		$new   = (string) ( $after['start'] ?? '' );
		$old   = (string) ( $before['start'] ?? '' );
		$start = $cancelled ? ( '' !== $old ? $old : $new ) : ( '' !== $new ? $new : $old );
		$when  = self::when( $start );

		$sent = 0;
		foreach ( $by_team as $team_id => $user_ids ) {
			$emails = self::emails_for( $user_ids );
			if ( ! $emails ) {
				continue;
			}
			$team    = $team_id > 0 ? get_post( $team_id ) : null;
			$name    = $team ? self::one_line( (string) $team->post_title ) : '';
			$subject = $cancelled
				/* translators: 1: team name, 2: game date and time. */
				? __( 'Your spare game is cancelled: %1$s · %2$s', 'sportspress-bench' )
				/* translators: 1: team name, 2: game date and time. */
				: __( 'Your spare game changed: %1$s · %2$s', 'sportspress-bench' );
			$sent   += self::send_bcc( $emails, sprintf( $subject, $name, $when ), $body );
		}
		return $sent;
	}

	/**
	 * What changed, as the body of a spare's change mail.
	 *
	 * @param string $status The normalised status now.
	 * @param array  $before {start,venue,teams,status} before the change.
	 * @param array  $after  {start,venue,teams,status} now.
	 */
	private static function change_body( string $status, array $before, array $after ): string {
		$cancelled = 'cancelled' === $status;
		$lines     = array();
		if ( $cancelled ) {
			$lines[] = __( 'The game you were going to spare in has been cancelled, so you are not needed.', 'sportspress-bench' );
		} elseif ( 'postponed' === $status ) {
			$lines[] = __( 'The game you were going to spare in has been postponed. Check Bench for the new date.', 'sportspress-bench' );
		} else {
			$lines[] = __( 'The game you are sparing in has changed.', 'sportspress-bench' );
		}
		$lines[] = '';
		$lines[] = __( 'Was:', 'sportspress-bench' ) . ' ' . self::describe( $before );
		if ( ! $cancelled ) {
			$lines[] = __( 'Now:', 'sportspress-bench' ) . ' ' . self::describe( $after );
		}
		if ( ! empty( $before['teams'] ) && (array) ( $before['teams'] ?? array() ) !== (array) ( $after['teams'] ?? array() ) ) {
			$lines[] = __( 'The matchup has changed too.', 'sportspress-bench' );
		}
		$lines[] = '';
		$lines[] = home_url( '/bench/spares' );

		return implode( "\n", $lines );
	}

	/**
	 * Valid, distinct addresses for some users, in user id order. Never asks
	 * WordPress for "all users": an empty list returns nothing.
	 *
	 * @param int[] $user_ids User ids.
	 * @return string[]
	 */
	private static function emails_for( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return array();
		}
		$users = (array) get_users(
			array(
				'include' => $user_ids,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'user_email' ),
			)
		);
		usort(
			$users,
			function ( $a, $b ) {
				return (int) $a->ID <=> (int) $b->ID;
			}
		);

		$out = array();
		foreach ( $users as $user ) {
			$email = sanitize_email( (string) $user->user_email );
			if ( '' === $email || 1 === preg_match( '/[\r\n,]/', $email ) || ! is_email( $email ) ) {
				continue;
			}
			$out[ strtolower( $email ) ] = $email;
		}
		return array_values( $out );
	}

	/**
	 * Where BCC mail is addressed: the spbn_mail_to option when it holds a valid
	 * address, else noreply@ the site's host, passed through the spbn_mail_to
	 * filter. Never the site admin, whose inbox would get a copy of every blast.
	 * '' when the result is not a usable address.
	 */
	public static function bcc_to(): string {
		$option  = trim( (string) get_option( 'spbn_mail_to', '' ) );
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$default = '' !== $option && is_email( $option ) ? $option : ( '' !== $host ? 'noreply@' . $host : '' );

		$to = trim( (string) apply_filters( 'spbn_mail_to', $default ) );
		if ( '' === $to || 1 === preg_match( '/[\r\n,]/', $to ) || ! is_email( $to ) ) {
			return '';
		}
		return $to;
	}

	/**
	 * One mail per chunk of 40, addressed to bcc_to() with every recipient on
	 * BCC.
	 *
	 * @param string[] $emails Recipients.
	 * @return int Recipients in the mails that were accepted for delivery.
	 */
	private static function send_bcc( array $emails, string $subject, string $body ): int {
		$to = self::bcc_to();
		if ( '' === $to ) {
			return 0;
		}

		$sent = 0;
		foreach ( array_chunk( $emails, self::CHUNK_SIZE ) as $chunk ) {
			if ( self::send( $to, $subject, $body, array( 'Bcc: ' . implode( ', ', $chunk ) ) ) ) {
				$sent += count( $chunk );
			}
		}
		return $sent;
	}

	/**
	 * @param string   $to            Recipient address.
	 * @param string   $subject       Subject line.
	 * @param string   $body          Plain-text body.
	 * @param string[] $extra_headers Headers beyond the content type.
	 */
	private static function send( string $to, string $subject, string $body, array $extra_headers ): bool {
		$headers = array_merge( array( 'Content-Type: text/plain; charset=UTF-8' ), $extra_headers );
		$sent    = (bool) wp_mail( $to, self::one_line( $subject ), $body, $headers );
		if ( ! $sent && class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::warn( 'bench', 'A Bench email could not be sent.' );
		}
		return $sent;
	}

	private static function team_name( array $game ): string {
		return self::one_line( (string) ( ( (array) ( $game['team'] ?? array() ) )['name'] ?? '' ) );
	}

	/**
	 * "Thu Nov 5, 9:45 PM" in the game's own offset, or the raw text when it
	 * does not parse.
	 */
	private static function when( string $iso ): string {
		if ( '' === trim( $iso ) ) {
			return '';
		}
		try {
			return ( new DateTimeImmutable( $iso ) )->format( 'D M j, g:i A' );
		} catch ( Exception $e ) {
			return $iso;
		}
	}

	/**
	 * "Thu Nov 5, 9:45 PM at Twin Rinks" for a {start,venue,...} snapshot.
	 */
	private static function describe( array $snapshot ): string {
		$text  = self::when( (string) ( $snapshot['start'] ?? '' ) );
		$venue = trim( (string) ( $snapshot['venue'] ?? '' ) );
		if ( '' !== $venue ) {
			$text .= ( '' !== $text ? ' ' : '' ) . __( 'at', 'sportspress-bench' ) . ' ' . $venue;
		}
		return '' !== $text ? $text : __( 'unknown', 'sportspress-bench' );
	}

	/**
	 * Collapse line breaks so a name cannot add a header.
	 */
	private static function one_line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}
}
