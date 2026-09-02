import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	fetchWaitlist,
	addWaitlistEntry,
	offerWaitlistSpot,
	cancelWaitlistEntry,
	setWaitlistGate,
	setWaitlistTarget,
} from '../lib/api';
import HelpLink from '../components/HelpLink';

// M3: mirrors SPLM_Waitlist::DEFAULT_HOURS/MIN_HOURS/MAX_HOURS, localized via
// splmDashboard.waitlistHours so the two copies of these bounds cannot drift.
// The literals here are only the last-resort fallback if that config is
// somehow missing.
const HOURS_CONFIG = ( window.splmDashboard && window.splmDashboard.waitlistHours ) || {};
const DEFAULT_HOURS = HOURS_CONFIG.default || 48;
const MIN_HOURS = HOURS_CONFIG.min || 1;
const MAX_HOURS = HOURS_CONFIG.max || 720;

// Deadlines arrive as UTC 'Y-m-d H:i:s'. Date can't parse that shape reliably
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

function Countdown( { expiresAt } ) {
	const [ now, setNow ] = useState( () => Date.now() );

	useEffect( () => {
		const timer = setInterval( () => setNow( Date.now() ), 30000 );
		return () => clearInterval( timer );
	}, [] );

	const target = parseUtc( expiresAt );
	if ( ! target ) {
		return null;
	}

	const remaining = target.getTime() - now;
	if ( remaining <= 0 ) {
		return <span className="splm-waitlist__countdown splm-waitlist__countdown--lapsed">expired</span>;
	}

	const hours = Math.floor( remaining / 3600000 );
	const minutes = Math.floor( ( remaining % 3600000 ) / 60000 );
	const label = hours > 0 ? `${ hours }h ${ minutes }m left` : `${ minutes }m left`;

	return (
		<span className={ `splm-waitlist__countdown${ hours < 6 ? ' splm-waitlist__countdown--soon' : '' }` }>
			{ label }
		</span>
	);
}

export default function Waitlist() {
	const [ rows, setRows ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ warnings, setWarnings ] = useState( [] );
	// Raw text box vs the debounced value actually sent to the server, so we
	// don't fire a request on every keystroke — matches Payments.jsx's search
	// box. Position and status are discrete selects, so they stay immediate.
	const [ seasonInput, setSeasonInput ] = useState( '' );
	const [ season, setSeason ] = useState( '' );
	const [ position, setPosition ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ gates, setGates ] = useState( {} );
	const [ busyId, setBusyId ] = useState( 0 );
	const [ adding, setAdding ] = useState( false );
	const [ form, setForm ] = useState( { name: '', email: '', season: '', position: 'player', target_product_id: '' } );
	// I1: per-row draft value for the inline "set a target product" control,
	// keyed by row id, plus which row's Set button is mid-request.
	const [ targetInputs, setTargetInputs ] = useState( {} );
	const [ settingTargetId, setSettingTargetId ] = useState( 0 );

	// Debounce the season box (300ms) into the value used for fetching.
	useEffect( () => {
		const t = setTimeout( () => setSeason( seasonInput.trim() ), 300 );
		return () => clearTimeout( t );
	}, [ seasonInput ] );

	// cancelled guards against a slower earlier request (e.g. from a filter
	// change that has since been superseded) overwriting the table with stale
	// data after a later request resolves first — same pattern as
	// Payments.jsx / Schedule.jsx.
	const load = useCallback( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		fetchWaitlist( { season, position, status } )
			.then( ( res ) => {
				if ( cancelled ) return;
				setRows( res.data );
				// Seed/refresh the Season access panel's gate state from the
				// rows just fetched. target_gated is the server's live truth,
				// so this keeps the panel honest on first render and every
				// reload, instead of defaulting every product to "Gate" (not
				// gated) until a convener happens to toggle it.
				setGates( ( prev ) => {
					const next = { ...prev };
					res.data.forEach( ( row ) => {
						if ( row.target_product_id > 0 ) {
							next[ row.target_product_id ] = Boolean( row.target_gated );
						}
					} );
					return next;
				} );
				setLoading( false );
			} )
			.catch( ( e ) => {
				if ( cancelled ) return;
				setError( e?.message || 'Could not load the waitlist.' );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [ season, position, status ] );

	useEffect( () => {
		const cleanup = load();
		return cleanup;
	}, [ load ] );

	// Target products for the Season access panel, derived from the rows on
	// screen so the panel always describes what the convener is looking at.
	const targets = useMemo( () => {
		const seen = new Map();
		rows.forEach( ( row ) => {
			if ( row.target_product_id > 0 && ! seen.has( row.target_product_id ) ) {
				seen.set( row.target_product_id, { id: row.target_product_id, season: row.season, position: row.position } );
			}
		} );
		return Array.from( seen.values() );
	}, [ rows ] );

	const handleOffer = ( row ) => {
		const input = window.prompt(
			`Offer this spot to ${ row.name || row.email }? This emails them a real claim link. Claim window in hours:`,
			String( DEFAULT_HOURS )
		);
		if ( input === null ) {
			return;
		}
		const hours = Number( input );
		if ( ! Number.isInteger( hours ) || hours < MIN_HOURS || hours > MAX_HOURS ) {
			setError( `The claim window must be a whole number of hours between ${ MIN_HOURS } and ${ MAX_HOURS }.` );
			return;
		}

		setBusyId( row.id );
		setError( '' );
		setNotice( '' );
		setWarnings( [] );
		offerWaitlistSpot( row.id, hours )
			.then( ( res ) => {
				setNotice( `Offer sent. It expires ${ formatLocal( res.expires_at ) }.` );
				setWarnings( res.warnings || [] );
				load();
			} )
			.catch( ( e ) => setError( e?.message || 'Could not send the offer.' ) )
			.finally( () => setBusyId( 0 ) );
	};

	const handleCancel = ( row ) => {
		const label = row.status === 'offered' ? 'Cancel this offer?' : 'Remove this entry from the queue?';
		// Matches every other bulk/irreversible action in this dashboard.
		if ( ! window.confirm( label ) ) {
			return;
		}
		setBusyId( row.id );
		setError( '' );
		cancelWaitlistEntry( row.id )
			.then( () => load() )
			.catch( ( e ) => setError( e?.message || 'Could not cancel.' ) )
			.finally( () => setBusyId( 0 ) );
	};

	const handleGate = ( productId, gated ) => {
		const label = gated
			? 'Gate this product? The public will no longer be able to buy it without an offer.'
			: 'Un-gate this product? Anyone will be able to buy it again.';
		if ( ! window.confirm( label ) ) {
			return;
		}
		setError( '' );
		setWaitlistGate( productId, gated )
			.then( ( res ) => {
				setGates( ( prev ) => ( { ...prev, [ productId ]: res.gated } ) );
				setNotice( res.gated ? 'Product gated.' : 'Product un-gated.' );
			} )
			.catch( ( e ) => setError( e?.message || 'Could not change gating.' ) );
	};

	// I1: pair a queued/expired row with a registration product in place,
	// instead of the only prior path (Remove + re-Add), which loses the
	// row's source order and original queue position.
	const handleSetTarget = ( row ) => {
		const raw = targetInputs[ row.id ];
		const targetProductId = Number( raw );
		if ( ! raw || ! Number.isInteger( targetProductId ) || targetProductId <= 0 ) {
			setError( 'Enter a valid registration product ID.' );
			return;
		}
		setSettingTargetId( row.id );
		setError( '' );
		setNotice( '' );
		setWaitlistTarget( row.id, targetProductId )
			.then( () => {
				setNotice( 'Registration product set.' );
				setTargetInputs( ( prev ) => {
					const next = { ...prev };
					delete next[ row.id ];
					return next;
				} );
				load();
			} )
			.catch( ( e ) => setError( e?.message || 'Could not set the registration product.' ) )
			.finally( () => setSettingTargetId( 0 ) );
	};

	const handleAdd = ( event ) => {
		event.preventDefault();
		setAdding( true );
		setError( '' );
		addWaitlistEntry( { ...form, target_product_id: Number( form.target_product_id ) } )
			.then( () => {
				setForm( { name: '', email: '', season: '', position: 'player', target_product_id: '' } );
				setNotice( 'Entry added to the queue.' );
				load();
			} )
			.catch( ( e ) => setError( e?.message || 'Could not add the entry.' ) )
			.finally( () => setAdding( false ) );
	};

	return (
		<div className="splm-waitlist">
			<h2>Waitlist <HelpLink topic="waitlist" /></h2>

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }
			{ notice && <div className="splm-alert splm-alert--success" role="status">{ notice }</div> }
			{ warnings.map( ( w ) => (
				<div key={ w.code } className="splm-alert splm-alert--warning" role="alert">{ w.message }</div>
			) ) }

			<div className="splm-card splm-waitlist__access">
				<h3>Season access</h3>
				<p className="splm-muted">
					A gated product cannot be bought by the public — only by someone holding a live
					offer. Un-gating puts it back on sale to anyone who has its URL.
				</p>
				{ targets.length === 0 && <p className="splm-empty">No registration products for the current filter.</p> }
				<ul className="splm-waitlist__gates">
					{ targets.map( ( t ) => {
						const gated = gates[ t.id ];
						return (
							<li key={ t.id }>
								<span>#{ t.id } — { t.season } { t.position }</span>
								<button type="button" className="splm-btn splm-btn--small" onClick={ () => handleGate( t.id, ! gated ) }>
									{ gated ? 'Un-gate' : 'Gate' }
								</button>
							</li>
						);
					} ) }
				</ul>
			</div>

			<div className="splm-waitlist__filters">
				<label>
					<span className="splm-waitlist__filter-label">Season</span>
					<input
						type="text"
						className="splm-select"
						value={ seasonInput }
						onChange={ ( e ) => setSeasonInput( e.target.value ) }
					/>
				</label>
				<label>
					<span className="splm-waitlist__filter-label">Position</span>
					<select
						className="splm-select"
						value={ position }
						onChange={ ( e ) => setPosition( e.target.value ) }
					>
						<option value="">All</option>
						<option value="player">Player</option>
						<option value="goalie">Goalie</option>
					</select>
				</label>
				<label>
					<span className="splm-waitlist__filter-label">Status</span>
					<select
						className="splm-select"
						value={ status }
						onChange={ ( e ) => setStatus( e.target.value ) }
					>
						<option value="">All</option>
						<option value="queued">Queued</option>
						<option value="offered">Offered</option>
						<option value="claimed">Claimed</option>
						<option value="expired">Expired</option>
						<option value="cancelled">Cancelled</option>
					</select>
				</label>
			</div>

			{ loading && <div className="splm-loading">Loading…</div> }

			{ ! loading && rows.length === 0 && <p className="splm-empty">Nobody is on the waitlist for this filter.</p> }

			{ ! loading && rows.length > 0 && (
				<div className="splm-table-wrapper">
					<table className="splm-table splm-waitlist__table">
						<thead>
							<tr>
								<th scope="col">Joined</th>
								<th scope="col">Name</th>
								<th scope="col">Email</th>
								<th scope="col">Season</th>
								<th scope="col">Position</th>
								<th scope="col">Status</th>
								<th scope="col">Deadline</th>
								<th scope="col">Actions</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.id }>
									<td>{ formatLocal( row.created_at ) }</td>
									<td>{ row.name || '—' }</td>
									<td>{ row.email }</td>
									<td>{ row.season }</td>
									<td>{ row.position }</td>
									<td>
										<span className={ `splm-waitlist__status splm-waitlist__status--${ row.status }` }>
											{ row.status }
										</span>
										{ ! row.has_target && (
											<div className="splm-waitlist__flag" role="note">
												<span>No registration product paired — set one before offering.</span>
												<input
													type="number"
													className="splm-select splm-waitlist__target-input"
													min="1"
													placeholder="Product ID"
													value={ targetInputs[ row.id ] ?? '' }
													onChange={ ( e ) => setTargetInputs( ( prev ) => ( { ...prev, [ row.id ]: e.target.value } ) ) }
												/>
												<button
													type="button"
													className="splm-btn splm-btn--small"
													disabled={ settingTargetId === row.id }
													onClick={ () => handleSetTarget( row ) }
												>
													{ settingTargetId === row.id ? 'Setting…' : 'Set' }
												</button>
											</div>
										) }
									</td>
									<td>
										{ row.status === 'offered' ? (
											<>
												{ formatLocal( row.expires_at ) }{ ' ' }
												<Countdown expiresAt={ row.expires_at } />
											</>
										) : (
											'—'
										) }
									</td>
									<td className="splm-waitlist__actions">
										{ ( row.status === 'queued' || row.status === 'expired' ) && (
											<button
												type="button"
												className="splm-btn splm-btn--small"
												disabled={ busyId === row.id || ! row.has_target }
												title={ row.has_target ? '' : 'This entry has no registration product paired.' }
												onClick={ () => handleOffer( row ) }
											>
												{ row.status === 'expired' ? 'Re-offer' : 'Offer' }
											</button>
										) }
										{ row.status !== 'claimed' && row.status !== 'cancelled' && (
											<button
												type="button"
												className="splm-btn splm-btn--small splm-btn--danger"
												disabled={ busyId === row.id }
												onClick={ () => handleCancel( row ) }
											>
												{ row.status === 'offered' ? 'Cancel offer' : 'Remove' }
											</button>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			<div className="splm-card splm-waitlist__add">
				<h3>Add to waitlist</h3>
				<form onSubmit={ handleAdd } className="splm-waitlist__add-form">
					<label>
						<span className="splm-waitlist__filter-label">Name</span>
						<input
							type="text"
							className="splm-select"
							required
							value={ form.name }
							onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) }
						/>
					</label>
					<label>
						<span className="splm-waitlist__filter-label">Email</span>
						<input
							type="email"
							className="splm-select"
							required
							value={ form.email }
							onChange={ ( e ) => setForm( { ...form, email: e.target.value } ) }
						/>
					</label>
					<label>
						<span className="splm-waitlist__filter-label">Season</span>
						<input
							type="text"
							className="splm-select"
							required
							placeholder="S2026"
							value={ form.season }
							onChange={ ( e ) => setForm( { ...form, season: e.target.value } ) }
						/>
					</label>
					<label>
						<span className="splm-waitlist__filter-label">Position</span>
						<select
							className="splm-select"
							value={ form.position }
							onChange={ ( e ) => setForm( { ...form, position: e.target.value } ) }
						>
							<option value="player">Player</option>
							<option value="goalie">Goalie</option>
						</select>
					</label>
					<label>
						<span className="splm-waitlist__filter-label">Registration product ID</span>
						<input
							type="number"
							className="splm-select"
							required
							min="1"
							value={ form.target_product_id }
							onChange={ ( e ) => setForm( { ...form, target_product_id: e.target.value } ) }
						/>
					</label>
					<button type="submit" className="splm-btn splm-btn--primary" disabled={ adding }>
						{ adding ? 'Adding…' : 'Add' }
					</button>
				</form>
			</div>
		</div>
	);
}
