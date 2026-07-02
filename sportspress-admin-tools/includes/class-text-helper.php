<?php
/**
 * Text Helper for SportsPress text overrides
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Text_Helper {

	/**
	 * Get text with SportsPress override support
	 *
	 * @param string $text The original text to potentially override
	 * @param string $domain Text domain (default: 'sportspress-admin-tools')
	 * @return string The overridden text or original text
	 */
	public static function get_text( $text, $domain = 'sportspress-admin-tools' ) {
		// Check if SportsPress is available and has text overrides
		if ( function_exists( 'SP' ) && SP() && ! empty( SP()->text ) && isset( SP()->text[ $text ] ) && ! empty( SP()->text[ $text ] ) ) {
			return SP()->text[ $text ];
		}

		// No SportsPress override — return the original string unchanged.
		// We deliberately do NOT wrap $text in __() here: $text is a runtime
		// variable, so __( $text, $domain ) is not extractable by i18n tooling
		// and would be a no-op passthrough. Translation of these UI strings is
		// handled at their literal call sites; SportsPress label overrides are
		// the dynamic layer this helper exists to apply. The $domain parameter
		// is retained for call-site compatibility but is intentionally unused. (AT-6)
		return $text;
	}

	/**
	 * Echo text with SportsPress override support
	 *
	 * @param string $text The original text to potentially override
	 * @param string $domain Text domain (default: 'sportspress-admin-tools')
	 */
	public static function echo_text( $text, $domain = 'sportspress-admin-tools' ) {
		echo esc_html( self::get_text( $text, $domain ) );
	}
}
