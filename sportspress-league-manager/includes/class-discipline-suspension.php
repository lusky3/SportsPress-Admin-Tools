<?php
/**
 * Manually issued suspensions: row building, duplicate detection, recipients,
 * delivery and the history summary line.
 *
 * Pure functions (build_row, is_duplicate, summary_line) hold every decision
 * that can be wrong in an embarrassing way; the WordPress-touching methods
 * (captain_recipients, deliver) are thin.
 *
 * A SENT notice is never edited. Amend / decide / revoke insert a child row
 * (parent_id) so the history stays an append-only record.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Suspension {

	/**
	 * Build a notice row from convener input, an infraction and a projection.
	 *
	 * @param array  $in         Keys: player_id, season_id, incident_event_id, incident_note,
	 *                           games (nullable override), team, division, parent_id.
	 * @param object $infraction Infraction row.
	 * @param array  $elig       Result of SPLM_Discipline_Eligibility::next_eligible().
	 * @return array Row for SPLM_Discipline_Notice_Database::insert().
	 */
	public static function build_row( array $in, $infraction, array $elig ): array {
		$indefinite = 'indefinite' === (string) $infraction->outcome;
		$games      = $indefinite
			? 0
			: ( isset( $in['games'] ) && '' !== $in['games'] && null !== $in['games'] ? absint( $in['games'] ) : (int) $infraction->default_games );

		$eligible_on = ( ! $indefinite && ! empty( $elig['date'] ) ) ? substr( (string) $elig['date'], 0, 10 ) : null;

		return array(
			'player_id'         => (int) ( $in['player_id'] ?? 0 ),
			'season_id'         => (int) ( $in['season_id'] ?? 0 ),
			'tier_key'          => 'manual',
			'ack_key'           => 'manual:' . (int) $infraction->id,
			'scope'             => 'manual',
			'severity'          => 'critical',
			'consequence'       => 'suspend',
			'games'             => $games,
			'team'              => (string) ( $in['team'] ?? '' ),
			'division'          => (string) ( $in['division'] ?? '' ),
			'status'            => 'pending',
			'source'            => 'manual',
			'infraction_id'     => (int) $infraction->id,
			'rule_ref'          => (string) $infraction->rule_ref,
			'infraction_title'  => (string) $infraction->title,
			'rule_text'         => (string) $infraction->rule_text,
			'outcome'           => $indefinite ? 'indefinite' : 'games',
			'incident_event_id' => (int) ( $in['incident_event_id'] ?? 0 ),
			'incident_note'     => (string) ( $in['incident_note'] ?? '' ),
			'parent_id'         => (int) ( $in['parent_id'] ?? 0 ),
			'eligible_on'       => $eligible_on,
		);
	}

	/**
	 * Whether issuing this would duplicate an existing manual notice.
	 *
	 * Same player + infraction + incident match; with no incident match, the
	 * same calendar day. Revoked and discarded rows do not block re-issuing.
	 * Guards the double-click, not a legitimately repeated offence on another
	 * match.
	 *
	 * @param object[] $existing         The player's existing rows.
	 * @param int      $player_id        Player.
	 * @param int      $infraction_id    Infraction.
	 * @param int      $incident_event_id Incident match (0 = none).
	 * @param string   $today            UTC 'Y-m-d' of the attempt.
	 * @return bool
	 */
	public static function is_duplicate( array $existing, int $player_id, int $infraction_id, int $incident_event_id, string $today ): bool {
		foreach ( $existing as $row ) {
			if ( 'manual' !== (string) ( $row->source ?? '' ) ) {
				continue;
			}
			if ( in_array( (string) $row->status, array( 'revoked', 'discarded' ), true ) ) {
				continue;
			}
			if ( (int) $row->player_id !== $player_id || (int) $row->infraction_id !== $infraction_id ) {
				continue;
			}
			if ( (int) $row->incident_event_id !== $incident_event_id ) {
				continue;
			}
			if ( 0 === $incident_event_id && substr( (string) $row->created_at, 0, 10 ) !== $today ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * One-line summary of a player's record.
	 *
	 * Counts only notices that were actually issued: baseline (nothing issued),
	 * revoked and discarded rows are excluded — including them would inflate a
	 * player's apparent record, the panel's most visible possible error.
	 *
	 * @param object[] $rows Notice rows.
	 * @return string
	 */
	public static function summary_line( array $rows ): string {
		$suspensions = 0;
		$games       = 0;
		$warnings    = 0;
		$seasons     = array();

		foreach ( $rows as $row ) {
			if ( in_array( (string) $row->status, array( 'baseline', 'revoked', 'discarded' ), true ) ) {
				continue;
			}
			if ( 'suspend' === (string) $row->consequence ) {
				++$suspensions;
				$games += (int) $row->games;
			} elseif ( 'warn' === (string) $row->consequence ) {
				++$warnings;
			} else {
				continue;
			}
			$seasons[ (int) $row->season_id ] = true;
		}

		if ( 0 === $suspensions && 0 === $warnings ) {
			return __( 'No disciplinary record.', 'sportspress-league-manager' );
		}

		return sprintf(
			/* translators: 1: suspension count phrase, 2: warning count phrase, 3: season count phrase. */
			__( '%1$s, %2$s, across %3$s.', 'sportspress-league-manager' ),
			sprintf(
				/* translators: 1: number of suspensions, 2: total games phrase. */
				_n( '%1$d suspension (%2$s)', '%1$d suspensions (%2$s)', $suspensions, 'sportspress-league-manager' ),
				$suspensions,
				/* translators: %d: number of games. */
				sprintf( _n( '%d game', '%d games', $games, 'sportspress-league-manager' ), $games )
			),
			/* translators: %d: number of warnings. */
			sprintf( _n( '%d warning', '%d warnings', $warnings, 'sportspress-league-manager' ), $warnings ),
			/* translators: %d: number of seasons. */
			sprintf( _n( '%d season', '%d seasons', count( $seasons ), 'sportspress-league-manager' ), count( $seasons ) )
		);
	}

	/**
	 * Captains of every team the player is on.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $player_id Player post id.
	 * @return array[] Each: array( 'team_id' => int, 'team' => string, 'email' => string ). 'email' may be ''.
	 */
	public static function captain_recipients( int $player_id ): array {
		$out = array();
		foreach ( SPLM_Discipline_Eligibility::player_team_ids( $player_id ) as $team_id ) {
			$out[] = array(
				'team_id' => $team_id,
				'team'    => get_the_title( $team_id ),
				'email'   => SPLM_Discipline_Notice_Recipients::captain_email( $team_id ),
			);
		}
		return $out;
	}

	/**
	 * Send the player's email (To:, convener Bcc:) and each captain's own copy.
	 *
	 * The player's outcome decides the row's status, exactly like the automatic
	 * notices; a captain with no address is recorded as not notified rather
	 * than blocking the send. Addresses actually used are written to the row.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int    $notice_id Row id.
	 * @param array  $ctx       Body context (allow-listed keys; see the body class).
	 * @param string $kind      issued|decided|amended|revoked.
	 * @return array array( 'sent' => bool, 'captains' => array ).
	 */
	public static function deliver( int $notice_id, array $ctx, string $kind ): array {
		$row = SPLM_Discipline_Notice_Database::find( $notice_id );
		if ( ! $row ) {
			return array(
				'sent' => false,
				'captains' => array(),
			);
		}

		$db      = 'SPLM_Discipline_Notice_Database';
		$player  = SPLM_Discipline_Notice_Recipients::player_email( (int) $row->player_id );
		$bcc     = SPLM_Discipline_Notice_Recipients::bcc_for( (int) $row->season_id, 0 );
		$subject = SPLM_Discipline_Suspension_Body::subject( $kind, (string) ( $ctx['season_name'] ?? '' ) );
		$ctx['kind'] = $kind;

		if ( '' === $player['email'] ) {
			$db::update(
				$notice_id,
				array(
					'status'     => $db::STATUS_FAILED,
					'last_error' => __( 'No email address on file for this player.', 'sportspress-league-manager' ),
				)
			);
			return array(
				'sent' => false,
				'captains' => array(),
			);
		}

		$bcc     = array_values( array_diff( $bcc, array( $player['email'] ) ) );
		$headers = $bcc ? array( 'Bcc: ' . implode( ', ', $bcc ) ) : array();
		$sent    = wp_mail( $player['email'], $subject, SPLM_Discipline_Suspension_Body::body( 'player', $ctx ), $headers );

		$captains = array();
		foreach ( self::captain_recipients( (int) $row->player_id ) as $cap ) {
			$ok = false;
			if ( '' !== $cap['email'] && $cap['email'] !== $player['email'] ) {
				$ok = (bool) wp_mail(
					$cap['email'],
					$subject,
					SPLM_Discipline_Suspension_Body::body( 'captain', array_merge( $ctx, array( 'team_names' => $cap['team'] ) ) )
				);
			}
			$captains[] = array(
				'team'  => $cap['team'],
				'email' => $cap['email'],
				'sent'  => $ok,
			);
		}

		$db::update(
			$notice_id,
			array(
				'status'            => $sent ? $db::STATUS_SENT : $db::STATUS_FAILED,
				'sent_at'           => $sent ? $db::now() : null,
				'recipient'         => $player['email'],
				'recipient_via'     => $player['via'],
				'bcc'               => implode( ', ', $bcc ),
				'released_by'       => get_current_user_id(),
				'last_error'        => $sent ? '' : __( 'wp_mail() rejected the message.', 'sportspress-league-manager' ),
				'captains_notified' => wp_json_encode( $captains ),
			)
		);

		return array(
			'sent' => $sent,
			'captains' => $captains,
		);
	}
}
