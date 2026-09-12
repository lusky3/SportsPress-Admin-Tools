<?php
/**
 * Registration waitlist: best-effort side notifications.
 *
 * Every dispatch or status change on a waitlist row can optionally copy one
 * shared address — typically a FreeScout mailbox, so the message becomes a
 * ticket the whole team can see, rather than reaching only the one convener
 * who happened to click the button.
 *
 * Deliberately separate from SPLM_Waitlist_Offer::send_offer_email(): that
 * email IS the invitation, and a failed send unwinds the offer. This one is
 * a side effect nobody is waiting on, so a failure here is logged and
 * swallowed, never propagated back to the caller.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Notify {

	/**
	 * Option holding the shared notification address. Empty disables
	 * sending entirely — the default, so a fresh install stays silent.
	 */
	const OPTION = 'splm_waitlist_notify_email';

	const EVENT_OFFER_DISPATCHED = 'offer_dispatched';
	const EVENT_OFFER_CLAIMED    = 'offer_claimed';
	const EVENT_OFFER_EXPIRED    = 'offer_expired';
	const EVENT_OFFER_WITHDRAWN  = 'offer_withdrawn';
	const EVENT_ENTRY_REMOVED    = 'entry_removed';

	/**
	 * Human labels for each event, doubling as the subject line's lead
	 * phrase. Centralised here so compose() and any caller checking a
	 * label share one source of truth.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return array(
			self::EVENT_OFFER_DISPATCHED => __( 'Waitlist offer sent', 'sportspress-league-manager' ),
			self::EVENT_OFFER_CLAIMED    => __( 'Waitlist spot claimed', 'sportspress-league-manager' ),
			self::EVENT_OFFER_EXPIRED    => __( 'Waitlist offer expired', 'sportspress-league-manager' ),
			self::EVENT_OFFER_WITHDRAWN  => __( 'Waitlist offer withdrawn', 'sportspress-league-manager' ),
			self::EVENT_ENTRY_REMOVED    => __( 'Waitlist entry removed', 'sportspress-league-manager' ),
		);
	}

	/**
	 * Notify the shared address of a waitlist row's status change.
	 *
	 * No-ops when nothing is configured — the default state — so every
	 * call site can call this unconditionally without its own "is this
	 * configured" check.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object $row     Waitlist row: needs id, name, email, season, position.
	 * @param string $event   One of the EVENT_* constants.
	 * @param array  $context Event-specific extras: 'expires_at' for a
	 *                        dispatch, 'order_id' for a claim. Ignored by
	 *                        every other event.
	 * @return bool Whether a notification was sent.
	 */
	public static function send( $row, string $event, array $context = array() ): bool {
		$to = trim( (string) get_option( self::OPTION, '' ) );
		if ( '' === $to ) {
			return false;
		}

		$labels = self::labels();
		$label  = $labels[ $event ] ?? ucfirst( str_replace( '_', ' ', $event ) );

		$subject = sprintf( '[Waitlist] %1$s — %2$s', $label, (string) $row->season );
		$body    = self::compose_body( $row, $event, $label, $context );

		$sent = wp_mail( $to, $subject, $body );

		if ( ! $sent && class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::error(
				'waitlist',
				sprintf( 'wp_mail() rejected a waitlist notification: waitlist_id=%d event=%s', (int) $row->id, $event )
			);
		}

		return (bool) $sent;
	}

	/**
	 * The notification body: the row's identifying facts, plus one
	 * event-specific line.
	 *
	 * @param object $row     Waitlist row.
	 * @param string $event   One of the EVENT_* constants.
	 * @param string $label   This event's human label, from labels().
	 * @param array  $context Event-specific extras (see send()).
	 * @return string
	 */
	private static function compose_body( $row, string $event, string $label, array $context ): string {
		$lines = array(
			$label . '.',
			'',
			sprintf( '%1$s: %2$s', __( 'Name', 'sportspress-league-manager' ), $row->name ? $row->name : __( '(none)', 'sportspress-league-manager' ) ),
			sprintf( '%1$s: %2$s', __( 'Email', 'sportspress-league-manager' ), $row->email ),
			sprintf( '%1$s: %2$s', __( 'Season', 'sportspress-league-manager' ), $row->season ),
			sprintf( '%1$s: %2$s', __( 'Position', 'sportspress-league-manager' ), $row->position ),
			sprintf( '%1$s: %2$d', __( 'Waitlist entry ID', 'sportspress-league-manager' ), (int) $row->id ),
		);

		if ( self::EVENT_OFFER_DISPATCHED === $event && ! empty( $context['expires_at'] ) ) {
			$lines[] = sprintf( '%1$s: %2$s', __( 'Claim deadline (UTC)', 'sportspress-league-manager' ), $context['expires_at'] );
		}

		if ( self::EVENT_OFFER_CLAIMED === $event && ! empty( $context['order_id'] ) ) {
			$lines[] = sprintf( '%1$s: %2$d', __( 'Order ID', 'sportspress-league-manager' ), (int) $context['order_id'] );
		}

		return implode( "\n", $lines ) . "\n";
	}
}
