import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { fetchInfractions, fetchPlayerGames, previewSuspension, createSuspension } from '../lib/api';
import { formatDate } from '../lib/time';
import useFocusTrap from './useFocusTrap';
import useUid from './useUid';

// Issue-suspension dialog: the convener picks an infraction, sees a live
// preview of the consequence and the emails, then sends or saves a draft.
//
// CONTRACT (focus trap): the PARENT must pass a `useCallback`-memoised
// `onClose`. useFocusTrap's effect depends on the identity of the function it
// receives; this component derives a stable `requestClose` from `onClose`
// (useCallback on [ onClose ]), so an inline-arrow `onClose` would still re-run
// the trap on every render and keep yanking focus back to the first field (and
// restore it to the trigger). Never hand the hook a fresh arrow.
//
// Props: { player: { id, name }, season, onClose, onDone( notice | null ) }.
// `season` may be falsy; the param is then omitted and the server falls back
// to the default season.
// onDone contract:
//  - successful create (sent, or draft saved): onDone( notice ), then onClose().
//  - FAILED send (201, notice.status === 'failed'): the row exists but the
//    email did not go. The modal stays open showing the error; onDone( notice )
//    fires only when the convener closes it. Parents: if notice.status ===
//    'failed', refresh but do NOT show a success toast.
//  - busy-lock 409 or a request that never completed (network/timeout): the
//    write may have happened, so onDone( null ) fires — refresh the data.
// Escape, overlay click and Cancel are ignored while a create is in flight.

const NOTE_MAX = 2000;
const GAMES_MAX = 20;
const PREVIEW_DEBOUNCE_MS = 400;

function describeWarning( code, duplicateOf ) {
	if ( code === 'no_player_email' ) {
		return { level: 'blocking', text: 'This player has no email address on file, so the notice cannot be sent. You can still save it as a draft.' };
	}
	if ( code === 'duplicate' ) {
		const ref = duplicateOf ? `notice #${ duplicateOf }` : 'a matching notice';
		return { level: 'blocking', text: `Already issued — ${ ref } covers this incident.` };
	}
	if ( code === 'prior_suspension_this_season' ) {
		return { level: 'notice', text: 'This player already has a suspension this season — check the second-offence rules (§5.10).' };
	}
	if ( code === 'no_team_for_season' ) {
		return { level: 'info', text: 'No team was found for this player in the season, so no captain will be notified.' };
	}
	if ( code.indexOf( 'captain_no_email:' ) === 0 ) {
		const team = code.slice( 'captain_no_email:'.length ) || 'A team';
		return { level: 'info', text: `${ team } has a captain with no email on file — not notified.` };
	}
	return { level: 'info', text: code };
}

const PREFIX = { blocking: 'Blocking:', notice: 'Heads up:', info: 'Note:' };

function Warnings( { warnings, duplicateOf } ) {
	if ( ! warnings.length ) return null;
	return (
		<ul className="splm-discipline-warnings">
			{ warnings.map( ( code, index ) => {
				const { level, text } = describeWarning( code, duplicateOf );
				const cls = level === 'notice' ? '' : ` splm-discipline-warning--${ level }`;
				return (
					<li key={ `${ code }-${ index }` } className={ `splm-discipline-warning${ cls }` }>
						<strong>{ PREFIX[ level ] }</strong> { text }
					</li>
				);
			} ) }
		</ul>
	);
}

// Today as a local 'YYYY-MM-DD' string, comparable with eligible_on.
function todayLocal() {
	const d = new Date();
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
}

function eligibilityText( data ) {
	if ( data.outcome === 'indefinite' ) {
		return 'Suspended until you decide a length. You can set one later from the player’s Discipline panel.';
	}
	if ( data.games === 0 ) {
		return 'Balance of the game only — no further games are missed.';
	}
	if ( data.eligible_on && data.eligible_on < todayLocal() ) {
		return `Next eligible game: ${ formatDate( data.eligible_on ) }. That date has already passed — games played since the incident count toward the suspension, so check whether it has already been served.`;
	}
	if ( data.eligible_on ) {
		return `Next eligible game: ${ formatDate( data.eligible_on ) } (projected, if the schedule doesn’t change).`;
	}
	if ( data.remaining > 0 ) {
		return `No scheduled game yet for the full length — the remaining ${ data.remaining } game(s) will be served at the player’s next scheduled game(s).`;
	}
	return 'Not enough scheduled games to name a next eligible game yet.';
}

const COVERED_BY = {
	player: 'covered by the player copy — no separate captain email',
	bcc: 'covered by the convener (Bcc) copy — no separate captain email',
};

function Recipients( { data } ) {
	const email = data.player_email || {};
	const captains = Array.isArray( data.captains ) ? data.captains : [];
	return (
		<ul className="splm-discipline-recipients">
			<li>
				<strong>Player:</strong>{ ' ' }
				{ email.email
					? <>{ email.email }{ email.via ? ` (via ${ email.via })` : '' }</>
					: 'no email on file' }
			</li>
			{ captains.map( ( c, index ) => (
				<li key={ `${ c.team }-${ c.email || '' }-${ index }` }>
					<strong>Captain, { c.team }:</strong>{ ' ' }
					{ c.email ? c.email : 'no email on file — not notified' }
					{ c.email && c.covered_by && ` (${ COVERED_BY[ c.covered_by ] || 'covered by another copy' })` }
				</li>
			) ) }
		</ul>
	);
}

// Short summary: the only part announced by the live region.
function PreviewSummary( { data } ) {
	return (
		<>
			<p className="splm-discipline-preview__result">{ eligibilityText( data ) }</p>
			<Warnings warnings={ Array.isArray( data.warnings ) ? data.warnings : [] } duplicateOf={ data.duplicate_of } />
		</>
	);
}

function PreviewDetails( { data } ) {
	return (
		<>
			<h4>Who will be emailed</h4>
			<Recipients data={ data } />
			<details className="splm-discipline-details">
				<summary>Email to the player</summary>
				{ data.player_subject && <p><strong>Subject:</strong> { data.player_subject }</p> }
				<pre className="splm-discipline-email" role="textbox" aria-readonly="true" aria-multiline="true" tabIndex={ 0 } aria-label="Email text to the player">{ data.player_body }</pre>
			</details>
			<details className="splm-discipline-details">
				<summary>Email to captains</summary>
				<pre className="splm-discipline-email" role="textbox" aria-readonly="true" aria-multiline="true" tabIndex={ 0 } aria-label="Email text to captains">{ data.captain_body }</pre>
			</details>
		</>
	);
}

const SUBMIT_MESSAGES = {
	splm_notice_busy: 'Another update is in progress — refresh before retrying.',
	splm_no_lock: 'Cannot send safely right now. Wait a moment and try again.',
};

function duplicateMessage( err ) {
	const id = err.data?.duplicate_of;
	return `Already issued${ id ? ` — notice #${ id }` : '' }. Open the player\u2019s Discipline panel to manage it.`;
}

// A busy lock or a request that never completed: the write may or may not have
// happened, so the parent must refresh.
function isUnknownOutcome( err ) {
	return err?.code === 'splm_notice_busy' || ! err?.code || err.code === 'fetch_error';
}

function submitErrorMessage( err ) {
	const code = err?.code;
	if ( code === 'splm_suspension_duplicate' ) {
		return duplicateMessage( err );
	}
	if ( SUBMIT_MESSAGES[ code ] ) {
		return SUBMIT_MESSAGES[ code ];
	}
	if ( ! code || code === 'fetch_error' ) {
		return 'The request did not complete — refresh the list before retrying; the notice may already exist.';
	}
	return err.message || 'Something went wrong';
}

// A 201 whose email did not go: the row exists as failed.
const isFailedSend = ( res, notice ) => !! res && res.sent === false && !! notice && notice.status === 'failed';

export default function SuspensionModal( { player, season, onClose, onDone } ) {
	const uid = useUid( 'splm-susp' );

	const [ infractions, setInfractions ] = useState( null ); // null = loading
	const [ infractionsError, setInfractionsError ] = useState( '' );
	const [ games, setGames ] = useState( null );
	const [ gamesError, setGamesError ] = useState( '' );

	const [ infractionId, setInfractionId ] = useState( '' );
	const [ gameCount, setGameCount ] = useState( '' );
	const [ incident, setIncident ] = useState( '' );
	const [ note, setNote ] = useState( '' );

	const [ preview, setPreview ] = useState( { status: 'idle' } );
	// Last SUCCESSFUL preview, tagged with the params key it answered. Send is
	// only allowed against a preview for the current params (never blind).
	const [ lastOk, setLastOk ] = useState( null );

	const [ submitting, setSubmitting ] = useState( false );
	const [ submitError, setSubmitError ] = useState( '' );
	const [ failedNotice, setFailedNotice ] = useState( null );
	const [ duplicateOf, setDuplicateOf ] = useState( null );

	const seqRef = useRef( 0 );
	const submittingRef = useRef( false );
	const mountedRef = useRef( true );
	const failedRef = useRef( null );
	const onDoneRef = useRef( onDone );
	const overlayDownRef = useRef( false );
	const closeBtnRef = useRef( null );
	const errorRef = useRef( null );
	const infractionRef = useRef( null );
	const noteRef = useRef( null );
	useEffect( () => { onDoneRef.current = onDone; } );
	useEffect( () => {
		mountedRef.current = true;
		return () => { mountedRef.current = false; };
	}, [] );

	// Infractions + incident-match candidates, each cancel-safe.
	useEffect( () => {
		let cancelled = false;
		fetchInfractions().then( ( list ) => {
			if ( ! cancelled ) setInfractions( list );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setInfractions( [] );
			setInfractionsError( err?.message || 'Could not load the infraction list.' );
		} );
		return () => { cancelled = true; };
	}, [] );

	useEffect( () => {
		let cancelled = false;
		fetchPlayerGames( player.id, season ).then( ( list ) => {
			if ( ! cancelled ) setGames( list );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setGames( [] );
			setGamesError( err?.message || 'Could not load this player’s games.' );
		} );
		return () => { cancelled = true; };
	}, [ player.id, season ] );

	const infraction = ( infractions || [] ).find( ( i ) => String( i.id ) === infractionId ) || null;
	const isGames = infraction ? infraction.outcome === 'games' : false;
	const gameNumber = gameCount === '' ? Number.NaN : Number( gameCount );
	const gamesValid = Number.isInteger( gameNumber ) && gameNumber >= 0 && gameNumber <= GAMES_MAX;

	let params = null;
	if ( infraction && ( ! isGames || gamesValid ) ) {
		params = { player: player.id, infraction: infraction.id };
		if ( season ) params.season = season;
		if ( incident ) params.incident_event = Number( incident );
		if ( isGames ) params.games = gameNumber;
	}
	const key = params ? JSON.stringify( params ) : '';

	// Debounced live preview. `cancelled` covers unmount/param change; the
	// sequence counter additionally drops any response that is not the newest.
	useEffect( () => {
		if ( ! key ) {
			seqRef.current += 1;
			setPreview( { status: 'idle' } );
			return undefined;
		}
		let cancelled = false;
		const seq = ++seqRef.current;
		setPreview( { status: 'pending' } );
		const timer = setTimeout( () => {
			setPreview( { status: 'loading' } );
			previewSuspension( JSON.parse( key ) ).then( ( data ) => {
				if ( cancelled || seq !== seqRef.current ) return;
				setPreview( { status: 'ok', data } );
				setLastOk( { key, data } );
			} ).catch( ( err ) => {
				if ( cancelled || seq !== seqRef.current ) return;
				setPreview( { status: 'error', message: err?.message || 'Preview unavailable' } );
			} );
		}, PREVIEW_DEBOUNCE_MS );
		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
	}, [ key ] );

	const effective = lastOk && key && lastOk.key === key ? lastOk.data : null;
	const warnings = effective && Array.isArray( effective.warnings ) ? effective.warnings : [];
	const blocked = warnings.indexOf( 'duplicate' ) !== -1;
	const noEmail = warnings.indexOf( 'no_player_email' ) !== -1;
	const locked = submitting || failedNotice !== null || duplicateOf !== null;
	const sendDisabled = locked || ! effective || blocked || noEmail;
	const draftDisabled = locked || ! effective || blocked;

	const onPickInfraction = ( value ) => {
		setInfractionId( value );
		const next = ( infractions || [] ).find( ( i ) => String( i.id ) === value );
		setGameCount( next && next.outcome === 'games' ? String( next.default_games ?? '' ) : '' );
	};

	// The row exists as failed: keep the dialog open to show why. onDone( notice )
	// fires when the convener closes it (or now, if already unmounted).
	const handleFailedSend = ( notice ) => {
		failedRef.current = notice;
		if ( ! mountedRef.current ) {
			if ( onDone ) onDone( notice );
			return;
		}
		setSubmitting( false );
		setFailedNotice( notice );
	};

	const finishCreate = ( res ) => {
		submittingRef.current = false;
		const notice = res?.notice || null;
		if ( isFailedSend( res, notice ) ) {
			handleFailedSend( notice );
			return;
		}
		if ( onDone ) onDone( notice );
		if ( mountedRef.current ) {
			setSubmitting( false );
			onClose();
		}
	};

	const failCreate = ( err ) => {
		submittingRef.current = false;
		if ( isUnknownOutcome( err ) && onDone ) onDone( null );
		if ( ! mountedRef.current ) return;
		if ( err?.code === 'splm_suspension_duplicate' ) setDuplicateOf( err.data?.duplicate_of || 0 );
		setSubmitting( false );
		setSubmitError( submitErrorMessage( err ) );
	};

	const submit = ( mode ) => {
		if ( ! params || submittingRef.current ) return;
		submittingRef.current = true;
		setSubmitting( true );
		setSubmitError( '' );
		createSuspension( { ...params, incident_note: note.trim(), mode } ).then( finishCreate ).catch( failCreate );
	};

	// Ignored mid-submit so the convener never closes on an in-flight create.
	// After a failed send, closing hands the failed notice to the parent.
	const requestClose = useCallback( () => {
		if ( submittingRef.current ) return;
		if ( failedRef.current && onDoneRef.current ) {
			const failed = failedRef.current;
			failedRef.current = null;
			onDoneRef.current( failed );
		}
		onClose();
	}, [ onClose ] );
	const trapRef = useFocusTrap( requestClose );

	// Both selects start disabled, so the trap's first-focusable lands on the
	// note. Once the infractions are in, move to the Infraction select unless
	// the user has deliberately gone elsewhere.
	useEffect( () => {
		if ( infractions === null || ! infractions.length ) return;
		const active = document.activeElement;
		const untouched = ! active || active === document.body || active === trapRef.current
			|| ( active === noteRef.current && note === '' );
		if ( untouched && infractionRef.current ) infractionRef.current.focus();
		// Deliberately keyed on the list arriving only, not on later note edits.
	}, [ infractions ] );

	// Keep focus inside the dialog when the focused button disables or vanishes.
	useEffect( () => {
		if ( failedNotice && closeBtnRef.current ) closeBtnRef.current.focus();
	}, [ failedNotice ] );
	useEffect( () => {
		if ( submitError && errorRef.current ) errorRef.current.focus();
	}, [ submitError ] );

	const ids = {
		title: `${ uid }-title`,
		infraction: `${ uid }-infraction`,
		infractionHint: `${ uid }-infraction-hint`,
		games: `${ uid }-games`,
		gamesHint: `${ uid }-games-hint`,
		incident: `${ uid }-incident`,
		note: `${ uid }-note`,
		noteHelp: `${ uid }-note-help`,
	};

	const loadingInfractions = infractions === null;
	const noInfractions = ! loadingInfractions && infractions.length === 0 && ! infractionsError;

	let previewContent;
	if ( ! infraction ) {
		previewContent = <p className="splm-discipline-muted">Choose an infraction to see the consequence and the emails.</p>;
	} else if ( isGames && ! gamesValid ) {
		previewContent = <p className="splm-discipline-muted">{ `Enter a length between 0 and ${ GAMES_MAX } games to see the preview.` }</p>;
	} else if ( preview.status === 'ok' ) {
		previewContent = <PreviewSummary data={ preview.data } />;
	} else if ( preview.status === 'error' ) {
		previewContent = (
			<p className="splm-discipline-preview__error">
				<strong>Preview unavailable:</strong> { preview.message }
				{ ! effective && ' Sending is disabled until a preview loads — change a field to retry.' }
			</p>
		);
	} else if ( preview.status === 'loading' ) {
		previewContent = <p className="splm-discipline-muted">Updating preview…</p>;
	} else {
		// Debounce pending: shown below, outside the live region, so it is not
		// announced for every keystroke.
		previewContent = null;
	}
	const paramsReady = infraction && ( ! isGames || gamesValid );

	return (
		<div
			className="splm-modal-overlay"
			role="presentation"
			onMouseDown={ ( e ) => { overlayDownRef.current = e.target === e.currentTarget; } }
			onClick={ ( e ) => {
				// Close only when the press AND the release were on the overlay,
				// so dragging a text selection out of a field keeps the dialog.
				if ( e.target === e.currentTarget && overlayDownRef.current ) requestClose();
				overlayDownRef.current = false;
			} }
		>
			<div
				ref={ trapRef }
				tabIndex={ -1 }
				className="splm-modal splm-modal--wide"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ ids.title }
			>
				<h3 id={ ids.title }>Issue a suspension — { player.name }</h3>

				{ infractionsError && <div className="splm-alert splm-alert--error" role="alert">{ infractionsError }</div> }
				{ loadingInfractions && <p role="status" className="splm-discipline-muted">Loading infractions…</p> }
				{ noInfractions && <p className="splm-discipline-muted">No active infractions. An administrator can add or re-activate them under Settings → SportsPress Admin Tools → League Manager → Discipline Rules.</p> }

				<form className="splm-discipline-form" onSubmit={ ( e ) => e.preventDefault() } noValidate>
					<label htmlFor={ ids.infraction }>Infraction</label>
					<select
						id={ ids.infraction }
						ref={ infractionRef }
						className="splm-select"
						value={ infractionId }
						onChange={ ( e ) => onPickInfraction( e.target.value ) }
						disabled={ locked || loadingInfractions || ! infractions.length }
						aria-describedby={ infraction ? ids.infractionHint : undefined }
					>
						<option value="">Select an infraction…</option>
						{ ( infractions || [] ).map( ( i ) => (
							<option key={ i.id } value={ i.id }>{ i.rule_ref ? `${ i.rule_ref } — ${ i.title }` : i.title }</option>
						) ) }
					</select>
					{ infraction && (
						<div id={ ids.infractionHint } className="splm-discipline-form__hint">
							{ infraction.rule_text && <p>{ infraction.rule_text }</p> }
							{ !! infraction.needs_review && <p><strong>Reviewed by the convener under the rulebook.</strong></p> }
						</div>
					) }

					{ infraction && isGames && (
						<>
							<label htmlFor={ ids.games }>Length (games)</label>
							<input
								id={ ids.games }
								type="number"
								min="0"
								max={ GAMES_MAX }
								step="1"
								value={ gameCount }
								onChange={ ( e ) => setGameCount( e.target.value ) }
								disabled={ locked }
								aria-invalid={ ! gamesValid }
								aria-describedby={ ids.gamesHint }
							/>
							<p id={ ids.gamesHint } className="splm-discipline-form__hint">0–{ GAMES_MAX } games</p>
						</>
					) }
					{ infraction && ! isGames && (
						<p className="splm-discipline-form__hint">Indefinite — pending your review; you can set a length later.</p>
					) }

					<label htmlFor={ ids.incident }>Incident match</label>
					<select
						id={ ids.incident }
						className="splm-select"
						value={ incident }
						onChange={ ( e ) => setIncident( e.target.value ) }
						disabled={ locked || games === null }
					>
						<option value="">No match / not during a game</option>
						{ ( games || [] ).map( ( g ) => (
							<option key={ g.id } value={ g.id }>
								{ g.label || `${ g.title }${ formatDate( ( g.date || '' ).slice( 0, 10 ) ) ? ` — ${ formatDate( ( g.date || '' ).slice( 0, 10 ) ) }` : '' }` }
							</option>
						) ) }
					</select>
					{ games === null && <p role="status" className="splm-discipline-muted">Loading games…</p> }
					{ gamesError && <div className="splm-alert splm-alert--error" role="alert">{ gamesError }</div> }

					<label htmlFor={ ids.note }>Incident note (private, never emailed)</label>
					<textarea
						id={ ids.note }
						ref={ noteRef }
						className="splm-textarea"
						rows={ 3 }
						maxLength={ NOTE_MAX }
						value={ note }
						onChange={ ( e ) => setNote( e.target.value ) }
						disabled={ locked }
						aria-describedby={ ids.noteHelp }
					/>
					<p id={ ids.noteHelp } className="splm-discipline-form__hint">
						Only conveners can see this note. It is never included in an email. { note.length } / { NOTE_MAX }
					</p>
				</form>

				<div className="splm-discipline-preview" aria-busy={ preview.status === 'pending' || preview.status === 'loading' }>
					<h4>Preview</h4>
					<div aria-live="polite">{ previewContent }</div>
					{ paramsReady && preview.status === 'pending' && <p className="splm-discipline-muted">Updating preview…</p> }
					{ paramsReady && preview.status === 'ok' && <PreviewDetails data={ preview.data } /> }
				</div>

				{ submitError && <div className="splm-alert splm-alert--error" role="alert" tabIndex={ -1 } ref={ errorRef }>{ submitError }</div> }
				{ failedNotice && (
					<div className="splm-alert splm-alert--error" role="alert">
						<span>
							The suspension was recorded but the email could not be sent{ failedNotice.last_error ? `: ${ failedNotice.last_error }` : '.' }
							{ ' ' }It is in the Notices queue, where you can retry it.
						</span>
					</div>
				) }

				<div className="splm-modal__actions">
					{ failedNotice ? (
						<button type="button" className="splm-btn splm-btn--primary" onClick={ requestClose } ref={ closeBtnRef }>Close</button>
					) : (
						<>
							<button type="button" className="splm-btn splm-btn--primary" onClick={ () => submit( 'send' ) } disabled={ sendDisabled }>
								{ submitting ? 'Working…' : 'Send now' }
							</button>
							<button type="button" className="splm-btn" onClick={ () => submit( 'draft' ) } disabled={ draftDisabled }>Save draft</button>
							<button type="button" className="splm-btn" onClick={ requestClose } disabled={ submitting }>Cancel</button>
						</>
					) }
				</div>
			</div>
		</div>
	);
}
