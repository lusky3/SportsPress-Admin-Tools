import { useState } from '@wordpress/element';

const NAV_ITEMS = [
	{ id: 'dashboard', label: 'Dashboard', icon: '📊' },
	{ id: 'schedule', label: 'Schedule', icon: '📅' },
	{ id: 'scores', label: 'Scores', icon: '🏒' },
	{ id: 'standings', label: 'Standings', icon: '🏆' },
	{ id: 'rosters', label: 'Rosters', icon: '👥' },
	{ id: 'payments', label: 'Payments', icon: '💰' },
	{ id: 'health', label: 'Health', icon: '🔍' },
];

const MOBILE_VISIBLE = 5;

export default function Layout( { currentPage, onNavigate, onSeasonChange, season, children } ) {
	const config = window.splmDashboard || {};
	const seasons = config.seasons || [];
	const caps = config.capabilities || {};
	const [ moreOpen, setMoreOpen ] = useState( false );

	const capMap = {
		scores: caps.canEnterScores,
		rosters: caps.canManageRosters,
		payments: caps.canViewPayments,
		health: caps.canViewHealth,
	};
	const visibleItems = NAV_ITEMS.filter( ( item ) => capMap[ item.id ] === undefined || capMap[ item.id ] );

	const mobileItems = visibleItems.slice( 0, MOBILE_VISIBLE );
	const moreItems = visibleItems.slice( MOBILE_VISIBLE );

	return (
		<div className="splm-app">
			<header className="splm-header">
				<h1 className="splm-header__title">{ config.leagueName || 'League Manager' }</h1>
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
					<a href={ config.logoutUrl || '/wp-login.php?action=logout' } className="splm-header__logout">
						Log out
					</a>
				</div>
			</header>

			<div className="splm-layout">
				<nav className="splm-sidebar">
					{ visibleItems.map( ( item ) => (
						<button
							key={ item.id }
							className={ `splm-nav-item ${ currentPage === item.id ? 'splm-nav-item--active' : '' }` }
							onClick={ () => onNavigate( item.id ) }
						>
							<span className="splm-nav-item__icon">{ item.icon }</span>
							<span className="splm-nav-item__label">{ item.label }</span>
						</button>
					) ) }
				</nav>

				<main className="splm-content">
					{ children }
				</main>
			</div>

			<nav className="splm-mobile-nav">
				{ mobileItems.map( ( item ) => (
					<button
						key={ item.id }
						className={ `splm-mobile-nav__item ${ currentPage === item.id ? 'splm-mobile-nav__item--active' : '' }` }
						onClick={ () => onNavigate( item.id ) }
					>
						<span className="splm-mobile-nav__icon">{ item.icon }</span>
						<span className="splm-mobile-nav__label">{ item.label }</span>
					</button>
				) ) }
				{ moreItems.length > 0 && (
					<div className="splm-more-menu">
						<button
							className={ `splm-mobile-nav__item ${ moreItems.some( ( m ) => m.id === currentPage ) ? 'splm-mobile-nav__item--active' : '' }` }
							onClick={ () => setMoreOpen( ! moreOpen ) }
						>
							<span className="splm-mobile-nav__icon">⋯</span>
							<span className="splm-mobile-nav__label">More</span>
						</button>
						{ moreOpen && (
							<div className="splm-more-menu__dropdown">
								{ moreItems.map( ( item ) => (
									<button
										key={ item.id }
										className={ `splm-more-menu__item ${ currentPage === item.id ? 'splm-more-menu__item--active' : '' }` }
										onClick={ () => { onNavigate( item.id ); setMoreOpen( false ); } }
									>
										<span>{ item.icon }</span>
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
