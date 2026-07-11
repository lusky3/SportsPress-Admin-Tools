<?php
/**
 * Recognition provider registry + orchestration.
 *
 * Holds the set of available providers, exposes the admin-selected Primary and
 * optional Secondary (cross-check) providers, and runs recognition. When a
 * Secondary is configured, disagreements between the two passes are flagged and
 * the affected fields' confidence is downgraded so the review UI surfaces them.
 *
 * Add a new backend by implementing SPSS_Recognition_Provider and registering
 * it via the `spss_register_recognition_providers` filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Recognition_Manager {

	/**
	 * @return SPSS_Recognition_Provider[] keyed by provider id.
	 */
	public static function get_providers() {
		$providers = array();

		$claude = new SPSS_Claude_Provider();
		$providers[ $claude->get_id() ] = $claude;

		/**
		 * Register additional recognition providers.
		 *
		 * @param SPSS_Recognition_Provider[] $providers Keyed by provider id.
		 */
		$providers = apply_filters( 'spss_register_recognition_providers', $providers );

		// Keep only valid implementations.
		foreach ( $providers as $id => $p ) {
			if ( ! $p instanceof SPSS_Recognition_Provider ) {
				unset( $providers[ $id ] );
			}
		}
		return $providers;
	}

	public static function get_provider( $id ) {
		$providers = self::get_providers();
		return $providers[ $id ] ?? null;
	}

	public static function get_primary_id() {
		$id = (string) get_option( 'spss_primary_provider', 'claude' );
		return '' !== $id ? $id : 'claude';
	}

	public static function get_secondary_id() {
		return (string) get_option( 'spss_secondary_provider', '' );
	}

	/**
	 * Run recognition using the Primary provider, optionally cross-checked by a
	 * Secondary. Returns the (possibly flag-augmented) primary result.
	 *
	 * @return SPSS_Extraction_Result|WP_Error
	 */
	public static function recognize( $image_abs_path, array $context ) {
		$primary = self::get_provider( self::get_primary_id() );
		if ( ! $primary ) {
			return new WP_Error( 'spss_no_provider', __( 'No recognition provider is configured.', 'sportspress-score-sheets' ) );
		}
		if ( ! $primary->is_configured() ) {
			return new WP_Error( 'spss_provider_unconfigured', sprintf( /* translators: %s: provider label */ __( '%s is not configured (missing API key).', 'sportspress-score-sheets' ), $primary->get_label() ) );
		}

		$result = $primary->recognize( $image_abs_path, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$secondary_id = self::get_secondary_id();
		if ( '' !== $secondary_id && $secondary_id !== $primary->get_id() ) {
			$secondary = self::get_provider( $secondary_id );
			if ( $secondary && $secondary->is_configured() ) {
				$second = $secondary->recognize( $image_abs_path, $context );
				if ( ! is_wp_error( $second ) ) {
					self::cross_check( $result, $second );
				}
			}
		}

		return $result;
	}

	/**
	 * Compare two independent extractions and flag disagreements on the primary
	 * result so a human resolves them. Conservative: any team-score or
	 * per-jersey stat mismatch becomes a `cross_check_mismatch` flag.
	 */
	private static function cross_check( SPSS_Extraction_Result $primary, SPSS_Extraction_Result $secondary ) {
		foreach ( array( 'home', 'away' ) as $side ) {
			$p_score = $primary->data['teams'][ $side ]['final_score'] ?? null;
			$s_score = $secondary->data['teams'][ $side ]['final_score'] ?? null;
			if ( ! is_null( $p_score ) && ! is_null( $s_score ) && (int) $p_score !== (int) $s_score ) {
				$primary->add_flag( 'cross_check_mismatch', sprintf( '%s final score: %s vs %s', $side, $p_score, $s_score ) );
			}
		}

		$index_secondary = array();
		foreach ( (array) ( $secondary->data['players'] ?? array() ) as $sp ) {
			$key = ( $sp['team'] ?? '' ) . ':' . ( $sp['jersey_written'] ?? '' );
			$index_secondary[ $key ] = $sp;
		}
		foreach ( (array) ( $primary->data['players'] ?? array() ) as $i => $pp ) {
			$key = ( $pp['team'] ?? '' ) . ':' . ( $pp['jersey_written'] ?? '' );
			if ( ! isset( $index_secondary[ $key ] ) ) {
				continue;
			}
			$sp = $index_secondary[ $key ];
			foreach ( array( 'goals', 'assists', 'pim' ) as $stat ) {
				$pv = $pp[ $stat ] ?? null;
				$sv = $sp[ $stat ] ?? null;
				if ( ! is_null( $pv ) && ! is_null( $sv ) && (int) $pv !== (int) $sv ) {
					$primary->add_flag( 'cross_check_mismatch', sprintf( '#%s %s: %s vs %s', $pp['jersey_written'] ?? '?', $stat, $pv, $sv ), $i );
				}
			}
		}
	}
}
