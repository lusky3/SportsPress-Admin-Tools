import { useState, useMemo, useRef, useEffect, useCallback } from '@wordpress/element';
import { createSeason, fetchTeamsWithDivisions, rolloverPreview, rolloverExecute, spsg } from '../lib/api';
import { DndContext, closestCenter, DragOverlay, useDroppable, PointerSensor, KeyboardSensor, useSensor, useSensors } from '@dnd-kit/core';
import { useSortable, SortableContext, verticalListSortingStrategy, sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import Toast from '../components/Toast';

const SEASON_REGEX = /^[A-Za-z]?\d{4}(-\d{2,4})?$/;
const NOT_PLAYING = 'not-playing';

/* ─── TeamCard (draggable) ─── */
function TeamCard( { team, columns, divisions, onMoveTo } ) {
	const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable( { id: String( team.id ) } );
	const [ menuOpen, setMenuOpen ] = useState( false );
	const menuWrapRef = useRef( null );
	const menuRef = useRef( null );
	const menuBtnRef = useRef( null );

	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
	};

	const cls = [ 'splm-team-card' ];
	if ( isDragging ) cls.push( 'splm-team-card--dragging' );
	if ( team.isNew ) cls.push( 'splm-team-card--new' );

	const moveTargets = [ ...divisions.map( ( d ) => ( { id: d.id, name: d.name } ) ), { id: NOT_PLAYING, name: 'Not Playing' } ]
		.filter( ( t ) => {
			// Find which column this team is currently in. Normalize both sides to
			// String so a numeric term id never mismatches the stringified column key.
			const currentCol = Object.keys( columns ).find( ( col ) => columns[ col ].includes( String( team.id ) ) );
			return String( t.id ) !== String( currentCol );
		} );

	// Move focus into the menu when it opens; close on Escape or outside click.
	useEffect( () => {
		if ( ! menuOpen ) return undefined;
		const first = menuRef.current?.querySelector( 'button' );
		first?.focus();
		const onKeyDown = ( e ) => {
			if ( e.key === 'Escape' ) {
				setMenuOpen( false );
				menuBtnRef.current?.focus();
			}
		};
		const onClickOutside = ( e ) => {
			if ( menuWrapRef.current && ! menuWrapRef.current.contains( e.target ) ) {
				setMenuOpen( false );
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		document.addEventListener( 'mousedown', onClickOutside );
		return () => {
			document.removeEventListener( 'keydown', onKeyDown );
			document.removeEventListener( 'mousedown', onClickOutside );
		};
	}, [ menuOpen ] );

	return (
		<div ref={ setNodeRef } style={ style } className={ cls.join( ' ' ) } { ...attributes }>
			<span className="splm-team-card__grip" { ...listeners }>⠿</span>
			<span className="splm-team-card__name">{ team.name }{ team.isNew ? ' (new)' : '' }</span>
			<span className="splm-team-card__menu" ref={ menuWrapRef }>
				<button ref={ menuBtnRef } type="button" className="splm-team-card__menu-btn" onClick={ () => setMenuOpen( ! menuOpen ) } aria-label={ `Move ${ team.name } to division` } aria-haspopup="true" aria-expanded={ menuOpen }>⋮</button>
				{ menuOpen && (
					<div className="splm-team-card__dropdown" ref={ menuRef }>
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

	// PointerSensor for mouse/touch (with a small activation distance so clicks on
	// the ⋮ menu still register), KeyboardSensor for full keyboard drag support.
	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 5 } } ),
		useSensor( KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates } )
	);

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

	const handleDragStart = ( event ) => setActiveId( String( event.active.id ) );

	const handleDragEnd = ( event ) => {
		setActiveId( null );
		const { active, over } = event;
		if ( ! over ) return;

		const activeTeamId = String( active.id );
		// Determine target column: if dropped over a column, use that; if over a card, find its column.
		const overId = String( over.id );
		let targetCol = null;
		if ( columns[ overId ] !== undefined ) {
			targetCol = overId;
		} else {
			targetCol = Object.keys( columns ).find( ( col ) => columns[ col ].includes( overId ) );
		}
		if ( ! targetCol ) return;

		const sourceCol = Object.keys( columns ).find( ( col ) => columns[ col ].includes( activeTeamId ) );
		if ( sourceCol === targetCol ) return;

		onMoveTo( activeTeamId, targetCol );
	};

	const activeTeam = activeId ? teamsMap[ activeId ] : null;

	return (
		<DndContext sensors={ sensors } collisionDetection={ closestCenter } onDragStart={ handleDragStart } onDragEnd={ handleDragEnd }>
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
	// Monotonic counter for new-team temp ids — a ref so it survives re-renders and
	// stays unique even when two teams are added within the same millisecond.
	const nextTempId = useRef( 0 );
	// Step 2
	const [ columns, setColumns ] = useState( {} );
	const [ teamsMap, setTeamsMap ] = useState( {} );
	const [ teamsLoading, setTeamsLoading ] = useState( false );
	// Step 3/4
	const [ loading, setLoading ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ toast, setToast ] = useState( null ); // UI-13: { message, type }
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

	// Unmount guard — async chains check `cancelledRef.current.cancelled` before
	// any setState so a late resolve/reject never touches an unmounted component.
	const cancelledRef = useRef( { cancelled: false } );
	useEffect( () => {
		const ref = cancelledRef.current;
		ref.cancelled = false;
		return () => { ref.cancelled = true; };
	}, [] );

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
		setNewTeams( ( prev ) => [ ...prev, { tempId: `new-${ Date.now() }-${ nextTempId.current++ }`, name } ] );
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
				if ( cancelledRef.current.cancelled ) return;
				const map = {};
				const cols = {};

				// Initialize columns for selected divisions + not-playing.
				activeDivisions.forEach( ( d ) => { cols[ String( d.id ) ] = []; } );
				cols[ NOT_PLAYING ] = [];

				// Place existing teams.
				teams.forEach( ( t ) => {
					map[ String( t.id ) ] = { id: String( t.id ), name: t.name, originalDivision: t.division_id != null ? String( t.division_id ) : null, isNew: false };
					const divStr = t.division_id != null ? String( t.division_id ) : null;
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
			.catch( ( err ) => { if ( ! cancelledRef.current.cancelled ) setError( err?.message || 'Failed to load teams.' ); } )
			.finally( () => { if ( ! cancelledRef.current.cancelled ) setTeamsLoading( false ); } );
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
				} else if ( String( team.originalDivision ) === String( div.id ) ) {
					unchanged.push( team.name );
				} else {
					const origDiv = leafDivisions.find( ( d ) => String( d.id ) === String( team.originalDivision ) );
					moved.push( { name: team.name, from: origDiv ? origDiv.name : 'Unassigned', to: div.name } );
				}
			} );
		} );

		return { unchanged, moved, newAssigned, notPlaying: notPlaying.length };
	}, [ step, columns, teamsMap, activeDivisions, leafDivisions ] );

	// At least one active division must contain a team before we let the
	// destructive site-wide create run.
	const hasAssignedTeams = useMemo( () => {
		return activeDivisions.some( ( div ) => ( columns[ String( div.id ) ] || [] ).length > 0 );
	}, [ activeDivisions, columns ] );

	const handleExecute = () => {
		setConfirming( false );
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
					// Only assign if division changed (compare as strings to avoid
					// number-vs-string mismatches).
					if ( String( team.originalDivision ) !== String( div.id ) ) {
						divisionAssignments[ teamId ] = div.id;
					}
				}
			} );
		} );

		// New teams — names and their target division term ids are kept index-aligned
		// so the server can assign each new team to its division.
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
			newTeamDivisions: newTeamDivTargets,
			divisionAssignments,
		} )
			.then( ( data ) => {
				if ( cancelledRef.current.cancelled ) return;
				// Guard the result shape — only advance on a real season id.
				if ( ! data?.season_id ) {
					setError( 'Season was not created (no season id returned).' );
					setToast( { message: 'Failed to create season.', type: 'error' } );
					return;
				}
				setResult( data );
				setToast( { message: `Season "${ data.season_name || seasonName }" created.`, type: 'success' } );
				spsg.getSeasons().then( ( s ) => {
					if ( cancelledRef.current.cancelled ) return;
					setRSeasons( s );
					setRTo( String( data.season_id ) );
				} ).catch( () => {} );
				setStep( 4 );
			} )
			.catch( ( err ) => {
				if ( cancelledRef.current.cancelled ) return;
				setError( err?.message || 'Failed to create season.' );
				setToast( { message: err?.message || 'Failed to create season.', type: 'error' } );
			} )
			.finally( () => { if ( ! cancelledRef.current.cancelled ) setLoading( false ); } );
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
		setConfirming( false );
	};

	return (
		<div className="splm-wizard">
			<h2>Season Setup</h2>
			<Toast message={ toast?.message } type={ toast?.type } onDismiss={ () => setToast( null ) } />

			{ /* STEP 1: Configure */ }
			{ step === 1 && (
				<>
					<p className="splm-muted">Create a new season, select divisions, and add teams.</p>
					{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
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
								<div style={ { maxHeight: '180px', overflow: 'auto', border: '1px solid var(--splm-border)', borderRadius: '4px', padding: '0.5rem', marginTop: '0.25rem' } }>
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
					<p className="splm-muted">Drag teams between divisions, or use the ⋮ menu (keyboard/touch fallback). Teams can also be dragged with the keyboard: focus a card and press Space, then arrow keys.</p>
					{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
					{ Object.keys( teamsMap ).length === 0 ? (
						<p className="splm-empty">No teams found. Add new teams in the previous step, or create teams in SportsPress first.</p>
					) : (
						<DivisionBoard divisions={ activeDivisions } columns={ columns } setColumns={ setColumns } teamsMap={ teamsMap } />
					) }
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
					{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
					{ ! hasAssignedTeams && <div className="splm-alert splm-alert--warning" role="alert">Assign at least one team to a division before creating the season.</div> }
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
						{ confirming && (
							<div className="splm-alert splm-alert--warning" role="alert" style={ { marginTop: '1rem' } }>
								This sets the site-wide default season and updates teams across the site. This cannot be undone automatically.
							</div>
						) }
						<div style={ { marginTop: '1rem', display: 'flex', gap: '0.75rem' } }>
							<button className="splm-btn" onClick={ () => { setConfirming( false ); setStep( 2 ); } }>← Back</button>
							{ ! confirming ? (
								<button className="splm-btn splm-btn--primary" disabled={ loading || ! hasAssignedTeams } onClick={ () => setConfirming( true ) }>
									Confirm & Create Season
								</button>
							) : (
								<>
									<button className="splm-btn" disabled={ loading } onClick={ () => setConfirming( false ) }>Cancel</button>
									<button className="splm-btn splm-btn--danger" disabled={ loading || ! hasAssignedTeams } onClick={ handleExecute }>
										{ loading ? 'Creating…' : 'Yes, create season' }
									</button>
								</>
							) }
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
					{ rErr && <div className="splm-alert splm-alert--warning" role="alert">{ rErr }</div> }
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
									.then( ( data ) => {
										if ( cancelledRef.current.cancelled ) return;
										setRPrev( data );
										const sel = {};
										( data?.not_returning || [] ).forEach( ( g ) => { ( g.players || [] ).forEach( ( p ) => { sel[ p.id ] = true; } ); } );
										setRSel( sel );
									} )
									.catch( ( err ) => { if ( ! cancelledRef.current.cancelled ) setRErr( err?.message || 'Failed to load preview' ); } )
									.finally( () => { if ( ! cancelledRef.current.cancelled ) setRLoad( false ); } );
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
									.then( ( data ) => {
										if ( cancelledRef.current.cancelled ) return;
										const moved = data?.count || ids.length;
										setRMsg( `${ moved } player(s) moved to past teams.` );
										setToast( { message: `${ moved } player(s) moved to past teams.`, type: 'success' } );
										setRPrev( null );
									} )
									.catch( ( err ) => {
										if ( cancelledRef.current.cancelled ) return;
										setRErr( err?.message || 'Failed to execute rollover' );
										setToast( { message: err?.message || 'Failed to execute rollover', type: 'error' } );
									} )
									.finally( () => { if ( ! cancelledRef.current.cancelled ) setRLoad( false ); } );
							} }>{ rLoad ? 'Processing…' : 'Move Selected to Past Teams' }</button>
						</div>
					) }
				</>
			) }
		</div>
	);
}
