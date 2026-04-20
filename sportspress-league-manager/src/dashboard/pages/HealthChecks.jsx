import { useState, useEffect } from '@wordpress/element';
import { fetchHealth } from '../lib/api';

const ICONS = { error: '❌', warning: '⚠️', info: 'ℹ️' };

export default function HealthChecks() {
	const [ alerts, setAlerts ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		fetchHealth().then( ( data ) => {
			const items = [];
			if ( data.events_without_results?.length ) {
				items.push( { type: 'error', message: 'Past games missing scores', count: data.events_without_results.length } );
			}
			if ( data.players_without_email?.length ) {
				items.push( { type: 'warning', message: 'Players without email address', count: data.players_without_email.length } );
			}
			if ( data.events_without_venue?.length ) {
				items.push( { type: 'warning', message: 'Games without venue assigned', count: data.events_without_venue.length } );
			}
			if ( data.teams_without_players?.length ) {
				items.push( { type: 'info', message: 'Teams with no players', count: data.teams_without_players.length } );
			}
			setAlerts( items );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	if ( loading ) {
		return <div className="splm-loading">Running health checks...</div>;
	}

	const errors = alerts.filter( ( a ) => a.type === 'error' );
	const warnings = alerts.filter( ( a ) => a.type === 'warning' );
	const info = alerts.filter( ( a ) => a.type === 'info' );

	const renderGroup = ( items, type ) => {
		if ( items.length === 0 ) return null;
		return (
			<div className={ `splm-health-alert splm-health-alert--${ type }` }>
				{ items.map( ( item, i ) => (
					<div key={ i } className="splm-health-alert__item">
						<span className="splm-health-alert__icon">{ ICONS[ type ] }</span>
						<span className="splm-health-alert__message">{ item.message }</span>
						<span className="splm-health-alert__count">{ item.count } affected</span>
					</div>
				) ) }
			</div>
		);
	};

	return (
		<div className="splm-health">
			<h2>Health Checks</h2>
			{ alerts.length === 0 ? (
				<p className="splm-empty">All systems healthy. ✓</p>
			) : (
				<>
					{ renderGroup( errors, 'error' ) }
					{ renderGroup( warnings, 'warning' ) }
					{ renderGroup( info, 'info' ) }
				</>
			) }
		</div>
	);
}
