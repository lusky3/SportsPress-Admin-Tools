import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { fetchNotices, releaseNotice, discardNotice, serveNotice } from '../lib/api';
import HelpLink from '../components/HelpLink';
import ConfirmDialog from '../components/ConfirmDialog';
import PlayerPicker from '../components/PlayerPicker';
import PlayerDisciplinePanel from '../components/PlayerDisciplinePanel';
import SuspensionModal from '../components/SuspensionModal';
import useFocusTrap from '../components/useFocusTrap';
import useUid from '../components/useUid';
import { formatLocal } from '../lib/time';
import { STATUS_LABELS, consequenceLabel, penaltyLabel, kindLabel, replacedIds, availableActions, releaseMessage, captainWarning } from '../lib/discipline';

// Fail-open like Leaders.jsx; the server gate is the real enforcement.
const canUseDiscipline = () =>
	window.splmDashboard?.modules?.discipline !== false
	&& window.splmDashboard?.capabilities?.canManage !== false;

function Problem( { row } ) {
	if ( row.status !== 'failed' ) {
		return null;
	}
	// The stored last_error is written for the technical view. A convener needs
	// the one cause they can actually fix, in words.
	const missingEmail = /email/i.test( row.last_error || '' );
	return (
		<p className="splm-notice__problem">
			{ missingEmail
				? 'No email address on file for this player — add one, then release again.'
				: 'The email could not be sent. Try releasing it again.' }
		</p>
	);
}

function RowActions( { row, replaced, busy, canManage, onRelease, onDiscard, onServe, onManage } ) {
	const actions = availableActions( row, replaced );
	const isReplaced = replaced.has( row.id );
	const manageable = canManage && row.source === 'manual';

	if ( actions.length === 0 && ! isReplaced && ! manageable ) {
		return <td />;
	}

	return (
		<td>
			{ ( actions.includes( 'release' ) || actions.includes( 'discard' ) ) && (
				<>
					<button type="button" className="splm-btn" disabled={ busy } onClick={ () => onRelease( row ) }>
						{ row.status === 'failed' ? 'Try again' : 'Release' }
					</button>{ ' ' }
					<button type="button" className="splm-btn splm-btn--secondary" disabled={ busy } onClick={ () => onDiscard( row ) }>
						Discard
					</button>
				</>
			) }
			{ actions.includes( 'serve' ) && (
				<button type="button" className="splm-btn" disabled={ busy } onClick={ () => onServe( row ) }>
					Mark served
				</button>
			) }
			{ isReplaced && <p className="splm-muted">Replaced by a later notice</p> }
			{ manageable && (
				<>
					{ ' ' }
					<button
						type="button"
						className="splm-btn splm-btn--small"
						aria-label={ `Manage suspensions for ${ row.player }` }
						onClick={ () => onManage( row ) }
					>
						Manage
					</button>
				</>
			) }
		</td>
	);
}

function NoticeRow( { row, replaced, busy, canManage, onRelease, onDiscard, onServe, onManage } ) {
	const manual = row.source === 'manual';
	return (
		<tr>
			<td>
				{ row.player || '—' }
				{ manual && <small className="splm-muted splm-notices__kind">{ kindLabel( row ) }</small> }
			</td>
			<td>{ row.team || '—' }</td>
			<td>{ row.division || '—' }</td>
			<td>{ penaltyLabel( row ) }</td>
			<td>{ consequenceLabel( row ) }</td>
			<td>
				<span className={ `splm-badge splm-badge--${ row.status }` }>
					{ STATUS_LABELS[ row.status ] || row.status }
				</span>
				<Problem row={ row } />
			</td>
			<td>{ formatLocal( row.sent_at || row.created_at ) }</td>
			<RowActions
				row={ row }
				replaced={ replaced }
				busy={ busy }
				canManage={ canManage }
				onRelease={ onRelease }
				onDiscard={ onDiscard }
				onServe={ onServe }
				onManage={ onManage }
			/>
		</tr>
	);
}

function Filters( { status, onStatusChange } ) {
	return (
		<div className="splm-filters">
			<label>
				Show{ ' ' }
				<select value={ status } onChange={ ( e ) => onStatusChange( e.target.value ) }>
					<option value="pending">Waiting for you</option>
					<option value="failed">Could not send</option>
					<option value="sent">Sent</option>
					<option value="served">Served</option>
					<option value="discarded">Discarded</option>
					<option value="revoked">Withdrawn</option>
					<option value="baseline">On record</option>
					<option value="">Everything</option>
				</select>
			</label>
		</div>
	);
}

// First step of "Issue suspension": pick the player. Selecting one hands over
// to SuspensionModal (the page swaps this dialog for that one).
function PlayerPickDialog( { onSelect, onClose } ) {
	const titleId = useUid( 'splm-issue-pick' );
	const trapRef = useFocusTrap( onClose );
	return (
		<div className="splm-modal-overlay">
			<div ref={ trapRef } className="splm-modal" role="dialog" aria-modal="true" aria-labelledby={ titleId } tabIndex={ -1 }>
				<h3 id={ titleId }>Issue a suspension</h3>
				<PlayerPicker label="Find the player (at least 3 letters)" onSelect={ onSelect } autoFocus />
				<div className="splm-modal__actions">
					<button type="button" className="splm-btn" onClick={ onClose }>Cancel</button>
				</div>
			</div>
		</div>
	);
}

export default function Notices( { season } ) {
	const [ rows, setRows ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ status, setStatus ] = useState( 'pending' );
	const [ busyId, setBusyId ] = useState( 0 );
	const [ confirm, setConfirm ] = useState( null );
	const [ picking, setPicking ] = useState( false );
	const [ issuePlayer, setIssuePlayer ] = useState( null );
	const [ managePlayer, setManagePlayer ] = useState( null );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ focusTick, setFocusTick ] = useState( 0 );
	const pageRef = useRef( null );
	const headingRef = useRef( null );
	const issueBtnRef = useRef( null );
	const refocusIssueRef = useRef( false );
	const seqRef = useRef( 0 );

	const canManage = canUseDiscipline();
	const replaced = replacedIds( rows );

	// cancelled guards against a slower earlier request (e.g. from a filter
	// change that has since been superseded) overwriting the table with stale
	// data after a later request resolves first — same pattern as Waitlist.jsx.
	// `silent` is a reload after a change: the rows stay on screen (aria-busy)
	// rather than the table unmounting, which would drop keyboard focus.
	const load = useCallback( ( silent = false ) => {
		let cancelled = false;
		// Only the newest request may apply its answer, so a slower stale
		// response (e.g. from before a filter change) never overwrites it.
		const seq = ++seqRef.current;
		if ( silent ) {
			setRefreshing( true );
		} else {
			setLoading( true );
		}
		setError( '' );
		fetchNotices( { season, status } )
			.then( ( res ) => {
				if ( cancelled || seq !== seqRef.current ) return;
				setRows( res.data );
				setTotal( res.total );
				setLoading( false );
				setRefreshing( false );
				if ( silent ) {
					setFocusTick( ( t ) => t + 1 );
				}
			} )
			.catch( ( e ) => {
				if ( cancelled || seq !== seqRef.current ) return;
				setError( e?.message || 'Could not load the notice queue.' );
				setLoading( false );
				setRefreshing( false );
				if ( silent ) {
					setFocusTick( ( t ) => t + 1 );
				}
			} );
		return () => { cancelled = true; };
	}, [ season, status ] );

	useEffect( () => {
		const cleanup = load();
		return cleanup;
	}, [ load ] );

	// After a change settles, the control that had focus may be gone or
	// disabled and the browser drops focus to <body>. Hand it to the result
	// message, else the heading. Never on the initial load or a filter change
	// (focusTick is only bumped by changes), and never when focus is still
	// somewhere on the page (e.g. an open dialog).
	useEffect( () => {
		if ( focusTick === 0 ) return;
		const page = pageRef.current;
		const active = document.activeElement;
		if ( ! page || ( active && active !== document.body && page.contains( active ) ) ) return;
		( page.querySelector( '.splm-alert' ) || headingRef.current )?.focus();
	}, [ focusTick ] );

	const run = ( row, fn, successText, warnVerb ) => {
		setBusyId( row.id );
		setError( '' );
		setNotice( '' );
		let failure = '';
		fn( row.id )
			.then( ( res ) => {
				// A released manual notice also mails the captains: a missed one is
				// a warning, not a success. Automatic responses carry no captains.
				const warning = warnVerb ? captainWarning( { status: 'sent', captains_notified: res?.captains }, warnVerb ) : '';
				if ( warning ) {
					failure = warning;
				} else {
					setNotice( successText );
				}
			} )
			.catch( ( e ) => { failure = e?.message || 'That did not work.'; } )
			.finally( () => {
				// Reload either way: a failed send leaves the row 'failed' and a
				// busy 409 may still have written, so the list must not go stale.
				// load() clears the error, so set it afterwards.
				load( true );
				if ( failure ) setError( failure );
				setBusyId( 0 );
				setFocusTick( ( t ) => t + 1 );
			} );
	};

	// Every action here asks first; the dialog closes before the request runs
	// and the row's own controls are disabled via busyId while it does.
	const ask = ( row, fn, message, successText, confirmLabel, danger = false, warnVerb = '' ) =>
		setConfirm( { row, fn, message, successText, confirmLabel, danger, warnVerb } );

	const handleRelease = ( row ) =>
		ask(
			row,
			releaseNotice,
			releaseMessage( row, row.player ),
			'Notice sent.',
			row.status === 'failed' ? 'Try again' : 'Release',
			false,
			'Sent'
		);

	const handleDiscard = ( row ) =>
		ask( row, discardNotice, `Discard this notice? ${ row.player } will not be told.`, 'Notice discarded.', 'Discard', true );

	// serve() is a one-way sent -> served transition with no un-serve route on
	// the server, so it confirms like every other irreversible action here.
	const handleServe = ( row ) =>
		ask(
			row,
			serveNotice,
			`Mark ${ row.player }'s suspension as served? This cannot be undone.`,
			'Suspension marked served.',
			'Mark served'
		);

	const cancelConfirm = useCallback( () => setConfirm( null ), [] );
	const doConfirm = () => {
		const c = confirm;
		setConfirm( null );
		run( c.row, c.fn, c.successText, c.warnVerb );
	};

	const closePick = useCallback( () => setPicking( false ), [] );
	const pickPlayer = useCallback( ( p ) => {
		setPicking( false );
		setIssuePlayer( { id: p.id, name: p.name } );
	}, [] );
	const closeIssue = useCallback( () => {
		refocusIssueRef.current = true;
		setIssuePlayer( null );
	}, [] );
	// The picker dialog that led here is gone, so the trap cannot restore focus
	// to it: hand it to the Issue button (or the heading) once the modal closes.
	useEffect( () => {
		if ( issuePlayer || ! refocusIssueRef.current ) return;
		refocusIssueRef.current = false;
		( issueBtnRef.current || headingRef.current )?.focus();
	}, [ issuePlayer ] );
	const issueDone = useCallback( ( n ) => {
		load( true );
		if ( ! n ) return;
		setNotice( '' );
		if ( n.status === 'failed' ) {
			setError( n.last_error || 'The email could not be sent.' );
			return;
		}
		const warning = captainWarning( n );
		if ( warning ) {
			setError( warning );
			return;
		}
		setNotice( n.status === 'pending' ? 'Draft saved.' : 'Suspension recorded.' );
	}, [ load ] );

	const openManage = ( row ) => setManagePlayer( { id: row.player_id, name: row.player } );
	const closeManage = useCallback( () => {
		setManagePlayer( null );
		load( true );
	}, [ load ] );
	// The panel reports into the page's own alert regions instead of a toast.
	const manageNotify = useCallback( ( message, type ) => {
		if ( type === 'error' ) {
			setError( message );
		} else {
			setNotice( message );
		}
	}, [] );

	return (
		<div className="splm-notices" ref={ pageRef }>
			<div className="splm-notices__header">
				<h2 ref={ headingRef } tabIndex={ -1 }>Discipline Notices <HelpLink topic="notices" /></h2>
				{ canManage && (
					<button type="button" className="splm-btn splm-btn--primary" ref={ issueBtnRef } onClick={ () => setPicking( true ) }>
						Issue suspension
					</button>
				) }
			</div>

			{ error && <div className="splm-alert splm-alert--warning" role="alert" tabIndex={ -1 }>{ error }</div> }
			{ notice && <div className="splm-alert splm-alert--success" role="status" tabIndex={ -1 }>{ notice }</div> }

			<Filters status={ status } onStatusChange={ setStatus } />

			{ loading && <div className="splm-loading">Loading…</div> }

			{ ! loading && rows.length === 0 && (
				<p className="splm-empty">
					{ status === 'pending'
						? 'Nothing is waiting for you.'
						: 'No notices match this filter.' }
				</p>
			) }

			{ ! loading && rows.length > 0 && (
				<div className="splm-table-wrapper" aria-busy={ refreshing }>
					<table className="splm-table splm-notices__table">
						<thead>
							<tr>
								<th scope="col">Player</th>
								<th scope="col">Team</th>
								<th scope="col">Division</th>
								<th scope="col">Penalties</th>
								<th scope="col">Consequence</th>
								<th scope="col">Status</th>
								<th scope="col">When</th>
								<th scope="col">Actions</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<NoticeRow
									key={ row.id }
									row={ row }
									replaced={ replaced }
									busy={ busyId === row.id }
									canManage={ canManage }
									onRelease={ handleRelease }
									onDiscard={ handleDiscard }
									onServe={ handleServe }
									onManage={ openManage }
								/>
							) ) }
						</tbody>
					</table>
					{ total > rows.length && (
						<p className="splm-muted">
							Showing the { rows.length } most recent of { total }. Narrow the filter to see
							the rest.
						</p>
					) }
				</div>
			) }

			{ confirm && (
				<ConfirmDialog
					message={ confirm.message }
					confirmLabel={ confirm.confirmLabel }
					danger={ confirm.danger }
					onConfirm={ doConfirm }
					onCancel={ cancelConfirm }
				/>
			) }
			{ picking && <PlayerPickDialog onSelect={ pickPlayer } onClose={ closePick } /> }
			{ issuePlayer && (
				<SuspensionModal player={ issuePlayer } season={ season } onClose={ closeIssue } onDone={ issueDone } />
			) }
			{ managePlayer && (
				<PlayerDisciplinePanel player={ managePlayer } season={ season } onClose={ closeManage } notify={ manageNotify } />
			) }
		</div>
	);
}
