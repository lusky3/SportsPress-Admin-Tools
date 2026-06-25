import { useState } from '@wordpress/element';
import { createSeason, rolloverPreview, rolloverExecute, spsg } from '../lib/api';
import apiFetch from '@wordpress/api-fetch';

const SEASON_REGEX = /^[A-Za-z]?\d{4}(-\d{2,4})?$/;

function previewSeason( seasonName, leagueId, opts ) {
	return apiFetch( { path: '/splm/v1/season/preview', method: 'POST', data: { season_name: seasonName, league_id: leagueId, ...opts } } );
}

export default function SeasonSetup() {
	const [ step, setStep ] = useState( 1 );
	// Step 1: config
	const [ seasonName, setSeasonName ] = useState( '' );
	const [ leagueId, setLeagueId ] = useState( '' );
	const [ createCalendars, setCreateCalendars ] = useState( true );
	const [ createRosters, setCreateRosters ] = useState( false );
	const [ nameError, setNameError ] = useState( '' );
	const [ error, setError ] = useState( '' );
	// Teams
	const [ teams, setTeams ] = useState( [] );
	const [ selectedTeams, setSelectedTeams ] = useState( {} );
	const [ teamsLoading, setTeamsLoading ] = useState( false );
	const [ newTeamName, setNewTeamName ] = useState( '' );
	const [ newTeams, setNewTeams ] = useState( [] ); // { tempId, name }
	let nextTempId = 0;
	// Step 2: preview
	const [ preview, setPreview ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	// Step 3: result + rollover
	const [ result, setResult ] = useState( null );
	const [ rSeasons, setRSeasons ] = useState( [] );
	const [ rFrom, setRFrom ] = useState( '' );
	const [ rTo, setRTo ] = useState( '' );
	const [ rPrev, setRPrev ] = useState( null );
	const [ rSel, setRSel ] = useState( {} );
	const [ rLoad, setRLoad ] = useState( false );
	const [ rMsg, setRMsg ] = useState( '' );
	const [ rErr, setRErr ] = useState( '' );

	const leagues = window.splmDashboard?.leagues || [];

	const handleNameChange = ( val ) => {
		setSeasonName( val );
		setNameError( val && ! SEASON_REGEX.test( val ) ? 'Format: W2025, S2025-26, or 2025' : '' );
	};

	const handleLeagueChange = ( val ) => {
		setLeagueId( val );
		setTeams( [] );
		setSelectedTeams( {} );
		if ( ! val ) return;
		setTeamsLoading( true );
		spsg.getLeagueTeams( val )
			.then( ( t ) => {
				setTeams( t );
				const sel = {};
				t.forEach( ( team ) => { sel[ team.id ] = true; } );
				setSelectedTeams( sel );
			} )
			.catch( () => {} )
			.finally( () => setTeamsLoading( false ) );
	};

	const addNewTeam = () => {
		const name = newTeamName.trim();
		if ( ! name ) return;
		if ( newTeams.some( ( t ) => t.name.toLowerCase() === name.toLowerCase() ) ) return;
		setNewTeams( ( prev ) => [ ...prev, { tempId: `new-${ Date.now() }-${ nextTempId++ }`, name } ] );
		setNewTeamName( '' );
	};

	const removeNewTeam = ( tempId ) => {
		setNewTeams( ( prev ) => prev.filter( ( t ) => t.tempId !== tempId ) );
	};

	const selectedCount = Object.values( selectedTeams ).filter( Boolean ).length + newTeams.length;
	const allSelected = teams.length > 0 && Object.values( selectedTeams ).filter( Boolean ).length === teams.length;

	const canProceed = leagueId && seasonName && ! nameError && selectedCount > 0;

	const handlePreview = () => {
		if ( ! canProceed ) return;
		setError( '' );
		setLoading( true );
		const teamIds = Object.keys( selectedTeams ).filter( ( k ) => selectedTeams[ k ] ).map( Number );
		previewSeason( seasonName, leagueId, {
			team_ids: teamIds,
			new_teams: newTeams.map( ( t ) => t.name ),
			create_calendars: createCalendars,
			create_rosters: createRosters,
		} )
			.then( ( data ) => { setPreview( data ); setStep( 2 ); } )
			.catch( ( err ) => setError( err.message || 'Failed to generate preview.' ) )
			.finally( () => setLoading( false ) );
	};

	const handleExecute = () => {
		setError( '' );
		setLoading( true );
		const teamIds = Object.keys( selectedTeams ).filter( ( k ) => selectedTeams[ k ] ).map( Number );
		createSeason( seasonName, leagueId, {
			createCalendars,
			createRosters,
			teamIds,
			newTeams: newTeams.map( ( t ) => t.name ),
		} )
			.then( ( data ) => {
				setResult( data );
				spsg.getSeasons().then( ( s ) => {
					setRSeasons( s );
					setRTo( String( data.season_id ) );
				} ).catch( () => {} );
				setStep( 3 );
			} )
			.catch( ( err ) => setError( err.message || 'Failed to create season.' ) )
			.finally( () => setLoading( false ) );
	};

	const resetAll = () => {
		setStep( 1 );
		setResult( null );
		setPreview( null );
		setSeasonName( '' );
		setLeagueId( '' );
		setTeams( [] );
		setSelectedTeams( {} );
		setNewTeams( [] );
		setError( '' );
	};

	return (
		<div className="splm-wizard">
			<h2>Season Setup</h2>

			{ /* STEP 1: Configure */ }
			{ step === 1 && (
				<>
					<p className="splm-muted">Create a new season and select which teams are playing.</p>
					{ error && <div className="splm-alert splm-alert--warning">{ error }</div> }
					<div className="splm-card">
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' } }>
							<div>
								<label>League</label>
								<select className="splm-select" value={ leagueId } onChange={ ( e ) => handleLeagueChange( e.target.value ) }>
									<option value="">Select…</option>
									{ leagues.map( ( l ) => <option key={ l.id } value={ l.id }>{ l.name }</option> ) }
								</select>
							</div>
							<div>
								<label>Season Name</label>
								<input
									type="text"
									className="splm-select"
									placeholder="W2025"
									value={ seasonName }
									onChange={ ( e ) => handleNameChange( e.target.value ) }
								/>
								{ nameError && <small style={ { color: 'var(--splm-danger)' } }>{ nameError }</small> }
							</div>
						</div>

						{ teamsLoading && <p style={ { marginTop: '0.75rem' } }>Loading teams…</p> }

						{ teams.length > 0 && (
							<div style={ { marginTop: '1rem' } }>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' } }>
									<strong>Teams ({ selectedCount }/{ teams.length + newTeams.length })</strong>
									<button type="button" className="splm-btn" onClick={ () => {
										const next = {};
										teams.forEach( ( t ) => { next[ t.id ] = ! allSelected; } );
										setSelectedTeams( next );
									} }>{ allSelected ? 'Deselect All' : 'Select All' }</button>
								</div>
								<div style={ { maxHeight: '240px', overflow: 'auto', border: '1px solid var(--splm-border, #ddd)', borderRadius: '4px', padding: '0.5rem' } }>
									{ teams.map( ( t ) => (
										<label key={ t.id } className="splm-checkbox" style={ { display: 'block', padding: '0.2rem 0' } }>
											<input type="checkbox" checked={ !! selectedTeams[ t.id ] } onChange={ ( e ) => setSelectedTeams( ( prev ) => ( { ...prev, [ t.id ]: e.target.checked } ) ) } />
											{ t.name }
										</label>
									) ) }
									{ newTeams.map( ( t ) => (
										<div key={ t.tempId } style={ { display: 'flex', alignItems: 'center', padding: '0.2rem 0' } }>
											<label className="splm-checkbox" style={ { flex: 1 } }>
												<input type="checkbox" checked disabled />
												<strong>{ t.name }</strong> <em>(new)</em>
											</label>
											<button type="button" className="splm-btn" style={ { padding: '0 0.5rem', fontSize: '0.8em' } } onClick={ () => removeNewTeam( t.tempId ) }>✕</button>
										</div>
									) ) }
								</div>
								<div style={ { marginTop: '0.5rem', display: 'flex', gap: '0.5rem' } }>
									<input
										type="text"
										className="splm-select"
										placeholder="New team name"
										value={ newTeamName }
										onChange={ ( e ) => setNewTeamName( e.target.value ) }
										onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); addNewTeam(); } } }
										style={ { flex: 1 } }
									/>
									<button type="button" className="splm-btn" onClick={ addNewTeam } disabled={ ! newTeamName.trim() }>Add Team</button>
								</div>
							</div>
						) }

						<div style={ { marginTop: '0.75rem', display: 'flex', gap: '1.5rem' } }>
							<label className="splm-checkbox">
								<input type="checkbox" checked={ createCalendars } onChange={ ( e ) => setCreateCalendars( e.target.checked ) } />
								Update team calendars to new season
							</label>
							<label className="splm-checkbox">
								<input type="checkbox" checked={ createRosters } onChange={ ( e ) => setCreateRosters( e.target.checked ) } />
								Create empty roster lists
							</label>
						</div>
						<button
							className="splm-btn splm-btn--primary"
							style={ { marginTop: '1rem' } }
							disabled={ loading || ! canProceed }
							onClick={ handlePreview }
						>
							{ loading ? 'Loading…' : 'Review Changes →' }
						</button>
					</div>
				</>
			) }

			{ /* STEP 2: Review */ }
			{ step === 2 && preview && (
				<>
					<p className="splm-muted">Review what will be created and modified.</p>
					{ error && <div className="splm-alert splm-alert--warning">{ error }</div> }
					<div className="splm-card">
						<h3 style={ { marginTop: 0 } }>Summary</h3>
						<table className="splm-table" style={ { width: '100%' } }>
							<tbody>
								<tr><td><strong>Season</strong></td><td>{ preview.season_exists ? `Reuse existing "${ seasonName }"` : `Create "${ seasonName }"` }</td></tr>
								{ preview.new_teams.length > 0 && (
									<tr><td><strong>New teams</strong></td><td>{ preview.new_teams.join( ', ' ) }</td></tr>
								) }
								<tr><td><strong>Assign season to</strong></td><td>{ preview.teams_to_update } team(s)</td></tr>
								{ createCalendars && (
									<>
										<tr><td><strong>Calendars retagged</strong></td><td>{ preview.calendars_to_update } existing</td></tr>
										<tr><td><strong>Calendars created</strong></td><td>{ preview.calendars_to_create } new (teams without a calendar)</td></tr>
									</>
								) }
								{ createRosters && (
									<tr><td><strong>Rosters created</strong></td><td>{ preview.rosters_to_create } new list(s)</td></tr>
								) }
							</tbody>
						</table>

						{ preview.teams_list && preview.teams_list.length > 0 && (
							<details style={ { marginTop: '0.75rem' } }>
								<summary style={ { cursor: 'pointer' } }>Teams ({ preview.teams_list.length })</summary>
								<ul style={ { margin: '0.5rem 0', paddingLeft: '1.5rem' } }>
									{ preview.teams_list.map( ( name, i ) => <li key={ i }>{ name }</li> ) }
								</ul>
							</details>
						) }

						<div style={ { marginTop: '1rem', display: 'flex', gap: '0.75rem' } }>
							<button className="splm-btn" onClick={ () => setStep( 1 ) }>← Back</button>
							<button className="splm-btn splm-btn--primary" disabled={ loading } onClick={ handleExecute }>
								{ loading ? 'Creating…' : 'Confirm & Create Season' }
							</button>
						</div>
					</div>
				</>
			) }

			{ /* STEP 3: Result + Rollover */ }
			{ step === 3 && (
				<>
					{ result && (
						<div className="splm-card" style={ { marginBottom: '1rem' } }>
							<p><strong>✅ Season "{ result.season_name }" created.</strong></p>
							<p>{ result.teams_updated } team(s) updated · { result.calendars_updated || 0 } calendar(s) retagged · { result.calendars_created } new calendar(s) · { result.rosters_created } roster(s)</p>
							{ result.new_teams_created > 0 && <p>{ result.new_teams_created } new team(s) created</p> }
							<button className="splm-btn" onClick={ resetAll }>← Create Another</button>
						</div>
					) }

					<h3>Player Rollover</h3>
					<p className="splm-muted">Move players who didn't register for the new season from current team to past teams.</p>
					{ rErr && <div className="splm-alert splm-alert--warning">{ rErr }</div> }
					{ rMsg && <div className="splm-card"><p>{ rMsg }</p></div> }
					<div className="splm-card">
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: '0.75rem', alignItems: 'end' } }>
							<div>
								<label>From Season</label>
								<select className="splm-select" value={ rFrom } onChange={ ( e ) => setRFrom( e.target.value ) }>
									<option value="">Select…</option>
									{ rSeasons.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.name }</option> ) }
								</select>
							</div>
							<div>
								<label>To Season</label>
								<select className="splm-select" value={ rTo } onChange={ ( e ) => setRTo( e.target.value ) }>
									<option value="">Select…</option>
									{ rSeasons.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.name }</option> ) }
								</select>
							</div>
							<button className="splm-btn splm-btn--primary" disabled={ rLoad || ! rFrom || ! rTo } onClick={ () => {
								setRErr( '' ); setRMsg( '' ); setRLoad( true );
								rolloverPreview( rFrom, rTo )
									.then( ( data ) => { setRPrev( data ); const sel = {}; ( data.not_returning || [] ).forEach( ( g ) => { g.players.forEach( ( p ) => { sel[ p.id ] = true; } ); } ); setRSel( sel ); } )
									.catch( () => setRErr( 'Failed to load preview' ) )
									.finally( () => setRLoad( false ) );
							} }>{ rLoad ? 'Loading…' : 'Preview' }</button>
						</div>
					</div>
					{ rPrev && (
						<div className="splm-card">
							<p><strong>{ rPrev.returning_count || 0 }</strong> returning · <strong>{ rPrev.total_not_returning || 0 }</strong> not returning</p>
							{ ( rPrev.not_returning || [] ).map( ( group ) => {
								const allChecked = group.players.every( ( p ) => rSel[ p.id ] );
								return (
									<details key={ group.team_id } style={ { marginBottom: '0.5rem' } }>
										<summary style={ { cursor: 'pointer', fontWeight: 600 } }>
											<label className="splm-checkbox" style={ { display: 'inline' } } onClick={ ( e ) => e.stopPropagation() }>
												<input type="checkbox" checked={ allChecked } onChange={ ( e ) => {
													setRSel( ( prev ) => { const next = { ...prev }; group.players.forEach( ( p ) => { next[ p.id ] = e.target.checked; } ); return next; } );
												} } />
											</label>
											{ group.team } ({ group.players.length })
										</summary>
										<div style={ { paddingLeft: '2rem' } }>
											{ group.players.map( ( p ) => (
												<label key={ p.id } className="splm-checkbox" style={ { display: 'block' } }>
													<input type="checkbox" checked={ !! rSel[ p.id ] } onChange={ ( e ) => setRSel( ( prev ) => ( { ...prev, [ p.id ]: e.target.checked } ) ) } />
													{ p.name }
												</label>
											) ) }
										</div>
									</details>
								);
							} ) }
							<button className="splm-btn splm-btn--danger" style={ { marginTop: '1rem' } } disabled={ rLoad || ! Object.values( rSel ).some( Boolean ) } onClick={ () => {
								const ids = Object.keys( rSel ).filter( ( k ) => rSel[ k ] ).map( Number );
								if ( ! ids.length ) return;
								setRErr( '' ); setRLoad( true );
								rolloverExecute( rFrom, rTo, ids )
									.then( ( data ) => { setRMsg( `✅ ${ data.count || ids.length } player(s) moved to past teams.` ); setRPrev( null ); } )
									.catch( () => setRErr( 'Failed to execute rollover' ) )
									.finally( () => setRLoad( false ) );
							} }>{ rLoad ? 'Processing…' : 'Move Selected to Past Teams' }</button>
						</div>
					) }
				</>
			) }
		</div>
	);
}
