import { useState, useMemo, useEffect, useCallback } from '@wordpress/element';
import { createSeason, fetchTeamsWithDivisions, rolloverPreview, rolloverExecute, spsg } from '../lib/api';
import { DndContext, closestCenter, DragOverlay, useDroppable } from '@dnd-kit/core';
import { useSortable, SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

const SEASON_REGEX = /^[A-Za-z]?\d{4}(-\d{2,4})?$/;
const NOT_PLAYING = 'not-playing';

/* ─── TeamCard (draggable) ─── */
function TeamCard( { team, columns, divisions, onMoveTo } ) {
	const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable( { id: String( team.id ) } );
	const [ menuOpen, setMenuOpen ] = useState( false );

	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
	};

	const cls = [ 'splm-team-card' ];
	if ( isDragging ) cls.push( 'splm-team-card--dragging' );
	if ( team.isNew ) cls.push( 'splm-team-card--new' );

	const moveTargets = [ ...divisions.map( ( d ) => ( { id: d.id, name: d.name } ) ), { id: NOT_PLAYING, name: 'Not Playing' } ]
		.filter( ( t ) => {
			// Find which column this team is currently in.
			const currentCol = Object.keys( columns ).find( ( col ) => columns[ col ].includes( String( team.id ) ) );
			return String( t.id ) !== currentCol;
		} );

	return (
		<div ref={ setNodeRef } style={ style } className={ cls.join( ' ' ) } { ...attributes }>
			<span className="splm-team-card__grip" { ...listeners }>⠿</span>
			<span className="splm-team-card__name">{ team.name }{ team.isNew ? ' (new)' : '' }</span>
			<span className="splm-team-card__menu">
				<button type="button" className="splm-team-card__menu-btn" onClick={ () => setMenuOpen( ! menuOpen ) } aria-label="Move to">⋮</button>
				{ menuOpen && (
					<div className="splm-team-card__dropdown">
						{ moveTargets.map( ( t ) => (
							<button key={ t.id } type="button" onClick={ () => { onMoveTo( String( team.id ), String( t.id ) ); setMenuOpen( false ); } }>
								{ t.name }
							</button>
						) ) }
					</div>
				) }
			</span>
		</div>
	);
}

/* ─── DivisionColumn (droppable) ─── */
function DivisionColumn( { columnId, label, teamIds, teamsMap, columns, divisions, onMoveTo } ) {
	const { setNodeRef, isOver } = useDroppable( { id: columnId } );
	const cls = [ 'splm-division-col' ];
	if ( isOver ) cls.push( 'splm-division-col--over' );

	return (
		<div ref={ setNodeRef } className={ cls.join( ' ' ) }>
			<div className="splm-division-col__header">
				<span>{ label }</span>
				<span>{ teamIds.length }</span>
			</div>
			<div className="splm-division-col__body">
				<SortableContext items={ teamIds } strategy={ verticalListSortingStrategy }>
					{ teamIds.map( ( id ) => teamsMap[ id ] && (
						<TeamCard key={ id } team={ teamsMap[ id ] } columns={ columns } divisions={ divisions } onMoveTo={ onMoveTo } />
					) ) }
				</SortableContext>
			</div>
		</div>
	);
}

/* ─── DivisionBoard ─── */
function DivisionBoard( { divisions, columns, setColumns, teamsMap } ) {
	const [ activeId, setActiveId ] = useState( null );

	const onMoveTo = useCallback( ( teamId, targetCol ) => {
		setColumns( ( prev ) => {
			const next = {};
			for ( const col in prev ) {
				next[ col ] = prev[ col ].filter( ( id ) => id !== teamId );
			}
			if ( ! next[ targetCol ] ) next[ targetCol ] = [];
			next[ targetCol ] = [ ...next[ targetCol ], teamId ];
			return next;
		} );
	}, [ setColumns ] );

	const handleDragStart = ( event ) => setActiveId( event.active.id );

	const handleDragEnd = ( event ) => {
		setActiveId( null );
		const { active, over } = event;
		if ( ! over ) return;

		const activeTeamId = String( active.id );
		// Determine target column: if dropped over a column, use that; if over a card, find its column.
		let targetCol = null;
		if ( columns[ over.id ] !== undefined ) {
			targetCol = over.id;
		} else {
			targetCol = Object.keys( columns ).find( ( col ) => columns[ col ].includes( String( over.id ) ) );
		}
		if ( ! targetCol ) return;

		const sourceCol = Object.keys( columns ).find( ( col ) => columns[ col ].includes( activeTeamId ) );
		if ( sourceCol === targetCol ) return;

		onMoveTo( activeTeamId, targetCol );
	};

	const activeTeam = activeId ? teamsMap[ activeId ] : null;

	return (
		<DndContext collisionDetection={ closestCenter } onDragStart={ handleDragStart } onDragEnd={ handleDragEnd }>
			<div className="splm-division-board">
				{ divisions.map( ( div ) => (
					<DivisionColumn
						key={ div.id }
						columnId={ String( div.id ) }
						label={ div.name }
						teamIds={ columns[ String( div.id ) ] || [] }
						teamsMap={ teamsMap }
						columns={ columns }
						divisions={ divisions }
						onMoveTo={ onMoveTo }
					/>
				) ) }
				<DivisionColumn
					columnId={ NOT_PLAYING }
					label="Not Playing"
					teamIds={ columns[ NOT_PLAYING ] || [] }
					teamsMap={ teamsMap }
					columns={ columns }
					divisions={ divisions }
					onMoveTo={ onMoveTo }
				/>
			</div>
			<DragOverlay>
				{ activeTeam ? (
					<div className="splm-team-card">
						<span className="splm-team-card__grip">⠿</span>
						<span className="splm-team-card__name">{ activeTeam.name }</span>
					</div>
				) : null }
			</DragOverlay>
		</DndContext>
	);
}

/* ─── Main SeasonSetup ─── */
export default function SeasonSetup() {
	const [ step, setStep ] = useState( 1 );
	// Step 1
	const [ seasonName, setSeasonName ] = useState( '' );
	const [ leagueId, setLeagueId ] = useState( '' );
	const [ createCalendars, setCreateCalendars ] = useState( true );
	const [ createRosters, setCreateRosters ] = useState( false );
	const [ createPlayoffs, setCreatePlayoffs ] = useState( true );
	const [ nameError, setNameError ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ selectedDivisions, setSelectedDivisions ] = useState( {} );
	const [ newTeamName, setNewTeamName ] = useState( '' );
	const [ newTeams, setNewTeams ] = useState( [] );
	let nextTempId = 0;
	// Step 2
	const [ columns, setColumns ] = useState( {} );
	const [ teamsMap, setTeamsMap ] = useState( {} );
	const [ allTeams, setAllTeams ] = useState( [] );
	const [ teamsLoading, setTeamsLoading ] = useState( false );
	// Step 3/4
	const [ loading, setLoading ] = useState( false );
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

	// Compute leaf divisions (terms that are not parents of any other term).
	const leafDivisions = useMemo( () => {
		const parentIds = new Set( leagues.filter( ( l ) => l.parent ).map( ( l ) => l.parent ) );
		return leagues.filter( ( l ) => ! parentIds.has( l.id ) );
	}, [ leagues ] );

	// Divisions selected for this season.
	const activeDivisions = useMemo( () => {
		return leafDivisions.filter( ( d ) => selectedDivisions[ d.id ] );
	}, [ leafDivisions, selectedDivisions ] );

	const handleNameChange = ( val ) => {
		setSeasonName( val );
		setNameError( val && ! SEASON_REGEX.test( val ) ? 'Format: W2025, S2025-26, or 2025' : '' );
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

	const canProceedStep1 = leagueId && seasonName && ! nameError && activeDivisions.length > 0;

	// Transition to Step 2: fetch teams and build board state.
	const goToStep2 = () => {
		if ( ! canProceedStep1 ) return;
		setError( '' );
		setTeamsLoading( true );

		fetchTeamsWithDivisions()
			.then( ( teams ) => {
				setAllTeams( teams );
				const map = {};
				const cols = {};

				// Initialize columns for selected divisions + not-playing.
				activeDivisions.forEach( ( d ) => { cols[ String( d.id ) ] = []; } );
				cols[ NOT_PLAYING ] = [];

				// Place existing teams.
				teams.forEach( ( t ) => {
					map[ String( t.id ) ] = { id: String( t.id ), name: t.name, originalDivision: t.division_id, isNew: false };
					const divStr = t.division_id ? String( t.division_id ) : null;
					if ( divStr && cols[ divStr ] ) {
						cols[ divStr ].push( String( t.id ) );
					} else {
						cols[ NOT_PLAYING ].push( String( t.id ) );
					}
				} );

				// Place new teams in Not Playing.
				newTeams.forEach( ( t ) => {
					map[ t.tempId ] = { id: t.tempId, name: t.name, originalDivision: null, isNew: true };
					cols[ NOT_PLAYING ].push( t.tempId );
				} );

				setTeamsMap( map );
				setColumns( cols );
				setStep( 2 );
			} )
			.catch( ( err ) => setError( err.message || 'Failed to load teams.' ) )
			.finally( () => setTeamsLoading( false ) );
	};

	// Compute review summary for Step 3.
	const reviewSummary = useMemo( () => {
		if ( step < 3 ) return null;
		const unchanged = [];
		const moved = [];
		const newAssigned = [];
		const notPlaying = columns[ NOT_PLAYING ] || [];

		activeDivisions.forEach( ( div ) => {
			const col = columns[ String( div.id ) ] || [];
			col.forEach( ( teamId ) => {
				const team = teamsMap[ teamId ];
				if ( ! team ) return;
				if ( team.isNew ) {
					newAssigned.push( { name: team.name, divName: div.name } );
				} else if ( team.originalDivision === div.id ) {
					unchanged.push( team.name );
				} else {
					const origDiv = leafDivisions.find( ( d ) => d.id === team.originalDivision );
					moved.push( { name: team.name, from: origDiv ? origDiv.name : 'Unassigned', to: div.name } );
				}
			} );
		} );

		return { unchanged, moved, newAssigned, notPlaying: notPlaying.length };
	}, [ step, columns, teamsMap, activeDivisions, leafDivisions ] );

	const handleExecute = () => {
		setError( '' );
		setLoading( true );

		// Collect team_ids from all division columns (exclude not-playing).
		const teamIds = [];
		const divisionAssignments = {};

		activeDivisions.forEach( ( div ) => {
			( columns[ String( div.id ) ] || [] ).forEach( ( teamId ) => {
				const team = teamsMap[ teamId ];
				if ( ! team ) return;
				if ( ! team.isNew ) {
					teamIds.push( Number( teamId ) );
					// Only assign if division changed.
					if ( team.originalDivision !== div.id ) {
						divisionAssignments[ teamId ] = div.id;
					}
				}
			} );
		} );

		// New teams — pass names and their target divisions will be assigned after creation.
		const newTeamNames = [];
		const newTeamDivTargets = [];
		activeDivisions.forEach( ( div ) => {
			( columns[ String( div.id ) ] || [] ).forEach( ( teamId ) => {
				const team = teamsMap[ teamId ];
				if ( team && team.isNew ) {
					newTeamNames.push( team.name );
					newTeamDivTargets.push( div.id );
				}
			} );
		} );

		createSeason( seasonName, leagueId, {
			createCalendars,
			createRosters,
			createPlayoffs,
			teamIds,
			newTeams: newTeamNames,
			divisionAssignments,
		} )
			.then( ( data ) => {
				setResult( data );
				spsg.getSeasons().then( ( s ) => {
					setRSeasons( s );
					setRTo( String( data.season_id ) );
				} ).catch( () => {} );
				setStep( 4 );
			} )
			.catch( ( err ) => setError( err.message || 'Failed to create season.' ) )
			.finally( () => setLoading( false ) );
	};

	const resetAll = () => {
		setStep( 1 );
		setResult( null );
		setSeasonName( '' );
		setLeagueId( '' );
		setSelectedDivisions( {} );
		setNewTeams( [] );
		setColumns( {} );
		setTeamsMap( {} );
		setError( '' );
	};

	return (
		<div className="splm-wizard">
			<h2>Season Setup</h2>

			{ /* STEP 1: Configure */ }
			{ step === 1 && (
				<>
					<p className="splm-muted">Create a new season, select divisions, and add teams.</p>
					{ error && <div className="splm-alert splm-alert--warning">{ error }</div> }
					<div className="splm-card">
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' } }>
							<div>
								<label>League</label>
								<select className="splm-select" value={ leagueId } onChange={ ( e ) => setLeagueId( e.target.value ) }>
									<option value="">Select…</option>
									{ leagues.filter( ( l ) => ! l.parent ).map( ( l ) => <option key={ l.id } value={ l.id }>{ l.name }</option> ) }
								</select>
							</div>
							<div>
								<label>Season Name</label>
								<input type="text" className="splm-select" placeholder="W2025" value={ seasonName } onChange={ ( e ) => handleNameChange( e.target.value ) } />
								{ nameError && <small style={ { color: 'var(--splm-danger)' } }>{ nameError }</small> }
							</div>
						</div>

						{ leagueId && (
							<div style={ { marginTop: '1rem' } }>
								<strong>Divisions</strong>
								<div style={ { maxHeight: '180px', overflow: 'auto', border: '1px solid var(--splm-border, #ddd)', borderRadius: '4px', padding: '0.5rem', marginTop: '0.25rem' } }>
									{ leafDivisions.map( ( d ) => (
										<label key={ d.id } className="splm-checkbox" style={ { display: 'block', padding: '0.2rem 0' } }>
											<input type="checkbox" checked={ !! selectedDivisions[ d.id ] } onChange={ ( e ) => setSelectedDivisions( ( prev ) => ( { ...prev, [ d.id ]: e.target.checked } ) ) } />
											{ d.name }
										</label>
									) ) }
								</div>
							</div>
						) }

						<div style={ { marginTop: '0.75rem' } }>
							<strong>New Teams</strong>
							{ newTeams.map( ( t ) => (
								<div key={ t.tempId } style={ { display: 'flex', alignItems: 'center', padding: '0.2rem 0' } }>
									<span style={ { flex: 1 } }><em>{ t.name }</em></span>
									<button type="button" className="splm-btn" style={ { padding: '0 0.5rem', fontSize: '0.8em' } } onClick={ () => removeNewTeam( t.tempId ) }>✕</button>
								</div>
							) ) }
							<div style={ { marginTop: '0.5rem', display: 'flex', gap: '0.5rem' } }>
								<input type="text" className="splm-select" placeholder="New team name" value={ newTeamName } onChange={ ( e ) => setNewTeamName( e.target.value ) } onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); addNewTeam(); } } } style={ { flex: 1 } } />
								<button type="button" className="splm-btn" onClick={ addNewTeam } disabled={ ! newTeamName.trim() }>Add Team</button>
							</div>
						</div>

						<div style={ { marginTop: '0.75rem', display: 'flex', gap: '1.5rem', flexWrap: 'wrap' } }>
							<label className="splm-checkbox">
								<input type="checkbox" checked={ createCalendars } onChange={ ( e ) => setCreateCalendars( e.target.checked ) } />
								Update team calendars to new season
							</label>
							<label className="splm-checkbox">
								<input type="checkbox" checked={ createRosters } onChange={ ( e ) => setCreateRosters( e.target.checked ) } />
								Create empty roster lists
							</label>
							<label className="splm-checkbox">
								<input type="checkbox" checked={ createPlayoffs } onChange={ ( e ) => setCreatePlayoffs( e.target.checked ) } />
								Create Playoffs sub-season
							</label>
						</div>
						<button className="splm-btn splm-btn--primary" style={ { marginTop: '1rem' } } disabled={ teamsLoading || ! canProceedStep1 } onClick={ goToStep2 }>
							{ teamsLoading ? 'Loading…' : 'Assign Divisions →' }
						</button>
					</div>
				</>
			) }

			{ /* STEP 2: Division Assignment */ }
			{ step === 2 && (
				<>
					<p className="splm-muted">Drag teams between divisions. Use the ⋮ menu for keyboard/touch fallback.</p>
					{ error && <div className="splm-alert splm-alert--warning">{ error }</div> }
					<DivisionBoard divisions={ activeDivisions } columns={ columns } setColumns={ setColumns } teamsMap={ teamsMap } />
					<div style={ { marginTop: '1rem', display: 'flex', gap: '0.75rem' } }>
						<button className="splm-btn" onClick={ () => setStep( 1 ) }>← Back</button>
						<button className="splm-btn splm-btn--primary" onClick={ () => setStep( 3 ) }>Review →</button>
					</div>
				</>
			) }

			{ /* STEP 3: Review */ }
			{ step === 3 && reviewSummary && (
				<>
					<p className="splm-muted">Review what will be created and modified.</p>
					{ error && <div className="splm-alert splm-alert--warning">{ error }</div> }
					<div className="splm-card">
						<h3 style={ { marginTop: 0 } }>Summary</h3>
						<table className="splm-table" style={ { width: '100%' } }>
							<tbody>
								<tr><td><strong>Season</strong></td><td>{ seasonName }</td></tr>
								<tr><td><strong>Unchanged</strong></td><td>{ reviewSummary.unchanged.length } team(s) stay in their division</td></tr>
								{ reviewSummary.moved.length > 0 && (
									<tr><td><strong>Moved</strong></td><td>{ reviewSummary.moved.map( ( m ) => `${ m.name } (${ m.from } → ${ m.to })` ).join( ', ' ) }</td></tr>
								) }
								{ reviewSummary.newAssigned.length > 0 && (
									<tr><td><strong>New</strong></td><td>{ reviewSummary.newAssigned.map( ( n ) => `${ n.name } → ${ n.divName }` ).join( ', ' ) }</td></tr>
								) }
								<tr><td><strong>Not playing</strong></td><td>{ reviewSummary.notPlaying } team(s)</td></tr>
								{ createCalendars && <tr><td><strong>Calendars</strong></td><td>Will be updated to new season</td></tr> }
								{ createRosters && <tr><td><strong>Rosters</strong></td><td>Empty roster lists will be created</td></tr> }
								{ createPlayoffs && <tr><td><strong>Playoffs</strong></td><td>{ seasonName } Playoffs sub-season will be created</td></tr> }
								<tr><td><strong>Standings</strong></td><td>League tables created for each active division</td></tr>
								<tr><td><strong>Current season</strong></td><td>Site default season will be set to { seasonName }</td></tr>
							</tbody>
						</table>
						<div style={ { marginTop: '1rem', display: 'flex', gap: '0.75rem' } }>
							<button className="splm-btn" onClick={ () => setStep( 2 ) }>← Back</button>
							<button className="splm-btn splm-btn--primary" disabled={ loading } onClick={ handleExecute }>
								{ loading ? 'Creating…' : 'Confirm & Create Season' }
							</button>
						</div>
					</div>
				</>
			) }

			{ /* STEP 4: Result + Rollover */ }
			{ step === 4 && (
				<>
					{ result && (
						<div className="splm-card" style={ { marginBottom: '1rem' } }>
							<p><strong>✅ Season "{ result.season_name }" created.</strong></p>
							<p>{ result.teams_updated } team(s) updated · { result.calendars_updated || 0 } calendar(s) retagged · { result.calendars_created } new calendar(s) · { result.rosters_created } roster(s) · { result.tables_created || 0 } standings table(s)</p>
							{ result.new_teams_created > 0 && <p>{ result.new_teams_created } new team(s) created</p> }
							{ result.playoffs_created && <p>Playoffs sub-season created</p> }
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
