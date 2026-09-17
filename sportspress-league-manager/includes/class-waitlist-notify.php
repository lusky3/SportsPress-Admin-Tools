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

	/**
	 * Master on/off switch, independent of which address is configured.
	 * Absent (a brand-new option key on every install, upgraded or fresh)
	 * reads as enabled via the get_option() default in is_enabled() below —
	 * matching how this class already behaved before this switch existed,
	 * so upgrading never silently turns off a live install's notifications.
	 */
	const OPTION_ENABLED = 'splm_waitlist_notify_enabled';

	/**
	 * Which events are copied to the shared address. Absent reads as "every
	 * event", for the same upgrade-safety reason as OPTION_ENABLED. An admin
	 * who explicitly saves the settings page with every category unchecked
	 * gets an empty array stored, which is a deliberate "send nothing".
	 */
	const OPTION_EVENTS = 'splm_waitlist_notify_events';

	const EVENT_OFFER_DISPATCHED = 'offer_dispatched';
	const EVENT_OFFER_CLAIMED    = 'offer_claimed';
	const EVENT_OFFER_EXPIRED    = 'offer_expired';
	const EVENT_OFFER_WITHDRAWN  = 'offer_withdrawn';
	const EVENT_ENTRY_REMOVED    = 'entry_removed';

	/**
	 * Human labels for each event, doubling as the subject line's lead
	 * phrase. Centralised here so compose() and any caller checking a
	 * label share one source of truth. Public: the settings page's
	 * per-category checkbox field reads these same labels rather than
	 * duplicating them.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::EVENT_OFFER_DISPATCHED => __( 'Waitlist offer sent', 'sportspress-league-manager' ),
			self::EVENT_OFFER_CLAIMED    => __( 'Waitlist spot claimed', 'sportspress-league-manager' ),
			self::EVENT_OFFER_EXPIRED    => __( 'Waitlist offer expired', 'sportspress-league-manager' ),
			self::EVENT_OFFER_WITHDRAWN  => __( 'Waitlist offer withdrawn', 'sportspress-league-manager' ),
			self::EVENT_ENTRY_REMOVED    => __( 'Waitlist entry removed', 'sportspress-league-manager' ),
		);
	}

	/**
	 * Every event key, in the same order as labels() — the default value
	 * for OPTION_EVENTS ("every category enabled") and the valid set a
	 * settings-page submission is sanitized against.
	 *
	 * @return string[]
	 */
	public static function all_events(): array {
		return array_keys( self::labels() );
	}

	/**
	 * Whether the master switch is on.
	 *
	 * @return bool
	 */
	private static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * Whether a specific event is in the enabled set.
	 *
	 * @param string $event One of the EVENT_* constants.
	 * @return bool
	 */
	private static function event_enabled( string $event ): bool {
		$enabled = get_option( self::OPTION_EVENTS, self::all_events() );
		return is_array( $enabled ) && in_array( $event, $enabled, true );
	}

	/**
	 * Notify the shared address of a waitlist row's status change.
	 *
	 * No-ops when nothing is configured — the default state — so every
	 * call site can call this unconditionally without its own "is this
	 * configured" check. Also no-ops when the master switch is off, or when
	 * this specific event has been unchecked on the settings page — checked
	 * in that order, ahead of the address, so turning the master switch off
	 * silences everything without touching the address or the per-category
	 * choices an admin already made.
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
		if ( ! self::is_enabled() || ! self::event_enabled( $event ) ) {
			return false;
		}

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
			self::line( __( 'Name', 'sportspress-league-manager' ), $row->name ? $row->name : __( '(none)', 'sportspress-league-manager' ) ),
			self::line( __( 'Email', 'sportspress-league-manager' ), $row->email ),
			self::line( __( 'Season', 'sportspress-league-manager' ), $row->season ),
			self::line( __( 'Position', 'sportspress-league-manager' ), $row->position ),
			self::line( __( 'Waitlist entry ID', 'sportspress-league-manager' ), (int) $row->id ),
		);

		$lines = array_merge(
			$lines,
			array_filter(
				array(
					self::dispatched_by_line( $row ),
					self::offer_message_line( $row ),
					self::event_context_line( $event, $context ),
				)
			)
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * "Dispatched by" line, present on every event once a row has ever been
	 * offered (it stays on the row through claim/expiry/withdrawal), and
	 * absent for a row removed straight from the queue, which was never
	 * offered at all.
	 *
	 * @param object $row Waitlist row.
	 * @return string '' when the row was never offered.
	 */
	private static function dispatched_by_line( $row ): string {
		$dispatched_by = isset( $row->dispatched_by ) ? (int) $row->dispatched_by : 0;
		if ( $dispatched_by <= 0 ) {
			return '';
		}

		$dispatcher = get_userdata( $dispatched_by );

		return self::line(
			__( 'Dispatched by', 'sportspress-league-manager' ),
			$dispatcher ? $dispatcher->display_name : __( '(unknown user)', 'sportspress-league-manager' )
		);
	}

	/**
	 * "Offer message" line. Read straight off the row, like dispatched_by
	 * above, rather than threaded through $context: it is a persisted
	 * column that survives claim/expiry/withdrawal exactly as dispatched_by
	 * does, so every event shows it once an offer has ever carried one --
	 * not only the dispatch event itself.
	 *
	 * @param object $row Waitlist row.
	 * @return string '' when the row carries no message.
	 */
	private static function offer_message_line( $row ): string {
		$offer_message = isset( $row->offer_message ) ? trim( (string) $row->offer_message ) : '';
		if ( '' === $offer_message ) {
			return '';
		}

		return self::line( __( 'Offer message', 'sportspress-league-manager' ), $offer_message );
	}

	/**
	 * The one line specific to a single event: the claim deadline on a
	 * dispatch, the order id on a claim. Every other event contributes
	 * nothing here.
	 *
	 * @param string $event   One of the EVENT_* constants.
	 * @param array  $context Event-specific extras (see send()).
	 * @return string '' when this event has no context line.
	 */
	private static function event_context_line( string $event, array $context ): string {
		if ( self::EVENT_OFFER_DISPATCHED === $event && ! empty( $context['expires_at'] ) ) {
			return self::line( __( 'Claim deadline (UTC)', 'sportspress-league-manager' ), $context['expires_at'] );
		}

		if ( self::EVENT_OFFER_CLAIMED === $event && ! empty( $context['order_id'] ) ) {
			return self::line( __( 'Order ID', 'sportspress-league-manager' ), (int) $context['order_id'] );
		}

		return '';
	}

	/**
	 * One "Label: value" line of the notification body.
	 *
	 * @param string     $label Field label, already translated.
	 * @param string|int $value Field value.
	 * @return string
	 */
	private static function line( string $label, $value ): string {
		return sprintf( '%1$s: %2$s', $label, $value );
	}
}
