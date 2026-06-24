import { useState, useEffect, useMemo } from '@wordpress/element';
import { fetchGames, fetchActivity, saveUserPreferences } from '../lib/api';
import Icon from '../components/icons';

const CARDS = [ 'upcoming', 'recent', 'activity' ];

// M7: allowlist the activity types we ship CSS for. Any new types must be
// added here AND in styles.css; unknown types fall back to "other".
const ACTIVITY_TYPES = [ 'registration', 'payment', 'role' ];
function activityTypeClass( type ) {
	return ACTIVITY_TYPES.includes( type ) ? type : 'other';
}

const ACTIVITY_TIMESTAMP_FORMATTER = new Intl.DateTimeFormat( undefined, {
	year: 'numeric',
	month: 'short',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
} );

function formatActivityTimestamp( raw ) {
	if ( ! raw ) return '';
	// MySQL timestamps come back as "YYYY-MM-DD HH:MM:SS" — Safari needs a "T".
	const d = new Date( typeof raw === 'string' ? raw.replace( ' ', 'T' ) : raw );
	if ( Number.isNaN( d.getTime() ) ) return String( raw );
	return ACTIVITY_TIMESTAMP_FORMATTER.format( d );
}

export default function Dashboard( { onNavigate, season } ) {
	const [ games, setGames ] = useState( [] );
	const [ activity, setActivity ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ visibleCards, setVisibleCards ] = useState( () => {
		const saved = window.splmDashboard?.dashboardLayout;
		return ( Array.isArray( saved ) && saved.length ) ? saved : CARDS;
	} );
	const [ showSettings, setShowSettings ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		Promise.all( [
			fetchGames( season ? { season } : {} ),
			fetchActivity( 10 ),
		] ).then( ( [ gamesData, actData ] ) => {
			if ( cancelled ) return;
			setGames( gamesData );
			setActivity( actData );
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setError( err?.message || 'Failed to load' );
			setLoading( false );
		} );
		return () => { cancelled = true; };
	}, [ season ] );

	const toggleCard = ( card ) => {
		const next = visibleCards.includes( card )
			? visibleCards.filter( ( c ) => c !== card )
			: [ ...visibleCards, card ];
		setVisibleCards( next );
		saveUserPreferences( { dashboard_layout: next } ).catch( () => {} );
	};

	// UI-10: derive these only when `games` changes, not on every unrelated
	// render (settings toggle, etc.).
	const { upcoming, needScores, recent } = useMemo( () => {
		const today = new Date().toISOString().split( 'T' )[ 0 ];
		return {
			upcoming: games.filter( ( g ) => g.date >= today && ! g.cancelled ).slice( 0, 5 ),
			needScores: games.filter( ( g ) => g.date < today && g.home_score === null && ! g.cancelled ),
			recent: [ ...games ]
				.filter( ( g ) => g.home_score !== null )
				.sort( ( a, b ) => b.date.localeCompare( a.date ) )
				.slice( 0, 5 ),
		};
	}, [ games ] );

	if ( loading ) {
		return <div className="splm-loading">Loading...</div>;
	}

	return (
		<div className="splm-dashboard">
			<div className="splm-dashboard__header">
				<h2>Dashboard</h2>
				<button
					className="splm-btn splm-btn--small"
					onClick={ () => setShowSettings( ! showSettings ) }
					aria-label="Customize dashboard"
					aria-expanded={ showSettings }
					aria-controls="splm-dashboard-settings"
				>
					<Icon name="gear" size={ 16 } />
				</button>
			</div>

			{ showSettings && (
				<div className="splm-card splm-dashboard__settings" id="splm-dashboard-settings">
					<h3>Visible Cards</h3>
					{ CARDS.map( ( card ) => (
						<label key={ card } className="splm-checkbox">
							<input type="checkbox" checked={ visibleCards.includes( card ) } onChange={ () => toggleCard( card ) } />
							{ card.charAt( 0 ).toUpperCase() + card.slice( 1 ) }
						</label>
					) ) }
				</div>
			) }

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
				{ visibleCards.includes( 'upcoming' ) && (
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
				) }

				{ visibleCards.includes( 'recent' ) && (
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
				) }

				{ visibleCards.includes( 'activity' ) && (
				<section className="splm-card">
					<h3>Recent Activity</h3>
					{ activity.length === 0 ? (
						<p className="splm-empty">No recent activity.</p>
					) : (
						<ul className="splm-game-list">
							{ activity.map( ( a, i ) => (
								<li key={ i } className="splm-game-list__item">
									<span className="splm-game-list__date">{ formatActivityTimestamp( a.timestamp ) }</span>
									<span className={ `splm-activity-badge splm-activity-badge--${ activityTypeClass( a.type ) }` }>{ a.type }</span>
									<span>{ a.description }</span>
								</li>
							) ) }
						</ul>
					) }
				</section>
				) }
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
