<?php
/**
 * Standalone tests for SPEM_Standings_Content.
 *
 * The block-content manipulation is pure string work, so it runs with no
 * WordPress bootstrap. The page reads and writes live in
 * SPEM_Standings_Pages and are exercised against staging instead.
 */

define( 'ABSPATH', __DIR__ );

// Minimal stand-ins for the two WordPress functions the builder touches.
if ( ! function_exists( '__' ) ) {
	/**
	 * Stub mirroring the WordPress signature; unused arguments are deliberate.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function __( $text, $domain = '' ) { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

require_once __DIR__ . '/../includes/class-standings-content.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

function block( $id ) {
	return "<!-- wp:shortcode -->\n[team_standings {$id}]\n<!-- /wp:shortcode -->";
}

$page = "<!-- wp:paragraph -->\n<p>Intro copy.</p>\n<!-- /wp:paragraph -->\n\n"
	. block( 111 ) . "\n\n" . block( 222 ) . "\n\n"
	. "<!-- wp:paragraph -->\n<p>Closing copy.</p>\n<!-- /wp:paragraph -->";

echo "\n=== extract_table_ids() ===\n\n";

assert_test(
	array( 111, 222 ) === SPEM_Standings_Content::extract_table_ids( $page ),
	'table ids are read in document order'
);
assert_test(
	array() === SPEM_Standings_Content::extract_table_ids( '<p>no shortcodes here</p>' ),
	'a page with no standings shortcodes yields an empty list'
);
assert_test(
	array( 5 ) === SPEM_Standings_Content::extract_table_ids( '[team_standings   5  ]' ),
	'extra whitespace inside the shortcode is tolerated'
);
assert_test(
	array( 7 ) === SPEM_Standings_Content::extract_table_ids( "[team_standings 7]\n[other_shortcode 9]" ),
	'only team_standings shortcodes are picked up'
);

echo "\n=== replace_table_ids() ===\n\n";

$updated = SPEM_Standings_Content::replace_table_ids( $page, array( 333, 444, 555 ) );

assert_test(
	array( 333, 444, 555 ) === SPEM_Standings_Content::extract_table_ids( $updated ),
	'all replacement ids are present, including one more than before'
);
assert_test(
	strpos( $updated, 'Intro copy.' ) !== false && strpos( $updated, 'Closing copy.' ) !== false,
	'surrounding prose blocks are preserved'
);
assert_test(
	strpos( $updated, '[team_standings 111]' ) === false,
	'the previous ids are gone'
);
assert_test(
	strpos( $updated, 'Intro copy.' ) < strpos( $updated, '[team_standings 333]' )
		&& strpos( $updated, '[team_standings 555]' ) < strpos( $updated, 'Closing copy.' ),
	'the new block run sits where the old one was, not appended at the end'
);

$shrunk = SPEM_Standings_Content::replace_table_ids( $page, array( 999 ) );
assert_test(
	array( 999 ) === SPEM_Standings_Content::extract_table_ids( $shrunk ),
	'replacing with fewer ids removes the surplus blocks'
);

$emptied = SPEM_Standings_Content::replace_table_ids( $page, array() );
assert_test(
	array() === SPEM_Standings_Content::extract_table_ids( $emptied )
		&& strpos( $emptied, 'Intro copy.' ) !== false,
	'replacing with nothing strips the tables but keeps the prose'
);

$fresh = SPEM_Standings_Content::replace_table_ids( "<!-- wp:paragraph -->\n<p>Only prose.</p>\n<!-- /wp:paragraph -->", array( 42 ) );
assert_test(
	array( 42 ) === SPEM_Standings_Content::extract_table_ids( $fresh ),
	'a page with no existing tables gets the blocks appended'
);

echo "\n=== build_archive_content() ===\n\n";

$archive = SPEM_Standings_Content::build_archive_content( 'W2025-26', array( 1, 2 ), array( 3, 4 ) );

assert_test(
	array( 1, 2, 3, 4 ) === SPEM_Standings_Content::extract_table_ids( $archive ),
	'archive combines regular then playoff tables in order'
);
assert_test(
	strpos( $archive, 'Regular Season' ) !== false && strpos( $archive, 'Playoffs' ) !== false,
	'archive carries both section headings'
);
assert_test(
	strpos( $archive, 'W2025-26' ) !== false,
	'archive names the season it covers'
);

$no_playoffs = SPEM_Standings_Content::build_archive_content( 'S2026', array( 1 ), array() );
assert_test(
	strpos( $no_playoffs, 'Playoffs' ) === false,
	'the playoff section is omitted entirely when there are no playoff tables'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
