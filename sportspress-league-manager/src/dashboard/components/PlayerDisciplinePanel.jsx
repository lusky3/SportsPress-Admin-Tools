import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import {
	fetchDiscipline,
	releaseNotice,
	discardNotice,
	serveNotice,
	decideSuspension,
	amendSuspension,
	revokeSuspension,
	recalculateSuspension,
} from '../lib/api';
import { STATUS_LABELS, consequenceLabel, penaltyLabel, kindLabel, replacedIds, availableActions } from '../lib/discipline';
import { formatLocal, formatDate } from '../lib/time';
import useFocusTrap from './useFocusTrap';
import useUid from './useUid';
import SuspensionModal from './SuspensionModal';

// Per-player discipline history with the actions a convener can take on each
// notice. Props: { player: { id, name }, season, onClose, notify }.
//
// CONTRACT (focus trap): the PARENT must pass a `useCallback`-memoised
// `onClose` (see SuspensionModal / useFocusTrap). This component derives a
// stable `requestClose` from it.
//
// Closing: Escape / overlay click / Close are ignored while a mutation is in
// flight and while the nested SuspensionModal is open (it owns Escape). With an
// inline editor open, Escape closes the editor first, the dialog second.

const GAMES_MAX = 20;

const ACTIONS = {
	release: { label: 'Release', call: ( row ) => releaseNotice( row.id ), done: 'Notice sent.' },
	discard: { label: 'Discard', call: ( row ) => discardNotice( row.id ), done: 'Notice discarded.' },
	serve: { label: 'Mark served', call: ( row ) => serveNotice( row.id ), done: 'Suspension marked served.' },
	decide: { label: 'Decide length', call: ( row, games ) => decideSuspension( row.id, games ), done: 'Suspension length set.' },
	amend: { label: 'Amend', call: ( row, games ) => amendSuspension( row.id, games ), done: 'Suspension amended.' },
	revoke: { label: 'Revoke', call: ( row, email ) => revokeSuspension( row.id, email ), done: 'Suspension withdrawn.' },
	recalculate: { label: 'Recalculate', call: ( row ) => recalculateSuspension( row.id ), done: 'Eligibility recalculated.' },
};
// Actions that open an inline editor/confirm first; the rest run on click.
const NEEDS_INPUT = [ 'release', 'discard', 'decide', 'amend', 'revoke' ];

const STALE_HINT = 'Refresh and check the current state before retrying.';

// A busy lock or a request that never completed means the write may or may not
// have happened, so say so (the list is reloaded either way).
function mutationError( err ) {
	const base = err?.message || 'That did not work.';
	if ( err?.code === 'splm_notice_busy' || ! err?.code || err.code === 'fetch_error' ) {
		return `${ base } ${ STALE_HINT }`;
	}
	return base;
}

function seasonNameFor( id ) {
	if ( ! id ) return 'Unknown season';
	const list = window.splmDashboard?.seasons;
	const found = Array.isArray( list ) ? list.find( ( s ) => String( s.id ) === String( id ) ) : null;
	return found?.name || `Season #${ id }`;
}

// Server order is newest-first; keep it, and order season groups by first
// appearance.
function groupBySeason( rows ) {
	const groups = [];
	const index = new Map();
	rows.forEach( ( row ) => {
		const key = row.season_id || 0;
		if ( ! index.has( key ) ) {
			index.set( key, { id: key, name: seasonNameFor( key ), rows: [] } );
			groups.push( index.get( key ) );
		}
		index.get( key ).rows.push( row );
	} );
	return groups;
}

function GamesEditor( { uid, label, min, initial, busy, onConfirm, onCancel } ) {
	const [ value, setValue ] = useState( initial );
	const inputRef = useRef( null );
	useEffect( () => { if ( inputRef.current ) inputRef.current.focus(); }, [] );
	const n = value === '' ? NaN : Number( value );
	const valid = Number.isInteger( n ) && n >= min && n <= GAMES_MAX;
	return (
		<form
			className="splm-discipline-history__editor"
			noValidate
			onSubmit={ ( e ) => {
				e.preventDefault();
				if ( valid && ! busy ) onConfirm( n );
			} }
		>
			<label htmlFor={ `${ uid }-games` }>{ label }</label>
			<input
				id={ `${ uid }-games` }
				ref={ inputRef }
				type="number"
				min={ min }
				max={ GAMES_MAX }
				step="1"
				value={ value }
				onChange={ ( e ) => setValue( e.target.value ) }
				disabled={ busy }
				aria-invalid={ ! valid }
				aria-describedby={ `${ uid }-games-hint` }
			/>
			<span id={ `${ uid }-games-hint` } className="splm-discipline-form__hint">{ min }–{ GAMES_MAX } games</span>
			<button type="submit" className="splm-btn splm-btn--small splm-btn--primary" disabled={ busy || ! valid }>Confirm</button>
			<button type="button" className="splm-btn splm-btn--small" onClick={ onCancel } disabled={ busy }>Cancel</button>
		</form>
	);
}

const DecideEditor = ( props ) => <GamesEditor { ...props } label="Length (games)" min={ 1 } />;
const AmendEditor = ( props ) => <GamesEditor { ...props } label="New length (games)" min={ 0 } />;

// Yes/no confirm rendered in the row. Focus lands on Cancel so an accidental
// second Enter never fires the destructive action.
function ConfirmInline( { uid, message, confirmLabel, danger, busy, onConfirm, onCancel, children } ) {
	const cancelRef = useRef( null );
	useEffect( () => { if ( cancelRef.current ) cancelRef.current.focus(); }, [] );
	return (
		<div className="splm-discipline-history__editor" role="group" aria-labelledby={ `${ uid }-confirm` }>
			<p id={ `${ uid }-confirm` } className="splm-discipline-history__confirm-text">{ message }</p>
			{ children }
			<button
				type="button"
				className={ `splm-btn splm-btn--small ${ danger ? 'splm-btn--danger' : 'splm-btn--primary' }` }
				onClick={ onConfirm }
				disabled={ busy }
			>{ confirmLabel }</button>
			<button type="button" className="splm-btn splm-btn--small" onClick={ onCancel } disabled={ busy } ref={ cancelRef }>Cancel</button>
		</div>
	);
}

function RevokeConfirm( { uid, name, busy, onConfirm, onCancel } ) {
	const [ email, setEmail ] = useState( true );
	return (
		<ConfirmInline
			uid={ uid }
			message={ `Withdraw this suspension for ${ name }?` }
			confirmLabel="Revoke"
			danger
			busy={ busy }
			onConfirm={ () => onConfirm( email ) }
			onCancel={ onCancel }
		>
			<label className="splm-discipline-history__check" htmlFor={ `${ uid }-email` }>
				<input id={ `${ uid }-email` } type="checkbox" checked={ email } onChange={ ( e ) => setEmail( e.target.checked ) } disabled={ busy } />
				{ ' ' }Email the player and captains a correction
			</label>
		</ConfirmInline>
	);
}

function RowEditor( { uid, row, mode, busy, onRun, onCancel } ) {
	const name = row.player || '';
	if ( mode === 'decide' ) {
		return <DecideEditor uid={ uid } initial="" busy={ busy } onConfirm={ ( n ) => onRun( row, 'decide', n ) } onCancel={ onCancel } />;
	}
	if ( mode === 'amend' ) {
		return <AmendEditor uid={ uid } initial={ String( row.games ?? '' ) } busy={ busy } onConfirm={ ( n ) => onRun( row, 'amend', n ) } onCancel={ onCancel } />;
	}
	if ( mode === 'revoke' ) {
		return <RevokeConfirm uid={ uid } name={ name } busy={ busy } onConfirm={ ( email ) => onRun( row, 'revoke', email ) } onCancel={ onCancel } />;
	}
	const release = mode === 'release';
	return (
		<ConfirmInline
			uid={ uid }
			message={ release ? `Email ${ name } to tell them: ${ consequenceLabel( row ) }?` : `Discard this notice? ${ name } will not be told.` }
			confirmLabel={ release ? 'Release' : 'Discard' }
			danger={ ! release }
			busy={ busy }
			onConfirm={ () => onRun( row, mode ) }
			onCancel={ onCancel }
		/>
	);
}

function RowActions( { row, actions, busy, btnRefs, onEdit, onRun } ) {
	return (
		<div className="splm-discipline-history__actions">
			{ actions.map( ( key ) => {
				const label = key === 'release' && row.status === 'failed' ? 'Try again' : ACTIONS[ key ].label;
				return (
					<button
						key={ key }
						type="button"
						className="splm-btn splm-btn--small"
						disabled={ busy }
						ref={ ( el ) => { btnRefs.current[ key ] = el; } }
						aria-label={ `${ label } — ${ consequenceLabel( row ) }, ${ formatLocal( row.sent_at || row.created_at ) }` }
						onClick={ () => ( NEEDS_INPUT.includes( key ) ? onEdit( row.id, key ) : onRun( row, key ) ) }
					>{ label }</button>
				);
			} ) }
		</div>
	);
}

function RowDetails( { row } ) {
	const when = formatLocal( row.sent_at || row.created_at );
	const manual = row.source === 'manual';
	const eligible = formatDate( row.eligible_on );
	const team = [ row.team, row.division ].filter( Boolean ).join( ' — ' );
	return (
		<>
			<div className="splm-discipline-history__head">
				<strong>{ consequenceLabel( row ) }</strong>
				<span className={ `splm-badge splm-badge--${ row.status }` }>{ STATUS_LABELS[ row.status ] || row.status }</span>
			</div>
			<p className="splm-discipline-history__meta">
				{ when } · { penaltyLabel( row ) }{ manual ? ` · ${ kindLabel( row ) }` : '' }{ team ? ` · ${ team }` : '' }
			</p>
			{ eligible && <p className="splm-discipline-history__meta">Next eligible game: { eligible }, projected</p> }
			{ manual && row.incident_note && (
				<p className="splm-discipline-history__private-note"><strong>Private note (conveners only):</strong> { row.incident_note }</p>
			) }
			{ row.status === 'failed' && row.last_error && (
				<p className="splm-discipline-history__error" role="alert">Could not send: { row.last_error }</p>
			) }
		</>
	);
}

function DisciplineRow( { uid, row, replacedSet, busy, mode, error, onEdit, onRun, onCancel } ) {
	const rowRef = useRef( null );
	const btnRefs = useRef( {} );
	const lastMode = useRef( null );
	const replaced = replacedSet.has( row.id );
	const actions = availableActions( row, replacedSet );

	// Put focus back on the action that opened the editor (or on the row if
	// that button is gone after the reload).
	useEffect( () => {
		if ( mode === null && lastMode.current ) {
			const btn = btnRefs.current[ lastMode.current ];
			( btn || rowRef.current )?.focus();
		}
		lastMode.current = mode;
	}, [ mode ] );

	return (
		<li
			ref={ rowRef }
			tabIndex={ -1 }
			className={ `splm-discipline-history__row${ replaced ? ' splm-discipline-history__row--replaced' : '' }` }
			aria-busy={ busy }
		>
			<RowDetails row={ row } />
			{ replaced && <p className="splm-discipline-history__meta"><em>Replaced by a later notice</em></p> }
			{ actions.length > 0 && <RowActions row={ row } actions={ actions } busy={ busy } btnRefs={ btnRefs } onEdit={ onEdit } onRun={ onRun } /> }
			{ mode && ! replaced && <RowEditor uid={ uid } row={ row } mode={ mode } busy={ busy } onRun={ onRun } onCancel={ onCancel } /> }
			{ error && <div className="splm-alert splm-alert--error" role="alert">{ error }</div> }
		</li>
	);
}

function SeasonGroup( { uid, group, replaced, busyId, editing, errors, handlers } ) {
	const titleId = `${ uid }-season-${ group.id }`;
	return (
		<section className="splm-discipline-history__season" aria-labelledby={ titleId }>
			<h4 id={ titleId }>{ group.name }</h4>
			<ul className="splm-discipline-history__list">
				{ group.rows.map( ( row ) => (
					<DisciplineRow
						key={ row.id }
						uid={ `${ uid }-r${ row.id }` }
						row={ row }
						replacedSet={ replaced }
						busy={ busyId === row.id }
						mode={ editing && editing.id === row.id ? editing.mode : null }
						error={ errors[ row.id ] || '' }
						{ ...handlers }
					/>
				) ) }
			</ul>
		</section>
	);
}

// Loads the history; every change to the inputs (or a `tick` bump after a
// mutation) cancels the previous request, and the sequence counter drops any
// answer that is not the newest.
function useDisciplineData( playerId, includeBaseline ) {
	const [ data, setData ] = useState( null ); // null = loading
	const [ loadError, setLoadError ] = useState( '' );
	const [ tick, setTick ] = useState( 0 );
	const seqRef = useRef( 0 );
	useEffect( () => {
		let cancelled = false;
		const seq = ++seqRef.current;
		fetchDiscipline( playerId, includeBaseline ).then( ( res ) => {
			if ( cancelled || seq !== seqRef.current ) return;
			setData( res );
			setLoadError( '' );
		} ).catch( ( err ) => {
			if ( cancelled || seq !== seqRef.current ) return;
			setLoadError( err?.message || 'Could not load the disciplinary record.' );
			setData( ( prev ) => prev || { rows: [], summary: '' } );
		} );
		return () => { cancelled = true; };
	}, [ playerId, includeBaseline, tick ] );
	const refresh = useCallback( () => setTick( ( t ) => t + 1 ), [] );
	return { data, setData, loadError, refresh };
}

export default function PlayerDisciplinePanel( { player, season, onClose, notify } ) {
	const uid = useUid( 'splm-disc' );
	const [ includeBaseline, setIncludeBaseline ] = useState( false );
	const { data, setData, loadError, refresh } = useDisciplineData( player.id, includeBaseline );
	const [ busyId, setBusyId ] = useState( 0 );
	const [ editing, setEditing ] = useState( null ); // { id, mode }
	const [ errors, setErrors ] = useState( {} );
	const [ issuing, setIssuing ] = useState( false );
	const [ issueError, setIssueError ] = useState( '' );

	const busyRef = useRef( false );
	const editingRef = useRef( null );
	const issuingRef = useRef( false );
	const mountedRef = useRef( true );
	const overlayDownRef = useRef( false );
	const issueBtnRef = useRef( null );
	const wasIssuing = useRef( false );
	useEffect( () => {
		mountedRef.current = true;
		return () => { mountedRef.current = false; };
	}, [] );
	useEffect( () => { editingRef.current = editing; }, [ editing ] );

	const setRowError = useCallback( ( id, message ) => {
		setErrors( ( prev ) => ( { ...prev, [ id ]: message } ) );
	}, [] );

	const runAction = useCallback( ( row, key, arg ) => {
		if ( busyRef.current ) return Promise.resolve( false );
		busyRef.current = true;
		setBusyId( row.id );
		setRowError( row.id, '' );
		return ACTIONS[ key ].call( row, arg ).then( ( res ) => {
			const failed = res && res.notice && res.notice.status === 'failed';
			if ( failed ) notify( `Saved, but the email could not be sent${ res.notice.last_error ? `: ${ res.notice.last_error }` : '.' }`, 'error' );
			else notify( ACTIONS[ key ].done, 'success' );
			return true;
		}, ( err ) => {
			if ( mountedRef.current ) setRowError( row.id, mutationError( err ) );
			return false;
		} ).then( ( ok ) => {
			busyRef.current = false;
			if ( mountedRef.current ) {
				setBusyId( 0 );
				if ( ok ) setEditing( null );
				refresh();
			}
			return ok;
		} );
	}, [ notify, refresh, setRowError ] );

	const openEditor = useCallback( ( id, mode ) => {
		if ( busyRef.current ) return;
		setRowError( id, '' );
		setEditing( { id, mode } );
	}, [ setRowError ] );
	const cancelEditor = useCallback( () => {
		if ( ! busyRef.current ) setEditing( null );
	}, [] );

	// Ignored mid-mutation and while the issue dialog is open; with an inline
	// editor open Escape closes that first.
	const requestClose = useCallback( () => {
		if ( busyRef.current || issuingRef.current ) return;
		if ( editingRef.current ) {
			setEditing( null );
			return;
		}
		onClose();
	}, [ onClose ] );
	const trapRef = useFocusTrap( requestClose );
	// The Close button always closes the dialog (Escape peels off an editor first).
	const closeDialog = () => {
		if ( ! busyRef.current && ! issuingRef.current ) onClose();
	};

	// Safari does not focus a button on click, so the modal cannot always
	// restore focus to it; do it explicitly when the modal closes.
	useEffect( () => {
		if ( wasIssuing.current && ! issuing && issueBtnRef.current ) issueBtnRef.current.focus();
		wasIssuing.current = issuing;
	}, [ issuing ] );

	const openIssue = () => {
		issuingRef.current = true;
		setIssueError( '' );
		setIssuing( true );
	};
	const closeIssue = useCallback( () => {
		issuingRef.current = false;
		setIssuing( false );
	}, [] );
	// See SuspensionModal's onDone contract: always refresh; a failed notice is
	// an error, never a success toast; null means "outcome unknown".
	const handleIssueDone = useCallback( ( notice ) => {
		refresh();
		if ( ! notice ) return;
		if ( notice.status === 'failed' ) {
			const msg = `The suspension was recorded but the email could not be sent${ notice.last_error ? `: ${ notice.last_error }` : '.' } It is in the Notices queue, where you can retry it.`;
			setIssueError( msg );
			notify( msg, 'error' );
			return;
		}
		notify( notice.status === 'pending' ? 'Draft saved' : 'Suspension recorded', 'success' );
	}, [ refresh, notify ] );

	const toggleBaseline = ( e ) => {
		setEditing( null );
		setData( null );
		setIncludeBaseline( e.target.checked );
	};

	const rows = data ? data.rows : [];
	const replaced = replacedIds( rows );
	const titleId = `${ uid }-title`;
	const handlers = { onEdit: openEditor, onRun: runAction, onCancel: cancelEditor };

	return (
		<>
			<div
				className="splm-modal-overlay"
				onMouseDown={ ( e ) => { overlayDownRef.current = e.target === e.currentTarget; } }
				onClick={ ( e ) => {
					if ( e.target === e.currentTarget && overlayDownRef.current ) requestClose();
					overlayDownRef.current = false;
				} }
				ref={ trapRef }
				tabIndex={ -1 }
			>
				<div className="splm-modal splm-modal--wide" role="dialog" aria-modal="true" aria-labelledby={ titleId }>
					<h3 id={ titleId }>Discipline — { player.name }</h3>
					<div className="splm-discipline-history__toolbar">
						<button type="button" className="splm-btn splm-btn--primary" ref={ issueBtnRef } onClick={ openIssue } disabled={ busyId !== 0 }>Issue suspension</button>
						<label htmlFor={ `${ uid }-baseline` }>
							<input id={ `${ uid }-baseline` } type="checkbox" checked={ includeBaseline } onChange={ toggleBaseline } />
							{ ' ' }Show recorded-only rows
						</label>
					</div>
					{ data && data.summary && <p className="splm-discipline-history__summary">{ data.summary }</p> }
					{ issueError && <div className="splm-alert splm-alert--error" role="alert">{ issueError }</div> }
					{ loadError && <div className="splm-alert splm-alert--error" role="alert">{ loadError }</div> }
					<div className="splm-discipline-history" aria-busy={ data === null }>
						{ data === null && <p role="status" className="splm-discipline-muted">Loading…</p> }
						{ data && ! rows.length && ! loadError && <p className="splm-discipline-muted">No disciplinary record.</p> }
						{ groupBySeason( rows ).map( ( group ) => (
							<SeasonGroup
								key={ group.id }
								uid={ uid }
								group={ group }
								replaced={ replaced }
								busyId={ busyId }
								editing={ editing }
								errors={ errors }
								handlers={ handlers }
							/>
						) ) }
					</div>
					<div className="splm-modal__actions">
						<button type="button" className="splm-btn" onClick={ closeDialog } disabled={ busyId !== 0 }>Close</button>
					</div>
				</div>
			</div>
			{ issuing && <SuspensionModal player={ player } season={ season } onClose={ closeIssue } onDone={ handleIssueDone } /> }
		</>
	);
}
