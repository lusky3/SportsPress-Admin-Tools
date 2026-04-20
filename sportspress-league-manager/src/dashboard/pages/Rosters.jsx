import { useState, useEffect } from '@wordpress/element';
import { fetchTeams, fetchRoster, fetchNotes, addNote, movePlayer, updatePlayer, removePlayer } from '../lib/api';

function NotesPanel( { player, teams, onClose } ) {
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

export default function Rosters() {
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
		fetchRoster( selectedTeam ).then( ( data ) => {
			setRoster( data );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [ selectedTeam ] );

	const reload = () => {
		if ( selectedTeam ) {
			fetchRoster( selectedTeam ).then( setRoster );
		}
	};

	return (
		<div className="splm-rosters">
			<h2>Rosters</h2>
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

			{ loading && <div className="splm-loading">Loading roster...</div> }

			{ ! loading && selectedTeam && roster.length === 0 && (
				<p className="splm-empty">No players on this roster.</p>
			) }

			{ ! loading && roster.length > 0 && (
				<div className="splm-roster-list">
					{ roster.map( ( player ) => (
						<div key={ player.id } className="splm-roster-list__item">
							<span className="splm-roster-list__number">
								#<EditableCell value={ player.number } field="number" playerId={ player.id } onSaved={ ( f, v ) => {
									setRoster( ( r ) => r.map( ( p ) => p.id === player.id ? { ...p, [ f ]: v } : p ) );
								} } />
							</span>
							<div className="splm-roster-list__info">
								<span className="splm-roster-list__name">{ player.name }</span>
								<span className="splm-roster-list__email">
									<EditableCell value={ player.email } field="email" playerId={ player.id } onSaved={ ( f, v ) => {
										setRoster( ( r ) => r.map( ( p ) => p.id === player.id ? { ...p, [ f ]: v } : p ) );
									} } />
								</span>
							</div>
							{ ( window.splmDashboard?.capabilities?.canManageRosters !== false ) && (
							<div className="splm-roster-list__actions">
								<button className="splm-btn splm-btn--small" onClick={ () => setNotesPlayer( player ) }>Notes</button>
								<button className="splm-btn splm-btn--small" onClick={ () => setMovePlayerData( player ) }>Move</button>
								<button className="splm-btn splm-btn--small splm-btn--danger" onClick={ () => {
									if ( window.confirm( `Remove ${ player.name } from this roster?` ) ) {
										removePlayer( player.id, selectedTeam ).then( reload );
									}
								} }>✕</button>
							</div>
						) }
						</div>
					) ) }
				</div>
			) }

			{ notesPlayer && (
				<NotesPanel player={ notesPlayer } teams={ teams } onClose={ () => setNotesPlayer( null ) } />
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
