import { useState, useEffect } from '@wordpress/element';
import { fetchScheduleConfig, generateSchedule, publishSchedule, rolloverPreview, rolloverExecute } from '../lib/api';

function SelectAllToggle( { items, selected, onToggle } ) {
	const allSelected = items.length > 0 && items.every( ( i ) => selected.includes( i.id ) );
	return (
		<label className="splm-checkbox" style={ { fontWeight: 600, marginBottom: '0.5rem', display: 'block' } }>
			<input type="checkbox" checked={ allSelected } onChange={ () => onToggle( allSelected ? [] : items.map( ( i ) => i.id ) ) } />
			{ allSelected ? 'Deselect All' : 'Select All' } ({ items.length })
		</label>
	);
}

export default function ScheduleGenerator() {
	const [ step, setStep ] = useState( 1 );
	const [ config, setConfig ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ form, setForm ] = useState( {
		teams: [], season: '', league: '', start_date: '', end_date: '',
		games_per_team: 20, venues: [], blackout_dates: '',
	} );
	const [ games, setGames ] = useState( [] );
	const [ publishedCount, setPublishedCount ] = useState( 0 );

	// Rollover state
	const [ rolloverFrom, setRolloverFrom ] = useState( '' );
	const [ rolloverTo, setRolloverTo ] = useState( '' );
	const [ rolloverPreviewData, setRolloverPreviewData ] = useState( null );
	const [ rolloverSelected, setRolloverSelected ] = useState( {} );
	const [ rolloverLoading, setRolloverLoading ] = useState( false );
	const [ rolloverMsg, setRolloverMsg ] = useState( '' );
	const [ rolloverError, setRolloverError ] = useState( '' );

	useEffect( () => {
		fetchScheduleConfig()
			.then( setConfig )
			.catch( () => setError( 'Failed to load config' ) )
			.finally( () => setLoading( false ) );
	}, [] );

	// Filter out retired teams
	const activeTeams = ( config?.teams || [] ).filter( ( t ) => ! t.name.startsWith( '(Retired)' ) );
	const venues = config?.venues || [];
	const seasons = config?.seasons || [];
	const leagues = config?.leagues || [];

	const toggleArray = ( key, value ) => {
		setForm( ( prev ) => {
			const arr = prev[ key ];
			return { ...prev, [ key ]: arr.includes( value ) ? arr.filter( ( v ) => v !== value ) : [ ...arr, value ] };
		} );
	};

	const setArrayField = ( key, ids ) => setForm( ( prev ) => ( { ...prev, [ key ]: ids } ) );

	const handleGenerate = () => {
		setError( '' );
		setLoading( true );
		generateSchedule( { ...form, blackout_dates: form.blackout_dates.split( '\n' ).map( ( d ) => d.trim() ).filter( Boolean ) } )
			.then( ( data ) => { setGames( data.games || data ); setStep( 2 ); } )
			.catch( () => setError( 'Failed to generate schedule' ) )
			.finally( () => setLoading( false ) );
	};

	const handlePublish = () => {
		setError( '' );
		setLoading( true );
		publishSchedule( games, form.season, form.league )
			.then( ( data ) => { setPublishedCount( data.count || games.length ); setStep( 3 ); } )
			.catch( () => setError( 'Failed to publish schedule' ) )
			.finally( () => setLoading( false ) );
	};

	const reset = () => { setStep( 1 ); setGames( [] ); };

	// Rollover handlers
	const handleRolloverPreview = () => {
		setRolloverError( '' ); setRolloverMsg( '' ); setRolloverLoading( true );
		rolloverPreview( rolloverFrom, rolloverTo )
			.then( ( data ) => {
				setRolloverPreviewData( data );
				const sel = {};
				( data.not_returning || [] ).forEach( ( p ) => { sel[ p.id ] = true; } );
				setRolloverSelected( sel );
			} )
			.catch( () => setRolloverError( 'Failed to load preview' ) )
			.finally( () => setRolloverLoading( false ) );
	};

	const handleRolloverExecute = () => {
		const ids = Object.keys( rolloverSelected ).filter( ( k ) => rolloverSelected[ k ] ).map( Number );
		if ( ! ids.length ) return;
		setRolloverError( '' ); setRolloverLoading( true );
		rolloverExecute( rolloverFrom, rolloverTo, ids )
			.then( ( data ) => { setRolloverMsg( `✅ ${ data.count || ids.length } player(s) moved to past teams.` ); setRolloverPreviewData( null ); } )
			.catch( () => setRolloverError( 'Failed to execute rollover' ) )
			.finally( () => setRolloverLoading( false ) );
	};

	const toggleTeamPlayers = ( players, checked ) => {
		setRolloverSelected( ( prev ) => {
			const next = { ...prev };
			players.forEach( ( p ) => { next[ p.id ] = checked; } );
			return next;
		} );
	};

	if ( loading && ! config ) {
		return <div className="splm-loading">Loading…</div>;
	}

	return (
		<>
		<div className="splm-wizard">
			<h2>Schedule Generator</h2>
			{ error && <div className="splm-alert splm-alert--warning">{ error }</div> }

			{ step === 1 && config && (
				<div className="splm-wizard__step">
					<div className="splm-card">
						<h3>Season, League & Dates</h3>
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' } }>
							<div>
								<label>Season</label>
								<select className="splm-select" value={ form.season } onChange={ ( e ) => setForm( { ...form, season: e.target.value } ) }>
									<option value="">Select…</option>
									{ seasons.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.name }</option> ) }
								</select>
							</div>
							<div>
								<label>League</label>
								<select className="splm-select" value={ form.league } onChange={ ( e ) => setForm( { ...form, league: e.target.value } ) }>
									<option value="">Select…</option>
									{ leagues.map( ( l ) => <option key={ l.id } value={ l.id }>{ l.name }</option> ) }
								</select>
							</div>
							<div>
								<label>Start Date</label>
								<input type="date" className="splm-select" value={ form.start_date } onChange={ ( e ) => setForm( { ...form, start_date: e.target.value } ) } />
							</div>
							<div>
								<label>End Date</label>
								<input type="date" className="splm-select" value={ form.end_date } onChange={ ( e ) => setForm( { ...form, end_date: e.target.value } ) } />
							</div>
							<div>
								<label>Games per Team</label>
								<input type="number" className="splm-select" min="1" value={ form.games_per_team } onChange={ ( e ) => setForm( { ...form, games_per_team: parseInt( e.target.value, 10 ) || 0 } ) } />
							</div>
						</div>
					</div>

					<div className="splm-card">
						<h3>Teams ({ form.teams.length } selected)</h3>
						<SelectAllToggle items={ activeTeams } selected={ form.teams } onToggle={ ( ids ) => setArrayField( 'teams', ids ) } />
						<div className="splm-checkbox-grid">
							{ activeTeams.map( ( t ) => (
								<label key={ t.id } className="splm-checkbox">
									<input type="checkbox" checked={ form.teams.includes( t.id ) } onChange={ () => toggleArray( 'teams', t.id ) } />
									{ t.name }
								</label>
							) ) }
						</div>
					</div>

					<div className="splm-card">
						<h3>Venues ({ form.venues.length } selected)</h3>
						<SelectAllToggle items={ venues } selected={ form.venues } onToggle={ ( ids ) => setArrayField( 'venues', ids ) } />
						<div className="splm-checkbox-grid">
							{ venues.map( ( v ) => (
								<label key={ v.id } className="splm-checkbox">
									<input type="checkbox" checked={ form.venues.includes( v.id ) } onChange={ () => toggleArray( 'venues', v.id ) } />
									{ v.name }
								</label>
							) ) }
						</div>
					</div>

					<div className="splm-card">
						<h3>Blackout Dates</h3>
						<textarea className="splm-textarea" rows="3" placeholder="One date per line (YYYY-MM-DD)" value={ form.blackout_dates } onChange={ ( e ) => setForm( { ...form, blackout_dates: e.target.value } ) } />
					</div>

					<div className="splm-wizard__actions">
						<button className="splm-btn splm-btn--primary" onClick={ handleGenerate } disabled={ loading || ! form.teams.length || ! form.season || ! form.league || ! form.start_date || ! form.end_date }>
							{ loading ? 'Generating…' : 'Generate Preview' }
						</button>
					</div>
				</div>
			) }

			{ step === 2 && (
				<div className="splm-wizard__step">
					<div className="splm-card">
						<h3>Preview ({ games.length } games)</h3>
						<div className="splm-table-wrapper">
							<table className="splm-table">
								<thead><tr><th>Date</th><th>Time</th><th>Home</th><th>Away</th><th>Venue</th></tr></thead>
								<tbody>
									{ games.map( ( g, i ) => (
										<tr key={ i }><td>{ g.date }</td><td>{ g.time }</td><td>{ g.home }</td><td>{ g.away }</td><td>{ g.venue }</td></tr>
									) ) }
								</tbody>
							</table>
						</div>
					</div>
					<div className="splm-wizard__actions">
						<button className="splm-btn" onClick={ () => setStep( 1 ) }>Back</button>
						<button className="splm-btn splm-btn--primary" onClick={ handlePublish } disabled={ loading }>
							{ loading ? 'Publishing…' : 'Publish Schedule' }
						</button>
					</div>
				</div>
			) }

			{ step === 3 && (
				<div className="splm-wizard__step">
					<div className="splm-card" style={ { textAlign: 'center', padding: '3rem' } }>
						<p style={ { fontSize: '1.5rem', marginBottom: '1rem' } }>✅ { publishedCount } events created.</p>
						<button className="splm-btn splm-btn--primary" onClick={ reset }>Generate Another</button>
					</div>
				</div>
			) }
		</div>

		{ config && (
			<div className="splm-wizard" style={ { marginTop: '2rem' } }>
				<h2>Season Rollover</h2>
				<p className="splm-muted">Move players who didn't register for the new season from current team to past teams.</p>

				{ rolloverError && <div className="splm-alert splm-alert--warning">{ rolloverError }</div> }
				{ rolloverMsg && <div className="splm-card"><p>{ rolloverMsg }</p></div> }

				<div className="splm-card">
					<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: '0.75rem', alignItems: 'end' } }>
						<div>
							<label>From Season</label>
							<select className="splm-select" value={ rolloverFrom } onChange={ ( e ) => setRolloverFrom( e.target.value ) }>
								<option value="">Select…</option>
								{ seasons.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.name }</option> ) }
							</select>
						</div>
						<div>
							<label>To Season</label>
							<select className="splm-select" value={ rolloverTo } onChange={ ( e ) => setRolloverTo( e.target.value ) }>
								<option value="">Select…</option>
								{ seasons.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.name }</option> ) }
							</select>
						</div>
						<button className="splm-btn splm-btn--primary" onClick={ handleRolloverPreview } disabled={ rolloverLoading || ! rolloverFrom || ! rolloverTo }>
							{ rolloverLoading ? 'Loading…' : 'Preview' }
						</button>
					</div>
				</div>

				{ rolloverPreviewData && (
					<div className="splm-card">
						<p><strong>{ rolloverPreviewData.returning_count || 0 }</strong> returning · <strong>{ rolloverPreviewData.total_not_returning || 0 }</strong> not returning</p>
						{ ( rolloverPreviewData.not_returning || [] ).map( ( group ) => {
							const allChecked = group.players.every( ( p ) => rolloverSelected[ p.id ] );
							return (
								<details key={ group.team_id } style={ { marginBottom: '0.5rem' } }>
									<summary style={ { cursor: 'pointer', fontWeight: 600 } }>
										<label className="splm-checkbox" style={ { display: 'inline' } } onClick={ ( e ) => e.stopPropagation() }>
											<input type="checkbox" checked={ allChecked } onChange={ ( e ) => toggleTeamPlayers( group.players, e.target.checked ) } />
										</label>
										{ group.team } ({ group.players.length })
									</summary>
									<div style={ { paddingLeft: '2rem' } }>
										{ group.players.map( ( p ) => (
											<label key={ p.id } className="splm-checkbox" style={ { display: 'block' } }>
												<input type="checkbox" checked={ !! rolloverSelected[ p.id ] } onChange={ ( e ) => setRolloverSelected( ( prev ) => ( { ...prev, [ p.id ]: e.target.checked } ) ) } />
												{ p.name }
											</label>
										) ) }
									</div>
								</details>
							);
						} ) }
						<button className="splm-btn splm-btn--danger" style={ { marginTop: '1rem' } } onClick={ handleRolloverExecute } disabled={ rolloverLoading || ! Object.values( rolloverSelected ).some( Boolean ) }>
							{ rolloverLoading ? 'Processing…' : 'Move Selected to Past Teams' }
						</button>
					</div>
				) }
			</div>
		) }
		</>
	);
}
