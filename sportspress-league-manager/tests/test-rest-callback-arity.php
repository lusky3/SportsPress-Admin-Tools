<?php
/**
 * Guard: REST arg callbacks must not be PHP-internal functions.
 *
 * WordPress calls a REST arg's sanitize_callback / validate_callback with THREE
 * arguments ( $value, $request, $param ). Userland functions ignore the extras,
 * but internal PHP functions (intval, trim, strval...) throw ArgumentCountError
 * on PHP 8, turning the request into a 500.
 *
 * Two layers: every string callback named anywhere under includes/ is checked
 * against ReflectionFunction::isInternal(), and the routes the infraction REST
 * class actually registers are loaded and each callback invoked with three args.
 */

define( 'ABSPATH', __DIR__ );

/** Mutable harness state (a class rather than $GLOBALS). */
class SPLM_Arity_Test_State {
	public $routes   = array();
	public $total    = 0;
	public $failures = 0;
}

function splm_arity_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Arity_Test_State();
	}
	return $state;
}

function check( $label, $actual, $expected ) {
	$state = splm_arity_state();
	++$state->total;
	if ( $actual === $expected ) {
		echo "PASS: $label\n";
		return;
	}
	++$state->failures;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

function add_action() {}
function __( $text ) {
	return $text;
}
function absint( $v ) {
	return abs( (int) $v );
}
function sanitize_key( $v ) {
	return strtolower( (string) $v );
}
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function sanitize_textarea_field( $v ) {
	return trim( (string) $v );
}
function rest_validate_request_arg() {
	return true;
}
function rest_sanitize_boolean( $v ) {
	return (bool) $v;
}
function register_rest_route( $ns, $route, $args ) {
	splm_arity_state()->routes[ $route ] = $args;
}

class SPLM_Discipline_Notice_REST {
	public static function gate() {
		return true;
	}
}
class SPLM_Discipline_Infraction {
	const MAX_GAMES = 20;
}

require_once dirname( __DIR__ ) . '/includes/class-discipline-infraction-rest.php';

echo "\n=== no internal PHP function named as a string callback under includes/ ===\n\n";
$files = glob( dirname( __DIR__ ) . '/includes/*.php' );
foreach ( $files as $file ) {
	preg_match_all( "/'(?:sanitize|validate)_callback'\s*=>\s*'([A-Za-z_][A-Za-z0-9_]*)'/", file_get_contents( $file ), $m );
	foreach ( array_unique( $m[1] ) as $name ) {
		$internal = function_exists( $name ) && ( new ReflectionFunction( $name ) )->isInternal();
		check( basename( $file ) . ": '$name' is not an internal function", $internal, false );
	}
}

echo "\n=== registered callbacks survive WordPress's three-argument call ===\n\n";
( new SPLM_Discipline_Infraction_REST() )->register_routes();
$request = new stdClass();
foreach ( splm_arity_state()->routes as $path => $def ) {
	foreach ( $def['args'] as $name => $arg ) {
		foreach ( array( 'sanitize_callback', 'validate_callback' ) as $key ) {
			if ( ! isset( $arg[ $key ] ) ) {
				continue;
			}
			$callback = $arg[ $key ];
			if ( is_string( $callback ) ) {
				$internal = function_exists( $callback ) && ( new ReflectionFunction( $callback ) )->isInternal();
				check( "$path:$name $key is not internal", $internal, false );
			}
			$error = '';
			try {
				call_user_func( $callback, 'sample', $request, $name );
				call_user_func( $callback, '-5', $request, $name );
			} catch ( Throwable $e ) {
				$error = get_class( $e ) . ': ' . $e->getMessage();
			}
			check( "$path:$name $key accepts three arguments", $error, '' );
		}
	}
}

$state = splm_arity_state();
echo "\n{$state->total} checks, {$state->failures} failures\n";
exit( $state->failures > 0 ? 1 : 0 );
