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

		foreach ( array( 'SPSS_Claude_Provider', 'SPSS_Gemini_Provider', 'SPSS_OpenAI_Provider', 'SPSS_OpenRouter_Provider', 'SPSS_SelfHosted_Provider' ) as $class ) {
			if ( class_exists( $class ) ) {
				$provider                        = new $class();
				$providers[ $provider->get_id() ] = $provider;
			}
		}

		/**
		 * Register additional recognition providers (third-party backends).
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

	/**
	 * Ordered recognition (failover) chain of provider ids. The lead result comes
	 * from the first one that is configured, within budget, and succeeds; the rest
	 * are fallbacks. Falls back to the legacy single `spss_primary_provider`.
	 *
	 * @return string[]
	 */
	public static function get_primary_chain() {
		$chain = get_option( 'spss_primary_chain', array() );
		if ( ! is_array( $chain ) || empty( $chain ) ) {
			$legacy = (string) get_option( 'spss_primary_provider', 'claude' );
			$chain  = array( '' !== $legacy ? $legacy : 'claude' );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $chain ) ) ) );
	}

	/**
	 * Confirmation (cross-check) provider ids. Each is run in addition to the lead
	 * and its disagreements are flagged. Falls back to the legacy single
	 * `spss_secondary_provider`.
	 *
	 * @return string[]
	 */
	public static function get_confirmation_ids() {
		$ids = get_option( 'spss_confirmation_providers', array() );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			$legacy = (string) get_option( 'spss_secondary_provider', '' );
			$ids    = '' !== $legacy ? array( $legacy ) : array();
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
	}

	/** First provider in the chain (back-compat + admin display). */
	public static function get_primary_id() {
		$chain = self::get_primary_chain();
		return $chain[0] ?? 'claude';
	}

	/**
	 * Whether at least one provider in the recognition chain is configured (used
	 * for the admin "not configured" warning).
	 */
	public static function has_usable_primary() {
		$providers = self::get_providers();
		foreach ( self::get_primary_chain() as $id ) {
			if ( isset( $providers[ $id ] ) && $providers[ $id ]->is_configured() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Run recognition: walk the chain until one provider (configured + within its
	 * monthly budget) returns a result, failing over on error or budget
	 * exhaustion; then run every configured, in-budget confirmation provider and
	 * flag disagreements against the lead result. Estimated cost is recorded per
	 * call made.
	 *
	 * @return SPSS_Extraction_Result|WP_Error
	 */
	public static function recognize( $image_abs_path, array $context ) {
		$providers = self::get_providers();
		$errors    = array();
		$lead      = null;
		$lead_id   = '';

		foreach ( self::get_primary_chain() as $id ) {
			$p = $providers[ $id ] ?? null;
			if ( ! $p ) {
				continue;
			}
			if ( ! $p->is_configured() ) {
				$errors[] = $p->get_label() . ': not configured';
				continue;
			}
			if ( ! SPSS_Budget::can_spend( $id, $p ) ) {
				$errors[] = $p->get_label() . ': monthly budget exhausted';
				continue;
			}
			$r = $p->recognize( $image_abs_path, $context );
			SPSS_Budget::record( $id, $p ); // A call was made; bill it (estimate).
			if ( is_wp_error( $r ) ) {
				$errors[] = $p->get_label() . ': ' . $r->get_error_message();
				continue; // Fail over to the next provider in the chain.
			}
			$lead    = $r;
			$lead_id = $id;
			break;
		}

		if ( ! $lead ) {
			return new WP_Error(
				'spss_recognition_failed',
				__( 'No recognition provider produced a result.', 'sportspress-score-sheets' ) . ' ' . implode( '; ', $errors )
			);
		}

		// Confirmation cross-checks (each configured + in-budget provider, except the lead).
		foreach ( self::get_confirmation_ids() as $cid ) {
			if ( $cid === $lead_id ) {
				continue;
			}
			$c = $providers[ $cid ] ?? null;
			if ( ! $c || ! $c->is_configured() || ! SPSS_Budget::can_spend( $cid, $c ) ) {
				continue;
			}
			$second = $c->recognize( $image_abs_path, $context );
			SPSS_Budget::record( $cid, $c );
			if ( ! is_wp_error( $second ) ) {
				self::cross_check( $lead, $second, $c->get_label() );
			}
		}

		return $lead;
	}

	/**
	 * Compare two independent extractions and flag disagreements on the primary
	 * result so a human resolves them. Conservative: any team-score or
	 * per-jersey stat mismatch becomes a `cross_check_mismatch` flag.
	 */
	private static function cross_check( SPSS_Extraction_Result $primary, SPSS_Extraction_Result $secondary, $label = '' ) {
		$who = '' !== $label ? ' [' . $label . ']' : '';
		foreach ( array( 'home', 'away' ) as $side ) {
			$p_score = $primary->data['teams'][ $side ]['final_score'] ?? null;
			$s_score = $secondary->data['teams'][ $side ]['final_score'] ?? null;
			if ( ! is_null( $p_score ) && ! is_null( $s_score ) && (int) $p_score !== (int) $s_score ) {
				$primary->add_flag( 'cross_check_mismatch', sprintf( '%s final score: %s vs %s%s', $side, $p_score, $s_score, $who ) );
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
