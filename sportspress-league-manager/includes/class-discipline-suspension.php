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

/**
 * ExcessiveClassComplexity: row building, duplicate detection, summary and
 * delivery in one place; each method is small and the pure ones are tested
 * branch by branch, so the class total is the only thing that trips the rule.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
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
		$games      = 0;
		if ( ! $indefinite ) {
			$has_override = isset( $in['games'] ) && '' !== $in['games'];
			$games        = $has_override ? min( SPLM_Discipline_Infraction::MAX_GAMES, absint( $in['games'] ) ) : (int) $infraction->default_games;
		}

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
	 * Build the follow-up row for a decide / amend / revoke. Pure.
	 *
	 * The parent is never edited once sent; the child carries the new state and
	 * points back through parent_id. The parent's private incident note stays on
	 * the parent.
	 *
	 * @param object $parent Parent notice row.
	 * @param string $kind   decided|amended|revoked.
	 * @param array  $fields Keys: games, eligible_on, notify (revoked only).
	 * @return array Row for SPLM_Discipline_Notice_Database::insert().
	 */
	public static function build_child_row( object $parent, string $kind, array $fields ): array {
		$specific = 'revoked' === $kind
			? self::revoked_fields( $parent, $fields )
			: self::resumed_fields( $parent, $kind, $fields );

		return array_merge( self::child_base( $parent, $kind ), $specific );
	}

	/**
	 * Columns every child row copies from its parent.
	 *
	 * @param object $parent Parent notice row.
	 * @param string $kind   decided|amended|revoked.
	 * @return array
	 */
	private static function child_base( object $parent, string $kind ): array {
		return array(
			'player_id'         => (int) $parent->player_id,
			'season_id'         => (int) $parent->season_id,
			'tier_key'          => 'manual',
			'ack_key'           => (string) $parent->ack_key,
			'scope'             => 'manual-' . $kind,
			'severity'          => (string) $parent->severity,
			'team'              => (string) $parent->team,
			'division'          => (string) $parent->division,
			'source'            => 'manual',
			'infraction_id'     => (int) $parent->infraction_id,
			'rule_ref'          => (string) $parent->rule_ref,
			'infraction_title'  => (string) $parent->infraction_title,
			'rule_text'         => (string) $parent->rule_text,
			'incident_event_id' => (int) $parent->incident_event_id,
			'incident_note'     => '',
			'parent_id'         => (int) $parent->id,
		);
	}

	/**
	 * Outcome columns for a decided or amended child.
	 *
	 * A decision always ends up a games suspension; an amendment keeps the
	 * parent's outcome and may shorten to 0 games.
	 *
	 * @param object $parent Parent notice row.
	 * @param string $kind   decided|amended.
	 * @param array  $fields Keys: games, eligible_on.
	 * @return array
	 */
	private static function resumed_fields( object $parent, string $kind, array $fields ): array {
		$decided     = 'decided' === $kind;
		$eligible_on = empty( $fields['eligible_on'] ) ? null : substr( (string) $fields['eligible_on'], 0, 10 );

		return array(
			'outcome'     => $decided ? 'games' : (string) $parent->outcome,
			'games'       => max( $decided ? 1 : 0, min( SPLM_Discipline_Infraction::MAX_GAMES, (int) ( $fields['games'] ?? 0 ) ) ),
			'consequence' => 'suspend',
			'eligible_on' => $eligible_on,
			'status'      => 'pending',
		);
	}

	/**
	 * Outcome columns for a revocation's correction row: it carries no
	 * consequence, so it never counts as a suspension or warning.
	 *
	 * @param object $parent Parent notice row.
	 * @param array  $fields Key: notify (false stores the row discarded, no mail).
	 * @return array
	 */
	private static function revoked_fields( object $parent, array $fields ): array {
		return array(
			'outcome'     => (string) $parent->outcome,
			'games'       => 0,
			'consequence' => 'none',
			'eligible_on' => null,
			'status'      => false === ( $fields['notify'] ?? true ) ? 'discarded' : 'pending',
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
		return null !== self::duplicate_row( $existing, $player_id, $infraction_id, $incident_event_id, $today );
	}

	/**
	 * The in-force row an attempt would duplicate, or null. Same rules as
	 * is_duplicate().
	 *
	 * @param object[] $existing          The player's existing rows.
	 * @param int      $player_id         Player.
	 * @param int      $infraction_id     Infraction.
	 * @param int      $incident_event_id Incident match (0 = none).
	 * @param string   $today             UTC 'Y-m-d' of the attempt.
	 * @return object|null
	 */
	public static function duplicate_row( array $existing, int $player_id, int $infraction_id, int $incident_event_id, string $today ): ?object {
		foreach ( self::in_force_rows( $existing ) as $row ) {
			if ( self::row_blocks_reissue( $row, $player_id, $infraction_id, $incident_event_id, $today ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The manual suspensions still in force: not replaced by a newer
	 * non-discarded row (amend, decide, revoke), not revoked or discarded, not
	 * baseline, and actually a suspension (a revoke's correction row is not).
	 * Pending and failed drafts count: they are the convener's open work.
	 *
	 * @param object[] $rows The player's rows.
	 * @return object[] Rows in force, original order.
	 */
	public static function in_force_rows( array $rows ): array {
		$superseded = self::superseded_ids( $rows );
		$out        = array();

		foreach ( $rows as $row ) {
			if ( in_array( (string) ( $row->status ?? '' ), array( 'baseline', 'revoked', 'discarded' ), true ) ) {
				continue;
			}
			if ( 'suspend' !== (string) ( $row->consequence ?? '' ) || isset( $row->id, $superseded[ (int) $row->id ] ) ) {
				continue;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Whether one in-force row blocks re-issuing the same notice.
	 *
	 * @param object $row               Existing notice row.
	 * @param int    $player_id         Player.
	 * @param int    $infraction_id     Infraction.
	 * @param int    $incident_event_id Incident match (0 = none).
	 * @param string $today             UTC 'Y-m-d' of the attempt.
	 * @return bool
	 */
	private static function row_blocks_reissue( $row, int $player_id, int $infraction_id, int $incident_event_id, string $today ): bool {
		if ( 'manual' !== (string) ( $row->source ?? '' ) ) {
			return false;
		}
		if ( (int) $row->player_id !== $player_id || (int) $row->infraction_id !== $infraction_id || (int) $row->incident_event_id !== $incident_event_id ) {
			return false;
		}
		return 0 !== $incident_event_id || substr( (string) $row->created_at, 0, 10 ) === $today;
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
		$superseded  = self::superseded_ids( $rows );

		foreach ( $rows as $row ) {
			if ( in_array( (string) $row->status, array( 'baseline', 'revoked', 'discarded' ), true ) ) {
				continue;
			}
			// An amended or decided notice is replaced by its child row.
			if ( isset( $row->id, $superseded[ (int) $row->id ] ) ) {
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
	 * Ids of rows that are the parent of another, non-discarded row.
	 *
	 * @param object[] $rows Notice rows; fixtures may lack parent_id.
	 * @return array<int,true> Set keyed by parent id.
	 */
	private static function superseded_ids( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			$parent = isset( $row->parent_id ) ? (int) $row->parent_id : 0;
			// A discarded child never took effect, so its parent still stands.
			if ( $parent > 0 && 'discarded' !== (string) ( $row->status ?? '' ) ) {
				$ids[ $parent ] = true;
			}
		}
		return $ids;
	}

	/**
	 * Captains of every team the player is rostered on this season.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $player_id Player post id.
	 * @param int $season_id Season term id.
	 * @return array[] Each: array( 'team_id' => int, 'team' => string, 'email' => string ). 'email' may be ''.
	 */
	public static function captain_recipients( int $player_id, int $season_id ): array {
		$out = array();
		foreach ( SPLM_Discipline_Eligibility::player_team_ids( $player_id, $season_id ) as $team_id ) {
			$out[] = array(
				'team_id' => $team_id,
				'team'    => get_the_title( $team_id ),
				'email'   => SPLM_Discipline_Notice_Recipients::captain_email( $team_id ),
			);
		}
		return $out;
	}

	/**
	 * Decide which captain mails are needed. Delegates to the captain-mail class.
	 *
	 * @param array[]  $captains     Each: team_id, team, email.
	 * @param string   $player_email Player's address.
	 * @param string[] $bcc          Convener Bcc addresses.
	 * @return array[] See SPLM_Discipline_Captain_Mail::plan().
	 */
	public static function plan_captain_mail( array $captains, string $player_email, array $bcc ): array {
		return SPLM_Discipline_Captain_Mail::plan( $captains, $player_email, $bcc );
	}

	/**
	 * Remove an address from a Bcc list, case-insensitively. Delegates.
	 *
	 * @param string[] $bcc   Bcc addresses.
	 * @param string   $email Address to remove (the player's).
	 * @return string[] Reindexed.
	 */
	public static function bcc_without( array $bcc, string $email ): array {
		return SPLM_Discipline_Captain_Mail::bcc_without( $bcc, $email );
	}

	/**
	 * The convener Bcc list a delivery uses (the player is never Bcc'd on their
	 * own mail). The preview calls this too, so it cannot misreport who is
	 * covered by the Bcc.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int    $season_id    Season term id.
	 * @param string $player_email The player's address ('' when none).
	 * @return string[]
	 */
	public static function delivery_bcc( int $season_id, string $player_email ): array {
		return self::bcc_without( SPLM_Discipline_Notice_Recipients::bcc_for( $season_id, 0 ), $player_email );
	}

	/**
	 * Whether a notice in this status may be mailed. Only pending and failed
	 * rows: a sent row must never be re-mailed.
	 *
	 * @param string $status Row status.
	 * @return bool
	 */
	public static function can_deliver( string $status ): bool {
		return in_array( $status, array( 'pending', 'failed' ), true );
	}

	/**
	 * Send the player's email (To:, convener Bcc:) and each captain's own copy.
	 *
	 * The player's outcome decides the row's status, exactly like the automatic
	 * notices; a captain with no address is recorded as not notified rather
	 * than blocking the send. Addresses actually used are written to the row.
	 *
	 * Refuses anything but a pending/failed row (a sent row is never re-mailed).
	 * The player's outcome is persisted straight after their wp_mail(), before
	 * any captain mail, so a failure mid-loop cannot leave a sent mail marked
	 * pending. The caller (PR 2a's route) must run this inside
	 * SPAT_Lock::with( 'splm_discipline_notice_' . $id, 60, ... ) so two
	 * concurrent requests cannot both pass the status check.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int    $notice_id Row id.
	 * @param array  $ctx       Body context (allow-listed keys; see the body class).
	 * @param string $kind      issued|decided|amended|revoked.
	 * @return array array( 'sent' => bool, 'captains' => array, 'skipped' => string (only when refused) ).
	 */
	public static function deliver( int $notice_id, array $ctx, string $kind ): array {
		$row = SPLM_Discipline_Notice_Database::find( $notice_id );
		if ( ! $row ) {
			return array(
				'sent'     => false,
				'captains' => array(),
			);
		}
		if ( ! self::can_deliver( (string) $row->status ) ) {
			return array(
				'sent'     => false,
				'captains' => array(),
				'skipped'  => 'status',
			);
		}

		$player  = SPLM_Discipline_Notice_Recipients::player_email( (int) $row->player_id );
		$bcc     = self::delivery_bcc( (int) $row->season_id, $player['email'] );
		$subject = SPLM_Discipline_Suspension_Body::subject( $kind, (string) ( $ctx['season_name'] ?? '' ) );
		$ctx['kind'] = $kind;

		$plan = self::plan_captain_mail( self::captain_recipients( (int) $row->player_id, (int) $row->season_id ), $player['email'], $bcc );

		if ( '' === $player['email'] ) {
			self::fail_no_player_email( $notice_id, $plan );
			return array(
				'sent'     => false,
				'captains' => array(),
			);
		}

		$sent     = self::send_player_mail( $notice_id, $player, $bcc, $subject, $ctx );
		$captains = SPLM_Discipline_Captain_Mail::send( $plan, $sent, $subject, $ctx );

		SPLM_Discipline_Notice_Database::update( $notice_id, array( 'captains_notified' => wp_json_encode( $captains ) ) );

		return array(
			'sent'     => $sent,
			'captains' => $captains,
		);
	}

	/**
	 * Record that the player has no address; captains are listed as skipped.
	 *
	 * @param int     $notice_id Row id.
	 * @param array[] $plan      Result of plan_captain_mail().
	 * @return void
	 */
	private static function fail_no_player_email( int $notice_id, array $plan ): void {
		SPLM_Discipline_Notice_Database::update(
			$notice_id,
			array(
				'status'            => SPLM_Discipline_Notice_Database::STATUS_FAILED,
				'recipient_via'     => '',
				'released_by'       => get_current_user_id(),
				'last_error'        => __( 'No email address on file for this player.', 'sportspress-league-manager' ),
				'captains_notified' => wp_json_encode( SPLM_Discipline_Captain_Mail::skipped_lines( $plan ) ),
			)
		);
	}

	/**
	 * Mail the player and persist the outcome immediately, before any captain mail.
	 *
	 * @param int      $notice_id Row id.
	 * @param array    $player    Result of player_email(): email, via.
	 * @param string[] $bcc       Bcc addresses (player already removed).
	 * @param string   $subject   Subject.
	 * @param array    $ctx       Body context.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	private static function send_player_mail( int $notice_id, array $player, array $bcc, string $subject, array $ctx ): bool {
		$db      = 'SPLM_Discipline_Notice_Database';
		$headers = $bcc ? array( 'Bcc: ' . implode( ', ', $bcc ) ) : array();
		$sent    = wp_mail( $player['email'], $subject, SPLM_Discipline_Suspension_Body::body( 'player', $ctx ), $headers );

		$db::update(
			$notice_id,
			array(
				'status'        => $sent ? $db::STATUS_SENT : $db::STATUS_FAILED,
				'sent_at'       => $sent ? $db::now() : null,
				'recipient'     => $player['email'],
				'recipient_via' => $player['via'],
				'bcc'           => implode( ', ', $bcc ),
				'released_by'   => get_current_user_id(),
				'last_error'    => $sent ? '' : __( 'wp_mail() rejected the message.', 'sportspress-league-manager' ),
			)
		);

		return (bool) $sent;
	}
}
