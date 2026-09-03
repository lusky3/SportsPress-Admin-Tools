<?php
/**
 * Registration waitlist: deadline enforcement.
 *
 * Separate from SPLM_Waitlist_Offer, which holds the convener's actions,
 * because nothing here has a human behind it. An offer is made, cancelled or
 * re-targeted synchronously by a person clicking a button, under a lock, and
 * answers with an array or a WP_Error they read. Expiry is driven by the
 * scheduler: the single event this class registers, plus sweep() as the
 * backstop for when that scheduler does not run at all. It answers with a
 * bool and a count, and swallows its own failures by design, because nothing
 * is waiting on the reply.
 *
 * That difference — different trigger, different failure policy, different
 * return contract — is what makes this a concern rather than the leftovers of
 * one.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Expiry {

	/**
	 * Hook name for the single-event offer expiry.
	 */
	const EXPIRE_HOOK = 'splm_waitlist_expire_offer';

	public function __construct() {
		add_action( self::EXPIRE_HOOK, array( __CLASS__, 'expire_offer' ) );
	}

	/**
	 * Whether a row should be expired right now.
	 *
	 * Pure, and the whole defence against a stale cron event. Clearing pending
	 * events on cancel and re-offer is not sufficient: an event already in
	 * flight cannot be recalled, so if a convener cancels an offer and
	 * re-offers the same row a day later, the FIRST event still fires at the
	 * old deadline. Expiring on the mere fact of firing would kill the new
	 * offer. This re-reads the row's own state instead.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string      $status     Current status.
	 * @param string|null $expires_at Stored UTC deadline.
	 * @return bool
	 */
	public static function should_expire( $status, $expires_at ): bool {
		if ( SPLM_Waitlist_Database::STATUS_OFFERED !== (string) $status ) {
			return false;
		}
		return SPLM_Waitlist_Database::is_past_due( $expires_at );
	}

	/**
	 * Cron callback: expire one offer, if it really is due.
	 *
	 * $id defaults to 0 so this degrades instead of fataling when invoked
	 * with no argument — a hand-triggered `wp cron event run
	 * splm_waitlist_expire_offer`, or a legacy event somehow scheduled
	 * without args. Without the default, PHP 8's ArgumentCountError would
	 * throw before should_expire() is ever reached. That path matters here:
	 * running cron events by hand is this league's routine practice on hosts
	 * where WP-Cron's self-trigger does not reliably complete — the exact
	 * unreliability sweep() exists to back up.
	 * SPLM_Waitlist_Database::get( 0 ) returns null, so the `! $row` check
	 * below short-circuits to false.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $id Row id.
	 * @return bool Whether the row was expired.
	 */
	public static function expire_offer( int $id = 0 ): bool {
		$row = SPLM_Waitlist_Database::get( (int) $id );
		if ( ! $row || ! self::should_expire( $row->status, $row->expires_at ) ) {
			return false;
		}

		// claim_token is DELIBERATELY RETAINED here (this used to null it —
		// see C1). Nulling it does not buy any of the two things it looks
		// like it buys, and it costs the one thing this feature needs most:
		//
		// - "A link that arrives late cannot be claimed" is already
		// enforced by STATUS, not by the token's presence: the public
		// claim route's claim_state() returns 'expired' the moment
		// status is STATUS_EXPIRED, independent of claim_token
		// (class-waitlist-claim.php's claim_state()), and the purchase
		// gate's product_from_request_token() -> resolve_token() path
		// checks is_claimable() (the strict predicate), which still
		// rejects an expired row outright (class-waitlist-gate.php).
		// Neither consumer that must reject a stale link relies on the
		// token being gone.
		// - "The UNIQUE index is free for the next offer on this row" is
		// not a real constraint: a re-offer of this SAME row writes a
		// freshly generated token onto this SAME row via UPDATE
		// (offer_updates()), replacing the old value in place — there is
		// no second row for the retained token to collide with.
		//
		// What retaining it buys: handle_order_completed() ties a completed
		// order back to its offer by looking up the token that rode the
		// order's own line item (find_by_token()). This league's $0
		// registration orders routinely sit in Processing for days and are
		// completed by hand — very often AFTER this row has already been
		// swept to expired. Nulling the token here made that row permanently
		// unfindable by find_by_token() from that point on, so a player who
		// claimed well inside the window showed up as silently 'expired' the
		// moment an admin got around to completing the order (C1). Tie-back
		// uses is_claimable_by_token(), not is_claimable(), specifically so
		// expiry does not re-disqualify a token that already did its job.
		return SPLM_Waitlist_Database::update(
			(int) $id,
			array(
				'status' => SPLM_Waitlist_Database::STATUS_EXPIRED,
			)
		);
	}

	/**
	 * Backstop: expire every past-due offer matching these filters.
	 *
	 * The scheduled event is the primary mechanism. This exists because
	 * WP-Cron's self-trigger is not reliable on every host — it has been
	 * observed failing to complete on this league's staging box — and a
	 * stalled cron would otherwise leave a row showing "offered" with a
	 * deadline in the past indefinitely.
	 *
	 * Bounded to the caller's own filters so a dashboard request only touches
	 * rows it was already asking about, and failures are swallowed: a sweep
	 * problem must never fail the read that triggered it.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param array $filters Optional 'season' and 'position'.
	 * @return int Rows expired.
	 */
	public static function sweep( array $filters = array() ): int {
		$expired = 0;

		try {
			foreach ( SPLM_Waitlist_Database::past_due_offered( $filters ) as $row ) {
				if ( self::expire_offer( (int) $row->id ) ) {
					wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( (int) $row->id ) );
					$expired++;
				}
			}
		} catch ( \Throwable $e ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				// The filters are folded into the message string itself so they
				// survive on a default install: SPAT_Logger::write() only emits
				// $context when spat_verbose is on. $context is kept for the
				// exception detail alone, which is genuinely fine to withhold by
				// default.
				$message = sprintf( 'waitlist sweep failed: filters=%s', wp_json_encode( $filters ) );
				$context = array();
				if ( method_exists( 'SPAT_Logger', 'is_verbose' ) && SPAT_Logger::is_verbose() ) {
					$context['exception_msg'] = $e->getMessage();
				}
				SPAT_Logger::error( 'waitlist', $message, $context );
			}
		}

		return $expired;
	}
}
