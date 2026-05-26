import { useState, useRef, useEffect } from '@wordpress/element';
import { searchPlayers } from '../lib/api';

const NAV_ITEMS = [
	{ id: 'dashboard', label: 'Dashboard', icon: '📊' },
	{ id: 'schedule', label: 'Schedule', icon: '📅' },
	{ id: 'scores', label: 'Scores', icon: '🏒' },
	{ id: 'standings', label: 'Standings', icon: '🏆' },
	{ id: 'rosters', label: 'Rosters', icon: '👥' },
	{ id: 'payments', label: 'Payments', icon: '💰' },
	{ id: 'div-balance', label: 'Balance', icon: '⚖️' },
	{ id: 'team-compare', label: 'Compare', icon: '🔄' },
	{ id: 'season-report', label: 'Report', icon: '📋' },
	{ id: 'health', label: 'Health', icon: '🔍' },
	{ id: 'schedule-gen', label: 'Generate', icon: '📅' },
];

const MOBILE_VISIBLE = 5;

export default function Layout( { currentPage, onNavigate, onSeasonChange, season, children } ) {
	const config = window.splmDashboard || {};
	const seasons = config.seasons || [];
	const caps = config.capabilities || {};
	const [ moreOpen, setMoreOpen ] = useState( false );
	const moreRef = useRef( null );
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchResults, setSearchResults ] = useState( [] );
	const [ searchOpen, setSearchOpen ] = useState( false );
	const searchRef = useRef( null );
	const searchTimer = useRef( null );

	useEffect( () => {
		// L1: the handler only reads refs and setters (stable), so the listener
		// should be installed once on mount, not re-registered each time moreOpen
		// toggles. Also clears the pending search debounce on unmount (L2).
		const handler = ( e ) => {
			if ( moreRef.current && ! moreRef.current.contains( e.target ) ) setMoreOpen( false );
			if ( searchRef.current && ! searchRef.current.contains( e.target ) ) setSearchOpen( false );
		};
		document.addEventListener( 'mousedown', handler );
		return () => {
			document.removeEventListener( 'mousedown', handler );
			if ( searchTimer.current ) {
				clearTimeout( searchTimer.current );
			}
		};
	}, [] );

	const handleSearch = ( q ) => {
		setSearchQuery( q );
		clearTimeout( searchTimer.current );
		// M5: client threshold (3) matches the server-side floor in
		// SPLM_REST_API::search_players so we don't burn a request that
		// the server will reject as too-short.
		if ( q.length < 3 ) { setSearchResults( [] ); setSearchOpen( false ); return; }
		searchTimer.current = setTimeout( () => {
			searchPlayers( q ).then( ( r ) => { setSearchResults( r ); setSearchOpen( true ); } ).catch( () => {} );
		}, 300 );
	};

	const capMap = {
		scores: caps.canEnterScores,
		rosters: caps.canManageRosters,
		payments: caps.canViewPayments,
		health: caps.canViewHealth,
		'schedule-gen': caps.canManageSchedule,
	};
	const visibleItems = NAV_ITEMS.filter( ( item ) => capMap[ item.id ] === undefined || capMap[ item.id ] );

	const mobileItems = visibleItems.slice( 0, MOBILE_VISIBLE );
	const moreItems = visibleItems.slice( MOBILE_VISIBLE );

	return (
		<div className="splm-app">
			<header className="splm-header">
				<h1 className="splm-header__title">{ config.leagueName || 'League Manager' }</h1>
				<div className="splm-header__search" ref={ searchRef }>
					<input
						type="search"
						className="splm-search-input"
						placeholder="Search players..."
						value={ searchQuery }
						onChange={ ( e ) => handleSearch( e.target.value ) }
						aria-label="Search players"
					/>
					{ searchOpen && searchResults.length > 0 && (
						<ul className="splm-search-results" role="listbox">
							{ searchResults.map( ( p ) => (
								<li key={ p.id } role="option" className="splm-search-results__item">
									<button onClick={ () => { setSearchOpen( false ); setSearchQuery( '' ); onNavigate( 'rosters' ); } }>
										<strong>{ p.name }</strong>
										{ p.team_name && <span> — { p.team_name }</span> }
										{ p.number && <span> #{ p.number }</span> }
									</button>
								</li>
							) ) }
						</ul>
					) }
				</div>
				<div className="splm-header__meta">
					{ seasons.length > 0 && (
						<select
							className="splm-select splm-header__season-select"
							value={ season }
							onChange={ ( e ) => onSeasonChange( e.target.value ) }
						>
							<option value="">All Seasons</option>
							{ seasons.map( ( s ) => (
								<option key={ s.id } value={ s.id }>{ s.name }</option>
							) ) }
						</select>
					) }
					{ ! seasons.length && <span className="splm-header__season">{ config.currentSeason || '' }</span> }
					{ config.logoutUrl && <a href={ config.logoutUrl } className="splm-header__logout">
						Log out
					</a> }
				</div>
			</header>

			<div className="splm-layout">
				<nav className="splm-sidebar" aria-label="Main navigation">
					{ visibleItems.map( ( item ) => (
						<button
							key={ item.id }
							className={ `splm-nav-item ${ currentPage === item.id ? 'splm-nav-item--active' : '' }` }
							onClick={ () => onNavigate( item.id ) }
							aria-current={ currentPage === item.id ? 'page' : undefined }
						>
							<span className="splm-nav-item__icon" aria-hidden="true">{ item.icon }</span>
							<span className="splm-nav-item__label">{ item.label }</span>
						</button>
					) ) }
				</nav>

				<main className="splm-content">
					{ children }
				</main>
			</div>

			<nav className="splm-mobile-nav" aria-label="Mobile navigation">
				{ mobileItems.map( ( item ) => (
					<button
						key={ item.id }
						className={ `splm-mobile-nav__item ${ currentPage === item.id ? 'splm-mobile-nav__item--active' : '' }` }
						onClick={ () => onNavigate( item.id ) }
						aria-current={ currentPage === item.id ? 'page' : undefined }
					>
						<span className="splm-mobile-nav__icon" aria-hidden="true">{ item.icon }</span>
						<span className="splm-mobile-nav__label">{ item.label }</span>
					</button>
				) ) }
				{ moreItems.length > 0 && (
					<div className="splm-more-menu" ref={ moreRef }>
						<button
							className={ `splm-mobile-nav__item ${ moreItems.some( ( m ) => m.id === currentPage ) ? 'splm-mobile-nav__item--active' : '' }` }
							onClick={ () => setMoreOpen( ! moreOpen ) }
							aria-expanded={ moreOpen }
							aria-haspopup="true"
						>
							<span className="splm-mobile-nav__icon" aria-hidden="true">⋯</span>
							<span className="splm-mobile-nav__label">More</span>
						</button>
						{ moreOpen && (
							<div className="splm-more-menu__dropdown">
								{ moreItems.map( ( item ) => (
									<button
										key={ item.id }
										className={ `splm-more-menu__item ${ currentPage === item.id ? 'splm-more-menu__item--active' : '' }` }
										onClick={ () => { onNavigate( item.id ); setMoreOpen( false ); } }
										aria-current={ currentPage === item.id ? 'page' : undefined }
									>
										<span aria-hidden="true">{ item.icon }</span>
										<span>{ item.label }</span>
									</button>
								) ) }
							</div>
						) }
					</div>
				) }
			</nav>
		</div>
	);
}
