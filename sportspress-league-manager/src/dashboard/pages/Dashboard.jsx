import { useState, useEffect, useMemo } from '@wordpress/element';
import HelpLink from '../components/HelpLink';
import { fetchGames, fetchActivity, fetchStats, saveUserPreferences } from '../lib/api';
import Icon from '../components/icons';
import PenaltyWatchCard from '../components/PenaltyWatchCard';
import NoticeQueueCard from '../components/NoticeQueueCard';

const CARDS = [ 'upcoming', 'recent', 'activity', 'penalties' ];

// M7: allowlist the activity types we ship CSS for. Any new types must be
// added here AND in styles.css; unknown types fall back to "other".
const ACTIVITY_TYPES = [ 'registration', 'payment', 'role' ];
function activityTypeClass( type ) {
	return ACTIVITY_TYPES.includes( type ) ? type : 'other';
}

const ACTIVITY_TIMESTAMP_FORMATTER = new Intl.DateTimeFormat( undefined, {
	year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
} );
const GAME_DATE_FORMATTER = new Intl.DateTimeFormat( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
const GAME_TIME_FORMATTER = new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit' } );

function formatActivityTimestamp( raw ) {
	if ( ! raw ) return '';
	// MySQL timestamps come back as "YYYY-MM-DD HH:MM:SS" — Safari needs a "T".
	const d = new Date( typeof raw === 'string' ? raw.replace( ' ', 'T' ) : raw );
	if ( Number.isNaN( d.getTime() ) ) return String( raw );
	return ACTIVITY_TIMESTAMP_FORMATTER.format( d );
}

function formatGameDate( raw ) {
	if ( ! raw ) return '';
	const d = new Date( `${ raw }T00:00:00` );
	return Number.isNaN( d.getTime() ) ? String( raw ) : GAME_DATE_FORMATTER.format( d );
}

function formatGameTime( raw ) {
	if ( ! raw ) return '';
	const [ h, m ] = String( raw ).split( ':' );
	if ( h === undefined ) return '';
	const d = new Date();
	d.setHours( Number( h ), Number( m ) || 0, 0, 0 );
	return Number.isNaN( d.getTime() ) ? '' : GAME_TIME_FORMATTER.format( d );
}

function humanizeActivityType( type ) {
	return String( type || '' ).replace( /(^|\s)\w/g, ( c ) => c.toUpperCase() );
}

// A game row that links to the event page (view/interact) — keeps the flex
// layout of splm-game-list__item while being a single accessible link.
function GameRow( { game, children } ) {
	if ( game.permalink ) {
		return (
			<li>
				<a
					className="splm-game-list__item splm-game-list__link"
					href={ game.permalink }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ children }
				</a>
			</li>
		);
	}
	return <li className="splm-game-list__item">{ children }</li>;
}

export default function Dashboard( { onNavigate, season } ) {
	const [ upcomingGames, setUpcomingGames ] = useState( [] );
	const [ recentGames, setRecentGames ] = useState( [] );
	const [ activity, setActivity ] = useState( [] );
	const [ stats, setStats ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ visibleCards, setVisibleCards ] = useState( () => {
		const saved = window.splmDashboard?.dashboardLayout;
		return ( Array.isArray( saved ) && saved.length ) ? saved : CARDS;
	} );
	const [ showSettings, setShowSettings ] = useState( false );
	// Score entry is delegated to the Events Manager module; when it's
	// unavailable the Scores page is hidden, so don't surface score prompts.
	const scoresAvailable = window.splmDashboard?.dependencies?.events_manager !== false;

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		const today = new Date().toISOString().split( 'T' )[ 0 ];
		const seasonParam = season ? { season } : {};
		// Two targeted queries instead of pulling the oldest N events and
		// slicing client-side (which hid upcoming games once a season exceeded
		// per_page): upcoming = from today ascending; recent = up to today
		// descending (also feeds the "needs scores" prompt).
		Promise.all( [
			fetchGames( { ...seasonParam, from: today, order: 'asc', per_page: 8 } ),
			fetchGames( { ...seasonParam, to: today, order: 'desc', per_page: 25 } ),
			fetchActivity( 10 ),
		] ).then( ( [ up, recent, actData ] ) => {
			if ( cancelled ) return;
			setUpcomingGames( up );
			setRecentGames( recent );
			setActivity( actData );
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setError( err?.message || 'Failed to load' );
			setLoading( false );
		} );

		// Stats tile is best-effort and season-scoped: fetch independently so a
		// stats failure (or no season selected) never blocks the dashboard.
		if ( season ) {
			fetchStats( season )
				.then( ( s ) => { if ( ! cancelled ) setStats( s ); } )
				.catch( () => { if ( ! cancelled ) setStats( null ); } );
		} else {
			setStats( null );
		}
		return () => { cancelled = true; };
	}, [ season ] );

	const toggleCard = ( card ) => {
		const next = visibleCards.includes( card )
			? visibleCards.filter( ( c ) => c !== card )
			: [ ...visibleCards, card ];
		setVisibleCards( next );
		saveUserPreferences( { dashboard_layout: next } ).catch( () => {} );
	};

	const { upcoming, needScores, recent } = useMemo( () => {
		const today = new Date().toISOString().split( 'T' )[ 0 ];
		return {
			upcoming: upcomingGames.filter( ( g ) => ! g.cancelled ).slice( 0, 5 ),
			recent: recentGames.filter( ( g ) => g.home_score !== null && ! g.cancelled ).slice( 0, 5 ),
			needScores: recentGames.filter( ( g ) => g.date < today && g.home_score === null && ! g.cancelled ),
		};
	}, [ upcomingGames, recentGames ] );

	if ( loading ) {
		return <div className="splm-loading">Loading...</div>;
	}

	return (
		<div className="splm-dashboard">
			<div className="splm-dashboard__header">
				<h2>Dashboard <HelpLink topic="dashboard" /></h2>
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

			{ stats && (
				<div className="splm-stat-tiles" aria-label="Season at a glance">
					<div className="splm-stat-tile">
						<span className="splm-stat-tile__value">{ stats.teams }</span>
						<span className="splm-stat-tile__label">Team{ stats.teams === 1 ? '' : 's' }</span>
					</div>
					<div className="splm-stat-tile">
						<span className="splm-stat-tile__value">{ stats.players }</span>
						<span className="splm-stat-tile__label">Player{ stats.players === 1 ? '' : 's' }</span>
					</div>
					{ stats.fees && (
						<button
							type="button"
							className="splm-stat-tile splm-stat-tile--fees"
							onClick={ () => onNavigate( 'payments' ) }
							title="View payments"
						>
							<span className="splm-stat-tile__value">
								{ stats.fees.paid }<span className="splm-stat-tile__value-sub"> / { stats.players }</span>
							</span>
							<span className="splm-stat-tile__label">Registration fees paid</span>
							<span className="splm-stat-tile__breakdown">
								{ stats.fees.pending > 0 && <span className="splm-stat-tile__chip splm-stat-tile__chip--pending">{ stats.fees.pending } pending</span> }
								{ stats.fees.unpaid > 0 && <span className="splm-stat-tile__chip splm-stat-tile__chip--unpaid">{ stats.fees.unpaid } unpaid</span> }
							</span>
						</button>
					) }
				</div>
			) }

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			{ scoresAvailable && needScores.length > 0 && (
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
									<GameRow key={ g.id } game={ g }>
										<span className="splm-game-list__date">{ formatGameDate( g.date ) }{ g.time ? ` · ${ formatGameTime( g.time ) }` : '' }</span>
										<span className="splm-game-list__teams">{ g.home_team.name } vs { g.away_team.name }</span>
										{ g.venue && <span className="splm-game-list__venue">{ g.venue }</span> }
									</GameRow>
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
									<GameRow key={ g.id } game={ g }>
										<span className="splm-game-list__date">{ formatGameDate( g.date ) }</span>
										<span className="splm-game-list__teams">{ g.home_team.name } { g.home_score } – { g.away_score } { g.away_team.name }</span>
									</GameRow>
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
										<span className={ `splm-activity-badge splm-activity-badge--${ activityTypeClass( a.type ) }` }>{ humanizeActivityType( a.type ) }</span>
										{ a.link
											? <a className="splm-activity__link" href={ a.link } target="_blank" rel="noopener noreferrer">{ a.description }</a>
											: <span>{ a.description }</span> }
									</li>
								) ) }
							</ul>
						) }
					</section>
				) }

				{ window.splmDashboard?.modules?.discipline !== false && (
					<NoticeQueueCard season={ season } onNavigate={ onNavigate } />
				) }

				{ visibleCards.includes( 'penalties' ) && window.splmDashboard?.modules?.discipline !== false && (
					<PenaltyWatchCard season={ season } onNavigate={ onNavigate } />
				) }
			</div>

			<div className="splm-quick-actions">
				{ scoresAvailable && (
					<button className="splm-btn splm-btn--primary" onClick={ () => onNavigate( 'scores' ) }>
						Enter Scores
					</button>
				) }
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
