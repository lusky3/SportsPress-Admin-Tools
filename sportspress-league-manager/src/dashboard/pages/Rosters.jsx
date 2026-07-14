import { useState, useEffect, useRef, memo } from '@wordpress/element';
import { fetchTeams, fetchRosterDetails, fetchNotes, fetchNoteCounts, addNote, movePlayer, updatePlayer, updatePlayerMetadata, setCaptain, removePlayer, importRoster, calculateSkills, bulkUploadRoster, bulkProcessRoster } from '../lib/api';
import Toast from '../components/Toast';
import Icon from '../components/icons';

// UX-9: focus trap + focus move-in / restore-on-close for modal dialogs.
// Returns a ref to attach to the dialog container.
function useFocusTrap( onClose ) {
	const ref = useRef( null );
	useEffect( () => {
		const node = ref.current;
		if ( ! node ) return undefined;
		const previouslyFocused = document.activeElement;
		const selector = 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';
		const focusables = () => Array.from( node.querySelectorAll( selector ) ).filter( ( el ) => el.offsetParent !== null || el === document.activeElement );
		// Move focus into the dialog.
		const first = focusables()[ 0 ];
		( first || node ).focus();

		const onKeyDown = ( e ) => {
			if ( e.key === 'Escape' ) { onClose(); return; }
			if ( e.key !== 'Tab' ) return;
			const els = focusables();
			if ( ! els.length ) { e.preventDefault(); return; }
			const firstEl = els[ 0 ];
			const lastEl = els[ els.length - 1 ];
			if ( e.shiftKey && document.activeElement === firstEl ) {
				e.preventDefault();
				lastEl.focus();
			} else if ( ! e.shiftKey && document.activeElement === lastEl ) {
				e.preventDefault();
				firstEl.focus();
			}
		};
		node.addEventListener( 'keydown', onKeyDown );
		return () => {
			node.removeEventListener( 'keydown', onKeyDown );
			// Restore focus to the trigger.
			if ( previouslyFocused && typeof previouslyFocused.focus === 'function' ) {
				previouslyFocused.focus();
			}
		};
	}, [ onClose ] );
	return ref;
}

function NotesPanel( { player, onClose } ) {
	const [ notes, setNotes ] = useState( [] );
	const [ content, setContent ] = useState( '' );
	const [ loading, setLoading ] = useState( true );

	const refresh = () => {
		return fetchNotes( player.id ).then( ( data ) => {
			setNotes( data );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	};

	useEffect( () => {
		refresh();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ player.id ] );

	const handleSubmit = () => {
		if ( ! content.trim() ) return;
		// F19: refetch instead of merging the POST response, whose shape
		// differs from the GET response (id/success vs full note record).
		addNote( player.id, content ).then( () => {
			setContent( '' );
			refresh();
		} );
	};

	// UX-9: trap Tab inside the panel, move focus in on open, restore on close.
	const trapRef = useFocusTrap( onClose );

	return (
		<div className="splm-notes-panel" role="dialog" aria-modal="true" aria-label={ `Notes for ${ player.name }` } ref={ trapRef } tabIndex={ -1 }>
			<div className="splm-notes-panel__overlay" onClick={ onClose }></div>
			<div className="splm-notes-panel__content">
				<div className="splm-notes-panel__header">
					<h3>Notes — { player.name }</h3>
					<button className="splm-btn splm-btn--small" onClick={ onClose } aria-label="Close notes">✕</button>
				</div>
				{ loading ? (
					<div className="splm-loading">Loading...</div>
				) : (
					<div className="splm-notes-panel__list">
						{ notes.map( ( n, i ) => (
							<div key={ n.id ?? i } className="splm-notes-panel__note">
								<span className="splm-notes-panel__date">{ n.created_at }</span>
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

	// UX-9: focus trap for the Move dialog.
	const trapRef = useFocusTrap( onClose );

	return (
		<div className="splm-modal-overlay" onClick={ onClose } role="dialog" aria-modal="true" aria-label={ `Move ${ player.name }` } ref={ trapRef } tabIndex={ -1 }>
			<div className="splm-modal" onClick={ ( e ) => e.stopPropagation() }>
				<h3>Move { player.name }</h3>
				{ /* UX-5: associate label with the select via id/htmlFor. */ }
				<label htmlFor="splm-move-team-select">Move to team:</label>
				<select id="splm-move-team-select" className="splm-select" value={ toTeam } onChange={ ( e ) => setToTeam( e.target.value ) } aria-label={ `Move ${ player.name } to team` }>
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

// UI-11: memoized so editing one cell doesn't re-render every other cell.
// Keyed by player id + field at the call site.
const EditableCell = memo( function EditableCell( { value, field, fieldLabel, playerId, onSaved } ) {
	const [ editing, setEditing ] = useState( false );
	const [ val, setVal ] = useState( value );

	const save = () => {
		setEditing( false );
		// F19: trim before equality so leading/trailing whitespace doesn't
		// trigger spurious updates.
		const trimmed = typeof val === 'string' ? val.trim() : val;
		const originalTrimmed = typeof value === 'string' ? value.trim() : value;
		if ( trimmed !== originalTrimmed ) {
			updatePlayer( playerId, field, trimmed ).then( () => onSaved( field, trimmed ) );
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
				aria-label={ `Edit ${ fieldLabel || field }` }
				autoFocus
			/>
		);
	}
	// UX-8: real <button> with a visible pencil edit affordance + aria-label,
	// instead of a role-less focusable span.
	return (
		<button
			type="button"
			className="splm-editable"
			onClick={ () => setEditing( true ) }
			aria-label={ `Edit ${ fieldLabel || field }${ value ? `: ${ value }` : '' }` }
		>
			<span className="splm-editable__value">{ value || '—' }</span>
			<Icon name="pencil" size={ 12 } />
		</button>
	);
} );

const SkillCell = memo( function SkillCell( { value, playerId, onSaved } ) {
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
				aria-label="Edit skill level"
				autoFocus
			>
				<option value="">—</option>
				<option value="Beginner">Beginner</option>
				<option value="Intermediate">Intermediate</option>
				<option value="Advanced">Advanced</option>
			</select>
		);
	}
	return (
		<button
			type="button"
			className="splm-editable"
			onClick={ () => setEditing( true ) }
			aria-label={ `Edit skill level${ value ? `: ${ value }` : '' }` }
		>
			<span className="splm-editable__value">{ value || '—' }</span>
			<Icon name="pencil" size={ 12 } />
		</button>
	);
} );

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
	const [ noteCounts, setNoteCounts ] = useState( {} );
	const [ movePlayerData, setMovePlayerData ] = useState( null );
	const [ toast, setToast ] = useState( null ); // UI-13: { message, type }
	// Graceful degradation: skill-level editing and Calculate Skills route
	// through SPPT_REST_API (Player Tools). When that module is off the routes
	// are not registered, so surface an inline note instead of a control that 404s.
	const playerToolsAvailable = window.splmDashboard?.dependencies?.player_tools !== false;

	useEffect( () => {
		let cancelled = false;
		fetchTeams( season )
			.then( ( data ) => { if ( ! cancelled ) setTeams( data ); } )
			.catch( () => {} );
		return () => { cancelled = true; };
	}, [ season ] );

	useEffect( () => {
		if ( ! selectedTeam ) return;
		let cancelled = false;
		setLoading( true );
		fetchRosterDetails( selectedTeam, season )
			.then( ( data ) => {
				if ( cancelled ) return;
				setRoster( data );
				setLoading( false );
				fetchNoteCounts( data.map( ( p ) => p.id ) ).then( ( c ) => { if ( ! cancelled ) setNoteCounts( c ); } );
			} )
			.catch( () => { if ( ! cancelled ) setLoading( false ); } );
		return () => { cancelled = true; };
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
			<Toast message={ toast?.message } type={ toast?.type } onDismiss={ () => setToast( null ) } />
			<div className="splm-rosters__toolbar">
				{ /* UX-5: label the team selector. */ }
				<label htmlFor="splm-roster-team" className="screen-reader-text">Select team</label>
				<select
					id="splm-roster-team"
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
				{ playerToolsAvailable && (
					<button className="splm-btn" onClick={ () => {
						calculateSkills( 0, season || 0 ).then( ( r ) => {
							// M6: skipped_manual may be absent if Player Tools is older;
							// fall back to 0 rather than rendering "undefined manual skipped".
							const updated = r?.updated ?? 0;
							const skipped = r?.skipped_manual ?? 0;
							setToast( { message: `Updated ${ updated } players (${ skipped } manual skipped)`, type: 'success' } );
							if ( selectedTeam ) reload();
						} ).catch( ( err ) => setToast( { message: err?.message || 'Failed', type: 'error' } ) );
					} }>
						Calculate Skills
					</button>
				) }
			</div>

			{ ! playerToolsAvailable && (
				<p className="splm-rosters__dep-note" role="note">
					Skill-level editing and bulk skill calculation are unavailable — the
					Player Tools module is not enabled. Enable it under Settings →
					SportsPress Admin Tools.
				</p>
			) }

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
										<EditableCell key={ `${ player.id }-number` } value={ player.number } field="number" fieldLabel={ `number for ${ player.name }` } playerId={ player.id } onSaved={ ( f, v ) => updateRosterPlayer( player.id, f, v ) } />
									</td>
									<td className="splm-table__team">{ player.name }</td>
									<td>
										<EditableCell key={ `${ player.id }-position` } value={ player.position } field="position" fieldLabel={ `position for ${ player.name }` } playerId={ player.id } onSaved={ ( f, v ) => updateRosterPlayer( player.id, f, v ) } />
									</td>
									<td>
										{ playerToolsAvailable ? (
											<SkillCell key={ `${ player.id }-skill` } value={ player.skill_level } playerId={ player.id } onSaved={ ( f, v ) => updateRosterPlayer( player.id, f, v ) } />
										) : (
											<span className="splm-editable__value">{ player.skill_level || '—' }</span>
										) }
									</td>
									<td>
										<EditableCell key={ `${ player.id }-email` } value={ player.email } field="email" fieldLabel={ `email for ${ player.name }` } playerId={ player.id } onSaved={ ( f, v ) => updateRosterPlayer( player.id, f, v ) } />
									</td>
									<td>
										{ /* UI-6: text + color cue, not emoji-color alone. */ }
										<span className={ `splm-reg-badge splm-reg-badge--${ player.registered ? 'yes' : 'no' }` }>
											{ player.registered ? 'Yes' : 'No' }
										</span>
									</td>
									<td>
										<span
											className={ `splm-captain-badge${ player.is_captain ? ' splm-captain-badge--active' : '' }` }
											onClick={ () => toggleCaptain( player ) }
											tabIndex={0}
											role="button"
											aria-label={ player.is_captain ? 'Remove captain' : 'Make captain' }
											onKeyDown={ ( e ) => { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); e.currentTarget.click(); } } }
										>
											ⓒ
										</span>
									</td>
									<td>
										<div className="splm-roster-list__actions">
											<button
												className={ `splm-btn splm-btn--small${ noteCounts[ player.id ] ? ' splm-btn--has-notes' : '' }` }
												onClick={ () => setNotesPlayer( player ) }
												aria-label={ noteCounts[ player.id ] ? `Notes (${ noteCounts[ player.id ] } on file)` : 'Notes' }
											>Notes{ noteCounts[ player.id ] ? ` (${ noteCounts[ player.id ] })` : '' }</button>
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
				<NotesPanel player={ notesPlayer } onClose={ () => { setNotesPlayer( null ); fetchNoteCounts( roster.map( ( p ) => p.id ) ).then( setNoteCounts ); } } />
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
