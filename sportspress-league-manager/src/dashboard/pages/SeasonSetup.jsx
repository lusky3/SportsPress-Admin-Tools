import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import { createSeason, createDivision, fetchTeamsWithDivisions, rolloverPreview, rolloverExecute, spsg } from '../lib/api';
import Toast from '../components/Toast';

const SEASON_REGEX = /^[A-Za-z]?\d{4}(-\d{2,4})?$/;
const NEW_DIVISION = '__new__';

let boxCounter = 0;

/* ─── A division content box: choose which of its teams are playing, add teams ─── */
function DivisionBox( { box, teams, onChange, onRemove } ) {
	const checkedCount = teams.filter( ( t ) => box.teams[ t.id ] ).length;

	const toggleTeam = ( id ) => onChange( { ...box, teams: { ...box.teams, [ id ]: ! box.teams[ id ] } } );
	const setAll = ( val ) => {
		const sel = {};
		teams.forEach( ( t ) => { sel[ t.id ] = val; } );
		onChange( { ...box, teams: sel } );
	};
	const addNewTeam = () => {
		const name = box.newTeamName.trim();
		if ( ! name || box.newTeams.some( ( n ) => n.toLowerCase() === name.toLowerCase() ) ) return;
		onChange( { ...box, newTeams: [ ...box.newTeams, name ], newTeamName: '' } );
	};
	const removeNewTeam = ( name ) => onChange( { ...box, newTeams: box.newTeams.filter( ( n ) => n !== name ) } );

	return (
		<div className="splm-card splm-division-box">
			<div className="splm-division-box__head">
				<h3 style={ { margin: 0 } }>{ box.divisionName }</h3>
				<button type="button" className="splm-btn splm-btn--small" onClick={ onRemove }>Remove</button>
			</div>
			<div className="splm-division-box__teams">
				<div className="splm-division-box__teams-head">
					<strong>Teams { checkedCount > 0 ? `(${ checkedCount } playing)` : '' }</strong>
					{ teams.length > 0 && (
						<span>
							<button type="button" className="splm-linkbtn" onClick={ () => setAll( true ) }>All</button>
							{ ' · ' }
							<button type="button" className="splm-linkbtn" onClick={ () => setAll( false ) }>None</button>
						</span>
					) }
				</div>
				<div className="splm-division-box__team-list">
					{ teams.length === 0 ? (
						<p className="splm-muted">No existing teams in this division — add new ones below.</p>
					) : teams.map( ( t ) => (
						<label key={ t.id } className="splm-checkbox splm-division-box__team">
							<input type="checkbox" checked={ !! box.teams[ t.id ] } onChange={ () => toggleTeam( t.id ) } />
							{ t.name }
						</label>
					) ) }
				</div>
				{ box.newTeams.length > 0 && (
					<div className="splm-division-box__new">
						{ box.newTeams.map( ( n ) => (
							<span key={ n } className="splm-chip">{ n } (new)
								<button type="button" className="splm-chip__x" onClick={ () => removeNewTeam( n ) } aria-label={ `Remove ${ n }` }>✕</button>
							</span>
						) ) }
					</div>
				) }
				<div className="splm-division-box__add">
					<input type="text" className="splm-select" placeholder="Add a new team to this division" value={ box.newTeamName }
						onChange={ ( e ) => onChange( { ...box, newTeamName: e.target.value } ) }
						onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); addNewTeam(); } } } />
					<button type="button" className="splm-btn" onClick={ addNewTeam } disabled={ ! box.newTeamName.trim() }>Add team</button>
				</div>
			</div>
		</div>
	);
}

export default function SeasonSetup() {
	const [ step, setStep ] = useState( 1 ); // 1 build · 2 preview · 3 result
	const [ seasonName, setSeasonName ] = useState( '' );
	const [ nameError, setNameError ] = useState( '' );
	const [ createCalendars, setCreateCalendars ] = useState( true );
	const [ createRosters, setCreateRosters ] = useState( false );
	const [ createPlayoffs, setCreatePlayoffs ] = useState( true );
	const [ boxes, setBoxes ] = useState( [] );

	// Division picker (top of the builder).
	const [ pickValue, setPickValue ] = useState( '' );
	const [ newDivName, setNewDivName ] = useState( '' );
	const [ createdDivisions, setCreatedDivisions ] = useState( [] ); // divisions made on the fly this session
	const [ adding, setAdding ] = useState( false );

	const [ allTeams, setAllTeams ] = useState( null );
	const [ teamsLoading, setTeamsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ toast, setToast ] = useState( null );
	const [ results, setResults ] = useState( null );

	// Rollover (runs after a season is created) — unchanged.
	const [ rSeasons, setRSeasons ] = useState( [] );
	const [ rFrom, setRFrom ] = useState( '' );
	const [ rTo, setRTo ] = useState( '' );
	const [ rPrev, setRPrev ] = useState( null );
	const [ rSel, setRSel ] = useState( {} );
	const [ rLoad, setRLoad ] = useState( false );
	const [ rMsg, setRMsg ] = useState( '' );
	const [ rErr, setRErr ] = useState( '' );

	const cancelledRef = useRef( { cancelled: false } );
	useEffect( () => {
		const ref = cancelledRef.current;
		ref.cancelled = false;
		return () => { ref.cancelled = true; };
	}, [] );

	useEffect( () => {
		setTeamsLoading( true );
		fetchTeamsWithDivisions()
			.then( ( teams ) => { if ( ! cancelledRef.current.cancelled ) setAllTeams( teams ); } )
			.catch( ( err ) => { if ( ! cancelledRef.current.cancelled ) setError( err?.message || 'Failed to load teams.' ); } )
			.finally( () => { if ( ! cancelledRef.current.cancelled ) setTeamsLoading( false ); } );
	}, [] );

	const leagues = window.splmDashboard?.leagues || [];

	// Resolve any sp_league term to its top-level ancestor (walk the parent chain).
	const childToTop = useMemo( () => {
		const byId = {};
		leagues.forEach( ( l ) => { byId[ l.id ] = l; } );
		const resolve = ( id ) => {
			let cur = byId[ id ];
			const seen = new Set();
			while ( cur && cur.parent && byId[ cur.parent ] && ! seen.has( cur.id ) ) {
				seen.add( cur.id );
				cur = byId[ cur.parent ];
			}
			return cur ? cur.id : id;
		};
		const map = {};
		leagues.forEach( ( l ) => { map[ l.id ] = resolve( l.id ); } );
		return map;
	}, [ leagues ] );

	// Teams grouped under their top-level division (so "Division 4" gathers its
	// Div 4A/4B/4C sub-groups instead of showing "divisions inside divisions").
	const teamsByDivision = useMemo( () => {
		const byDiv = {};
		( allTeams || [] ).forEach( ( t ) => {
			const tops = new Set();
			( t.league_ids || [] ).forEach( ( lid ) => { tops.add( childToTop[ lid ] ?? lid ); } );
			tops.forEach( ( top ) => {
				( byDiv[ top ] = byDiv[ top ] || [] ).push( { id: t.id, name: t.name } );
			} );
		} );
		return byDiv;
	}, [ allTeams, childToTop ] );

	// Division options: top-level terms + any created this session, minus the
	// ones already added as boxes.
	const divisionOptions = useMemo( () => {
		const taken = new Set( boxes.map( ( b ) => String( b.divisionId ) ) );
		const opts = leagues
			.filter( ( l ) => ! l.parent )
			.map( ( l ) => ( { id: String( l.id ), name: l.name } ) )
			.concat( createdDivisions.map( ( d ) => ( { id: String( d.id ), name: d.name } ) ) );
		const seen = new Set();
		return opts
			.filter( ( o ) => ! taken.has( o.id ) && ! seen.has( o.id ) && seen.add( o.id ) )
			.sort( ( a, b ) => a.name.localeCompare( b.name ) );
	}, [ leagues, createdDivisions, boxes ] );

	const divName = ( id ) => {
		const l = leagues.find( ( x ) => String( x.id ) === String( id ) ) || createdDivisions.find( ( x ) => String( x.id ) === String( id ) );
		return l ? l.name : String( id );
	};

	const handleNameChange = ( val ) => {
		setSeasonName( val );
		setNameError( val && ! SEASON_REGEX.test( val ) ? 'Format: W2025, S2025-26, or 2025' : '' );
	};

	const spawnBox = ( divisionId, divisionName ) => {
		const list = teamsByDivision[ divisionId ] || [];
		const teams = {};
		list.forEach( ( t ) => { teams[ t.id ] = true; } );
		setBoxes( ( prev ) => [ ...prev, { key: `box-${ boxCounter++ }`, divisionId: String( divisionId ), divisionName, teams, newTeamName: '', newTeams: [] } ] );
	};

	const handleAdd = async () => {
		setError( '' );
		if ( pickValue === NEW_DIVISION ) {
			const name = newDivName.trim();
			if ( ! name ) return;
			setAdding( true );
			try {
				const d = await createDivision( name );
				if ( cancelledRef.current.cancelled ) return;
				setCreatedDivisions( ( prev ) => ( prev.some( ( x ) => String( x.id ) === String( d.id ) ) ? prev : [ ...prev, { id: d.id, name: d.name } ] ) );
				spawnBox( d.id, d.name );
				setNewDivName( '' );
				setPickValue( '' );
			} catch ( err ) {
				setError( err?.message || 'Could not create division.' );
			}
			setAdding( false );
		} else if ( pickValue ) {
			spawnBox( pickValue, divName( pickValue ) );
			setPickValue( '' );
		}
	};

	const updateBox = ( key, next ) => setBoxes( ( prev ) => prev.map( ( b ) => ( b.key === key ? next : b ) ) );
	const removeBox = ( key ) => setBoxes( ( prev ) => prev.filter( ( b ) => b.key !== key ) );

	// A box contributes if it has at least one playing team (existing or new).
	const activeBoxes = boxes.filter( ( b ) => Object.values( b.teams ).some( Boolean ) || b.newTeams.length > 0 );
	const canPreview = !! seasonName && ! nameError && activeBoxes.length > 0;

	const buildPreview = () => activeBoxes.map( ( b ) => {
		const teams = teamsByDivision[ b.divisionId ] || [];
		const playing = teams.filter( ( t ) => b.teams[ t.id ] );
		return { divisionId: b.divisionId, divisionName: b.divisionName, playing, newTeams: b.newTeams };
	} );

	const handleCreate = async () => {
		setBusy( true );
		setError( '' );
		const agg = { teams_updated: 0, calendars_created: 0, calendars_updated: 0, rosters_created: 0, tables_created: 0, new_teams_created: 0, divisions: 0, errors: [] };
		for ( const b of activeBoxes ) {
			const teams = teamsByDivision[ b.divisionId ] || [];
			const teamIds = teams.filter( ( t ) => b.teams[ t.id ] ).map( ( t ) => Number( t.id ) );
			const divisionAssignments = {};
			teamIds.forEach( ( id ) => { divisionAssignments[ id ] = Number( b.divisionId ); } );
			try {
				// eslint-disable-next-line no-await-in-loop
				const data = await createSeason( seasonName, Number( b.divisionId ), {
					createCalendars, createRosters, createPlayoffs,
					teamIds,
					newTeams: b.newTeams,
					newTeamDivisions: b.newTeams.map( () => Number( b.divisionId ) ),
					divisionAssignments,
				} );
				if ( data?.season_id ) {
					agg.divisions++;
					agg.teams_updated += data.teams_updated || 0;
					agg.calendars_created += data.calendars_created || 0;
					agg.calendars_updated += data.calendars_updated || 0;
					agg.rosters_created += data.rosters_created || 0;
					agg.tables_created += data.tables_created || 0;
					agg.new_teams_created += data.new_teams_created || 0;
					agg.season_name = data.season_name || seasonName;
					agg.season_id = data.season_id;
				} else {
					agg.errors.push( `${ b.divisionName }: no season id returned` );
				}
			} catch ( err ) {
				agg.errors.push( `${ b.divisionName }: ${ err?.message || 'failed' }` );
			}
			if ( cancelledRef.current.cancelled ) return;
		}
		setBusy( false );
		if ( agg.divisions === 0 ) {
			setError( agg.errors.join( ' · ' ) || 'Season was not created.' );
			setToast( { message: 'Failed to create season.', type: 'error' } );
			return;
		}
		setResults( agg );
		setToast( { message: `Season "${ agg.season_name }" created across ${ agg.divisions } division(s).`, type: 'success' } );
		if ( agg.season_id ) {
			spsg.getSeasons().then( ( s ) => { if ( ! cancelledRef.current.cancelled ) { setRSeasons( s ); setRTo( String( agg.season_id ) ); } } ).catch( () => {} );
		}
		setStep( 3 );
	};

	const resetAll = () => {
		setStep( 1 );
		setResults( null );
		setSeasonName( '' );
		setNameError( '' );
		setBoxes( [] );
		setCreatedDivisions( [] );
		setPickValue( '' );
		setNewDivName( '' );
		setError( '' );
	};

	const preview = step === 2 ? buildPreview() : [];

	return (
		<div className="splm-wizard">
			<h2>Season Setup</h2>
			<Toast message={ toast?.message } type={ toast?.type } onDismiss={ () => setToast( null ) } />

			{ /* STEP 1 — Build */ }
			{ step === 1 && (
				<>
					<p className="splm-muted">Name the season, then add each division playing this season — pick an existing division or create a new one, click <strong>Add</strong>, and choose its teams in the box that appears.</p>
					{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

					<div className="splm-card">
						<label className="splm-season-setup__season">
							<span className="splm-schedule__filter-label">Season name</span>
							<input type="text" className="splm-select" placeholder="W2025-26" value={ seasonName } onChange={ ( e ) => handleNameChange( e.target.value ) } />
							{ nameError && <small style={ { color: 'var(--splm-danger)' } }>{ nameError }</small> }
						</label>
						<div className="splm-season-setup__opts">
							<label className="splm-checkbox"><input type="checkbox" checked={ createCalendars } onChange={ ( e ) => setCreateCalendars( e.target.checked ) } /> Update team calendars to this season</label>
							<label className="splm-checkbox"><input type="checkbox" checked={ createRosters } onChange={ ( e ) => setCreateRosters( e.target.checked ) } /> Create empty roster lists</label>
							<label className="splm-checkbox"><input type="checkbox" checked={ createPlayoffs } onChange={ ( e ) => setCreatePlayoffs( e.target.checked ) } /> Create Playoffs sub-season</label>
						</div>
					</div>

					{ teamsLoading ? (
						<div className="splm-loading">Loading divisions…</div>
					) : (
						<>
							{ /* Division picker */ }
							<div className="splm-card splm-division-picker">
								<label className="splm-division-picker__select">
									<span className="splm-schedule__filter-label">Add a division</span>
									<select className="splm-select" value={ pickValue } onChange={ ( e ) => setPickValue( e.target.value ) }>
										<option value="">Select a division…</option>
										{ divisionOptions.map( ( d ) => (
											<option key={ d.id } value={ d.id }>{ d.name } ({ ( teamsByDivision[ d.id ] || [] ).length } teams)</option>
										) ) }
										<option value={ NEW_DIVISION }>＋ Create a new division…</option>
									</select>
								</label>
								{ pickValue === NEW_DIVISION && (
									<input type="text" className="splm-select" placeholder="New division name" value={ newDivName }
										onChange={ ( e ) => setNewDivName( e.target.value ) }
										onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); handleAdd(); } } } />
								) }
								<button type="button" className="splm-btn splm-btn--primary" onClick={ handleAdd }
									disabled={ adding || ! pickValue || ( pickValue === NEW_DIVISION && ! newDivName.trim() ) }>
									{ adding ? 'Adding…' : 'Add' }
								</button>
							</div>

							{ boxes.map( ( box ) => (
								<DivisionBox
									key={ box.key }
									box={ box }
									teams={ teamsByDivision[ box.divisionId ] || [] }
									onChange={ ( next ) => updateBox( box.key, next ) }
									onRemove={ () => removeBox( box.key ) }
								/>
							) ) }

							{ boxes.length > 0 && (
								<div className="splm-season-setup__actions">
									<button type="button" className="splm-btn splm-btn--primary" disabled={ ! canPreview } onClick={ () => setStep( 2 ) }>Preview changes →</button>
								</div>
							) }
						</>
					) }
				</>
			) }

			{ /* STEP 2 — Preview */ }
			{ step === 2 && (
				<>
					<p className="splm-muted">Review what will be created, then confirm to write the changes.</p>
					{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
					<div className="splm-card">
						<h3 style={ { marginTop: 0 } }>Season “{ seasonName }”</h3>
						<div className="splm-table-wrapper">
							<table className="splm-table">
								<thead><tr><th scope="col">Division</th><th scope="col">Teams playing</th><th scope="col">New teams</th></tr></thead>
								<tbody>
									{ preview.map( ( p ) => (
										<tr key={ p.divisionId }>
											<td><strong>{ p.divisionName }</strong></td>
											<td>{ p.playing.length }{ p.playing.length > 0 ? `: ${ p.playing.map( ( t ) => t.name ).join( ', ' ) }` : '' }</td>
											<td>{ p.newTeams.length ? p.newTeams.join( ', ' ) : '—' }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
						<ul className="splm-muted" style={ { marginTop: '0.75rem' } }>
							<li>Standings tables will be created for each division above.</li>
							{ createCalendars && <li>Team calendars will be updated to “{ seasonName }”.</li> }
							{ createRosters && <li>Empty roster lists will be created.</li> }
							{ createPlayoffs && <li>A “{ seasonName } Playoffs” sub-season will be created.</li> }
							<li>The site’s default season will be set to “{ seasonName }”.</li>
						</ul>
						<div className="splm-alert splm-alert--warning" role="alert">
							This updates teams across the site and sets the default season. It can’t be undone automatically.
						</div>
						<div className="splm-season-setup__actions">
							<button type="button" className="splm-btn" disabled={ busy } onClick={ () => setStep( 1 ) }>← Back</button>
							<button type="button" className="splm-btn splm-btn--danger" disabled={ busy } onClick={ handleCreate }>
								{ busy ? 'Creating…' : `Create season across ${ preview.length } division(s)` }
							</button>
						</div>
					</div>
				</>
			) }

			{ /* STEP 3 — Result + rollover */ }
			{ step === 3 && (
				<>
					{ results && (
						<div className="splm-card" style={ { marginBottom: '1rem' } }>
							<p><strong>✅ Season “{ results.season_name }” created across { results.divisions } division(s).</strong></p>
							<p>{ results.teams_updated } team(s) updated · { results.tables_created } standings table(s) · { results.calendars_updated } calendar(s) retagged · { results.calendars_created } new calendar(s) · { results.rosters_created } roster(s)</p>
							{ results.new_teams_created > 0 && <p>{ results.new_teams_created } new team(s) created</p> }
							{ results.errors.length > 0 && (
								<div className="splm-alert splm-alert--warning" role="alert">Some divisions had problems: { results.errors.join( ' · ' ) }</div>
							) }
							<button className="splm-btn" onClick={ resetAll }>← Create another</button>
						</div>
					) }

					<h3>Player Rollover</h3>
					<p className="splm-muted">Move players who didn’t register for the new season from their current team to past teams.</p>
					{ rErr && <div className="splm-alert splm-alert--warning" role="alert">{ rErr }</div> }
					{ rMsg && <div className="splm-card"><p>{ rMsg }</p></div> }
					<div className="splm-card">
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: '0.75rem', alignItems: 'end' } }>
							<div>
								<label>From Season</label>
								<select className="splm-select" aria-label="From season" value={ rFrom } onChange={ ( e ) => setRFrom( e.target.value ) }>
									<option value="">Select…</option>
									{ rSeasons.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.name }</option> ) }
								</select>
							</div>
							<div>
								<label>To Season</label>
								<select className="splm-select" aria-label="To season" value={ rTo } onChange={ ( e ) => setRTo( e.target.value ) }>
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
