import { useState, useMemo } from '@wordpress/element';

/**
 * Graceful degradation notices for unavailable dependency plugins/modules.
 *
 * The `dependencies` map is localized from PHP (splmDashboard.dependencies) and
 * mirrors the exact class_exists guards that gate REST route registration. A
 * feature is only usable when BOTH its capability and its dependency are present;
 * this component explains the dependency-missing case (never the permission case).
 *
 * Behaviour:
 *  - SportsPress core inactive  -> BLOCKING full-dashboard notice (role="alert"),
 *    rendered INSTEAD of the dashboard UI (handled by the caller checking
 *    `blocking`).
 *  - One or more optional modules off -> dismissible top-of-app banner
 *    (role="status") listing each unavailable feature + how to enable it.
 */

// Each entry: the dependency key in splmDashboard.dependencies plus the
// user-facing explanation/enable hint. Order here drives display order.
export const DEPENDENCY_FEATURES = [
	{
		key: 'events_manager',
		message:
			'Score entry is unavailable — the Events Manager module is not enabled. Enable it under Settings → SportsPress Admin Tools.',
	},
	{
		key: 'schedule_generator',
		message:
			'Schedule generation is unavailable — the Schedule Generator module is not enabled. Enable it under Settings → SportsPress Admin Tools.',
	},
	{
		key: 'woocommerce',
		message:
			'Payments/fees are unavailable — WooCommerce is not active. Install and activate WooCommerce to track registration fees.',
	},
	{
		key: 'player_tools',
		message:
			'Rosters and skill ratings are unavailable — the Player Tools plugin is not active. Activate it to manage rosters and player skill.',
	},
];

/**
 * Returns the list of feature entries whose backing dependency is missing.
 * `false` means "registered-but-off"; an absent key is treated as present so a
 * future build that drops a flag fails open rather than nagging.
 */
export function getMissingFeatures( deps ) {
	return DEPENDENCY_FEATURES.filter( ( f ) => deps[ f.key ] === false );
}

export default function DependencyNotice() {
	const deps = window.splmDashboard?.dependencies || {};

	const sportsPressActive = deps.sportspress !== false;
	const missing = useMemo( () => getMissingFeatures( deps ), [ deps ] );

	// sessionStorage flag keyed to the exact set of missing deps, so dismissing
	// one warning set does not permanently hide a different future warning.
	const dismissKey = useMemo(
		() => `splm_dep_notice_dismissed:${ missing.map( ( f ) => f.key ).join( ',' ) }`,
		[ missing ]
	);

	const [ dismissed, setDismissed ] = useState( () => {
		try {
			return sessionStorage.getItem( dismissKey ) === '1';
		} catch {
			return false;
		}
	} );

	// Blocking case: SportsPress core is inactive. The dashboard cannot function.
	if ( ! sportsPressActive ) {
		return (
			<div className="splm-alert splm-alert--error splm-dep-notice splm-dep-notice--blocking" role="alert">
				<div>
					<strong>SportsPress is not active.</strong>{ ' ' }
					The League Manager dashboard requires SportsPress (or SportsPress Pro)
					to be installed and activated.
				</div>
			</div>
		);
	}

	if ( ! missing.length || dismissed ) {
		return null;
	}

	const dismiss = () => {
		setDismissed( true );
		try {
			sessionStorage.setItem( dismissKey, '1' );
		} catch {
			// sessionStorage unavailable (private mode / quota) — dismiss for this
			// render only; the banner reappears on reload, which is acceptable.
		}
	};

	return (
		<div className="splm-alert splm-alert--warning splm-dep-notice" role="status">
			<div>
				<strong>Some features are unavailable</strong>
				<ul className="splm-dep-notice__list">
					{ missing.map( ( f ) => (
						<li key={ f.key }>{ f.message }</li>
					) ) }
				</ul>
			</div>
			<button
				type="button"
				className="splm-alert__action splm-dep-notice__dismiss"
				onClick={ dismiss }
				aria-label="Dismiss"
			>
				✕
			</button>
		</div>
	);
}
