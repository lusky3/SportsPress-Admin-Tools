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
 * Call a handler the way core's WP_REST_Server::dispatch() would:
 *
 * 1. fill the `default` of every arg the request lacks (get_default_params);
 * 2. has_valid_params(): every `required` arg must be present (else 400
 *    rest_missing_callback_param), then each present arg's validate_callback
 *    runs (false or a WP_Error is a 400 rest_invalid_param);
 * 3. sanitize_params(): each present arg's sanitize_callback runs; an arg with a
 *    `type` and no sanitize_callback key gets core's rest_parse_request_arg,
 *    which validates the value against the arg's schema (type, enum, pattern,
 *    minimum/maximum, minLength/maxLength, minItems/maxItems, items and object
 *    properties/required, recursively) and then sanitizes it to that type.
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

	// has_valid_params(): required first, then validate_callback.
	$missing = array();
	foreach ( $args as $key => $arg ) {
		if ( isset( $arg['required'] ) && true === $arg['required'] && null === $request->get_param( $key ) ) {
			$missing[] = $key;
		}
	}
	if ( $missing ) {
		return new WP_Error(
			'rest_missing_callback_param',
			'Missing parameter(s): ' . implode( ', ', $missing ),
			array(
				'status' => 400,
				'params' => $missing,
			)
		);
	}
	$present = array_merge( $defaults, $params );
	$invalid = array();
	foreach ( $present as $key => $value ) {
		if ( empty( $args[ $key ]['validate_callback'] ) ) {
			continue;
		}
		$ok = call_user_func( $args[ $key ]['validate_callback'], $value, $request, $key );
		if ( false === $ok || is_wp_error( $ok ) ) {
			$invalid[ $key ] = is_wp_error( $ok ) ? $ok->get_error_message() : "Invalid parameter: {$key}";
		}
	}
	if ( $invalid ) {
		return sppa_invalid_params( $invalid );
	}

	// sanitize_params().
	foreach ( $present as $key => $value ) {
		if ( ! isset( $args[ $key ] ) ) {
			continue;
		}
		$arg = $args[ $key ];
		if ( ! array_key_exists( 'sanitize_callback', $arg ) && ! empty( $arg['type'] ) ) {
			$arg['sanitize_callback'] = 'sppa_rest_parse_request_arg';
		}
		if ( empty( $arg['sanitize_callback'] ) ) {
			continue;
		}
		$clean = 'sppa_rest_parse_request_arg' === $arg['sanitize_callback']
			? sppa_rest_parse_request_arg( $value, $arg, $key )
			: call_user_func( $arg['sanitize_callback'], $value, $request, $key );
		if ( is_wp_error( $clean ) ) {
			$invalid[ $key ] = $clean->get_error_message();
			continue;
		}
		$request->params[ $key ] = $clean;
	}
	if ( $invalid ) {
		return sppa_invalid_params( $invalid );
	}
	return call_user_func( array( 'SPPA_REST', $handler ), $request );
}

function sppa_invalid_params( array $invalid ) {
	return new WP_Error(
		'rest_invalid_param',
		'Invalid parameter(s): ' . implode( ', ', array_keys( $invalid ) ),
		array(
			'status' => 400,
			'params' => $invalid,
		)
	);
}

/** Core's rest_parse_request_arg(): validate against the arg's schema, then sanitize to it. */
function sppa_rest_parse_request_arg( $value, array $schema, $param ) {
	$valid = sppa_rest_validate_value( $value, $schema, $param );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	return sppa_rest_sanitize_value( $value, $schema );
}

function sppa_rest_is_integer( $value ) {
	return is_numeric( $value ) && round( (float) $value ) === (float) $value;
}

function sppa_rest_is_boolean( $value ) {
	if ( is_bool( $value ) ) {
		return true;
	}
	if ( is_string( $value ) ) {
		return in_array( strtolower( $value ), array( 'true', 'false', '1', '0', '' ), true );
	}
	return is_int( $value ) && in_array( $value, array( 0, 1 ), true );
}

function sppa_rest_to_list( $value ) {
	if ( is_string( $value ) ) {
		return '' === $value ? array() : preg_split( '/[\s,]+/', $value );
	}
	return $value;
}

/** Core's rest_validate_value_from_schema(), for the keywords these routes use. */
function sppa_rest_validate_value( $value, array $schema, $param ) {
	$fail = function ( $why ) use ( $param ) {
		return new WP_Error( 'rest_invalid_param', "{$param} {$why}", array( 'param' => $param ) );
	};
	$type = $schema['type'] ?? null;
	if ( is_array( $type ) ) {
		foreach ( $type as $one ) {
			if ( ! is_wp_error( sppa_rest_validate_value( $value, array( 'type' => $one ) + $schema, $param ) ) ) {
				return true;
			}
		}
		return $fail( 'is not of type ' . implode( ',', $type ) . '.' );
	}
	switch ( $type ) {
		case 'null':
			if ( null !== $value ) {
				return $fail( 'is not of type null.' );
			}
			break;
		case 'boolean':
			if ( ! sppa_rest_is_boolean( $value ) ) {
				return $fail( 'is not of type boolean.' );
			}
			break;
		case 'integer':
			if ( ! sppa_rest_is_integer( $value ) ) {
				return $fail( 'is not of type integer.' );
			}
			break;
		case 'number':
			if ( ! is_numeric( $value ) ) {
				return $fail( 'is not of type number.' );
			}
			break;
		case 'string':
			if ( ! is_string( $value ) ) {
				return $fail( 'is not of type string.' );
			}
			break;
		case 'array':
			$value = sppa_rest_to_list( $value );
			if ( ! is_array( $value ) || ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
				return $fail( 'is not of type array.' );
			}
			if ( isset( $schema['minItems'] ) && count( $value ) < $schema['minItems'] ) {
				return $fail( "must contain at least {$schema['minItems']} items." );
			}
			if ( isset( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] ) {
				return $fail( "must contain at most {$schema['maxItems']} items." );
			}
			foreach ( isset( $schema['items'] ) ? $value : array() as $i => $item ) {
				$ok = sppa_rest_validate_value( $item, $schema['items'], "{$param}[{$i}]" );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			break;
		case 'object':
			if ( ! is_array( $value ) && ! is_object( $value ) && '' !== $value ) {
				return $fail( 'is not of type object.' );
			}
			$value = (array) $value;
			foreach ( (array) ( $schema['required'] ?? array() ) as $name ) {
				if ( is_string( $name ) && ! array_key_exists( $name, $value ) ) {
					return $fail( "[{$name}] is a required property." );
				}
			}
			foreach ( $value as $name => $item ) {
				if ( isset( $schema['properties'][ $name ] ) ) {
					$ok = sppa_rest_validate_value( $item, $schema['properties'][ $name ], "{$param}[{$name}]" );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
				} elseif ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
					return $fail( "[{$name}] is not a valid property of Object." );
				}
			}
			break;
	}
	if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
		return $fail( 'is not one of ' . implode( ', ', $schema['enum'] ) . '.' );
	}
	if ( in_array( $type, array( 'integer', 'number' ), true ) ) {
		if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			return $fail( "must be greater than or equal to {$schema['minimum']}" );
		}
		if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
			return $fail( "must be less than or equal to {$schema['maximum']}" );
		}
	}
	if ( 'string' === $type ) {
		if ( isset( $schema['minLength'] ) && mb_strlen( $value ) < $schema['minLength'] ) {
			return $fail( "must be at least {$schema['minLength']} characters long." );
		}
		if ( isset( $schema['maxLength'] ) && mb_strlen( $value ) > $schema['maxLength'] ) {
			return $fail( "must be at most {$schema['maxLength']} characters long." );
		}
		if ( isset( $schema['pattern'] ) && ! preg_match( '#' . str_replace( '#', '\\#', $schema['pattern'] ) . '#u', $value ) ) {
			return $fail( "does not match pattern {$schema['pattern']}." );
		}
	}
	return true;
}

/** Core's rest_sanitize_value_from_schema(), for the same keywords. */
function sppa_rest_sanitize_value( $value, array $schema ) {
	switch ( $schema['type'] ?? null ) {
		case 'boolean':
			return is_string( $value ) ? ! in_array( strtolower( $value ), array( 'false', '0', '' ), true ) : (bool) $value;
		case 'integer':
			return (int) $value;
		case 'number':
			return (float) $value;
		case 'string':
			return (string) $value;
		case 'array':
			$value = array_values( (array) sppa_rest_to_list( $value ) );
			foreach ( isset( $schema['items'] ) ? $value : array() as $i => $item ) {
				$value[ $i ] = sppa_rest_sanitize_value( $item, $schema['items'] );
			}
			return $value;
		case 'object':
			$value = (array) $value;
			foreach ( $value as $name => $item ) {
				if ( isset( $schema['properties'][ $name ] ) ) {
					$value[ $name ] = sppa_rest_sanitize_value( $item, $schema['properties'][ $name ] );
				}
			}
			return $value;
	}
	return $value;
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
