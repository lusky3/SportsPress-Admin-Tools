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

		// Open the try IMMEDIATELY after the lock claim so the finally runs on
		// every exit path (including throws during meta writes) and the lock is
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
			throw $e;
		} finally {
			// Always release the lock so the next attempt (rerun / retry) is not
			// blocked by the 300s TTL. Matches the e-Transfer webhook pattern.
			if ( class_exists( 'SPAT_Lock' ) ) {
				SPAT_Lock::release( $lock_key );
			} else {
				wp_cache_delete( $lock_key, 'spr_claims' );
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

			$tags = wp_get_post_terms( $lookup_id, 'product_tag' );
			$position = 'player';

			foreach ( $tags as $tag ) {
				$matched = strtolower( $tag->name ) === 'goalie';
				if ( apply_filters( 'spr_is_goalie_tag', $matched, $tag, $product ) ) {
					$position = 'goalie';
					break;
				}
			}

			$items[] = array(
				'product_id' => $lookup_id,
				'position' => $position,
			);
		}

		return $items;
	}

	private function extract_season_from_product( $product_id ) {
		$product_title = get_the_title( $product_id );
		if ( preg_match( '/\b([WS]\d{4}(?:-\d{2})?)\b/', $product_title, $matches ) ) {
			return $matches[1];
		}

		$categories = wp_get_post_terms( $product_id, 'product_cat' );
		foreach ( $categories as $category ) {
			if ( preg_match( '/^[WS]\d{4}(-\d{2})?$/', $category->name ) ) {
				return $category->name;
			}
		}

		return null;
	}

	private function find_or_create_player( $customer_name, $season, $position, $customer_email = '', $user_id = 0 ) {
		$player_id = null;
		$action = '';

		$match = $this->find_existing_player( $customer_name, $customer_email );
		$player_id = $match['player_id'];
		$action = $match['action'];

		// Email conflict is terminal: a player with the same name exists but a
		// different stored email — likely a guest/user duplicate or two distinct
		// people sharing a name. Do NOT auto-create a parallel record; surface
		// the conflict for manual reconciliation instead.
		if ( $action === 'multiple_players_found_email_conflict' ) {
			return array(
				'player_id' => 0,
				'action' => 'multiple_players_found_email_conflict',
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
		$is_goalie = (bool) preg_match( '/goal(ie|tender|keeper)/', $position );
		foreach ( $terms as $term ) {
			$slug = strtolower( $term->slug );
			$name = strtolower( $term->name );
			$match = false !== strpos( $slug, $position ) || false !== strpos( $name, $position );
			if ( ! $match && $is_goalie ) {
				$match = (bool) preg_match( '/goal(ie|tender|keeper)/', $slug . ' ' . $name );
			}
			if ( $match ) {
				wp_set_object_terms( $player_id, array( (int) $term->term_id ), 'sp_position' );
				return;
			}
		}
	}

	private function find_existing_player( $customer_name, $customer_email ) {
		$email_meta_enabled = $this->email_meta_enabled();

		// Use exact title match via wpdb since WP_Query 'title' param is unreliable
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
			if ( $email_meta_enabled && ! empty( $customer_email ) ) {
				$stored_email = strtolower( (string) get_post_meta( $players[0]->ID, 'spt_email', true ) );
				$normalized_email = strtolower( sanitize_email( $customer_email ) );
				if ( $stored_email && $stored_email !== $normalized_email ) {
					return array(
						'player_id' => 0,
						'action' => 'multiple_players_found_email_conflict',
					);
				}
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

		return array(
			'player_id' => null,
			'action' => '',
		);
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

	private function link_user_to_player( $user_id, $player_id ) {
		update_post_meta( $player_id, 'sp_user', $user_id );
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
