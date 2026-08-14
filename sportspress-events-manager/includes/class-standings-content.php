<?php
/**
 * Block-content manipulation for the standings pages.
 *
 * The live site keeps a hand-built page per season that hardcodes one
 * [team_standings <id>] shortcode per division — twelve of them for W2025-26,
 * five regular and seven playoff. Rebuilding that by hand every season is the
 * largest piece of manual work in a rollover, and it is entirely derivable from
 * the tables the rollover just created.
 *
 * Kept free of WordPress calls so the string handling can be tested directly.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Standings_Content {

	/**
	 * Matches a whole shortcode block, with or without its Gutenberg wrapper.
	 */
	const BLOCK_PATTERN = '/(?:<!--\s*wp:shortcode\s*-->\s*)?\[team_standings\s+(\d+)\s*\](?:\s*<!--\s*\/wp:shortcode\s*-->)?/i';

	/**
	 * Read the standings table IDs a page renders, in document order.
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	public static function extract_table_ids( $content ) {
		if ( ! preg_match_all( self::BLOCK_PATTERN, (string) $content, $matches ) ) {
			return array();
		}

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Wrap a table ID in the Gutenberg shortcode block the pages already use.
	 *
	 * @param int $table_id Standings table post ID.
	 * @return string
	 */
	public static function block( $table_id ) {
		return "<!-- wp:shortcode -->\n[team_standings " . (int) $table_id . "]\n<!-- /wp:shortcode -->";
	}

	/**
	 * Swap a page's standings blocks for a new set, leaving everything else be.
	 *
	 * The replacement run is written where the first existing block was, so the
	 * surrounding prose keeps its meaning — an intro paragraph stays above the
	 * tables rather than being orphaned when the count changes. Pages with no
	 * tables yet get the blocks appended.
	 *
	 * @param string $content   Post content.
	 * @param int[]  $table_ids Replacement table IDs.
	 * @return string
	 */
	public static function replace_table_ids( $content, array $table_ids ) {
		$content = (string) $content;
		$blocks  = array();

		foreach ( $table_ids as $table_id ) {
			$blocks[] = self::block( $table_id );
		}

		$replacement = implode( "\n\n", $blocks );

		if ( ! preg_match( self::BLOCK_PATTERN, $content ) ) {
			if ( '' === $replacement ) {
				return $content;
			}

			return rtrim( $content ) . "\n\n" . $replacement;
		}

		// Drop every existing block, marking where the first one was so the new
		// run lands in the same position.
		$marker  = "\0SPEM_STANDINGS\0";
		$first   = true;
		$stripped = preg_replace_callback(
			self::BLOCK_PATTERN,
			static function () use ( &$first, $marker ) {
				if ( $first ) {
					$first = false;

					return $marker;
				}

				return '';
			},
			$content
		);

		$stripped = str_replace( $marker, $replacement, $stripped );

		// Collapse the blank lines left behind by removed blocks.
		$stripped = preg_replace( "/\n{3,}/", "\n\n", $stripped );

		return trim( $stripped ) === '' ? $replacement : $stripped;
	}

	/**
	 * Build an archive page body combining a season's regular and playoff tables.
	 *
	 * Mirrors the existing archive pages: a heading and a line of copy per
	 * section, then the tables.
	 *
	 * @param string $season_name    Season being archived.
	 * @param int[]  $regular_ids    Regular season table IDs.
	 * @param int[]  $playoff_ids    Playoff table IDs.
	 * @return string
	 */
	public static function build_archive_content( $season_name, array $regular_ids, array $playoff_ids ) {
		$parts = array();

		if ( $regular_ids ) {
			$parts[] = self::heading( __( 'Regular Season', 'sportspress-events-manager' ) );
			$parts[] = self::paragraph(
				sprintf(
					/* translators: %s: season name */
					__( 'Below are the %s Regular Season standings.', 'sportspress-events-manager' ),
					$season_name
				)
			);

			foreach ( $regular_ids as $table_id ) {
				$parts[] = self::block( $table_id );
			}
		}

		if ( $playoff_ids ) {
			$parts[] = self::heading( __( 'Playoffs', 'sportspress-events-manager' ) );
			$parts[] = self::paragraph(
				sprintf(
					/* translators: %s: season name */
					__( 'Below are the %s Playoffs standings.', 'sportspress-events-manager' ),
					$season_name
				)
			);

			foreach ( $playoff_ids as $table_id ) {
				$parts[] = self::block( $table_id );
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * A Gutenberg heading block.
	 *
	 * @param string $text Heading text.
	 * @return string
	 */
	private static function heading( $text ) {
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $text ) . "</h2>\n<!-- /wp:heading -->";
	}

	/**
	 * A Gutenberg paragraph block.
	 *
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private static function paragraph( $text ) {
		return "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
	}
}
