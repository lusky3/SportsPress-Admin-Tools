<?php
/**
 * Dynamic Standings
 *
 * Provides the [arl_standings] shortcode that dynamically renders league
 * tables by season and type (regular/playoffs) using AJAX filtering.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Dynamic_Standings {

	/**
	 * Lifetime, in seconds, of a rendered standings block in the transient
	 * cache (5 minutes). A literal rather than MINUTE_IN_SECONDS: class
	 * constants are resolved when the class is declared, which would make
	 * loading this file depend on WordPress's constants already being defined.
	 */
	const STANDINGS_CACHE_TTL = 300;

	public function __construct() {
		add_shortcode( 'arl_standings', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_spem_get_standings', array( $this, 'ajax_get_standings' ) );
		add_action( 'wp_ajax_nopriv_spem_get_standings', array( $this, 'ajax_get_standings' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );

		// Invalidate the standings transient cache whenever an sp_table is
		// saved or an sp_season term is edited. Without this, edits don't
		// show up for up to five minutes (the transient TTL).
		add_action( 'save_post_sp_table', array( $this, 'flush_standings_cache' ) );
		add_action( 'edited_sp_season', array( $this, 'flush_standings_cache' ) );
	}

	/**
	 * Flush all standings transients. Runs on sp_table save and sp_season edit.
	 *
	 * Uses a versioned namespace so flushing works on both DB-backed and
	 * external-object-cache hosts. Bumping the version makes existing keys
	 * unreachable; stale entries are evicted on their own TTL.
	 */
	public function flush_standings_cache() {
		global $wpdb;

		// Ensure the option exists before we try to atomically increment it.
		// add_option() is a no-op if the option already exists, so this is safe
		// to call unconditionally.
		add_option( 'spem_standings_cache_version', 1, '', false );

		// Atomic increment via direct SQL. Using get_option()+update_option()
		// is non-atomic: two concurrent flushes can both read N and both write
		// N+1, resulting in a single bump instead of two. The version only
		// needs to monotonically increase, so a SQL UPDATE is sufficient.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic increment, no caching needed.
		$wpdb->query(
			"UPDATE {$wpdb->options}
			SET option_value = option_value + 1
			WHERE option_name = 'spem_standings_cache_version'"
		);

		// Bust the options cache so subsequent get_option() calls see the new
		// value rather than the stale cached copy.
		wp_cache_delete( 'spem_standings_cache_version', 'options' );
	}

	/**
	 * Build a versioned cache key for a season+type pair.
	 *
	 * @param string $season Season slug.
	 * @param string $type   'regular' or 'playoff'.
	 * @return string
	 */
	private function build_cache_key( $season, $type ) {
		$version = (int) get_option( 'spem_standings_cache_version', 1 );
		return 'spem_standings_v' . $version . '_' . md5( $season . '|' . $type );
	}

	/**
	 * Enqueue assets only on pages that use the shortcode.
	 */
	public function maybe_enqueue() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'arl_standings' ) ) {
			return;
		}

		// __DIR__ is the /includes dir; plugin_dir_url() returns the URL of its
		// containing directory — the plugin ROOT (with trailing slash) — so the
		// enqueued assets point at <plugin-root>/assets/… where they actually
		// live. The old plugins_url( '', __DIR__ . '/..' ) never collapsed the
		// "/.." segment and pointed at includes/assets/… → 404 (H4).
		$plugin_url = plugin_dir_url( __DIR__ );

		wp_enqueue_script(
			'spem-dynamic-standings',
			$plugin_url . 'assets/js/dynamic-standings.js',
			array( 'jquery' ),
			SPEM_VERSION,
			true
		);
		wp_localize_script(
			'spem-dynamic-standings',
			'spemStandings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'spem_standings_nonce' ),
			)
		);
		wp_enqueue_style(
			'spem-dynamic-standings',
			$plugin_url . 'assets/css/dynamic-standings.css',
			array(),
			SPEM_VERSION
		);
	}

	/**
	 * Render the [arl_standings] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'season' => '',
				'type'   => 'regular',
			),
			$atts,
			'arl_standings'
		);

		$seasons = $this->get_grouped_seasons();
		if ( empty( $seasons ) ) {
			return '<p>' . esc_html__( 'No standings available.', 'sportspress-events-manager' ) . '</p>';
		}

		// Determine default season.
		$default_season = $atts['season'];
		if ( ! $default_season ) {
			$current_id = absint( get_option( 'spem_current_season_id', 0 ) );
			if ( $current_id ) {
				$term = get_term( $current_id, 'sp_season' );
				if ( $term && ! is_wp_error( $term ) ) {
					$default_season = $this->base_slug( $term->slug );
				}
			}
			// Fallback: first (newest) season in the list.
			if ( ! $default_season ) {
				$default_season = $seasons[0]['slug'];
			}
		}

		$default_type = in_array( $atts['type'], array( 'regular', 'playoff' ), true ) ? $atts['type'] : 'regular';

		// Initial render — cached like the AJAX path. This is the *page load*
		// every visitor pays for, so leaving it uncached while caching only the
		// filter switches had the caching exactly backwards.
		$initial_html = $this->get_cached_standings_html( $default_season, $default_type );

		ob_start();
		?>
		<div class="arl-standings-wrap" data-season="<?php echo esc_attr( $default_season ); ?>" data-type="<?php echo esc_attr( $default_type ); ?>">
			<div class="arl-standings-filters">
				<label for="arl-season-select"><?php esc_html_e( 'Season', 'sportspress-events-manager' ); ?></label>
				<select id="arl-season-select">
					<?php foreach ( $seasons as $s ) : ?>
						<option value="<?php echo esc_attr( $s['slug'] ); ?>" <?php selected( $s['slug'], $default_season ); ?>>
							<?php echo esc_html( $s['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="arl-type-select"><?php esc_html_e( 'Type', 'sportspress-events-manager' ); ?></label>
				<select id="arl-type-select">
					<option value="regular" <?php selected( $default_type, 'regular' ); ?>><?php esc_html_e( 'Regular Season', 'sportspress-events-manager' ); ?></option>
					<option value="playoff" <?php selected( $default_type, 'playoff' ); ?>><?php esc_html_e( 'Playoffs', 'sportspress-events-manager' ); ?></option>
				</select>
			</div>

			<div class="arl-standings-content" id="arl-standings-content">
				<?php
				// Trust boundary: $initial_html is the rendered output of
				// SportsPress's own [standings]/table shortcode via do_shortcode().
				// SportsPress is responsible for escaping its own markup; the input
				// is built from server-side term/season IDs (not request data), so
				// this is trusted HTML and must NOT be re-escaped (esc_html would
				// mangle the table). Do not echo arbitrary/user HTML through here.
				echo $initial_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted SportsPress shortcode output, see note above
				?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler: return rendered standings HTML.
	 */
	public function ajax_get_standings() {
		check_ajax_referer( 'spem_standings_nonce', '_ajax_nonce' );

		$season = sanitize_title( wp_unslash( $_POST['season'] ?? '' ) );
		$type   = sanitize_text_field( wp_unslash( $_POST['type'] ?? 'regular' ) );
		$type   = in_array( $type, array( 'regular', 'playoff' ), true ) ? $type : 'regular';

		if ( ! $season ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'sportspress-events-manager' ) ) );
		}

		// Validate that the season slug refers to a real sp_season term BEFORE
		// we touch the transient cache. Otherwise an attacker can pollute the
		// cache with arbitrary keys via crafted slugs.
		if ( false === get_term_by( 'slug', $season, 'sp_season' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'sportspress-events-manager' ) ) );
		}

		$html = $this->get_cached_standings_html( $season, $type );

		wp_send_json_success(
			array(
				'html'   => $html,
				'season' => $season,
				'type'   => $type,
			)
		);
	}

	/**
	 * Rendered standings HTML for a season+type, via the transient cache.
	 *
	 * Callers reached from a request parameter MUST validate the season slug
	 * against a real sp_season term first (see ajax_get_standings) — otherwise
	 * crafted slugs can fill the cache with arbitrary keys. The shortcode path
	 * passes an author-authored attribute or a server-resolved slug.
	 *
	 * @param string $season Season slug.
	 * @param string $type   'regular' or 'playoff'.
	 * @return string HTML.
	 */
	private function get_cached_standings_html( $season, $type ) {
		$cache_key = $this->build_cache_key( $season, $type );
		$html      = get_transient( $cache_key );

		if ( false === $html ) {
			$html = $this->get_standings_html( $season, $type );
			set_transient( $cache_key, $html, self::STANDINGS_CACHE_TTL );
		}

		return $html;
	}

	/**
	 * Get rendered HTML for standings tables matching a season and type.
	 *
	 * @param string $base_slug Base season slug (e.g., 'w2025-26').
	 * @param string $type      'regular' or 'playoff'.
	 * @return string HTML.
	 */
	private function get_standings_html( $base_slug, $type ) {
		$season_slug = $base_slug;
		if ( 'playoff' === $type ) {
			// Find the matching playoffs term.
			$season_slug = $this->find_playoff_slug( $base_slug );
			if ( ! $season_slug ) {
				return '<p class="arl-standings-empty">' . esc_html__( 'No playoff standings available for this season.', 'sportspress-events-manager' ) . '</p>';
			}
		}

		$term = get_term_by( 'slug', $season_slug, 'sp_season' );
		if ( ! $term ) {
			return '<p class="arl-standings-empty">' . esc_html__( 'Season not found.', 'sportspress-events-manager' ) . '</p>';
		}

		$tables = get_posts(
			array(
				'post_type'      => 'sp_table',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'sp_season',
						'field'            => 'term_id',
						'terms'            => $term->term_id,
						'include_children' => false,
					),
				),
			)
		);

		if ( empty( $tables ) ) {
			return '<p class="arl-standings-empty">' . esc_html__( 'Standings not yet available for this season.', 'sportspress-events-manager' ) . '</p>';
		}

		$html = '<div class="arl-standings-tables">';
		foreach ( $tables as $table ) {
			$html .= do_shortcode( '[team_standings ' . absint( $table->ID ) . ']' );
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Get seasons grouped by base name, newest first.
	 *
	 * Groups "W2025-26" and "W2025-26 Playoffs" under one entry.
	 * Only includes seasons that have at least one sp_table post.
	 *
	 * @return array [ { slug, label } ]
	 */
	private function get_grouped_seasons() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'DESC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		// Batch: get all season term IDs that have at least one published sp_table.
		$all_term_ids       = wp_list_pluck( $terms, 'term_id' );
		$terms_with_tables  = $this->get_terms_with_tables( $all_term_ids );

		// Build a map of base-slug → playoff term for quick lookup.
		$playoff_map = array(); // base_slug => playoff_slug.
		$terms_by_slug = array();
		foreach ( $terms as $term ) {
			$terms_by_slug[ $term->slug ] = $term;
			if ( stripos( $term->name, 'Playoff' ) !== false ) {
				$base = $this->base_slug( $term->slug );
				if ( $base && $base !== $term->slug ) {
					$playoff_map[ $base ] = $term->slug;
				}
			}
		}

		// Collect base season slugs (skip playoff-only entries).
		$seen    = array();
		$seasons = array();

		foreach ( $terms as $term ) {
			if ( stripos( $term->name, 'Playoff' ) !== false ) {
				continue;
			}

			$slug = $term->slug;
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;

			// Check regular season tables.
			$has_tables = in_array( $term->term_id, $terms_with_tables, true );

			// Check playoff counterpart tables.
			if ( ! $has_tables && isset( $playoff_map[ $slug ], $terms_by_slug[ $playoff_map[ $slug ] ] ) ) {
				$playoff_term = $terms_by_slug[ $playoff_map[ $slug ] ];
				$has_tables   = in_array( $playoff_term->term_id, $terms_with_tables, true );
			}

			if ( $has_tables ) {
				$seasons[] = array(
					'slug'  => $slug,
					'label' => $term->name,
				);
			}
		}

		return $seasons;
	}

	/**
	 * Get term IDs from a list that have at least one published sp_table.
	 *
	 * Uses a single query instead of one per term.
	 *
	 * @param int[] $term_ids Array of term IDs to check.
	 * @return int[] Term IDs that have tables.
	 */
	private function get_terms_with_tables( $term_ids ) {
		if ( empty( $term_ids ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- dynamic placeholder count.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tt.term_id
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
				WHERE tt.taxonomy = 'sp_season'
				AND tt.term_id IN ({$placeholders})
				AND p.post_type = 'sp_table'
				AND p.post_status = 'publish'",
				...$term_ids
			)
		);

		return array_map( 'absint', $results );
	}

	/**
	 * Find the playoff season slug for a base season slug.
	 *
	 * Tries common patterns: "{slug}-playoffs", then name-based matching.
	 *
	 * @param string $base_slug Base season slug.
	 * @return string Playoff slug, or empty string if not found.
	 */
	private function find_playoff_slug( $base_slug ) {
		// Pattern 1: base-slug-playoffs (e.g., w2024-25-playoffs).
		$try  = $base_slug . '-playoffs';
		$term = get_term_by( 'slug', $try, 'sp_season' );
		if ( $term ) {
			return $try;
		}

		// Pattern 2: Search by name containing "Playoffs" and matching base.
		$base_term = get_term_by( 'slug', $base_slug, 'sp_season' );
		if ( ! $base_term ) {
			return '';
		}

		$all = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
				'search'     => 'Playoffs',
			)
		);

		if ( is_wp_error( $all ) ) {
			return '';
		}

		$base_name = $base_term->name;
		foreach ( $all as $t ) {
			if ( stripos( $t->name, $base_name ) === 0 && stripos( $t->name, 'Playoff' ) !== false ) {
				return $t->slug;
			}
		}

		return '';
	}

	/**
	 * Get the base slug from a season slug (strip playoff suffix).
	 */
	private function base_slug( $slug ) {
		return preg_replace( '/-?playoffs?$/i', '', $slug );
	}
}
