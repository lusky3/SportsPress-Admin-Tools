import { useState, useEffect } from '@wordpress/element';
import { fetchGames, rescheduleGame, cancelGame } from '../lib/api';

export default function Schedule() {
	const [ games, setGames ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ modal, setModal ] = useState( null );

	const loadGames = () => {
		setLoading( true );
		fetchGames().then( ( data ) => {
			setGames( data );
			setLoading( false );
		} );
	};

	useEffect( loadGames, [] );

	const handleReschedule = async ( e ) => {
		e.preventDefault();
		const form = new FormData( e.target );
		await rescheduleGame( modal.id, {
			date: form.get( 'date' ),
			time: form.get( 'time' ),
			reason: form.get( 'reason' ),
			notify: form.get( 'notify' ) === 'on',
		} );
		setModal( null );
		loadGames();
	};

	const handleCancel = async ( game ) => {
		const reason = prompt( 'Reason for cancellation:' );
		if ( reason === null ) return;
		await cancelGame( game.id, { reason, notify: true } );
		loadGames();
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
							<input type="checkbox" name="notify" defaultChecked />
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
