<?php
/**
 * Whether a name-matched sp_player record really belongs to the paying customer.
 *
 * A name is a candidate, not an identity. Two different people called "Steve
 * Long" (different accounts, emails and birth years) both exist on the live
 * roster, and a name-only match once attached one's order to the other's record
 * and then overwrote that record's email and user link with the wrong person's.
 * This class weighs everything the site already knows about the record against
 * the order before a name match is accepted:
 *
 *   - the account it is linked to (sp_user),
 *   - its stored spt_email,
 *   - the orders already linked to it in wp_spat_registration_logs.
 *
 * post_author is deliberately NOT evidence. On the live site two former staff
 * accounts, since demoted to customer and subscriber, authored over a thousand
 * player records between them, so no capability check can tell "typed the
 * record in" from "is this player". Only sp_user says who a record belongs to.
 *
 * Verdicts:
 *   CONFLICT   — a positive contradiction. The linked user is another account, the
 *                stored email differs, or earlier linked orders belong to a
 *                different customer and nothing corroborates this one.
 *   VERIFIED   — something agrees: same linked user, same stored email, or an earlier
 *                linked order from the same customer or billing email.
 *   UNVERIFIED — nothing to compare. Most legacy records carry no email, user
 *                link or logged orders, so these are allowed (blocking them would
 *                flood the admins) but logged distinctly for audit.
 *
 * Guest checkout (customer ID 0) is compared by billing email alone: against the
 * linked account's email and the earlier orders' billing emails. A guest whose
 * email differs from the linked account's is a contradiction, the same as a
 * signed-in customer on a different account.
 *
 * Read-only: nothing here writes. Shared by registration, link_user_to_player()
 * and the `wp spr audit-name-matches` report so all three apply one policy.
 *
 * @package SportsPress_Player_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPR_Identity_Check {

	const VERIFIED   = 'verified';
	const UNVERIFIED = 'unverified';
	const CONFLICT   = 'conflict';

	/** Most recent linked orders read per record. A real player has a handful. */
	const HISTORY_LIMIT = 50;

	/** @var bool Whether spt_email is compared (the spr_email_meta setting). */
	private $compare_stored_email;

	/**
	 * @param bool $compare_stored_email Compare the stored spt_email meta.
	 */
	public function __construct( $compare_stored_email = true ) {
		$this->compare_stored_email = (bool) $compare_stored_email;
	}

	/**
	 * Weigh a candidate record against an order's customer.
	 *
	 * @param int    $player_id        Candidate sp_player ID.
	 * @param int    $customer_id      Order's customer user ID (0 for a guest).
	 * @param string $billing_email    Order's billing email.
	 * @param int    $exclude_order_id The order being judged, left out of the history.
	 * @return array{verdict:string,reasons:string[],orders:int[]} `orders` lists
	 *         the linked orders that belong to a different customer.
	 */
	public function assess( $player_id, $customer_id, $billing_email, $exclude_order_id = 0 ) {
		$customer = array(
			'customer_id' => (int) $customer_id,
			'email'       => self::normalize_email( $billing_email ),
		);

		$reasons = array();
		$checks  = array(
			'linked_to_other_user' => $this->owner_agrees( (int) $player_id, $customer ),
			'stored_email_differs' => $this->stored_email_agrees( (int) $player_id, $customer['email'] ),
		);
		foreach ( $checks as $reason => $agrees ) {
			if ( false === $agrees ) {
				$reasons[] = $reason;
			}
		}
		$corroborated = in_array( true, $checks, true );

		$history = $this->history( (int) $player_id, $customer, (int) $exclude_order_id );
		$corroborated = $corroborated || $history['corroborated'];
		if ( ! $corroborated && ! empty( $history['mismatched'] ) ) {
			$reasons[] = 'linked_orders_other_customer';
		}

		$verdict = self::UNVERIFIED;
		if ( ! empty( $reasons ) ) {
			$verdict = self::CONFLICT;
		} elseif ( $corroborated ) {
			$verdict = self::VERIFIED;
		}

		return array(
			'verdict' => $verdict,
			'reasons' => $reasons,
			'orders'  => $history['mismatched'],
		);
	}

	/**
	 * The account a record already belongs to (its sp_user link), or 0 when it
	 * belongs to nobody. A link to an account that no longer exists counts as
	 * nobody: there is no one left for the customer to be confused with.
	 *
	 * @param int $player_id Player post ID.
	 * @return int User ID, or 0.
	 */
	public function owner_user_id( $player_id ) {
		$sp_user = (int) get_post_meta( (int) $player_id, 'sp_user', true );
		return ( $sp_user > 0 && get_userdata( $sp_user ) ) ? $sp_user : 0;
	}

	/**
	 * Orders already linked to a record by registration, newest first.
	 *
	 * Read from wp_spat_registration_logs (links_to_order = 1) rather than the
	 * orders' own `_spr_processed_items` meta: the log covers every order since
	 * logging began, the meta only orders processed since it was added, and the
	 * log is indexed by player.
	 *
	 * @param int $player_id        Player post ID.
	 * @param int $exclude_order_id Order to leave out (the one being judged).
	 * @return int[]
	 */
	public function linked_order_ids( $player_id, $exclude_order_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'spat_registration_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT order_id FROM `{$table}` WHERE player_id = %d AND links_to_order = 1 AND order_id <> %d ORDER BY order_id DESC LIMIT %d",
				(int) $player_id,
				(int) $exclude_order_id,
				self::HISTORY_LIMIT
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Whether the record's owner is this customer: true, false, or null when
	 * the record has no owner or there is nothing to compare.
	 *
	 * A signed-in customer must be the owner account itself. A guest is compared
	 * by billing email against the owner account's email.
	 *
	 * @param int   $player_id Player post ID.
	 * @param array $customer  customer_id and email.
	 * @return bool|null
	 */
	private function owner_agrees( $player_id, $customer ) {
		$owner = $this->owner_user_id( $player_id );
		if ( $owner <= 0 ) {
			return null;
		}
		if ( $customer['customer_id'] > 0 ) {
			return $owner === $customer['customer_id'];
		}
		$account = get_userdata( $owner );
		return self::same_customer(
			array(
				'customer_id' => 0,
				'email'       => self::normalize_email( $account ? $account->user_email : '' ),
			),
			$customer
		);
	}

	/**
	 * Whether the stored spt_email is this billing email: true, false, or null
	 * when either is empty or the comparison is switched off.
	 *
	 * @param int    $player_id Player post ID.
	 * @param string $email     Normalized billing email.
	 * @return bool|null
	 */
	private function stored_email_agrees( $player_id, $email ) {
		if ( ! $this->compare_stored_email ) {
			return null;
		}
		$stored = self::normalize_email( get_post_meta( $player_id, 'spt_email', true ) );
		if ( '' === $stored || '' === $email ) {
			return null;
		}
		return $stored === $email;
	}

	/**
	 * Compare the customer with every order already linked to the record.
	 *
	 * @param int   $player_id        Player post ID.
	 * @param array $customer         customer_id and email.
	 * @param int   $exclude_order_id Order being judged.
	 * @return array{corroborated:bool,mismatched:int[]}
	 */
	private function history( $player_id, $customer, $exclude_order_id ) {
		$result = array(
			'corroborated' => false,
			'mismatched'   => array(),
		);
		foreach ( $this->linked_order_ids( $player_id, $exclude_order_id ) as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
			if ( ! $order ) {
				continue;
			}
			$same = self::same_customer(
				array(
					'customer_id' => (int) $order->get_user_id(),
					'email'       => self::normalize_email( $order->get_billing_email() ),
				),
				$customer
			);
			if ( true === $same ) {
				$result['corroborated'] = true;
			} elseif ( false === $same ) {
				$result['mismatched'][] = $order_id;
			}
		}
		return $result;
	}

	/**
	 * Whether two customers are the same person: true when the account or the
	 * email matches, false when something comparable differs, null when there is
	 * nothing to compare (a guest without an email, say).
	 *
	 * @param array $first  customer_id and email.
	 * @param array $second customer_id and email.
	 * @return bool|null
	 */
	private static function same_customer( $first, $second ) {
		$both_accounts = $first['customer_id'] > 0 && $second['customer_id'] > 0;
		$both_emails   = '' !== $first['email'] && '' !== $second['email'];

		if ( ( $both_accounts && $first['customer_id'] === $second['customer_id'] )
			|| ( $both_emails && $first['email'] === $second['email'] ) ) {
			return true;
		}
		if ( $both_accounts || $both_emails ) {
			return false;
		}
		return null;
	}

	/**
	 * @param mixed $email Raw email.
	 * @return string Lowercased, sanitized email, or ''.
	 */
	private static function normalize_email( $email ) {
		return strtolower( (string) sanitize_email( (string) $email ) );
	}
}
