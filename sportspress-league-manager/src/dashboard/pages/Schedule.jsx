import { useState, useEffect, useCallback } from '@wordpress/element';
import { fetchGames, rescheduleGame, cancelGame, importGamesPreview, importGames } from '../lib/api';
import Toast from '../components/Toast';

export default function Schedule( { season } ) {
	const [ games, setGames ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ modal, setModal ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ importPreview, setImportPreview ] = useState( null );
	const [ importing, setImporting ] = useState( false );
	const [ toast, setToast ] = useState( '' ); // UI-13: in-app success feedback

	const handleImportFile = ( e ) => {
		const file = e.target.files?.[ 0 ];
		if ( ! file ) return;
		importGamesPreview( file ).then( setImportPreview ).catch( ( err ) => setError( err?.message || 'Failed to parse file' ) );
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

	const loadGames = useCallback( () => {
		let cancelled = false;
		setLoading( true );
		fetchGames( season ? { season } : {} ).then( ( data ) => {
			if ( cancelled ) return;
			setGames( data );
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setError( err?.message || 'Failed to load games' );
			setLoading( false );
		} );
		return () => { cancelled = true; };
	}, [ season ] );

	useEffect( () => {
		const cleanup = loadGames();
		return cleanup;
	}, [ loadGames ] );

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
		if ( window.confirm( 'Cancel this game?' ) ) {
			await cancelGame( game.id, { reason: 'Cancelled by admin', notify: true } ).catch( ( err ) => setError( err?.message || 'Failed' ) );
			loadGames();
		}
	};

	if ( loading ) {
		return <div className="splm-loading">Loading schedule...</div>;
	}

	// Group games by date.
	const grouped = {};
	games.forEach( ( g ) => {
		if ( ! grouped[ g.date ] ) grouped[ g.date ] = [];
		grouped[ g.date ].push( g );
	} );

	return (
		<div className="splm-schedule">
			<h2>Schedule</h2>

			<Toast message={ toast } onDismiss={ () => setToast( '' ) } />

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			<div className="splm-schedule__toolbar">
				<label className="splm-btn">
					Import Games <input type="file" accept=".csv,.xlsx" onChange={ handleImportFile } hidden />
				</label>
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
							<thead><tr><th>Date</th><th>Time</th><th>Home</th><th>Away</th><th>Venue</th></tr></thead>
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

			{ Object.entries( grouped ).map( ( [ date, dateGames ] ) => (
				<div key={ date } className="splm-schedule__day">
					<h3 className="splm-schedule__date">
						{ new Date( date + 'T12:00:00' ).toLocaleDateString( undefined, {
							weekday: 'long', month: 'long', day: 'numeric',
						} ) }
					</h3>
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
							{ ! g.cancelled && ( window.splmDashboard?.capabilities?.canManageSchedule !== false ) && (
								<div className="splm-game-card__actions">
									<button
										className="splm-btn splm-btn--small"
										onClick={ () => setModal( g ) }
									>
										Reschedule
									</button>
									<button
										className="splm-btn splm-btn--small splm-btn--danger"
										onClick={ () => handleCancel( g ) }
									>
										Cancel
									</button>
								</div>
							) }
						</div>
					) ) }
				</div>
			) ) }

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
