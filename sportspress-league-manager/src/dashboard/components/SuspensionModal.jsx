import { useState, useEffect, useRef } from '@wordpress/element';
import { fetchInfractions, fetchPlayerGames, previewSuspension, createSuspension } from '../lib/api';
import { formatDate } from '../lib/time';
import useFocusTrap from './useFocusTrap';

// Issue-suspension dialog: the convener picks an infraction, sees a live
// preview of the consequence and the emails, then sends or saves a draft.
//
// CONTRACT (focus trap): the PARENT must pass a `useCallback`-memoised
// `onClose`. useFocusTrap's effect depends on that function's identity, so an
// inline arrow would re-run it on every render and keep yanking focus back to
// the first field (and restore it to the trigger). Inside this component
// `onClose` is handed to the hook as-is — never wrap it in a fresh arrow.
//
// Props: { player: { id, name }, season, onClose, onDone( notice | null ) }.
// `season` may be falsy; the param is then omitted and the server falls back
// to the default season. onDone( notice ) fires after a create (including a
// failed send, whose row exists — check notice.status === 'failed'); it fires
// with null after a busy-lock 409 so the parent refreshes (the write may have
// happened). On success the modal then closes itself; after a failed send it
// stays open so the convener can read the error.

const NOTE_MAX = 2000;
const GAMES_MAX = 20;
const PREVIEW_DEBOUNCE_MS = 400;

// Module-level counter: stable unique ids per instance without randomness
// during render.
let modalCount = 0;

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
			{ warnings.map( ( code ) => {
				const { level, text } = describeWarning( code, duplicateOf );
				const cls = level === 'notice' ? '' : ` splm-discipline-warning--${ level }`;
				return (
					<li key={ code } className={ `splm-discipline-warning${ cls }` }>
						<strong>{ PREFIX[ level ] }</strong> { text }
					</li>
				);
			} ) }
		</ul>
	);
}

function eligibilityText( data ) {
	if ( data.outcome === 'indefinite' ) {
		return 'Suspended until you decide a length. You can set one later from the player’s Discipline panel.';
	}
	if ( data.games === 0 ) {
		return 'Balance of the game only — no further games are missed.';
	}
	if ( data.eligible_on ) {
		return `Next eligible game: ${ formatDate( data.eligible_on ) } (projected, if the schedule doesn’t change).`;
	}
	if ( data.remaining > 0 ) {
		return `No scheduled game yet for the full length — the remaining ${ data.remaining } game(s) will be served at the player’s next scheduled game(s).`;
	}
	return 'Not enough scheduled games to name a next eligible game yet.';
}

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
			{ captains.map( ( c ) => (
				<li key={ `${ c.team }-${ c.email || '' }` }>
					<strong>Captain, { c.team }:</strong>{ ' ' }
					{ c.email ? c.email : 'no email on file — not notified' }
					{ c.email && c.covered_by && ` (also receives the ${ c.covered_by === 'player' ? 'player' : 'convener' } copy)` }
				</li>
			) ) }
		</ul>
	);
}

function PreviewBody( { data } ) {
	return (
		<>
			<p className="splm-discipline-preview__result">{ eligibilityText( data ) }</p>
			<Warnings warnings={ Array.isArray( data.warnings ) ? data.warnings : [] } duplicateOf={ data.duplicate_of } />
			<h4>Who will be emailed</h4>
			<Recipients data={ data } />
			<details className="splm-discipline-details">
				<summary>Email to the player</summary>
				{ data.player_subject && <p><strong>Subject:</strong> { data.player_subject }</p> }
				<pre className="splm-discipline-email">{ data.player_body }</pre>
			</details>
			<details className="splm-discipline-details">
				<summary>Email to captains</summary>
				<pre className="splm-discipline-email">{ data.captain_body }</pre>
			</details>
		</>
	);
}

function submitErrorMessage( err ) {
	switch ( err?.code ) {
		case 'splm_suspension_duplicate': {
			const id = err.data?.duplicate_of;
			return `Already issued${ id ? ` — notice #${ id }` : '' }. Nothing was sent again.`;
		}
		case 'splm_notice_busy':
			return 'Another update is in progress — refresh before retrying.';
		case 'splm_no_lock':
			return 'Cannot send safely right now. Wait a moment and try again.';
		default:
			return err?.message || 'Something went wrong';
	}
}

export default function SuspensionModal( { player, season, onClose, onDone } ) {
	const idRef = useRef( null );
	if ( idRef.current === null ) {
		modalCount += 1;
		idRef.current = `splm-susp-${ modalCount }`;
	}
	const uid = idRef.current;

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

	const seqRef = useRef( 0 );
	const submittingRef = useRef( false );
	const mountedRef = useRef( true );
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
	const gameNumber = gameCount === '' ? NaN : Number( gameCount );
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
		setPreview( { status: 'loading' } );
		const timer = setTimeout( () => {
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
	const locked = submitting || failedNotice !== null;
	const sendDisabled = locked || ! effective || blocked || noEmail;
	const draftDisabled = locked || ! effective || blocked;

	const onPickInfraction = ( value ) => {
		setInfractionId( value );
		const next = ( infractions || [] ).find( ( i ) => String( i.id ) === value );
		setGameCount( next && next.outcome === 'games' ? String( next.default_games ) : '' );
	};

	const submit = ( mode ) => {
		if ( ! params || submittingRef.current ) return;
		submittingRef.current = true;
		setSubmitting( true );
		setSubmitError( '' );
		createSuspension( { ...params, incident_note: note.trim(), mode } ).then( ( res ) => {
			submittingRef.current = false;
			const notice = res?.notice || null;
			if ( res && res.sent === false && notice && notice.status === 'failed' ) {
				// The row exists as failed; keep the dialog open to show why.
				if ( onDone ) onDone( notice );
				if ( mountedRef.current ) {
					setSubmitting( false );
					setFailedNotice( notice );
				}
				return;
			}
			if ( onDone ) onDone( notice );
			if ( mountedRef.current ) {
				setSubmitting( false );
				onClose();
			}
		} ).catch( ( err ) => {
			submittingRef.current = false;
			// A busy lock means the write may or may not have happened.
			if ( err?.code === 'splm_notice_busy' && onDone ) onDone( null );
			if ( mountedRef.current ) {
				setSubmitting( false );
				setSubmitError( submitErrorMessage( err ) );
			}
		} );
	};

	// Passed as-is: see the CONTRACT note above.
	const trapRef = useFocusTrap( onClose );

	const ids = {
		title: `${ uid }-title`,
		infraction: `${ uid }-infraction`,
		infractionHint: `${ uid }-infraction-hint`,
		games: `${ uid }-games`,
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
		previewContent = <PreviewBody data={ preview.data } />;
	} else if ( preview.status === 'error' ) {
		previewContent = (
			<p className="splm-discipline-preview__error">
				<strong>Preview unavailable:</strong> { preview.message }
				{ ! effective && ' Sending is disabled until a preview loads — change a field to retry.' }
			</p>
		);
	} else {
		previewContent = <p className="splm-discipline-muted">Updating preview…</p>;
	}

	return (
		<div className="splm-modal-overlay" onClick={ onClose } ref={ trapRef } tabIndex={ -1 }>
			<div
				className="splm-modal splm-modal--wide"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ ids.title }
				onClick={ ( e ) => e.stopPropagation() }
			>
				<h3 id={ ids.title }>Issue a suspension — { player.name }</h3>

				{ infractionsError && <div className="splm-alert splm-alert--error" role="alert">{ infractionsError }</div> }
				{ loadingInfractions && <p role="status" className="splm-discipline-muted">Loading infractions…</p> }
				{ noInfractions && <p className="splm-discipline-muted">No infractions are configured yet.</p> }

				<form className="splm-discipline-form" onSubmit={ ( e ) => e.preventDefault() } noValidate>
					<label htmlFor={ ids.infraction }>Infraction</label>
					<select
						id={ ids.infraction }
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
							/>
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
								{ g.title }{ formatDate( ( g.date || '' ).slice( 0, 10 ) ) ? ` — ${ formatDate( ( g.date || '' ).slice( 0, 10 ) ) }` : '' }
							</option>
						) ) }
					</select>
					{ games === null && <p role="status" className="splm-discipline-muted">Loading games…</p> }
					{ gamesError && <div className="splm-alert splm-alert--error" role="alert">{ gamesError }</div> }

					<label htmlFor={ ids.note }>Incident note (private, never emailed)</label>
					<textarea
						id={ ids.note }
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

				<div className="splm-discipline-preview" aria-live="polite">
					<h4>Preview</h4>
					{ previewContent }
				</div>

				{ submitError && <div className="splm-alert splm-alert--error" role="alert">{ submitError }</div> }
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
						<button type="button" className="splm-btn splm-btn--primary" onClick={ onClose }>Close</button>
					) : (
						<>
							<button type="button" className="splm-btn splm-btn--primary" onClick={ () => submit( 'send' ) } disabled={ sendDisabled }>
								{ submitting ? 'Working…' : 'Send now' }
							</button>
							<button type="button" className="splm-btn" onClick={ () => submit( 'draft' ) } disabled={ draftDisabled }>Save draft</button>
							<button type="button" className="splm-btn" onClick={ onClose } disabled={ submitting }>Cancel</button>
						</>
					) }
				</div>
			</div>
		</div>
	);
}
