import { useState, useEffect, useCallback } from '@wordpress/element';
import HelpLink from '../components/HelpLink';
import { fetchGamesPage, rescheduleGame, cancelGame, importGamesPreview, importGames } from '../lib/api';
import Toast from '../components/Toast';

const PER_PAGE = 50;
const TODAY = new Date().toISOString().split( 'T' )[ 0 ];

// Divisions (sp_league terms) localized by the frontend — reused to populate
// the division filter without an extra round-trip. Sorted by name.
function divisionOptions() {
	const leagues = window.splmDashboard?.leagues || [];
	return [ ...leagues ].sort( ( a, b ) => String( a.name ).localeCompare( String( b.name ) ) );
}

function formatDayHeading( date ) {
	const d = new Date( `${ date }T12:00:00` );
	return Number.isNaN( d.getTime() )
		? date
		: d.toLocaleDateString( undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' } );
}

export default function Schedule( { season } ) {
	const [ games, setGames ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ modal, setModal ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ importPreview, setImportPreview ] = useState( null );
	const [ importing, setImporting ] = useState( false );
	const [ toast, setToast ] = useState( '' ); // UI-13: in-app success feedback

	// Filters.
	const [ statusFilter, setStatusFilter ] = useState( 'upcoming' );
	const [ divisionFilter, setDivisionFilter ] = useState( '' );
	const [ teamSearch, setTeamSearch ] = useState( '' );

	const canManage = window.splmDashboard?.capabilities?.canManageSchedule !== false;
	const adminUrl = window.splmDashboard?.adminUrl || '/wp-admin/';

	// Reset to first page whenever the query (season or a server-side filter)
	// changes, so we never request a page number past the new result set.
	useEffect( () => {
		setPage( 1 );
	}, [ season, statusFilter, divisionFilter ] );

	const loadGames = useCallback( () => {
		let cancelled = false;
		setLoading( true );
		const params = { per_page: PER_PAGE, offset: ( page - 1 ) * PER_PAGE };
		if ( season ) params.season = season;
		if ( divisionFilter ) params.league = divisionFilter;
		if ( 'upcoming' === statusFilter ) {
			params.from = TODAY;
			params.order = 'asc';
		} else if ( 'past' === statusFilter ) {
			params.to = TODAY;
			params.order = 'desc';
		} else {
			params.order = 'asc';
		}
		fetchGamesPage( params ).then( ( res ) => {
			if ( cancelled ) return;
			setGames( res.data );
			setTotal( res.total );
			setTotalPages( res.totalPages );
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setError( err?.message || 'Failed to load games' );
			setLoading( false );
		} );
		return () => { cancelled = true; };
	}, [ season, page, statusFilter, divisionFilter ] );

	useEffect( () => {
		const cleanup = loadGames();
		return cleanup;
	}, [ loadGames ] );

	const handleImportFile = ( e ) => {
		const file = e.target.files?.[ 0 ];
		if ( ! file ) return;
		importGamesPreview( file ).then( setImportPreview ).catch( ( err ) => setError( err?.message || 'Failed to parse file' ) );
		e.target.value = ''; // allow re-selecting the same file
	};

	const handleImportConfirm = async () => {
		if ( ! importPreview?.games?.length ) return;
		setImporting( true );
		try {
			const res = await importGames( importPreview.games, season || 0 );
			setError( '' );
			setImportPreview( null );
			setToast( `Imported ${ res.imported } games (${ res.skipped } skipped)` );
			loadGames();
		} catch ( err ) {
			setError( err?.message || 'Import failed' );
		}
		setImporting( false );
	};

	const handleReschedule = async ( e ) => {
		e.preventDefault();
		const form = new FormData( e.target );
		await rescheduleGame( modal.id, {
			date: form.get( 'date' ),
			time: form.get( 'time' ),
			reason: form.get( 'reason' ),
			notify: form.get( 'notify' ) === 'on',
		} ).catch( ( err ) => setError( err?.message || 'Failed' ) );
		setModal( null );
		loadGames();
	};

	const handleCancel = async ( game ) => {
		// eslint-disable-next-line no-alert
		if ( window.confirm( 'Cancel this game? Affected players can be notified.' ) ) {
			await cancelGame( game.id, { reason: 'Cancelled by admin', notify: true } ).catch( ( err ) => setError( err?.message || 'Failed' ) );
			loadGames();
		}
	};

	// Client-side team search narrows the current page (server has no team param).
	const q = teamSearch.trim().toLowerCase();
	const visibleGames = q
		? games.filter( ( g ) => `${ g.home_team.name } ${ g.away_team.name }`.toLowerCase().includes( q ) )
		: games;

	// Group (already-ordered) games by date, preserving server order.
	const grouped = [];
	const byDate = {};
	visibleGames.forEach( ( g ) => {
		if ( ! byDate[ g.date ] ) {
			byDate[ g.date ] = [];
			grouped.push( [ g.date, byDate[ g.date ] ] );
		}
		byDate[ g.date ].push( g );
	} );

	const startIdx = total === 0 ? 0 : ( page - 1 ) * PER_PAGE + 1;
	const endIdx = Math.min( page * PER_PAGE, total );

	return (
		<div className="splm-schedule">
			<h2>Schedule <HelpLink topic="schedule" /></h2>

			<Toast message={ toast } onDismiss={ () => setToast( '' ) } />

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			<div className="splm-schedule__toolbar">
				<div className="splm-schedule__filters">
					<label>
						<span className="splm-schedule__filter-label">Show</span>
						<select className="splm-select" value={ statusFilter } onChange={ ( e ) => setStatusFilter( e.target.value ) }>
							<option value="upcoming">Upcoming</option>
							<option value="past">Past</option>
							<option value="all">All</option>
						</select>
					</label>
					<label>
						<span className="splm-schedule__filter-label">Division</span>
						<select className="splm-select" value={ divisionFilter } onChange={ ( e ) => setDivisionFilter( e.target.value ) }>
							<option value="">All divisions</option>
							{ divisionOptions().map( ( d ) => (
								<option key={ d.id } value={ d.id }>{ d.name }</option>
							) ) }
						</select>
					</label>
					<label>
						<span className="splm-schedule__filter-label">Team</span>
						<input
							type="search"
							className="splm-select"
							placeholder="Filter this page by team…"
							value={ teamSearch }
							onChange={ ( e ) => setTeamSearch( e.target.value ) }
						/>
					</label>
				</div>
				{ canManage && (
					<label className="splm-btn">
						Import Games <input type="file" accept=".csv,.xlsx" onChange={ handleImportFile } hidden />
					</label>
				) }
			</div>

			{ importPreview && (
				<div className="splm-card" style={ { marginBottom: '1rem' } }>
					<h3>Import Preview — { importPreview.games.length } games</h3>
					{ importPreview.warnings?.length > 0 && (
						<div className="splm-alert splm-alert--warning" role="alert">
							{ importPreview.warnings.map( ( w, i ) => <div key={ i }>{ w }</div> ) }
						</div>
					) }
					<div className="splm-table-wrapper">
						<table className="splm-table">
							<thead><tr><th scope="col">Date</th><th scope="col">Time</th><th scope="col">Home</th><th scope="col">Away</th><th scope="col">Venue</th></tr></thead>
							<tbody>
								{ importPreview.games.slice( 0, 20 ).map( ( g, i ) => (
									<tr key={ i }><td>{ g.date }</td><td>{ g.time }</td><td>{ g.home_team }</td><td>{ g.away_team }</td><td>{ g.venue }</td></tr>
								) ) }
								{ importPreview.games.length > 20 && <tr><td colSpan="5">...and { importPreview.games.length - 20 } more</td></tr> }
							</tbody>
						</table>
					</div>
					<button className="splm-btn splm-btn--primary" onClick={ handleImportConfirm } disabled={ importing }>
						{ importing ? 'Importing...' : `Import ${ importPreview.games.length } Games` }
					</button>
					<button className="splm-btn" onClick={ () => setImportPreview( null ) } style={ { marginLeft: '0.5rem' } }>Cancel</button>
				</div>
			) }

			<div className="splm-schedule__summary" aria-live="polite">
				{ loading
					? 'Loading…'
					: total === 0
						? 'No games match these filters.'
						: `Showing ${ startIdx }–${ endIdx } of ${ total } ${ statusFilter } game${ total === 1 ? '' : 's' }${ q ? ` · ${ visibleGames.length } match “${ teamSearch }” on this page` : '' }` }
			</div>

			{ loading ? (
				<div className="splm-loading">Loading schedule...</div>
			) : (
				<>
					{ grouped.map( ( [ date, dateGames ] ) => (
						<div key={ date } className="splm-schedule__day">
							<h3 className="splm-schedule__date">{ formatDayHeading( date ) }</h3>
							{ dateGames.map( ( g ) => (
								<div key={ g.id } className={ `splm-game-card ${ g.cancelled ? 'splm-game-card--cancelled' : '' }` }>
									<div className="splm-game-card__info">
										<span className="splm-game-card__time">{ g.time }</span>
										<span className="splm-game-card__matchup">
											{ g.home_team.name } vs { g.away_team.name }
										</span>
										<span className="splm-game-card__venue">{ g.venue }</span>
										{ g.home_score !== null && (
											<span className="splm-game-card__score">
												{ g.home_score } - { g.away_score }
											</span>
										) }
										{ g.cancelled && <span className="splm-game-card__badge">Cancelled</span> }
									</div>
									<div className="splm-game-card__actions">
										{ g.permalink && (
											<a className="splm-btn splm-btn--small" href={ g.permalink } target="_blank" rel="noopener noreferrer">
												View
											</a>
										) }
										{ canManage && (
											<a className="splm-btn splm-btn--small" href={ `${ adminUrl }post.php?post=${ g.id }&action=edit` } target="_blank" rel="noopener noreferrer">
												Edit
											</a>
										) }
										{ canManage && ! g.cancelled && (
											<>
												<button className="splm-btn splm-btn--small" onClick={ () => setModal( g ) }>
													Reschedule
												</button>
												<button className="splm-btn splm-btn--small splm-btn--danger" onClick={ () => handleCancel( g ) }>
													Cancel
												</button>
											</>
										) }
									</div>
								</div>
							) ) }
						</div>
					) ) }

					{ total > PER_PAGE && (
						<div className="splm-pager">
							<button type="button" className="splm-btn" onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) } disabled={ page <= 1 }>Previous</button>
							<span className="splm-pager__status">Page { page } of { totalPages }</span>
							<button type="button" className="splm-btn" onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) } disabled={ page >= totalPages }>Next</button>
						</div>
					) }
				</>
			) }

			{ modal && (
				<div className="splm-modal-overlay" onClick={ () => setModal( null ) }>
					<form className="splm-modal" onClick={ ( e ) => e.stopPropagation() } onSubmit={ handleReschedule }>
						<h3>Reschedule Game</h3>
						<p>{ modal.home_team.name } vs { modal.away_team.name }</p>
						<label>
							New Date
							<input type="date" name="date" defaultValue={ modal.date } required />
						</label>
						<label>
							New Time
							<input type="time" name="time" defaultValue={ modal.time } required />
						</label>
						<label>
							Reason
							<input type="text" name="reason" placeholder="e.g. Ice unavailable" />
						</label>
						<label className="splm-checkbox">
							<input type="checkbox" name="notify" />
							Notify affected players
						</label>
						<div className="splm-modal__actions">
							<button type="submit" className="splm-btn splm-btn--primary">Reschedule</button>
							<button type="button" className="splm-btn" onClick={ () => setModal( null ) }>Cancel</button>
						</div>
					</form>
				</div>
			) }
		</div>
	);
}
