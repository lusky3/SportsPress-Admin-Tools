<?php
/**
 * The infraction list editor, on the SPAT settings page.
 *
 * A "Discipline Rules" tab beside the notice queue, outside the options form
 * for the same reason (an actionable table nested in a <form action=options.php>
 * would post into the settings save). Each row saves on its own through the
 * infraction REST routes, so the write path, validation and gate live in one
 * place. Infractions are retired (Active off), never deleted, and an edit
 * never rewrites a notice already sent: notices carry their own snapshot.
 *
 * @author Cody (lusky3)
 *
 * One method per rendered region of a single admin table, plus the pure row
 * builders the tests exercise. Collapsing them would rebuild a long render
 * method, which is what they came out of.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Infraction_Admin {

	public function __construct() {
		add_action( 'spat_admin_page_tabs', array( $this, 'add_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_content' ) );
	}

	/**
	 * The nav tab.
	 *
	 * @return void
	 */
	public function add_tab() {
		echo '<a href="#discipline-rules" class="nav-tab">' . esc_html__( 'Discipline Rules', 'sportspress-league-manager' ) . '</a>';
	}

	/**
	 * The tab panel.
	 *
	 * Like the queue, a disabled module makes it read-only rather than blank:
	 * the list is what already-sent notices were cited from.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function add_content() {
		$readonly = ! SPLM_REST_API::module_enabled( 'league_discipline' );

		echo '<div id="discipline-rules" class="tab-content" style="display: none;">';

		if ( $readonly ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The Penalty Discipline module is disabled. The list below is read-only.', 'sportspress-league-manager' ) . '</p></div>';
		}

		$this->render_intro();
		$this->render_table( SPLM_Discipline_Infraction::all( false ), $readonly );

		if ( ! $readonly ) {
			$this->render_script();
		}

		echo '</div>';
	}

	/**
	 * Heading, the revision currently cited, and the editing rules.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function render_intro(): void {
		echo '<h3>' . esc_html__( 'Discipline Rules', 'sportspress-league-manager' ) . '</h3>';
		printf(
			'<p>%s <strong>%s</strong>. <span class="description">%s</span></p>',
			esc_html__( 'Suspension emails currently cite the rulebook revision', 'sportspress-league-manager' ),
			esc_html( SPLM_Discipline_Suspension_Context::rulebook_rev() ),
			esc_html__( 'Change the revision and link on the League Manager tab.', 'sportspress-league-manager' )
		);
		echo '<p class="description">' . esc_html__( 'Editing a rule never rewrites a notice that was already sent: each notice keeps the wording it was sent with. Infractions are retired, not deleted: clear Active to stop offering one.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * The editable table.
	 *
	 * @param object[] $infractions Every infraction, active and retired.
	 * @param bool     $readonly    Whether to disable the controls.
	 * @return void
	 */
	private function render_table( array $infractions, bool $readonly ): void {
		$headings = array(
			__( 'Rule', 'sportspress-league-manager' ),
			__( 'Title', 'sportspress-league-manager' ),
			__( 'Rulebook wording', 'sportspress-league-manager' ),
			__( 'Outcome', 'sportspress-league-manager' ),
			__( 'Default games', 'sportspress-league-manager' ),
			__( 'Needs review', 'sportspress-league-manager' ),
			__( 'Active', 'sportspress-league-manager' ),
			__( 'Sort', 'sportspress-league-manager' ),
			__( 'Action', 'sportspress-league-manager' ),
		);

		echo '<table class="widefat striped" id="splm-infraction-table"><thead><tr>';
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- the row builders escape every value.
		foreach ( $infractions as $infraction ) {
			echo self::render_row_html( (array) $infraction, $readonly );
		}
		if ( ! $readonly ) {
			echo self::blank_row_html();
		}
		echo '</tbody></table>';

		if ( ! $readonly ) {
			// The clone source for a freshly added row. Inert until scripted.
			echo '<template id="splm-infraction-template"><table><tbody>' . self::render_row_html( array( 'title' => '' ) ) . '</tbody></table></template>';
		}
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * One <td> holding a screen-reader label and its control.
	 *
	 * @param string|int $key     Row key (an id, or 'new').
	 * @param string     $field   Field name.
	 * @param string     $label   Label text for assistive technology.
	 * @param string     $control Already-escaped control markup carrying the matching id.
	 * @return string
	 */
	private static function cell( $key, string $field, string $label, string $control ): string {
		return sprintf(
			'<td><label class="screen-reader-text" for="%1$s">%2$s</label>%3$s</td>',
			esc_attr( self::dom_id( $key, $field ) ),
			esc_html( $label ),
			$control
		);
	}

	/**
	 * The element id for a row's field.
	 *
	 * @param string|int $key   Row key.
	 * @param string     $field Field name.
	 * @return string
	 */
	private static function dom_id( $key, string $field ): string {
		return 'splm-inf-' . $key . '-' . $field;
	}

	/**
	 * A text or number <input>.
	 *
	 * @param string|int $key   Row key.
	 * @param string     $field Field name (also the data-field the script reads).
	 * @param string     $type  text|number.
	 * @param mixed      $value Current value.
	 * @param string     $extra Extra attributes, already escaped.
	 * @return string
	 */
	private static function input( $key, string $field, string $type, $value, string $extra ): string {
		return sprintf(
			'<input type="%1$s" id="%2$s" data-field="%3$s" value="%4$s" %5$s/>',
			esc_attr( $type ),
			esc_attr( self::dom_id( $key, $field ) ),
			esc_attr( $field ),
			esc_attr( (string) $value ),
			$extra
		);
	}

	/**
	 * A checkbox.
	 *
	 * @param string|int $key     Row key.
	 * @param string     $field   Field name.
	 * @param bool       $checked Whether checked.
	 * @param string     $extra   Extra attributes, already escaped.
	 * @return string
	 */
	private static function checkbox( $key, string $field, bool $checked, string $extra ): string {
		return sprintf(
			'<input type="checkbox" id="%1$s" data-field="%2$s" value="1" %3$s %4$s/>',
			esc_attr( self::dom_id( $key, $field ) ),
			esc_attr( $field ),
			$checked ? 'checked' : '',
			$extra
		);
	}

	/**
	 * The outcome <select>.
	 *
	 * @param string|int $key      Row key.
	 * @param string     $outcome  games|indefinite.
	 * @param string     $disabled 'disabled' or ''.
	 * @return string
	 */
	private static function outcome_select( $key, string $outcome, string $disabled ): string {
		return sprintf(
			'<select id="%1$s" data-field="outcome" %2$s><option value="games"%3$s>%4$s</option><option value="indefinite"%5$s>%6$s</option></select>',
			esc_attr( self::dom_id( $key, 'outcome' ) ),
			$disabled,
			'games' === $outcome ? ' selected' : '',
			esc_html__( 'Games', 'sportspress-league-manager' ),
			'indefinite' === $outcome ? ' selected' : '',
			esc_html__( 'Indefinite', 'sportspress-league-manager' )
		);
	}

	/**
	 * The rulebook wording <textarea>.
	 *
	 * @param string|int $key      Row key.
	 * @param string     $text     Current wording.
	 * @param string     $disabled 'disabled' or ''.
	 * @return string
	 */
	private static function wording_textarea( $key, string $text, string $disabled ): string {
		return sprintf(
			'<textarea id="%1$s" data-field="rule_text" rows="3" class="large-text" %2$s>%3$s</textarea>',
			esc_attr( self::dom_id( $key, 'rule_text' ) ),
			$disabled,
			esc_textarea( $text )
		);
	}

	/**
	 * The field cells shared by an existing row and the add row.
	 *
	 * @param array      $inf      Infraction fields.
	 * @param string|int $key      Row key.
	 * @param string     $disabled 'disabled' or ''.
	 * @return string
	 */
	private static function field_cells( array $inf, $key, string $disabled ): string {
		$outcome  = 'indefinite' === ( $inf['outcome'] ?? 'games' ) ? 'indefinite' : 'games';
		$games_ro = 'indefinite' === $outcome ? 'disabled' : $disabled;
		$ref      = self::input( $key, 'rule_ref', 'text', $inf['rule_ref'] ?? '', 'maxlength="20" size="6" ' . $disabled );
		$title    = self::input( $key, 'title', 'text', $inf['title'] ?? '', 'maxlength="120" class="regular-text" ' . $disabled );
		$games    = self::input( $key, 'default_games', 'number', (int) ( $inf['default_games'] ?? 0 ), 'min="0" max="20" class="small-text" ' . $games_ro );

		return self::cell( $key, 'rule_ref', __( 'Rule', 'sportspress-league-manager' ), $ref )
			. self::cell( $key, 'title', __( 'Title', 'sportspress-league-manager' ), $title )
			. self::cell( $key, 'rule_text', __( 'Rulebook wording', 'sportspress-league-manager' ), self::wording_textarea( $key, (string) ( $inf['rule_text'] ?? '' ), $disabled ) )
			. self::cell( $key, 'outcome', __( 'Outcome', 'sportspress-league-manager' ), self::outcome_select( $key, $outcome, $disabled ) )
			. self::cell( $key, 'default_games', __( 'Default games', 'sportspress-league-manager' ), $games )
			. self::cell( $key, 'needs_review', __( 'Needs review', 'sportspress-league-manager' ), self::checkbox( $key, 'needs_review', ! empty( $inf['needs_review'] ), $disabled ) );
	}

	/**
	 * One existing infraction as a table row. Pure: no queries.
	 *
	 * @param array $infraction Infraction fields (an object cast to array also works).
	 * @param bool  $readonly   Disable the controls and drop the Save button.
	 * @return string Escaped HTML.
	 */
	public static function render_row_html( array $infraction, bool $readonly = false ): string {
		$id       = (int) ( $infraction['id'] ?? 0 );
		$active   = ! array_key_exists( 'active', $infraction ) || ! empty( $infraction['active'] );
		$disabled = $readonly ? 'disabled' : '';

		// Retired is stated in words, never by colour alone. The script fills
		// the tag from data-label when a Save flips Active.
		$retired = sprintf(
			'<span class="splm-inf-retired description" data-label="%1$s">%2$s</span>',
			esc_attr__( 'Retired', 'sportspress-league-manager' ),
			$active ? '' : esc_html__( 'Retired', 'sportspress-league-manager' )
		);

		$active_cell = self::cell( $id, 'active', __( 'Active', 'sportspress-league-manager' ), self::checkbox( $id, 'active', $active, $disabled ) . ' ' . $retired );
		$sort        = self::input( $id, 'sort_order', 'number', (int) ( $infraction['sort_order'] ?? 0 ), 'min="-32768" max="32767" class="small-text" ' . $disabled );

		return sprintf(
			'<tr class="splm-inf-row" data-id="%1$d">%2$s%3$s%4$s<td>%5$s</td></tr>',
			$id,
			self::field_cells( $infraction, $id, $disabled ),
			$active_cell,
			self::cell( $id, 'sort_order', __( 'Sort', 'sportspress-league-manager' ), $sort ),
			$readonly ? '' : self::action_html( 'save', __( 'Save', 'sportspress-league-manager' ) )
		);
	}

	/**
	 * The Add row at the foot of the table. Pure.
	 *
	 * New infractions are always created active; an empty Sort lets the server
	 * place the row at the end.
	 *
	 * @return string Escaped HTML.
	 */
	public static function blank_row_html(): string {
		$sort = self::input( 'new', 'sort_order', 'number', '', 'min="-32768" max="32767" class="small-text"' );

		return sprintf(
			'<tr class="splm-inf-row splm-inf-new" data-id="new">%1$s<td><span class="description">%2$s</span></td>%3$s<td>%4$s</td></tr>',
			self::field_cells( array(), 'new', '' ),
			esc_html__( 'Added as active', 'sportspress-league-manager' ),
			self::cell( 'new', 'sort_order', __( 'Sort', 'sportspress-league-manager' ), $sort ),
			self::action_html( 'add', __( 'Add infraction', 'sportspress-league-manager' ) )
		);
	}

	/**
	 * The row's button plus its status and error regions.
	 *
	 * @param string $action save|add.
	 * @param string $label  Button text.
	 * @return string
	 */
	private static function action_html( string $action, string $label ): string {
		return sprintf(
			'<button type="button" class="button splm-inf-%1$s">%2$s</button> <span class="splm-inf-status" role="status"></span><span class="splm-inf-error" role="alert"></span>',
			esc_attr( $action ),
			esc_html( $label )
		);
	}

	/**
	 * The action layer.
	 *
	 * Calls the infraction REST routes with the REST nonce, as the queue tab
	 * does. Plain fetch rather than wp.apiFetch: that script is not loaded on
	 * the SPAT settings page, and the queue tab already sets the precedent.
	 *
	 * @return void
	 */
	private function render_script(): void {
		$nonce = wp_create_nonce( 'wp_rest' );
		$base  = rest_url( 'splm/v1/discipline/infractions' );
		?>
		<script>
		( function () {
			var base = <?php echo wp_json_encode( $base ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var table = document.getElementById( 'splm-infraction-table' );
			var template = document.getElementById( 'splm-infraction-template' );
			var messages = {
				saved: <?php echo wp_json_encode( __( 'Saved.', 'sportspress-league-manager' ) ); ?>,
				added: <?php echo wp_json_encode( __( 'Added.', 'sportspress-league-manager' ) ); ?>,
				failed: <?php echo wp_json_encode( __( 'The change could not be saved.', 'sportspress-league-manager' ) ); ?>
			};

			<?php
			$this->script_row_js();
			$this->script_request_js();
			$this->script_events_js();
			?>
		}() );
		</script>
		<?php
	}

	/**
	 * Script part: reading a row into a payload and writing a response back.
	 *
	 * @return void
	 */
	private function script_row_js(): void {
		?>
			function field( row, name ) {
				return row.querySelector( '[data-field="' + name + '"]' );
			}

			function payload( row, isAdd ) {
				var indefinite = field( row, 'outcome' ).value === 'indefinite';
				var data = {
					rule_ref: field( row, 'rule_ref' ).value,
					title: field( row, 'title' ).value,
					rule_text: field( row, 'rule_text' ).value,
					outcome: field( row, 'outcome' ).value,
					default_games: indefinite ? 0 : parseInt( field( row, 'default_games' ).value || '0', 10 ),
					needs_review: field( row, 'needs_review' ).checked
				};
				var sort = field( row, 'sort_order' ).value;
				if ( sort !== '' ) { data.sort_order = parseInt( sort, 10 ); }
				if ( ! isAdd ) { data.active = field( row, 'active' ).checked; }
				return data;
			}

			function fill( row, inf ) {
				field( row, 'rule_ref' ).value = inf.rule_ref;
				field( row, 'title' ).value = inf.title;
				field( row, 'rule_text' ).value = inf.rule_text;
				field( row, 'outcome' ).value = inf.outcome;
				field( row, 'default_games' ).value = inf.default_games;
				field( row, 'default_games' ).disabled = inf.outcome === 'indefinite';
				field( row, 'needs_review' ).checked = inf.needs_review;
				field( row, 'sort_order' ).value = inf.sort_order;
				var active = field( row, 'active' );
				if ( active ) {
					active.checked = inf.active;
					var tag = row.querySelector( '.splm-inf-retired' );
					tag.textContent = inf.active ? '' : tag.getAttribute( 'data-label' );
				}
			}

			function say( row, kind, text ) {
				row.querySelector( '.splm-inf-status' ).textContent = kind === 'ok' ? text : '';
				row.querySelector( '.splm-inf-error' ).textContent = kind === 'err' ? text : '';
			}

		<?php
	}

	/**
	 * Script part: the POST, and cloning a new row from the template.
	 *
	 * @return void
	 */
	private function script_request_js(): void {
		?>
			function send( url, data ) {
				return fetch( url, {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( data )
				} ).then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} );
			}

			// A new row is cloned from the template (rendered with id 0), then
			// re-keyed so its label/for pairs stay unique on the page.
			function appendRow( inf ) {
				var row = template.content.querySelector( 'tr' ).cloneNode( true );
				row.setAttribute( 'data-id', inf.id );
				row.innerHTML = row.innerHTML.split( 'splm-inf-0-' ).join( 'splm-inf-' + inf.id + '-' );
				fill( row, inf );
				table.querySelector( 'tbody' ).insertBefore( row, table.querySelector( '.splm-inf-new' ) );
				return row;
			}

			function resetBlank( row ) {
				fill( row, { rule_ref: '', title: '', rule_text: '', outcome: 'games', default_games: 0, needs_review: false, sort_order: '' } );
				field( row, 'default_games' ).disabled = false;
			}

		<?php
	}

	/**
	 * Script part: submit and the delegated listeners.
	 *
	 * @return void
	 */
	private function script_events_js(): void {
		?>
			function submit( button ) {
				var row = button.closest( 'tr' );
				var isAdd = button.classList.contains( 'splm-inf-add' );
				button.disabled = true;
				say( row, 'ok', '' );
				send( isAdd ? base : base + '/' + row.getAttribute( 'data-id' ), payload( row, isAdd ) )
					.then( function ( result ) {
						button.disabled = false;
						if ( ! result.ok ) {
							say( row, 'err', ( result.body && result.body.message ) || messages.failed );
							return;
						}
						if ( isAdd ) {
							say( appendRow( result.body.infraction ), 'ok', messages.added );
							resetBlank( row );
							return;
						}
						fill( row, result.body.infraction );
						say( row, 'ok', messages.saved );
					} )
					.catch( function () {
						button.disabled = false;
						say( row, 'err', messages.failed );
					} );
			}

			table.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '.splm-inf-save, .splm-inf-add' );
				if ( button ) { submit( button ); }
			} );

			// Default games means nothing for an indefinite outcome.
			table.addEventListener( 'change', function ( event ) {
				if ( event.target.getAttribute( 'data-field' ) === 'outcome' ) {
					field( event.target.closest( 'tr' ), 'default_games' ).disabled = event.target.value === 'indefinite';
				}
			} );
		<?php
	}
}
