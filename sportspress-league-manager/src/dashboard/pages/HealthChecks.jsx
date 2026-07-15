import { useState, useEffect } from '@wordpress/element';
import { fetchHealth } from '../lib/api';

const ICONS = { error: '❌', warning: '⚠️', info: 'ℹ️' };

// Each check maps a health-report array to a display group. `cap` mirrors the
// server-side LIMIT so we can say "first N shown" when the list is truncated.
// `fix` describes the correction; `action` is an optional in-dashboard link.
const CHECKS = [
	{
		key: 'events_without_results',
		type: 'error',
		label: 'Past games missing scores',
		cap: 20,
		fix: 'Enter each game’s final score so standings stay accurate.',
		action: { href: '#scores', text: 'Go to Score Entry →' },
		itemLabel: ( it ) => `${ it.title }${ it.date ? ` — ${ it.date }` : '' }`,
	},
	{
		key: 'events_without_venue',
		type: 'warning',
		label: 'Games without a venue',
		cap: 20,
		fix: 'Assign a venue on each event so players know where to play.',
		itemLabel: ( it ) => it.title,
	},
	{
		key: 'players_without_email',
		type: 'warning',
		label: 'Players without an email address',
		cap: 20,
		fix: 'Add an email address so these players receive schedule and cancellation notices.',
		itemLabel: ( it ) => it.name,
	},
	{
		key: 'teams_without_players',
		type: 'info',
		label: 'Teams with no players',
		cap: 50,
		fix: 'Assign players to each team (set the player’s current team), or retire the team if it isn’t active.',
		itemLabel: ( it ) => it.name,
	},
];

export default function HealthChecks() {
	const [ report, setReport ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		fetchHealth().then( ( data ) => {
			if ( cancelled ) return;
			setReport( data );
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setError( err?.message || 'Failed to run health checks' );
			setLoading( false );
		} );
		return () => { cancelled = true; };
	}, [] );

	if ( loading ) {
		return <div className="splm-loading">Running health checks...</div>;
	}

	const adminUrl = window.splmDashboard?.adminUrl || '/wp-admin/';
	const editUrl = ( id ) => `${ adminUrl }post.php?post=${ id }&action=edit`;

	const groups = CHECKS
		.map( ( check ) => ( { check, items: Array.isArray( report?.[ check.key ] ) ? report[ check.key ] : [] } ) )
		.filter( ( g ) => g.items.length > 0 );

	return (
		<div className="splm-health">
			<h2>Health Checks</h2>
			<p className="splm-muted">Data problems that can throw off standings, notifications, or reports. Expand a check to see the affected records and open each one to fix it.</p>
			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			{ groups.length === 0 && ! error ? (
				<p className="splm-empty">All systems healthy. ✓</p>
			) : (
				groups.map( ( { check, items } ) => {
					const capped = items.length >= check.cap;
					return (
						<div key={ check.key } className={ `splm-health-alert splm-health-alert--${ check.type }` }>
							<div className="splm-health-alert__head">
								<span className="splm-health-alert__icon" aria-hidden="true">{ ICONS[ check.type ] }</span>
								<span className="splm-health-alert__message">{ check.label }</span>
								<span className="splm-health-alert__count">
									{ items.length }{ capped ? '+' : '' } affected
								</span>
							</div>
							<p className="splm-health-alert__fix">
								{ check.fix }
								{ check.action && (
									<> { ' ' }<a className="splm-health-alert__action" href={ check.action.href }>{ check.action.text }</a></>
								) }
							</p>
							<details className="splm-health-alert__details">
								<summary>{ capped ? `Show first ${ check.cap } affected` : `Show ${ items.length } affected` }</summary>
								<ul className="splm-health-alert__list">
									{ items.map( ( it ) => (
										<li key={ it.id }>
											<a href={ editUrl( it.id ) } target="_blank" rel="noopener noreferrer">
												{ check.itemLabel( it ) } ↗
											</a>
										</li>
									) ) }
								</ul>
								{ capped && <p className="splm-muted">More may exist beyond the first { check.cap }. Fix these, then re-open this tab to refresh.</p> }
							</details>
						</div>
					);
				} )
			) }
		</div>
	);
}
