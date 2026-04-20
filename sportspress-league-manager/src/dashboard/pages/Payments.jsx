import { useState, useEffect } from '@wordpress/element';
import { fetchPayments } from '../lib/api';

export default function Payments() {
	const [ payments, setPayments ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		fetchPayments().then( ( data ) => {
			setPayments( data );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	if ( loading ) {
		return <div className="splm-loading">Loading payments...</div>;
	}

	const paid = payments.filter( ( p ) => p.status === 'paid' );
	const unpaid = payments.filter( ( p ) => p.status === 'unpaid' );
	const partial = payments.filter( ( p ) => p.status === 'partial' );

	return (
		<div className="splm-payments">
			<h2>Payments</h2>

			<div className="splm-summary-stats">
				<div className="splm-summary-stats__item splm-summary-stats__item--green">
					<span className="splm-summary-stats__value">{ paid.length }</span>
					<span className="splm-summary-stats__label">Paid</span>
				</div>
				<div className="splm-summary-stats__item splm-summary-stats__item--red">
					<span className="splm-summary-stats__value">{ unpaid.length }</span>
					<span className="splm-summary-stats__label">Unpaid</span>
				</div>
				<div className="splm-summary-stats__item splm-summary-stats__item--yellow">
					<span className="splm-summary-stats__value">{ partial.length }</span>
					<span className="splm-summary-stats__label">Partial</span>
				</div>
			</div>

			{ payments.length === 0 ? (
				<p className="splm-empty">No payment records.</p>
			) : (
				<div className="splm-table-wrapper">
					<table className="splm-table splm-payment-table">
						<thead>
							<tr>
								<th>Player</th>
								<th>Team</th>
								<th>Status</th>
								<th>Amount</th>
							</tr>
						</thead>
						<tbody>
							{ payments.map( ( p ) => (
								<tr key={ p.id } className={ `splm-payment-table__row--${ p.status }` }>
									<td>{ p.player }</td>
									<td>{ p.team }</td>
									<td><span className={ `splm-payment-table__status splm-payment-table__status--${ p.status }` }>{ p.status }</span></td>
									<td>${ p.amount }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}
