<?php
/**
 * FreeScout bridge integration: the "waitlist" customer section and the
 * waitlist.offer / reoffer / withdraw / remove actions.
 *
 * Registered only when the league_waitlist module is enabled. The actions are
 * thin wrappers over SPLM_Waitlist_Offer::offer() and ::cancel(); they add the
 * dry-run preview, the agent-facing preconditions, and the re-offer sequence.
 *
 * @author Cody (lusky3)
 * @package SportsPress_League_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class SPLM_FreeScout_Waitlist {

	const CAPABILITY = 'manage_sportspress';

	/**
	 * Hook the provider and the actions into the parent's registries.
	 */
	public static function register() {
		add_filter( 'spat_freescout_providers', array( __CLASS__, 'add_provider' ) );
		add_filter( 'spat_freescout_actions', array( __CLASS__, 'add_actions' ) );
	}

	public static function add_provider( $providers ) {
		$providers['waitlist'] = array( __CLASS__, 'waitlist_section' );
		return $providers;
	}

	public static function add_actions( $actions ) {
		foreach ( array( 'offer', 'reoffer', 'withdraw', 'remove' ) as $name ) {
			$actions[ 'waitlist.' . $name ] = array(
				'capability' => self::CAPABILITY,
				'handler'    => array( __CLASS__, $name . '_action' ),
			);
		}
		return $actions;
	}

	/**
	 * Build sections.waitlist.
	 *
	 * @param int[]  $player_ids Resolved player ids (unused).
	 * @param string $email      Lower-cased customer email.
	 * @return array
	 */
	public static function waitlist_section( array $player_ids, string $email ): array {
		unset( $player_ids );

		$rows = SPLM_Waitlist_Database::find_for_email_with_statuses(
			$email,
			array(
				SPLM_Waitlist_Database::STATUS_QUEUED,
				SPLM_Waitlist_Database::STATUS_OFFERED,
				SPLM_Waitlist_Database::STATUS_CLAIMED,
				SPLM_Waitlist_Database::STATUS_EXPIRED,
			)
		);

		$entries = array();
		foreach ( $rows as $row ) {
			$entries[] = array(
				'id'         => (int) $row->id,
				'season'     => (string) $row->season,
				'position'   => (string) $row->position,
				'status'     => (string) $row->status,
				'created_at' => self::iso( $row->created_at ),
				'offered_at' => self::iso( $row->offered_at ),
				'expires_at' => self::iso( $row->expires_at ),
				'has_target' => (int) $row->target_product_id > 0,
			);
		}

		return array( 'entries' => $entries );
	}

	/**
	 * Action waitlist.offer.
	 *
	 * @param array   $params     { entry_id, hours?, message? }.
	 * @param bool    $dry_run    Preview only.
	 * @param WP_User $actor      Acting WordPress user (unused).
	 * @param array   $actor_info FreeScout agent (unused).
	 * @return array|WP_Error
	 */
	public static function offer_action( array $params, bool $dry_run, $actor, array $actor_info ) {
		unset( $actor, $actor_info );

		$input = self::read_offer_input( $params );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$row = self::entry_for_offer( $input['id'], false );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$result = null;
		if ( ! $dry_run ) {
			$result = SPLM_Waitlist_Offer::offer( $input['id'], $input['hours'], $input['message'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return array(
			'summary'          => sprintf( 'Offer %1$s (%2$s) to %3$s', $row->season, self::position( $row ), self::who( $row ) ),
			'changes'          => array(
				sprintf( 'Emails an offer to %s with a claim link', $row->email ),
				'Deadline: ' . self::deadline( $input['hours'], $result ),
			),
			'requires_confirm' => array(),
			'data'             => self::offer_data( $result ),
		);
	}

	/**
	 * Action waitlist.reoffer: withdraw the live offer, then offer again.
	 *
	 * @param array   $params     { entry_id, hours?, message? }.
	 * @param bool    $dry_run    Preview only.
	 * @param WP_User $actor      Acting WordPress user (unused).
	 * @param array   $actor_info FreeScout agent (unused).
	 * @return array|WP_Error
	 */
	public static function reoffer_action( array $params, bool $dry_run, $actor, array $actor_info ) {
		unset( $actor, $actor_info );

		// Everything that can be refused is refused before the old offer is
		// withdrawn, so a bad window or message never costs the player their
		// current offer.
		$input = self::read_offer_input( $params );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$row = self::entry_for_offer( $input['id'], true );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$result = null;
		if ( ! $dry_run ) {
			$result = self::withdraw_then_offer( $input );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return array(
			'summary'          => sprintf( 'Re-offer %1$s (%2$s) to %3$s', $row->season, self::position( $row ), self::who( $row ) ),
			'changes'          => array(
				'Withdraws the current offer',
				sprintf( 'Emails a new offer to %s', $row->email ),
				'New deadline: ' . self::deadline( $input['hours'], $result ),
			),
			'requires_confirm' => array(),
			'data'             => self::offer_data( $result ),
		);
	}

	/**
	 * Action waitlist.withdraw.
	 *
	 * @param array   $params     { entry_id }.
	 * @param bool    $dry_run    Preview only.
	 * @param WP_User $actor      Acting WordPress user (unused).
	 * @param array   $actor_info FreeScout agent (unused).
	 * @return array|WP_Error
	 */
	public static function withdraw_action( array $params, bool $dry_run, $actor, array $actor_info ) {
		unset( $actor, $actor_info );

		$row = self::entry_with_status( $params, SPLM_Waitlist_Database::STATUS_OFFERED, 'Only an offered entry can be withdrawn.' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$result = null;
		if ( ! $dry_run ) {
			$result = SPLM_Waitlist_Offer::cancel( (int) $row->id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return array(
			'summary'          => sprintf( 'Withdraw the offer to %s', self::who( $row ) ),
			'changes'          => array_merge(
				array(
					'The entry goes back to the queue',
					'The claim link stops working',
				),
				self::status_note( $result, SPLM_Waitlist_Database::STATUS_QUEUED )
			),
			'requires_confirm' => array(),
			'data'             => array(),
		);
	}

	/**
	 * Action waitlist.remove.
	 *
	 * @param array   $params     { entry_id }.
	 * @param bool    $dry_run    Preview only.
	 * @param WP_User $actor      Acting WordPress user (unused).
	 * @param array   $actor_info FreeScout agent (unused).
	 * @return array|WP_Error
	 */
	public static function remove_action( array $params, bool $dry_run, $actor, array $actor_info ) {
		unset( $actor, $actor_info );

		$row = self::entry_with_status( $params, SPLM_Waitlist_Database::STATUS_QUEUED, 'Only a queued entry can be removed from the waitlist.' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$result = null;
		if ( ! $dry_run ) {
			$result = SPLM_Waitlist_Offer::cancel( (int) $row->id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return array(
			'summary'          => sprintf( 'Remove %1$s from the %2$s waitlist', self::who( $row ), $row->season ),
			'changes'          => array_merge(
				array( 'Marks the entry cancelled' ),
				self::status_note( $result, SPLM_Waitlist_Database::STATUS_CANCELLED )
			),
			'requires_confirm' => array(),
			'data'             => array(),
		);
	}

	/**
	 * Cancel the live offer (offered to queued), then offer again.
	 *
	 * When the new offer fails the entry must not be left offered with a link
	 * nobody was emailed: the service unwinds a failed send itself, and if it
	 * could not, one more cancel is attempted here.
	 *
	 * @param array $input { id, hours, message }.
	 * @return array|WP_Error
	 */
	private static function withdraw_then_offer( array $input ) {
		$cancelled = SPLM_Waitlist_Offer::cancel( $input['id'] );
		if ( is_wp_error( $cancelled ) ) {
			return $cancelled;
		}

		$offered = SPLM_Waitlist_Offer::offer( $input['id'], $input['hours'], $input['message'] );
		if ( ! is_wp_error( $offered ) ) {
			return $offered;
		}

		// Retry only when this call's own offer was left stuck. After a
		// "locked" or "no lock" error an offered row may be someone else's
		// legitimate offer, which must not be withdrawn.
		$retried = false;
		if ( 'splm_waitlist_unwind_failed' === $offered->get_error_code() ) {
			$retried = true;
			SPLM_Waitlist_Offer::cancel( $input['id'] );
		}

		return new WP_Error(
			'reoffer_partial',
			sprintf(
				'The old offer was withdrawn but the new offer failed: %1$s. %2$s',
				rtrim( $offered->get_error_message(), ". \t\n" ),
				self::state_sentence( SPLM_Waitlist_Database::get( $input['id'] ), $retried )
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * Honest description of where an entry ended up after a failed re-offer.
	 *
	 * @param object|null $row     The entry as re-read, null if it is gone.
	 * @param bool        $retried Whether a second cancel was attempted.
	 * @return string
	 */
	private static function state_sentence( $row, bool $retried ): string {
		if ( ! $row ) {
			return 'The entry no longer exists.';
		}
		if ( SPLM_Waitlist_Database::STATUS_QUEUED === $row->status ) {
			return 'The entry is back in the queue.';
		}
		if ( $retried && SPLM_Waitlist_Database::STATUS_OFFERED === $row->status ) {
			return 'The entry is still marked offered; withdraw it before trying again.';
		}
		return sprintf( 'The entry is now %s.', $row->status );
	}

	/**
	 * A note line when cancel() landed the entry somewhere other than expected,
	 * i.e. it changed status between the preview check and the write.
	 *
	 * @param array|null $cancelled The cancel() result, null for a dry run.
	 * @param string     $expected  Status the action expects.
	 * @return string[] Zero or one line.
	 */
	public static function status_note( $cancelled, string $expected ): array {
		if ( ! is_array( $cancelled ) || ( $cancelled['status'] ?? $expected ) === $expected ) {
			return array();
		}
		return array( sprintf( 'Note: the entry changed status while this ran; it is now %s.', $cancelled['status'] ) );
	}

	/**
	 * Parse and validate entry_id, hours and message.
	 *
	 * @param array $params Action params.
	 * @return array|WP_Error { id:int, hours:int, message:string }
	 */
	private static function read_offer_input( array $params ) {
		$id = self::entry_id( $params );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$hours = SPLM_Waitlist_Offer::validate_hours( $params['hours'] ?? null );
		if ( is_wp_error( $hours ) ) {
			return new WP_Error(
				'bad_hours',
				sprintf( 'The claim window must be a whole number of hours between %1$d and %2$d.', SPLM_Waitlist_Offer::MIN_HOURS, SPLM_Waitlist_Offer::MAX_HOURS ),
				array( 'status' => 400 )
			);
		}

		$raw = $params['message'] ?? null;
		if ( null !== $raw && ! is_scalar( $raw ) ) {
			return new WP_Error( 'bad_message', 'The offer message must be plain text.', array( 'status' => 400 ) );
		}
		$message = SPLM_Waitlist_Offer::validate_offer_message( $raw );
		if ( is_wp_error( $message ) ) {
			return new WP_Error(
				'bad_message',
				sprintf( 'The offer message must be %d characters or fewer.', SPLM_Waitlist_Offer::MAX_OFFER_MESSAGE_LENGTH ),
				array( 'status' => 400 )
			);
		}

		return array(
			'id'      => $id,
			'hours'   => $hours,
			'message' => $message,
		);
	}

	/**
	 * @param array $params Action params.
	 * @return int|WP_Error
	 */
	private static function entry_id( array $params ) {
		$id = isset( $params['entry_id'] ) && is_numeric( $params['entry_id'] ) ? (int) $params['entry_id'] : 0;
		if ( $id <= 0 ) {
			return new WP_Error( 'bad_params', 'An entry_id is required.', array( 'status' => 400 ) );
		}
		return $id;
	}

	/**
	 * The entry the agent is acting on, or the reason it cannot be.
	 *
	 * @param int $id Entry id.
	 * @return object|WP_Error
	 */
	private static function find_entry( int $id ) {
		$row = SPLM_Waitlist_Database::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'splm_waitlist_not_found', 'Waitlist entry not found.', array( 'status' => 404 ) );
		}
		return $row;
	}

	/**
	 * Preconditions for offer (queued or expired) and re-offer (offered).
	 *
	 * The offer rules mirror SPLM_Waitlist_Offer::offerable_row(), which is
	 * private: status via can_offer(), and a registration product to send the
	 * player to. A re-offer needs the product too, so that the old offer is
	 * not withdrawn only for the new one to be refused.
	 *
	 * @param int  $id      Entry id.
	 * @param bool $reoffer True for re-offer (status offered).
	 * @return object|WP_Error
	 */
	private static function entry_for_offer( int $id, bool $reoffer ) {
		$row = self::find_entry( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( $reoffer ? SPLM_Waitlist_Database::STATUS_OFFERED !== $row->status : ! SPLM_Waitlist_Offer::can_offer( $row->status ) ) {
			return new WP_Error(
				'splm_waitlist_bad_status',
				$reoffer ? 'Only an offered entry can be re-offered.' : 'Only a queued or expired entry can be offered a spot.',
				array( 'status' => 409 )
			);
		}
		if ( (int) $row->target_product_id <= 0 ) {
			return new WP_Error(
				'splm_waitlist_no_target',
				'This entry has no registration product set. Choose one before offering the spot.',
				array( 'status' => 409 )
			);
		}
		return $row;
	}

	/**
	 * Entry that must be in exactly one status (withdraw: offered, remove: queued).
	 *
	 * @param array  $params  Action params.
	 * @param string $status  Required status.
	 * @param string $message Refusal sentence.
	 * @return object|WP_Error
	 */
	private static function entry_with_status( array $params, string $status, string $message ) {
		$id = self::entry_id( $params );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$row = self::find_entry( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( $status !== $row->status ) {
			return new WP_Error( 'splm_waitlist_bad_status', $message, array( 'status' => 409 ) );
		}
		return $row;
	}

	/**
	 * The deadline text: the stored one after a real run, projected otherwise.
	 *
	 * @param int        $hours  Claim window.
	 * @param array|null $result offer() result, null for a dry run.
	 * @return string
	 */
	private static function deadline( int $hours, $result ): string {
		$timestamp = time() + $hours * HOUR_IN_SECONDS;
		if ( is_array( $result ) && ! empty( $result['expires_at'] ) ) {
			$stored = strtotime( $result['expires_at'] . ' UTC' );
			if ( false !== $stored ) {
				$timestamp = $stored;
			}
		}
		return gmdate( 'M j, g:i A', $timestamp ) . ' UTC';
	}

	/**
	 * @param array|null $result offer() result, null for a dry run.
	 * @return array
	 */
	private static function offer_data( $result ): array {
		if ( ! is_array( $result ) ) {
			return array();
		}
		return array(
			'expires_at' => $result['expires_at'] ?? null,
			'warnings'   => $result['warnings'] ?? array(),
		);
	}

	private static function who( $row ): string {
		$name = trim( (string) $row->name );
		return '' !== $name ? $name : (string) $row->email;
	}

	private static function position( $row ): string {
		return ucfirst( (string) $row->position );
	}

	/**
	 * Stored UTC datetime to ISO 8601, or null when unset.
	 *
	 * @param string|null $value Stored 'Y-m-d H:i:s' UTC.
	 * @return string|null
	 */
	private static function iso( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
