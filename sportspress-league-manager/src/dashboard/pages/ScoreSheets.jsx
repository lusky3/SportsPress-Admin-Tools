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

function Queue( { onReview, onView, onToast } ) {
	const [ status, setStatus ] = useState( '' );
	const [ sheets, setSheets ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ uploading, setUploading ] = useState( false );

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

	return (
		<div className="splm-score-sheets__queue">
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
				<p className="splm-empty">No score sheets found.</p>
			) : (
				<div className="splm-table-wrapper">
					<table className="splm-table">
						<thead>
							<tr>
								<th>Created</th>
								<th>Channel</th>
								<th>Status</th>
								<th>Event</th>
								<th>Flags</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ sheets.map( ( s ) => (
								<tr key={ s.id }>
									<td>{ s.created_at }</td>
									<td>{ s.channel || '—' }</td>
									<td><StatusBadge status={ s.status } /></td>
									<td>{ s.event_title || '—' }</td>
									<td>{ s.flags_count > 0 ? s.flags_count : '' }</td>
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
		fetchScoreSheetEvents( season ).then( setEvents ).catch( () => {} );
	}, [ season ] );

	useEffect( () => {
		setLoading( true );
		setError( '' );
		fetchSheet( id ).then( ( d ) => {
			setDetail( d );
			setEventId( d.event?.id ? String( d.event.id ) : '' );
			const players = d.extracted?.players || [];
			setRows( players.map( ( p ) => ( {
				key: nextKey.current++,
				side: p.side === 'away' ? 'away' : 'home',
				jersey_written: p.jersey_written ?? '',
				player_id: p.player_id ?? 0,
				g: p.g ?? 0,
				a: p.a ?? 0,
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
	const rosterOptions = [ ...( rosters.home || [] ), ...( rosters.away || [] ) ];
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
										<input type="number" min="0" className="splm-score-input" value={ homeScore }
											onChange={ ( e ) => setHomeScore( toInt( e.target.value ) ) } disabled={ readOnly }
											aria-label={ `${ homeTeamName } score` } />
									</td>
								</tr>
								<tr>
									<th>{ awayTeamName } score</th>
									<td>
										<input type="number" min="0" className="splm-score-input" value={ awayScore }
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
										<th>Side</th>
										<th>Jersey</th>
										<th>Player</th>
										<th>G</th>
										<th>A</th>
										<th>PIM</th>
										{ ! readOnly && <th></th> }
									</tr>
								</thead>
								<tbody>
									{ rows.map( ( r ) => (
										<tr key={ r.key }>
											<td>
												<select className="splm-select" value={ r.side }
													onChange={ ( e ) => updateRow( r.key, 'side', e.target.value ) } disabled={ readOnly }
													aria-label="Side">
													<option value="home">{ homeTeamName }</option>
													<option value="away">{ awayTeamName }</option>
												</select>
											</td>
											<td>{ r.jersey_written || '—' }</td>
											<td>
												<select className="splm-select" value={ r.player_id }
													onChange={ ( e ) => updateRow( r.key, 'player_id', parseInt( e.target.value, 10 ) || 0 ) }
													disabled={ readOnly } aria-label="Player">
													<option value={ 0 }>Write-in / no player record</option>
													{ rosterOptions.map( ( p ) => (
														<option key={ p.player_id } value={ p.player_id }>
															{ ( p.number ? `#${ p.number } ` : '' ) + p.name }
														</option>
													) ) }
												</select>
											</td>
											<td>
												<input type="number" min="0" className="splm-score-input" value={ r.g }
													onChange={ ( e ) => updateRow( r.key, 'g', toInt( e.target.value ) ) } disabled={ readOnly }
													aria-label="Goals" />
											</td>
											<td>
												<input type="number" min="0" className="splm-score-input" value={ r.a }
													onChange={ ( e ) => updateRow( r.key, 'a', toInt( e.target.value ) ) } disabled={ readOnly }
													aria-label="Assists" />
											</td>
											<td>
												<input type="number" min="0" className="splm-score-input" value={ r.pim }
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
									) ) }
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
										<tr><th>Side</th><th>Goal</th><th>Scorer</th><th>Assist 1</th><th>Assist 2</th><th>Period</th></tr>
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
										<tr><th>Side</th><th>Player</th><th>Minutes</th><th>Period</th><th>Offense</th></tr>
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
