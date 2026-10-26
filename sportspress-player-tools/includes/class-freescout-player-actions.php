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
		list( $players, $linked ) = self::old_records( $old_ids );

		$user   = get_user_by( 'email', $old );
		$user   = $user ? $user : null;
		$orders = self::orders_for( $old );

		// Refusals, in order: nothing to change, a staff login, then the new
		// address already in use.
		if ( empty( $players ) && null === $user && empty( $orders ) ) {
			return self::error( 'nothing_to_change', sprintf( 'Nothing in WordPress uses %s.', $old ), 404 );
		}
		$protected = self::staff_refusal( $user, $linked );
		if ( null !== $protected ) {
			return $protected;
		}
		$conflict = self::email_conflict( $new, $user, $old_ids, $linked );
		if ( null !== $conflict ) {
			return $conflict;
		}

		return array(
			'players' => $players,
			'user'    => $user,
			'orders'  => $orders,
		);
	}

	/**
	 * The sp_player posts among the old-email ids, and the login id each id
	 * links to through sp_user (0 when none; non-player posts count too).
	 *
	 * @param int[] $old_ids Post ids found for the old email.
	 * @return array{0: WP_Post[], 1: int[]}
	 */
	private static function old_records( array $old_ids ): array {
		$players = array();
		$linked  = array();
		foreach ( $old_ids as $post_id ) {
			$linked[] = (int) get_post_meta( $post_id, 'sp_user', true );
			$post     = get_post( $post_id );
			if ( $post && 'sp_player' === $post->post_type ) {
				$players[] = $post;
			}
		}
		return array( $players, $linked );
	}

	/**
	 * WooCommerce orders billed to the email (none without WooCommerce).
	 *
	 * @return WC_Order[]
	 */
	private static function orders_for( string $email ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		return (array) wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => -1,
				'return'        => 'objects',
			)
		);
	}

	/**
	 * The old address comes from a ticket sender, which can be spoofed, and
	 * the change notice is suppressed: never move a staff login this way,
	 * whether it is the old-email login or one linked to these players.
	 *
	 * @param WP_User|null $user   Old-email login.
	 * @param int[]        $linked Login ids linked to the old-email posts.
	 * @return WP_Error|null account_protected for the first staff login found.
	 */
	private static function staff_refusal( $user, array $linked ): ?WP_Error {
		$logins = $user ? array( $user ) : array();
		foreach ( array_unique( array_filter( $linked ) ) as $linked_id ) {
			$linked_user = get_userdata( $linked_id );
			if ( $linked_user ) {
				$logins[] = $linked_user;
			}
		}
		foreach ( $logins as $login ) {
			if ( self::is_staff( $login ) ) {
				return self::error( 'account_protected', sprintf( '%s is a staff account. Change its email in WordPress instead.', $login->user_login ), 409 );
			}
		}
		return null;
	}

	/**
	 * email_in_use when the new address already belongs to someone else: a
	 * second login, an unlinked login, or another player record.
	 *
	 * @param string       $new     New email.
	 * @param WP_User|null $user    Old-email login.
	 * @param int[]        $old_ids Post ids found for the old email.
	 * @param int[]        $linked  Login ids linked to the old-email posts.
	 */
	private static function email_conflict( string $new, $user, array $old_ids, array $linked ): ?WP_Error {
		$holder = get_user_by( 'email', $new );
		if ( $holder && $user ) {
			// Two logins: WordPress would refuse to give the old one this address.
			return self::error(
				'email_in_use',
				sprintf( '%1$s already belongs to WordPress login #%2$d. Merge the accounts in WordPress first.', $new, $holder->ID ),
				409
			);
		}
		// Without an old-email login, the new address may already sit on the
		// login linked to these players; anyone else holding it is a different person.
		if ( $holder && ! in_array( (int) $holder->ID, $linked, true ) ) {
			return self::error( 'email_in_use', sprintf( '%s already belongs to another WordPress account.', $new ), 409 );
		}
		foreach ( SPAT_Player::ids_for_email( $new ) as $post_id ) {
			if ( ! self::is_own_player( $post_id, $old_ids, $holder ) ) {
				return self::error( 'email_in_use', sprintf( '%1$s is already used by another player record (#%2$d).', $new, $post_id ), 409 );
			}
		}
		return null;
	}

	/**
	 * Whether a post found for the new email is one of the customer's own: in
	 * the old-email set, or reached through the allowed login holding the new
	 * address.
	 *
	 * @param int           $post_id Post found for the new email.
	 * @param int[]         $old_ids Post ids found for the old email.
	 * @param WP_User|false $holder  Login holding the new email, if any.
	 */
	private static function is_own_player( $post_id, array $old_ids, $holder ): bool {
		if ( in_array( $post_id, $old_ids, true ) ) {
			return true;
		}
		return $holder && (int) get_post_meta( $post_id, 'sp_user', true ) === (int) $holder->ID;
	}

	/**
	 * @return string[]
	 */
	private static function describe( array $plan, string $new ): array {
		$changes = array();
		foreach ( $plan['players'] as $post ) {
			$trashed   = 'trash' === $post->post_status ? ' (trashed)' : '';
			$changes[] = sprintf( 'Player #%1$d %2$s%3$s: email → %4$s', $post->ID, $post->post_title, $trashed, $new );
		}
		if ( $plan['user'] ) {
			$roles     = (array) $plan['user']->roles;
			$changes[] = sprintf(
				'WordPress login of %1$s (user #%2$d, %3$s): email → %4$s',
				$plan['user']->user_login,
				$plan['user']->ID,
				empty( $roles ) ? 'no role' : implode( ', ', $roles ),
				$new
			);
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
	 * everything as it was; spt_email follows it, so it is the final word on
	 * every player whatever a profile_update listener wrote in between. Orders
	 * go last. If one fails part-way, the login and players already carry the
	 * new address but some orders keep the old one. When a login was moved, a
	 * retry (old → new again) does not finish the job: no login holds the old
	 * email any more, so the login now holding the new one looks like another
	 * account and the retry fails closed with email_in_use. Fix the remaining
	 * orders' billing email in WooCommerce instead.
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

		foreach ( $plan['players'] as $post ) {
			update_post_meta( (int) $post->ID, 'spt_email', $new );
		}

		$note = sprintf( 'Billing email changed from %1$s to %2$s from FreeScout by %3$s', $old, $new, $agent );
		foreach ( $plan['orders'] as $order ) {
			$order->set_billing_email( $new );
			$order->save();
			$order->add_order_note( $note, 0, true );
		}

		return true;
	}

	/**
	 * Whether a login is staff: authors and above, shop managers, user admins,
	 * site admins, SportsPress league, team and event managers, super admins,
	 * and any custom role holding one of the caps below.
	 *
	 * Not edit_posts: SportsPress's sp_player role carries it, and player
	 * registration gives that role to every registering customer. None of the
	 * caps below is in the sp_player or sp_staff roles (SportsPress 2.7.31),
	 * customer (WooCommerce 11.0.1) or subscriber (WordPress core).
	 *
	 * Single-site assumption: user_can() answers for the current site only, so
	 * on a multisite network a login that is staff on another site of the
	 * network passes this check.
	 *
	 * @param WP_User $user Login.
	 */
	private static function is_staff( $user ): bool {
		$staff_caps = array(
			'edit_others_posts',
			'manage_woocommerce',
			'list_users',
			'manage_sportspress',
			'edit_others_sp_players',
			'edit_others_sp_events',
			'promote_users',
			'manage_options',
			'edit_users',
			'create_users',
			'delete_users',
			'activate_plugins',
			'publish_posts',
			'edit_pages',
		);
		foreach ( $staff_caps as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}
		return is_super_admin( (int) $user->ID );
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
