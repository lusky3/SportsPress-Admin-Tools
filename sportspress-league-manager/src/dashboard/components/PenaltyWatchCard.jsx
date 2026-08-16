import { useState, useEffect } from '@wordpress/element';
import { fetchPenaltyWatch } from '../lib/api';

export default function PenaltyWatchCard( { season, onNavigate } ) {
	const [ rows, setRows ] = useState( [] );
	const [ loaded, setLoaded ] = useState( false );

	useEffect( () => {
		if ( ! season ) return undefined;
		let cancelled = false;
		fetchPenaltyWatch( season )
			.then( ( d ) => {
				if ( cancelled ) return;
				setRows( d || [] );
				setLoaded( true );
			} )
			// The card is supplementary: a failure here must not break the
			// Dashboard, so it simply renders nothing.
			.catch( () => { if ( ! cancelled ) setLoaded( true ); } );
		return () => { cancelled = true; };
	}, [ season ] );

	const critical = rows.filter( ( r ) => r.severity === 'critical' );
	if ( ! loaded || rows.length === 0 ) return null;

	return (
		<section className="splm-card">
			<h3>Penalty Watch</h3>
			<p className="splm-muted">
				{ critical.length } critical, { rows.length - critical.length } warning
			</p>
			<ul className="splm-game-list">
				{ rows.slice( 0, 5 ).map( ( r ) => (
					<li key={ r.player_id } className="splm-game-list__item">
						<strong>{ r.player }</strong> — { r.season_pim } PIM
						{ r.window_pim > 0 && ` (${ r.window_pim } recent)` }
						{ ' ' }<span className={ `splm-badge splm-badge--${ r.severity }` }>{ r.severity }</span>
					</li>
				) ) }
			</ul>
			<button className="splm-btn" onClick={ () => onNavigate( 'leaders' ) }>
				View all
			</button>
		</section>
	);
}
