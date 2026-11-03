<?php
/**
 * REST test helpers shared by the Player App REST suites: a register_rest_route
 * recorder, a WP_REST_Request that mirrors core, and sppa_call(), which runs a
 * handler the way core would (route-arg defaults, validate and sanitize).
 *
 * Usage: require after bootstrap.php and the plugin classes.
 */

/* ------------------------------------------------------------------------
 * Stubs
 * --------------------------------------------------------------------- */

$GLOBALS['sppa_routes'] = array();
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['sppa_routes'][] = array( $namespace, $route, $args );
}

/**
 * Mirrors core for the parts that bit us: get_param() is null for an absent
 * key (isset semantics), has_param() is true for any present key including a
 * route arg's `default`, which core fills in for absent args before the handler
 * runs (see sppa_call()).
 */
class WP_REST_Request implements ArrayAccess {
	public $params   = array();
	public $defaults = array();

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_param( $key ) {
		if ( isset( $this->params[ $key ] ) ) {
			return $this->params[ $key ];
		}
		return $this->defaults[ $key ] ?? null;
	}

	public function has_param( $key ) {
		return array_key_exists( $key, $this->params ) || array_key_exists( $key, $this->defaults );
	}

	public function set_default_params( array $defaults ) {
		$this->defaults = $defaults;
	}

	#[ReturnTypeWillChange]
	public function offsetGet( $key ) {
		return $this->params[ $key ] ?? null;
	}

	#[ReturnTypeWillChange]
	public function offsetExists( $key ) {
		return isset( $this->params[ $key ] );
	}

	#[ReturnTypeWillChange]
	public function offsetSet( $key, $value ) {
		$this->params[ $key ] = $value;
	}

	#[ReturnTypeWillChange]
	public function offsetUnset( $key ) {
		unset( $this->params[ $key ] );
	}
}

function sppa_req( array $params = array() ) {
	return new WP_REST_Request( $params );
}

/**
 * Call a handler the way core would: find its registered route, fill the
 * `default` of every arg the request lacks, run validate_callback (a false is a
 * 400 rest_invalid_param) and sanitize_callback on the args that are present,
 * then call the handler.
 */
function sppa_call( $handler, array $params = array() ) {
	if ( empty( $GLOBALS['sppa_routes'] ) ) {
		SPPA_REST::register_routes();
	}
	$args = array();
	foreach ( $GLOBALS['sppa_routes'] as $route ) {
		if ( is_array( $route[2]['callback'] ) && $handler === $route[2]['callback'][1] ) {
			$args = $route[2]['args'];
			break;
		}
	}
	$request  = new WP_REST_Request( $params );
	$defaults = array();
	foreach ( $args as $key => $arg ) {
		if ( isset( $arg['default'] ) ) {
			$defaults[ $key ] = $arg['default'];
		}
	}
	$request->set_default_params( $defaults );
	foreach ( $params as $key => $value ) {
		if ( isset( $args[ $key ]['validate_callback'] ) && ! call_user_func( $args[ $key ]['validate_callback'], $value, $request, $key ) ) {
			return new WP_Error( 'rest_invalid_param', "Invalid parameter(s): {$key}", array( 'status' => 400 ) );
		}
		if ( isset( $args[ $key ]['sanitize_callback'] ) ) {
			$request->params[ $key ] = call_user_func( $args[ $key ]['sanitize_callback'], $value, $request, $key );
		}
	}
	return call_user_func( array( 'SPPA_REST', $handler ), $request );
}

/** Log a user in (organizer when $organizer) and start the request from scratch. */
function sppa_login( $user_id, $organizer = false ) {
	sppa_login_caps( $user_id, $organizer ? array( 'manage_sportspress' ) : array() );
}

/** Log a user in with exactly these capabilities (a timekeeper: sppa_keep_time). */
function sppa_login_caps( $user_id, array $caps ) {
	$GLOBALS['sppa_user_id']   = $user_id;
	$GLOBALS['sppa_user_caps'] = $caps;
	SPPA_REST::reset_cache();
	SPPA_Roster_Reader::flush_cache();
}

function sppa_data( $response ) {
	return $response->get_data();
}

function sppa_err( $response, $code, $status ) {
	return is_wp_error( $response ) && $code === $response->get_error_code() && $status === ( $response->get_error_data()['status'] ?? 0 );
}
