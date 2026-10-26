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
		$feature = isset( $body['feature'] ) && is_string( $body['feature'] ) ? $body['feature'] : '';
		$action  = isset( $body['action'] ) && is_string( $body['action'] ) ? $body['action'] : '';
		$params  = $body['params'] ?? array();
		$key     = isset( $body['idempotency_key'] ) && is_string( $body['idempotency_key'] ) ? $body['idempotency_key'] : '';
		$actor   = isset( $body['actor'] ) && is_array( $body['actor'] ) ? $body['actor'] : array();
		$email   = isset( $actor['email'] ) && is_string( $actor['email'] ) ? strtolower( trim( $actor['email'] ) ) : '';
		$name    = isset( $actor['name'] ) && is_string( $actor['name'] ) ? trim( $actor['name'] ) : '';
		$dry_run = ! empty( $body['dry_run'] );

		if ( '' === $feature || '' === $action || ! is_array( $params ) ) {
			return self::bad_request( 'A feature, an action and params are required.' );
		}
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			return self::bad_request( 'A valid idempotency key is required.' );
		}
		if ( '' === $email ) {
			return self::bad_request( 'The acting agent\'s email is required.' );
		}

		$registry = apply_filters( 'spat_freescout_actions', array() );
		$entry    = is_array( $registry ) ? ( $registry[ $feature . '.' . $action ] ?? null ) : null;
		if ( ! is_array( $entry ) || empty( $entry['capability'] ) || empty( $entry['handler'] ) || ! is_callable( $entry['handler'] ) ) {
			return new WP_Error( 'spat_freescout_unknown_action', 'Unknown action.', array( 'status' => 404 ) );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new WP_Error( 'spat_freescout_actor_unknown', 'No WordPress account for ' . $email, array( 'status' => 403 ) );
		}
		if ( ! user_can( $user, (string) $entry['capability'] ) ) {
			return new WP_Error( 'spat_freescout_actor_forbidden', 'Your WordPress account is not allowed to do this.', array( 'status' => 403 ) );
		}

		$transient = self::IDEMPOTENCY_PREFIX . md5( $key );
		if ( $dry_run ) {
			return self::run( $entry, $params, $dry_run, $user, $email, $name, $feature, $action, $transient );
		}

		$stored = self::stored( $transient );
		if ( null !== $stored ) {
			return $stored;
		}

		// Claim the key atomically, so two concurrent runs with one key (a
		// duplicate POST) cannot both reach the handler. SPAT_Lock uses a
		// guarded INSERT; add_option() is not atomic on WP 6.4+.
		$lock   = 'fs_idem_' . md5( $key );
		$handle = SPAT_Lock::acquire( $lock, self::RUN_LOCK_TTL );
		if ( false === $handle ) {
			return new WP_Error( 'spat_freescout_in_progress', 'This action is already running. Wait a moment and check the result.', array( 'status' => 409 ) );
		}
		try {
			// The other run may have finished between the check above and our claim.
			$stored = self::stored( $transient );
			if ( null !== $stored ) {
				return $stored;
			}
			return self::run( $entry, $params, $dry_run, $user, $email, $name, $feature, $action, $transient );
		} finally {
			SPAT_Lock::release( $lock, $handle );
		}
	}

	/**
	 * The stored response for a key already run, marked as a replay, or null.
	 */
	private static function stored( string $transient ): ?array {
		$stored = get_transient( $transient );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		$stored['replayed'] = true;
		return $stored;
	}

	/**
	 * Call the handler as the actor and normalise its answer; a real run's
	 * success is stored for replay.
	 *
	 * @return array|WP_Error
	 */
	private static function run( array $entry, array $params, bool $dry_run, $user, string $email, string $name, string $feature, string $action, string $transient ) {
		$previous = get_current_user_id();
		wp_set_current_user( $user->ID );
		try {
			$result = call_user_func(
				$entry['handler'],
				$params,
				$dry_run,
				$user,
				array(
					'email' => $email,
					'name'  => $name,
				)
			);
		} catch ( Throwable $e ) {
			SPAT_Logger::error( 'freescout', sprintf( 'Action "%s.%s" failed: %s', $feature, $action, $e->getMessage() ) );
			return new WP_Error( 'spat_freescout_action_failed', 'The action failed in WordPress. Check the SPAT logs.', array( 'status' => 500 ) );
		} finally {
			wp_set_current_user( $previous );
		}

		if ( is_wp_error( $result ) ) {
			return self::pass_through( $result );
		}

		$result   = is_array( $result ) ? $result : array();
		$response = array(
			'success'          => true,
			'dry_run'          => $dry_run,
			'replayed'         => false,
			'summary'          => (string) ( $result['summary'] ?? '' ),
			'changes'          => array_values( (array) ( $result['changes'] ?? array() ) ),
			'requires_confirm' => array_values( (array) ( $result['requires_confirm'] ?? array() ) ),
			'data'             => (array) ( $result['data'] ?? array() ),
		);
		if ( ! $dry_run ) {
			set_transient( $transient, $response, DAY_IN_SECONDS );
		}

		return $response;
	}

	/**
	 * Run a read-only query. No actor is needed.
	 *
	 * @param array $body Decoded request body.
	 * @return array|WP_Error `{results: [...]}`.
	 */
	public static function query( array $body ) {
		$feature = isset( $body['feature'] ) && is_string( $body['feature'] ) ? $body['feature'] : '';
		$query   = isset( $body['query'] ) && is_string( $body['query'] ) ? $body['query'] : '';
		$params  = $body['params'] ?? array();
		if ( '' === $feature || '' === $query || ! is_array( $params ) ) {
			return self::bad_request( 'A feature, a query and params are required.' );
		}

		$registry = apply_filters( 'spat_freescout_queries', array() );
		$handler  = is_array( $registry ) ? ( $registry[ $feature . '.' . $query ] ?? null ) : null;
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
