<?php
/**
 * FreeScout bridge action player.change_email: move a customer from one email
 * address to another everywhere SPAT keeps it — the players' spt_email, the
 * WordPress login, the billing_email user meta and the billing email on their
 * WooCommerce orders.
 *
 * @package SportsPress_Player_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class SPT_FreeScout_Player_Actions {

	const CAPABILITY = 'manage_options';

	/**
	 * Hook the action into the parent's registry.
	 */
	public static function register() {
		add_filter( 'spat_freescout_actions', array( __CLASS__, 'add_actions' ) );
	}

	public static function add_actions( $actions ) {
		$actions['player.change_email'] = array(
			'capability' => self::CAPABILITY,
			'handler'    => array( __CLASS__, 'change_email_action' ),
		);
		return $actions;
	}

	/**
	 * Action player.change_email.
	 *
	 * @param array   $params     { old_email, new_email }.
	 * @param bool    $dry_run    Preview only.
	 * @param WP_User $actor      Acting WordPress user (unused).
	 * @param array   $actor_info FreeScout agent { email, name }.
	 * @return array|WP_Error
	 */
	public static function change_email_action( array $params, bool $dry_run, $actor, array $actor_info ) {
		unset( $actor );

		$old = self::email_param( $params, 'old_email' );
		$new = self::email_param( $params, 'new_email' );
		if ( ! is_email( $old ) ) {
			return self::error( 'email_invalid', 'The current email address is not valid.', 400 );
		}
		if ( ! is_email( $new ) ) {
			return self::error( 'email_invalid', 'Enter a valid new email address.', 400 );
		}
		if ( $old === $new ) {
			return self::error( 'email_invalid', 'The new email address is the same as the current one.', 400 );
		}

		$plan = self::plan( $old, $new );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( ! $dry_run ) {
			$applied = self::apply( $plan, $old, $new, self::agent_name( $actor_info ) );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
		}

		return array(
			'summary'          => sprintf( 'Change email %1$s → %2$s', $old, $new ),
			'changes'          => self::describe( $plan, $new ),
			'requires_confirm' => array(),
			'data'             => array(
				'player_ids' => array_map(
					function ( $post ) {
						return (int) $post->ID;
					},
					$plan['players']
				),
				'user_id'    => $plan['user'] ? (int) $plan['user']->ID : 0,
				'order_ids'  => array_map(
					function ( $order ) {
						return (int) $order->get_id();
					},
					$plan['orders']
				),
			),
		);
	}

	/**
	 * Everything the change will touch, resolved before any write: once the
	 * login email changes, SPAT_Player::ids_for_email( $old ) no longer finds
	 * the players linked to that login through sp_user.
	 *
	 * @return array{players: WP_Post[], user: WP_User|null, orders: WC_Order[]}|WP_Error
	 */
	private static function plan( string $old, string $new ) {
		$old_ids = SPAT_Player::ids_for_email( $old );

		$players = array();
		$linked  = array();
		foreach ( $old_ids as $post_id ) {
			$linked[] = (int) get_post_meta( $post_id, 'sp_user', true );
			$post     = get_post( $post_id );
			if ( $post && 'sp_player' === $post->post_type ) {
				$players[] = $post;
			}
		}

		$user   = get_user_by( 'email', $old );
		$user   = $user ? $user : null;
		$orders = function_exists( 'wc_get_orders' )
			? (array) wc_get_orders(
				array(
					'billing_email' => $old,
					'limit'         => -1,
					'return'        => 'objects',
				)
			)
			: array();

		if ( empty( $players ) && null === $user && empty( $orders ) ) {
			return self::error( 'nothing_to_change', sprintf( 'Nothing in WordPress uses %s.', $old ), 404 );
		}

		// The new address may already sit on this customer's own login (one
		// linked to their players); anyone else holding it is a different person.
		if ( $user ) {
			$linked[] = (int) $user->ID;
		}
		$holder = get_user_by( 'email', $new );
		if ( $holder && ! in_array( (int) $holder->ID, $linked, true ) ) {
			return self::error( 'email_in_use', sprintf( '%s already belongs to another WordPress account.', $new ), 409 );
		}
		foreach ( SPAT_Player::ids_for_email( $new ) as $post_id ) {
			// Players reached through that same allowed login are theirs too.
			if ( in_array( $post_id, $old_ids, true ) || ( $holder && (int) get_post_meta( $post_id, 'sp_user', true ) === (int) $holder->ID ) ) {
				continue;
			}
			return self::error( 'email_in_use', sprintf( '%1$s is already used by another player record (#%2$d).', $new, $post_id ), 409 );
		}

		return array(
			'players' => $players,
			'user'    => $user,
			'orders'  => $orders,
		);
	}

	/**
	 * @return string[]
	 */
	private static function describe( array $plan, string $new ): array {
		$changes = array();
		foreach ( $plan['players'] as $post ) {
			$changes[] = sprintf( 'Player #%1$d %2$s: email → %3$s', $post->ID, $post->post_title, $new );
		}
		if ( $plan['user'] ) {
			$changes[] = sprintf( 'WordPress login of %1$s (user #%2$d): email → %3$s', $plan['user']->user_login, $plan['user']->ID, $new );
			$changes[] = 'This changes the address they log in with';
		}
		$count = count( $plan['orders'] );
		if ( $count > 0 ) {
			$changes[] = sprintf( '%1$d WooCommerce %2$s: billing email → %3$s', $count, 1 === $count ? 'order' : 'orders', $new );
		}
		return $changes;
	}

	/**
	 * Write the change. The login goes first, so a refusal there leaves
	 * everything as it was; spt_email goes last, so it is the final word on
	 * every player whatever a profile_update listener wrote in between.
	 *
	 * @return true|WP_Error
	 */
	private static function apply( array $plan, string $old, string $new, string $agent ) {
		if ( $plan['user'] ) {
			add_filter( 'send_email_change_email', '__return_false', PHP_INT_MAX );
			add_filter( 'send_password_change_email', '__return_false', PHP_INT_MAX );
			try {
				$updated = wp_update_user(
					array(
						'ID'         => (int) $plan['user']->ID,
						'user_email' => $new,
					)
				);
			} finally {
				remove_filter( 'send_email_change_email', '__return_false', PHP_INT_MAX );
				remove_filter( 'send_password_change_email', '__return_false', PHP_INT_MAX );
			}
			if ( is_wp_error( $updated ) ) {
				return self::error(
					'update_failed',
					sprintf( 'The WordPress login could not be changed (%s). Nothing was changed.', $updated->get_error_message() ),
					500
				);
			}
			update_user_meta( (int) $plan['user']->ID, 'billing_email', $new );
		}

		$note = sprintf( 'Billing email changed from %1$s to %2$s from FreeScout by %3$s', $old, $new, $agent );
		foreach ( $plan['orders'] as $order ) {
			$order->set_billing_email( $new );
			$order->save();
			$order->add_order_note( $note, 0, true );
		}

		foreach ( $plan['players'] as $post ) {
			update_post_meta( (int) $post->ID, 'spt_email', $new );
		}

		return true;
	}

	private static function email_param( array $params, string $key ): string {
		return isset( $params[ $key ] ) && is_string( $params[ $key ] ) ? strtolower( trim( $params[ $key ] ) ) : '';
	}

	/**
	 * The agent's FreeScout name for the order note, or their email when the
	 * name is blank (the dispatcher always supplies the email).
	 */
	private static function agent_name( array $actor_info ): string {
		$name = sanitize_text_field( (string) ( $actor_info['name'] ?? '' ) );
		return '' !== $name ? $name : sanitize_text_field( (string) ( $actor_info['email'] ?? '' ) );
	}

	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
