import { useState, useEffect } from '@wordpress/element';
import { fetchNotices } from '../lib/api';

/**
 * "What is waiting on me" — distinct from PenaltyWatchCard, which answers
 * "who is over a threshold".
 *
 * Deliberately outside the Dashboard's visibleCards preference: a convener who
 * has ever toggled a card has a saved layout that cannot contain a card added
 * later, and an alert that can be permanently hidden defeats its own purpose.
 */
export default function NoticeQueueCard( { season, onNavigate } ) {
	const [ pending, setPending ] = useState( 0 );
	const [ failed, setFailed ] = useState( 0 );
	const [ loaded, setLoaded ] = useState( false );

	useEffect( () => {
		if ( ! season ) return undefined;
		let cancelled = false;

		Promise.all( [
			fetchNotices( { season, status: 'pending', per_page: 1 } ),
			fetchNotices( { season, status: 'failed', per_page: 1 } ),
		] )
			.then( ( [ p, f ] ) => {
				if ( cancelled ) return;
				setPending( p.total );
				setFailed( f.total );
				setLoaded( true );
			} )
			// The card is supplementary: a failure here must not break the
			// Dashboard, so it simply renders nothing.
			.catch( () => { if ( ! cancelled ) setLoaded( true ); } );

		return () => { cancelled = true; };
	}, [ season ] );

	if ( ! loaded || ( pending === 0 && failed === 0 ) ) {
		return null;
	}

	return (
		<section className="splm-card splm-card--alert">
			<h3>Discipline Notices</h3>
			<p className="splm-muted">
				{ pending > 0 && (
					<>
						<strong>{ pending }</strong>
						{ pending === 1 ? ' notice is waiting for you' : ' notices are waiting for you' }
					</>
				) }
				{ pending > 0 && failed > 0 && '. ' }
				{ failed > 0 && (
					<>
						<strong>{ failed }</strong> could not be sent
					</>
				) }
			</p>
			<button type="button" className="splm-btn" onClick={ () => onNavigate( 'notices' ) }>
				Review them
			</button>
		</section>
	);
}
