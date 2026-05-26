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

	public function process_completed_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip if already done or actively being processed; 'failed' falls through to retry.
		$existing = $order->get_meta( '_spr_processed' );
		if ( in_array( $existing, array( '1', 'processing' ), true ) ) {
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
		// so only one worker (within a process / across processes with an external object
		// cache) wins the claim. External object cache is required for full cross-process safety.
		$lock_key = 'spr_processing_' . $order_id;
		if ( class_exists( 'SPAT_Lock' ) ) {
			$claimed = SPAT_Lock::acquire( $lock_key, 300 );
		} else {
			$claimed = wp_cache_add( $lock_key, 1, 'spr_claims', 300 );
		}
		if ( ! $claimed ) {
			return;
		}

		// Persist the in-progress marker so reloads / admin re-runs see it.
		$order->update_meta_data( '_spr_processed', 'processing' );
		$order->save();

		try {
			$customer_email = strtolower( sanitize_email( $order->get_billing_email() ) );
			$user_id = $order->get_user_id();

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
					if ( $user_id > 0 ) {
						if ( get_option( 'spr_auto_role', '1' ) === '1' ) {
							$this->assign_player_role( $user_id );
						}
						$this->link_user_to_player( $user_id, $result['player_id'] );
					}
					SPPR_Database::log_registration_activity( $order_id, $customer_name, $result['player_id'], $season, $item['position'], $result['action'], true );
				}
			}

			$order->update_meta_data( '_spr_processed', '1' );
			$order->save();
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
		$customer_name = '';
		if ( $order ) {
			$raw_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$cleaned = $this->validate_and_clean_name( $raw_name );
			$customer_name = $cleaned ? $cleaned : $raw_name;
		}
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

		if ( $player_id && get_option( 'spr_auto_update', '1' ) !== '1' ) {
			return array(
				'player_id' => $player_id,
				'action' => $action,
			);
		}

		// Auto-update is on (or we are about to create) — safe to persist email metadata.
		if ( $player_id && get_option( 'spt_email_meta', '1' ) === '1' && ! empty( $customer_email ) ) {
			update_post_meta( $player_id, 'spt_email', strtolower( sanitize_email( $customer_email ) ) );
		}

		// Create new player
		if ( ! $player_id && get_option( 'spr_auto_create', '1' ) === '1' ) {
			$result = $this->create_new_player( $customer_name, $customer_email, $user_id );
			$player_id = $result['player_id'];
			$action = $result['action'];

			// Fire notification for newly created player
			if ( $player_id && $action === 'player_created' ) {
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

	private function find_existing_player( $customer_name, $customer_email ) {
		$email_meta_enabled = get_option( 'spt_email_meta', '1' ) === '1';

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

		if ( $player_id && get_option( 'spt_email_meta', '1' ) === '1' && ! empty( $customer_email ) ) {
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

		if ( ! $user || in_array( $role, $user->roles ) ) {
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
