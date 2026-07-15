import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { fetchSheets, fetchSheet, fetchScoreSheetEvents, uploadSheet, confirmSheet } from '../lib/api';
import Toast from '../components/Toast';

// Queue status filter — values are the raw `status` strings the backend stores.
// Only `pending_review` rows are editable; everything else opens read-only.
const STATUS_FILTERS = [
	{ value: '', label: 'All' },
	{ value: 'pending_review', label: 'Pending review' },
	{ value: 'queued', label: 'Queued' },
	{ value: 'processing', label: 'Processing' },
	{ value: 'failed', label: 'Failed' },
	{ value: 'confirmed', label: 'Confirmed' },
];

const STATUS_LABELS = {
	pending_review: 'Pending review',
	queued: 'Queued',
	processing: 'Processing',
	failed: 'Failed',
	confirmed: 'Confirmed',
	duplicate: 'Duplicate',
};

function StatusBadge( { status } ) {
	const label = STATUS_LABELS[ status ] || status || '—';
	const mod = status === 'failed' ? ' splm-badge--warning' : '';
	return <span className={ `splm-badge${ mod }` }>{ label }</span>;
}

// Clamp free text / event values to a non-negative integer for stat + score fields.
const toInt = ( v ) => Math.max( 0, parseInt( v, 10 ) || 0 );

// FileReader gives a data URL ("data:image/png;base64,AAAA…"); strip the prefix
// so we send only the raw base64 payload the contract expects.
function readFileAsBase64( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () => {
			const result = String( reader.result || '' );
			const comma = result.indexOf( ',' );
			resolve( comma >= 0 ? result.slice( comma + 1 ) : result );
		};
		reader.onerror = () => reject( reader.error || new Error( 'Could not read file' ) );
		reader.readAsDataURL( file );
	} );
}

const TYPE_EXT = { 'image/jpeg': 'jpg', 'image/jpg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif', 'application/pdf': 'pdf' };

function deriveExt( file ) {
	const fromName = ( file.name || '' ).split( '.' ).pop();
	if ( fromName && fromName !== file.name ) {
		return fromName.toLowerCase();
	}
	return TYPE_EXT[ file.type ] || '';
}

// #34: in-dashboard help so users aren't sent to an external plugin guide.
function HelpPanel() {
	return (
		<details className="splm-score-sheets__help">
			<summary>How score sheets work</summary>
			<div className="splm-score-sheets__help-body">
				<p>Turn a photo of a completed, handwritten score sheet into an event’s final score and player stats — without typing it all in by hand.</p>
				<ol>
					<li><strong>Add a sheet.</strong> Click <em>Upload sheet</em> and choose a photo (JPG/PNG) or PDF of the finished score sheet. Sheets can also arrive automatically by email, SMS, or WhatsApp when an administrator has set up remote intake — those land in this same queue.</li>
					<li><strong>Automatic reading.</strong> Each sheet moves through <em>Queued → Processing</em> while it’s read, then becomes <em>Pending review</em>. <em>Failed</em> means it couldn’t be read — upload a clearer, well-lit photo.</li>
					<li><strong>Review.</strong> Open a <em>Pending review</em> sheet. Anything the reader was unsure about is highlighted for you. Confirm which game it belongs to, correct any misread jersey numbers or scores, then choose <em>Confirm &amp; apply to event</em>.</li>
					<li><strong>Done.</strong> Confirmed sheets write the final score and player stats straight to the event, then show as <em>Confirmed</em> and open read-only.</li>
				</ol>
				<p className="splm-muted"><strong>Tip:</strong> write-ins are fine — leave a substitute as “Write-in / no player record” and their goals still count in the team total from the final score. Only pick a roster player when a jersey number was misread.</p>
			</div>
		</details>
	);
}

function Queue( { onReview, onView, onToast } ) {
	const [ status, setStatus ] = useState( '' );
	const [ sheets, setSheets ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ uploading, setUploading ] = useState( false );
	// Client-side sort of the fetched rows (no refetch). Default: newest first.
	const [ sort, setSort ] = useState( { column: 'created_at', direction: 'desc' } );

	const load = useCallback( () => {
		setLoading( true );
		setError( '' );
		fetchSheets( status )
			.then( ( data ) => { setSheets( data ); setLoading( false ); } )
			.catch( ( err ) => { setError( err?.message || 'Failed to load sheets' ); setLoading( false ); } );
	}, [ status ] );

	useEffect( () => { load(); }, [ load ] );

	const handleUpload = async ( e ) => {
		const file = e.target.files?.[ 0 ];
		// Reset so selecting the same file again re-fires onChange.
		e.target.value = '';
		if ( ! file ) return;
		setUploading( true );
		try {
			const image_b64 = await readFileAsBase64( file );
			const res = await uploadSheet( { image_b64, ext: deriveExt( file ) } );
			if ( res?.status === 'duplicate' ) {
				onToast( { message: 'This sheet was already uploaded (duplicate).', type: 'success' } );
			} else {
				onToast( { message: 'Sheet uploaded and queued for processing.', type: 'success' } );
			}
			load();
		} catch ( err ) {
			onToast( { message: err?.message || 'Upload failed', type: 'error' } );
		}
		setUploading( false );
	};

	// Toggle direction when re-clicking the active column; otherwise start ascending.
	const toggleSort = ( column ) => {
		setSort( ( prev ) => (
			prev.column === column
				? { column, direction: prev.direction === 'asc' ? 'desc' : 'asc' }
				: { column, direction: 'asc' }
		) );
	};

	// Sort a copy of the fetched rows — created_at by date, the rest by string.
	const sortedSheets = [ ...sheets ].sort( ( a, b ) => {
		const dir = sort.direction === 'asc' ? 1 : -1;
		if ( sort.column === 'created_at' ) {
			const at = Date.parse( a.created_at );
			const bt = Date.parse( b.created_at );
			if ( ! Number.isNaN( at ) && ! Number.isNaN( bt ) ) return ( at - bt ) * dir;
			return String( a.created_at || '' ).localeCompare( String( b.created_at || '' ) ) * dir;
		}
		return String( a[ sort.column ] || '' ).localeCompare( String( b[ sort.column ] || '' ) ) * dir;
	} );

	// Render a keyboard-activatable, aria-sort-aware column header.
	const sortableHeader = ( label, column ) => {
		const active = sort.column === column;
		const ariaSort = active ? ( sort.direction === 'asc' ? 'ascending' : 'descending' ) : 'none';
		const caret = active ? ( sort.direction === 'asc' ? ' ▲' : ' ▼' ) : '';
		return (
			<th scope="col" aria-sort={ ariaSort }>
				<button type="button" className="splm-sort-btn" onClick={ () => toggleSort( column ) }>
					{ label }{ caret }
				</button>
			</th>
		);
	};

	return (
		<div className="splm-score-sheets__queue">
			<HelpPanel />
			<div className="splm-score-sheets__toolbar">
				<label className="splm-score-sheets__filter">
					Status:{ ' ' }
					<select className="splm-select" value={ status } onChange={ ( e ) => setStatus( e.target.value ) }>
						{ STATUS_FILTERS.map( ( f ) => (
							<option key={ f.value } value={ f.value }>{ f.label }</option>
						) ) }
					</select>
				</label>
				<button className="splm-btn" onClick={ load } disabled={ loading }>
					{ loading ? 'Refreshing…' : 'Refresh' }
				</button>
				<label className={ `splm-btn splm-btn--primary splm-score-sheets__upload${ uploading ? ' splm-btn--disabled' : '' }` }>
					{ uploading ? 'Uploading…' : 'Upload sheet' }
					<input
						type="file"
						accept="image/*,application/pdf"
						className="screen-reader-text"
						onChange={ handleUpload }
						disabled={ uploading }
					/>
				</label>
			</div>

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			{ loading ? (
				<div className="splm-loading">Loading sheets…</div>
			) : sheets.length === 0 ? (
				<p className="splm-empty">No score sheets yet. Click <strong>Upload sheet</strong> above to add one — expand <em>How score sheets work</em> for the full workflow. Sheets sent in by email, SMS, or WhatsApp (when remote intake is set up) also appear here.</p>
			) : (
				<div className="splm-table-wrapper">
					<table className="splm-table">
						<thead>
							<tr>
								{ sortableHeader( 'Created', 'created_at' ) }
								{ sortableHeader( 'Channel', 'channel' ) }
								{ sortableHeader( 'Status', 'status' ) }
								{ sortableHeader( 'Event', 'event_title' ) }
								<th scope="col">Flags</th>
								<th scope="col"></th>
							</tr>
						</thead>
						<tbody>
							{ sortedSheets.map( ( s ) => (
								<tr key={ s.id }>
									<td>{ s.created_at }</td>
									<td>{ s.channel || '—' }</td>
									<td><StatusBadge status={ s.status } /></td>
									<td>{ s.event_title || '—' }</td>
									<td className="splm-tabular">{ s.flags_count > 0 ? s.flags_count : '' }</td>
									<td>
										{ s.status === 'pending_review' ? (
											<button className="splm-btn splm-btn--small splm-btn--primary" onClick={ () => onReview( s.id ) }>
												Review
											</button>
										) : (
											<button className="splm-btn splm-btn--small" onClick={ () => onView( s.id ) }>
												View
											</button>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

function ReviewView( { id, season, readOnly, onBack, onToast } ) {
	const [ detail, setDetail ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ events, setEvents ] = useState( [] );
	const [ eventId, setEventId ] = useState( '' );
	const [ changingEvent, setChangingEvent ] = useState( false );
	const [ rows, setRows ] = useState( [] );
	const [ homeScore, setHomeScore ] = useState( 0 );
	const [ awayScore, setAwayScore ] = useState( 0 );
	const [ otLossSide, setOtLossSide ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const nextKey = useRef( 0 );

	const loadEvents = useCallback( () => {
		fetchScoreSheetEvents( season )
			.then( setEvents )
			.catch( () => onToast( { message: 'Could not load games — refresh and try again.', type: 'error' } ) );
	}, [ season, onToast ] );

	useEffect( () => {
		setLoading( true );
		setError( '' );
		fetchSheet( id ).then( ( d ) => {
			setDetail( d );
			setEventId( d.event?.id ? String( d.event.id ) : '' );
			const players = d.extracted?.players || [];
			setRows( players.map( ( p, i ) => ( {
				key: nextKey.current++,
				// Original index into extracted.players, so per-row flag
				// highlighting (below) lines up with extracted.flags[].player_index.
				player_index: i,
				side: p.team === 'away' ? 'away' : 'home',
				jersey_written: p.jersey_written ?? '',
				player_id: p.matched_player_id ?? 0,
				g: p.goals ?? 0,
				a: p.assists ?? 0,
				pim: p.pim ?? 0,
			} ) ) );
			setHomeScore( d.home_score ?? 0 );
			setAwayScore( d.away_score ?? 0 );
			setOtLossSide( d.ot_loss_side ?? '' );
			setLoading( false );
			// No event assigned yet — the picker is required, so load it up front.
			if ( ! d.event ) {
				loadEvents();
			}
		} ).catch( ( err ) => {
			setError( err?.message || 'Failed to load sheet' );
			setLoading( false );
		} );
	}, [ id, season, loadEvents ] );

	if ( loading ) {
		return <div className="splm-loading">Loading sheet…</div>;
	}

	if ( error || ! detail ) {
		return (
			<div>
				<button className="splm-btn" onClick={ onBack }>← Back to queue</button>
				<div className="splm-alert splm-alert--warning" role="alert">{ error || 'Sheet not found.' }</div>
			</div>
		);
	}

	const rosters = detail.rosters || { home: [], away: [] };
	// Roster scoped to a row's side — a reviewer should only pick from the team the
	// player actually belongs to (mirrors class-review-admin.php's $rosters[$side]).
	const rosterForSide = ( side ) => ( side === 'away' ? rosters.away : rosters.home ) || [];
	// Map extracted.players index → collected flag detail/type strings, so the
	// specific flagged player row can be highlighted (mirrors class-review-admin.php
	// $flags_by_index). Only entries carrying a non-null player_index apply to a row.
	const flagsByIndex = {};
	( detail.extracted?.flags || [] ).forEach( ( f ) => {
		if ( f.player_index !== undefined && f.player_index !== null ) {
			const idx = f.player_index;
			( flagsByIndex[ idx ] = flagsByIndex[ idx ] || [] ).push( f.detail || f.type || '' );
		}
	} );
	const homeTeamName = detail.event?.home_team || 'Home';
	const awayTeamName = detail.event?.away_team || 'Away';
	const teamNameForSide = ( side ) => ( side === 'away' ? awayTeamName : homeTeamName );

	// Resolve a jersey number (on a side) to "#N Name" via the roster — mirrors
	// the read-only reference tables in class-review-admin.php.
	const labelFor = ( side, jersey ) => {
		const j = String( jersey ?? '' ).trim();
		if ( ! j ) return '—';
		const num = j.replace( /\D/g, '' );
		if ( num ) {
			const match = ( rosters[ side ] || [] ).find( ( r ) => String( r.number ?? '' ).replace( /\D/g, '' ) === num );
			if ( match ) return `#${ j } ${ match.name }`;
		}
		return `#${ j }`;
	};

	const updateRow = ( key, field, value ) => {
		setRows( ( prev ) => prev.map( ( r ) => ( r.key === key ? { ...r, [ field ]: value } : r ) ) );
	};

	const addRow = () => {
		setRows( ( prev ) => [ ...prev, { key: nextKey.current++, side: 'home', jersey_written: '', player_id: 0, g: 0, a: 0, pim: 0 } ] );
	};

	const removeRow = ( key ) => {
		setRows( ( prev ) => prev.filter( ( r ) => r.key !== key ) );
	};

	const handleConfirm = async () => {
		setSubmitting( true );
		const payload = {
			event_id: parseInt( eventId, 10 ) || 0,
			home_score: toInt( homeScore ),
			away_score: toInt( awayScore ),
			ot_loss_side: otLossSide,
			players: rows.map( ( r ) => ( {
				side: r.side,
				player_id: parseInt( r.player_id, 10 ) || 0,
				g: toInt( r.g ),
				a: toInt( r.a ),
				pim: toInt( r.pim ),
			} ) ),
		};
		try {
			await confirmSheet( id, payload );
			onToast( { message: 'Applied to the event.', type: 'success' } );
			onBack();
		} catch ( err ) {
			onToast( { message: err?.message || 'Failed to apply sheet', type: 'error' } );
			setSubmitting( false );
		}
	};

	const flags = detail.extracted?.flags || [];
	const scoring = detail.extracted?.scoring || [];
	const penalties = detail.extracted?.penalties || [];
	const showEventPicker = ! detail.event || changingEvent;

	return (
		<div className="splm-score-sheets__review">
			<button className="splm-btn" onClick={ onBack }>← Back to queue</button>

			<h2 className="splm-score-sheets__review-heading">{ homeTeamName } vs { awayTeamName } — review</h2>

			<p className="splm-score-sheets__meta">
				<StatusBadge status={ detail.status } />
				{ detail.channel && <span> · { detail.channel }</span> }
				{ detail.provider && <span> · { detail.provider }</span> }
				{ detail.created_at && <span> · { detail.created_at }</span> }
			</p>

			{ readOnly && (
				<div className="splm-alert" role="status">This sheet is { STATUS_LABELS[ detail.status ] || detail.status } and cannot be edited.</div>
			) }

			{ flags.length > 0 && (
				<div className="splm-alert splm-alert--warning" role="alert">
					<strong>Please check the highlighted items:</strong>
					<ul className="splm-score-sheets__flags">
						{ flags.map( ( f, i ) => (
							<li key={ i }>{ ( f.type || '' ) + ( f.detail ? ` — ${ f.detail }` : '' ) }</li>
						) ) }
					</ul>
				</div>
			) }

			<div className="splm-score-sheets__grid">
				<div className="splm-score-sheets__image">
					{ detail.image_url ? (
						<img src={ detail.image_url } alt="Uploaded score sheet" />
					) : (
						<p className="splm-empty">Image no longer stored.</p>
					) }
				</div>

				<div className="splm-score-sheets__form">
					{ /* Event assignment */ }
					<div className="splm-score-sheets__section">
						<h3>Game</h3>
						{ detail.event && ! changingEvent ? (
							<p>
								<strong>{ detail.event.title || `${ homeTeamName } vs ${ awayTeamName }` }</strong>
								{ ! readOnly && (
									<button
										type="button"
										className="splm-btn splm-btn--small"
										onClick={ () => { setChangingEvent( true ); loadEvents(); } }
									>
										Change
									</button>
								) }
							</p>
						) : (
							<label>
								<span className="screen-reader-text">Select the game this sheet belongs to</span>
								<select
									className="splm-select"
									value={ eventId }
									onChange={ ( e ) => setEventId( e.target.value ) }
									disabled={ readOnly }
									required
								>
									<option value="">— Select a game —</option>
									{ events.map( ( ev ) => (
										<option key={ ev.id } value={ ev.id }>
											{ `${ ev.home_team } vs ${ ev.away_team } — ${ ev.date }` }
										</option>
									) ) }
								</select>
							</label>
						) }
					</div>

					{ /* Scores */ }
					<div className="splm-score-sheets__section">
						<table className="splm-table">
							<tbody>
								<tr>
									<th>{ homeTeamName } score</th>
									<td>
										<input type="number" min="0" className="splm-score-input splm-tabular" value={ homeScore }
											onChange={ ( e ) => setHomeScore( toInt( e.target.value ) ) } disabled={ readOnly }
											aria-label={ `${ homeTeamName } score` } />
									</td>
								</tr>
								<tr>
									<th>{ awayTeamName } score</th>
									<td>
										<input type="number" min="0" className="splm-score-input splm-tabular" value={ awayScore }
											onChange={ ( e ) => setAwayScore( toInt( e.target.value ) ) } disabled={ readOnly }
											aria-label={ `${ awayTeamName } score` } />
									</td>
								</tr>
								<tr>
									<th><label htmlFor="spss-ot">Overtime / shootout loss</label></th>
									<td>
										<select id="spss-ot" className="splm-select" value={ otLossSide }
											onChange={ ( e ) => setOtLossSide( e.target.value ) } disabled={ readOnly }>
											<option value="">— None (regulation) —</option>
											<option value="home">{ `${ homeTeamName } lost in OT/SO` }</option>
											<option value="away">{ `${ awayTeamName } lost in OT/SO` }</option>
										</select>
									</td>
								</tr>
							</tbody>
						</table>
					</div>

					{ /* Player stats (editable) */ }
					<div className="splm-score-sheets__section">
						<h3>Player stats</h3>
						<div className="splm-table-wrapper">
							<table className="splm-table">
								<thead>
									<tr>
										<th scope="col">Side</th>
										<th scope="col">Jersey</th>
										<th scope="col">Player</th>
										<th scope="col">G</th>
										<th scope="col">A</th>
										<th scope="col">PIM</th>
										{ ! readOnly && <th scope="col"></th> }
									</tr>
								</thead>
								<tbody>
									{ rows.map( ( r ) => {
										// Recompute per row from the row's CURRENT side, so changing
										// Side both re-scopes the player list and re-flags the row.
										const rowFlags = r.player_index != null ? flagsByIndex[ r.player_index ] : undefined;
										const flagged = !! ( rowFlags && rowFlags.length );
										const flagTitle = flagged ? rowFlags.join( '; ' ) : undefined;
										const sideRoster = rosterForSide( r.side );
										return (
										<tr key={ r.key } className={ flagged ? 'splm-row--flagged' : undefined } title={ flagTitle }>
											<td>
												<select className="splm-select" value={ r.side }
													onChange={ ( e ) => updateRow( r.key, 'side', e.target.value ) } disabled={ readOnly }
													aria-label="Side">
													<option value="home">{ homeTeamName }</option>
													<option value="away">{ awayTeamName }</option>
												</select>
											</td>
											<td>
												{ r.jersey_written || '—' }
												{ flagged && (
													<span className="splm-row__flag-note" role="note"> ⚠ { flagTitle }</span>
												) }
											</td>
											<td>
												<select className="splm-select" value={ r.player_id }
													onChange={ ( e ) => updateRow( r.key, 'player_id', parseInt( e.target.value, 10 ) || 0 ) }
													disabled={ readOnly } aria-label="Player">
													<option value={ 0 }>Write-in / no player record</option>
													{ sideRoster.map( ( p ) => (
														<option key={ p.player_id } value={ p.player_id }>
															{ ( p.number ? `#${ p.number } ` : '' ) + p.name }
														</option>
													) ) }
												</select>
											</td>
											<td>
												<input type="number" min="0" className="splm-score-input splm-tabular" value={ r.g }
													onChange={ ( e ) => updateRow( r.key, 'g', toInt( e.target.value ) ) } disabled={ readOnly }
													aria-label="Goals" />
											</td>
											<td>
												<input type="number" min="0" className="splm-score-input splm-tabular" value={ r.a }
													onChange={ ( e ) => updateRow( r.key, 'a', toInt( e.target.value ) ) } disabled={ readOnly }
													aria-label="Assists" />
											</td>
											<td>
												<input type="number" min="0" className="splm-score-input splm-tabular" value={ r.pim }
													onChange={ ( e ) => updateRow( r.key, 'pim', toInt( e.target.value ) ) } disabled={ readOnly }
													aria-label="Penalty minutes" />
											</td>
											{ ! readOnly && (
												<td>
													<button type="button" className="splm-btn splm-btn--small splm-btn--danger"
														onClick={ () => removeRow( r.key ) } aria-label="Remove row">Remove</button>
												</td>
											) }
										</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
						{ ! readOnly && (
							<button type="button" className="splm-btn splm-btn--small" onClick={ addRow }>+ Add player row</button>
						) }
						<p className="splm-score-sheets__note">
							Write-ins are fine: leave a substitute as &quot;Write-in / no player record&quot; — their goals still count in the team total from the final score. Only pick a roster player if the jersey was misread.
						</p>
					</div>

					{ /* Read-only reference: scoring */ }
					{ scoring.length > 0 && (
						<div className="splm-score-sheets__section">
							<h3>Scoring (read from the sheet — for reference)</h3>
							<div className="splm-table-wrapper">
								<table className="splm-table">
									<thead>
										<tr><th scope="col">Side</th><th scope="col">Goal</th><th scope="col">Scorer</th><th scope="col">Assist 1</th><th scope="col">Assist 2</th><th scope="col">Period</th></tr>
									</thead>
									<tbody>
										{ scoring.map( ( s, i ) => {
											const side = s.team === 'away' ? 'away' : 'home';
											return (
												<tr key={ i }>
													<td>{ teamNameForSide( side ) }</td>
													<td>{ s.goal_number ?? '' }</td>
													<td>{ labelFor( side, s.scorer_jersey ) }</td>
													<td>{ labelFor( side, s.assist1_jersey ) }</td>
													<td>{ labelFor( side, s.assist2_jersey ) }</td>
													<td>{ s.period ?? '' }</td>
												</tr>
											);
										} ) }
									</tbody>
								</table>
							</div>
						</div>
					) }

					{ /* Read-only reference: penalties */ }
					{ penalties.length > 0 && (
						<div className="splm-score-sheets__section">
							<h3>Penalties (read from the sheet — feeds PIM above)</h3>
							<div className="splm-table-wrapper">
								<table className="splm-table">
									<thead>
										<tr><th scope="col">Side</th><th scope="col">Player</th><th scope="col">Minutes</th><th scope="col">Period</th><th scope="col">Offense</th></tr>
									</thead>
									<tbody>
										{ penalties.map( ( pen, i ) => {
											const side = pen.team === 'away' ? 'away' : 'home';
											return (
												<tr key={ i }>
													<td>{ teamNameForSide( side ) }</td>
													<td>{ labelFor( side, pen.jersey ) }</td>
													<td>{ pen.length ?? '' }</td>
													<td>{ pen.period ?? '' }</td>
													<td>{ pen.offense ?? '' }</td>
												</tr>
											);
										} ) }
									</tbody>
								</table>
							</div>
						</div>
					) }

					{ ! readOnly && (
						<button
							className="splm-btn splm-btn--primary splm-btn--large"
							onClick={ handleConfirm }
							disabled={ submitting || ! eventId }
						>
							{ submitting ? 'Applying…' : 'Confirm & apply to event' }
						</button>
					) }
				</div>
			</div>
		</div>
	);
}

export default function ScoreSheets( { season } ) {
	const [ view, setView ] = useState( 'queue' ); // 'queue' | 'review'
	const [ activeId, setActiveId ] = useState( null );
	const [ readOnly, setReadOnly ] = useState( false );
	const [ toast, setToast ] = useState( null ); // { message, type }

	const openReview = ( id ) => { setActiveId( id ); setReadOnly( false ); setView( 'review' ); };
	const openView = ( id ) => { setActiveId( id ); setReadOnly( true ); setView( 'review' ); };
	const backToQueue = () => { setView( 'queue' ); setActiveId( null ); };

	return (
		<div className="splm-score-sheets">
			<h2>Score Sheets</h2>
			<Toast message={ toast?.message } type={ toast?.type } onDismiss={ () => setToast( null ) } />
			{ view === 'queue' ? (
				<Queue onReview={ openReview } onView={ openView } onToast={ setToast } />
			) : (
				<ReviewView id={ activeId } season={ season } readOnly={ readOnly } onBack={ backToQueue } onToast={ setToast } />
			) }
		</div>
	);
}
