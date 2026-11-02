<?php
/**
 * Standalone tests for SPBN_Ical (renderer and token management).
 *
 * Usage: php test-ical.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-ical.php';

/** A presenter game object plus the feed's sequence. */
function spbn_game( array $over = array() ) {
	return array_merge(
		array(
			'id'       => 101,
			'start'    => '2026-11-04T21:45:00-05:00',
			'status'   => 'ok',
			'venue'    => array( 'name' => 'Twin Rinks', 'address' => '' ),
			'team'     => array( 'id' => 12, 'name' => 'Ducks', 'logo' => '' ),
			'opponent' => array( 'id' => 7, 'name' => 'Knights', 'logo' => '' ),
			'is_home'  => true,
			'as_spare' => false,
			'sequence' => 0,
		),
		$over
	);
}

/** Unfold a rendered feed back into logical lines. */
function spbn_unfold( $ics ) {
	return explode( "\r\n", rtrim( str_replace( "\r\n ", '', $ics ), "\r\n" ) );
}

/** The logical lines of a single VEVENT, as a key => value map (first wins). */
function spbn_event_props( $ics ) {
	$props = array();
	foreach ( spbn_unfold( $ics ) as $line ) {
		$pos = strpos( $line, ':' );
		if ( false === $pos ) {
			continue;
		}
		$name = substr( $line, 0, $pos );
		if ( ! isset( $props[ $name ] ) ) {
			$props[ $name ] = substr( $line, $pos + 1 );
		}
	}
	return $props;
}

echo "\n=== envelope ===\n\n";

$ics = SPBN_Ical::render( array( spbn_game() ), 'arl.example.test', 'ARL Bench' );
spbn_assert( 0 === strpos( $ics, "BEGIN:VCALENDAR\r\n" ), 'output starts BEGIN:VCALENDAR CRLF' );
spbn_assert( "END:VCALENDAR\r\n" === substr( $ics, -15 ), 'output ends END:VCALENDAR CRLF' );
spbn_assert( 0 === preg_match( '/(?<!\r)\n/', $ics ) && 0 === preg_match( '/\r(?!\n)/', $ics ), 'every line ends CRLF (no bare LF or CR)' );
spbn_assert( false !== strpos( $ics, "VERSION:2.0\r\n" ) && false !== strpos( $ics, "PRODID:" ), 'has VERSION and PRODID' );
spbn_assert( false !== strpos( $ics, "X-WR-CALNAME:ARL Bench\r\n" ), 'X-WR-CALNAME is set' );
spbn_assert( 1 === substr_count( $ics, "BEGIN:VEVENT\r\n" ) && 1 === substr_count( $ics, "END:VEVENT\r\n" ), 'one VEVENT per game' );

$empty = SPBN_Ical::render( array(), 'arl.example.test', 'ARL Bench' );
spbn_assert( 0 === strpos( $empty, "BEGIN:VCALENDAR\r\n" ) && false === strpos( $empty, 'BEGIN:VEVENT' ), 'no games: a valid empty calendar' );

$two = SPBN_Ical::render( array( spbn_game(), spbn_game( array( 'id' => 102 ) ) ), 'arl.example.test', 'ARL Bench' );
spbn_assert( 2 === substr_count( $two, "BEGIN:VEVENT\r\n" ), 'two games, two VEVENTs' );

$cal = SPBN_Ical::render( array(), 'h', "Bench, for; Ducks\nline2" );
spbn_assert( false !== strpos( $cal, "X-WR-CALNAME:Bench\\, for\\; Ducks\\nline2\r\n" ), 'the calendar name is escaped too' );

echo "\n=== event properties ===\n\n";

$p = spbn_event_props( $ics );
spbn_assert( 'spbn-101-12@arl.example.test' === $p['UID'], 'UID is spbn-<event>-<team>@host' );
spbn_assert( '20261105T024500Z' === $p['DTSTART'], 'DTSTART is UTC: 21:45 -05:00 -> 20261105T024500Z' );
spbn_assert( '20261105T040000Z' === $p['DTEND'], 'DTEND is start + 75 minutes by default' );
spbn_assert( 1 === preg_match( '/^\d{8}T\d{6}Z$/', $p['DTSTAMP'] ), 'DTSTAMP is a UTC stamp' );
spbn_assert( '0' === $p['SEQUENCE'], 'SEQUENCE defaults to 0' );
spbn_assert( 'CONFIRMED' === $p['STATUS'], 'an ok game is CONFIRMED' );
spbn_assert( "\u{1F3D2} Ducks vs Knights" === $p['SUMMARY'], 'home game summary: "<hockey> Team vs Opponent"' );
spbn_assert( 'Twin Rinks' === $p['LOCATION'], 'LOCATION is the venue name' );

$away = spbn_event_props( SPBN_Ical::render( array( spbn_game( array( 'is_home' => false ) ) ), 'h', 'c' ) );
spbn_assert( "\u{1F3D2} Ducks @ Knights" === $away['SUMMARY'], 'away game summary uses @' );

$spare = spbn_event_props( SPBN_Ical::render( array( spbn_game( array( 'as_spare' => true ) ) ), 'h', 'c' ) );
spbn_assert( "\u{1F3D2} Ducks vs Knights (spare)" === $spare['SUMMARY'], 'a spare game is tagged' );

$seq = spbn_event_props( SPBN_Ical::render( array( spbn_game( array( 'sequence' => 2 ) ) ), 'h', 'c' ) );
spbn_assert( '2' === $seq['SEQUENCE'], 'SEQUENCE:2 passes through' );

$addr = spbn_event_props( SPBN_Ical::render( array( spbn_game( array( 'venue' => array( 'name' => 'Twin Rinks', 'address' => '1 Main St, Toronto' ) ) ) ), 'h', 'c' ) );
spbn_assert( 'Twin Rinks\\, 1 Main St\\, Toronto' === $addr['LOCATION'], 'LOCATION adds the address, comma-escaped' );

$full = spbn_event_props( SPBN_Ical::render( array( spbn_game( array( 'venue' => array( 'name' => 'Black', 'rink' => 'Wave Twin Rinks', 'address' => '1179 Northside Rd, Burlington, ON L7M, Canada', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=43.361123,-79.804012' ) ) ) ), 'h', 'c' ) );
spbn_assert( 'Wave Twin Rinks · Black\\, 1179 Northside Rd\\, Burlington\\, ON L7M\\, Canada' === $full['LOCATION'], 'LOCATION is "rink · name, address", escaped' );
spbn_assert( '43.361123;-79.804012' === ( $full['GEO'] ?? '' ), 'GEO:lat;lng from the venue\'s coordinates' );

$name_only = spbn_event_props( SPBN_Ical::render( array( spbn_game( array( 'venue' => array( 'name' => 'Mainway Rink 2', 'rink' => '', 'address' => '', 'map_url' => '' ) ) ) ), 'h', 'c' ) );
spbn_assert( 'Mainway Rink 2' === $name_only['LOCATION'] && ! isset( $name_only['GEO'] ), 'empty rink and address are skipped; no GEO without coordinates' );

$rink_only = spbn_event_props( SPBN_Ical::render( array( spbn_game( array( 'venue' => array( 'name' => 'Appleby 1', 'rink' => 'APPLEBY ICE CENTRE', 'address' => '', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=1%20A%20St' ) ) ) ), 'h', 'c' ) );
spbn_assert( 'APPLEBY ICE CENTRE · Appleby 1' === $rink_only['LOCATION'] && ! isset( $rink_only['GEO'] ), 'rink and name, an address-based map link gives no GEO' );

$noven = spbn_event_props( SPBN_Ical::render( array( spbn_game( array( 'venue' => array( 'name' => '', 'address' => '' ) ) ) ), 'h', 'c' ) );
spbn_assert( ! isset( $noven['LOCATION'] ), 'no venue: no LOCATION line' );

echo "\n=== status ===\n\n";

foreach ( array( 'cancelled', 'postponed' ) as $st ) {
	$out = SPBN_Ical::render( array( spbn_game( array( 'status' => $st ) ) ), 'h', 'c' );
	spbn_assert( false !== strpos( $out, "STATUS:CANCELLED\r\n" ) && false === strpos( $out, 'STATUS:CONFIRMED' ), "{$st} -> STATUS:CANCELLED" );
}
$tbd = SPBN_Ical::render( array( spbn_game( array( 'status' => 'tbd' ) ) ), 'h', 'c' );
spbn_assert( false !== strpos( $tbd, "STATUS:TENTATIVE\r\n" ), 'tbd -> STATUS:TENTATIVE' );
$pp = spbn_event_props( SPBN_Ical::render( array( spbn_game( array( 'status' => 'postponed' ) ) ), 'h', 'c' ) );
spbn_assert( 0 === strpos( $pp['SUMMARY'], 'Postponed: ' ), 'a postponed game says so in its title for clients that ignore STATUS' );

echo "\n=== duration filter ===\n\n";

add_filter(
	'spbn_game_duration_minutes',
	function () {
		return 60;
	}
);
$d = spbn_event_props( SPBN_Ical::render( array( spbn_game() ), 'h', 'c' ) );
spbn_assert( '20261105T034500Z' === $d['DTEND'], 'spbn_game_duration_minutes filter changes DTEND' );
$GLOBALS['spbn_filters'] = array();
$d = spbn_event_props( SPBN_Ical::render( array( spbn_game() ), 'h', 'c', 90 ) );
spbn_assert( '20261105T041500Z' === $d['DTEND'], 'an explicit duration argument wins' );

echo "\n=== games without a usable start ===\n\n";

$none = SPBN_Ical::render( array( spbn_game( array( 'start' => '' ) ), spbn_game( array( 'id' => 5, 'start' => 'not a date' ) ) ), 'h', 'c' );
spbn_assert( false === strpos( $none, 'BEGIN:VEVENT' ), 'games with no parseable start are skipped' );

echo "\n=== escaping ===\n\n";

$e = spbn_event_props(
	SPBN_Ical::render(
		array(
			spbn_game(
				array(
					'team'     => array( 'id' => 12, 'name' => 'Ducks, Inc; Back\\Slash', 'logo' => '' ),
					'opponent' => array( 'id' => 7, 'name' => "Line1\nLine2", 'logo' => '' ),
					'venue'    => array( 'name' => 'Rink A, B; "C"', 'address' => '' ),
				)
			),
		),
		'h',
		'c'
	)
);
spbn_assert( false !== strpos( $e['SUMMARY'], 'Ducks\\, Inc\\; Back\\\\Slash' ), 'comma, semicolon and backslash are escaped in SUMMARY' );
spbn_assert( false !== strpos( $e['SUMMARY'], 'Line1\\nLine2' ), 'a newline becomes \\n in SUMMARY' );
spbn_assert( 'Rink A\\, B\\; "C"' === $e['LOCATION'], 'comma and semicolon are escaped in LOCATION' );

$crlf = SPBN_Ical::render( array( spbn_game( array( 'opponent' => array( 'id' => 7, 'name' => "A\r\nB\rC", 'logo' => '' ) ) ) ), 'h', 'c' );
spbn_assert( 0 === preg_match( '/(?<!\r)\n/', $crlf ) && false !== strpos( $crlf, 'A\\nB\\nC' ), 'CRLF and CR inside text become \\n, never raw line breaks' );

echo "\n=== folding ===\n\n";

$long_name = 'The Extraordinarily Long Named Hockey Club ' . str_repeat( 'Wonderful ', 12 );
$folded    = SPBN_Ical::render( array( spbn_game( array( 'opponent' => array( 'id' => 7, 'name' => $long_name, 'logo' => '' ) ) ) ), 'h', 'c' );
$max       = 0;
foreach ( explode( "\r\n", rtrim( $folded, "\r\n" ) ) as $line ) {
	$max = max( $max, strlen( $line ) );
}
spbn_assert( $max <= 75, "no physical line is longer than 75 octets (longest: {$max})" );
spbn_assert( false !== strpos( $folded, "\r\n " ), 'long lines are folded with CRLF + one space' );
$unfolded = spbn_event_props( $folded );
spbn_assert( false !== strpos( $unfolded['SUMMARY'], trim( $long_name ) ), 'unfolding gives back the full text' );

// Multibyte: a run of 3-byte characters (and 4-byte emoji) forces a fold mid-run.
$mb_name = str_repeat( "\u{00E9}\u{4E2D}\u{1F3D2}", 30 );
$mb      = SPBN_Ical::render( array( spbn_game( array( 'opponent' => array( 'id' => 7, 'name' => $mb_name, 'logo' => '' ) ) ) ), 'h', 'c' );
$ok      = true;
$maxmb   = 0;
foreach ( explode( "\r\n", rtrim( $mb, "\r\n" ) ) as $line ) {
	$maxmb = max( $maxmb, strlen( $line ) );
	if ( ! mb_check_encoding( $line, 'UTF-8' ) ) {
		$ok = false;
	}
}
spbn_assert( $maxmb <= 75, "multibyte text: no line over 75 octets (longest: {$maxmb})" );
spbn_assert( $ok, 'multibyte text: every physical line is valid UTF-8 (no split sequence)' );
$props = spbn_event_props( $mb );
spbn_assert( false !== strpos( $props['SUMMARY'], $mb_name ), 'multibyte text survives folding and unfolding intact' );

echo "\n=== tokens ===\n\n";

spbn_reset();
$t1 = SPBN_Ical::token_for( 5 );
spbn_assert( 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $t1 ), 'token_for creates a 32-char alphanumeric token' );
spbn_assert( $t1 === SPBN_Ical::token_for( 5 ), 'token_for is stable once created' );
spbn_assert( $t1 === get_user_meta( 5, 'spbn_ical_token', true ), 'the token lives in user meta spbn_ical_token' );
spbn_assert( SPBN_Ical::token_for( 6 ) !== $t1, 'users get different tokens' );

update_user_meta( 8, 'spbn_ical_token', 'short' );
$t8 = SPBN_Ical::token_for( 8 );
spbn_assert( 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $t8 ), 'a malformed stored token is replaced' );

$t2 = SPBN_Ical::rotate( 5 );
spbn_assert( 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $t2 ) && $t2 !== $t1, 'rotate returns a new 32-char token' );
spbn_assert( $t2 === SPBN_Ical::token_for( 5 ), 'and it is the one now stored' );

spbn_add_user( 5 );
spbn_add_user( 6 );
$GLOBALS['spbn_user_queries'] = array();
spbn_assert( 5 === SPBN_Ical::user_for_token( $t2 ), 'user_for_token finds the owner' );
$q = $GLOBALS['spbn_user_queries'][0];
spbn_assert( 'spbn_ical_token' === $q['meta_key'] && $t2 === $q['meta_value'] && 1 === $q['number'] && 'ID' === $q['fields'], 'the lookup is an exact meta match, one user, ids only' );
spbn_assert( 0 === SPBN_Ical::user_for_token( $t1 ), 'a rotated-out token no longer works' );
spbn_assert( 0 === SPBN_Ical::user_for_token( str_repeat( 'a', 32 ) ), 'an unknown well-formed token is 0' );

$GLOBALS['spbn_user_queries'] = array();
foreach ( array( '', 'short', str_repeat( 'a', 31 ), str_repeat( 'a', 33 ), str_repeat( 'a', 31 ) . '-', str_repeat( 'a', 31 ) . "\n", "x' OR '1'='1" . str_repeat( 'a', 20 ) ) as $bad ) {
	spbn_assert( 0 === SPBN_Ical::user_for_token( $bad ), 'malformed token ' . json_encode( $bad ) . ' -> 0' );
}
spbn_assert( array() === $GLOBALS['spbn_user_queries'], 'malformed tokens never reach the database' );

// A case-insensitive database collation must not let a differently cased token in.
update_user_meta( 6, 'spbn_ical_token', 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' );
spbn_assert( 0 === SPBN_Ical::user_for_token( 'abcdefghijklmnopqrstuvwxyz012345' ), 'tokens match case-sensitively' );
spbn_assert( 6 === SPBN_Ical::user_for_token( 'AbCdEfGhIjKlMnOpQrStUvWxYz012345' ), 'the exact token matches' );

spbn_assert( 'https://arl.example.test/bench/ical/' . $t2 . '.ics' === SPBN_Ical::feed_url( $t2 ), 'feed_url is home_url(/bench/ical/<token>.ics)' );

spbn_done();
