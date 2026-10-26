<?php
/**
 * Signed events the FreeScout bridge receives when something happens that a
 * helpdesk agent would want to see on the customer's ticket: an unmatched
 * e-Transfer, a registration that needs attention, a waitlist change.
 *
 * Hooks fired by the child plugins are turned into outbox rows; WP-Cron sends
 * them. Nothing here ever sends inline, so a slow or dead FreeScout can never
 * delay a checkout, a webhook or a cron job.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_FreeScout_Events {

	const SEND_HOOK  = 'spat_freescout_send';
	const SWEEP_HOOK = 'spat_freescout_events_sweep';

	/** Option holding '1' once the outbox table is known to exist (cheap lazy check). */
	const TABLE_READY_OPTION = 'spat_freescout_outbox_ready';

	const STATUS_PENDING = 'pending';
	const STATUS_SENDING = 'sending';
	const STATUS_SENT    = 'sent';
	const STATUS_FAILED  = 'failed';

	const MAX_ATTEMPTS = 5;

	/** Seconds to wait after the Nth failed attempt (index N - 1). */
	const BACKOFF = array( 60, 300, 1800, 7200, 43200 );

	const TIMEOUT = 10;

	/** A claimed ('sending') row untouched this long belonged to a crashed worker. */
	const STALE_SENDING_SECONDS = 600;

	const PURGE_SENT_DAYS   = 30;
	const PURGE_FAILED_DAYS = 90;

	/** Waitlist transition => event type. Withdrawn and removed are deliberately absent. */
	const WAITLIST_EVENTS = array(
		'offer_dispatched' => 'waitlist.offered',
		'offer_expired'    => 'waitlist.expired',
		'offer_claimed'    => 'waitlist.claimed',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'spat_freescout_outbox';
	}

	/**
	 * Register the cron callbacks (always: queued rows must still be sent after
	 * an admin edits settings) and, when configured, the hook listeners.
	 */
	public function register(): void {
		add_action( self::SEND_HOOK, array( $this, 'send' ), 10, 1 );
		add_action( self::SWEEP_HOOK, array( $this, 'sweep' ) );

		if ( ! self::is_active() ) {
			if ( wp_next_scheduled( self::SWEEP_HOOK ) ) {
				wp_clear_scheduled_hook( self::SWEEP_HOOK );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}

		add_action( 'spat_payment_unmatched', array( $this, 'on_payment_unmatched' ), 10, 4 );
		add_action( 'spat_payment_matched', array( $this, 'on_payment_matched' ), 10, 4 );
		add_action( 'spr_registration_outcome', array( $this, 'on_registration_outcome' ), 10, 3 );
		add_action( 'spr_registration_reversed', array( $this, 'on_registration_reversed' ), 10, 1 );
		add_action( 'splm_waitlist_transition', array( $this, 'on_waitlist_transition' ), 10, 3 );
	}

	/**
	 * Events are on, and both the endpoint URL and a usable secret are set.
	 */
	public static function is_active(): bool {
		return '1' === (string) get_option( SPAT_FreeScout_REST::EVENTS_ENABLED_OPTION, '0' )
			&& '' !== self::url()
			&& '' !== self::secret();
	}

	private static function url(): string {
		$url = trim( (string) get_option( SPAT_FreeScout_REST::EVENTS_URL_OPTION, '' ) );
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	private static function secret(): string {
		$secret = (string) get_option( SPAT_FreeScout_REST::SECRET_OPTION, '' );
		return strlen( $secret ) >= SPAT_FreeScout_REST::MIN_SECRET_LENGTH ? $secret : '';
	}

	// -----------------------------------------------------------------------
	// Listeners. Each one is best-effort: a failure here must never reach the
	// request that fired the hook.
	// -----------------------------------------------------------------------

	/**
	 * @param string $sender_name Sender.
	 * @param mixed  $amount      Amount.
	 * @param string $reference   Reference number.
	 * @param mixed  $context     {log_id, from_email, reason}; absent on the legacy 3-argument form, which is ignored.
	 */
	public function on_payment_unmatched( $sender_name, $amount, $reference, $context = null ): void {
		if ( ! is_array( $context ) ) {
			return;
		}
		$this->safely(
			function () use ( $sender_name, $amount, $reference, $context ) {
				$this->enqueue(
					'etransfer.unmatched',
					(string) ( $context['from_email'] ?? '' ),
					array(
						'log_id'    => (int) ( $context['log_id'] ?? 0 ),
						'amount'    => self::money( $amount ),
						'from_name' => (string) $sender_name,
						'reference' => (string) $reference,
						'reason'    => (string) ( $context['reason'] ?? '' ),
					)
				);
			}
		);
	}

	/**
	 * @param string $sender_name Sender.
	 * @param mixed  $amount      Amount.
	 * @param int    $order_id    Order the payment completed.
	 * @param mixed  $context     {log_id, from_email, reference, manual}.
	 */
	public function on_payment_matched( $sender_name, $amount, $order_id, $context = array() ): void {
		$context = is_array( $context ) ? $context : array();
		$this->safely(
			function () use ( $amount, $order_id, $context ) {
				$this->enqueue(
					'etransfer.matched',
					(string) ( $context['from_email'] ?? '' ),
					array(
						'log_id'    => (int) ( $context['log_id'] ?? 0 ),
						'order_id'  => (int) $order_id,
						'amount'    => self::money( $amount ),
						'reference' => (string) ( $context['reference'] ?? '' ),
						'manual'    => ! empty( $context['manual'] ),
					)
				);
			}
		);
	}

	/**
	 * @param int    $order_id Order.
	 * @param string $outcome  The registration log action.
	 * @param mixed  $context  Unused; carries no email.
	 */
	public function on_registration_outcome( $order_id, $outcome, $context = array() ): void {
		$this->safely(
			function () use ( $order_id, $outcome ) {
				$this->enqueue(
					'registration.incomplete',
					self::order_email( (int) $order_id ),
					array(
						'order_id' => (int) $order_id,
						'outcome'  => (string) $outcome,
					)
				);
			}
		);
	}

	/**
	 * @param int $order_id Order whose registration was reversed.
	 */
	public function on_registration_reversed( $order_id ): void {
		$this->safely(
			function () use ( $order_id ) {
				$this->enqueue(
					'registration.reversed',
					self::order_email( (int) $order_id ),
					array( 'order_id' => (int) $order_id )
				);
			}
		);
	}

	/**
	 * @param object $row     Waitlist row as it was BEFORE the write; its status is not trusted.
	 * @param string $event   Waitlist event constant value.
	 * @param mixed  $context Event extras: expires_at (offer), order_id (claim).
	 */
	public function on_waitlist_transition( $row, $event, $context = array() ): void {
		if ( ! is_object( $row ) || ! isset( self::WAITLIST_EVENTS[ $event ] ) ) {
			return;
		}
		$context = is_array( $context ) ? $context : array();
		$type    = self::WAITLIST_EVENTS[ $event ];

		$data = array(
			'entry_id' => (int) ( $row->id ?? 0 ),
			'season'   => (string) ( $row->season ?? '' ),
			'position' => (string) ( $row->position ?? '' ),
		);
		if ( 'waitlist.offered' === $type ) {
			$data['expires_at'] = (string) ( $context['expires_at'] ?? '' );
		} elseif ( 'waitlist.claimed' === $type ) {
			$data['order_id'] = (int) ( $context['order_id'] ?? 0 );
		}

		$this->safely(
			function () use ( $type, $row, $data ) {
				$this->enqueue( $type, (string) ( $row->email ?? '' ), $data );
			}
		);
	}

	private function safely( callable $fn ): void {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			self::log_error( 'Could not queue a FreeScout event: ' . $e->getMessage() );
		}
	}

	private static function money( $amount ): string {
		return number_format( (float) $amount, 2, '.', '' );
	}

	private static function order_email( int $order_id ): string {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}
		$order = wc_get_order( $order_id );
		return ( $order && method_exists( $order, 'get_billing_email' ) ) ? (string) $order->get_billing_email() : '';
	}

	// -----------------------------------------------------------------------
	// Outbox
	// -----------------------------------------------------------------------

	/**
	 * Queue an event for the cron sender. Never sends inline.
	 *
	 * @param string $type  Event type, e.g. etransfer.unmatched.
	 * @param string $email Customer email; may be empty.
	 * @param array  $data  Event data.
	 * @return string The event id, or '' when it could not be queued.
	 */
	public function enqueue( string $type, string $email, array $data ): string {
		global $wpdb;

		if ( ! self::ensure_table() ) {
			self::log_error( 'FreeScout outbox table is missing and could not be created; event dropped: ' . $type );
			return '';
		}

		$event_id = wp_generate_uuid4();
		$email    = substr( trim( (string) sanitize_email( $email ) ), 0, 191 );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$payload  = wp_json_encode(
			array(
				'id'          => $event_id,
				'type'        => $type,
				'email'       => $email,
				'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'data'        => (object) $data,
			)
		);

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'event_id'        => $event_id,
				'type'            => $type,
				'email'           => $email,
				'payload'         => $payload,
				'status'          => self::STATUS_PENDING,
				'attempts'        => 0,
				'next_attempt_at' => $now,
				'last_error'      => '',
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			self::log_error( 'Could not insert a FreeScout event into the outbox: ' . $type );
			return '';
		}

		wp_schedule_single_event( time(), self::SEND_HOOK, array( $event_id ) );
		spawn_cron();

		return $event_id;
	}

	/**
	 * Cron callback: deliver one event.
	 *
	 * @param string $event_id Event id.
	 */
	public function send( $event_id ): void {
		global $wpdb;

		$event_id = (string) $event_id;
		$url      = self::url();
		$secret   = self::secret();
		if ( '' === $event_id || '' === $url || '' === $secret || ! self::ensure_table() ) {
			return; // Left pending; the sweep picks it up once configured.
		}

		$table = self::table();

		// Atomic claim: of two overlapping runs, only one flips pending to
		// sending, so an event is never posted twice by the cron path.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s, updated_at = %s WHERE event_id = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
				self::STATUS_SENDING,
				gmdate( 'Y-m-d H:i:s' ),
				$event_id,
				self::STATUS_PENDING
			)
		);
		if ( 1 !== (int) $claimed ) {
			return;
		}

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE event_id = %s", $event_id ) );
			if ( ! $row ) {
				return;
			}
			$this->deliver( $row, $url, $secret );
		} catch ( \Throwable $e ) {
			// Hand the row back rather than stranding it as 'sending'.
			$wpdb->update(
				$table,
				array(
					'status'     => self::STATUS_PENDING,
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'event_id' => $event_id ),
				array( '%s', '%s' ),
				array( '%s' )
			);
			self::log_error( 'FreeScout event send aborted: ' . $e->getMessage() );
		}
	}

	private function deliver( $row, string $url, string $secret ): void {
		$body      = (string) $row->payload;
		$timestamp = (string) time();

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'     => 'application/json',
					'X-SPAT-Timestamp' => $timestamp,
					'X-SPAT-Signature' => SPAT_Signed_Request::sign( $timestamp, $body, $secret ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->retry_or_fail( $row, 'Transport error: ' . $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$this->update_row(
				(string) $row->event_id,
				array(
					'status'     => self::STATUS_SENT,
					'attempts'   => (int) $row->attempts + 1,
					'last_error' => '',
				)
			);
			return;
		}

		if ( 429 === $code || $code >= 500 ) {
			$this->retry_or_fail( $row, 'HTTP ' . $code );
			return;
		}

		$this->fail( $row, 'HTTP ' . $code, (int) $row->attempts + 1 );
	}

	private function retry_or_fail( $row, string $error ): void {
		$attempts = (int) $row->attempts + 1;
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$this->fail( $row, $error, $attempts );
			return;
		}

		$delay = self::BACKOFF[ $attempts - 1 ];
		$when  = time() + $delay;
		$this->update_row(
			(string) $row->event_id,
			array(
				'status'          => self::STATUS_PENDING,
				'attempts'        => $attempts,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $when ),
				'last_error'      => $error,
			)
		);
		wp_schedule_single_event( $when, self::SEND_HOOK, array( (string) $row->event_id ) );
	}

	private function fail( $row, string $error, int $attempts ): void {
		$this->update_row(
			(string) $row->event_id,
			array(
				'status'     => self::STATUS_FAILED,
				'attempts'   => $attempts,
				'last_error' => $error,
			)
		);
		self::log_error( sprintf( 'FreeScout event %s (%s) failed permanently: %s', $row->event_id, $row->type, $error ) );
	}

	private function update_row( string $event_id, array $fields ): void {
		global $wpdb;

		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		if ( isset( $fields['last_error'] ) ) {
			$fields['last_error'] = substr( $fields['last_error'], 0, 255 );
		}
		$formats = array();
		foreach ( $fields as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}
		$wpdb->update( self::table(), $fields, array( 'event_id' => $event_id ), $formats, array( '%s' ) );
	}

	// -----------------------------------------------------------------------
	// Sweep
	// -----------------------------------------------------------------------

	/**
	 * Daily: rescue rows cron lost, then purge old ones.
	 */
	public function sweep(): void {
		global $wpdb;

		if ( ! self::ensure_table() ) {
			return;
		}
		$table = self::table();
		$now   = time();

		// A worker that died mid-send leaves its row claimed; hand it back.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s WHERE status = %s AND updated_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
				self::STATUS_PENDING,
				self::STATUS_SENDING,
				gmdate( 'Y-m-d H:i:s', $now - self::STALE_SENDING_SECONDS )
			)
		);

		$due = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT event_id FROM `{$table}` WHERE status = %s AND next_attempt_at <= %s ORDER BY id ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
				self::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s', $now )
			)
		);
		foreach ( (array) $due as $event_id ) {
			if ( ! wp_next_scheduled( self::SEND_HOOK, array( (string) $event_id ) ) ) {
				wp_schedule_single_event( $now, self::SEND_HOOK, array( (string) $event_id ) );
			}
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE status = %s AND updated_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
				self::STATUS_SENT,
				gmdate( 'Y-m-d H:i:s', $now - self::PURGE_SENT_DAYS * DAY_IN_SECONDS )
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE status = %s AND updated_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
				self::STATUS_FAILED,
				gmdate( 'Y-m-d H:i:s', $now - self::PURGE_FAILED_DAYS * DAY_IN_SECONDS )
			)
		);
	}

	// -----------------------------------------------------------------------
	// Table + status
	// -----------------------------------------------------------------------

	/**
	 * Make sure the outbox table exists, creating it if an upgrade has not.
	 *
	 * The schema version tracks the plugin version, so an existing install is
	 * not migrated until the next release. This check lets the table appear
	 * the first time it is needed. The 'ready' option keeps it to one query
	 * per request at most, and none once it has been confirmed.
	 */
	public static function ensure_table(): bool {
		global $wpdb;

		if ( '1' === (string) get_option( self::TABLE_READY_OPTION, '' ) ) {
			return true;
		}

		$table  = self::table();
		$exists = static function () use ( $wpdb, $table ) {
			return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		};

		if ( ! $exists() ) {
			SPAT_Database::create_outbox_table();
			if ( ! $exists() ) {
				return false;
			}
		}

		update_option( self::TABLE_READY_OPTION, '1', false );
		return true;
	}

	/**
	 * Read-only counts for the settings screen.
	 *
	 * @return array{pending:int,failed:int,last_error:string}
	 */
	public static function status(): array {
		global $wpdb;

		$out = array(
			'pending'    => 0,
			'failed'     => 0,
			'last_error' => '',
		);
		if ( '1' !== (string) get_option( self::TABLE_READY_OPTION, '' ) ) {
			return $out;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM `{$table}` GROUP BY status" );
		foreach ( (array) $rows as $row ) {
			if ( self::STATUS_FAILED === $row->status ) {
				$out['failed'] = (int) $row->n;
			} elseif ( in_array( $row->status, array( self::STATUS_PENDING, self::STATUS_SENDING ), true ) ) {
				$out['pending'] += (int) $row->n;
			}
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$out['last_error'] = (string) $wpdb->get_var( "SELECT last_error FROM `{$table}` WHERE last_error <> '' ORDER BY updated_at DESC, id DESC LIMIT 1" );

		return $out;
	}

	private static function log_error( string $message ): void {
		if ( class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::error( 'freescout-events', $message );
		}
	}
}
