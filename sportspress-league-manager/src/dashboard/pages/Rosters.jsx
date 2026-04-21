import { useState, useEffect } from '@wordpress/element';
import { fetchTeams, fetchRosterDetails, fetchNotes, addNote, movePlayer, updatePlayer, updatePlayerMetadata, setCaptain, removePlayer, importRoster } from '../lib/api';

function NotesPanel( { player, onClose } ) {
	const [ notes, setNotes ] = useState( [] );
	const [ content, setContent ] = useState( '' );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		fetchNotes( player.id ).then( ( data ) => {
			setNotes( data );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [ player.id ] );

	const handleSubmit = () => {
		if ( ! content.trim() ) return;
		addNote( player.id, content ).then( ( note ) => {
			setNotes( [ ...notes, note ] );
			setContent( '' );
		} );
	};

	return (
		<div className="splm-notes-panel">
			<div className="splm-notes-panel__overlay" onClick={ onClose }></div>
			<div className="splm-notes-panel__content">
				<div className="splm-notes-panel__header">
					<h3>Notes — { player.name }</h3>
					<button className="splm-btn splm-btn--small" onClick={ onClose }>✕</button>
				</div>
				{ loading ? (
					<div className="splm-loading">Loading...</div>
				) : (
					<div className="splm-notes-panel__list">
						{ notes.map( ( n, i ) => (
							<div key={ i } className="splm-notes-panel__note">
								<span className="splm-notes-panel__date">{ n.date }</span>
								<p>{ n.content }</p>
							</div>
						) ) }
					</div>
				) }
				<textarea
					className="splm-textarea"
					value={ content }
					onChange={ ( e ) => setContent( e.target.value ) }
					placeholder="Add a note..."
					rows="3"
				/>
				<button className="splm-btn splm-btn--primary" onClick={ handleSubmit }>Add Note</button>
			</div>
		</div>
	);
}

function MoveModal( { player, teams, currentTeam, onClose, onMoved } ) {
	const [ toTeam, setToTeam ] = useState( '' );

	const handleMove = () => {
		if ( ! toTeam ) return;
		movePlayer( player.id, currentTeam, toTeam ).then( () => {
			onMoved();
			onClose();
		} );
	};

	return (
		<div className="splm-modal-overlay" onClick={ onClose }>
			<div className="splm-modal" onClick={ ( e ) => e.stopPropagation() }>
				<h3>Move { player.name }</h3>
				<label>Move to team:</label>
				<select className="splm-select" value={ toTeam } onChange={ ( e ) => setToTeam( e.target.value ) }>
					<option value="">Select team...</option>
					{ teams.filter( ( t ) => t.id !== currentTeam ).map( ( t ) => (
						<option key={ t.id } value={ t.id }>{ t.name }</option>
					) ) }
				</select>
				<div className="splm-modal__actions">
					<button className="splm-btn" onClick={ onClose }>Cancel</button>
					<button className="splm-btn splm-btn--primary" onClick={ handleMove }>Move</button>
				</div>
			</div>
		</div>
	);
}

function EditableCell( { value, field, playerId, onSaved } ) {
	const [ editing, setEditing ] = useState( false );
	const [ val, setVal ] = useState( value );

	const save = () => {
		setEditing( false );
		if ( val !== value ) {
			updatePlayer( playerId, field, val ).then( () => onSaved( field, val ) );
		}
	};

	if ( editing ) {
		return (
			<input
				className="splm-inline-edit"
				value={ val }
				onChange={ ( e ) => setVal( e.target.value ) }
				onBlur={ save }
				onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) save(); } }
				autoFocus
			/>
		);
	}
	return <span onClick={ () => setEditing( true ) } style={ { cursor: 'pointer' } }>{ value || '—' }</span>;
}

function SkillCell( { value, playerId, onSaved } ) {
	const [ editing, setEditing ] = useState( false );
	const [ val, setVal ] = useState( value || '' );

	const save = ( newVal ) => {
		setEditing( false );
		if ( newVal !== value ) {
			updatePlayerMetadata( playerId, 'skill_level', newVal ).then( () => onSaved( 'skill_level', newVal ) );
		}
	};

	if ( editing ) {
		return (
			<select
				className="splm-inline-edit"
				value={ val }
				onChange={ ( e ) => { setVal( e.target.value ); save( e.target.value ); } }
				onBlur={ () => save( val ) }
				autoFocus
			>
				<option value="">—</option>
				<option value="Beginner">Beginner</option>
				<option value="Intermediate">Intermediate</option>
				<option value="Advanced">Advanced</option>
			</select>
		);
	}
	return <span onClick={ () => setEditing( true ) } style={ { cursor: 'pointer' } }>{ value || '—' }</span>;
}

function CSVUpload( { teamId, seasonId, onImported } ) {
	const [ show, setShow ] = useState( false );
	const [ preview, setPreview ] = useState( null );

	const handleFile = ( e ) => {
		const file = e.target.files[ 0 ];
		if ( ! file ) return;
		const reader = new FileReader();
		reader.onload = ( ev ) => {
			const lines = ev.target.result.trim().split( '\n' ).filter( ( l ) => l.trim() );
			const rows = lines.map( ( line ) => {
				const cols = line.split( ',' ).map( ( c ) => c.trim() );
				return { name: cols[ 0 ] || '', number: cols[ 1 ] || '', email: cols[ 2 ] || '', position: cols[ 3 ] || '' };
			} );
			// Skip header if first row looks like a header
			if ( rows.length && rows[ 0 ].name.toLowerCase() === 'name' ) rows.shift();
			setPreview( rows );
		};
		reader.readAsText( file );
	};

	const handleImport = () => {
		importRoster( teamId, seasonId, preview ).then( () => {
			setPreview( null );
			setShow( false );
			onImported();
		} );
	};

	if ( ! show ) {
		return <button className="splm-btn" onClick={ () => setShow( true ) }>Upload CSV</button>;
	}

	return (
		<div className="splm-csv-upload">
			<input type="file" accept=".csv" onChange={ handleFile } />
			{ preview && (
				<>
					<div className="splm-table-wrapper">
						<table className="splm-table">
							<thead><tr><th>Name</th><th>#</th><th>Email</th><th>Position</th></tr></thead>
							<tbody>
								{ preview.map( ( r, i ) => (
									<tr key={ i }><td>{ r.name }</td><td>{ r.number }</td><td>{ r.email }</td><td>{ r.position }</td></tr>
								) ) }
							</tbody>
						</table>
					</div>
					<button className="splm-btn splm-btn--primary" onClick={ handleImport }>Import</button>
				</>
			) }
			<button className="splm-btn" onClick={ () => { setShow( false ); setPreview( null ); } }>Cancel</button>
		</div>
	);
}

export default function Rosters( { season } ) {
	const [ teams, setTeams ] = useState( [] );
	const [ selectedTeam, setSelectedTeam ] = useState( '' );
	const [ roster, setRoster ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ notesPlayer, setNotesPlayer ] = useState( null );
	const [ movePlayerData, setMovePlayerData ] = useState( null );

	useEffect( () => {
		fetchTeams().then( setTeams );
	}, [] );

	useEffect( () => {
		if ( ! selectedTeam ) return;
		setLoading( true );
		fetchRosterDetails( selectedTeam, season ).then( ( data ) => {
			setRoster( data );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [ selectedTeam, season ] );

	const reload = () => {
		if ( selectedTeam ) {
			fetchRosterDetails( selectedTeam, season ).then( setRoster );
		}
	};

	const updateRosterPlayer = ( playerId, field, value ) => {
		setRoster( ( r ) => r.map( ( p ) => p.id === playerId ? { ...p, [ field ]: value } : p ) );
	};

	const toggleCaptain = ( player ) => {
		const newVal = ! player.is_captain;
		setCaptain( player.id, selectedTeam, newVal ).then( () => {
			updateRosterPlayer( player.id, 'is_captain', newVal );
		} );
	};

	return (
		<div className="splm-rosters">
			<h2>Rosters</h2>
			<div className="splm-rosters__toolbar">
				<select
					className="splm-select"
					value={ selectedTeam }
					onChange={ ( e ) => setSelectedTeam( e.target.value ) }
				>
					<option value="">Select a team...</option>
					{ teams.map( ( t ) => (
						<option key={ t.id } value={ t.id }>{ t.name }</option>
					) ) }
				</select>
				{ selectedTeam && <CSVUpload teamId={ selectedTeam } seasonId={ season } onImported={ reload } /> }
			</div>

			{ loading && <div className="splm-loading">Loading roster...</div> }

			{ ! loading && selectedTeam && roster.length === 0 && (
				<p className="splm-empty">No players on this roster.</p>
			) }

			{ ! loading && roster.length > 0 && (
				<div className="splm-table-wrapper">
					<table className="splm-table">
						<thead>
							<tr>
								<th>#</th>
								<th>Name</th>
								<th>Position</th>
								<th>Skill</th>
								<th>Email</th>
								<th>Reg</th>
								<th>Captain</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							{ roster.map( ( player ) => (
								<tr key={ player.id }>
									<td>
										<EditableCell value={ player.number } field="number" playerId={ player.id } onSaved={ ( f, v ) => updateRosterPlayer( player.id, f, v ) } />
									</td>
									<td className="splm-table__team">{ player.name }</td>
									<td>
										<EditableCell value={ player.position } field="position" playerId={ player.id } onSaved={ ( f, v ) => updateRosterPlayer( player.id, f, v ) } />
									</td>
									<td>
										<SkillCell value={ player.skill_level } playerId={ player.id } onSaved={ ( f, v ) => updateRosterPlayer( player.id, f, v ) } />
									</td>
									<td>
										<EditableCell value={ player.email } field="email" playerId={ player.id } onSaved={ ( f, v ) => updateRosterPlayer( player.id, f, v ) } />
									</td>
									<td>
										<span className={ `splm-reg-badge splm-reg-badge--${ player.registered ? 'yes' : 'no' }` }>
											{ player.registered ? '✅' : '❌' }
										</span>
									</td>
									<td>
										<span
											className={ `splm-captain-badge${ player.is_captain ? ' splm-captain-badge--active' : '' }` }
											onClick={ () => toggleCaptain( player ) }
										>
											ⓒ
										</span>
									</td>
									<td>
										<div className="splm-roster-list__actions">
											<button className="splm-btn splm-btn--small" onClick={ () => setNotesPlayer( player ) }>Notes</button>
											<button className="splm-btn splm-btn--small" onClick={ () => setMovePlayerData( player ) }>Move</button>
											<button className="splm-btn splm-btn--small splm-btn--danger" onClick={ () => {
												if ( window.confirm( `Remove ${ player.name } from this roster?` ) ) {
													removePlayer( player.id, selectedTeam ).then( reload );
												}
											} }>✕</button>
										</div>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ notesPlayer && (
				<NotesPanel player={ notesPlayer } onClose={ () => setNotesPlayer( null ) } />
			) }

			{ movePlayerData && (
				<MoveModal
					player={ movePlayerData }
					teams={ teams }
					currentTeam={ selectedTeam }
					onClose={ () => setMovePlayerData( null ) }
					onMoved={ reload }
				/>
			) }
		</div>
	);
}
