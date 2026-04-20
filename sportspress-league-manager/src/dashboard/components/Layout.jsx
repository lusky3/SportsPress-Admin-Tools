const NAV_ITEMS = [
	{ id: 'dashboard', label: 'Dashboard', icon: '📊' },
	{ id: 'schedule', label: 'Schedule', icon: '📅' },
	{ id: 'scores', label: 'Scores', icon: '🏒' },
	{ id: 'standings', label: 'Standings', icon: '🏆' },
];

export default function Layout( { currentPage, onNavigate, children } ) {
	const config = window.splmDashboard || {};

	return (
		<div className="splm-app">
			<header className="splm-header">
				<h1 className="splm-header__title">{ config.leagueName || 'League Manager' }</h1>
				<div className="splm-header__meta">
					<span className="splm-header__season">{ config.currentSeason || '' }</span>
					<a href={ config.logoutUrl || '/wp-login.php?action=logout' } className="splm-header__logout">
						Log out
					</a>
				</div>
			</header>

			<div className="splm-layout">
				<nav className="splm-sidebar">
					{ NAV_ITEMS.map( ( item ) => (
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
				{ NAV_ITEMS.map( ( item ) => (
					<button
						key={ item.id }
						className={ `splm-mobile-nav__item ${ currentPage === item.id ? 'splm-mobile-nav__item--active' : '' }` }
						onClick={ () => onNavigate( item.id ) }
					>
						<span className="splm-mobile-nav__icon">{ item.icon }</span>
						<span className="splm-mobile-nav__label">{ item.label }</span>
					</button>
				) ) }
			</nav>
		</div>
	);
}
