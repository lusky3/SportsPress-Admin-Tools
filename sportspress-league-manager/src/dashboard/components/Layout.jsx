import { useState, useRef, useEffect } from '@wordpress/element';
import { searchPlayers } from '../lib/api';
import Icon from './icons';

// UX-3/UI-8: structural icons are now SVG keys (see components/icons.js).
// Schedule (calendar) and Generate (sparkle) have DISTINCT glyphs — they
// previously both rendered 📅.
const NAV_ITEMS = [
	{ id: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
	{ id: 'schedule', label: 'Schedule', icon: 'schedule' },
	{ id: 'scores', label: 'Scores', icon: 'scores' },
	{ id: 'score-sheets', label: 'Sheets', icon: 'score-sheets' },
	{ id: 'standings', label: 'Standings', icon: 'standings' },
	{ id: 'rosters', label: 'Rosters', icon: 'rosters' },
	{ id: 'payments', label: 'Payments', icon: 'payments' },
	{ id: 'div-balance', label: 'Balance', icon: 'div-balance' },
	{ id: 'team-compare', label: 'Compare', icon: 'team-compare' },
	{ id: 'season-report', label: 'Report', icon: 'season-report' },
	{ id: 'season-setup', label: 'Seasons', icon: 'seasons' },
	{ id: 'health', label: 'Health', icon: 'health' },
	{ id: 'schedule-gen', label: 'Generate', icon: 'schedule-gen' },
];

const MOBILE_VISIBLE = 5;

export default function Layout( { currentPage, onNavigate, onSeasonChange, season, children } ) {
	const config = window.splmDashboard || {};
	const seasons = config.seasons || [];
	const caps = config.capabilities || {};
	// Graceful degradation: feature availability mirrors the PHP class_exists
	// guards. A missing key is treated as available (fail open) so a future
	// build that drops a flag doesn't silently hide a working feature.
	const deps = config.dependencies || {};
	const depPresent = ( key ) => deps[ key ] !== false;
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

	// A feature is shown only when BOTH its capability AND its backing
	// dependency are present. Items without a dependency (rosters, health,
	// season-setup, and the core SPLM/SP pages) stay gated on capability alone.
	const capMap = {
		scores: caps.canEnterScores && depPresent( 'events_manager' ),
		'score-sheets': caps.canReviewScoreSheets && depPresent( 'score_sheets' ),
		rosters: caps.canManageRosters,
		payments: caps.canViewPayments && depPresent( 'woocommerce' ),
		health: caps.canViewHealth,
		'schedule-gen': caps.canManageSchedule && depPresent( 'schedule_generator' ),
		'season-setup': caps.canManageSchedule,
	};
	const visibleItems = NAV_ITEMS.filter( ( item ) => capMap[ item.id ] === undefined || capMap[ item.id ] );

	const mobileItems = visibleItems.slice( 0, MOBILE_VISIBLE );
	const moreItems = visibleItems.slice( MOBILE_VISIBLE );

	return (
		<div className="splm-app">
			<header className="splm-header">
				<h1 className="splm-header__title">{ config.leagueName || 'League Manager' }</h1>
				<div className="splm-header__search" ref={ searchRef }>
					{ /* UX-12: chose the LOWER-RISK option — a plain focusable
					     <button> list. The previous markup declared
					     role=listbox/option but had no aria-activedescendant /
					     arrow-key handling (a broken combobox). Each result is now
					     a real button (tab + Enter/Space work natively); the
					     listbox/option roles are removed so AT no longer expects
					     combobox keyboard semantics that weren't implemented. */ }
					<input
						type="search"
						className="splm-search-input"
						placeholder="Search players..."
						value={ searchQuery }
						onChange={ ( e ) => handleSearch( e.target.value ) }
						aria-label="Search players"
					/>
					{ searchOpen && searchResults.length > 0 && (
						<ul className="splm-search-results">
							{ searchResults.map( ( p ) => (
								<li key={ p.id } className="splm-search-results__item">
									<button type="button" onClick={ () => { setSearchOpen( false ); setSearchQuery( '' ); onNavigate( 'rosters' ); } }>
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
							<span className="splm-nav-item__icon"><Icon name={ item.icon } /></span>
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
						<span className="splm-mobile-nav__icon"><Icon name={ item.icon } /></span>
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
							<span className="splm-mobile-nav__icon"><Icon name="more" /></span>
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
										<span className="splm-icon-wrap"><Icon name={ item.icon } /></span>
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
