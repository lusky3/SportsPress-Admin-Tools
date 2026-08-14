<?php
/**
 * Shared title builder for generated calendars and player lists.
 *
 * The Events Management calendar tool already exposed prefix/suffix/separator
 * settings, but the season rollover hardcoded its own titles — which is why
 * rollover output used an em dash while ten years of site content uses a pipe
 * ("Cherry Pickers | ARL", "B-Town Bulldogs | S2026"). Both paths now build
 * titles here so they cannot drift again.
 *
 * The builder is pure: callers resolve the team name, division and season
 * themselves and pass strings in.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Naming {

	/**
	 * Assemble a title from the enabled parts.
	 *
	 * Parts are emitted in a fixed order — prefix, team, division, season,
	 * suffix — and any that is disabled or resolves to an empty string is
	 * skipped, so a missing division never leaves a dangling separator.
	 *
	 * @param array $settings prefix, suffix, separator, and the booleans
	 *                        team / division / season.
	 * @param array $values   team, division, season strings.
	 * @return string
	 */
	public static function build( array $settings, array $values ) {
		$separator = isset( $settings['separator'] ) ? trim( (string) $settings['separator'] ) : '|';
		$parts     = array();

		$candidates = array(
			array( true, isset( $settings['prefix'] ) ? $settings['prefix'] : '' ),
			array( ! empty( $settings['team'] ), isset( $values['team'] ) ? $values['team'] : '' ),
			array( ! empty( $settings['division'] ), isset( $values['division'] ) ? $values['division'] : '' ),
			array( ! empty( $settings['season'] ), isset( $values['season'] ) ? $values['season'] : '' ),
			array( true, isset( $settings['suffix'] ) ? $settings['suffix'] : '' ),
		);

		foreach ( $candidates as $candidate ) {
			list( $enabled, $value ) = $candidate;

			$value = trim( (string) $value );

			if ( $enabled && '' !== $value ) {
				$parts[] = $value;
			}
		}

		// Every part disabled or empty: fall back to the team name so a
		// misconfigured setting never produces an untitled post.
		if ( ! $parts ) {
			return trim( (string) ( isset( $values['team'] ) ? $values['team'] : '' ) );
		}

		$glue = '' !== $separator ? ' ' . $separator . ' ' : ' ';

		return implode( $glue, $parts );
	}

	/**
	 * Option keys for the calendar naming group.
	 *
	 * These predate the shared helper and do NOT share a single prefix — the
	 * checkbox keys are `spem_include_team_name` / `spem_include_division`, not
	 * `spem_naming_*`. Mapping them explicitly is what stops a tidy-looking
	 * refactor from silently reading options that were never written.
	 *
	 * @return array
	 */
	public static function calendar_keys() {
		return array(
			'prefix'    => 'spem_naming_prefix',
			'suffix'    => 'spem_naming_suffix',
			'separator' => 'spem_naming_separator',
			'team'      => 'spem_include_team_name',
			'division'  => 'spem_include_division',
			'season'    => null,
		);
	}

	/**
	 * Option keys for the player-list naming group.
	 *
	 * @return array
	 */
	public static function list_keys() {
		return array(
			'prefix'    => 'spem_list_naming_prefix',
			'suffix'    => 'spem_list_naming_suffix',
			'separator' => 'spem_list_naming_separator',
			'team'      => 'spem_list_include_team',
			'division'  => 'spem_list_include_division',
			'season'    => 'spem_list_include_season',
		);
	}

	/**
	 * Resolve a settings group through an injected option getter.
	 *
	 * A null key means the part has no setting and always uses its default,
	 * which is how calendars express "no season in the title".
	 *
	 * @param array    $keys     Part => option name (or null).
	 * @param callable $getter   fn( string $key, mixed $default ): mixed — injected
	 *                           so this stays testable without WordPress.
	 * @param array    $defaults Part defaults.
	 * @return array Settings array shaped for build().
	 */
	public static function settings_from_options( array $keys, $getter, array $defaults = array() ) {
		$defaults = array_merge(
			array(
				'prefix'    => '',
				'suffix'    => '',
				'separator' => '|',
				'team'      => true,
				'division'  => false,
				'season'    => false,
			),
			$defaults
		);

		$read = static function ( $part, $default ) use ( $keys, $getter ) {
			if ( empty( $keys[ $part ] ) ) {
				return $default;
			}

			return call_user_func( $getter, $keys[ $part ], $default );
		};

		return array(
			'prefix'    => (string) $read( 'prefix', $defaults['prefix'] ),
			'suffix'    => (string) $read( 'suffix', $defaults['suffix'] ),
			'separator' => (string) $read( 'separator', $defaults['separator'] ),
			'team'      => '1' === (string) $read( 'team', $defaults['team'] ? '1' : '0' ),
			'division'  => '1' === (string) $read( 'division', $defaults['division'] ? '1' : '0' ),
			'season'    => '1' === (string) $read( 'season', $defaults['season'] ? '1' : '0' ),
		);
	}

	/**
	 * Convenience wrapper that reads a group straight from get_option().
	 *
	 * @param array $keys     Part => option name.
	 * @param array $defaults Part defaults.
	 * @return array
	 */
	public static function settings( array $keys, array $defaults = array() ) {
		return self::settings_from_options(
			$keys,
			static function ( $key, $default ) {
				return get_option( $key, $default );
			},
			$defaults
		);
	}
}
