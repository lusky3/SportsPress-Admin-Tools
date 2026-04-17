<?php
/**
 * Admin Page Renderer
 *
 * Renders all League Manager admin page HTML with clean, card-based UI.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPLM_Admin_Renderer {

	/** @var array Enabled module IDs */
	private $enabled_modules;

	/**
	 * @param array $enabled_modules List of enabled SPLM module IDs.
	 */
	public function __construct( array $enabled_modules = array() ) {
		$this->enabled_modules = $enabled_modules;
	}

	/**
	 * Whether a specific module is active.
	 */
	private function is_module_enabled( string $module_id ): bool {
		return in_array( $module_id, $this->enabled_modules, true );
	}

	/**
	 * Get current user's saved league/season filter preference.
	 */
	private function get_user_filter( string $key, $default = 0 ) {
		return get_user_meta( get_current_user_id(), 'splm_preferred_' . $key, true ) ?: $default;
	}

	// =========================================================================
	// Dashboard
	// =========================================================================

	/**
	 * Render the main League Manager dashboard page.
	 */
	public function render_dashboard() {
		$user_id    = get_current_user_id();
		$leagues    = SPLM_SportsPress_Data::get_leagues();
		$seasons    = SPLM_SportsPress_Data::get_seasons();
		if ( is_wp_error( $leagues ) ) {
			$leagues = array(); }
		if ( is_wp_error( $seasons ) ) {
			$seasons = array(); }
		$league_id  = $this->get_user_filter( 'league' );
		$season_id  = $this->get_user_filter( 'season' );

		$filters = array_filter(
			array(
				'league_id' => absint( $league_id ),
				'season_id' => absint( $season_id ),
			)
		);

		$teams       = SPLM_SportsPress_Data::get_teams( $filters );
		$team_count  = count( $teams );

		$count_obj    = wp_count_posts( 'sp_player' );
		$player_count = $count_obj->publish;

		$event_args = array(
			'post_type'      => 'sp_event',
			'posts_per_page' => 5,
			'post_status'    => 'future',
			'orderby'        => 'date',
			'order'          => 'ASC',
		);
		if ( ! empty( $filters['league_id'] ) ) {
			$event_args['tax_query'][] = array(
				'taxonomy' => 'sp_league',
				'field'    => 'term_id',
				'terms'    => $filters['league_id'],
			);
		}
		if ( ! empty( $filters['season_id'] ) ) {
			$event_args['tax_query'][] = array(
				'taxonomy' => 'sp_season',
				'field'    => 'term_id',
				'terms'    => $filters['season_id'],
			);
		}
		$upcoming = get_posts( $event_args );

		$wizard_done = get_user_meta( $user_id, 'splm_wizard_completed', true );
		?>
		<div class="wrap splm-wrap">
			<h1 class="splm-page-title">
				<?php esc_html_e( 'League Manager Dashboard', 'sportspress-league-manager' ); ?>
				<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Overview of your league data. Use the filters to narrow by league and season.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Overview of your league data. Use the filters to narrow by league and season.', 'sportspress-league-manager' ); ?>">?</span>
			</h1>

			<?php if ( ! $wizard_done ) : ?>
			<div class="splm-wizard notice notice-info is-dismissible" id="splm-first-run-wizard">
				<h3><?php esc_html_e( 'Welcome to League Manager!', 'sportspress-league-manager' ); ?></h3>
				<ol class="splm-wizard-steps">
					<li><?php esc_html_e( 'Select your league and season using the filters below.', 'sportspress-league-manager' ); ?></li>
					<li><?php esc_html_e( 'Verify your teams are configured in SportsPress.', 'sportspress-league-manager' ); ?></li>
					<li><?php esc_html_e( 'Run the Health Check to confirm everything is set up correctly.', 'sportspress-league-manager' ); ?></li>
				</ol>
				<button type="button" class="button button-primary" id="splm-dismiss-wizard"><?php esc_html_e( 'Got it — dismiss', 'sportspress-league-manager' ); ?></button>
			</div>
			<?php endif; ?>

			<!-- League / Season Filters -->
			<div class="splm-filters">
				<label for="splm-filter-league">
					<?php esc_html_e( 'League', 'sportspress-league-manager' ); ?>
					<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Select the league to filter all dashboard data.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Select the league to filter all dashboard data.', 'sportspress-league-manager' ); ?>">?</span>
				</label>
				<select id="splm-filter-league" class="splm-select">
					<option value=""><?php esc_html_e( 'All Leagues', 'sportspress-league-manager' ); ?></option>
					<?php foreach ( $leagues as $league ) : ?>
						<option value="<?php echo esc_attr( $league->term_id ); ?>" <?php selected( $league_id, $league->term_id ); ?>>
							<?php echo esc_html( $league->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="splm-filter-season">
					<?php esc_html_e( 'Season', 'sportspress-league-manager' ); ?>
					<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Select the season to filter all dashboard data.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Select the season to filter all dashboard data.', 'sportspress-league-manager' ); ?>">?</span>
				</label>
				<select id="splm-filter-season" class="splm-select">
					<option value=""><?php esc_html_e( 'All Seasons', 'sportspress-league-manager' ); ?></option>
					<?php foreach ( $seasons as $season ) : ?>
						<option value="<?php echo esc_attr( $season->term_id ); ?>" <?php selected( $season_id, $season->term_id ); ?>>
							<?php echo esc_html( $season->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<button type="button" class="button" id="splm-apply-filters"><?php esc_html_e( 'Apply', 'sportspress-league-manager' ); ?></button>
			</div>

			<!-- Card Grid -->
			<div class="splm-card-grid">

				<!-- Teams Card -->
				<div class="splm-card" id="splm-teams-wrap">
					<h3 class="splm-card-title">
						<span class="dashicons dashicons-groups"></span>
						<?php esc_html_e( 'Teams', 'sportspress-league-manager' ); ?>
					</h3>
					<div class="splm-card-value" id="splm-teams-count"><?php echo esc_html( $team_count ); ?></div>
					<p class="splm-card-desc"><?php esc_html_e( 'Teams in selected league', 'sportspress-league-manager' ); ?></p>
					<div class="splm-card-scroll" id="splm-teams-data"></div>
					<?php if ( $this->is_module_enabled( 'league_roster_management' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=splm-rosters' ) ); ?>" class="splm-card-link">
							<?php esc_html_e( 'Manage Rosters →', 'sportspress-league-manager' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<!-- Players Card -->
				<div class="splm-card">
					<h3 class="splm-card-title">
						<span class="dashicons dashicons-id"></span>
						<?php esc_html_e( 'Players', 'sportspress-league-manager' ); ?>
					</h3>
					<div class="splm-card-value" id="splm-player-count"><?php echo esc_html( $player_count ); ?></div>
					<p class="splm-card-desc"><?php esc_html_e( 'Total registered players', 'sportspress-league-manager' ); ?></p>
				</div>

				<!-- Upcoming Games Card -->
				<div class="splm-card splm-card-wide">
					<h3 class="splm-card-title">
						<span class="dashicons dashicons-calendar-alt"></span>
						<?php esc_html_e( 'Upcoming Games', 'sportspress-league-manager' ); ?>
						<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Next 5 scheduled events matching your filters.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Next 5 scheduled events matching your filters.', 'sportspress-league-manager' ); ?>">?</span>
					</h3>
					<?php if ( ! empty( $upcoming ) ) : ?>
						<ul class="splm-upcoming-list">
							<?php foreach ( $upcoming as $event ) : ?>
								<li>
									<span class="splm-event-date"><?php echo esc_html( get_the_date( 'M j, Y g:i A', $event ) ); ?></span>
									<span class="splm-event-title"><?php echo esc_html( get_the_title( $event ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="splm-card-empty"><?php esc_html_e( 'No upcoming games found.', 'sportspress-league-manager' ); ?></p>
					<?php endif; ?>
				</div>

				<?php $this->render_dashboard_fee_card(); ?>

				<!-- Health Status Card -->
				<div class="splm-card" id="splm-health-card">
					<h3 class="splm-card-title">
						<span class="dashicons dashicons-heart"></span>
						<?php esc_html_e( 'Health Status', 'sportspress-league-manager' ); ?>
						<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Validates your SportsPress configuration and surfaces issues.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Validates your SportsPress configuration and surfaces issues.', 'sportspress-league-manager' ); ?>">?</span>
					</h3>
					<div class="splm-health-results" id="splm-health-results">
						<p class="splm-card-empty"><?php esc_html_e( 'Click below to run a health check.', 'sportspress-league-manager' ); ?></p>
					</div>
					<button type="button" class="button" id="splm-run-health-check"><?php esc_html_e( 'Run Health Check', 'sportspress-league-manager' ); ?></button>
				</div>

			</div><!-- .splm-card-grid -->
		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Render the fee summary card (only if fee tracking module is enabled).
	 */
	private function render_dashboard_fee_card() {
		if ( ! $this->is_module_enabled( 'league_fee_tracking' ) ) {
			return;
		}
		?>
		<div class="splm-card">
			<h3 class="splm-card-title">
				<span class="dashicons dashicons-money-alt"></span>
				<?php esc_html_e( 'Fee Summary', 'sportspress-league-manager' ); ?>
				<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Overview of player fee payment status from WooCommerce orders.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Overview of player fee payment status from WooCommerce orders.', 'sportspress-league-manager' ); ?>">?</span>
			</h3>
			<div class="splm-fee-summary" id="splm-fee-summary">
				<div class="splm-fee-stat">
					<span class="splm-badge splm-badge-success" id="splm-fees-paid">0</span>
					<?php esc_html_e( 'Paid', 'sportspress-league-manager' ); ?>
				</div>
				<div class="splm-fee-stat">
					<span class="splm-badge splm-badge-danger" id="splm-fees-unpaid">0</span>
					<?php esc_html_e( 'Unpaid', 'sportspress-league-manager' ); ?>
				</div>
				<div class="splm-fee-stat">
					<span class="splm-badge splm-badge-muted" id="splm-fees-total">0</span>
					<?php esc_html_e( 'Total', 'sportspress-league-manager' ); ?>
				</div>
			</div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=splm-fees' ) ); ?>" class="splm-card-link">
				<?php esc_html_e( 'View Fee Details →', 'sportspress-league-manager' ); ?>
			</a>
		</div>
		<?php
	}

	// =========================================================================
	// Rosters
	// =========================================================================

	/**
	 * Render the Teams & Rosters management page.
	 */
	public function render_rosters() {
		$teams  = SPLM_SportsPress_Data::get_teams();
		$max_kb = absint( get_option( 'splm_roster_max_upload_kb', 512 ) );
		?>
		<div class="wrap splm-wrap">
			<h1 class="splm-page-title">
				<?php esc_html_e( 'Teams & Rosters', 'sportspress-league-manager' ); ?>
				<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'View team rosters and upload roster changes via CSV.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'View team rosters and upload roster changes via CSV.', 'sportspress-league-manager' ); ?>">?</span>
			</h1>

			<!-- Team Selector -->
			<div class="splm-section">
				<label for="splm-team-selector">
					<?php esc_html_e( 'Select Team', 'sportspress-league-manager' ); ?>
					<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Choose a team to view its current roster.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Choose a team to view its current roster.', 'sportspress-league-manager' ); ?>">?</span>
				</label>
				<select id="splm-team-selector" class="splm-select">
					<option value=""><?php esc_html_e( '— Select a team —', 'sportspress-league-manager' ); ?></option>
					<?php foreach ( $teams as $team ) : ?>
						<option value="<?php echo esc_attr( $team->ID ); ?>">
							<?php echo esc_html( get_the_title( $team ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Current Roster Table -->
			<div class="splm-section" id="splm-roster-section" style="display:none;">
				<h2 class="splm-section-title"><?php esc_html_e( 'Current Roster', 'sportspress-league-manager' ); ?></h2>
				<table class="widefat striped splm-table" id="splm-roster-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Player Name', 'sportspress-league-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Number', 'sportspress-league-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Position', 'sportspress-league-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Email', 'sportspress-league-manager' ); ?></th>
						</tr>
					</thead>
					<tbody id="splm-roster-body">
						<tr class="splm-loading-row">
							<td colspan="4"><?php esc_html_e( 'Select a team to load its roster.', 'sportspress-league-manager' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- CSV Upload -->
			<div class="splm-section" id="splm-upload-section">
				<h2 class="splm-section-title">
					<?php esc_html_e( 'Upload Roster CSV', 'sportspress-league-manager' ); ?>
					<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Upload a CSV file to add or update players on the selected team.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Upload a CSV file to add or update players on the selected team.', 'sportspress-league-manager' ); ?>">?</span>
				</h2>

				<div class="splm-help-text">
					<details>
						<summary><?php esc_html_e( 'CSV Format Instructions', 'sportspress-league-manager' ); ?></summary>
						<div class="splm-help-content">
							<p><?php esc_html_e( 'Your CSV file should contain the following columns:', 'sportspress-league-manager' ); ?></p>
							<pre>Name,Number,Position,Email
John Doe,12,Forward,john@example.com
Jane Smith,7,Midfielder,jane@example.com</pre>
							<p>
							<?php
							printf(
								/* translators: %d: max file size in KB */
								esc_html__( 'Maximum file size: %d KB. Only .csv files are accepted.', 'sportspress-league-manager' ),
								$max_kb
							);
							?>
							</p>
						</div>
					</details>
				</div>

				<div class="splm-dropzone" id="splm-csv-dropzone">
					<div class="splm-dropzone-inner">
						<span class="dashicons dashicons-upload"></span>
						<p><?php esc_html_e( 'Drag & drop a CSV file here, or click to browse', 'sportspress-league-manager' ); ?></p>
						<input type="file" id="splm-csv-file" accept=".csv" class="splm-file-input" />
					</div>
					<p class="splm-dropzone-filename" id="splm-csv-filename"></p>
				</div>

				<!-- Preview Step -->
				<div class="splm-preview" id="splm-roster-preview" style="display:none;">
					<h3><?php esc_html_e( 'Preview Roster Changes', 'sportspress-league-manager' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Review the data below before committing changes.', 'sportspress-league-manager' ); ?></p>
					<table class="widefat striped splm-table" id="splm-preview-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Player Name', 'sportspress-league-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Number', 'sportspress-league-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Position', 'sportspress-league-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Email', 'sportspress-league-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'sportspress-league-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="splm-preview-body"></tbody>
					</table>
					<div class="splm-preview-actions">
						<button type="button" class="button button-primary" id="splm-confirm-upload"><?php esc_html_e( 'Confirm & Upload', 'sportspress-league-manager' ); ?></button>
						<button type="button" class="button" id="splm-cancel-upload"><?php esc_html_e( 'Cancel', 'sportspress-league-manager' ); ?></button>
					</div>
				</div>
			</div>

			<div id="splm-roster-messages"></div>
		</div><!-- .wrap -->
		<?php
	}

	// =========================================================================
	// Fees
	// =========================================================================

	/**
	 * Render the Fee Status page.
	 */
	public function render_fees() {
		$fee_source = get_option( 'splm_fee_source', 'none' );
		?>
		<div class="wrap splm-wrap">
			<h1 class="splm-page-title">
				<?php esc_html_e( 'Fee Status', 'sportspress-league-manager' ); ?>
				<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Look up player and team fee payment status linked to WooCommerce orders.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Look up player and team fee payment status linked to WooCommerce orders.', 'sportspress-league-manager' ); ?>">?</span>
			</h1>

			<?php if ( $fee_source === 'woocommerce' && ! class_exists( 'WooCommerce' ) ) : ?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Fee tracking is configured to use WooCommerce, but WooCommerce is not active. Fee data will be unavailable until WooCommerce is installed and activated.', 'sportspress-league-manager' ); ?></p>
			</div>
			<?php elseif ( $fee_source === 'none' ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Fee tracking is not configured. Ask an administrator to set a fee source in Settings → SportsPress Admin Tools → League Manager.', 'sportspress-league-manager' ); ?></p>
			</div>
			<?php endif; ?>

			<!-- Help Text -->
			<div class="splm-help-text">
				<details>
					<summary><?php esc_html_e( 'How fee tracking works', 'sportspress-league-manager' ); ?></summary>
					<div class="splm-help-content">
						<p><?php esc_html_e( 'Fee status is pulled from WooCommerce orders. When a player or parent purchases a registration product, the order status determines the fee status:', 'sportspress-league-manager' ); ?></p>
						<ul>
							<li><span class="splm-badge splm-badge-success"><?php esc_html_e( 'Paid', 'sportspress-league-manager' ); ?></span> — <?php esc_html_e( 'Order is completed or processing.', 'sportspress-league-manager' ); ?></li>
							<li><span class="splm-badge splm-badge-warning"><?php esc_html_e( 'On Hold', 'sportspress-league-manager' ); ?></span> — <?php esc_html_e( 'Order is on-hold (e.g., awaiting e-transfer).', 'sportspress-league-manager' ); ?></li>
							<li><span class="splm-badge splm-badge-danger"><?php esc_html_e( 'Unpaid', 'sportspress-league-manager' ); ?></span> — <?php esc_html_e( 'No matching order found, or order is pending/failed.', 'sportspress-league-manager' ); ?></li>
						</ul>
						<p><?php esc_html_e( 'Configure the fee integration source in Settings → SportsPress Admin Tools → League Manager.', 'sportspress-league-manager' ); ?></p>
					</div>
				</details>
			</div>

			<!-- Search -->
			<div class="splm-section splm-search-bar">
				<label for="splm-fee-search" class="screen-reader-text"><?php esc_html_e( 'Search by team or player name', 'sportspress-league-manager' ); ?></label>
				<input type="search" id="splm-fee-search" class="splm-search-input" placeholder="<?php esc_attr_e( 'Search by team or player name…', 'sportspress-league-manager' ); ?>" />
				<button type="button" class="button" id="splm-fee-search-btn"><?php esc_html_e( 'Search', 'sportspress-league-manager' ); ?></button>
				<button type="button" class="button" id="splm-fee-export-csv">
					<span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
					<?php esc_html_e( 'Export CSV', 'sportspress-league-manager' ); ?>
				</button>
			</div>

			<!-- Fee Table -->
			<div class="splm-section" id="splm-fees-wrap">
				<table class="widefat striped splm-table" id="splm-fee-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Player Name', 'sportspress-league-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Team', 'sportspress-league-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Product', 'sportspress-league-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Amount', 'sportspress-league-manager' ); ?></th>
							<th scope="col">
								<?php esc_html_e( 'Status', 'sportspress-league-manager' ); ?>
								<span class="splm-tooltip" tabindex="0" data-tip="<?php esc_attr_e( 'Payment status based on the linked WooCommerce order.', 'sportspress-league-manager' ); ?>" aria-label="<?php esc_attr_e( 'Payment status based on the linked WooCommerce order.', 'sportspress-league-manager' ); ?>">?</span>
							</th>
							<th scope="col"><?php esc_html_e( 'Order Date', 'sportspress-league-manager' ); ?></th>
						</tr>
					</thead>
					<tbody id="splm-fee-body">
						<tr class="splm-loading-row">
							<td colspan="6"><?php esc_html_e( 'Enter a search term or click Search to load fee data.', 'sportspress-league-manager' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<div id="splm-fee-messages"></div>
		</div><!-- .wrap -->
		<?php
	}
}