<?php
/**
 * Captain-copy planning and sending for manual suspension notices.
 *
 * Split out of SPLM_Discipline_Suspension to keep each class small. The pure
 * planning helpers (plan, bcc_without) are also reachable through thin
 * delegates on SPLM_Discipline_Suspension.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Captain_Mail {

	/**
	 * Decide which captain mails are needed.
	 *
	 * One entry per distinct (case-insensitive) address, listing every team that
	 * address captains; addresses already receiving the notice as the player or
	 * as a Bcc are flagged via covered_by. An empty address stays its own entry
	 * per team so it records as not notified.
	 *
	 * @param array[]  $captains     Each: team_id, team, email.
	 * @param string   $player_email Player's address.
	 * @param string[] $bcc          Convener Bcc addresses.
	 * @return array[] Each: array( 'email' => string, 'teams' => string[], 'covered_by' => ''|'player'|'bcc' ).
	 */
	public static function plan( array $captains, string $player_email, array $bcc ): array {
		$player_key = strtolower( $player_email );
		$bcc_keys   = array_map( 'strtolower', $bcc );
		$plan       = array();
		$index      = array();

		foreach ( $captains as $cap ) {
			$email = (string) $cap['email'];
			$key   = strtolower( $email );
			if ( '' !== $key && isset( $index[ $key ] ) ) {
				$plan[ $index[ $key ] ]['teams'][] = (string) $cap['team'];
				continue;
			}
			$plan[] = array(
				'email'      => $email,
				'teams'      => array( (string) $cap['team'] ),
				'covered_by' => self::covered_by( $key, $player_key, $bcc_keys ),
			);
			if ( '' !== $key ) {
				$index[ $key ] = count( $plan ) - 1;
			}
		}

		return $plan;
	}
	/**
	 * Who already receives this address's copy.
	 *
	 * @param string   $key        Lower-cased captain address ('' when none).
	 * @param string   $player_key Lower-cased player address.
	 * @param string[] $bcc_keys   Lower-cased Bcc addresses.
	 * @return string ''|'player'|'bcc'.
	 */
	private static function covered_by( string $key, string $player_key, array $bcc_keys ): string {
		if ( '' === $key ) {
			return '';
		}
		if ( $key === $player_key ) {
			return 'player';
		}
		return in_array( $key, $bcc_keys, true ) ? 'bcc' : '';
	}
	/**
	 * Remove an address from a Bcc list, case-insensitively. Pure.
	 *
	 * @param string[] $bcc   Bcc addresses.
	 * @param string   $email Address to remove (the player's).
	 * @return string[] Reindexed.
	 */
	public static function bcc_without( array $bcc, string $email ): array {
		$key = strtolower( $email );
		return array_values(
			array_filter(
				$bcc,
				static function ( $addr ) use ( $key ) {
					return strtolower( (string) $addr ) !== $key;
				}
			)
		);
	}
	/**
	 * History note for a captain covered by another copy.
	 *
	 * @param string $covered_by 'player'|'bcc'.
	 * @return string
	 */
	private static function covered_note( string $covered_by ): string {
		return 'player' === $covered_by ? 'covered by player copy' : 'covered by Bcc copy';
	}
	/**
	 * Flatten a captain-mail plan to history lines, all marked not sent.
	 *
	 * @param array[] $plan Result of plan_captain_mail().
	 * @return array[] Each: array( 'team', 'email', 'sent' => false ).
	 */
	public static function skipped_lines( array $plan ): array {
		$lines = array();
		foreach ( $plan as $entry ) {
			foreach ( $entry['teams'] as $team ) {
				$lines[] = array(
					'team'  => $team,
					'email' => $entry['email'],
					'sent'  => false,
				);
			}
		}
		return $lines;
	}
	/**
	 * Send each captain's own copy where needed.
	 *
	 * @param array[] $plan    Result of plan_captain_mail().
	 * @param bool    $sent    Whether the player's mail was accepted.
	 * @param string  $subject Subject.
	 * @param array   $ctx     Body context.
	 * @return array[] History lines: team, email, sent (and note when covered).
	 */
	public static function send( array $plan, bool $sent, string $subject, array $ctx ): array {
		$captains = array();
		foreach ( $plan as $entry ) {
			$ok = $sent && ( '' !== $entry['covered_by'] || ( '' !== $entry['email'] && self::mail_captain( $entry, $subject, $ctx ) ) );
			foreach ( $entry['teams'] as $team ) {
				$line = array(
					'team'  => $team,
					'email' => $entry['email'],
					'sent'  => $ok,
				);
				if ( $sent && '' !== $entry['covered_by'] ) {
					$line['note'] = self::covered_note( $entry['covered_by'] );
				}
				$captains[] = $line;
			}
		}
		return $captains;
	}
	/**
	 * One captain's own email.
	 *
	 * @param array  $entry   Plan entry.
	 * @param string $subject Subject.
	 * @param array  $ctx     Body context.
	 * @return bool
	 */
	private static function mail_captain( array $entry, string $subject, array $ctx ): bool {
		return (bool) wp_mail(
			$entry['email'],
			$subject,
			SPLM_Discipline_Suspension_Body::body( 'captain', array_merge( $ctx, array( 'team_names' => implode( ', ', $entry['teams'] ) ) ) )
		);
	}
}
