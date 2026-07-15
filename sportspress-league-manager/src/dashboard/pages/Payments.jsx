import { useState, useEffect } from '@wordpress/element';
import { fetchPayments } from '../lib/api';

// M7: status values we ship CSS for. Anything else gets the "other" class
// rather than letting the server inject arbitrary tokens into className.
const PAYMENT_STATUSES = [ 'paid', 'unpaid', 'pending' ];
function statusClass( status ) {
	return PAYMENT_STATUSES.includes( status ) ? status : 'other';
}

const PER_PAGE = 50;

function Pager( { page, totalPages, startIdx, endIdx, total, onPrev, onNext, atLastGuess } ) {
	return (
		<div className="splm-pager">
			<button type="button" className="splm-btn" onClick={ onPrev } disabled={ page <= 1 }>Previous</button>
			<span className="splm-pager__status" aria-live="polite">
				Showing { startIdx }–{ endIdx } of { total }
				{ totalPages > 1 ? ` (page ${ page } of ${ totalPages })` : '' }
			</span>
			<button
				type="button"
				className="splm-btn"
				onClick={ onNext }
				disabled={ totalPages ? page >= totalPages : atLastGuess }
			>Next</button>
		</div>
	);
}

export default function Payments( { season } ) {
	const [ payments, setPayments ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	// Reset to first page whenever season changes.
	useEffect( () => {
		setPage( 1 );
	}, [ season ] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		fetchPayments( season, { page, perPage: PER_PAGE } ).then( ( res ) => {
			if ( cancelled ) return;
			setPayments( res.data );
			setTotal( res.total );
			setTotalPages( res.total_pages );
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setError( err?.message || 'Failed to load payments' );
			setLoading( false );
		} );
		return () => { cancelled = true; };
	}, [ season, page ] );

	if ( loading ) {
		return <div className="splm-loading">Loading payments...</div>;
	}

	// F22: summary counts reflect the current page only (server-side pagination).
	const paid = payments.filter( ( p ) => p.status === 'paid' );
	const unpaid = payments.filter( ( p ) => p.status === 'unpaid' );
	const pending = payments.filter( ( p ) => p.status === 'pending' );

	const startIdx = total === 0 ? 0 : ( page - 1 ) * PER_PAGE + 1;
	const endIdx = Math.min( page * PER_PAGE, total );
	const sym = window.splmDashboard?.currencySymbol || '$';

	const pager = payments.length > 0 ? (
		<Pager
			page={ page }
			totalPages={ totalPages }
			startIdx={ startIdx }
			endIdx={ endIdx }
			total={ total }
			atLastGuess={ payments.length < PER_PAGE }
			onPrev={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
			onNext={ () => setPage( ( p ) => ( totalPages ? Math.min( totalPages, p + 1 ) : p + 1 ) ) }
		/>
	) : null;

	return (
		<div className="splm-payments">
			<h2>Payments</h2>

			{ error && <div className="splm-alert splm-alert--warning" role="alert">{ error }</div> }

			<div className="splm-summary-stats">
				<div className="splm-summary-stats__item splm-summary-stats__item--green">
					<span className="splm-summary-stats__value">{ paid.length }</span>
					<span className="splm-summary-stats__label">Paid (this page)</span>
				</div>
				<div className="splm-summary-stats__item splm-summary-stats__item--red">
					<span className="splm-summary-stats__value">{ unpaid.length }</span>
					<span className="splm-summary-stats__label">Unpaid (this page)</span>
				</div>
				<div className="splm-summary-stats__item splm-summary-stats__item--yellow">
					<span className="splm-summary-stats__value">{ pending.length }</span>
					<span className="splm-summary-stats__label">Pending (this page)</span>
				</div>
			</div>

			{ payments.length === 0 ? (
				<p className="splm-empty">No payment records.</p>
			) : (
				<>
					{ pager }
					<div className="splm-table-wrapper">
						<table className="splm-table splm-payment-table">
							<thead>
								<tr>
									<th scope="col">Player</th>
									<th scope="col">Team</th>
									<th scope="col">Status</th>
									<th scope="col">Amount</th>
								</tr>
							</thead>
							<tbody>
								{ payments.map( ( p ) => {
									const sc = statusClass( p.status );
									const amount = `${ sym }${ parseFloat( p.amount || 0 ).toFixed( 2 ) }`;
									return (
										<tr key={ p.player_id } className={ `splm-payment-table__row--${ sc }` }>
											<td>{ p.player }</td>
											<td>{ p.team }</td>
											<td><span className={ `splm-payment-table__status splm-payment-table__status--${ sc }` }>{ p.status }</span></td>
											<td>
												{ p.order_url
													? <a className="splm-order-link" href={ p.order_url } target="_blank" rel="noopener noreferrer" title="View order">{ amount } ↗</a>
													: amount }
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
					{ pager }
				</>
			) }
		</div>
	);
}
