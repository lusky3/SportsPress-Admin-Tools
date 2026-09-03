import { useCallback, useEffect, useState } from '@wordpress/element';
import { fetchNotices, releaseNotice, discardNotice, serveNotice } from '../lib/api';

// Timestamps arrive as UTC 'Y-m-d H:i:s'. Date can't parse that shape reliably
// across browsers, so normalise it to ISO with an explicit Z before parsing —
// without the Z it would be read as local time, which is the same four-to-five
// hour error the server side guards against.
function parseUtc( value ) {
	if ( ! value ) {
		return null;
	}
	const parsed = new Date( value.replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( parsed.getTime() ) ? null : parsed;
}

function formatLocal( value ) {
	const date = parseUtc( value );
	return date ? date.toLocaleString() : '—';
}

// Conveners get plain language, never the stored vocabulary. 'baseline' in
// particular means "recorded so we don't mail them retroactively", which is
// not a phrase anyone should have to decode from a status badge.
const STATUS_LABELS = {
	baseline: 'On record',
	pending: 'Waiting for you',
	sent: 'Sent',
	failed: 'Could not send',
	discarded: 'Discarded',
	served: 'Served',
};

function consequenceLabel( row ) {
	if ( row.consequence === 'suspend' ) {
		return row.games === 1 ? 'Suspension — 1 game' : `Suspension — ${ row.games } games`;
	}
	return 'Warning';
}

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

function RowActions( { row, busy, onRelease, onDiscard, onServe } ) {
	const actionable = row.status === 'pending' || row.status === 'failed';
	const servable = row.status === 'sent' && row.consequence === 'suspend';

	if ( ! actionable && ! servable ) {
		return <td />;
	}

	return (
		<td>
			{ actionable && (
				<>
					<button type="button" className="splm-btn" disabled={ busy } onClick={ () => onRelease( row ) }>
						{ row.status === 'failed' ? 'Try again' : 'Release' }
					</button>{ ' ' }
					<button type="button" className="splm-btn splm-btn--secondary" disabled={ busy } onClick={ () => onDiscard( row ) }>
						Discard
					</button>
				</>
			) }
			{ servable && (
				<button type="button" className="splm-btn" disabled={ busy } onClick={ () => onServe( row ) }>
					Mark served
				</button>
			) }
		</td>
	);
}

// Which accumulation crossed the line. A convener seeing "8 PIM" needs to know
// whether that is a season total or a recent-window one, or the number looks
// wrong next to a player whose season total is far higher.
function penaltyLabel( row ) {
	if ( row.scope === 'window' ) {
		return `${ row.value_at_fire } PIM in the recent window`;
	}
	return `${ row.value_at_fire } PIM this season`;
}

function NoticeRow( { row, busy, onRelease, onDiscard, onServe } ) {
	return (
		<tr>
			<td>{ row.player || '—' }</td>
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
			<RowActions row={ row } busy={ busy } onRelease={ onRelease } onDiscard={ onDiscard } onServe={ onServe } />
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
					<option value="">Everything</option>
				</select>
			</label>
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

	// cancelled guards against a slower earlier request (e.g. from a filter
	// change that has since been superseded) overwriting the table with stale
	// data after a later request resolves first — same pattern as Waitlist.jsx.
	const load = useCallback( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		fetchNotices( { season, status } )
			.then( ( res ) => {
				if ( cancelled ) return;
				setRows( res.data );
				setTotal( res.total );
				setLoading( false );
			} )
			.catch( ( e ) => {
				if ( cancelled ) return;
				setError( e?.message || 'Could not load the notice queue.' );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [ season, status ] );

	useEffect( () => {
		const cleanup = load();
		return cleanup;
	}, [ load ] );

	const act = ( row, fn, confirmText, successText ) => {
		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}
		setBusyId( row.id );
		setError( '' );
		setNotice( '' );
		fn( row.id )
			.then( () => {
				setNotice( successText );
				load();
			} )
			.catch( ( e ) => setError( e?.message || 'That did not work.' ) )
			.finally( () => setBusyId( 0 ) );
	};

	const handleRelease = ( row ) =>
		act(
			row,
			releaseNotice,
			`Email ${ row.player } to tell them: ${ consequenceLabel( row ) }?`,
			'Notice sent.'
		);

	const handleDiscard = ( row ) =>
		act( row, discardNotice, `Discard this notice? ${ row.player } will not be told.`, 'Notice discarded.' );

	// serve() is a one-way sent -> served transition with no un-serve route on
	// the server, so it confirms like every other irreversible action here.
	const handleServe = ( row ) =>
		act(
			row,
			serveNotice,
			`Mark ${ row.player }'s suspension as served? This cannot be undone.`,
			'Suspension marked served.'
		);

	return (
		<div className="splm-notices">
			{ /* No HelpLink: Help.jsx's SECTIONS has no 'discipline' entry, so the
			     link would navigate to Help and then no-op looking for
			     #help-discipline. Add a Help section first if one is wanted. */ }
			<h2>Discipline Notices</h2>

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
			{ notice && <div className="splm-alert splm-alert--success" role="status">{ notice }</div> }

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
				<div className="splm-table-wrapper">
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
									busy={ busyId === row.id }
									onRelease={ handleRelease }
									onDiscard={ handleDiscard }
									onServe={ handleServe }
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
		</div>
	);
}
