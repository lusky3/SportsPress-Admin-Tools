<?php
/**
 * Player Registration Core Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPR_Player_Registration {

	public function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'process_completed_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_order_reversed' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_order_reversed' ) );
		// NOTE: the map_meta_cap guard that pairs with link_user_to_player() is NOT
		// registered here. It lives in SPPR_Ownership_Caps and is hooked from the main
		// plugin file above the module gate, so it survives this class not loading.
	}

	/**
	 * Whether to store/compare the player email meta (spt_email) during registration.
	 *
	 * CROSS-PLUGIN DEPENDENCY: the email meta key (spt_email) and its enable flag
	 * originate from the sibling "Player Tools" (SPPT_) plugin. To avoid silently
	 * changing behavior on existing installs — where this gate read
	 * get_option('spt_email_meta', '1') — we now read an own-namespaced option
	 * 'spr_email_meta' but DEFAULT it to the current effective value of
	 * 'spt_email_meta' (itself defaulting to '1'). This preserves the prior
	 * behavior exactly: when Player Tools is present its setting still applies as
	 * the default, and when absent the gate stays enabled ('1') as before.
	 *
	 * @return bool True when email metadata should be written/compared.
	 */
	private function email_meta_enabled() {
		$default = get_option( 'spt_email_meta', '1' );
		return get_option( 'spr_email_meta', $default ) === '1';
	}

	public function process_completed_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip if already done; 'failed' (and any *_conflict terminal state) falls through to retry.
		$existing = $order->get_meta( '_spr_processed' );
		if ( $existing === '1' ) {
			return;
		}

		$registration_items = $this->get_registration_items( $order );
		if ( empty( $registration_items ) ) {
			return;
		}

		$raw_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$customer_name = $this->validate_and_clean_name( $raw_name );
		if ( ! $customer_name ) {
			SPPR_Database::log_registration_activity( $order_id, $raw_name, 0, '', '', 'name_rejected' );
			return;
		}

		// Atomic claim: SPAT_Lock::acquire() returns false if the key is already held,
		// so only one worker wins the claim. SPAT_Lock is cross-process safe via an
		// atomic DB INSERT (it does not depend on an external object cache). The
		// wp_cache_add() branch below is only the in-request fallback used when the
		// SPAT_Lock class is unavailable.
		$lock_key = 'spr_processing_' . $order_id;
		if ( class_exists( 'SPAT_Lock' ) ) {
			$claimed = SPAT_Lock::acquire( $lock_key, 300 );
		} else {
			$claimed = wp_cache_add( $lock_key, 1, 'spr_claims', 300 );
		}
		if ( ! $claimed ) {
			return;
		}

		// LOW (registration): the lock above is keyed per ORDER, so it does nothing
		// about two DIFFERENT orders for the same person completing concurrently —
		// both run find_existing_player(), both see no match, and both create an
		// sp_player with the same title. Take a second, name-scoped lock so the
		// find-then-create sequence is serialized per customer name. Best effort:
		// after a short retry window we proceed anyway rather than dropping a paid
		// registration on the floor. Lock order is always order → name, so two
		// waiters can never deadlock.
		$name_lock_key    = 'spr_player_name_' . md5( strtolower( $customer_name ) );
		$name_lock_handle = false;
		if ( class_exists( 'SPAT_Lock' ) ) {
			for ( $attempt = 0; $attempt < 5; $attempt++ ) {
				$name_lock_handle = SPAT_Lock::acquire( $name_lock_key, 60 );
				if ( false !== $name_lock_handle ) {
					break;
				}
				usleep( 100000 ); // 100ms backoff before retrying a contended lock.
			}
		}

		// Open the try IMMEDIATELY after the lock claims so the finally runs on
		// every exit path (including throws during meta writes) and the locks are
		// always released. The SPAT_Lock acquired above — not any meta marker — is
		// the authoritative single-flight guard, so a single save at the end suffices.
		try {
			$customer_email = strtolower( sanitize_email( $order->get_billing_email() ) );
			$user_id = $order->get_user_id();

			// Track resolved player(s) so the user role/link runs ONCE after the loop
			// rather than once per item, and so we know whether any item hit a terminal
			// *_conflict / requires_email state (which must NOT be marked processed).
			$resolved_player_id = 0;
			$had_conflict = false;

			foreach ( $registration_items as $item ) {
				$season = $this->extract_season_from_product( $item['product_id'] );
				if ( ! $season ) {
					SPPR_Database::log_registration_activity(
						$order_id,
						$customer_name,
						0,
						'',
						$item['position'],
						'season_not_detected'
					);
					if ( class_exists( 'SPAT_Logger' ) && method_exists( 'SPAT_Logger', 'warn' ) ) {
						$warn_context = array(
							'order_id'   => $order_id,
							'product_id' => $item['product_id'],
						);
						if ( method_exists( 'SPAT_Logger', 'is_verbose' ) && SPAT_Logger::is_verbose() ) {
							$warn_context['product_title'] = get_the_title( $item['product_id'] );
						}
						SPAT_Logger::warn( 'player_registration', 'season not detected for product', $warn_context );
					}
					continue;
				}

				$result = $this->find_or_create_player( $customer_name, $season, $item['position'], $customer_email, $user_id );

				if ( $result['player_id'] ) {
					$resolved_player_id = $result['player_id'];
					SPPR_Database::log_registration_activity( $order_id, $customer_name, $result['player_id'], $season, $item['position'], $result['action'], true );
				} elseif ( ! empty( $result['action'] ) ) {
					// No player linked but we still want a paper trail for terminal
					// outcomes like multiple_players_found_email_conflict.
					if ( $result['action'] === 'multiple_players_found_email_conflict'
						|| $result['action'] === 'multiple_players_found_name_match_requires_email' ) {
						$had_conflict = true;
					}
					SPPR_Database::log_registration_activity( $order_id, $customer_name, 0, $season, $item['position'], $result['action'] );
				}
			}

			// Run role assignment / user link ONCE for the order using the resolved
			// player_id, avoiding duplicate 'role_already_exists' rows and repeated
			// sp_user meta writes for multi-item orders.
			if ( $resolved_player_id && $user_id > 0 ) {
				if ( get_option( 'spr_auto_role', '1' ) === '1' ) {
					$this->assign_player_role( $user_id );
				}
				$this->link_user_to_player( $user_id, $resolved_player_id );
			}

			// Do NOT mark a terminal *_conflict / requires_email order as processed:
			// a paying customer would be left silently unregistered with no resolution
			// on re-run. Leaving the marker unset lets a corrected re-run resolve it.
			if ( ! $had_conflict ) {
				$order->update_meta_data( '_spr_processed', '1' );
				$order->save();
			}
		} catch ( \Throwable $e ) {
			// Surface failure for retry visibility — admin re-run action can clear flag.
			$order->update_meta_data( '_spr_processed', 'failed' );
			$order->save();
			if ( class_exists( 'SPAT_Logger' ) ) {
				$context = array( 'order_id' => $order_id );
				if ( method_exists( 'SPAT_Logger', 'is_verbose' ) && SPAT_Logger::is_verbose() ) {
					$context['exception_msg'] = $e->getMessage();
				}
				SPAT_Logger::error( 'player_registration', 'process_completed_order failed', $context );
			}
			// M37: do NOT re-throw. This runs on `woocommerce_order_status_completed`,
			// so an exception escaping here aborts do_action() for the whole hook:
			// every later listener is suppressed (customer/admin emails, webhooks,
			// stock and points integrations) and the admin's status change fatals with
			// a white screen. Registration failing must not take the order with it.
			// The recovery path is already in place above — the '_spr_processed' =>
			// 'failed' marker makes the order re-runnable from the admin re-run action
			// and the logger records the cause — so the exception has nowhere useful
			// left to go. Swallow it and let the rest of the hook run.
		} finally {
			// Always release the lock so the next attempt (rerun / retry) is not
			// blocked by the 300s TTL. Matches the e-Transfer webhook pattern.
			if ( class_exists( 'SPAT_Lock' ) ) {
				// Pass the acquire handle so a request that overran its TTL releases
				// only its OWN lock, never the next holder's live one (the "legacy
				// unconditional delete" SPAT_Lock's handle argument exists to prevent).
				SPAT_Lock::release( $lock_key, is_string( $claimed ) ? $claimed : null );
			} else {
				wp_cache_delete( $lock_key, 'spr_claims' );
			}
			// Same for the name-scoped lock, and only when this request actually
			// acquired one.
			if ( is_string( $name_lock_handle ) && class_exists( 'SPAT_Lock' ) ) {
				SPAT_Lock::release( $name_lock_key, $name_lock_handle );
			}
		}
	}

	/**
	 * Log when an order is refunded or cancelled. Does NOT delete the player —
	 * manual reconciliation is intentional so historical data is preserved.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function handle_order_reversed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Only log a reversal for orders that actually registered a player. Orders
		// that never matched a registration product (or never finished processing)
		// would otherwise spam the activity log with refund/cancel noise.
		if ( $order->get_meta( '_spr_processed' ) !== '1' ) {
			return;
		}
		$raw_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$cleaned = $this->validate_and_clean_name( $raw_name );
		// PR-7: never log the raw, unvalidated billing name. Use the validated name
		// when it passes, otherwise a neutral placeholder rather than arbitrary input.
		$customer_name = $cleaned ? $cleaned : '(unverified name)';
		SPPR_Database::log_registration_activity( $order_id, $customer_name, 0, '', '', 'registration_reversed' );
	}

	/**
	 * Registration line items on an order, with each product's season position.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WC_Order $order Completed order.
	 * @return array List of arrays with 'product_id' and 'position' keys.
	 */
	private function get_registration_items( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$lookup_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product->get_id();

			$categories = wp_get_post_terms( $lookup_id, 'product_cat' );
			$is_registration = false;
			$registration_keyword = get_option( 'spr_registration_keyword', 'registration' );

			foreach ( $categories as $category ) {
				$matched = stripos( $category->name, $registration_keyword ) !== false;
				if ( apply_filters( 'spr_is_registration_category', $matched, $category, $product ) ) {
					$is_registration = true;
					break;
				}
			}

			if ( ! $is_registration ) {
				continue;
			}

			$position = class_exists( 'SPAT_Season' )
				? SPAT_Season::position_from_product( $lookup_id, $product )
				: 'player';

			$items[] = array(
				'product_id' => $lookup_id,
				'position' => $position,
			);
		}

		return $items;
	}

	/**
	 * Season code for a registration product.
	 *
	 * Delegates to SPAT_Season so the waitlist queue in League Manager and this
	 * registration path cannot disagree about what season a product belongs to.
	 * Kept as a private method with its original name and return contract so
	 * every existing call site is unchanged.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $product_id Product post ID.
	 * @return string|null Season code, or null.
	 */
	private function extract_season_from_product( $product_id ) {
		if ( ! class_exists( 'SPAT_Season' ) ) {
			// The parent plugin gates this class's loading, so this is
			// unreachable in practice — but returning null rather than
			// fataling keeps a paid order recoverable if it ever happens.
			return null;
		}
		return SPAT_Season::from_product( $product_id );
	}

	private function find_or_create_player( $customer_name, $season, $position, $customer_email = '', $user_id = 0 ) {
		$player_id = null;
		$action = '';

		$match = $this->find_existing_player( $customer_name, $customer_email );
		$player_id = $match['player_id'];
		$action = $match['action'];

		// Both conflict actions are terminal: an ambiguous match means a player with
		// this identity already exists but we cannot tell WHICH one — likely a
		// guest/user duplicate or two distinct people sharing a name. Do NOT
		// auto-create a parallel record; surface the conflict for manual
		// reconciliation instead.
		//
		// BEHAVIOR CHANGE: 'multiple_players_found_name_match_requires_email' used
		// to fall through to the create branch below with a null player_id, so an
		// order that could not be disambiguated added yet another same-named record
		// — the exact duplicate-manufacturing this lookup exists to prevent. It now
		// stops here, and process_completed_order() already refuses to mark such an
		// order processed so a human can resolve it and re-run.
		if ( $action === 'multiple_players_found_email_conflict'
			|| $action === 'multiple_players_found_name_match_requires_email' ) {
			return array(
				'player_id' => 0,
				'action' => $action,
			);
		}

		if ( $player_id && get_option( 'spr_auto_update', '1' ) !== '1' ) {
			// BEHAVIOR CHANGE (PR-6): season assignment is independent of auto-update.
			// Previously this early return skipped add_season_to_player() for existing
			// players whenever auto-update was off, even if auto-season was on. Now an
			// existing player still gets the season term when spr_auto_season is on.
			// wp_set_object_terms() with append=true is idempotent, so re-runs are safe.
			if ( get_option( 'spr_auto_season', '1' ) === '1' ) {
				$this->add_season_to_player( $player_id, $season );
			}
			return array(
				'player_id' => $player_id,
				'action' => $action,
			);
		}

		// Auto-update is on (or we are about to create) — safe to persist email metadata.
		if ( $player_id && $this->email_meta_enabled() && ! empty( $customer_email ) ) {
			update_post_meta( $player_id, 'spt_email', strtolower( sanitize_email( $customer_email ) ) );
		}

		// Create new player
		if ( ! $player_id && get_option( 'spr_auto_create', '1' ) === '1' ) {
			$result = $this->create_new_player( $customer_name, $customer_email, $user_id );
			$player_id = $result['player_id'];
			$action = $result['action'];

			// Fire notification for newly created player
			if ( $player_id && $action === 'player_created' ) {
				// Apply the detected position (e.g. goalie) to the new player.
				// Only on create so a re-run can't clobber an admin-set position.
				$this->assign_position( $player_id, $position );

				$team_names = wp_get_object_terms( $player_id, 'sp_team', array( 'fields' => 'names' ) );
				$team = ! empty( $team_names ) ? implode( ', ', $team_names ) : '';
				do_action( 'spat_player_registered', $customer_name, $team, $season );
			}
		}

		if ( $player_id && get_option( 'spr_auto_season', '1' ) === '1' ) {
			$this->add_season_to_player( $player_id, $season );
		}

		return array(
			'player_id' => $player_id,
			'action' => $action,
		);
	}

	/**
	 * Apply a detected position (e.g. "goalie") to a player's sp_position term.
	 *
	 * Matches an existing sp_position term by slug or name (case-insensitive),
	 * with a goalie synonym fallback, and sets it (replacing any prior position).
	 * No-op for an empty position or when no matching term exists.
	 *
	 * LOW (registration): matching used to be a bare substring test
	 * (strpos($slug, $position)), so the default position "player" matched ANY
	 * term whose slug or name merely contained it — "Utility Player", "Rostered
	 * Player", "Player (Sub)" — and whichever of those get_terms() returned first
	 * (alphabetical) silently won. Matching is now exact on slug or name, with
	 * SportsPress's menu-order slug prefix ("2-player") tolerated and the goalie
	 * synonym set kept as an explicit fallback. When nothing matches exactly the
	 * player is left without a position rather than given an arbitrary one.
	 *
	 * @param int    $player_id Player post ID.
	 * @param string $position  Detected position keyword.
	 * @return void
	 */
	private function assign_position( $player_id, $position ) {
		$position = strtolower( trim( (string) $position ) );
		if ( '' === $position ) {
			return;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'sp_position',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}
		$is_goalie      = (bool) preg_match( '/goal(ie|tender|keeper)/', $position );
		$goalie_synonym = null;

		foreach ( $terms as $term ) {
			$slug = strtolower( (string) $term->slug );
			$name = strtolower( trim( (string) $term->name ) );

			// SportsPress prefixes position slugs with the menu order ("2-player"),
			// so strip a leading numeric prefix before comparing slugs.
			$slug_base = preg_replace( '/^\d+-/', '', $slug );

			if ( $name === $position || $slug === $position || $slug_base === $position ) {
				wp_set_object_terms( $player_id, array( (int) $term->term_id ), 'sp_position' );
				return;
			}

			// Remember the first goalie-labelled term in case the detected keyword is
			// a goalie synonym that doesn't match any term name exactly.
			if ( $is_goalie && null === $goalie_synonym
				&& preg_match( '/goal(ie|tender|keeper)/', $slug_base . ' ' . $name ) ) {
				$goalie_synonym = (int) $term->term_id;
			}
		}

		if ( $goalie_synonym ) {
			wp_set_object_terms( $player_id, array( $goalie_synonym ), 'sp_position' );
		}
	}

	/**
	 * Resolve the sp_player record a paying customer already has, if any.
	 *
	 * Three steps, strongest identifier first:
	 *   1. stored email (spt_email) — survives renames and roster suffixes,
	 *   2. exact post_title,
	 *   3. post_title with trailing parenthetical suffixes stripped.
	 *
	 * Step 3 exists because the exact-title step alone manufactured duplicates on
	 * live data: the roster carries suffixed titles ("Dennis Arnold (G)", "Peter
	 * Kondo (C)") that a WooCommerce billing name can never equal, so every such
	 * customer got a second, empty record.
	 *
	 * @param string $customer_name  Validated billing name.
	 * @param string $customer_email Billing email (may be empty).
	 * @return array{player_id:int|null,action:string}
	 */
	private function find_existing_player( $customer_name, $customer_email ) {
		$email_meta_enabled = $this->email_meta_enabled();
		$normalized_email   = ( $email_meta_enabled && ! empty( $customer_email ) )
			? strtolower( sanitize_email( $customer_email ) )
			: '';

		// STEP 1 — email. The customer's own email is the only identifier that is
		// stable across a title suffix, a divisional rename or a marriage, so it
		// outranks any name match. Only 44 of ~2100 players carry the meta today,
		// so this is a cheap miss for most orders and an exact hit for the rest.
		if ( '' !== $normalized_email ) {
			$by_email = $this->find_player_ids_by_email( $normalized_email );
			if ( count( $by_email ) > 1 ) {
				// Two live records claim the same person. Terminal — a human picks.
				return array(
					'player_id' => 0,
					'action' => 'multiple_players_found_email_conflict',
				);
			}
			if ( count( $by_email ) === 1 ) {
				return array(
					'player_id' => $by_email[0],
					'action' => 'player_found_by_email',
				);
			}
		}

		// STEP 2 — exact title. Use exact title match via wpdb since WP_Query
		// 'title' param is unreliable.
		global $wpdb;
		$player_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sp_player' AND post_status = 'publish' AND post_title = %s",
				$customer_name
			)
		);

		$players = array();
		foreach ( $player_ids as $pid ) {
			$post = get_post( $pid );
			if ( $post ) {
				$players[] = $post;
			}
		}

		if ( count( $players ) === 1 ) {
			// If email metadata is enabled and the stored player email differs from the
			// new customer email, surface a conflict instead of silently merging — likely
			// a guest+user duplicate or two distinct people sharing a name.
			if ( $this->stored_email_conflicts( $players[0]->ID, $normalized_email ) ) {
				return array(
					'player_id' => 0,
					'action' => 'multiple_players_found_email_conflict',
				);
			}
			// An exact hit is only conclusive when it is the ONLY live record for this
			// person. The duplicates this lookup exists to stop are already ON the live
			// roster — "Peter Kondo" (created Aug 9) now sits beside "Peter Kondo (C)" —
			// so matching the exact title alone would silently keep feeding the newer,
			// empty record and compound the split. Two live records for one name is
			// ambiguity no matter which one the title happens to equal.
			if ( count( $this->find_live_normalized_candidates( $customer_name ) ) > 1 ) {
				return array(
					'player_id' => null,
					'action' => 'multiple_players_found_name_match_requires_email',
				);
			}
			return array(
				'player_id' => $players[0]->ID,
				'action' => 'player_found_by_name',
			);
		}

		if ( count( $players ) > 1 && $email_meta_enabled && ! empty( $customer_email ) ) {
			return $this->match_player_by_email( $players, $customer_email );
		}

		if ( count( $players ) > 1 ) {
			return array(
				'player_id' => null,
				'action' => 'multiple_players_found_name_match_requires_email',
			);
		}

		// STEP 3 — suffix-tolerant title.
		return $this->find_player_by_normalized_name( $customer_name, $normalized_email );
	}

	/**
	 * Published sp_player IDs whose spt_email meta equals a normalized email.
	 *
	 * Tombstoned records (see is_tombstoned_player_title()) are dropped: a record a
	 * human retired must never absorb a new registration, even when it still holds
	 * the customer's address.
	 *
	 * @param string $normalized_email Lowercased, sanitized email.
	 * @return int[] Unique player IDs.
	 */
	private function find_player_ids_by_email( $normalized_email ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE p.post_type = 'sp_player' AND p.post_status = 'publish'
				AND m.meta_key = 'spt_email' AND m.meta_value = %s",
				$normalized_email
			)
		);

		$ids = array();
		foreach ( (array) $rows as $row ) {
			if ( $this->is_tombstoned_player_title( $row->post_title ) ) {
				continue;
			}
			// Keyed so a player carrying the meta twice counts once and cannot fake
			// an "ambiguous" conflict.
			$ids[ (int) $row->ID ] = (int) $row->ID;
		}

		return array_values( $ids );
	}

	/**
	 * Match a player whose title differs from the billing name only by a trailing
	 * parenthetical suffix — "Dennis Arnold" vs "Dennis Arnold (G)".
	 *
	 * Ambiguity among LIVE records is terminal, never a guess: "Peter Kondo" has
	 * both "Peter Kondo (C)" and the "Peter Kondo" his own registration created, and
	 * picking either silently would be worse than asking a human. Tombstones do not
	 * create that ambiguity — see find_live_normalized_candidates().
	 *
	 * @param string $customer_name    Validated billing name.
	 * @param string $normalized_email Lowercased billing email, or '' when unused.
	 * @return array{player_id:int|null,action:string}
	 */
	private function find_player_by_normalized_name( $customer_name, $normalized_email ) {
		$live = $this->find_live_normalized_candidates( $customer_name );

		if ( count( $live ) > 1 ) {
			return array(
				'player_id' => null,
				'action' => 'multiple_players_found_name_match_requires_email',
			);
		}

		if ( count( $live ) === 1 ) {
			if ( $this->stored_email_conflicts( $live[0], $normalized_email ) ) {
				return array(
					'player_id' => 0,
					'action' => 'multiple_players_found_email_conflict',
				);
			}
			return array(
				'player_id' => $live[0],
				'action' => 'player_found_by_normalized_name',
			);
		}

		// Nothing, or nothing but tombstones: a genuinely new registration.
		return array(
			'player_id' => null,
			'action' => '',
		);
	}

	/**
	 * Live (non-tombstoned) published players whose title normalizes to this name.
	 *
	 * Tombstoned records are dropped BEFORE anything is counted, so they are simply
	 * invisible to matching. That is the whole point of the marker: eleven of the
	 * "(dup)" records on the live roster have exactly one live sibling, and treating
	 * the tombstone as evidence of ambiguity would make those eleven people
	 * permanently unmatchable — a conflict on every future registration, which is
	 * the outcome the marker was created to prevent. "(dup)" means "ignore this row,
	 * use the other one", so this method does exactly that.
	 *
	 * @param string $customer_name Validated billing name.
	 * @return int[] Unique live candidate player IDs.
	 */
	private function find_live_normalized_candidates( $customer_name ) {
		global $wpdb;

		$key = $this->normalize_player_title( $customer_name );
		if ( '' === $key ) {
			return array();
		}

		// Bounded query. Normalizing all ~2100 published players in PHP on every
		// order would be wasteful, so MySQL narrows to titles STARTING with the
		// normalized name ("Peter Kondo%") and only that handful is normalized here.
		// The trade-off is deliberate: a suffix that PRECEDES the name ("(Sub) Peter
		// Kondo") is not found. No such record exists on the live roster.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'sp_player' AND post_status = 'publish' AND post_title LIKE %s",
				$wpdb->esc_like( $key ) . '%'
			)
		);

		$live = array();
		foreach ( (array) $rows as $row ) {
			if ( $this->normalize_player_title( $row->post_title ) !== $key ) {
				continue;
			}
			if ( $this->is_tombstoned_player_title( $row->post_title ) ) {
				continue;
			}
			$live[ (int) $row->ID ] = (int) $row->ID;
		}

		return array_values( $live );
	}

	/**
	 * Comparison key for an sp_player title or a billing name.
	 *
	 * Drops every parenthetical segment, collapses whitespace and lowercases, so
	 * "Michael Nagatakiya (G)" and "Michael Nagatakiya" collapse to one key. The
	 * result is a comparison key ONLY — never display it, never write it to a post.
	 *
	 * @param string $title Raw post title or billing name.
	 * @return string Normalized comparison key.
	 */
	private function normalize_player_title( $title ) {
		$title = preg_replace( '/\([^)]*\)/', ' ', (string) $title );
		$title = trim( preg_replace( '/\s+/', ' ', $title ) );
		// Accented names (José García) need the multibyte fold; strtolower() is
		// byte-wise and would leave them unequal.
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
	}

	/**
	 * Whether a player title marks a deliberately retired ("tombstoned") record.
	 *
	 * ASSUMPTION — READ THIS BEFORE CHANGING ANYTHING ELSE. Roughly 17 published
	 * sp_player records carry a "(dup)"-style suffix, e.g. "Peter Kondo (Dup / Div
	 * 3)". A human put that there to declare the record dead and to point at the
	 * sibling row that is real: it means "ignore this one, use the other one".
	 *
	 * A tombstone is therefore INVISIBLE to matching — not merely unselectable. It
	 * is removed from the candidate set before anything is counted, so it can
	 * neither be matched nor make a name look ambiguous. Treating it as ambiguity
	 * would strand the eleven people whose "(dup)" row has exactly one live sibling,
	 * failing every future registration for precisely the records the marker was
	 * added to disambiguate.
	 *
	 * This method is the ONLY place that convention is encoded — if the league ever
	 * stops using "(dup)", or starts using a different marker, change it here and
	 * nowhere else.
	 *
	 * Only the parenthetical segments are inspected, deliberately: a surname that
	 * merely contains the letters "dup" (Dupont, Dupuis, Duplessis) must not be
	 * mistaken for a tombstone.
	 *
	 * @param string $title Raw post title.
	 * @return bool True when the record is retired and must not be matched.
	 */
	private function is_tombstoned_player_title( $title ) {
		if ( ! preg_match_all( '/\(([^)]*)\)/', (string) $title, $matches ) ) {
			return false;
		}
		foreach ( $matches[1] as $segment ) {
			if ( stripos( $segment, 'dup' ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a candidate player's stored email contradicts the billing email.
	 *
	 * An empty stored email is not a contradiction — most records have none — and
	 * an empty billing email (or disabled email meta) disables the check entirely.
	 *
	 * @param int    $player_id        Candidate player post ID.
	 * @param string $normalized_email Lowercased billing email, or '' when unused.
	 * @return bool True when the two emails are both present and different.
	 */
	private function stored_email_conflicts( $player_id, $normalized_email ) {
		if ( '' === $normalized_email ) {
			return false;
		}
		$stored_email = strtolower( (string) get_post_meta( $player_id, 'spt_email', true ) );
		return ( $stored_email && $stored_email !== $normalized_email );
	}

	private function match_player_by_email( $players, $customer_email ) {
		$normalized_email = strtolower( sanitize_email( $customer_email ) );
		foreach ( $players as $player ) {
			$player_email = strtolower( (string) get_post_meta( $player->ID, 'spt_email', true ) );
			if ( $player_email === $normalized_email ) {
				return array(
					'player_id' => $player->ID,
					'action' => 'player_found_by_name_and_email',
				);
			}
		}

		return array(
			'player_id' => null,
			'action' => 'multiple_players_found_name_match_requires_email',
		);
	}

	private function create_new_player( $customer_name, $customer_email, $user_id ) {
		$player_data = array(
			'post_type' => 'sp_player',
			'post_title' => $customer_name,
			'post_status' => 'publish',
		);

		if ( $user_id > 0 ) {
			$player_data['post_author'] = $user_id;
		}

		$player_id = wp_insert_post( $player_data, true );

		if ( is_wp_error( $player_id ) ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				$context = array();
				if ( method_exists( 'SPAT_Logger', 'is_verbose' ) && SPAT_Logger::is_verbose() ) {
					$context['error'] = $player_id->get_error_message();
				}
				SPAT_Logger::error( 'player_registration', 'Failed to create player', $context );
			}
			return array(
				'player_id' => null,
				'action' => 'player_creation_failed',
			);
		}

		if ( $player_id && $this->email_meta_enabled() && ! empty( $customer_email ) ) {
			update_post_meta( $player_id, 'spt_email', strtolower( sanitize_email( $customer_email ) ) );
		}

		return array(
			'player_id' => $player_id,
			'action' => 'player_created',
		);
	}

	private function add_season_to_player( $player_id, $season ) {
		$season_term = get_term_by( 'name', $season, 'sp_season' );
		if ( ! $season_term ) {
			$result = wp_insert_term( $season, 'sp_season' );
			if ( is_wp_error( $result ) ) {
				// Handle race where another request just created the term.
				if ( $result->get_error_code() === 'term_exists' ) {
					$season_term = get_term_by( 'name', $season, 'sp_season' );
				}
				if ( ! $season_term ) {
					if ( class_exists( 'SPAT_Logger' ) ) {
						$context = array( 'season' => $season );
						if ( method_exists( 'SPAT_Logger', 'is_verbose' ) && SPAT_Logger::is_verbose() ) {
							$context['error'] = $result->get_error_message();
						}
						SPAT_Logger::error( 'player_registration', 'Failed to insert or refetch sp_season term', $context );
					}
					return;
				}
			} else {
				$season_term = get_term( $result['term_id'], 'sp_season' );
			}
		}

		if ( $season_term ) {
			$append = apply_filters( 'spr_assign_season', true, $player_id, $season_term->term_id );
			wp_set_object_terms( $player_id, array( $season_term->term_id ), 'sp_season', $append );
		}
	}

	private function assign_player_role( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		$role = get_option( 'spr_player_role', 'sp_player' );

		if ( ! $user ) {
			return;
		}
		if ( in_array( $role, $user->roles, true ) ) {
			// Surface "no-op" runs in the admin log so the 'role_already_exists'
			// label is actually populated instead of being a dead value.
			SPPR_Database::log_role_assignment( $user_id, $user->display_name, 'role_already_exists' );
			return;
		}

		if ( ! wp_roles()->is_role( $role ) ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::error( 'player_registration', 'Cannot assign non-existent role', array( 'role' => $role ) );
			}
			return;
		}

		$user->add_role( $role );
		SPPR_Database::log_role_assignment( $user_id, $user->display_name, 'role_assigned' );
	}

	/**
	 * Link a WordPress user to their player record.
	 *
	 * The sp_user meta is what SportsPress reads, but post_author is what WordPress
	 * itself treats as ownership, so both are set and the player owns their own
	 * record. create_new_player() already authors new records this way; this closes
	 * the gap for players matched to a pre-existing record.
	 *
	 * SAFETY: authorship alone would hand every sp_player role holder wp-admin edit
	 * rights over their own record, because map_meta_cap grants edit_posts /
	 * edit_published_posts on a post you authored and the role holds both.
	 * SPPR_Ownership_Caps::filter_owner_player_caps() revokes that again unless the
	 * site opts in via spr_owner_can_edit. That guard is hooked from the main plugin
	 * file, above the module gate, so it holds even when this class never loads.
	 *
	 * @param int $user_id   WordPress user ID.
	 * @param int $player_id Player post ID.
	 * @return void
	 */
	private function link_user_to_player( $user_id, $player_id ) {
		update_post_meta( $player_id, 'sp_user', $user_id );

		$player = get_post( $player_id );
		if ( ! $player || (int) $player->post_author === (int) $user_id ) {
			return;
		}

		// wp_update_post(), never a raw UPDATE: authorship changes have to run the
		// normal save path so caches, revisions and post-save listeners all see it.
		$result = wp_update_post(
			array(
				'ID' => (int) $player_id,
				'post_author' => (int) $user_id,
			),
			true
		);

		if ( ( is_wp_error( $result ) || ! $result ) && class_exists( 'SPAT_Logger' ) ) {
			$context = array(
				'player_id' => (int) $player_id,
				'user_id' => (int) $user_id,
			);
			if ( is_wp_error( $result ) && method_exists( 'SPAT_Logger', 'is_verbose' ) && SPAT_Logger::is_verbose() ) {
				$context['error'] = $result->get_error_message();
			}
			SPAT_Logger::error( 'player_registration', 'Failed to set player post_author', $context );
		}
	}

	private function validate_and_clean_name( $name ) {
		// Normalize curly quotes to plain apostrophe so names like O’Brien pass validation.
		$name = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98" ), "'", $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );

		// Allow letters (including accented/unicode), spaces, hyphens, apostrophes, periods, commas, parentheses
		if ( ! preg_match( '/^[\p{L}\s\-\'.,()]+$/u', $name ) || mb_strlen( $name ) < 2 || mb_strlen( $name ) > 100 ) {
			return false;
		}

		return $name;
	}
}
