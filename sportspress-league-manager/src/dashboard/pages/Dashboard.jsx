import { useState, useEffect } from '@wordpress/element';
import { fetchGames } from '../lib/api';

export default function Dashboard( { onNavigate, season } ) {
	const [ games, setGames ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		fetchGames( season ? { season } : {} ).then( ( data ) => {
			if ( cancelled ) return;
			setGames( data );
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setError( err?.message || 'Failed to load' );
			setLoading( false );
		} );
		return () => { cancelled = true; };
	}, [ season ] );

	const today = new Date().toISOString().split( 'T' )[ 0 ];
	const upcoming = games.filter( ( g ) => g.date >= today && ! g.cancelled ).slice( 0, 5 );
	const needScores = games.filter( ( g ) => g.date < today && g.home_score === null && ! g.cancelled );
	const recent = [ ...games ]
		.filter( ( g ) => g.home_score !== null )
		.sort( ( a, b ) => b.date.localeCompare( a.date ) )
		.slice( 0, 5 );

	if ( loading ) {
		return <div className="splm-loading">Loading...</div>;
	}

	return (
		<div className="splm-dashboard">
			<h2>Dashboard</h2>

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			{ needScores.length > 0 && (
				<div className="splm-alert splm-alert--warning" role="alert">
					<strong>{ needScores.length } game{ needScores.length > 1 ? 's' : '' } need scores.</strong>
					<button className="splm-alert__action" onClick={ () => onNavigate( 'scores' ) }>
						Enter Scores →
					</button>
				</div>
			) }

			<div className="splm-grid">
				<section className="splm-card">
					<h3>Upcoming Games</h3>
					{ upcoming.length === 0 ? (
						<p className="splm-empty">No upcoming games.</p>
					) : (
						<ul className="splm-game-list">
							{ upcoming.map( ( g ) => (
								<li key={ g.id } className="splm-game-list__item">
									<span className="splm-game-list__date">{ g.date } { g.time }</span>
									<span className="splm-game-list__teams">
										{ g.home_team.name } vs { g.away_team.name }
									</span>
									<span className="splm-game-list__venue">{ g.venue }</span>
								</li>
							) ) }
						</ul>
					) }
				</section>

				<section className="splm-card">
					<h3>Recent Scores</h3>
					{ recent.length === 0 ? (
						<p className="splm-empty">No scores yet.</p>
					) : (
						<ul className="splm-game-list">
							{ recent.map( ( g ) => (
								<li key={ g.id } className="splm-game-list__item">
									<span className="splm-game-list__date">{ g.date }</span>
									<span className="splm-game-list__teams">
										{ g.home_team.name } { g.home_score } - { g.away_score } { g.away_team.name }
									</span>
								</li>
							) ) }
						</ul>
					) }
				</section>
			</div>

			<div className="splm-quick-actions">
				<button className="splm-btn splm-btn--primary" onClick={ () => onNavigate( 'scores' ) }>
					Enter Scores
				</button>
				<button className="splm-btn" onClick={ () => onNavigate( 'schedule' ) }>
					View Schedule
				</button>
				<button className="splm-btn" onClick={ () => onNavigate( 'standings' ) }>
					Standings
				</button>
			</div>
		</div>
	);
}
