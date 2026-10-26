<?php
/**
 * Dispatch for FreeScout action and query requests: registry lookup, actor
 * resolution, capability check, idempotency and normalised responses.
 *
 * Features register handlers through the `spat_freescout_actions` and
 * `spat_freescout_queries` filters. Signature checks happen earlier, in
 * SPAT_FreeScout_REST::guard().
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_FreeScout_Actions {

	const IDEMPOTENCY_PREFIX = 'spat_fs_idem_';
	const KEY_PATTERN        = '/^[A-Za-z0-9-]{8,64}$/';

	/** Seconds a run holds its key before a crashed holder's claim can be taken over. */
	const RUN_LOCK_TTL = 120;

	/**
	 * Run (or preview) an action as the FreeScout agent's WordPress user.
	 *
	 * @param array $body Decoded request body.
	 * @return array|WP_Error Normalised response.
	 */
	public static function dispatch( array $body ) {
		// Order matters: request shape, then the registry, then the actor and
		// their capability, and only then idempotency (dry run, replay, claim).
		$request = self::action_request( $body );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$entry = self::registered_action( $request['feature'], $request['action'] );
		if ( null === $entry ) {
			return new WP_Error( 'spat_freescout_unknown_action', 'Unknown action.', array( 'status' => 404 ) );
		}

		$user = self::authorized_actor( $request['email'], (string) $entry['capability'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$transient = self::IDEMPOTENCY_PREFIX . hash( 'sha256', $request['key'] );
		if ( $request['dry_run'] ) {
			return self::run( $entry, $request, $user, $transient );
		}

		$stored = self::stored( $transient, $request );
		if ( null !== $stored ) {
			return $stored;
		}

		return self::claim_and_run( $entry, $request, $user, $transient );
	}

	/**
	 * Read the action request fields from the body and validate them.
	 *
	 * @param array $body Decoded request body.
	 * @return array|WP_Error The fields (feature, action, params, key, email, name, dry_run) or a 400.
	 */
	private static function action_request( array $body ) {
		$actor   = isset( $body['actor'] ) && is_array( $body['actor'] ) ? $body['actor'] : array();
		$request = array(
			'feature' => self::string_field( $body, 'feature' ),
			'action'  => self::string_field( $body, 'action' ),
			'params'  => $body['params'] ?? array(),
			'key'     => self::string_field( $body, 'idempotency_key' ),
			'email'   => strtolower( trim( self::string_field( $actor, 'email' ) ) ),
			'name'    => trim( self::string_field( $actor, 'name' ) ),
			'dry_run' => ! empty( $body['dry_run'] ),
		);

		if ( '' === $request['feature'] || '' === $request['action'] || ! is_array( $request['params'] ) ) {
			return self::bad_request( 'A feature, an action and params are required.' );
		}
		if ( 1 !== preg_match( self::KEY_PATTERN, $request['key'] ) ) {
			return self::bad_request( 'A valid idempotency key is required.' );
		}
		if ( '' === $request['email'] ) {
			return self::bad_request( 'The acting agent\'s email is required.' );
		}
		return $request;
	}

	/**
	 * The registered entry for feature.action, or null when there is none or it
	 * lacks a capability or a callable handler.
	 */
	private static function registered_action( string $feature, string $action ): ?array {
		$registry = apply_filters( 'spat_freescout_actions', array() );
		$entry    = is_array( $registry ) ? ( $registry[ $feature . '.' . $action ] ?? null ) : null;
		if ( ! is_array( $entry ) || empty( $entry['capability'] ) || empty( $entry['handler'] ) || ! is_callable( $entry['handler'] ) ) {
			return null;
		}
		return $entry;
	}

	/**
	 * The WordPress user for the agent's email, if they hold the capability.
	 *
	 * @return WP_User|WP_Error
	 */
	private static function authorized_actor( string $email, string $capability ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new WP_Error( 'spat_freescout_actor_unknown', 'No WordPress account for ' . $email, array( 'status' => 403 ) );
		}
		if ( ! user_can( $user, $capability ) ) {
			return new WP_Error( 'spat_freescout_actor_forbidden', 'Your WordPress account is not allowed to do this.', array( 'status' => 403 ) );
		}
		return $user;
	}

	/**
	 * Claim the key, then run the action unless another run stored a result
	 * meanwhile. The claim is released however the run ends (result, WP_Error
	 * or throw).
	 *
	 * @return array|WP_Error
	 */
	private static function claim_and_run( array $entry, array $request, $user, string $transient ) {
		// Claim the key atomically, so two concurrent runs with one key (a
		// duplicate POST) cannot both reach the handler. SPAT_Lock uses a
		// guarded INSERT; add_option() is not atomic on WP 6.4+.
		$lock   = 'fs_idem_' . hash( 'sha256', $request['key'] );
		$handle = SPAT_Lock::acquire( $lock, self::RUN_LOCK_TTL );
		if ( false === $handle ) {
			return new WP_Error( 'spat_freescout_in_progress', 'This action is already running. Wait a moment and check the result.', array( 'status' => 409 ) );
		}
		try {
			// The other run may have finished between the caller's check and our claim.
			$stored = self::stored( $transient, $request );
			if ( null !== $stored ) {
				return $stored;
			}
			return self::run( $entry, $request, $user, $transient );
		} finally {
			SPAT_Lock::release( $lock, $handle );
		}
	}

	/**
	 * $source[ $key ] when it is a string, else ''.
	 */
	private static function string_field( array $source, string $key ): string {
		return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? $source[ $key ] : '';
	}

	/**
	 * What a key was used for: the action, its params (key order ignored) and
	 * the acting agent's email. The agent's display name does not count.
	 */
	private static function fingerprint( array $request ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					$request['feature'] . '.' . $request['action'],
					self::canonical( $request['params'] ),
					$request['email'],
				)
			)
		);
	}

	/**
	 * The value with every array's keys sorted, recursively, so two params
	 * arrays that differ only in key order encode the same.
	 *
	 * @param mixed $value Params or a nested value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		// SORT_STRING: under the default flag, keys like 1 and "01" compare
		// equal and keep their input order.
		ksort( $value, SORT_STRING );
		return array_map( array( __CLASS__, 'canonical' ), $value );
	}

	/**
	 * For a key already run: its stored response marked as a replay, or a 409
	 * when the key was used for a different request (or the entry does not say
	 * what it was used for). Null when the key has not been run.
	 *
	 * @param string $transient Idempotency transient name.
	 * @param array  $request   Validated fields from action_request().
	 * @return array|WP_Error|null
	 */
	private static function stored( string $transient, array $request ) {
		$stored = get_transient( $transient );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		if ( ! isset( $stored['fingerprint'], $stored['response'] ) || ! is_array( $stored['response'] )
			|| ! hash_equals( (string) $stored['fingerprint'], self::fingerprint( $request ) ) ) {
			return new WP_Error( 'spat_freescout_key_reused', 'This idempotency key was already used for a different request.', array( 'status' => 409 ) );
		}
		$response             = $stored['response'];
		$response['replayed'] = true;
		return $response;
	}

	/**
	 * Call the handler as the actor and normalise its answer; a real run's
	 * success is stored for replay.
	 *
	 * @param array  $entry     Registry entry (capability, handler).
	 * @param array  $request   Validated fields from action_request().
	 * @param mixed  $user      The actor's WP_User.
	 * @param string $transient Idempotency transient name.
	 * @return array|WP_Error
	 */
	private static function run( array $entry, array $request, $user, string $transient ) {
		$dry_run  = $request['dry_run'];
		$previous = get_current_user_id();
		wp_set_current_user( $user->ID );
		try {
			$result = call_user_func(
				$entry['handler'],
				$request['params'],
				$dry_run,
				$user,
				array(
					'email' => $request['email'],
					'name'  => $request['name'],
				)
			);
		} catch ( Throwable $e ) {
			SPAT_Logger::error( 'freescout', sprintf( 'Action "%s.%s" failed: %s', $request['feature'], $request['action'], $e->getMessage() ) );
			return new WP_Error( 'spat_freescout_action_failed', 'The action failed in WordPress. Check the SPAT logs.', array( 'status' => 500 ) );
		} finally {
			wp_set_current_user( $previous );
		}

		if ( is_wp_error( $result ) ) {
			return self::pass_through( $result );
		}

		$response = self::normalise( $result, $dry_run );
		if ( ! $dry_run ) {
			// Stored with what the key was used for, so a later request that
			// reuses the key for something else is refused, not answered with
			// this result.
			set_transient(
				$transient,
				array(
					'fingerprint' => self::fingerprint( $request ),
					'response'    => $response,
				),
				DAY_IN_SECONDS
			);
		}

		return $response;
	}

	/**
	 * The response contract for a handler's (non-error) answer.
	 *
	 * @param mixed $result  Handler return value.
	 * @param bool  $dry_run Whether this was a preview.
	 */
	private static function normalise( $result, bool $dry_run ): array {
		$result = is_array( $result ) ? $result : array();
		return array(
			'success'          => true,
			'dry_run'          => $dry_run,
			'replayed'         => false,
			'summary'          => (string) ( $result['summary'] ?? '' ),
			'changes'          => array_values( (array) ( $result['changes'] ?? array() ) ),
			'requires_confirm' => array_values( (array) ( $result['requires_confirm'] ?? array() ) ),
			'data'             => (array) ( $result['data'] ?? array() ),
		);
	}

	/**
	 * Run a read-only query. No actor is needed.
	 *
	 * @param array $body Decoded request body.
	 * @return array|WP_Error `{results: [...]}`.
	 */
	public static function query( array $body ) {
		$feature = self::string_field( $body, 'feature' );
		$query   = self::string_field( $body, 'query' );
		$params  = $body['params'] ?? array();
		if ( '' === $feature || '' === $query || ! is_array( $params ) ) {
			return self::bad_request( 'A feature, a query and params are required.' );
		}

		$handler = self::registered_query( $feature, $query );
		if ( ! is_callable( $handler ) ) {
			return new WP_Error( 'spat_freescout_unknown_query', 'Unknown query.', array( 'status' => 404 ) );
		}

		try {
			$results = call_user_func( $handler, $params );
		} catch ( Throwable $e ) {
			SPAT_Logger::error( 'freescout', sprintf( 'Query "%s.%s" failed: %s', $feature, $query, $e->getMessage() ) );
			return new WP_Error( 'spat_freescout_query_failed', 'The query failed in WordPress. Check the SPAT logs.', array( 'status' => 500 ) );
		}

		if ( is_wp_error( $results ) ) {
			return self::pass_through( $results );
		}

		return array( 'results' => is_array( $results ) ? $results : array() );
	}

	/**
	 * The registered handler for feature.query (null when the registry has none).
	 *
	 * @return mixed
	 */
	private static function registered_query( string $feature, string $query ) {
		$registry = apply_filters( 'spat_freescout_queries', array() );
		return is_array( $registry ) ? ( $registry[ $feature . '.' . $query ] ?? null ) : null;
	}

	/**
	 * Hand a handler's error back with its code and message and a plain status
	 * (400 when the handler gave none).
	 */
	private static function pass_through( WP_Error $error ): WP_Error {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => $status ) );
	}

	private static function bad_request( string $message ): WP_Error {
		return new WP_Error( 'spat_freescout_bad_request', $message, array( 'status' => 400 ) );
	}
}
