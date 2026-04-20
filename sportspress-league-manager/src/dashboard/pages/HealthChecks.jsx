import { useState, useEffect } from '@wordpress/element';
import { fetchHealth } from '../lib/api';

const ICONS = { error: '❌', warning: '⚠️', info: 'ℹ️' };

export default function HealthChecks() {
	const [ alerts, setAlerts ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		fetchHealth().then( ( data ) => {
			setAlerts( data );
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
